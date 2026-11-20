<?php

namespace App\Support\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FROM WHEN IS A STORE'S COUPON-REDEMPTION RECORD COMPLETE? P5-5, after P4-F134 / P4-F135.
 * =================================================================================================
 *
 * WHY THIS EXISTS. The owner's instruction on `CouponRedemption` was "review P4-F134 and P4-F135
 * before using coupon-redemption records as authoritative data". Both are now measured and fixed,
 * and the review changes what can honestly be said -- but not to "authoritative", because the fix is
 * forward-only:
 *
 *   * F134 -- `order-save` recorded NOTHING for any order. Every discounted order placed through
 *     that route before the fix has no `order_coupon_details` row and no `user_coupons` row.
 *   * F135 -- `place_order` recorded refused coupons, so some pre-fix rows should not be there.
 *
 * NOTHING IS BACKFILLED, and that is deliberate rather than unfinished: writing redemption rows for
 * historical orders now would be a backfill of financial records, forbidden by the same rule that
 * governs the ledger (owner decision D1). So the figure gets a START, exactly as the ledger figures
 * do, and this class finds it.
 *
 * THE RULE, AND IT IS `LedgerCoverage`'S RULE APPLIED TO A DIFFERENT PAIR OF TABLES. The complete
 * period starts on the day AFTER the latest DISCOUNTED order that has no coupon-detail row.
 *
 *   * no discounted orders at all              -> complete; there is nothing to have missed
 *   * every discounted order has a detail row  -> complete; all-time is sound
 *   * some lack one, some do not               -> complete from the day after the latest that lacks
 *   * discounted orders exist and NONE has one -> nothing is complete; there is no period to state
 *
 * IT IS DERIVED, NOT A CUT-OVER DATE. A hard-coded "fixed on 2026-11-20" would be wrong for three
 * separate reasons: the fix ships when it is deployed and not when it was written; a store that
 * never used the API has nothing missing and should not be told it has; and one order lost to some
 * other failure after the fix would still be invisible. Counting answers all three.
 *
 * THE UNIVERSE IS DISCOUNTED ORDERS ONLY (`orders.coupon_price > 0`). An order that used no coupon
 * needs no redemption row, so including it would make every store's record permanently incomplete.
 *
 * TENANCY, AND THE ONE WAY THIS DIFFERS FROM `LedgerCoverage`: **`order_coupon_details` HAS NO
 * `store_id` COLUMN** -- measured, and `CouponRedemption`'s own docblock records it. So the join can
 * only be on `order_id`, and the tenant predicate comes from `orders.store_id` alone. `LedgerCoverage`
 * names the store on both sides of its join precisely because `store_order_financials` HAS the column
 * and a row misfiled under another store must not count as cover; that defence is unavailable here
 * and its absence is stated rather than implied. It is not a hole in this class: `order_coupon_details`
 * rows are reachable only through their order, which is scoped.
 *
 * NO GLOBAL SCOPE IS IN PLAY. The query builder is used on purpose, like every metric in this
 * namespace, and the store is an explicit predicate.
 */
final class CouponRedemptionCoverage
{
    private function __construct(
        private readonly int $storeId,
        private readonly int $discountedOrders,
        private readonly int $withoutRow,
        private readonly int $undatedWithoutRow,
        private readonly ?CarbonImmutable $completeFrom,
    ) {}

    public static function forStore(int $storeId): self
    {
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('CouponRedemptionCoverage needs a positive store id, got '.$storeId.'.');
        }

        /*
         * ONE STATEMENT, for the reason LedgerCoverage gives: the cost scales with the store's orders
         * and four separate queries walked them four times. `COUNT(DISTINCT ...)` rather than
         * `COUNT(*)` because a join that ever fanned out (two coupons on one order) must not count
         * that order twice.
         */
        $row = DB::table('orders')
            ->where('orders.store_id', $storeId)
            ->where('orders.coupon_price', '>', 0)
            ->leftJoin('order_coupon_details', 'order_coupon_details.order_id', '=', 'orders.id')
            ->selectRaw(
                'COUNT(DISTINCT orders.id) as discounted_orders, '
                .'COUNT(DISTINCT CASE WHEN order_coupon_details.id IS NULL THEN orders.id END) as orders_without_row, '
                .'COUNT(DISTINCT CASE WHEN order_coupon_details.id IS NULL AND orders.order_date IS NULL THEN orders.id END) as undated_without_row, '
                .'MAX(CASE WHEN order_coupon_details.id IS NULL THEN orders.order_date END) as latest_unrowed'
            )
            ->first();

        $total = (int) ($row->discounted_orders ?? 0);
        $withoutRow = (int) ($row->orders_without_row ?? 0);
        $undated = (int) ($row->undated_without_row ?? 0);
        $latest = $row->latest_unrowed ?? null;

        if ($withoutRow === 0) {
            return new self($storeId, $total, 0, 0, null);
        }

        /*
         * THE START IS THE NEXT DAY, NOT THE NEXT SECOND -- LedgerCoverage's reasoning, unchanged: a
         * day containing an unrecorded redemption is a day whose total cannot be given, so the period
         * begins at the following midnight and never includes a partly-covered day.
         *
         * AN UNDATED ORDER WITH NO ROW cannot move the start (`orders.order_date` is nullable) and is
         * counted separately instead of being silently ignored or allowed to withhold every figure.
         */
        $from = $latest === null
            ? null
            : CarbonImmutable::parse((string) $latest)->addDay()->startOfDay();

        return new self($storeId, $total, $withoutRow, $undated, $from);
    }

    /** Every discounted order has a detail row, or there are none: all-time is sound. */
    public function isComplete(): bool
    {
        return $this->withoutRow === 0;
    }

    /**
     * Discounted orders exist and NOT ONE has a detail row, so there is no period to state.
     *
     * Distinct from `isComplete()` being false: a consumer must be able to tell "sound from March"
     * from "nothing can be said", and the second is what a store that only ever used the pre-fix
     * `order-save` route looks like.
     */
    public function hasNothingComplete(): bool
    {
        return $this->withoutRow > 0 && $this->completeFrom === null;
    }

    public function completeFrom(): ?CarbonImmutable
    {
        return $this->completeFrom;
    }

    public function discountedOrders(): int
    {
        return $this->discountedOrders;
    }

    /** Discounted orders with no coupon-detail row: the size of what is missing, not a guess at it. */
    public function ordersWithoutRow(): int
    {
        return $this->withoutRow;
    }

    /** Of those, the ones that cannot be placed in any window because they carry no `order_date`. */
    public function undatedOrdersWithoutRow(): int
    {
        return $this->undatedWithoutRow;
    }

    public function storeId(): int
    {
        return $this->storeId;
    }
}
