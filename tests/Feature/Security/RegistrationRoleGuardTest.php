<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StripsSourceComments;

/**
 * NEW-registration-addrole-null-deref -- AND ITS SECOND HALF, GAP-014.
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
 *
 * ========================================================================================
 * TWO OF THE THREE ASSERTIONS BELOW ARE INVERTED, 2026-11-26, AND THE REASON IS THE WHOLE
 * POINT OF THIS SLICE'S SECOND HALF.
 * ========================================================================================
 *
 * This file's original fix was a tolerant `if ($role_r)` at each call site, and its first case
 * asserted that "registration must still COMPLETE rather than TypeError". That removed the
 * TypeError and kept the half-registered merchant the paragraph above names as the real failure
 * mode -- it just stopped reporting it: 302, authenticated, user row, store row, store wallet,
 * `users.current_store`, and no role.
 *
 * `GAP-014` / `P01-DEFECT-REG` is that residue, and its instruction is explicit: "the
 * half-provisioned row should not survive". Its traceability row states the expected behaviour as
 * "a merchant who registers is provisioned with a role, or registration fails loudly". So the
 * lookup has moved ABOVE the first write and its absence refuses the registration outright.
 *
 * BOTH PROPERTIES THIS FILE WAS WRITTEN FOR STILL HOLD, which is why the cases are inverted
 * rather than deleted: there is still no TypeError (asserted below -- the role is proven to exist
 * before `addRole` is reached, so null cannot get there), and a merchant with a role still gets
 * it (unchanged, below). What changed is the answer to "and what if the role is absent": not
 * "finish anyway", but "write nothing".
 *
 * The behavioural coverage is `tests/Feature/Auth/RegistrationRoleProvisioningTest`, which drives
 * both branches and also asserts what the refused visitor is told.
 */
class RegistrationRoleGuardTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

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
     * THE DEFECT, AND THE RESIDUE OF ITS FIRST FIX. With no `admin` Role row -- the state of any
     * install where PermissionTableSeeder has not run, or where the role was renamed --
     * registration must raise no TypeError AND must leave nothing behind.
     *
     * The first limb is this file's original assertion, kept verbatim in spirit: the 500 that
     * `addRole(null)` used to raise after the user and the store were already written.
     *
     * The second limb is GAP-014 and it is the INVERSION: this used to assert that the user, the
     * store and `users.current_store` were all present, i.e. that the half-registered merchant
     * survived. It must not.
     */
    public function test_registration_is_refused_rather_than_half_completed_when_no_admin_role_exists(): void
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

        $this->assertNull(
            User::where('email', $email)->first(),
            'The merchant row survives a registration that could not give it a role. GAP-014: '
            . 'the half-provisioned row must not survive -- an account with a store, a session '
            . 'and no permissions, reported to its owner as a successful signup.'
        );

        $this->assertSame(
            0,
            Store::where('email', $email)->count(),
            'The store row survives a registration that could not give its owner a role, so the '
            . 'tenant exists with nobody able to administer it.'
        );

        $this->assertSame(
            0,
            DB::table('store_wallets')->count(),
            'A store wallet survives a refused registration, so the refusal is partial.'
        );

        $this->assertGuest();
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
     * NO CALL SITE CAN RECEIVE A NULL ROLE -- the same property this case always asserted, now
     * held by a different mechanism and so asserted differently.
     *
     * It used to require TWO `if ($role_r) {` guards, one per branch, because the two branches of
     * `store()` are near-duplicates and a fix applied to one left the other live. GAP-014 removed
     * the need for either: there is now ONE lookup, above every write, and it refuses. So the
     * assertion is that there is no tolerant branch left and that the single lookup dominates the
     * first row the method writes -- a guard that refused correctly but sat below `User::create()`
     * would be the original defect with a new message.
     *
     * COMMENTS ARE STRIPPED. The docblocks in this file and in the controller both quote
     * `if ($role_r)` while explaining that it is gone, and CLAUDE.md records four censuses in one
     * day that a comment about the thing being measured satisfied or broke.
     */
    public function test_neither_addrole_call_site_can_receive_a_null_role(): void
    {
        $source = $this->sourceWithoutComments('app/Http/Controllers/Auth/RegisteredUserController.php');

        $this->assertSame(
            2,
            substr_count($source, '$user->addRole($role_r);'),
            'The two near-duplicate branches of store() should still have one '
            . 'addRole call each; if this number changed, re-read the method.'
        );

        $this->assertSame(
            0,
            substr_count($source, 'if ($role_r)'),
            'A call site tolerates a null role again. Tolerating it is what GAP-014 refuses: it '
            . 'removes the TypeError and keeps the half-provisioned merchant, silently. The role '
            . 'must be proven to exist before the first write instead.'
        );

        $lookupAt = strpos($source, "Role::where('name', 'admin')->first()");
        $firstWriteAt = strpos($source, 'User::create([');

        $this->assertNotFalse($lookupAt, 'The admin-role lookup is gone from the controller.');
        $this->assertNotFalse($firstWriteAt, 'The user row is no longer created here.');
        $this->assertSame(
            1,
            substr_count($source, "Role::where('name', 'admin')->first()"),
            'There is more than one admin-role lookup again, so the two branches can drift apart '
            . '-- which is how they came to hold byte-identical tolerant guards.'
        );
        $this->assertLessThan(
            $firstWriteAt,
            $lookupAt,
            'The admin-role lookup happens AFTER the first row is written, so a missing role '
            . 'still leaves a half-provisioned merchant behind.'
        );
    }
}
