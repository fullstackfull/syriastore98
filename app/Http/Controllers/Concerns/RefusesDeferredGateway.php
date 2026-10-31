<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Plan;
use Illuminate\Support\Facades\Log;

/**
 * D-33 — BENEFIT AND FLUTTERWAVE ARE DEFERRED, SO A **PAID** PLAN ACTIVATION ON THEM REFUSES.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THE OWNER ASKED FOR, and this is the clause that needs code rather than a document
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * > *"Not activate paid subscriptions without verified payment evidence. Have tested fail-closed
 * > behavior on unsupported or unverified paths."*
 *
 * Both providers verify something, and neither verifies the thing that matters:
 *
 * * **Benefit** gates on `$data->gateway->response->code == '00'` — a property of the tap CHARGE,
 *   which says nothing about WHICH PLAN the money was for. Its return then activates whatever
 *   `$request->plan_id` names, a raw query-string integer.
 * * **Flutterwave** gates on `$response['status'] == 'success'` where `$response` is the
 *   **envelope of the verify CALL** rather than the transaction's own state — so a reachable
 *   endpoint answering at all reads as a successful payment.
 *
 * The fix for both is the plan binding Cashfree and Mollie now have, and it needs a reference the
 * **provider attests**. Establishing that needs each provider's documented response contract, and
 * both documentation hosts are unreachable from this environment (`getaddrinfo ENOTFOUND`).
 * Requirement 8 forbids guessing one. So the owner deferred them, and deferral with the money path
 * left open would be the worst of both: excluded from acceptance AND still activating plans on
 * evidence nobody checked.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY A **PAID** ACTIVATION AND NOT EVERY ACTIVATION
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * A zero-price plan has no payment to verify, so refusing it would protect nothing and would break
 * a path that is already safe — the free-plan branches on both controllers activate without any
 * gateway round trip at all. The refusal is therefore scoped to what the owner's words scope it to:
 * **paid** subscriptions. `serverOwnedPlanPrice()` decides, so a coupon that genuinely zeroes the
 * price still works and a caller cannot talk their way past it with a posted amount.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE COMPATIBILITY AND BUSINESS IMPACT, STATED HERE BECAUSE THE OWNER ASKED FOR IT EXPLICITLY
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **A merchant on an installation configured for Benefit or Flutterwave can no longer buy a paid
 * plan through it.** They are told the method is unavailable and the other configured gateways are
 * unaffected. That is a real loss of function and it is the intended trade: an entitlement granted
 * on unverified evidence is a loss that cannot be undone by a refund, because the platform cannot
 * tell which grants were real.
 *
 * **No money is taken by the refused path** — both refusals land on the RETURN, after the provider
 * may already have captured. So a buyer can be charged and not activated, which is why the refusal
 * is logged at `warning` with the reference: that is a reconciliation signal an operator must act
 * on, exactly like `PlanPurchaseIntent::unreconciled()`. **This introduces no refund behaviour**
 * (D-31 requirement 9); it records and refuses.
 *
 * **Nothing else changes.** Storefront orders through these gateways are untouched — this is the
 * SaaS plan path only. Neither provider is removed, neither setting is deleted, and restoring them
 * is the future task D-33 reserves.
 */
trait RefusesDeferredGateway
{
    /** The two providers D-33 excludes from Phase 02, by the label each controller already uses. */
    public const DEFERRED_GATEWAYS = ['Benefit', 'Flutterwave'];

    /**
     * Refuse a paid plan activation on a deferred gateway. Returns a redirect to refuse with, or
     * null when the activation may proceed.
     *
     * @param  mixed  $plan        the plan being activated
     * @param  mixed  $couponCode  the coupon, so the server decides the price rather than the caller
     * @param  mixed  $reference   the provider's reference, for the operator log only
     */
    protected function refuseDeferredGatewayPaidActivation(string $gateway, $plan, $couponCode = null, $reference = null)
    {
        if (! in_array($gateway, self::DEFERRED_GATEWAYS, true)) {
            return null;
        }

        if (! $plan instanceof Plan) {
            /* No plan, nothing to activate -- let the handler's own refusal answer, so this does
               not mask a different defect with a deferral message. */
            return null;
        }

        /* THE SERVER DECIDES THE PRICE. Never `$request->amount`: that is the substitution vector
           this whole family of findings is about. */
        $price = method_exists($this, 'serverOwnedPlanPrice')
            ? $this->serverOwnedPlanPrice($plan, $couponCode)
            : (float) $plan->price;

        if ((float) $price <= 0) {
            /* A genuinely free plan has no payment to verify. Refusing it would protect nothing. */
            return null;
        }

        Log::warning('Paid plan activation refused: this gateway is deferred from Phase 02 and its payment evidence cannot be verified.', [
            'gateway' => $gateway,
            'plan_id' => $plan->id,
            'user_id' => \Auth::id(),
            'provider_reference' => is_scalar($reference) ? (string) $reference : null,
            'owner_decision' => 'D-33',
            'operator_action' => 'The provider may already have captured. Reconcile manually; no '
                . 'refund is issued by this path.',
        ]);

        return redirect()->route('plan.index')->with(
            'error',
            __('This payment method is not available for paid plans. Please choose another method.')
        );
    }
}
