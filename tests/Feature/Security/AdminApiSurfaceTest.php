<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Route;

/**
 * R2-03 / NEW-unauthenticated-admin-list-routes, R2-04 /
 * NEW-unauthenticated-order-notes-write, SEC-R1-02 / NEW-throttle-bypass and
 * NEW-adminlogin-duplicate-route-throttle-bypass.
 *
 * The seven admin list endpoints carried only ['AdminApiLog'], which enforces
 * nothing -- AdminApiLog::handle is `return $next($request);` and nothing else.
 * Both of their immediate neighbours in the route file already carried
 * custom.auth, so the omission was visible on the page.
 *
 * The two order-note WRITE endpoints carried no ->middleware() call at all, so
 * the anonymous writes were not even logged.
 */
class AdminApiSurfaceTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Six of these seven are called by the shipped Flutter app
     * (base_api.dart); `variantlist` has no Flutter caller. All six app call
     * sites go through NetworkHttps._getAuthHeaders(), so they already send a
     * bearer token.
     */
    public static function adminListUriProvider(): array
    {
        return [
            ['/api/admin/categorylist'],
            ['/api/admin/productlist'],
            ['/api/admin/variantlist'],
            ['/api/admin/orderlist'],
            ['/api/admin/couponlist'],
            ['/api/admin/shippinglist'],
            ['/api/admin/taxlist'],
        ];
    }

    #[DataProvider('adminListUriProvider')]
    public function test_admin_list_routes_require_authentication(string $uri): void
    {
        [, $store] = $this->makeTenant();

        $this->getJson($uri . '?store_id=' . $store->id)->assertStatus(401);
    }

    #[DataProvider('adminListUriProvider')]
    public function test_admin_list_routes_carry_the_custom_auth_middleware(string $uri): void
    {
        $path = ltrim(str_replace('/api', '', $uri), '/');

        $found = null;
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'api/' . $path) {
                $found = $route;
                break;
            }
        }

        $this->assertNotNull($found, "Route {$uri} not found in the route table.");
        $this->assertContains('custom.auth', $found->gatherMiddleware());
    }

    public function test_add_order_note_rejects_anonymous_and_writes_nothing(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $before = \Illuminate\Support\Facades\DB::table('order_notes')->count();

        $this->postJson('/api/admin/orders/notes/create', [
            'order_id' => $order->id,
            'note' => 'injected by an anonymous caller',
        ])->assertStatus(401);

        $this->assertSame(
            $before,
            \Illuminate\Support\Facades\DB::table('order_notes')->count(),
            'An anonymous note write must not have persisted a row.'
        );
    }

    public function test_delete_order_note_rejects_anonymous(): void
    {
        $this->deleteJson('/api/admin/orders/notes/delete', ['id' => 1])
            ->assertStatus(401);
    }

    public function test_order_note_routes_are_now_logged_as_well_as_gated(): void
    {
        foreach (['api/admin/orders/notes/create', 'api/admin/orders/notes/delete'] as $uri) {
            $found = null;
            foreach (Route::getRoutes() as $route) {
                if ($route->uri() === $uri) {
                    $found = $route;
                    break;
                }
            }

            $this->assertNotNull($found, "Route {$uri} not found.");

            $middleware = $found->gatherMiddleware();
            $this->assertContains('custom.auth', $middleware);
            // So that anonymous write ATTEMPTS stop being invisible.
            $this->assertContains('AdminApiLog', $middleware);
        }
    }

    // ---------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------

    /**
     * The RateLimiter named 'api' was defined in RouteServiceProvider but never
     * bound to the api group, so it was dead code.
     */
    public function test_api_group_applies_throttle_middleware(): void
    {
        $groups = app('router')->getMiddlewareGroups();

        $this->assertArrayHasKey('api', $groups);

        $hasThrottle = false;
        foreach ($groups['api'] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                $hasThrottle = true;
                break;
            }
        }

        $this->assertTrue($hasThrottle, 'The api middleware group must carry a throttle.');
    }

    /**
     * `adminlogin` is registered twice against the same controller action. The
     * shipped Flutter app calls /api/admin/adminlogin (base_api.dart:3 + :5),
     * which was the UNTHROTTLED one. Both must now be throttled, and NEITHER
     * may be deleted -- removing that URI would 404 the app's login screen.
     */
    #[DataProvider('adminLoginUriProvider')]
    public function test_both_adminlogin_routes_are_throttled(string $uri): void
    {
        $found = null;
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === ltrim($uri, '/') && in_array('POST', $route->methods(), true)) {
                $found = $route;
                break;
            }
        }

        $this->assertNotNull($found, "Route {$uri} must still exist -- the shipped app calls it.");

        $hasThrottle = false;
        foreach ($found->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                $hasThrottle = true;
                break;
            }
        }

        $this->assertTrue($hasThrottle, "{$uri} must be throttled.");
    }

    public static function adminLoginUriProvider(): array
    {
        return [
            'top-level'              => ['/api/adminlogin'],
            'admin prefix (Flutter)' => ['/api/admin/adminlogin'],
        ];
    }

    /**
     * Uses $route->middleware() -- the route's OWN declared middleware -- and
     * NOT gatherMiddleware().
     *
     * gatherMiddleware() resolves controller middleware, which INSTANTIATES the
     * controller. The web `forgot-password` route resolves to
     * App\Http\Controllers\Auth\PasswordResetLinkController, whose
     * constructor (:15-22) calls a bare `die` when storage/installed is absent
     * -- and storage/installed is gitignored, so it is absent in every fresh
     * clone. Instantiating it therefore terminates the PHP process silently
     * with exit status 0 and no output, which is reported separately as
     * NEW-installer-guard-die. Reading the declared middleware array
     * instantiates nothing, and it is also the more precise assertion here:
     * throttle:auth is declared per-route, not inherited from a group.
     */
    public function test_forgot_password_api_routes_are_throttled(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            if (!str_contains($route->uri(), 'forgot-password')) {
                continue;
            }

            $checked++;

            $declared = array_map('strval', (array) $route->middleware());

            $this->assertContains(
                'throttle:auth',
                $declared,
                'Route ' . $route->uri() . ' must be throttled: it is an unauthenticated '
                . 'credential-reset endpoint.'
            );
        }

        $this->assertSame(3, $checked, 'Expected exactly three forgot-password API routes.');
    }

    // ---------------------------------------------------------------
    // R2-03 half 2 / NEW-client-supplied-store-id: the CROSS-TENANT half.
    //
    // Closing the anonymous path (half 1) and repairing CustomAuth (half 3)
    // still left every list method doing a bare Store::find($request->store_id)
    // and then filtering on $store->id, so any caller with a VALID token read
    // ANY store's rows by changing one query-string parameter.
    // ---------------------------------------------------------------

    /**
     * 403, not 401: a 401 would log the shipped app out
     * (network.dart:285-288 -> _handleUnauthorized -> Prefs.clear()).
     *
     * @param string $uri
     */
    #[DataProvider('adminListUriProvider')]
    public function test_admin_list_routes_reject_a_store_the_caller_does_not_own(string $uri): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $token = $merchantA->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($uri . '?store_id=' . $storeB->id)
            ->assertStatus(403);
    }

    /**
     * THE POSITIVE CASE, parameterised so no endpoint is fixed while the others
     * are forgotten: the caller's OWN store must still resolve. This is what
     * the shipped app sends -- Prefs.getStoreID(), set from the server-provided
     * owned-store list.
     *
     * @param string $uri
     */
    #[DataProvider('adminListUriProvider')]
    public function test_admin_list_routes_still_accept_the_callers_own_store(string $uri): void
    {
        [$merchant, $store] = $this->makeTenant();

        $token = $merchant->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($uri . '?store_id=' . $store->id);

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            $uri . ' must not deny the caller their own store.'
        );
    }

    /**
     * A super admin keeps the escape hatch, in the shape the controller already
     * expresses it at :4613 and :6605.
     */
    public function test_platform_admin_can_read_any_store_list(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        [, $store] = $this->makeTenant();

        $token = $platformAdmin->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/categorylist?store_id=' . $store->id);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    /**
     * NEW-viewusedcoupon-unscoped: the method resolved $store and then never
     * used it in the redemption query.
     */
    public function test_view_used_coupon_does_not_leak_another_stores_redemptions(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $couponB = \App\Models\Coupon::create([
            'coupon_name' => 'B only',
            'coupon_code' => 'BONLY',
            'coupon_type' => 'flat',
            'discount_amount' => 5,
            'status' => 1,
            'store_id' => $storeB->id,
        ]);

        $token = $merchantA->createToken('auth_token')->plainTextToken;

        // Merchant A asks for merchant B's coupon while naming their OWN store.
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/view-used-coupon?store_id=' . $storeA->id
                . '&coupon_id=' . $couponB->id);

        // The coupon is not in store A, so it must not resolve at all. The
        // controller answers with the ApiResponser error envelope (status 0),
        // and critically the payload carries no redemption rows from store B.
        $this->assertSame(0, $response->json('status'));
    }

    public function test_view_used_coupon_rejects_a_foreign_store_id(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $token = $merchantA->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/view-used-coupon?store_id=' . $storeB->id . '&coupon_id=1')
            ->assertStatus(403);
    }

    /**
     * NEW-unauthenticated-500s: anonymous callers must get 401, never 500.
     */
    public function test_anonymous_dashboard_returns_401_not_500(): void
    {
        $response = $this->postJson('/api/admin/dashboard');

        $response->assertStatus(401);
    }

    public function test_anonymous_currency_returns_401_not_500(): void
    {
        $response = $this->postJson('/api/admin/currency');

        $response->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // adminlogout -- the last /api/admin/* route with no route middleware
    // ---------------------------------------------------------------

    /**
     * It was LOW severity rather than open: Api/DashboardController::logouto
     * re-implements the `{id}|{secret}` parse and the hash_equals comparison
     * itself, which is why the Phase 1A anonymous probe got 401 and not 200. The
     * control is declared at the route now, where every sibling declares it,
     * rather than depending on one handler continuing to get token verification
     * right on its own. The baseline manifest's must_be_protected list is empty
     * once this lands.
     */
    public function test_adminlogout_carries_the_custom_auth_middleware(): void
    {
        $found = null;

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'api/admin/adminlogout') {
                $found = $route;
                break;
            }
        }

        $this->assertNotNull($found, 'Route /api/admin/adminlogout not found.');

        $middleware = $found->gatherMiddleware();

        $this->assertContains('custom.auth', $middleware);
        // So that logout attempts stop being invisible, as the order-note writes were.
        $this->assertContains('AdminApiLog', $middleware);
    }

    public function test_adminlogout_rejects_an_anonymous_caller(): void
    {
        $this->postJson('/api/admin/adminlogout')->assertStatus(401);
    }

    public function test_adminlogout_rejects_a_forged_token(): void
    {
        $merchant = $this->makeMerchant();
        $token = $merchant->createToken('auth_token');

        // Right id, wrong secret -- the forgery CustomAuth's hash_equals catches.
        $forged = $token->accessToken->id . '|' . str_repeat('a', 40);

        $this->withHeader('Authorization', 'Bearer ' . $forged)
            ->postJson('/api/admin/adminlogout')
            ->assertStatus(401);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    /**
     * THE LEGITIMATE WORKFLOW. A real token must still log out, and the token
     * row must actually be gone -- otherwise the route guard could be "working"
     * while logout silently stopped revoking anything.
     */
    public function test_adminlogout_still_revokes_a_valid_token(): void
    {
        $merchant = $this->makeMerchant();
        $token = $merchant->createToken('auth_token');
        $tokenId = $token->accessToken->id;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/admin/adminlogout');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('status'));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    /**
     * The recorded baseline must not silently widen again. A baseline wider than
     * reality permits a regression back to it, which is why the manifest is
     * shrunk as routes are fixed rather than left at its original 13.
     */
    public function test_the_admin_auth_coverage_baseline_lists_only_public_endpoints(): void
    {
        $path = base_path('tests/Support/ApiContract/manifests/admin-auth-coverage-baseline.json');

        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($manifest, 'The baseline manifest must be valid JSON.');

        $this->assertSame(
            [],
            $manifest['must_be_protected'] ?? null,
            'Every /api/admin/* route that needs authentication now has it, so '
            . 'must_be_protected must be empty.'
        );

        $this->assertSame(
            ['/api/admin/adminlogin', '/api/admin/base_url', '/api/admin/delivery-login'],
            $manifest['routes'] ?? null,
            'The only /api/admin/* routes left without authentication must be the two '
            . 'login endpoints and base_url.'
        );

        // Each remaining entry must carry its own written justification.
        foreach ($manifest['routes'] as $uri) {
            $this->assertArrayHasKey(
                $uri,
                $manifest['legitimately_public'] ?? [],
                "{$uri} is in the baseline with no recorded justification."
            );
        }
    }
}
