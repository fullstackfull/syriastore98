<?php

namespace App\Support\Metrics;

/**
 * SECTION 9 -- Coupons actually consumed. **BLOCKED AS AN AUTHORITATIVE FIGURE, BY INSTRUCTION.**
 *
 * Owner: "Review P4-F134 and P4-F135 before using coupon-redemption records as authoritative
 * data." Both are open, and they pull in OPPOSITE directions:
 *
 *   - P4-F134: `ordersave` may record NO coupon redemption at all, so coupon caps never see those
 *     orders -- redemption is UNDER-counted by an unknown amount;
 *   - P4-F135: a REFUSED coupon still writes `order_coupon_details` and `user_coupons`, burning
 *     the shopper's allowance -- redemption is OVER-counted by an unknown amount.
 *
 * Errors in both directions, neither quantified, so the true value cannot even be bounded. That is
 * why `MetricCaveat::COUPON_REDEMPTION_UNRELIABLE` is BLOCKING rather than qualifying, and why
 * every result this class can return has `isAuthoritative() === false`. "No metric in Phase 05 may
 * treat this as settled."
 *
 * The class exists rather than being omitted because campaign MANAGEMENT is not blocked -- only
 * outcome measurement is -- and a surface that needs the figure should get it with the warning
 * attached, not reimplement the query without one.
 *
 * `order_coupon_details` HAS NO `store_id` COLUMN -- measured. Its tenant predicate can only come
 * from the order it belongs to, so this metric joins from `orders` rather than querying the detail
 * table directly. A `whereIn('store_id', ...)` on that table would not compile, and a join without
 * the predicate would read every tenant's coupons.
 */
final class CouponRedemption extends Metric implements SingleFigureMetric
{
    public function key(): string
    {
        return 'coupon_redemption';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §9 (BLOCKED)';
    }

    public function for(MetricScope $scope, MetricWindow $window): MetricResult
    {
        $query = $this->orders($scope)
            ->join(
                'order_coupon_details',
                'order_coupon_details.order_id',
                '=',
                'orders.id'
            );

        $window->applyTo($query, 'orders.order_date');

        $aggregate = $this->sumByCurrency(
            $query,
            'orders.currency_code',
            'order_coupon_details.coupon_discount_amount'
        );

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_SETTLEMENT,
            'orders.order_date',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            [
                MetricCaveat::COUPON_REDEMPTION_UNRELIABLE,
                // coupon_discount_amount is FLOAT on order_coupon_details.
                MetricCaveat::FLOAT_PRECISION,
            ],
            [
                'blocked_by' => ['P4-F134', 'P4-F135'],
                'error_direction' => 'both, neither quantified',
                'authoritative' => false,
            ],
        );
    }
}
