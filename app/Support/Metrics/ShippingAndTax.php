<?php

namespace App\Support\Metrics;

use InvalidArgumentException;

/**
 * SECTION 10 -- Amounts collected on the merchant's behalf and largely passed through.
 *
 * READ FROM THE FINANCIAL ROW, **NOT** FROM `orders.delivery_price` / `orders.tax_price`, and the
 * reason is a measured defect rather than a preference: `RefundAmonut` zeroes
 * `orders.delivery_price` on a CLIENT-SUPPLIED BOOLEAN without recomputing `final_price`, so after
 * one refund request the order's own components stop agreeing with its own total. The financial
 * row is the snapshot taken at order time and does not move.
 *
 * Two figures, not one, and they are not interchangeable -- tax is remitted, shipping is paid to a
 * carrier. `MultiFigureMetric` keeps them apart for the same reason section 3's three sub-metrics
 * are kept apart.
 */
final class ShippingAndTax extends Metric implements MultiFigureMetric
{
    public const SHIPPING = 'shipping';

    public const TAX = 'tax';

    public const SUB_METRICS = [self::SHIPPING, self::TAX];

    private const COLUMNS = [
        self::SHIPPING => 'store_order_financials.shipping_amount',
        self::TAX => 'store_order_financials.tax_amount',
    ];

    public function key(): string
    {
        return 'shipping_and_tax';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §10';
    }

    /** @return array<string, MetricResult> */
    public function all(MetricScope $scope, MetricWindow $window): array
    {
        $results = [];

        foreach (self::SUB_METRICS as $subMetric) {
            $results[$subMetric] = $this->subMetric($subMetric, $scope, $window);
        }

        return $results;
    }

    public function subMetric(string $subMetric, MetricScope $scope, MetricWindow $window): MetricResult
    {
        if (! array_key_exists($subMetric, self::COLUMNS)) {
            throw new InvalidArgumentException(
                'Unknown shipping/tax sub-metric "' . $subMetric . '". Section 10 defines: '
                . implode(', ', self::SUB_METRICS) . '.'
            );
        }

        $query = $this->financials($scope);
        $window->applyTo($query, 'store_order_financials.created_at');

        $aggregate = $this->sumByCurrency(
            $query,
            'store_order_financials.currency_code',
            self::COLUMNS[$subMetric]
        );

        return MetricResult::make(
            $this->key() . '.' . $subMetric,
            MetricResult::RECORD_ENTITLEMENT,
            'store_order_financials.created_at',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            [],
            [
                'sub_metric' => $subMetric,
                'source_column' => self::COLUMNS[$subMetric],
                'orders_columns_not_used' => ['orders.delivery_price', 'orders.tax_price'],
            ],
        );
    }
}
