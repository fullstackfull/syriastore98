<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * status.cancel -- an anonymous, cross-tenant order cancellation AND an unbounded
 * inventory-inflation primitive.
 *
 * The route (routes/customer.php:142, POST {storeSlug?}/status-cancel) sits in the storefront
 * group whose only middleware is [ActiveTheme, themelanguage], so it required no session at
 * all. The handler took `order_id` straight off the request with no ownership check, so an
 * anonymous caller who guessed a numeric id could cancel ANY order in ANY store.
 *
 * Worse, the stock-restore loop ran BEFORE the handler examined whether the cancellation had
 * actually succeeded. Order::order_status_change() refuses a second attempt
 * (delivered_status != 0), but the loop had already added `qty` back -- so repeating one
 * request against one guessed id inflated a merchant's inventory without limit.
 *
 * This was found by the Phase 1C API/authorization agent, which could not fix it because
 * OrderController belonged to another agent, and is closed here on integration.
 */
class OrderCancelAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeCustomerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Cust',
            'last_name' => 'Omer',
            'email' => 'cust' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => \App\Models\User::class,
        ]);
    }

    private function makeProductIn($store, $owner, int $stock): Product
    {
        return Product::forceCreate([
            'name' => 'cancel-product-' . uniqid(),
            'slug' => 'cancel-' . uniqid(),
            'product_stock' => $stock,
            'track_stock' => 0,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $owner->id,
        ]);
    }

    /** THE DEFECT: no session at all was required. */
    public function test_an_anonymous_caller_cannot_cancel_an_order(): void
    {
        [$owner, $store] = $this->makeTenant();
        $customer = $this->makeCustomerIn($store);
        $product = $this->makeProductIn($store, $owner, 5);

        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'delivered_status' => 0,
            'product_json' => json_encode([
                ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
            ]),
        ]);

        $this->post("/{$store->slug}/status-cancel", [
            'order_id' => $order->id,
            'order_status' => 'cancel',
        ])->assertForbidden();

        $this->assertSame(0, (int) $order->fresh()->delivered_status,
            'An anonymous caller cancelled an order.');
        $this->assertSame(5, (int) $product->fresh()->product_stock,
            'An anonymous caller inflated a merchant\'s stock.');
    }

    /** A signed-in customer must not cancel a DIFFERENT customer's order. */
    public function test_a_customer_cannot_cancel_another_customers_order(): void
    {
        [$owner, $store] = $this->makeTenant();
        $victim = $this->makeCustomerIn($store);
        $attacker = $this->makeCustomerIn($store);
        $product = $this->makeProductIn($store, $owner, 5);

        $order = $this->makeOrder($store, [
            'customer_id' => $victim->id,
            'delivered_status' => 0,
            'product_json' => json_encode([
                ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
            ]),
        ]);

        $this->actingAs($attacker, 'customers')
            ->post("/{$store->slug}/status-cancel", [
                'order_id' => $order->id,
                'order_status' => 'cancel',
            ])->assertNotFound();

        $this->assertSame(0, (int) $order->fresh()->delivered_status);
        $this->assertSame(5, (int) $product->fresh()->product_stock);
    }

    /** And must not reach across stores. */
    public function test_a_customer_cannot_cancel_an_order_in_another_store(): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();

        $attacker = $this->makeCustomerIn($storeA);
        $victimCustomer = $this->makeCustomerIn($storeB);
        $product = $this->makeProductIn($storeB, $ownerB, 5);

        $order = $this->makeOrder($storeB, [
            'customer_id' => $victimCustomer->id,
            'delivered_status' => 0,
            'product_json' => json_encode([
                ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
            ]),
        ]);

        $this->actingAs($attacker, 'customers')
            ->post("/{$storeA->slug}/status-cancel", [
                'order_id' => $order->id,
                'order_status' => 'cancel',
            ])->assertNotFound();

        $this->assertSame(0, (int) $order->fresh()->delivered_status);
        $this->assertSame(5, (int) $product->fresh()->product_stock);
    }

    /**
     * THE POSITIVE CASE. The legitimate customer self-service cancellation must still work,
     * and the stock must come back exactly once.
     */
    public function test_the_owning_customer_can_still_cancel_and_the_stock_returns(): void
    {
        [$owner, $store] = $this->makeTenant();
        $customer = $this->makeCustomerIn($store);
        $product = $this->makeProductIn($store, $owner, 5);

        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'delivered_status' => 0,
            'product_json' => json_encode([
                ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
            ]),
        ]);

        $this->actingAs($customer, 'customers')
            ->post("/{$store->slug}/status-cancel", [
                'order_id' => $order->id,
                'order_status' => 'cancel',
            ])->assertOk();

        $this->assertSame(2, (int) $order->fresh()->delivered_status,
            'The owning customer must still be able to cancel their own order.');
        $this->assertSame(8, (int) $product->fresh()->product_stock,
            'And the 3 units must come back.');
    }

    /**
     * THE INFLATION PRIMITIVE. Replaying the cancellation must add NOTHING. Before the fix
     * the stock loop ran before the success check, so every replay added qty again even
     * though order_status_change refused the cancellation.
     */
    public function test_replaying_a_cancellation_does_not_inflate_stock_again(): void
    {
        [$owner, $store] = $this->makeTenant();
        $customer = $this->makeCustomerIn($store);
        $product = $this->makeProductIn($store, $owner, 5);

        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'delivered_status' => 0,
            'product_json' => json_encode([
                ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
            ]),
        ]);

        $payload = ['order_id' => $order->id, 'order_status' => 'cancel'];

        $this->actingAs($customer, 'customers')->post("/{$store->slug}/status-cancel", $payload);
        $afterFirst = (int) $product->fresh()->product_stock;

        $this->actingAs($customer, 'customers')->post("/{$store->slug}/status-cancel", $payload);
        $this->actingAs($customer, 'customers')->post("/{$store->slug}/status-cancel", $payload);

        $this->assertSame($afterFirst, (int) $product->fresh()->product_stock,
            'Replaying the cancellation inflated stock. This endpoint was reachable '
            . 'anonymously, so that made it an unbounded inventory-inflation primitive.');
        $this->assertSame(8, $afterFirst, 'and the single legitimate restore is 5 + 3');
    }

    /** The route is storefront-public by design; the control is in the handler. */
    public function test_the_route_remains_registered_on_the_storefront_group(): void
    {
        $route = Route::getRoutes()->getByName('status.cancel');
        $this->assertNotNull($route, 'status.cancel must stay registered.');
        $this->assertNotContains('auth', $route->gatherMiddleware(),
            'This is a customer-guard endpoint on the storefront, not an admin route; the '
            . 'ownership control belongs in the handler.');
    }
}
