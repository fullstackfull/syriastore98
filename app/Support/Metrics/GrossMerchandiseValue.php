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
}
