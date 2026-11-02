<?php

namespace Tests\Feature\Security;

use App\Models\PlanOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * D-33 — BENEFIT AND FLUTTERWAVE ARE DEFERRED, AND A PAID ACTIVATION ON THEM FAILS CLOSED.
 * ========================================================================================
 *
 * The owner's conditions on the deferral, and this file is condition 3:
 *
 * > *"Not activate paid subscriptions without verified payment evidence. **Have tested fail-closed
 * > behavior on unsupported or unverified paths.**"*
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THESE TWO CANNOT BE PROTECTED THE WAY THE OTHER THREE WERE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Paytm, Cashfree and Mollie are bound to a server-owned `PlanPurchaseIntent` whose reference the
 * **provider** attests. These two have no such reference available:
 *
 * * **Benefit** keys its return on `$request->tap_id`, a charge id the server does not know at
 *   initiation. Binding it would mean trusting tap to echo a merchant reference back on retrieval
 *   — a contract with no captured evidence here.
 * * **Flutterwave** gates on `$response['status'] == 'success'` where that is the **envelope of
 *   the verify call**, not the transaction's state.
 *
 * Both official documentation hosts fail DNS from this environment (`getaddrinfo ENOTFOUND`), and
 * requirement 8 forbids guessing a contract. So the owner deferred them — and a deferral that left
 * the money path open would be the worst of both worlds: out of acceptance scope AND still
 * activating plans on evidence nobody checked.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS AND IS NOT REFUSED, which is the whole shape of the control
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Paid** activations refuse. A zero-price plan has no payment to verify, so refusing it would
 * protect nothing and would break a path that is already safe. The price comes from
 * `serverOwnedPlanPrice()` where available and `$plan->price` otherwise — never from the request,
 * which is the substitution vector this family of findings is about.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE REFUSAL IS ON THE RETURN, SO MONEY MAY ALREADY BE CAPTURED — and that is logged
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Both refusals sit on the provider's return, after a capture may have happened. That is a
 * reconciliation signal an operator must act on, so it is logged at `warning` with the provider
 * reference and the owner decision named. **No refund is issued** — D-31 requirement 9 forbids
 * introducing settlement behaviour, and the trait records rather than pays.
 */
class DeferredGatewayFailClosedTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    public static function deferredPaidReturns(): array
    {
        return [
            /* [label, how to call the return for a given plan id] */
            'Benefit' => ['Benefit'],
            'Flutterwave' => ['Flutterwave'],
        ];
    }

    private function callReturn(string $gateway, $merchant, int $planId)
    {
        if ($gateway === 'Benefit') {
            return $this->actingAs($merchant)->get(route('benefit.call_back', [
                'plan_id' => $planId,
                'tap_id' => 'chg_TS0' . uniqid(),
                'amount' => 499,
            ]));
        }

        /* Flutterwave's return takes the payment id and a Crypt'd plan in the path. */
        /* The route is spelled `plan-flaterwave-payment` -- WorkDo's own typo, kept because
           renaming a live route is a compatibility change outside this slice. */
        return $this->actingAs($merchant)->get(route('plan.flaterwave', ['txref' => uniqid(), 'plan_id' => Crypt::encrypt($planId)]));
    }

    /**
     * THE CONTROL. A paid plan is not activated, and nothing is recorded as a sale.
     *
     * **THIS CASE CANNOT DISTINGUISH THE DEFERRAL ON ITS OWN, and that is measured rather than
     * assumed.** Disabling the guard entirely leaves it GREEN, because neither gateway is
     * reachable from this container: the handler's own outbound call fails, lands in its catch, and
     * activates nothing either way. It is the same trap `PlanActivationReplayGuardTest` was
     * rewritten once to escape.
     *
     * So the distinguisher is `test_the_refusal_is_logged_for_reconciliation` — a line only the
     * deferral writes, which a network failure cannot produce. Both cases are kept: this one
     * asserts the OUTCOME that matters (no entitlement, no recorded sale, whatever the cause), and
     * the log case proves the deferral is what produced it.
     *
     * @dataProvider deferredPaidReturns
     */
    public function test_a_paid_activation_on_a_deferred_gateway_is_refused(string $gateway): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'benefit_secret_key' => 'sk_test_not_a_real_key',
            'flutterwave_secret_key' => 'not-a-real-flutterwave-key',
        ]);

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');

        $this->callReturn($gateway, $merchant, $plan->id);

        $this->assertNull(
            $merchant->fresh()->plan_id,
            $gateway . ': A PAID PLAN WAS ACTIVATED on a gateway whose payment evidence cannot be '
            . 'verified. D-33 condition 2 forbids exactly this.'
        );

        $this->assertSame(
            0,
            (int) DB::table('plan_orders')->count(),
            $gateway . ': a plan order was recorded for a payment nothing verified'
        );
    }

    /**
     * AND THE REFUSAL IS REPORTABLE, because the provider may already have captured.
     *
     * @dataProvider deferredPaidReturns
     */
    public function test_the_refusal_is_logged_for_reconciliation(string $gateway): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'benefit_secret_key' => 'sk_test_not_a_real_key',
            'flutterwave_secret_key' => 'not-a-real-flutterwave-key',
        ]);

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');

        $this->callReturn($gateway, $merchant, $plan->id);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains(
                (string) $message,
                'this gateway is deferred from Phase 02'
            ) && ($context['gateway'] ?? null) === $gateway)
            ->atLeast()->once();
    }

    /**
     * A GENUINELY FREE PLAN IS NOT REFUSED — the refusal is scoped to what the owner scoped it to.
     *
     * There is no payment to verify on a zero-price plan, so refusing it would protect nothing and
     * would break a path that never involved the gateway. The price is decided by the server, so a
     * caller cannot reach this branch by posting an amount.
     *
     * @dataProvider deferredPaidReturns
     */
    public function test_a_zero_price_plan_is_not_refused_by_the_deferral(string $gateway): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'benefit_secret_key' => 'sk_test_not_a_real_key',
            'flutterwave_secret_key' => 'not-a-real-flutterwave-key',
        ]);

        $merchant = $this->makeMerchant();
        $free = $this->makePlan('0');

        $this->callReturn($gateway, $merchant, $free->id);

        /*
         * The deferral must NOT be what stopped this. Whether the free plan then activates depends
         * on the handler's own unreachable-gateway path, which is not what this case is about --
         * so the assertion is on the refusal's absence from the log, which only the deferral
         * writes.
         */
        Log::shouldNotHaveReceived('warning', [
            \Mockery::on(fn ($message) => str_contains((string) $message, 'this gateway is deferred from Phase 02')),
            \Mockery::any(),
        ]);
    }

    // =====================================================================================
    //  D-33 OWNER CORRECTION — NO CHARGE MAY BE INITIATED AT ALL
    // =====================================================================================

    private function initiate(string $gateway, $merchant, int $planId, ?string $coupon = null)
    {
        $body = array_filter(['plan_id' => Crypt::encrypt($planId), 'coupon' => $coupon]);

        return $gateway === 'Benefit'
            ? $this->actingAs($merchant)->post(route('benefit.initiate'), $body)
            : $this->actingAs($merchant)->post(route('flutterwave.payment'), $body);
    }

    /**
     * THE CONTROL THAT MATTERS: the provider is NEVER CONTACTED for a paid plan.
     *
     * The owner's correction, and it was right. The first version of this deferral refused only on
     * the RETURN — so a merchant reached the gateway, was charged, came back, and was told the
     * method was unavailable. Money taken, nothing given, and a manual refund nobody had been told
     * to make.
     *
     * `Http::preventStrayRequests()` is the assertion, not a convenience: if either initiation
     * still reaches out, the test fails on the stray request rather than on a status code. Benefit
     * uses Guzzle directly so `Http::fake()` cannot intercept it — which is why the structural
     * ordering assertion below is kept as well, and why this case also asserts no plan order and
     * no entitlement.
     *
     * @dataProvider deferredPaidReturns
     */
    public function test_a_paid_checkout_cannot_be_initiated_on_a_deferred_gateway(string $gateway): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'benefit_secret_key' => 'sk_test_not_a_real_key',
            'flutterwave_secret_key' => 'not-a-real-flutterwave-key',
        ]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');

        $response = $this->initiate($gateway, $merchant, $plan->id);

        /* Refused, and sent somewhere a merchant can act -- never a 401, which the shipped app
           maps to a forced logout. */
        $this->assertNotSame(401, $response->getStatusCode());
        $this->assertTrue(
            $response->isRedirect(),
            $gateway . ': the paid checkout was not refused with a redirect (HTTP '
            . $response->getStatusCode() . ')'
        );

        $this->assertNull(
            $merchant->fresh()->plan_id,
            $gateway . ': a plan was activated by the initiation itself'
        );
        $this->assertSame(
            0,
            (int) DB::table('plan_orders')->count(),
            $gateway . ': a plan order was written for a checkout that must never have started'
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains(
                (string) $message,
                'refused before any charge'
            ) && ($context['gateway'] ?? null) === $gateway)
            ->atLeast()->once();
    }

    /**
     * AND A FREE PLAN STILL CHECKS OUT — the refusal is scoped to paid, as the owner scoped it.
     *
     * @dataProvider deferredPaidReturns
     */
    public function test_a_free_plan_checkout_is_not_blocked(string $gateway): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'benefit_secret_key' => 'sk_test_not_a_real_key',
            'flutterwave_secret_key' => 'not-a-real-flutterwave-key',
        ]);

        $merchant = $this->makeMerchant();
        $free = $this->makePlan('0');

        $this->initiate($gateway, $merchant, $free->id);

        Log::shouldNotHaveReceived('warning', [
            \Mockery::on(fn ($message) => str_contains((string) $message, 'refused before any charge')),
            \Mockery::any(),
        ]);
    }

    /**
     * A COUPON CANNOT TALK ITS WAY PAST THE BLOCK, and this is the adversarial case.
     *
     * The price is decided by the server. A caller who posts a coupon that does NOT zero the price
     * is still buying a paid plan, so the refusal must hold — and a caller cannot reach the
     * free-plan exemption by naming a coupon that does not exist, or one whose discount is
     * partial.
     *
     * @dataProvider deferredPaidReturns
     */
    public function test_a_partial_or_unknown_coupon_does_not_unblock_a_paid_checkout(string $gateway): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'benefit_secret_key' => 'sk_test_not_a_real_key',
            'flutterwave_secret_key' => 'not-a-real-flutterwave-key',
        ]);

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');
        $this->makeCoupon('HALFOFF', 50, 5);

        foreach (['HALFOFF', 'NO-SUCH-COUPON'] as $coupon) {
            Log::swap(\Mockery::spy(\Illuminate\Log\LogManager::class));

            $this->initiate($gateway, $merchant, $plan->id, $coupon);

            Log::shouldHaveReceived('warning')
                ->withArgs(fn ($message, $context = []) => str_contains(
                    (string) $message,
                    'refused before any charge'
                ))
                ->atLeast()->once();

            $this->assertNull(
                $merchant->fresh()->plan_id,
                $gateway . ': the coupon `' . $coupon . '` got a paid plan past the block'
            );
        }
    }

    /**
     * RECONCILIATION SURVIVES: a payment already in flight is still refused AND still surfaced.
     *
     * This is the owner's second condition — *"Preserve safe manual reconciliation for previously
     * initiated or captured payments."* The checkout block stops NEW charges; it cannot un-start a
     * payment a merchant began before it landed, or one a provider retries. For those the return
     * path must still refuse to activate on unverified evidence and must still log, because that
     * log is the only thing that tells an operator a capture needs reconciling.
     *
     * So this asserts the two controls are BOTH present and do different jobs. Deleting the
     * return-path refusal once the checkout block exists would be the owner's own correction made
     * one step later: a previously-initiated payment would activate a plan on evidence nobody
     * checked.
     *
     * @dataProvider deferredPaidReturns
     */
    public function test_an_in_flight_payment_is_still_refused_and_still_surfaced(string $gateway): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'benefit_secret_key' => 'sk_test_not_a_real_key',
            'flutterwave_secret_key' => 'not-a-real-flutterwave-key',
        ]);

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');

        /* The return arrives as it would for a payment started before the block landed. */
        $this->callReturn($gateway, $merchant, $plan->id);

        $this->assertNull($merchant->fresh()->plan_id, $gateway . ': an in-flight payment activated a plan');
        $this->assertSame(0, (int) DB::table('plan_orders')->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains(
                (string) $message,
                'this gateway is deferred from Phase 02'
            ) && ($context['operator_action'] ?? '') !== '')
            ->atLeast()->once();
    }

    /** BOTH CONTROLS EXIST. Neither may be removed in favour of the other. */
    public function test_both_the_checkout_block_and_the_return_refusal_are_wired(): void
    {
        $trait = file_get_contents(base_path('app/Http/Controllers/Concerns/RefusesDeferredGateway.php'));

        foreach (['refuseDeferredGatewayCheckout', 'refuseDeferredGatewayPaidActivation'] as $method) {
            $this->assertStringContainsString(
                'function ' . $method,
                $trait,
                $method . ' is gone. The two controls do different jobs: the checkout block stops '
                . 'a NEW charge, the return refusal stops an in-flight capture activating a plan '
                . 'and surfaces it for reconciliation. Removing either re-opens one half.'
            );
        }

        /* And each is actually CALLED -- a trait method nothing invokes is F237's shape. */
        $benefit = file_get_contents(base_path('app/Http/Controllers/BenefitPaymentController.php'));
        $flutterwave = file_get_contents(base_path('app/Http/Controllers/FlutterwaveController.php'));

        $this->assertStringContainsString('refuseDeferredGatewayCheckout', $benefit, 'Benefit does not block checkout');
        $this->assertStringContainsString('refuseDeferredGatewayPaidActivation', $benefit, 'Benefit does not refuse the return');
        $this->assertStringContainsString('refuseDeferredGatewayCheckout', $flutterwave, 'Flutterwave does not block checkout');
        $this->assertStringContainsString('refuseDeferredGatewayPaidActivation', $flutterwave, 'Flutterwave does not refuse the return');
    }

    /**
     * THE STRUCTURAL ASSERTION: both handlers refuse BEFORE their outbound call.
     *
     * A refusal that happens after the provider has been contacted still leaks a request to a
     * gateway whose answer the platform has decided it cannot trust, and on Benefit it would spend
     * an authenticated API call on a charge lookup. Asked of the method with comments stripped,
     * because both handlers now explain the deferral in prose directly above the guard.
     *
     * @dataProvider handlers
     */
    public function test_the_deferral_refuses_before_the_outbound_call(string $file, string $method, string $outbound): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/' . $file));
        $start = strpos($source, 'public function ' . $method . '(');
        $this->assertNotFalse($start, $file . ': ' . $method . ' has been renamed');
        $body = substr($source, $start);
        $next = strpos($body, 'public function ', 10);
        $body = $next === false ? $body : substr($body, 0, $next);

        $code = implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all('<?php ' . $body)
        ));

        $guard = strpos($code, 'refuseDeferredGatewayPaidActivation');
        $call = strpos($code, $outbound);

        $this->assertNotFalse($guard, $file . ': the deferral guard is gone from ' . $method);
        $this->assertNotFalse($call, $file . ': the outbound call marker `' . $outbound . '` has moved; re-read this test');
        $this->assertLessThan(
            $call,
            $guard,
            $file . ': the deferral is checked AFTER the outbound call, so a gateway the platform '
            . 'has decided it cannot trust is still contacted on every paid return.'
        );
    }

    public static function handlers(): array
    {
        return [
            'Benefit' => ['BenefitPaymentController.php', 'call_back', 'api.tap.company'],
            'Flutterwave' => ['FlutterwaveController.php', 'getPaymentStatus', 'getpaidx/api/v2/verify'],
        ];
    }
}
