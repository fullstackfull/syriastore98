<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * WHAT THIS FILE ACTUALLY COVERS, measured rather than assumed.
 *
 * It arrived as stock Breeze scaffolding, and the standing assumption has been that it
 * therefore tests nothing this product implements. That is true of two of its five tests
 * and FALSE of the rest, so the file is corrected here, not retired.
 *
 *  - test_profile_page_is_displayed GETs /profile, and /profile is served by
 *    UserController@profile (routes/web.php:937), NOT by Breeze's ProfileController@edit. It
 *    originally reached that handler BY ACCIDENT -- two GET declarations shared the URI and the
 *    later one won the RouteCollection method+URI index -- and it still reaches it now that the
 *    shadowing is fixed, because the fix moved Breeze's screen to GET /profile/edit (:489) and
 *    left UserController@profile on GET profile (:937). So this test exercises THE REAL MERCHANT
 *    PROFILE PAGE and its 500 is a real defect. Keep it red; it is telling the truth.
 *
 *    THE CITATION IS CORRECTED HERE. This docblock recorded the cause as `Attempt to read
 *    property "id" on null` at app/Helper/helper.php:1378 via
 *    resources/views/partision/sidebar.blade.php -- the view is header.blade.php. RE-MEASURED on p1f/agent5-ci, read off the
 *    real failure output rather than carried forward:
 *
 *        ErrorException: Attempt to read property "slug" on null
 *        in app/Http/Controllers/HomeController.php:367
 *        reached through resources/views/partision/header.blade.php
 *
 *    which is `return route('landing_page', $store->slug);` on a null $store -- the same
 *    expression is repeated at :369 in the sibling branch, so a guard has to cover both. The
 *    helper.php:1378 site was presumably guarded between branches; whatever the reason, the old
 *    line number sent a reader to the wrong file, and a stale citation is worse than none
 *    because it gets trusted.
 *
 *    IT IS ALSO NOT the owner_menu_settings casing bug that accounts for the other six failures.
 *    Measured: with every stranded LandingPage migration present, GET /profile still answers 500
 *    on this line. tests/Feature/Platform/AddonMigrationStrandingTest::
 *    test_the_profile_page_failure_is_not_this_bug asserts exactly that, so the two causes
 *    cannot be merged again by assumption.
 *
 *  - test_user_can_delete_their_account and
 *    test_correct_password_must_be_provided_to_delete_account are green and stay untouched.
 *    Note that the `/profile` in the second one is a redirect back to the REFERER set by
 *    ->from('/profile'), not a route lookup, so it is correct regardless of what
 *    profile.edit resolves to and must not be changed with the two below.
 *
 *  - the two PATCH tests asserted `assertRedirect('/profile')`. THAT ASSERTION WAS WRONG,
 *    and is corrected below. ProfileController::update() ends in
 *    `Redirect::route('profile.edit')` (app/Http/Controllers/ProfileController.php:37), so
 *    the property under test is "the update redirects to the profile edit screen". The
 *    literal '/profile' only happened to match while profile.edit was declared on that
 *    URI. MEASURED: with p1e/agent2-security's routes/web.php in place -- which fixes the
 *    shadowing by moving profile.edit to GET /profile/edit (:476) and leaving
 *    UserController@profile on GET profile (:924) -- the old assertion fails with
 *    "Expected 'http://localhost/profile', Actual 'http://localhost/profile/edit'", while
 *    route('profile.edit') passes. The corrected assertion is right both before and after
 *    that rename and survives any future URI change, so the coverage is kept and made
 *    robust rather than pinned to a URI the test does not own.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response->assertSessionHasNoErrors();

        // CORRECTED from assertRedirect('/profile'): the property is "redirects to the
        // profile edit screen", and ProfileController.php:37 redirects to the NAME.
        // Route::has() first so an unregistered name is an assertion FAILURE carrying the
        // diagnosis, not a RouteNotFoundException ERROR -- the suite's 0-errors baseline
        // is a property worth keeping, and an error hides the message.
        $this->assertTrue(Route::has('profile.edit'), 'Route [profile.edit] is not registered, so the redirect ProfileController.php:37 actually issues cannot be asserted. This was the NEW-ROUTE-SHADOW-01 defect: profile.edit was declared on GET /profile and later re-declared as "profile" on the same method+URI, which replaced it in RouteCollection\'s index and dropped the name. FIXED on this branch -- profile.edit is now GET /profile/edit (routes/web.php:489) and UserController@profile keeps GET profile (:937), so Route::has() passes. The guard stays because it turns a regression of that fix into an assertion failure carrying the diagnosis, instead of a RouteNotFoundException ERROR that hides the message and breaks the suite\'s 0-errors baseline.');
        $response->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response->assertSessionHasNoErrors();

        // CORRECTED from assertRedirect('/profile'); see the class docblock.
        $this->assertTrue(Route::has('profile.edit'), 'Route [profile.edit] is not registered, so the redirect ProfileController.php:37 actually issues cannot be asserted. This was the NEW-ROUTE-SHADOW-01 defect: profile.edit was declared on GET /profile and later re-declared as "profile" on the same method+URI, which replaced it in RouteCollection\'s index and dropped the name. FIXED on this branch -- profile.edit is now GET /profile/edit (routes/web.php:489) and UserController@profile keeps GET profile (:937), so Route::has() passes. The guard stays because it turns a regression of that fix into an assertion failure carrying the diagnosis, instead of a RouteNotFoundException ERROR that hides the message and breaks the suite\'s 0-errors baseline.');
        $response->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
