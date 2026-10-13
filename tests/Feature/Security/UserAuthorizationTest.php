<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/**
 * SEC-F4 (mass assignment + cross-tenant user edit), SEC-F1 (password-reset
 * takeover) and NEW-audit-oldvalues.
 *
 * The password assertions are load-bearing in a specific way: User.php casts
 * 'password' => 'hashed', so fill(['password' => 'x']) stored a WORKING
 * credential. A status-code-only assertion would pass even if the write had
 * happened.
 */
class UserAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // SEC-F4
    // ---------------------------------------------------------------

    public function test_merchant_cannot_edit_a_user_in_another_tenant(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit User');

        [$victimOwner] = $this->makeTenant();
        $victim = $this->makeStaff($victimOwner, ['name' => 'Original Name']);

        $this->actingAs($attacker)->put("/users/{$victim->id}", [
            'name' => 'Pwned',
            'email' => $victim->email,
        ])->assertForbidden();

        $this->assertSame('Original Name', $victim->fresh()->name);
    }

    public function test_type_is_not_mass_assignable(): void
    {
        $this->requireTable('audit_logs');

        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit User');
        $staff = $this->makeStaff($owner);

        $this->actingAs($owner)->put("/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'type' => 'super admin',
        ]);

        $this->assertNotSame('super admin', $staff->fresh()->type);
    }

    /**
     * THE LIMB PHASE 0 MISSED.
     */
    public function test_password_is_not_mass_assignable(): void
    {
        $this->requireTable('audit_logs');

        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit User');
        $staff = $this->makeStaff($owner);

        $this->actingAs($owner)->put("/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'password' => 'attacker-chosen-password',
        ]);

        $this->assertFalse(
            Hash::check('attacker-chosen-password', $staff->fresh()->password),
            'The user-edit form must not be able to set a working password.'
        );
    }

    public function test_created_by_is_not_mass_assignable(): void
    {
        $this->requireTable('audit_logs');

        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit User');
        $staff = $this->makeStaff($owner);
        $originalCreatedBy = $staff->created_by;

        $this->actingAs($owner)->put("/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'created_by' => 1,
        ]);

        $this->assertSame((int) $originalCreatedBy, (int) $staff->fresh()->created_by);
    }

    /**
     * The role lookup was Role::find($request->role) -- unscoped -- so a
     * merchant could attach any role on the platform and, via the server-side
     * type derivation, name themselves anything.
     */
    public function test_role_outside_the_store_is_rejected(): void
    {
        $this->requireTable('audit_logs');

        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit User');
        $staff = $this->makeStaff($owner);

        [$foreignOwner, $foreignStore] = $this->makeTenant();
        $foreignRole = $this->makeRole($foreignOwner, $foreignStore, 'foreign-role');

        $this->actingAs($owner)->put("/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => $foreignRole->id,
        ]);

        $this->assertNotSame('foreign-role', $staff->fresh()->type);
    }

    /**
     * NEW-audit-oldvalues: $oldValues was passed to AuditLog::record while
     * being defined six lines AFTER the call, and the parameter is typed
     * `array`, so the call was a TypeError -- the privileged write at
     * fill()->save() landed and then the request 500'd.
     */
    public function test_user_update_succeeds_and_records_previous_values(): void
    {
        $this->requireTable('audit_logs');

        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit User');
        $staff = $this->makeStaff($owner, ['name' => 'Before']);

        $response = $this->actingAs($owner)->put("/users/{$staff->id}", [
            'name' => 'After',
            'email' => $staff->email,
        ]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame('After', $staff->fresh()->name);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_updated',
            'subject_id' => $staff->id,
        ]);

        $log = \App\Models\AuditLog::where('action', 'user_updated')
            ->where('subject_id', $staff->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log->old_values);
        $this->assertSame('Before', $log->old_values['name']);
    }

    // ---------------------------------------------------------------
    // SEC-F1
    // ---------------------------------------------------------------

    /**
     * NOTE ON ROUTE COVERAGE. UserController::reset() and
     * UserController::updatePassword() are hardened by this change too, but
     * they are NOT exercised here because they are currently UNROUTABLE:
     * resources/views/users/reset.blade.php:1 posts to the route name
     * `users.resetpassword`, and that name is registered nowhere in routes/ or
     * packages/ (reported separately as NEW-user-reset-route-missing). The
     * reachable password-reset takeover is the StoreController pair below,
     * which is what SEC-F1 actually names.
     */
    public function test_merchant_cannot_reset_another_tenants_password(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Reset Password');

        $platformAdmin = $this->makePlatformAdmin();

        $this->actingAs($attacker)
            ->post(route('stores.reset.password.update', $platformAdmin->id), [
                'password' => 'attacker-chosen-password',
                'password_confirmation' => 'attacker-chosen-password',
            ])
            ->assertForbidden();

        // The password-unchanged assertion is load-bearing: a 403 alone would
        // pass even if the forceFill had happened before the check.
        $this->assertFalse(
            Hash::check('attacker-chosen-password', $platformAdmin->fresh()->password),
            'A denied reset must not have written a working credential.'
        );
    }

    public function test_merchant_cannot_reset_a_foreign_tenants_staff_password(): void
    {
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Reset Password');

        [$victimOwner] = $this->makeTenant();
        $victimStaff = $this->makeStaff($victimOwner);

        $this->actingAs($attacker)
            ->post(route('stores.reset.password.update', $victimStaff->id), [
                'password' => 'attacker-chosen-password',
                'password_confirmation' => 'attacker-chosen-password',
            ])
            ->assertForbidden();

        $this->assertFalse(
            Hash::check('attacker-chosen-password', $victimStaff->fresh()->password)
        );
    }

    public function test_anonymous_cannot_reset_any_password(): void
    {
        $platformAdmin = $this->makePlatformAdmin();

        $this->post(route('stores.reset.password.update', $platformAdmin->id), [
            'password' => 'attacker-chosen-password',
            'password_confirmation' => 'attacker-chosen-password',
        ]);

        $this->assertFalse(
            Hash::check('attacker-chosen-password', $platformAdmin->fresh()->password)
        );
    }

    /**
     * THE POSITIVE CASE: a platform admin resetting a merchant's password is a
     * real support workflow and must keep working.
     */
    public function test_platform_admin_can_still_reset_a_merchant_password(): void
    {
        $this->requireTable('audit_logs');

        $platformAdmin = $this->makePlatformAdmin();
        [$merchant] = $this->makeTenant();

        $this->actingAs($platformAdmin)
            ->post(route('stores.reset.password.update', $merchant->id), [
                'password' => 'a-new-valid-password',
                'password_confirmation' => 'a-new-valid-password',
            ]);

        $this->assertTrue(
            Hash::check('a-new-valid-password', $merchant->fresh()->password),
            'The platform admin support workflow must not be broken by the fix.'
        );
    }

    /**
     * A merchant resetting their OWN staff member's password is legitimate.
     */
    public function test_merchant_can_reset_their_own_staff_password(): void
    {
        $this->requireTable('audit_logs');

        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Reset Password');
        $staff = $this->makeStaff($owner);

        $this->actingAs($owner)
            ->post(route('stores.reset.password.update', $staff->id), [
                'password' => 'a-new-valid-password',
                'password_confirmation' => 'a-new-valid-password',
            ]);

        $this->assertTrue(
            Hash::check('a-new-valid-password', $staff->fresh()->password)
        );
    }
}
