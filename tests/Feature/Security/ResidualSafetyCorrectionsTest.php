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
     * A store that has written NO settings rows at all still completes, which is the condition the
     * two missing keys represent: nothing seeds `out_of_stock_threshold` or `notification`.
     */
    public function test_a_store_with_no_settings_rows_can_still_save_an_order(): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant(seedStockManagement: false);
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
            'A store that has configured no settings cannot save an order.');
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
