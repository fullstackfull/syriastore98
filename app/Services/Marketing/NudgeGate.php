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

        $reservation = $this->ledger->reserve(
            $storeId, $channel, $template, $contactHash,
            $subjectType, $subjectId, $actor
        );

        if (! $reservation->allowed()) {
            /*
             * NOT RE-RECORDED. The winning reservation is already the row in the ledger, and the
             * loser's refusal adds no fact: "this action happened once" is the whole content. A
             * second row per double-click would make the ledger's own counts wrong, which matters
             * because they are what a cadence rule would read.
             */
            return $reservation;
        }

        return $reservation;
    }
}
