<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Models\LynomiaConsentState;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\EventSubscriptionManager;
use Workdo\LynomiaChat\app\Services\ConsentDecisionDispatcher;
use Workdo\LynomiaChat\app\Services\EventSignature;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * BIDIRECTIONAL CONSENT SYNCHRONISATION.
 *
 * Outbound: a decision a customer gave this store is delivered to Lynomia, once, with an
 * identity that makes every repeat a no-op there. Inbound: a decision made on a Lynomia
 * channel -- a shopper replying STOP on WhatsApp -- lands here so the merchant screen is not
 * confidently wrong.
 *
 * WHAT THESE TESTS DO NOT PROVE. Both directions run against a scriptable fake of the other
 * system. That is agreement between two expressions of one reading of the contract, not
 * evidence the reading is right. Blocker A1 stays open until two real deployments have
 * actually exchanged one of these.
 */
class LynomiaConsentSyncTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: LynomiaStoreLink} */
    private function connectedTenant(int $accountId = 7001): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        $link = app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store, $link->fresh()];
    }

    // ============================================================ the subscription

    /**
     * A STORE CONNECTED BEFORE AN EVENT EXISTED HAS TO BE SUBSCRIBED TO IT.
     *
     * `consent_changed` joined SUBSCRIPTIONS in P5E. `ensureSubscribed` matched on the URL
     * alone and returned early the moment it found one, so every store connected before
     * that upgrade kept a webhook carrying the OLD events and never received a consent
     * change again -- silently, with only the hourly reconciler behind it.
     */
    public function test_a_webhook_registered_before_consent_events_existed_is_widened(): void
    {
        [$owner, , $link] = $this->connectedTenant();
        $url = route('lynomia.events.ingress', ['store' => $link->store_id]);
        $this->lynomiaScript['webhooks'] = [
            ['id' => 44, 'url' => $url, 'subscriptions' => ['conversation_created', 'message_created']],
        ];

        app(EventSubscriptionManager::class)->ensureSubscribed($link, $owner);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/webhooks/44') || strtoupper($request->method()) !== 'PATCH') {
                return false;
            }
            $sent = json_decode($request->body(), true)['webhook']['subscriptions'] ?? [];

            // Widened, not replaced: what the workspace already subscribed stays.
            return in_array('consent_changed', $sent, true)
                && in_array('conversation_created', $sent, true)
                && in_array('message_created', $sent, true);
        });
    }

    public function test_a_webhook_that_already_covers_everything_is_left_alone(): void
    {
        [$owner, , $link] = $this->connectedTenant();
        $url = route('lynomia.events.ingress', ['store' => $link->store_id]);
        $this->lynomiaScript['webhooks'] = [
            ['id' => 44, 'url' => $url, 'subscriptions' => EventSubscriptionManager::SUBSCRIPTIONS],
        ];

        $this->assertFalse(app(EventSubscriptionManager::class)->ensureSubscribed($link, $owner));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/webhooks/44'));
    }

    // ============================================================ Store Syria -> Lynomia

    public function test_a_decision_is_delivered_to_lynomia_with_its_own_identity(): void
    {
        [, , $link] = $this->connectedTenant();

        $decision = $this->decision($link, ['state' => 'granted']);
        $this->dispatcher()->deliver($decision);

        Http::assertSent(function ($request) use ($decision) {
            return str_contains($request->url(), '/partner_consents')
                && $request['decision_ref'] === $decision->decision_ref
                && $request['state'] === 'granted'
                && $request['provider'] === 'storesyria';
        });

        $this->assertNotNull($decision->fresh()->delivered_at);
        $this->assertSame('applied', $decision->fresh()->remote_outcome);
    }

    /**
     * No name, no phone, no email. Lynomia resolves the subject through the customer link it
     * already holds, so sending contact details would put them in a request log and a queue
     * for nobody to read.
     */
    public function test_the_delivery_carries_no_customer_contact_details(): void
    {
        [, , $link] = $this->connectedTenant();

        $this->dispatcher()->deliver($this->decision($link, [
            'state' => 'granted',
            'evidence' => ['notice' => 'checkout_v3'],
        ]));

        Http::assertSent(function ($request) {
            $keys = array_keys((array) $request->data());

            return ! array_intersect($keys, ['email', 'phone', 'phone_number', 'name', 'customer_name']);
        });
    }

    /**
     * A REFUSAL IS AN ANSWER, NOT A FAILURE. Lynomia refuses a plain grant that follows a
     * withdrawal, and that refusal is permanent. Retrying would be asking the same question
     * until the answer changed, and it will not.
     */
    public function test_a_refused_grant_is_settled_rather_than_retried(): void
    {
        [, , $link] = $this->connectedTenant();
        $this->lynomiaScript['partner_consent_outcome'] = 'refused_withdrawal_dominant';

        $decision = $this->decision($link, ['state' => 'granted']);
        $this->dispatcher()->deliver($decision);

        $decision->refresh();
        $this->assertNotNull($decision->delivered_at, 'a settled refusal must not stay pending');
        $this->assertSame('refused_withdrawal_dominant', $decision->remote_outcome);
        $this->assertSame(0, LynomiaConsentDecision::query()->pending()->count());
    }

    public function test_an_unreachable_lynomia_defers_with_backoff_and_keeps_the_same_identity(): void
    {
        [, , $link] = $this->connectedTenant();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        $decision = $this->decision($link, ['state' => 'withdrawn']);
        $ref = $decision->decision_ref;

        $this->dispatcher()->deliver($decision);

        $decision->refresh();
        $this->assertNull($decision->delivered_at);
        $this->assertSame(1, $decision->attempts);
        $this->assertNotNull($decision->deliver_after);
        // LynomiaHttp converts a transport failure into a KIND_UNREACHABLE exception before
        // it reaches here, so the reason is kind-prefixed -- the dispatcher never sees a raw
        // ConnectionException, which is why the message never carries a URL.
        $this->assertSame('kind_unreachable', $decision->last_error);
        $this->assertSame($ref, $decision->decision_ref, 'a retry must reuse the identity the receiver dedupes on');
    }

    /**
     * A FIRST-TIME SHOPPER'S OPT-IN IS A RACE, NOT A MISTAKE.
     *
     * Lynomia resolves the subject through its own customer link, and that link is created
     * by the order event -- which arrives AFTER the checkout opt-in this dispatcher is
     * carrying. Its 404 was classed with every other 404 as "this side's mistake and it
     * will be the same mistake next time", so the decision was retired as `rejected` on its
     * first attempt and the shopper's consent was lost. Nothing said so either: the
     * reconciler skips a scope with nothing known remotely, believing the outbox still owns
     * it, so the operator's drift count stayed 0.
     *
     * The two 404s are told apart by `error_code`, which Lynomia now sends.
     */
    public function test_a_subject_lynomia_has_not_linked_yet_is_retried_rather_than_retired(): void
    {
        [, , $link] = $this->connectedTenant();
        // Scripted through the trait rather than a second Http::fake(), which MERGES: the
        // tenant's own fake is consulted first and would answer 201.
        $this->lynomiaScript['fail'] = [
            "POST /platform/api/v1/accounts/{$link->lynomia_account_id}/partner_consents" => Http::response(
                ['error' => 'No customer of this installation matches that subject',
                    'error_code' => 'subject_not_linked'],
                404
            ),
        ];

        $decision = $this->decision($link, ['state' => 'granted']);
        $this->dispatcher()->deliver($decision);

        $decision->refresh();
        $this->assertNull($decision->delivered_at, 'the opt-in is still owed, so it is not settled');
        $this->assertNull($decision->remote_outcome);
        $this->assertNotNull($decision->deliver_after);
        $this->assertSame('kind_subject_not_linked', $decision->last_error);
    }

    /** And a 404 that is NOT the race is still permanent -- an installation this account does not have. */
    public function test_an_unknown_installation_still_retires_the_decision(): void
    {
        [, , $link] = $this->connectedTenant();
        $this->lynomiaScript['fail'] = [
            "POST /platform/api/v1/accounts/{$link->lynomia_account_id}/partner_consents" => Http::response(
                ['error' => 'No such installation for this account'],
                404
            ),
        ];

        $decision = $this->decision($link, ['state' => 'granted']);
        $this->dispatcher()->deliver($decision);

        $decision->refresh();
        $this->assertNotNull($decision->delivered_at);
        $this->assertSame('rejected', $decision->remote_outcome);
        $this->assertSame('kind_not_found', $decision->last_error);
    }

    public function test_a_disconnected_store_retires_the_decision_instead_of_retrying_forever(): void
    {
        [, , $link] = $this->connectedTenant();
        $link->forceFill(['state' => 'revoked'])->save();
        Http::fake();

        $decision = $this->decision($link, ['state' => 'granted']);
        $this->dispatcher()->deliver($decision);

        $this->assertSame('link_not_active', $decision->fresh()->last_error);
        $this->assertNotNull($decision->fresh()->delivered_at);
        Http::assertNothingSent();
    }

    /**
     * B3.2. The race is retried for a LONG time -- weeks -- but not for ever.
     *
     * Nothing in an order event creates the customer link this is waiting for: it appears when
     * the shopper first writes in and is auto-matched, or when an agent links them by hand. A
     * shopper who never does either left a row retried once an hour for the life of the
     * installation, and nothing prunes this table.
     */
    public function test_a_decision_that_has_waited_a_month_is_retired_undelivered(): void
    {
        [, , $link] = $this->connectedTenant();
        $this->lynomiaScript['fail'] = [
            "POST /platform/api/v1/accounts/{$link->lynomia_account_id}/partner_consents" => Http::response(
                ['error' => 'No customer of this installation matches that subject',
                    'error_code' => 'subject_not_linked'],
                404
            ),
        ];

        $decision = $this->decision($link, ['state' => 'granted']);
        $decision->forceFill(['captured_at' => now()->subDays(31), 'attempts' => 300])->save();

        $this->dispatcher()->deliver($decision);

        $decision->refresh();
        $this->assertNotNull($decision->delivered_at, 'it is terminal, so the sweep stops picking it up');
        $this->assertStringStartsWith('expired_after_30d', $decision->last_error);
        // LEFT NULL DELIBERATELY: the merchant's consent screen maps outcomes with a default
        // arm that reads "accepted", so any value here would tell them an opt-in took effect
        // that never reached Lynomia. No outcome is what that screen renders as "Not sent".
        $this->assertNull($decision->remote_outcome);
    }

    /** ...and one that has not waited that long is still held, which is the B2.3 behaviour. */
    public function test_a_decision_inside_the_window_is_still_retried(): void
    {
        [, , $link] = $this->connectedTenant();
        $this->lynomiaScript['fail'] = [
            "POST /platform/api/v1/accounts/{$link->lynomia_account_id}/partner_consents" => Http::response(
                ['error_code' => 'subject_not_linked'], 404
            ),
        ];

        $decision = $this->decision($link, ['state' => 'granted']);
        $decision->forceFill(['captured_at' => now()->subDays(29), 'attempts' => 300])->save();

        $this->dispatcher()->deliver($decision);

        $decision->refresh();
        $this->assertNull($decision->delivered_at, 'still owed');
        $this->assertSame('kind_subject_not_linked', $decision->last_error);
    }

    // ============================================================ Lynomia -> Store Syria

    public function test_an_opt_out_on_whatsapp_lands_in_the_consent_state(): void
    {
        [, , $link] = $this->connectedTenant();

        $this->deliverEvent($link, $this->consentPayload($link, ['state' => 'withdrawn', 'revision' => 10]))
            ->assertOk();

        $state = LynomiaConsentState::forStore($link->store_id)->sole();
        $this->assertSame('withdrawn', $state->state);
        $this->assertSame('lynomia_native', $state->origin);
        $this->assertTrue($state->blocking());
    }

    /** At-least-once delivery must not re-apply a consent change. */
    public function test_a_duplicate_delivery_changes_nothing(): void
    {
        [, , $link] = $this->connectedTenant();
        $payload = $this->consentPayload($link, ['state' => 'withdrawn', 'revision' => 10]);

        $this->deliverEvent($link, $payload, ['delivery' => 'same-id'])->assertOk();
        $this->deliverEvent($link, $payload, ['delivery' => 'same-id'])->assertOk();

        $this->assertSame(1, LynomiaConsentState::forStore($link->store_id)->count());
    }

    /**
     * THE PROPERTY THE WHOLE DESIGN TURNS ON, on this side too: a delayed grant must not
     * reinstate a withdrawal. Ordering is Lynomia's server-assigned revision, so an event
     * that was assigned earlier loses however late it arrives.
     */
    public function test_a_late_grant_with_an_older_revision_does_not_overwrite_a_withdrawal(): void
    {
        [, , $link] = $this->connectedTenant();

        $this->deliverEvent($link, $this->consentPayload($link, ['state' => 'withdrawn', 'revision' => 20]))->assertOk();
        $this->deliverEvent($link, $this->consentPayload($link, ['state' => 'granted', 'revision' => 15]))->assertOk();

        $this->assertSame('withdrawn', LynomiaConsentState::forStore($link->store_id)->sole()->state);
    }

    public function test_a_newer_revision_does_update_the_state(): void
    {
        [, , $link] = $this->connectedTenant();

        $this->deliverEvent($link, $this->consentPayload($link, ['state' => 'withdrawn', 'revision' => 20]))->assertOk();
        $this->deliverEvent($link, $this->consentPayload($link, ['state' => 'granted', 'revision' => 25,
            're_consent' => true]))->assertOk();

        $this->assertSame('granted', LynomiaConsentState::forStore($link->store_id)->sole()->state);
    }

    public function test_purposes_and_channels_are_separate_scopes(): void
    {
        [, , $link] = $this->connectedTenant();

        $this->deliverEvent($link, $this->consentPayload($link,
            ['state' => 'withdrawn', 'purpose' => 'promotional', 'revision' => 10]))->assertOk();
        $this->deliverEvent($link, $this->consentPayload($link,
            ['state' => 'granted', 'purpose' => 'transactional', 'revision' => 11]))->assertOk();

        $states = LynomiaConsentState::forStore($link->store_id)->pluck('state', 'purpose');
        $this->assertSame('withdrawn', $states['promotional']);
        $this->assertSame('granted', $states['transactional']);
    }

    /** A signature proves who sent it; the store in the body proves which installation it may speak for. */
    public function test_an_event_naming_another_store_is_not_projected(): void
    {
        [, , $link] = $this->connectedTenant();
        $payload = $this->consentPayload($link, ['state' => 'withdrawn', 'revision' => 10]);
        $payload['store']['external_store_id'] = StoreIdentity::of((int) $link->store_id + 999);

        $this->deliverEvent($link, $payload)->assertOk();

        $this->assertSame(0, LynomiaConsentState::count());
    }

    // ============================================================ helpers

    private function dispatcher(): ConsentDecisionDispatcher
    {
        return new ConsentDecisionDispatcher(app(PlatformApiClient::class));
    }

    private function decision(LynomiaStoreLink $link, array $attributes = []): LynomiaConsentDecision
    {
        return LynomiaConsentDecision::create(array_merge([
            'store_id' => $link->store_id,
            'external_subject_id' => 'cust-77',
            'purpose' => 'promotional',
            'channel' => 'any',
            'state' => 'granted',
            'source' => 'checkout_optin',
            'policy_version' => 'v3',
            'captured_at' => now(),
            'decision_ref' => (string) \Illuminate\Support\Str::uuid(),
        ], $attributes));
    }

    private function consentPayload(LynomiaStoreLink $link, array $attributes = []): array
    {
        return array_merge([
            'event' => 'consent_changed',
            'origin' => 'lynomia_native',
            'account' => ['id' => (int) $link->lynomia_account_id],
            'store' => ['provider' => 'storesyria', 'external_store_id' => StoreIdentity::of((int) $link->store_id)],
            'subject' => ['external_customer_id' => 'cust-77'],
            'purpose' => 'promotional',
            'channel' => 'any',
            'state' => 'withdrawn',
            'source' => 'shopper_keyword',
            'occurred_at' => now()->toIso8601String(),
            'revision' => 1,
        ], $attributes);
    }

    /** Posted the way Lynomia's Webhooks::Trigger does. */
    private function deliverEvent(LynomiaStoreLink $link, array $payload, array $overrides = [])
    {
        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}",
            EventSignature::secretFor((int) $link->store_id));

        $server = [];
        foreach ([
            EventSignature::SIGNATURE_HEADER => $signature,
            EventSignature::TIMESTAMP_HEADER => $timestamp,
            EventSignature::DELIVERY_HEADER => $overrides['delivery'] ?? 'delivery-' . uniqid(),
            'Content-Type' => 'application/json',
        ] as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $this->call('POST', "/lynomia/events/{$link->store_id}", [], [], [], $server, $body);
    }
}
