<?php

namespace Workdo\LynomiaChat\app\Services;

use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice;
use Workdo\LynomiaChat\app\Support\DeliveryOutcome;

/**
 * ASKING, WHEN NOTHING WAS TOLD.
 *
 * WHY A FEED IS NOT ENOUGH ON ITS OWN. A webhook can be lost in every way a request can be
 * lost: Lynomia enqueues the job and the worker dies, the delivery arrives while this side is
 * redeploying, the signature clock has drifted past tolerance, the body exceeded the cap. Each
 * of those leaves a notice sitting at `accepted` for ever, which is indistinguishable from one
 * that genuinely has not resolved yet -- and `accepted` was the whole problem this phase set
 * out to fix.
 *
 * SO THE FEED IS THE FAST PATH AND THIS IS THE FLOOR. It reads the message back from Lynomia
 * and writes the outcome through the SAME projector the feed uses, with a synthesised delivery
 * id, so the ordering rule and the audit trail apply identically. A reconciliation that
 * disagreed with the feed by writing through a second path would be a second source of truth.
 *
 * IT ONLY CHASES WHAT IS OPEN. A notice in a terminal outcome is finished; one this side never
 * handed over has nothing to ask about; and `sent` with no outcome at all is the dangerous
 * case -- Lynomia took it, nothing came back, and before the delivery feed that was
 * indistinguishable from success.
 *
 * READS ARE NOT RETRIED ACROSS A TIMEOUT, and that is AgentApiClient's rule, not an oversight:
 * for a read the answer is simply unknown this time and will be asked for again on the next
 * pass. Nothing here writes a worse state because a read failed.
 */
class PlatformDeliveryReconciler
{
    /**
     * How long a notice may sit unresolved before it is worth asking about.
     *
     * Long enough that the ordinary delivery feed, including a queue backlog, has had every
     * chance -- re-asking inside that window would mean this side racing Lynomia's own
     * webhook and recording the slower answer.
     */
    public const GRACE_MINUTES = 15;

    public function __construct(
        private readonly PlatformWorkspaceGateway $workspace,
        private readonly PlatformDeliveryProjector $projector
    ) {
    }

    /**
     * @return array{checked:int, resolved:int, unreachable:int}
     */
    public function run(int $limit = 50): array
    {
        $checked = 0;
        $resolved = 0;
        $unreachable = 0;

        $notices = LynomiaPlatformNotice::query()
            ->awaitingOutcome()
            ->whereNotNull('lynomia_conversation_id')
            ->where('sent_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->orderBy('sent_at')
            ->limit($limit)
            ->get();

        foreach ($notices as $notice) {
            $checked++;

            try {
                $resolved += $this->reconcile($notice) ? 1 : 0;
            } catch (LynomiaApiException $e) {
                // Asking failed. The notice keeps the state it had, which is the honest one,
                // and the next pass asks again.
                $unreachable++;
                Log::info('[lynomia] could not reconcile a notice', [
                    'notice_id' => $notice->id, 'kind' => $e->kind,
                ]);
            }
        }

        return ['checked' => $checked, 'resolved' => $resolved, 'unreachable' => $unreachable];
    }

    /** @return bool whether an outcome was written */
    private function reconcile(LynomiaPlatformNotice $notice): bool
    {
        $message = $this->findMessage($notice);

        if ($message === null) {
            return false;
        }

        // THROUGH THE PROJECTOR, with a delivery id of our own, so reconciliation obeys the
        // same ordering rule as the feed and leaves the same audit row. The id names where the
        // answer came from, so a trail that disagrees with itself can be read.
        $event = $this->projector->apply(
            'reconcile-' . $notice->lynomia_message_id . '-' . $this->occurredAt($message),
            [
                'event' => 'message_delivery_status',
                'account' => ['id' => (int) config('lynomia.platform_workspace_account_id')],
                'message' => ['id' => (int) $notice->lynomia_message_id],
                'outcome' => $this->outcomeOf($message),
                'reason_code' => null,
                'occurred_at_us' => $this->occurredAt($message),
            ]
        );

        return (bool) $event->applied;
    }

    /**
     * The message, found in the conversation it was sent to.
     *
     * One page. A notice's message is the newest thing in its own conversation at the moment
     * it is sent, and a conversation the platform workspace opened for one merchant does not
     * accumulate hundreds of messages between then and a reconciliation pass.
     */
    private function findMessage(LynomiaPlatformNotice $notice): ?array
    {
        $payload = $this->workspace->listMessages((int) $notice->lynomia_conversation_id);
        $messages = $payload['payload'] ?? [];

        foreach ($messages as $message) {
            if ((int) ($message['id'] ?? 0) === (int) $notice->lynomia_message_id) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Lynomia's four states, read back rather than reported.
     *
     * The same derivation the feed makes, for the same reason: `sent` is the column default
     * and is written before anything is attempted, so what separates "we have it" from "the
     * provider took it" is the provider's own id.
     */
    private function outcomeOf(array $message): string
    {
        return match ($message['status'] ?? null) {
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            default => ($message['source_id'] ?? null) ? 'provider_accepted' : 'queued',
        };
    }

    /**
     * THE STAMP A RECONCILED OUTCOME IS ORDERED BY, AND WHY IT IS THE OLDEST ONE AVAILABLE.
     *
     * `api/v1/models/_message.json.jbuilder` serialises `created_at` and nothing else
     * temporal -- there is no `updated_at` on the wire and no per-status timestamp, so when
     * a state was reached is simply not a thing the read endpoint will say.
     *
     * Creation time is therefore what a reconciled outcome carries, and that is the right
     * answer rather than a regrettable one: the feed stamps an event with the message's
     * `updated_at`, which is at or after its creation, so a reconciled write always orders
     * BELOW every feed event for the same message. It fills the gap where nothing was ever
     * reported and can never overwrite something that was.
     *
     * An epoch integer, as the serialiser emits it. Multiplied into microseconds because that
     * is the unit the ordering column is in, not because the precision is real.
     */
    private function occurredAt(array $message): int
    {
        return (int) round(((float) ($message['created_at'] ?? 0)) * 1_000_000);
    }
}
