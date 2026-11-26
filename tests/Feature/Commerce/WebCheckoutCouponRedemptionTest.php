<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F134 / P4-F135 / GAP-132 — THE SAME PAIR, ON THE **WEB** CHECKOUT WRITER.
 * =================================================================================================
 *
 * THE ORIGINAL FINDINGS, verbatim from `docs/development/phase-4/PHASE-04-FINAL-REPORT.md`:
 *
 *   P4-F134  "`ordersave` may record **no coupon redemption at all**, so coupon caps never see
 *             those orders. Needs a driven test before anything changes, because the fix direction
 *             changes what shoppers may spend."
 *   P4-F135  "a **refused** coupon still writes `order_coupon_details` and `user_coupons`, burning
 *             the shopper's allowance. Queued with P4-F134; both want the same change."
 *
 * THIS SUITE IS A PIN, NOT A FAILING-FIRST TEST, AND THAT IS THE MEASUREMENT IT EXISTS TO RECORD.
 * =================================================================================================
 *
 * GAP-132's row reads NOT IMPLEMENTED and asks for F134/F135 to be driven "before changing
 * behaviour". They were driven. All seven cases below PASSED against unmodified code, so **neither
 * finding is live on this path and no behaviour was changed for them.** The row is stale, and the
 * reason it is stale is worth writing down rather than inferring, because the pair is closed by TWO
 * UNRELATED FIXES on two different surfaces:
 *
 *   THE API WRITERS — `Api\ApiController::ordersave` and `::place_order` — were fixed on 2026-11-20
 *   through `App\Services\CouponRedemptionRecorder`, which the findings register records as MEASURED
 *   AND FIXED and `ApiCouponRedemptionRecordingTest` drives.
 *
 *   THE WEB WRITER — `PaymentService::getProductStatus()`, the cod and bank-transfer order writer —
 *   is NOT fixed in that way and still carries its own copy of the child-row block, gated on a
 *   REQUEST key and resolving the coupon from a REQUEST id with no store predicate:
 *
 *       if (!empty($requests_data['coupon_info'])) {
 *           $coupon_data = $requests_data['coupon_info'];
 *           $Coupon = Coupon::find($coupon_data['coupon_id']);   // caller-named id, NO store predicate
 *           ... every column from $coupon_data ...
 *
 *   IT IS NOT REACHABLE BECAUSE OF A FIX ONE LAYER UP. `PaymentController::processCoupon()`
 *   (P1-ORD-02) DISCARDS the posted `coupon_info`, calls `apply_coupon()` itself, and merges the
 *   SERVER's answer back into the request — returning an **empty array** when the coupon was
 *   refused. `prepareFinalRequestData()` then overwrites `cartlist` with the server-derived cart as
 *   well, which is the second arm's source. So by the time `getProductStatus()` reads
 *   `$requests_data`, both arms hold the server's own figures or nothing at all:
 *
 *       accepted coupon -> server coupon_info with its coupon_id -> both child rows written, from
 *                          server-derived values (MEASURED: coupon_price 50.00, 1 user_coupons row,
 *                          1 order_coupon_details row with coupon_discount_amount 50.00)
 *       refused coupon  -> coupon_info [] -> the gate is false -> nothing written
 *
 * WHY THAT IS WORTH A SUITE ANYWAY. The containment lives in a DIFFERENT file from the vulnerable
 * block, two layers away, and nothing asserted it: the web writer's copy of the F134/F135 shape is
 * still there verbatim, and it becomes live again the moment anything stops replacing `coupon_info`
 * or `cartlist`. Three mutations of the containment, each applied alone, run and reverted, show that
 * these cases can see exactly that (the table is in the work record):
 *
 *   W1  the pre-P1-ORD-02 short-circuit restored (a posted coupon_info trusted)   4 of 7 fail
 *   W2  a refused coupon stops returning an EMPTY coupon_info                     1 of 7 fails
 *   W3  neither arm carries a server-derived coupon_info (F134's own condition)   4 of 7 fail
 *
 * W3 HAD TO BE A TWO-PART MUTANT, AND THAT IS ITSELF A MEASUREMENT. Dropping only
 * `processCoupon()`'s derived `coupon_info` SURVIVED all seven, because the recording block's
 * SECOND arm reads `$requests_data['cartlist']['coupon_info']` and `prepareFinalRequestData()` had
 * still overwritten `cartlist` with the server's cart — traced to `PaymentService.php:3308` with a
 * backtrace on the `user_coupons` insert, not reasoned about. W3 removes both, which is the only
 * form that creates F134's condition. So the containment is TWO server-derived substitutions, not
 * one, and a change to either alone leaves the other holding the door.
 *
 * WHAT THIS SUITE DOES NOT CLAIM. It drives `POST /{slug}/process-order` with a real signed-in
 * `Customer`, a real cart and a real coupon — the shipped web checkout, the same route
 * `MultiLineCouponArithmeticTest` uses for the money half. It does NOT drive the gateway
 * controllers (`PaymentController` itself at its gateway-return block, `AuthorizeNetPaymnetController`,
 * `PayUPaymentController` and about twenty more), each of which holds a further copy of the same
 * block reached through its own return path. Those are reported rather than repaired, because each
 * needs a gateway fixture of its own and several are outside this change's permitted files.
 */
class WebCheckoutCouponRedemptionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const LINE = 100.00;
    private const FLAT = 50.00;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /*
    |--------------------------------------------------------------------------
    | F134 — THE ORDINARY WEB CHECKOUT MUST RECORD THE REDEMPTION IT GRANTED
    |--------------------------------------------------------------------------
    */

    /**
     * THE PREMISE AND THE CONTROL IN ONE: the discount really is granted on this path.
     *
     * Without it, "no redemption row" is also what a checkout that refused the coupon outright
     * produces, and the case below would be satisfied by a broken handler.
     */
    public function test_premise_the_web_cod_checkout_grants_the_coupon_discount(): void
    {
        [$store, $customer] = $this->basketWithCoupon('CAPME');

        $order = $this->checkout($store, $customer, 'CAPME');

        $this->assertNotNull($order, 'premise: the cod checkout wrote an order');
        $this->assertEqualsWithDelta(self::FLAT, (float) $order->coupon_price, 0.005,
            'premise: the server granted the 50.00 discount, so there IS a redemption to record. '
            . 'orders.coupon_price came out ' . ($order->coupon_price ?? '?'));
    }

    /** THE DEFECT: the discount is granted and the cap's own table stays empty. */
    public function test_a_web_cod_order_records_the_redemption_it_granted(): void
    {
        [$store, $customer] = $this->basketWithCoupon('CAPME');

        $order = $this->checkout($store, $customer, 'CAPME');
        $this->assertNotNull($order, 'premise: the cod checkout wrote an order');

        $this->assertSame(1, DB::table('user_coupons')->where('order_id', $order->id)->count(),
            'A WEB COD ORDER TOOK THE DISCOUNT AND RECORDED NO REDEMPTION. Every coupon cap in '
            . 'this application is a count on user_coupons, so the merchant\'s limit cannot fire '
            . 'for any web order. This PASSES against shipped code: the containment is one layer '
            . 'up, in PaymentController. MEASURED under mutant W3, which removes it: '
            . 'orders.coupon_price 50.00 with 0 user_coupons rows and 0 order_coupon_details rows.');

        $this->assertSame(1, DB::table('order_coupon_details')->where('order_id', $order->id)->count(),
            'the order carries a discount and no detail row, so the order cannot be reconciled '
            . 'against the coupon that produced it');
    }

    /**
     * AND THE CAP THEN FIRES. The row's purpose, driven end to end rather than asserted as a count.
     *
     * `coupon_limit_user = 1` on a coupon the same shopper uses twice: the second checkout must not
     * get the discount. This is the case that says what the missing row COSTS, and it is the one
     * the register means by "coupon caps can be evaded".
     */
    public function test_a_one_per_shopper_coupon_cannot_be_used_twice_through_the_web_checkout(): void
    {
        [$store, $customer] = $this->basketWithCoupon('ONEONLY', ['coupon_limit_user' => 1]);

        $first = $this->checkout($store, $customer, 'ONEONLY');
        $this->assertNotNull($first, 'premise: the first cod checkout wrote an order');
        $this->assertEqualsWithDelta(self::FLAT, (float) $first->coupon_price, 0.005,
            'premise: the first order got the discount');

        // A second basket for the same shopper, same coupon.
        $this->basketFor($store, $customer, 1);
        $second = $this->checkout($store, $customer, 'ONEONLY', $first->id);

        $this->assertNotNull($second, 'premise: the second cod checkout wrote an order too — this '
            . 'case is about the DISCOUNT being refused, not the order');

        $this->assertEqualsWithDelta(0.0, (float) $second->coupon_price, 0.005,
            'A COUPON LIMITED TO ONE USE PER SHOPPER DISCOUNTED A SECOND WEB ORDER FOR THE SAME '
            . 'SHOPPER. The cap is a count on user_coupons and the first order wrote no row, so '
            . 'the limit could not see it. This PASSES against shipped code. MEASURED under '
            . 'mutant W3: second order coupon_price 50.00, i.e. the cap evaded, which is what the '
            . 'register means by "coupon caps can be evaded".');
    }

    /*
    |--------------------------------------------------------------------------
    | F135 — A POSTED coupon_info MAY NOT WRITE THE ROWS
    |--------------------------------------------------------------------------
    */

    /**
     * A REFUSED COUPON MUST BURN NO ALLOWANCE.
     *
     * The coupon is real and belongs to this store, but its `minimum_spend` is above the basket, so
     * `apply_coupon()` refuses it and `orders.coupon_price` is 0. The shopper's client nevertheless
     * posts a `coupon_info` block naming it — which is exactly what the handler's own gate reads.
     */
    public function test_a_refused_coupon_burns_no_allowance_on_the_web_checkout(): void
    {
        [$store, $customer, $couponId] = $this->basketWithCoupon('TOOBIG', ['minimum_spend' => 5000]);

        $order = $this->checkout($store, $customer, 'TOOBIG', null, [
            'coupon_info' => [
                'coupon_id' => $couponId,
                'coupon_name' => 'TOOBIG',
                'coupon_code' => 'TOOBIG',
                'coupon_discount_type' => 'flat',
                'coupon_discount_number' => self::FLAT,
                'coupon_discount_amount' => self::FLAT,
                'coupon_final_amount' => self::LINE - self::FLAT,
            ],
        ]);

        $this->assertNotNull($order, 'premise: the cod checkout wrote an order');

        $this->assertSame(0, DB::table('user_coupons')->where('order_id', $order->id)->count(),
            'A COUPON THE SERVER REFUSED SPENT THE SHOPPER\'S ALLOWANCE. The child-row gate reads '
            . 'the REQUEST (`!empty($requests_data[coupon_info])`) instead of whether '
            . 'apply_coupon() accepted anything, and every column comes from that array. This '
            . 'PASSES against shipped code because PaymentController::processCoupon() replaces the '
            . 'posted coupon_info with an EMPTY array on a refusal. MEASURED under mutants W1 and '
            . 'W2, which remove that: 1 user_coupons row and 1 order_coupon_details row for an '
            . 'order whose discount is 0.00.');

        $this->assertSame(0, DB::table('order_coupon_details')->where('order_id', $order->id)->count(),
            'a refused coupon wrote a detail row, so the order records a discount it never got');
    }

    /**
     * AND A POSTED ID MAY NOT NAME ANOTHER MERCHANT'S COUPON.
     *
     * `Coupon::find($coupon_data['coupon_id'])` has no store predicate, and `Coupon` carries no
     * `StoreScope` and is in no `scoped_models` entry (CLAUDE.md), so the lookup reaches every
     * tenant's coupons. The shopper checks out of store A posting store B's coupon id.
     */
    public function test_a_posted_coupon_id_cannot_name_another_merchants_coupon(): void
    {
        [$storeA, $customer] = $this->basketWithCoupon('MINE');
        [$storeB, , $foreignId] = $this->basketWithCoupon('THEIRS');

        $this->assertNotSame((int) $storeA->id, (int) $storeB->id, 'premise: two distinct stores');

        $order = $this->checkout($storeA, $customer, 'MINE', null, [
            'coupon_info' => [
                'coupon_id' => $foreignId,
                'coupon_name' => 'THEIRS',
                'coupon_code' => 'THEIRS',
                'coupon_discount_type' => 'flat',
                'coupon_discount_number' => 999.00,
                'coupon_discount_amount' => 999.00,
                'coupon_final_amount' => 0.00,
            ],
        ]);

        $this->assertNotNull($order, 'premise: the cod checkout wrote an order');

        $this->assertSame(0,
            DB::table('order_coupon_details')->where('order_id', $order->id)->where('coupon_id', $foreignId)->count(),
            'ANOTHER MERCHANT\'S COUPON WAS RECORDED AGAINST THIS STORE\'S ORDER, from a posted '
            . 'id with no store predicate. coupon_id ' . $foreignId . ' belongs to store '
            . $storeB->id . ', the order to store ' . $storeA->id . '.');

        $this->assertSame(0,
            DB::table('user_coupons')->where('coupon_id', $foreignId)->count(),
            'a redemption was counted against another merchant\'s coupon, which moves THEIR cap');
    }

    /**
     * AND THE REDEMPTION IS STILL RECORDED FOR THE COUPON THE SERVER REALLY GRANTED, even on a
     * request that posted a different coupon's id. The liveness control for the two cases above:
     * without it, a fix that writes nothing at all would satisfy both.
     */
    public function test_a_posted_id_does_not_steer_the_row_away_from_the_granted_coupon(): void
    {
        [$storeA, $customer, $ownId] = $this->basketWithCoupon('MINE');
        [, , $foreignId] = $this->basketWithCoupon('THEIRS');

        $order = $this->checkout($storeA, $customer, 'MINE', null, [
            'coupon_info' => [
                'coupon_id' => $foreignId,
                'coupon_name' => 'THEIRS',
                'coupon_code' => 'THEIRS',
                'coupon_discount_type' => 'flat',
                'coupon_discount_number' => 999.00,
                'coupon_discount_amount' => 999.00,
                'coupon_final_amount' => 0.00,
            ],
        ]);

        $this->assertNotNull($order, 'premise: the cod checkout wrote an order');
        $this->assertEqualsWithDelta(self::FLAT, (float) $order->coupon_price, 0.005,
            'premise: the server granted THIS store\'s coupon, so there is a redemption to record');

        $this->assertSame(1,
            DB::table('user_coupons')->where('order_id', $order->id)->where('coupon_id', $ownId)->count(),
            'CONTROL: the redemption for the coupon the server actually granted is missing, so the '
            . 'two cases above are satisfied by a handler that records nothing and F134 is '
            . 're-opened on this writer.');

        $this->assertSame((int) $customer->id,
            (int) DB::table('user_coupons')->where('order_id', $order->id)->value('user_id'),
            'the redemption is attributed to the authenticated shopper, not to a posted id');
    }

    /** AND THE RECORDED DISCOUNT IS THE CHARGED ONE, not a number the client supplied. */
    public function test_the_recorded_discount_is_the_servers_figure_not_the_posted_one(): void
    {
        [$store, $customer, $ownId] = $this->basketWithCoupon('MINE');

        $order = $this->checkout($store, $customer, 'MINE', null, [
            'coupon_info' => [
                'coupon_id' => $ownId,
                'coupon_name' => 'MINE',
                'coupon_code' => 'MINE',
                'coupon_discount_type' => 'flat',
                'coupon_discount_number' => 999.00,
                'coupon_discount_amount' => 999.00,
                'coupon_final_amount' => 0.00,
            ],
        ]);

        $this->assertNotNull($order, 'premise: the cod checkout wrote an order');

        $detail = DB::table('order_coupon_details')->where('order_id', $order->id)->first();

        $this->assertNotNull($detail, 'premise: a detail row exists to read');
        $this->assertEqualsWithDelta(self::FLAT, (float) $detail->coupon_discount_amount, 0.005,
            'the recorded discount is the POSTED 999.00 rather than the 50.00 the server took off');
        $this->assertEqualsWithDelta(self::FLAT, (float) $detail->coupon_discount_number, 0.005,
            'the recorded configured number came from the request rather than from the coupon row');
        $this->assertSame((int) $ownId, (int) $detail->coupon_id);
    }

    /*
    |--------------------------------------------------------------------------
    | FIXTURES
    |--------------------------------------------------------------------------
    */

    /** @return array{0:mixed,1:Customer,2:int} store, shopper, coupon id */
    private function basketWithCoupon(string $code, array $couponOverrides = []): array
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store);
        $this->basketFor($store, $customer, 1);

        return [$store, $customer, $this->coupon($store, $code, $couponOverrides)];
    }

    /**
     * Drive the shipped web checkout and return the order it wrote.
     *
     * `$after` excludes orders written by an earlier call in the same case, so the second checkout
     * of the cap test cannot read the first one's row.
     */
    private function checkout($store, Customer $customer, string $code, ?int $after = null, array $extra = []): ?Order
    {
        $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order', array_merge([
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'cod', 'coupon_code' => $code,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => $customer->email,
                'billing_user_telephone' => '123', 'billing_address' => 'x', 'billing_postecode' => '1',
                'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
                'billing_company_name' => '', 'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ], $extra));

        $query = Order::withoutGlobalScopes()->where('store_id', $store->id);

        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        return $query->latest('id')->first();
    }

    /** @return array{0:mixed,1:mixed} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'wcc-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $n => $v) {
            DB::table('settings')->insert(['name' => $n, 'value' => $v, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        (new \App\Models\Setting)->flushCache();

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $store];
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'wcc-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /** @return array<int, Product> */
    private function basketFor($store, Customer $customer, int $lines): array
    {
        $products = [];

        for ($i = 0; $i < $lines; $i++) {
            $product = Product::forceCreate([
                'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 0,
                'variant_product' => 0, 'price' => self::LINE, 'sale_price' => self::LINE,
                'status' => 1, 'stock_status' => 'in_stock',
                'store_id' => $store->id, 'created_by' => $store->created_by,
            ]);
            Cart::forceCreate([
                'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => self::LINE, 'store_id' => $store->id,
            ]);
            $products[] = $product;
        }

        return $products;
    }

    private function coupon($store, string $code, array $overrides = []): int
    {
        return (int) DB::table('coupons')->insertGetId($overrides + [
            'coupon_name' => $code, 'coupon_code' => $code,
            'coupon_type' => 'flat', 'discount_amount' => self::FLAT,
            'coupon_limit' => 1000, 'coupon_limit_user' => 1000,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'free_shipping_coupon' => 0, 'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
