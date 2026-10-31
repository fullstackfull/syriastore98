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
