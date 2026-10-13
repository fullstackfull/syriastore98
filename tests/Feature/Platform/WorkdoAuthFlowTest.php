<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * NEW COVERAGE. This suite exists because retiring or correcting a Breeze scaffold test
 * must not lower coverage -- it replaces assertions about Breeze with assertions about
 * what THIS product does on the same paths.
 *
 * Everything here was measured working before it was written, so each test pins real
 * behaviour rather than a hoped-for contract. What is deliberately NOT asserted here is
 * anything that renders a page: no page in this product can render against the test
 * database, because resources/views/layouts/guest.blade.php:96 queries
 * owner_menu_settings and that addon module's migration path is never registered. Those
 * assertions stay in the scaffold suites, red and visible. This file covers the request
 * handling, persistence and redirect behaviour that works today and that nothing else
 * was testing.
 *
 * NOTE ON FIXTURES. These tests seed NO super-admin row on purpose. app/Helper/helper.php
 * get_file() dereferences a null super admin at line 145, but it is reached only from
 * views, so the POST/PUT/PATCH paths below are unaffected. Keeping the fixture minimal
 * means these tests cannot accidentally mask that defect.
 */
class WorkdoAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------------
    //  Authentication. The scaffold test for this is red because of the post-login
    //  redirect (see the defect note on the last test in this group); the credential
    //  check itself works and was untested in isolation.
    // -----------------------------------------------------------------------------

    public function test_correct_credentials_authenticate_the_user(): void
    {
        $user = User::factory()->create(['type' => 'company']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_does_not_authenticate(): void
    {
        $user = User::factory()->create(['type' => 'company']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $this->assertGuest();
    }

    public function test_an_unknown_email_does_not_authenticate(): void
    {
        User::factory()->create(['type' => 'company', 'email' => 'real@example.com']);

        $this->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_logout_clears_the_session(): void
    {
        $user = User::factory()->create(['type' => 'company']);

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    // -----------------------------------------------------------------------------
    //  Password update. Breeze's PasswordUpdateTest covers these two paths and passes,
    //  so the value added here is the assertion it omits: that a REJECTED update leaves
    //  the stored hash untouched. A controller that errored and still wrote would have
    //  passed the scaffold test.
    // -----------------------------------------------------------------------------

    public function test_a_rejected_password_update_leaves_the_stored_hash_untouched(): void
    {
        $user = User::factory()->create(['type' => 'company']);
        $originalHash = $user->password;

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'attacker-chosen-9',
                'password_confirmation' => 'attacker-chosen-9',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertSame(
            $originalHash,
            $user->fresh()->password,
            'A password update that failed validation still wrote a new hash.'
        );
        $this->assertFalse(
            Hash::check('attacker-chosen-9', $user->fresh()->password),
            'A rejected password update set the password to the submitted value anyway.'
        );
    }

    // -----------------------------------------------------------------------------
    //  Registration. The scaffold RegistrationTest::test_new_users_can_register asserts
    //  Breeze's name/email/password signup. This product's signup is a MERCHANT STORE
    //  signup and requires a store name, so the scaffold assertion describes a flow that
    //  was never built here. That real contract is pinned below.
    // -----------------------------------------------------------------------------

    /**
     * MEASURED: POST /register with Breeze's four fields and no store_name returns 302
     * with the validation error "The store name field is required." The requirement is
     * declared at app/Http/Controllers/Auth/RegisteredUserController.php:77
     *   'store_name' => ['required', 'string', 'max:255'],
     */
    public function test_registration_requires_a_store_name(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('store_name');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'store_name' => 'Second Shop',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
    }

    public function test_registration_rejects_a_mismatched_password_confirmation(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'mismatch@example.com',
            'password' => 'password-one-9',
            'password_confirmation' => 'password-two-9',
            'store_name' => 'Mismatch Shop',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);
    }

    // -----------------------------------------------------------------------------
    //  Profile update. ProfileTest's two profile.edit failures are symptoms of a route
    //  defect (see RouteRegistrationIntegrityTest). This separates the two halves: the
    //  PERSISTENCE works and is asserted here; only the redirect is broken.
    // -----------------------------------------------------------------------------

    /**
     * MEASURED: PATCH /profile persists name and email and nulls email_verified_at, then
     * returns 500 because ProfileController::update redirects to the unregistered route
     * name 'profile.edit' (app/Http/Controllers/ProfileController.php:37). The write
     * happens before the redirect, so the data layer is verifiable independently and is
     * asserted here. The redirect itself stays covered, and red, in ProfileTest.
     */
    public function test_a_profile_patch_persists_the_new_name_and_email(): void
    {
        $user = User::factory()->create([
            'type' => 'company',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Renamed Merchant',
            'email' => 'renamed@example.com',
        ]);

        $fresh = $user->fresh();

        $this->assertSame('Renamed Merchant', $fresh->name);
        $this->assertSame('renamed@example.com', $fresh->email);
        $this->assertNull(
            $fresh->email_verified_at,
            'Changing the email address must clear email_verified_at, or an unverified '
            . 'address inherits the old address\'s verified state.'
        );
    }

    public function test_a_profile_patch_that_keeps_the_email_keeps_the_verified_timestamp(): void
    {
        $user = User::factory()->create([
            'type' => 'company',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Renamed Merchant',
            'email' => $user->email,
        ]);

        $this->assertNotNull(
            $user->fresh()->email_verified_at,
            'An unchanged email address must keep its verified timestamp.'
        );
    }

    public function test_a_guest_cannot_patch_a_profile(): void
    {
        $user = User::factory()->create(['type' => 'company', 'name' => 'Original']);

        $this->patch('/profile', [
            'name' => 'Hijacked',
            'email' => 'hijacked@example.com',
        ]);

        $this->assertSame(
            'Original',
            $user->fresh()->name,
            'An unauthenticated PATCH /profile modified a user record.'
        );
    }
}
