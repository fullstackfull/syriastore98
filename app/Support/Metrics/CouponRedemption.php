<?php

namespace App\Support\Metrics;

/**
 * SECTION 9 -- Coupons actually consumed. **AUTHORITATIVE PER WINDOW, NOT IN GENERAL.** P5-5.
 *
 * Owner: "Review P4-F134 and P4-F135 before using coupon-redemption records as authoritative
 * data." BOTH ARE NOW REVIEWED AND FIXED (2026-11-20), so that instruction is discharged -- and
 * the honest answer it yields is neither "still blocked" nor "authoritative". It is per window.
 *
 * WHAT THEY WERE, because the pre-fix period still carries both errors and this class still has to
 * say so:
 *
 *   - P4-F134: `ordersave` recorded NO coupon redemption at all, for every order -- redemption is
 *     UNDER-counted for that period by an unknown amount. (Measured: a coupon limited to one use
 *     per shopper discounted two orders for the same shopper.)
 *   - P4-F135: a REFUSED coupon still wrote `order_coupon_details` and `user_coupons`, burning the
 *     shopper's allowance -- redemption is OVER-counted by an unknown amount.
 *
 * WHY THE FIX DOES NOT MAKE THE WHOLE SERIES SOUND. It is forward-only: historical rows are
 * deliberately NOT backfilled, because writing financial records for past orders is forbidden by
 * the rule that governs the ledger (owner decision D1). So a window reaching back into the pre-fix
 * period carries errors in both directions, unbounded, exactly as before -- and keeps the BLOCKING
 * `MetricCaveat::COUPON_REDEMPTION_UNRELIABLE`. A window lying entirely inside a store's complete
 * period is sound and gets the QUALIFYING `COUPON_REDEMPTION_COVERAGE` instead, which says where
 * the records start rather than refusing the figure.
 *
 * `App\Support\Metrics\CouponRedemptionCoverage` finds that start, per store, from the data: the
 * day after the latest DISCOUNTED order with no coupon-detail row. It is `LedgerCoverage`'s rule
 * applied to a different pair of tables, and it is derived rather than a hard-coded cut-over date
 * because the fix ships when it is deployed, a store that never used the API has nothing missing,
 * and an order lost to some later failure would be invisible to a constant.
 *
 * The class existed before any of this rather than being omitted, because campaign MANAGEMENT was
 * never blocked -- only outcome measurement was -- and a surface that needs the figure should get
 * it with the right warning attached rather than reimplement the query without one.
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
        return 'PHASE-05-METRIC-DEFINITIONS.md §9';
    }

    /**
     * IS THIS WINDOW INSIDE EVERY SCOPED STORE'S COMPLETE PERIOD? P5-5.
     *
     * The owner instruction was to review P4-F134 and P4-F135 before treating these records as
     * authoritative. Both are now reviewed and fixed, and the honest consequence is neither "still
     * blocked" nor "authoritative" but PER WINDOW: the fix is forward-only and history is not
     * backfilled, so a window after a store's last unrecorded redemption is sound and one reaching
     * behind it is not.
     *
     * THREE WAYS THIS ANSWERS NO, each of them a real case rather than defensiveness:
     *
     *   * an ALL-TIME window necessarily includes the pre-fix period, so it can never be sound;
     *   * a window with no `from()` is open-ended backwards and is the same thing;
     *   * in a CROSS-TENANT scope every store must be sound, because one store still carrying
     *     unrecorded redemptions makes the summed figure carry them too. Decided per store and
     *     ANDed -- taking the best store's coverage would be the kind of aggregate that is right
     *     about nothing.
     *
     * It fails CLOSED: anything it cannot establish keeps the blocking caveat.
     */
    private function windowIsFullyRecorded(MetricScope $scope, MetricWindow $window): bool
    {
        if ($window->isAllTime()) {
            return false;
        }

        $from = $window->from();

        if ($from === null) {
            return false;
        }

        $storeIds = $scope->storeIds();

        if ($storeIds === []) {
            return false;
        }

        foreach ($storeIds as $storeId) {
            $coverage = CouponRedemptionCoverage::forStore((int) $storeId);

            if ($coverage->isComplete()) {
                continue;
            }

            $completeFrom = $coverage->completeFrom();

            // `hasNothingComplete()` lands here with a null start: nothing can be said for it.
            if ($completeFrom === null || $from->lt($completeFrom)) {
                return false;
            }
        }

        return true;
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

        $fullyRecorded = $this->windowIsFullyRecorded($scope, $window);

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_SETTLEMENT,
            'orders.order_date',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            [
                /*
                 * ONE OF TWO, chosen from the data rather than fixed. The blocking caveat is still
                 * the right answer for a window reaching back before the P4-F134/F135 recording
                 * fix -- that period really is under-counted AND over-counted, and history is not
                 * backfilled. For a window inside the complete period it would be a false warning,
                 * and a merchant taught to ignore caveats is worse off than one who gets none.
                 */
                $fullyRecorded
                    ? MetricCaveat::COUPON_REDEMPTION_COVERAGE
                    : MetricCaveat::COUPON_REDEMPTION_UNRELIABLE,
                // coupon_discount_amount is FLOAT on order_coupon_details.
                MetricCaveat::FLOAT_PRECISION,
            ],
            $fullyRecorded
                ? [
                    'recording_fixed_by' => ['P4-F134', 'P4-F135'],
                    'coverage' => 'the window lies inside every scoped store\'s complete period',
                    'history_backfilled' => false,
                    'authoritative' => true,
                ]
                : [
                    'blocked_by' => ['P4-F134', 'P4-F135'],
                    'error_direction' => 'both, neither quantified',
                    'reason' => 'the window reaches back before the recording fix, which is not backfilled',
                    'authoritative' => false,
                ],
        );
    }
}
