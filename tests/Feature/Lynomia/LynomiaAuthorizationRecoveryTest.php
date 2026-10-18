<?php

namespace Tests\Feature\Lynomia;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\AuthorizationHealth;
use Workdo\LynomiaChat\app\Services\CommerceActivator;
use Workdo\LynomiaChat\app\Services\SsoLinkIssuer;
use Workdo\LynomiaChat\app\Support\CommerceState;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * THE INCIDENT, AND THE RECOVERY IT HAD NO PATH FOR.
 *
 * A merchant opened Lynomia Chat -> Settings -> Integrations -> Connected platforms, saw "Store
 * Syria Integration" with a Disconnect button, and pressed it. That deleted the one
 * `platform_app_permissibles` row the whole integration rests on, so every platform call for
 * their workspace answered 401 -- and nothing on this side changed, so the merchant page went on
 * reporting a healthy connection while their inbox had no orders in it.
 *
 * There was no way back either: the only mechanism that can restore a permissible is a
 * merchant-minted connection code, and `AccountLinker::assertClaimable` refuses to redeem one
 * for a store that already has a workspace -- which is correct for a CLAIM and had no case for
 * re-authorising the workspace the store already holds.
 *
 * Nothing here touches production. Store 26 and account 135 are never named; every store is an
 * in-memory fixture and Lynomia is the shared scriptable fake.
 */
class LynomiaAuthorizationRecoveryTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const ACCOUNT = 7001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    /** An active, provisioned link -- the state store 26 was in. */
    private function activeLink(): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => self::ACCOUNT]);
        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();

        return [$owner->fresh(), $store, $link];
    }

    /** Script Lynomia the way it answers once the grant is gone: 401 + non_permissible_resource. */
    private function revokeUpstream(string $verbAndPath = 'GET /platform/api/v1/users/9001/login'): void
    {
        $this->fakeLynomia([
            'account_id' => self::ACCOUNT,
            'fail' => [$verbAndPath => Http::response(
                ['error' => 'Non permissible resource', 'error_code' => 'non_permissible_resource'],
                401
            )],
        ]);
    }

    // ================================================ telling the two 401s apart

    /**
     * THE WIRE SIGNAL THE WHOLE FIX RESTS ON. A revoked grant and a bad platform credential both
     * arrive as 401; before Lynomia sent a code they were one indistinguishable failure, and a
     * merchant's revocation was reported as a platform-credential fault nobody could act on.
     */
    public function test_a_revoked_grant_is_a_different_kind_from_a_bad_credential(): void
    {
        $revoked = LynomiaApiException::fromStatus(401, 'non_permissible_resource');
        $this->assertSame(LynomiaApiException::KIND_NOT_PERMISSIBLE, $revoked->kind);
        $this->assertSame('non_permissible_resource', $revoked->errorCode);

        // A token Lynomia does not recognise, and an older Lynomia that sends no code at all,
        // are both the credential -- this installation's problem, not one merchant's.
        $this->assertSame(LynomiaApiException::KIND_UNAUTHORIZED, LynomiaApiException::fromStatus(401, 'invalid_access_token')->kind);
        $this->assertSame(LynomiaApiException::KIND_UNAUTHORIZED, LynomiaApiException::fromStatus(401, null)->kind);
    }

    /** Permanent until recovered, so an outbox retires it rather than retrying forever. */
    public function test_a_revoked_grant_is_not_transient(): void
    {
        $this->assertFalse(LynomiaApiException::fromStatus(401, 'non_permissible_resource')->isTransient());
    }

    // ================================================ detection

    /**
     * The merchant clicks Open Lynomia Chat, which is the first thing they do after revoking
     * without realising what they revoked. The failure is recorded rather than only shown.
     */
    public function test_opening_the_workspace_records_the_lost_authorization(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->assertFalse(AuthorizationHealth::lost($link));
        $this->revokeUpstream();

        $response = $this->actingAs($owner)->get('/lynomia/open');

        $response->assertSessionHas('error');
        $fresh = $link->fresh();
        $this->assertTrue(AuthorizationHealth::lost($fresh));
        $this->assertNotNull($fresh->authorization_lost_at);
        // THE WORKSPACE IS NOT TOUCHED. Nothing about the arrangement changed -- only Lynomia's
        // willingness to admit us -- so the state, the account id and the mode all stand.
        $this->assertSame(LinkState::ACTIVE, $fresh->state);
        $this->assertSame(self::ACCOUNT, (int) $fresh->lynomia_account_id);
        $this->assertSame(LynomiaStoreLink::MODE_PROVISION, $fresh->link_mode);
    }

    /** A commerce attempt records it too, and does not report it as a commerce fault. */
    public function test_connecting_commerce_records_the_lost_authorization(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->revokeUpstream('PUT /platform/api/v1/accounts/7001/commerce_installation');

        try {
            app(CommerceActivator::class)->activate($link, $owner);
            $this->fail('activation should have failed');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_NOT_PERMISSIBLE, $e->kind);
        }

        $this->assertTrue(AuthorizationHealth::lost($link->fresh()));
    }

    /**
     * A BAD CREDENTIAL MUST NOT BE RECORDED HERE. It is this installation's configuration, it
     * breaks every merchant at once, and no merchant can recover it -- so recording it would tell
     * a thousand merchants to go and re-authorise for one operator's mistake.
     */
    public function test_a_bad_platform_credential_is_not_recorded_as_a_merchant_problem(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->fakeLynomia([
            'account_id' => self::ACCOUNT,
            'fail' => ['GET /platform/api/v1/users/9001/login' => Http::response(
                ['error' => 'Invalid access_token', 'error_code' => 'invalid_access_token'],
                401
            )],
        ]);

        $this->actingAs($owner)->get('/lynomia/open');

        $this->assertFalse(AuthorizationHealth::lost($link->fresh()));
    }

    /** The first observation is the one kept: "since when" must not creep forward. */
    public function test_a_second_refusal_does_not_move_the_timestamp(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->revokeUpstream();
        $this->actingAs($owner)->get('/lynomia/open');
        $first = $link->fresh()->authorization_lost_at;

        $this->travel(2)->minutes();
        $this->actingAs($owner)->get('/lynomia/open');

        $this->assertEquals($first, $link->fresh()->authorization_lost_at);
        $this->travelBack();
    }

    // ================================================ what the merchant is told

    /**
     * NOT "CONNECTED", which is what it said before. Every column the page renders from was
     * still true as of the last call that worked, so the one fact that had changed had nowhere
     * to be written down.
     */
    public function test_the_page_reports_authorization_needs_recovery_instead_of_health(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->forceFill(['authorization_lost_at' => now()])->save();

        $page = $this->actingAs($owner)->get('/lynomia');

        $page->assertOk();
        $page->assertSee(__('Integration authorization needs recovery'));
        $page->assertSee(route('lynomia.connect.recover'));
        $page->assertDontSee(__('This store is connected to Lynomia Chat.'));
        // And it must not offer the first-time choice, which would invite a SECOND workspace.
        $page->assertDontSee(__('Create workspace'));
    }

    /** Commerce cannot work while every platform call 401s, and must not claim it can. */
    public function test_commerce_is_unavailable_while_authorization_is_lost(): void
    {
        [$owner, , $link] = $this->activeLink();
        app(CommerceActivator::class)->activate($link, $owner);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link->fresh()));

        $link->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        $this->assertSame(CommerceState::UNAVAILABLE, CommerceState::of($link->fresh()));
        $this->assertFalse($link->fresh()->allowsSso());
    }

    // ================================================ recovery

    /**
     * EXISTING ACCOUNT RECOVERY. The same workspace, the same id, the same state, the same
     * commerce installation -- only the grant comes back. No second workspace, no new link row.
     */
    public function test_the_merchant_recovers_the_existing_workspace_with_a_code(): void
    {
        [$owner, , $link] = $this->activeLink();
        app(CommerceActivator::class)->activate($link, $owner);
        $link->fresh()->forceFill(['authorization_lost_at' => now()])->save();
        $before = $link->fresh();

        $response = $this->actingAs($owner)->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ]);

        $response->assertSessionHas('success');
        $after = $link->fresh();
        $this->assertFalse(AuthorizationHealth::lost($after));
        $this->assertNotNull($after->authorization_recovered_at);
        $this->assertTrue($after->allowsSso());
        // Nothing about the arrangement moved.
        $this->assertSame((int) $before->lynomia_account_id, (int) $after->lynomia_account_id);
        $this->assertSame($before->state, $after->state);
        $this->assertSame($before->link_mode, $after->link_mode);
        $this->assertSame((int) $before->lynomia_commerce_store_id, (int) $after->lynomia_commerce_store_id);
        $this->assertSame((int) $before->commerce_credential_version, (int) $after->commerce_credential_version);
        $this->assertSame(1, LynomiaStoreLink::count(), 'no second link row');
    }

    /**
     * ATTEMPTED CROSS-TENANT TAKEOVER. A perfectly valid code -- for somebody else's workspace.
     * Refused on the strength of what this row already says, not on anything the caller supplied.
     */
    public function test_a_valid_code_for_another_workspace_cannot_repoint_this_store(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        // Lynomia honours the code and answers with the account it really proves: a different one.
        $this->fakeLynomia(['account_id' => self::ACCOUNT, 'link_account_id' => 9999]);

        $response = $this->actingAs($owner)->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ]);

        $response->assertSessionHas('error');
        $after = $link->fresh();
        $this->assertSame(self::ACCOUNT, (int) $after->lynomia_account_id, 'the store was re-pointed');
        $this->assertTrue(AuthorizationHealth::lost($after), 'a refused recovery must not clear the state');
        $this->assertNotNull(AuditLog::where('action', 'lynomia_authorization_recovery_refused')->first());
    }

    /** REPEATED RECOVERY REQUESTS. Idempotent, and never a second workspace. */
    public function test_repeated_recovery_requests_are_safe(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        for ($i = 0; $i < 3; $i++) {
            $response = $this->actingAs($owner)->post('/lynomia/connect/recover', [
                'connection_code' => 'ABCDEFGHJKLMNPQR',
            ]);
            $response->assertSessionHas('success');
        }

        $this->assertSame(1, LynomiaStoreLink::count());
        $this->assertSame(self::ACCOUNT, (int) $link->fresh()->lynomia_account_id);
        $this->assertFalse(AuthorizationHealth::lost($link->fresh()));
    }

    /**
     * A SUCCESSFUL CALL IS PROOF ENOUGH. The grant can come back without this app being told --
     * an operator restores it -- and a working store must not keep a banner because nobody
     * pressed the button that would have cleared it.
     */
    public function test_a_call_that_works_clears_the_banner_without_a_recovery(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        // allowsSso() is false while the banner is up, so SSO is not the path that proves this;
        // the sweep is, and it is the one that runs unprompted.
        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $this->assertFalse(AuthorizationHealth::lost($link->fresh()));
    }

    /** And the sweep is what NOTICES, for a merchant who has not clicked anything since. */
    public function test_the_hourly_sweep_notices_a_revocation_nobody_clicked_on(): void
    {
        [, , $link] = $this->activeLink();
        $this->fakeLynomia([
            'account_id' => self::ACCOUNT,
            'fail' => ['GET /platform/api/v1/accounts/7001/integration_health' => Http::response(
                ['error' => 'Non permissible resource', 'error_code' => 'non_permissible_resource'],
                401
            )],
        ]);

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $this->assertTrue(AuthorizationHealth::lost($link->fresh()));
    }

    /** A dry run reports and writes nothing, including this. */
    public function test_a_dry_run_sweep_records_nothing(): void
    {
        [, , $link] = $this->activeLink();
        $this->fakeLynomia([
            'account_id' => self::ACCOUNT,
            'fail' => ['GET /platform/api/v1/accounts/7001/integration_health' => Http::response(
                ['error' => 'Non permissible resource', 'error_code' => 'non_permissible_resource'],
                401
            )],
        ]);

        $this->artisan('lynomia:reconcile-commerce', ['--dry-run' => true])->assertExitCode(0);

        $this->assertFalse(AuthorizationHealth::lost($link->fresh()));
    }

    /** Recovery needs a workspace to recover; there is nothing to prove otherwise. */
    public function test_recovery_is_refused_for_a_store_with_no_workspace(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => self::ACCOUNT]);
        LynomiaStoreLink::create([
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
        ]);

        $response = $this->actingAs($owner->fresh())->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, $this->sentCount('POST', '/account_links'));
    }

    /** A merchant cannot recover another tenant's store: the middleware never hands it over. */
    public function test_a_merchant_cannot_recover_another_tenants_store(): void
    {
        [, $storeA, $linkA] = $this->activeLink();
        $linkA->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        $ownerB = $this->merchant();
        $this->storeFor($ownerB);
        $this->entitle($ownerB);
        $ownerB->current_store = $storeA->id;
        $ownerB->save();

        $response = $this->actingAs($ownerB->fresh())->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('error');
        $this->assertTrue(AuthorizationHealth::lost($linkA->fresh()));
        $this->assertSame(0, $this->sentCount('POST', '/account_links'));
    }

    /** The claim path is untouched: a provisioned store still cannot be re-claimed. */
    public function test_the_claim_path_still_refuses_a_provisioned_store(): void
    {
        [$owner, $store, ] = $this->activeLink();

        try {
            app(AccountLinker::class)->request($store, $owner, 'ABCDEFGHJKLMNPQR');
            $this->fail('a provisioned store must not be claimable');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }
    }
}
