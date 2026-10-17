<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaInboxEvent;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;

/**
 * The half of the delivery guarantee push cannot provide.
 *
 * Lynomia sends account webhooks fire-and-forget: a non-2xx is swallowed and the event is never
 * sent again. So the moment this installation refuses ANYTHING -- a throttled burst, a deploy, a
 * dropped connection -- the merchant's inbox is silently behind with no way back. Rate limiting
 * the ingress without this would be a data-loss feature.
 */
class LynomiaReconciliationTest extends TestCase
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
        $link = app(AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store, $link->fresh()];
    }

    public function test_it_notices_a_store_is_behind_and_puts_a_marker_on_the_cursor(): void
    {
        [, , $link] = $this->connected();
        $this->assertDatabaseCount('lynomia_inbox_events', 0);

        $this->artisan('lynomia:reconcile-events')->assertSuccessful();

        $event = LynomiaInboxEvent::where('store_id', $link->store_id)->first();
        $this->assertNotNull($event, 'a gap was not detected');
        $this->assertSame('reconciled', $event->event);
    }

    // THE DEFECT THIS TEST EXISTS FOR. The watermark first lived in lynomia_inbox_events -- the
    // table the same sweep prunes. On a quiet store whose newest marker aged past the retention
    // window, the sweep deleted its own evidence, re-detected the identical gap, wrote another
    // marker, and did it again every five minutes forever. The watermark is on the link now.
    public function test_a_second_sweep_does_not_report_the_same_gap_again(): void
    {
        [, , $link] = $this->connected();

        $this->artisan('lynomia:reconcile-events')->assertSuccessful();
        $first = LynomiaInboxEvent::count();

        $this->artisan('lynomia:reconcile-events')->assertSuccessful();

        $this->assertSame($first, LynomiaInboxEvent::count(), 'the sweep is writing a marker on every run');
        $this->assertNotNull($link->fresh()->events_reconciled_at);
    }

    public function test_the_watermark_survives_the_pruning_of_the_events_it_was_derived_from(): void
    {
        [, , $link] = $this->connected();
        $this->artisan('lynomia:reconcile-events')->assertSuccessful();

        LynomiaInboxEvent::query()->delete();

        $this->artisan('lynomia:reconcile-events')->assertSuccessful();

        $this->assertDatabaseCount('lynomia_inbox_events', 0);
    }

    public function test_it_prunes_rows_past_the_retention_window_and_keeps_recent_ones(): void
    {
        [, , $link] = $this->connected();

        LynomiaInboxEvent::create([
            'store_id' => $link->store_id, 'lynomia_account_id' => 7001, 'event' => 'message_created',
            'conversation_display_id' => 41, 'occurred_at' => now()->subDays(90), 'delivery_id' => 'old-1',
        ]);
        LynomiaInboxEvent::create([
            'store_id' => $link->store_id, 'lynomia_account_id' => 7001, 'event' => 'message_created',
            'conversation_display_id' => 42, 'occurred_at' => now()->subMinutes(5), 'delivery_id' => 'new-1',
        ]);

        $this->artisan('lynomia:reconcile-events')->assertSuccessful();

        $this->assertDatabaseMissing('lynomia_inbox_events', ['delivery_id' => 'old-1']);
        $this->assertDatabaseHas('lynomia_inbox_events', ['delivery_id' => 'new-1']);
    }

    public function test_it_does_nothing_at_all_while_the_integration_is_disabled(): void
    {
        $this->connected();
        config()->set('lynomia.enabled', false);

        $this->artisan('lynomia:reconcile-events')->assertSuccessful();

        $this->assertDatabaseCount('lynomia_inbox_events', 0);
    }
}
