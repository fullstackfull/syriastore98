<?php

namespace App\Support\Metrics;

/**
 * THE MERCHANT DASHBOARD'S HEADLINE MONEY TILE, AS A CARRIER. GAP-152 / P05-04, F257, P5-R7-C.
 * =================================================================================================
 *
 * WHY THIS CLASS EXISTS, and it is one omission rather than a tidying. The GAP-152 row names it in
 * terms: "`dashboard/overview.blade.php` composes the Revenue tile's hint inside an `@php` block
 * from the `MetricResult` it is handed, and `GrossMerchandiseValue`'s own `FLOAT_PRECISION` is not
 * in it -- the merchant dashboard's headline money figure states no precision limit."
 *
 * MEASURED AGAIN BEFORE THE MOVE. `GrossMerchandiseValue::for()` attaches
 * `MetricCaveat::FLOAT_PRECISION` unconditionally, because GMV is the one money metric that cannot
 * be aggregated on `store_order_financials`' `DECIMAL(15,2)` and has to come off
 * `orders.final_price`, which is FLOAT. The `@php` block consulted the result's `currencies()` and
 * `totalIn()` and never its `caveats()`, so the one thing the metric knew about its own limits
 * reached the busiest money figure in the product on no screen at all.
 *
 * THE WORDS ARE NOT REWRITTEN. Every sentence below is moved from the view BYTE FOR BYTE, including
 * the two that are owner-decision text -- the cancellation exclusion and the F257 returns clause --
 * because `DashboardOrderValueCurrencyTest`'s own docblock records that `grep` for "Returned orders
 * are still included" returned NOTHING before that file existed, so a rewrite here could have
 * dropped an owner's sentence with the Feature gate green. What changes is WHERE the composition
 * happens and that the caveat list is now consulted.
 *
 * NOTHING HERE COMPUTES A FIGURE. No money is summed, no currency converted, no inclusion rule
 * decided. It is handed a result a metric produced and a value a formatter already formatted, and
 * it decides which currency the headline is in -- the same decision the view made, in the same
 * words, from the same two inputs.
 */
final class DashboardOrderValueFigure
{
    /**
     * @param  ?MetricResult  $result  the GMV result, already folded through
     *                                 `assumingUnknownCurrencyIs()`, or null for a principal with
     *                                 no store -- for whom the tile is not rendered at all
     * @param  string  $storeCurrencyCode  the store's own code, NORMALISED through
     *                                     `CurrencySymbol::code()`, so '' means "not a code"
     * @param  ?string  $formattedValue  the headline as the page prints it, or null for "we cannot
     *                                   say" -- more than one currency and no configured one to
     *                                   choose between them
     */
    public static function make(
        ?MetricResult $result,
        string $storeCurrencyCode,
        ?string $formattedValue,
        string $period,
    ): CaveatedFigure {
        /* `unknown` is a BUCKET KEY, not a currency -- `orders.currency_code` is not backfilled, so
           it holds every order placed before the stamp landed. The caller folds it into the store's
           currency when there is one; when there is not, nothing can be assumed, and naming it here
           would print "1,250.00 unknown" to the merchant as though it were a currency they trade in. */
        $currencies = $result?->currencies() ?? [];

        /* WHICH CURRENCY THE HEADLINE IS IN -- null when there is no headline. The "others" list is
           then exactly the money the headline LEAVES OUT, which is the only list a merchant can act
           on. Deriving it from `$storeCurrencyCode` alone was wrong in both directions: a store
           whose `defult_currancy` is a symbol normalises to '' and would have seen its own single
           currency listed back as "another currency", and a mixed store with no configured currency
           shows no headline at all, where the word "Also" claims one. */
        $included = $storeCurrencyCode !== ''
            ? $storeCurrencyCode
            : (count($currencies) === 1 ? ($currencies[0] ?? null) : null);

        /* THE `unknown` BUCKET STAYS IN THIS LIST, and that is deliberate. Filtering it out would
           drop it from the merchant's view silently -- the defect this tile exists to fix, arriving
           by the other door. `MoneyText::currencyLabel()` renders the bucket as "Currency not
           recorded" rather than offering the internal token as a currency. */
        $others = array_values(array_filter(
            $currencies,
            static fn ($code): bool => $code !== '' && $code !== $included
        ));

        $meaning = [
            $storeCurrencyCode === ''
                ? __('The total value of orders placed, excluding orders that were cancelled. Returned orders are still included. This is not payments received, and not profit.')
                : __('The total value of orders placed, counted in :code, excluding orders that were cancelled. Returned orders are still included. This is not payments received, and not profit.', ['code' => $storeCurrencyCode]),
        ];

        $statedAs = [];

        if ($others !== [] && $result !== null) {
            $amounts = implode(', ', array_map(
                static fn ($code): string => MoneyText::amount($result->totalIn($code), $code),
                $others
            ));

            /* "Also" presupposes a headline. When there is none -- more than one currency and no
               configured store currency to pick between them -- the honest sentence is the one the
               rest of the codebase already uses for exactly this state, so no new string is
               introduced. */
            $listed = $formattedValue === null
                ? __('These amounts are in more than one currency and are not combined: :amounts.', ['amounts' => $amounts])
                : __('Also ordered in another currency, which is not added in and not converted: :amounts.', ['amounts' => $amounts]);

            $meaning[] = $listed;

            /*
             * DECLARED AS THE ANSWER TO `MIXED_CURRENCY` ONLY WHEN THE RESULT CARRIES IT.
             * `MetricResult::make()` attaches that caveat from the same data this branch counted,
             * so with a real second currency it always does -- but `CaveatedFigure` REFUSES a
             * statement for a caveat a figure does not carry, and on the merchant dashboard that
             * refusal would be an HTTP 500. `FinanceLedgerFigures::tile()` guards its own identical
             * declaration for this reason and says so. The sentence is in `$meaning` either way.
             */
            if ($result->hasCaveat(MetricCaveat::MIXED_CURRENCY)) {
                $statedAs[MetricCaveat::MIXED_CURRENCY] = $listed;
            }
        }

        /* A PRINCIPAL WITH NO STORE REACHES HERE WITH A NULL RESULT, and the tile is not rendered
           for them -- `$ssMayOrders` gates it. A carrier with no caveats is still built rather than
           returning null, so the view has one shape to render and no second branch to forget. */
        return $result === null
            ? CaveatedFigure::of($formattedValue, $period, [], $meaning)
            : CaveatedFigure::from($result, $formattedValue, $period, $meaning, $statedAs);
    }
}
