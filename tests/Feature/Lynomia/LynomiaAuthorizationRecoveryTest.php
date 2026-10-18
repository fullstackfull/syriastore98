<?php

namespace Tests\Feature\Lynomia;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController;
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\AuthorizationHealth;
use Workdo\LynomiaChat\app\Services\CommerceActivator;
use Workdo\LynomiaChat\app\Services\CommerceCredential;
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

    /**
     * THE OPERATOR IS NOT LOCKED OUT OF THE THING THEY NEED DURING A RECOVERY.
     *
     * Minting a commerce credential needs Lynomia for nothing -- it is derived from the master
     * secret, the store id and a version -- and it is one of the things an operator may have to
     * do while a revoked grant is being restored. The UI gate (`canIssueCommerceCredential`) and
     * the server gate (`IntegrationController::issueCommerceCredential`) must therefore agree,
     * and both must read the ARRANGEMENT rather than the current authorization.
     *
     * Pinned because the split that introduced `arrangementAllowsSso()` changed the UI gate and
     * very nearly left the server gate on `allowsSso()` -- which offers an operator an enabled
     * button that answers "that workspace connection is not active" when the workspace is
     * perfectly active and it is the grant that is gone.
     */
    public function test_an_operator_can_still_mint_a_commerce_credential_while_authorization_is_lost(): void
    {
        [, , $link] = $this->activeLink();
        $link->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        $this->assertTrue($link->fresh()->canIssueCommerceCredential(), 'the button was hidden');

        $this->actingAs($this->operator());
        $redirect = app(IntegrationController::class)->issueCommerceCredential($link->id);

        $this->assertSame(1, (int) $link->fresh()->commerce_credential_version);
        $this->assertNotEmpty($redirect->getSession()->get('lynomia_commerce_credential'));
        $this->assertEmpty($redirect->getSession()->get('error'));
    }

    /** And a link the ARRANGEMENT refuses is still refused, by both gates, for the same reason. */
    public function test_a_suspended_link_is_refused_by_both_gates(): void
    {
        [, , $link] = $this->activeLink();
        $link->fresh()->forceFill(['state' => LinkState::SUSPENDED])->save();

        $this->assertFalse($link->fresh()->canIssueCommerceCredential());

        $this->actingAs($this->operator());
        $redirect = app(IntegrationController::class)->issueCommerceCredential($link->id);

        $this->assertSame(0, (int) $link->fresh()->commerce_credential_version);
        $this->assertNotEmpty($redirect->getSession()->get('error'));
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

    /**
     * THE GRANT CAME BACK; THE CONNECTION DID NOT. SAY SO.
     *
     * `reauthorize` deliberately leaves `state` alone, so a link an operator revoked while the
     * grant was already gone comes out of a successful redemption with its grant restored and
     * its state still REVOKED. Every surface goes on refusing it -- correctly, and no state gate
     * is added here, because one would fold health back into state and would also stop a
     * merchant putting the grant back BEFORE an operator's restore(), whose resume push needs
     * the grant to exist.
     *
     * What is NOT acceptable is telling that merchant their connection "has been restored".
     * This whole change exists because a page reported health it did not have.
     */
    public function test_a_revoked_link_is_not_told_its_connection_is_restored(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->fresh()->forceFill([
            'authorization_lost_at' => now(),
            'state' => LinkState::REVOKED,
        ])->save();

        $response = $this->actingAs($owner)->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ]);

        // The redemption itself succeeded -- the grant is back and the flag is cleared.
        $response->assertSessionHas('success');
        $this->assertFalse(AuthorizationHealth::lost($link->fresh()));

        // But the sentence does not claim the connection works, because it does not.
        $this->assertSame(
            __('Lynomia Chat accepted the code, but this store\'s connection was ended by support. Please contact support to reopen it.'),
            $response->getSession()->get('success')
        );
        $this->assertSame(LinkState::REVOKED, $link->fresh()->state);
        $this->assertFalse($link->fresh()->allowsSso(), 'a revoked link must gain nothing from a recovery');
        $this->assertFalse($link->fresh()->arrangementAllowsSso());
    }

    /**
     * A MERCHANT'S RECOVERY DOES NOT ERASE AN OPERATOR'S RECORD.
     *
     * `last_error` on this row is written by operator-owned transitions --
     * InstallationLifecycle::move puts the suspend/decommission reason there, and revoke() puts
     * the revocation reason there, both up to 480 characters. A merchant restoring a GRANT has
     * answered none of that, so recovery clears the field only on an ACTIVE row, where the only
     * thing that can have written it is a failure of the very thing just fixed.
     *
     * The audit rows do keep a copy of the reason, but truncated to 200 characters, so letting a
     * merchant action null the column is not a clean restore.
     */
    public function test_recovery_does_not_clear_an_operators_reason_on_a_revoked_link(): void
    {
        [$owner, , $link] = $this->activeLink();
        $reason = 'Ended by support after the 2026-10 billing dispute; see ticket SS-4417.';
        $link->fresh()->forceFill([
            'authorization_lost_at' => now(),
            'state' => LinkState::REVOKED,
            'last_error' => $reason,
        ])->save();

        $response = $this->actingAs($owner)->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ]);

        $response->assertSessionHas('success');
        $this->assertFalse(AuthorizationHealth::lost($link->fresh()), 'the grant did come back');
        $this->assertSame($reason, $link->fresh()->last_error, "the operator's reason was erased");
    }

    /** On an ACTIVE row it IS ours to clear: nothing but the restored grant can have written it. */
    public function test_recovery_clears_the_stale_error_on_an_active_link(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->fresh()->forceFill([
            'authorization_lost_at' => now(),
            'last_error' => 'kind_not_permissible',
        ])->save();

        $this->actingAs($owner)->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ])->assertSessionHas('success');

        $this->assertNull($link->fresh()->last_error);
    }

    /**
     * A REFUSED RECOVERY DOES NOT LEAVE A GRANT NOBODY CAN SEE.
     *
     * `redeemLinkCode` burns the code upstream AND creates this app's permissible on the
     * workspace it proves -- so by the time the cross-tenant guard refuses, Lynomia has already
     * granted access to a workspace that no row in this database references. Nothing would ever
     * hand it back, because nothing could find it. `request()` has disposed of exactly this case
     * since it was written ("the orphaned access this flow exists to prevent"); recovery did not.
     *
     * Reached without an attack: one merchant, two stores, store A's code pasted into store B's
     * recovery form.
     */
    public function test_a_refused_recovery_hands_back_the_grant_it_accidentally_created(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        // Lynomia honours the code and answers with the workspace it really proves: another one.
        $this->fakeLynomia(['account_id' => self::ACCOUNT, 'link_account_id' => 9999]);

        $response = $this->actingAs($owner)->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(self::ACCOUNT, (int) $link->fresh()->lynomia_account_id, 'the store was repointed');

        // The grant on 9999 is handed back, because nothing here references that workspace.
        $this->assertSame(1, $this->sentCount('DELETE', '/account_links/9999'),
            'the accidental grant on the other workspace was left standing');
    }

    /**
     * ...BUT IT DOES NOT TOUCH A WORKSPACE SOMEBODY ELSE IS STANDING ON.
     *
     * If another store's link already names that workspace, the grant is not something the
     * redemption just created -- it is what that store's working connection runs on. Handing it
     * back would break an innocent merchant's SSO and hide their workspace from the recovery
     * lookup, which is a worse outcome than the orphan.
     */
    public function test_a_refused_recovery_leaves_another_stores_workspace_alone(): void
    {
        [$ownerA, , $linkA] = $this->activeLink();
        $linkA->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        // A second tenant with a live link on workspace 9999.
        $ownerB = $this->merchant();
        $storeB = $this->storeFor($ownerB);
        $this->entitle($ownerB);
        LynomiaStoreLink::create([
            'store_id' => (int) $storeB->id,
            'owner_user_id' => (int) $ownerB->creatorId(),
            'lynomia_account_id' => 9999,
            'state' => LinkState::ACTIVE,
            'link_mode' => LynomiaStoreLink::MODE_PROVISION,
        ]);

        $this->fakeLynomia(['account_id' => self::ACCOUNT, 'link_account_id' => 9999]);

        $this->actingAs($ownerA)->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ])->assertSessionHas('error');

        $this->assertSame(0, $this->sentCount('DELETE', '/account_links/9999'),
            "another store's live workspace was revoked");
        $this->assertSame(LinkState::ACTIVE, LynomiaStoreLink::where('store_id', $storeB->id)->first()->state);
    }

    /**
     * RECOVERY HAS TO RESTORE THE INBOX, NOT JUST THE ROW.
     *
     * Lynomia's revoke destroys the per-USER permissibles alongside the account's, and redeeming
     * a code restores only the account one -- on purpose, because granting the authorizing
     * administrator's identity would hand this app an unscoped session for them. That leaves the
     * `lynomia_store_identities` rows naming users this app no longer holds:
     * `GET /platform/api/v1/users/:id/login` checks the permissible on the USER, so SSO keeps
     * answering 401 and the next failure re-lights the banner. The merchant is told they are
     * restored and then cannot open their workspace -- a recovery that loops.
     *
     * So recovery forgets the workspace's identities and the next sign-in mints fresh ones, which
     * is what re-grants the per-user permissible. `handBackGrant` has done this since it was
     * written, for this exact reason; recovery did not.
     */
    public function test_recovery_forgets_the_stale_identities_so_sso_works_again(): void
    {
        [$owner, , $link] = $this->activeLink();

        // The merchant has signed in once, so an identity row exists naming a Lynomia user.
        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $owner->fresh());
        $this->assertSame(
            1,
            LynomiaStoreIdentity::where('lynomia_account_id', self::ACCOUNT)->count(),
            'no identity to go stale'
        );

        $link->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        $this->fakeLynomia(['account_id' => self::ACCOUNT]);
        $this->actingAs($owner->fresh())->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ])->assertSessionHas('success');

        // The stale row is gone, so the next sign-in creates the user upstream again -- and that
        // call is what re-grants this app the permissible on them.
        $this->assertSame(
            0,
            LynomiaStoreIdentity::where('lynomia_account_id', self::ACCOUNT)->count(),
            'the stale identity survived, so SSO will keep answering 401'
        );

        $before = $this->sentCount('POST', '/platform/api/v1/users');
        app(SsoLinkIssuer::class)->issueFor($link->fresh(), $owner->fresh());
        $this->assertGreaterThan($before, $this->sentCount('POST', '/platform/api/v1/users'),
            'the identity was reused rather than re-minted');
        $this->assertSame(1, LynomiaStoreIdentity::where('lynomia_account_id', self::ACCOUNT)->count());
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

    /**
     * THE PRODUCTION CASE, REPRODUCED BY SHAPE. This is the row the incident happened on, and the
     * one the remedy has to work for -- so it is pinned rather than argued.
     *
     * Store 26 -> Lynomia account 135 ("al atalssian"): `link_mode = provision`, `state = active`,
     * `provisioning_stage = complete`, commerce installed, and `ownership_proven_at` NULL -- it was
     * never claimed, because this app CREATED the workspace, so there was never an ownership
     * question to answer. That last field is the one that makes this row distinctive: every
     * ownership-proven predicate on the SSO path reads
     * `link_mode !== MODE_LINK_EXISTING || ownershipProven()`, which a provisioned link satisfies
     * on the left -- and a recovery path that required `ownershipProven()` outright would refuse
     * exactly this merchant while appearing to work for claimed workspaces.
     *
     * NOTHING HERE TOUCHES PRODUCTION. The ids are an in-memory sqlite fixture; 135 is used as the
     * literal so the correspondence is unmistakable, not because anything reaches that account.
     */
    public function test_the_production_row_shape_for_store_26_is_recoverable(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 135]);
        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();
        app(CommerceActivator::class)->activate($link, $owner->fresh());

        // The row as production holds it: provisioned, active, commerce installed, never claimed,
        // and -- as of today -- with nothing recorded about the lost grant.
        $link->fresh()->forceFill([
            'ownership_proven_at' => null,
            'authorization_lost_at' => null,
            'authorization_recovered_at' => null,
        ])->save();

        $before = $link->fresh();
        $this->assertSame(LynomiaStoreLink::MODE_PROVISION, $before->link_mode);
        $this->assertSame(LinkState::ACTIVE, $before->state);
        $this->assertSame(135, (int) $before->lynomia_account_id);
        $this->assertNull($before->ownership_proven_at, 'the distinguishing field');
        $this->assertFalse($before->ownershipProven());
        $this->assertNotNull($before->lynomia_commerce_store_id);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($before));

        // STEP 1. Lynomia starts refusing every call for this workspace. Before the diff the page
        // went on reporting health; the sweep now notices without the merchant touching anything.
        $this->revokeUpstream('GET /platform/api/v1/accounts/135/integration_health');
        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);
        $this->assertTrue(AuthorizationHealth::lost($link->fresh()), 'the sweep did not notice');

        // STEP 2. The page says recovery is needed rather than showing the workspace as healthy,
        // and commerce reads unavailable rather than connected.
        $this->actingAs($owner->fresh())->get('/lynomia/')
            ->assertOk()
            ->assertSee('Integration authorization needs recovery', false);
        $this->assertSame(CommerceState::UNAVAILABLE, CommerceState::of($link->fresh()));

        // STEP 3. The merchant redeems a code minted inside account 135. The code is proof of who
        // they are; the row decides which workspace they are allowed to prove it for.
        $this->fakeLynomia(['account_id' => 135]);
        $response = $this->actingAs($owner->fresh())->post('/lynomia/connect/recover', [
            'connection_code' => 'ABCDEFGHJKLMNPQR',
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(
            __('Your Lynomia Chat connection has been restored.'),
            $response->getSession()->get('success'),
            'an active link must get the active sentence'
        );

        // STEP 4. The same workspace, and only that. No second account, no second link row.
        $after = $link->fresh();
        $this->assertFalse(AuthorizationHealth::lost($after));
        $this->assertSame(135, (int) $after->lynomia_account_id, 'the workspace id moved');
        $this->assertSame($before->state, $after->state);
        $this->assertSame($before->link_mode, $after->link_mode);
        $this->assertSame($before->provisioning_stage, $after->provisioning_stage);
        $this->assertSame((int) $before->owner_user_id, (int) $after->owner_user_id);
        $this->assertSame(1, LynomiaStoreLink::count(), 'a second link row appeared');
        $this->assertSame(0, $this->sentCount('POST', '/platform/api/v1/accounts'),
            'a second workspace was provisioned');

        // STEP 5. The commerce installation is untouched and usable again -- no reinstall, no new
        // credential, no version bump that would strand the one Lynomia already holds.
        $this->assertSame(
            (int) $before->lynomia_commerce_store_id,
            (int) $after->lynomia_commerce_store_id
        );
        $this->assertSame(
            (int) $before->commerce_credential_version,
            (int) $after->commerce_credential_version
        );
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($after));
        $this->assertTrue($after->allowsSso());
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

        // THE MIDDLEWARE'S OWN REFUSAL, asserted by its message and its destination rather than
        // by the status. EnsureStoreOwnsLink authorizes the STORE from Store.created_by before
        // it looks up any link, and sends the caller to the dashboard.
        //
        // Pinned this precisely because a looser assertion did not test the control at all:
        // `assertStatus(302)` plus `assertSessionHas('error')` also passes when the middleware
        // is absent, because recoverAuthorization's own "no link on this request" branch answers
        // `back()->with('error', ...)` -- also a 302, also a session error, also no wire call.
        // Removing the middleware left the test green. It does not now.
        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error', __('You do not have access to this store.'));
        $this->assertTrue(AuthorizationHealth::lost($linkA->fresh()));
        $this->assertSame(0, $this->sentCount('POST', '/account_links'));
    }

    /**
     * THE ASYMMETRY, PINNED, BECAUSE IT IS THE MOST COUNTERINTUITIVE THING IN THIS CHANGE.
     *
     * A merchant removing Store Syria's grant on their WORKSPACE has not withdrawn Lynomia's
     * access to their STORE: the inbound commerce credential is an `lsc_` secret this side
     * issued, which the merchant never touched, and `AuthenticateCommerceApi` therefore reads
     * `arrangementAllowsSso()`. Refusing here would flip Lynomia's commerce store to
     * `needs_reauth` and manufacture a second outage out of the first.
     *
     * Without this example the inbound middleware is one well-meaning "consistency" edit away
     * from `allowsSso()`, which is precisely the second outage. The outbound direction is
     * refused in the same example so the two halves cannot drift apart silently.
     */
    public function test_inbound_commerce_reads_keep_working_while_authorization_is_lost(): void
    {
        [, , $link] = $this->activeLink();
        $link->fresh()->forceFill(['commerce_credential_version' => 1])->save();
        $token = CommerceCredential::issue($link->fresh());

        $this->getJson('/api/lynomia/commerce/v1/store', ['Authorization' => 'Bearer ' . $token])
            ->assertOk();

        $link->fresh()->forceFill(['authorization_lost_at' => now()])->save();

        // INBOUND: unaffected. The credential proves which store; the grant it lost was on the
        // workspace, which this direction does not go through.
        $this->getJson('/api/lynomia/commerce/v1/store', ['Authorization' => 'Bearer ' . $token])
            ->assertOk();

        // OUTBOUND: refused, by the same link, at the same moment.
        $this->assertFalse($link->fresh()->allowsSso());
        $this->assertTrue($link->fresh()->arrangementAllowsSso());
    }

    /** And the link state still governs the inbound direction, which the widening did not touch. */
    public function test_a_suspended_link_still_refuses_inbound_commerce_reads(): void
    {
        [, , $link] = $this->activeLink();
        $link->fresh()->forceFill(['commerce_credential_version' => 1])->save();
        $token = CommerceCredential::issue($link->fresh());

        $link->fresh()->forceFill(['state' => LinkState::SUSPENDED])->save();

        $this->getJson('/api/lynomia/commerce/v1/store', ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(403);
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
