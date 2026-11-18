<?php

namespace App\Support\Metrics;

/**
 * THE TWO LEDGER FIGURES ON /finance, ASSEMBLED FOR THE PAGE. Owner decisions D1 and D2, P5-16.
 * =================================================================================================
 *
 * `Payments received` and `Estimated gross profit` are the first figures in the product read through
 * the metric layer (P5-2) instead of computed inline. This class is the seam between that layer and
 * a Blade tile: it decides WHICH PERIOD to read (D1), reads it, and turns the answer into the things a
 * tile needs -- a value, a period, a hint, and an honest "not available".
 *
 * THE PERIOD IS NEVER CHOSEN BY THE CALLER. It comes from `LedgerCoverage`, which finds the day from
 * which the ledger can vouch for every order. A merchant cannot ask for a window earlier than that
 * and be handed zero; the figure starts where the record does and says so (D1 Option A).
 *
 * WHAT A TILE MAY SAY, in the order the rules apply:
 *
 *   * nothing verified (orders exist, none has a ledger row)  -> no figure, and the reason
 *   * the verified period has not begun (the gap is today)    -> no figure, and the date it begins
 *   * more than one real currency                              -> no figure; the amounts are LISTED, never
 *                                                                 added and never converted
 *   * otherwise                                                -> the figure, in the currency of its rows
 *
 * THE LIMIT THE PERIOD CANNOT SEE, and the tile therefore says out loud. The period is set by ORDER date and
 * the figures are dated by PAYMENT, so a cash-on-delivery order placed before the start and collected after
 * it has no ledger row, never gets one (`OrderFinancialService::syncFromOrder` returns at once when there is
 * none) and is in neither figure. The tile says orders without a record are excluded EVEN IF PAID INSIDE THE
 * PERIOD -- the first version said only that they were "not included", which read as if the period itself were
 * complete. Found by independent review.
 *
 * UNRECORDED CURRENCY is folded into the store currency (`MetricResult::assumingUnknownCurrencyIs`) and
 * SAID: the metric layer's own guidance is that wiring `->total()` into a tile without it is an HTTP 500
 * for every store with history. What is assumed is that rows carrying no currency were in the currency the
 * store uses now, true for every store that has not changed currency. The note follows the metric's own
 * `CURRENCY_UNRECORDED` caveat rather than the SIGN of the folded amount: the first version tested
 * `> 0.0`, and a negative profit folded in silently dropped its disclosure. A BLANK store-currency setting
 * is treated as a missing one (`?? 'USD'` does not catch an empty string); folded into '' it would have
 * re-bucketed as "unknown" and printed "N.NN unknown".
 *
 * NO TRY/CATCH, BY CHOICE. The known failure (two currencies) is handled before `total()` is reached; any
 * other fault should be loud. Swallowing it would turn a latent bug into a merchant page that quietly shows
 * "not available" and hides why -- the pattern this codebase's own observer comment calls "the silence".
 *
 * NOTHING HERE WRITES. The metrics are read-only, this class only reads them, and
 * `FinanceLedgerFiguresTest::test_reading_the_ledger_changes_nothing_that_is_stored` pins it.
 */
final class FinanceLedgerFigures
{
    private const FALLBACK_CURRENCY = 'USD';

    /**
     * @return array{coverage: LedgerCoverage, payments: array<string, mixed>, profit: array<string, mixed>}
     */
    public static function forStore(int $storeId, string $storeCurrency): array
    {
        $storeCurrency = trim($storeCurrency) === '' ? self::FALLBACK_CURRENCY : trim($storeCurrency);

        $coverage = LedgerCoverage::forStore($storeId);
        $window = $coverage->window();
        $scope = MetricScope::store($storeId);
        $period = self::periodLabel($coverage);

        return [
            'coverage' => $coverage,
            'payments' => self::tile(
                $window === null ? null : (new CollectedCustomerPayments())->for($scope, $window),
                $storeCurrency,
                $period,
                $coverage,
                __('Money customers actually paid, including orders cancelled or refunded after payment. It is not order value and not profit.'),
                null,
            ),
            'profit' => self::tile(
                $window === null ? null : (new StoreRevenueMargin(true))->for($scope, $window),
                $storeCurrency,
                $period,
                $coverage,
                __('Sales value minus product cost for settled orders. Excludes shipping and tax.'),
                static fn (MetricResult $result): ?string => ($n = (int) ($result->notes()['rows_without_cost'] ?? 0)) > 0
                    ? __('Settled orders with no cost price recorded: :count. Profit is overstated for those.', ['count' => number_format($n)])
                    : null,
            ),
        ];
    }

    private static function periodLabel(LedgerCoverage $coverage): string
    {
        if ($coverage->hasNothingVerified()) {
            return __('no financial records yet');
        }

        $from = $coverage->verifiedFrom();

        if ($from === null) {
            return __('all time');
        }

        return __('from :date', ['date' => self::date($from)]);
    }

    private static function date(\Carbon\CarbonInterface $date): string
    {
        return $date->locale(app()->getLocale())->translatedFormat('j F Y');
    }

    /**
     * @param  null|callable(MetricResult): ?string  $extra  a figure-specific limitation, or null for none
     * @return array{value: ?string, period: string, hint: string}
     */
    private static function tile(
        ?MetricResult $result,
        string $storeCurrency,
        string $period,
        LedgerCoverage $coverage,
        string $meaning,
        ?callable $extra,
    ): array {
        if ($result === null) {
            return [
                'value' => null,
                'period' => $period,
                // "Nothing verified" outranks "starts in the future": with no row at all there is no record
                // that could be "complete from" any date.
                'hint' => (! $coverage->hasNothingVerified() && $coverage->startsInTheFuture())
                    ? __('The financial record is complete from :date, so there is nothing to show yet.', [
                        'date' => self::date($coverage->verifiedFrom()),
                    ])
                    : __('No order has a financial record yet, so this figure cannot be shown.'),
            ];
        }

        $result = $result->assumingUnknownCurrencyIs($storeCurrency);
        $notes = [$meaning];

        if ($coverage->ordersWithoutRow() > 0) {
            $notes[] = __('Orders without a financial record are not included, even if they were paid inside this period. How many: :count.', [
                'count' => number_format($coverage->ordersWithoutRow()),
            ]);
        }

        if ($coverage->undatedOrdersWithoutRow() > 0) {
            $notes[] = __('Orders with no date and no financial record cannot be placed in any period. How many: :count.', [
                'count' => number_format($coverage->undatedOrdersWithoutRow()),
            ]);
        }

        if ($extra !== null && ($line = $extra($result)) !== null) {
            $notes[] = $line;
        }

        if ($result->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED)) {
            $notes[] = __('Includes :amount :currency from records that carry no currency, counted in your store currency.', [
                'amount' => number_format((float) ($result->notes()['currency_assumed_amount'] ?? 0.0), 2),
                'currency' => $storeCurrency,
            ]);
        }

        if (! $result->isSingleCurrency()) {
            // Two real currencies. Nothing is converted and no number is invented; the amounts are LISTED so
            // the tile is not a dead end for a store that changed currency.
            $listed = [];
            foreach ($result->byCurrency() as $currency => $amount) {
                $listed[] = number_format((float) $amount, 2).' '.$currency;
            }

            $notes[] = __('These amounts are in more than one currency and are not combined: :amounts.', [
                'amounts' => implode(', ', $listed),
            ]);

            return ['value' => null, 'period' => $period, 'hint' => implode(' ', $notes)];
        }

        return [
            'value' => number_format($result->total(), 2) . ' ' . ($result->currency() ?? $storeCurrency),
            'period' => $period,
            'hint' => implode(' ', $notes),
        ];
    }
}
