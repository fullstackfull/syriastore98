<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * SEC-F3 -- global role hijack and the users.type rewrite.
 *
 * The worst exploit needed no knowledge of the super-admin role id. Every
 * self-registered merchant is attached to ONE SHARED GLOBAL `admin` role, and
 * RoleController::update contained:
 *
 *   $userIds = DB::table('role_user')->where('role_id', $role->id)
 *       ->pluck('user_id')->toArray();
 *   User::whereIn('id', $userIds)->update(['type' => $input['name']]);
 *
 * So editing one's OWN role and submitting name='super admin' promoted EVERY
 * merchant on the platform in a single request.
 */
class RoleEscalationTest extends SecurityTestCase
{
    use RefreshDatabase;


    /**
     * RoleController validates `'permissions' => 'required'`, and an EMPTY
     * array fails `required`. So every role-update post here must carry at
     * least one real permission id, or the test would pass for the wrong
     * reason -- validation rejecting the request rather than authorization.
     */
    private function benignPermissionId(): int
    {
        return Permission::firstOrCreate(
            ['name' => 'Manage Order'],
            ['display_name' => 'Manage Order']
        )->id;
    }

    /**
     * THE KEY TEST. Even for a role the caller legitimately owns, a rename must
     * never rewrite users.type.
     */
    public function test_role_rename_does_not_rewrite_user_type(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Role');

        $ownRole = $this->makeRole($merchant, $store, 'shop-manager');
        $merchant->addRole($ownRole);

        $platformAdmin = $this->makePlatformAdmin();
        $otherMerchant = $this->makeMerchant();
        $otherMerchant->addRole($ownRole);

        $this->actingAs($merchant)->put("/roles/{$ownRole->id}", [
            'name' => 'renamed-role',
            'permissions' => [$this->benignPermissionId()],
        ]);

        // No user's type may have changed, whoever shares the role.
        $this->assertSame('admin', $merchant->fresh()->type);
        $this->assertSame('admin', $otherMerchant->fresh()->type);
        $this->assertSame('super admin', $platformAdmin->fresh()->type);
    }

    /**
     * THE ASSERTION THAT WOULD HAVE CAUGHT THE PRIMITIVE.
     */
    public function test_super_admin_type_survives_a_role_update(): void
    {
        [$attacker, $store] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Role');

        $platformAdmin = $this->makePlatformAdmin();

        $ownRole = $this->makeRole($attacker, $store, 'escalate-me');
        $platformAdmin->addRole($ownRole);

        $this->actingAs($attacker)->put("/roles/{$ownRole->id}", [
            'name' => 'super admin',
            'permissions' => [$this->benignPermissionId()],
        ]);

        $this->assertSame('super admin', $platformAdmin->fresh()->type);
        $this->assertNotSame('super admin', $attacker->fresh()->type);
    }

    public function test_reserved_role_name_is_rejected(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Role');
        $ownRole = $this->makeRole($merchant, $store, 'shop-manager');

        $this->actingAs($merchant)->put("/roles/{$ownRole->id}", [
            'name' => 'super admin',
            'permissions' => [$this->benignPermissionId()],
        ]);

        $this->assertSame('shop-manager', $ownRole->fresh()->name);
    }

    /**
     * The store_id-theft assertion is the one that matters: the old code did
     * `$input['store_id'] = getCurrentStore(); $role->fill($input)->save();`
     * with Role::$guarded = [], so a victim role was STOLEN into the attacker's
     * store.
     */
    public function test_merchant_cannot_edit_a_role_outside_their_own_store(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Role');

        [$victimOwner, $victimStore] = $this->makeTenant();
        $victimRole = $this->makeRole($victimOwner, $victimStore, 'victim-role');

        $this->actingAs($attacker)
            ->put("/roles/{$victimRole->id}", [
                'name' => 'stolen-role',
                'permissions' => [$this->benignPermissionId()],
            ])
            ->assertForbidden();

        $this->assertSame('victim-role', $victimRole->fresh()->name);
        $this->assertSame(
            $victimStore->id,
            (int) $victimRole->fresh()->store_id,
            'The victim role must not have been stolen into the attacker store.'
        );
    }

    /**
     * The shared global `admin` role has a null store_id and is shared by every
     * self-registered merchant, so it must never be editable through a
     * per-tenant screen.
     */
    public function test_merchant_cannot_edit_the_shared_global_admin_role(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Role');

        $globalRole = $this->makeGlobalAdminRole();

        $this->actingAs($attacker)
            ->put("/roles/{$globalRole->id}", [
                'name' => 'super admin',
                'permissions' => [$this->benignPermissionId()],
            ])
            ->assertForbidden();

        $this->assertSame('admin', $globalRole->fresh()->name);
        $this->assertNull($globalRole->fresh()->store_id);
    }

    /**
     * config/superadminaccess.php was enforced only in Blade, so a crafted POST
     * granted platform-operator permissions to any role.
     */
    public function test_role_update_cannot_grant_superadminaccess_permissions(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Role');
        $ownRole = $this->makeRole($merchant, $store, 'shop-manager');

        $restrictedName = config('superadminaccess')[0] ?? 'Create Plan';
        $restricted = Permission::firstOrCreate(
            ['name' => $restrictedName],
            ['display_name' => $restrictedName, 'guard_name' => 'web']
        );

        $this->actingAs($merchant)->put("/roles/{$ownRole->id}", [
            'name' => 'shop-manager',
            'permissions' => [$restricted->id],
        ]);

        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $ownRole->id,
            'permission_id' => $restricted->id,
        ]);
    }

    /**
     * THE SIBLING ENDPOINT, which the server-side filter was never applied to.
     *
     * SEC-F3 reported the superadminaccess escalation as closed because
     * RoleController::update() filters the posted permission ids server-side
     * (:240). RoleController::store() did not: it took $request['permissions']
     * raw and called givePermission() on each id in a loop, so the escalation
     * stayed fully open through role CREATION.
     *
     * The route is reachable by any merchant: public self-registration grants
     * type=admin and the shared admin role carries 'Create Role'
     * (Utility.php:2871). Nothing but the Blade template hid the restricted
     * permissions, and a template hides nothing from a crafted POST.
     */
    public function test_role_create_cannot_grant_superadminaccess_permissions(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');

        $restrictedName = config('superadminaccess')[0] ?? 'Create Plan';
        $restricted = Permission::firstOrCreate(
            ['name' => $restrictedName],
            ['display_name' => $restrictedName, 'guard_name' => 'web']
        );

        $this->actingAs($merchant)->post('/roles', [
            'name' => 'escalated-role',
            'permissions' => [$restricted->id],
        ]);

        $created = Role::where('name', 'escalated-role')->first();

        if ($created !== null) {
            // No third argument: assertDatabaseMissing's third parameter is the
            // CONNECTION name, not a message, so passing prose there makes Laravel
            // look for a connection by that name.
            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $created->id,
                'permission_id' => $restricted->id,
            ]);
        }
    }

    /** The positive case: an ordinary permission is still grantable on create. */
    public function test_role_create_still_grants_an_ordinary_permission(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');

        $ordinary = Permission::firstOrCreate(
            ['name' => 'Manage Order'],
            ['display_name' => 'Manage Order', 'guard_name' => 'web']
        );

        $this->actingAs($merchant)->post('/roles', [
            'name' => 'ordinary-role',
            'permissions' => [$ordinary->id],
        ]);

        $created = Role::where('name', 'ordinary-role')->first();
        $this->assertNotNull($created, 'role creation must still work');
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $created->id,
            'permission_id' => $ordinary->id,
        ]);
    }

    public function test_merchant_cannot_delete_a_role_outside_their_own_store(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Role');

        [$victimOwner, $victimStore] = $this->makeTenant();
        $victimRole = $this->makeRole($victimOwner, $victimStore, 'victim-role');

        $this->actingAs($attacker)
            ->delete("/roles/{$victimRole->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $victimRole->id]);
    }

    /**
     * THE POSITIVE CASE: a merchant must still be able to rename their own role.
     */
    public function test_merchant_can_still_rename_their_own_role(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Role');
        $ownRole = $this->makeRole($merchant, $store, 'shop-manager');

        $this->actingAs($merchant)->put("/roles/{$ownRole->id}", [
            'name' => 'floor-manager',
            'permissions' => [$this->benignPermissionId()],
        ]);

        $this->assertSame('floor-manager', $ownRole->fresh()->name);
        $this->assertSame($store->id, (int) $ownRole->fresh()->store_id);
    }

    // ---------------------------------------------------------------
    // NEW-rolepolicy-grantpermission-dead-code
    // ---------------------------------------------------------------

    /**
     * RolePolicy::grantPermission() was called from NOWHERE. `grep -rn
     * "grantPermission" app/` found the policy declaration and no call site,
     * while RoleController::store() and ::update() each re-implemented the same
     * rule inline in filterGrantablePermissions().
     *
     * That is worse than either having the control or not having it: a reviewer
     * asking "is privilege escalation gated?" finds a correct-looking policy
     * method and stops, while the two live paths are free to drift from it --
     * which is the exact shape of the original defect, where update() filtered
     * server-side and store() did not.
     *
     * filterGrantablePermissions() now asks the policy, through the Gate, so
     * there is ONE decision point for both paths. This test invokes the policy
     * directly: if it ever goes dead again, this is the test that keeps it
     * honest about what it is for.
     */
    public function test_the_grant_permission_policy_decides_and_is_reachable_through_the_gate(): void
    {
        [$merchant] = $this->makeTenant();
        $super = $this->makePlatformAdmin();

        $restrictedName = config('superadminaccess')[0] ?? 'Create Plan';

        $policy = new \App\Policies\RolePolicy();

        // The policy's own answers.
        $this->assertFalse(
            $policy->grantPermission($merchant, $restrictedName),
            'A merchant must not be able to grant a platform-operator permission.'
        );
        $this->assertTrue(
            $policy->grantPermission($merchant, 'Manage Order'),
            'An ordinary permission must stay grantable.'
        );
        $this->assertTrue(
            $policy->grantPermission($super, $restrictedName),
            'A platform admin may grant anything.'
        );

        // And the SAME answers through the Gate, which is how the controller
        // reaches it. Gate::callPolicyMethod drops a leading class-string
        // argument (Gate.php:802-809), so this resolves to
        // RolePolicy::grantPermission($user, $name). Asserted by execution
        // rather than inferred from the framework source.
        $this->assertFalse(
            $merchant->can('grantPermission', [Role::class, $restrictedName]),
            'The Gate must reach RolePolicy::grantPermission. If this is false for every '
            . 'name the controller would grant NOTHING; if it is true for the restricted '
            . 'name the policy is not being consulted at all.'
        );
        $this->assertTrue(
            $merchant->can('grantPermission', [Role::class, 'Manage Order'])
        );
        $this->assertTrue(
            $super->can('grantPermission', [Role::class, $restrictedName])
        );
    }

    /**
     * The platform-operator branch, end to end: a super admin creating a role
     * with a restricted permission must still succeed. Without this, routing
     * store() through the policy could silently have become "nobody may grant
     * the superadminaccess permissions", locking the operator out of their own
     * plan and currency screens.
     */
    public function test_a_platform_admin_can_still_grant_a_restricted_permission(): void
    {
        $super = $this->makePlatformAdmin();
        [, $store] = $this->makeTenant();
        $super->current_store = $store->id;
        $super->save();

        $this->grantPermission($super, 'Create Role');

        $restrictedName = config('superadminaccess')[0] ?? 'Create Plan';
        $restricted = Permission::firstOrCreate(
            ['name' => $restrictedName],
            ['display_name' => $restrictedName, 'guard_name' => 'web']
        );

        $this->actingAs($super->fresh())->post('/roles', [
            'name' => 'operator-role',
            'permissions' => [$restricted->id],
        ]);

        $created = Role::where('name', 'operator-role')->first();

        $this->assertNotNull($created, 'A platform admin must still be able to create a role.');
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $created->id,
            'permission_id' => $restricted->id,
        ]);
    }

    /**
     * A permission id with no matching row is dropped rather than passed
     * through. store() used to reach firstOrFail() on it (a 500) and update()
     * used to syncPermissions() a dangling id.
     */
    public function test_an_unknown_permission_id_is_dropped_rather_than_passed_through(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');

        $ordinary = Permission::firstOrCreate(
            ['name' => 'Manage Order'],
            ['display_name' => 'Manage Order', 'guard_name' => 'web']
        );

        $unknownId = (int) Permission::max('id') + 9999;

        $this->actingAs($merchant->fresh())->post('/roles', [
            'name' => 'mixed-role',
            'permissions' => [$ordinary->id, $unknownId],
        ]);

        $created = Role::where('name', 'mixed-role')->first();

        $this->assertNotNull($created, 'The valid half of the request must still be honoured.');
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $created->id,
            'permission_id' => $ordinary->id,
        ]);
        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $created->id,
            'permission_id' => $unknownId,
        ]);
    }

    // ---------------------------------------------------------------
    // NEW-ROLE-FORM-UNGATED-01 -- the two role SCREENS, which had no gate at all
    // ---------------------------------------------------------------

    /**
     * RoleController::create() carried no authorization of any kind, and the view
     * it renders lists `Permission::all()` -- every permission name on the
     * platform, the config/superadminaccess.php operator names included. store()
     * has required 'Create Role' since the beginning, so the write was gated and
     * the screen was not: the same sibling-endpoint asymmetry as SEC-F3.
     */
    public function test_the_role_creation_screen_is_denied_without_the_create_role_permission(): void
    {
        [$merchant] = $this->makeTenant();

        // Deliberately NO grantPermission() call: a self-registered merchant who
        // has not been given 'Create Role'.
        $this->assertFalse(
            $merchant->isAbleTo('Create Role'),
            'Fixture check: this test is meaningless if the actor already holds the '
            . 'permission.'
        );

        $this->actingAs($merchant)->get('/roles/create')->assertForbidden();
    }

    /**
     * THE POSITIVE DIRECTION. The gate must not have closed the screen for the
     * people whose screen it is -- a storefront-breaking control has shipped twice
     * on this codebase.
     */
    public function test_the_role_creation_screen_still_opens_for_a_holder_of_create_role(): void
    {
        // A platform operator must EXIST for any view rendering the shared layout:
        // app/Helper/helper.php:143-145 reads ->current_store off
        // User::where('type','super admin')->first() with no null guard
        // (NEW-NULLGUARD-01, another agent's file). Every real install has one, so
        // this fixture matches production rather than asserting around the defect.
        $this->makePlatformAdmin();

        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');

        $response = $this->actingAs($merchant->fresh())->get('/roles/create');

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'The new gate has locked the role-creation screen for a user who holds '
            . "'Create Role'."
        );
        $response->assertOk();
    }

    /**
     * The IDOR half. edit() had no authorization and no ownership predicate, so
     * any authenticated user could open the update form for ANY role id on the
     * platform and read its name back out of the rendered view. update() and
     * destroy() already authorized; the form that leads to update() did not.
     */
    public function test_a_merchant_cannot_open_the_edit_form_for_another_stores_role(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Role');

        [$victimOwner, $victimStore] = $this->makeTenant();
        $victimRole = $this->makeRole($victimOwner, $victimStore, 'victim-role-form');

        $response = $this->actingAs($attacker->fresh())->get("/roles/{$victimRole->id}/edit");

        $response->assertForbidden();

        $this->assertStringNotContainsString(
            'victim-role-form',
            $response->getContent(),
            'The denial must not still disclose the victim role name.'
        );
    }

    public function test_a_merchant_cannot_open_the_edit_form_for_the_shared_global_admin_role(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Role');

        $globalRole = $this->makeGlobalAdminRole();

        $this->actingAs($attacker->fresh())
            ->get("/roles/{$globalRole->id}/edit")
            ->assertForbidden();
    }

    /**
     * THE POSITIVE DIRECTION for edit(): a merchant must still be able to open the
     * form for a role they own, or role administration is broken outright.
     */
    public function test_a_merchant_can_still_open_the_edit_form_for_their_own_role(): void
    {
        $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Role');

        $ownRole = $this->makeRole($merchant, $store, 'own-role-form');

        $response = $this->actingAs($merchant->fresh())->get("/roles/{$ownRole->id}/edit");

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'The new gate has locked a merchant out of their own role edit form. '
            . 'RolePolicy::ownsRole requires roles.created_by == creatorId() AND '
            . 'roles.store_id == getCurrentStore(), which is exactly what update() '
            . 'already enforced on the write.'
        );
        $response->assertOk();
    }
}
