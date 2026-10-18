<?php

namespace Tests\Feature\Lynomia;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\CommerceActivator;
use Workdo\LynomiaChat\app\Services\CommerceCredential;
use Workdo\LynomiaChat\app\Support\CommerceState;
use Workdo\LynomiaChat\app\Support\LinkState;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * AUTOMATIC COMMERCE ACTIVATION: the merchant's half of the connection, with nobody handling a
 * token.
 *
 * WHAT WAS MISSING BEFORE THIS. Everything between the two systems already worked -- the derived
 * credential, the commerce API, the provider adapter on the far side, the tenancy checks at both
 * ends. The only thing absent was something to carry the credential ACROSS, so the merchant's
 * one-click workspace was followed by: an operator mints a token in Super Admin, a human carries
 * it to Lynomia, an administrator of that workspace pastes it into a form.
 *
 * NOTHING HERE TOUCHES PRODUCTION. Every store is an in-memory fixture, the Lynomia installation
 * is the shared scriptable fake (Http::preventStrayRequests, so an unscripted call fails loudly),
 * and the master secret is the harness dummy. Store 26 and account 135 are never named.
 *
 * The refusals are the point. A merchant clicking a button is the easy half; what makes it safe to
 * offer is that a foreign store, an inactive link, a revoked credential, a second simultaneous
 * click and a misconfigured installation are each refused, and that a failure never leaves a
 * green tick over a dead connection.
 */
class LynomiaCommerceActivationTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    /** An active, provisioned link with no commerce installation and no credential yet. */
    private function activeLink(): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();

        return [$owner->fresh(), $store, $link];
    }

    private function activator(): CommerceActivator
    {
        return app(CommerceActivator::class);
    }

    /** @return array<string,mixed> the body of the one commerce_installation call that was sent */
    private function installRequest(): array
    {
        foreach (Http::recorded() as [$request]) {
            if (str_contains((string) $request->url(), '/commerce_installation')) {
                return json_decode($request->body(), true) ?? [];
            }
        }

        return [];
    }

    private function installCallCount(): int
    {
        return $this->sentCount('PUT', '/commerce_installation');
    }

    // ================================================== the merchant journey, end to end

    /**
     * E2E-01 (simulated). ONE CLICK, BOTH HALVES. The whole reason the activation is wired into
     * provisioning: a workspace with no commerce installation is a chat inbox with no orders in
     * it, and leaving the merchant to find a second button is the manual step this replaces.
     */
    public function test_provisioning_a_new_workspace_also_connects_the_store_data(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7001]);

        $response = $this->actingAs($owner->fresh())->post('/lynomia/connect/provision');

        $response->assertSessionHas('success');
        $link = LynomiaStoreLink::forStore((int) $store->id)->first();
        $this->assertSame(LinkState::ACTIVE, $link->state);
        $this->assertSame(3, (int) $link->lynomia_commerce_store_id);
        $this->assertSame('active', $link->commerce_remote_status);
        $this->assertNull($link->commerce_last_error);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link));
    }

    /**
     * E2E-02. The merchant who already had a workspace and no commerce: one button, no
     * reprovisioning, and the same workspace afterwards.
     */
    public function test_an_existing_workspace_completes_commerce_without_reprovisioning(): void
    {
        [$owner, $store, $link] = $this->activeLink();
        $this->assertSame(CommerceState::NOT_CONNECTED, CommerceState::of($link));
        $accountBefore = (int) $link->lynomia_account_id;

        $response = $this->actingAs($owner)->post('/lynomia/commerce/connect');

        $response->assertSessionHas('success');
        $link = $link->fresh();
        $this->assertSame($accountBefore, (int) $link->lynomia_account_id);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link));
        $this->assertSame(1, LynomiaStoreLink::count(), 'no second link row was created');
    }

    /**
     * E2E-03 and E2E-12. The already-connected merchant. A verify is a NO-OP on both sides: the
     * remote operation reports `applied: false` and -- the part that matters for a live store --
     * the credential version does not move, so the working installation is not invalidated by
     * being checked.
     */
    public function test_verifying_an_active_connection_changes_nothing_and_rotates_nothing(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->actingAs($owner)->post('/lynomia/commerce/connect');
        $versionAfterInstall = (int) $link->fresh()->commerce_credential_version;

        $this->fakeLynomia([
            'account_id' => 7001,
            'commerce_installation' => ['provider' => 'storesyria', 'commerce_store_id' => 3,
                'status' => 'active', 'applied' => false],
        ]);

        $response = $this->actingAs($owner)->post('/lynomia/commerce/verify');

        $response->assertSessionHas('success');
        $this->assertSame($versionAfterInstall, (int) $link->fresh()->commerce_credential_version);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link->fresh()));
    }

    // ================================================== the credential

    /**
     * THE PROPERTY THAT PROTECTS A LIVE STORE. Rotating invalidates every token minted before it,
     * so an activation that rotated on every attempt would take down the working installation it
     * was asked to repair. A store with an active credential re-sends THAT one.
     */
    public function test_activation_reuses_an_active_credential_instead_of_rotating_it(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->forceFill(['commerce_credential_version' => 4, 'commerce_credential_issued_at' => now()])->save();
        $expected = CommerceCredential::issue($link->fresh());

        $this->activator()->activate($link->fresh(), $owner);

        $this->assertSame(4, (int) $link->fresh()->commerce_credential_version);
        $this->assertSame($expected, $this->installRequest()['commerce_token']);
    }

    public function test_activation_mints_a_credential_when_the_store_has_none(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->assertSame(0, (int) $link->commerce_credential_version);

        $this->activator()->activate($link, $owner);

        $fresh = $link->fresh();
        $this->assertSame(1, (int) $fresh->commerce_credential_version);
        $this->assertTrue($fresh->hasActiveCommerceCredential());
        $this->assertSame(CommerceCredential::issue($fresh), $this->installRequest()['commerce_token']);
    }

    /**
     * A REVOKED CREDENTIAL IS NOT A CREDENTIAL. Re-deriving a revoked version would send a token
     * the commerce middleware is guaranteed to refuse -- the version check is a database read on
     * every request -- so there is no window in which reuse would work.
     */
    public function test_activation_mints_again_when_the_credential_was_revoked(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->forceFill(['commerce_credential_version' => 2, 'commerce_credential_issued_at' => null])->save();

        $this->activator()->activate($link->fresh(), $owner);

        $this->assertSame(3, (int) $link->fresh()->commerce_credential_version);
    }

    /**
     * E2E-09. THE DEFECT THIS WHOLE PATH WAS BUILT AROUND. Issuance used to bump the version and
     * only then derive; deriving refuses outright with no master secret, so a failed issuance had
     * already revoked every credential minted before it. The version must not move.
     */
    public function test_a_missing_master_secret_leaves_the_credential_version_untouched(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->forceFill(['commerce_credential_version' => 7, 'commerce_credential_issued_at' => now()])->save();
        config()->set('lynomia.webhook_master_secret', '');

        try {
            CommerceCredential::mint($link->fresh());
            $this->fail('minting should refuse without a master secret');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('LYNOMIA_WEBHOOK_MASTER_SECRET', $e->getMessage());
        }

        $this->assertSame(7, (int) $link->fresh()->commerce_credential_version);
        $this->assertNotNull($link->fresh()->commerce_credential_issued_at);
    }

    /** The same defect from the merchant's side: refused before an attempt is even counted. */
    public function test_activation_refuses_before_minting_when_the_installation_is_not_configured(): void
    {
        [$owner, , $link] = $this->activeLink();
        config()->set('lynomia.webhook_master_secret', '');

        try {
            $this->activator()->activate($link, $owner);
            $this->fail('activation should refuse');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_NOT_CONFIGURED, $e->kind);
        }

        $this->assertSame(0, $this->installCallCount());
        $this->assertSame(0, (int) $link->fresh()->commerce_attempts);
    }

    // ================================================== what goes on the wire

    public function test_the_request_names_the_installation_qualified_store_and_this_installation_url(): void
    {
        [$owner, $store, $link] = $this->activeLink();
        config()->set('app.url', 'https://storesyria.test/');

        $this->activator()->activate($link, $owner);

        $body = $this->installRequest();
        $this->assertSame('storesyria', $body['provider']);
        $this->assertSame(StoreIdentity::of((int) $store->id), $body['external_store_id']);
        // THE MERCHANT SUPPLIES NO URL. It is this installation's own, which is how the SSRF
        // question is answered here rather than defended against.
        $this->assertSame('https://storesyria.test', $body['base_url']);
    }

    /** A merchant never holds a commerce token. Not in the page, not in the session, not in a log. */
    public function test_no_credential_reaches_the_merchant(): void
    {
        [$owner, , $link] = $this->activeLink();

        $response = $this->actingAs($owner)->post('/lynomia/commerce/connect');
        $credential = CommerceCredential::issue($link->fresh());

        $response->assertSessionMissing('lynomia_commerce_credential');
        foreach ($response->baseResponse->headers->all() as $values) {
            foreach ((array) $values as $value) {
                $this->assertStringNotContainsString($credential, (string) $value);
            }
        }

        $page = $this->actingAs($owner)->get('/lynomia');
        $page->assertDontSee($credential);
        $page->assertDontSee(CommerceCredential::PREFIX . $link->store_id, false);
    }

    public function test_the_audit_record_carries_the_ids_and_never_the_credential(): void
    {
        [$owner, , $link] = $this->activeLink();

        $this->activator()->activate($link, $owner);

        $entry = AuditLog::where('action', 'lynomia_commerce_installed')->latest('id')->first();
        $this->assertNotNull($entry);

        $values = is_array($entry->new_values) ? $entry->new_values : json_decode((string) $entry->new_values, true);
        $this->assertSame(3, (int) $values['lynomia_commerce_store_id']);
        $this->assertSame(StoreIdentity::of((int) $link->store_id), $values['external_store_id']);
        $this->assertSame('active', $values['remote_status']);
        // Only the fact and the ids. The credential is never written to the audit trail, and
        // neither is anything a reader could derive it from.
        $this->assertStringNotContainsString(
            CommerceCredential::issue($link->fresh()),
            json_encode($entry->getAttributes())
        );
    }

    // ================================================== refusals

    /**
     * E2E-10. The gate is allowsSso(), which is the same one SSO and the commerce API already
     * use -- so a pending claim, a suspended link and a revoked one are refused here for exactly
     * the reasons they are refused there, and nothing is sent.
     */
    public function test_a_link_that_cannot_be_opened_cannot_connect_commerce(): void
    {
        // Each state gets its own tenant and its own workspace id. One scripted account id
        // shared across four provisions would be refused by the unique index on
        // lynomia_account_id -- correctly, since two stores cannot own one workspace -- and the
        // example would then be asserting on a provisioning conflict rather than on the gate.
        $states = [LinkState::PENDING_VERIFICATION, LinkState::SUSPENDED, LinkState::REVOKED, LinkState::DECOMMISSIONED];

        foreach ($states as $index => $state) {
            [$owner, $store] = $this->entitledTenant();
            $this->fakeLynomia(['account_id' => 7100 + $index]);
            $link = app(AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();
            $link->forceFill(['state' => $state])->save();

            $response = $this->actingAs($owner->fresh())->post('/lynomia/commerce/connect');

            $response->assertSessionHas('error');
            $this->assertSame(0, $this->installCallCount(), "state {$state} reached Lynomia");
            $this->assertSame(CommerceState::UNAVAILABLE, CommerceState::of($link->fresh()));
            $this->assertSame(0, (int) $link->fresh()->commerce_credential_version);
        }
    }

    /**
     * An operator's approval of a CLAIMED workspace is not enough: Lynomia itself has to have
     * confirmed who the claimant is. The same clause allowsSso() enforces for opening it.
     */
    public function test_a_claimed_workspace_whose_ownership_was_never_proven_cannot_connect_commerce(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->forceFill([
            'link_mode' => LynomiaStoreLink::MODE_LINK_EXISTING,
            'ownership_proven_at' => null,
        ])->save();

        $response = $this->actingAs($owner)->post('/lynomia/commerce/connect');

        $response->assertSessionHas('error');
        $this->assertSame(0, $this->installCallCount());
    }

    /**
     * AND THE PAGE DOES NOT OFFER A BUTTON THAT CANNOT WORK. An ACTIVE claimed workspace whose
     * ownership Lynomia never confirmed still renders the workspace card, so without this the
     * merchant was shown "Complete store data setup" over a link the commerce API would refuse
     * and told, on pressing it, to connect a store that is already connected.
     */
    public function test_an_unproven_claimed_workspace_is_told_why_rather_than_offered_a_button(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->forceFill([
            'link_mode' => LynomiaStoreLink::MODE_LINK_EXISTING,
            'ownership_proven_at' => null,
        ])->save();

        $this->assertSame(CommerceState::UNAVAILABLE, CommerceState::of($link->fresh()));

        $page = $this->actingAs($owner)->get('/lynomia');

        $page->assertSee(__('Your Lynomia Chat connection is not ready for store data yet. Please contact support.'));
        $page->assertDontSee(__('Complete store data setup'));
        $page->assertDontSee(route('lynomia.commerce.connect'));
    }

    /**
     * E2E-08. A merchant pointing current_store at another tenant's store. EnsureStoreOwnsLink
     * authorises the STORE before it looks a link up, so the foreign store id never reaches the
     * controller -- and this is the case that matters most, because the foreign store HAS a link
     * and the activator would otherwise have been handed a usable one.
     */
    public function test_a_merchant_cannot_connect_commerce_for_another_tenants_store(): void
    {
        [, $storeA, $linkA] = $this->activeLink();

        $ownerB = $this->merchant();
        $this->storeFor($ownerB);
        $this->entitle($ownerB);
        $ownerB->current_store = $storeA->id;
        $ownerB->save();

        $response = $this->actingAs($ownerB->fresh())->post('/lynomia/commerce/connect');

        $response->assertStatus(302);
        $response->assertSessionHas('error');
        $this->assertSame(0, $this->installCallCount());
        $this->assertNull($linkA->fresh()->lynomia_commerce_store_id);
        $this->assertSame(0, (int) $linkA->fresh()->commerce_credential_version);
    }

    // ================================================== failure, retry and concurrency

    /**
     * E2E-04. A failure records a merchant-safe reason and leaves the WORKSPACE alone. Sending a
     * merchant back to re-provision something that already exists is how a resumable operation
     * gets re-run.
     */
    public function test_a_failed_activation_records_the_reason_and_keeps_the_workspace(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->fakeLynomia([
            'account_id' => 7001,
            'fail' => ['PUT /platform/api/v1/accounts/7001/commerce_installation' => Http::failedConnection()],
        ]);

        $response = $this->actingAs($owner)->post('/lynomia/commerce/connect');

        $response->assertSessionHas('error');
        $fresh = $link->fresh();
        $this->assertSame(LinkState::ACTIVE, $fresh->state, 'the workspace survived');
        $this->assertNotNull($fresh->lynomia_account_id);
        $this->assertSame(CommerceState::FAILED, CommerceState::of($fresh));
        $this->assertNotNull($fresh->commerce_last_error);
        $this->assertSame(1, (int) $fresh->commerce_attempts);
        $this->assertNull($fresh->commerce_activation_started_at, 'the claim was released');
    }

    /** And the retry works, from the same button, with no duplicate anything. */
    public function test_the_retry_after_a_failure_succeeds_and_clears_the_reason(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->fakeLynomia([
            'account_id' => 7001,
            'fail' => ['PUT /platform/api/v1/accounts/7001/commerce_installation' => Http::failedConnection()],
        ]);
        $this->actingAs($owner)->post('/lynomia/commerce/connect');

        $this->fakeLynomia(['account_id' => 7001]);
        $response = $this->actingAs($owner)->post('/lynomia/commerce/connect');

        $response->assertSessionHas('success');
        $fresh = $link->fresh();
        $this->assertNull($fresh->commerce_last_error);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($fresh));
        $this->assertSame(0, (int) $fresh->commerce_attempts, 'the counter resets on success');
        $this->assertSame(1, LynomiaStoreLink::count());
    }

    /**
     * The merchant-safe reason, not the provider's prose. KIND_CONFLICT is the one kind whose own
     * message is written for a merchant to read, and the commerce refusals Lynomia defines land
     * there -- so a plan without the commerce module says so, rather than "invalid request".
     */
    public function test_a_plan_without_commerce_is_reported_as_an_entitlement_not_a_fault(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->fakeLynomia([
            'account_id' => 7001,
            'fail' => ['PUT /platform/api/v1/accounts/7001/commerce_installation' => Http::response(
                ['error' => 'Commerce is not enabled for that account', 'error_code' => 'commerce_disabled'],
                422
            )],
        ]);

        $this->actingAs($owner)->post('/lynomia/commerce/connect');

        $reason = (string) $link->fresh()->commerce_last_error;
        $this->assertStringContainsString('plan', $reason);
        // Lynomia's own sentence never reaches the merchant.
        $this->assertStringNotContainsString('not enabled for that account', $reason);
    }

    /**
     * E2E-06. Two simultaneous requests. The conditional UPDATE is the arbiter: exactly one
     * caller proceeds and the other is told to wait, so one click too many is not a second
     * installation and not an error a merchant has to interpret.
     */
    public function test_two_simultaneous_activations_produce_one_call(): void
    {
        [$owner, , $link] = $this->activeLink();

        // The first caller's claim, taken and still held -- which is exactly the row a second
        // request sees while the first is mid-flight.
        $link->forceFill(['commerce_activation_started_at' => now()])->save();

        try {
            $this->activator()->activate($link->fresh(), $owner);
            $this->fail('the second caller should be told to wait');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_IN_PROGRESS, $e->kind);
        }

        $this->assertSame(0, $this->installCallCount());
    }

    /** A claim left behind by a killed process goes stale, so a store cannot be wedged. */
    public function test_a_stale_claim_does_not_block_the_next_attempt(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->forceFill(['commerce_activation_started_at' => now()->subHours(2)])->save();

        $this->activator()->activate($link->fresh(), $owner);

        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link->fresh()));
    }

    // ================================================== the merchant stops sharing

    /**
     * THE PROPERTY §14 ACTUALLY ASKS FOR: revocation stops the reads even if the other system
     * never hears about it. The credential version is inside the signed material and the commerce
     * middleware re-reads it on every request, so every token already issued stops verifying at
     * once, without a deploy and without Lynomia's cooperation.
     */
    public function test_disconnecting_revokes_the_credential_and_tells_lynomia(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->actingAs($owner)->post('/lynomia/commerce/connect');
        $working = CommerceCredential::issue($link->fresh());
        $this->assertSame((int) $link->store_id, CommerceCredential::resolveStoreId($working));

        $response = $this->actingAs($owner)->post('/lynomia/commerce/disconnect');

        $response->assertSessionHas('success');
        // The token that worked a moment ago no longer resolves to anything.
        $this->assertNull(CommerceCredential::resolveStoreId($working));
        $this->assertFalse($link->fresh()->hasActiveCommerceCredential());
        $this->assertSame('disconnected', $link->fresh()->commerce_remote_status);
        $this->assertSame(CommerceState::DISCONNECTED, CommerceState::of($link->fresh()));
        // The workspace half is untouched: conversations, team and SSO all stay.
        $this->assertSame(LinkState::ACTIVE, $link->fresh()->state);
        $this->assertTrue($link->fresh()->allowsSso());
    }

    /**
     * AND IT HOLDS WHEN LYNOMIA IS UNREACHABLE, which is the whole point of doing the local half
     * first. Reporting a failed disconnect here would tell the merchant their data is still being
     * read when it is not.
     */
    public function test_disconnecting_still_stops_the_reads_when_lynomia_cannot_be_told(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->actingAs($owner)->post('/lynomia/commerce/connect');
        $working = CommerceCredential::issue($link->fresh());

        $this->fakeLynomia([
            'account_id' => 7001,
            'fail' => ['PUT /platform/api/v1/accounts/7001/installation_lifecycle' => Http::failedConnection()],
        ]);
        $response = $this->actingAs($owner)->post('/lynomia/commerce/disconnect');

        $response->assertSessionHas('success');
        $this->assertNull(CommerceCredential::resolveStoreId($working));
        // Nothing was written that Lynomia did not say -- and the merchant is still told the
        // truth, because a revoked credential reads as a disconnected installation.
        $this->assertSame('active', $link->fresh()->commerce_remote_status);
        $this->assertSame(CommerceState::DISCONNECTED, CommerceState::of($link->fresh()));
    }

    /**
     * A MERCHANT ACTION MUST NOT CLOSE A DOOR ON THE OPERATOR'S SIDE. A suspended link already
     * has no commerce access, so there is nothing to stop -- but a push WOULD drop Lynomia's
     * stored credentials, and its `enable` refuses to bring back a store whose credentials are
     * gone. The merchant would have made a pending resume impossible from the far side.
     */
    public function test_disconnecting_is_refused_for_a_link_that_is_not_active(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->actingAs($owner)->post('/lynomia/commerce/connect');
        $versionBefore = (int) $link->fresh()->commerce_credential_version;
        $link->fresh()->forceFill(['state' => LinkState::SUSPENDED])->save();

        $response = $this->actingAs($owner)->post('/lynomia/commerce/disconnect');

        $response->assertSessionHas('error');
        $this->assertSame(0, $this->sentCount('PUT', '/installation_lifecycle'));
        $this->assertSame($versionBefore, (int) $link->fresh()->commerce_credential_version);
    }

    /** Nothing to disconnect is the current state, not a failure the merchant has to interpret. */
    public function test_disconnecting_with_no_installation_changes_nothing(): void
    {
        [$owner, , $link] = $this->activeLink();

        $response = $this->actingAs($owner)->post('/lynomia/commerce/disconnect');

        $response->assertSessionHas('success');
        $this->assertSame(0, (int) $link->fresh()->commerce_credential_version);
        $this->assertSame(0, $this->sentCount('PUT', '/installation_lifecycle'));
        $this->assertSame(CommerceState::NOT_CONNECTED, CommerceState::of($link->fresh()));
    }

    public function test_reconnecting_after_a_disconnect_mints_a_new_credential(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->actingAs($owner)->post('/lynomia/commerce/connect');
        $this->actingAs($owner)->post('/lynomia/commerce/disconnect');
        $versionAfterRevoke = (int) $link->fresh()->commerce_credential_version;

        $response = $this->actingAs($owner)->post('/lynomia/commerce/connect');

        $response->assertSessionHas('success');
        $fresh = $link->fresh();
        $this->assertSame($versionAfterRevoke + 1, (int) $fresh->commerce_credential_version);
        $this->assertTrue($fresh->hasActiveCommerceCredential());
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($fresh));
        $this->assertSame(1, LynomiaStoreLink::count());
    }

    /**
     * AN INSTALLATION THAT CANNOT AUTHENTICATE IS NOT A CONNECTION, whatever Lynomia's row still
     * says -- which is what an operator revoking in Super Admin leaves behind. Without this the
     * merchant was shown "Connected" over a credential that verifies nothing.
     */
    public function test_an_operator_revoking_the_credential_shows_as_disconnected_not_connected(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->activator()->activate($link, $owner);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link->fresh()));

        // Exactly what SuperAdmin\IntegrationController::revokeCommerceCredential writes.
        $link->fresh()->forceFill([
            'commerce_credential_version' => (int) $link->fresh()->commerce_credential_version + 1,
            'commerce_credential_issued_at' => null,
        ])->save();

        $this->assertSame(CommerceState::DISCONNECTED, CommerceState::of($link->fresh()));
        $page = $this->actingAs($owner)->get('/lynomia');
        $page->assertSee(__('Your store data is not being shared with Lynomia Chat.'));
        $page->assertSee(__('Connect store data'));
    }

    // ============================ the ways a credential and an installation come apart

    /**
     * AN OPERATOR ROTATING A CREDENTIAL USED TO BREAK A LIVE MERCHANT SILENTLY.
     *
     * Before merchants could self-install, the operator who pressed Issue/Rotate WAS the person who
     * then pasted the new token into Lynomia, so the rotation and the re-install were one act. Now
     * they come apart -- and every column still reads healthy: the version is current, issued_at is
     * set, the installation id is recorded, the far side still says `active`. Only comparing what
     * Lynomia was last GIVEN against what is current can see it.
     */
    public function test_an_operator_rotating_the_credential_shows_as_needing_repair(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->activator()->activate($link, $owner);
        $installed = (int) $link->fresh()->commerce_installed_version;
        $this->assertSame((int) $link->fresh()->commerce_credential_version, $installed);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link->fresh()));

        // Exactly what Super Admin's Issue/Rotate does now.
        CommerceCredential::mint($link->fresh());

        $this->assertSame(CommerceState::NEEDS_REPAIR, CommerceState::of($link->fresh()));
        $page = $this->actingAs($owner)->get('/lynomia');
        $page->assertSee(__('Lynomia Chat can no longer read your store data. Reconnecting fixes this.'));
        $page->assertSee(__('Reconnect store data'));
    }

    /** And reconnecting repairs it, leaving the two versions in step again. */
    public function test_reconnecting_after_an_operator_rotation_puts_the_versions_back_in_step(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->activator()->activate($link, $owner);
        CommerceCredential::mint($link->fresh());

        $this->actingAs($owner)->post('/lynomia/commerce/connect');

        $fresh = $link->fresh();
        $this->assertSame((int) $fresh->commerce_credential_version, (int) $fresh->commerce_installed_version);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($fresh));
    }

    /**
     * A STORE WE NEVER INSTALLED FROM HERE IS UNKNOWN, NOT BROKEN. Null means nothing has ever
     * handed Lynomia a credential through this path -- a store connected by hand, or one the sweep
     * merely observed -- so which version it holds cannot be known, and claiming drift would put a
     * Reconnect prompt over a working connection.
     */
    public function test_an_unknown_installed_version_is_not_reported_as_drift(): void
    {
        [$owner, , $link] = $this->activeLink();
        $link->forceFill([
            'lynomia_commerce_store_id' => 41,
            'commerce_remote_status' => 'active',
            'commerce_installed_at' => now(),
            'commerce_installed_version' => null,
            'commerce_credential_version' => 3,
            'commerce_credential_issued_at' => now(),
        ])->save();

        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link->fresh()));
    }

    /**
     * A FAILED CHECK OVER A LIVE INSTALLATION IS A NOTE, NOT A DISCONNECTION.
     *
     * recordFailure deliberately clears nothing it did not learn, so a verify that times out leaves
     * the installation id, the active credential and the far side's `active` all in place. Reading
     * that as "Not connected" told the merchant their store data was off while Lynomia was still
     * reading it -- and took away the one control that would have stopped it.
     */
    public function test_a_failed_verify_does_not_disconnect_a_working_installation(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->actingAs($owner)->post('/lynomia/commerce/connect');
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link->fresh()));

        $this->fakeLynomia([
            'account_id' => 7001,
            'fail' => ['PUT /platform/api/v1/accounts/7001/commerce_installation' => Http::failedConnection()],
        ]);
        $this->actingAs($owner)->post('/lynomia/commerce/verify');

        $fresh = $link->fresh();
        $this->assertNotNull($fresh->commerce_last_error);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($fresh), 'a failed check is not a disconnection');

        $page = $this->actingAs($owner)->get('/lynomia');
        $page->assertSee(__('The last check did not get through:'));
        // The control that actually stops the sharing is still there.
        $page->assertSee(route('lynomia.commerce.disconnect'));
        $page->assertDontSee(__('Try again'));
    }

    /**
     * A SUSPENDED INSTALLATION OUTRANKS A REVOKED CREDENTIAL. The two co-occur -- an operator
     * pauses the store in Lynomia, another revokes the token here -- and reading the credential
     * first offered a Connect button whose press cannot turn the far side back on.
     */
    public function test_a_lynomia_suspension_wins_over_a_revoked_credential(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->activator()->activate($link, $owner);
        $link->fresh()->forceFill([
            'commerce_remote_status' => 'disabled',
            'commerce_credential_version' => 9,
            'commerce_credential_issued_at' => null,
        ])->save();

        $this->assertSame(CommerceState::SUSPENDED, CommerceState::of($link->fresh()));
        $page = $this->actingAs($owner)->get('/lynomia');
        $page->assertSee(__('Your store data connection has been paused. Please contact support.'));
        $page->assertDontSee(route('lynomia.commerce.connect'));
    }

    /**
     * THE FLASH HAS TO AGREE WITH THE PANEL. `applied` only says whether this call changed anything
     * upstream, and a Lynomia-disabled installation answers false -- so branching on it alone told
     * the merchant "connected and working" two inches above a panel correctly reading Unavailable.
     */
    public function test_a_suspended_installation_is_not_flashed_as_working(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->fakeLynomia([
            'account_id' => 7001,
            'commerce_installation' => ['provider' => 'storesyria', 'commerce_store_id' => 3,
                'status' => 'disabled', 'applied' => false],
        ]);

        $response = $this->actingAs($owner)->post('/lynomia/commerce/connect');

        $response->assertSessionHas('error', __('Your store data connection has been paused. Please contact support.'));
        $response->assertSessionMissing('success');
        $this->assertSame(CommerceState::SUSPENDED, CommerceState::of($link->fresh()));
    }

    /**
     * THE BUTTON IS DISABLED WHEN THE CREDENTIAL CANNOT BE DERIVED AT ALL.
     *
     * The panel used to gate on "can we reach Lynomia" -- enabled, base URL, platform token -- which
     * does not cover the master secret the credential is derived from. That is a separate variable
     * and can be absent on its own, so the page offered an enabled button whose press threw before
     * the attempt was even claimed: nothing recorded, no reason on the row, and a flash that was
     * gone on the next reload.
     */
    public function test_the_setup_button_is_disabled_when_the_credential_cannot_be_derived(): void
    {
        [$owner, , $link] = $this->activeLink();
        config()->set('lynomia.webhook_master_secret', '');

        $page = $this->actingAs($owner)->get('/lynomia');

        $page->assertOk();
        $page->assertSee(__('Complete store data setup'));
        // Scoped to THIS button's own markup. Asserting that the page contains the word "disabled"
        // somewhere would pass on any unrelated control and prove nothing.
        $this->assertTrue(
            $this->commerceButtonIsDisabled($page->getContent()),
            'the setup button was pressable with no master secret configured'
        );
    }

    /** And it is pressable in the ordinary case, which is what makes the example above mean something. */
    public function test_the_setup_button_is_pressable_when_the_installation_is_configured(): void
    {
        [$owner] = $this->activeLink();

        $page = $this->actingAs($owner)->get('/lynomia');

        $this->assertFalse($this->commerceButtonIsDisabled($page->getContent()));
    }

    /** The `disabled` attribute on the one button that posts to the commerce connect route. */
    private function commerceButtonIsDisabled(string $html): bool
    {
        $form = strstr($html, route('lynomia.commerce.connect'));
        $this->assertIsString($form, 'the connect form is not on the page at all');

        $button = substr($form, 0, strpos($form, '</form>') ?: strlen($form));

        return str_contains($button, 'disabled');
    }

    /**
     * THE RECORDED REASON IS A CODE, NOT A SENTENCE, so the row does not carry the language of
     * whichever request happened to fail. Every user of a tenant reads the same row.
     */
    public function test_the_recorded_failure_reason_is_locale_free(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->fakeLynomia([
            'account_id' => 7001,
            'fail' => ['PUT /platform/api/v1/accounts/7001/commerce_installation' => Http::response(
                ['error' => 'Commerce is not enabled for that account', 'error_code' => 'commerce_disabled'],
                422
            )],
        ]);

        $this->actingAs($owner)->post('/lynomia/commerce/connect');

        // What is stored is a token, in no language at all.
        $this->assertSame(CommerceState::REASON_PLAN, $link->fresh()->commerce_last_error);

        // And it renders as a sentence, in the reader's language, on demand.
        app()->setLocale('en');
        $this->assertSame(
            __('Your Lynomia Chat plan does not include store data yet. Please contact support to enable it.'),
            CommerceState::reason($link->fresh())
        );
        app()->setLocale('ar');
        $arabic = CommerceState::reason($link->fresh());
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $arabic);
        app()->setLocale('en');
    }

    // ================================================== derived state

    public function test_the_far_sides_own_status_decides_what_the_merchant_is_told(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->activator()->activate($link, $owner);

        foreach ([
            'active' => CommerceState::CONNECTED,
            'disabled' => CommerceState::SUSPENDED,
            'needs_reauth' => CommerceState::NEEDS_REPAIR,
            'disconnected' => CommerceState::DISCONNECTED,
        ] as $remote => $expected) {
            $link->fresh()->forceFill(['commerce_remote_status' => $remote])->save();
            $this->assertSame($expected, CommerceState::of($link->fresh()), "remote status {$remote}");
        }
    }

    /**
     * A SUSPENDED INSTALLATION OFFERS NO BUTTON. It was switched off by a Lynomia operator, and a
     * retry the merchant presses cannot and should not reverse that -- offering one would be
     * offering a control that deliberately does nothing.
     */
    public function test_a_suspended_installation_is_not_offered_a_retry(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->activator()->activate($link, $owner);
        $link->fresh()->forceFill(['commerce_remote_status' => 'disabled'])->save();

        // ASSERTED ON THE PAGE, not on a helper. There used to be a CommerceState::actionable()
        // that nothing in production consulted -- the blade hand-wrote the same rule -- so an
        // assertion on it could stay green while the merchant was offered something else entirely.
        $page = $this->actingAs($owner)->get('/lynomia');
        $page->assertSee(__('Your store data connection has been paused. Please contact support.'));
        $page->assertDontSee(route('lynomia.commerce.connect'));
    }

    /**
     * A COMMERCE INSTALLATION BELONGS TO ONE WORKSPACE. Carried across a re-point, the columns
     * describe an installation the new workspace does not have -- and because "installation id
     * present, no error" reads as CONNECTED, the merchant would be shown a connected store with
     * no orders in it and no action to fix it.
     */
    public function test_repointing_the_workspace_clears_what_was_known_about_the_old_one(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->activator()->activate($link, $owner);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link->fresh()));

        $link->fresh()->forceFill(['lynomia_account_id' => 8002])->save();

        $fresh = $link->fresh();
        $this->assertNull($fresh->lynomia_commerce_store_id);
        $this->assertNull($fresh->commerce_remote_status);
        $this->assertNull($fresh->commerce_installed_at);
        $this->assertSame(CommerceState::NOT_CONNECTED, CommerceState::of($fresh));
    }

    /**
     * EVERY STATE RENDERS, which is worth asserting because the panel is a @switch and a state
     * with no branch falls through to @default -- that is, an in-flight or broken connection
     * would quietly offer the first-time setup button.
     */
    public function test_each_remaining_state_renders_its_own_wording(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->activator()->activate($link, $owner);

        $cases = [
            // in flight: the claim is held and not stale
            [['commerce_activation_started_at' => now(), 'lynomia_commerce_store_id' => null,
                'commerce_remote_status' => null], CommerceState::CONNECTING,
                'We are connecting your store data. This usually takes a few seconds -- refresh to see the result.'],
            // Lynomia rejected the credential
            [['commerce_remote_status' => 'needs_reauth'], CommerceState::NEEDS_REPAIR,
                'Lynomia Chat can no longer read your store data. Reconnecting fixes this.'],
            // the last attempt failed
            [['commerce_remote_status' => null, 'lynomia_commerce_store_id' => null,
                'commerce_last_error' => 'Store data could not be connected just now. You can try again.'],
                CommerceState::FAILED, 'Try again'],
        ];

        foreach ($cases as [$columns, $expectedState, $expectedText]) {
            $link->fresh()->forceFill($columns + ['commerce_activation_started_at' => null])->save();
            $this->assertSame($expectedState, CommerceState::of($link->fresh()));

            $page = $this->actingAs($owner)->get('/lynomia');

            $page->assertOk();
            $page->assertSee(__($expectedText));
        }
    }

    public function test_the_page_offers_the_setup_button_when_commerce_is_not_connected(): void
    {
        [$owner, , $link] = $this->activeLink();

        $page = $this->actingAs($owner)->get('/lynomia');

        $page->assertOk();
        $page->assertSee(__('Complete store data setup'));
        $page->assertSee(route('lynomia.commerce.connect'));
        $page->assertDontSee(__('Your orders, customers and abandoned carts are available to your team in Lynomia Chat.'));
    }

    public function test_the_page_shows_connected_once_it_is(): void
    {
        [$owner, , $link] = $this->activeLink();
        $this->activator()->activate($link, $owner);

        $page = $this->actingAs($owner)->get('/lynomia');

        $page->assertSee(__('Your orders, customers and abandoned carts are available to your team in Lynomia Chat.'));
        $page->assertSee(__('Verify connection'));
        $page->assertDontSee(__('Complete store data setup'));
    }
}
