<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformEvent;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice;
use Workdo\LynomiaChat\app\Services\EventSignature;
use Workdo\LynomiaChat\app\Services\PlatformDeliveryReconciler;
use Workdo\LynomiaChat\app\Support\DeliveryOutcome;

/**
 * WHAT BECAME OF A NOTICE, AND WHY A 200 WAS NEVER AN ANSWER.
 *
 * `POST /api/v1/accounts/:id/conversations/:id/messages` answers 200 the moment the message
 * ROW exists on the far side. The provider call, the platform workspace's UTILITY gate, the
 * consent gate and the allowance meter all run AFTERWARDS. So "Lynomia said 200" has never
 * meant a merchant received anything, and a notice Lynomia went on to refuse was recorded as
 * handed over with nothing ever reporting otherwise.
 *
 * These examples pin the four properties that make the delivery feed trustworthy rather than
 * merely present: it is authenticated, it is idempotent, a late event cannot overwrite a newer
 * one, and what it never says can still be found out by asking.
 */
class LynomiaDeliveryOutcomeTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const WORKSPACE = 9001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
        config()->set('lynomia.platform_workspace_account_id', self::WORKSPACE);
        config()->set('lynomia.platform_workspace_sender_user_id', 501);
        config()->set('lynomia.platform_workspace_inbox_id', 31);
        config()->set('lynomia.webhook_master_secret', 'master-secret-for-tests');
    }

    private function notice(array $attributes = []): LynomiaPlatformNotice
    {
        return LynomiaPlatformNotice::create(array_merge([
            'store_id' => 11,
            'recipient_user_id' => null,
            'recipient_phone' => '963900111222',
            'kind' => 'integration_recovery',
            'template_name' => 'integration_broken',
            'template_language' => 'en_US',
            'dedupe_key' => 'occasion:' . uniqid('', true),
            'status' => LynomiaPlatformNotice::SENT,
            'lynomia_message_id' => 4242,
            'lynomia_conversation_id' => 77,
            'delivery_outcome' => DeliveryOutcome::ACCEPTED,
            'sent_at' => now()->subHour(),
            'automated' => true,
        ], $attributes));
    }

    /** @param array<string,mixed> $payload */
    private function deliver(array $payload, string $deliveryId = 'delivery-4242-1', array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $timestamp = (string) ($overrides['timestamp'] ?? time());
        $secret = $overrides['secret'] ?? EventSignature::platformSecret();
        $signature = $overrides['signature']
            ?? 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call(
            'POST',
            route('lynomia.platform.events.ingress'),
            [],
            [],
            [],
            array_filter([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CHATWOOT_SIGNATURE' => $signature,
                'HTTP_X_CHATWOOT_TIMESTAMP' => $timestamp,
                'HTTP_X_CHATWOOT_DELIVERY' => $deliveryId === '' ? null : $deliveryId,
            ]),
            $body
        );
    }

    /** @param array<string,mixed> $extra */
    private function event(string $outcome, int $occurredAt, array $extra = []): array
    {
        return array_merge([
            'event' => 'message_delivery_status',
            'account' => ['id' => self::WORKSPACE],
            'message' => ['id' => 4242, 'source_id' => 'wamid.HBg', 'conversation_id' => 77],
            'status' => 'delivered',
            'outcome' => $outcome,
            'reason_code' => null,
            'occurred_at' => now()->toIso8601String(),
            'occurred_at_us' => $occurredAt,
        ], $extra);
    }

    // ===================================================== the wire contract

    /**
     * THE LITERALS THAT CROSS THE GAP, WRITTEN DOWN ON THIS SIDE.
     *
     * There is no shared code between the two systems, so a rename in Lynomia's
     * `Delivery::StatusFeed` is silent here: the ingress would simply stop recognising
     * anything and every notice would fall through to the fifteen-minute sweep, which looks
     * like a slow feed rather than a broken one. Lynomia carries the mirror of this
     * (spec/services/delivery/status_feed_spec.rb), so a rename on either side fails against
     * its own recorded contract even though neither can see the other.
     *
     * WHAT THIS DOES NOT PROVE, and the reason blocker A1 stays open: both suites run against
     * a fake of the other system. That is agreement between two expressions of one reading of
     * the contract, not evidence the reading is right. Only two real deployments exchanging a
     * delivery can show that.
     */
    public function test_the_wire_vocabulary_is_the_one_lynomia_publishes(): void
    {
        $this->assertSame(
            'message_delivery_status',
            \Workdo\LynomiaChat\app\Services\PlatformWorkspaceGateway::DELIVERY_EVENT
        );

        // Lynomia's Delivery::StatusFeed publishes exactly these six words, and every one of
        // them has to land somewhere other than `unknown`.
        $this->assertSame(
            ['queued', 'provider_accepted', 'delivered', 'read', 'failed', 'refused'],
            array_keys(DeliveryOutcome::FROM_FEED)
        );

        foreach (array_keys(DeliveryOutcome::FROM_FEED) as $word) {
            $this->assertNotNull(DeliveryOutcome::fromFeed($word), "{$word} is published and not understood");
        }
    }

    /** The fields the projector reads, read off a payload shaped exactly as Lynomia sends it. */
    public function test_every_field_the_projector_needs_is_one_lynomia_sends(): void
    {
        $notice = $this->notice();

        // Written out rather than built by the helper: a helper that drifted with the
        // implementation would pin nothing.
        $this->deliver([
            'event' => 'message_delivery_status',
            'account' => ['id' => self::WORKSPACE],
            'message' => ['id' => 4242, 'source_id' => 'wamid.HBg', 'conversation_id' => 77],
            'status' => 'delivered',
            'outcome' => 'delivered',
            'reason_code' => null,
            'occurred_at' => '2026-10-16T09:30:00.123456Z',
            'occurred_at_us' => 1_792_200_600_123_456,
        ], 'delivery-4242-1792200600123456')->assertOk();

        $fresh = $notice->fresh();
        $this->assertSame(DeliveryOutcome::DELIVERED, $fresh->delivery_outcome);
        $this->assertSame(1_792_200_600_123_456, (int) $fresh->outcome_at_us);
        $this->assertSame('2026-10-16 09:30:00', $fresh->delivery_outcome_at->utc()->format('Y-m-d H:i:s'));

        $event = LynomiaPlatformEvent::firstOrFail();
        $this->assertSame('message_delivery_status', $event->event);
        $this->assertSame(self::WORKSPACE, (int) $event->lynomia_account_id);
        $this->assertSame($notice->id, $event->notice_id);
    }

    // ===================================================== authenticated

    public function test_a_delivery_is_applied_when_it_is_signed_with_the_platform_secret(): void
    {
        $notice = $this->notice();

        $this->deliver($this->event('delivered', 2_000))->assertOk();

        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $notice = $this->notice();

        $this->deliver($this->event('delivered', 2_000), 'd-1', ['signature' => 'sha256=nonsense'])
            ->assertStatus(401);

        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->fresh()->delivery_outcome);
        $this->assertSame(0, LynomiaPlatformEvent::count());
    }

    /**
     * A MERCHANT'S OWN SECRET MUST NOT OPEN THIS DOOR. The store feed derives its key from the
     * store id; if the platform feed shared that derivation, any merchant who learned their
     * own secret could forge the platform's delivery reports.
     */
    public function test_a_store_secret_cannot_sign_a_platform_delivery(): void
    {
        $notice = $this->notice();

        $this->deliver($this->event('delivered', 2_000), 'd-1', ['secret' => EventSignature::secretFor(11)])
            ->assertStatus(401);

        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->fresh()->delivery_outcome);
    }

    public function test_a_stale_timestamp_is_refused(): void
    {
        $this->notice();

        $this->deliver($this->event('delivered', 2_000), 'd-1', ['timestamp' => (string) (time() - 4000)])
            ->assertStatus(401);
    }

    /** A signature proves who sent a delivery; the account proves what it may talk about. */
    public function test_a_delivery_about_another_account_is_refused(): void
    {
        $notice = $this->notice();

        $this->deliver($this->event('delivered', 2_000, ['account' => ['id' => 777]]))->assertStatus(404);

        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->fresh()->delivery_outcome);
    }

    public function test_a_delivery_without_an_id_is_refused_because_there_is_no_idempotency_without_one(): void
    {
        $this->notice();

        $this->deliver($this->event('delivered', 2_000), '')->assertStatus(400);
    }

    // ===================================================== idempotent

    public function test_the_same_delivery_twice_changes_nothing_the_second_time(): void
    {
        $notice = $this->notice();

        $this->deliver($this->event('delivered', 2_000), 'delivery-4242-2000')->assertOk();
        $this->deliver($this->event('delivered', 2_000), 'delivery-4242-2000')->assertOk();

        $this->assertSame(1, LynomiaPlatformEvent::count(), 'the unique delivery id is the arbiter');
        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    // ===================================================== ordered

    /**
     * THE PROPERTY THE OWNER ASKED FOR BY NAME. Delivery events arrive out of order whenever a
     * retry, a queue backlog or a provider re-send puts two in flight at once. A late
     * `provider_accepted` landing on top of a `delivered` would tell an operator a merchant
     * has not been reached when they have.
     */
    public function test_a_late_event_cannot_overwrite_a_newer_one(): void
    {
        $notice = $this->notice();

        $this->deliver($this->event('delivered', 5_000), 'delivery-4242-5000')->assertOk();
        $this->deliver($this->event('provider_accepted', 1_000), 'delivery-4242-1000')->assertOk();

        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
        $this->assertSame(5_000, (int) $notice->fresh()->outcome_at_us);
    }

    /** Nothing is dropped silently: a feed that discards without a record cannot be debugged. */
    public function test_a_late_event_is_recorded_with_the_reason_it_was_not_applied(): void
    {
        $this->notice();
        $this->deliver($this->event('delivered', 5_000), 'delivery-4242-5000');

        $this->deliver($this->event('provider_accepted', 1_000), 'delivery-4242-1000');

        $late = LynomiaPlatformEvent::where('delivery_id', 'delivery-4242-1000')->firstOrFail();
        $this->assertFalse($late->applied);
        $this->assertSame(LynomiaPlatformEvent::SKIP_STALE, $late->skip_reason);
    }

    /**
     * ORDERED ON TIME, NOT ON A RANKING OF THE STATES. Lynomia allows a failed message to be
     * retried and delivered, and its own specs pin that. A rule like "never leave a terminal
     * state" would discard the true later outcome.
     */
    public function test_a_newer_event_may_move_a_notice_out_of_a_terminal_state(): void
    {
        $notice = $this->notice();

        $this->deliver($this->event('failed', 1_000), 'delivery-4242-1000')->assertOk();
        $this->deliver($this->event('delivered', 9_000), 'delivery-4242-9000')->assertOk();

        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    public function test_an_event_about_no_notice_we_hold_is_recorded_rather_than_dropped(): void
    {
        $this->deliver($this->event('delivered', 2_000, ['message' => ['id' => 999999]]))->assertOk();

        $event = LynomiaPlatformEvent::firstOrFail();
        $this->assertFalse($event->applied);
        $this->assertSame(LynomiaPlatformEvent::SKIP_NO_NOTICE, $event->skip_reason);
    }

    public function test_an_outcome_this_side_does_not_know_is_never_guessed_at(): void
    {
        $notice = $this->notice();

        $this->deliver($this->event('teleported', 2_000))->assertOk();

        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->fresh()->delivery_outcome);
        $this->assertSame(
            LynomiaPlatformEvent::SKIP_UNKNOWN_OUTCOME,
            LynomiaPlatformEvent::firstOrFail()->skip_reason
        );
    }

    // ===================================================== what the states mean

    public function test_a_refusal_is_recorded_with_the_reason_lynomia_gave(): void
    {
        $notice = $this->notice();

        $this->deliver($this->event('refused', 2_000, ['reason_code' => 'consent_withdrawn']))->assertOk();

        $fresh = $notice->fresh();
        $this->assertSame(DeliveryOutcome::REFUSED, $fresh->delivery_outcome);
        $this->assertSame('consent_withdrawn', $fresh->delivery_reason);
        $this->assertSame(
            LynomiaPlatformNotice::SENT,
            $fresh->status,
            'the attempt still happened; what Lynomia did with it is a separate column'
        );
    }

    /** A transport failure is the one case where nobody knows. `unknown`, never `failed`. */
    public function test_a_lost_answer_is_recorded_as_unknown_rather_than_failed(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['mobile' => '963900111222', 'mobile_verified_at' => now()])->save();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)
            ->provision($store, $owner->fresh())->fresh();
        $this->lynomiaScript['fail'] = [
            'POST /api/v1/accounts/9001/conversations/77/messages' => Http::response(['error' => 'gateway'], 503),
        ];

        $notice = app(\Workdo\LynomiaChat\app\Services\PlatformNotifier::class)
            ->notify($link, 'integration_recovery', 'integration_broken', 'lost:1', [], $this->operator());

        $this->assertSame(LynomiaPlatformNotice::INDETERMINATE, $notice->status);
        $this->assertSame(DeliveryOutcome::UNKNOWN, $notice->delivery_outcome);
    }

    /**
     * THE MOST COMMON REAL CASE, and before this it had no outcome at all. B4 means most
     * merchants start with a number nobody has confirmed, so "we would not send this" is the
     * first thing that happens to most notices -- and a null outcome is what the console
     * reads as "we do not know yet", which is the opposite of the truth.
     */
    public function test_a_notice_this_side_refuses_on_policy_is_recorded_as_refused(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['mobile' => null, 'mobile_verified_at' => null])->save();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)
            ->provision($store, $owner->fresh())->fresh();

        $notice = app(\Workdo\LynomiaChat\app\Services\PlatformNotifier::class)
            ->notify($link, 'integration_recovery', 'integration_broken', 'refused:1', [], $this->operator());

        $this->assertSame(LynomiaPlatformNotice::FAILED, $notice->status);
        $this->assertSame(DeliveryOutcome::REFUSED, $notice->delivery_outcome);
        $this->assertSame('no_phone_on_record', $notice->delivery_reason);
    }

    /** A row being tried again must not still be showing the previous attempt's answer. */
    public function test_reopening_a_refused_notice_clears_the_outcome_it_carried(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['mobile' => null, 'mobile_verified_at' => null])->save();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)
            ->provision($store, $owner->fresh())->fresh();
        $notifier = app(\Workdo\LynomiaChat\app\Services\PlatformNotifier::class);

        $notifier->notify($link, 'integration_recovery', 'integration_broken', 'reopen:1', [], $this->operator());

        // The number is fixed and confirmed, and the same occasion is attempted again.
        $owner->forceFill(['mobile' => '963900111222', 'mobile_verified_at' => now()])->save();
        $notice = $notifier->notify($link, 'integration_recovery', 'integration_broken', 'reopen:1', [], $this->operator());

        $this->assertSame(LynomiaPlatformNotice::SENT, $notice->status);
        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->delivery_outcome);
        $this->assertNull($notice->delivery_reason);
    }

    // ===================================================== reconciliation

    /**
     * A FEED CAN BE LOST IN EVERY WAY A REQUEST CAN BE LOST, and a notice left at `accepted`
     * for ever is exactly the state this phase set out to abolish. So what the feed never said
     * is asked for.
     */
    public function test_reconciliation_resolves_a_notice_whose_events_never_arrived(): void
    {
        $notice = $this->notice();
        $this->fakeLynomia([
            // The shape api/v1/models/_message.json.jbuilder actually emits: created_at as an
            // epoch integer, and no updated_at at all. A fixture that invented one would have
            // tested a payload this endpoint has never sent.
            'messages' => [[
                'id' => 4242, 'status' => 'delivered', 'source_id' => 'wamid.HBg',
                'created_at' => now()->subMinutes(30)->timestamp,
            ]],
        ]);

        $result = app(PlatformDeliveryReconciler::class)->run();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['resolved']);
        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    /** It writes through the same projector, so it leaves the same audit row. */
    public function test_reconciliation_is_recorded_in_the_same_trail_as_the_feed(): void
    {
        $this->notice();
        $this->fakeLynomia([
            // The shape api/v1/models/_message.json.jbuilder actually emits: created_at as an
            // epoch integer, and no updated_at at all. A fixture that invented one would have
            // tested a payload this endpoint has never sent.
            'messages' => [[
                'id' => 4242, 'status' => 'delivered', 'source_id' => 'wamid.HBg',
                'created_at' => now()->subMinutes(30)->timestamp,
            ]],
        ]);

        app(PlatformDeliveryReconciler::class)->run();

        $event = LynomiaPlatformEvent::firstOrFail();
        $this->assertTrue($event->applied);
        $this->assertStringStartsWith('reconcile-', $event->delivery_id);
    }

    /** A notice the feed already settled is finished and must not be asked about again. */
    public function test_reconciliation_leaves_a_settled_notice_alone(): void
    {
        $this->notice(['delivery_outcome' => DeliveryOutcome::DELIVERED, 'outcome_at_us' => 9_000]);
        $this->fakeLynomia();

        $result = app(PlatformDeliveryReconciler::class)->run();

        $this->assertSame(0, $result['checked']);
    }

    /** Nor one still inside the window where the ordinary feed has every chance. */
    public function test_reconciliation_waits_before_racing_the_feed(): void
    {
        $this->notice(['sent_at' => now()->subMinute()]);
        $this->fakeLynomia();

        $this->assertSame(0, app(PlatformDeliveryReconciler::class)->run()['checked']);
    }

    /** Asking can fail. The notice keeps the honest state it had and is asked about again. */
    public function test_a_failed_reconciliation_does_not_worsen_what_is_known(): void
    {
        $notice = $this->notice();
        $this->fakeLynomia();
        $this->lynomiaScript['fail'] = [
            'GET /api/v1/accounts/9001/conversations/77/messages' => Http::response(['error' => 'down'], 503),
        ];

        $result = app(PlatformDeliveryReconciler::class)->run();

        $this->assertSame(1, $result['unreachable']);
        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->fresh()->delivery_outcome);
    }

    // ===================================================== and something runs it

    public function test_the_command_resolves_what_the_feed_never_reported(): void
    {
        $notice = $this->notice();
        $this->fakeLynomia([
            // The shape api/v1/models/_message.json.jbuilder actually emits: created_at as an
            // epoch integer, and no updated_at at all. A fixture that invented one would have
            // tested a payload this endpoint has never sent.
            'messages' => [[
                'id' => 4242, 'status' => 'delivered', 'source_id' => 'wamid.HBg',
                'created_at' => now()->subMinutes(30)->timestamp,
            ]],
        ]);

        $this->artisan('lynomia:reconcile-deliveries')
            ->expectsOutputToContain('resolved 1')
            ->assertExitCode(0);

        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    public function test_the_command_does_nothing_while_the_integration_is_switched_off(): void
    {
        $notice = $this->notice();
        $this->fakeLynomia();
        config()->set('lynomia.enabled', false);

        $this->artisan('lynomia:reconcile-deliveries')->assertExitCode(0);

        $this->assertSame(DeliveryOutcome::ACCEPTED, $notice->fresh()->delivery_outcome);
    }

    /**
     * An installation that never provisioned a workspace has sent no notices, so there is
     * nothing outstanding -- and a sweep that reported a failure every quarter of an hour
     * would train an operator to ignore it.
     */
    public function test_the_command_is_quiet_on_an_installation_with_no_platform_workspace(): void
    {
        config()->set('lynomia.platform_workspace_account_id', null);

        $this->artisan('lynomia:reconcile-deliveries')
            ->expectsOutputToContain('not set up')
            ->assertExitCode(0);
    }

    // ===================================================== and Lynomia was told where to post

    /**
     * THE DEFECT TWENTY-FIVE PASSING TESTS DID NOT CATCH. Lynomia posts an account webhook
     * only to an endpoint somebody registered WITH THE EVENT in its subscription list, and
     * EventSubscriptionManager registers a merchant's workspace -- it takes a store link, and
     * the platform workspace has none. Every example above posts to the ingress directly, so
     * the ingress, the projector and the ordering rule were all green while nothing had ever
     * told Lynomia the endpoint existed.
     */
    public function test_the_sweep_registers_the_delivery_feed_on_the_platform_workspace(): void
    {
        $this->fakeLynomia();

        $this->artisan('lynomia:reconcile-deliveries')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/v1/accounts/9001/webhooks')
                && ($request['webhook']['subscriptions'] ?? []) === ['message_delivery_status']
                && $request['webhook']['url'] === route('lynomia.platform.events.ingress')
                // The receiver chooses the signing key, so there is no copy of a live secret
                // in a database whose APP_KEY is published.
                && $request['webhook']['secret'] === EventSignature::platformSecret();
        });
    }

    /** Registered already, and nothing is created a second time. */
    public function test_an_existing_subscription_is_left_alone(): void
    {
        $this->fakeLynomia(['webhooks' => [[
            'id' => 5,
            'url' => route('lynomia.platform.events.ingress'),
            'subscriptions' => ['message_delivery_status'],
        ]]]);

        $this->artisan('lynomia:reconcile-deliveries')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/webhooks'));
    }

    /**
     * A workspace registered before the feed existed is WIDENED, never replaced: whatever
     * else it points at this endpoint for is not this module's to remove.
     */
    public function test_a_subscription_from_before_the_feed_is_widened_rather_than_replaced(): void
    {
        $this->fakeLynomia(['webhooks' => [[
            'id' => 5,
            'url' => route('lynomia.platform.events.ingress'),
            'subscriptions' => ['message_created'],
        ]]]);

        $this->artisan('lynomia:reconcile-deliveries')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/webhooks/5')
                && $request['webhook']['subscriptions'] === ['message_created', 'message_delivery_status'];
        });
    }

    /** Reconciliation is what covers a feed that is not arriving, so it must still run. */
    public function test_the_sweep_still_runs_when_the_subscription_cannot_be_repaired(): void
    {
        $notice = $this->notice();
        $this->fakeLynomia([
            'messages' => [[
                'id' => 4242, 'status' => 'delivered', 'source_id' => 'wamid.HBg',
                'created_at' => now()->subMinutes(30)->timestamp,
            ]],
        ]);
        $this->lynomiaScript['fail'] = [
            'GET /api/v1/accounts/9001/webhooks' => Http::response(['error' => 'down'], 503),
        ];

        $this->artisan('lynomia:reconcile-deliveries')->assertExitCode(0);

        $this->assertSame(DeliveryOutcome::DELIVERED, $notice->fresh()->delivery_outcome);
    }

    /**
     * THE TRIPWIRE FOR A RECONCILER NOBODY RUNS. The reconciler was written, tested and
     * complete while nothing in production called it -- which is a safety net rolled up in
     * the cupboard. Measured on the BOOTED scheduler rather than read off the provider, for
     * the same reason the route stacks are measured on the booted router.
     */
    public function test_the_sweep_is_actually_scheduled(): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command);

        $this->assertTrue(
            $commands->contains(fn ($command) => str_contains($command, 'lynomia:reconcile-deliveries')),
            'the delivery reconciliation sweep is registered but never runs'
        );
    }
}
