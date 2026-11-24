<?php

namespace App\Support\Marketing;

use Carbon\CarbonInterface;

/**
 * GAP-219 — THE ANSWER TO "MAY THIS NUDGE GO OUT", and the words for a merchant if not.
 *
 * Shaped on `App\Services\Marketing\SendDecision` on purpose: that class is the consent gate's
 * answer and this is the duplication gate's, so the two read the same way at a call site that has
 * to consult both. The difference is what they carry — a consent hold is about the PERSON, a nudge
 * refusal is about something the merchant themselves already did, so this one carries WHEN, because
 * "you sent this 20 seconds ago" is actionable and "blocked" is not.
 *
 * A REFUSAL IS NOT AN ERROR AND MUST NOT BE SILENT. The four nudge endpoints answer through
 * `ApiResponser`-style JSON or a redirect with a flash message; either way the merchant has pressed
 * a button and is owed an answer. A guard that drops the second click without saying so is
 * indistinguishable from a guard that is broken, and a merchant who sees nothing happen clicks
 * again.
 */
final class NudgeDecision
{
    private function __construct(
        private readonly bool $allowed,
        private readonly string $reason,
        private readonly ?CarbonInterface $previousSentAt,
        private readonly ?int $retryAfterSeconds,
        /*
         * GAP-219 FOLLOW-UP — THE RESERVATION THIS DECISION MADE, so the caller can RELEASE it.
         *
         * An allowed decision that came from `NudgeLedger::reserve()` has already written a row
         * claiming the nudge, BEFORE the message was attempted -- which is correct, because the
         * row's job is to stop a second send and a row written only after a successful send leaves
         * the race window open for as long as an HTTP call to a gateway takes.
         *
         * But it means a FAILED send leaves a claim behind, and the key has no clock in it, so
         * nothing ever expires it. Found by an independent review: with SMTP misconfigured, a
         * merchant's first click wrote the row and failed, and every later click was answered
         * "this nudge was already sent" -- for ever. That is worse than the duplicate it prevents.
         *
         * So the decision carries its own store and key, and `NudgeGate::release()` undoes the
         * claim. They are null on a decision that reserved nothing (a refusal, or a caller with no
         * contact), and `release()` is then a no-op.
         */
        private readonly ?int $reservedStoreId = null,
        private readonly ?string $reservationKey = null,
    ) {}

    public static function allow(?int $reservedStoreId = null, ?string $reservationKey = null): self
    {
        return new self(true, 'no_previous_send', null, null, $reservedStoreId, $reservationKey);
    }

    /** The store and key of the claim this decision wrote, or null when it wrote none. */
    public function reservedStoreId(): ?int
    {
        return $this->reservedStoreId;
    }

    public function reservationKey(): ?string
    {
        return $this->reservationKey;
    }

    /**
     * THE EXACT SAME LOGICAL ACTION WAS ALREADY RECORDED. No time window is involved: the key is
     * derived from the action, so this is "you already did this", not "you did this too recently".
     */
    public static function duplicate(?CarbonInterface $previousSentAt): self
    {
        return new self(false, 'duplicate', $previousSentAt, null);
    }

    /**
     * A CADENCE RULE REFUSED IT. Only reachable once an interval has been configured; see
     * `NudgeThrottle`, which leaves that limb off because the number is the owner's to choose.
     */
    public static function throttled(?CarbonInterface $previousSentAt, int $retryAfterSeconds): self
    {
        return new self(false, 'throttled', $previousSentAt, max(0, $retryAfterSeconds));
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function previousSentAt(): ?CarbonInterface
    {
        return $this->previousSentAt;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /** The ledger `outcome` this decision should be recorded as. */
    public function outcome(): string
    {
        return match ($this->reason) {
            'duplicate' => \App\Models\MarketingNudgeSend::OUTCOME_DUPLICATE,
            'throttled' => \App\Models\MarketingNudgeSend::OUTCOME_THROTTLED,
            default => \App\Models\MarketingNudgeSend::OUTCOME_SENT,
        };
    }

    /**
     * What to show the merchant. Bilingual through `__()` like every other merchant-facing string;
     * the previous time is formatted by the caller's own locale via `diffForHumans()`.
     *
     * THE SENTENCE NAMES WHAT HAPPENED AND WHAT TO DO, because the alternative — "Permission
     * denied" or a silent no-op — is what makes a merchant click a third time.
     */
    public function message(): string
    {
        if ($this->allowed) {
            return '';
        }

        if ($this->reason === 'throttled') {
            return __('This shopper was messaged recently, so this nudge was not sent. You can try again later.');
        }

        return $this->previousSentAt === null
            ? __('This nudge has already been sent, so it was not sent again.')
            : __('This nudge was already sent :when, so it was not sent again.', [
                'when' => $this->previousSentAt->diffForHumans(),
            ]);
    }
}
