<?php

namespace App\Support\Metrics;

/**
 * THE TWO LEDGER FIGURES ON /finance, ASSEMBLED FOR THE PAGE. Owner decisions D1 and D2, P5-16.
 * =================================================================================================
 *
 * `Payments received` and `Estimated gross profit` are the first figures in the product read through
 * the metric layer (P5-2) instead of computed inline. This class is the seam between that layer and
 * a Blade tile: it decides WHICH PERIOD to read (D1), reads it, and turns the answer into the four
 * things a tile needs -- a value, a period, a hint, and an honest "not available".
 *
 * THE PERIOD IS NEVER CHOSEN BY THE CALLER. It comes from `LedgerCoverage`, which finds the day from
 * which the ledger can vouch for every order. A merchant cannot ask for a window earlier than that
 * and be handed zero; the figure starts where the record does and says so (D1 Option A).
 *
 * WHAT A TILE MAY SAY, in the order the rules apply:
 *
 *   * nothing verified (orders exist, none has a ledger row) -> no figure, and the reason
 *   * more than one real currency                             -> no figure; nothing is converted
 *   * otherwise                                               -> the figure, in the store currency
 *
 * UNRECORDED CURRENCY is folded into the store currency (`MetricResult::assumingUnknownCurrencyIs`)
 * and SAID: the metric layer's own guidance is that wiring `->total()` into a tile without it is an
 * HTTP 500 for every store with history, because `orders.currency_code` was never backfilled. What
 * is assumed is that those orders were in the currency the store uses now, which is true for every
 * store that has not changed currency; the tile states the amount so a merchant can judge.
 *
 * NOTHING HERE WRITES. The metrics are read-only, this class only reads them, and
 * `FinanceLedgerFiguresTest::test_reading_the_ledger_changes_nothing_that_is_stored` pins it.
 */
final class FinanceLedgerFigures
{
    /**
     * @return array{coverage: LedgerCoverage, payments: array<string, mixed>, profit: array<string, mixed>}
     */
    public static function forStore(int $storeId, string $storeCurrency): array
    {
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

        if ($coverage->isComplete() || $from === null) {
            return __('all time');
        }

        return __('from :date', ['date' => $from->locale(app()->getLocale())->translatedFormat('j F Y')]);
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
                'hint' => __('No order has a financial record yet, so this figure cannot be shown.'),
            ];
        }

        $result = $result->assumingUnknownCurrencyIs($storeCurrency);
        $notes = [$meaning];

        if ($coverage->ordersWithoutRow() > 0) {
            $notes[] = __('Orders without a financial record are not included. How many: :count.', [
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

        $assumed = (float) ($result->notes()['currency_assumed_amount'] ?? 0.0);

        if ($assumed > 0.0) {
            $notes[] = __('Includes :amount :currency from orders placed before currency was recorded, counted in your store currency.', [
                'amount' => number_format($assumed, 2),
                'currency' => $storeCurrency,
            ]);
        }

        if (! $result->isSingleCurrency()) {
            // Two real currencies. Nothing is converted and no number is invented; the tile says so.
            $notes[] = __('These amounts are in more than one currency and are not combined.');

            return ['value' => null, 'period' => $period, 'hint' => implode(' ', $notes)];
        }

        return [
            'value' => number_format($result->total(), 2) . ' ' . ($result->currency() ?? $storeCurrency),
            'period' => $period,
            'hint' => implode(' ', $notes),
        ];
    }
}
