<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11, AUTHZ-3 and AUTHZ-4 — the two storefront order mutations.
 *
 *   POST api/{slug}/order-status-change  -> ApiController::order_status_change
 *   POST api/{slug}/product-return       -> ApiController::product_return
 *
 * Both carried ['custom.auth','APILog'], resolved `$store` and never read it, and passed
 * `$request->order_id` straight into a model method that does a bare `Order::find()`. `Order`
 * carries neither the tenancy trait nor an entry in config/tenancy.php's `scoped_models`, so no
 * global scope narrowed them either. Any holder of any valid token could therefore act on any
 * order on the platform by iterating integers.
 *
 * THE FINANCIAL EFFECT IS THE POINT, not the status field. The cancel branch stamps
 * `delivered_status = 2` and reverses the merchant's entitlement, so the hole was an
 * attacker-triggered refund against any order in any store. BOTH doors also write INVENTORY:
 * product_return increments `products.product_stock`, and the cancel door does too, from the
 * controller (`restoreCancelledOrderStock`, P4-F034).
 *
 * HOW the money moves was rewritten after this file was written, and the old wording -- "sets
 * `payment_status = 'Unpaid'` and then saves, and the observer picks that change up and calls the
 * wallet service" -- is no longer true of this revision. P4-F124 (owner decision Option A)
 * removed that write so a cancellation stops rewriting what the customer actually paid;
 * `Order::order_status_change()` now calls `OrderEntitlementReversal::reverseOrLog()` explicitly.
 * MEASURED 2026-11-24 through this very door: `payment_status` reads `Paid` before AND after a
 * successful cancel. That matters to the helper below, which still asserts on the column.
 *
 * WHY THESE TESTS ARE NOT VACUOUS. Two traps were checked for:
 *
 *   - `makeOrder()` skips the test when `orders.currency_code` is undeclared. That column IS
 *     declared (database/migrations/2026_10_12_000102_...), so these tests RUN; the first test
 *     below asserts an order was really created, so a silent regression to skipping is visible.
 *   - A test asserting "nothing changed" would pass against a handler that 500s for an unrelated
 *     reason. `Order::product_return()` really does dereference its find() with no null guard,
 *     so the refusal tests here assert the ENVELOPE (status 0 with the not-found message), which
 *     only a clean denial produces — a 500 fails them.
 */
class StorefrontOrderMutationAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'ord-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function driverFor(User $merchant, $store, string $label = 'd'): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver ' . $label,
            'email' => 'drv-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
    }

    /** Products have no factory in this project; the convention is forceCreate. */
    private function productIn($store, User $owner, int $stock): Product
    {
        return Product::forceCreate([
            'name' => 'ordmut-' . uniqid(),
            'slug' => 'ordmut-' . uniqid(),
            'product_stock' => $stock,
            'track_stock' => 0,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $owner->id,
        ]);
    }

    private function as(?string $bearer)
    {
        return $this->withHeaders($bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer]);
    }

    private function changeStatus($store, $orderId, string $status, ?string $bearer)
    {
        return $this->as($bearer)->postJson('/api/' . $store->slug . '/order-status-change', [
            'order_id' => $orderId,
            'order_status' => $status,
        ]);
    }

    private function returnProduct($store, $orderId, $productId, ?string $bearer)
    {
        return $this->as($bearer)->postJson('/api/' . $store->slug . '/product-return', [
            'order_id' => $orderId,
            'product_id' => $productId,
            'variant_id' => 0,
        ]);
    }

    /**
     * Order lines carry `final_price` as well as `qty`: `Order::product_return()` reads
     * `$product['final_price']` when recomputing the return total, so a fixture without it makes
     * the AUTHORISED journey 500 and the test measures a crash instead of a success. Measured —
     * that is exactly what the first version of these fixtures did. (The `:685` this used to cite
     * is inside `order_detail()` now; the claim is true and the line number was not, so it is
     * cited by method.)
     */
    /** A paid, undelivered order belonging to $customer — cancellable, so the money path is live. */
    private function paidOrderFor($store, Customer $customer): Order
    {
        return $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'payment_status' => 'Paid',
            'delivered_status' => 0,
        ]);
    }

    private function assertRefused($response, string $because): void
    {
        /*
         * HTTP 200 with status 0 is this API's denial shape. A 401 would be a forced logout in
         * the shipped Flutter client, and a 500 would mean the handler crashed rather than
         * refused -- which `assertStatus(200)` already excludes. An earlier version of this
         * helper then asserted `assertNotSame(500, $response->status())`, which CANNOT fail once
         * 200 has been asserted: a dead assertion carrying the only message a reader would see.
         *
         * AND IT NEVER ASSERTED WHICH RULE REFUSED (review finding). Every status-0 outcome on
         * these two doors is HTTP 200, so a mutation that changes a refusal from "not yours" to
         * some unrelated refusal was invisible here. Both doors answer a failed ownership
         * resolution with their own not-found envelope, verbatim and deliberately -- "not yours"
         * must be indistinguishable from "no such order" so the endpoint cannot enumerate order
         * ids -- so the message is the same string for every case in this file, the unknown-id
         * ones included, and asserting it costs nothing and closes the hole.
         */
        $response->assertStatus(200);
        $response->assertJsonPath('status', 0);
        $response->assertJsonPath('message', 'Order not found.');
    }

    /**
     * The order row as the DATABASE holds it, not as the model cache holds it.
     *
     * `Order` is one of the 79 `Cachable` models, so `$order->fresh()` is answered by the model
     * cache, which under `CACHE_STORE=array` lives for the rest of the test. That is harmless in
     * the refusal direction (nothing was written, so the cached row IS the true row) but it is
     * the wrong instrument for an "it did not change" assertion, which must be able to see a
     * write that bypassed Eloquent. A raw read always sees the table.
     */
    private function orderRow(Order $order)
    {
        return \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->first();
    }

    /** Product stock as the table holds it. `ProductStockService` writes it with `increment()`. */
    private function stockOf(Product $product): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('products')
            ->where('id', $product->id)
            ->value('product_stock');
    }

    private function assertOrderUntouched(Order $order, string $because): void
    {
        $fresh = $this->orderRow($order);

        /*
         * THE FIRST OF THESE THREE IS VACUOUS ON THIS REVISION, and is labelled rather than
         * deleted. When it was written the cancel arm wrote `payment_status = 'Unpaid'`, so
         * "still Paid" meant "not cancelled". P4-F124 removed that write, and a SUCCESSFUL
         * cancel now leaves the column at `Paid` -- measured 2026-11-24 through this door. So
         * this line can no longer distinguish a refusal from a successful cancellation and
         * proves nothing on its own.
         *
         * It stays because it is a ratchet in the other direction: if anything ever reinstates a
         * `payment_status` write on the cancel path, every refusal test in this file starts
         * asserting that the customer's payment record was not rewritten -- which is the
         * property P4-F124 bought. The two assertions that DO carry this helper today are
         * `delivered_status` (0 -> 2 on a successful cancel) and `cancel_date` (null -> stamped),
         * and both were measured moving on exactly the request these tests refuse.
         */
        $this->assertSame('Paid', (string) $fresh->payment_status,
            $because . ' — payment_status was rewritten. VACUOUS as of P4-F124: a successful '
            . 'cancel also leaves this at Paid, so this line is a ratchet against that write '
            . 'coming back, not evidence of a refusal.');
        $this->assertSame(0, (int) $fresh->delivered_status,
            $because . ' — delivered_status was changed. A successful cancel writes 2.');
        $this->assertNull($fresh->cancel_date,
            $because . ' — the order was cancelled. A successful cancel stamps this.');
    }

    // ===================================================================================
    // THE PREMISE — these tests must actually exercise an order
    // ===================================================================================

    public function test_an_order_can_really_be_created_so_these_tests_are_not_skipping(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'premise');
        $order = $this->paidOrderFor($store, $customer);

        $this->assertNotNull($order->id);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'customer_id' => $customer->id]);
    }

    // ===================================================================================
    // AUTHZ-3 — order-status-change
    // ===================================================================================

    public function test_a_customer_cannot_cancel_another_customers_order(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a1-victim');
        $attacker = $this->customerIn($store, 'a1-attacker');
        $order = $this->paidOrderFor($store, $victim);

        $this->assertRefused(
            $this->changeStatus($store, $order->id, 'cancel', $attacker->createToken('t')->plainTextToken),
            'One customer cancelled another customer\'s order'
        );

        $this->assertOrderUntouched($order, 'One customer cancelled another customer\'s order');
    }

    public function test_a_customer_cannot_cancel_an_order_in_another_store(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'a2-victim');
        $attacker = $this->customerIn($storeB, 'a2-attacker');
        $order = $this->paidOrderFor($storeA, $victim);

        $this->assertRefused(
            $this->changeStatus($storeB, $order->id, 'cancel', $attacker->createToken('t')->plainTextToken),
            'A customer of store B cancelled an order in store A'
        );

        $this->assertOrderUntouched($order, 'A customer of store B cancelled an order in store A');
    }

    public function test_a_merchant_cannot_cancel_an_order_in_a_store_they_do_not_own(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'a3');
        $order = $this->paidOrderFor($storeA, $victim);

        $this->assertRefused(
            $this->changeStatus($storeB, $order->id, 'cancel', $merchantB->createToken('t')->plainTextToken),
            'A merchant cancelled an order belonging to another tenant'
        );

        $this->assertOrderUntouched($order, 'A merchant cancelled an order belonging to another tenant');
    }

    public function test_a_driver_cannot_cancel_an_order_not_assigned_to_them(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a4');
        $order = $this->paidOrderFor($store, $victim);
        $driver = $this->driverFor($merchant, $store, 'a4');

        // The order carries no deliveryboy_id, so it is not this driver's.
        $this->assertRefused(
            $this->changeStatus($store, $order->id, 'cancel', $driver->createToken('t')->plainTextToken),
            'A driver cancelled an order not assigned to them'
        );

        $this->assertOrderUntouched($order, 'A driver cancelled an order not assigned to them');
    }

    public function test_a_guest_order_cannot_be_cancelled_by_any_customer(): void
    {
        [, $store] = $this->makeTenant();
        $attacker = $this->customerIn($store, 'a5');

        // THE ORDINARY GUEST SHAPE: `customer_id = 0`, which matches no authenticated id. That is
        // ONE of the two shapes a guest order takes, and this test only ever covered this one —
        // which is exactly why the other slipped through. A guest checkout whose billing e-mail
        // matches an existing customer of the same store is written with that customer's REAL
        // `customer_id` and `is_guest = 1` (App\Services\PaymentService), so `customer_id` alone
        // cannot answer "did this customer place it". The adopted shape is covered by
        // test_an_adopted_guest_order_cannot_be_cancelled_by_the_customer_it_was_matched_to
        // below; this case stays because the zero shape is still the common one and still has to
        // be refused.
        $order = $this->makeOrder($store, [
            'customer_id' => 0, 'is_guest' => 1,
            'payment_status' => 'Paid', 'delivered_status' => 0,
        ]);

        $this->assertRefused(
            $this->changeStatus($store, $order->id, 'cancel', $attacker->createToken('t')->plainTextToken),
            'A customer cancelled a guest order'
        );

        $this->assertOrderUntouched($order, 'A customer cancelled a guest order');
    }

    public function test_an_unknown_order_id_is_refused_cleanly_and_not_a_server_error(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'a6');

        $response = $this->changeStatus($store, 999999, 'cancel', $customer->createToken('t')->plainTextToken);

        $this->assertRefused($response, 'An unknown order id was not refused cleanly');
    }

    public function test_the_owning_customer_can_still_cancel_their_own_order(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'a7');
        $order = $this->paidOrderFor($store, $customer);

        $this->changeStatus($store, $order->id, 'cancel', $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $fresh = $order->fresh();

        $this->assertSame(2, (int) $fresh->delivered_status,
            'Self-service cancellation of one\'s own order stopped working; that is a legitimate '
            . 'journey the model itself supports and it must be preserved.');
        $this->assertNotNull($fresh->cancel_date);
    }

    public function test_the_owning_merchant_can_still_change_their_own_stores_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'a8');
        $order = $this->paidOrderFor($store, $customer);

        $this->changeStatus($store, $order->id, 'confirmed', $merchant->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $this->assertSame(4, (int) $order->fresh()->delivered_status,
            'The owning merchant can no longer confirm an order in their own store.');
    }

    public function test_the_platform_operator_can_still_change_any_order(): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $customer = $this->customerIn($store, 'a9');
        $order = $this->paidOrderFor($store, $customer);

        $this->changeStatus($store, $order->id, 'confirmed', $operator->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $this->assertSame(4, (int) $order->fresh()->delivered_status,
            'The platform operator was refused. resolveViewableOrder admits a super admin '
            . 'explicitly, because isAbleTo() has no super-admin bypass in this codebase.');
    }

    /**
     * GAP-277 — THE PRINCIPAL THIS SUITE DID NOT HAVE, AND ITS ABSENCE IS WHY THE HOLE SURVIVED.
     *
     * Every case above drives a customer, a merchant, a driver or the operator, and each asks WHOSE
     * order it is. None asked whether a `User` may change an order AT ALL — and the resolver's User
     * branch resolves a `type='staff'` row to its owning admin through
     * `UserPolicy::merchantTenantRoot()`, so a staff member holding NO ROLE was admitted. Measured
     * through this route before the gate: HTTP 200 `{"status":1}`, `delivered_status` 0 → 2,
     * `cancel_date` stamped, the merchant's entitlement reversed and `products.product_stock` 5 → 7.
     *
     * It lives here as well as in `Gap277StorefrontOrderStatePermissionTest` deliberately: this is
     * the file a reader opens to ask "who may act on these two doors", the permission census
     * exempts storefront surfaces by design, and a principal that is in neither place is a
     * principal nobody drives.
     */
    public function test_a_roleless_staff_member_of_the_tenant_cannot_change_an_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $customer = $this->customerIn($store, 'a10');
        $order = $this->paidOrderFor($store, $customer);

        $this->assertSame([], $staff->allPermissions()->pluck('name')->all(),
            'Premise: this staff member must hold NOTHING, or the case measures a permitted caller.');

        $response = $this->changeStatus($store, $order->id, 'cancel', $staff->createToken('t')->plainTextToken);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 0);
        $this->assertSame('You do not have permission to perform this action.', $response->json('message'),
            'A staff member holding no role cancelled their tenant\'s order, or was refused for some '
            . 'other reason. "Order not found." would mean the ownership limb answered and the '
            . 'permission gate was never reached — the resolver admits them by design.');

        $this->assertOrderUntouched($order, 'A staff member holding no role cancelled an order');
    }

    /** The same principal on the twin door, which writes stock and `final_price`. GAP-277. */
    public function test_a_roleless_staff_member_of_the_tenant_cannot_mark_a_return(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $customer = $this->customerIn($store, 'a11');
        $product = $this->productIn($store, $merchant, 5);

        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id, 'is_guest' => 0,
            'delivered_status' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2, 'final_price' => 50,
            ]]),
        ]);

        $response = $this->returnProduct($store, $order->id, $product->id, $staff->createToken('t')->plainTextToken);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 0);
        $this->assertSame('You do not have permission to perform this action.', $response->json('message'),
            'A staff member holding no role marked a line returned on their tenant\'s order.');
        $this->assertSame(5, $this->stockOf($product),
            'The stock was credited by a principal holding no permission.');
    }

    // ===================================================================================
    // AUTHZ-4 — product-return, which also writes inventory
    // ===================================================================================

    public function test_a_customer_cannot_mark_a_return_on_another_customers_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'b1-victim');
        $attacker = $this->customerIn($store, 'b1-attacker');

        $product = $this->productIn($store, $merchant, 5);

        $order = $this->makeOrder($store, [
            'customer_id' => $victim->id, 'is_guest' => 0,
            'delivered_status' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2, 'final_price' => 50,
            ]]),
        ]);

        $this->assertRefused(
            $this->returnProduct($store, $order->id, $product->id, $attacker->createToken('t')->plainTextToken),
            'One customer marked a return on another customer\'s order'
        );

        $this->assertSame(5, (int) $product->fresh()->product_stock,
            'The stock was incremented by a caller who does not own the order — a cross-tenant '
            . 'inventory write.');

        $decoded = json_decode((string) $order->fresh()->product_json, true);
        $this->assertArrayNotHasKey('return', $decoded[0],
            'The order line was marked returned by a caller who does not own it.');
    }

    public function test_a_customer_cannot_mark_a_return_on_an_order_in_another_store(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'b2-victim');
        $attacker = $this->customerIn($storeB, 'b2-attacker');

        $product = $this->productIn($storeA, $merchantA, 7);

        $order = $this->makeOrder($storeA, [
            'customer_id' => $victim->id, 'is_guest' => 0,
            'delivered_status' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 3, 'final_price' => 30,
            ]]),
        ]);

        $this->assertRefused(
            $this->returnProduct($storeB, $order->id, $product->id, $attacker->createToken('t')->plainTextToken),
            'A customer of store B marked a return on store A\'s order'
        );

        $this->assertSame(7, (int) $product->fresh()->product_stock,
            'Stock in store A was incremented from store B.');
    }

    public function test_an_unknown_order_id_on_product_return_is_refused_and_not_a_crash(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'b3');

        // Order::product_return() dereferences its find() with no null guard, so before the fix
        // this path was a 500. Ownership is resolved first now, so it never reaches the model.
        $response = $this->returnProduct($store, 999999, 1, $customer->createToken('t')->plainTextToken);

        $this->assertRefused($response, 'An unknown order id reached the model and crashed');
    }

    public function test_the_owning_customer_can_still_mark_their_own_return(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'b4');

        $product = $this->productIn($store, $merchant, 1);

        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id, 'is_guest' => 0,
            'delivered_status' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 4, 'final_price' => 40,
            ]]),
        ]);

        $this->returnProduct($store, $order->id, $product->id, $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $decoded = json_decode((string) $order->fresh()->product_json, true);
        $this->assertSame(1, (int) ($decoded[0]['return'] ?? 0),
            'The owning customer can no longer mark their own return; that journey must be '
            . 'preserved.');
    }

    // ===================================================================================
    // THE ADOPTED GUEST SHAPE — `orders.customer_id` is not an answer to "who placed it"
    //
    // A guest checkout whose billing e-mail matches an existing customer OF THE SAME STORE is
    // written with that customer's REAL `customer_id` AND `is_guest = 1`, because nobody is
    // signed in (App\Services\PaymentService, and the same three lines in each gateway
    // controller). The e-mail match itself is correct and is left alone: it is the
    // returning-guest flow, it follows from per-store e-mail uniqueness, and
    // UnscopedCustomerEmailLookupTest pins it.
    //
    // What is not correct is reading the recorded id as proof that this customer placed the
    // order — nobody proved they own that address. So:
    //
    //     orders.customer_id  -> whose record is this attached to
    //     orders.is_guest     -> was anybody signed in when it was placed
    //
    // A self-service mutation wants the SECOND. The tests above only ever built the
    // `customer_id = 0` shape, so the first column answered both questions by accident and the
    // adopted shape walked through. This is an ACCOUNT-INTEGRITY defect, not a disclosure: the
    // matched customer is handed none of a registered customer's data by these doors.
    // ===================================================================================

    public function test_an_adopted_guest_order_cannot_be_cancelled_by_the_customer_it_was_matched_to(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $matched = $this->customerIn($store, 'g1');
        $product = $this->productIn($store, $merchant, 5);

        $order = $this->makeOrder($store, [
            // THE ADOPTED SHAPE: a real customer_id together with is_guest = 1.
            'customer_id' => $matched->id,
            'is_guest' => 1,
            'payment_status' => 'Paid',
            'delivered_status' => 0,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2, 'final_price' => 50,
            ]]),
        ]);

        // REFUSED FOR EXACTLY ONE REASON, which is the question a refusal test has to be able to
        // answer. The order is in this store and this slug, it is `Paid` and undelivered, and the
        // token is this customer's own — so resolveViewableOrder's store check, its id comparison
        // and order_status_change's `delivered_status != 0` guard all ADMIT this request. Nothing
        // but the is_guest limb under test can refuse it.
        $this->assertRefused(
            $this->changeStatus($store, $order->id, 'cancel', $matched->createToken('t')->plainTextToken),
            'The customer a guest checkout was matched to cancelled an order they never placed'
        );

        $this->assertOrderUntouched(
            $order,
            'The customer a guest checkout was matched to cancelled an order they never placed'
        );

        // THIS ASSERTION IS LOAD-BEARING, and an earlier draft of this comment said the
        // opposite — that `Order::order_status_change()` restores no stock, "so this assertion
        // cannot fail here today". MEASURED 2026-11-24 on this exact fixture and this exact
        // door, before the fix: HTTP 200 "Order status changed.", `delivered_status` 0 -> 2,
        // `cancel_date` stamped, and `products.product_stock` 5 -> 7. The model method really
        // does leave stock alone and says so, but THE CONTROLLER restores it
        // (`restoreCancelledOrderStock`, P4-F034) — so the claim was true of the method and
        // false of the door, which is the only thing a test can observe. Recorded because it is
        // the same mistake #188 is about: a statement about a check that stopped being true of
        // the path it sits on.
        $this->assertSame(5, $this->stockOf($product),
            'Stock was credited back by a cancellation this door refused. Measured at 5 -> 7 '
            . 'before the fix, so this is a real inventory write.');
    }

    public function test_an_adopted_guest_order_cannot_be_returned_by_the_customer_it_was_matched_to(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $matched = $this->customerIn($store, 'g2');
        $product = $this->productIn($store, $merchant, 5);

        $order = $this->makeOrder($store, [
            'customer_id' => $matched->id,
            'is_guest' => 1,
            'delivered_status' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2, 'final_price' => 50,
            ]]),
        ]);

        // ONE REASON ONLY, and each of `Order::product_return()`'s own refusals is cleared
        // deliberately: `delivered_status` is 1 so it is not "not delivered yet", the line
        // carries `product_id` and `qty` so `linesAreReturnable()` admits it, no line is marked
        // returned so the replay guard does not fire, and the order has a `store_id` so the
        // restock is not skipped. Before the fix this request SUCCEEDED and stock went 5 -> 7,
        // which is what makes the assertion below measure something.
        $this->assertRefused(
            $this->returnProduct($store, $order->id, $product->id, $matched->createToken('t')->plainTextToken),
            'The customer a guest checkout was matched to returned goods on an order they never placed'
        );

        $this->assertSame(5, $this->stockOf($product),
            'Stock was credited back by a caller who never placed the order. ProductStockService '
            . 'increments products.product_stock, so this is a real inventory write and not just '
            . 'a status field.');

        $line = json_decode((string) $this->orderRow($order)->product_json, true)[0];
        $this->assertArrayNotHasKey('return', $line,
            'The order line was marked returned by a caller who never placed the order.');
    }

    // ===================================================================================
    // THE LIMB IS CUSTOMER-SCOPED ON PURPOSE, AND THESE TWO PIN IT
    //
    // Only the CUSTOMER branch of resolveViewableOrder() proves ownership from
    // `orders.customer_id`, which is the one column adoption corrupts. The merchant branch
    // proves it from store ownership and the driver branch from `deliveryboy_id`; neither is
    // affected by whom a billing e-mail happened to match. Refusing them on a guest order would
    // remove the only way a guest order can be cancelled or returned at all — which is the
    // remedy the sibling web door names in its own docblock, "a guest cancellation has to go
    // through the merchant" — and would buy no security, because they never relied on the
    // corrupted column.
    // ===================================================================================

    public function test_the_owning_merchant_can_still_cancel_a_guest_order_in_their_own_store(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, $merchant, 5);

        $order = $this->makeOrder($store, [
            'customer_id' => 0, 'is_guest' => 1,
            'payment_status' => 'Paid', 'delivered_status' => 0,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2, 'final_price' => 50,
            ]]),
        ]);

        $this->changeStatus($store, $order->id, 'cancel', $merchant->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $fresh = $this->orderRow($order);

        $this->assertSame(2, (int) $fresh->delivered_status,
            'The merchant can no longer cancel a guest order in their own store. A guest order '
            . 'has no account behind it, so the merchant is the ONLY principal who can act on '
            . 'one — refusing them here would make guest orders uncancellable.');
        $this->assertNotNull($fresh->cancel_date);

        /*
         * THE POSITIVE CONTROL FOR THE REFUSAL TEST'S STOCK ASSERTION (review finding). Without
         * it, "stock is still 5" after a refused cancellation held for two reasons that a test
         * cannot tell apart: the control refused the write, or this door never writes stock at
         * all. The fixture here now carries the same product and the same qty-2 line, so the
         * authorised journey is measured moving stock 5 -> 7 through THIS door -- and if
         * `restoreCancelledOrderStock` ever stops firing, this fails rather than quietly making
         * the sibling assertion vacuous.
         */
        $this->assertSame(7, $this->stockOf($product),
            'The cancel door no longer restores stock, which would make the refusal test above '
            . 'vacuous rather than failing it.');
    }

    public function test_the_owning_merchant_can_still_mark_a_return_on_a_guest_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, $merchant, 5);

        $order = $this->makeOrder($store, [
            'customer_id' => 0, 'is_guest' => 1,
            'delivered_status' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2, 'final_price' => 50,
            ]]),
        ]);

        $this->returnProduct($store, $order->id, $product->id, $merchant->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        // AND THIS IS ALSO THE MEASUREMENT THAT MAKES THE REFUSAL TEST ABOVE NON-VACUOUS: the
        // authorised journey through this same door really does credit stock, 5 -> 7, so "stock
        // is still 5" after a refusal is a statement about the control and not about a door that
        // never writes stock at all.
        $this->assertSame(7, $this->stockOf($product),
            'The merchant can no longer process a return on a guest order.');

        $line = json_decode((string) $this->orderRow($order)->product_json, true)[0];
        $this->assertSame(1, (int) ($line['return'] ?? 0),
            'The merchant can no longer process a return on a guest order.');
    }

    /*
     * THE DRIVER AND THE OPERATOR, ON A GUEST ORDER (review finding, and it was a real hole in
     * the evidence rather than a tidiness point). The limb narrows the CUSTOMER branch only. The
     * two merchant oracles above prove the merchant branch survives, and the refusals prove the
     * customer branch does not -- but nothing proved the OTHER two branches survive on a guest
     * order, so a mutant shaped `… && ! auth()->user()`, or any other principal predicate in
     * place of the customers guard, passed every test in this file while silently refusing every
     * driver and every operator on every guest order. A guest order is the one kind nobody can
     * self-service, so those two principals are part of the remedy, not an edge case.
     *
     * NO SINGLE ORACLE CATCHES EVERY SUBSTITUTION, AND THAT IS WHY THERE ARE THREE. Measured, two
     * complementary mutants of the guard expression:
     *
     *   `… && ! auth()->user()`                  CAUGHT by the driver. SURVIVES the operator,
     *                                            because CustomAuth logs a User into the `web`
     *                                            guard, so the default guard IS populated for
     *                                            them and the negation is false.
     *   `… && ! auth('deliveryboy')->user()`     CAUGHT by the operator AND the merchant.
     *                                            SURVIVES the driver, for the mirror reason.
     *
     * So the first mutant run against the operator reported SURVIVED, and that was the mutation
     * being wrong rather than the oracle being decorative -- the same lesson this session already
     * recorded about aiming a mutation at a threshold instead of at the condition. Each oracle is
     * load-bearing against a different substitution; together they leave none.
     */

    public function test_the_assigned_driver_can_still_act_on_a_guest_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $driver = $this->driverFor($merchant, $store, 'g3');

        $order = $this->makeOrder($store, [
            'customer_id' => 0, 'is_guest' => 1,
            'payment_status' => 'Paid', 'delivered_status' => 0,
            'deliveryboy_id' => $driver->id,
        ]);

        $this->changeStatus($store, $order->id, 'confirmed', $driver->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $this->assertSame(4, (int) $this->orderRow($order)->delivered_status,
            'The assigned driver can no longer act on a guest order. A driver\'s claim comes from '
            . 'orders.deliveryboy_id, which the adoption shape cannot corrupt, so narrowing them '
            . 'would close nothing and break deliveries.');
    }

    public function test_the_platform_operator_can_still_act_on_a_guest_order(): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();

        $order = $this->makeOrder($store, [
            'customer_id' => 0, 'is_guest' => 1,
            'payment_status' => 'Paid', 'delivered_status' => 0,
        ]);

        $this->changeStatus($store, $order->id, 'confirmed', $operator->createToken('t')->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $this->assertSame(4, (int) $this->orderRow($order)->delivered_status,
            'The platform operator can no longer act on a guest order. Their claim comes from '
            . 'users.type, which the adoption shape cannot corrupt.');
    }

    /*
     * ONE TEST CANNOT DRIVE TWO PRINCIPALS AT THE SAME ORDER HERE, AND THIS IS MEASURED RATHER
     * THAN ASSUMED -- it was written, it failed, and the failure was the harness.
     *
     * The obvious strengthening of the cases above is one test that refuses the matched customer
     * on an adopted order and then has the MERCHANT cancel that same row, so the scoping cannot
     * be an accident of two different fixtures. It does not work, because THE `customers` GUARD
     * LEAKS BETWEEN REQUESTS INSIDE ONE TEST METHOD. Probed 2026-11-24, on this surface:
     *
     *     before any request        auth('customers')->check() === false
     *     after a customer-token request   === true, id = the customer
     *     after a MERCHANT-token request   === true, id = STILL that customer
     *     after $this->app['auth']->forgetGuards()  === true  (it does not clear it)
     *
     * So the merchant's request is judged by resolveViewableOrder's CUSTOMER branch -- which is
     * checked first -- and the limb refuses it. The second request answered "Order not found."
     * and `delivered_status` stayed 0.
     *
     * IT IS A HARNESS ARTEFACT AND NOT A PRODUCTION DEFECT, and the reason is worth keeping
     * because it is the same reason the limb is sound: these routes are in the `api` group, whose
     * middleware stack carries no StartSession (bootstrap/app.php appends only to `web`), and
     * CustomAuth logs a token's owner into EXACTLY ONE guard per request. A production request
     * therefore cannot arrive holding a customer login and a merchant token at once; a test
     * process, which serves both requests from one container, can.
     *
     * CLAUDE.md records the same class of trap from the other direction -- `actingAs($c,
     * "customers")` makes `customers` the DEFAULT guard and hides it, which is why `asAShopper()`
     * exists. This is that hazard without any `actingAs` at all.
     *
     * What covers the proposition instead: one principal per test, plus the mutation that
     * replaces the customers guard with any other principal predicate, which the driver and
     * operator oracles above CATCH. That is the review finding this note answers, and dropping
     * the paired test rather than plumbing around the leak is deliberate -- a test that logs a
     * guard out mid-method to simulate a second request would be asserting something about a
     * sequence production never performs.
     */
}
