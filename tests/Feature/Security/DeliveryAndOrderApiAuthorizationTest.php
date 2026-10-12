<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * NEW-api-deliveryboy-unscoped, NEW-api-status-change-any-order,
 * NEW-api-order-cancel-any-order.
 *
 * Five handlers on the /api/admin/* surface (routes/api.php:338-344, all
 * `custom.auth` only) resolved their subject from a request field with no
 * predicate:
 *
 *   statusChange   Order::order_status_change() on $request->id, nothing else,
 *                  then Order::find($request->id) dereferenced with no null
 *                  check. The API-side status.cancel.
 *   changeProfile  DeliveryBoy::find($request->id), then writes name/email.
 *   logout         DeliveryBoy::find($request->user_id), then
 *                  $user->tokens()->delete() -- force any driver off the road.
 *   delete_user    DeliveryBoy::find($request->user_id), then $user->delete().
 *                  Irreversible.
 *   orderCancel    the instructive one. It had an ownership test whose BOTH
 *                  branches set delivered_status = 2 and saved; the test only
 *                  decided whether deliveryboy_id was also cleared. A control
 *                  in shape that authorised nothing -- which is why reading
 *                  found this and no scanner would have.
 */
class DeliveryAndOrderApiAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Store, 2: string}
     */
    private function tenantWithToken(): array
    {
        [$merchant, $store] = $this->makeTenant();

        return [$merchant, $store, $merchant->createToken('auth_token')->plainTextToken];
    }

    private function makeDriverIn(Store $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver ' . uniqid(),
            'email' => 'driver' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'contact' => '0000000',
            // delivery_boys.created_by is NOT nullable
            // (create_delivery_boys_table:22).
            'created_by' => $store->created_by,
            'store_id' => $store->id,
        ]);
    }

    private function bootstrapIds(): void
    {
        // The platform operator takes user id 1, matching a real install. See
        // AdminApiTenancyTest::twoTenants() for why this matters: a merchant
        // landing on id 1 makes its creatorId() equal every other merchant's
        // created_by and the tenancy predicate appears to pass across tenants.
        $this->makePlatformAdmin();
    }

    // ------------------------------------------------------- delivery boys

    public function test_another_tenants_driver_cannot_be_deleted(): void
    {
        $this->bootstrapIds();

        [, , $tokenA] = $this->tenantWithToken();
        [, $storeB] = $this->makeTenant();

        $victim = $this->makeDriverIn($storeB);

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/delete-user', ['user_id' => $victim->id]);

        $this->assertDatabaseHas(
            'delivery_boys',
            ['id' => $victim->id]
        );
    }

    public function test_the_owning_merchant_can_still_delete_their_own_driver(): void
    {
        $this->bootstrapIds();

        [, $storeA, $tokenA] = $this->tenantWithToken();

        $own = $this->makeDriverIn($storeA);

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/delete-user', ['user_id' => $own->id])
            ->assertStatus(200);

        $this->assertDatabaseMissing('delivery_boys', ['id' => $own->id]);
    }

    public function test_another_tenants_driver_profile_cannot_be_rewritten(): void
    {
        $this->bootstrapIds();

        [, , $tokenA] = $this->tenantWithToken();
        [, $storeB] = $this->makeTenant();

        $victim = $this->makeDriverIn($storeB);
        $originalEmail = $victim->email;

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/change-profile', [
                'id' => $victim->id,
                'name' => 'pwned',
                'email' => 'attacker@example.test',
            ]);

        $fresh = $victim->fresh();

        $this->assertSame($originalEmail, $fresh->email,
            'Another tenant\'s driver email was rewritten. Changing a login email is '
            . 'an account-takeover primitive via password reset.');
        $this->assertNotSame('pwned', $fresh->name);
    }

    public function test_the_owning_merchant_can_still_edit_their_own_driver(): void
    {
        $this->bootstrapIds();

        [, $storeA, $tokenA] = $this->tenantWithToken();

        $own = $this->makeDriverIn($storeA);

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/change-profile', [
                'id' => $own->id,
                'name' => 'Renamed Driver',
            ])->assertStatus(200);

        $this->assertSame('Renamed Driver', $own->fresh()->name);
    }

    /**
     * logout revoked tokens for any driver id -- a fleet-wide denial of
     * service from one body field.
     */
    public function test_another_tenants_driver_cannot_be_force_logged_out(): void
    {
        $this->bootstrapIds();

        [, , $tokenA] = $this->tenantWithToken();
        [, $storeB] = $this->makeTenant();

        $victim = $this->makeDriverIn($storeB);
        $victim->createToken('driver_token');

        $this->assertSame(1, $victim->tokens()->count(), 'fixture: the driver holds a token');

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/logOut', ['user_id' => $victim->id]);

        $this->assertSame(
            1,
            $victim->fresh()->tokens()->count(),
            'Another tenant\'s driver had their tokens revoked and would be thrown '
            . 'out of the driver app mid-delivery.'
        );
    }

    /**
     * A driver signing themselves out is the real flow and must still work --
     * on the `deliveryboy` guard, which is what custom.auth logs a driver
     * token into.
     */
    public function test_a_driver_can_still_log_themselves_out(): void
    {
        $this->bootstrapIds();

        [, $storeA] = $this->makeTenant();

        $driver = $this->makeDriverIn($storeA);
        $driverToken = $driver->createToken('driver_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $driverToken)
            ->postJson('/api/admin/logOut', ['user_id' => $driver->id])
            ->assertStatus(200);

        $this->assertSame(
            0,
            $driver->fresh()->tokens()->count(),
            'A driver could not sign themselves out; the self-service limb of '
            . 'resolveManageableDeliveryBoy() is broken.'
        );
    }

    // -------------------------------------------------------------- orders

    public function test_status_change_cannot_touch_another_tenants_order(): void
    {
        $this->bootstrapIds();

        [, , $tokenA] = $this->tenantWithToken();
        [, $storeB] = $this->makeTenant();

        $victimOrder = $this->makeOrder($storeB, ['delivered_status' => 0]);

        // 'delivered', not 1. Order::order_status_change compares the status
        // against STRING names ('cancel', 'confirmed', 'pickedup', 'shipped',
        // 'return', 'delivered', 'pre_order' -- app/Models/Order.php:539-626),
        // so an integer matches no branch and changes nothing. The first draft
        // of this suite sent 1 and the owner-still-works test failed for that
        // reason alone, reading exactly like the fix being too tight.
        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/status-change', [
                'id' => $victimOrder->id,
                'delivered' => 'delivered',
            ]);

        $this->assertSame(
            0,
            (int) $victimOrder->fresh()->delivered_status,
            'Another tenant\'s order was marked delivered through '
            . '/api/admin/status-change.'
        );
    }

    public function test_status_change_does_not_500_on_an_unknown_order_id(): void
    {
        $this->bootstrapIds();

        [, , $tokenA] = $this->tenantWithToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/status-change', ['id' => 999999, 'delivered' => 'delivered']);

        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'statusChange still dereferences Order::find() with no null check.'
        );
    }

    public function test_status_change_still_works_for_the_owning_merchant(): void
    {
        $this->bootstrapIds();

        [, $storeA, $tokenA] = $this->tenantWithToken();

        $own = $this->makeOrder($storeA, ['delivered_status' => 0]);

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/status-change', ['id' => $own->id, 'delivered' => 'delivered'])
            ->assertStatus(200);

        $this->assertSame(
            1,
            (int) $own->fresh()->delivered_status,
            'The owning merchant can no longer change their own order\'s status. '
            . 'The predicate is too tight -- that is a defect, not a fix.'
        );
    }

    /**
     * The decorative-branch one. Both limbs used to cancel.
     */
    public function test_order_cancel_cannot_cancel_another_tenants_order(): void
    {
        $this->bootstrapIds();

        [, , $tokenA] = $this->tenantWithToken();
        [, $storeB] = $this->makeTenant();

        $victimOrder = $this->makeOrder($storeB, ['delivered_status' => 0]);

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/admin/order-cancel', ['order_id' => $victimOrder->id]);

        $this->assertSame(
            0,
            (int) $victimOrder->fresh()->delivered_status,
            'An order in another tenant was cancelled. Both branches of the old '
            . 'ownership test cancelled, so the test authorised nothing.'
        );
    }

    /**
     * And the assigned driver cancelling their own delivery -- the flow the
     * deliveryboy_id reset exists for -- must still work, with the assignment
     * released exactly as before.
     */
    public function test_the_assigned_driver_can_still_cancel_their_own_delivery(): void
    {
        $this->bootstrapIds();

        [, $storeA] = $this->makeTenant();

        $driver = $this->makeDriverIn($storeA);
        $driverToken = $driver->createToken('driver_token')->plainTextToken;

        $order = $this->makeOrder($storeA, [
            'delivered_status' => 0,
            'deliveryboy_id' => $driver->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $driverToken)
            ->postJson('/api/admin/order-cancel', [
                'order_id' => $order->id,
                'user_id' => $driver->id,
            ])->assertStatus(200);

        $fresh = $order->fresh();

        $this->assertSame(2, (int) $fresh->delivered_status,
            'The assigned driver could not cancel their own delivery.');
        $this->assertNull($fresh->deliveryboy_id,
            'The assignment was not released. That reset is the only thing the old '
            . 'ownership test actually controlled and its behaviour must be kept.');
    }

    /**
     * A driver must NOT be able to cancel a delivery assigned to someone else,
     * which is the case the old code waved through its else branch.
     */
    public function test_a_driver_cannot_cancel_a_delivery_assigned_to_another_driver(): void
    {
        $this->bootstrapIds();

        [, $storeA] = $this->makeTenant();

        $attacker = $this->makeDriverIn($storeA);
        $assigned = $this->makeDriverIn($storeA);

        $attackerToken = $attacker->createToken('driver_token')->plainTextToken;

        $order = $this->makeOrder($storeA, [
            'delivered_status' => 0,
            'deliveryboy_id' => $assigned->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $attackerToken)
            ->postJson('/api/admin/order-cancel', [
                'order_id' => $order->id,
                'user_id' => $attacker->id,
            ]);

        $fresh = $order->fresh();

        $this->assertSame(0, (int) $fresh->delivered_status,
            'A driver cancelled a delivery assigned to a different driver.');
        $this->assertSame((int) $assigned->id, (int) $fresh->deliveryboy_id,
            'The other driver\'s assignment was cleared.');
    }

    /**
     * 403-not-401 does not apply to these methods -- they answer through the
     * existing not-found envelope rather than a new status -- but a denial must
     * still never be a 401, for the repo-B forced-logout reason. A VALID token
     * is sent, so a 401 could only come from the denial path.
     */
    public function test_a_denial_on_these_routes_is_never_a_401(): void
    {
        $this->bootstrapIds();

        [, , $tokenA] = $this->tenantWithToken();
        [, $storeB] = $this->makeTenant();

        $victimDriver = $this->makeDriverIn($storeB);
        $victimOrder = $this->makeOrder($storeB, ['delivered_status' => 0]);

        foreach ([
            ['/api/admin/delete-user', ['user_id' => $victimDriver->id]],
            ['/api/admin/logOut', ['user_id' => $victimDriver->id]],
            ['/api/admin/change-profile', ['id' => $victimDriver->id, 'name' => 'x']],
            ['/api/admin/status-change', ['id' => $victimOrder->id, 'delivered' => 'delivered']],
            ['/api/admin/order-cancel', ['order_id' => $victimOrder->id]],
        ] as [$uri, $payload]) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
                ->postJson($uri, $payload);

            $this->assertNotSame(401, $response->getStatusCode(),
                "{$uri} answered an authorization denial with 401. repo-B "
                . 'network.dart turns any 401 into a forced logout that clears '
                . 'preferences.');
        }
    }
}
