<?php

namespace App\Support;

use App\Services\StoredPathPolicy;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/**
 * A `File` that will not delete anything but the current store's own uploads and the framework's own working files.  P1-SEC-70.
 *
 * THE DELETERS IT CONTAINS. The vendored sign-in modules (SignInWithFacebook, SignInWithLinkedIn) read a path out of the `settings` table
 * and `File::delete(base_path($row->value))` it. The row is chosen by `Setting::where(['name' => '<provider>_sign_in_image'])->first()` with
 * NO store predicate (`Setting` is not in config/tenancy.php's scoped_models), so the row is the OLDEST one of that name -- whichever tenant
 * saved the sign-in settings first. The next tenant to save the form with an image deletes the first tenant's file. And because the Store
 * Setting handlers take EVERY posted name into `settings` for the caller's store, a merchant can write the row the handler will choose and
 * put any value in it. The handlers are vendored and are not edited.
 *
 * WHERE THE CONTROL GOES, AND WHAT IT JUDGES. At the delete, which is the only place that knows both the file and who is asking. For the
 * lifetime of one request to one of those two routes (App\Http\Middleware\SignInImageDeleteGuard swaps the `File` facade's root for one of
 * these and puts the original back, whatever happens) a delete is carried out only when BOTH of these hold:
 *
 *   1. THE PLACE. The path is judged as the OPERATING SYSTEM will resolve it -- `realpath()` of its directory, then the file's own name (a
 *      final symlink is judged where it lies, because unlink removes the link and does not follow it). So `..` that leaves the application
 *      and walks back in, `//`, `.` and a symlinked directory are all resolved before anything is compared; the first version collapsed `..`
 *      lexically and was walked around by `../<this directory>/uploads/<another store>/x.png`. The place must be
 *        - under one of the three upload roots (`uploads`, `public/uploads`, `storage/uploads`, each resolved too) in a folder
 *          StoredPathPolicy::isSafe() accepts for the CURRENT store -- its own, or one of the stores its owner also runs -- or
 *        - under `storage/framework` or `bootstrap/cache`, the framework's own working areas (compiled views, cache and session files).
 *      EVERYTHING ELSE IS REFUSED: the first version passed any path outside the upload roots to the real filesystem, so a row naming
 *      `.env`, `config/app.php` or the vendored module's shared default image was carried out. Default-deny is the correction.
 *   2. THE ROW. The guard that binds this class hands it `$rowIsTheStores`: whether the row the handler is about to use belongs to this
 *      store (or one its owner runs). When it does not, EVERY upload delete is refused -- including one that names the current store's own file,
 *      which a row planted by another tenant would otherwise have the victim's OWN save carry out (executed by the second review).
 *
 * A store that is not a store (0, or none) owns nothing, not even a folder that happens to be called `0`.
 *
 * WHAT IS BOUND. `delete()`, and `deleteDirectory()` (which would otherwise answer `true` while this class refused every file in it; the framework's
 * `cleanDirectory()` is `deleteDirectory($directory, true)`, so it is bound by the same override, and a test pins it). The write side -- put, append, move, copy, replace, link, moveDirectory -- is NOT bound: nothing on the two routes calls it, and
 * the claim is therefore "will not delete", not "cannot be made to damage another tenant's file".
 *
 * A refused delete is one `warning` with the store and a reason (`row`, `path`, `directory`, `no store`, `outside`), never the path (a stranger's
 * text). A path with nothing at it to remove -- a missing file, a directory, an empty value -- answers `false` quietly, as the real
 * filesystem does, because that is what a refusal of nothing is.
 */
final class StoreBoundFilesystem extends Filesystem
{
    /**
     * @param int  $storeId        the store of the signed-in user who made the request; 0 or less owns nothing
     * @param bool $rowIsTheStores whether the settings row the handler chose is this store's (see 2. above)
     */
    public function __construct(private readonly int $storeId, private readonly bool $rowIsTheStores = true)
    {
    }

    /**
     * @param  string|array<int, string>  $paths
     * @return bool false when ANY path was refused or could not be removed, as Filesystem::delete() answers for a failure
     */
    public function delete($paths)
    {
        $paths = is_array($paths) ? $paths : func_get_args();
        $success = true;

        foreach ($paths as $path) {
            $real = $this->resolve((string) $path);

            if ($real === null) {
                $success = false;       // nothing there that unlink could remove

                continue;
            }

            $reason = $this->refusal($real);

            if ($reason !== null) {
                $this->refused($reason);
                $success = false;

                continue;
            }

            $success = parent::delete($real) && $success;
        }

        return $success;
    }

    public function deleteDirectory($directory, $preserve = false)
    {
        return $this->mayTouchDirectory((string) $directory) ? parent::deleteDirectory($directory, $preserve) : false;
    }

    /** The path a delete of $path would remove, spelled the way the operating system resolves it; null when there is nothing a delete could remove. */
    private function resolve(string $path): ?string
    {
        // a trailing slash names a directory (`basename()` would quietly drop it), and an empty value or a NUL names nothing
        if ($path === '' || str_contains($path, "\0") || str_ends_with($path, '/') || str_ends_with($path, DIRECTORY_SEPARATOR)) {
            return null;
        }

        $name = basename($path);
        $directory = realpath(dirname($path));          // a relative path resolves against the working directory, exactly as unlink() will

        if ($directory === false || $name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $real = rtrim($directory, '/') . '/' . $name;

        // unlink() removes a file or a link (a dangling one too) and fails on a directory
        return is_file($real) || is_link($real) ? $real : null;
    }

    /** Why a delete of this resolved path is refused, or null when it is allowed. */
    private function refusal(string $real): ?string
    {
        foreach (['uploads', 'public/uploads', 'storage/uploads'] as $root) {
            $resolved = realpath(base_path($root));

            if ($resolved === false || ! str_starts_with($real . '/', $resolved . '/')) {
                continue;
            }

            if ($this->storeId <= 0) {
                return 'no store';
            }

            if (! $this->rowIsTheStores) {
                return 'row';
            }

            return StoredPathPolicy::isSafe('uploads/' . ltrim(substr($real, strlen($resolved)), '/'), $this->storeId) ? null : 'path';
        }

        return $this->inFrameworkArea($real) ? null : 'outside';
    }

    private function mayTouchDirectory(string $directory): bool
    {
        $real = $directory === '' ? false : realpath($directory);

        if ($real !== false && $this->inFrameworkArea($real . '/x')) {
            return true;
        }

        if ($real !== false) {
            $this->refused('directory');
        }

        return false;
    }

    private function inFrameworkArea(string $real): bool
    {
        foreach (['storage/framework', 'bootstrap/cache'] as $area) {
            $resolved = realpath(base_path($area));

            if ($resolved !== false && str_starts_with($real . '/', $resolved . '/')) {
                return true;
            }
        }

        return false;
    }

    private function refused(string $reason): void
    {
        // Written for whoever asks, afterwards, whether anybody tried: that it happened, in which store and why -- never the path (a stranger's text).
        Log::warning('a delete outside the current store was refused', ['store' => $this->storeId, 'reason' => $reason]);
    }
}
