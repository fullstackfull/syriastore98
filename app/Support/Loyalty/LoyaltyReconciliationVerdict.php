<?php

namespace App\Support\Loyalty;

/**
 * `MKT-005` — one customer's reconciliation result. A READING, never an instruction.
 *
 * Readonly, because a verdict that could be edited after it was computed is not evidence. Every
 * figure on it is nullable for the same reason `LoyaltyBalance` returns null rather than zero: a
 * figure nobody could observe must not be reported as a number somebody might act on.
 *
 * @see \App\Support\Loyalty\LoyaltyReconciliation  which computes it, and whose docblock holds the
 *                                                  reasoning for the six statuses
 */
final class LoyaltyReconciliationVerdict
{
    public function __construct(
        /** One of {@see \App\Support\Loyalty\LoyaltyReconciliation}'s status constants. */
        public readonly string $status,
        /** How many movements the ledger holds for this customer, in this store. */
        public readonly int $movements,
        /** The balance the chain arrives at, or null when the chain cannot vouch for one. */
        public readonly ?float $expected = null,
        /** The balance the authoritative column actually holds, or null when unobservable. */
        public readonly ?float $actual = null,
        /** `actual - expected`, or null when either side is unavailable. */
        public readonly ?float $difference = null,
        /** The first row's `balance_before` — what existed before the ledger saw this customer. */
        public readonly ?float $anchor = null,
        /** The id of the movement whose link failed, for a broken chain. */
        public readonly ?int $brokenAt = null,
    ) {
    }

    /**
     * Is this something a human should look at?
     *
     * `NO_HISTORY` and `UNOBSERVABLE` are emphatically NOT findings — they are the ordinary state of
     * a customer who has never moved a point and of every store without the vendored module. See
     * `LoyaltyReconciliation`'s docblock on why conflating them with drift would switch the control
     * off on its first day.
     */
    public function isFinding(): bool
    {
        return in_array($this->status, LoyaltyReconciliation::FINDINGS, true);
    }
}
