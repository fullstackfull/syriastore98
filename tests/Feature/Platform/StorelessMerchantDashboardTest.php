<?php

namespace Tests\Feature\Platform;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-013 / `P01-DEFECT-PROFILE`, THE HALF THAT WAS STILL LIVE.
 * =============================================================
 *
 * The gap names "a null-store guard at both lines" --
 * `app/Http/Controllers/HomeController.php:610` and `:612`, both of them
 * `route('landing_page', $store->slug)` on the result of
 * `getStoreById(getCurrentStore())`. Those two lines were inside `getThemeUrl()` and they were
 * guarded on 2026-11-20; `ProfileTest::test_profile_page_is_displayed` passes at HEAD and its
 * manifest entry is already deleted. So the gap's own citation is DISCHARGED, and this file is
 * not about those lines.
 *
 * WHAT WAS STILL LIVE, MEASURED AT HEAD BEFORE THIS CHANGE, and it is the SAME EXPRESSION on the
 * SAME NULL from the SAME helper, 27 lines below the ones that were fixed:
 *
 *     ErrorException: Attempt to read property "slug" on null
 *         at app/Http/Controllers/HomeController.php:637
 *            633  $chartData = $this->getOrderChart(['duration' => 'week']);
 *            635  $store = getStoreById(getCurrentStore());
 *         -> 637  $slug = $store->slug;
 *
 * reached over HTTP by `GET /dashboard` as a merchant whose store does not resolve. The earlier
 * fix's own evidence said this would be here: `StorelessChromeRendersTest` records that
 * `StoreFinanceController` measured the chrome 500 twice and "recorded in a comment that the
 * dashboard was a 500 there too and that fixing the chrome was not finance's to do". Fixing the
 * chrome did not fix the dashboard, because the dashboard has a dereference of its own.
 *
 * WHY THE ANSWER IS A REDIRECT AND NOT A RENDERED PAGE. Measured by null-guarding only line 637
 * and re-running this test: the request then dies in the VIEW instead --
 *
 *     ViewException: Missing required parameter for [Route: theme.pages]
 *         [URI: theme-customize/{theme}/pages] [Missing parameter: theme]
 *         (View: resources/views/dashboard.blade.php)
 *
 * because `$theme_name` is null for a principal with no store as well. The merchant dashboard
 * cannot be rendered without a store, so the handler must not try.
 *
 * AND THE REDIRECT IS NOT A NEW PRODUCT RULE -- it is the one this codebase already applies to
 * this principal, two lines above the defect. `getOrderChart()` answers a non-operator with no
 * resolvable store by `auth()->logout()` and `redirect()->route('login')`, and
 * `handleRegularUser()` was DISCARDING that answer into `$chartData` and carrying on. Every
 * principal who can reach line 637 with a null store has therefore ALREADY been signed out by
 * line 633 -- which is why rendering anything at all here was wrong, and why the login redirect
 * is the only coherent answer. `handleRegularUser()`'s own comment at :466-472 already named
 * that logout "this codebase's documented answer for them".
 *
 * THE GUARD IS ON THE STORE, NOT ON `$chartData`'s TYPE. Deriving it from whether another
 * function happened to return a redirect would make the dashboard's tenant safety a side effect
 * of a chart helper. CLAUDE.md's rule for `null_tenant => 'open'` is the same shape: guard
 * explicitly, never let something else's behaviour be the check.
 *
 * THE LAST TWO CASES ARE THE MUTATION COUNTERWEIGHT. A guard weakened to a falsy test that a
 * legitimate store also fails -- `! $store->slug`, `empty($store->name)` -- would take the
 * dashboard away from a store that exists and works. `StorelessChromeRendersTest` records review
 * forcing exactly that correction on the `getThemeUrl()` fix, whose first version guarded
 * `! $store || ! isset($store->slug)` together and silently withdrew a working custom-domain link.
 *
 * THE FALSY-BUT-LEGITIMATE VALUE IS `'0'`, NOT NULL, and that is a measurement rather than a
 * preference. `StorelessChromeRendersTest` states that `stores.slug` is nullable; it is not --
 * `2023_12_14_090259_create_stores_table.php:19` is a bare `$table->string('slug')`, and
 * persisting a null slug here fails with
 *
 *     SQLSTATE[23000]: NOT NULL constraint failed: stores.slug
 *
 * so that case can only be built in memory, which an HTTP drive cannot use -- the handler
 * re-reads the row. `'0'` is a legal value for the column, is what `User::slugs()` yields for a
 * store named "0", and is false to every falsy test, so it catches the same weakening through a
 * row that really persists.
 */
final class StorelessMerchantDashboardTest extends SecurityTestCase
{
    use RefreshDatabase;

    public function test_a_merchant_whose_store_does_not_resolve_is_not_shown_an_error_page(): void
    {
        $merchant = $this->makeMerchant(['current_store' => null]);
        Cache::forget('admin_details');

        $response = $this->actingAs($merchant->fresh())->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * The same principal, reached the other way: `current_store` is set and points at nothing.
     * This is the production shape -- registration always writes a store, so a merchant becomes
     * storeless when their store row goes away, not when they are created.
     */
    public function test_a_merchant_whose_selected_store_was_deleted_is_not_shown_an_error_page(): void
    {
        $merchant = $this->makeMerchant(['current_store' => 987654]);
        Cache::forget('admin_details');

        $this->assertNull(
            Store::withoutGlobalScopes()->find(987654),
            'The premise of this case is that store 987654 does not exist.'
        );

        $response = $this->actingAs($merchant->fresh())->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * THE GUARD ENDS THE SESSION ITSELF, which is the only property that can tell its own
     * `auth()->logout()` apart from the one `getOrderChart()` performs two lines above it. Without
     * this case, removing that call changes nothing observable -- measured: that mutation SURVIVED
     * the rest of this file.
     *
     * =============================================================================================
     *  IT IS DRIVEN THROUGH THE HANDLER RATHER THAN OVER HTTP, AND THAT IS A FIX UPSTREAM OF IT
     *  RATHER THAN A WEAKENING HERE.
     * =============================================================================================
     *
     * This case used to drive `GET /dashboard` as a `users.type` of `'super admin '`. It reached
     * the guard with a live session because two operator tests in ONE request disagreed:
     * `HomeController::index()` compared the raw string while `getOrderChart()` asked
     * `UserPolicy::isOperator()`, which rtrims a space to match MySQL's PAD SPACE collation.
     *
     * ADM-001 / GAP-231 (`3d72820e6`) CLOSED THAT DIVERGENCE -- `index()` now asks the same helper,
     * and `OperatorDashboardOneOperatorDefinitionTest::
     * test_a_trailing_space_operator_gets_the_operator_dashboard` pins it. So that principal is now
     * the operator to every test on the path and is handed the OPERATOR dashboard, never reaching
     * `handleRegularUser()` at all. The old premise assertion here said in so many words that
     * `handleSuperAdmin()` "is not the branch under test"; after ADM-001 it is exactly the branch
     * that answers, so the HTTP drive was measuring the wrong screen and failed on a 200.
     *
     * NO HTTP PRINCIPAL CAN REACH THIS GUARD SIGNED IN ANY MORE, established rather than assumed:
     *
     *   - `index()` routes to `handleRegularUser()` only when `isOperator()` is FALSE;
     *   - for such a principal `getOrderChart()` signs out unless `getStoreById($user->current_store)`
     *     resolves a row;
     *   - and when `current_store` is non-empty `getCurrentStore()` returns that same value, so the
     *     guard's own `getStoreById(getCurrentStore())` resolves the same row and does not fire.
     *
     * The guard's `logout()` is therefore DEFENSIVE -- which is what the handler's own comment
     * already claims ("`logout()` is idempotent ... depending on it having happened would make
     * this screen's termination a side effect of a chart helper"). An undriven claim is how that
     * call gets deleted by the next person simplifying the method, so it is driven at the level it
     * is reachable at. CLAUDE.md section 1 is explicit about the instrument: a check the HTTP layer
     * cannot reach is invisible to an HTTP test, "so call the handler directly". The reflection
     * idiom is `TopSalesReportPairingTest`'s.
     *
     * THE TWO CASES ABOVE STILL DRIVE THE SAME GUARD OVER HTTP for the principals that can reach
     * it, so what moved here is this one property and not the guard's coverage.
     */
    public function test_the_refusal_ends_the_session_itself(): void
    {
        $operatorish = $this->makeMerchant([
            'type' => 'super admin ',
            'current_store' => null,
        ]);
        Cache::forget('admin_details');

        $this->assertTrue(
            \App\Policies\UserPolicy::isOperator($operatorish),
            'premise: this principal is not an operator to UserPolicy, so getOrderChart() signs '
            . 'them out and this case cannot tell the two sign-outs apart.'
        );

        /*
         * AND THE HTTP DRIVE REALLY DOES GO SOMEWHERE ELSE NOW, asserted rather than argued. This
         * is what makes the direct call below the only instrument instead of the lazy one: if
         * `index()` is ever reverted to a literal comparison, this redirects to `login` instead and
         * this file says so.
         */
        $overHttp = $this->actingAs($operatorish->fresh())->get('/dashboard');

        $overHttp->assertOk();
        $this->assertAuthenticatedAs($operatorish->fresh(), 'web');

        /* THE HANDLER, ENTERED WITH A LIVE SESSION. `newInstanceWithoutConstructor()` because
           `HomeController::__construct()` `exit`s the process when `storage/installed` is absent,
           which would take the whole suite with it. */
        $this->actingAs($operatorish->fresh());

        $this->assertTrue(auth()->check(), 'premise: the handler is entered with a live session.');

        $method = new \ReflectionMethod(\App\Http\Controllers\HomeController::class, 'handleRegularUser');
        $method->setAccessible(true);

        $answer = $method->invoke(
            (new \ReflectionClass(\App\Http\Controllers\HomeController::class))->newInstanceWithoutConstructor(),
            $operatorish->fresh()
        );

        $this->assertInstanceOf(
            \Illuminate\Http\RedirectResponse::class,
            $answer,
            'the storeless branch of handleRegularUser() no longer answers a redirect, so '
            . 'HomeController::index() would hand this to view() -- a second fault on the same path.'
        );
        $this->assertSame(route('login'), $answer->getTargetUrl());
        $this->assertGuest();
    }

    /** THE PREMISE: the guard must not touch a merchant who has a store. */
    public function test_a_merchant_with_a_store_still_gets_the_dashboard(): void
    {
        [$merchant] = $this->makeTenant();
        Cache::forget('admin_details');

        $response = $this->actingAs($merchant->fresh())->get('/dashboard');

        $response->assertOk();
        $this->assertAuthenticatedAs($merchant->fresh(), 'web');
    }

    /**
     * A store whose slug is FALSY still exists, and the dashboard is its merchant's to open. A
     * guard written as a falsy test on the slug -- the expression the defect was ON -- would
     * refuse a working tenant.
     */
    public function test_a_merchant_whose_store_slug_is_falsy_still_gets_the_dashboard(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $store->slug = '0';
        $store->save();
        Cache::forget('admin_details');

        $this->assertSame(
            '0',
            Store::withoutGlobalScopes()->find($store->id)->slug,
            'The premise of this case is a persisted store row whose slug is falsy.'
        );

        $response = $this->actingAs($merchant->fresh())->get('/dashboard');

        $response->assertOk();
        $this->assertAuthenticatedAs($merchant->fresh(), 'web');
    }
}
