<?php

namespace App\Support\Metrics;

use RuntimeException;

/**
 * Thrown when a caller asks for one number from a result that holds several currencies.
 *
 * `PHASE-05-METRIC-DEFINITIONS.md` section 11 rule 3: "A cross-store total without conversion is
 * meaningless." `store_order_financials` carries `currency_code` per row, so a single store can
 * hold orders in more than one currency; an operator reading several stores almost certainly does.
 * Adding those rows produces a number with no unit, and a number with no unit printed next to a
 * currency symbol is a lie rather than an approximation.
 *
 * The caller's options are to read `byCurrency()`, to name one with `totalIn()`, or to convert --
 * which this layer deliberately does not do, because `CurrencyRate` is a rate at a moment and
 * choosing the moment is a finance decision, not a reporting one.
 */
final class MixedCurrencyException extends RuntimeException
{
    public static function forMetric(string $metric, array $currencies): self
    {
        sort($currencies);

        return new self(
            'The metric "' . $metric . '" holds amounts in ' . count($currencies) . ' currencies ('
            . implode(', ', $currencies) . ') and nothing converted them. Read byCurrency(), or '
            . 'name one with totalIn(). Adding them would produce a number with no unit.'
        );
    }
}
