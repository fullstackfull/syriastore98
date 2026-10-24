<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * A REFUSED COUPON MADE THE LINE FREE.  P1-ORD-01 family, under the owner's standing directive
 * "do not defer any confirmed payment-total integrity defect, unauthorized discount, or incorrect
 * gateway charge."
 * =================================================================================================
 *
 * `Cart::getCouponTotalAmount()` returns the line price AFTER the coupon, and both callers use it
 * that way:
 *
 *     $coupon_apply_price = Cart::getCouponTotalAmount($coupon, $final_price, ...);
 *     $coupon_price += $final_price - $coupon_apply_price;
 *     $final_price   = $coupon_apply_price;
 *
 * It answered **0** on every refusal path -- the shopper's per-user limit, the store's total usage
 * limit or expiry, and a `fixed product discount` matching no arm (which fell through to the
 * `$coupon_price = 0` initialiser). So a refusal zeroed the line instead of leaving it alone.
 *
 * THAT IS CASH, NOT A DISPLAY BUG. The line price feeds `total_sub_price`, which
 * `PaymentService::getProductStatus()` writes into `orders.final_price` for `cod` and
 * `bank_transfer` -- and for a `cod` order that column is the money the driver collects.
 *
 * WHY THE FIX HAD TO BE IN THE CALLEE. A legitimate 100%-off percentage coupon also computes 0, so
 * "does not apply" and "makes it free" were literally the same answer and no caller could tell them
 * apart. The refusal paths now return the undiscounted line.
 *
 * THESE TESTS CALL THE METHOD DIRECTLY. The two callers are inside 200-line cart builders whose
 * other inputs (tax, shipping, variants, stock) would have to be staged to reach this one
 * arithmetic step, and a fixture that elaborate tends to pass for a reason other than the one it
 * names. The method is public and static; the defect is entirely in its return value.
 */
class RefusedCouponLinePriceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const LINE = 100.00;

    private function coupon($store, array $overrides = []): Coupon
    {
        $id = DB::table('coupons')->insertGetId(array_merge([
            'coupon_name' => 'C', 'coupon_code' => 'C-' . strtoupper(uniqid()),
            'coupon_type' => 'percentage', 'discount_amount' => 10,
            'coupon_limit' => 100, 'coupon_limit_user' => 100,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'free_shipping_coupon' => 0, 'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return Coupon::findOrFail($id);
    }

    /**
     * THE PREMISE: a coupon that DOES apply still discounts.
     *
     * Without this, every "the line was not zeroed" assertion below is satisfied by a method that
     * refuses everything, and the fix would look correct while having removed coupons entirely.
     */
    public function test_a_valid_percentage_coupon_still_discounts_the_line(): void
    {
        [, $store] = $this->makeTenant();
        $coupon = $this->coupon($store, ['coupon_type' => 'percentage', 'discount_amount' => 10]);

        $this->assertEqualsWithDelta(90.0, (float) Cart::getCouponTotalAmount($coupon, self::LINE, 1, 1), 0.005,
            'Premise: a 10% coupon on a 100.00 line leaves 90.00. If this fails the method has '
            . 'stopped applying coupons at all and nothing below means what it says.');
    }

    /** AND A 100% COUPON LEGITIMATELY MAKES THE LINE FREE -- which is why 0 could not be the refusal. */
    public function test_a_hundred_percent_coupon_legitimately_zeroes_the_line(): void
    {
        [, $store] = $this->makeTenant();
        $coupon = $this->coupon($store, ['coupon_type' => 'percentage', 'discount_amount' => 100]);

        $this->assertEqualsWithDelta(0.0, (float) Cart::getCouponTotalAmount($coupon, self::LINE, 1, 1), 0.005,
            'A 100% coupon must still be able to zero a line. This is the case that makes 0 an '
            . 'ambiguous refusal value and forced the fix into the callee.');
    }

    /**
     * THE DEFECT: the store's usage limit is spent, so the coupon is refused -- and the line was
     * being zeroed rather than left alone.
     */
    public function test_a_coupon_whose_store_limit_is_spent_does_not_zero_the_line(): void
    {
        [, $store] = $this->makeTenant();
        // coupon_limit 0 can never exceed the usage count, so the `coupon_limit > count` predicate
        // fails and the method takes its `empty($coupon_expiry_date)` refusal path.
        $coupon = $this->coupon($store, ['coupon_limit' => 0]);

        $this->assertEqualsWithDelta(self::LINE, (float) Cart::getCouponTotalAmount($coupon, self::LINE, 1, 1), 0.005,
            'a refused coupon returned 0, which both cart builders read as the discounted line '
            . 'price -- so the line became FREE and its whole value was recorded as the discount. '
            . 'On a cod order that figure is the cash the driver collects.');
    }

    /** THE SAME, through an expired coupon. */
    public function test_an_expired_coupon_does_not_zero_the_line(): void
    {
        [, $store] = $this->makeTenant();
        $coupon = $this->coupon($store, ['coupon_expiry_date' => now()->subDay()->toDateString()]);

        $this->assertEqualsWithDelta(self::LINE, (float) Cart::getCouponTotalAmount($coupon, self::LINE, 1, 1), 0.005,
            'an expired coupon zeroed the line.');
    }

    /**
     * AND A `fixed product discount` WHOSE ARMS ASSIGN NOTHING -- the quietest of the three,
     * because it never returned at all: it fell through to the `$coupon_price = 0` initialiser.
     *
     * THE FIXTURE IS THE EXCLUDED-PRODUCT CASE, and it took a measurement to find the right one.
     * `explode(',', null)` is `['']`, not `[]`, so every `count(...) > 0` test in that branch is
     * true however the coupon is configured and the first arm is always entered. The only way to
     * reach the fall-through is for an INNER condition to fail -- here, the product being in
     * `exclude_product`, which makes `!in_array($productId, $exclude_product)` false and leaves
     * `$coupon_price` untouched.
     *
     * A first version of this test used `applied_product = '9999'` expecting a non-match, and it
     * measured 95.00 instead of 100.00: the arm had fired anyway. That is a SEPARATE pre-existing
     * defect -- the arm grants the discount when the product is NOT in the applied list -- and it
     * belongs to the coupon-arithmetic redesign the owner deferred. It is recorded in
     * docs/security/P01-ORD-01-COUPON-POLICY-DEFERRAL.md rather than changed here.
     */
    public function test_a_fixed_product_coupon_whose_arms_assign_nothing_does_not_zero_the_line(): void
    {
        [, $store] = $this->makeTenant();
        $coupon = $this->coupon($store, [
            'coupon_type' => 'fixed product discount',
            'discount_amount' => 5,
            'exclude_product' => '1',          // the product in the basket is excluded
        ]);

        $this->assertEqualsWithDelta(self::LINE, (float) Cart::getCouponTotalAmount($coupon, self::LINE, 1, 1), 0.005,
            'a fixed-product coupon that excludes this very product zeroed the line. No arm '
            . 'assigned, so there was not even a refusal to find -- the initialiser was the answer.');
    }

    /**
     * AND THE PER-SHOPPER LIMIT, which is the only one of the three that needs a signed-in
     * customer and a usage history.
     *
     * A REMOVAL CHECK IS WHY THIS EXISTS. Reverting that refusal to `return 0` survived the first
     * mutation run: the store-limit and expiry cases both take the OTHER refusal, so nothing
     * reached this one. It is also the refusal most likely to fire in real life, because it is the
     * one an ordinary repeat customer trips.
     *
     * `PerUsesCouponCount()` resolves the e-mails by joining `user_coupons.order_id` to
     * `order_billing_details.email` (`app/Models/Coupon.php:76-85`), so the fixture has to supply
     * both rows, not just a usage count.
     */
    public function test_a_coupon_the_shopper_has_already_used_to_their_limit_does_not_zero_the_line(): void
    {
        [, $store] = $this->makeTenant();

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'repeat-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        // coupon_limit high enough that the STORE limit is not what refuses -- otherwise this test
        // would pass through the other branch and prove nothing about this one.
        $coupon = $this->coupon($store, ['coupon_limit' => 100, 'coupon_limit_user' => 1]);

        $orderId = DB::table('order_billing_details')->insertGetId([
            'order_id' => 4242, 'email' => $customer->email,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_coupons')->insert([
            'coupon_id' => $coupon->id, 'order_id' => 4242, 'user_id' => $customer->id,
            'amount' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, $orderId, 'Premise: the billing row the e-mail join needs exists.');

        $this->actingAs($customer, 'customers');

        $this->assertSame([$customer->email], $coupon->PerUsesCouponCount(),
            'Premise: the shopper is recorded as having used this coupon once. Without this the '
            . 'per-user branch is never entered and the assertion below holds for the wrong reason.');

        $this->assertEqualsWithDelta(self::LINE, (float) Cart::getCouponTotalAmount($coupon, self::LINE, 1, 1), 0.005,
            'a shopper who had already used this coupon to their limit got the line FREE instead '
            . 'of being refused. This is the refusal an ordinary repeat customer trips.');
    }

    /**
     * A COUPON MAY NOT PAY THE SHOPPER.
     *
     * `flat` subtracts a fixed amount with no floor, so a coupon worth more than the line produced
     * a NEGATIVE line price, which the caller then added into the basket total as a credit.
     */
    public function test_a_flat_coupon_larger_than_the_line_cannot_produce_a_negative_price(): void
    {
        [, $store] = $this->makeTenant();
        $coupon = $this->coupon($store, ['coupon_type' => 'flat', 'discount_amount' => 500]);

        $result = (float) Cart::getCouponTotalAmount($coupon, self::LINE, 1, 1);

        $this->assertGreaterThanOrEqual(0.0, $result,
            'a flat coupon larger than the line returned a negative price, which the cart builder '
            . 'adds into the basket as a credit. Actual: ' . $result);
        $this->assertEqualsWithDelta(0.0, $result, 0.005,
            'and the floor is zero, not some other number.');
    }

    /** A flat coupon smaller than the line still works normally. */
    public function test_a_flat_coupon_smaller_than_the_line_still_applies(): void
    {
        [, $store] = $this->makeTenant();
        $coupon = $this->coupon($store, ['coupon_type' => 'flat', 'discount_amount' => 25]);

        $this->assertEqualsWithDelta(75.0, (float) Cart::getCouponTotalAmount($coupon, self::LINE, 1, 1), 0.005,
            'PRESERVE FIRST: the floor must not have broken an ordinary flat coupon.');
    }
}
