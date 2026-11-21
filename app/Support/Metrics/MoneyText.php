<?php

namespace App\Support\Metrics;

/**
 * HOW A MONEY FIGURE IS SPELLED, IN ONE PLACE.
 *
 * `FinanceLedgerFigures` carried this privately and `WalletEntitlementFigure` (GAP-222) needed the
 * same three rules. A second copy is how two tiles on the SAME CARD drift apart, and this project
 * has just measured that failure in another register: a vendored module's `ar.json` defines 1,126
 * of the app's own keys with different Arabic, and a test that asked the resolver rather than the
 * catalogue could not see it (GAP-224). One definition, one test surface.
 *
 * THE ISOLATES ARE NOT DECORATION. On an Arabic page a bare `1,234.56 SYP` is reordered by the
 * bidirectional algorithm -- the Latin currency code and the digits are separate runs, and the code
 * can end up in front of the amount or a minus sign behind it. U+2066 (LRI) .. U+2069 (PDI) bind the
 * pair into one left-to-right unit. They are invisible and carry no width; the tests that care
 * about them read the raw markup, and the rest strip them.
 */
final class MoneyText
{
    /** LEFT-TO-RIGHT ISOLATE. */
    private const LRI = "\u{2066}";

    /** POP DIRECTIONAL ISOLATE. */
    private const PDI = "\u{2069}";

    public static function isolate(string $text): string
    {
        return self::LRI.$text.self::PDI;
    }

    /** An amount and the currency it is denominated in, as one isolated left-to-right unit. */
    public static function amount(float $amount, string $currency): string
    {
        return self::isolate(number_format($amount, 2).' '.$currency);
    }

    /**
     * Currency buckets, normalised and ordered.
     *
     * `$dropEmpty` IS THE WHOLE OF GAP-222's ZERO TRAP, and the two callers want opposite answers:
     *
     *   - A LEDGER wants it true. A currency that nothing was ever actually paid in should not make
     *     a tile "mixed" and send it to the refusal branch; that is a rounding artefact, not a fact
     *     about the business.
     *   - A WALLET wants it false. A wallet's currency is a property of the ACCOUNT, not of the
     *     amount in it, and an empty wallet is the commonest wallet there is. Dropping the bucket
     *     leaves the caller's `?? $storeCurrency` fallback to answer, which prints the STORE's
     *     currency code against a balance denominated in another one -- a wrong statement about
     *     money, on the tile most merchants see.
     *
     * @param  array<string, float>  $byCurrency
     * @return array<string, float>
     */
    public static function buckets(array $byCurrency, bool $dropEmpty = true): array
    {
        $merged = [];

        foreach ($byCurrency as $currency => $amount) {
            $code = strtoupper(trim((string) $currency));
            $merged[$code] = round(($merged[$code] ?? 0.0) + (float) $amount, 2);
        }

        // Alphabetical, so the list reads the same whichever order the engine's GROUP BY returned.
        ksort($merged);

        return $dropEmpty
            ? array_filter($merged, static fn (float $amount): bool => abs($amount) >= 0.005)
            : $merged;
    }
}
