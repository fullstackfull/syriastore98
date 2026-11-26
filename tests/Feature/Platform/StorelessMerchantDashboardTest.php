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
     * THE PRINCIPAL WHO REACHES THE GUARD STILL SIGNED IN, which is the only case that can tell
     * the guard's own `auth()->logout()` apart from the one `getOrderChart()` performs two lines
     * above it. Without this case, removing that call changes nothing observable -- measured: that
     * mutation SURVIVED the rest of this file.
     *
     * IT IS REACHABLE BECAUSE TWO OPERATOR TESTS IN THE SAME REQUEST DISAGREE, and the
     * disagreement is documented in the codebase rather than invented here. `index()` branches on
     * `$user->type == 'super admin'` -- an exact PHP comparison -- while `getOrderChart()` asks
     * `UserPolicy::isOperator()`, which lowercases and rtrims a SPACE "matching MySQL's PAD
     * SPACE: a trailing space is ignored by the engine, a leading one is not" (that class's own
     * measured note). So a `users.type` of `'super admin '` is the operator to one and a merchant
     * to the other:
     *
     *   index()            'super admin ' == 'super admin'  -> false -> handleRegularUser()
     *   getOrderChart()    isOperator()                     -> true  -> NO sign-out
     *   getCurrentStore()  exact compare again              -> false -> no store, no fallback
     *
     * and the guard is reached with a live session. A row like that passes
     * `where('type', 'super admin')` in MySQL, which is how it survives unnoticed.
     *
     * THIS CASE DOES NOT CLAIM THE DIVERGENCE IS CORRECT -- it is reported, not fixed, because
     * normalising `index()` would move a principal between two whole dashboards. What it pins is
     * that the storeless refusal ends the session on its own, whoever else did or did not.
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
        $this->assertFalse(
            $operatorish->type === 'super admin',
            'premise: index() would send this principal to handleSuperAdmin(), which is not the '
            . 'branch under test.'
        );

        $response = $this->actingAs($operatorish->fresh())->get('/dashboard');

        $response->assertRedirect(route('login'));
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
