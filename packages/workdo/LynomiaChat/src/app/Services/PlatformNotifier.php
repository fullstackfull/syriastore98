<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\DeliveryOutcome;

/**
 * SENDING ONE OPERATIONAL NOTICE TO ONE MERCHANT, ONCE.
 *
 * Store Syria tells merchants about their accounts -- onboarding, a broken integration, a
 * security notice -- from its own Lynomia workspace, reusing Lynomia's WhatsApp channel,
 * its template store and its send path. Nothing here is a second sender: this resolves who
 * may be told, records the intent, and hands the send to Lynomia.
 *
 * "ONCE" IS TWO DATABASE WRITES, NOT A CHECK.
 *
 * There is no idempotency mechanism anywhere on Lynomia's send path -- no key on message
 * create, no unique index on messages.source_id, and the campaign guard's own docblock
 * records that re-running a half-sent campaign messages people twice. So the guarantee has
 * to be made here, and under concurrency it takes both of these:
 *
 *   the unique index on `dedupe_key`   one ROW per occasion. Two operators acting on the
 *                                      same incident collide on it, and the loser is refused
 *                                      by Postgres rather than by a race somebody has to get
 *                                      right.
 *   the conditional PENDING -> SENDING one SEND per row. The index alone is not enough: the
 *                                      loser re-reads the row the winner inserted, and if it
 *                                      reads it before the winner has moved it, BOTH hold a
 *                                      pending row and both send. Whoever moves the status
 *                                      owns the attempt; the other returns the row untouched.
 *
 * THE ROW IS WRITTEN BEFORE THE CALL.
 *
 * A record written only on success cannot tell "never sent" from "sent, and the answer was
 * lost". So the row goes in `pending`, moves to `sending` before the remote call, and only
 * then to `sent` or `failed`. A transport failure -- where the request may or may not have
 * reached Meta -- lands on `indeterminate`, which is NEVER retried automatically: for an
 * operational notice the safe failure is one that might not arrive, not one that arrives
 * twice. An operator decides, with the row in front of them.
 *
 * WHAT IT DOES NOT DECIDE. Whether the template is allowed is Lynomia's: the workspace
 * refuses anything Meta does not classify as UTILITY, reading the category off the channel's
 * own synced list, so an operator cannot relabel a marketing template as operational by
 * calling it one here.
 */
class PlatformNotifier
{
    public function __construct(
        private readonly PlatformRecipients $recipients,
        private readonly PlatformWorkspaceGateway $workspace
    ) {
    }

    /**
     * @param  array<string,string>  $params  the template's placeholder values, in order
     * @return LynomiaPlatformNotice the record, in whatever state the attempt left it
     */
    public function notify(
        LynomiaStoreLink $link,
        string $kind,
        string $templateName,
        string $dedupeKey,
        array $params = [],
        ?User $actor = null,
        string $language = 'en_US'
    ): LynomiaPlatformNotice {
        $this->assertKind($kind);

        // Resolved before the row is written, because an unreachable merchant is a fact to
        // record against the occasion rather than a reason to have no record of it.
        [$recipient, $refusal] = $this->recipients->resolve($link);

        $notice = $this->claim($link, $kind, $templateName, $language, $dedupeKey, $params, $recipient, $actor);

        // Already said, or being said. The claim found an existing row rather than making
        // one, and that row is in a state where a message may already be in front of the
        // merchant. `claim` reopens the one state that proves nothing was sent.
        if ($notice->status !== LynomiaPlatformNotice::PENDING) {
            return $notice;
        }

        if ($refusal !== null) {
            return $this->markRefused($notice, $refusal);
        }

        return $this->deliver($notice, $recipient);
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, LynomiaPlatformNotice::KINDS, true)) {
            throw new \InvalidArgumentException("Not an authorised notice kind: {$kind}");
        }
    }

    /**
     * Take the occasion, or discover somebody already has.
     *
     * The insert is attempted and its unique violation caught, rather than looked up first:
     * a check-then-insert has a window between the two, and this is the exact operation that
     * must not have one.
     */
    private function claim(
        LynomiaStoreLink $link,
        string $kind,
        string $templateName,
        string $language,
        string $dedupeKey,
        array $params,
        ?array $recipient,
        ?User $actor
    ): LynomiaPlatformNotice {
        $attributes = [
            // From the link, not the recipient: an unreachable merchant has no recipient and
            // the row still belongs to their store, which is where an operator looks for it.
            'store_id' => (int) $link->store_id,
            'recipient_user_id' => $recipient['user_id'] ?? null,
            'recipient_phone' => $recipient['phone'] ?? '',
            'kind' => $kind,
            'template_name' => $templateName,
            'template_language' => $language,
            'template_params' => $params,
            'dedupe_key' => $dedupeKey,
            'status' => LynomiaPlatformNotice::PENDING,
            'requested_by' => $actor?->id,
            'automated' => $actor === null,
        ];

        try {
            return LynomiaPlatformNotice::create($attributes);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return $this->reopen(
                LynomiaPlatformNotice::where('dedupe_key', $dedupeKey)->firstOrFail(),
                $attributes
            );
        }
    }

    /**
     * A ROW THAT PROVES NOTHING WAS SENT MAY BE TRIED AGAIN. The others may not.
     *
     * `failed` is the only state where the message demonstrably never left: either the
     * merchant could not be addressed, or Lynomia refused outright. `sent` speaks for
     * itself, `sending` and `indeterminate` mean a message may be in front of the merchant
     * already, and nothing downstream would recognise a repeat.
     *
     * This is not a nicety. There is no verified merchant phone number on this installation
     * -- the blocker register has it -- so `failed` with `no_phone_on_record` is the EXPECTED
     * first outcome for most merchants. Without this, fixing the number afterwards would
     * leave the occasion permanently unsendable, because the row for it already exists.
     *
     * The status move is conditional for the same reason as the one in `deliver`: two
     * attempts arriving together must not both come away holding a pending row.
     */
    private function reopen(LynomiaPlatformNotice $notice, array $attributes): LynomiaPlatformNotice
    {
        $reopened = LynomiaPlatformNotice::whereKey($notice->getKey())
            ->where('status', LynomiaPlatformNotice::FAILED)
            ->update(['status' => LynomiaPlatformNotice::PENDING]);

        if ($reopened === 0) {
            return $notice->refresh();
        }

        // The recipient and the parameters are re-resolved, because what failed last time is
        // exactly what may have changed since.
        $notice->refresh();
        // The outcome goes with the failure reason: a row being tried again must not still be
        // showing the answer the previous attempt got.
        $notice->forceFill(array_merge($attributes, [
            'failure_reason' => null,
            'delivery_outcome' => null,
            'delivery_reason' => null,
            'delivery_outcome_at' => null,
        ]))->save();

        return $notice;
    }

    private function deliver(LynomiaPlatformNotice $notice, array $recipient): LynomiaPlatformNotice
    {
        // THE SEND CLAIM, and the reason it is an UPDATE with a WHERE rather than a save().
        // The unique index gives one row per occasion; it cannot give one SEND per row,
        // because the attempt that loses the index re-reads the winner's row and can find it
        // still `pending`. Whoever moves `pending` to `sending` owns the attempt.
        //
        // Moved BEFORE the call for the other reason too: a crash between here and the
        // answer leaves `sending`, which is the honest state for "we do not know", not
        // `pending`, which would invite a retry.
        $claimed = LynomiaPlatformNotice::whereKey($notice->getKey())
            ->where('status', LynomiaPlatformNotice::PENDING)
            ->update(['status' => LynomiaPlatformNotice::SENDING]);

        if ($claimed === 0) {
            return $notice->refresh();
        }

        $notice->refresh();

        try {
            $handed = $this->workspace->sendTemplate(
                $recipient['phone'],
                $recipient['name'],
                $notice->template_name,
                $notice->template_language,
                $notice->template_params ?? []
            );

            $notice->forceFill([
                // `SENT` here has always meant "Lynomia accepted it", never "the merchant
                // received it" -- Lynomia answers 200 when the message row exists, and its
                // own gates run afterwards. What it went on to do arrives through the
                // delivery feed and lands in `delivery_outcome`, which is a separate column
                // for exactly that reason.
                'status' => LynomiaPlatformNotice::SENT,
                'lynomia_message_id' => $handed['message_id'],
                // Kept so this notice can be reconciled if its delivery events are lost:
                // Lynomia's read endpoints are addressed by conversation, not by message.
                'lynomia_conversation_id' => $handed['conversation_id'],
                'delivery_outcome' => DeliveryOutcome::ACCEPTED,
                'sent_at' => now(),
            ])->save();
        } catch (LynomiaApiException $e) {
            // A REFUSAL IS SETTLED; A TRANSPORT FAILURE IS NOT. Lynomia answering "no" means
            // nothing was sent and the same answer is coming next time. A connection that
            // died may have delivered the message anyway, and nothing downstream would
            // recognise a repeat.
            $notice->forceFill([
                'status' => $e->isTransient() ? LynomiaPlatformNotice::INDETERMINATE : LynomiaPlatformNotice::FAILED,
                'failure_reason' => Str::limit('kind_' . $e->kind, 191, ''),
                // A transport failure is the one case where nobody knows: the request may or
                // may not have arrived. `unknown` says that; `failed` would claim more.
                'delivery_outcome' => $e->isTransient()
                    ? DeliveryOutcome::UNKNOWN
                    : DeliveryOutcome::FAILED,
            ])->save();
        }

        $this->audit($notice);

        return $notice->refresh();
    }

    /**
     * A merchant who cannot be reached is recorded as such rather than silently skipped: the
     * commonest reason is that Store Syria has no usable phone number for them, and that is
     * a fact an operator needs to see rather than an absence of rows.
     *
     * Nothing was sent, so `reopen` will let the same occasion be tried again once whatever
     * made them unreachable is fixed.
     */
    private function markRefused(LynomiaPlatformNotice $notice, string $reason): LynomiaPlatformNotice
    {
        $notice->forceFill([
            'status' => LynomiaPlatformNotice::FAILED,
            'failure_reason' => $reason,
            'recipient_user_id' => null,
            'recipient_phone' => '',
            // REFUSED BY POLICY, AND SAID SO IN THE OUTCOME COLUMN TOO.
            //
            // Lynomia never saw this one -- there is no verified merchant to address it to,
            // so nothing was handed over and no delivery event can ever arrive for it. The
            // outcome is therefore settled here or nowhere, and leaving it null would put the
            // single most common real case (B4: a merchant whose number nobody has confirmed)
            // in the one state the console reads as "we do not know yet".
            //
            // No `outcome_at_us`: that column orders the delivery feed against itself, and a
            // notice with no message on the far side has no feed to order against.
            'delivery_outcome' => DeliveryOutcome::REFUSED,
            'delivery_reason' => Str::limit($reason, 64, ''),
            'delivery_outcome_at' => now(),
        ])->save();

        $this->audit($notice);

        return $notice;
    }

    private function audit(LynomiaPlatformNotice $notice): void
    {
        AuditLog::record('lynomia_platform_notice', $notice, [], [
            'store_id' => $notice->store_id,
            'kind' => $notice->kind,
            'template' => $notice->template_name,
            'status' => $notice->status,
            'failure_reason' => $notice->failure_reason,
            // The number is on the row for the operator; it is not copied into the trail.
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
