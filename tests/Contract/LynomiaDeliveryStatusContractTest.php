<?php

namespace Tests\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Lynomia\InteractsWithLynomia;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformEvent;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice;
use Workdo\LynomiaChat\app\Services\EventSignature;
use Workdo\LynomiaChat\app\Support\DeliveryOutcome;

/**
 * THE CONSUMER HALF OF A CROSS-REPOSITORY CONTRACT TEST, for the delivery feed.
 *
 * WHY THIS EXISTS AND WHY PINNING CONSTANTS WAS NOT ENOUGH. Sender-side and receiver-side
 * tests can both pass while disagreeing, and in this integration they already did: the consent
 * feed shipped with a payload carrying no `account`, both repositories' tests were green, and
 * every real delivery would have been refused as `unknown_workspace`. The delivery feed shipped
 * with each repository pinning its OWN literals, which is the same weakness one step removed --
 * two sides can be consistently wrong about a shape neither has seen the other produce.
 *
 * HOW THIS IS DIFFERENT. tests/Contract/lynomia/message_delivery_status.v1.json is not written
 * by hand and not a fixture invented here -- it is the REAL OUTPUT of
 * `Delivery::StatusFeed.payload`, generated in the producer repository and committed
 * byte-identically in both. This test signs it the way Lynomia's `Webhooks::Trigger` signs,
 * posts it to the REAL platform ingress route, and lets the real signature check, the real
 * workspace check, the real delivery-id de-duplication, the real ordering rule and the real
 * projector run.
 *
 * THE SHA GUARD IS WHAT MAKES IT CROSS-REPOSITORY. Both repositories assert the same hash over
 * the same bytes (Lynomia: spec/contracts/message_delivery_status_contract_spec.rb).
 * Regenerating the artifact on one side without copying it to the other breaks a test.
 *
 * WHAT THIS IS NOT, and the distinction matters for the release gate. CONTRACT verification. It
 * proves the two codebases agree about the wire format, the vocabulary, the ordering key and
 * the refusal semantics. It proves NOTHING about DNS, TLS, real credentials, Sidekiq actually
 * delivering, clock skew between two hosts, or a webhook subscription that was never created
 * upstream -- and that last one is not hypothetical here: this feed shipped without its
 * subscription and every test on both sides was green. Blocker A1 stays open.
 */
class LynomiaDeliveryStatusContractTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    /** Asserted identically in the producer repository. Changing the artifact means changing both. */
    private const CONTRACT_SHA256 = '5d7a5d897a68a435b0261fefa243bde5aec19393969f304a2431224f61abd8d5';

    private const ARTIFACT = __DIR__ . '/lynomia/message_delivery_status.v1.json';

    /** The workspace the artifact names. Configured to match, as a real installation would. */
    private const WORKSPACE = 7001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
        config()->set('lynomia.platform_workspace_account_id', self::WORKSPACE);
        config()->set('lynomia.platform_workspace_sender_user_id', 501);
        config()->set('lynomia.platform_workspace_inbox_id', 31);
        config()->set('lynomia.webhook_master_secret', 'master-secret-for-tests');
    }

    public function test_the_artifact_is_the_one_the_producer_repository_holds(): void
    {
        $this->assertSame(
            self::CONTRACT_SHA256,
            hash_file('sha256', self::ARTIFACT),
            'the contract artifact changed; regenerate it in lynomiachat, copy it here, and update '
            . 'CONTRACT_SHA256 in both repositories'
        );
    }

    /** @return array<string,mixed> the producer's own payload for one sample */
    private function sample(string $name): array
    {
        $artifact = json_decode((string) file_get_contents(self::ARTIFACT), true);

        return $artifact['samples'][$name] ?? throw new \RuntimeException("no sample {$name}");
    }

    /** The notice the artifact's message id belongs to, as PlatformNotifier would have written it. */
    private function notice(array $attributes = []): LynomiaPlatformNotice
    {
        return LynomiaPlatformNotice::create(array_merge([
            'store_id' => 11,
            'recipient_phone' => '963900111222',
            'kind' => 'integration_recovery',
            'template_name' => 'integration_broken',
            'template_language' => 'en_US',
            'dedupe_key' => 'contract:' . uniqid('', true),
            'status' => LynomiaPlatformNotice::SENT,
            'lynomia_message_id' => 4242,
            'lynomia_conversation_id' => 77,
            'delivery_outcome' => DeliveryOutcome::ACCEPTED,
            'sent_at' => now()->subHour(),
            'automated' => true,
        ], $attributes));
    }

    /** Signed the way Webhooks::Trigger signs, and posted to the route that is actually served. */
    private function deliver(array $payload, string $deliveryId = 'contract-1'): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $timestamp = (string) time();

        return $this->postSignedPlatform(
            $body,
            'sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}", EventSignature::platformSecret()),
            $timestamp,
            $deliveryId
        );
    }

    private function postSignedPlatform(
        string $body,
        string $signature,
        string $timestamp,
        string $deliveryId
    ): \Illuminate\Testing\TestResponse {
        return $this->call('POST', route('lynomia.platform.events.ingress'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CHATWOOT_SIGNATURE' => $signature,
            'HTTP_X_CHATWOOT_TIMESTAMP' => $timestamp,
            'HTTP_X_CHATWOOT_DELIVERY' => $deliveryId,
        ], $body);
    }

    /**
     * THE SAMPLE THAT IS THE WHOLE POINT. Lynomia's `status` is "sent" and nothing has been
     * sent: that column defaults to `sent` on INSERT, before the provider is called. A
     * consumer that read the enum would record a message nothing had touched as delivered.
     */
    public function test_a_queued_message_is_not_recorded_as_sent(): void
    {
        $notice = $this->notice();
        $queued = $this->sample('queued');

        $this->assertSame('sent', $queued['status'], 'the producer still reports the misleading enum');

        $this->deliver($queued)->assertOk();

        $this->assertSame(DeliveryOutcome::QUEUED, $notice->fresh()->delivery_outcome);
    }

    public function test_the_provider_acceptance_sample_is_accepted_and_projected(): void
    {
        $notice = $this->notice();

        $this->deliver($this->sample('provider_accepted'))->assertOk()->assertJson(['applied' => true]);

        $this->assertSame(DeliveryOutcome::PROVIDER_ACCEPTED, $notice->fresh()->delivery_outcome);
    }

    public function test_the_delivery_sample_is_accepted_and_projected(): void
    {
        $notice = $this->notice();

        $this->deliver($this->sample('delivered'))->assertOk();

        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    /**
     * A POLICY REFUSAL AND A PROVIDER FAILURE ARE THE SAME `failed` IN LYNOMIA'S ENUM AND ARE
     * NOT THE SAME FACT. One will never succeed and the other may; a consumer that merged them
     * would retry a refusal for ever or give up on something transient.
     */
    public function test_a_policy_refusal_and_a_provider_failure_arrive_as_different_outcomes(): void
    {
        $refusedNotice = $this->notice();
        $this->deliver($this->sample('policy_refusal'), 'refusal-1')->assertOk();

        $fresh = $refusedNotice->fresh();
        $this->assertSame(DeliveryOutcome::REFUSED, $fresh->delivery_outcome);
        $this->assertSame('consent_withdrawn', $fresh->delivery_reason);
        $this->assertSame(
            LynomiaPlatformNotice::SENT,
            $fresh->status,
            'the attempt still happened; what Lynomia did with it is a separate column'
        );

        // Same message, newer stamp, so this one is applied rather than dropped as stale.
        $failure = $this->sample('provider_failure');
        $failure['occurred_at_us'] += 1;
        $this->deliver($failure, 'failure-1')->assertOk();

        $after = $refusedNotice->fresh();
        $this->assertSame(DeliveryOutcome::FAILED, $after->delivery_outcome);
        $this->assertSame('131026', $after->delivery_reason, 'a provider code travels as text, never a number');
    }

    /**
     * The field whose absence caused the original consent defect. Removing it from the real
     * payload must be refused, not quietly accepted -- otherwise the check is decorative.
     */
    public function test_a_payload_without_the_workspace_is_refused(): void
    {
        $notice = $this->notice();
        $payload = $this->sample('delivered');
        unset($payload['account']);

        $this->deliver($payload)->assertStatus(404);

        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->fresh()->delivery_outcome);
        $this->assertSame(0, LynomiaPlatformEvent::count());
    }

    public function test_a_payload_naming_another_workspace_is_refused(): void
    {
        $notice = $this->notice();
        $payload = $this->sample('delivered');
        $payload['account']['id'] = self::WORKSPACE + 1;

        $this->deliver($payload)->assertStatus(404);

        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->fresh()->delivery_outcome);
    }

    /** The signature is what authenticates an otherwise unauthenticated route. */
    public function test_a_tampered_body_fails_the_signature_the_producer_computes(): void
    {
        $notice = $this->notice();
        $payload = $this->sample('delivered');
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac(
            'sha256',
            "{$timestamp}." . json_encode($payload),
            EventSignature::platformSecret()
        );

        $payload['outcome'] = 'read';

        $this->postSignedPlatform(json_encode($payload), $signature, $timestamp, 'tampered-1')
            ->assertStatus(401);

        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->fresh()->delivery_outcome);
    }

    public function test_a_duplicate_delivery_of_the_real_payload_changes_nothing(): void
    {
        $notice = $this->notice();

        $this->deliver($this->sample('delivered'), 'same-delivery')->assertOk();
        $this->deliver($this->sample('delivered'), 'same-delivery')->assertOk();

        $this->assertSame(1, LynomiaPlatformEvent::count(), 'the unique delivery id is the arbiter');
        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    /**
     * THE ORDERING PROPERTY, ACROSS THE REPOSITORY BOUNDARY. The producer stamps
     * `occurred_at_us`; this side drops anything not strictly newer. A delayed
     * `provider_accepted` landing after a `delivered` would tell an operator a merchant has
     * not been reached when they have.
     */
    public function test_a_delayed_acceptance_cannot_overwrite_a_delivery_that_already_landed(): void
    {
        $notice = $this->notice();

        $delivered = $this->sample('delivered');
        $delivered['occurred_at_us'] += 5_000;
        $this->deliver($delivered, 'later-1')->assertOk()->assertJson(['applied' => true]);

        $this->deliver($this->sample('provider_accepted'), 'earlier-1')
            ->assertOk()
            ->assertJson(['applied' => false, 'skipped' => LynomiaPlatformEvent::SKIP_STALE]);

        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    /**
     * ORDERED ON TIME, NOT ON A RANKING OF THE STATES. Lynomia allows a failed message to be
     * retried and delivered, and its own specs pin that, so "never leave a terminal state"
     * would discard the true later outcome.
     */
    public function test_a_newer_delivery_may_follow_a_failure(): void
    {
        $notice = $this->notice();

        $this->deliver($this->sample('provider_failure'), 'failed-1')->assertOk();

        $delivered = $this->sample('delivered');
        $delivered['occurred_at_us'] += 9_000;
        $this->deliver($delivered, 'recovered-1')->assertOk();

        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    /** Every outcome word the producer can send has to land somewhere other than a guess. */
    public function test_every_outcome_the_producer_sends_is_one_this_side_understands(): void
    {
        $artifact = json_decode((string) file_get_contents(self::ARTIFACT), true);

        foreach ($artifact['samples'] as $name => $sample) {
            $this->assertNotNull(
                DeliveryOutcome::fromFeed($sample['outcome']),
                "the producer sends '{$sample['outcome']}' for {$name} and this side does not understand it"
            );
        }
    }
}
