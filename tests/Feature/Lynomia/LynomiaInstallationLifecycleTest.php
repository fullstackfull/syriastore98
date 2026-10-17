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
use Workdo\LynomiaChat\app\Services\InstallationLifecycle;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * P6B. THE INSTALLATION LIFECYCLE, ON BOTH SIDES AT ONCE.
 *
 * The defect these tests exist to keep closed: the two lifecycles were independent state
 * machines with no channel between them. Revoking a link tore down the account grant and left
 * `commerce_stores.status` at `active` in Lynomia, so its send boundary went on authorising
 * that installation's campaigns -- indefinitely, and invisibly to the operator who had just
 * revoked it.
 */
class LynomiaInstallationLifecycleTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    private function connected(): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7001]);

        return [$owner->fresh(), $store, app(AccountProvisioner::class)->provision($store, $owner)->fresh()];
    }

    private function lifecycle(): InstallationLifecycle
    {
        return app(InstallationLifecycle::class);
    }

    private function sentTransitions(): array
    {
        $seen = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'installation_lifecycle')) {
                $seen[] = $request['transition'] ?? null;
            }
        }

        return $seen;
    }

    // ===================================================== the transitions carry across

    public function test_suspending_stops_the_installation_on_both_sides(): void
    {
        [, , $link] = $this->connected();

        $moved = $this->lifecycle()->suspend($link, $this->operator(), 'billing hold');

        $this->assertSame(LinkState::SUSPENDED, $moved->state);
        $this->assertSame(['suspend'], $this->sentTransitions());
    }

    public function test_resuming_brings_it_back_on_both_sides(): void
    {
        [, , $link] = $this->connected();
        $this->lifecycle()->suspend($link, $this->operator(), 'hold');

        $moved = $this->lifecycle()->resume($link->fresh(), $this->operator(), 'paid');

        $this->assertSame(LinkState::ACTIVE, $moved->state);
        $this->assertSame(['suspend', 'resume'], $this->sentTransitions());
    }

    /** The one transition that drops Lynomia's credentials, and so the one that is final. */
    public function test_decommissioning_disconnects_rather_than_disabling(): void
    {
        [, , $link] = $this->connected();

        $moved = $this->lifecycle()->decommission($link, $this->operator(), 'merchant closed');

        $this->assertSame(LinkState::DECOMMISSIONED, $moved->state);
        $this->assertSame(['disconnect'], $this->sentTransitions());
    }

    public function test_a_decommissioned_installation_cannot_be_resumed(): void
    {
        [, , $link] = $this->connected();
        $this->lifecycle()->decommission($link, $this->operator(), 'closed');

        $this->expectException(LynomiaApiException::class);
        $this->lifecycle()->resume($link->fresh(), $this->operator(), 'changed our mind');
    }

    // ===================================================== the hole P6B closes

    /**
     * REVOKING NOW STOPS THE COMMERCE INSTALLATION TOO. Before P6B the only remote effect was
     * the account grant -- and for a PROVISIONED link, which keeps its grant deliberately,
     * there was no remote effect at all.
     */
    public function test_revoking_a_provisioned_link_also_stops_the_installation(): void
    {
        [, , $link] = $this->connected();

        app(AccountLinker::class)->revoke($link, $this->operator(), 'abuse');

        $this->assertSame(LinkState::REVOKED, $link->fresh()->state);
        $this->assertSame(['suspend'], $this->sentTransitions());
    }

    /** `suspend`, not `disconnect`: a revocation is reversible and dropping credentials is not. */
    public function test_restoring_a_revoked_link_brings_the_installation_back(): void
    {
        [, , $link] = $this->connected();
        app(AccountLinker::class)->revoke($link, $this->operator(), 'abuse');

        app(AccountLinker::class)->restore($link->fresh(), $this->operator());

        $this->assertSame(['suspend', 'resume'], $this->sentTransitions());
    }

    /**
     * THE SAME HOLE, FOR THE OTHER KIND OF LINK, AND THE ORDER THAT CLOSES IT.
     *
     * A PROVISIONED link keeps its account grant deliberately, so the example above never
     * exercised the hand-back -- and the hand-back is what broke this. For a LINKED workspace
     * revoke() returns the grant, which releases this app's permissible on the account;
     * Lynomia's validate_platform_app_permissible then answers the lifecycle call with 401,
     * stopInstallation logs it at warning level, and the commerce store is left `active` with
     * Partners::Eligibility still authorising its campaigns. Exactly the defect P6B was
     * written to close, surviving inside P6B's own fix because of the order of two lines.
     *
     * Asserted on the ORDER of the recorded calls, because both of them happen either way.
     */
    public function test_revoking_a_linked_workspace_stops_the_installation_before_returning_the_grant(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7001]);
        $claim = app(AccountLinker::class)->request($store, $owner, 'ABCD2345EFGH6789');
        $link = app(AccountLinker::class)->approve($claim['link']->fresh(), $this->operator(), $claim['verification_code']);
        $this->assertSame(LynomiaStoreLink::MODE_LINK_EXISTING, $link->link_mode);

        app(AccountLinker::class)->revoke($link->fresh(), $this->operator(), 'abuse');

        $calls = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'installation_lifecycle')) {
                $calls[] = 'stop';
            }
            if (str_contains($request->url(), 'account_links') && $request->method() === 'DELETE') {
                $calls[] = 'hand-back';
            }
        }

        $this->assertSame(['stop', 'hand-back'], $calls,
            'the installation must be stopped while this app still holds the permissible to do it with');
        $this->assertSame(LinkState::REVOKED, $link->fresh()->state);
    }

    /**
     * A LINK IS STILL REVOKED WHEN LYNOMIA CANNOT BE REACHED. Best effort here, unlike the
     * lifecycle service: the operator asked to revoke a LINK, and an unreachable Lynomia must
     * not leave it un-revoked, which is the worse of the two failures.
     */
    public function test_revoking_survives_an_unreachable_lynomia(): void
    {
        [, , $link] = $this->connected();
        $this->lynomiaScript['fail'] = [
            'PUT /platform/api/v1/accounts/7001/installation_lifecycle' => Http::response(['error' => 'down'], 500),
        ];

        app(AccountLinker::class)->revoke($link, $this->operator(), 'abuse');

        $this->assertSame(LinkState::REVOKED, $link->fresh()->state);
    }

    // ===================================================== fail closed, and attribution

    /**
     * THE REMOTE CALL GOES FIRST. Writing locally first would leave this side saying
     * "suspended" while Lynomia kept sending -- the exact disagreement P6B exists to end,
     * now with an operator who has been told it is handled.
     */
    public function test_a_refused_push_leaves_the_local_state_untouched(): void
    {
        [, , $link] = $this->connected();
        $this->lynomiaScript['fail'] = [
            'PUT /platform/api/v1/accounts/7001/installation_lifecycle'
                => Http::response(['error' => 'no such installation', 'error_code' => 'installation_not_found'], 404),
        ];

        try {
            $this->lifecycle()->suspend($link, $this->operator(), 'hold');
            $this->fail('the push failed, so the transition should have been refused');
        } catch (LynomiaApiException) {
            // expected
        }

        $this->assertSame(LinkState::ACTIVE, $link->fresh()->state);
    }

    public function test_a_transition_from_the_wrong_state_is_refused_before_anything_is_sent(): void
    {
        [, , $link] = $this->connected();
        $link->forceFill(['state' => LinkState::PENDING_VERIFICATION])->save();
        Http::fake();

        try {
            $this->lifecycle()->suspend($link->fresh(), $this->operator(), 'hold');
            $this->fail('a pending claim cannot be suspended');
        } catch (LynomiaApiException) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_every_transition_records_who_and_why(): void
    {
        [, , $link] = $this->connected();
        $operator = $this->operator();

        $this->lifecycle()->suspend($link, $operator, 'unpaid invoice');

        $entry = AuditLog::query()->where('action', 'lynomia_installation_suspended')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame((int) $operator->id, (int) ($entry->new_values['actor_id'] ?? 0));
        $this->assertSame('unpaid invoice', $entry->new_values['reason'] ?? null);
    }

    // ===================================================== re-authentication

    public function test_reauthenticating_mints_a_new_credential_and_tries_to_bring_it_back(): void
    {
        [, , $link] = $this->connected();
        $this->lifecycle()->suspend($link, $this->operator(), 'credential rejected');
        $before = (int) $link->fresh()->commerce_credential_version;

        [$restored, $credential, $resumed] = $this->lifecycle()->reauthenticate($link->fresh(), $this->operator());

        $this->assertSame($before + 1, (int) $restored->commerce_credential_version);
        $this->assertNotEmpty($credential);
        $this->assertTrue($resumed);
        $this->assertSame(LinkState::ACTIVE, $restored->state);
        $this->assertContains('resume', $this->sentTransitions());
    }

    /**
     * THE NORMAL PATH, AND THE ONE THAT MUST NOT LOSE THE CREDENTIAL.
     *
     * Issuing a credential invalidates the one Lynomia is holding at that instant -- it is
     * never stored on either side, an operator carries it across by hand -- so the resume
     * that follows asks Lynomia to health-check a store whose credential has just
     * deliberately stopped working, and it fails. An operator left with an error and no
     * credential to paste would be worse off than before they pressed the button.
     */
    public function test_a_resume_that_cannot_succeed_yet_still_yields_the_credential(): void
    {
        [, , $link] = $this->connected();
        $this->lifecycle()->suspend($link, $this->operator(), 'credential rejected');
        $this->lynomiaScript['fail'] = [
            'PUT /platform/api/v1/accounts/7001/installation_lifecycle'
                => Http::response(['error' => 'could not be reached', 'error_code' => 'resume_failed'], 409),
        ];

        [$still, $credential, $resumed] = $this->lifecycle()->reauthenticate($link->fresh(), $this->operator());

        $this->assertFalse($resumed);
        $this->assertNotEmpty($credential, 'the operator must still get something to paste');
        $this->assertSame(LinkState::SUSPENDED, $still->state, 'and the installation stays where it was');
    }

    /**
     * AND WHEN THE RESUME FAILS FOR A REASON THAT IS NOT LYNOMIA'S ANSWER.
     *
     * The version bump is committed before the resume is attempted, so by the time anything
     * can go wrong the credential Lynomia holds is already dead and the operator MUST come
     * away with the replacement. The example above covers a refusal from Lynomia; this one
     * covers the other source, the locked re-check in `move` refusing because the link moved
     * underneath the caller. Both raise LynomiaApiException, which is why one catch is
     * enough -- established here rather than assumed, because the consequence of being wrong
     * is an operator holding an error and no credential.
     */
    public function test_the_credential_is_returned_when_the_link_moved_underneath_the_resume(): void
    {
        [, , $link] = $this->connected();
        $this->lifecycle()->suspend($link, $this->operator(), 'credential rejected');
        $stale = LynomiaStoreLink::findOrFail($link->id);
        LynomiaStoreLink::whereKey($link->id)->update(['state' => LinkState::DECOMMISSIONED]);

        [$still, $credential, $resumed] = $this->lifecycle()->reauthenticate($stale, $this->operator());

        $this->assertFalse($resumed);
        $this->assertNotEmpty($credential, 'the operator must still get something to paste');
        $this->assertSame(LinkState::DECOMMISSIONED, $still->fresh()->state);
    }

    /** The credential itself is never written to the audit trail -- only the fact and the version. */
    public function test_the_new_credential_is_never_audited(): void
    {
        [, , $link] = $this->connected();

        [, $credential] = $this->lifecycle()->reauthenticate($link, $this->operator());

        foreach (AuditLog::query()->get() as $entry) {
            $this->assertStringNotContainsString($credential, json_encode($entry->new_values) ?: '');
            $this->assertStringNotContainsString($credential, json_encode($entry->old_values) ?: '');
        }
    }

    // ===================================================== the automated notice (P6E)

    /**
     * A HOLD THE MERCHANT DOES NOT KNOW ABOUT is a product that has silently stopped
     * working. No operator decides to send this; the transition does.
     */
    public function test_suspending_tells_the_merchant(): void
    {
        config()->set('lynomia.platform_workspace_account_id', 9001);
        config()->set('lynomia.platform_workspace_sender_user_id', 501);
        config()->set('lynomia.platform_workspace_inbox_id', 31);
        [$owner, , $link] = $this->connected();
        $owner->forceFill(['mobile' => '963900111222'])->save();

        $this->lifecycle()->suspend($link, $this->operator(), 'billing hold');

        $notice = \Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice::query()->latest('id')->first();
        $this->assertNotNull($notice);
        $this->assertSame('integration_recovery', $notice->kind);
        $this->assertTrue($notice->automated, 'no operator asked for it; the transition did');
    }

    /**
     * BEST EFFORT. An operator asked to suspend an installation; a hold that fails to apply
     * because a notice could not be sent is far worse than one the merchant learns later.
     */
    public function test_the_suspension_stands_even_when_the_merchant_cannot_be_told(): void
    {
        // Workspace unconfigured, and the merchant has no usable number either.
        [$owner, , $link] = $this->connected();
        $owner->forceFill(['mobile' => ''])->save();

        $moved = $this->lifecycle()->suspend($link, $this->operator(), 'billing hold');

        $this->assertSame(LinkState::SUSPENDED, $moved->state);
    }

    /** Resuming is not an occasion for this notice; only the hold is. */
    public function test_resuming_does_not_send_one(): void
    {
        [, , $link] = $this->connected();
        $this->lifecycle()->suspend($link, $this->operator(), 'hold');
        $before = \Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice::count();

        $this->lifecycle()->resume($link->fresh(), $this->operator(), 'paid');

        $this->assertSame($before, \Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice::count());
    }

    // ===================================================== retention

    /**
     * Consent evidence outlives the commercial arrangement that produced it. A decommission
     * that took it with the installation would destroy the only record that an opt-out ever
     * happened.
     */
    public function test_decommissioning_keeps_the_row_and_the_consent_evidence(): void
    {
        [, , $link] = $this->connected();
        \Workdo\LynomiaChat\app\Models\LynomiaConsentDecision::create([
            'store_id' => $link->store_id, 'external_subject_id' => 'cust-1', 'purpose' => 'promotional',
            'channel' => 'whatsapp', 'state' => 'withdrawn', 'source' => 'checkout_optin',
            'captured_at' => now(), 'decision_ref' => 'evt-1',
        ]);

        $this->lifecycle()->decommission($link, $this->operator(), 'merchant closed');

        $this->assertNotNull(LynomiaStoreLink::find($link->id));
        $this->assertSame(1, \Workdo\LynomiaChat\app\Models\LynomiaConsentDecision::query()
            ->where('store_id', $link->store_id)->count());
    }
    /**
     * TWO OPERATORS, ONE INSTALLATION, AND THE GUARD THAT DECIDES.
     *
     * The eligibility check at the top of a transition runs against a row read before the
     * push to Lynomia. Two operators acting at the same moment therefore both pass it. If the
     * write were not guarded again under the lock, the second one would simply win -- here, a
     * resume landing on top of a decommission, leaving this side ACTIVE on an installation
     * whose credentials Lynomia has already dropped and which can never work again.
     *
     * Modelled by holding a link object whose state is what the first operator saw, while the
     * row has already moved. That is precisely what the losing request holds in the race, and
     * it needs no interleaving to arrange.
     */
    public function test_a_transition_decided_on_a_stale_read_is_refused_under_the_lock(): void
    {
        [$owner, , $link] = $this->connected();
        $link->forceFill(['state' => LinkState::SUSPENDED])->save();
        $stale = LynomiaStoreLink::findOrFail($link->id);

        // The other operator gets there first.
        LynomiaStoreLink::whereKey($link->id)->update(['state' => LinkState::DECOMMISSIONED]);

        try {
            $this->lifecycle()->resume($stale, $owner, 'bringing it back');
            $this->fail('a resume decided on a stale read must not be applied');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $this->assertSame(LinkState::DECOMMISSIONED, LynomiaStoreLink::findOrFail($link->id)->state,
            'the loser of the race must not be the final word');
    }

}
