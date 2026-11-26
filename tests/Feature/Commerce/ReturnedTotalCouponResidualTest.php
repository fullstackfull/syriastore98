<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\OrderCouponDetail;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-261's RESIDUAL, MEASURED ON THE REAL SCHEMA. `orders.final_price` CAN STILL END NEGATIVE.
 * =================================================================================================
 *
 * WHAT THIS FILE IS, AND IT IS NOT A CLAIM THAT THE BEHAVIOUR IS CORRECT.
 *
 * GAP-261's headline -- *"a zero-value returned line plus a flat coupon RAISES the order total"* --
 * is CLOSED. The clamp `max(0.0, $return_price - $coupon_price)` is applied at BOTH copies of the
 * formula (`Order.php:1659` in the live summation and `:1168` in `returnedAmountOf()`), and
 * `SecondReturnTotalTest` covers it. Verified before this file was written, by reading both sites
 * and running that suite: 16 green.
 *
 * The same register row then records a RESIDUAL with a DIFFERENT cause, as *"Reported, not
 * repaired"*: when `orders.coupon_price` exceeds what the detail row's `coupon_discount_number`
 * takes off the returned total, the subtraction still overshoots and `orders.final_price` ends
 * NEGATIVE. `Order::product_return()`'s own comment at `:1650-1658` carries that account.
 *
 * NOTHING IN THE SUITE COVERED IT, AND THE REGISTER'S FIGURE CAME FROM A SCHEMA THAT IS NOT THE
 * PRODUCT'S. `SecondReturnTotalTest` builds its own tables in `createSchema()` and drops them in
 * `tearDown()`, and that hand-rolled `orders` carries an `order_status` column **no migration
 * creates** -- measured: the same fixture against the migrated schema dies with *"table orders has
 * no column named order_status"*. So the recorded figure was taken against a shape the product does
 * not have. This file re-measures it under `RefreshDatabase`, on the real migrations, and it
 * reproduces TO THE CENT.
 *
 * -------------------------------------------------------------------------------------------------
 * THE MEASUREMENT
 * -------------------------------------------------------------------------------------------------
 *
 * A delivered two-line cod order: 90.00 + 40.00 of goods, `orders.coupon_price` 100.00, so
 * `orders.final_price` is 30.00. The `order_coupon_details` row's `coupon_discount_number` is
 * 0.00 -- the recorded-versus-configured disagreement P4-F134/F135 left open, where
 * `orders.coupon_price` holds one number and the child row holds another.
 *
 *     start                final_price   30.00   return_price     0.00
 *     return line 201      final_price  -60.00   return_price    90.00
 *     return line 202      final_price -100.00   return_price   130.00
 *
 * AND IT IS ALREADY NEGATIVE AFTER THE FIRST LINE, which the register's note does not say: the
 * harm does not wait for the order to be fully returned. On a cod order `final_price` is the cash
 * a driver collects at the door, so a negative figure is not cosmetic.
 *
 * WHY THE CLAMP DOES NOT CATCH IT, confirmed rather than assumed: the clamp floors a NEGATIVE
 * RETURNED TOTAL, and here the returned total is positive (130.00, because the detail row
 * contributes 0.00 of coupon). What overshoots is the SUBTRACTION, because the line figures in
 * `product_json` are gross while `orders.final_price` is already net of a coupon no line records.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THIS FILE DOES NOT FIX IT
 * -------------------------------------------------------------------------------------------------
 *
 * Flooring `final_price` here would HIDE the disagreement rather than resolve it -- the register's
 * own reason, and the same reason the tax column in that method is left alone instead of guessed
 * at. Resolving it means deciding how a flat coupon apportions across the lines of a partly
 * returned order, which is a pricing rule: `OrderRefundController`'s settlement already records
 * that *prorating a flat coupon across returned lines is a revenue-reporting decision rather than
 * something to guess at*. CLAUDE.md §2 puts a material change to a price rule in the
 * escalate-to-owner set, and the discount-stacking rule is an OPEN owner decision. So this is
 * measured and reported, not chosen.
 *
 * THE ASSERTIONS RECORD THE PRESENT BEHAVIOUR AND SAY SO IN THEIR MESSAGES. `test_MEASURED_*` is
 * this repository's own naming for that, and the convention is that such a pin is INVERTED by the
 * change that closes the defect rather than deleted -- so whoever implements the owner's decision
 * finds this file red and has the figures in front of them. It must not be read as approval of a
 * negative total.
 *
 * @see \App\Models\Order::product_return()
 */
class ReturnedTotalCouponResidualTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 7261;
    private const LINE_A = 90.00;
    private const LINE_B = 40.00;

    /** `orders.coupon_price`, the RECORDED coupon -- which the detail row does not agree with. */
    private const RECORDED_COUPON = 100.00;

    /** 90 + 40 - 100. */
    private const ORDER_TOTAL = 30.00;

    /**
     * A delivered two-line order whose recorded coupon and configured coupon disagree.
     *
     * `track_stock => 0` on both products, for `SecondReturnTotalTest`'s own stated reason: the
     * restock half of this method is covered elsewhere, and pulling the stock service in here
     * would make a failure there read as a money defect.
     *
     * `$configuredCoupon` is the detail row's `coupon_discount_number`. The disagreement is the
     * whole fixture, so it is a parameter rather than a constant.
     */
    private function orderWithDisagreeingCoupon(float $configuredCoupon): Order
    {
        foreach ([201 => self::LINE_A, 202 => self::LINE_B] as $id => $price) {
            (new Product())->forceFill([
                'id' => $id, 'name' => 'Line ' . $id, 'track_stock' => 0,
                'stock_order_status' => 'not_allow', 'price' => $price, 'product_stock' => 0,
                'variant_product' => 0, 'store_id' => self::STORE, 'created_by' => 1,
            ])->save();
        }

        $lines = [
            ['product_id' => 201, 'variant_id' => 0, 'qty' => 1, 'final_price' => self::LINE_A, 'return' => 0, 'store_id' => self::STORE],
            ['product_id' => 202, 'variant_id' => 0, 'qty' => 1, 'final_price' => self::LINE_B, 'return' => 0, 'store_id' => self::STORE],
        ];

        $order = new Order();
        $order->forceFill([
            'product_order_id' => 'GAP261-RESIDUAL', 'customer_id' => 1, 'store_id' => self::STORE,
            'product_json' => json_encode($lines),
            'product_price' => self::LINE_A + self::LINE_B,
            'tax_price' => 0, 'delivery_price' => 0,
            'coupon_price' => self::RECORDED_COUPON,
            'final_price' => self::ORDER_TOTAL, 'return_price' => 0,
            'payment_type' => 'cod', 'payment_status' => 'Unpaid',
            'delivered_status' => 1, 'return_status' => 0,
        ])->save();

        (new OrderCouponDetail())->forceFill([
            'order_id' => $order->id, 'product_order_id' => $order->product_order_id,
            'coupon_id' => 1, 'coupon_name' => 'c', 'coupon_code' => 'C',
            'coupon_discount_type' => 'flat',
            'coupon_discount_number' => $configuredCoupon,
            'coupon_discount_amount' => $configuredCoupon,
            'coupon_final_amount' => self::ORDER_TOTAL,
        ])->save();

        return $order;
    }

    /** @return array{0: float, 1: float} final_price and return_price, from the raw table */
    private function money(int $orderId): array
    {
        $row = DB::table('orders')->where('id', $orderId)->first();

        return [(float) $row->final_price, (float) $row->return_price];
    }

    // =============================================================================================
    //  PREMISES -- the clamp GAP-261 added is present and working
    // =============================================================================================

    /**
     * THE CLAMP IS AT BOTH COPIES OF THE FORMULA, which is what closes GAP-261's headline. Asserted
     * over source because the two sites are the thing that must not drift apart, and a behavioural
     * test of one cannot see the other.
     */
    public function test_premise_the_gap_261_clamp_is_applied_at_both_copies_of_the_formula(): void
    {
        $source = file_get_contents(app_path('Models/Order.php'));

        $this->assertSame(
            2,
            substr_count($source, 'max(0.0, $return'),
            'GAP-261 clamped the returned total at zero in BOTH the live summation and '
            . '`returnedAmountOf()`, which reconstructs the base. If one copy is gone the headline '
            . 'defect is open again and the residual this file measures is the smaller half.'
        );
    }

    /**
     * AND THE CLAMP REALLY BITES, on the case GAP-261 was raised for: a flat coupon larger than the
     * value of the returned line no longer RAISES the amount owed. Without this, "the residual is a
     * different cause" would be an assertion about code rather than a measurement.
     */
    public function test_premise_a_return_worth_less_than_the_flat_coupon_no_longer_raises_the_total(): void
    {
        /* Configured 10.00, so the returned 5.00 line is worth less than the coupon. */
        $order = $this->orderWithDisagreeingCoupon(10.00);

        DB::table('orders')->where('id', $order->id)->update([
            'coupon_price' => 10.00,
            'final_price' => 120.00,
            'product_json' => json_encode([
                ['product_id' => 201, 'variant_id' => 0, 'qty' => 1, 'final_price' => 5.00, 'return' => 0, 'store_id' => self::STORE],
                ['product_id' => 202, 'variant_id' => 0, 'qty' => 1, 'final_price' => 125.00, 'return' => 0, 'store_id' => self::STORE],
            ]),
        ]);
        (new Order)->flushCache();

        Order::product_return(['order_id' => $order->id, 'product_id' => 201, 'variant_id' => 0]);

        [$total, $returned] = $this->money($order->id);

        $this->assertSame(0.0, $returned,
            'the clamp did not floor the returned total: 5.00 - 10.00 should credit NOTHING, which '
            . 'is what GAP-261 settled. It reads ' . $returned . '.');
        $this->assertSame(120.00, $total,
            'returning a line worth less than the flat coupon changed the amount owed. GAP-261 '
            . 'closed exactly this. It reads ' . $total . '.');
    }

    // =============================================================================================
    //  THE RESIDUAL -- present behaviour, recorded, NOT endorsed
    // =============================================================================================

    /**
     * MEASURED, NOT APPROVED: a recorded coupon the detail row does not carry drives
     * `orders.final_price` NEGATIVE, and already after the FIRST line.
     *
     * Invert this case when the owner settles how a flat coupon apportions across returned lines.
     * Do not delete it: the figures are the input to that decision.
     */
    public function test_MEASURED_a_recorded_coupon_the_detail_row_does_not_carry_drives_the_total_negative(): void
    {
        $order = $this->orderWithDisagreeingCoupon(0.00);

        $this->assertSame([self::ORDER_TOTAL, 0.0], $this->money($order->id), 'premise: the starting figures');

        Order::product_return(['order_id' => $order->id, 'product_id' => 201, 'variant_id' => 0]);

        $this->assertSame([-60.00, 90.00], $this->money($order->id),
            'MEASURED RESIDUAL, first line: `orders.final_price` is already NEGATIVE after ONE '
            . 'return -- 30.00 - 90.00 -- because the line figure is gross while the order total '
            . 'is net of a 100.00 coupon no line records. The register\'s note gives only the '
            . 'end-state figure; the harm starts here. On a cod order this is the cash a driver '
            . 'collects at the door.');

        Order::product_return(['order_id' => $order->id, 'product_id' => 202, 'variant_id' => 0]);

        $this->assertSame([-100.00, 130.00], $this->money($order->id),
            'MEASURED RESIDUAL, both lines: `final_price` -100.00 with `return_price` 130.00 -- the '
            . 'figure GAP-261 recorded, reproduced to the cent on the REAL migrated schema rather '
            . 'than the hand-built one that recorded it. The GAP-261 clamp is in place and does '
            . 'not catch this, because it floors a NEGATIVE returned total and this one is '
            . 'positive. NOT A FIX TARGET FOR ENGINEERING: resolving it means deciding how a flat '
            . 'coupon apportions across the lines of a partly returned order, which is a pricing '
            . 'rule and an open owner decision. Flooring `final_price` here would hide the '
            . 'disagreement rather than fix it.');
    }

    /**
     * THE CONTROL THAT ISOLATES THE CAUSE. With the two coupon figures in AGREEMENT, the identical
     * fixture behaves correctly -- so the negative total above is the disagreement and not the
     * return arithmetic. Without this, the residual could be read as a second defect in the clamp.
     */
    public function test_the_same_fixture_is_correct_when_the_two_coupon_figures_agree(): void
    {
        $order = $this->orderWithDisagreeingCoupon(self::RECORDED_COUPON);

        Order::product_return(['order_id' => $order->id, 'product_id' => 201, 'variant_id' => 0]);

        [$total, $returned] = $this->money($order->id);

        $this->assertGreaterThanOrEqual(0.0, $total,
            'with `orders.coupon_price` and the detail row\'s `coupon_discount_number` both 100.00, '
            . 'the clamp has the figure it needs and the total must not go negative. It reads '
            . $total . ' with return_price ' . $returned . '. If this is negative too then the '
            . 'cause is NOT the recorded-versus-configured disagreement and the paragraph above is '
            . 'wrong about it.');
    }
}
