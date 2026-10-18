<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * @param  int  $limit  Cart::getCouponTotalAmount() refuses a coupon unless
     *                      `coupon_limit > UsesCouponCount()`, so 0 means "found but never
     *                      applied". The apply_coupon tests want exactly that: they assert the
     *                      LOOKUP, not the application.
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
