<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * F230 — THE CYBERSOURCE CHARGE WAS THE CALLER'S FIGURE AND THE RECORD WAS THE SERVER'S.
 *
 * `CyberSourceController::planPayWithCyberSourceData()` reads its whole context out of one
 * **plaintext, unsigned** hidden field — `resources/views/plans/cybersourcerequest.blade.php:35`
 * renders `<input type="hidden" name="data" value="{{ $data }}">` and the handler does
 * `json_decode($input['data'], true)` at `:103`, taking its plan id from `:107` and, before this
 * fix, its amount from the same blob. There is no `Crypt`, no signature and no MAC on it.
 *
 * **WHY THIS IS WORSE THAN AN ORDINARY CALLER-SUPPLIED AMOUNT, AND IT IS THE WHOLE FINDING:**
 * the handler sends the caller's figure OUTBOUND at `:173` (`"totalAmount" => $amount`), gates on
 * `$result['status'] == "AUTHORIZED"` at `:214` — which only establishes that *its own
 * instruction* succeeded — and then records `serverOwnedPlanPrice()` at `:254`. A previous fix
 * made the RECORD server-owned and left the CHARGE caller-owned, so the two are decoupled by
 * construction: **pay 1 for a 499 plan and `plan_orders` says 499.** The underpayment leaves no
 * trace in the database at all.
 *
 * ## This is not the F229 unit question, and that distinction is why it needs no decision
 *
 * D-31 requires provider-specific amount normalisation built from verified contracts, and forbids
 * guessing minor/major units. **None of that arises here.** Nothing is being *compared* to a
 * figure a provider reported; the handler is choosing what amount to *send*, and it already
 * computes the correct one on the next page of the same method. Making the charge equal the record
 * involves no provider unit convention whatsoever. It is the same rule D-17 settled for Paystack
 * and the same rule F229's row restates: never read the payable amount from the caller.
 *
 * ## What is asserted, and how
 *
 * The outbound call is `\Http::withHeaders(...)->post(...)`, so `Http::fake()` intercepts it and
 * the **request body the handler built** can be read directly. That matters: the real endpoint is
 * unreachable from this container, so asserting on a response would be asserting on the network.
 * Asserting on the payload asserts on the decision.
 *
 * ## Deliberately NOT changed here, and each for a stated reason
 *
 * - **`assertPlanActivationEnabled()` is NOT added.** CyberSource's outbound POST *is* its
 *   verification — `PlanPaymentVerificationInventoryTest:102` pins it as provider-verified with
 *   the `"AUTHORIZED"` sentinel — so it is correctly absent from `config/payments.php`'s
 *   fail-closed map. Adding the gate would disable a provider whose verification is genuine and
 *   contradict that classification.
 * - **the replay guard is NOT added.** `txn_id` is recorded as `time()` (`:256`), not a provider
 *   reference, so there is nothing stable to key a guard on; supplying one means changing what is
 *   recorded, which is a separate slice.
 * - **the six hardcoded `apitest.cybersource.com` sites are NOT touched** (`:158`, `:194`, `:201`,
 *   `:336`, `:372`, `:379`). That is the **sandbox** host, and whether a live installation should
 *   be pointed at production is an owner/operator question, not an engineering one.
 * - **the three routes' missing `auth` middleware is NOT changed** (`routes/customer.php:365-367`).
 *   Recorded; changing a route's middleware is a routing decision and the handler already
 *   dereferences `\Auth::user()` unguarded, so it is effectively session-bound today. (Review
 *   confirmed the mechanism: an anonymous caller is stopped by `$authuser->name`, whose
 *   null-property warning becomes an `ErrorException` the method's own catch absorbs — so no
 *   write and no outbound call, but a 500 rather than a clean refusal.)
 *
 * ## Found by the independent review of THIS change, and left open deliberately
 *
 * - **`coupon_code` is the one money lever the unsigned blob still carries.** The pricing lookup
 *   honours a coupon that is deactivated or limit-exhausted *on purpose*
 *   (`GuardsPlanActivation::findPlanCouponExact($code, false)`) — the rationale is that a coupon
 *   valid when checkout began may be inactive by the time a provider returns, and filtering it
 *   here would recompute a HIGHER price than the server advertised. That reasoning is sound for a
 *   real provider return and weak here, because this step is a POST the caller composes. Since the
 *   discount now drives the charge as well as the record, a caller who knows one real code gets it
 *   repeatedly — and the *recording* lookup requires `is_active = 1`, so for an inactive coupon no
 *   usage row is written and nothing counts against the limit.
 *   **Bounded, and worth stating:** `PlanCouponController` rejects `discount >= 100` on both write
 *   paths, so the price cannot reach zero and there is no free-plan path here. **Not a regression**
 *   — before this change the charge was wholly caller-chosen — and **not closed by it**.
 *   Its proper fix is **D-31 requirement 1**, the server-owned payment intent: binding the data
 *   step to the intent the initiator persisted closes the plan-id lever and the coupon lever
 *   together. A session hack would close it too and would break a merchant with two checkout tabs,
 *   which is why it is deferred to the intent rather than patched here.
 * - **the sibling arm `storePayWithCyberSourceData` still has this defect**, and worse: it takes
 *   `amount`, `user` **and `store_id`** from the same unsigned blob and never inspects the response
 *   at all. `$data['store_id']` is a textbook tenant-predicate-from-caller-input. Its own initiator
 *   supplies no `store_id` key, so the honest storefront flow raises outside the `try` — latent for
 *   real users, drivable by a caller who composes their own blob on an anonymous route. Already
 *   recorded in `E14-GATEWAY-MATRIX-2026-10-31.md:191-196`; named here because this file lists what
 *   it leaves alone and must not appear to have covered it.
 * - **the outbound call is an AUTHORIZATION, not a capture.** It posts to `/pts/v2/payments` with
 *   no `processingInformation.capture`, and no capture call exists anywhere in the repository, so
 *   the plan is activated on an auth hold that lapses. Pre-existing and out of scope, but it
 *   qualifies any claim that "the money is now right": what this change fixes is that the amount
 *   AUTHORISED equals the amount recorded.
 */
class CyberSourceServerOwnedAmountTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    /**
     * THE DEFECT. A posted amount must never become the amount the provider is asked to charge.
     */
    public function test_the_outbound_charge_uses_the_server_price_not_the_posted_one(): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'cybersource_pay_api_key' => 'test-key',
            'cybersource_pay_merchant_id' => 'test-merchant',
            'cybersource_pay_secret_key' => 'test-secret',
            'defult_currancy' => 'USD',
        ]);

        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan('499');

        Http::fake([
            '*' => Http::response(['status' => 'DECLINED'], 200),
        ]);

        $this->actingAs($merchant)->post(route('plan.get.cybersource.status'), [
            'data' => json_encode([
                'id' => $plan->id,
                'amount' => 1,              /* <-- the substitution: 1 unit for a 499 plan */
                'duration' => 'Monthly',
                'coupon_code' => '',
            ]),
            'cardNumber' => '4111111111111111',
            'month' => '12',
            'year' => '2030',
            'owner' => 'A Buyer',
        ]);

        $sent = null;

        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data();

            return true;
        });

        $this->assertNotNull($sent, 'premise: the handler made no outbound call, so there is no payload to judge');

        $charged = $sent['orderInformation']['amountDetails']['totalAmount'] ?? null;

        $this->assertSame(
            '499.00',
            number_format((float) $charged, 2, '.', ''),
            'F230: CyberSource was asked to charge the amount the CALLER posted (' . var_export($charged, true)
            . ') rather than the plan price the server computes. The handler then records '
            . '`serverOwnedPlanPrice()`, so a successful underpayment is written to plan_orders as '
            . 'the FULL price and leaves no trace. The charge and the record must be the same '
            . 'server-owned figure.'
        );
    }

    /**
     * THE POSITIVE CONTROL. A legitimate purchase must still be charged, and charged correctly.
     *
     * Without this, the assertion above is satisfied by a handler that refuses everything or sends
     * a constant — which is the failure mode every guard in this family has had at least once.
     */
    public function test_a_legitimate_purchase_is_still_charged_the_plan_price(): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'cybersource_pay_api_key' => 'test-key',
            'cybersource_pay_merchant_id' => 'test-merchant',
            'cybersource_pay_secret_key' => 'test-secret',
            'defult_currancy' => 'USD',
        ]);

        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan('250');

        Http::fake([
            '*' => Http::response(['status' => 'AUTHORIZED', 'id' => 'cs-ref-1'], 200),
        ]);

        $this->actingAs($merchant)->post(route('plan.get.cybersource.status'), [
            'data' => json_encode([
                'id' => $plan->id,
                'amount' => 250,            /* the honest figure, matching the plan */
                'duration' => 'Monthly',
                'coupon_code' => '',
            ]),
            'cardNumber' => '4111111111111111',
            'month' => '12',
            'year' => '2030',
            'owner' => 'A Buyer',
        ]);

        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data();

            return true;
        });

        $charged = $sent['orderInformation']['amountDetails']['totalAmount'] ?? null;

        $this->assertSame(
            '250.00',
            number_format((float) $charged, 2, '.', ''),
            'a legitimate CyberSource purchase is no longer charged the plan price'
        );

        $this->assertSame(
            1,
            DB::table('plan_orders')->count(),
            'an AUTHORIZED CyberSource purchase no longer records a plan order, so the fix has '
            . 'broken the path it was meant to leave working'
        );

        $this->assertSame(
            '250',
            (string) (int) DB::table('plan_orders')->value('price'),
            'the recorded price disagrees with the amount charged'
        );
    }

    /**
     * A PLAN ID THAT NAMES NOTHING MUST NOT REACH THE CARD NETWORK.
     *
     * `Plan::find($data['id'])` returns null for an id that does not exist, and the handler then
     * dereferences `$plan->id` when building the order — so an unknown id was a fatal after the
     * charge had already been attempted. The refusal belongs before the outbound call: there is no
     * correct amount to charge for a plan that does not exist.
     */
    public function test_an_unknown_plan_id_is_refused_before_any_charge(): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'cybersource_pay_api_key' => 'test-key',
            'cybersource_pay_merchant_id' => 'test-merchant',
            'cybersource_pay_secret_key' => 'test-secret',
            'defult_currancy' => 'USD',
        ]);

        [$merchant] = $this->makeTenant();

        Http::fake();

        $response = $this->actingAs($merchant)->post(route('plan.get.cybersource.status'), [
            'data' => json_encode([
                'id' => 99999999,
                'amount' => 1,
                'duration' => 'Monthly',
                'coupon_code' => '',
            ]),
            'cardNumber' => '4111111111111111',
            'month' => '12',
            'year' => '2030',
            'owner' => 'A Buyer',
        ]);

        /*
         * THE STATUS IS ASSERTED BECAUSE WITHOUT IT THIS TEST CANNOT TELL A REFUSAL FROM A CRASH,
         * and independent review measured exactly that.
         *
         * Remove the guard but keep the rest of the fix and `serverOwnedPlanPrice(Plan $plan, ...)`
         * -- a TYPED parameter -- raises a `TypeError` on a null plan. `TypeError extends \Error`,
         * which the method's `catch (\Exception $e)` does NOT catch, so the request 500s BEFORE the
         * outbound call. `Http::assertNothingSent()` and the zero-row assertion both still pass.
         *
         * So the two assertions below fail against the ORIGINAL pre-fix code (where the caller's
         * amount let the request reach the provider) but do not pin the GUARD, and they accept a
         * 500 as "refused" -- which is the state the guard exists to replace. The status does pin
         * it, and it also pins 403-rather-than-500 and 403-rather-than-redirect so nobody has to
         * re-derive those choices.
         */
        $response->assertForbidden();

        Http::assertNothingSent();

        $this->assertSame(
            0,
            DB::table('plan_orders')->count(),
            'a plan id that names nothing produced a plan order'
        );
    }
}
