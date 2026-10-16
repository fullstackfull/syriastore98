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
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;
use Workdo\LynomiaChat\app\Support\LinkState;
use Workdo\LynomiaChat\app\Support\ProvisioningStage;

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
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
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
        foreach ([
            'lynomia.admin.index',
            'lynomia.admin.links.approve',
            'lynomia.admin.links.revoke',
            'lynomia.admin.links.restore',
            // P6A. It reaches across the boundary with the platform credential, so it is
            // behind the same door as every other administrative act here.
            'lynomia.admin.links.remote_health',
        ] as $name) {
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
            ['get', '/super-admin/lynomia-integration/links/1/remote-health'],
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
        $this->assertSame((int) $store->id, $link->store_id);
        $this->assertSame((int) $owner->creatorId(), $link->owner_user_id);

        // NOBODY IS RECORDED ON THE LINK, because nobody signs in as the link any more. The
        // workspace's one administrator is the tenant owner, and it is their own identity.
        $this->assertNull($link->lynomia_user_id);

        $identity = LynomiaStoreIdentity::forWorkspacePerson(7001, (int) $owner->id)->first();
        $this->assertNotNull($identity, 'the owner was not given an identity in the workspace');
        $this->assertSame(9001, (int) $identity->lynomia_user_id);
        $this->assertSame(LynomiaStoreIdentity::ROLE_ADMINISTRATOR, $identity->role);
        $this->assertSame((int) $store->id, $identity->store_id);
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

        // From the FIRST call only: lookup, account, user, membership, seat grant, then the
        // event subscription (a scoped-token mint, a webhook list and a webhook create). The
        // lookup is the adopt-before-create step. The second provision() returns from the row
        // and sends nothing, which is the property under test.
        $firstCallTraffic = count(Http::recorded());
        $this->assertGreaterThan(0, $firstCallTraffic);
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

    // ----------------------------------------------- linking an existing workspace

    public function test_a_claim_is_pending_proven_and_grants_nothing_until_approved(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $result = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
        $link = $result['link'];

        $this->assertSame(LinkState::PENDING_VERIFICATION, $link->state);
        $this->assertFalse($link->allowsSso(), 'A pending claim must not permit SSO.');

        // Lynomia has answered which workspace this is and who vouched for it. The authorizer
        // is recorded for the operator reviewing the claim and is NOT granted: nobody signs in
        // as them, and this app holds nothing on their identity.
        $this->assertNotNull($link->ownership_proven_at);
        $this->assertSame(7001, (int) $link->lynomia_account_id);
        $this->assertSame(4242, (int) $link->lynomia_user_id);

        $this->assertNotEmpty($result['verification_code']);
        $this->assertNotSame($result['verification_code'], $link->verification_code_hash);
        $this->assertTrue(Hash::check($result['verification_code'], $link->verification_code_hash));

        // Exactly one remote call, and it is the redemption. In particular NOT POST /users,
        // which upstream would attach an existing Lynomia user to this app -- the takeover
        // primitive this whole flow exists to avoid.
        $this->assertSame(1, $this->sentCount('POST', '/account_links'));
        $this->assertSame(0, $this->sentCount('POST', '/platform/api/v1/users'));
        Http::assertSentCount(1);
    }

    public function test_the_merchant_never_names_the_account_they_are_claiming(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 5150, 'authorized_by' => 6160]);

        // The merchant asks for account 7001. They are given 5150, because the CODE decides
        // and they do not. There is no input on this path that an attacker could aim.
        $link = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789')['link'];

        $this->assertSame(5150, (int) $link->lynomia_account_id);
        $this->assertSame(6160, (int) $link->lynomia_user_id);

        $sent = Http::recorded()[0][0];
        $this->assertSame(['code' => 'ABCD2345EFGH6789'], $sent->data());
    }

    /**
     * DECISION 1, THE MERCHANT-FACING HALF. A link code is now minted FOR a named integration
     * and is redeemable only by it. A merchant who pastes the code they made for some other
     * approved integration gets a sentence they can act on -- and nothing of theirs is spent,
     * because upstream checks the audience BEFORE consuming the code.
     *
     * The distinction is carried by a machine-readable error_code, not by matching English:
     * the sentence below is written here, in Store Syria, and survives any rewording on the
     * Lynomia side.
     */
    public function test_a_code_for_a_different_integration_says_so_and_links_nothing(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['fail' => [
            'POST /platform/api/v1/account_links' => Http::response([
                'error' => 'This code was issued for a different integration',
                'error_code' => 'wrong_platform_app',
            ], 422),
        ]]);

        try {
            app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
            $this->fail('a code for another integration was accepted');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
            $this->assertStringContainsString('different integration', $e->getMessage());
            // Not the generic rejection, which is what a merchant used to be told.
            $this->assertStringNotContainsString('rejected the request as invalid', $e->getMessage());
        }

        $this->assertNull(LynomiaStoreLink::forStore((int) $store->id)->first());
    }

    public function test_a_dead_code_tells_the_merchant_to_make_a_new_one(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['fail' => [
            'POST /platform/api/v1/account_links' => Http::response([
                'error' => 'Invalid or expired code',
                'error_code' => 'invalid_code',
            ], 422),
        ]]);

        try {
            app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
            $this->fail('a dead code was accepted');
        } catch (LynomiaApiException $e) {
            $this->assertStringContainsString('generate a new one', $e->getMessage());
        }
    }

    /**
     * An unrecognised error_code must not invent a sentence. The merchant gets the ordinary
     * refusal rather than a message this side made up about a condition it does not know.
     */
    public function test_an_unknown_error_code_falls_back_to_the_generic_refusal(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['fail' => [
            'POST /platform/api/v1/account_links' => Http::response([
                'error' => 'Something new',
                'error_code' => 'something_nobody_here_knows',
            ], 422),
        ]]);

        try {
            app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
            $this->fail('an unknown refusal was accepted');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
            $this->assertStringContainsString('rejected the request as invalid', $e->getMessage());
            // And never the provider's own words.
            $this->assertStringNotContainsString('Something new', $e->getMessage());
        }
    }

    public function test_a_code_lynomia_rejects_links_nothing(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia([
            'fail' => ['POST /platform/api/v1/account_links' => Http::response(['error' => 'Invalid or expired code'], 422)],
        ]);

        try {
            app(AccountLinker::class)->request($store, $owner, 'ZZZZ9999ZZZZ9999');
            $this->fail('a rejected code produced a link');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        // Nothing written: no row, and therefore no store pointing at a workspace that never agreed.
        $this->assertFalse(LynomiaStoreLink::forStore((int) $store->id)->exists());
    }

    public function test_a_claim_on_a_workspace_another_store_holds_is_refused_and_their_grant_left_alone(): void
    {
        [$ownerA, $storeA] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountLinker::class)->request($storeA, $ownerA, 'ABCD2345EFGH6789');

        $ownerB = $this->merchant();
        $storeB = $this->storeFor($ownerB);
        $this->entitle($ownerB);

        try {
            app(AccountLinker::class)->request($storeB, $ownerB->fresh(), 'JKLM2345NPQR6789');
            $this->fail('two stores were allowed to hold one workspace');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $this->assertFalse(LynomiaStoreLink::forStore((int) $storeB->id)->exists());

        // AND STORE A IS LEFT ALONE. The index that fired is lynomia_account_id, which means
        // store A's live link owns this workspace -- so the grant is not something store B's
        // redemption created, it is what store A's link runs on. Handing it back here would
        // break SSO for a store that did nothing wrong, hide its workspace from the recovery
        // lookup, and make the only operator remedy produce a second workspace with all the
        // conversations stranded in the first.
        $this->assertSame(0, $this->sentCount('DELETE', '/account_links/7001'));

        $a = LynomiaStoreLink::forStore((int) $storeA->id)->first();
        $this->assertSame(7001, (int) $a->lynomia_account_id);
    }

    public function test_approval_requires_the_code_issued_for_that_claim(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $result = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');

        try {
            app(AccountLinker::class)->approve($result['link']->fresh(), $this->operator(), 'WRONGCODE1');
            $this->fail('a claim was approved with the wrong code');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $this->assertSame(LinkState::PENDING_VERIFICATION, $result['link']->fresh()->state);
    }

    public function test_approval_with_the_right_code_activates_and_burns_it(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $result = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');

        $approved = app(AccountLinker::class)->approve($result['link']->fresh(), $this->operator(), $result['verification_code']);

        $this->assertSame(LinkState::ACTIVE, $approved->state);
        $this->assertNull($approved->verification_code_hash, 'the code must be single use');
        $this->assertTrue($approved->allowsSso(), 'a proven, approved link must be openable');
    }

    /**
     * The gap that made the old flow unfinished: an approved claim that could not be opened.
     * Proof supplies the user id, approval supplies the permission, and SSO needs both.
     */
    public function test_a_proven_and_approved_claim_can_actually_open_the_workspace(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $result = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
        app(AccountLinker::class)->approve($result['link']->fresh(), $this->operator(), $result['verification_code']);

        $response = $this->actingAs($owner->fresh())->get('/lynomia/open');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('sso_auth_token', (string) $response->headers->get('Location'));
        $this->assertSame(1, $this->sentCount('GET', '/users/9001/login'));
    }

    /**
     * An operator answers a commercial question. They are in no position to answer "does this
     * merchant control that workspace?", and must not be able to grant access by being
     * persuaded -- so a row Lynomia never confirmed cannot be approved at all.
     */
    public function test_an_operator_cannot_approve_a_claim_lynomia_never_confirmed(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $link = LynomiaStoreLink::create([
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'lynomia_account_id' => 4242,
            'lynomia_user_id' => 4243,
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => LynomiaStoreLink::MODE_LINK_EXISTING,
            'verification_code_hash' => Hash::make('HANDMADE12'),
            'verification_expires_at' => now()->addHour(),
        ]);

        try {
            app(AccountLinker::class)->approve($link, $this->operator(), 'HANDMADE12');
            $this->fail('an unproven claim was approved');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $this->assertSame(LinkState::PENDING_VERIFICATION, $link->fresh()->state);
        $this->assertFalse($link->fresh()->allowsSso());
    }

    public function test_an_expired_claim_cannot_be_approved(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $result = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');

        $result['link']->forceFill(['verification_expires_at' => now()->subMinute()])->save();

        try {
            app(AccountLinker::class)->approve($result['link']->fresh(), $this->operator(), $result['verification_code']);
            $this->fail('an expired claim was approved');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }
    }

    public function test_a_merchant_cannot_approve_their_own_claim(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $result = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');

        $response = $this->actingAs($owner->fresh())
            ->post("/super-admin/lynomia-integration/links/{$result['link']->id}/approve", [
                'verification_code' => $result['verification_code'],
            ]);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(LinkState::PENDING_VERIFICATION, $result['link']->fresh()->state);
    }

    public function test_sso_is_refused_for_a_claim_that_is_not_approved(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');

        $response = $this->actingAs($owner->fresh())->get('/lynomia/open');

        $this->assertStringNotContainsString('sso_auth_token', (string) $response->headers->get('Location'));
        $this->assertSame(0, $this->sentCount('GET', '/login'));
    }

    public function test_revoking_a_linked_workspace_hands_the_grant_back(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $result = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
        app(AccountLinker::class)->approve($result['link']->fresh(), $this->operator(), $result['verification_code']);

        app(AccountLinker::class)->revoke($result['link']->fresh(), $this->operator(), 'merchant left');

        // It is the merchant's own workspace; keeping access to it after the link is gone
        // would be an access with no mapping behind it.
        $this->assertSame(1, $this->sentCount('DELETE', '/account_links/7001'));
        $this->assertSame(LinkState::REVOKED, $result['link']->fresh()->state);
    }

    public function test_revoking_a_provisioned_workspace_keeps_the_grant(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $link = app(AccountProvisioner::class)->provision($store, $owner);

        app(AccountLinker::class)->revoke($link->fresh(), $this->operator(), 'non-payment');

        // This workspace is the integration's own creation and the grant is the only way back
        // to it. Handing it back would orphan the merchant's conversations for good.
        $this->assertSame(0, $this->sentCount('DELETE', '/account_links/7001'));
        $this->assertSame(LinkState::REVOKED, $link->fresh()->state);
    }

    public function test_a_revocation_stands_even_when_lynomia_cannot_be_reached(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $result = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
        app(AccountLinker::class)->approve($result['link']->fresh(), $this->operator(), $result['verification_code']);

        $this->fakeLynomia(['fail' => ['DELETE /platform/api/v1/account_links/7001' => Http::failedConnection()]]);

        // An abuse revocation must not be blocked by an unreachable provider: access stops
        // here whether or not the hand-back lands.
        app(AccountLinker::class)->revoke($result['link']->fresh(), $this->operator(), 'abuse');

        $this->assertSame(LinkState::REVOKED, $result['link']->fresh()->state);
        $this->assertFalse($result['link']->fresh()->allowsSso());
    }

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

        // The WHOLE flashed bag, not one guessed key. An earlier version asserted
        // session('lynomia_sso_url') was null -- a key no production code writes, so it
        // could never fail, while layouts/app.blade.php renders Session::get('success')
        // UNESCAPED into a toast. Scanning everything is the only version that would catch
        // a url flashed under a name nobody predicted.
        foreach (session()->all() as $key => $value) {
            $this->assertStringNotContainsString(
                'sso_auth_token',
                is_scalar($value) ? (string) $value : json_encode($value),
                "the SSO url was flashed under session key {$key}"
            );
        }
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

        // The SSO endpoint must never have been reached for A's user. Asserted directly rather
        // than by counting: provisioning's own traffic has grown with each phase, and a test
        // that pins a total has to be edited every time something legitimate is added -- which
        // is how a leak eventually gets waved through as "the count moved again".
        $this->assertSame(0, $this->sentCount('GET', '/login'));
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
        $originalAccountId = (int) $link->lynomia_account_id;
        $this->assertGreaterThan(0, $originalAccountId);

        app(AccountLinker::class)->revoke($link->fresh(), $this->operator(), 'abuse');

        // The merchant tries to re-open the row as a claim on a DIFFERENT workspace. Before
        // the fix this succeeded and silently carried the old workspace's user id across, so an operator
        // who approved an unrelated account was in fact restoring the one they had revoked.
        try {
            app(AccountLinker::class)->request($store, $owner->fresh(), 'JKLM2345NPQR6789');
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

    // ------------------------------------- provisioning: resumption, idempotency, races

    /**
     * THE DUPLICATE THIS WHOLE DESIGN EXISTS TO PREVENT.
     *
     * A read timeout is the case where the server did all the work and only the answer was
     * lost. The workspace exists; its id is known to Lynomia alone. A blind retry -- which is
     * what the transport-level retry used to do, on POST /accounts, automatically -- makes a
     * SECOND workspace, and the merchant's conversations then start landing in whichever one
     * they are eventually pointed at.
     */
    public function test_a_create_whose_answer_was_lost_is_adopted_not_repeated(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $this->fakeLynomia(['fail' => ['POST /platform/api/v1/accounts' => Http::failedConnection()]]);

        try {
            app(AccountProvisioner::class)->provision($store, $owner);
            $this->fail('a dropped create reported success');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_UNREACHABLE, $e->kind);
        }

        // The create was attempted exactly ONCE. Retrying it on a dropped connection is how
        // the duplicate appears, so that retry is switched off for this call specifically.
        $this->assertSame(1, $this->sentCount('POST', '/platform/api/v1/accounts'));

        $link = LynomiaStoreLink::forStore((int) $store->id)->first();
        $this->assertNotNull($link, 'the row must survive to carry the retry');
        $this->assertNull($link->lynomia_account_id);
        $this->assertNotNull($link->last_error);

        // Lynomia did in fact create it. The retry finds it by the store stamped on it.
        $this->fakeLynomia(['inventory' => $this->inventoryFor((int) $store->id, 7001, 'Recovered WS')]);

        $resumed = app(AccountProvisioner::class)->provision($store->fresh(), $owner->fresh());

        $this->assertSame(LinkState::ACTIVE, $resumed->state);
        $this->assertSame(7001, (int) $resumed->lynomia_account_id);
        $this->assertSame('Recovered WS', $resumed->lynomia_account_name);
        $this->assertSame(0, $this->sentCount('POST', '/platform/api/v1/accounts'), 'a second workspace was created');
        $this->assertSame(1, LynomiaStoreLink::count());
    }

    public function test_provisioning_resumes_from_the_step_that_failed(): void
    {
        [$owner, $store] = $this->entitledTenant();

        // The account lands; the user call dies.
        $this->fakeLynomia(['fail' => ['POST /platform/api/v1/users' => Http::failedConnection()]]);

        try {
            app(AccountProvisioner::class)->provision($store, $owner);
            $this->fail('a dropped user create reported success');
        } catch (LynomiaApiException) {
            // expected
        }

        $link = LynomiaStoreLink::forStore((int) $store->id)->first();
        // The account id was written the moment it was known, not batched to the end -- which
        // is what makes the next attempt a resumption rather than a restart.
        $this->assertSame(7001, (int) $link->lynomia_account_id);
        $this->assertSame(ProvisioningStage::ACCOUNT_CREATED, $link->provisioning_stage);
        $this->assertNull($link->lynomia_user_id);

        $this->fakeLynomia();
        $resumed = app(AccountProvisioner::class)->provision($store->fresh(), $owner->fresh());

        $this->assertSame(LinkState::ACTIVE, $resumed->state);
        $this->assertSame(ProvisioningStage::COMPLETE, $resumed->provisioning_stage);
        // It did not redo the account: no create, and no lookup either, because the id was known.
        $this->assertSame(0, $this->sentCount('POST', '/platform/api/v1/accounts'));
        $this->assertSame(0, $this->sentCount('GET', '/platform/api/v1/accounts'));
        $this->assertSame(2, (int) $resumed->provisioning_attempts);
    }

    public function test_an_attach_that_failed_is_retried_without_recreating_anything(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $this->fakeLynomia(['fail' => ['POST /platform/api/v1/accounts/7001/account_users' => Http::response([], 500)]]);

        try {
            app(AccountProvisioner::class)->provision($store, $owner);
            $this->fail('a failed attach reported success');
        } catch (LynomiaApiException) {
            // expected
        }

        $link = LynomiaStoreLink::forStore((int) $store->id)->first();
        // The workspace is recorded; the owner has no seat in it yet, which is exactly what
        // ACCOUNT_CREATED means and exactly where the next attempt picks up.
        $this->assertSame(ProvisioningStage::ACCOUNT_CREATED, $link->provisioning_stage);
        $this->assertSame(0, LynomiaStoreIdentity::forWorkspace(7001)->count());

        $firstAddress = $this->lastUserCreateAddress();

        $this->fakeLynomia();
        $resumed = app(AccountProvisioner::class)->provision($store->fresh(), $owner->fresh());

        $this->assertSame(LinkState::ACTIVE, $resumed->state);
        $this->assertSame(0, $this->sentCount('POST', '/platform/api/v1/accounts'));
        $this->assertSame(1, $this->sentCount('POST', '/account_users'));

        // IT DOES RE-SEND POST /users, AND THAT IS NOT A SECOND USER. The identity row is
        // written only once the attach lands, so a resume has nothing local to resolve from
        // and asks Lynomia again -- by the same deterministic address, which upstream
        // resolves to the user the first attempt already created. Asserting the ADDRESS
        // rather than the call count is what distinguishes "resolved the same one" from
        // "made another", which a count cannot tell apart.
        $this->assertSame(1, $this->sentCount('POST', '/platform/api/v1/users'));
        $this->assertSame($firstAddress, $this->lastUserCreateAddress());

        // And exactly one seat exists at the end of it.
        $this->assertSame(1, LynomiaStoreIdentity::forWorkspace(7001)->count());
    }


    /**
     * Two concurrent provision requests for one store.
     *
     * THE LOCK IS A CONDITIONAL UPDATE, AND THAT IS WHAT IS BEING TESTED. A single process
     * cannot genuinely run two requests at once here, so this does not pretend to: it holds
     * the first attempt's claim -- exactly the row state a request still talking to Lynomia
     * leaves behind -- and asserts the second caller is turned away without sending anything.
     * The database settles the real race, because a WHERE clause that only one UPDATE can
     * match is decided by the database and not by this code.
     */
    public function test_a_second_concurrent_provision_is_refused_and_sends_nothing(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        LynomiaStoreLink::create([
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
            'provisioning_stage' => ProvisioningStage::CLAIMED,
            'provisioning_started_at' => now(),
        ]);

        try {
            app(AccountProvisioner::class)->provision($store, $owner);
            $this->fail('two attempts ran against one store');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_IN_PROGRESS, $e->kind);
            $this->assertTrue($e->isTransient(), 'the merchant should be told to wait, not that it failed');
        }

        Http::assertNothingSent();
    }

    /**
     * The guard has to be the UPDATE itself: only one of two callers may come away with it.
     *
     * DRIVEN THROUGH THE REAL acquireLock, not a copy of its query. An earlier version of
     * this test rebuilt the conditional UPDATE in the test body and asserted 1 then 0 on
     * that -- which passed with the production method replaced by `return now();`, because
     * the production method was never called. A test that restates the implementation
     * measures the restatement.
     */
    public function test_only_one_caller_can_take_the_provisioning_claim(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $link = LynomiaStoreLink::create([
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
            'provisioning_stage' => ProvisioningStage::CLAIMED,
        ]);

        $acquire = new \ReflectionMethod(AccountProvisioner::class, 'acquireLock');
        $acquire->setAccessible(true);
        $provisioner = app(AccountProvisioner::class);

        $first = $acquire->invoke($provisioner, $link);
        $second = $acquire->invoke($provisioner, $link->fresh());

        $this->assertNotNull($first, 'the first caller must take the claim');
        $this->assertNull($second, 'the second caller must find nothing to take');
        $this->assertSame(1, (int) $link->fresh()->provisioning_attempts, 'only one attempt was counted');
    }

    /**
     * A process killed mid-provision must not wedge the store forever. Taking over early
     * would be safe anyway -- every step is idempotent or guarded by the adopt-before-create
     * lookup -- but it would waste calls racing a request that is still running.
     */
    public function test_a_stale_claim_from_a_dead_process_is_taken_over(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        LynomiaStoreLink::create([
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
            'provisioning_stage' => ProvisioningStage::CLAIMED,
            'provisioning_started_at' => now()->subHours(2),
        ]);

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $this->assertSame(LinkState::ACTIVE, $link->state);
        $this->assertNull($link->provisioning_started_at, 'the claim must be released when the attempt ends');
    }

    public function test_the_claim_is_released_even_when_the_attempt_throws(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['fail' => ['POST /platform/api/v1/accounts' => Http::response([], 500)]]);

        try {
            app(AccountProvisioner::class)->provision($store, $owner);
        } catch (LynomiaApiException) {
            // expected
        }

        // A failed attempt that kept the claim would block every retry until it went stale.
        $this->assertNull(LynomiaStoreLink::forStore((int) $store->id)->first()->provisioning_started_at);
    }

    public function test_repeated_provisioning_of_a_finished_link_reaches_lynomia_not_at_all(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($store, $owner);

        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($store->fresh(), $owner->fresh());
        app(AccountProvisioner::class)->provision($store->fresh(), $owner->fresh());

        Http::assertNothingSent();
        $this->assertSame(1, LynomiaStoreLink::count());
    }

    /**
     * Two workspaces stamped with the same store is already a failure; picking one would
     * silently strand the other with a merchant's conversations in it.
     */
    public function test_two_workspaces_claiming_one_store_is_refused_rather_than_guessed(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $install = config('lynomia.installation_ref');
        $this->fakeLynomia(['inventory' => [
            ['id' => 7001, 'name' => 'A', 'custom_attributes' => ['store_syria_store_id' => (int) $store->id, 'store_syria_installation' => $install]],
            ['id' => 7002, 'name' => 'B', 'custom_attributes' => ['store_syria_store_id' => (int) $store->id, 'store_syria_installation' => $install]],
        ]]);

        try {
            app(AccountProvisioner::class)->provision($store, $owner);
            $this->fail('a duplicate workspace pair was silently resolved');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $this->assertSame(0, $this->sentCount('POST', '/platform/api/v1/accounts'));
        $this->assertNull(LynomiaStoreLink::forStore((int) $store->id)->first()->lynomia_account_id);
    }

    public function test_another_stores_workspace_is_never_adopted(): void
    {
        [$owner, $store] = $this->entitledTenant();

        // The lookup is scoped by Lynomia to this app's permissibles, but the client filters
        // again on the stamp: an inventory row for a DIFFERENT store must not be taken.
        $this->fakeLynomia(['inventory' => $this->inventoryFor((int) $store->id + 999, 7050)]);

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $this->assertSame(7001, (int) $link->lynomia_account_id, 'it should have created its own');
        $this->assertSame(1, $this->sentCount('POST', '/platform/api/v1/accounts'));
    }

    public function test_provisioning_stamps_the_store_on_the_workspace_it_creates(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        app(AccountProvisioner::class)->provision($store, $owner);

        $create = collect(Http::recorded())
            ->first(fn ($pair) => strtoupper($pair[0]->method()) === 'POST'
                && str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/platform/api/v1/accounts'));

        // Without this stamp there is no way to find the workspace again after a lost answer,
        // and the whole recovery path above has nothing to match on. The installation half is
        // what stops a cloned database adopting the workspace it was cloned from.
        $this->assertSame(
            [
                'store_syria_store_id' => (int) $store->id,
                'store_syria_installation' => config('lynomia.installation_ref'),
            ],
            $create[0]->data()['custom_attributes']
        );
    }

    public function test_a_provisioning_attempt_cannot_hijack_a_claim_flow(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');

        try {
            app(AccountProvisioner::class)->provision($store->fresh(), $owner->fresh());
            $this->fail('provisioning overwrote a pending claim');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $link = LynomiaStoreLink::forStore((int) $store->id)->first();
        $this->assertSame(LynomiaStoreLink::MODE_LINK_EXISTING, $link->link_mode);
        $this->assertSame(0, $this->sentCount('POST', '/platform/api/v1/accounts'));
    }

    public function test_a_revoked_store_cannot_be_reprovisioned_by_the_merchant(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $link = app(AccountProvisioner::class)->provision($store, $owner);
        app(AccountLinker::class)->revoke($link->fresh(), $this->operator(), 'abuse');

        $this->fakeLynomia();

        try {
            app(AccountProvisioner::class)->provision($store->fresh(), $owner->fresh());
            $this->fail('a revoked store provisioned itself a fresh workspace');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------- regressions from the adversarial review

    /**
     * A merchant with two tabs could abandon a half-finished provision by starting a claim in
     * the other one. The row was pending_verification, which assertClaimable accepted without
     * looking at link_mode, so the claim overwrote it -- and the workspace provisioning had
     * already created was left with nothing referencing it, no revocation to hand its grant
     * back, and provision() refusing the store forever because the mode no longer matched.
     */
    public function test_a_claim_cannot_abandon_a_half_finished_provision(): void
    {
        [$owner, $store] = $this->entitledTenant();

        // Provisioning gets as far as creating the workspace, then dies.
        $this->fakeLynomia(['fail' => ['POST /platform/api/v1/users' => Http::failedConnection()]]);
        try {
            app(AccountProvisioner::class)->provision($store, $owner);
        } catch (LynomiaApiException) {
            // expected
        }

        $this->assertSame(7001, (int) LynomiaStoreLink::forStore((int) $store->id)->first()->lynomia_account_id);

        $this->fakeLynomia(['account_id' => 8500, 'user_id' => 8600]);

        try {
            app(AccountLinker::class)->request($store->fresh(), $owner->fresh(), 'ABCD2345EFGH6789');
            $this->fail('a claim overwrote an unfinished provision');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $link = LynomiaStoreLink::forStore((int) $store->id)->first();
        $this->assertSame(LynomiaStoreLink::MODE_PROVISION, $link->link_mode);
        $this->assertSame(7001, (int) $link->lynomia_account_id, 'the provisioned workspace was orphaned');
        // No code was even redeemed: the refusal happens before anything is spent.
        $this->assertSame(0, $this->sentCount('POST', '/account_links'));

        // And the merchant is not wedged -- "Continue setup" still finishes what they started.
        $this->fakeLynomia();
        $resumed = app(AccountProvisioner::class)->provision($store->fresh(), $owner->fresh());
        $this->assertSame(LinkState::ACTIVE, $resumed->state);
        $this->assertSame(7001, (int) $resumed->lynomia_account_id);
    }

    public function test_a_claim_cannot_abandon_a_provision_that_created_nothing_yet(): void
    {
        [$owner, $store] = $this->entitledTenant();

        // The create itself dropped -- so this row has no id, but a workspace may exist
        // upstream and only the adopt-before-create lookup can find it. Converting the row to
        // a claim would retire that lookup and strand it.
        $this->fakeLynomia(['fail' => ['POST /platform/api/v1/accounts' => Http::failedConnection()]]);
        try {
            app(AccountProvisioner::class)->provision($store, $owner);
        } catch (LynomiaApiException) {
            // expected
        }

        $this->fakeLynomia();

        try {
            app(AccountLinker::class)->request($store->fresh(), $owner->fresh(), 'ABCD2345EFGH6789');
            $this->fail('a claim overwrote a provisioning row');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $this->assertSame(
            LynomiaStoreLink::MODE_PROVISION,
            LynomiaStoreLink::forStore((int) $store->id)->first()->link_mode
        );
    }

    /**
     * A cloned database is the ordinary way two installations come to share store ids. With
     * only the store id in the marker the lookup does not fail closed -- it finds exactly one
     * candidate and adopts it, signing a staging merchant into a production workspace.
     */
    public function test_a_workspace_from_another_installation_is_never_adopted(): void
    {
        [$owner, $store] = $this->entitledTenant();

        // Same store id, same Platform App, different installation.
        $this->fakeLynomia([
            'inventory' => $this->inventoryFor((int) $store->id, 7050, 'Production WS', 'production.example'),
        ]);

        $link = app(AccountProvisioner::class)->provision($store, $owner);

        $this->assertSame(7001, (int) $link->lynomia_account_id, "it adopted another installation's workspace");
        $this->assertSame(1, $this->sentCount('POST', '/platform/api/v1/accounts'));
    }

    /**
     * The same collision one layer down: upstream resolves an existing user by address, so a
     * shared service address hands this installation the other one's service user -- an
     * administrator of the other installation's workspace.
     */
    public function test_the_service_address_is_scoped_to_this_installation(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        app(AccountProvisioner::class)->provision($store, $owner);

        $create = collect(Http::recorded())
            ->first(fn ($pair) => strtoupper($pair[0]->method()) === 'POST'
                && str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/platform/api/v1/users'));

        $email = $create[0]->data()['email'];
        $this->assertStringContainsString((string) $store->id, $email);
        $this->assertStringContainsString(
            (string) config('lynomia.installation_ref'),
            $email,
            'the address must carry the installation, or two installations share a service user'
        );
        // The merchant's own address must not appear anywhere in it. An earlier version
        // asserted that the local part contained no '@', which is true of every string by
        // construction and therefore guarded nothing.
        $this->assertStringNotContainsString((string) $owner->email, $email);
        $this->assertNotSame(
            sprintf('store-%d@stores.storesyria.invalid', $store->id),
            $email,
            'the address must not be derived from the store id alone'
        );
    }

    /**
     * Another store adopting the workspace between the lookup and the write is a conflict to
     * report, not a raw database error to show a merchant.
     */
    public function test_losing_the_workspace_between_lookup_and_write_is_reported_as_a_conflict(): void
    {
        [$ownerA, $storeA] = $this->entitledTenant();
        $ownerB = $this->merchant();
        $storeB = $this->storeFor($ownerB);
        $this->entitle($ownerB);

        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($storeA, $ownerA);

        // Store B's attempt is handed the SAME account id -- the unique index is the arbiter.
        $this->fakeLynomia();

        try {
            app(AccountProvisioner::class)->provision($storeB, $ownerB->fresh());
            $this->fail('two stores were allowed to hold one workspace');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $this->assertSame(1, LynomiaStoreLink::whereNotNull('lynomia_account_id')->count());
    }

    // --------------------------------------------------- restoring a revoked connection

    /**
     * Revocation for a billing hold was a one way door: the merchant could not re-claim (a
     * revoked row is refused), could not provision (same), and could not move to another
     * store either, because the revoked row still owned the account id under the unique
     * index. The docblock on revoke() said the mapping was kept "so the same workspace is
     * reconnected rather than a second one created". Nothing reconnected it.
     */
    public function test_an_operator_can_restore_a_revoked_provisioned_link(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $link = app(AccountProvisioner::class)->provision($store, $owner);
        app(AccountLinker::class)->revoke($link->fresh(), $this->operator(), 'billing hold');

        $restored = app(AccountLinker::class)->restore($link->fresh(), $this->operator());

        // The grant was never handed back for a provisioned workspace, so the access never
        // went away and restoring it is only a state change.
        $this->assertSame(LinkState::ACTIVE, $restored->state);
        $this->assertSame(7001, (int) $restored->lynomia_account_id);
        $this->assertTrue($restored->allowsSso());
    }

    public function test_restoring_a_revoked_claim_releases_the_workspace_for_a_fresh_proof(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $result = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
        app(AccountLinker::class)->approve($result['link']->fresh(), $this->operator(), $result['verification_code']);
        app(AccountLinker::class)->revoke($result['link']->fresh(), $this->operator(), 'billing hold');

        $restored = app(AccountLinker::class)->restore($result['link']->fresh(), $this->operator());

        // This app gave the grant back, so no state change can conjure the access back. The
        // row becomes an empty claim and the merchant re-proves with a fresh code.
        $this->assertSame(LinkState::PENDING_VERIFICATION, $restored->state);
        $this->assertNull($restored->lynomia_account_id, 'the unique index must be released');
        $this->assertNull($restored->ownership_proven_at);
        $this->assertFalse($restored->allowsSso());

        // And the merchant can actually get back in.
        $this->fakeLynomia();
        $again = app(AccountLinker::class)->request($store->fresh(), $owner->fresh(), 'JKLM2345NPQR6789');
        $this->assertNotNull($again['link']->ownership_proven_at);
    }

    public function test_only_a_revoked_link_can_be_restored(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $link = app(AccountProvisioner::class)->provision($store, $owner);

        try {
            app(AccountLinker::class)->restore($link->fresh(), $this->operator());
            $this->fail('an active link was "restored"');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }
    }

    public function test_restore_is_a_platform_operator_action(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $link = app(AccountProvisioner::class)->provision($store, $owner);
        app(AccountLinker::class)->revoke($link->fresh(), $this->operator(), 'abuse');

        $response = $this->actingAs($owner->fresh())
            ->post("/super-admin/lynomia-integration/links/{$link->id}/restore");

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(LinkState::REVOKED, $link->fresh()->state);
    }

    /** The state had three places branching on it and nothing anywhere writing it. */
    public function test_there_is_no_unwritable_suspended_state(): void
    {
        $this->assertFalse(defined(LinkState::class . '::SUSPENDED'));
        $this->assertSame(
            [LinkState::PENDING_VERIFICATION, LinkState::ACTIVE, LinkState::REVOKED],
            LinkState::ALL
        );
    }

    // ------------------------------- second review round: lost updates and displaced grants

    /**
     * assertClaimable ran against a snapshot taken before a remote call that can take fifteen
     * seconds, and an operator could approve the claim they were shown inside that window. A
     * plain save() writes only the columns dirty relative to that stale snapshot -- `state`
     * matched in both, so it was never in the UPDATE -- and the operator's state=active
     * survived while the ids they approved were silently replaced. An ACTIVE link, carrying
     * their verified_by, to a workspace nobody approved.
     */
    public function test_a_claim_cannot_overwrite_a_link_an_operator_approved_mid_flight(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $first = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
        $operator = $this->operator();

        // THE WINDOW, REPRODUCED AT THE POINT IT ACTUALLY OPENS. assertClaimable has already
        // run against a row that was pending; the operator approves while the redemption is
        // on the wire. Driving it from inside redeemLinkCode is the faithful shape -- a test
        // that approves BEFORE calling request() proves nothing, because the fresh read
        // inside request() would see the approval and refuse for a different reason.
        $this->mock(PlatformApiClient::class, function ($mock) use ($first, $operator) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('revokeAccountLink')->andReturnNull();
            // Approval now also pushes the merchant's included seat allowance. Not what this
            // test is about, so it is allowed rather than asserted on.
            $mock->shouldReceive('grantEntitlement')->andReturn([]);
            $mock->shouldReceive('withdrawEntitlementGrant')->andReturn(true);
            $mock->shouldReceive('redeemLinkCode')->andReturnUsing(function () use ($first, $operator) {
                app(AccountLinker::class)->approve(
                    $first['link']->fresh(),
                    $operator,
                    $first['verification_code']
                );

                return [
                    'account_id' => 7002, 'account_name' => 'Other WS',
                    'authorized_by_user_id' => 9002, 'authorized_by_name' => 'Other Admin',
                ];
            });
        });

        try {
            app(AccountLinker::class)->request($store->fresh(), $owner->fresh(), 'JKLM2345NPQR6789');
            $this->fail('a claim overwrote a link an operator had just approved');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $link = LynomiaStoreLink::forStore((int) $store->id)->first();
        $this->assertSame(LinkState::ACTIVE, $link->state);
        $this->assertSame(7001, (int) $link->lynomia_account_id, 'the approved workspace was replaced');
        // Still the administrator who authorized the approved claim, not the one who
        // authorized the claim that lost the race.
        $this->assertSame(4242, (int) $link->lynomia_user_id);
        $this->assertSame((int) $operator->id, (int) $link->verified_by);
    }

    /**
     * A pending claim may be re-opened -- a merchant who pasted the wrong code should be able
     * to paste the right one. But the first redemption already made Lynomia grant this app
     * access to the first workspace, and overwriting the row in place discarded the only
     * reference to it: nothing left to revoke it with, ever. No race needed.
     */
    public function test_reclaiming_hands_back_the_workspace_it_displaces(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $this->fakeLynomia(['account_id' => 7001, 'user_id' => 9001]);
        app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');

        $this->fakeLynomia(['account_id' => 7002, 'user_id' => 9002]);
        $second = app(AccountLinker::class)->request($store->fresh(), $owner->fresh(), 'JKLM2345NPQR6789');

        $this->assertSame(7002, (int) $second['link']->lynomia_account_id);
        // The displaced workspace is given back rather than left as access nobody records.
        $this->assertSame(1, $this->sentCount('DELETE', '/account_links/7001'));
        $this->assertSame(0, $this->sentCount('DELETE', '/account_links/7002'));
    }

    public function test_reclaiming_the_same_workspace_does_not_hand_its_own_grant_back(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');

        $this->fakeLynomia();
        app(AccountLinker::class)->request($store->fresh(), $owner->fresh(), 'JKLM2345NPQR6789');

        // Same workspace, re-proved. Revoking the grant we are about to rely on would be
        // self-defeating.
        $this->assertSame(0, $this->sentCount('DELETE', '/account_links/7001'));
    }

    /**
     * An attempt that overran its window does not know it was superseded: its finally block
     * still runs. An unconditional release would clear the SUCCESSOR's claim and let a third
     * request start while the second was still mid-flight -- two attempts between the lookup
     * and the create, which is the duplicate the lookup exists to prevent.
     */
    public function test_a_superseded_attempt_does_not_release_the_claim_that_replaced_it(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $link = LynomiaStoreLink::create([
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
            'provisioning_stage' => ProvisioningStage::CLAIMED,
        ]);

        $provisioner = app(AccountProvisioner::class);
        $release = new \ReflectionMethod($provisioner, 'releaseLock');
        $release->setAccessible(true);

        // A took the claim long ago and is still running; B took it over when it went stale.
        $overrun = now()->subHours(2);
        $successor = now();
        $link->forceFill(['provisioning_started_at' => $successor])->save();

        $release->invoke($provisioner, $link, $overrun);

        $this->assertNotNull(
            $link->fresh()->provisioning_started_at,
            "the overrunning attempt cleared its successor's claim"
        );

        // The successor's own release still works.
        $release->invoke($provisioner, $link, $successor);
        $this->assertNull($link->fresh()->provisioning_started_at);
    }

    /**
     * The class docblock rests on the service address not belonging to a real person, and the
     * address is derived from public facts: a store id and the installation host. On an
     * installation with open signup somebody can register it first and wait. Upstream would
     * then resolve THEIR user, grant this app a permissible on it, and attach it to the new
     * workspace as an administrator.
     */
    public function test_provisioning_stops_if_the_service_identity_was_taken_first(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['squatted_user' => true]);

        try {
            app(AccountProvisioner::class)->provision($store, $owner);
            $this->fail('provisioning adopted a user it did not create');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        // It stops BEFORE attaching, so the squatter is never made an administrator.
        $this->assertSame(0, $this->sentCount('POST', '/account_users'));

        // The workspace it already created is recorded, so nothing is orphaned and an
        // operator resuming after clearing the squatter picks up from here.
        $link = LynomiaStoreLink::forStore((int) $store->id)->first();
        $this->assertSame(7001, (int) $link->lynomia_account_id);
        $this->assertNull($link->lynomia_user_id);
        $this->assertNotSame(LinkState::ACTIVE, $link->state);
    }

    public function test_provisioning_stamps_the_owner_identity_so_ownership_is_checkable(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        app(AccountProvisioner::class)->provision($store, $owner);

        $create = collect(Http::recorded())
            ->first(fn ($pair) => strtoupper($pair[0]->method()) === 'POST'
                && str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/platform/api/v1/users'));

        // THE PERSON IS IN THE MARKER, and that is the whole difference from the shared
        // service user this replaced: the address and the stamp both name whose identity it
        // is, so "we created this user" and "we created it for this person" are one check.
        $this->assertSame(
            [
                'store_syria_store_id' => (int) $store->id,
                'store_syria_user_id' => (int) $owner->id,
                'store_syria_installation' => config('lynomia.installation_ref'),
            ],
            $create[0]->data()['custom_attributes']
        );
    }

    /**
     * Fencing only on state catches pending -> active but not pending -> pending. Two
     * concurrent claims over the same pending row both read the same displaced workspace,
     * both succeed, and both hand back THAT one -- leaving the workspace the first claim
     * wrote with no row referencing it and no revocation that can ever reach it.
     */
    public function test_two_concurrent_claims_over_one_pending_row_cannot_both_win(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $this->fakeLynomia(['account_id' => 7001, 'user_id' => 9001]);
        app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');

        // The inner claim runs to completion inside the outer one's redemption -- which is
        // where the window actually opens -- so the outer one writes against a row that has
        // already moved on.
        $inner = false;
        $this->mock(PlatformApiClient::class, function ($mock) use (&$inner, $store, $owner) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('revokeAccountLink')->andReturnNull();
            $mock->shouldReceive('redeemLinkCode')->andReturnUsing(function () use (&$inner, $store, $owner) {
                if (! $inner) {
                    $inner = true;
                    app(AccountLinker::class)->request($store->fresh(), $owner->fresh(), 'JKLM2345NPQR6789');

                    return ['account_id' => 7003, 'account_name' => 'C',
                            'authorized_by_user_id' => 9003, 'authorized_by_name' => 'c'];
                }

                return ['account_id' => 7002, 'account_name' => 'B',
                    'authorized_by_user_id' => 9002, 'authorized_by_name' => 'b'];
            });
        });

        try {
            app(AccountLinker::class)->request($store->fresh(), $owner->fresh(), 'QRST2345UVWX6789');
            $this->fail('two concurrent claims both wrote the row');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        // Exactly one workspace is referenced, and it is the one the winner wrote.
        $link = LynomiaStoreLink::forStore((int) $store->id)->first();
        $this->assertSame(7002, (int) $link->lynomia_account_id);
    }
}
