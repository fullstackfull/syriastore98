<?php

namespace App\Support\Metrics;

/**
 * SECTION 1 -- Gross merchandise value.
 *
 * The total customer-facing value of orders PLACED in the period, before any settlement,
 * cancellation or refund is considered. **A demand measure, not a money measure**, and the only
 * metric for which `SUM(orders.final_price)` is a legitimate computation.
 *
 * WHETHER CANCELLATIONS COUNT IS A CHOICE, NOT A DEFAULT. GMV conventionally includes them; this
 * platform has no prior convention. Section 1 is explicit: "The report must label which it used.
 * Never silently pick one." So the flag is constructor-mandatory in spirit -- it has a default,
 * but whichever way it falls the answer carries `notes()['cancellations']`, and a surface that
 * prints the figure without the label is the defect this design exists to make visible.
 */
final class GrossMerchandiseValue extends Metric implements SingleFigureMetric
{
    public function __construct(private readonly bool $includeCancelled = true)
    {
    }

    public function key(): string
    {
        return 'gmv';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §1';
    }

    public function for(MetricScope $scope, MetricWindow $window): MetricResult
    {
        $query = $this->placedOrders($scope, $window);

        $aggregate = $this->sumByCurrency(
            $query,
            'orders.currency_code',
            'orders.final_price'
        );

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_SETTLEMENT,
            'orders.order_date',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            // `orders.final_price` is FLOAT -- about seven significant digits. GMV is the one
            // metric with no DECIMAL alternative, because an order that was never given a
            // financial row has no entitlement figure at all.
            [MetricCaveat::FLOAT_PRECISION],
            ['cancellations' => $this->includeCancelled ? 'included' : 'excluded'],
        );
    }

    /**
     * THE SAME FIGURE, PER STORE AND PER CURRENCY -- for a ranking, which is the one thing a
     * `MetricResult` cannot express.
     *
     * It is the same metric and deliberately not a second class: same record, same date column,
     * same cancellations flag, same FLOAT caveat, same section of the definitions document. A
     * ranking that reached for its own SQL is how the operator dashboard's "Top Selling Store"
     * panel came to disagree with every other figure about currency (GAP-162): it summed
     * `final_price` across every store and ordered by the raw number, so a store trading in a
     * large-nominal currency outranked every dollar store by arithmetic alone.
     *
     * GROUPED BY CURRENCY AS WELL AS BY STORE, AND THE CALLER MUST KEEP THEM APART. Nothing in this
     * schema stamps an exchange rate onto an order, so two amounts in different currencies are
     * not comparable -- comparing them is the same error as adding them, which
     * `MetricResult::total()` already refuses. This method returns the buckets and ranks nothing.
     *
     * A null `currency` means the order never recorded one. It is returned as null rather than
     * guessed here, because the honest fallback is the STORE's own currency and this class does
     * not read settings; see `PlatformAnalyticsFigures::topStoresByOrderValue()`.
     *
     * @return list<array{store_id: int, currency: ?string, amount: float, orders: int}>
     */
    public function perStore(MetricScope $scope, MetricWindow $window): array
    {
        $rows = $this->placedOrders($scope, $window)
            ->selectRaw(
                'orders.store_id as metric_store, '
                . 'orders.currency_code as metric_currency, '
                . 'SUM(orders.final_price) as metric_amount, '
                . 'COUNT(*) as metric_rows'
            )
            ->groupBy('orders.store_id', 'orders.currency_code')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $currency = $row->metric_currency;

            $buckets[] = [
                'store_id' => (int) $row->metric_store,
                'currency' => ($currency === null || trim((string) $currency) === '') ? null : (string) $currency,
                'amount' => round((float) $row->metric_amount, 2),
                'orders' => (int) $row->metric_rows,
            ];
        }

        return $buckets;
    }

    /** The orders this metric counts: the scope, the window, and the cancellations choice. */
    private function placedOrders(MetricScope $scope, MetricWindow $window): \Illuminate\Database\Query\Builder
    {
        $query = $this->orders($scope);
        $window->applyTo($query, 'orders.order_date');

        if (! $this->includeCancelled) {
            /*
             * THE NULL LIMB IS NOT REACHABLE UNDER THE MIGRATION AS DECLARED, AND IS KEPT ANYWAY.
             *
             * Measured: `create_orders_table` declares `delivered_status` as
             * `integer()->default(0)` -- NOT NULL -- and SQLite rejects a null insert, so the
             * verification test for this limb could not even be written. It stays for two reasons.
             * CLAUDE.md's first schema rule is that THE MIGRATIONS ARE NOT THE SCHEMA: this file
             * has already been wrong about a column's presence in both directions, and nothing
             * here has measured the production `orders` table. And in SQL `NULL != 2` is NULL,
             * which is not true, so if the column ever is null -- in production, or through a left
             * join added later -- a plain inequality would silently drop those orders from a GMV
             * figure rather than fail. The limb costs one predicate; the failure it prevents is
             * invisible.
             */
            $query->where(function ($notCancelled) {
                $notCancelled->whereNull('orders.delivered_status')
                    ->orWhere('orders.delivered_status', '!=', 2);
            });
        }

        return $query;
    }
}
