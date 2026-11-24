<?php

namespace App\Support\Money;

/**
 * A store's currency SYMBOL -- and its CODE, which one display format prints in the symbol's place -- as it may be printed.  P1-SEC-80, round 4.
 *
 * Money is printed raw in more places than a census of raw prints can keep: the product list's price cell is a raw DataTable column, the till's cart row is HTML concatenated in a controller,
 * and several screens write a formatted amount from a JSON answer with `.html()`. All of them print what the four formatters in `app/Helper/helper.php` return, and the formatters prefix or
 * suffix the stored symbol. The symbol was free text: the Settings screen posts `<symbol>-<code>` and the handler stored whatever stood before the first hyphen. A member of staff who may save
 * settings could therefore store markup as the symbol, and the owner's product list and till ran it (executed before the fix).
 *
 * A currency symbol is a few letters or one sign. It never needs the characters markup, an attribute value, a script string or a style string cannot do without, so they are removed where
 * the value is READ -- the control, because the settings table is written by handlers that take every posted name -- and the Settings handler no longer takes the symbol from the request at
 * all. An `&` stays: a symbol stored as an entity (`&euro;`) still prints as its sign where money is printed raw, and no entity can open a tag.
 */
final class CurrencySymbol
{
    /**
     * The settings rows that hold a symbol or a code and are printed next to an amount: the two the Settings screen writes for each, and the two older names an order falls back to when it
     * takes its own snapshot (`Order::booted()`).
     */
    public const SETTING_NAMES = ['defult_currancy_symbol', 'CURRENCY', 'defult_currancy', 'CURRENCY_NAME', 'currency_symbol', 'currency'];

    /**
     * Not the control -- without the characters below a long value is ugly, not active -- but a bound on what a list cell or a receipt line is asked to carry. The admin API's own rule for a
     * symbol and for a code is ten characters; the platform's widest symbol is three.
     */
    public const MAX_LENGTH = 24;

    private const NEVER_IN_A_SYMBOL = ['<', '>', '"', "'", '`', '\\'];

    /** The value as it may be printed: without the characters above, without control characters, and no longer than a symbol is. Anything that is not text is no symbol. */
    public static function clean($stored): string
    {
        if (! is_string($stored) && ! is_int($stored) && ! is_float($stored)) {
            return '';
        }

        $value = str_replace(self::NEVER_IN_A_SYMBOL, '', (string) $stored);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';

        return mb_substr(trim($value), 0, self::MAX_LENGTH);
    }

    /**
     * For a reader of ONE setting by name: a symbol or a code is cleaned, a value that is absent stays absent (callers fall back with `??`), and every other setting is returned untouched.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public static function cleanSetting($name, $value)
    {
        return $value !== null && is_string($name) && in_array($name, self::SETTING_NAMES, true) ? self::clean($value) : $value;
    }

    /**
     * THE STORED VALUE AS A CURRENCY CODE, or '' when it is not one.
     *
     * `clean()` answers "is this safe to print"; this answers "is this a currency at all". They
     * are different questions, and a reader that GROUPS money by the value needs the second one:
     * `clean()` removes markup and control characters, so a SYMBOL -- `$`, `ل.س` -- passes it
     * intact and was then taken for a currency.
     *
     * That is not hypothetical in this schema. P5-R14 measured that the two storefront-API order
     * writers stamped `orders.currency_code` from the store's SYMBOL setting, and owner decision
     * D-N34 leaves the rows already written untouched, so the column really does hold a mix of
     * codes and symbols. A grouping reader that trusts it prints a symbol as a currency heading
     * and splits one store's money across two buckets.
     *
     * The test is three ASCII letters, which is the shape of every currency code this product
     * stores: `currency_rates.currency_code`, seeded with the thirteen the settings screen offers
     * (`SettingController::seedDefaultCurrencyRates()`, `'currency_code' => $currency[0]`), and
     * ISO 4217 has no other shape. The case is folded, because `'syp'` and `'SYP'` are one
     * currency and keeping them apart is the same defect in a smaller font.
     *
     * A value that is not a code is NOT repaired or guessed at here; '' is returned and the
     * CALLER decides what to fall back to. Guessing is how a figure acquires a unit it was never
     * measured in.
     *
     * @param  mixed  $stored
     */
    public static function code($stored): string
    {
        $value = self::clean($stored);

        return preg_match('/\A[A-Za-z]{3}\z/', $value) === 1 ? strtoupper($value) : '';
    }
}
