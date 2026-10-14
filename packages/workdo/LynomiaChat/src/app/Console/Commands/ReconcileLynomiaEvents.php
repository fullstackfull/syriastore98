<?php

namespace Workdo\LynomiaChat\app\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaInboxEvent;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\MerchantInboxGateway;
use Workdo\LynomiaChat\app\Services\SeatAllowanceSync;

/**
 * The half of the delivery guarantee that push cannot provide.
 *
 * A REFUSED DELIVERY IS GONE. Lynomia sends account webhooks fire-and-forget: Webhooks::Trigger
 * swallows a non-2xx, WebhookJob declares no retry_on, and the fork's own override says in
 * writing that it deliberately keeps it that way. So anything this installation does not accept
 * -- a throttled burst, a deploy, a database blip, a network partition -- is never sent again.
 *
 * That makes the ingress rate limit a data-loss device unless something can notice the gap and
 * close it. This is that something. It does not ask Lynomia to re-send: it asks what the current
 * state IS, which is a question that always has an answer.
 *
 * IT DOES NOT REPLAY EVENTS, AND DOES NOT NEED TO. The browser never applies a diff -- any event
 * on the cursor makes it re-fetch conversations through the authorized BFF. So recovering from a
 * gap needs exactly one thing: a row on the cursor. Comparing the newest activity Lynomia
 * reports against the newest this store has recorded tells us whether one is owed, and a single
 * marker row is enough to make the inbox current again. One GET per store per sweep, and an
 * INSERT only when there is real drift.
 *
 * THE SWEEP IS THE PRUNE TOO. lynomia_inbox_events had no retention of any kind: it is an
 * append-only cursor source that nothing reads twice, so under a flood it was the unbounded
 * resource in this path. Old rows go in the same bounded pass.
 *
 * Modelled on the shape this codebase already uses for deferred work -- a scheduled command
 * draining in bounded batches with an overlap guard (app/Console/Commands/ZidProcessEvents.php),
 * not a queued job. The module dispatches no jobs and the installation may have no worker.
 */
class ReconcileLynomiaEvents extends Command
{
    protected $signature = 'lynomia:reconcile-events
                            {--limit= : examine at most this many stores (default: every one)}';

    protected $description = 'Close gaps left by undelivered Lynomia events, and prune the cursor table.';

    public const RECONCILE_EVENT = 'reconciled';

    public function handle(MerchantInboxGateway $gateway, SeatAllowanceSync $seats): int
    {
        if (! config('lynomia.enabled')) {
            $this->info('Lynomia integration is disabled; nothing to reconcile.');

            return self::SUCCESS;
        }

        $pruned = $this->prune();
        $examined = 0;
        $repaired = 0;
        $seatsSynced = 0;
        $seatsFailed = 0;

        // EVERY ACTIVE LINK, NOT A PREFIX OF THEM. This took `--limit=50` by default and
        // ordered by id, so only the fifty lowest-id stores were ever examined: the recovery
        // that justifies refusing a delivery at all simply did not exist for store 51 onwards,
        // silently and forever. A cap that quietly decides which merchants get a working inbox
        // is worse than no cap.
        //
        // chunkById rather than get(): the set grows with the business and must not be loaded
        // whole. The option survives for a manual, deliberately narrowed run.
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        LynomiaStoreLink::query()
            ->where('state', 'active')
            ->whereNotNull('lynomia_account_id')
            ->orderBy('id')
            ->chunkById(100, function ($links) use ($gateway, $seats, &$examined, &$repaired, &$seatsSynced, &$seatsFailed, $limit) {
                foreach ($links as $link) {
                    if ($limit !== null && $examined >= $limit) {
                        return false;
                    }

                    $examined++;
                    $repaired += $this->reconcile($gateway, $link) ? 1 : 0;

                    // THE SEAT CEILING RIDES THE SAME SWEEP, which is what makes
                    // IntegrationController's "will be retried automatically" true rather than
                    // a hope. Nothing retried a failed seat push before: SeatAllowanceSync had
                    // three callers, all one-shot (provisioning, operator approval, the admin
                    // form), and the only scheduled command was this one, which did not touch
                    // seats. An operator who changed a plan while a workspace was unreachable
                    // silently never reached it.
                    //
                    // Cheap and safe to repeat: the Lynomia grant endpoint is an upsert, so a
                    // store already carrying the right ceiling is a no-op, and a plan that
                    // includes nothing withdraws rather than granting zero.
                    $seats->syncQuietly($link) ? $seatsSynced++ : $seatsFailed++;
                }

                return true;
            });

        $this->info("examined={$examined} repaired={$repaired} pruned={$pruned} "
            . "seats_synced={$seatsSynced} seats_failed={$seatsFailed}");

        return self::SUCCESS;
    }

    /**
     * @return bool whether this store was behind and a marker was written
     */
    private function reconcile(MerchantInboxGateway $gateway, LynomiaStoreLink $link): bool
    {
        $owner = User::find($link->owner_user_id);

        if ($owner === null) {
            return false;
        }

        try {
            $client = $gateway->clientFor($link, $owner);
            $body = $client->listConversations(['status' => 'all', 'page' => 1]);
        } catch (LynomiaApiException $e) {
            // Unreachable is the normal case this runs in; it is not an error worth alerting on
            // every minute. The next sweep tries again.
            Log::warning('[lynomia] reconciliation could not read the workspace', [
                'store_id' => $link->store_id,
                'kind' => $e->kind,
            ]);

            return false;
        }

        [$latest, $conversationId] = $this->latestActivity($body);

        if ($latest === null) {
            return false;
        }

        // The watermark is on the LINK, not on the events table. The events table is pruned,
        // and taking the watermark from it meant a quiet store whose newest marker aged out
        // re-detected the same gap on every sweep and wrote a marker forever.
        $seen = $link->events_reconciled_at;
        $newestEvent = LynomiaInboxEvent::where('store_id', $link->store_id)->max('occurred_at');

        if ($newestEvent !== null) {
            $newestEvent = \Illuminate\Support\Carbon::parse($newestEvent);
            $seen = $seen === null || $newestEvent->greaterThan($seen) ? $newestEvent : $seen;
        }

        if ($seen !== null && $latest->lessThanOrEqualTo($seen)) {
            return false;
        }

        // A deterministic delivery id, so two sweeps that observe the same drift write one row.
        // The unique index on delivery_id is what makes that true rather than hoped for.
        LynomiaInboxEvent::firstOrCreate(
            ['delivery_id' => 'reconcile:' . $link->store_id . ':' . $latest->getTimestamp()],
            [
                'store_id' => (int) $link->store_id,
                'lynomia_account_id' => (int) $link->lynomia_account_id,
                'event' => self::RECONCILE_EVENT,
                // NAME THE CONVERSATION THAT MOVED. A marker with a null conversation id makes
                // the browser reload the LIST, but leaves the thread the agent is actually
                // reading stale -- which is the one view a dropped message matters in most.
                'conversation_display_id' => $conversationId,
                // WHEN THE GAP WAS NOTICED, not when the remote activity happened. The remote
                // time goes on the watermark. Stamping the marker with old remote activity made
                // it instantly older than the retention window, so the next sweep pruned the
                // very row it had just written.
                'occurred_at' => now(),
            ]
        );

        // Move the durable watermark forward whether or not the marker row was new, so the
        // same gap is never reported twice.
        $link->forceFill(['events_reconciled_at' => $latest])->save();

        Log::warning('[lynomia] reconciled a gap in delivered events', [
            'store_id' => $link->store_id,
            'latest_remote' => $latest->toIso8601String(),
            'latest_local' => $seen?->toIso8601String(),
        ]);

        return true;
    }

    /**
     * The newest activity in the workspace, and the conversation it belongs to.
     *
     * @return array{0: \Illuminate\Support\Carbon|null, 1: int|null}
     */
    private function latestActivity(array $body): array
    {
        $payload = $body['data']['payload'] ?? [];

        if (! is_array($payload) || $payload === []) {
            return [null, null];
        }

        $newest = null;

        foreach ($payload as $conversation) {
            if (! is_array($conversation) || ! is_numeric($conversation['last_activity_at'] ?? null)) {
                continue;
            }

            $at = (int) $conversation['last_activity_at'];

            if ($newest === null || $at > $newest[0]) {
                // `id` IS the display id in Lynomia's payload
                // (_conversation.json.jbuilder:70 emits conversation.display_id as `id`).
                $newest = [$at, is_numeric($conversation['id'] ?? null) ? (int) $conversation['id'] : null];
            }
        }

        return $newest === null
            ? [null, null]
            : [\Illuminate\Support\Carbon::createFromTimestampUTC($newest[0]), $newest[1]];
    }

    private function prune(): int
    {
        $days = max(1, (int) config('lynomia.event_retention_days', 14));

        return LynomiaInboxEvent::where('occurred_at', '<', now()->subDays($days))
            ->limit(5000)
            ->delete();
    }
}
