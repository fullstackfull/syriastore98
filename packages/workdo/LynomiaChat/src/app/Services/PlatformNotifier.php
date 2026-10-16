<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * SENDING ONE OPERATIONAL NOTICE TO ONE MERCHANT, ONCE.
 *
 * Store Syria tells merchants about their accounts -- onboarding, a broken integration, a
 * security notice -- from its own Lynomia workspace, reusing Lynomia's WhatsApp channel,
 * its template store and its send path. Nothing here is a second sender: this resolves who
 * may be told, records the intent, and hands the send to Lynomia.
 *
 * "ONCE" IS A DATABASE CONSTRAINT, NOT A CHECK.
 *
 * There is no idempotency mechanism anywhere on Lynomia's send path -- no key on message
 * create, no unique index on messages.source_id, and the campaign guard's own docblock
 * records that re-running a half-sent campaign messages people twice. So the guarantee has
 * to be made here, and under concurrency only the unique index on `dedupe_key` can make it.
 * Two operators acting on the same incident collide on the index; the loser is refused by
 * Postgres rather than by a race somebody has to get right.
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

        [$recipient, $refusal] = $this->recipients->resolve($link);

        if ($refusal !== null) {
            return $this->refused($link, $kind, $templateName, $dedupeKey, $refusal, $actor);
        }

        $notice = $this->claim($link, $kind, $templateName, $language, $dedupeKey, $params, $recipient, $actor);

        // Already said. The claim found an existing row rather than making one, so somebody
        // -- or some earlier attempt -- has been here.
        if ($notice->status !== LynomiaPlatformNotice::PENDING) {
            return $notice;
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
        array $recipient,
        ?User $actor
    ): LynomiaPlatformNotice {
        try {
            return LynomiaPlatformNotice::create([
                'store_id' => $recipient['store_id'],
                'recipient_user_id' => $recipient['user_id'],
                'recipient_phone' => $recipient['phone'],
                'kind' => $kind,
                'template_name' => $templateName,
                'template_language' => $language,
                'template_params' => $params,
                'dedupe_key' => $dedupeKey,
                'status' => LynomiaPlatformNotice::PENDING,
                'requested_by' => $actor?->id,
                'automated' => $actor === null,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return LynomiaPlatformNotice::where('dedupe_key', $dedupeKey)->firstOrFail();
        }
    }

    private function deliver(LynomiaPlatformNotice $notice, array $recipient): LynomiaPlatformNotice
    {
        // Moved BEFORE the call: a crash between here and the answer leaves `sending`, which
        // is the honest state for "we do not know", not `pending`, which would invite a retry.
        $notice->forceFill(['status' => LynomiaPlatformNotice::SENDING])->save();

        try {
            $messageId = $this->workspace->sendTemplate(
                $recipient['phone'],
                $recipient['name'],
                $notice->template_name,
                $notice->template_language,
                $notice->template_params ?? []
            );

            $notice->forceFill([
                'status' => LynomiaPlatformNotice::SENT,
                'lynomia_message_id' => $messageId,
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
            ])->save();
        }

        $this->audit($notice);

        return $notice->refresh();
    }

    /**
     * A merchant who cannot be reached is recorded as such rather than silently skipped: the
     * commonest reason is that Store Syria has no usable phone number for them, and that is
     * a fact an operator needs to see rather than an absence of rows.
     */
    private function refused(
        LynomiaStoreLink $link,
        string $kind,
        string $templateName,
        string $dedupeKey,
        string $reason,
        ?User $actor
    ): LynomiaPlatformNotice {
        $notice = LynomiaPlatformNotice::firstOrNew(['dedupe_key' => $dedupeKey]);

        if ($notice->exists) {
            return $notice;
        }

        $notice->forceFill([
            'store_id' => (int) $link->store_id,
            'recipient_user_id' => null,
            'recipient_phone' => '',
            'kind' => $kind,
            'template_name' => $templateName,
            'template_language' => 'en_US',
            'dedupe_key' => $dedupeKey,
            'status' => LynomiaPlatformNotice::FAILED,
            'failure_reason' => $reason,
            'requested_by' => $actor?->id,
            'automated' => $actor === null,
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
