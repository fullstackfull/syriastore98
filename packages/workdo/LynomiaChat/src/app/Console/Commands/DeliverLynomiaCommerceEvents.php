<?php

namespace Workdo\LynomiaChat\app\Console\Commands;

use Illuminate\Console\Command;
use Workdo\LynomiaChat\app\Models\LynomiaCommerceEvent;
use Workdo\LynomiaChat\app\Services\CommerceEventDispatcher;

/**
 * Drains the commerce outbox.
 *
 * A SCHEDULED DRAIN, NOT A QUEUED JOB, for the same reason ReconcileLynomiaEvents is one: this
 * module dispatches no jobs and an installation may have no worker running at all. A command on
 * the scheduler needs only the cron entry every Laravel installation already has.
 *
 * AND NOT INLINE, EITHER. Delivering from the observer would put an HTTP call inside the
 * transaction that changed the order -- including the inbound courier webhook, before it gets
 * its response. That is precisely the defect the retired Chatwoot integration shipped: up to
 * six serial calls at a fifteen-second timeout each, running inside the request. The cost of
 * doing it properly is up to a minute of delivery latency, which is the right trade for an
 * event whose only job is to invalidate a cache.
 *
 * BOUNDED, so one store's backlog cannot hold the scheduler. Rows are taken oldest first across
 * every store, which is also the order they happened in; a store that is failing backs its own
 * rows off and stops competing for the batch within a couple of passes.
 *
 * THE PRUNE RIDES ALONG, as it does in the reconciliation sweep: delivered rows are a log
 * nobody reads twice, and without retention this table is the unbounded one in this path.
 * UNDELIVERED rows are never pruned, whatever their age -- they are the evidence that a store
 * stopped syncing, and deleting them is how that becomes invisible.
 */
class DeliverLynomiaCommerceEvents extends Command
{
    protected $signature = 'lynomia:deliver-commerce-events
                            {--limit=200 : deliver at most this many events in one pass}';

    protected $description = 'Deliver queued Store Syria commerce events to Lynomia, and prune delivered ones.';

    public function handle(): int
    {
        if (! config('lynomia.enabled') || ! config('lynomia.commerce_events_enabled')) {
            $this->info('The Lynomia commerce event feed is switched off; nothing was delivered.');

            return self::SUCCESS;
        }

        $dispatcher = new CommerceEventDispatcher((array) config('lynomia.http', []));
        $limit = max(1, (int) $this->option('limit'));
        $delivered = 0;
        $failed = 0;

        LynomiaCommerceEvent::pending()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (LynomiaCommerceEvent $event) use ($dispatcher, &$delivered, &$failed) {
                $dispatcher->deliver($event) ? $delivered++ : $failed++;
            });

        $pruned = $this->prune();

        $this->info("Delivered {$delivered}, deferred {$failed}, pruned {$pruned}.");

        return self::SUCCESS;
    }

    private function prune(): int
    {
        $days = max(1, (int) config('lynomia.commerce_event_retention_days', 14));

        return LynomiaCommerceEvent::whereNotNull('delivered_at')
            ->where('delivered_at', '<', now()->subDays($days))
            ->limit(1000)
            ->delete();
    }
}
