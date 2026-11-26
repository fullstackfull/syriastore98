<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Policies\UserPolicy;
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
 *     `/app/uploads-evil` would pass a prefix test against `/app/uploads`. The precedent was a private `isInsideUploadsRoot()`
 *     inside `ProductController`; P1-INV-09 DELETED it when this became the only implementation, so there is no such method to
 *     grep for today -- the history is in `docs/development/phase-1/12-PHASE-01-TASK-LEDGER.md`.
 *  3. NOT ANOTHER TENANT'S FOLDER. Uploads are namespaced `uploads/<store id>/...` -- or `uploads/uploads/<store id>/...` for
 *     the product gallery, whose helper prepends the prefix twice -- so when the owner is known, the numeric segment after any
 *     leading `uploads` must be one of that owner's stores (the platform operator, who legitimately edits any store's files, is
 *     exempt). Ownership of the ROW is the callers' business; this stops a poisoned row from naming someone else's folder.
 *     P1-SEC-67d: THE EXEMPTION IS THE OPERATOR ACTING, NOT THE OPERATOR'S ID. "The owner" is whatever a caller passes, and about
 *     sixty call sites in some thirty-five handlers pass `Auth::user()->creatorId()` -- which IS the operator's id for a principal rooted at the platform (a
 *     merchant who demoted their own `type` through PUT /users/{self}, or staff the operator created). They presented the
 *     operator's id and were handed the operator's exemption: any tenant's folder, the operator's own included, from a path
 *     they stored themselves and an image they then replaced (executed: OperatorRootedStoredUploadTest). So when the owner is
 *     an operator and a panel user who is NOT the operator is acting, they get what they would get as themselves: a store they
 *     own. Read `mayTouchStoreFolder()` for who that does and does not change.
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
     * @param  int|string|null  $ownerId  the tenant owner's user id (User::creatorId()); null skips rule 3. It is not trusted to carry
     *                                    the operator's exemption on its own: see mayTouchStoreFolder()
     * @param  string|null  $confineTo  a directory, relative to base_path(), that the RESOLVED file must sit strictly inside;
     *                                  null applies no confinement. Rule 3 only fires when the resolved first segment is a run
     *                                  of DIGITS, which is correct for what it claims -- it answers "whose store folder is
     *                                  this" -- but it means a caller that derives a folder from its principal cannot express
     *                                  "and nothing outside it" through $ownerId alone. A caller that holds such a folder
     *                                  passes it here, and the test is applied to the realpath, so `<folder>/../elsewhere/x`
     *                                  is refused by the same comparison that admits `<folder>/x`. The alternative -- having
     *                                  the caller realpath() the value itself and compare -- is TWO resolutions of one string
     *                                  that can disagree, which is the defect this parameter exists to make unexpressible.
     * @return string|null the real, absolute path of an existing file that may be touched; null otherwise
     */
    public static function resolve(?string $relativePath, $ownerId = null, ?string $confineTo = null): ?string
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

        /*
         * The caller's own confinement, imposed on the RESOLVED path. See $confineTo: this is the
         * only place the comparison can be made exactly once, which is why it lives here rather
         * than in the caller.
         */
        if ($confineTo !== null) {
            $confineReal = realpath(base_path($confineTo));

            if ($confineReal === false || ! str_starts_with($real, $confineReal . DIRECTORY_SEPARATOR)) {
                Log::warning('a stored upload path resolved outside the folder its caller confined it to and was not touched', [
                    'path' => $relativePath,
                    'confined_to' => $confineTo,
                ]);

                return null;
            }
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
    public static function delete(?string $relativePath, $ownerId = null, ?string $confineTo = null): bool
    {
        $real = self::resolve($relativePath, $ownerId, $confineTo);

        return $real !== null && File::delete($real);
    }

    /**
     * May a file in `uploads/<storeId>/` be touched on behalf of `$ownerId`?
     *
     * WHO THE P1-SEC-67d LIMB CHANGES, AND WHO IT DOES NOT. It is reached only when the owner presented is an operator.
     *
     *   the operator acting                     unchanged: any store's folder. (A second operator account acting while the first
     *                                           one's id is presented is still an operator acting.)
     *   no `User` on the web guard              unchanged: the console, a queue job, a storefront request with no panel session (a
     *                                           `Customer` is on another guard and is not a `User`). Recorded, not endorsed: two
     *                                           customer-side callers present a store owner's id -- Api\ApiController (a customer's
     *                                           profile image, `$store->created_by`) and AccountProfileController::attachmentDestroy
     *                                           (`$customer->creatorId()`) -- and for a customer of the operator's own store that is
     *                                           the operator, so the exemption applies there as it always did. No first-party
     *                                           writer was found that lets a customer store an arbitrary path in either column.
     *   a merchant, a merchant's staff          never reach it: their `creatorId()` is the merchant.
     *   staff of a self-demoted merchant        never reach it: their `creatorId()` is their employer, so the ownership limb at the
     *                                           foot already bounds them to their employer's stores. A uniform "the root must be a
     *                                           merchant" refusal would cost them their own housekeeping and close nothing.
     *   a self-demoted merchant                 no exemption: only a store THEY own (they still own theirs). NOT `created_by =
     *                                           $ownerId`: that is the operator's id, and it would hand them the operator's stores.
     *   staff the operator created              no exemption, and they own no store: nothing under `uploads/<id>/`, the operator's
     *                                           own store included. WHAT THAT COSTS THEM: a replaced image's old file stays on disk;
     *                                           a deleted product's media stays with no row left pointing at it; the storage figure
     *                                           charged for their uploads is never credited back; and the log line for each refusal
     *                                           says "another tenant's folder" about what is their own workplace. It is the price of
     *                                           their being indistinguishable from a demoted merchant by `creatorId()` -- the owner's
     *                                           fork, P01-SECURITY-WORK-QUEUE f)(2).
     *
     * THE WEB GUARD, READ EXPLICITLY, AND A `User`. `CustomAuth` signs an admin-API token's `User` into the DEFAULT guard, which is
     * `web` (config/auth.php), so the panel and the admin API arrive here the same way. An `auth:customers` route rewrites the
     * default guard, where `auth()->user()` would be a `Customer`; reading `web` by name keeps a panel session visible there too.
     * One edge follows and fails closed: a storefront request from a browser that ALSO holds a non-operator panel session is
     * narrowed like that panel user, so a customer's old file in the operator's store may be left on disk.
     *
     * ONE NORMALISATION FOR BOTH QUESTIONS: `UserPolicy::isOperator()` for the owner row and for the actor. The owner test used to
     * be its own `strtolower(trim())`, which calls a row typed with a leading space or a tab an operator when the policy does not.
     */
    private static function mayTouchStoreFolder(int $storeId, $ownerId): bool
    {
        $owner = User::find($ownerId);

        if (UserPolicy::isOperator($owner)) {
            $actor = auth('web')->user();

            if ($actor instanceof User && ! UserPolicy::isOperator($actor)) {
                return Store::where('id', $storeId)->where('created_by', $actor->id)->exists();
            }

            return true;
        }

        return Store::where('id', $storeId)->where('created_by', $ownerId)->exists();
    }
}
