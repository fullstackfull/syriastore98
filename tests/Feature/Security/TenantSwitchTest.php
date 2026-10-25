<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * R4-03 + R2-05 -- the attacker-controlled tenant resolver.
 *
 * users.current_store is the discriminator getCurrentStore() returns
 * (app/Helper/helper.php:357). Both switch endpoints wrote it from an
 * unvalidated caller-supplied store id, so pointing it at a victim's store id
 * satisfied every manual `where('store_id', getCurrentStore())` filter in the
 * application as that victim.
 *
 * In every denial case the assertion that the discriminator is PROVABLY
 * UNCHANGED is load-bearing: a 403 alone would pass even if the write had
 * already happened before the check.
 */
class TenantSwitchTest extends SecurityTestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Web: POST /change-store/{id} -> StoreController::changeStore
    //
    // P2-SEC-F12 changed the verb from GET. These six drives were rewritten with it; every
    // assertion they make is about the ownership predicate and the discriminator, which the
    // verb does not touch, so the regression coverage R4-03 added is preserved exactly. The
    // verb itself is pinned by the two tests at the end of this section.
    // ---------------------------------------------------------------

    public function test_user_cannot_switch_into_a_store_they_do_not_own(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $response = $this->actingAs($merchantA)->post("/change-store/{$storeB->id}");

        $response->assertForbidden();

        $this->assertSame(
            $storeA->id,
            $merchantA->fresh()->current_store,
            'current_store must be provably unchanged after a denied switch.'
        );
    }

    /**
     * THE POSITIVE CASE. This guards the Flutter store switcher and the web
     * store dropdown: a merchant with two stores must still be able to move
     * between them.
     */
    public function test_merchant_can_switch_between_their_own_stores(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($merchant);

        $this->actingAs($merchant)->post("/change-store/{$storeB->id}");

        $this->assertSame($storeB->id, $merchant->fresh()->current_store);
        $this->assertNotSame($storeA->id, $merchant->fresh()->current_store);
    }

    /**
     * Staff resolve through creatorId(), so they must keep access to their
     * employer's stores or the fix would lock them out of their own job.
     */
    public function test_staff_can_switch_between_their_employers_stores(): void
    {
        [$owner] = $this->makeTenant();
        $secondStore = $this->makeStore($owner);
        $staff = $this->makeStaff($owner);

        $this->actingAs($staff)->post("/change-store/{$secondStore->id}");

        $this->assertSame($secondStore->id, $staff->fresh()->current_store);
    }

    public function test_staff_cannot_switch_into_another_tenants_store(): void
    {
        [$owner] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $original = $staff->fresh()->current_store;

        [, $foreignStore] = $this->makeTenant();

        $this->actingAs($staff)
            ->post("/change-store/{$foreignStore->id}")
            ->assertForbidden();

        $this->assertSame($original, $staff->fresh()->current_store);
    }

    /**
     * The original guard was `if ($store && $store->is_active = 1)` -- an
     * ASSIGNMENT, so it always evaluated truthy, the is_active check was dead
     * code and the "Store is locked" branch was unreachable.
     */
    public function test_inactive_owned_store_is_refused_and_reports_locked(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $locked = $this->makeStore($merchant, ['is_active' => 0]);

        $this->actingAs($merchant)
            ->post("/change-store/{$locked->id}")
            ->assertSessionHas('error');

        $this->assertSame($storeA->id, $merchant->fresh()->current_store);
        $this->assertSame(0, (int) $locked->fresh()->is_active);
    }

    public function test_anonymous_cannot_switch_store(): void
    {
        [, $store] = $this->makeTenant();

        $response = $this->post("/change-store/{$store->id}");

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
    }

    /**
     * P2-SEC-F12. THE VERB IS THE CONTROL. A GET that writes `users.current_store` is reachable
     * from a third-party page's `<img src>` or link and from browser link prefetching, with no
     * click and no token -- so the request shape, not just the ownership predicate, is what is
     * being asserted here.
     *
     * 405 is what Laravel answers for a known uri on the wrong verb. A 404 would mean the route
     * is gone entirely, which is a different (and broken) outcome, so it is excluded explicitly
     * -- the project has been bitten by a row assertion that a 404 satisfied.
     */
    public function test_the_store_switch_refuses_a_get(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($merchant);

        $response = $this->actingAs($merchant)->get("/change-store/{$storeB->id}");

        $this->assertSame(405, $response->getStatusCode());

        $this->assertSame(
            $storeA->id,
            $merchant->fresh()->current_store,
            'a GET must not have moved the tenant pointer before being refused'
        );
    }

    /**
     * AND THE TOKEN IS ACTUALLY ENFORCED -- asserted STRUCTURALLY, because it cannot be
     * asserted behaviourally in this suite and the attempt to do so was a false reading.
     *
     * MEASURED: driving `POST /change-store/{id}` with no token, with
     * `withMiddleware(VerifyCsrfToken::class)` reinstated, returns 302 AND MOVES
     * `users.current_store` -- the write happens. Not because the route is unprotected, but
     * because `ValidateCsrfToken::handle()` short-circuits on
     * `runningInConsole() && runningUnitTests()` (framework line 84), so the middleware is inert
     * in every PHPUnit run. The 302 was the controller's own `redirect()->back()`, not a
     * refusal, and asserting 419 against it would have been one of the project's
     * passes-for-the-wrong-reason shapes with the sign flipped: a RED test that proves nothing.
     *
     * So what is pinned is the two things that decide whether a real browser request is checked:
     * the CSRF middleware is in the matched route's resolved stack, and this uri is not in
     * `validateCsrfTokens(except: [...])` (bootstrap/app.php:365). Both are what an edit would
     * break, and neither depends on the environment.
     */
    public function test_the_store_switch_carries_csrf_protection(): void
    {
        [$merchant] = $this->makeTenant();
        $store = $this->makeStore($merchant);

        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->match(\Illuminate\Http\Request::create("/change-store/{$store->id}", 'POST'));

        $this->assertSame('change.store', $route->getName(), 'premise: the POST uri resolves to this route');

        $stack = app('router')->gatherRouteMiddleware($route);

        // `ValidateCsrfToken`, which is the Laravel 11 name and what this app's `web` group
        // actually resolves to -- measured by dumping `gatherRouteMiddleware()` for this route.
        // `VerifyCsrfToken` is the legacy subclass and is NOT what is in the stack, so naming it
        // here made this assertion fail against a correctly protected route.
        $this->assertContains(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            $stack,
            'the store switch is outside CSRF protection. As a POST with no token check it is no '
            . 'better than the GET it replaced.'
        );

        // AND NOT EXEMPTED. `inExceptArray()` matches with `Str::is`, so only `*` is special;
        // this asks the middleware's own list rather than re-implementing the match.
        $csrf = app(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $except = (new \ReflectionClass($csrf))->getProperty('except');
        $except->setAccessible(true);

        foreach ((array) $except->getValue($csrf) as $pattern) {
            $this->assertFalse(
                \Illuminate\Support\Str::is(ltrim($pattern, '/'), "change-store/{$store->id}"),
                "change-store is CSRF-exempt through the pattern [{$pattern}]."
            );
        }
    }

    // ---------------------------------------------------------------
    // API: POST /api/admin/changestore -> DashboardController::ChangeStore
    // ---------------------------------------------------------------

    public function test_api_changestore_rejects_a_foreign_store_id(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $token = $merchantA->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/changestore', ['store_id' => $storeB->id]);

        // 403, NOT 401: repo-B lib/network/network.dart:285-288 maps any 401 to
        // _handleUnauthorized(), which clears prefs and forces a re-login, so a
        // 401 here would log the merchant out of the shipped app on every
        // tenancy denial.
        $response->assertStatus(403);

        $this->assertSame($storeA->id, $merchantA->fresh()->current_store);
    }

    public function test_api_changestore_allows_an_owned_store(): void
    {
        [$merchant] = $this->makeTenant();
        $second = $this->makeStore($merchant);

        $token = $merchant->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/changestore', ['store_id' => $second->id])
            ->assertStatus(200);

        $this->assertSame($second->id, $merchant->fresh()->current_store);
    }

    public function test_api_changestore_rejects_a_non_integer_store_id(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $token = $merchant->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/changestore', ['store_id' => 'not-an-integer']);

        $this->assertSame($storeA->id, $merchant->fresh()->current_store);
    }

    public function test_api_changestore_rejects_anonymous_without_a_500(): void
    {
        [, $store] = $this->makeTenant();

        $response = $this->postJson('/api/admin/changestore', ['store_id' => $store->id]);

        $response->assertStatus(401);
        $this->assertNotSame(500, $response->getStatusCode());
    }
}
