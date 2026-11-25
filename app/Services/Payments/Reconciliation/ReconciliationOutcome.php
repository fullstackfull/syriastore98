<?php

namespace App\Services\Payments\Reconciliation;

/**
 * WHAT HAPPENED TO ONE INITIATED PAYMENT WHEN IT WAS RECONCILED. Named outcomes, and not a bool.
 *
 * `GatewayReference::claim()` is this repository's own record of what a boolean costs on a money
 * path: it returned `true` on four different paths that did not mean the same thing. So every
 * outcome here has a name, and the two questions a caller actually has -- "does a person need to
 * look at this?" and "is it worth asking again?" -- are methods rather than something each caller
 * re-derives from the name.
 *
 * | outcome | money | written by an applying run | a person must look | ask again |
 * |---|---|---|---|---|
 * | `SETTLED` | provider confirms, figures match | plan order, entitlement, audit row | no | no |
 * | `WOULD_SETTLE` | provider confirms, figures match | nothing -- it was a dry run | no | -- |
 * | `ALREADY_SETTLED` | settled through another door first | nothing | no | no |
 * | `NOT_CAPTURED` | provider answers, and it is not a capture | nothing | no | yes |
 * | `UNVERIFIABLE` | no authoritative answer was obtained | nothing | no | yes |
 * | `NO_VERIFIER` | this provider cannot be asked | nothing | unknowable from here | no |
 * | `DISCREPANCY` | provider confirms a capture that cannot be matched to the intent | the reason, an audit row | **yes** | no |
 * | `SETTLEMENT_FAILED` | confirmed and matching, but the settlement raised | nothing -- it was rolled back | **yes** | yes |
 *
 * `NO_VERIFIER` IS NOT COUNTED AS "NEEDS A PERSON", AND THAT IS A JUDGEMENT WORTH STATING. Whether
 * money moved is genuinely unknown -- the provider cannot be asked. But the providers in that
 * position (Cashfree, Mollie) have a working browser return, so their `pending` intents are
 * overwhelmingly checkouts somebody abandoned, and a report that flags every one of them as an
 * emergency is a report nobody reads by the second week. They are counted and listed as their own
 * bucket, in their own words, and they do not turn the run red.
 *
 * `NOT_CAPTURED` IS RETRYABLE BY DESIGN. A provider's "not successful" includes "not successful
 * YET" -- Paytm reports `PENDING` while a bank has not confirmed -- so a run writes nothing terminal
 * on it. The intent stays exactly as it was and the next run asks again.
 */
final class ReconciliationOutcome
{
    public const SETTLED = 'settled';
    public const WOULD_SETTLE = 'would_settle';
    public const ALREADY_SETTLED = 'already_settled';
    public const NOT_CAPTURED = 'not_captured';
    public const UNVERIFIABLE = 'unverifiable';
    public const NO_VERIFIER = 'no_verifier';
    public const DISCREPANCY = 'discrepancy';
    public const SETTLEMENT_FAILED = 'settlement_failed';

    /** The intent is not `pending`, so it is not this surface's to act on. No provider was asked. */
    public const NOT_ELIGIBLE = 'not_eligible';

    /*
     * WHICH discrepancy. A `DISCREPANCY` is recorded differently depending on what it is -- an
     * amount that does not match is a fact that will not change and closes the intent, exactly as
     * the browser return closes it; the others keep the intent open with their reason -- so the
     * distinction is a code a caller branches on, never a sentence it parses.
     */
    public const AMOUNT = 'amount';
    public const CURRENCY = 'currency';
    public const NO_TRANSACTION_ID = 'no_transaction_id';
    public const PURCHASER_MISSING = 'purchaser_missing';
    public const PLAN_MISSING = 'plan_missing';
    public const TRANSACTION_ALREADY_RECORDED = 'transaction_already_recorded';

    /**
     * THE PURCHASER'S ENTITLEMENT HAS MOVED ON SINCE THIS PAYMENT WAS INITIATED, so settling it now
     * would not be recording a purchase -- it would be CHANGING what they currently hold.
     *
     * Found by the independent financial review, confirmed by execution three ways: an intent three
     * days old for a cheaper plan DOWNGRADED a buyer who had since bought a dearer one and switched
     * off two of their three stores; a purchase an operator had already granted by hand was granted
     * again and the expiry pushed out a month; and two captured intents of one purchaser both
     * settled, recording two sales for one month of service.
     *
     * `assignPlan()` SETS the plan and recomputes the expiry from NOW -- it does not accumulate --
     * so "apply the payment late" and "give them what they paid for" are not the same operation
     * once anything else has happened. Whether such a payment should ever be applied unattended is
     * a settlement question and the owner's; this refuses and records instead of deciding.
     */
    public const ENTITLEMENT_MOVED_ON = 'entitlement_moved_on';

    /**
     * @param  array<string,scalar|null>  $evidence  what the run observed, for the report and the audit row
     * @param  ?string  $discrepancy  one of the discrepancy codes above; null for every other kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $reason = '',
        public readonly array $evidence = [],
        public readonly ?string $discrepancy = null,
    ) {
    }

    /** Money the provider says it took, or may have taken, with nothing this run could do about it. */
    public function needsHuman(): bool
    {
        return in_array($this->kind, [self::DISCREPANCY, self::SETTLEMENT_FAILED], true);
    }

    public function isRetryable(): bool
    {
        return in_array($this->kind, [self::NOT_CAPTURED, self::UNVERIFIABLE, self::SETTLEMENT_FAILED], true);
    }

    public function isSettled(): bool
    {
        return $this->kind === self::SETTLED;
    }
}
