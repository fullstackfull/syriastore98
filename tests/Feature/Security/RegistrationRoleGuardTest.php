<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * NEW-registration-addrole-null-deref.
 *
 * Auth/RegisteredUserController::store did
 *   $role_r = Role::where('name', 'admin')->first();
 *   $user->addRole($role_r);
 * at BOTH of its two branches (the email-verification path and the plain path),
 * with no check on $role_r. Laratrust's addRole() takes a TYPED parameter --
 * array|string|int|Model|UuidInterface|BackedEnum, no null in the union
 * (vendor/santigarcor/laratrust/src/Traits/HasRolesAndPermissions.php:375-378)
 * -- so null is an immediate TypeError, not a quiet no-op.
 *
 * And it fires LATE: by then the User row, the Store row,
 * users.current_store, Auth::login() and defaultSetting() have all already
 * happened, none of it in a transaction. So the failure mode is not a clean
 * 500 -- it is a half-registered merchant with a live session and no role.
 */
class RegistrationRoleGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function registrationPayload(string $email): array
    {
        return [
            'name' => 'New Merchant',
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'store_name' => 'New Store ' . uniqid(),
        ];
    }

    /**
     * THE DEFECT. With no `admin` Role row -- the state of any install where
     * PermissionTableSeeder has not run, or where the role was renamed --
     * registration must still complete rather than TypeError after having
     * already written the user and the store.
     */
    public function test_registration_completes_when_no_admin_role_exists(): void
    {
        $this->assertSame(
            0,
            Role::where('name', 'admin')->count(),
            'fixture: this test is only meaningful with no admin role present.'
        );

        $email = 'merchant' . uniqid() . '@example.test';

        $response = $this->post('/register', $this->registrationPayload($email));

        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'Registration raised a TypeError from addRole(null) AFTER creating the '
            . 'user and the store, leaving a half-registered merchant.'
        );

        $user = User::where('email', $email)->first();

        $this->assertNotNull($user, 'The merchant row was not created.');
        $this->assertNotNull(
            Store::where('created_by', $user->id)->first(),
            'The merchant has no store, so registration did not finish.'
        );
        $this->assertNotNull(
            $user->current_store,
            'users.current_store was never written, so the tenant discriminator is '
            . 'unset and every store-scoped query would resolve to nothing.'
        );
    }

    /**
     * THE CONVERSE. With the role present -- the normal install -- the new
     * merchant must still actually RECEIVE it. A guard that skipped the grant
     * unconditionally would satisfy the test above and silently leave every
     * merchant permissionless.
     */
    public function test_registration_still_grants_the_admin_role_when_it_exists(): void
    {
        $adminRole = $this->makeGlobalAdminRole();

        $email = 'merchant' . uniqid() . '@example.test';

        $this->post('/register', $this->registrationPayload($email));

        $user = User::where('email', $email)->first();

        $this->assertNotNull($user, 'The merchant row was not created.');

        $user->flushCache();

        $this->assertTrue(
            $user->hasRole($adminRole->name),
            'The new merchant did not receive the admin role. The null guard must '
            . 'skip the grant ONLY when the role is genuinely absent.'
        );
    }

    /**
     * The guard must exist at BOTH call sites, not just the one the brief
     * named. The two branches of store() are near-duplicates and a fix applied
     * to one of them leaves the other live.
     */
    public function test_both_addrole_call_sites_are_guarded(): void
    {
        $source = file_get_contents(
            base_path('app/Http/Controllers/Auth/RegisteredUserController.php')
        );

        $this->assertSame(
            2,
            substr_count($source, '$user->addRole($role_r);'),
            'The two near-duplicate branches of store() should still have one '
            . 'addRole call each; if this number changed, re-read the method.'
        );

        $this->assertSame(
            2,
            substr_count($source, 'if ($role_r) {'),
            'Both addRole($role_r) call sites must sit behind a null check. '
            . 'RegisteredUserController::store has TWO near-identical branches '
            . '(the email-verification path and the plain path) and guarding only '
            . 'one leaves the other able to TypeError after the user and store rows '
            . 'are already written.'
        );
    }
}
