<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\DB;

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
     * The logo an order document prints when its store has named none.
     *
     * The same path `Utility::Setting()`'s own defaults carry for `invoice_logo`
     * (app/Models/Utility.php:53), so this is the value the view would have rendered anyway.
     */
    private const DEFAULT_LOGO = 'storage/uploads/logo/logo.png';

    /**
     * Does this store's own configuration say its documents read right to left?
     *
     * @param  int|string|null  $storeId  the ORDER'S store, never the viewer's
     */
    public static function isRtl($storeId): bool
    {
        // An ABSENT row reads as left-to-right, which is `Utility::Setting()`'s own documented default
        // for this name ('off', app/Models/Utility.php:43) -- so a store that never set the flag gets
        // the platform's answer rather than the reader's.
        return strtolower(trim(self::ownRow($storeId, 'SITE_RTL'))) === 'on';
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
            /*
             * `app.fallback_locale` AND NOT `app()->getLocale()`, and not `config('app.locale')` either.
             *
             * `stores.default_language` is nullable with no default, so a store that never chose one
             * needs a fallback -- and `app()->getLocale()` is whatever the VIEWER'S request resolved
             * through `themelanguage`/`SetLocate`, so the document would declare the language of
             * whoever happened to print it: the same viewer-derived answer the direction and the logo
             * were just fixed to stop giving.
             *
             * `config('app.locale')` is NOT a way round that, which is worth recording because it looks
             * like one and the first cut of this used it. `Application::setLocale()` WRITES
             * `config('app.locale')`, so the two are the same value at runtime -- MEASURED:
             * `setLocale('fr')` leaves `config('app.locale')` as 'fr' and `app.fallback_locale` as 'en'.
             * A removal check recorded that first cut as an EQUIVALENT mutant for exactly this reason,
             * which is how it was caught rather than shipped.
             */
            $language = (string) (config('app.fallback_locale') ?: 'en');
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
        $stored = trim(self::ownRow($storeId, 'invoice_logo'));
        $resolved = $stored === '' ? '' : (string) get_file($stored);

        if ($resolved !== '') {
            return $resolved;
        }

        // `get_file()` returns '' from its own `catch (\Throwable)` when the platform's storage disk
        // throws -- which it selects from the SUPER ADMIN's settings, not this store's, so neither limb
        // above can rule it out. The raw default path is still a usable local `src`, and an empty one is
        // not, so that is what is returned rather than nothing.
        return (string) (get_file(self::DEFAULT_LOGO) ?: self::DEFAULT_LOGO);
    }

    /**
     * Does this store's LANGUAGE disagree with its direction flag?
     *
     * Not used to decide anything -- it is what the diagnostic script counts, so the owner can see how
     * many stores are in that state before anyone proposes changing which of the two wins.
     *
     * @param  int|string|null  $storeId
     */
    /**
     * ONE SETTING ROW OF THE ORDER'S OWN STORE, AND NOTHING ELSE'S.
     *
     * WHY NOT `Utility::GetValueByName($name, $storeId)`, WHICH TAKES A STORE ID. Because passing it
     * one is not enough, and that is the defect a read-only tenancy review of the first cut of this
     * service found -- MEASURED, not inferred, before it was believed:
     *
     *   * its PRIMARY read is correctly `where('store_id', $storeId)` (Utility.php:214-220), but
     *   * on a MISS it falls through to `Utility::Setting($store_id)` (:228), and
     *   * `Utility::Setting()` IGNORES its own `$store_id` argument whenever `Auth::check()` is true
     *     (:139-141): it reads `getCurrentStore()`, THE VIEWER'S STORE.
     *
     * So for any name the order's store has no row of, the answer came from whoever was printing the
     * document. MEASURED, operator viewing a merchant's receipt with the merchant's two rows absent:
     * the OPERATOR's logo on the document, and `dir="rtl"` from the OPERATOR's flag while the order's
     * store had no flag at all.
     *
     * AND THE ABSENT ROW IS THE COMMON CASE, not an edge one. `invoice_logo` is NEVER SEEDED --
     * `defaultSetting()` seeds `SITE_RTL` and not it (app/Helper/helper.php:1051-1203), and the only
     * writers are the merchant's own App Settings upload and the admin API -- so "no row" is the
     * default state of every merchant store that has not uploaded a logo.
     *
     * Reading the table directly is the shape `OrderController::shippinglabel()` already uses for the
     * five address names, for the same reason, and it is why this is a private helper rather than two
     * copies: the two names must not drift apart.
     *
     * NOT A WIDER REPAIR OF `GetValueByName()`. `Utility::Setting()`'s viewer-store behaviour is read
     * by almost every handler on the platform, and `config/tenancy.php:60-63` records two storefront
     * outages shipped by changing a helper that central. The narrow change is the one that closes the
     * defect on the two documents this task owns; the general question belongs to P1-SEC-26.
     *
     * NO `$default` PARAMETER, and that is a removal rather than an omission. The first cut took one,
     * and a removal check recorded dropping it as an EQUIVALENT mutant -- 14 tests, none failed -- which
     * is correct and not a test gap: for `SITE_RTL` an empty answer is already not `'on'`, and for
     * `invoice_logo` an empty answer already falls through to {@see invoiceLogoFor()}'s own last resort.
     * Two defences for one case, with the outer one load-bearing and the inner one untestable, so the
     * inner one is gone. Each caller states its own default where that default is actually applied.
     *
     * @param  int|string|null  $storeId
     */
    private static function ownRow($storeId, string $name): string
    {
        // STRICTLY, never `is_numeric` or a cast: MySQL coerces `'5abc'` to 5 in a `WHERE` comparison,
        // and a `(int)` cast turns any junk into 0 -- which is a store id some rows really carry, so an
        // unusable value would be answered with store 0's settings rather than refused. SQLite cannot
        // show the junk-suffix half of that (its affinity leaves 'abc' unmatched), which is why the test
        // asserts the half it CAN show: a store id of 0 must read no row at all.
        $storeId = filter_var($storeId, FILTER_VALIDATE_INT);

        if ($storeId === false || $storeId <= 0) {
            return '';
        }

        return (string) DB::table('settings')->where('store_id', $storeId)->where('name', $name)->value('value');
    }

    public static function languageDisagreesWithFlag($storeId): bool
    {
        $language = strtolower(substr(self::localeFor($storeId), 0, 2));

        return in_array($language, self::RTL_LANGUAGES, true) !== self::isRtl($storeId);
    }
}
