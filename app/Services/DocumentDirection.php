<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Utility;

/**
 * WHICH WAY AN ORDER DOCUMENT READS, AND IN WHICH LANGUAGE.  P1-SEC-11 criterion 4.
 * =================================================================================================
 *
 * WHAT WAS WRONG, measured on both host order documents. `resources/views/order/shippinglabel.blade.php`
 * and `.../receipt.blade.php` opened with a HARDCODED
 *
 *     <html lang="en">
 *
 * and no `dir` attribute at all. The shipping label's own stylesheet ends with a block of
 * `html[dir="rtl"]` rules -- table cells right-aligned, `.text-right` flipped, the QR block moved -- so
 * its author intended right-to-left support and the selector could NEVER match. An Arabic store's
 * shipping label and receipt were laid out left-to-right, with Arabic text in them.
 *
 * WHY IT IS A SERVICE AND NOT TWO BLADE EXPRESSIONS. Both documents are printed FOR AN ORDER, and this
 * phase's rule for them is P1-SEC-11's: a document renders the settings of THAT ORDER'S store, not the
 * viewer's. The direction is such a setting, and so is the language. Putting the resolution in one place
 * means the two documents cannot disagree, and means the admin layout's rule -- which keys on the same
 * `SITE_RTL` flag (resources/views/layouts/app.blade.php:54) -- is restated once rather than copied.
 *
 * `SITE_RTL` AND NOT THE LOCALE, deliberately, and the asymmetry is worth stating because it is
 * surprising. The whole application keys direction on that per-store flag, NOT on the chosen language:
 * a store whose locale is Arabic but whose `SITE_RTL` is off is laid out left-to-right everywhere else,
 * by its own configuration. Making these two documents the only surface that infers direction from the
 * language would make them disagree with every screen around them. So the flag decides, and the
 * MISMATCH -- an Arabic store with `SITE_RTL` off -- is reported to the owner rather than silently
 * reinterpreted here: whether that combination is a misconfiguration or a deliberate choice is theirs
 * to say. See docs/security/P01-SEC-11-ORDER-DOCUMENT-DIRECTION.md.
 */
final class DocumentDirection
{
    /** The languages this application ships that are written right to left. */
    private const RTL_LANGUAGES = ['ar', 'he', 'fa', 'ur'];

    /**
     * Does this store's own configuration say its documents read right to left?
     *
     * @param  int|string|null  $storeId  the ORDER'S store, never the viewer's
     */
    public static function isRtl($storeId): bool
    {
        return strtolower(trim((string) Utility::GetValueByName('SITE_RTL', $storeId))) === 'on';
    }

    /**
     * The `lang` attribute for this store's documents: its configured language, falling back to the
     * application's current locale rather than to a hardcoded 'en'.
     *
     * READ FROM THE `stores` ROW, not from `settings`. `default_language` is a COLUMN on `stores`
     * (and on `users`), not a setting name -- `Utility::GetValueByName('default_language', ...)` finds
     * nothing. `App\Providers\ViewServiceProvider:50` reads it the same way for every other screen.
     *
     * @param  int|string|null  $storeId
     */
    public static function localeFor($storeId): string
    {
        $language = (string) (Store::where('id', $storeId)->value('default_language') ?? '');

        if (trim($language) === '') {
            $language = (string) app()->getLocale();
        }

        // `lang` takes a BCP 47 tag: the stored value is a bare code or a `pt_BR`-style pair.
        return str_replace('_', '-', trim($language)) ?: 'en';
    }

    /**
     * The invoice logo an order document prints: the ORDER'S store's, never the viewer's, and never empty.
     *
     * TWO DEFECTS IN ONE LINE, both measured. The views fetched this themselves as
     * `GetValueByName('invoice_logo')` with NO store id, which resolves `getCurrentStore()` -- so the
     * platform operator printing a merchant's label or receipt put the OPERATOR'S logo on it (criterion 1's
     * remainder). And when the value came back empty both templates fell back to `$profile . '/logo.png'`
     * with `$profile` DEFINED NOWHERE -- not by the controller, not by a composer, not shared; grepped --
     * so the document answered 500 with "Undefined variable $profile". The first defect is what kept the
     * second latent: reading the viewer's store almost always found a row.
     *
     * So this never returns an empty string. The last resort is the same path `Utility`'s own settings
     * defaults use for `invoice_logo`, which is what the view would have rendered anyway.
     *
     * @param  int|string|null  $storeId
     */
    public static function invoiceLogoFor($storeId): string
    {
        $stored = trim((string) Utility::GetValueByName('invoice_logo', $storeId));
        $resolved = $stored === '' ? '' : (string) get_file($stored);

        return $resolved !== '' ? $resolved : (string) get_file('storage/uploads/logo/logo.png');
    }

    /**
     * Does this store's LANGUAGE disagree with its direction flag?
     *
     * Not used to decide anything -- it is what the diagnostic script counts, so the owner can see how
     * many stores are in that state before anyone proposes changing which of the two wins.
     *
     * @param  int|string|null  $storeId
     */
    public static function languageDisagreesWithFlag($storeId): bool
    {
        $language = strtolower(substr(self::localeFor($storeId), 0, 2));

        return in_array($language, self::RTL_LANGUAGES, true) !== self::isRtl($storeId);
    }
}
