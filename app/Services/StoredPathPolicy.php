<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Which strings may be stored in a column that LATER becomes a filesystem path.  P1-SEC-28.
 *
 * The host's own deleters go through App\Services\StoredUpload, which refuses a path outside uploads/ at the moment of the unlink. The
 * VENDORED module deleters (AuctionProduct, DigitalProduct, SignInWithLinkedIn, SignInWithFacebook) cannot be edited and do
 * `File::delete(base_path($row->cover_image_path))` on whatever the row holds -- and the ImportExport CSV importer writes `cover_image`,
 * `preview_content` and `downloadable_product` from a spreadsheet cell, raw. So `.env` or `config/*` in a cell, then a delete through a
 * vendored handler, removed it. The control that reaches those readers is on the WRITE side: a model rule that keeps the value a
 * relative path under one of the roots uploads actually live in. Only a value that CHANGES is judged, so a legacy row is never made
 * unsaveable by an unrelated edit.
 *
 * Accepted: empty; an absolute http(s) URL (a cover image kept on another host -- `base_path('https://x/..')` names a directory `https:` that
 * does not exist, so no deleter can reach a file through it); or a relative path under `uploads/` (also spelled `public/uploads/` and
 * `storage/uploads/`) with no `..`/`.`/empty segment and no control byte or backslash; or exactly ImportExport's bundled default image
 * (a shared vendored asset: the host deleters refuse it because it is outside uploads/; a vendored deleter could still remove it --
 * recorded in the ledger). When the OWNING STORE is known the first segment under `uploads/` (peeling the gallery's doubled `uploads/`)
 * must be that store's folder, or the folder of another store the same owner runs: a poisoned row cannot name another tenant's file or one of
 * the platform's shared ones (`uploads/profile/..`, `uploads/logo/..`) for a vendored deleter to remove.
 *
 * REVIEW FINDINGS folded in (a read-only review of c71c717e): the first version also accepted `public/`, `storage/` and `themes/` as roots,
 * so a vendored deleter could still be pointed at `public/index.php` or `storage/logs/laravel.log`; it accepted any tenant's folder; it
 * refused `* ? [ ] { } < > | "` -- characters real upload names contain (`photo[1].jpg`), so a product save failed AFTER its file was
 * written and its quota debited (nothing here globs: File::delete is unlink); and it refused the absolute URLs a CSV import carries.
 */
final class StoredPathPolicy
{
    public const IMPORT_EXPORT_DEFAULT = 'packages/workdo/ImportExport/src/resources/assets/img/img.png';

    private const ROOTS = ['uploads', 'public/uploads', 'storage/uploads'];

    /** @param int|string|null $storeId the store that owns the row, when the caller knows it */
    public static function isSafe($path, $storeId = null): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        if (! is_string($path)) {
            return false;
        }

        if ($path === self::IMPORT_EXPORT_DEFAULT) {
            return true;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1) {
            return false;
        }

        if (preg_match('#^https?://[^\s/]#i', $path) === 1) {
            return true;
        }

        // a leading slash, a drive letter or any other scheme all make the FIRST segment something other than one of the roots below
        $segments = explode('/', $path);

        if (array_intersect(['..', '.', ''], $segments) !== []) {
            return false;
        }

        foreach (self::ROOTS as $root) {
            $rootSegments = explode('/', $root);

            if (array_slice($segments, 0, count($rootSegments)) === $rootSegments && count($segments) > count($rootSegments)) {
                return $storeId === null || self::inStoreFolder(array_slice($segments, count($rootSegments)), $storeId);
            }
        }

        return false;
    }

    /** @param list<string> $segments what follows the root; the gallery helper prepends `uploads/` a second time */
    private static function inStoreFolder(array $segments, $storeId): bool
    {
        while (($segments[0] ?? null) === 'uploads' && count($segments) > 1) {
            array_shift($segments);
        }

        $folder = $segments[0] ?? '';

        if (! ctype_digit($folder) || count($segments) < 2) {
            return false;
        }

        if ((string) $folder === (string) $storeId) {
            return true;
        }

        $owner = \App\Models\Store::query()->whereKey($storeId)->value('created_by');

        return $owner !== null && (string) \App\Models\Store::query()->whereKey((int) $folder)->value('created_by') === (string) $owner;
    }

    /**
     * @throws ValidationException
     */
    public static function assert(string $attribute, $value, $storeId = null): void
    {
        if (! self::isSafe($value, $storeId)) {
            throw ValidationException::withMessages([$attribute => [__('The file path is not accepted.')]]);
        }
    }

    /** Product::saving -- every path column a vendored deleter reads, judged only when it changes. */
    public static function product(\App\Models\Product $product): void
    {
        foreach (['cover_image_path', 'downloadable_product'] as $attribute) {
            if (! $product->exists || $product->isDirty($attribute)) {
                self::assert($attribute, $product->getAttribute($attribute), $product->store_id);
            }
        }

        // `preview_content` is a path only for a video FILE (a video URL lives in the same column)
        if ($product->getAttribute('preview_type') === 'Video File' && (! $product->exists || $product->isDirty(['preview_content', 'preview_type']))) {
            self::assert('preview_content', $product->getAttribute('preview_content'), $product->store_id);
        }

        if (! $product->exists || $product->isDirty('other_description_api')) {
            $decoded = json_decode((string) $product->getAttribute('other_description_api'), true);

            foreach ((array) ($decoded['product-other-description'] ?? []) as $field) {
                if (is_array($field) && ($field['field_type'] ?? null) === 'photo upload') {
                    self::assert('other_description_api', $field['value'] ?? null, $product->store_id);
                }
            }
        }
    }

    /** ProductImage::saving */
    public static function productImage(\App\Models\ProductImage $image): void
    {
        if (! $image->exists || $image->isDirty('image_path')) {
            self::assert('image_path', $image->getAttribute('image_path'), \App\Models\Product::withoutGlobalScopes()->whereKey($image->product_id)->value('store_id'));
        }
    }

    /** Setting::saving -- the names the vendored sign-in modules unlink (`linkedin_sign_in_image`, `facebook_sign_in_image`, ...). */
    public static function setting(\App\Models\Setting $setting): void
    {
        if (preg_match('/_sign_in_image$/', strtolower(trim((string) $setting->name))) === 1 && (! $setting->exists || $setting->isDirty(['name', 'value']))) {
            self::assert('value', $setting->value);
        }
    }
}
