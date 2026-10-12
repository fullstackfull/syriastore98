<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * R4-03 + R2-05 -- the attacker-controlled tenant resolver.
 *
 * users.current_store is the discriminator getCurrentStore() returns
 * (app/Helper/helper.php:357). Both switch endpoints wrote it from an
 * unvalidated caller-supplied store id, so pointing it at a victim's store id
 * satisfied every manual `where('store_id', getCurrentStore())` filter in the
 * application as that victim.
 *
 * In every denial case the assertion that the discriminator is PROVABLY
 * UNCHANGED is load-bearing: a 403 alone would pass even if the write had
 * already happened before the check.
 */
class TenantSwitchTest extends SecurityTestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Web: GET /change-store/{id} -> StoreController::changeStore
    // ---------------------------------------------------------------

    public function test_user_cannot_switch_into_a_store_they_do_not_own(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $response = $this->actingAs($merchantA)->get("/change-store/{$storeB->id}");

        $response->assertForbidden();

        $this->assertSame(
            $storeA->id,
            $merchantA->fresh()->current_store,
            'current_store must be provably unchanged after a denied switch.'
        );
    }

    /**
     * THE POSITIVE CASE. This guards the Flutter store switcher and the web
     * store dropdown: a merchant with two stores must still be able to move
     * between them.
     */
    public function test_merchant_can_switch_between_their_own_stores(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($merchant);

        $this->actingAs($merchant)->get("/change-store/{$storeB->id}");

        $this->assertSame($storeB->id, $merchant->fresh()->current_store);
        $this->assertNotSame($storeA->id, $merchant->fresh()->current_store);
    }

    /**
     * Staff resolve through creatorId(), so they must keep access to their
     * employer's stores or the fix would lock them out of their own job.
     */
    public function test_staff_can_switch_between_their_employers_stores(): void
    {
        [$owner] = $this->makeTenant();
        $secondStore = $this->makeStore($owner);
        $staff = $this->makeStaff($owner);

        $this->actingAs($staff)->get("/change-store/{$secondStore->id}");

        $this->assertSame($secondStore->id, $staff->fresh()->current_store);
    }

    public function test_staff_cannot_switch_into_another_tenants_store(): void
    {
        [$owner] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $original = $staff->fresh()->current_store;

        [, $foreignStore] = $this->makeTenant();

        $this->actingAs($staff)
            ->get("/change-store/{$foreignStore->id}")
            ->assertForbidden();

        $this->assertSame($original, $staff->fresh()->current_store);
    }

    /**
     * The original guard was `if ($store && $store->is_active = 1)` -- an
     * ASSIGNMENT, so it always evaluated truthy, the is_active check was dead
     * code and the "Store is locked" branch was unreachable.
     */
    public function test_inactive_owned_store_is_refused_and_reports_locked(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $locked = $this->makeStore($merchant, ['is_active' => 0]);

        $this->actingAs($merchant)
            ->get("/change-store/{$locked->id}")
            ->assertSessionHas('error');

        $this->assertSame($storeA->id, $merchant->fresh()->current_store);
        $this->assertSame(0, (int) $locked->fresh()->is_active);
    }

    public function test_anonymous_cannot_switch_store(): void
    {
        [, $store] = $this->makeTenant();

        $response = $this->get("/change-store/{$store->id}");

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
    }

    // ---------------------------------------------------------------
    // API: POST /api/admin/changestore -> DashboardController::ChangeStore
    // ---------------------------------------------------------------

    public function test_api_changestore_rejects_a_foreign_store_id(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $token = $merchantA->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/changestore', ['store_id' => $storeB->id]);

        // 403, NOT 401: repo-B lib/network/network.dart:285-288 maps any 401 to
        // _handleUnauthorized(), which clears prefs and forces a re-login, so a
        // 401 here would log the merchant out of the shipped app on every
        // tenancy denial.
        $response->assertStatus(403);

        $this->assertSame($storeA->id, $merchantA->fresh()->current_store);
    }

    public function test_api_changestore_allows_an_owned_store(): void
    {
        [$merchant] = $this->makeTenant();
        $second = $this->makeStore($merchant);

        $token = $merchant->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/changestore', ['store_id' => $second->id])
            ->assertStatus(200);

        $this->assertSame($second->id, $merchant->fresh()->current_store);
    }

    public function test_api_changestore_rejects_a_non_integer_store_id(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $token = $merchant->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/changestore', ['store_id' => 'not-an-integer']);

        $this->assertSame($storeA->id, $merchant->fresh()->current_store);
    }

    public function test_api_changestore_rejects_anonymous_without_a_500(): void
    {
        [, $store] = $this->makeTenant();

        $response = $this->postJson('/api/admin/changestore', ['store_id' => $store->id]);

        $response->assertStatus(401);
        $this->assertNotSame(500, $response->getStatusCode());
    }
}
