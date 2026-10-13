<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * GATE C, the half that `auth` did not close.
 *
 * Phase 1C moved order.return.request behind the `auth` middleware, which closed ANONYMOUS
 * access and was reported as "GATE C CLOSED". It was not closed. The handler resolved the
 * order with a bare `Order::find($id)` and had no ownership check of any kind, so the same
 * attack survived -- it merely required a login, which public self-registration hands out.
 *
 * This is the clearest conflict Phase 1C produced between two agents: the route owner moved
 * the route and assumed the handler guarded ownership; the handler owner changed other
 * methods in the same file and assumed the route guarded access. Neither end carried the
 * control, and because the two changes lived in different files Git reported nothing.
 *
 * The suite also pins the silent stock defect found in the same method: the loop reassigned
 * $product from the array element to the Product model and then read $product['qty'] off the
 * model, which has no such attribute, so `product_stock += null` added zero and every
 * non-variant line's restore was a no-op.
 */
class OrderReturnAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The route must still require authentication -- the half that WAS closed. */
    public function test_the_return_request_route_requires_authentication(): void
    {
        $route = Route::getRoutes()->getByName('order.return.request');

        $this->assertNotNull($route, 'order.return.request is not registered at all.');
        $this->assertContains('auth', $route->gatherMiddleware(),
            'order.return.request must stay behind auth; it was an anonymous arbitrary write.');
    }

    /** THE DEFECT: a logged-in merchant must not approve another store's return. */
    public function test_a_merchant_cannot_approve_a_return_on_another_stores_order(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Manage Order');

        [, $victimStore] = $this->makeTenant();
        $victimOrder = $this->makeOrder($victimStore, ['return_status' => 0]);

        $this->actingAs($attacker->fresh())
            ->post('/order-return-request', ['id' => $victimOrder->id, 'status' => 2])
            ->assertForbidden();

        $this->assertSame(0, (int) $victimOrder->fresh()->return_status,
            "A merchant approved another store's return request.");
    }

    /** The same, for the reject limb. */
    public function test_a_merchant_cannot_reject_a_return_on_another_stores_order(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Manage Order');

        [, $victimStore] = $this->makeTenant();
        $victimOrder = $this->makeOrder($victimStore, ['return_status' => 0]);

        $this->actingAs($attacker->fresh())
            ->post('/order-return-request', ['id' => $victimOrder->id, 'status' => 3])
            ->assertForbidden();

        $this->assertSame(0, (int) $victimOrder->fresh()->return_status);
    }

    /**
     * THE POSITIVE CASE, which matters as much: the legitimate merchant workflow must
     * still work. Without this, refusing everything would satisfy both tests above.
     */
    public function test_a_permitted_merchant_can_approve_a_return_in_their_own_store(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Order');

        $order = $this->makeOrder($store, ['return_status' => 0]);

        $this->actingAs($owner->fresh())
            ->post('/order-return-request', ['id' => $order->id, 'status' => 2])
            ->assertOk();

        $this->assertSame(2, (int) $order->fresh()->return_status,
            'The merchant must still be able to approve a return in their own store.');
    }

    /** The platform-operator support workflow must not be broken by the fix. */
    public function test_a_platform_admin_can_still_approve_a_return(): void
    {
        $admin = $this->makePlatformAdmin();
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store, ['return_status' => 0]);

        $this->actingAs($admin)
            ->post('/order-return-request', ['id' => $order->id, 'status' => 2])
            ->assertOk();

        $this->assertSame(2, (int) $order->fresh()->return_status);
    }

    /**
     * THE SILENT STOCK DEFECT. Approving a return must actually give the stock back for a
     * NON-VARIANT line. Before the fix this added zero, because $product had been
     * reassigned from the array element to the Product model and the model has no `qty`.
     */
    public function test_approving_a_return_restores_stock_for_a_non_variant_line(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Order');

        $product = Product::forceCreate([
            'name' => 'returned-product-' . uniqid(),
            'slug' => 'returned-' . uniqid(),
            'product_stock' => 5,
            'track_stock' => 0,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $owner->id,
        ]);

        $order = $this->makeOrder($store, [
            'return_status' => 0,
            'product_json' => json_encode([
                ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
            ]),
        ]);

        $this->actingAs($owner->fresh())
            ->post('/order-return-request', ['id' => $order->id, 'status' => 2])
            ->assertOk();

        $this->assertSame(8, (int) $product->fresh()->product_stock,
            'Approving a return must give back the 3 units of a non-variant line. '
            . 'Adding zero here is the silent no-op this test exists to catch.');
    }
}
