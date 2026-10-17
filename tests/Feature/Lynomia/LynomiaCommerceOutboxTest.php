<?php

namespace Tests\Feature\Lynomia;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaCommerceEvent;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\CommerceCredential;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * P3D. The transactional outbox that carries order changes to Lynomia.
 *
 * THE PROPERTY THAT MATTERS IS DURABILITY, and it is the one a happy-path test cannot show. An
 * event must exist if and only if the order change it describes exists -- so these tests roll
 * a transaction back, switch the integration off, revoke a credential and break the network,
 * and assert what survives each one.
 */
class LynomiaCommerceOutboxTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const ENDPOINT = 'https://lynomia.test/webhooks/storesyria';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
        config()->set('lynomia.commerce_events_enabled', true);
        config()->set('lynomia.base_url', 'https://lynomia.test');
    }

    /** @return array{0: int, 1: LynomiaStoreLink} */
    private function connected(int $accountId = 7001): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        $link = app(AccountProvisioner::class)->provision($store, $owner)->fresh();
        $link->forceFill(['commerce_credential_version' => 1])->save();

        return [(int) $store->id, $link->fresh()];
    }

    /** What Lynomia answers an event delivery with. The harness's fake owns every Lynomia path. */
    private function lynomiaAnswers(int $status): void
    {
        $this->fakeLynomia(['commerce_event_status' => $status]);
    }

    private function order(int $storeId, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'product_order_id' => 'A-1',
            'order_date' => '2026-01-01 12:00:00',
            'customer_id' => 42,
            'is_guest' => 0,
            'final_price' => 1500.5,
            'payment_status' => 'pending',
            'delivered_status' => 0,
            'store_id' => $storeId,
        ], $attributes));
    }

    // ---------------------------------------------------------------- recording

    public function test_creating_an_order_records_one_event_for_its_own_store(): void
    {
        [$storeId] = $this->connected();

        $order = $this->order($storeId);

        $event = LynomiaCommerceEvent::forStore($storeId)->sole();
        $this->assertSame('order.created', $event->event);
        $this->assertSame((int) $order->id, $event->order_id);
        $this->assertSame('42', $event->customer_reference);
        $this->assertNull($event->delivered_at);
    }

    // The event that the retired integration could never see: App\Events\OrderPaymentStatus is
    // not dispatched on creation, and its settings UI offered an `order_created` notification
    // that no code path fired. Recording from the model is what closes that.
    public function test_a_status_change_and_a_payment_change_are_each_recorded(): void
    {
        [$storeId] = $this->connected();
        $order = $this->order($storeId);

        $order->update(['delivered_status' => 6]);
        $order->update(['payment_status' => 'succeeded']);

        $this->assertSame(
            ['order.created', 'order.status_changed', 'order.payment_changed'],
            LynomiaCommerceEvent::forStore($storeId)->orderBy('id')->pluck('event')->all()
        );
    }

    // A save that touches neither status is not a commerce event. Recording one would wake the
    // whole refresh path for a note nobody can see.
    public function test_an_unrelated_change_records_nothing(): void
    {
        [$storeId] = $this->connected();
        $order = $this->order($storeId);
        LynomiaCommerceEvent::query()->delete();

        $order->update(['additional_note' => 'leave at the door']);

        $this->assertSame(0, LynomiaCommerceEvent::count());
    }

    public function test_a_guest_order_is_referenced_the_way_the_commerce_api_references_one(): void
    {
        [$storeId] = $this->connected();

        $order = $this->order($storeId, ['is_guest' => 1, 'customer_id' => 0]);

        $this->assertSame('guest:' . $order->id, LynomiaCommerceEvent::forStore($storeId)->sole()->customer_reference);
    }

    // THE WHOLE REASON THIS IS AN OUTBOX. A job dispatched from the observer would fire for an
    // order that does not exist; a row written in the caller's transaction cannot.
    public function test_an_event_does_not_survive_a_rolled_back_order(): void
    {
        [$storeId] = $this->connected();

        try {
            DB::transaction(function () use ($storeId) {
                $this->order($storeId);
                $this->assertSame(1, LynomiaCommerceEvent::count(), 'the row is written inside the transaction');

                throw new \RuntimeException('rolled back');
            });
            $this->fail('the transaction should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('rolled back', $e->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, LynomiaCommerceEvent::count(), 'the event outlived the order change it describes');
    }

    public function test_a_store_with_no_workspace_records_nothing(): void
    {
        [$owner, $store] = $this->entitledTenant();

        $this->order((int) $store->id);

        $this->assertSame(0, LynomiaCommerceEvent::count());
    }

    public function test_a_revoked_link_records_nothing(): void
    {
        [$storeId, $link] = $this->connected();
        $link->forceFill(['state' => LinkState::REVOKED])->save();

        $this->order($storeId);

        $this->assertSame(0, LynomiaCommerceEvent::count());
    }

    // Nothing is recorded while the feed is off, rather than recorded and held: a backlog
    // accumulated while switched off is a queue of deliveries aimed at a workspace that may
    // not exist by the time somebody switches it on.
    public function test_nothing_is_recorded_while_the_feed_is_switched_off(): void
    {
        [$storeId] = $this->connected();
        config()->set('lynomia.commerce_events_enabled', false);

        $this->order($storeId);

        $this->assertSame(0, LynomiaCommerceEvent::count());
    }

    // THE MEMO THAT WAS NOT. Caching the link check on the observer would have cached it for
    // the life of the PROCESS, not the request -- so in a queue worker a store revoked an hour
    // ago would go on recording events until the worker restarted. Two orders in one process,
    // with the link revoked in between, is the case that catches it.
    public function test_a_link_revoked_mid_process_stops_recording_at_once(): void
    {
        [$storeId, $link] = $this->connected();
        $this->order($storeId, ['product_order_id' => 'BEFORE']);
        $this->assertSame(1, LynomiaCommerceEvent::count());

        $link->forceFill(['state' => LinkState::REVOKED])->save();
        $this->order($storeId, ['product_order_id' => 'AFTER']);

        $this->assertSame(1, LynomiaCommerceEvent::count(), 'the observer kept a stale link decision');
    }

    // ---------------------------------------------------------------- delivery

    public function test_delivery_signs_the_body_and_names_the_store_without_sending_the_credential(): void
    {
        [$storeId, $link] = $this->connected();
        $this->order($storeId);
        $this->lynomiaAnswers(200);

        $this->artisan('lynomia:deliver-commerce-events')->assertExitCode(0);

        $token = CommerceCredential::issue($link->fresh());
        Http::assertSent(function ($request) use ($storeId, $token) {
            $key = hash_hmac('sha256', 'storesyria-commerce-events', $token);
            $expected = 'sha256=' . hash_hmac('sha256', $request->header('X-Storesyria-Timestamp')[0] . '.' . $request->body(), $key);

            return $request->url() === self::ENDPOINT
                && $request->header('X-Storesyria-Signature')[0] === $expected
                && str_ends_with($request->header('X-Storesyria-Store')[0], ':' . $storeId)
                // The credential itself never travels: only an HMAC made with a key derived
                // from it, so a captured delivery discloses neither.
                && ! str_contains($request->body() . json_encode($request->headers()), $token);
        });

        $this->assertNotNull(LynomiaCommerceEvent::forStore($storeId)->sole()->delivered_at);
    }

    // No names, no totals, no phone numbers. Lynomia re-reads the order from this application
    // rather than trusting a delivery, so order data in the body would be customer data sitting
    // in a request log and a queue for nobody to read.
    public function test_a_delivery_carries_no_order_data(): void
    {
        [$storeId] = $this->connected();
        $this->order($storeId, ['product_order_id' => 'SECRET-NUMBER', 'final_price' => 987654.21]);
        $this->lynomiaAnswers(200);

        $this->artisan('lynomia:deliver-commerce-events');

        Http::assertSent(fn ($request) => ! str_contains($request->body(), 'SECRET-NUMBER')
            && ! str_contains($request->body(), '987654'));
    }

    public function test_a_failed_delivery_backs_off_and_is_retried_rather_than_dropped(): void
    {
        [$storeId] = $this->connected();
        $this->order($storeId);
        $this->lynomiaAnswers(500);

        $this->artisan('lynomia:deliver-commerce-events');

        $event = LynomiaCommerceEvent::forStore($storeId)->sole();
        $this->assertNull($event->delivered_at);
        $this->assertSame(1, $event->attempts);
        $this->assertSame('http_500', $event->last_error);
        $this->assertTrue($event->deliver_after->isFuture());

        // The next pass leaves it alone until its backoff elapses, so a failing store cannot
        // consume every batch.
        $this->lynomiaAnswers(200);
        $this->artisan('lynomia:deliver-commerce-events');
        $this->assertNull($event->fresh()->delivered_at);

        $this->travel(2)->minutes();
        $this->artisan('lynomia:deliver-commerce-events');
        $this->assertNotNull($event->fresh()->delivered_at);
    }

    // A store that was disconnected after its events were recorded has nowhere to deliver them
    // and never will, so they are retired rather than retried forever.
    public function test_events_of_a_link_that_went_away_are_retired_not_retried(): void
    {
        [$storeId, $link] = $this->connected();
        $this->order($storeId);
        $link->forceFill(['state' => LinkState::REVOKED])->save();
        $this->lynomiaAnswers(200);

        $this->artisan('lynomia:deliver-commerce-events');

        $this->assertSame('link_not_active', LynomiaCommerceEvent::forStore($storeId)->sole()->last_error);
        Http::assertNothingSent();
    }

    public function test_nothing_is_delivered_while_the_feed_is_switched_off(): void
    {
        [$storeId] = $this->connected();
        $this->order($storeId);
        config()->set('lynomia.commerce_events_enabled', false);
        $this->lynomiaAnswers(200);

        $this->artisan('lynomia:deliver-commerce-events');

        Http::assertNothingSent();
        $this->assertNull(LynomiaCommerceEvent::forStore($storeId)->sole()->delivered_at);
    }

    // Delivered rows are a log nobody reads twice. UNDELIVERED rows are never pruned whatever
    // their age: they are the evidence that a store stopped syncing.
    public function test_the_sweep_prunes_delivered_events_and_keeps_undelivered_ones(): void
    {
        [$storeId] = $this->connected();
        $this->order($storeId);
        $event = LynomiaCommerceEvent::forStore($storeId)->sole();
        $event->forceFill(['delivered_at' => now()->subDays(30)])->save();

        $stuck = LynomiaCommerceEvent::create([
            'store_id' => $storeId, 'event' => 'order.created', 'order_id' => 1,
            'occurred_at' => now()->subDays(60), 'delivery_id' => (string) \Illuminate\Support\Str::uuid(),
            'attempts' => 40, 'deliver_after' => now()->subDay(),
        ]);
        $this->lynomiaAnswers(500);

        $this->artisan('lynomia:deliver-commerce-events');

        $this->assertNull($event->fresh(), 'a delivered event past retention should be pruned');
        $this->assertNotNull($stuck->fresh(), 'an undelivered event is evidence and is never pruned');
    }

    public function test_one_stores_events_are_never_delivered_under_another_stores_signature(): void
    {
        [$storeA, $linkA] = $this->connected(7001);
        [$storeB, $linkB] = $this->connected(7002);
        $this->order($storeA);
        $this->order($storeB);
        $this->lynomiaAnswers(200);

        $this->artisan('lynomia:deliver-commerce-events');

        foreach ([[$storeA, $linkA], [$storeB, $linkB]] as [$storeId, $link]) {
            $token = CommerceCredential::issue($link->fresh());
            Http::assertSent(function ($request) use ($storeId, $token) {
                if (! str_ends_with($request->header('X-Storesyria-Store')[0], ':' . $storeId)) {
                    return false;
                }
                $key = hash_hmac('sha256', 'storesyria-commerce-events', $token);

                return $request->header('X-Storesyria-Signature')[0]
                    === 'sha256=' . hash_hmac('sha256', $request->header('X-Storesyria-Timestamp')[0] . '.' . $request->body(), $key);
            });
        }
    }
}
