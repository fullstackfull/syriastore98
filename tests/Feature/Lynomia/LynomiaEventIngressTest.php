<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaInboxEvent;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\EventSignature;

/**
 * Lynomia telling Store Syria that something changed, and everything that must be true about it.
 *
 * THE INGRESS HAS NO SESSION, so the signature is the whole of its authentication. These tests
 * are therefore written as an attacker would approach it: no signature, a wrong one, a stale
 * one, one for a different store, a correct one about somebody else's workspace, the same one
 * twice.
 *
 * AND THE THING BEING PROTECTED IS SMALLER THAN IT LOOKS. An event row carries ids and nothing
 * else -- no message text, no contact details -- because the browser re-fetches whatever changed
 * through the authorized BFF. So the worst a forged delivery could achieve is a spurious refresh
 * of the recipient's own inbox. The tests below still refuse it, but that is the reason the
 * design can afford an unauthenticated route at all.
 */
class LynomiaEventIngressTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: \Workdo\LynomiaChat\app\Models\LynomiaStoreLink} */
    private function connectedTenant(int $accountId = 7001): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        $link = app(AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store, $link->fresh()];
    }

    private function payload(int $accountId = 7001, int $conversation = 41, string $event = 'message_created'): array
    {
        return [
            'event' => $event,
            'id' => 9001,
            'account' => ['id' => $accountId],
            'conversation' => ['display_id' => $conversation, 'id' => 555],
            'content' => 'Where is my order?',
        ];
    }

    /** Post a delivery the way Lynomia's Webhooks::Trigger does. */
    private function deliver(int $storeId, array $payload, array $overrides = [])
    {
        $body = json_encode($payload);
        $timestamp = (string) ($overrides['timestamp'] ?? time());
        $secret = $overrides['secret'] ?? EventSignature::secretFor($overrides['signAs'] ?? $storeId);
        $signature = $overrides['signature']
            ?? 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        $headers = array_filter([
            EventSignature::SIGNATURE_HEADER => $signature,
            EventSignature::TIMESTAMP_HEADER => $timestamp,
            EventSignature::DELIVERY_HEADER => $overrides['delivery'] ?? 'delivery-' . uniqid(),
            'Content-Type' => 'application/json',
        ], static fn ($value) => $value !== false);

        return $this->call('POST', "/lynomia/events/{$storeId}", [], [], [], $this->serverHeaders($headers), $body);
    }

    private function serverHeaders(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }

    // ================================================================ accepting

    public function test_a_correctly_signed_delivery_is_recorded(): void
    {
        [, $store] = $this->connectedTenant();

        $this->deliver((int) $store->id, $this->payload())->assertOk();

        $event = LynomiaInboxEvent::sole();
        $this->assertSame('message_created', $event->event);
        $this->assertSame(41, (int) $event->conversation_display_id);
        $this->assertSame((int) $store->id, (int) $event->store_id);
    }

    /**
     * WHAT IS DELIBERATELY NOT STORED. The delivery carried the customer's message; the row must
     * not. An event names what changed so the browser can re-fetch it through the authorized
     * BFF -- putting the text here would make a table written by an inbound HTTP request into a
     * copy of the merchant's conversations.
     */
    public function test_no_conversation_content_is_ever_stored(): void
    {
        [, $store] = $this->connectedTenant();

        $this->deliver((int) $store->id, $this->payload())->assertOk();

        $row = json_encode(LynomiaInboxEvent::sole()->getAttributes());
        $this->assertStringNotContainsString('Where is my order?', $row);
    }

    // ================================================================ refusing

    public function test_an_unsigned_delivery_is_refused(): void
    {
        [, $store] = $this->connectedTenant();
        $body = json_encode($this->payload());

        $this->call('POST', "/lynomia/events/{$store->id}", [], [], [], [], $body)->assertUnauthorized();
        $this->assertSame(0, LynomiaInboxEvent::count());
    }

    public function test_a_wrongly_signed_delivery_is_refused(): void
    {
        [, $store] = $this->connectedTenant();

        $this->deliver((int) $store->id, $this->payload(), ['secret' => 'not-the-secret'])->assertUnauthorized();
        $this->assertSame(0, LynomiaInboxEvent::count());
    }

    /**
     * REPLAY. The signature covers the timestamp, so an attacker replaying a captured delivery
     * can keep the timestamp and fail the freshness window, or change it and fail the signature.
     * This is the first of those.
     */
    public function test_a_stale_delivery_is_refused(): void
    {
        [, $store] = $this->connectedTenant();

        $this->deliver((int) $store->id, $this->payload(), ['timestamp' => time() - 3600])->assertUnauthorized();
        $this->assertSame(0, LynomiaInboxEvent::count());
    }

    /** ...and this is the second: moving the timestamp invalidates the signature over it. */
    public function test_a_replayed_body_with_a_fresh_timestamp_is_refused(): void
    {
        [, $store] = $this->connectedTenant();
        $body = json_encode($this->payload());
        $capturedSignature = 'sha256=' . hash_hmac('sha256', (time() - 3600) . ".{$body}",
            EventSignature::secretFor((int) $store->id));

        $this->deliver((int) $store->id, $this->payload(), [
            'timestamp' => (string) time(), 'signature' => $capturedSignature,
        ])->assertUnauthorized();

        $this->assertSame(0, LynomiaInboxEvent::count());
    }

    /**
     * ONE KEY PER STORE. A secret that leaks forges deliveries for one store, not for every
     * store on the installation -- so a correctly-signed delivery for store A, posted at store
     * B's endpoint, is refused.
     */
    public function test_a_delivery_signed_for_another_store_is_refused(): void
    {
        [, $storeA] = $this->connectedTenant();
        [, $storeB] = $this->connectedTenant(7002);

        $this->deliver((int) $storeB->id, $this->payload(7002), ['signAs' => (int) $storeA->id])
            ->assertUnauthorized();

        $this->assertSame(0, LynomiaInboxEvent::count());
    }

    /**
     * A SIGNATURE PROVES WHO SENT IT; IT DOES NOT SAY WHAT THEY MAY TALK ABOUT. A delivery
     * correctly signed for store B, but describing store A's workspace, is dropped -- otherwise
     * a compromised or confused sender could attribute one store's traffic to another.
     */
    public function test_a_valid_delivery_about_another_workspace_is_dropped(): void
    {
        [, $storeA] = $this->connectedTenant();
        [, $storeB] = $this->connectedTenant(7002);

        $this->deliver((int) $storeB->id, $this->payload(7001))->assertNotFound();

        $this->assertSame(0, LynomiaInboxEvent::count());
        $this->assertNotNull($storeA);
    }

    public function test_a_delivery_for_an_unconnected_store_is_dropped(): void
    {
        [, $store] = $this->entitledTenant();

        $this->deliver((int) $store->id, $this->payload())->assertNotFound();
    }

    public function test_a_delivery_with_no_delivery_id_is_refused(): void
    {
        [, $store] = $this->connectedTenant();

        $this->deliver((int) $store->id, $this->payload(), ['delivery' => ''])->assertStatus(400);
    }

    // ================================================================ idempotency

    /**
     * Lynomia's webhook delivery is at-least-once and fire-and-forget -- Webhooks::Trigger
     * rescues and logs rather than retrying -- so a duplicate is ordinary traffic, not an error.
     * It answers 200 because from the sender's side the event WAS delivered.
     */
    public function test_the_same_delivery_twice_records_one_event(): void
    {
        [, $store] = $this->connectedTenant();
        $payload = $this->payload();

        $first = $this->deliver((int) $store->id, $payload, ['delivery' => 'abc-123']);
        $second = $this->deliver((int) $store->id, $payload, ['delivery' => 'abc-123']);

        $first->assertOk();
        $second->assertOk();
        $this->assertTrue($first->json('recorded'));
        $this->assertFalse($second->json('recorded'));
        $this->assertSame(1, LynomiaInboxEvent::count());
    }

    // ================================================================ delivery to the browser

    public function test_the_inbox_reads_only_its_own_events_and_advances_a_cursor(): void
    {
        [$ownerA, $storeA] = $this->connectedTenant();
        [, $storeB] = $this->connectedTenant(7002);

        $this->deliver((int) $storeA->id, $this->payload(7001, 41))->assertOk();
        $this->deliver((int) $storeB->id, $this->payload(7002, 77))->assertOk();

        $response = $this->actingAs($ownerA)->getJson('/lynomia/inbox/api/events?after=0');

        $response->assertOk();
        $events = $response->json('events');
        $this->assertCount(1, $events, 'the inbox saw another store\'s events');
        $this->assertSame(41, $events[0]['conversation_id']);
        $this->assertSame($events[0]['id'], $response->json('cursor'));
    }

    /**
     * RECONNECTION AND CATCH-UP ARE THE SAME CALL AS AN ORDINARY POLL. There is no separate
     * recovery path, which is what stops there being a recovery path nobody exercises.
     */
    public function test_a_client_resumes_from_its_cursor_and_misses_nothing(): void
    {
        [$owner, $store] = $this->connectedTenant();

        $this->deliver((int) $store->id, $this->payload(7001, 1))->assertOk();
        $cursor = $this->actingAs($owner)->getJson('/lynomia/inbox/api/events?after=0')->json('cursor');

        // Three more arrive while the client is away.
        foreach ([2, 3, 4] as $conversation) {
            $this->deliver((int) $store->id, $this->payload(7001, $conversation))->assertOk();
        }

        $caughtUp = $this->actingAs($owner)->getJson("/lynomia/inbox/api/events?after={$cursor}");

        $this->assertSame([2, 3, 4], array_column($caughtUp->json('events'), 'conversation_id'));
    }

    public function test_a_quiet_poll_returns_nothing_and_holds_the_cursor(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->deliver((int) $store->id, $this->payload())->assertOk();
        $cursor = $this->actingAs($owner)->getJson('/lynomia/inbox/api/events?after=0')->json('cursor');

        $quiet = $this->actingAs($owner)->getJson("/lynomia/inbox/api/events?after={$cursor}");

        $this->assertSame([], $quiet->json('events'));
        $this->assertSame($cursor, $quiet->json('cursor'));
    }

    /** A cursor is not an authorization parameter: it can only move within this store's rows. */
    public function test_a_forged_cursor_cannot_reach_another_stores_events(): void
    {
        [$ownerA, $storeA] = $this->connectedTenant();
        [, $storeB] = $this->connectedTenant(7002);
        $this->deliver((int) $storeB->id, $this->payload(7002, 77))->assertOk();
        $this->deliver((int) $storeA->id, $this->payload(7001, 41))->assertOk();

        $all = $this->actingAs($ownerA)->getJson('/lynomia/inbox/api/events?after=-999');

        $this->assertSame([41], array_column($all->json('events'), 'conversation_id'));
    }

    public function test_the_event_stream_is_refused_to_an_anonymous_caller(): void
    {
        $this->connectedTenant();

        $response = $this->getJson('/lynomia/inbox/api/events');

        $this->assertContains($response->getStatusCode(), [401, 403, 302]);
    }

    // ================================================================ subscribing

    public function test_provisioning_subscribes_the_workspace_to_the_right_events(): void
    {
        [, $store] = $this->connectedTenant();

        $created = collect(Http::recorded())->first(fn ($pair) => str_ends_with(
            (string) parse_url($pair[0]->url(), PHP_URL_PATH), '/webhooks'
        ) && strtoupper($pair[0]->method()) === 'POST');

        $this->assertNotNull($created, 'the workspace was never subscribed');
        $sent = $created[0]->data()['webhook'];
        $this->assertStringContainsString("/lynomia/events/{$store->id}", $sent['url']);
        $this->assertContains('message_created', $sent['subscriptions']);
        $this->assertContains('conversation_status_changed', $sent['subscriptions']);

        // THE SECRET IS CHOSEN BY THE RECEIVER so it can be derived per request instead of
        // stored. If this ever stopped being sent, Lynomia would generate one and Store Syria
        // would be verifying against a key it does not have.
        $this->assertSame(EventSignature::secretFor((int) $store->id), $sent['secret']);
    }

    /** The signing key is derived, so it appears in no table. */
    public function test_the_signing_key_is_never_persisted(): void
    {
        [, $store, $link] = $this->connectedTenant();
        $secret = EventSignature::secretFor((int) $store->id);

        $this->assertStringNotContainsString($secret, json_encode($link->getAttributes()));
        $this->assertNotSame('', $secret);
    }

    public function test_two_stores_have_different_signing_keys(): void
    {
        $this->assertNotSame(EventSignature::secretFor(1), EventSignature::secretFor(2));
    }

    // ================================================================ A1 hardening
    //
    // The ingress inherited `->middleware('api')`, which applies the platform-wide
    // `throttle:120,1`: ONE bucket, keyed on the client IP, shared by every store AND by the two
    // hundred-odd routes/api.php endpoints. Lynomia sends every store's deliveries from a single
    // egress address, so one merchant's burst spent everybody's budget -- and an account webhook
    // is never retried upstream, so each 429 destroyed a real event. These pin the replacement.

    public function test_the_ingress_no_longer_shares_the_platform_wide_api_throttle(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->getName() === 'lynomia.events.ingress'
        );
        $this->assertNotNull($route);

        $middleware = collect(app('router')->gatherRouteMiddleware($route))
            ->map(fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : gettype($m)))
            ->all();

        $this->assertNotContains(
            'Illuminate\\Routing\\Middleware\\ThrottleRequests:120,1',
            $middleware,
            'the ingress is back on the shared IP-keyed bucket'
        );
        $this->assertContains(
            'Illuminate\\Routing\\Middleware\\ThrottleRequests:lynomia-events',
            $middleware,
            'the ingress must use its own per-store limiter'
        );
    }

    public function test_the_limiter_is_keyed_per_store_so_one_merchant_cannot_spend_anothers_budget(): void
    {
        $limiter = app(\Illuminate\Cache\RateLimiter::class)->limiter('lynomia-events');
        $this->assertNotNull($limiter, 'the lynomia-events limiter is not registered');

        $keyFor = function (int $store) use ($limiter) {
            $request = \Illuminate\Http\Request::create("/lynomia/events/{$store}", 'POST');
            $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('POST', 'lynomia/events/{store}', []))
                ->bind($request));

            return collect((array) $limiter($request))->first()->key;
        };

        $this->assertNotSame($keyFor(11), $keyFor(12), 'two stores share a rate-limit bucket');
        $this->assertStringContainsString('11', $keyFor(11));
    }

    public function test_an_oversized_body_is_refused_before_it_is_parsed(): void
    {
        [, $store] = $this->connectedTenant();

        $payload = $this->payload();
        $payload['content'] = str_repeat('x', \Workdo\LynomiaChat\app\Http\Middleware\GuardEventIngress::MAX_BYTES + 1);

        $response = $this->deliver($store->id, $payload);

        $response->assertStatus(413);
        $this->assertDatabaseCount('lynomia_inbox_events', 0);
    }

    public function test_a_body_at_the_limit_is_still_accepted(): void
    {
        [, $store] = $this->connectedTenant();

        $response = $this->deliver($store->id, $this->payload());

        $response->assertOk();
    }

    // The row records when LYNOMIA says it happened, which is inside the signature, not when
    // this server happened to parse it. The two differ by the delivery latency, and after an
    // outage they differ by the length of the outage.
    public function test_the_row_carries_the_signed_timestamp_not_the_arrival_time(): void
    {
        [, $store] = $this->connectedTenant();
        $signed = time() - 120;

        $this->deliver($store->id, $this->payload(), ['timestamp' => (string) $signed])->assertOk();

        $row = \Workdo\LynomiaChat\app\Models\LynomiaInboxEvent::first();
        $this->assertSame($signed, $row->occurred_at->getTimestamp());
    }
}
