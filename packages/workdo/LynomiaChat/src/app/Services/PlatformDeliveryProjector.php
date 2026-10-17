<?php

namespace Workdo\LynomiaChat\app\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformEvent;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice;
use Workdo\LynomiaChat\app\Support\DeliveryOutcome;

/**
 * WHAT LYNOMIA REPORTS ABOUT A NOTICE, WRITTEN DOWN ONCE AND IN THE RIGHT ORDER.
 *
 * THE RULE THAT MAKES THIS SAFE: an event is applied only if it is STRICTLY NEWER than the one
 * that produced the state currently on the row. Delivery events arrive out of order whenever a
 * retry, a queue backlog or a provider re-send puts two in flight, and a late
 * `provider_accepted` landing on top of a `delivered` would walk the row backwards and tell an
 * operator a merchant has not been reached when they have.
 *
 * ORDERED ON TIME, NOT ON A RANKING OF THE STATES, and that is deliberate. Lynomia allows a
 * failed message to be retried and delivered, and a delivered one to be re-sent and fail --
 * its own specs pin both. A rule like "never leave a terminal state" would therefore discard
 * the true later outcome. The microsecond stamp is the only thing that orders two reports of
 * the same message correctly, which is why the feed carries it as an integer.
 *
 * NOTHING IS DROPPED SILENTLY. An event that is stale, or that matches no notice, is recorded
 * with the reason it was not applied. A feed that quietly discards is a feed nobody can debug
 * at three in the morning, and "it must have been out of order" is not evidence.
 *
 * `status` IS NOT TOUCHED. That column records THIS side's attempt -- pending, sending, sent,
 * failed, indeterminate -- and the outcome columns record what Lynomia went on to do. Keeping
 * them apart is what lets the console say "we handed it over, and then it was refused", which
 * is the truth and is not expressible in one field.
 */
class PlatformDeliveryProjector
{
    /**
     * @param array<string,mixed> $payload a verified delivery from the platform feed
     * @return LynomiaPlatformEvent the record of what was done with it
     */
    public function apply(string $deliveryId, array $payload): LynomiaPlatformEvent
    {
        $messageId = (int) ($payload['message']['id'] ?? 0);
        $outcome = DeliveryOutcome::fromFeed($payload['outcome'] ?? null);
        $occurredAt = (int) ($payload['occurred_at_us'] ?? 0);

        $notice = $messageId > 0
            ? LynomiaPlatformNotice::where('lynomia_message_id', $messageId)->first()
            : null;

        $event = [
            'delivery_id' => mb_substr($deliveryId, 0, 64),
            'event' => (string) ($payload['event'] ?? ''),
            'lynomia_account_id' => (int) ($payload['account']['id'] ?? 0) ?: null,
            'lynomia_message_id' => $messageId ?: null,
            'notice_id' => $notice?->id,
            'outcome' => $outcome,
            'reason' => ($payload['reason_code'] ?? null) === null
                ? null
                : mb_substr((string) $payload['reason_code'], 0, 64),
            'occurred_at_us' => $occurredAt ?: null,
            'applied' => false,
            'skip_reason' => null,
        ];

        if ($outcome === null) {
            // A vocabulary this side does not know. Recorded rather than guessed at: inventing
            // a state for an unrecognised word is how a feed starts lying.
            return $this->record(array_merge($event, ['skip_reason' => LynomiaPlatformEvent::SKIP_UNKNOWN_OUTCOME]));
        }

        if ($notice === null) {
            // Legitimate: the platform workspace sends things that are not notices.
            return $this->record(array_merge($event, ['skip_reason' => LynomiaPlatformEvent::SKIP_NO_NOTICE]));
        }

        return $this->record($this->applyToNotice($notice, $event, $outcome, $occurredAt, $payload));
    }

    /**
     * Move the notice, under a row lock, and only if this event is newer than the one already
     * written there.
     *
     * THE COMPARISON IS INSIDE THE LOCK. Two deliveries for one message can arrive at once --
     * a retry racing the original is the ordinary case, not an exotic one -- and a check made
     * before the lock is a check both of them pass.
     */
    private function applyToNotice(
        LynomiaPlatformNotice $notice,
        array $event,
        string $outcome,
        int $occurredAt,
        array $payload
    ): array {
        return DB::transaction(function () use ($notice, $event, $outcome, $occurredAt, $payload) {
            $locked = LynomiaPlatformNotice::query()->lockForUpdate()->find($notice->id);

            if ($locked === null) {
                return array_merge($event, ['skip_reason' => LynomiaPlatformEvent::SKIP_NO_NOTICE]);
            }

            if ($locked->outcome_at_us !== null && $occurredAt <= (int) $locked->outcome_at_us) {
                return array_merge($event, ['skip_reason' => LynomiaPlatformEvent::SKIP_STALE]);
            }

            $locked->forceFill([
                'delivery_outcome' => $outcome,
                'delivery_reason' => $event['reason'],
                'delivery_outcome_at' => isset($payload['occurred_at'])
                    ? (string) $payload['occurred_at']
                    : now(),
                'outcome_at_us' => $occurredAt,
            ])->save();

            return array_merge($event, ['applied' => true]);
        });
    }

    /**
     * Write the event, or recognise that we already have it.
     *
     * Insert-and-catch rather than check-then-insert, as the per-store ingress does: the
     * unique index is the arbiter, and a check has a window in it that a retried webhook will
     * find.
     */
    private function record(array $attributes): LynomiaPlatformEvent
    {
        try {
            return LynomiaPlatformEvent::create($attributes);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }

            return LynomiaPlatformEvent::where('delivery_id', $attributes['delivery_id'])->firstOrFail();
        }
    }
}
