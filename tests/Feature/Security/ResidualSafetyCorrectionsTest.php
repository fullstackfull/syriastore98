<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-14 Work Package C — the residual items, and the corrections that were safe to make.
 *
 * The brief asked for five items to be verified and classified, with "only safe, independent
 * corrections" implemented. Three produced code changes and are tested here. Two did not, for
 * reasons that are findings rather than omissions, and are recorded in
 * docs/security/P01-SECURITY-WORK-QUEUE.md rather than asserted in a test:
 *
 *   C1  user_delete's order deletion and financial retention. Measured: Order uses no SoftDeletes
 *       (app/Models/Order.php:13), the delete is a query-builder mass delete so no model events
 *       fire, OrderObserver has only created/updated hooks, and OrderFinancialService has no
 *       reversal method at all. So deleting an account orphans its store_order_financials rows
 *       with no path to reverse the wallet. It CANNOT be demonstrated in this suite: those tables
 *       have no applied migration (only drafts under database/migrations/_gate_f_pending/), so in
 *       tests the observer's try/catch swallows the failure and no financial row is ever written.
 *       And the correction -- not deleting the orders -- is a retention policy change, which the
 *       brief reserves to the owner. Classified, not coded.
 *
 *   C5  customers whose store_id is null. The measurement that decides it --
 *       SELECT COUNT(*) FROM customers WHERE store_id IS NULL -- is a production query, and this
 *       package may not touch live data. Tightening blind would lock a legacy shopper out of their
 *       own account; adopting such a row on first use would silently re-parent an account, which is
 *       the exact defect class P01-SEC-11 closed. So the deliverable is a read-only diagnostic the
 *       owner can run: tests/Support/Security/bin/diagnose-data-integrity.php.
 */
class ResidualSafetyCorrectionsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $label = 'c'): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'res-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'res-product-' . uniqid(), 'slug' => 'res-product-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function callApi($store, string $path, array $payload, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->postJson('/api/' . $store->slug . '/' . $path, $payload);
    }

    /**
     * The route is `{slug}/notify_user` with an UNDERSCORE (routes/api.php:116), unlike almost
     * every other storefront path in that file, which uses hyphens. The first version of this file
     * used `notify-user` and three denial tests passed against a 404 -- they were asserting that
     * nothing was written by an endpoint they never reached.
     */
    private static function notifyRows()
    {
        return DB::table('notify_users');
    }

    // ===================================================================================
    // C2 — notify_user
    // ===================================================================================

    /**
     * THE LIVE DEFECT. `notify_users.user_id` carries a foreign key to `users`
     * (2024_03_21_084240_create_notify_users_table.php:23) and the handler took that id from the
     * request. The consumer, ProductController:1609-1618, resolves `User::find($value->user_id)`
     * on restock and sends an FCM PUSH to that user's firebase_token.
     *
     * So the write bought a caller the ability to aim unsolicited push notifications at a
     * merchant's -- or the platform operator's -- device, triggered by a restock of a product the
     * caller chose.
     */
    public function test_a_caller_cannot_enrol_an_arbitrary_platform_user_for_push_notifications(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $attacker = $this->customerIn($store, 'c2a');
        $product = $this->productIn($store);

        foreach ([$operator->id, $merchant->id] as $victimUserId) {
            $this->callApi($store, 'notify_user', [
                'user_id' => $victimUserId, 'product_id' => $product->id,
            ], $attacker->createToken('t' . $victimUserId)->plainTextToken);
        }

        $this->assertSame(0, self::notifyRows()->count(),
            'A shopper enrolled a platform user for push notifications about a restock.');
    }

    /** The same shape with a merchant's and a driver's token, which custom.auth also admits. */
    public function test_neither_a_merchant_nor_a_driver_token_can_enrol_a_user(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store);
        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);

        foreach ([$merchant->createToken('m')->plainTextToken,
                  $driver->createToken('d')->plainTextToken] as $token) {
            $this->callApi($store, 'notify_user', [
                'user_id' => $merchant->id, 'product_id' => $product->id,
            ], $token);
        }

        $this->assertSame(0, self::notifyRows()->count(),
            "A merchant's or driver's token wrote a notify_users row.");
    }

    /**
     * The cross-tenant limb: Product registers StoreScope but is not in config/tenancy.php's
     * scoped_models, so an unscoped find() reaches every tenant's catalogue. This is the same
     * omission P01-SEC-11 closed in addtocart, wishlist, variant_list and the checkout stock loops,
     * and notify_user still carried it.
     */
    public function test_a_caller_cannot_name_another_tenants_product(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $attacker = $this->customerIn($storeA, 'c2c');
        $foreignProduct = $this->productIn($storeB);

        $this->callApi($storeA, 'notify_user', [
            'user_id' => $merchantB->id, 'product_id' => $foreignProduct->id,
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertSame(0, self::notifyRows()->where('product_id', $foreignProduct->id)->count(),
            "A shopper of store A enrolled against store B's product.");
    }

    /**
     * The endpoint fails CLOSED rather than writing a misdirected row.
     *
     * A storefront caller is a `customers` row, and a customers id in a column with an FK to
     * `users` either violates the constraint or points at whichever MERCHANT shares that integer --
     * the same id-space conflation P01-SEC-11 closed in user_delete and product_rating. So there is
     * no correct row for this endpoint to write until the schema can record a customer, and it
     * writes none. Asserted explicitly so a future change cannot reopen it by accident.
     */
    public function test_even_a_legitimate_shopper_writes_no_row_while_the_schema_cannot_hold_one(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'c2d');
        $product = $this->productIn($store);

        $response = $this->callApi($store, 'notify_user', [
            'user_id' => $customer->id, 'product_id' => $product->id,
        ], $customer->createToken('t')->plainTextToken);

        $this->assertSame(0, $response->json('status'));
        $this->assertSame(0, self::notifyRows()->count(),
            'notify_user wrote a row again. If the schema now has a customer_id column, this test '
            . 'should be rewritten to assert the correct write rather than relaxed.');
        $this->assertSame(200, $response->getStatusCode(),
            'The refusal changed the HTTP status, which a Flutter client reads as a forced logout.');
    }

    // ===================================================================================
    // C3 — ordersave's coupon_info and settings reads
    // ===================================================================================

    /**
     * ordersave could not complete for ANY caller, and the 500 landed after the stock decrement and
     * the cart delete -- so a shopper's basket was emptied and a merchant's stock moved with no
     * order to show for it. Three causes, all measured by running it:
     *
     *   1. `$order_array['coupon_info']['discount_amount']` read a key populated only inside the
     *      coupon branch, with coupon_info initialised to null.
     *   2. `$settings['out_of_stock_threshold']` -- absent, because this method reads settings with
     *      a raw pluck that supplies no defaults, unlike place_order's Utility::Setting().
     *   3. `$settings['notification']` -- same cause.
     *
     * The authorized journey is asserted in StorefrontOrderPlacementAuthorizationTest, whose
     * previous "ordersave cannot complete for anyone" test was replaced by a premise assertion once
     * this fix landed. What is asserted HERE is the specific thing that was crashing: an order with
     * NO coupon saves, and records a zero discount rather than null or a crash.
     */
    public function test_an_order_with_no_coupon_records_a_zero_discount(): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'c3');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $this->callApi($store, 'order-save', [
            'customer_id' => $customer->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ], $customer->createToken('t')->plainTextToken);

        $order = \App\Models\Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->first();

        $this->assertNotNull($order,
            'order-save still cannot complete for an order with no coupon.');
        $this->assertSame(0.0, (float) $order->coupon_price,
            'An order with no coupon recorded a non-zero discount. coupon_price is a money column, '
            . 'so the default matters.');
    }

    /**
     * THE HALF THE C3 CRASH FIX DID NOT CLOSE, found by re-reading the line it touched.
     *
     * `$order->coupon_price` is written from `$order_array['coupon_info']['discount_amount']`, and
     * that array is populated at Api/ApiController.php:7781-7802 from **`$request->coupon_info`**:
     *
     *     if (!empty($request->coupon_info)) {
     *         $coupon_data = $request->coupon_info;
     *         ...
     *         $order_array['coupon_info']['discount_amount']
     *             = SetNumber($coupon_data['coupon_discount_amount']);
     *     }
     *
     * No coupon is looked up, no code is checked against this store, and no arithmetic is
     * re-derived: the shopper posts the discount and the order records it. Adding `?? 0` fixed the
     * crash on the no-coupon path and left this one exactly as it was, which is why it is closed
     * here rather than filed.
     *
     * WHAT IT COSTS, MEASURED RATHER THAN ASSUMED. `$order->final_price` is computed from
     * `$order_array['final_price']`, which is `$cartlist_final_price` -- server-derived -- so a
     * posted discount does NOT reduce what the order says is payable. It corrupts the discount
     * column that the merchant's invoice, reports and any refund arithmetic read. That is a
     * financial-record integrity defect, not a free-goods one, and the two assertions below say so
     * in those terms.
     *
     * THE FIX: take `coupon_info` from ordersave's OWN cart_list() response, which is already in
     * scope as `$cartlist` and which derives the discount from the coupon row.
     */
    public function test_a_shopper_cannot_post_their_own_discount_onto_an_order(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'c3c');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        // No coupon exists anywhere on the platform. Everything below is the caller's invention.
        $this->callApi($store, 'order-save', [
            'customer_id' => $customer->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
            'coupon_info' => [
                'coupon_code' => 'INVENTED',
                'coupon_discount_type' => 'flat',
                'coupon_discount_number' => '95',
                'coupon_discount_amount' => '95',
                'coupon_final_amount' => '5',
            ],
        ], $customer->createToken('t')->plainTextToken);

        $order = \App\Models\Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->first();

        $this->assertNotNull($order,
            'order-save did not complete, so this test is measuring a crash rather than a control.');
        $this->assertSame(0.0, (float) $order->coupon_price,
            'The order recorded a discount of ' . $order->coupon_price . ' from a coupon that does '
            . 'not exist, because the figure came from the request body.');
    }

    /**
     * THE PRESERVATION, and the control for the test above: a coupon the SERVER accepts is still
     * recorded, with the server's own figure.
     *
     * Without this, "the order records 0" is satisfiable by writing 0 unconditionally.
     *
     * WHY THE FIXTURE IS A `fixed product discount` AND NOT THE OBVIOUS PERCENTAGE ONE. Measured:
     * apply_coupon() refuses every other coupon type. Api/ApiController.php:3703 handles `flat`
     * and :3707 handles `percentage`, computing the discount correctly -- and then :3716 opens
     * `if ($coupon->coupon_type == 'fixed product discount')` whose `} else {` at :3943 returns
     * "The minimum spend for this coupon is ...". So the else belongs to the TYPE test, not to a
     * spend test, and a percentage coupon with no minimum spend is refused with a minimum-spend
     * message. The same shape is at :4268. Dumped from a real request: a 10% coupon on a 100.00
     * cart came back `{"message":" The minimum spend for this coupon is $0,00."}` with
     * `sub_total_min='100.00' min=NULL max=NULL`, so neither spend bound was involved.
     *
     * THAT IS NOT FIXED HERE, deliberately. Repairing it would make discounts start applying on a
     * live platform where they currently never do -- a revenue change, and the owner's call, not
     * an authorization fix's. It is S24 in docs/security/P01-SECURITY-WORK-QUEUE.md with the
     * one-line change named. What it costs in the meantime is recorded in the same row: with the
     * fix above in place, a percentage or flat coupon records `coupon_price = 0` where it used to
     * record whatever the client posted. 0 is the server's honest answer -- the server refused the
     * coupon -- but it is a CHANGE, so it is reported rather than buried.
     */
    public function test_the_recorded_discount_is_the_servers_verdict(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'c3d');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        $this->makeAcceptedCoupon($store, 'REALFIXED', $product->id);

        $this->callApi($store, 'order-save', [
            'customer_id' => $customer->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
            // Posted the way the documented contract posts it: theme.json's order-save request
            // carries a `coupon_info` object. The CODE is a claim the server must test; the
            // NUMBERS in it are what must not be trusted -- so they are posted WRONG on purpose.
            'coupon_info' => [
                'coupon_code' => 'REALFIXED',
                'coupon_discount_type' => 'flat',
                'coupon_discount_number' => '95',
                'coupon_discount_amount' => '95',
                'coupon_final_amount' => '5',
            ],
        ], $customer->createToken('t')->plainTextToken);

        $order = \App\Models\Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->first();

        $this->assertNotNull($order,
            'order-save did not complete with an accepted coupon, so this test is measuring a '
            . 'crash rather than a control.');

        $recorded = (float) $order->coupon_price;

        $this->assertNotSame(95.0, $recorded,
            'The order recorded the posted 95, so the request is still the source of the figure.');
        $this->assertSame(7.0, $recorded,
            'The order recorded ' . $recorded . '. 7 is the coupon row\'s own discount_amount, '
            . 'which is what the server computed; 0 would mean the fix refuses coupons the server '
            . 'accepted, and 95 would mean the posted number is still being read.');
    }
    /**
     * A POSTED SHIPPING PRICE MUST NOT REACH THE ORDER'S LEDGER COLUMNS.
     *
     * `cart_list` reads `$shipping_price = (int)$request['shipping_final_price']` with no floor and
     * adds it to `total_sub_price`, which `place_order` stores as `orders.product_price`. MEASURED
     * on a 100.00 basket through api/{slug}/place-order:
     *
     *   nothing posted                    product_price  100.0   final_price 100.0
     *   shipping_final_price = 500        product_price  600.0   final_price 100.0
     *   shipping_final_price = -900       product_price -800.0   final_price 100.0
     *
     * `final_price` -- the amount owed -- never moved, so this is the merchant's ledger and
     * reporting rather than what the shopper pays. It is the same class as the posted discount D1
     * closed on `coupon_price`, and it is closed the same way. Found by an adversarial review of
     * commit E.
     *
     * The order's shipping is unaffected: it lives in its own column, `delivery_price`, from
     * get_shipping_method()'s server computation, which runs after this read.
     */
    public function test_a_posted_shipping_price_cannot_reach_the_orders_ledger(): void
    {
        $recorded = [];

        foreach ([null, 500, -900] as $shipping) {
            [, $store, $shippingId] = $this->orderableTenant();
            $customer = $this->customerIn($store, 'f5' . (string) $shipping);
            $product = $this->productIn($store);
            Cart::forceCreate([
                'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            ]);

            $payload = [
                'customer_id' => $customer->id,
                'billing_info' => $this->billing(),
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
            ];
            if ($shipping !== null) {
                $payload['shipping_final_price'] = $shipping;
            }

            $this->callApi($store, 'place-order', $payload,
                $customer->createToken('t')->plainTextToken);

            $order = \App\Models\Order::withoutGlobalScopes()
                ->where('customer_id', $customer->id)->first();
            $this->assertNotNull($order,
                'place-order did not complete with shipping_final_price = ' . json_encode($shipping)
                . ', so this test is measuring a crash rather than a control.');

            $recorded[json_encode($shipping)] = [
                (float) $order->product_price, (float) $order->final_price,
            ];
        }

        $this->assertSame(
            ['null' => [100.0, 100.0], '500' => [100.0, 100.0], '-900' => [100.0, 100.0]],
            $recorded,
            'A posted shipping price moved a money column of the order. Measured before the fix: '
            . '500 wrote product_price 600.0 and -900 wrote -800.0.'
        );
    }

    /**
     * A NEGATIVE CART LINE MUST NOT REDUCE THE ORDER, AND MUST NOT INFLATE THE MERCHANT'S STOCK.
     *
     * P01-SEC-14 E put this refusal on `payment_sheet` alone, which was the wrong scope: the two
     * handlers that write the order's money columns read the same cart rows and were untouched, so
     * the whole control was bypassed by placing a COD order instead of paying by card. An
     * adversarial review of that commit traced it; these are the measurements that confirmed it,
     * taken before the fix with a basket of one 1000.00 product plus one 50.00 product at qty -5:
     *
     *   order-save    product_price 750.0   final_price 750.0   cheap product stock 20 -> 25
     *   place-order   product_price 750.0   final_price 750.0   cheap product stock 20 -> 25
     *
     * So the shopper takes 1000.00 of goods for 750.00 and the merchant's inventory is corrupted
     * in the same request -- `$remain_stock = $ProductStock->stock - $qtyy` with a negative `$qtyy`
     * ADDS stock. `final_price` is the amount owed, so this is not a reporting column.
     *
     * Both handlers are driven, because a guard in one of them proves nothing about the other --
     * which is exactly how the gap arose.
     */
    public function test_a_negative_cart_line_cannot_reduce_an_order_or_inflate_stock(): void
    {
        foreach (['order-save', 'place-order'] as $path) {
            [, $store, $shippingId] = $this->orderableTenant();
            $customer = $this->customerIn($store, 'f1' . md5($path));
            $big = $this->productIn($store);
            $cheap = Product::forceCreate([
                'name' => 'f1-cheap-' . uniqid(), 'slug' => 'f1-cheap-' . uniqid(),
                'product_stock' => 20, 'track_stock' => 1, 'variant_product' => 0,
                'price' => 50.00, 'sale_price' => 50.00,
                'store_id' => $store->id, 'created_by' => $store->created_by,
            ]);

            Cart::forceCreate([
                'customer_id' => $customer->id, 'product_id' => $big->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 1000.00, 'store_id' => $store->id,
            ]);
            Cart::forceCreate([
                'customer_id' => $customer->id, 'product_id' => $cheap->id, 'variant_id' => 0,
                'qty' => -5, 'price' => -250.00, 'store_id' => $store->id,
            ]);

            $this->callApi($store, $path, [
                'customer_id' => $customer->id,
                'billing_info' => $this->billing(),
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
            ], $customer->createToken('t')->plainTextToken);

            $order = \App\Models\Order::withoutGlobalScopes()
                ->where('customer_id', $customer->id)->first();

            $this->assertNull($order,
                $path . ' recorded an order for a basket holding a negative line: product_price '
                . ($order->product_price ?? '?') . ', final_price ' . ($order->final_price ?? '?')
                . ' against a basket worth 1000.00.');

            $this->assertSame(20, (int) Product::withoutGlobalScopes()
                ->find($cheap->id)->product_stock,
                $path . " moved the merchant's stock for a refused order. It goes UP, not down, "
                . 'because the decrement subtracts a negative quantity.');
        }
    }

    /**
     * THE PAYER CANNOT SET WHAT THEY OWE ON THE WEB CHECKOUT. The sharpest money defect the whole
     * package found, and a different column from the API one.
     *
     * MEASURED through the real route on a 100.00 basket, before the fix:
     *
     *   posted                         orders.product_price   orders.final_price
     *   (nothing)                              100.0               100.0
     *   shipping_final_price = 500             100.0               600.0
     *   shipping_final_price = -900            100.0              -800.0
     *
     * `final_price` is the amount OWED -- for a `cod` order it is the cash the driver collects --
     * and it lands there from `$cartlist['total_sub_price']` (PaymentController.php:1997, :2025),
     * which cart_list builds by adding `(int)$request['shipping_final_price']` with no floor.
     *
     * Note the contrast with the API path, where the same input moved `product_price` only, which
     * is the ledger. Here it moves what the shopper pays. F5 fixed the three API handlers and
     * stopped -- the third time in this package a guard was scoped to the API while the web path
     * stayed open. Found by an adversarial review of commit F.
     *
     * Six gateway-RETURN controllers have the identical defect and are NOT fixed: driving one needs
     * that gateway's credentials and a real callback. The one-line remedy and the test that would
     * prove it are written up as S31 in docs/security/P01-SECURITY-WORK-QUEUE.md.
     */
    public function test_a_posted_shipping_price_cannot_change_what_the_web_checkout_charges(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();

        // PaymentService writes a `cities` row keyed on these ids; without the parents the whole
        // checkout dies on a foreign-key violation and every assertion here is vacuous.
        DB::table('countries')->insert([
            'id' => 101, 'name' => 'Testland', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insert([
            'id' => 1, 'name' => 'Teststate', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insert([
            'id' => 1, 'name' => 'Testcity', 'state_id' => 1, 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $billing = array_merge($this->billing(), [
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ]);

        $recorded = [];

        foreach ([null, 500, -900] as $shipping) {
            $customer = $this->customerIn($store, 'h' . (string) $shipping);
            $product = $this->productIn($store);
            Cart::forceCreate([
                'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            ]);

            $payload = [
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
                'billing_info' => $billing,
            ] + $billing;
            if ($shipping !== null) {
                $payload['shipping_final_price'] = $shipping;
            }

            $this->actingAs($customer, 'customers')
                ->post('/' . $store->slug . '/process-order', $payload);

            $order = \App\Models\Order::withoutGlobalScopes()
                ->where('customer_id', $customer->id)->first();

            $this->assertNotNull($order,
                'The web checkout recorded no order for shipping_final_price = '
                . json_encode($shipping) . ', so this test is measuring a broken checkout rather '
                . 'than the control. Session error: ' . json_encode(session('error')));

            $recorded[json_encode($shipping)] = [
                (float) $order->product_price, (float) $order->final_price,
            ];
        }

        $this->assertSame(
            ['null' => [100.0, 100.0], '500' => [100.0, 100.0], '-900' => [100.0, 100.0]],
            $recorded,
            'A posted shipping price moved what the web checkout charges. Measured before the fix: '
            . '500 recorded final_price 600.0 and -900 recorded -800.0, against a basket worth '
            . '100.00.'
        );
    }

    /**
     * `confirm_order`'s QUANTITY GUARD, which had no test -- and the absence was not theoretical.
     *
     * F added the non-positive-quantity refusal to three handlers and tested two. An adversarial
     * review of F flagged the third as uncovered, and the proof arrived by accident minutes later:
     * a stray `if (false)` was left over that handler's guard in a working tree and **the entire
     * Feature suite still passed, gate and all**. A guard no test touches is a guard that can be
     * disabled without anyone noticing, which is the whole argument for this file.
     *
     * confirm_order persists nothing, so the observable is its REFUSAL rather than an absent order:
     * the preview must not price a basket the order writers will refuse.
     */
    public function test_the_confirmation_preview_refuses_a_basket_with_a_negative_line(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'confirmqty');
        $big = $this->productIn($store);
        $cheap = Product::forceCreate([
            'name' => 'cq-' . uniqid(), 'slug' => 'cq-' . uniqid(),
            'product_stock' => 20, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 50.00, 'sale_price' => 50.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        $token = $customer->createToken('t')->plainTextToken;

        $payload = [
            'customer_id' => $customer->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ];

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $big->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 1000.00, 'store_id' => $store->id,
        ]);

        // THE PREMISE FIRST: a clean basket previews successfully, so a refusal below is the guard
        // and not this endpoint failing for an unrelated reason.
        $clean = $this->callApi($store, 'confirm-order', $payload, $token);
        $this->assertSame(1, (int) $clean->json('status'),
            'confirm-order refused a clean basket, so the assertion below cannot distinguish the '
            . 'quantity guard from a broken endpoint: ' . json_encode($clean->json('message')));

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $cheap->id, 'variant_id' => 0,
            'qty' => -5, 'price' => -250.00, 'store_id' => $store->id,
        ]);

        $refused = $this->callApi($store, 'confirm-order', $payload, $token);

        $this->assertSame(0, (int) $refused->json('status'),
            'confirm-order priced a basket holding a negative line, so it previews a total the '
            . 'order writers will refuse.');
        $this->assertSame('Your cart contains an invalid quantity.',
            $refused->json('data.message') ?? $refused->json('message'),
            'confirm-order refused for some other reason than the quantity: '
            . json_encode($refused->json('message')));
    }

    /**
     * THE WEB CHECKOUT IS THE FOURTH MONEY PATH, and it was the one still open after F.
     *
     * F guarded the three API order handlers and E guarded payment_sheet. An adversarial review of
     * F went looking for the same scope mistake F had just fixed and found
     * PaymentController::processOrder -- the storefront web checkout -- unguarded, with its own
     * order-writing code.
     *
     * Its pre-flight stock check does NOT cover this: assertCartAvailable() at
     * PaymentController.php:315 does `if (... (int) $qty <= 0) { continue; }`, which SKIPS a
     * non-positive line. That is right for a stock question and wrong for a pricing one -- the line
     * is skipped for stock and then priced anyway into `$order->product_price` and
     * `$order->final_price`.
     *
     * THE FIRST VERSION OF THIS TEST WAS VACUOUS and the premise caught it. It asserted only "no
     * order was created", and no order was created either way: the web checkout 500s in a bare
     * fixture because PaymentService inserts a `cities` row whose `state_id` and `country_id` have
     * no parents. So the test passed against unguarded code. It now seeds the geo rows, asserts
     * that a CLEAN basket records an order through the same request, and identifies the refusal by
     * the guard's OWN message -- which no other refusal on this path uses.
     */
    public function test_the_web_checkout_refuses_a_basket_with_a_negative_line(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();

        // PaymentService writes a `cities` row against these ids; without the parents the whole
        // checkout dies on a foreign-key violation and every assertion below is vacuous.
        DB::table('countries')->insert([
            'id' => 101, 'name' => 'Testland', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insert([
            'id' => 1, 'name' => 'Teststate', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insert([
            'id' => 1, 'name' => 'Testcity', 'state_id' => 1, 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = $this->customerIn($store, 'gweb');
        $big = $this->productIn($store);
        $cheap = Product::forceCreate([
            'name' => 'gweb-cheap-' . uniqid(), 'slug' => 'gweb-cheap-' . uniqid(),
            'product_stock' => 20, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 50.00, 'sale_price' => 50.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        // The shared billing() fixture uses 'X'/'Y'/'Z' for country, state and city. The API order
        // handlers never look at them, but the WEB checkout inserts a `cities` row keyed on those
        // values (PaymentService.php:2672), so non-numeric ids are a foreign-key violation and a
        // 500 -- which is what made the first version of this test pass against unguarded code.
        // These are the ids seeded above.
        $billing = array_merge($this->billing(), [
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ]);
        $post = fn () => $this->actingAs($customer, 'customers')
            ->post('/' . $store->slug . '/process-order', [
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
                'billing_info' => $billing,
            ] + $billing);

        $basket = function (bool $withNegativeLine) use ($customer, $store, $big, $cheap) {
            Cart::withoutGlobalScopes()->where('customer_id', $customer->id)->delete();
            Cart::forceCreate([
                'customer_id' => $customer->id, 'product_id' => $big->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 1000.00, 'store_id' => $store->id,
            ]);
            if ($withNegativeLine) {
                Cart::forceCreate([
                    'customer_id' => $customer->id, 'product_id' => $cheap->id, 'variant_id' => 0,
                    'qty' => -5, 'price' => -250.00, 'store_id' => $store->id,
                ]);
            }
        };

        // THE DENIAL, identified by the guard's own message.
        $basket(true);
        $post();

        $this->assertSame('Your cart contains an invalid quantity.', session('error'),
            'The web checkout did not refuse a basket holding a negative line. Session error was: '
            . json_encode(session('error')));
        $this->assertNull(\App\Models\Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->first(),
            'An order was recorded for a basket holding a negative line.');
        $this->assertSame(20, (int) Product::withoutGlobalScopes()
            ->find($cheap->id)->product_stock,
            "The web checkout moved the merchant's stock for a refused order.");

        // THE PREMISE, which the first version of this test lacked: the SAME request on a clean
        // basket must go through, or the guard is indistinguishable from a broken checkout.
        session()->forget('error');
        $basket(false);
        $post();

        $this->assertNotNull(\App\Models\Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->first(),
            'With the negative line removed the same request records no order, so this test is '
            . 'measuring a checkout that refuses everything rather than the quantity guard. '
            . 'Session error: ' . json_encode(session('error')));
    }

    /**
     * AND THE WRITER THAT LET THE ROW EXIST. The storefront web route took the quantity on trust.
     *
     * `CartController::product_cartlist` did `$qty = $request->qty ?? 0` with no validation
     * anywhere in the method, and wrote it under the session customer's own id and this store --
     * the rows the order writers price. Refusing in the order handlers stops the money moving;
     * refusing here stops the row existing.
     *
     * The positive case is asserted first, so "the endpoint refuses everything" cannot satisfy it.
     */
    public function test_the_web_cart_writer_refuses_a_non_positive_quantity(): void
    {
        [, $store] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'f1web');
        $product = $this->productIn($store);

        $post = fn ($qty) => $this->actingAs($customer, 'customers')
            ->postJson('/' . $store->slug . '/product_cart', [
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => $qty,
            ]);

        // THE PREMISE: a real quantity still works through this route.
        $ok = $post(2);
        $this->assertSame(1, (int) $ok->json('status'),
            'A quantity of 2 was refused, so this route is broken rather than guarded: '
            . json_encode($ok->json('message')));
        $this->assertSame(1, Cart::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('qty', 2)->count(),
            'The legitimate quantity was not written.');

        foreach ([-5, 0, '-1', 1.5] as $bad) {
            $response = $post($bad);

            $this->assertSame(0, (int) $response->json('status'),
                'The web cart writer accepted a quantity of ' . json_encode($bad) . '.');
        }

        $this->assertSame(0, Cart::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->where('qty', '<=', 0)->count(),
            'A non-positive quantity reached the carts table.');
    }

    /**
     * AN ACCEPTED COUPON MADE THE ORDER FREE. Measured, and that is not an exaggeration.
     *
     * Driven through api/{slug}/order-save with one 100.00 product:
     *
     *   no coupon                 product_price 100.0   coupon_price 0.0   final_price 100.0
     *   an ACCEPTED coupon        product_price   0.0   coupon_price 7.0   final_price   0.0
     *
     * `apply_coupon()` builds its `final_price` from a nested cart read that is not reached for a
     * coupon whose `sale_items` is 0, so it returns the string "0.00" -- and `!empty("0.00")` is
     * TRUE in PHP, so `$cartlist_final_price = $apply_coupon['final_price']` fired on a zero. A
     * shopper holding any working coupon code got an order recording nothing owed, which for a COD
     * order is free goods. Found by an adversarial review of this package; pre-existing, and
     * untestable before C4 made apply_coupon reachable on SQLite at all.
     *
     * The guard refuses a figure that cannot be a discounted basket -- not positive, or larger
     * than the basket -- and keeps the server's own total. It is NOT the coupon fix: correcting
     * apply_coupon's arithmetic is the order-calculation change this package is not to start, and
     * S24 records that the same path refuses every coupon type but one. So the order is now
     * internally inconsistent in a visible way -- 100.00 owed with a 7.00 discount recorded
     * beside it -- which is the honest state to leave it in and is far better than 0.00 owed.
     */
    public function test_an_accepted_coupon_cannot_zero_the_order_total(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'c3f');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        $this->makeAcceptedCoupon($store, 'ZEROPROBE', $product->id);

        $this->callApi($store, 'order-save', [
            'customer_id' => $customer->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
            'coupon_info' => [
                'coupon_code' => 'ZEROPROBE',
                'coupon_discount_type' => 'flat',
                'coupon_discount_number' => '7',
                'coupon_discount_amount' => '7',
                'coupon_final_amount' => '93',
            ],
        ], $customer->createToken('t')->plainTextToken);

        $order = \App\Models\Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->first();

        $this->assertNotNull($order, 'order-save did not complete with an accepted coupon.');
        $this->assertSame(100.0, (float) $order->product_price,
            'The order recorded product_price ' . $order->product_price . '. 0 means an accepted '
            . "coupon still zeroes the basket total this order was built from.");
        $this->assertGreaterThan(0.0, (float) $order->final_price,
            'The order recorded final_price ' . $order->final_price . ', so a shopper holding a '
            . 'working coupon code owes nothing.');
    }

    /**
     * THE CONFIRMATION PREVIEW MUST AGREE WITH WHAT ORDER-SAVE WILL RECORD.
     *
     * `confirm_order` persists nothing, which is why E6's guard skipped it -- but it RETURNS
     * `$cartlist_final_price` as the preview's `final_price`, so an accepted coupon made the
     * confirmation screen read 0.00 while order-save then recorded the full basket. The shopper was
     * shown a total they could never place. An adversarial review of commit E pointed out that a
     * preview the client renders is still an answer that has to be right.
     *
     * This test is the reason the guard is in three handlers rather than two: the same fixture is
     * driven through confirm-order and order-save and the two figures must match.
     */
    public function test_the_confirmation_preview_agrees_with_what_the_order_records(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'f4');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        $this->makeAcceptedCoupon($store, 'PREVIEWFIXED', $product->id);
        $token = $customer->createToken('t')->plainTextToken;

        $payload = [
            'customer_id' => $customer->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
            'coupon_info' => [
                'coupon_code' => 'PREVIEWFIXED',
                'coupon_discount_type' => 'flat',
                'coupon_discount_number' => '7',
                'coupon_discount_amount' => '7',
                'coupon_final_amount' => '93',
            ],
        ];

        $preview = $this->callApi($store, 'confirm-order', $payload, $token);
        $previewed = (float) $preview->json('data.final_price');

        $this->assertGreaterThan(0.0, $previewed,
            'The confirmation preview showed ' . $previewed . ' for an accepted coupon, so the '
            . 'shopper is shown a total that order-save will not honour.');

        $this->callApi($store, 'order-save', $payload, $token);
        $order = \App\Models\Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->first();

        $this->assertNotNull($order, 'order-save did not complete, so there is nothing to compare.');
        $this->assertSame((float) $order->product_price, $previewed,
            'The preview said ' . $previewed . ' and the order recorded '
            . $order->product_price . '.');
    }

    /**
     * AND THE STORE BOUNDARY ON THE SAME COLUMN. Another tenant's coupon code must not discount
     * an order here.
     *
     * Measured before the fix, through api/{slug}/cart-list: store B's 90% coupon applied to a
     * store A cart returned `total_coupon_price = 90` and `total_sub_price = 10.00`. cart_list's
     * two coupon lookups carried no store predicate, and `Coupon` has no StoreScope and is not in
     * config/tenancy.php's scoped_models, so nothing underneath added one.
     */
    public function test_another_stores_coupon_does_not_discount_an_order_here(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        [, $storeB] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'c3e');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        $this->makeCoupon($storeB, 'FOREIGNHALF', 1000);

        $this->callApi($store, 'order-save', [
            'customer_id' => $customer->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
            'coupon_info' => [
                'coupon_code' => 'FOREIGNHALF',
                'coupon_discount_type' => 'percentage',
                'coupon_discount_number' => '10',
                'coupon_discount_amount' => '10',
                'coupon_final_amount' => '90',
            ],
        ], $customer->createToken('t')->plainTextToken);

        $order = \App\Models\Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->first();

        $this->assertNotNull($order,
            'order-save did not complete, so this test is measuring a crash rather than a control.');
        $this->assertSame(0.0, (float) $order->coupon_price,
            'The order recorded a ' . $order->coupon_price . ' discount from a coupon belonging to '
            . 'another store.');
    }

    /**
     * THE TWO MISSING SETTINGS KEYS, isolated -- and this test is a CORRECTION of one that was
     * vacuous.
     *
     * The first version called `orderableTenant(seedStockManagement: false)`, reasoning that a
     * store with no settings rows is exactly the condition the missing keys represent. It is not:
     * `out_of_stock_threshold` and `notification` are read INSIDE the block gated on
     * `$settings['stock_management'] == 'on'`, so omitting `stock_management` skips the code the
     * fix guards. Proved rather than reasoned: with both `?? ` defaults reverted, five tests in
     * this file fail and that one still passed. An adversarial review of this package flagged it
     * and the revert confirmed it.
     *
     * So this version turns stock management ON -- entering the block -- while leaving the two
     * keys unwritten, and asserts their absence as a premise first, because a fixture that
     * accidentally seeded them would make the test pass for the wrong reason.
     */
    public function test_an_order_completes_with_stock_management_on_and_the_two_keys_unset(): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();

        $this->assertSame('on', DB::table('settings')->where('store_id', $store->id)
            ->where('name', 'stock_management')->value('value'),
            'stock_management is not on, so the block that reads the two keys is never entered '
            . 'and this test isolates nothing.');

        foreach (['out_of_stock_threshold', 'notification'] as $name) {
            $this->assertNull(DB::table('settings')->where('store_id', $store->id)
                ->where('name', $name)->value('value'),
                'The fixture seeded ' . $name . ', so the missing-key fix is not being exercised.');
        }

        $customer = $this->customerIn($store, 'c3b');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $this->callApi($store, 'order-save', [
            'customer_id' => $customer->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ], $customer->createToken('t')->plainTextToken);

        $this->assertSame(1, \App\Models\Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)->count(),
            'An order could not be saved with stock management on and neither '
            . '`out_of_stock_threshold` nor `notification` configured -- the raw settings pluck '
            . 'supplies no defaults, so both keys are simply absent.');
    }

    // ===================================================================================
    // C4 — apply_coupon's MySQL-only BINARY
    // ===================================================================================

    /**
     * `whereRaw('BINARY `coupon_code` = ?')` is MySQL-only syntax, so SQLite rejected the whole
     * statement and apply_coupon returned 500 before reaching any of its own logic. A money path
     * was therefore untestable, which is how it stayed unexamined.
     *
     * This asserts the endpoint is REACHABLE now -- it answers with its own logic rather than a
     * framework error -- and, separately, that removing BINARY did not make coupon codes
     * case-insensitive, which would be a business behaviour change.
     */
    public function test_apply_coupon_is_reachable_and_finds_an_exact_code(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'c4');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        $this->makeCoupon($store, 'SAVE10');

        $response = $this->callApi($store, 'apply-coupon',
            ['coupon_code' => 'SAVE10', 'customer_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->getStatusCode(),
            'apply-coupon returned HTTP ' . $response->getStatusCode() . '. Before this fix it was '
            . 'a 500 from a MySQL-only SQL fragment, so a non-200 here means the path is broken '
            . 'again.');
        $this->assertNotSame('Invalid coupon code.', $response->json('data.message'),
            'The exact code was not found, so the portable lookup does not match what BINARY did.');
    }

    public function test_a_coupon_code_is_still_case_sensitive(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'c4b');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        $this->makeCoupon($store, 'SAVE10');

        $response = $this->callApi($store, 'apply-coupon',
            ['coupon_code' => 'save10', 'customer_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $this->assertSame('Invalid coupon code.', $response->json('data.message'),
            'A lower-case variant matched an upper-case coupon. Dropping BINARY made codes '
            . 'case-insensitive, which widens what a guessed code matches.');
    }

    /** The lookup helper itself, including the exact-match filter that replaces BINARY. */
    public function test_the_portable_lookup_rejects_case_variants_directly(): void
    {
        [, $store] = $this->makeTenant();
        $this->makeCoupon($store, 'SAVE10');

        $this->assertNotNull(\App\Models\Coupon::findByExactCode('SAVE10'),
            'The exact code did not resolve.');
        $this->assertNull(\App\Models\Coupon::findByExactCode('save10'),
            'A case variant resolved, so the PHP exact-match filter is not doing its job. On MySQL '
            . 'the SQL step is case-insensitive, so that filter is the only thing preserving '
            . 'case-sensitivity there.');
        $this->assertNull(\App\Models\Coupon::findByExactCode(''),
            'An empty code resolved to a coupon.');
        $this->assertNull(\App\Models\Coupon::findByExactCode(null));
    }

    // ===================================================================================
    // fixtures
    // ===================================================================================

    /**
     * AND apply_coupon's SECOND lookup, which exists only to fill the echoed `coupon_info`.
     *
     * apply_coupon decides acceptance with a store-scoped lookup, then re-resolves the coupon from
     * `$request->coupon_code` with no predicate to populate `coupon_info` and
     * `$coupon_array['id']`. Reached only when the first lookup succeeded, so it needs two stores
     * holding the SAME code to show: the response then carried whichever row the unscoped query
     * returned first, which is another merchant's coupon id, name, type and headline discount.
     *
     * Defence in depth rather than a hole on its own -- the arithmetic came from the scoped row --
     * but `$coupon_array['id']` is an id a caller or a downstream write could use, so it is pinned.
     */
    public function test_apply_coupon_echoes_this_stores_coupon_not_a_namesake(): void
    {
        [, $storeB] = $this->orderableTenant();
        [, $store] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'd2c');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        // Store B's row is created FIRST, so it is the one an unscoped `->get()` returns first.
        $foreignId = $this->makeCoupon($storeB, 'SHAREDCODE', 1000, ['coupon_name' => 'theirs']);
        $ownId = $this->makeAcceptedCoupon($store, 'SHAREDCODE', $product->id);
        $this->assertLessThan($ownId, $foreignId, 'fixture ordering premise');

        $response = $this->callApi($store, 'apply-coupon', [
            'customer_id' => $customer->id,
            'coupon_code' => 'SHAREDCODE',
            'sub_total' => 100,
        ], $customer->createToken('t')->plainTextToken);

        $this->assertSame(1, $response->json('status'),
            'apply-coupon refused this store\'s own coupon, so the echo below is not being '
            . 'measured. Message: ' . json_encode($response->json('data.message')));
        $this->assertSame($ownId, (int) $response->json('data.coupon_info.coupon_id'),
            'The response echoed coupon id ' . $response->json('data.coupon_info.coupon_id')
            . ' -- another store\'s row with the same code -- rather than this store\'s ' . $ownId);
        $this->assertSame('Ten off', $response->json('data.coupon_info.coupon_name'),
            'The response carried another store\'s coupon name.');
    }

    /**
     * AND THE GUEST CART, which is a different code path and needed its own predicate.
     *
     * `Cart::cart_list_cookie()` is the cookie-session cart the web storefront uses for a shopper
     * who is not logged in (PaymentController::getCartData(), `$isGuest` branch). Its coupon
     * lookup is a separate statement from cart_list()'s and carried no store predicate either.
     *
     * Driven against the model rather than over HTTP because the path is reached through a cookie
     * the test client does not own; the method takes the cart data and the store id directly, so
     * the lookup under test is exercised exactly as production calls it.
     */
    public function test_another_stores_coupon_code_produces_no_discount_in_a_guest_cart(): void
    {
        [, $store] = $this->orderableTenant();
        [, $storeB] = $this->orderableTenant();
        $product = $this->productIn($store);

        $session = 'guest-' . uniqid();
        Cart::forceCreate([
            'cookie_session_id' => $session, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $this->makeCoupon($store, 'GUESTOWN', 1000, ['discount_amount' => 90]);
        $this->makeCoupon($storeB, 'GUESTFOREIGN', 1000, ['discount_amount' => 90]);

        // `Cookie::get()` is a STATIC OVERRIDE on the facade --
        // Illuminate\Support\Facades\Cookie::get() returns `app['request']->cookie($key)` -- so
        // Cookie::shouldReceive() does not intercept it and the cart comes back empty. The cookie
        // has to be set on the bound request instead. That cost a false reading: the first version
        // of this test measured an empty cart and would have passed against unscoped code.
        $this->app['request']->cookies->set('cart', $session);

        $read = fn (string $code) => json_decode(json_encode(
            Cart::cart_list_cookie(['coupon_code' => $code], $store->id)
        ), true);

        $own = $read('GUESTOWN');
        $this->assertSame(1, count($own['data']['product_list'] ?? []),
            'The guest cart read no products, so every total below is 0 whether or not the '
            . 'predicate works. Check the `cart` cookie is reaching Cookie::get().');
        $this->assertSame(90.0, (float) ($own['data']['total_coupon_price'] ?? -1),
            'The guest cart applied no discount for this store\'s own coupon, so the assertion '
            . 'below cannot distinguish a refused foreign coupon from a key that never moves.');

        $foreign = $read('GUESTFOREIGN');
        $this->assertSame(0.0, (float) ($foreign['data']['total_coupon_price'] ?? -1),
            'The guest cart applied a discount from another store\'s coupon code.');
    }

    /**
     * THE CART READ ITSELF: another store's coupon code must not produce a discount here.
     *
     * This is the surface D2's predicate actually governs, and it is separate from the order
     * record. `cart_list` looks the posted code up twice -- once per item to compute the discount,
     * once afterwards for the limit and expiry checks and the echoed `coupon_info` -- and neither
     * lookup carried a store predicate. `Coupon` has no StoreScope and is not in
     * config/tenancy.php's scoped_models, so nothing underneath added one.
     *
     * MEASURED BEFORE THE PREDICATE, through this same endpoint: store B's 90% coupon on a store A
     * cart returned `total_coupon_price = 90` and `total_sub_price = 10.00`, against
     * `total_coupon_price = 0` for a code that does not exist. The predicate was absent before
     * P01-SEC-14 and the C4 conversion preserved it byte for byte -- `git show
     * b5794336:app/Http/Controllers/Api/ApiController.php` line 717 -- so this is a pre-existing
     * cross-tenant read, not a regression introduced by the conversion.
     *
     * WHAT IT DID AND DID NOT REACH, so the severity is not overstated: `total_final_price` is
     * unaffected, so neither payment_sheet's charge nor ordersave's order total moved, and the web
     * checkout prices its coupon through apply_coupon(), whose lookup was already store-scoped.
     * What moved was the DISPLAYED discount and the echoed `coupon_info` -- a cross-tenant read of
     * another merchant's coupon name, type and headline discount.
     */
    public function test_another_stores_coupon_code_produces_no_discount_in_this_cart(): void
    {
        [, $store] = $this->orderableTenant();
        [, $storeB] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'd2');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        $this->makeCoupon($storeB, 'BNINETY', 1000, ['discount_amount' => 90]);

        $token = $customer->createToken('t')->plainTextToken;

        // The premise: this store's OWN coupon does produce a discount through this key, so a zero
        // below means the predicate refused the foreign one rather than that the key never moves.
        $this->makeCoupon($store, 'ANINETY', 1000, ['discount_amount' => 90]);
        $own = $this->callApi($store, 'cart-list', [
            'customer_id' => $customer->id, 'coupon_code' => 'ANINETY',
        ], $token);
        $this->assertSame(90.0, (float) $own->json('data.total_coupon_price'),
            'This store\'s own 90% coupon produced no discount, so the assertion below cannot '
            . 'distinguish a refused foreign coupon from a key that never moves.');

        $foreign = $this->callApi($store, 'cart-list', [
            'customer_id' => $customer->id, 'coupon_code' => 'BNINETY',
        ], $token);

        $this->assertSame(0.0, (float) $foreign->json('data.total_coupon_price'),
            'The cart applied a ' . $foreign->json('data.total_coupon_price') . ' discount from '
            . "another store's coupon code.");
        $this->assertSame('-', $foreign->json('data.coupon_info.coupon_code'),
            "The response echoed another store's coupon back to this caller.");
    }

    /**
     * @param  int  $limit  Cart::getCouponTotalAmount() refuses a coupon unless
     *                      `coupon_limit > UsesCouponCount()`, so 0 means "found but never
     *                      applied". The apply_coupon tests want exactly that -- they assert the
     *                      LOOKUP -- and the ordersave tests below need a usable one.
     */
    private function makeCoupon($store, string $code, int $limit = 0, array $overrides = []): int
    {
        return (int) DB::table('coupons')->insertGetId($overrides + [
            'coupon_name' => 'Ten off', 'coupon_code' => $code,
            'coupon_type' => 'percentage', 'discount_amount' => 10, 'coupon_limit' => $limit,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A coupon apply_coupon() will actually ACCEPT.
     *
     * It only accepts `fixed product discount`. See the docblock on
     * test_the_recorded_discount_is_the_servers_verdict for why every other type is refused, and
     * S24 in docs/security/P01-SECURITY-WORK-QUEUE.md for the owner item. The preservation test
     * needs an accepted coupon or it cannot tell "refuses the caller's number" from "refuses
     * everything", so it uses this one.
     */
    private function makeAcceptedCoupon($store, string $code, int $productId): int
    {
        return $this->makeCoupon($store, $code, 1000, [
            'coupon_type' => 'fixed product discount',
            'discount_amount' => 7,
            'applied_product' => (string) $productId,
            'exclude_product' => '',
            'applied_categories' => '',
            'exclude_categories' => '',
        ]);
    }

    /**
     * A tenant that can take an order. The premises are the ones P01-SEC-11 established by running
     * the handlers: an admin user with a resolvable Plan assigned THROUGH ELOQUENT (User is
     * Cachable, so a raw write leaves a stale read), the admin_details cache forgotten, and a
     * shippings row.
     */
    /**
     * @param  bool  $seedStockManagement  no test passes false any more -- the one that did was
     *                                     vacuous for exactly that reason (see
     *                                     test_an_order_completes_with_stock_management_on_and_the_two_keys_unset).
     *                                     Kept because a future test of the stock-management-OFF
     *                                     path needs it, and removing it would invite the same
     *                                     mistake to be re-made inline.
     */
    /**
     * F46 — A SIGNED-IN SHOPPER CANNOT ATTRIBUTE THEIR ORDER TO SOMEBODY ELSE.
     *
     * `PaymentService` built both the order's reference and its `customer_id` from
     * `$request->customer_id`, for a caller `auth('customers')->check()` had ALREADY
     * authenticated. So a shopper could post another customer's id and the order landed on that
     * customer's account; and because `orders.product_order_id` is a STRING column while
     * `config/database.php` sets `'strict' => false`, a non-numeric value was carried whole into
     * the reference the merchant's order list renders while the numeric column coerced to 0.
     *
     * THE SAME BLOCK EXISTED IN TEN PLACES across nine files and the finding had been recorded
     * against one. All are fixed and `OrderAttributionRatchetTest` keeps them that way.
     *
     * THIS TEST DRIVES THE ONE ROUTE A TEST CAN REACH, and it is NOT the one F46 lived in -- see
     * the note in the body. It is a regression guard on the already-correct `place-order` handler.
     * The nine copies that F46 actually fixed sit behind the storefront web checkout and gateway
     * callbacks, and their evidence is the static ratchet plus the identical shape of the fix.
     *
     * Three payloads, one authenticated shopper: another customer's real id, a non-numeric
     * string, and the shopper's own id. All three must produce an order owned by the shopper.
     */
    public function test_a_signed_in_shopper_cannot_attribute_an_order_to_another_customer(): void
    {
        $recorded = [];

        /* WHAT THIS DRIVES, AND WHAT IT CANNOT -- stated here because the first version of this
           test was VACUOUS and looked fine.
           `place-order` is `ApiController::place_order`, which creates the order ITSELF. It was
           already safe: the P1-SEC-23/24 fix resolves `$customer_id` from the session far above.
           So these cases are a REGRESSION GUARD on that earlier fix, not proof of F46's.
           F46 itself lived in `PaymentService::getProductStatus()` and in eight gateway
           controllers, and those are reached by the storefront WEB checkout and by gateway
           callbacks -- not by any API route this fixture can call. Reverting the PaymentService
           fix therefore leaves these cases green, which is exactly what the bite test showed.
           `payment-sheet` was tried and is not the route either: it creates a Stripe
           PaymentIntent and writes no order.
           THE NINE UNDRIVEN COPIES ARE COVERED BY `OrderAttributionRatchetTest`, which names the
           offending file and was bitten by reverting CinetPay. That is a static guarantee, and it
           is the honest extent of the evidence.
           AND THESE CASES CANNOT BITE THE SPELLING EITHER, which was measured rather than
           assumed: reverting `place_order`'s own reference line to `$request->customer_id` STILL
           produced the shopper's id, because `ModuleStorefrontInputGuard` (appended to the `web`
           group, bootstrap/app.php:274) replaces `customer_id` with the authenticated customer's
           id before the handler runs. So on this route the attribution is correct twice over, and
           what these cases guard is the END STATE -- the order lands on the authenticated shopper
           and no posted string reaches the reference -- not which of the two spellings the
           handler uses.
           WHAT IS THEREFORE NOT ESTABLISHED, and should not be claimed: whether the nine
           web/gateway copies were reachable with a hostile `customer_id` at all. That guard is
           per-route, and whether it covers the gateway callback routes was not determined. The
           fix is defence in depth of undemonstrated reachability -- correct either way, and not
           a proven exploit closure. */
        foreach ([['place-order', 'other'], ['place-order', 'injection'], ['place-order', 'own']] as $spec) {
            [$route, $case] = $spec;
            $key = $route . '/' . $case;
            [, $store, $shippingId] = $this->orderableTenant();
            $shopper = $this->customerIn($store, 'f46a' . md5($key));
            $victim = $this->customerIn($store, 'f46b' . md5($key));
            $product = $this->productIn($store);
            Cart::forceCreate([
                'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            ]);

            $posted = $case === 'other' ? $victim->id
                : ($case === 'injection' ? '9<script>alert(1)</script>' : $shopper->id);

            $this->callApi($store, $route, [
                'customer_id' => $posted,
                'billing_info' => $this->billing(),
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
            ], $shopper->createToken('t')->plainTextToken);

            /* withoutGlobalScopes, because Order is NOT in scoped_models and a plain find would
               answer against whichever tenant happens to be resolved. */
            $order = \App\Models\Order::withoutGlobalScopes()
                ->where('store_id', $store->id)->latest('id')->first();
            if ($order === null) {
                /* Not every route completes for every payload on this fixture, and a route that
                   refused the request outright has told us nothing about attribution. Recorded
                   as skipped rather than asserted, so the summary below cannot pass by finding
                   no order at all. */
                $recorded[$key] = ['completed' => false];
                continue;
            }

            $recorded[$key] = [
                'completed' => true,
                'customer_id' => (int) $order->customer_id,
                'is_shopper' => (int) $order->customer_id === (int) $shopper->id,
                'is_victim' => (int) $order->customer_id === (int) $victim->id,
                'reference_carries_posted' => str_contains((string) $order->product_order_id, '<script>'),
                'reference_starts_with_shopper' => str_starts_with(
                    (string) $order->product_order_id, (string) $shopper->id),
            ];
        }

        /* A PREMISE. If no route completed, every assertion below is vacuous -- and this test
           previously passed while proving nothing, so the premise is explicit. */
        $completed = array_keys(array_filter($recorded, fn ($r) => $r['completed']));
        $this->assertNotEmpty($completed, 'no route completed, so nothing was measured');
        $this->assertCount(3, $completed,
            'all three place-order cases must complete, or this is measuring a crash rather than '
            . 'a control. Completed: ' . implode(', ', $completed));

        foreach ($completed as $key) {
            $r = $recorded[$key];
            $this->assertTrue($r['is_shopper'],
                $key . ': the order was attributed to ' . $r['customer_id'] . ' rather than to the '
                . 'authenticated shopper. Before the fix the posted id won.');
            $this->assertFalse($r['is_victim'], $key . ': the order landed on the OTHER account');
            $this->assertFalse($r['reference_carries_posted'],
                $key . ': the posted string reached orders.product_order_id, which the order list '
                . 'renders');
            $this->assertTrue($r['reference_starts_with_shopper'],
                $key . ': the reference does not begin with the authenticated shopper\'s id');
        }
    }

    private function orderableTenant(bool $seedStockManagement = true): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'res-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        if ($seedStockManagement) {
            DB::table('settings')->insert([
                'name' => 'stock_management', 'value' => 'on', 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant->fresh(), $store, $shippingId];
    }

    /** `billing_postecode` is misspelled in the handler, and every delivery_* key is required. */
    private function billing(): array
    {
        return [
            'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'billing_address' => '1 St', 'billing_user_telephone' => '555',
            'billing_postecode' => '11111', 'billing_country' => 'X',
            'billing_state' => 'Y', 'billing_city' => 'Z',
            'delivery_address' => '1 St', 'delivery_postcode' => '11111',
            'delivery_country' => 'X', 'delivery_state' => 'Y', 'delivery_city' => 'Z',
        ];
    }
}
