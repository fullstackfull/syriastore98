<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Models\LynomiaConsentState;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\ConsentReconciler;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * THE SAFETY NET BEHIND THE CONSENT EVENT FEED.
 *
 * A lost delivery is silent and permanent -- Lynomia's webhook sender swallows a failed
 * delivery rather than retrying it -- and the fact it loses is an opt-out. A periodic pull is
 * the only thing that notices.
 *
 * THE PROPERTY THAT MATTERS MOST HERE IS THAT THE SWEEP CANNOT GO BACKWARDS. It runs while
 * deliveries are in flight, so a pulled row that is older than what this side already holds
 * must be dropped exactly as a late webhook would be. A sweep that could regress a scope
 * would be a scheduled job that occasionally un-withdraws somebody.
 *
 * WHAT THESE TESTS DO NOT PROVE. The remote is a scriptable fake. That is agreement between
 * two expressions of one reading of the contract, not evidence the reading is right; blocker
 * A1 stays open until two real deployments have exchanged one of these.
 */
class LynomiaConsentReconciliationTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    private function connectedLink(int $accountId = 7001): LynomiaStoreLink
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);

        return app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)->provision($store, $owner)->fresh();
    }

    private function reconcile(LynomiaStoreLink $link): array
    {
        return app(ConsentReconciler::class)->reconcile($link);
    }

    private function remote(array $partner = [], array $native = []): void
    {
        $this->lynomiaScript['partner_consents'] = $partner;
        $this->lynomiaScript['native_consents'] = $native;
    }

    private function partnerRow(array $attributes = []): array
    {
        return array_merge([
            'external_subject_id' => 'cust-77', 'purpose' => 'promotional', 'channel' => 'any',
            'state' => 'granted', 'source' => 'checkout_optin', 'revision' => 10_001,
            'captured_at' => now()->toIso8601String(),
        ], $attributes);
    }

    private function nativeRow(array $attributes = []): array
    {
        return array_merge([
            'external_subject_id' => 'cust-77', 'purpose' => 'promotional', 'channel' => 'any',
            'state' => 'withdrawn', 'source' => 'shopper_keyword', 'revision' => 5,
            'decided_at' => now()->toIso8601String(),
        ], $attributes);
    }

    public function test_it_projects_both_collections_under_their_own_origins(): void
    {
        $link = $this->connectedLink();
        $this->remote([$this->partnerRow()], [$this->nativeRow()]);

        $result = $this->reconcile($link);

        $this->assertSame(2, $result['pulled']);
        $this->assertSame(2, $result['applied']);
        $this->assertEqualsCanonicalizing(
            ['partner', 'lynomia_native'],
            LynomiaConsentState::forStore($link->store_id)->pluck('origin')->all()
        );
    }

    /**
     * THE DEFECT THIS WHOLE ORIGIN SPLIT EXISTS FOR, reached through the pull path rather
     * than the push path. The partner grant's revision (10001, a partner_consent_events id)
     * is far larger than the native withdrawal's (5, a commerce_messaging_consents id), and
     * the two count in unrelated sequences. On one shared row the grant compared as "newer"
     * and the withdrawal was overwritten.
     */
    public function test_a_partner_grant_cannot_overwrite_a_native_withdrawal(): void
    {
        $link = $this->connectedLink();
        $this->remote([$this->partnerRow()], [$this->nativeRow()]);

        $this->reconcile($link);

        $rows = LynomiaConsentState::forStore($link->store_id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame('withdrawn', LynomiaConsentState::effectiveFor($rows)['state']);
    }

    /** A sweep that could move a scope backwards would occasionally un-withdraw somebody. */
    public function test_a_stale_pulled_row_cannot_regress_a_scope(): void
    {
        $link = $this->connectedLink();
        $this->remote([], [$this->nativeRow(['state' => 'withdrawn', 'revision' => 20])]);
        $this->reconcile($link);

        $this->remote([], [$this->nativeRow(['state' => 'granted', 'revision' => 19])]);
        $result = $this->reconcile($link);

        $this->assertSame(0, $result['applied'], 'an older revision must be dropped, not applied');
        $this->assertSame('withdrawn', LynomiaConsentState::forStore($link->store_id)->sole()->state);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $link = $this->connectedLink();
        $this->remote([$this->partnerRow()], [$this->nativeRow()]);
        $this->reconcile($link);

        $result = $this->reconcile($link);

        $this->assertSame(0, $result['applied']);
        $this->assertSame(2, LynomiaConsentState::forStore($link->store_id)->count());
    }

    /**
     * READ-ONLY TOWARDS LYNOMIA. A sweep that re-sent local decisions because the remote
     * disagreed would be this side overruling the other's refusals -- the competing
     * authorities failure docs/partner-consent/AUTHORITY.md exists to prevent.
     */
    public function test_it_never_writes_back_to_lynomia(): void
    {
        $link = $this->connectedLink();
        LynomiaConsentDecision::create([
            'store_id' => $link->store_id, 'external_subject_id' => 'cust-77', 'purpose' => 'promotional',
            'channel' => 'any', 'state' => 'granted', 'source' => 'checkout_optin', 'captured_at' => now(),
            'decision_ref' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->remote([], [$this->nativeRow()]);
        Http::fake();

        $this->reconcile($link);

        Http::assertNotSent(fn ($request) => strtoupper($request->method()) === 'POST');
    }

    /**
     * Drift is the merchant-visible fact: this store believes the customer agreed and
     * Lynomia will not send to them. Reported, never papered over -- it is usually Lynomia
     * being right, because the shopper opted out on a channel this side never saw.
     */
    public function test_it_reports_a_local_grant_that_lynomia_will_not_honour(): void
    {
        $link = $this->connectedLink();
        LynomiaConsentDecision::create([
            'store_id' => $link->store_id, 'external_subject_id' => 'cust-77', 'purpose' => 'promotional',
            'channel' => 'any', 'state' => 'granted', 'source' => 'checkout_optin', 'captured_at' => now(),
            'decision_ref' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->remote([], [$this->nativeRow()]);

        $this->assertSame(1, $this->reconcile($link)['drifted']);
    }

    public function test_agreement_is_not_reported_as_drift(): void
    {
        $link = $this->connectedLink();
        LynomiaConsentDecision::create([
            'store_id' => $link->store_id, 'external_subject_id' => 'cust-77', 'purpose' => 'promotional',
            'channel' => 'any', 'state' => 'granted', 'source' => 'checkout_optin', 'captured_at' => now(),
            'decision_ref' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->remote([$this->partnerRow()], []);

        $this->assertSame(0, $this->reconcile($link)['drifted']);
    }

    /** A decision still in the outbox is the outbox's problem, not drift. */
    public function test_a_scope_lynomia_has_never_heard_of_is_not_drift(): void
    {
        $link = $this->connectedLink();
        LynomiaConsentDecision::create([
            'store_id' => $link->store_id, 'external_subject_id' => 'cust-99', 'purpose' => 'promotional',
            'channel' => 'any', 'state' => 'granted', 'source' => 'checkout_optin', 'captured_at' => now(),
            'decision_ref' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->remote([], []);

        $this->assertSame(0, $this->reconcile($link)['drifted']);
    }

    /** STRICT STORE ISOLATION: a pulled row naming another store is not this store's business. */
    public function test_a_row_naming_another_store_is_dropped(): void
    {
        $link = $this->connectedLink();
        $this->remote([], [$this->nativeRow()]);
        // The projector compares the payload's store against the link it arrived on. The
        // reconciler does not forge one, so this is asserted through the projector directly
        // with a forged claim -- the shape a confused or hostile remote would produce.
        $applied = app(\Workdo\LynomiaChat\app\Services\ConsentStateProjector::class)->apply($link, [
            'origin' => 'lynomia_native',
            'store' => ['external_store_id' => (string) ((int) $link->store_id + 1)],
            'subject' => ['external_customer_id' => 'cust-77'],
            'purpose' => 'promotional', 'channel' => 'any', 'state' => 'withdrawn', 'revision' => 99,
        ]);

        $this->assertFalse($applied);
        $this->assertSame(0, LynomiaConsentState::count());
    }

    /** One store failing must not stop the sweep reaching the next merchant. */
    public function test_the_command_survives_a_store_it_cannot_reach(): void
    {
        $broken = $this->connectedLink(7001);
        $this->lynomiaScript['fail'] = [
            'GET /platform/api/v1/accounts/7001/partner_consents' => Http::failedConnection(),
        ];

        $this->artisan('lynomia:reconcile-consents')
            ->expectsOutputToContain('unreachable 1')
            ->assertExitCode(0);

        $this->assertSame(LinkState::ACTIVE, $broken->fresh()->state, 'a failed read must not revoke a link');
    }

    public function test_the_command_reconciles_only_the_store_it_is_given(): void
    {
        $first = $this->connectedLink(7001);
        $this->remote([], [$this->nativeRow()]);

        $this->artisan('lynomia:reconcile-consents', ['--store' => $first->store_id])->assertExitCode(0);

        $this->assertSame(1, LynomiaConsentState::forStore($first->store_id)->count());
    }

    public function test_the_command_does_nothing_while_the_integration_is_switched_off(): void
    {
        $this->connectedLink();
        config()->set('lynomia.enabled', false);

        $this->artisan('lynomia:reconcile-consents')->assertExitCode(0);

        $this->assertSame(0, LynomiaConsentState::count());
    }
}
