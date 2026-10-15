<?php

namespace Tests\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Lynomia\InteractsWithLynomia;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaConsentState;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\EventSignature;

/**
 * THE CONSUMER HALF OF A CROSS-REPOSITORY CONTRACT TEST.
 *
 * WHY THIS EXISTS. Sender-side and receiver-side tests can both pass while disagreeing, and
 * in this integration they already did: the Lynomia consent feed shipped with a payload
 * carrying no `account`, Lynomia's specs asserted the payload it built, this repository's
 * asserted the payload it accepted, both were green, and every real delivery would have been
 * refused as `unknown_workspace`. Two mocks agreeing with their own authors is not a contract.
 *
 * HOW THIS IS DIFFERENT. tests/Contract/lynomia/consent_changed.v1.json is not written by
 * hand and not a fixture invented here -- it is the REAL OUTPUT of Lynomia's producer,
 * generated from `Consents::ChangeFeed` and committed byte-identically in both repositories.
 * This test signs it the way Lynomia's Webhooks::Trigger signs, posts it to the REAL ingress
 * route, and lets the real signature check, the real workspace check, the real
 * delivery-id de-duplication and the real projector run. Nothing about the producer is
 * simulated here; nothing about the consumer is simulated in the producer's spec.
 *
 * THE SHA GUARD IS WHAT MAKES IT CROSS-REPOSITORY. Both repositories assert the same
 * CONTRACT_SHA256 over the same bytes (Lynomia:
 * spec/contracts/consent_changed_contract_spec.rb). Regenerating the artifact on one side
 * without copying it to the other breaks a test. Without that constant the two copies could
 * drift apart silently, which is the exact failure this is aimed at.
 *
 * WHAT THIS IS NOT -- and the distinction matters for the release gate. This is CONTRACT
 * verification. It proves the two codebases agree about the wire format, the identity fields,
 * the signature construction, the ordering key and the refusal semantics. It proves NOTHING
 * about DNS, TLS, real credentials, Sidekiq actually delivering, clock skew between two
 * hosts, or a webhook subscription that was never created upstream. Blocker A1 -- deployed
 * end-to-end verification between two non-production systems -- is about those and stays open.
 */
class LynomiaConsentContractTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    /** Asserted identically in the producer repository. Changing the artifact means changing both. */
    private const CONTRACT_SHA256 = 'e69d400bfcef51e65738b484d4c98221517f4fc1f7eaca0aa3a561c799ec5808';

    private const ARTIFACT = __DIR__ . '/lynomia/consent_changed.v1.json';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
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

    public function test_the_real_producer_payload_is_accepted_and_projected(): void
    {
        [$link, $payload] = $this->contractDelivery('native_withdrawal');

        $this->deliver($link, $payload)->assertOk()->assertJson(['recorded' => true]);

        $state = LynomiaConsentState::forStore($link->store_id)->sole();
        $this->assertSame('withdrawn', $state->state);
        $this->assertSame('promotional', $state->purpose);
        $this->assertSame('any', $state->channel);
        $this->assertSame('cust-77', $state->external_subject_id);
        $this->assertSame('lynomia_native', $state->origin);
        $this->assertTrue($state->blocking(), 'a withdrawal the producer sent must block here');
    }

    public function test_the_partner_grant_sample_is_accepted_in_its_own_scope(): void
    {
        [$link, $payload] = $this->contractDelivery('partner_grant');

        $this->deliver($link, $payload)->assertOk();

        $state = LynomiaConsentState::forStore($link->store_id)->sole();
        $this->assertSame('granted', $state->state);
        $this->assertSame('whatsapp', $state->channel, 'a channel-scoped grant must not widen to every channel');
    }

    /**
     * The field whose absence caused the original defect. Removing it from the real payload
     * must be refused, not quietly accepted -- otherwise the check is decorative.
     */
    public function test_a_payload_without_the_workspace_is_refused(): void
    {
        [$link, $payload] = $this->contractDelivery('native_withdrawal');
        unset($payload['account']);

        $this->deliver($link, $payload)->assertStatus(404);

        $this->assertSame(0, LynomiaConsentState::count());
    }

    public function test_a_payload_naming_another_workspace_is_refused(): void
    {
        [$link, $payload] = $this->contractDelivery('native_withdrawal');
        $payload['account']['id'] = (int) $link->lynomia_account_id + 1;

        $this->deliver($link, $payload)->assertStatus(404);

        $this->assertSame(0, LynomiaConsentState::count());
    }

    /** The signature is what authenticates an unauthenticated route. */
    public function test_a_tampered_body_fails_the_signature_the_producer_computes(): void
    {
        [$link, $payload] = $this->contractDelivery('native_withdrawal');
        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}",
            EventSignature::secretFor((int) $link->store_id));

        // Signed one body, sent another.
        $tampered = json_encode(array_merge($payload, ['state' => 'granted']));

        $this->postSigned($link, $tampered, $signature, $timestamp, 'tampered-1')
            ->assertStatus(401);
        $this->assertSame(0, LynomiaConsentState::count());
    }

    public function test_a_duplicate_delivery_of_the_real_payload_changes_nothing(): void
    {
        [$link, $payload] = $this->contractDelivery('native_withdrawal');

        $this->deliver($link, $payload, 'same-delivery')->assertOk()->assertJson(['recorded' => true]);
        $this->deliver($link, $payload, 'same-delivery')->assertOk()->assertJson(['recorded' => false]);

        $this->assertSame(1, LynomiaConsentState::forStore($link->store_id)->count());
    }

    /**
     * THE ORDERING PROPERTY, across the repository boundary and WITHIN ONE ORIGIN. The
     * producer assigns `revision`; this side drops anything not newer. A delayed grant must
     * not reinstate a withdrawal.
     *
     * The second delivery carries the SAME origin as the first, deliberately. Ordering is
     * only meaningful inside one sequence -- see the next test for what happens across two.
     */
    public function test_a_delayed_grant_with_an_older_revision_cannot_reinstate_a_withdrawal(): void
    {
        [$link, $withdrawal] = $this->contractDelivery('native_withdrawal');
        $this->deliver($link, $withdrawal)->assertOk();

        // The same installation, a second real sample. Provisioning again would be refused --
        // one workspace per store -- so the link is reused and only the sample is reloaded.
        $grant = $this->sampleFor($link, 'partner_grant');
        $grant['channel'] = 'any';
        $grant['origin'] = $withdrawal['origin'];
        $grant['revision'] = (int) $withdrawal['revision'] - 1;

        $this->deliver($link, $grant)->assertOk();

        $this->assertSame('withdrawn', LynomiaConsentState::forStore($link->store_id)->sole()->state);
    }

    /**
     * THE UNION PROPERTY, across the repository boundary and ACROSS ORIGINS.
     *
     * Lynomia publishes from two consent stores whose `revision` values count in unrelated
     * sequences -- a commerce_messaging_consents id and a partner_consent_events id. Before
     * the origin was part of this side's scope they shared a row, the partner grant's much
     * larger revision compared as "newer", and a native withdrawal was overwritten by a
     * grant. The two now keep separate rows and the effective answer unions the negatives,
     * which is the rule Lynomia applies at its own send boundary.
     *
     * Both samples here are the producer's real output, delivered in the order that used to
     * break: the withdrawal first, the higher-revision grant second.
     */
    public function test_a_partner_grant_cannot_mask_a_native_withdrawal(): void
    {
        [$link, $withdrawal] = $this->contractDelivery('native_withdrawal');
        $this->deliver($link, $withdrawal)->assertOk();

        $grant = $this->sampleFor($link, 'partner_grant');
        $grant['channel'] = 'any';
        $this->assertGreaterThan((int) $withdrawal['revision'], (int) $grant['revision'],
            'the grant must carry the higher revision, or this test is not reproducing the defect');

        $this->deliver($link, $grant)->assertOk();

        $rows = LynomiaConsentState::forStore($link->store_id)->get();
        $this->assertCount(2, $rows, 'each origin keeps its own row; one shared row is the defect');
        $this->assertSame('withdrawn', LynomiaConsentState::effectiveFor($rows)['state']);
        $this->assertSame('lynomia_native', LynomiaConsentState::effectiveFor($rows)['origin']);
    }

    // ============================================================ helpers

    /**
     * One sample from the artifact, with only the two deployment-specific ids rewritten to
     * this installation's. Every other value is the producer's verbatim output -- rewriting
     * more would be testing a payload nobody sends.
     *
     * @return array{0: LynomiaStoreLink, 1: array}
     */
    private function contractDelivery(string $sample): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(AccountProvisioner::class)->provision($store, $owner)->fresh();

        return [$link, $this->sampleFor($link, $sample)];
    }

    /**
     * One sample, with only the two deployment-specific ids rewritten to this installation's.
     * Every other value is the producer's verbatim output -- rewriting more would be testing
     * a payload nobody sends.
     */
    private function sampleFor(LynomiaStoreLink $link, string $sample): array
    {
        $artifact = json_decode(file_get_contents(self::ARTIFACT), true);
        $payload = $artifact['samples'][$sample];
        $payload['account']['id'] = (int) $link->lynomia_account_id;
        $payload['store']['external_store_id'] = (string) $link->store_id;

        return $payload;
    }

    /** Posted exactly the way Lynomia's Webhooks::Trigger posts it. */
    private function deliver(LynomiaStoreLink $link, array $payload, ?string $deliveryId = null)
    {
        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}",
            EventSignature::secretFor((int) $link->store_id));

        return $this->postSigned($link, $body, $signature, $timestamp,
            $deliveryId ?? 'delivery-' . uniqid());
    }

    private function postSigned(LynomiaStoreLink $link, string $body, string $signature, string $timestamp, string $deliveryId)
    {
        $server = [];

        foreach ([
            EventSignature::SIGNATURE_HEADER => $signature,
            EventSignature::TIMESTAMP_HEADER => $timestamp,
            EventSignature::DELIVERY_HEADER => $deliveryId,
            'Content-Type' => 'application/json',
        ] as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $this->call('POST', "/lynomia/events/{$link->store_id}", [], [], [], $server, $body);
    }
}
