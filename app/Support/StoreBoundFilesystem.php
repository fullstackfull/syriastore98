<?php

namespace App\Support;

use App\Services\StoredPathPolicy;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/**
 * A `File` whose `delete()` will not remove another tenant's upload.  P1-SEC-70.
 *
 * THE DELETERS IT CONTAINS. The vendored sign-in modules (SignInWithFacebook, SignInWithLinkedIn) read a path out of the `settings` table
 * and `File::delete(base_path($row->value))` it. The row is chosen by `Setting::where(['name' => '<provider>_sign_in_image'])->first()` with
 * NO store predicate (`Setting` is not in config/tenancy.php's scoped_models), so the row is the OLDEST one of that name -- whichever tenant
 * saved the sign-in settings first. The next tenant to save the form with an image deletes the first tenant's file. And because the Store
 * Setting handlers take EVERY posted name into `settings` for the caller's store, a merchant who writes their own
 * `facebook_sign_in_image` row (before anyone else has) can name ANY file under `uploads/` -- another tenant's logo, a product image --
 * and have it deleted by the same handler. The handlers are vendored and are not edited.
 *
 * WHERE THE CONTROL GOES. At the delete, which is the only place that knows both the file and who is asking, and which does not care how
 * the row was chosen or written. For the lifetime of one request to one of those two routes (App\Http\Middleware\
 * SignInImageDeleteGuard swaps the `File` facade's root for one of these and puts the original back, whatever happens), a delete of a path
 * under one of the roots uploads live in (StoredPathPolicy::ROOTS) is carried out only for a path StoredPathPolicy::isSafe() accepts for
 * the CURRENT store: a relative path with no `..`, `.` or empty segment under `uploads/`, whose first folder is the current store's or one
 * of the stores its owner also runs. Every other delete -- the framework's own caches, sessions and compiled views under `storage/` --
 * is passed to the real filesystem untouched, because this exists to stop one thing.
 */
final class StoreBoundFilesystem extends Filesystem
{
    /** @param int|string|null $storeId the store of the signed-in user who made the request */
    public function __construct(private readonly int|string|null $storeId)
    {
    }

    /**
     * @param  string|array<int, string>  $paths
     * @return bool false when ANY path was refused or failed, as Filesystem::delete() answers for a failure
     */
    public function delete($paths)
    {
        $paths = is_array($paths) ? $paths : func_get_args();
        $allowed = [];
        $refused = false;

        foreach ($paths as $path) {
            if ($this->mayDelete((string) $path)) {
                $allowed[] = $path;

                continue;
            }

            $refused = true;

            // Written for whoever asks, afterwards, whether anybody tried: that it happened, in which store -- never the path (a stranger's text).
            Log::warning('a delete of an upload outside the current store was refused', ['store' => is_numeric($this->storeId) ? (int) $this->storeId : null]);
        }

        $deleted = $allowed === [] ? true : parent::delete($allowed);

        return $deleted && ! $refused;
    }

    private function mayDelete(string $absolute): bool
    {
        $root = base_path() . DIRECTORY_SEPARATOR;

        if (! str_starts_with($absolute, $root)) {
            return true;       // not a path of this application: not what this exists to stop
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root)));

        // The path as written (a `..` inside uploads/ is refused by isSafe()), AND the path a `..` earlier in it resolves to
        // (`storage/../uploads/2/a.png` is uploads/2/a.png to the filesystem): either one under an upload root must be this store's.
        foreach ([$relative, $this->collapse($relative)] as $candidate) {
            if (StoredPathPolicy::isUploadPath($candidate) && ! StoredPathPolicy::isSafe($candidate, $this->storeId)) {
                return false;
            }
        }

        return true;
    }

    /** The lexical result of resolving `..` and `.`, without touching the disk (the file may not exist). */
    private function collapse(string $relative): string
    {
        $out = [];

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $segment === '..' ? array_pop($out) : $out[] = $segment;
        }

        return implode('/', $out);
    }
}
