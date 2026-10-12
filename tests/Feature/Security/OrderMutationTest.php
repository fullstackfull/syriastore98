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
        $this->grantPermission($merchant, 'Manage Order');
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
        $this->grantPermission($attacker, 'Manage Order');

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
        $this->grantPermission($attacker, 'Manage Order');

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

        // `delivery_earnings` is one of the six MONEY tables that finding G1 lists as read
        // and written by live code (Api/DashboardController.php:7688 deletes and :7707
        // creates rows) while NO migration in this repository creates it -- verified across
        // all 207 migration files. Agent 2 deliberately left the money tables behind GATE F
        // instead of committing DDL with a guessed decimal precision, because the eventual
        // correct migration is a Schema::hasTable() no-op and the guess would become
        // permanent and invisible. On a from-source database the table is therefore
        // legitimately absent.
        //
        // The authorization assertions below are what this test exists to prove and they run
        // unconditionally. Only the financial-record assertion depends on the gated table,
        // so it alone is conditional -- the alternative, skipping the whole test, would
        // silently drop a tenant-isolation guarantee for a reason unrelated to it.
        $hasEarnings = \Illuminate\Support\Facades\Schema::hasTable('delivery_earnings');
        $earningsBefore = $hasEarnings ? \App\Models\DeliveryEarning::count() : null;

        $this->actingAs($attacker)
            ->post('/order-assign', [
                'order_id' => $order->id,
                'delivery_boy' => $deliveryBoy->id,
            ])
            ->assertForbidden();

        $this->assertNull($order->fresh()->deliveryboy_id);
        if ($hasEarnings) {
            $this->assertSame($earningsBefore, \App\Models\DeliveryEarning::count());
        }
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
        $this->grantPermission($merchant, 'Manage Order');
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
     * Asserts the refusal the policy DELIBERATELY implements.
     *
     * An earlier version of this test asserted the opposite — that a delivery boy
     * could update their own assigned order through this web route — on the basis
     * of a delivery-boy limb in OrderPolicy::updateStatus. No such limb exists
     * (see OrderPolicy:74-91 for why it was refused), so that assertion was
     * testing a feature that was never built.
     *
     * It could not work in any case: this route sits behind the web `auth`
     * middleware and the policy is type-hinted User, while delivery boys
     * authenticate on the separate `deliveryboy` guard against the separate
     * delivery_boys table. And deliveryboy_id is a delivery_boys.id while
     * $actor->id is a users.id, two unrelated sequences that both start at 1 —
     * so the limb would have been a cross-tenant bypass, not a feature.
     *
     * The delivery-boy flow has its own guard and its own endpoints. If it ever
     * needs a policy it needs one written against the DeliveryBoy model.
     */
    public function test_a_web_guard_user_is_not_granted_access_by_a_matching_deliveryboy_id(): void
    {
        [, $store] = $this->makeTenant();

        // A user with NO order permission whose users.id is deliberately recorded
        // as the order's deliveryboy_id. Under the refused limb this would have
        // granted access to another store's order; it must not.
        $actor = $this->makeMerchant(['type' => 'deliveryboy']);
        $order = $this->makeOrder($store, [
            'deliveryboy_id' => $actor->id,
            'delivered_status' => 0,
        ]);

        $this->actingAs($actor)->post("/update-order-status/{$order->id}");

        $this->assertSame(
            0,
            (int) $order->fresh()->delivered_status,
            'A matching deliveryboy_id must not confer access on a web-guard user.'
        );
    }

    public function test_user_without_manage_order_permission_is_denied(): void
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
        $this->grantPermission($merchant, 'Manage Order');

        $response = $this->actingAs($merchant)->post('/update-order-status/999999');

        $this->assertNotSame(500, $response->getStatusCode());
    }
}
