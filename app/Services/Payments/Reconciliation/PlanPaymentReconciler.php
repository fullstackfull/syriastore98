<?php

namespace App\Services\Payments\Reconciliation;

use App\Models\User;
use App\Services\PlanPurchaseIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RECONCILE ONE PREVIOUSLY INITIATED PLAN PAYMENT -- the capability P4-F128 / P5-FIN-4 and Phase 06
 * review finding F13 each recorded as missing.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT WAS MISSING, MEASURED
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Every piece of a reconciliation existed and nothing joined them. `plan_purchase_intents` records
 * what was initiated. `PaytmTransactionVerifier` can ask the provider what became of it.
 * `PlanPurchaseIntent::unreconciled()` lists the purchases that went wrong -- and had NO READER
 * anywhere in `app/`, `routes/` or `resources/views`: the query "the whole design points a human
 * at" pointed at nothing. `app/Console/Commands/` held twelve commands and none about payments.
 *
 * And the one purchase path with full verification could not complete. `plan-paytm-payment` is
 * POST-only inside the session group, so Paytm's cross-site return is answered 419 before `auth`
 * runs (`F241-PAYMENT-RETURN-ARCHITECTURE.md` §1). On an installation with the intent table, a
 * buyer who pays for a plan through Paytm is charged, lands on an error, and is left with an intent
 * that is `pending`, carries no transaction id and no reason -- which is the one shape
 * `unreconciled()` cannot match. A captured payment, byte-identical to an abandoned checkout.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS DOES, AND THE REQUIREMENT EACH STEP ANSWERS
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *   1. **Durable proof of prior initiation.** It acts only on a `plan_purchase_intents` row, which
 *      the SERVER wrote before the buyer left for the provider. It never starts a payment and
 *      never creates an intent, so it cannot be used to transact through anything -- a disabled
 *      application, a suspended account, or otherwise. No row, no reconciliation.
 *   2. **Authoritative provider verification.** `CaptureVerifierRegistry` decides who can be
 *      asked. A provider with no verifier is `NO_VERIFIER` and nothing settles.
 *   3. **Transaction ownership.** The question asked of the provider is the intent's own
 *      server-minted reference; the purchaser, the plan and the price are the intent's, and nothing
 *      a caller supplies chooses any of them.
 *   4. **Amount, currency and state.** `PlanPurchaseIntent::compare()` -- the rule every other door
 *      uses, exact scaled-integer comparison -- and only a `pending` intent is eligible.
 *   5. **Replay, duplicate settlement, concurrency.** The intent's conditional UPDATE, inside the
 *      settlement's transaction; `plan_orders.order_id` UNIQUE on the reference; and a refusal when
 *      the provider's transaction id is already somebody's order. See `PlanPurchaseSettlement`.
 *   6. **Auditable discrepancies.** A capture the provider confirms and this application cannot
 *      match is written onto the intent in the server's own words, so `unreconciled()` surfaces
 *      it, and into `audit_logs`. Once: a second run that finds the same thing writes nothing.
 *   7. **No invented settlement rule.** A matching capture becomes what the paid return already
 *      makes it. A mismatching one is recorded and left for a person. Nothing is refunded,
 *      reversed, credited or guessed.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * A RUN THAT IS NOT TOLD TO APPLY WRITES NOTHING
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `$apply = false` asks the provider and reports what an applying run would do. Not an intent, not
 * an order, not an audit row. That is the default everywhere this is called from, for the reason
 * `config/payments.php` gives for shipping the Paytm callback closed: **no live Paytm transaction
 * has ever been verified from this project.** The orchestration is real and tested; the live
 * handshake is not. So settling is a deliberate act by a person, each time, after they have read
 * what it would do.
 */
final class PlanPaymentReconciler
{
    public function __construct(
        private readonly CaptureVerifierRegistry $verifiers,
        private readonly PlanPurchaseSettlement $settlement,
    ) {
    }

    /**
     * @param  object  $intent  a `plan_purchase_intents` row, as `PlanPurchaseIntent` returns one
     * @param  bool  $apply  false: report only, write nothing. true: settle or record.
     * @param  ?User  $actor  the operator who asked, when there is one
     */
    public function reconcile(object $intent, bool $apply, ?User $actor = null, string $channel = 'console'): ReconciliationOutcome
    {
        $provider = (string) ($intent->provider ?? '');
        $reference = (string) ($intent->reference ?? '');

        if (($intent->status ?? null) !== PlanPurchaseIntent::PENDING) {
            /*
             * ONLY A `pending` INTENT IS ELIGIBLE. A consumed one has been spent and a refused one
             * was closed by a door that recorded why; both stay in `unreconciled()` for a person.
             * This is answered before the provider is asked, so a spent reference costs no call.
             */
            return new ReconciliationOutcome(
                ReconciliationOutcome::NOT_ELIGIBLE,
                'the intent is ' . (string) ($intent->status ?? 'in an unknown state') . ', not pending',
            );
        }

        $verifier = $provider !== '' ? $this->verifiers->for($provider) : null;

        if ($verifier === null) {
            return new ReconciliationOutcome(
                ReconciliationOutcome::NO_VERIFIER,
                'no authoritative verifier exists for this provider, so nothing can be settled from here',
            );
        }

        if (trim($reference) === '') {
            return new ReconciliationOutcome(ReconciliationOutcome::UNVERIFIABLE, 'the intent carries no reference to ask about');
        }

        $answer = $verifier->verify($reference);

        if ($answer->state === CaptureAnswer::UNVERIFIABLE) {
            /* NOTHING IS WRITTEN. The browser return makes this terminal; an unattended run that
               closed a purchase because a status endpoint was unreachable would be destroying the
               thing it exists to recover. Asked again next time. */
            return new ReconciliationOutcome(ReconciliationOutcome::UNVERIFIABLE, $answer->reason);
        }

        if ($answer->state !== CaptureAnswer::VERIFIED) {
            return new ReconciliationOutcome(
                ReconciliationOutcome::NOT_CAPTURED,
                $answer->reason,
                ['provider_txn_id' => $answer->transactionId],
            );
        }

        /* From here the provider CONFIRMS A CAPTURE. Everything that is not a settlement is a
           discrepancy, and a discrepancy is recorded rather than dropped. */
        $outcome = $this->outcomeForConfirmedCapture($intent, $answer, $apply, $actor, $channel);

        if (! $apply) {
            return $outcome;
        }

        if ($outcome->kind === ReconciliationOutcome::DISCREPANCY) {
            $this->recordDiscrepancy($intent, $outcome, $actor, $channel);
        }

        if ($outcome->kind === ReconciliationOutcome::SETTLEMENT_FAILED) {
            /*
             * A VERIFIED, MATCHING CAPTURE THAT COULD NOT BE SETTLED WAS WRITTEN NOWHERE, and the
             * independent financial review measured what that cost: the intent stayed `pending`
             * with a NULL `failure_reason`, so it was absent from `unreconciled()` entirely -- and
             * once it aged past `--days` the run stopped examining it and exited 0. Money the
             * provider confirms, no entitlement, and a green run.
             *
             * Recorded RECOVERABLY, because the settlement rolled back: nothing was granted, the
             * cause may be transient, and the next run should try again. The same idempotence rule
             * as a discrepancy, so a persistent fault does not grow an audit row per run.
             */
            $this->recordDiscrepancy($intent, $outcome, $actor, $channel);
        }

        return $outcome;
    }

    private function outcomeForConfirmedCapture(object $intent, CaptureAnswer $answer, bool $apply, ?User $actor, string $channel): ReconciliationOutcome
    {
        [$comparison, $reason] = PlanPurchaseIntent::compare(
            $intent,
            (string) $answer->amount,
            (string) ($answer->currency ?? '')
        );

        if ($comparison !== PlanPurchaseIntent::VERIFIED) {
            return new ReconciliationOutcome(
                ReconciliationOutcome::DISCREPANCY,
                $reason,
                ['provider_txn_id' => $answer->transactionId, 'reported_amount' => $answer->amount, 'reported_currency' => $answer->currency],
                $comparison === PlanPurchaseIntent::REFUSED_RECOVERABLE ? ReconciliationOutcome::CURRENCY : ReconciliationOutcome::AMOUNT,
            );
        }

        if (! $apply) {
            /* The dry run asks the settlement's own preflight, so "would settle" cannot be said
               about a purchase the settlement would refuse. */
            return $this->settlement->preflight($intent, $answer)
                ?? new ReconciliationOutcome(
                    ReconciliationOutcome::WOULD_SETTLE,
                    'the provider confirms the capture and the figures match the intent',
                    ['provider_txn_id' => $answer->transactionId, 'amount' => $answer->amount],
                );
        }

        return $this->settlement->settle($intent, $answer, $actor, $channel);
    }

    /**
     * WRITE A DISCREPANCY DOWN -- ONCE -- IN THE TWO PLACES A PERSON WILL LOOK.
     *
     * On the intent, through the two writers the browser return already uses, so the handling is
     * that door's and not a new one:
     *
     *   - an AMOUNT that does not match is a fact about the payment and will not change. The return
     *     closes the intent on it (`recordRefusal()`, `status = refused`), and so does this.
     *   - everything else keeps the intent `pending` and writes only the reason
     *     (`recordRecoverableRefusal()`). A currency label, a deleted plan, a missing purchaser or
     *     an order that already carries the transaction are all things a person may put right, and
     *     closing the intent would make a recoverable payment unrecoverable -- the reasoning
     *     `PlanPurchaseIntent::REFUSED_RECOVERABLE` records at length.
     *
     * Both make the row visible to `PlanPurchaseIntent::unreconciled()`.
     *
     * AND IT IS IDEMPOTENT. An amount mismatch moves the intent off `pending`, so the next run does
     * not select it. A recoverable one stays `pending` and WOULD be found again -- so the reason is
     * compared with the one already on the row, and an unchanged finding writes nothing: no second
     * audit row for a discrepancy somebody has already been told about.
     */
    private function recordDiscrepancy(object $intent, ReconciliationOutcome $outcome, ?User $actor, string $channel): void
    {
        $provider = (string) $intent->provider;
        $reference = (string) $intent->reference;

        /*
         * ══════════════════════════════════════════════════════════════════════════════════════
         * IDEMPOTENCE IS KEYED ON A MACHINE-READABLE CODE IN THE REASON, NOT ON ITS SENTENCE.
         * ══════════════════════════════════════════════════════════════════════════════════════
         *
         * The first version compared the whole `failure_reason` with what it was about to write, and
         * the independent financial review found two ways that is not "written once": the callback
         * writes the same finding with a DIFFERENT prefix, so runs interleaved with callback retries
         * wrote a fresh audit row each time; and if the audit insert ever failed, the reason was
         * already on the intent, so every later run returned at that comparison and the audit row
         * was never written again -- for ever.
         *
         * **THE SECOND HALF IS NOW FIXED BY THE TRANSACTION BELOW** rather than by the key: the
         * reason and the audit row commit together, so a failed insert leaves neither and the next
         * run tries again. What the key has to do is only the first half -- recognise THIS
         * reconciliation's own finding -- and a canonical `reconciliation[<code>]:` prefix does
         * that exactly, where a sentence could not.
         *
         * AND IT DELIBERATELY DOES NOT READ `audit_logs`. The draft that did was caught by
         * `AuditAttributionTest::test_no_audit_reader_exists_without_an_authorization_gate`, a
         * ratchet that requires any first-party file reading audit rows to carry an authorization
         * check. This read was narrow and console-only -- keyed on one intent id, returning a
         * boolean, reachable from no request -- but the honest response to that gate is to not need
         * it, not to add the word the regex looks for. A ratchet satisfied by a decoration is worse
         * than one that fired.
         */
        $code = $outcome->discrepancy ?? $outcome->kind;
        $marker = 'reconciliation[' . $code . ']';
        $reason = $marker . ': ' . $outcome->reason;

        if (str_starts_with((string) ($intent->failure_reason ?? ''), $marker)) {
            return;
        }

        /*
         * ONE TRANSACTION. The reason on the intent and the audit row are two halves of one
         * statement: an intent carrying a reason nobody was told about reads as handled, and an
         * audit row with no reason on the intent is invisible to `unreconciled()`.
         */
        try {
            DB::transaction(function () use ($intent, $outcome, $actor, $channel, $provider, $reference, $reason) {
                if ($outcome->discrepancy === ReconciliationOutcome::AMOUNT) {
                    /* An amount that does not match is a fact that will not change, and the browser
                       return closes the intent on it too. */
                    PlanPurchaseIntent::recordRefusal(
                        $provider,
                        $reference,
                        $reason,
                        $outcome->evidence['provider_txn_id'] ?? null,
                        $intent->user_id ?? null
                    );
                } else {
                    PlanPurchaseIntent::recordRecoverableRefusal($provider, $reference, $reason, $intent->user_id ?? null);
                }

                PlanPurchaseSettlement::audit(PlanPurchaseSettlement::AUDIT_DISCREPANCY, $intent, $outcome, $actor, $channel);
            });
        } catch (\Throwable $theRecordFailed) {
            /* Nothing was written, so the next run finds the same thing and tries again -- which is
               the right direction for a record somebody needs. */
            Log::error('Payment reconciliation could not record a discrepancy; nothing was written.', [
                'provider' => $provider,
                'reference' => $reference,
                'outcome' => $outcome->kind,
            ]);
        }
    }

}
