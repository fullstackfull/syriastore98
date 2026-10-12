<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * SEC-F7 + NEW-order-payment-status + NEW-public-mutating-routes.
 *
 * `order_payment_status` was the single most severe trivially-exploitable
 * issue in the set: Order::find($request->order_id), assign, save, on a route
 * that sat OUTSIDE the `auth` group -- an anonymous arbitrary payment_status
 * write on any order in any store, in one request, with no account.
 *
 * Every denial asserts the orders row is UNCHANGED, not merely that the status
 * code was right.
 */
class OrderMutationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * NOTE: this whole suite is BLOCKED on the undeclared orders.currency_code /
     * orders.currency_symbol columns -- see SecurityTestCase::makeOrder(),
     * which skips automatically. The authorization work under test here is
     * meanwhile covered at the route level by
     * tests/Feature/Security/UnauthenticatedRouteBoundaryTest.php, which proves
     * every one of these routes now carries `auth`.
     */

    // ---------------------------------------------------------------
    // Anonymous
    // ---------------------------------------------------------------

    public function test_anonymous_cannot_change_payment_status(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store, ['payment_status' => 'Unpaid']);

        $this->post('/order-payment-status', [
            'order_id' => $order->id,
            'payment_status' => 'Paid',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'Unpaid',
        ]);
    }

    public function test_anonymous_cannot_mark_order_delivered(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store, ['delivered_status' => 0]);

        $this->post("/update-order-status/{$order->id}");

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivered_status' => 0,
        ]);
    }

    public function test_anonymous_cannot_delete_an_order(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $this->delete("/order/{$order->id}");

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    /**
     * An arbitrary string in a status column is a data-integrity defect
     * independent of the authorization defect. The vocabulary is taken from the
     * validation OrderController::store already applies to the same column.
     */
    public function test_payment_status_must_be_a_known_value(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Order');
        $order = $this->makeOrder($store, ['payment_status' => 'Unpaid']);

        $this->actingAs($merchant)->post('/order-payment-status', [
            'order_id' => $order->id,
            'payment_status' => 'totally-made-up-status',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'Unpaid',
        ]);
    }

    // ---------------------------------------------------------------
    // Cross-tenant
    // ---------------------------------------------------------------

    public function test_merchant_cannot_change_another_stores_payment_status(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Order');

        [, $victimStore] = $this->makeTenant();
        $order = $this->makeOrder($victimStore, ['payment_status' => 'Unpaid']);

        $this->actingAs($attacker)
            ->post('/order-payment-status', [
                'order_id' => $order->id,
                'payment_status' => 'Paid',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'Unpaid',
        ]);
    }

    /**
     * The delivery_earnings assertion is its own requirement: order_assign
     * deletes DeliveryEarning rows, which are financial records.
     */
    public function test_merchant_cannot_assign_another_stores_order(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Order');

        [, $victimStore] = $this->makeTenant();
        $order = $this->makeOrder($victimStore, ['deliveryboy_id' => null]);

        $deliveryBoy = \App\Models\DeliveryBoy::create([
            'name' => 'DB One',
            'email' => 'db' . uniqid() . '@example.test',
            'type' => 'deliveryboy',
            'password' => bcrypt('password'),
            'contact' => '0000000000',
            'created_by' => $attacker->id,
            'store_id' => $victimStore->id,
        ]);

        $earningsBefore = \App\Models\DeliveryEarning::count();

        $this->actingAs($attacker)
            ->post('/order-assign', [
                'order_id' => $order->id,
                'delivery_boy' => $deliveryBoy->id,
            ])
            ->assertForbidden();

        $this->assertNull($order->fresh()->deliveryboy_id);
        $this->assertSame($earningsBefore, \App\Models\DeliveryEarning::count());
    }

    public function test_merchant_cannot_delete_another_stores_order(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Order');

        [, $victimStore] = $this->makeTenant();
        $order = $this->makeOrder($victimStore);

        $this->actingAs($attacker)
            ->delete("/order/{$order->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    // ---------------------------------------------------------------
    // Positive cases -- these protect the flows the fix could break
    // ---------------------------------------------------------------

    public function test_merchant_can_change_their_own_stores_payment_status(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Order');
        $order = $this->makeOrder($store, ['payment_status' => 'Unpaid']);

        $this->actingAs($merchant)->post('/order-payment-status', [
            'order_id' => $order->id,
            'payment_status' => 'Paid',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'Paid',
        ]);
    }

    /**
     * THE POSITIVE CASE PROTECTING THE FLOW THIS FIX MOST LIKELY BREAKS.
     * OrderPolicy::updateStatus carries an explicit delivery-boy limb; without
     * it the whole delivery flow stops.
     */
    public function test_delivery_boy_can_still_update_their_own_assigned_order(): void
    {
        [, $store] = $this->makeTenant();

        // The delivery-boy limb compares $order->deliveryboy_id against the
        // acting user's id, so the actor here is a user whose id is recorded on
        // the order.
        $courier = $this->makeMerchant(['type' => 'deliveryboy']);
        $order = $this->makeOrder($store, [
            'deliveryboy_id' => $courier->id,
            'delivered_status' => 0,
        ]);

        $this->actingAs($courier)->post("/update-order-status/{$order->id}");

        $this->assertSame(1, (int) $order->fresh()->delivered_status);
    }

    public function test_user_without_edit_order_permission_is_denied(): void
    {
        [$merchant, $store] = $this->makeTenant();
        // Deliberately NO permission granted.
        $order = $this->makeOrder($store, ['payment_status' => 'Unpaid']);

        $this->actingAs($merchant)
            ->post('/order-payment-status', [
                'order_id' => $order->id,
                'payment_status' => 'Paid',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'Unpaid',
        ]);
    }

    public function test_unknown_order_id_is_a_404_not_a_500(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Order');

        $response = $this->actingAs($merchant)->post('/update-order-status/999999');

        $this->assertNotSame(500, $response->getStatusCode());
    }
}
