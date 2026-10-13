<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TWELVE PLAN-PURCHASE RETURN HANDLERS THAT ACTIVATED A PAID SUBSCRIPTION FOR FREE.
 * =============================================================================================
 *
 * THE SHAPE, identical in all twelve: the handler creates a PlanOrder with
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
 * from this environment. Eleven of the twelve are FAIL-CLOSED -- DISABLED PENDING VERIFICATION,
 * not verified; the twelfth (SenangPay) now has a real cryptographic gate but is still disabled
 * by default because that gate's formula has never been exercised against the provider.
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

        // 403, never 401: a 401 reaching repo B's network layer forces a logout and clears the
        // merchant's stored preferences.
        $response->assertForbidden();

        $this->assertSame(
            $ordersBefore,
            DB::table('plan_orders')->count(),
            $provider . ': a refused return still wrote a plan_orders row. The guard must run '
            . 'BEFORE the PlanOrder is built.'
        );

        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            $provider . ': a refused return still changed the user\'s entitlement. The guard '
            . 'must run BEFORE assignPlan().'
        );
    }

    // =========================================================================================
    //  A GENUINE RETURN STILL COMPLETES -- AND RECORDS THE PLAN'S PRICE, NOT THE URL'S
    // =========================================================================================

    /**
     * Yookassa is absent from this provider set and has its own test below: its handler
     * constructs a YooKassa\Client, and that SDK is not installed in this environment, so the
     * activation path cannot be reached here at all.
     */
    public static function enableableProviders(): array
    {
        $rows = self::forgeableProviders();
        unset($rows['Yookassa (gate is a session flag)']);

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
     * Ozow registers ONE url as cancelUrl, errorUrl, successUrl AND notifyUrl (OzowController
     * :81-104), so the same transaction legitimately arrives twice on every successful payment.
     */
    public function test_ozow_replaying_the_same_transaction_leaves_one_order_and_one_entitlement(): void
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

        $this->actingAs($merchant)->get($url)->assertStatus(302);
        $this->actingAs($merchant)->get($url)->assertStatus(302);

        $this->assertSame(
            1,
            DB::table('plan_orders')->where('plan_id', $plan->id)->count(),
            'Two identical Ozow notifications produced two PlanOrders. A gateway retry must be '
            . 'a no-op, keyed on the provider transaction id.'
        );

        $this->assertSame(
            1,
            DB::table('plan_orders')->where('txn_id', 'OZOW-REPLAY-1')->count(),
            'The provider transaction id was not recorded, so there is nothing to key '
            . 'idempotency on. Upstream wrote time() and then overwrote it with an empty string.'
        );
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
    private const SENANGPAY_SECRET = 'senangpay-test-secret';

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
     * YooKassa's SDK IS NOT INSTALLED HERE, which is why its verification was not implemented.
     *
     * composer.lock pins yoomoney/yookassa-sdk-php 3.7.1, but nothing under vendor/ provides it:
     * no file declares `namespace YooKassa` and vendor/composer/installed.json has no 'yoomoney'
     * entry. `new Client()` at YookassaController:180 therefore raises an Error -- which the
     * handler's `catch (\Exception $e)` does NOT catch.
     *
     * This test pins the fact, not the opinion. If the SDK is installed later, it reddens, and
     * whoever installs it is pointed at the verification that then becomes implementable.
     */
    public function test_yookassa_verification_is_unimplementable_because_its_sdk_is_absent(): void
    {
        $this->assertFalse(
            class_exists(\YooKassa\Client::class),
            'YooKassa\Client now exists. Its payment-info contract is readable, so '
            . 'YookassaController::planGetYooKassaStatus can and should verify the stored '
            . 'payment id before activating -- and config/payments.php can then be re-enabled. '
            . 'Remove this test as part of that work.'
        );
    }
}
