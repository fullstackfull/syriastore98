<?php

namespace Tests\Feature\Lynomia;

use App\Models\AddOnManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * Phase 1 of the Store Syria x Lynomia Chat integration: module foundation, account mapping,
 * verified provisioning/linking and one-way SSO.
 *
 * NO REAL CREDENTIAL AND NO REAL HOST. Every test runs against Http::fake() with a dummy
 * base URL and a dummy token set in config. Nothing here can reach a production Lynomia, and
 * the integration ships disabled (config/lynomia.php `enabled` defaults to false) so a run
 * that forgot to configure it would refuse rather than dial out.
 */
class LynomiaConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_BASE = 'https://lynomia.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('lynomia.enabled', true);
        config()->set('lynomia.base_url', self::FAKE_BASE);
        config()->set('lynomia.platform_token', 'test-token-not-a-real-credential');

        // The client is a singleton built from config at resolve time, so the config above
        // must be set before anything resolves it.
        $this->app->forgetInstance(\Workdo\LynomiaChat\app\Services\PlatformApiClient::class);

        // The module must be in the add-on registry for ActivatedModule() to see it at all.
        AddOnManager::updateOrCreate(['module' => 'LynomiaChat'], [
            'name' => 'Lynomia Chat',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'lynomia-chat',
        ]);
    }

    // ---------------------------------------------------------------- fixtures

    private function merchant(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'type' => 'admin',
            'created_by' => 1,
            'is_active' => 1,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function operator(): User
    {
        return User::factory()->create([
            'type' => 'super admin',
            'created_by' => null,
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function storeFor(User $owner): Store
    {
        $store = Store::create([
            'name' => 'Store ' . uniqid(),
            'email' => 'store' . uniqid() . '@example.test',
            'theme_id' => '1',
            'slug' => 'store-' . $owner->id . '-' . uniqid(),
            'created_by' => $owner->id,
            'is_active' => 1,
        ]);

        $owner->current_store = $store->id;
        $owner->save();

        return $store;
    }

    /** Grant the merchant's tenant the module, which is what PlanModuleCheck resolves. */
    private function entitle(User $owner): void
    {
        userActiveModule::updateOrCreate(
            ['user_id' => $owner->id, 'module' => 'LynomiaChat'],
            []
        );

        // Entitlement is the PLAN's answer; the permission is the ROLE's. A merchant owner
        // holds both in production -- PermissionTableSeeder grants `Manage Lynomia Chat` to
        // the global `admin` role that every owner carries -- so the fixture gives both.
        // They are granted together here precisely because they are NOT the same control:
        // the staff test below entitles the tenant and withholds only the permission.
        $this->grantLynomiaPermission($owner);
    }

    /**
     * Give a user a real Laratrust role carrying the module's permission.
     *
     * forceCreate, not create: App\Models\Role sets `public $guarded = []` but extends
     * Laratrust's Role, whose non-empty `protected $fillable` wins in isFillable(), so
     * store_id and created_by are silently dropped by create().
     */
    private function grantLynomiaPermission(User $user): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'Manage Lynomia Chat'],
            ['display_name' => 'Manage Lynomia Chat', 'guard_name' => 'web', 'module' => 'LynomiaChat']
        );

        $role = Role::firstWhere('name', 'lynomia-role-' . $user->id)
            ?? Role::forceCreate([
                'name' => 'lynomia-role-' . $user->id,
                'display_name' => 'Lynomia Role',
                'guard_name' => 'web',
                'created_by' => $user->creatorId(),
                'store_id' => $user->current_store,
            ]);

        $role->givePermission($permission);
        $role->flushCache();

        // addRole unconditionally, and NEVER probe with hasRole() first: Laratrust memoises
        // the user's role set on the first read, so a probe taken before the attach caches an
        // empty set and every later isAbleTo() in the same request answers from it.
        $user->addRole($role);
        $user->flushCache();
    }

    /**
     * A staff member of the owner's tenant with a role that carries NO permissions --
     * the shape UserController::store creates: type = the role's name, created_by = the
     * owner, current_store copied from the owner, email already verified.
     */
    private function staffOf(User $owner): User
    {
        $staff = User::factory()->create([
            'type' => 'Packing',
            'created_by' => $owner->id,
            'current_store' => $owner->current_store,
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);

        $role = Role::forceCreate([
            'name' => 'packing-' . $staff->id,
            'display_name' => 'Packing',
            'guard_name' => 'web',
            'created_by' => $owner->id,
            'store_id' => $owner->current_store,
        ]);

        $staff->addRole($role);
        $staff->flushCache();

        return $staff->fresh();
    }

    /** @return array{0: User, 1: Store} an entitled merchant with a store selected */
    private function entitledTenant(): array
    {
        $owner = $this->merchant();
        $store = $this->storeFor($owner);
        $this->entitle($owner);

        return [$owner->fresh(), $store];
    }

    private function fakeLynomia(int $accountId = 7001, int $userId = 9001): void
    {
        Http::fake([
            self::FAKE_BASE . '/platform/api/v1/accounts' => Http::response(['id' => $accountId, 'name' => 'WS'], 200),
            self::FAKE_BASE . '/platform/api/v1/users' => Http::response(['id' => $userId, 'email' => 'x@y.z'], 200),
            self::FAKE_BASE . "/platform/api/v1/accounts/{$accountId}/account_users" => Http::response(['id' => 1], 200),
            self::FAKE_BASE . "/platform/api/v1/users/{$userId}/login" => Http::response(
                ['url' => self::FAKE_BASE . '/app/login?email=x&sso_auth_token=SECRET'],
                200
            ),
        ]);
    }

    // ================================================================ route surface

    /**
     * The structural guarantee, asserted from the BOOTED router rather than the routes file.
     *
     * This is the control that cannot be defeated by test ordering or by a helper memoising
     * state: whatever the behavioural tests below do, these four stacks must be present.
     */
    public function test_every_merchant_route_carries_session_csrf_auth_entitlement_permission_and_tenancy(): void
    {
        $expected = [
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\EmailIsVerified::class,
        ];

        foreach (['lynomia.overview', 'lynomia.connect.provision', 'lynomia.connect.link', 'lynomia.open'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name));

            foreach ($expected as $middleware) {
                $this->assertContains($middleware, $stack, "{$name} lost " . class_basename($middleware));
            }

            $this->assertTrue(
                (bool) preg_grep('/PlanModuleCheck:LynomiaChat/', array_filter($stack, 'is_string')),
                "{$name} is not gated by the module entitlement."
            );
            $this->assertTrue(
                (bool) preg_grep('/EnsureStoreOwnsLink/', array_filter($stack, 'is_string')),
                "{$name} is not tenant-scoped."
            );
            // Entitlement and tenancy are both tenant-level answers; neither asks whether
            // THIS user may act. Pinned separately so the permission layer cannot be dropped
            // on the assumption that the other two already cover it -- they did not.
            $this->assertTrue(
                (bool) preg_grep(
                    '/Laratrust\\\\Middleware\\\\Permission:Manage Lynomia Chat/',
                    array_filter($stack, 'is_string')
                ),
                "{$name} does not require the 'Manage Lynomia Chat' permission."
            );
        }
    }

    public function test_every_admin_route_requires_the_platform_operator(): void
    {
        foreach (['lynomia.admin.index', 'lynomia.admin.links.approve', 'lynomia.admin.links.revoke'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name));

            $this->assertContains(\App\Http\Middleware\PlatformOperatorOnly::class, $stack, "{$name}");
            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, "{$name}");
        }
    }

    public function test_anonymous_callers_reach_no_integration_route(): void
    {
        Http::fake();

        foreach ([
            ['get', '/lynomia'],
            ['post', '/lynomia/connect/provision'],
            ['post', '/lynomia/connect/link'],
            ['get', '/lynomia/open'],
            ['get', '/super-admin/lynomia-integration'],
        ] as [$verb, $uri]) {
            $response = $this->{$verb}($uri);

            $this->assertContains(
                $response->getStatusCode(),
                [302, 401, 403],
                "{$verb} {$uri} answered {$response->getStatusCode()} to an anonymous caller."
            );
            $this->assertNotSame(200, $response->getStatusCode());
        }

        Http::assertNothingSent();
    }

    // ================================================================ provisioning

    public function test_provisioning_creates_one_active_link_for_the_store(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $this->assertSame(LinkState::ACTIVE, $link->state);
        $this->assertSame(7001, $link->lynomia_account_id);
        $this->assertSame(9001, $link->lynomia_user_id);
        $this->assertSame((int) $store->id, $link->store_id);
        $this->assertSame((int) $owner->creatorId(), $link->owner_user_id);
    }

    public function test_provisioning_never_sends_the_merchant_email_to_lynomia(): void
    {
        $owner = $this->merchant(['email' => 'real-merchant@example.com']);
        $store = $this->storeFor($owner);
        $this->entitle($owner);
        $this->fakeLynomia();

        app(AccountProvisioner::class)->provision($store, $owner->fresh());

        // POST /users with an address that already exists upstream attaches THAT user to this
        // platform app, which is an account-takeover primitive. Provisioning must therefore
        // never send a real person's address.
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/platform/api/v1/users')) {
                return true;
            }

            $this->assertNotSame('real-merchant@example.com', $request['email']);
            $this->assertStringContainsString('@stores.storesyria.invalid', (string) $request['email']);

            return true;
        });
    }

    public function test_provisioning_twice_does_not_create_a_second_workspace(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $first = app(AccountProvisioner::class)->provision($store, $owner);
        $second = app(AccountProvisioner::class)->provision($store, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LynomiaStoreLink::where('store_id', $store->id)->count());

        Http::assertSentCount(3); // account + user + membership, from the first call only.
    }

    public function test_one_lynomia_account_cannot_back_two_stores(): void
    {
        [$ownerA, $storeA] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($storeA, $ownerA);

        $ownerB = $this->merchant();
        $storeB = $this->storeFor($ownerB);

        // The database is the arbiter: lynomia_account_id is UNIQUE.
        $this->expectException(\Illuminate\Database\QueryException::class);

        LynomiaStoreLink::create([
            'store_id' => $storeB->id,
            'owner_user_id' => $ownerB->id,
            'lynomia_account_id' => 7001,
            'state' => LinkState::ACTIVE,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
        ]);
    }

    public function test_a_store_cannot_hold_two_links(): void
    {
        [$owner, $store] = $this->entitledTenant();

        LynomiaStoreLink::create([
            'store_id' => $store->id,
            'owner_user_id' => $owner->id,
            'state' => LinkState::ACTIVE,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        LynomiaStoreLink::create([
            'store_id' => $store->id,
            'owner_user_id' => $owner->id,
            'state' => LinkState::ACTIVE,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
        ]);
    }

    public function test_nothing_is_sent_while_the_integration_is_disabled(): void
    {
        [$owner, $store] = $this->entitledTenant();
        config()->set('lynomia.enabled', false);
        $this->app->forgetInstance(\Workdo\LynomiaChat\app\Services\PlatformApiClient::class);
        Http::fake();

        try {
            app(AccountProvisioner::class)->provision($store, $owner);
            $this->fail('Provisioning succeeded while the integration was disabled.');
        } catch (\Workdo\LynomiaChat\app\Exceptions\LynomiaApiException $e) {
            $this->assertSame('disabled', $e->kind);
        }

        Http::assertNothingSent();
    }

    // ================================================================ existing-account linking

    public function test_a_link_claim_is_pending_and_grants_nothing(): void
    {
        [$owner, $store] = $this->entitledTenant();
        Http::fake();

        $result = app(AccountLinker::class)->request($store, $owner, 4242);
        $link = $result['link'];

        $this->assertSame(LinkState::PENDING_VERIFICATION, $link->state);
        $this->assertFalse($link->allowsSso(), 'A pending claim must not permit SSO.');
        $this->assertNotEmpty($result['verification_code']);

        // Only the hash is stored.
        $this->assertNotSame($result['verification_code'], $link->verification_code_hash);
        $this->assertTrue(Hash::check($result['verification_code'], $link->verification_code_hash));

        // Critically: claiming an account performs NO remote call. In particular it must
        // never POST /users, which upstream would attach an existing Lynomia user to this app.
        Http::assertNothingSent();
    }

    public function test_a_claim_on_an_account_another_store_already_holds_is_refused(): void
    {
        [$ownerA, $storeA] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($storeA, $ownerA);

        $ownerB = $this->merchant();
        $storeB = $this->storeFor($ownerB);

        $this->expectException(\Workdo\LynomiaChat\app\Exceptions\LynomiaApiException::class);
        $this->expectExceptionMessage('already connected to another store');

        app(AccountLinker::class)->request($storeB, $ownerB, 7001);
    }

    public function test_approval_requires_the_code_issued_for_that_claim(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $operator = $this->operator();
        Http::fake();

        $result = app(AccountLinker::class)->request($store, $owner, 4242);

        try {
            app(AccountLinker::class)->approve($result['link'], $operator, 'WRONG-CODE');
            $this->fail('A claim was approved with the wrong verification code.');
        } catch (\Workdo\LynomiaChat\app\Exceptions\LynomiaApiException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        $this->assertSame(LinkState::PENDING_VERIFICATION, $result['link']->fresh()->state);
    }

    public function test_approval_with_the_right_code_activates_and_burns_it(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $operator = $this->operator();
        Http::fake();

        $result = app(AccountLinker::class)->request($store, $owner, 4242);
        $approved = app(AccountLinker::class)->approve($result['link'], $operator, $result['verification_code']);

        $this->assertSame(LinkState::ACTIVE, $approved->state);
        $this->assertSame((int) $operator->id, $approved->verified_by);
        $this->assertNull($approved->verification_code_hash, 'The code must be single-use.');
    }

    public function test_an_expired_claim_cannot_be_approved(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $operator = $this->operator();
        Http::fake();

        $result = app(AccountLinker::class)->request($store, $owner, 4242);
        $result['link']->forceFill(['verification_expires_at' => now()->subMinute()])->save();

        $this->expectException(\Workdo\LynomiaChat\app\Exceptions\LynomiaApiException::class);
        $this->expectExceptionMessage('expired');

        app(AccountLinker::class)->approve($result['link']->fresh(), $operator, $result['verification_code']);
    }

    public function test_a_merchant_cannot_approve_their_own_claim(): void
    {
        [$owner, $store] = $this->entitledTenant();
        Http::fake();

        $result = app(AccountLinker::class)->request($store, $owner, 4242);

        $response = $this->actingAs($owner->fresh())
            ->post("/super-admin/lynomia-integration/links/{$result['link']->id}/approve", [
                'verification_code' => $result['verification_code'],
            ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(LinkState::PENDING_VERIFICATION, $result['link']->fresh()->state);
    }

    // ================================================================ SSO

    public function test_sso_is_refused_for_a_claim_that_is_not_approved(): void
    {
        [$owner, $store] = $this->entitledTenant();
        Http::fake();

        app(AccountLinker::class)->request($store, $owner, 4242);

        $response = $this->actingAs($owner->fresh())->get('/lynomia/open');

        $this->assertStringNotContainsString(
            'sso_auth_token',
            (string) $response->headers->get('Location'),
            'A pending claim was handed a working session.'
        );

        // The decisive assertion: the SSO endpoint was never called at all, so no token was
        // even minted upstream. A refusal that still burns a token would be a weaker control.
        Http::assertNothingSent();
    }

    /**
     * ENTITLEMENT. A merchant whose plan does not include the module is refused by
     * PlanModuleCheck before the controller runs.
     *
     * THIS TEST FOUND A REAL CROSS-TENANT LEAK. ActivatedModule() memoised its result in a
     * `static $active_module` keyed on nothing, so the first non-empty module list resolved
     * in a process answered for every later principal in it. Measured here: this case passed
     * alone and FAILED after an entitled test, because the un-entitled merchant inherited the
     * entitled one's modules and was let through. Under PHP-FPM a request is a fresh process
     * so it stayed hidden; a queue worker, Octane or a test run is not. The memo is now keyed
     * by owner (app/Helper/helper.php), and this test is what holds that.
     *
     * The structural assertion in test_every_merchant_route_carries_... pins that the gate is
     * ON the route; this one pins that it actually refuses.
     */
    public function test_a_merchant_without_the_module_entitlement_is_refused(): void
    {
        $owner = $this->merchant();
        $this->storeFor($owner);
        // Deliberately NOT entitled: no userActiveModule row.
        Http::fake();

        foreach (['/lynomia', '/lynomia/open'] as $uri) {
            $response = $this->actingAs($owner->fresh())->get($uri);

            $this->assertSame(
                302,
                $response->getStatusCode(),
                "{$uri} did not refuse a merchant whose plan omits the module."
            );
            $this->assertStringContainsString(
                'dashboard',
                (string) $response->headers->get('Location'),
                "{$uri} should bounce an un-entitled merchant to the dashboard."
            );
        }

        Http::assertNothingSent();
    }

    public function test_sso_redirects_an_entitled_owner_into_their_workspace(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($store, $owner);

        $response = $this->actingAs($owner->fresh())->get('/lynomia/open');

        $response->assertStatus(302);
        $this->assertStringContainsString('sso_auth_token', (string) $response->headers->get('Location'));
        $this->assertStringStartsWith(self::FAKE_BASE, (string) $response->headers->get('Location'));
    }

    /**
     * The SSO url is a five-minute single-use credential. It may be a redirect target and
     * nothing else -- never a flash message, never a rendered value, never a stored column.
     */
    public function test_the_sso_url_is_never_persisted_or_flashed(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $this->actingAs($owner->fresh())->get('/lynomia/open');

        $row = (array) LynomiaStoreLink::find($link->id)->getAttributes();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(
                'sso_auth_token',
                (string) $value,
                "The SSO token was written to lynomia_store_links.{$column}."
            );
        }

        $this->assertNull(session('lynomia_sso_url'));
    }

    // ================================================================ tenant isolation

    public function test_a_merchant_cannot_open_another_tenants_workspace(): void
    {
        [$ownerA, $storeA] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($storeA, $ownerA);

        // B is a different tenant who has pointed current_store at A's store. changeStore
        // now refuses that, but this proves the integration does not depend on it: the
        // module re-derives ownership itself.
        $ownerB = $this->merchant();
        $this->entitle($ownerB);
        $ownerB->current_store = $storeA->id;
        $ownerB->save();

        $response = $this->actingAs($ownerB->fresh())->get('/lynomia/open');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringNotContainsString('sso_auth_token', (string) $response->headers->get('Location'));

        // The SSO endpoint must never have been reached for A's user. Counting is the
        // reliable form here: provisioning already sent three requests, and a leak would be
        // a FOURTH -- the /users/{id}/login call.
        Http::assertSentCount(3);
    }

    public function test_the_overview_of_another_tenants_store_exposes_no_link(): void
    {
        [$ownerA, $storeA] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($storeA, $ownerA);

        $ownerB = $this->merchant();
        $this->entitle($ownerB);
        $ownerB->current_store = $storeA->id;
        $ownerB->save();

        $response = $this->actingAs($ownerB->fresh())->get('/lynomia');

        // Refused outright rather than rendered with A's workspace.
        $this->assertSame(302, $response->getStatusCode());
    }

    // ------------------------------------------------- authorization within the tenant

    /**
     * FINDING 1. Entitlement is the plan's answer and tenancy is the store's; neither is a
     * ROLE check. A staff member inherits both -- PlanModuleCheck resolves the OWNER's module
     * list, and creatorId() resolves staff to the owning admin so EnsureStoreOwnsLink matches
     * the owner's row. The module seeds `Manage Lynomia Chat` and hides the menu on it, but
     * hiding a menu is not authorization: the staff member types the path.
     *
     * /lynomia/open is the one that matters. The provisioned Lynomia user is attached as
     * `administrator` (AccountProvisioner), so a successful redirect hands a warehouse packer
     * full read/write over every customer conversation in the merchant's workspace.
     */
    public function test_staff_without_the_permission_reach_no_merchant_route(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($store, $owner);

        $staff = $this->staffOf($owner->fresh());

        $this->assertFalse($staff->isAbleTo('Manage Lynomia Chat'), 'fixture: staff must not hold the permission');

        // Provisioning sent three requests. Any SSO leak would be a fourth.
        $sentBefore = count(Http::recorded());

        foreach (['get:/lynomia', 'get:/lynomia/open'] as $probe) {
            [$verb, $uri] = explode(':', $probe, 2);
            $response = $this->actingAs($staff)->{$verb}($uri);

            $this->assertSame(403, $response->getStatusCode(), "{$uri} admitted staff");
            $this->assertStringNotContainsString(
                'sso_auth_token',
                (string) $response->headers->get('Location'),
                "{$uri} handed staff an SSO url"
            );
        }

        foreach (['/lynomia/connect/provision', '/lynomia/connect/link'] as $uri) {
            $response = $this->actingAs($staff)->post($uri, ['lynomia_account_id' => 4242]);
            $this->assertSame(403, $response->getStatusCode(), "{$uri} admitted staff");
            $this->assertNull(session('lynomia_verification_code'), "{$uri} leaked a claim code to staff");
        }

        $this->assertSame($sentBefore, count(Http::recorded()), 'staff caused a call to Lynomia');
    }

    /**
     * FINDING 2. request() refused a re-claim only when the row was ACTIVE, and the attribute
     * set it force-filled rewrote lynomia_account_id but left lynomia_user_id untouched. A
     * revoked row therefore kept the ORIGINAL workspace's user id while presenting itself as a
     * fresh claim for an unrelated account, and allowsSso() asks only that both ids are
     * non-null -- so approving the claim the operator was shown minted a session for the
     * workspace they had revoked.
     */
    public function test_a_revoked_link_cannot_be_reclaimed_back_into_the_old_workspace(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh());
        $originalUserId = (int) $link->lynomia_user_id;
        $this->assertGreaterThan(0, $originalUserId);

        app(AccountLinker::class)->revoke($link->fresh(), $this->operator(), 'abuse');

        // The merchant tries to re-open the row as a claim on a DIFFERENT account. Before the
        // fix this succeeded and silently carried $originalUserId across, so an operator who
        // approved "account 777777" was in fact restoring the workspace they had revoked.
        try {
            app(AccountLinker::class)->request($store, $owner->fresh(), 777777);
            $this->fail('a revoked link was re-opened as a claim');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $after = $link->fresh();
        $this->assertSame(LinkState::REVOKED, $after->state, 'the revoked row was mutated');
        $this->assertNull($after->verification_code_hash, 'a claim code was issued on a revoked row');

        // And the merchant is still locked out.
        $sentBefore = count(Http::recorded());
        $response = $this->actingAs($owner->fresh())->get('/lynomia/open');

        $this->assertStringNotContainsString(
            'sso_auth_token',
            (string) $response->headers->get('Location'),
            'a revoked link still opened a workspace'
        );
        $this->assertSame($sentBefore, count(Http::recorded()), 'a login call was made for a revoked workspace');
    }

    /**
     * The middleware authorized the LINK but not the STORE, so the one case with no link to
     * compare against -- a foreign store that has never connected -- passed its id through to
     * the controller, and provisioning claimed it. store_id is unique, so the real owner would
     * then have been permanently unable to connect.
     *
     * No HTTP path writes users.current_store without an ownership check today, so this was
     * defence-in-depth rather than a live exploit; the fixture sets the column directly. That
     * is the point: this middleware is not supposed to depend on those checks staying correct.
     */
    public function test_a_merchant_cannot_provision_onto_another_tenants_unconnected_store(): void
    {
        [$ownerA, $storeA] = $this->entitledTenant();

        $ownerB = $this->merchant();
        $storeB = $this->storeFor($ownerB);
        $this->entitle($ownerB);

        $this->fakeLynomia();

        $ownerB->current_store = $storeA->id;
        $ownerB->save();

        $response = $this->actingAs($ownerB->fresh())->post('/lynomia/connect/provision');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse(
            LynomiaStoreLink::forStore((int) $storeA->id)->exists(),
            "another tenant's store was claimed"
        );
        $this->assertSame(0, count(Http::recorded()), 'a workspace was created for a foreign store');
    }

    /**
     * THE GATE AND THE SEEDER ARE NOW LOAD-BEARING FOR EACH OTHER.
     *
     * The merchant routes require `Manage Lynomia Chat`. Nothing grants a merchant owner that
     * permission except PermissionTableSeeder, which attaches it to the single global `admin`
     * role every owner carries. If the seeder has not run on an installation, the gate does
     * not fail open -- it fails CLOSED, and every merchant owner on the platform gets a 403
     * on a feature their plan includes. So the seeder is asserted here rather than assumed,
     * and `php artisan package:seed LynomiaChat` is a deployment prerequisite, not a nicety.
     */
    public function test_the_seeder_grants_the_permission_the_routes_require(): void
    {
        $adminRole = Role::forceCreate([
            'name' => 'admin',
            'display_name' => 'Admin',
            'guard_name' => 'web',
            'created_by' => 0,
        ]);

        (new \Workdo\LynomiaChat\database\seeders\PermissionTableSeeder())->run();

        $this->assertTrue(
            $adminRole->fresh()->hasPermission('Manage Lynomia Chat'),
            'the global admin role does not carry the permission the merchant routes require'
        );

        // THE NAMESPACE CASE IS LOAD-BEARING, so pin it rather than trusting it.
        // PackageSeed::getSeederClass() builds the name as
        // Workdo\{Module}\database\seeders\{Module}DatabaseSeeder -- lower case, matching the
        // directories on disk, which is what every other module in packages/workdo declares.
        // This module first declared Database\Seeders instead. PSR-4 maps the namespace
        // straight onto a path, so on a case-sensitive filesystem the capitalised spelling
        // resolves to src/Database/Seeders/..., which does not exist: `new PermissionTableSeeder`
        // inside the module seeder fatals, and package:seed finds nothing to run. The
        // permission below is then never granted and -- now that the routes require it --
        // every merchant owner gets a 403 on a feature their plan includes.
        $this->assertTrue(
            class_exists('Workdo\\LynomiaChat\\database\\seeders\\LynomiaChatDatabaseSeeder'),
            'package:seed cannot resolve the module seeder'
        );
    }
}
