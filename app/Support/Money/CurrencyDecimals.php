<?php

namespace App\Support\Money;

/**
 * HOW MANY DIGITS FOLLOW THE DECIMAL POINT.  GAP-290.
 *
 * ONE BASIS, DEFINED ONCE, BECAUSE FOUR READERS DEFAULTED IT SEPARATELY AND THEY DID NOT AGREE —
 * the same shape `CurrencySeparators` was built to end for the two separator controls on this very
 * panel, and this follows it deliberately rather than inventing a second pattern:
 *
 *     currency_format_with_sym()           absent -> 2
 *     default_currency_format_with_sym()   absent -> 2
 *     order_currency_format_with_sym()     absent -> 2
 *     SettingController::updateNoteValue() (the panel's live preview) -> '1', from the REQUEST
 *     defaultSetting()                     seeds "1"
 *
 * So a store with no row printed `1.00` on every surface while the panel's own preview showed
 * `1.0`. The basis here is **2**, the one the three formatters already used, because that is what
 * every existing store's prices are actually printed with.
 *
 * WHY A RESOLVER AND NOT `?? '2'`, which is the one-liner a reader will reach for first. The
 * formatters guard with `isset()`, and the value that actually reaches them is the EMPTY STRING —
 * which is set. A null-coalesce catches null and absence, neither of which is the reachable case.
 * `CurrencyDecimalFormatIntegrityTest` asserts that as an executable statement.
 *
 * WHAT THE REACHABLE VALUES DO, measured on PHP 8.3 rather than reasoned:
 *
 *     number_format(1234.56, '')     TypeError   <- HTTP 500 on every surface printing a price
 *     number_format(1234.56, ' ')    TypeError
 *     number_format(1234.56, 'abc')  TypeError
 *     number_format(1234.56, '0')    "1,235"     <- the minor units, silently gone
 *     number_format(1234.56, '99')   100 digits  <- `max:50` admits it; not a crash, not a price
 *
 * `''` is reachable because `Api/DashboardController` validates `currency_format` as
 * `nullable|string|max:50`, so the shipped Flutter admin app can store it; and `0` is reachable
 * because `''` matches none of the panel's five options, the browser then pre-selects the first —
 * which is `0` — and the next Save of that panel stores it. The merchant never chose either.
 *
 * THE ALLOWED SET IS THE PANEL'S OWN FIVE. Not "any integer": a format outside what the one screen
 * that writes this offers is a value no merchant can have chosen on purpose, and `'99'` shows what
 * admitting more costs. A merchant who wants zero or four decimals keeps them — asserted by a
 * control case, because a validation that refuses a legitimate choice is the worse defect.
 *
 * NOTHING HERE TOUCHES AN AMOUNT. This decides a digit count. The row records that no writer,
 * total, export or order column derives from `currency_format`, which is what keeps this inside
 * display-only engineering — and "display only" still means every priced surface in the product.
 */
class CurrencyDecimals
{
    /** The five the Currency Settings panel offers, as strings, in its own order. */
    public const ALLOWED = ['0', '1', '2', '3', '4'];

    /**
     * The absent-value basis.
     *
     * 2, because that is what `currency_format_with_sym()` and its two siblings already used when
     * no row existed, so no store's prices change shape by routing through here. `defaultSetting()`
     * seeds `"1"` and that is left alone: it is a SEEDED value, which a merchant can see and
     * change, not a silent fallback.
     */
    public const DEFAULT = 2;

    /** The setting name, so no caller spells it. */
    public const SETTING = 'currency_format';

    /**
     * Is this a value the panel offers, and therefore one a merchant could have chosen?
     *
     * Compared as a trimmed string so `2`, `'2'` and `' 2 '` all answer true — a settings column
     * holds text and the admin API may send either shape — while `''`, `'abc'`, `'2.9'` and `'99'`
     * answer false.
     */
    public static function isAllowed($value): bool
    {
        if (! is_scalar($value) || is_bool($value)) {
            return false;
        }

        return in_array(trim((string) $value), self::ALLOWED, true);
    }

    /**
     * The digit count to hand `number_format()`, from a settings array.
     *
     * Always an `int`, which is what makes this total: `number_format()`'s second parameter is
     * typed `int`, and every TypeError in this family came from giving it a string it could not
     * coerce. An unusable stored value yields the default rather than a throw, because a store
     * whose setting is nonsense must still be able to show its merchant a price.
     *
     * @param  array<string, mixed>|mixed  $settings
     */
    public static function fromSettings($settings): int
    {
        $stored = is_array($settings) ? ($settings[self::SETTING] ?? null) : null;

        return self::isAllowed($stored) ? (int) trim((string) $stored) : self::DEFAULT;
    }

    /**
     * The same resolution for a single value rather than a settings array.
     *
     * Used by the panel's live preview, which reads the merchant's unsaved selection off the
     * request, and by anything else holding one value.
     */
    public static function of($value): int
    {
        return self::isAllowed($value) ? (int) trim((string) $value) : self::DEFAULT;
    }

    /**
     * What the panel should MARK as selected, as a string.
     *
     * The panel compares with `===` against its option values, which are strings, so this returns
     * one. Marking the resolved value rather than the raw stored one is what stops the select
     * showing nothing — the state in which the browser pre-selects `0` and the next Save drops
     * every price's minor units.
     *
     * @param  array<string, mixed>|mixed  $settings
     */
    public static function markedOption($settings): string
    {
        return (string) self::fromSettings($settings);
    }
}
