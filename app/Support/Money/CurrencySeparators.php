<?php

namespace App\Support\Money;

/**
 * WHICH CHARACTER GROUPS THE THOUSANDS, AND WHICH ONE IS THE DECIMAL POINT.  D-42.3.
 *
 * ONE DEFAULT, DEFINED ONCE, BECAUSE FOUR PLACES DEFAULTED IT SEPARATELY AND THEY DID NOT AGREE.
 * `currency_format_with_sym()`, `default_currency_format_with_sym()`,
 * `order_currency_format_with_sym()` and `SettingController::updateNoteValue()` each carried their
 * own copy of the same ternary, and the Currency Settings form carried a fifth reading of it.
 *
 * WHAT THAT PRODUCED, MEASURED ON THE RUNNING ADMIN: the dashboard's one money figure read
 * `$1,162,50` and every card on `/order-grid` read `$10,00`. The old default was a comma for BOTH
 * roles, so `number_format($price, 2, ',', ',')` used the same character twice and the figure could
 * not be read back -- nothing in `1,162,50` says which comma is the decimal point. It is not a
 * European format and not an Arabic one.
 *
 * AND IT WAS THE DEFAULT, NOT AN EDGE CASE. Neither `decimal_separator` nor `thousand_separator` is
 * written by any seeder or migration; the only writer is `SettingController::currencySettings`. So
 * every store carried this until a merchant happened to open Currency Settings and press Save.
 *
 * THE DEFAULT IS `1,162.50`, which is what `number_format($x, 2)` does with no separator arguments
 * -- and therefore what the finance screen, the chart tables and every other `number_format` in
 * this codebase already produce. The old behaviour was not a different convention; it was two roles
 * sharing one character.
 *
 * THE COLLISION RULE APPLIES TO STORED VALUES TOO, and that is deliberate. The form offers `dot`
 * and `comma` for both selects, so comma+comma is a reachable SAVED state and renders exactly the
 * unreadable figure the default used to. When the two resolve to the same character the thousands
 * separator takes the other one, which keeps the merchant's decimal choice -- the one that changes
 * how the number is read -- and only moves the grouping mark they cannot have meant to collide.
 *
 * NOTHING HERE TOUCHES AN AMOUNT. This decides two characters. Stored amounts, financial
 * calculations, wallet balances, settlement, payment processing and conversion rates are all
 * somewhere else, which is what keeps this inside the display-only authorisation.
 *
 * ONE MORE DEFECT IS FIXED BY ROUTING THROUGH HERE rather than separately:
 * `default_currency_format_with_sym()`'s `else` limb read
 * `isset($company_settings['decimal_separator']) == 'dot' ? '.' : ','`. `isset()` returns a bool,
 * and PHP compares a bool with a string by casting the string -- `'dot'` is truthy -- so that limb
 * answered "dot whenever the row EXISTS", ignoring the merchant's actual choice. A store that chose
 * a comma decimal got a dot from that one function and a comma from the other three.
 */
final class CurrencySeparators
{
    /** The tokens the settings form posts and the `settings` table stores. */
    public const DOT = 'dot';

    public const COMMA = 'comma';

    /** What an unset store gets: `1,162.50`. */
    public const DEFAULT_DECIMAL = self::DOT;

    public const DEFAULT_THOUSAND = self::COMMA;

    private const CHARS = [self::DOT => '.', self::COMMA => ','];

    /**
     * The two tokens actually in force, after defaulting and after resolving a collision.
     *
     * @param  mixed  $decimal   the stored `decimal_separator`, or null/absent
     * @param  mixed  $thousand  the stored `thousand_separator`, or null/absent
     * @return array{decimal: string, thousand: string} `dot`|`comma` each
     */
    public static function tokens($decimal, $thousand): array
    {
        $d = self::token($decimal, self::DEFAULT_DECIMAL);
        $t = self::token($thousand, self::DEFAULT_THOUSAND);

        if ($d === $t) {
            $t = $d === self::DOT ? self::COMMA : self::DOT;
        }

        return ['decimal' => $d, 'thousand' => $t];
    }

    /**
     * The two CHARACTERS to hand `number_format()`, in its argument order.
     *
     * @return array{0: string, 1: string} [decimal point, thousands separator]
     */
    public static function chars($decimal, $thousand): array
    {
        $tokens = self::tokens($decimal, $thousand);

        return [self::CHARS[$tokens['decimal']], self::CHARS[$tokens['thousand']]];
    }

    /**
     * The same answer from a settings map, which is the shape every caller actually holds
     * (`getAdminAllSetting()` returns `name => value`, and the form holds `$setting`).
     *
     * @param  array<string, mixed>|\ArrayAccess<string, mixed>|null  $settings
     * @return array{decimal: string, thousand: string}
     */
    public static function tokensFromSettings($settings): array
    {
        return self::tokens(
            self::read($settings, 'decimal_separator'),
            self::read($settings, 'thousand_separator')
        );
    }

    /**
     * @param  array<string, mixed>|\ArrayAccess<string, mixed>|null  $settings
     * @return array{0: string, 1: string}
     */
    public static function charsFromSettings($settings): array
    {
        return self::chars(
            self::read($settings, 'decimal_separator'),
            self::read($settings, 'thousand_separator')
        );
    }

    /** A value that is not one of the two tokens -- absent, null, '', or anything else -- is unset. */
    private static function token($stored, string $fallback): string
    {
        $value = is_string($stored) ? strtolower(trim($stored)) : '';

        return isset(self::CHARS[$value]) ? $value : $fallback;
    }

    private static function read($settings, string $name)
    {
        if (is_array($settings)) {
            return $settings[$name] ?? null;
        }

        if ($settings instanceof \ArrayAccess) {
            return $settings->offsetExists($name) ? $settings[$name] : null;
        }

        return null;
    }
}
