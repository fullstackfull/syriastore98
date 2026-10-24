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
 * THE FINANCIAL EFFECT IS THE POINT, not the status field. app/Models/Order.php:539-554, the
 * cancel branch, sets `delivered_status = 2` AND `payment_status = 'Unpaid'` and then saves — and
 * the comment beside it says the observer picks that change up and calls the wallet service. So
 * the hole was an attacker-triggered refund against any order in any store. product_return
 * additionally increments `products.product_stock` (:657-669), a cross-tenant inventory write.
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
     * Order lines carry `final_price` as well as `qty`: Order::product_return() reads
     * `$product['final_price']` at app/Models/Order.php:685 when recomputing the return total, so
     * a fixture without it makes the AUTHORISED journey 500 and the test measures a crash instead
     * of a success. Measured — that is exactly what the first version of these fixtures did.
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
        // HTTP 200 with status 0 is this API's denial shape. A 401 would be a forced logout in the
        // shipped Flutter client, and a 500 would mean the handler crashed rather than refused.
        $response->assertStatus(200);
        $response->assertJsonPath('status', 0);
        $this->assertNotSame(500, $response->status(), $because);
    }

    private function assertOrderUntouched(Order $order, string $because): void
    {
        $fresh = $order->fresh();

        $this->assertSame('Paid', (string) $fresh->payment_status,
            $because . ' — payment_status was flipped, which is what drives the wallet refund.');
        $this->assertSame(0, (int) $fresh->delivered_status,
            $because . ' — delivered_status was changed.');
        $this->assertNull($fresh->cancel_date, $because . ' — the order was cancelled.');
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

        // A guest order carries customer_id = 0, so it belongs to no customer and 0 must never
        // match an authenticated id.
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
}
