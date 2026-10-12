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
            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $created->id,
                'permission_id' => $restricted->id,
            ], 'A merchant must not be able to mint a role holding a platform-operator permission.');
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
}
