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
         * And where the OWN coupon's row is written, its amount must be the server's 10% of 100.00
         * rather than the 99999 posted beside it. Conditional because this case's subject is the
         * foreign id: whether a row exists for the shopper's own coupon is the subject of the
         * accepted-coupon case above, and asserting it twice here would couple the two.
         */
        $recorded = DB::table('order_coupon_details')->where('coupon_id', $mine->id)->first();

        if ($recorded !== null) {
            $this->assertNotEquals(99999.0, (float) $recorded->coupon_discount_amount,
                "`{$path}`: the posted discount amount was written into the order's coupon record.");
        }
    }
}
