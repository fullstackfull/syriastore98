<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PaytmTransactionVerifier;
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
        $providerSays = app(PaytmTransactionVerifier::class)->verify($reference);

        if ($providerSays['state'] !== PaytmTransactionVerifier::VERIFIED) {
            /*
             * UNVERIFIABLE IS A REFUSAL, which is D-31's fail-closed requirement and requirement 8.
             * An unreachable status endpoint, a bad checksum, a malformed response: none of them
             * activates anything. The leftover is surfaced by `unreconciled()` for a human.
             *
             * The refusal is recorded against the intent so a captured payment that cannot be
             * confirmed does not become invisible — the same reasoning as the browser path's.
             */
            PlanPurchaseIntent::recordRefusal(
                'Paytm',
                $reference,
                'callback provider verification ' . $providerSays['state'] . ': ' . $providerSays['reason']
            );

            Log::warning('Paytm plan callback refused: the provider did not confirm the payment.', [
                'state' => $providerSays['state'],
                'reason' => $providerSays['reason'],
                'reference' => $reference,
            ]);

            return response()->json(['status' => 'refused'], 202);
        }

        /* ------------------------------- PROOFS 2, 3 and 4: BINDING, REPLAY and CONCURRENCY ---- */
        [$state, $intent, $reason] = PlanPurchaseIntent::consumeForProviderCallback(
            'Paytm',
            $reference,
            (string) $providerSays['amount'],
            (string) ($providerSays['currency'] ?? ''),
            $providerSays['txn_id'] ?? null
        );

        if ($state !== PlanPurchaseIntent::VERIFIED) {
            if ($state === PlanPurchaseIntent::REFUSED_RECOVERABLE) {
                /*
                 * KEPT OPEN BUT NOT INVISIBLE. The currency comparison is against a platform
                 * DISPLAY label Paytm is never sent and never echoes, so a difference there is a
                 * misconfiguration; closing the intent over it would destroy a captured payment.
                 */
                PlanPurchaseIntent::recordRecoverableRefusal('Paytm', $reference, $reason);
            } else {
                PlanPurchaseIntent::recordRefusal('Paytm', $reference, 'callback: ' . $reason,
                    $providerSays['txn_id'] ?? null,
                    $intent->user_id ?? null);
            }

            Log::warning('Paytm plan callback refused at the intent.', [
                'state' => $state,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            /*
             * 200 FOR AN ALREADY-CONSUMED INTENT, because a provider retrying a delivery it already
             * made is not an error and telling it otherwise invites an endless retry. Any other
             * refusal is 202: accepted, not acted on.
             */
            return response()->json(['status' => $state], 200);
        }

        /* ------------------------------------------------------------------------- ACTIVATION -- */
        $purchaser = User::find($intent->user_id);

        if ($purchaser === null) {
            /*
             * THE INTENT IS ALREADY CONSUMED AT THIS POINT, so this cannot be retried — and that is
             * the correct trade. The alternative is leaving the intent open while a plan may or may
             * not have been activated, which is the ambiguity `unreconciled()` exists to prevent.
             * A deleted purchaser with a captured payment is a human's problem, and it is logged as
             * one rather than guessed at.
             */
            Log::critical('Paytm plan callback: the payment is verified and the intent consumed, but the purchaser no longer exists. The plan was NOT activated and this needs a human.', [
                'reference' => $reference,
                'intent_user_id' => $intent->user_id,
            ]);

            return response()->json(['status' => 'accepted'], 200);
        }

        /*
         * THE EXISTING ENGINE, NOT A COPY. `User::assignPlan()` is what the browser path calls and
         * what every other provider calls. No plan-activation rule is re-expressed here.
         */
        $assigned = $purchaser->assignPlan($intent->plan_id);

        if (empty($assigned['is_success'])) {
            Log::critical('Paytm plan callback: the payment is verified and the intent consumed, but assignPlan refused. This needs a human.', [
                'reference' => $reference,
                'user_id' => $purchaser->id,
                'plan_id' => $intent->plan_id,
                'reason' => $assigned['message'] ?? '',
            ]);

            return response()->json(['status' => 'accepted'], 200);
        }

        Log::info('Paytm plan callback activated a plan.', [
            'reference' => $reference,
            'user_id' => $purchaser->id,
            'plan_id' => $intent->plan_id,
        ]);

        return response()->json(['status' => 'activated'], 200);
    }
}
