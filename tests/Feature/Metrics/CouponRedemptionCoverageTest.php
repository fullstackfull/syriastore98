<?php

namespace Tests\Feature\Metrics;

use App\Support\Metrics\CouponRedemptionCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * FROM WHEN IS A STORE'S COUPON-REDEMPTION RECORD COMPLETE? P5-5.
 *
 * The class answers a question that only exists because P4-F134's fix is forward-only: every
 * discounted order placed through `order-save` before it has no `order_coupon_details` row, and
 * backfilling financial records is forbidden (owner decision D1's rule). So a redemption figure
 * needs a START, derived from the data rather than hard-coded, and these cases pin the derivation.
 *
 * THE UNIVERSE IS DISCOUNTED ORDERS, and the case that proves it matters is
 * `test_an_order_that_used_no_coupon_does_not_make_the_record_incomplete`: counting every order
 * would leave every store permanently incomplete, which would be a figure nobody could ever show.
 */
final class CouponRedemptionCoverageTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A discounted order, optionally with its coupon-detail row. */
    private function discountedOrder($store, ?string $date, bool $withDetailRow): int
    {
        $order = $this->makeOrder($store, [
            'coupon_price' => 10.00,
            'order_date' => $date,
        ]);

        if ($withDetailRow) {
            DB::table('order_coupon_details')->insert([
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'coupon_id' => 1,
                'coupon_name' => 'SAVE10', 'coupon_code' => 'SAVE10',
                'coupon_discount_type' => 'percentage', 'coupon_discount_number' => 10,
                'coupon_discount_amount' => 10.00, 'coupon_final_amount' => 90.00,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (int) $order->id;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The four states
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_store_with_no_discounted_orders_is_complete(): void
    {
        [, $store] = $this->makeTenant();

        // An ordinary, undiscounted order, so the store is not simply empty.
        $this->makeOrder($store, ['coupon_price' => 0, 'order_date' => '2026-11-01']);

        $coverage = CouponRedemptionCoverage::forStore((int) $store->id);

        $this->assertTrue($coverage->isComplete(),
            'a store that has never discounted an order has nothing it could have failed to record');
        $this->assertFalse($coverage->hasNothingComplete());
        $this->assertNull($coverage->completeFrom(), 'a complete record needs no start date');
        $this->assertSame(0, $coverage->discountedOrders());
    }

    public function test_every_discounted_order_having_a_row_is_complete_for_all_time(): void
    {
        [, $store] = $this->makeTenant();

        $this->discountedOrder($store, '2026-11-01', true);
        $this->discountedOrder($store, '2026-11-05', true);

        $coverage = CouponRedemptionCoverage::forStore((int) $store->id);

        $this->assertTrue($coverage->isComplete());
        $this->assertNull($coverage->completeFrom());
        $this->assertSame(2, $coverage->discountedOrders());
        $this->assertSame(0, $coverage->ordersWithoutRow());
    }

    /** THE RULE: the day AFTER the latest discounted order that has no row. */
    public function test_the_record_is_complete_from_the_day_after_the_latest_unrecorded_redemption(): void
    {
        [, $store] = $this->makeTenant();

        $this->discountedOrder($store, '2026-10-01', false);   // pre-fix, unrecorded
        $this->discountedOrder($store, '2026-10-09', false);   // the LATEST unrecorded
        $this->discountedOrder($store, '2026-11-02', true);    // recorded

        $coverage = CouponRedemptionCoverage::forStore((int) $store->id);

        $this->assertFalse($coverage->isComplete());
        $this->assertFalse($coverage->hasNothingComplete(),
            'there IS a period that can be stated, so this is not the nothing-complete case');
        $this->assertSame(2, $coverage->ordersWithoutRow());
        $this->assertSame('2026-10-10', $coverage->completeFrom()?->toDateString(),
            'the period must start the day AFTER the latest unrecorded redemption: a day that '
            . 'contains one is a day whose total cannot be given');
    }

    public function test_a_store_whose_every_discounted_order_is_unrecorded_can_state_nothing(): void
    {
        [, $store] = $this->makeTenant();

        // All undated, so there is not even a latest date to start after.
        $this->discountedOrder($store, null, false);
        $this->discountedOrder($store, null, false);

        $coverage = CouponRedemptionCoverage::forStore((int) $store->id);

        $this->assertFalse($coverage->isComplete());
        $this->assertTrue($coverage->hasNothingComplete(),
            'a consumer must be able to tell "sound from October" from "nothing can be said"');
        $this->assertNull($coverage->completeFrom());
        $this->assertSame(2, $coverage->undatedOrdersWithoutRow());
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The distinctions that make the figure honest rather than merely computed
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * An order that used no coupon needs no redemption row. Counting it would make every store
     * permanently incomplete, i.e. produce a figure that could never be shown to anybody.
     */
    public function test_an_order_that_used_no_coupon_does_not_make_the_record_incomplete(): void
    {
        [, $store] = $this->makeTenant();

        $this->discountedOrder($store, '2026-11-01', true);
        $this->makeOrder($store, ['coupon_price' => 0, 'order_date' => '2026-11-10']);   // no coupon, no row
        $this->makeOrder($store, ['coupon_price' => 0, 'order_date' => '2026-11-11']);

        $coverage = CouponRedemptionCoverage::forStore((int) $store->id);

        $this->assertTrue($coverage->isComplete(),
            'an undiscounted order was treated as a missing redemption, which would leave every '
            . 'store permanently incomplete');
        $this->assertSame(1, $coverage->discountedOrders(),
            'only discounted orders belong in the universe this figure is measured over');
    }

    /**
     * An UNDATED unrecorded order cannot be placed in any window, so it must not move the start —
     * but it must be disclosed rather than silently dropped. `orders.order_date` is nullable.
     */
    public function test_an_undated_unrecorded_order_is_counted_but_does_not_move_the_start(): void
    {
        [, $store] = $this->makeTenant();

        $this->discountedOrder($store, '2026-10-09', false);
        $this->discountedOrder($store, null, false);

        $coverage = CouponRedemptionCoverage::forStore((int) $store->id);

        $this->assertSame('2026-10-10', $coverage->completeFrom()?->toDateString(),
            'an order with no date moved the start, which it cannot do honestly');
        $this->assertSame(2, $coverage->ordersWithoutRow());
        $this->assertSame(1, $coverage->undatedOrdersWithoutRow(),
            'the undated order was dropped silently instead of being disclosed');
    }

    /** Two coupon rows on one order must not make it count twice. */
    public function test_an_order_with_two_coupon_rows_counts_once(): void
    {
        [, $store] = $this->makeTenant();

        $orderId = $this->discountedOrder($store, '2026-11-01', true);

        DB::table('order_coupon_details')->insert([
            'order_id' => $orderId, 'product_order_id' => 'x', 'coupon_id' => 2,
            'coupon_name' => 'SECOND', 'coupon_code' => 'SECOND',
            'coupon_discount_type' => 'flat', 'coupon_discount_number' => 5,
            'coupon_discount_amount' => 5.00, 'coupon_final_amount' => 85.00,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $coverage = CouponRedemptionCoverage::forStore((int) $store->id);

        $this->assertSame(1, $coverage->discountedOrders(),
            'the join fanned out and counted one order twice');
        $this->assertTrue($coverage->isComplete());
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Tenancy, and the refusal
     * ---------------------------------------------------------------------------------------------
     */

    public function test_another_stores_unrecorded_redemptions_do_not_affect_this_store(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->discountedOrder($mine, '2026-11-01', true);
        $this->discountedOrder($theirs, '2026-11-15', false);   // their gap, later than my last order

        $coverage = CouponRedemptionCoverage::forStore((int) $mine->id);

        $this->assertTrue($coverage->isComplete(),
            'another store\'s unrecorded redemption withheld this store\'s figure');
        $this->assertSame(1, $coverage->discountedOrders());

        // And the converse, so the predicate is not merely absent in one direction.
        $theirCoverage = CouponRedemptionCoverage::forStore((int) $theirs->id);
        $this->assertFalse($theirCoverage->isComplete());
        $this->assertSame(1, $theirCoverage->ordersWithoutRow());
    }

    public function test_it_refuses_a_non_positive_store_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CouponRedemptionCoverage::forStore(0);
    }

    /** A premise, so none of the above passes because `makeOrder` silently ignored `coupon_price`. */
    public function test_premise_the_fixture_really_records_a_discount_on_the_order(): void
    {
        [, $store] = $this->makeTenant();
        $id = $this->discountedOrder($store, '2026-11-01', false);

        $this->assertGreaterThan(0.0, (float) DB::table('orders')->where('id', $id)->value('coupon_price'),
            'Fixture premise: makeOrder must honour coupon_price, or every case here measures '
            . 'a store with no discounted orders and passes for that reason.');
    }
}
