<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P1-SEC-67c -- TWO MORE PLACES THAT LISTED THE OPERATOR'S STORES TO A PRINCIPAL ROOTED AT THE OPERATOR.
 * ============================================================================================
 *
 * Found by the fourth independent review (of P1-SEC-67b), lens "blast radius"; both are LOW and read-only. Both ask `creatorId()` for "the stores of my
 * tenant", and for a user whose `created_by` is the platform operator -- a merchant who demoted their own `type` through `PUT /users/{self}`, or staff the
 * operator created -- that is the OPERATOR'S id:
 *
 *   User::switchableStores()               the workspace chip that renders on every admin page lists the operator's active stores, and offers a control
 *                                          `StoreController::changeStore()` has refused since P1-SEC-66. Its docblock says it "mirrors that handler's
 *                                          predicate exactly"; that stopped being true when the handler changed.
 *   UserController::UserUnable, `user`    returns `storeUserCounter($target->creatorId())`: the operator's store NAMES and per-store staff counts, to a
 *                                          caller toggling their own row (the `store` branch of the same method was fixed in P1-SEC-66).
 *
 * The operator keeps both: it is their tenant, and `changeStore()` admits them first. The demotion is done THROUGH THE PRODUCT, with its premise asserted.
 */
class OperatorRootedStoreListingsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $operator;
    private Store $operatorStore;
    private Store $operatorSecondStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        $this->assertSame(1, (int) $this->operator->id, 'premise: the operator is user 1, the id every merchant has as created_by');
        // TWO active stores, so that the chip's dropdown (which needs more than one entry) would be offered
        $this->operatorStore = $this->makeStore($this->operator, ['name' => 'Platform Store One']);
        $this->operatorSecondStore = $this->makeStore($this->operator, ['name' => 'Platform Store Two']);
        $this->operator->current_store = $this->operatorStore->id;
        $this->operator->save();
        $this->operator = $this->operator->fresh();
    }

    private function selfDemotedMerchant(): User
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit User');
        $role = Role::forceCreate([
            'name' => 'Manager', 'display_name' => 'Manager', 'guard_name' => 'web',
            'created_by' => $merchant->creatorId(), 'store_id' => $merchant->current_store,
        ]);

        $this->actingAs($merchant)->put("/users/{$merchant->id}", ['name' => $merchant->name, 'email' => $merchant->email, 'role' => $role->id]);

        $fresh = $merchant->fresh();
        $this->assertSame('Manager', $fresh->type, 'the self-demotion no longer happens, so these cases cannot reach the state they exist to test');
        $this->assertSame(1, (int) $fresh->creatorId(), "the principal's tenant root is no longer the platform, so the collapse is not being reproduced");

        return $fresh;
    }

    /** @return list<string> */
    private function names(\Illuminate\Support\Collection $stores): array
    {
        $names = $stores->pluck('name')->all();
        sort($names);

        return $names;
    }

    // ---- User::switchableStores() ----------------------------------------------------------------------------------------------------

    public function test_the_workspace_chip_lists_no_operator_store_to_a_self_demoted_merchant(): void
    {
        $demoted = $this->selfDemotedMerchant();

        $this->assertSame([], $this->names($demoted->switchableStores()), "The workspace chip's list holds the OPERATOR'S stores for a self-demoted merchant.");
    }

    public function test_the_workspace_chip_lists_no_operator_store_to_staff_the_operator_created(): void
    {
        $operatorsStaff = User::factory()->create(['type' => 'staff', 'created_by' => $this->operator->id, 'current_store' => $this->operatorStore->id, 'is_active' => 1, 'email_verified_at' => now()]);

        $this->assertSame([], $this->names($operatorsStaff->fresh()->switchableStores()), 'Staff the operator created were offered the operator\'s stores.');
    }

    /**
     * THE RULE, NOT THE OPERATOR: staff of a self-demoted merchant are rooted at a user who is no longer a merchant (their `created_by` is a `Manager`, id other
     * than 1). A variant that asked only "is the root the OPERATOR" would offer them the demoted merchant's stores in the chip while `changeStore()` refuses.
     */
    public function test_the_workspace_chip_lists_no_store_to_staff_of_a_self_demoted_merchant(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $staff = $this->makeStaff($demoted);

        $this->assertSame([], $this->names($staff->fresh()->switchableStores()), 'Staff of a self-demoted merchant were offered stores changeStore() refuses them.');
    }

    public function test_the_workspace_chip_still_lists_what_it_always_did(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $second = $this->makeStore($merchant, ['name' => 'Merchant Store Two']);
        $inactive = $this->makeStore($merchant, ['name' => 'Merchant Inactive Store', 'is_active' => 0]);
        $staff = $this->makeStaff($merchant);

        $expected = [$store->name, 'Merchant Store Two'];
        sort($expected);

        $this->assertSame($expected, $this->names($merchant->fresh()->switchableStores()), "Premise: a merchant is offered their own ACTIVE stores (not the inactive {$inactive->name}).");
        $this->assertSame($expected, $this->names($staff->fresh()->switchableStores()), "Premise: a merchant's staff are offered the merchant's active stores.");
        $this->assertSame(['Platform Store One', 'Platform Store Two'], $this->names($this->operator->fresh()->switchableStores()), 'Premise: the operator keeps the list of their own stores.');
    }

    // ---- UserController::UserUnable, `user` branch -----------------------------------------------------------------------------------

    private function unable(User $actor, User $target, string $label = 'caller'): array
    {
        $response = $this->actingAs($actor)->postJson('/user-unable', ['name' => 'user', 'id' => $target->id, 'owner_id' => $target->id, 'is_active' => 1]);
        $this->assertSame(200, $response->status(), "Premise: {$label} can toggle this user's login. Body: ".$response->getContent());

        return (array) $response->json();
    }

    public function test_a_self_demoted_merchant_is_not_handed_the_operators_store_counters(): void
    {
        $demoted = $this->selfDemotedMerchant();

        $json = $this->unable($demoted->fresh(), $demoted, 'a self-demoted merchant');

        $this->assertArrayHasKey('success', $json, 'Premise: the demoted merchant reaches the toggle and it is authorised for their own row, so the counters below are the thing under test.');
        $this->assertSame([], (array) ($json['users_data'] ?? []), "UserUnable's `user` branch returned the OPERATOR'S per-store staff counts (keyed by store name) to a self-demoted merchant.");
        // the AGGREGATE totals leak the same tenant's store count: every key of the structure, not only the one that carries names
        $this->assertSame(0, (int) ($json['store_data']['total_store'] ?? -1), "UserUnable's `user` branch returned the operator's store TOTAL.");
        $this->assertSame(0, (int) ($json['store_data']['active_store'] ?? -1), "UserUnable's `user` branch returned the operator's ACTIVE store count.");
        $this->assertSame(0, (int) ($json['store_data']['disable_store'] ?? -1), "UserUnable's `user` branch returned the operator's DISABLED store count.");
        $this->assertStringNotContainsString('Platform Store', json_encode($json), "UserUnable's `user` branch printed the operator's store names to a self-demoted merchant.");
    }

    public function test_staff_of_a_self_demoted_merchant_are_not_handed_their_employers_counters(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $staff = $this->makeStaff($demoted);
        $this->grantPermission($staff, 'Edit User');

        $json = $this->unable($staff->fresh(), $staff, 'staff of a self-demoted merchant');

        $this->assertSame([], (array) ($json['users_data'] ?? []), 'A non-merchant root (not the operator) was handed its per-store counters.');
        $this->assertSame(0, (int) ($json['store_data']['total_store'] ?? -1), 'A non-merchant root was handed its store total.');
    }

    public function test_the_counters_are_still_returned_to_the_callers_that_always_had_them(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit User');
        $staff = $this->makeStaff($merchant);

        // a merchant toggling their staff: the merchant's own store, keyed by name
        $json = $this->unable($merchant->fresh(), $staff, 'a merchant');
        $this->assertArrayHasKey($store->name, (array) $json['users_data'], "Premise: a merchant is returned their own stores' counters.");

        // the operator toggling staff they created: their own stores, as before. (Laratrust has no operator bypass: the operator needs `Edit User` too.)
        $this->grantPermission($this->operator, 'Edit User');
        $operatorsStaff = User::factory()->create(['type' => 'staff', 'created_by' => $this->operator->id, 'current_store' => $this->operatorStore->id, 'is_active' => 1, 'email_verified_at' => now()]);
        $json = $this->unable($this->operator->fresh(), $operatorsStaff->fresh(), 'the operator');
        $this->assertArrayHasKey('Platform Store One', (array) $json['users_data'], 'Premise: the operator keeps the counters for their own stores.');
    }
}
