<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Resolving, and deleting, a file whose path came out of the database.  P1-SEC-18 F6.
 *
 * WHAT WENT WRONG. A dozen handlers replace an uploaded image by reading the previous path out of a row and unlinking it, and
 * `Utility::changeStorageLimit($owner, $path)` -- called with a database path from ~35 sites -- did
 * `File::glob(base_path($path))` and then `File::delete()` of EVERY match. The web's generic settings writers
 * (`/customize-settings`, `/system-settings`, ... take every posted name/value into `settings` for the caller's store, no
 * allow-list, no permission) let any tenant -- a staff member included -- store `logo_dark = config/*` and then save a logo:
 * every file directly inside config/ was unlinked. Executed in WebSettingsSecretsTest. The same string reached the unlink in
 * the handlers that call File::delete(base_path($value)) themselves, so `.env`, `database/database.sqlite` or `uploads/../x`
 * worked too.
 *
 * WHAT THIS ENFORCES, for every caller, in one place:
 *  1. LITERAL PATHS ONLY. No glob expansion: a stored value is a file name, not a pattern.
 *  2. INSIDE THE UPLOADS DIRECTORY. `realpath()` resolves `..` and symlinks BEFORE the comparison and returns false for a path
 *     that does not exist (so a missing file is simply not touched). The trailing separator on the root matters: without it
 *     `/app/uploads-evil` would pass a prefix test against `/app/uploads`. Precedent: ProductController::isInsideUploadsRoot().
 *  3. NOT ANOTHER TENANT'S FOLDER. Uploads are namespaced `uploads/<store id>/...` -- or `uploads/uploads/<store id>/...` for
 *     the product gallery, whose helper prepends the prefix twice -- so when the owner is known, the numeric segment after any
 *     leading `uploads` must be one of that owner's stores (the platform operator, who legitimately edits any store's files, is
 *     exempt). Ownership of the ROW is the callers' business; this stops a poisoned row from naming someone else's folder.
 *     RESIDUAL, measured and accepted in writing under P1-SEC-28 acceptance 5: a path whose first segment after any leading
 *     `uploads` is NOT a run of digits -- `uploads/profile/`, `uploads/receipt/`, `uploads/logo/`, `uploads/stylique/`,
 *     `uploads/is_cover_image/` and the loose files sitting directly in `uploads/` -- encodes no owner anywhere in the path,
 *     so there is nothing for rule 3 to compare and a tenant who gets such a value into one of their own rows can make the
 *     application unlink it. The measurement, the blast radius and why those files cannot be given an owner from inside
 *     this phase are in docs/security/P01-SEC-28-STORE-LESS-UPLOAD-RESIDUAL.md.
 *  4. NEVER A PLATFORM DEFAULT. One part of that residual is NOT accepted, because it has a single, named, high-value target:
 *     `uploads/profile/avatar.png` is the shared default `profile_image` written for every new user and delivery boy
 *     (RegisteredUserController:123, Api\DeliveryBoyApiController:157) and it is the only file in that folder. Unlinking it
 *     breaks the avatar of every account that never uploaded one, platform-wide, from one tenant's own logo save. The
 *     never-unlink list below refuses it for EVERY caller, the operator included -- a platform default is not an editable
 *     file, and a legitimate replacement is a deployment step rather than a settings save.
 *
 * It does not decide whether the CALLER may touch the row: that is each handler's tenancy check.
 */
final class StoredUpload
{
    /**
     * Paths that are PLATFORM DEFAULTS, not any tenant's file. Refused for every caller, before the owner is even looked at.
     *
     * Compared on the resolved real path, not on the stored string, so `uploads/./profile/avatar.png`,
     * `uploads/2/../profile/avatar.png` and a symlink to it are the same answer. `StoredPathPolicy::IMPORT_EXPORT_DEFAULT` is
     * not listed because it lives under packages/ and rule 2 already refuses it; this list is for defaults that sit INSIDE
     * the uploads root, where rule 2 does not help and rule 3 has no owner to read.
     *
     * @var list<string>
     */
    private const PLATFORM_DEFAULTS = ['uploads/profile/avatar.png'];

    /**
     * @param  int|string|null  $ownerId  the tenant owner's user id (User::creatorId()); null skips rule 3
     * @return string|null the real, absolute path of an existing file that may be touched; null otherwise
     */
    public static function resolve(?string $relativePath, $ownerId = null): ?string
    {
        $relativePath = trim((string) $relativePath);

        // realpath() raises ValueError on a NUL byte in PHP 8; a stored value containing one is not a path.
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }

        $root = realpath(base_path('uploads'));
        $real = realpath(base_path($relativePath));

        if ($root === false || $real === false || ! is_file($real)) {
            return null;
        }

        if (! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            Log::warning('a stored upload path points outside the uploads directory and was not touched', ['path' => $relativePath]);

            return null;
        }

        foreach (self::PLATFORM_DEFAULTS as $default) {
            $defaultReal = realpath(base_path($default));

            if ($defaultReal !== false && $real === $defaultReal) {
                Log::warning('a stored upload path names a platform default and was not touched', ['path' => $relativePath]);

                return null;
            }
        }

        if ($ownerId !== null) {
            $segments = explode(DIRECTORY_SEPARATOR, substr($real, strlen($root) + 1));

            // Utility::keyWiseUpload_file() prepends `uploads/` to a directory its callers already pass as `uploads/<store id>`, so
            // every product gallery image lives at uploads/uploads/<store id>/... -- peel the doubled prefix before reading the
            // tenant segment, or the whole product gallery would sit outside rule 3. (Found by the second review.)
            while (($segments[0] ?? null) === 'uploads' && count($segments) > 1) {
                array_shift($segments);
            }
            $first = $segments[0] ?? '';

            if (ctype_digit($first) && count($segments) > 1 && ! self::mayTouchStoreFolder((int) $first, $ownerId)) {
                Log::warning('a stored upload path names another tenant\'s folder and was not touched', ['path' => $relativePath]);

                return null;
            }
        }

        return $real;
    }

    /**
     * The shape Utility::changeStorageLimit() wants: a list of zero or one real paths.
     *
     * @param  mixed  $relativePath
     * @return list<string>
     */
    public static function resolveAll($relativePath, $ownerId = null): array
    {
        $real = self::resolve(is_string($relativePath) ? $relativePath : null, $ownerId);

        return $real === null ? [] : [$real];
    }

    /**
     * @return bool true when a file was unlinked
     */
    public static function delete(?string $relativePath, $ownerId = null): bool
    {
        $real = self::resolve($relativePath, $ownerId);

        return $real !== null && File::delete($real);
    }

    private static function mayTouchStoreFolder(int $storeId, $ownerId): bool
    {
        $owner = User::find($ownerId);

        if ($owner !== null && strtolower(trim((string) $owner->type)) === 'super admin') {
            return true;
        }

        return Store::where('id', $storeId)->where('created_by', $ownerId)->exists();
    }
}
