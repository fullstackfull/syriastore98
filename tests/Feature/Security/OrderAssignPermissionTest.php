<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-04-15 / WS E12 — the PERMISSION half of "only authorised delivery changes".
 * =================================================================================================
 *
 * THE GAP THIS FILLS, found by gap analysis rather than by a failure. E12 asks for "a cross-tenant
 * AND an unpermitted-principal case for each write". For order notes both exist
 * (`OrderNoteAuthorizationTest`, nine cases, API and web, including
 * `test_web_note_create_still_requires_the_permission`). For DELIVERY ASSIGNMENT only the tenancy
 * half existed:
 *
 *     OrderMutationTest::test_merchant_cannot_assign_another_stores_order       foreign ORDER
 *     DeliveryBoyScopeTest::test_an_order_cannot_be_assigned_to_another_stores_driver   foreign DRIVER
 *     DeliveryBoyScopeTest::test_the_merchants_own_driver_passes_the_guard_...  the positive case
 *
 * Every one of them GRANTS `Manage Order` and varies the tenancy. So nothing asserted that the
 * permission is required at all: `OrderPolicy::assign()` delegating to
 * `allow($actor, $order, 'Manage Order')` was a reading of the code, not a measured fact, and a
 * change to `return true` in that one method would have been caught by no test.
 *
 * WHY THE ORDER OF THE CHECKS MATTERS HERE. `OrderPolicy::allow()` tests the platform admin FIRST,
 * deliberately — its own comment records that `isAbleTo()` running ahead of it locked the operator
 * out of assign entirely, because on a database built from source no role holds `Manage Order`.
 * That ordering means a permission test must use a principal who is NOT the operator, or it
 * measures the admin bypass instead. Both are asserted below, separately.
 */
class OrderAssignPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function orderIn($store, $merchant): Order
    {
        return Order::forceCreate([
            'product_order_id' => 'ASGP-' . uniqid(),
            'order_date' => now()->toDateString(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([['product_id' => 7, 'qty' => 1, 'price' => 10.0]]),
            'product_id' => '7',
            'product_price' => 10.0,
            'final_price' => 10.0,
            'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);
    }

    private function driverIn($store, $merchant): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver',
            'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'created_by' => $merchant->id,
            'store_id' => $store->id,
        ]);
    }

    public function test_a_merchant_without_manage_order_cannot_assign_their_own_order(): void
    {
        /* THE WHOLE POINT: everything about this principal is legitimate except the permission.
         * Their own store, their own order, their own driver. Only `Manage Order` is missing. */
        [$merchant, $store] = $this->makeTenant();
        $order = $this->orderIn($store, $merchant);
        $driver = $this->driverIn($store, $merchant);

        $this->assertFalse($merchant->fresh()->isAbleTo('Manage Order'),
            'premise: this principal must NOT hold Manage Order, or the case measures nothing');

        $response = $this->actingAs($merchant->fresh())->post('/order-assign', [
            'order_id' => $order->id,
            'delivery_boy' => $driver->id,
        ]);

        $response->assertForbidden();

        $this->assertNull($order->fresh()->deliveryboy_id,
            'an order was assigned by a principal who does not hold Manage Order');
    }

    public function test_the_same_principal_succeeds_once_the_permission_is_granted(): void
    {
        /* THE OTHER HALF, and the one that makes the refusal above meaningful rather than a
         * symptom of a broken fixture. Same tenant, same order, same driver -- only the
         * permission changes. Without this, a test that refused for ANY reason would pass. */
        [$merchant, $store] = $this->makeTenant();
        $order = $this->orderIn($store, $merchant);
        $driver = $this->driverIn($store, $merchant);

        $this->grantPermission($merchant, 'Manage Order');

        $response = $this->actingAs($merchant->fresh())->post('/order-assign', [
            'order_id' => $order->id,
            'delivery_boy' => $driver->id,
        ]);

        $this->assertNotSame(403, $response->getStatusCode(),
            'the same principal WITH Manage Order was still refused, so the refusal above was not '
            . 'about the permission');

        /* `delivery_earnings` HAS NO MIGRATION (GATE F), so the handler's own write to it cannot
         * complete in the test database. The authorization is what this file is about and it is
         * asserted unconditionally; only the assignment's completion depends on that table, and it
         * is read conditionally -- the pattern `OrderMutationTest:155-160` establishes, for the
         * same reason: skipping the whole case would drop a guarantee unrelated to the gap. */
        if (\Illuminate\Support\Facades\Schema::hasTable('delivery_earnings')) {
            $this->assertSame((int) $driver->id, (int) $order->fresh()->deliveryboy_id,
                'the permitted, owning merchant could not assign their own driver');
        }
    }

    public function test_the_platform_operator_may_assign_without_holding_the_permission(): void
    {
        /* THE ADMIN LIMB, asserted separately because `allow()` checks it FIRST and a permission
         * test that happened to use an operator would measure the bypass instead of the
         * permission. On a database built from source no role holds `Manage Order`, which is
         * exactly the situation that ordering exists for. */
        [$merchant, $store] = $this->makeTenant();
        $order = $this->orderIn($store, $merchant);
        $driver = $this->driverIn($store, $merchant);

        $operator = \App\Models\User::where('type', 'super admin')->first();

        if ($operator === null) {
            $operator = \App\Models\User::factory()->create(['type' => 'super admin']);
        }

        $this->assertFalse($operator->fresh()->isAbleTo('Manage Order'),
            'premise: the operator must NOT hold the permission, or this measures the permission '
            . 'and not the admin limb');

        $response = $this->actingAs($operator->fresh())->post('/order-assign', [
            'order_id' => $order->id,
            'delivery_boy' => $driver->id,
        ]);

        $this->assertNotSame(403, $response->getStatusCode(),
            'the platform operator was refused. OrderPolicy::allow() checks isPlatformAdmin FIRST '
            . 'precisely so the operator keeps the support workflow on a database where no role '
            . 'holds Manage Order; a 403 here means that ordering has been inverted again.');
    }
}
