<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F134 / P4-F135 — WHAT `user_coupons` ACTUALLY COUNTS ON THE TWO API ORDER WRITERS.
 * =====================================================================================
 *
 * The Phase 04 findings register recorded both of these as READ, NOT MEASURED, and said in terms
 * that each needed driving end to end before anything was changed, "because the fix direction
 * changes what shoppers are allowed to spend". This file is that measurement. It is written before
 * the fix and three of its cases FAIL on the pre-fix code.
 *
 * WHY `user_coupons` IS THE TABLE THAT MATTERS. Every coupon cap in the application is a count on
 * that one table: `Coupon::usesByCustomer()`, `Coupon::PerUsesCouponCount()`, `Coupon::coupon_uses()`
 * and `Coupon::UsesCouponCount()`. A redemption missing from it is a cap that cannot fire; a
 * redemption wrongly in it is an allowance the shopper never spent.
 *
 * P4-F134 — `ordersave` RECORDS NOTHING. Read first, in the code: the complete set of keys this
 * handler ever puts in `$order_array['coupon_info']` is `message`, `status`, `code`, `discount`,
 * `discount_string`, `discount_string2`, `price` and `discount_amount` (ApiController :9173, :9205,
 * :9206, :9240, :9665-:9670). The child-row block at :9987 is gated on
 * `isset($coupon_data['coupon_id'])` — a key that is never set — so the guard is ALWAYS FALSE and
 * `POST /api/{slug}/order-save` writes neither `order_coupon_details` nor `user_coupons`, for every
 * order, with the discount granted. The per-user and total caps are therefore blind to that route
 * entirely, which is what the first two cases below measure.
 *
 * P4-F135 — `place_order` RECORDS A REFUSED COUPON. Its child-row block (:4145) is gated on
 * `!empty($request->coupon_info)` — the REQUEST — rather than on whether the server's own
 * `apply_coupon()` accepted anything, and every column is taken from that request array including
 * `coupon_discount_amount`. So a coupon the server refused still burns the shopper's allowance, and
 * `Coupon::find($coupon_data['coupon_id'])` resolves a caller-named id with no store predicate.
 *
 * THE ORACLE IS THE TABLES, READ RAW. `user_coupons` and `order_coupon_details` are read with
 * `DB::table()` rather than through Eloquent: this suite runs with `CACHE_STORE=array` and most
 * models here are `Cachable`, and the question is literally "what rows exist".
 */
final class ApiCouponRedemptionRecordingTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** One product, priced so a 10% coupon is an unambiguous 10.00. */
    private const BASKET = 100.00;

    protected function setUp(): void
    {
        parent::setUp();

        // Both writers mail the shopper and the store owner on success.
        Mail::fake();
    }

    /** @return array<string, array{0: string}> the two writers every storefront and mobile client calls */
    public static function writers(): array
    {
        return [
            'place-order' => ['place-order'],
            'order-save' => ['order-save'],
        ];
    }

    /**
     * A store with everything the two writers dereference, and a shopper with a one-line basket.
     *
     * @return array{0: \App\Models\Store, 1: Customer, 2: int} store, shopper, shipping id
     */
    private function checkout(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'cr-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        // Several handlers read the platform admin through Cache::remember('admin_details', ...).
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\Setting)->flushCache();

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = Customer::forceCreate([
            'first_name' => 'Coupon', 'last_name' => 'Shopper',
            'email' => 'cr-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $this->fillBasket($store, $customer);

        return [$store, $customer, $shippingId];
    }

    /** A fresh single-line basket. Called again between two orders, because the writers clear the cart. */
    private function fillBasket($store, Customer $customer): void
    {
        $product = Product::forceCreate([
            'name' => 'cr-' . uniqid(), 'slug' => 'cr-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => self::BASKET, 'sale_price' => self::BASKET,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => self::BASKET, 'store_id' => $store->id,
        ]);
    }

    /** A genuine 10%-off coupon of this store. `$perUser` is the cap this test wants to exercise. */
    private function coupon($store, array $overrides = []): object
    {
        $id = DB::table('coupons')->insertGetId(array_merge([
            'coupon_name' => 'SAVE10', 'coupon_code' => 'SAVE10-' . strtoupper(substr(uniqid(), -6)),
            'coupon_type' => 'percentage', 'discount_amount' => 10,
            'coupon_limit' => 100, 'coupon_limit_user' => 100,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'free_shipping_coupon' => 0, 'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return DB::table('coupons')->where('id', $id)->first();
    }

    /**
     * Drive the real route with a real Sanctum token.
     *
     * `coupon_info` carries ONLY what a client can honestly know — the code it typed. Every other
     * key a client might post is deliberately absent, because the point of both fixes is that the
     * server derives the rest; a fixture that posted `coupon_id` would be measuring a different
     * question. The forged-input cases below add keys explicitly and say why.
     */
    private function order(string $path, $store, Customer $customer, int $shippingId, ?array $couponInfo = null)
    {
        $body = [
            'customer_id' => $customer->id,
            'billing_info' => [
                'firstname' => 'Coupon', 'lastname' => 'Shopper', 'email' => 'cr@example.test',
                'billing_user_telephone' => '555111222',
                'billing_address' => 'Street', 'billing_postecode' => '11111',
                'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
                'billing_company_name' => '',
                'delivery_address' => 'Street', 'delivery_postcode' => '11111',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ];

        if ($couponInfo !== null) {
            $body['coupon_info'] = $couponInfo;
        }

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('cr')->plainTextToken,
        ])->postJson('/api/' . $store->slug . '/' . $path, $body);
    }

    private function redemptions(int $couponId): int
    {
        return (int) DB::table('user_coupons')->where('coupon_id', $couponId)->count();
    }

    private function couponDetailRows(int $couponId): int
    {
        return (int) DB::table('order_coupon_details')->where('coupon_id', $couponId)->count();
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * PREMISE — without this the "no rows" assertions below are about an order that never happened
     * ---------------------------------------------------------------------------------------------
     */

    /** @dataProvider writers */
    public function test_premise_both_writers_create_an_order_for_this_basket(string $path): void
    {
        [$store, $customer, $shippingId] = $this->checkout();

        $this->order($path, $store, $customer, $shippingId);

        $this->assertSame(1, (int) DB::table('orders')->where('store_id', $store->id)->count(),
            "`{$path}` wrote no order for this fixture, so every coupon assertion in this file would "
            . 'be measuring a checkout that did not happen. Fix the fixture before reading them.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * P4-F134 — an accepted coupon must be RECORDED as redeemed
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * @dataProvider writers
     */
    public function test_an_accepted_coupon_records_exactly_one_redemption(string $path): void
    {
        [$store, $customer, $shippingId] = $this->checkout();
        $coupon = $this->coupon($store);

        $response = $this->order($path, $store, $customer, $shippingId, [
            'coupon_code' => $coupon->coupon_code,
        ]);

        // Premise: the coupon was ACCEPTED. A refused coupon would correctly record nothing.
        $this->assertSame(1, (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'Premise: the order must exist. Response: ' . $response->getContent());
        $this->assertGreaterThan(0.0,
            (float) DB::table('orders')->where('store_id', $store->id)->value('coupon_price'),
            'Premise: the server must have APPLIED a discount, or "no redemption recorded" is the '
            . 'correct outcome and this case proves nothing. Response: ' . $response->getContent());

        $this->assertSame(1, $this->redemptions((int) $coupon->id),
            "`{$path}` granted the discount and recorded no redemption in `user_coupons`. Every "
            . 'coupon cap in the application is a count on that table, so the merchant\'s own '
            . 'per-user and total limits cannot fire for orders placed this way (P4-F134).');
        $this->assertSame(1, $this->couponDetailRows((int) $coupon->id),
            "`{$path}` granted the discount and wrote no `order_coupon_details` row, so the order "
            . 'carries no record of which coupon reduced it.');
    }

    /**
     * THE MONEY CONSEQUENCE, and the reason F134 is not merely an audit gap.
     *
     * A coupon the merchant limited to ONE use per shopper must be refused the second time. The cap
     * is checked by `apply_coupon()` through `Coupon::usesByCustomer()`, which counts `user_coupons`
     * — so a writer that never inserts there makes the limit unenforceable however it is configured.
     *
     * @dataProvider writers
     */
    public function test_a_one_per_shopper_coupon_cannot_be_used_twice(string $path): void
    {
        [$store, $customer, $shippingId] = $this->checkout();
        $coupon = $this->coupon($store, ['coupon_limit_user' => 1]);

        $first = $this->order($path, $store, $customer, $shippingId, ['coupon_code' => $coupon->coupon_code]);

        $this->assertGreaterThan(0.0,
            (float) DB::table('orders')->where('store_id', $store->id)->value('coupon_price'),
            'Premise: the FIRST use must have been discounted. Response: ' . $first->getContent());

        // A second checkout by the same shopper, with the same coupon.
        $this->fillBasket($store, $customer);
        $this->order($path, $store, $customer, $shippingId, ['coupon_code' => $coupon->coupon_code]);

        $this->assertSame(2, (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'Premise: both checkouts must have produced an order — the second is expected to '
            . 'succeed WITHOUT the discount, not to be refused outright.');

        $discounted = (int) DB::table('orders')->where('store_id', $store->id)
            ->where('coupon_price', '>', 0)->count();

        $this->assertSame(1, $discounted,
            "`{$path}`: a coupon limited to one use per shopper discounted {$discounted} orders for "
            . 'the same shopper. The limit is a count on `user_coupons`, so a writer that records no '
            . 'redemption makes it unenforceable (P4-F134).');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * P4-F135 — a REFUSED coupon must record nothing
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * The coupon exists and belongs to this store, but the basket does not qualify: a minimum spend
     * above the basket total is the cheapest honest way to make "refused" a real server verdict
     * rather than an error.
     *
     * @dataProvider writers
     */
    public function test_a_refused_coupon_burns_no_allowance(string $path): void
    {
        [$store, $customer, $shippingId] = $this->checkout();
        $coupon = $this->coupon($store, ['minimum_spend' => 100000]);

        $response = $this->order($path, $store, $customer, $shippingId, [
            'coupon_code' => $coupon->coupon_code,
        ]);

        // Premise: the order happened AND the coupon really was refused.
        $this->assertSame(1, (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'Premise: the order must exist. Response: ' . $response->getContent());
        $this->assertSame(0.0,
            (float) DB::table('orders')->where('store_id', $store->id)->value('coupon_price'),
            'Premise: the server must have REFUSED the discount, or this case is about an accepted '
            . 'coupon. Response: ' . $response->getContent());

        $this->assertSame(0, $this->redemptions((int) $coupon->id),
            "`{$path}` recorded a redemption in `user_coupons` for a coupon the server REFUSED. The "
            . 'shopper got no discount and lost an allowance they never spent (P4-F135).');
        $this->assertSame(0, $this->couponDetailRows((int) $coupon->id),
            "`{$path}` wrote an `order_coupon_details` row for a refused coupon, so the order claims "
            . 'a discount it was never given.');
    }

    /**
     * THE IDENTITY HALF OF F135: a caller-named `coupon_id` must decide nothing.
     *
     * The register's prescription for both findings is the same — "derive the coupon from the
     * server's own validated resolution, never from the caller's `coupon_id`" — and the posted id
     * here names ANOTHER STORE's coupon, which `Coupon::find()` resolves with no store predicate.
     *
     * @dataProvider writers
     */
    public function test_a_posted_coupon_id_cannot_name_another_stores_coupon(string $path): void
    {
        [$store, $customer, $shippingId] = $this->checkout();
        [, $otherStore] = $this->makeTenant();

        $mine = $this->coupon($store);
        $theirs = $this->coupon($otherStore);

        $this->order($path, $store, $customer, $shippingId, [
            'coupon_code' => $mine->coupon_code,
            // A client cannot know this id, and the server must not read it.
            'coupon_id' => $theirs->id,
            'coupon_name' => 'FORGED',
            'coupon_code_forged' => 'FORGED',
            'coupon_discount_type' => 'flat',
            'coupon_discount_number' => 99999,
            'coupon_discount_amount' => 99999,
            'coupon_final_amount' => 0.01,
        ]);

        $this->assertSame(0, $this->redemptions((int) $theirs->id),
            "`{$path}`: a posted `coupon_id` naming another store's coupon was recorded as redeemed "
            . 'against that store\'s coupon, so one merchant\'s shopper can exhaust another '
            . 'merchant\'s campaign.');
        $this->assertSame(0, $this->couponDetailRows((int) $theirs->id),
            "`{$path}`: another store's coupon id reached this order's `order_coupon_details`.");

        /*
         * And the OWN coupon's row must exist and carry the SERVER's amount, not the 99999 posted
         * beside it. UNCONDITIONAL, which is a correction: it used to be wrapped in
         * `if ($recorded !== null)`, so all four assertions of this case passed if the recorder
         * wrote nothing at all — the F134 direction, i.e. the case could not fail in the one
         * direction its own message claims to cover. Found by independent review.
         */
        $recorded = DB::table('order_coupon_details')->where('coupon_id', $mine->id)->first();

        $this->assertNotNull($recorded,
            "`{$path}`: no row was written for the shopper's OWN coupon, so the three assertions "
            . 'above passed vacuously — nothing was recorded for either coupon.');
        $this->assertNotEquals(99999.0, (float) $recorded->coupon_discount_amount,
            "`{$path}`: the posted discount amount was written into the order's coupon record.");
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * THE REVIEW OF THE FIRST VERSION OF THIS FIX. Three defects it found, each driven here.
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE RECORDED DISCOUNT MUST BE THE DISCOUNT CHARGED.
     *
     * The original fixture could not see this: a 100.00 basket with a 10% coupon makes the coupon's
     * CONFIGURED number (10, the percent) and the real discount (10.00) the same figure, so a handler
     * recording either passed. `apply_coupon` publishes the configured number as
     * `coupon_discount_amount` for every type but `fixed product discount` (:4970), and `ordersave`
     * carried that into `orders.coupon_price` and from there into both child rows — so a 10% coupon on
     * a 300.00 basket charged 270.00 and recorded a discount of 10.00, a row contradicting itself and
     * an understated `user_coupons.amount` that `Api\DashboardController` shows the merchant.
     *
     * A 200.00 basket separates the two numbers: configured 10, charged 20.00.
     *
     * @dataProvider writers
     */
    public function test_the_recorded_discount_is_the_discount_actually_charged(string $path): void
    {
        [$store, $customer, $shippingId] = $this->checkout();

        // A second line, so the basket is 200.00 and 10% is 20.00 — distinct from the configured 10.
        $this->fillBasket($store, $customer);

        $coupon = $this->coupon($store);   // percentage, discount_amount = 10

        $response = $this->order($path, $store, $customer, $shippingId, [
            'coupon_code' => $coupon->coupon_code,
        ]);

        $order = DB::table('orders')->where('store_id', $store->id)->first();

        $this->assertNotNull($order, 'Premise: the order must exist. Response: ' . $response->getContent());

        $detail = DB::table('order_coupon_details')->where('coupon_id', $coupon->id)->first();
        $redemption = DB::table('user_coupons')->where('coupon_id', $coupon->id)->first();

        $this->assertNotNull($detail, "`{$path}` recorded no coupon detail row for an accepted coupon");
        $this->assertNotNull($redemption, "`{$path}` recorded no redemption for an accepted coupon");

        /*
         * The oracle is the coupon's CONFIGURED number, which must NOT be what was recorded. Stated
         * as an inequality as well as an equality because the equality alone would also hold if both
         * figures happened to coincide, which is exactly how the first fixture hid this.
         */
        $this->assertGreaterThan(
            (float) $coupon->discount_amount,
            (float) $detail->coupon_discount_amount,
            "`{$path}` recorded {$detail->coupon_discount_amount} against a basket of 200.00 and a "
            . "coupon configured at {$coupon->discount_amount}. That is the coupon's configured "
            . 'number, not the amount taken off: the row contradicts itself and the merchant is '
            . 'shown the wrong figure.'
        );

        $this->assertEqualsWithDelta(
            (float) $detail->coupon_discount_amount,
            (float) $redemption->amount,
            0.01,
            "`{$path}`: the detail row and the redemption row disagree about one discount"
        );
    }

    /**
     * A COUPON THE SERVER'S OWN PLAUSIBILITY GUARD REFUSED MUST RECORD NOTHING.
     *
     * `ordersave` has an E6 guard that refuses an implausible discounted total — `$couponedTotal > 0`
     * — and a flat coupon worth the whole basket produces exactly 0.00, so the guard fires and the
     * shopper is charged IN FULL. The first version of this fix still recorded the redemption,
     * because `$verified_coupon` was assigned on both arms of that guard and the gate read the
     * coupon's configured face value. Full price paid AND the allowance burned: P4-F135's harm
     * arriving in the other handler.
     *
     * Driven on both writers because the property — never record a discount that was not given —
     * belongs to both, even though only `ordersave` carries the guard.
     *
     * @dataProvider writers
     */
    public function test_a_coupon_worth_the_whole_basket_records_nothing_if_the_discount_was_refused(string $path): void
    {
        [$store, $customer, $shippingId] = $this->checkout();

        $coupon = $this->coupon($store, [
            'coupon_type' => 'flat',
            'discount_amount' => self::BASKET,   // the entire basket
        ]);

        $response = $this->order($path, $store, $customer, $shippingId, [
            'coupon_code' => $coupon->coupon_code,
        ]);

        $order = DB::table('orders')->where('store_id', $store->id)->first();

        $this->assertNotNull($order, 'Premise: the order must exist. Response: ' . $response->getContent());

        /*
         * THE CONSERVATION LAW, and it is the only honest oracle here because the two handlers are
         * allowed to differ on whether such a coupon applies at all: whatever was CHARGED, the
         * redemption record must agree with it. If the shopper paid full price, nothing was
         * redeemed; if they got the discount, exactly one redemption stands.
         */
        $paidInFull = (float) $order->product_price > 0
            && abs((float) $order->product_price - self::BASKET) < 0.01
            && (float) $order->final_price >= self::BASKET - 0.01;

        $redemptions = $this->redemptions((int) $coupon->id);

        if ($paidInFull) {
            $this->assertSame(0, $redemptions,
                "`{$path}` charged the shopper the full basket (final_price {$order->final_price}) "
                . 'and recorded a redemption anyway. They paid in full AND lost the allowance.');
            $this->assertSame(0, $this->couponDetailRows((int) $coupon->id),
                "`{$path}` wrote a coupon detail row for a discount it refused to give");
        } else {
            $this->assertSame(1, $redemptions,
                "`{$path}` gave the discount (final_price {$order->final_price}) and recorded "
                . 'no redemption, so the cap cannot see it');
        }
    }

    /**
     * AN UNQUOTED ALL-DIGIT CODE IN A JSON BODY IS STILL A CODE.
     *
     * `apply_coupon` resolves from `trim($request->coupon_code)`, and `trim()` casts — `trim(2024)`
     * is `"2024"` — so a JSON client posting `{"coupon_code": 2024}` against a coupon whose code is
     * `"2024"` is ACCEPTED and the discount is subtracted. The first version of this fix asked
     * `is_string()`, which is false for an int, and recorded nothing: discount given, cap blind.
     * The recorder is now handed the same coercion the acceptance used, so the two cannot disagree.
     *
     * @dataProvider writers
     */
    public function test_an_unquoted_numeric_coupon_code_is_still_recorded(string $path): void
    {
        [$store, $customer, $shippingId] = $this->checkout();

        $coupon = $this->coupon($store, ['coupon_code' => '2024']);

        // postJson, and the value is a PHP int — so it arrives as a JSON number, not a string.
        $response = $this->order($path, $store, $customer, $shippingId, [
            'coupon_code' => 2024,
        ]);

        $order = DB::table('orders')->where('store_id', $store->id)->first();
        $this->assertNotNull($order, 'Premise: the order must exist. Response: ' . $response->getContent());

        // Premise: the server ACCEPTED it. Without this the assertion below is about a refusal.
        $this->assertGreaterThan(0.0, (float) $order->coupon_price,
            'Premise: an unquoted numeric code must still be accepted upstream, or this case is '
            . 'measuring a refusal rather than a recording gap. Response: ' . $response->getContent());

        $this->assertSame(1, $this->redemptions((int) $coupon->id),
            "`{$path}` accepted an unquoted numeric coupon code, gave the discount, and recorded no "
            . 'redemption — so that coupon is uncapped for any JSON client (P4-F134 re-opened).');
    }
}
