<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * THE BINDING CONSTRAINT: the shipped Flutter app must keep working.
 *
 * This suite replays the exact sequence the shipped app performs, using the
 * same URIs and the same header shape, so that "a correctly-issued token still
 * works" is a measured fact rather than an argument:
 *
 *   1. POST {base_api.dart:3}/adminlogin  (= /api/admin/adminlogin, the
 *      DUPLICATE registration -- base_api.dart:3 + :5 prove the app calls this
 *      one, not /api/adminlogin, which is why it was throttled rather than
 *      deleted);
 *   2. read data.token verbatim, as auth_controller.dart does;
 *   3. send it as `Authorization: Bearer $token`, as network.dart:60 does;
 *   4. reach an authenticated endpoint.
 */
class FlutterCompatibilityTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const LOGIN_URI = '/api/admin/adminlogin';

    /**
     * THE END-TO-END FLUTTER PROOF. If this passes, a shipped app build
     * authenticates against the repaired CustomAuth without a re-login, a
     * forced update or a shim.
     */
    public function test_shipped_app_login_then_authenticated_request_still_works(): void
    {
        [$merchant] = $this->makeTenant();
        $merchant->forceFill(['password' => Hash::make('secret-password')])->save();

        // 1. Login, exactly as lib/core/controller/auth/auth_controller.dart does.
        $login = $this->postJson(self::LOGIN_URI, [
            'email' => $merchant->email,
            'password' => 'secret-password',
        ]);

        $login->assertStatus(200);

        // 2. The app stores userData['token'] verbatim.
        $token = $login->json('data.token');

        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        // Sanctum's plainTextToken shape: {id}|{40-char secret}.
        $this->assertMatchesRegularExpression('/^\d+\|\S{40,}$/', $token);

        // 3 + 4. Send it the way lib/network/network.dart:60 does, and reach a
        // real authenticated endpoint.
        $dashboard = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/dashboard');

        $dashboard->assertStatus(200);
        $this->assertSame($merchant->id, auth()->guard('web')->id());
    }

    /**
     * The app also sends store_id on list endpoints, taken from
     * Prefs.getStoreID(), which is set from the server-provided owned-store
     * list. That value must therefore still satisfy the new ownership
     * predicate.
     */
    public function test_shipped_app_list_request_with_its_own_store_id_still_works(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $token = $merchant->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/categorylist?store_id=' . $store->id)
            ->assertStatus(200);
    }

    /**
     * The duplicate login URI must NOT have been deleted: base_api.dart:3 + :5
     * prove the shipped app calls it, so removing it would 404 the login screen.
     */
    public function test_the_duplicate_adminlogin_uri_still_exists(): void
    {
        $found = false;

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'api/admin/adminlogin' && in_array('POST', $route->methods(), true)) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            'POST /api/admin/adminlogin must still exist -- the shipped Flutter app calls it.'
        );
    }

    /**
     * A tenancy denial must be 403, never 401. repo-B
     * lib/network/network.dart:285-288 maps ANY 401 to _handleUnauthorized(),
     * which calls Prefs.clear() and Get.offAll(NormalLoginWidget) -- so a 401
     * on a tenancy denial would log the merchant out of the shipped app.
     */
    public function test_tenancy_denial_is_403_so_the_app_is_not_logged_out(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $token = $merchantA->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/changestore', ['store_id' => $storeB->id]);

        $response->assertStatus(403);
        $this->assertNotSame(401, $response->getStatusCode());
    }

    /**
     * The Laratrust `permission` middleware alias was recorded as BLOCKED in
     * the plan: it is NOT in bootstrap/app.php's alias map, so it has to come
     * from Laratrust's own service provider, and that could not be verified
     * where the plan was written.
     *
     * It DOES resolve -- LaratrustServiceProvider registers
     * 'permission' => \Laratrust\Middleware\Permission::class via
     * $router->aliasMiddleware(). This test is the functional proof, so the
     * finance route group cannot silently 500 every request.
     */
    public function test_laratrust_permission_middleware_alias_resolves(): void
    {
        $this->assertArrayHasKey(
            'permission',
            app('router')->getMiddleware(),
            'The Laratrust `permission` alias must resolve, or every /finance route throws.'
        );

        [$merchant] = $this->makeTenant();

        // Without the permission -> denied, not a 500.
        $denied = $this->actingAs($merchant)->get('/finance');
        $this->assertNotSame(500, $denied->getStatusCode());
        $this->assertContains($denied->getStatusCode(), [302, 403]);

        // With the permission -> the middleware lets the request through, so
        // the alias is provably doing real work rather than always failing.
        Permission::firstOrCreate(
            ['name' => 'View Finance Dashboard'],
            ['display_name' => 'View Finance Dashboard']
        );
        $this->grantPermission($merchant, 'View Finance Dashboard');

        $allowed = $this->actingAs($merchant->fresh())->get('/finance');
        $this->assertNotSame(403, $allowed->getStatusCode());
    }
}
