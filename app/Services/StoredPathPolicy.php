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
 * Accepted: empty; or a relative path whose first segment is uploads, public, storage or themes, with no `..`/`.` segment, no control
 * byte, no backslash, no glob metacharacter, no scheme; or exactly ImportExport's bundled default image (a shared vendored asset: the host
 * deleters refuse it because it is outside uploads/; a vendored deleter could still remove it -- recorded in the ledger).
 */
final class StoredPathPolicy
{
    public const IMPORT_EXPORT_DEFAULT = 'packages/workdo/ImportExport/src/resources/assets/img/img.png';

    private const ROOTS = ['uploads', 'public', 'storage', 'themes'];

    public static function isSafe($path): bool
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

        if (preg_match('/[\x00-\x1F\x7F\\\\*?\[\]{}<>|"]/', $path) === 1) {
            return false;
        }

        // a leading slash, a drive letter or a scheme all make the FIRST segment something other than one of the roots below
        $segments = explode('/', $path);

        return count($segments) > 1
            && in_array($segments[0], self::ROOTS, true)
            && array_intersect(['..', '.', ''], $segments) === [];
    }

    /**
     * @throws ValidationException
     */
    public static function assert(string $attribute, $value): void
    {
        if (! self::isSafe($value)) {
            throw ValidationException::withMessages([$attribute => [__('The file path is not accepted.')]]);
        }
    }

    /** Product::saving -- every path column a vendored deleter reads, judged only when it changes. */
    public static function product(\App\Models\Product $product): void
    {
        foreach (['cover_image_path', 'downloadable_product'] as $attribute) {
            if (! $product->exists || $product->isDirty($attribute)) {
                self::assert($attribute, $product->getAttribute($attribute));
            }
        }

        // `preview_content` is a path only for a video FILE (a video URL lives in the same column)
        if ($product->getAttribute('preview_type') === 'Video File' && (! $product->exists || $product->isDirty(['preview_content', 'preview_type']))) {
            self::assert('preview_content', $product->getAttribute('preview_content'));
        }

        if (! $product->exists || $product->isDirty('other_description_api')) {
            $decoded = json_decode((string) $product->getAttribute('other_description_api'), true);

            foreach ((array) ($decoded['product-other-description'] ?? []) as $field) {
                if (is_array($field) && ($field['field_type'] ?? null) === 'photo upload') {
                    self::assert('other_description_api', $field['value'] ?? null);
                }
            }
        }
    }

    /** ProductImage::saving */
    public static function productImage(\App\Models\ProductImage $image): void
    {
        if (! $image->exists || $image->isDirty('image_path')) {
            self::assert('image_path', $image->getAttribute('image_path'));
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
