<?php

namespace App\Http\Controllers;

use App\Services\Payments\Reconciliation\PaytmCaptureVerifier;
use App\Services\Payments\Reconciliation\PlanPurchaseSettlement;
use App\Services\Payments\Reconciliation\ReconciliationOutcome;
use App\Services\PlanPurchaseIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * F241 — THE PROVIDER-AUTHENTICATED PLAN CALLBACK. Paytm only, behind a gate.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE PROBLEM THIS SOLVES, measured and narrow
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `PaytmPaymentController::planPayWithPaytm` already performs the whole secure sequence —
 * provider verification, intent binding, amount comparison, single-consumption — and it is
 * **unreachable by Paytm**. The route is `POST plan-paytm-payment/{plan_id}` inside the `web`
 * group, so Paytm's cross-site POST arrives without the session cookie (`SameSite=Lax`) and
 * without a `_token`, and `VerifyCsrfToken` answers **419 before `auth` is even reached**. All 28
 * exemption patterns were run through `fnmatch()` with `inExceptArray()`'s own semantics: none
 * matches. So the paid Paytm plan path cannot complete at all.
 *
 * The three sibling providers work only because their returns are GET, which `isReading()` skips —
 * and that is not a control either: a forged cross-site GET reaches them just as easily. A session
 * proves who is browsing; it never proved that money moved.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS, AND WHAT IT DELIBERATELY IS NOT
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * It is the §2.2 callback from `F241-PAYMENT-RETURN-ARCHITECTURE.md`: a route **outside the `web`
 * group entirely** — no session, no cookie, no CSRF token to exempt — that authenticates by
 * PROVIDER EVIDENCE and takes every fact about the purchase from the server's own intent record.
 *
 * **IT IS NOT A CSRF EXEMPTION.** The owner's instruction is explicit: *"Do not weaken CSRF for
 * existing session routes."* Nothing about `plan-paytm-payment` changes here — it keeps `auth`,
 * `verified`, `xss` and its CSRF check, and it keeps 419-ing on a cross-site POST. An exemption
 * would have kept a useless control's shape while removing its only effect. This adds a different
 * door with a different and stronger lock.
 *
 * **IT DOES NOT TRUST THE REQUEST.** Nothing in the body or the URL decides anything except WHICH
 * reference to ask Paytm about. The plan, the amount, the currency and the purchaser all come from
 * the intent. A caller who posts a reference they do not own learns nothing and activates nothing.
 *
 * **IT INVENTS NO MONEY BEHAVIOUR.** No refund, no settlement, no stock movement, no new charge.
 * It activates a plan the server already recorded as intended, once, after the provider confirms
 * the payment — and does nothing otherwise.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE FOUR PROOFS THE OWNER REQUIRED BEFORE ANY ROUTE IS EXPOSED
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * | proof | what provides it | where it is demonstrated |
 * |---|---|---|
 * | **authenticity** | `PaytmTransactionVerifier` — a server-to-server `getTxnStatus` over TLS this server validates (`'verify' => true`), with Paytm's own checksum verified over the response by the VENDORED official helper. Not a guessed field name. | `PaytmPlanCallbackTest`, with the checksum computed by `getChecksumFromArray()` itself |
 * | **intent binding** | `PlanPurchaseIntent::consumeForProviderCallback()` — the reference is server-minted, the plan/amount/currency come from the intent, the principal is the intent's `user_id` | same |
 * | **replay resistance** | the intent's single conditional `UPDATE … WHERE status = 'pending'` | same, plus the MariaDB script |
 * | **concurrency safety** | the same conditional UPDATE, raced on a real engine | `verify-paytm-callback-concurrency-on-mysql.php` |
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * AND IT IS OFF BY DEFAULT, WHICH IS NOT TIMIDITY
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `config('payments.paytm_callback_enabled')` gates the route itself, and it opens only on an
 * unambiguous yes — the same strict parse the F217 upload gate uses, because `env()` returns raw
 * strings and `empty('no')` is false, which once opened that gate on the word "no".
 *
 * The reason it ships closed: **no live Paytm transaction has been verified from this container.**
 * There are no Paytm credentials here and no network path to `securegw.paytm.in`, so the one proof
 * that cannot be synthesised — that a real captured payment produces a `VERIFIED` status with a
 * valid checksum — has not been obtained. Every other proof is demonstrated, including the
 * authenticity check against Paytm's own checksum implementation. So the architecture is built and
 * tested and the door stays shut until somebody with a sandbox opens it. **Paytm is not "fixed"
 * and this file does not say it is.**
 */
class PaymentCallbackController extends Controller
{
    /**
     * Paytm's server-to-server plan callback.
     *
     * Answers a bare 200 or 4xx with no body of consequence: a provider needs a status code, and
     * anything else is information a prober could use. Every outcome is logged server-side.
     */
    public function paytmPlan(Request $request)
    {
        if (! config('payments.paytm_callback_enabled')) {
            /*
             * The ROUTE is also gated, so this is belt and braces — but a controller that would
             * activate a plan must not rely on a route file for that, and a 404 here is the same
             * answer an unexposed route gives.
             */
            abort(404);
        }

        /*
         * THE ONLY THING TAKEN FROM THE REQUEST, and it is not trusted — it is the question asked
         * of Paytm, not an assertion accepted from the caller. `ORDERID` is Paytm's own field name
         * for the merchant order reference it was given at initiation.
         */
        $reference = (string) ($request->input('ORDERID') ?? $request->input('orderid') ?? '');

        if (trim($reference) === '' || strlen($reference) > 191) {
            Log::warning('Paytm plan callback refused: no usable order reference.', [
                'length' => strlen($reference),
            ]);

            return response()->json(['status' => 'refused'], 400);
        }

        /* ---------------------------------------------------------------- PROOF 1: AUTHENTICITY */
        /*
         * THROUGH `PaytmCaptureVerifier`, WHICH LOADS THE PLATFORM'S STORED CREDENTIALS FIRST.
         *
         * This called `PaytmTransactionVerifier` directly, and that verifier reads
         * `config('services.paytm-wallet.merchant_key')` -- a key only
         * `PaytmPaymentController::paymentConfig()` ever sets, and only `if (\Auth::check())`. This
         * route has no session by design, so a real callback always met an EMPTY key, was answered
         * `UNVERIFIABLE`, and had a terminal refusal recorded against a payment the provider had
         * captured. `PaytmPlatformCredentials` has the measurement; the adapter is the fix, and it
         * is the same one the reconciliation command uses for the same reason.
         */
        $answer = app(PaytmCaptureVerifier::class)->verify($reference);

        if (! $answer->isVerified()) {
            /*
             * UNVERIFIABLE IS A REFUSAL, which is D-31's fail-closed requirement and requirement 8.
             * An unreachable status endpoint, a bad checksum, a malformed response: none of them
             * activates anything. The leftover is surfaced by `unreconciled()` for a human.
             *
             * The refusal is recorded against the intent so a captured payment that cannot be
             * confirmed does not become invisible — the same reasoning as the browser path's.
             */
            /*
             * RECOVERABLE, NOT TERMINAL -- CORRECTED AFTER INDEPENDENT REVIEW, and the reviewer was
             * right that this was the asymmetry that mattered.
             *
             * This called `recordRefusal()`, which sets `refused` on ANY non-consumed intent and is
             * TERMINAL: the retry dies on `where('status', PENDING)`. It ran with NO PURCHASER SCOPE
             * and before any ownership check, on a reference taken from the request -- so whoever
             * could name a pending reference closed that purchase for good, and an unreachable
             * status endpoint was enough to do it by accident.
             *
             * `PlanPaymentReconciler` already answers the identical provider state by writing
             * nothing, precisely because an answer that could not be obtained says nothing about
             * the money. Two doors disagreeing about the same answer is how a captured payment gets
             * destroyed by the more careless one. This now keeps the intent open with its reason,
             * which `unreconciled()`'s fourth clause surfaces and `payments:reconcile` can finish.
             */
            PlanPurchaseIntent::recordRecoverableRefusal(
                'Paytm',
                $reference,
                'callback provider verification ' . $answer->state . ': ' . $answer->reason
            );

            Log::warning('Paytm plan callback refused: the provider did not confirm the payment.', [
                'state' => $answer->state,
                'reason' => $answer->reason,
                'reference' => $reference,
            ]);

            return response()->json(['status' => 'refused'], 202);
        }

        $intent = PlanPurchaseIntent::find('Paytm', $reference);

        if ($intent === null) {
            /* The same answer the intent itself gives, and for the same reason: saying more would
               tell a prober whether a reference is real. */
            Log::warning('Paytm plan callback refused at the intent.', [
                'state' => PlanPurchaseIntent::UNVERIFIABLE,
                'reason' => 'no intent exists for this provider and reference',
                'reference' => $reference,
            ]);

            return response()->json(['status' => PlanPurchaseIntent::UNVERIFIABLE], 200);
        }

        /* --------------------- PROOFS 2, 3 and 4, AND THE SETTLEMENT: ONE WRITER, ONE TRANSACTION */
        /*
         * `PlanPurchaseSettlement` -- THE SAME WRITER THE RECONCILIATION USES, NOT A SECOND ONE.
         *
         * It consumes the intent through `PlanPurchaseIntent::consumeForProviderCallback()`, so the
         * binding, the exact amount comparison and the single conditional UPDATE are the ones this
         * callback always relied on, and the MariaDB race evidence still covers them. What it adds
         * is what this controller did not do:
         *
         *   - it WRITES THE PLAN ORDER and attaches it to the intent. This activated a plan and
         *     recorded no sale: nothing in `plan_orders`, and an intent left `consumed` with no
         *     order -- which `unreconciled()` reports as a captured payment with nothing to show.
         *   - it is ONE TRANSACTION. This consumed the intent, then looked for the purchaser, then
         *     assigned the plan; a failure after the first step left a spent intent nobody could
         *     retry. Now a failure rolls the consumption back and the reconciliation can finish it.
         *   - it checks the purchaser and the plan BEFORE spending the intent.
         */
        if ($intent->status !== PlanPurchaseIntent::PENDING) {
            /*
             * A PROVIDER RETRYING A DELIVERY IT ALREADY MADE IS THE ORDINARY CASE, NOT AN EMERGENCY.
             *
             * Found by the independent financial review and confirmed by execution: without this,
             * `settle()` ran, its preflight found the plan order the FIRST delivery had written, and
             * control fell through to the terminal arm — which answers `accepted` and logs
             * **CRITICAL "this needs a human"** for a purchase that completed perfectly. A provider
             * that retries daily would have produced one critical alert a day for a working
             * payment, which is how a critical channel stops being read.
             *
             * `PlanPaymentReconciler` already answers a non-pending intent this way
             * (`NOT_ELIGIBLE`, no provider asked). The two doors now agree.
             */
            Log::info('Paytm plan callback re-presented for an intent that is no longer pending; nothing to do.', [
                'reference' => $reference,
                'status' => $intent->status,
            ]);

            return response()->json(['status' => $intent->status], 200);
        }

        $outcome = app(PlanPurchaseSettlement::class)->settle($intent, $answer, null, 'provider-callback');

        if ($outcome->isSettled()) {
            Log::info('Paytm plan callback activated a plan.', [
                'reference' => $reference,
                'user_id' => $intent->user_id,
                'plan_id' => $intent->plan_id,
                'plan_order_id' => $outcome->evidence['plan_order_id'] ?? null,
            ]);

            return response()->json(['status' => 'activated'], 200);
        }

        if ($outcome->kind === ReconciliationOutcome::DISCREPANCY && $outcome->discrepancy === ReconciliationOutcome::AMOUNT) {
            PlanPurchaseIntent::recordRefusal(
                'Paytm',
                $reference,
                'callback: ' . $outcome->reason,
                $answer->transactionId,
                $intent->user_id ?? null
            );

            Log::warning('Paytm plan callback refused at the intent.', [
                'state' => PlanPurchaseIntent::REFUSED_MISMATCH,
                'reason' => $outcome->reason,
                'reference' => $reference,
            ]);

            return response()->json(['status' => PlanPurchaseIntent::REFUSED_MISMATCH], 200);
        }

        if ($outcome->kind === ReconciliationOutcome::DISCREPANCY && $outcome->discrepancy === ReconciliationOutcome::CURRENCY) {
            /*
             * KEPT OPEN BUT NOT INVISIBLE. The currency comparison is against a platform DISPLAY
             * label Paytm is never sent and never echoes, so a difference there is a
             * misconfiguration; closing the intent over it would destroy a captured payment.
             */
            PlanPurchaseIntent::recordRecoverableRefusal('Paytm', $reference, $outcome->reason, $intent->user_id ?? null);

            Log::warning('Paytm plan callback refused at the intent.', [
                'state' => PlanPurchaseIntent::REFUSED_RECOVERABLE,
                'reason' => $outcome->reason,
                'reference' => $reference,
            ]);

            return response()->json(['status' => PlanPurchaseIntent::REFUSED_RECOVERABLE], 200);
        }

        if ($outcome->kind === ReconciliationOutcome::ALREADY_SETTLED) {
            /*
             * 200 FOR AN ALREADY-CONSUMED INTENT, because a provider retrying a delivery it already
             * made is not an error and telling it otherwise invites an endless retry.
             */
            Log::warning('Paytm plan callback refused at the intent.', [
                'state' => PlanPurchaseIntent::REFUSED_MISMATCH,
                'reason' => $outcome->reason,
                'reference' => $reference,
            ]);

            return response()->json(['status' => PlanPurchaseIntent::REFUSED_MISMATCH], 200);
        }

        /*
         * VERIFIED, AND STILL NOT SETTLED: a purchaser or a plan that no longer exists, a
         * transaction that is already somebody's order, or a settlement that raised and was rolled
         * back. The payment is real and nothing was granted, so it needs a person -- and it is
         * written onto the intent, in the server's own words, so `unreconciled()` shows them.
         *
         * THE INTENT IS LEFT `pending`, which is a change and a deliberate one. This used to consume
         * it first and then give up, on the reasoning that an open intent beside a possibly-activated
         * plan is ambiguous. Inside one transaction there is no "possibly": nothing was activated,
         * so nothing is ambiguous, and an intent that is still open is one the reconciliation
         * command can finish the moment the cause is put right.
         */
        if ($outcome->kind === ReconciliationOutcome::ACKNOWLEDGED) {
            /*
             * A PERSON HAS ALREADY DEALT WITH THIS PAYMENT, so this is a working outcome and not a
             * fault. It needs its own arm because an acknowledged intent is still `pending`, so the
             * non-pending short-circuit above does not catch it, and falling through to the terminal
             * arm did two wrong things -- found by independent review:
             *
             *   1. `recordRecoverableRefusal()` OVERWROTE `failure_reason`, destroying the
             *      reconciliation reason that recorded why a person was needed in the first place
             *      and replacing it with the operator's own acknowledgement text.
             *   2. `Log::critical(... needs a human)` fired ONCE PER PROVIDER RETRY for a payment a
             *      human had already resolved -- the same alert fatigue the non-pending
             *      short-circuit above was added to prevent.
             *
             * 200, because the provider's delivery succeeded and a retry would change nothing.
             */
            Log::info('Paytm plan callback: the payment was already dealt with by a person.', [
                'reference' => $reference,
                'intent_user_id' => $intent->user_id,
                'reason' => $outcome->reason,
            ]);

            return response()->json(['status' => ReconciliationOutcome::ACKNOWLEDGED], 200);
        }

        PlanPurchaseIntent::recordRecoverableRefusal(
            'Paytm',
            $reference,
            'callback: ' . $outcome->kind . ': ' . $outcome->reason,
            $intent->user_id ?? null
        );

        Log::critical('Paytm plan callback: the payment is verified but could not be settled. Nothing was activated and this needs a human.', [
            'reference' => $reference,
            'intent_user_id' => $intent->user_id,
            'plan_id' => $intent->plan_id,
            'outcome' => $outcome->kind,
            'reason' => $outcome->reason,
        ]);

        return response()->json(['status' => 'accepted'], 200);
    }
}
