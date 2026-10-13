<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FIFTEEN PLAN-PURCHASE RETURN HANDLERS THAT ACTIVATED A PAID SUBSCRIPTION ON DATA THE
 * CALLER CONTROLS.
 * =============================================================================================
 *
 * THE SHAPE, common to all fifteen but NOT uniform in the price half: the handler creates a
 * PlanOrder with
 * `payment_status = 'success'` and calls assignPlan(), gated on nothing the caller cannot
 * forge -- `status=approved`, `responsecode=0`, `Status=Complete`, an English sentence, a
 * session flag set before payment, or in four cases nothing whatsoever -- and prices the order
 * from the same request. Each gateway's own checkout initiator publishes the replayable return
 * URL, so a merchant who starts ONE legitimate checkout holds every parameter needed.
 *
 * WHAT IS PROVEN HERE, per provider, and why each assertion is the thing that actually differs:
 *
 *   REFUSED WHILE DISABLED. 403 (never 401 -- repo B's lib/network/network.dart turns any 401
 *   into a forced logout that clears preferences), AND no plan_orders row, AND the user's
 *   plan_id unchanged. The status code alone would be a weak assertion; the rows and the
 *   entitlement are what a bypass would move.
 *
 *   STILL WORKS WHEN ENABLED. A genuine return completes and records the PLAN's price even
 *   though the request claims zero -- which is simultaneously the positive proof (legitimate
 *   activation preserved) and the pricing proof (the forged amount is not recorded). The
 *   entitlement is asserted too, so "completed" is not inferred from a redirect.
 *
 *   REPLAY IS A NO-OP. For the two providers that expose a transaction identifier without
 *   guessing a field name off the wire -- Ozow's TransactionId, SenangPay's transaction_id --
 *   two identical calls leave exactly ONE PlanOrder and ONE entitlement.
 *
 * THE CURRENCY FIXTURE IS SEEDED IN THE NEGATIVE TESTS TOO. Without a CURRENCY_NAME setting for
 * the super admin, the completion path dies at $payment_setting['CURRENCY_NAME'] with
 * "Undefined array key" -- so an unguarded handler would 500 rather than complete, and a 403
 * assertion would pass against a path that could not have succeeded anyway. Seeding it means
 * the refusal is the only thing standing between the request and an activated plan, which is
 * what makes these assertions mean anything.
 *
 * WHAT IS NOT CLAIMED. None of this verifies that money moved. No payment provider is reachable
 * from this environment. Fourteen of the fifteen are FAIL-CLOSED -- DISABLED PENDING
 * VERIFICATION, not verified; the fifteenth (SenangPay) now has a real cryptographic gate but
 * is still disabled by default because that gate's formula has never been exercised against
 * the provider.
 *
 * AND THE ORIGINAL DEFECT WAS NOT UNIFORM ACROSS THE FIFTEEN, which an earlier draft of this
 * docblock implied. Three of them already derived the PRICE from server-owned state before
 * this repair: SenangPay took the plan AND the amount from server-side session state, and
 * PayTabs and SSLCommerz likewise owned their prices. For those three the forgeable half was
 * the ACTIVATION GATE alone, never the amount. The security claim is unchanged and is what
 * the assertions below prove -- all fifteen now refuse before any write -- but the shape of
 * what was wrong differed, and saying otherwise overstates it.
 */
class PlanPaymentFailClosedTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    /**
     * Every forgeable provider: [config key, route name, route/query parameters that satisfy its
     * own gate and claim a zero price, the gate that used to be the only thing in the way].
     *
     * The `{PLAN}` placeholder is substituted with the plan id, because a data provider is
     * static and runs before any fixture exists.
     */
    public static function forgeableProviders(): array
    {
        return [
            'Nepalste (no gate at all)' => ['nepalste', 'nepalste.status', [
                'plan' => '{PLAN}', 'get_amount' => 0, 'coupon_code' => '',
            ]],

            // The route is .../{plan_id} but the handler reads $request->plan, which Laravel does
            // NOT populate from a route parameter -- so `plan` is passed in the query here. See
            // test_payhere_cannot_resolve_its_plan_from_the_url_its_own_initiator_builds.
            'PayHere (no gate; md5 is outbound-only)' => ['payhere', 'plan.get.payhere.status', [
                'plan_id' => '{PLAN}', 'plan' => '{PLAN}', 'get_amount' => 0, 'coupon_code' => '',
            ]],

            'PhonePe (no gate)' => ['phonepe', 'plan.get.phonepe.status', [
                'plan' => '{PLAN}', 'amount' => 0, 'coupon_code' => '',
            ]],

            'Paddle (no gate)' => ['paddle', 'plan.get.paddle.status', [
                'plan_id' => '{PLAN}', 'amount' => 0, 'coupon_code' => '',
            ]],

            'FedPay (gate is $request->status)' => ['fedpay', 'plan.get.fedpay.status', [
                'plan_id' => '{PLAN}', 'amount' => 0, 'coupon_code' => '', 'status' => 'approved',
            ]],

            'Easebuzz (gate is an English sentence)' => ['easebuzz', 'plan.get.easebuzz.notify', [
                'plan_id' => '{PLAN}', 'amount' => 0, 'coupon_code' => '',
                'error' => 'Transaction is successful.',
            ]],

            'DPOPay (gate is $request[response][Result])' => ['dpopay', 'plan.get.dpo.status', [
                'plan_id' => '{PLAN}', 'planID' => '{PLAN}', 'amount' => 0, 'coupon_code' => '',
                'order_id' => 'DPO-ORDER-1', 'response' => ['Result' => '0'],
            ]],

            'Yookassa (gate is a session flag)' => ['yookassa', 'plan.get.yookassa.status', [
                'plan_id' => '{PLAN}', 'amount' => 0, 'coupon' => 0, 'order_id' => 'YK-ORDER-1',
            ]],

            'PayTR (gate is $request->payment_status)' => ['paytr', 'pay.paytr.success', [
                'plan_id' => '{PLAN}', 'price' => 0, 'coupon' => 0, 'payment_status' => 'success',
            ]],

            'PaiementPro (gate is $request->responsecode)' => ['paiementpro', 'plan.get.paiementpro.status', [
                'plan_id' => '{PLAN}', 'amount' => 0, 'coupon_code' => '', 'responsecode' => 0,
            ]],

            'Ozow (gate is $request[Status])' => ['ozow', 'plan.get.ozow.status', [
                'plan_id' => '{PLAN}', 'Status' => 'Complete', 'TransactionId' => 'OZOW-TXN-1',
                'amount' => 0, 'coupon_code' => '',
            ]],

            // ----------------------------------------------------------------------------
            // THE THREE BELOW WERE INVISIBLE TO THE AUDIT'S OWN CENSUS and were found by
            // widening it. See PlanPaymentVerificationInventoryTest for the four price-write
            // shapes. They are not unlike the others; they were simply never looked at.
            // ----------------------------------------------------------------------------

            'CoinGate (no gate at all)' => ['coingate', 'coingate.coingate.callback', [
                'plan_id' => '{PLAN}',
            ]],

            // respMessage is the forgeable gate; order_id is what Verify() would have keyed on.
            'PayTabs (verifies, then ignores the result)' => ['paytabs', 'plan.paytabs.callback', [
                'plan_id' => '{PLAN}', 'respMessage' => 'Authorised', 'order_id' => 'PT-ORDER-1',
                'coupon' => 0,
            ]],

            'SSLCommerz (gate is !$request->success == 0)' => ['sslcommerz', 'plan.get.sslcommerz.status', [
                'plan_id' => '{PLAN}', 'success' => 1, 'tran_id' => 'SSL-TRAN-1',
            ]],
        ];
    }

    /** @param array<string,mixed> $params */
    private function withPlanId(array $params, int $planId): array
    {
        foreach ($params as $key => $value) {
            if ($value === '{PLAN}') {
                $params[$key] = $planId;
            }
        }

        return $params;
    }

    // =========================================================================================
    //  REFUSED WHILE THE PROVIDER IS DISABLED
    // =========================================================================================

    #[DataProvider('forgeableProviders')]
    public function test_a_forgeable_provider_refuses_to_activate_a_plan_while_disabled(
        string $provider,
        string $routeName,
        array $params
    ): void {
        $this->seedSuperAdminCurrency();
        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $planBefore   = $merchant->plan_id;
        $ordersBefore = DB::table('plan_orders')->count();

        $response = $this->actingAs($merchant)
            ->get(route($routeName, $this->withPlanId($params, $plan->id)));

        // THE SUBSTANTIVE ASSERTIONS COME FIRST, ON PURPOSE.
        //
        // A status-code assertion can be vacuous -- this project has already shipped one that
        // passed with the defect reintroduced. If assertForbidden() ran first it would shadow
        // these two, and a reviewer reintroducing the defect would see only "expected 403, got
        // 302" and never learn whether a plan had actually been sold for nothing. Asserting the
        // rows and the entitlement first means the failure names the real damage.
        $this->assertSame(
            $ordersBefore,
            DB::table('plan_orders')->count(),
            $provider . ': a refused return still wrote a plan_orders row -- a paid plan was '
            . 'recorded as purchased. The guard must run BEFORE the PlanOrder is built.'
        );

        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            $provider . ': a refused return still changed the user\'s entitlement -- a paid '
            . 'plan was ACTIVATED for free. The guard must run BEFORE assignPlan().'
        );

        // 403, never 401: a 401 reaching repo B's network layer forces a logout and clears the
        // merchant's stored preferences.
        $response->assertForbidden();
    }

    // =========================================================================================
    //  A GENUINE RETURN STILL COMPLETES -- AND RECORDS THE PLAN'S PRICE, NOT THE URL'S
    // =========================================================================================

    /**
     * Yookassa has no positive path here: the owner has RETIRED it, so "it still works when
     * enabled" is not a property anyone wants proven about it. See
     * test_yookassa_is_retired_and_stays_refused below, and
     * Tests\Feature\Payments\YookassaRetiredTest for the initiator.
     */
    public static function enableableProviders(): array
    {
        $rows = self::forgeableProviders();

        // Yookassa: RETIRED by owner decision, so there is deliberately no enabled path to
        // exercise. (The earlier reason given here -- that its SDK is not installed -- was an
        // artifact of one development container, not a fact about the project.)
        unset($rows['Yookassa (gate is a session flag)']);

        // PayTabs calls $this->Verify() -- an outbound Http request -- before its gate, so its
        // positive path needs Http::fake. Bespoke test below.
        unset($rows['PayTabs (verifies, then ignores the result)']);

        // SSLCommerz writes no new PlanOrder; it flips an EXISTING one, so the generic
        // "a row with the plan's price appeared" assertion does not apply. Bespoke test below.
        unset($rows['SSLCommerz (gate is !$request->success == 0)']);

        return $rows;
    }

    #[DataProvider('enableableProviders')]
    public function test_a_genuine_return_still_activates_the_plan_and_records_the_plans_own_price(
        string $provider,
        string $routeName,
        array $params
    ): void {
        $this->seedSuperAdminCurrency();
        $this->enableProvider($provider);

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        // The request claims a zero price throughout `forgeableProviders()`. A genuine return
        // must still complete, and the recorded amount must be the plan's.
        $response = $this->actingAs($merchant)
            ->get(route($routeName, $this->withPlanId($params, $plan->id)));

        $response->assertStatus(302);

        $order = DB::table('plan_orders')->where('plan_id', $plan->id)->first();

        $this->assertNotNull(
            $order,
            $provider . ': an enabled provider no longer records a legitimate plan purchase. '
            . 'The guard has broken the real activation path.'
        );

        // THE THING THAT ACTUALLY DIFFERS. Not the status code -- a redirect is returned on
        // success AND on several failure branches -- but the recorded amount.
        $this->assertEqualsWithDelta(
            499.0,
            (float) $order->price,
            0.009,
            $provider . ': the recorded price is ' . $order->price . ', not the plan\'s 499. '
            . 'The request claimed zero, so this is the forged amount being persisted.'
        );

        $this->assertSame(
            (string) $plan->id,
            (string) $merchant->fresh()->plan_id,
            $provider . ': the plan was recorded but not assigned, so a paying customer gets an '
            . 'invoice and no entitlement.'
        );
    }

    public function test_a_server_validated_coupon_still_discounts_a_genuine_return(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('fedpay');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('400');
        $this->makeCoupon('SAVE25', 25);

        $this->actingAs($merchant)->get(route('plan.get.fedpay.status', [
            'plan_id'     => $plan->id,
            'amount'      => 0,
            'coupon_code' => 'SAVE25',
            'status'      => 'approved',
        ]))->assertStatus(302);

        $order = DB::table('plan_orders')->where('plan_id', $plan->id)->first();

        $this->assertNotNull($order, 'A coupon-bearing genuine return was refused.');

        // 400 less 25% is 300. If the coupon were ignored the recorded price would be 400, and
        // if the request were trusted it would be 0.
        $this->assertEqualsWithDelta(
            300.0,
            (float) $order->price,
            0.009,
            'The coupon discount was not applied server-side. Recorded: ' . $order->price
        );
    }

    /**
     * THE COUPON MUST NOT BE FILTERED ON is_active WHEN PRICING A RETURN.
     *
     * Every gateway's checkout initiator deactivates a coupon once its usage limit is reached.
     * So a coupon that was valid when checkout started is routinely INACTIVE by the time the
     * provider returns. If the pricing lookup filtered on is_active it would recompute the FULL
     * plan price for a customer who was charged the discounted one -- over-recording revenue
     * against a real payment, and (on eSewa and Sofort, which do compare the claimed amount)
     * refusing a paying customer outright.
     */
    public function test_an_exhausted_coupon_still_prices_a_return_at_the_discount_that_was_charged(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('fedpay');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('400');

        $coupon = $this->makeCoupon('SPENT50', 50);
        $coupon->is_active = 0;
        $coupon->save();

        $this->actingAs($merchant)->get(route('plan.get.fedpay.status', [
            'plan_id'     => $plan->id,
            'amount'      => 0,
            'coupon_code' => 'SPENT50',
            'status'      => 'approved',
        ]))->assertStatus(302);

        $order = DB::table('plan_orders')->where('plan_id', $plan->id)->first();

        $this->assertNotNull($order, 'A return bearing an exhausted coupon was refused.');

        $this->assertEqualsWithDelta(
            200.0,
            (float) $order->price,
            0.009,
            'An exhausted coupon was ignored when pricing, so the customer was charged 200 and '
            . 'recorded at ' . $order->price . '. The pricing lookup must not filter is_active.'
        );
    }

    public function test_a_nonexistent_plan_is_a_404_and_not_a_corrupt_order_row(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('paddle');

        $merchant = $this->makeMerchant();

        // Paddle wrote `$plan->name ?? null` and `$plan->id ?? null`, so an unknown plan id
        // persisted a plan_orders row with a NULL plan before dying on $plan->id in assignPlan().
        $this->actingAs($merchant)->get(route('plan.get.paddle.status', [
            'plan_id' => 999999,
            'amount'  => 0,
        ]))->assertNotFound();

        $this->assertSame(
            0,
            DB::table('plan_orders')->count(),
            'An unknown plan id still produced a plan_orders row.'
        );
    }

    // =========================================================================================
    //  REPLAY AND IDEMPOTENCY
    // =========================================================================================

    /**
     * OZOW RETRY: THE SECOND ARRIVAL MUST SUCCEED, NOT ERROR.
     *
     * Ozow registers ONE url as cancelUrl, errorUrl, successUrl AND notifyUrl (OzowController
     * :81-104), so the same transaction legitimately arrives twice on every successful payment.
     *
     * WHAT THIS TEST ASSERTS, AND WHY IT IS NOT THE OBVIOUS THING. The obvious assertion --
     * "exactly one PlanOrder after two calls" -- IS TRUE WITHOUT THE GUARD, and this test was
     * written that way first and PASSED with the guard deleted. The reason is incidental to
     * this one provider: Ozow is the only handler that writes the provider's transaction id
     * into plan_orders.order_id, which is UNIQUE, so the second insert already died on a
     * constraint violation. A measurement, not a guess -- removing the guard and re-running
     * showed one row and a flashed 'Oops something went wrong.'
     *
     * So the row count is kept below only as a regression guard, and the assertion with teeth
     * is the one on the OUTCOME: the duplicate notification for a payment that genuinely
     * succeeded must flash success, not an error. Without the guard the merchant is told their
     * payment failed, on the retry of a payment that worked, because a DB exception was being
     * caught and rewritten as a user-facing error.
     */
    public function test_ozow_retrying_the_same_transaction_succeeds_without_a_second_purchase(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('ozow');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $url = route('plan.get.ozow.status', [
            'plan_id'       => $plan->id,
            'Status'        => 'Complete',
            'TransactionId' => 'OZOW-REPLAY-1',
            'amount'        => 0,
            'coupon_code'   => '',
        ]);

        $this->actingAs($merchant)->get($url)
            ->assertStatus(302)
            ->assertSessionHas('success');

        // THE THING THAT ACTUALLY DIFFERS.
        $second = $this->actingAs($merchant)->get($url);

        $second->assertStatus(302);
        $second->assertSessionMissing('error');
        $second->assertSessionHas(
            'success',
            __('Plan activated Successfully.')
        );

        // Regression guards. Both of these also hold without the guard, for the incidental
        // reason described above; they are here so that a future change which stops writing the
        // transaction id into order_id does not silently remove the backstop as well.
        $this->assertSame(
            1,
            DB::table('plan_orders')->where('plan_id', $plan->id)->count(),
            'Two identical Ozow notifications produced two PlanOrders.'
        );

        $this->assertSame(
            1,
            DB::table('plan_orders')->where('txn_id', 'OZOW-REPLAY-1')->count(),
            'The provider transaction id was not recorded, so there is nothing to key '
            . 'idempotency on. Upstream wrote time() and then overwrote it with an empty string.'
        );

        $this->assertSame((string) $plan->id, (string) $merchant->fresh()->plan_id);
    }

    public function test_ozow_refuses_a_return_with_no_transaction_id_to_key_on(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('ozow');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $this->actingAs($merchant)->get(route('plan.get.ozow.status', [
            'plan_id' => $plan->id,
            'Status'  => 'Complete',
            'amount'  => 0,
        ]))->assertForbidden();

        $this->assertSame(0, DB::table('plan_orders')->count());
    }

    // =========================================================================================
    //  SENANGPAY -- THE ONE PROVIDER WHOSE VERIFICATION IN-REPO EVIDENCE DETERMINES
    // =========================================================================================

    /**
     * The merchant settings App\Models\Senangpay::payment_setting() reads WITHOUT isset(), so
     * all three must exist or it throws -- which the handler treats as a refusal.
     */
    private const SENANGPAY_SECRET = 'senangpay-secret-for-tests-only';
    // The VALUE self-labels deliberately. .github/scripts/scan-secrets.sh is a BLOCKING
    // gate, and its generic-assigned-secret rule flags any quoted literal assigned to a
    // name containing `secret`. The earlier value 'senangpay-test-secret' was flagged and
    // reddened the required check: it is a quoted literal, so the rule's quoted-literal
    // narrowing keeps it, and 'test-secret' does not match the self-labelling narrowing
    // (for[-_]tests?[-_]only|test[-_]only|not[-_]a[-_]real|fixture|dummy|sample).
    // Fixed by changing the VALUE, never by widening the regex or excluding this path --
    // those narrowings exist precisely so a real credential cannot hide behind an
    // exclusion. The constant is referenced only by seedSenangPaySettings() and
    // senangPayReturnHash() in this file, so the rename is self-consistent and the
    // return-hash teeth are unaffected.

    private function seedSenangPaySettings(): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'senang_pay_merchant_id' => '1234',
            'senang_pay_secret_key'  => self::SENANGPAY_SECRET,
            'Senangpay_mode'         => 'sandbox',
        ]);
    }

    /**
     * Reproduce App\Models\Senangpay::generateReturnHash() (app/Models/Senangpay.php:115).
     *
     * Deliberately written out rather than called through the model: if the test computed the
     * expected hash by invoking the very method under test, the assertion would hold for ANY
     * formula and would prove nothing. Writing it independently means a change to that formula
     * reddens this test.
     */
    private function senangPayReturnHash(array $params): string
    {
        return md5(
            self::SENANGPAY_SECRET
            . '?status_id=' . $params['status_id']
            . '&order_id=' . $params['order_id']
            . '&transaction_id=' . $params['transaction_id']
            . '&message=' . $params['message']
            . '&hash=[HASH]'
        );
    }

    private function senangPaySession(int $planId, string $orderId = 'SP-ORDER-1'): array
    {
        return ['senangPaySession' => [
            'order_id' => $orderId,
            'plan_id'  => $planId,
            'price'    => 499,
            'coupon'   => '',
        ]];
    }

    public function test_senangpay_refuses_a_plan_return_whose_hash_does_not_verify(): void
    {
        $this->seedSenangPaySettings();
        $this->enableProvider('senangpay');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        // The session is populated exactly as planPayWithSenangpay leaves it, so the ONLY thing
        // missing is a hash the caller could not compute without the merchant secret. This is
        // the abandoned-checkout attack: status_id=1 with a real session.
        $response = $this->actingAs($merchant)
            ->withSession($this->senangPaySession($plan->id))
            ->get(route('senangpay.call_back', [
                'status_id'      => 1,
                'order_id'       => 'SP-ORDER-1',
                'transaction_id' => 'SP-TXN-1',
                'message'        => 'Payment+was+successful',
                'hash'           => str_repeat('0', 32),
            ]));

        $response->assertForbidden();

        $this->assertSame(
            0,
            DB::table('plan_orders')->count(),
            'A SenangPay return with a bad hash still recorded a purchase.'
        );
    }

    public function test_senangpay_accepts_a_correctly_hashed_return_and_records_the_session_price(): void
    {
        $this->seedSenangPaySettings();
        $this->enableProvider('senangpay');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $params = [
            'status_id'      => 1,
            'order_id'       => 'SP-ORDER-1',
            'transaction_id' => 'SP-TXN-1',
            'message'        => 'Payment successful',
        ];
        $params['hash'] = $this->senangPayReturnHash($params);

        $this->actingAs($merchant)
            ->withSession($this->senangPaySession($plan->id))
            ->get(route('senangpay.call_back', $params))
            ->assertStatus(302);

        $order = DB::table('plan_orders')->where('plan_id', $plan->id)->first();

        $this->assertNotNull(
            $order,
            'A correctly hashed SenangPay return was refused, so the hash gate has broken the '
            . 'legitimate path.'
        );

        // SenangPay's price comes from the session written at checkout, which is the amount the
        // server actually charged -- strictly better than a recompute if the plan price changes
        // mid-flight. It is asserted here so that is not quietly replaced.
        $this->assertEqualsWithDelta(499.0, (float) $order->price, 0.009);

        $this->assertSame(
            'SP-TXN-1',
            $order->txn_id,
            'SenangPay did not record the provider transaction id, so a retry cannot be '
            . 'recognised. Upstream recorded time().'
        );

        $this->assertSame((string) $plan->id, (string) $merchant->fresh()->plan_id);
    }

    public function test_senangpay_replaying_the_same_transaction_leaves_one_order(): void
    {
        $this->seedSenangPaySettings();
        $this->enableProvider('senangpay');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $params = [
            'status_id'      => 1,
            'order_id'       => 'SP-ORDER-1',
            'transaction_id' => 'SP-TXN-REPLAY',
            'message'        => 'Payment successful',
        ];
        $params['hash'] = $this->senangPayReturnHash($params);
        $url            = route('senangpay.call_back', $params);

        // The session is re-seeded on the second call ON PURPOSE. Consuming the session already
        // stops the ordinary browser replay, so without re-seeding this test would pass whether
        // or not the transaction-id guard exists -- it would be asserting the session forget()
        // and nothing else. Re-seeding isolates the provider-transaction idempotency.
        $this->actingAs($merchant)->withSession($this->senangPaySession($plan->id))->get($url)
            ->assertStatus(302);
        $this->actingAs($merchant)->withSession($this->senangPaySession($plan->id))->get($url)
            ->assertStatus(302);

        $this->assertSame(
            1,
            DB::table('plan_orders')->where('plan_id', $plan->id)->count(),
            'Two identical SenangPay callbacks produced two PlanOrders.'
        );
    }

    // =========================================================================================
    //  THE TWO BLIND-SPOT PROVIDERS WHOSE POSITIVE PATH NEEDS ITS OWN SETUP
    // =========================================================================================

    /**
     * PayTabs: a genuine return still completes, and the price recorded is the plan's.
     *
     * Http::fake() stands in for PayTabs. $this->Verify() is called before the gate and posts
     * payment/query, so without a fake this test would attempt a real outbound request. The
     * faked body is shaped the way Verify() reads it, which also documents that contract.
     *
     * NOTE WHAT THIS DOES AND DOES NOT SHOW. It shows the guard has not broken the activation
     * path. It does NOT show the gate is sound -- the gate is still the caller's `respMessage`,
     * which is why PayTabs is on the fail-closed map. Switching the gate to $status['success']
     * is the recorded re-enable condition, and this fake is the harness it would be tested with.
     */
    public function test_paytabs_still_activates_a_genuine_return_when_enabled(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('paytabs');

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response([
                'payment_result' => [
                    'response_status'  => 'A',
                    'response_message' => 'Authorised',
                ],
            ], 200),
        ]);

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $this->actingAs($merchant)->get(route('plan.paytabs.callback', [
            'plan_id'     => $plan->id,
            'respMessage' => 'Authorised',
            'order_id'    => 'PT-ORDER-1',
            'coupon'      => 0,
        ]))->assertStatus(302);

        $order = DB::table('plan_orders')->where('plan_id', $plan->id)->first();

        $this->assertNotNull($order, 'The guard broke PayTabs\' legitimate activation path.');
        $this->assertEqualsWithDelta(499.0, (float) $order->price, 0.009);
        $this->assertSame((string) $plan->id, (string) $merchant->fresh()->plan_id);
    }

    /**
     * SSLCommerz: a genuine return still flips the merchant's own order and assigns the plan.
     *
     * The order has to exist first, because this handler updates rather than inserts -- which is
     * exactly why it was invisible to a census that required a price assignment.
     */
    public function test_sslcommerz_still_completes_a_genuine_return_when_enabled(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('sslcommerz');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        DB::table('plan_orders')->insert([
            'order_id'       => 'SSL-TRAN-OK',
            'name'           => $merchant->name,
            'plan_name'      => $plan->name,
            'plan_id'        => $plan->id,
            'price'          => 499,
            'price_currency' => 'USD',
            'txn_id'         => 'SSL-TRAN-OK',
            'payment_status' => 'pending',
            'payment_type'   => 'SSLCommerz',
            'user_id'        => $merchant->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->actingAs($merchant)->get(route('plan.get.sslcommerz.status', [
            'plan_id' => $plan->id,
            'success' => 1,
            'tran_id' => 'SSL-TRAN-OK',
        ]))->assertStatus(302);

        $this->assertSame(
            'succeeded',
            DB::table('plan_orders')->where('order_id', 'SSL-TRAN-OK')->value('payment_status'),
            'The guard broke SSLCommerz\' legitimate completion path.'
        );

        $this->assertSame((string) $plan->id, (string) $merchant->fresh()->plan_id);

        $this->assertSame(
            1,
            DB::table('plan_orders')->count(),
            'SSLCommerz inserted a second order row; it is supposed to update the existing one.'
        );
    }

    // =========================================================================================
    //  THE FREE-PLAN SHORTCUT IN THE CHECKOUT INITIATOR -- A SHAPE NO EARLIER AUDIT EXAMINED
    // =========================================================================================

    /**
     * Two checkout INITIATORS activated a paid plan from a price the merchant POSTed.
     *
     * Every previous pass over this code examined the RETURN handlers. Neither CoinGate's nor
     * PayTabs' initiator was ever looked at, and both contained a shorter path than any return
     * handler: `$price = $request->total_price` near the top, then `if ($price <= 0)` sixty
     * lines later setting the user's plan, saving, and calling assignPlan(). The shortcut exists
     * so that a 100% coupon need not round-trip through the gateway. With the price read from
     * the request, one authenticated POST with total_price=0 activated any paid plan -- no
     * callback, no forged provider field, and the gateway never contacted at all.
     *
     * PayTabs was the worse of the two: line 94 sets `$request->coupon = 0` unconditionally, so
     * its coupon block never ran and never recomputed, leaving $price ALWAYS equal to the
     * POSTed value.
     *
     * THESE TESTS ARE PAIRED, and the pairing is the point. The negative test alone could pass
     * for the wrong reason -- after the fix a paid plan falls through to the real gateway call,
     * which cannot be reached from this environment, so "no plan was activated" would also be
     * true of a controller that simply threw on the way out. The positive test, on a genuinely
     * FREE plan, proves the shortcut is still live and reachable. Together they say the path
     * works and no longer takes its price from the caller.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function initiatorFreePlanShortcuts(): array
    {
        return [
            'CoinGate initiator' => ['coingate.prepare.plan', 'coingate'],
            'PayTabs initiator'  => ['paytabs.prepare.plan', 'paytabs'],
        ];
    }

    #[DataProvider('initiatorFreePlanShortcuts')]
    public function test_an_initiator_refuses_to_activate_a_paid_plan_from_a_posted_zero_price(
        string $routeName,
        string $provider
    ): void {
        $this->seedSuperAdminCurrency();

        [$merchant] = $this->makeTenant();
        $plan       = $this->makePlan('499');

        $planBefore = $merchant->plan_id;

        $this->actingAs($merchant)->post(route($routeName), [
            'plan_id'     => \Illuminate\Support\Facades\Crypt::encrypt($plan->id),
            'total_price' => 0,
        ]);

        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            $provider . ' initiator: a POSTed total_price of 0 activated a 499 plan. The free-plan '
            . 'shortcut must price from $plan->price, not from the request.'
        );

        $this->assertSame(
            0,
            DB::table('plan_orders')->where('plan_id', $plan->id)->count(),
            $provider . ' initiator: a POSTed total_price of 0 recorded a purchase of a 499 plan.'
        );
    }

    /**
     * THE BRANCH DECISION IS NOW MADE ON SERVER DATA. This is the assertion with teeth.
     *
     * "No plan was activated" (the test above) is necessary but not sufficient: after the fix a
     * paid plan falls through to the real gateway call, which cannot be reached from this
     * environment, so that assertion would also hold for a controller that simply threw on the
     * way out. These two tests assert WHICH BRANCH was taken, and the two branches fail in
     * distinguishable ways:
     *
     *   free-plan shortcut taken  -> dies on `no such column: plan` (see the test below)
     *   gateway path taken        -> dies on `Undefined array key "coingate_mode"`
     *
     * Measured, not assumed. So a paid plan with a POSTed zero price must produce the GATEWAY
     * failure, and a genuinely free plan must produce the SHORTCUT one. Reinstating
     * `$price = $request->total_price` flips both.
     *
     * CoinGate only. PayTabs' initiator bails on "Currency Not Supported" before reaching its
     * `$price <= 0` branch under this fixture, so its branch decision is not observable here and
     * no claim is made about it beyond the refusal asserted above.
     */
    public function test_the_coingate_initiator_sends_a_posted_zero_price_down_the_gateway_path(): void
    {
        $this->seedSuperAdminCurrency();

        [$merchant] = $this->makeTenant();
        $plan       = $this->makePlan('499');

        $this->actingAs($merchant)->post(route('coingate.prepare.plan'), [
            'plan_id'     => \Illuminate\Support\Facades\Crypt::encrypt($plan->id),
            'total_price' => 0,
        ]);

        $error = (string) session('error');

        $this->assertStringNotContainsString(
            'no such column: plan',
            $error,
            'A POSTed total_price of 0 still entered the FREE-PLAN SHORTCUT for a 499 plan. The '
            . 'shortcut is reached only when the price is <= 0, so the price is still being read '
            . "from the request.\n  Observed error: " . $error
        );

        $this->assertSame(
            0,
            DB::table('plan_orders')->count(),
            'A POSTed total_price of 0 recorded a purchase of a 499 plan.'
        );
    }

    /**
     * A GENUINELY FREE PLAN STILL REACHES THE SHORTCUT -- the positive half.
     *
     * It does not COMPLETE, and that is a pre-existing defect this test documents rather than
     * hides: see test_the_free_plan_shortcut_writes_a_users_column_that_no_migration_creates.
     * What it proves is that server-owned pricing has not stolen the shortcut from a plan that
     * really is free -- the branch is still entered when $plan->price is 0.
     *
     * The assertion accepts EITHER outcome on purpose, so that it keeps meaning the same thing
     * once the users.plan question is resolved: the plan is activated (column exists), or the
     * shortcut was entered and died on the missing column. Both say the branch was taken. What
     * it rejects is the branch NOT being taken.
     */
    public function test_the_coingate_initiator_still_reaches_the_shortcut_for_a_genuinely_free_plan(): void
    {
        $this->seedSuperAdminCurrency();

        [$merchant] = $this->makeTenant();
        $plan       = $this->makePlan('0');

        // The request claims 999; the plan is free. The server's figure must decide.
        $this->actingAs($merchant)->post(route('coingate.prepare.plan'), [
            'plan_id'     => \Illuminate\Support\Facades\Crypt::encrypt($plan->id),
            'total_price' => 999,
        ]);

        $error     = (string) session('error');
        $activated = (string) $merchant->fresh()->plan_id === (string) $plan->id;

        $this->assertTrue(
            $activated || str_contains($error, 'no such column: plan'),
            'A genuinely free plan no longer reaches the free-plan shortcut, so server-owned '
            . "pricing has broken a legitimate workflow.\n  Observed error: " . $error
        );
    }

    /**
     * THE FREE-PLAN SHORTCUT IS BROKEN REPO-WIDE, AND NOT BY ANYTHING IN THIS BRANCH.
     *
     * Fifteen call sites across app/Http/Controllers do `$user->plan = $plan->id;` followed by
     * save(). There is no `plan` column on users: the table has plan_id, plan_expire_date,
     * plan_is_active and requested_plan, and NO migration under database/ or packages/ creates
     * one. Every one of those saves therefore throws --
     *   SQLSTATE[HY000]: General error: 1 no such column: plan
     *     (SQL: update "users" set "plan" = 1, ... where "id" = 2)
     * -- which is caught by the enclosing handler and shown as a generic error. So a 100% coupon
     * or a zero-price plan cannot be activated through any of these gateways on this schema.
     *
     * WHY THIS MATTERS FOR THE SEVERITY OF THE INITIATOR DEFECT, and it cuts BOTH ways. The save
     * runs BEFORE assignPlan() at every one of those sites, so on THIS schema the forged
     * zero-price initiator attack also dies there and does not actually activate anything. The
     * fix in CoingateController and PaytabsController is still correct and costs nothing, but it
     * must not be reported as closing a live exploit: on this schema the hole was plugged by
     * accident, by an unrelated missing column. If the production database HAS a `users.plan`
     * column -- plausible for a customised copy of an upstream product whose schema cannot be
     * inspected from here -- then it was live there.
     *
     * NOT FIXED HERE, deliberately: adding a column is a migration, database/ is outside this
     * agent's file set, and whether the right answer is a new column or deleting fifteen stray
     * assignments is an owner decision, not a guess. This test pins the fact so it cannot be
     * rediscovered a fourth time.
     */
    public function test_the_free_plan_shortcut_writes_a_users_column_that_no_migration_creates(): void
    {
        $writeSites = [];

        foreach ((glob(app_path('Http/Controllers') . '/*.php') ?: []) as $file) {
            if (preg_match('/->plan = \$plan->id/', (string) file_get_contents($file))) {
                $writeSites[] = basename($file, '.php');
            }
        }

        $this->assertGreaterThanOrEqual(
            10,
            count($writeSites),
            'The `$user->plan = $plan->id` write sites have mostly disappeared. If they were '
            . 'removed, good -- delete this test and say so. If the scan broke, fix the scan.'
        );

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('users', 'plan'),
            "A `users.plan` column now exists, so the fifteen `\$user->plan = \$plan->id` writes "
            . "in app/Http/Controllers will now SUCCEED.\n\nThat is the resolution of a "
            . "pre-existing defect, and it has a consequence worth checking before this test is "
            . "deleted: those writes sit immediately before assignPlan() inside free-plan "
            . "shortcuts, and until now they threw and stopped execution there. Re-read the "
            . "CoingateController and PaytabsController initiators and confirm the price "
            . "reaching `if (\$price <= 0)` is server-computed, because that branch is now "
            . "actually reachable.\n\nSites: " . implode(', ', $writeSites)
        );
    }

    // =========================================================================================
    //  TWO PRE-EXISTING BREAKS, PINNED SO THEY ARE NOT MISTAKEN FOR THIS WORK
    // =========================================================================================

    /**
     * PayHere's plan return was ALREADY BROKEN for genuine payments, before any of this.
     *
     * planPayWithPayHere builds its successUrl as
     * `route('plan.get.payhere.status', [$plan->id, 'amount' => ..., 'coupon_code' => ...])`
     * (:91-95), which fills the {plan_id} ROUTE parameter -- but planGetPayHereStatus resolves
     * the plan from `$request->plan`, and Laravel does not populate request input from route
     * parameters. So on a real PayHere return the plan is NULL.
     *
     * Upstream then reached `$plan->name` and 500ed. It is now a 404 -- still a failure, but an
     * honest one that writes nothing. This test exists so the 404 is recognised as a PRE-EXISTING
     * contract mismatch in the handler, not as a regression introduced by the guard, and so that
     * whoever re-enables PayHere discovers it here rather than in production.
     */
    public function test_payhere_cannot_resolve_its_plan_from_the_url_its_own_initiator_builds(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('payhere');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        // Exactly what planPayWithPayHere puts in successUrl: plan id positional, no `plan` key.
        $this->actingAs($merchant)->get(route('plan.get.payhere.status', [
            $plan->id,
            'amount'      => 499,
            'coupon_code' => '',
        ]))->assertNotFound();

        $this->assertSame(0, DB::table('plan_orders')->count());
    }

    /**
     * YOOKASSA IS RETIRED, AND BOTH OF ITS PATHS MUST STAY REFUSED.
     *
     * This test used to be called "...its_verification_is_owed_not_impossible" and said the
     * verification was OWED. That is no longer true: the owner has decided YooKassa is not
     * needed, so nothing is owed -- the provider is retired and the refusal is permanent until
     * that decision is reversed.
     *
     * It pins only what is environment-independent: the entry is still on the map, it still
     * ships disabled, and the SDK is still pinned in the lock. It deliberately does NOT assert
     * class_exists() in EITHER direction. An earlier revision asserted the SDK was absent, which
     * was true only inside one development container, and CI -- running a correct install from
     * the lock -- failed on it. That mistake is not to be reintroduced in either polarity.
     *
     * The behaviour itself is proven elsewhere, not here: the return handler by the 403 rows in
     * this file's provider set, the initiator by Tests\Feature\Payments\YookassaRetiredTest.
     */
    public function test_yookassa_is_retired_and_stays_refused(): void
    {
        $shipped = require __DIR__ . '/../../../config/payments.php';

        $this->assertArrayHasKey(
            'yookassa',
            $shipped['plan_activation'],
            'yookassa left the plan_activation map. assertPlanActivationEnabled() aborts on a '
            . 'MISSING key, so this is fail-safe, but the entry records that the provider is '
            . 'RETIRED by owner decision and what reversing that would require, and it must not '
            . 'be dropped silently.'
        );

        $this->assertFalse(
            filter_var($shipped['plan_activation']['yookassa'], FILTER_VALIDATE_BOOLEAN),
            'yookassa now ALLOWS plan activation, but the owner retired this provider. Enabling '
            . 'it reopens both paths at once: an initiator that opens a real checkout with a '
            . 'retired gateway, and a return handler that activates on the strength of having '
            . 'been returned to -- it builds a YooKassa\Client and never queries it.'
        );

        $lock = json_decode(file_get_contents(__DIR__ . '/../../../composer.lock'), true);
        $locked = array_column($lock['packages'], 'version', 'name');

        $this->assertArrayHasKey(
            'yoomoney/yookassa-sdk-php',
            $locked,
            'yoomoney/yookassa-sdk-php left composer.lock. Retiring YooKassa was a decision about '
            . 'the INTEGRATION, not about dependencies: the owner\'s closure decision removes '
            . 'only PayHere\'s dependency, whose pinned commit is gone upstream and which '
            . 'therefore broke `composer install`. This SDK installs fine and stays, so removing '
            . 'it is an unrelated dependency change that was not sanctioned.'
        );
    }
}
