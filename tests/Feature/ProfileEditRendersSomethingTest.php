<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P2.5-WEB-PROFILE-EDIT — GET /profile/edit rendered a BLANK PAGE.
 * =================================================================================================
 *
 * MEASURED, not inferred. The Phase 2.5 route sweep photographed this URI in all four
 * language/direction/theme combinations on the dev fixture, signed in as a real merchant with a
 * real store. All four returned HTTP 200, carried the correct `dir`, loaded the correct theme
 * sheet -- and showed NOTHING between the breadcrumb and the footer. Evidence:
 * docs/development/phase-2.5/evidence/web-sweep-2026-11-10/after/_profile_edit__*.png
 *
 * It passed the sweep's premise check for exactly that reason: the premise asks whether the
 * CHROME is correct, and the chrome was perfect. Only the page's own content was missing. The
 * sweep's other tell was the title -- `Store Syria -` with nothing after the dash.
 *
 * ROOT CAUSE, a two-convention collision:
 *
 *   resources/views/profile/edit.blade.php   is Breeze: `<x-app-layout>` ... `</x-app-layout>`,
 *                                            which passes its body to the component as `$slot`.
 *   App\View\Components\AppLayout::render()  returns view('layouts.app').
 *   resources/views/layouts/app.blade.php    is the WorkDo layout. It contains ZERO references
 *                                            to `$slot` and emits page content with
 *                                            `@yield('content')` (:708).
 *
 * A layout that yields a section never outputs the slot it was handed, so the whole form was
 * discarded silently -- no exception, no warning, HTTP 200.
 *
 * WHY A REDIRECT AND NOT "RENDER THE BREEZE FORM".
 *
 * The product already ships a profile screen: GET /profile -> UserController@profile ->
 * resources/views/users/profile.blade.php, which updates the profile and changes the password,
 * and which routes/web.php:417 and tests/Feature/Security/RouteShadowingTest guard by name.
 * /profile/edit exists only so that ProfileController::update()'s
 * `Redirect::route('profile.edit')` resolves -- that was the NEW-ROUTE-SHADOW-01 repair. Nothing
 * in the shipped UI links to it: its only two callers are
 * resources/views/layouts/navigation.blade.php:37,83, and that partial is included by NOTHING
 * (verified by grep across resources/, app/ and packages/).
 *
 * Rendering the Breeze markup instead would have added a SECOND profile editor beside the
 * product's own, and it would have put a **Delete Account** control into the merchant UI --
 * ProfileController@destroy is a bare `$user->delete()` with no store, order or subscription
 * cleanup. Exposing self-service tenant deletion is a product and data-integrity decision, not a
 * rendering repair, so this fix deliberately does not make it. It is recorded for the owner in
 * docs/development/phase-2.5/PHASE-2.5-WEB-SWEEP-FINDINGS.md.
 *
 * The redirect keeps every pinned assertion true -- `Route::has('profile.edit')`, the
 * `profile/edit` URI, and `route('profile.edit')` all still resolve -- and it sends the merchant
 * to the profile screen the product ships instead of an empty page. It also improves the save
 * path: a successful PATCH /profile previously landed the merchant on this blank page.
 *
 * WHAT THIS TEST DOES AND DOES NOT MEASURE. It asserts the redirect and stops there; it does not
 * follow it. That is deliberate, and the reason is worth stating so nobody "strengthens" it into
 * a red test: `GET /profile` is a DECLARED ACCEPTED FAILURE
 * (.github/known-test-outcomes.json -> expected_failures ->
 * Tests\Feature\ProfileTest::test_profile_page_is_displayed). It answers 500 for a principal
 * with no store, because partision/header.blade.php:11-12 reaches
 * HomeController::getThemeUrl() (:610, :612) through getStoreById(getCurrentStore()), which is
 * null for a bare `User::factory()` row. Adding `followRedirects()` here would assert against
 * that known failure and would also break the declared-outcome set, which the gate checks in
 * both directions. The sweep's fixture -- a real merchant WITH a store -- is where the blank
 * page becomes a real page, and that is shown by the captures, not by this case.
 */
class ProfileEditRendersSomethingTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_edit_sends_the_merchant_to_the_profile_screen_instead_of_a_blank_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile/edit');

        $this->assertTrue(
            Route::has('profile'),
            'The product profile screen route `profile` is gone, so /profile/edit has nowhere to '
            . 'send the merchant. GET /profile must keep serving UserController@profile.'
        );

        $response->assertRedirect(route('profile'));
    }

    /**
     * The NEW-ROUTE-SHADOW-01 guarantees, restated here so that "fixing" this blank page by
     * deleting the route fails loudly. ProfileController::update() redirects to this NAME.
     */
    public function test_the_profile_edit_route_still_exists_under_its_own_uri(): void
    {
        $this->assertTrue(Route::has('profile.edit'), 'Route [profile.edit] was dropped.');

        $edit = Route::getRoutes()->getByName('profile.edit');

        $this->assertNotNull($edit);
        $this->assertSame('profile/edit', $edit->uri());
        $this->assertSame(url('/profile/edit'), route('profile.edit'));
    }
}
