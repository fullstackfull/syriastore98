<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * TWO P0 REVENUE BYPASSES ON THE PLAN-PURCHASE RETURN ROUTES.
 *
 * Neither controller belonged to any agent's file set this phase, so neither was repaired by
 * the parallel work; the eSewa one was found by the API/authorization agent and the Sofort one
 * by auditing all 53 assignPlan() callers for the same shape.
 *
 * THE SHAPE, identical in both: success() took the plan AND the price from the query string,
 * hardcoded `payment_status = 'success'`, and called assignPlan() -- with no verification
 * against the provider and no signature. One authenticated GET activated any paid plan for
 * zero. The replayable URL is published by each controller's own addpayment(), so a merchant
 * who starts a single legitimate checkout holds every parameter needed to replay it.
 *
 * Sofort was strictly worse on two counts: it has no `$request->status` guard at all, so the
 * PlanOrder was created unconditionally; and it decrypted `plan_id` into a variable it then
 * DISCARDED, resolving the plan from the plaintext `plan` parameter instead, so the encryption
 * protected nothing.
 *
 * WHAT IS AND IS NOT CLAIMED HERE. These tests prove the zero-cost replay is refused and that
 * a recorded order carries the plan's real price. They prove NOTHING about whether money
 * moved: no payment provider is reachable from this environment, so the gateway contract stays
 * unverified and that finding stays open. A green run here is not a verified integration.
 */
class PlanPaymentBypassTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    // makePlan(), seedSuperAdminCurrency() and enableProvider() now live on
    // PlanPaymentTestCase, so this file and PlanPaymentFailClosedTest cannot drift apart on the
    // currency fixture that decides whether a positive test is real or vacuous.

    // =====================================================================================
    //  eSewa
    // =====================================================================================

    public function test_esewa_refuses_a_return_that_claims_a_zero_amount_for_a_paid_plan(): void
    {
        $this->seedSuperAdminCurrency();
        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $ordersBefore = DB::table('plan_orders')->count();

        $response = $this->actingAs($merchant)->get(route('plan.esewa.payment.success', [
            'plan_id' => $plan->id,
            'plan'    => $plan->id,
            'amount'  => 0,
            'status'  => 'success',
        ]));

        $response->assertForbidden();

        $this->assertSame(
            $ordersBefore,
            DB::table('plan_orders')->count(),
            'A rejected eSewa return still wrote a plan_orders row. The abort must happen '
            . 'BEFORE the order is built.'
        );

        $this->assertDatabaseMissing('plan_orders', ['plan_id' => $plan->id, 'price' => 0]);
    }

    public function test_esewa_records_the_plans_own_price_and_not_the_one_on_the_url(): void
    {
        $this->seedSuperAdminCurrency();
        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        // A legitimate return: the amount is the one this server itself put in the
        // success_url, so the check is a no-op and the purchase proceeds.
        $this->actingAs($merchant)->get(route('plan.esewa.payment.success', [
            'plan_id' => $plan->id,
            'plan'    => $plan->id,
            'amount'  => 499,
            'status'  => 'success',
        ]));

        $order = DB::table('plan_orders')->where('plan_id', $plan->id)->first();

        $this->assertNotNull($order, 'a legitimate eSewa return no longer completes');
        $this->assertEquals(
            499.0,
            (float) $order->price,
            'the recorded price must come from the plan, not from the query string'
        );
    }

    public function test_esewa_refuses_an_amount_that_merely_undercuts_the_plan(): void
    {
        $this->seedSuperAdminCurrency();
        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $this->actingAs($merchant)->get(route('plan.esewa.payment.success', [
            'plan_id' => $plan->id,
            'plan'    => $plan->id,
            'amount'  => 1,
            'status'  => 'success',
        ]))->assertForbidden();

        $this->assertSame(0, DB::table('plan_orders')->where('plan_id', $plan->id)->count());
    }

    // =====================================================================================
    //  Sofort
    // =====================================================================================

    public function test_sofort_refuses_a_return_that_claims_a_zero_amount(): void
    {
        $this->seedSuperAdminCurrency();
        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('799');

        // addpayment() double-wraps: Crypt::encrypt() applied to an already-encrypted value.
        $token = Crypt::encrypt(Crypt::encrypt((string) $plan->id));

        $this->actingAs($merchant)->get(route('plan.payment.success', [
            'plan_id' => $token,
            'plan'    => $plan->id,
            'amount'  => 0,
        ]))->assertForbidden();

        $this->assertSame(
            0,
            DB::table('plan_orders')->where('plan_id', $plan->id)->count(),
            'Sofort has no status guard at all, so a rejected return must be stopped by the '
            . 'amount check before the order is built.'
        );
    }

    public function test_sofort_refuses_a_plaintext_plan_that_disagrees_with_the_signed_token(): void
    {
        $this->seedSuperAdminCurrency();
        $merchant = $this->makeMerchant();
        $cheap    = $this->makePlan('10');
        $dear     = $this->makePlan('999');

        // Token for the cheap plan, plaintext parameter naming the expensive one -- the
        // escalation the discarded decrypt made possible.
        //
        // THE AMOUNT MUST MATCH THE DEAR PLAN, and that is the whole point of this test.
        // An earlier version claimed amount=10 (the cheap plan's price) and was VACUOUS:
        // with the token check disabled the plan resolves to $dear, the amount check then
        // recomputes 999 against a claim of 10, and the 403 arrives from the WRONG guard.
        // Verified by disabling the token check alone -- the test still passed. Claiming
        // the dear plan's own price makes the amount check a no-op, so only the
        // token/plaintext agreement check can refuse this.
        $token = Crypt::encrypt(Crypt::encrypt((string) $cheap->id));

        $this->actingAs($merchant)->get(route('plan.payment.success', [
            'plan_id' => $token,
            'plan'    => $dear->id,
            'amount'  => 999,
        ]))->assertForbidden();

        $this->assertSame(0, DB::table('plan_orders')->where('plan_id', $dear->id)->count());
    }

    public function test_sofort_completes_a_legitimate_return_and_records_the_plan_price(): void
    {
        $this->seedSuperAdminCurrency();
        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('799');

        $token = Crypt::encrypt(Crypt::encrypt((string) $plan->id));

        $this->actingAs($merchant)->get(route('plan.payment.success', [
            'plan_id' => $token,
            'plan'    => $plan->id,
            'amount'  => 799,
        ]));

        $order = DB::table('plan_orders')->where('plan_id', $plan->id)->first();

        $this->assertNotNull($order, 'a legitimate Sofort return no longer completes');
        $this->assertEquals(799.0, (float) $order->price);
    }

    /**
     * A forged token must not 500. Crypt is authenticated, so decryption throws on a bad MAC;
     * that has to surface as a refusal rather than a stack trace.
     */
    public function test_sofort_refuses_a_forged_token_without_a_server_error(): void
    {
        $this->seedSuperAdminCurrency();
        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('799');

        $response = $this->actingAs($merchant)->get(route('plan.payment.success', [
            'plan_id' => 'not-a-real-token',
            'plan'    => $plan->id,
            'amount'  => 799,
        ]));

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'a forged plan token was accepted'
        );
        $this->assertSame(0, DB::table('plan_orders')->where('plan_id', $plan->id)->count());
    }
}
