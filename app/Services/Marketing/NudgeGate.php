<?php

namespace App\Services\Marketing;

use App\Support\Marketing\NudgeDecision;

/**
 * GAP-219 — ONE DOOR THE FOUR NUDGE ENDPOINTS GO THROUGH, so they cannot drift apart.
 * =================================================================================================
 *
 * `POST abandon-carts-messagesend`, `POST abandon-carts-emailsend`,
 * `POST abandon-wishlist-message-send` and `POST abandon-wish-emailsend` each send a shopper a
 * nudge, and each needs the same three steps in the same order. Four copies of those steps is how
 * one of them ends up missing the throttle a year from now -- which is exactly the history of this
 * codebase: `abandon_carts_messsend` answered 403 JSON for a whole release while its sibling
 * `abandon_carts_emailsend` was still redirecting, because the two were fixed separately.
 *
 * THE ORDER IS CADENCE, THEN DUPLICATE, AND IT IS NOT ARBITRARY. A cadence refusal is about the
 * SHOPPER ("messaged too recently") and a duplicate refusal is about the MERCHANT'S OWN ACTION
 * ("you already did this"). Asking the cadence first means a throttled nudge never consumes the
 * idempotency key, so when the quiet period ends the merchant's next click still works. Reserving
 * first would burn the key on a send that never happened, and the nudge would then be permanently
 * unrepeatable -- a throttle that silently becomes a block.
 *
 * A REFUSAL IS RECORDED AND REPORTED. The ledger keeps why nothing arrived, and the decision
 * carries the words for the merchant, because a button that does nothing and says nothing is
 * indistinguishable from a broken one and gets clicked again.
 *
 * IT ABSTAINS RATHER THAN BLOCKS WHEN THE LEDGER IS ABSENT. The migration is approved for
 * development and isolated testing only, so in production the table may not exist yet;
 * `NudgeLedger::reserve()` returns `allow()` in that case and this method does too. Failing closed
 * would silently disable four working merchant features the moment the code ships without the
 * migration, turning a cost control into an outage.
 */
final class NudgeGate
{
    public function __construct(
        private readonly NudgeLedger $ledger,
        private readonly NudgeThrottle $throttle,
    ) {}

    /**
     * May this nudge go out? If yes, it is RESERVED by the time this returns.
     *
     * @param  string  $channel   `whatsapp` | `email` | `telegram`
     * @param  string  $template  the merchant's own template name, e.g. `Abandon Cart`
     * @param  ?string $address   the shopper's phone or e-mail; hashed here and never stored
     * @param  ?string $subjectType  `cart` | `wishlist`
     */
    public function reserve(
        int $storeId,
        string $channel,
        string $template,
        ?string $address,
        ?string $subjectType = null,
        ?int $subjectId = null
    ): NudgeDecision {
        $contactHash = $this->ledger->hashContact($channel, $address);

        /*
         * NO CONTACT, NO GUARD. An empty hash means there is no address to be the same person as,
         * so there is nothing to deduplicate. The handlers refuse a missing address themselves
         * (`blockedReason()` asks for it), and this must not become a second, differently-worded
         * refusal for the same condition.
         */
        if ($contactHash === '') {
            return NudgeDecision::allow();
        }

        $actor = auth()->id();

        $cadence = $this->throttle->check($storeId, $channel, $contactHash);

        if (! $cadence->allowed()) {
            $this->ledger->recordRefusal(
                $storeId, $channel, $template, $contactHash,
                $cadence->outcome(), $subjectType, $subjectId, $actor
            );

            return $cadence;
        }

        /*
         * THE REFUSAL IS NOT RE-RECORDED, and there is deliberately no branch here saying so.
         *
         * An earlier version had `if (! $reservation->allowed()) { return $reservation; } return
         * $reservation;` -- two legs that cannot disagree, which an independent review correctly
         * called out as a check no mutation can break. The fact it was trying to express belongs in
         * a comment, not in control flow: the WINNING reservation is already the row in the ledger,
         * and the loser's refusal adds no fact. "This action happened once" is the whole content,
         * and a second row per double-click would make the ledger's own counts wrong -- which
         * matters, because those counts are what a cadence rule reads.
         */
        return $this->ledger->reserve(
            $storeId, $channel, $template, $contactHash,
            $subjectType, $subjectId, $actor
        );
    }

    /**
     * UNDO A CLAIM WHOSE MESSAGE NEVER LEFT. Call this on every branch that reports a failure
     * after `reserve()` has allowed the send.
     *
     * Without it the guard is strictly worse than the defect it replaces: a double-click costs one
     * extra message, but an unreleased claim costs the nudge FOR EVER. Found by an independent
     * review at the two e-mail handlers, which reserve with no precondition check at all and then
     * discover the SMTP failure afterwards.
     *
     * A no-op for a decision that reserved nothing, so a call site does not have to ask.
     */
    public function release(NudgeDecision $decision): void
    {
        $storeId = $decision->reservedStoreId();
        $key = $decision->reservationKey();

        if ($storeId === null || $key === null) {
            return;
        }

        $this->ledger->release($storeId, $key);
    }
}
