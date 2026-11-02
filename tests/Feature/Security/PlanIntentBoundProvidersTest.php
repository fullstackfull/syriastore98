<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use App\Services\PlanPurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * F229 — CASHFREE AND MOLLIE NOW TAKE THE PLAN FROM THE SERVER'S OWN RECORD.
 * =========================================================================
 *
 * The substitution these close: pay once for the cheapest plan, name a dearer one on the FIRST
 * return. Cashfree reads the plan from `$request->plan_id`, a raw query-string integer; Mollie
 * reads it from a `Crypt` route parameter, and `APP_KEY` is in git history so that is an encoding
 * and not a control. Both gates — `payment_status == "SUCCESS"` and `$payment->isPaid()` — are
 * properties of the PROVIDER'S TRANSACTION and say nothing about which plan the money was for.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THESE TWO AND NOT BENEFIT, which is the requirement-8 judgement in one paragraph
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * An intent is only a control if its reference is one the PROVIDER attests. Cashfree's order id is
 * minted by this server, sent in the server-to-server order creation, echoed by `return_url`'s
 * `{order_id}` template, and is the id the return then asks Cashfree's own APIs about. Mollie's
 * payment id is minted by Mollie before the buyer leaves and held in the server session, so the
 * caller cannot even name it. Both are provider-attested by construction — no new round trip and
 * no new contract to establish.
 *
 * Benefit's is not: the return keys on `$request->tap_id`, a charge id the server does **not**
 * know at initiation. Binding it would mean sending a merchant reference in tap's
 * `reference.order` field and trusting it to come back in the charge-retrieve response — a
 * contract this project has no captured evidence for. Under requirement 8 that is BLOCKED, not
 * guessed. One captured tap charge response settles it.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY *NOT* ASSERTED HERE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The captured AMOUNT. `consumeForPlanBinding()` does not compare it, because requirement 3 wants
 * normalisation from verified contracts and this project has none for either provider. The two
 * controls are independent — binding the plan closes the substitution, comparing the amount closes
 * paying-less-than-the-plan-costs — and the second stays BLOCKED. The amount is server-owned at
 * initiation on both paths regardless, so a buyer cannot lower what the gateway asks for.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY NO OUTBOUND CALL IS FAKED, AND WHY THAT IS SOUND HERE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * No provider is reachable from this container, so the handler's outbound verification fails and
 * lands in its own catch. `PlanActivationReplayGuardTest` records the trap that creates: twelve
 * "no second plan order" assertions once passed against UNGUARDED code because they were
 * measuring the network failure.
 *
 * These cases avoid it by asserting on the INTENT RECORD, which the server writes before any
 * network call and which the network cannot touch. A refusal the network failure cannot produce
 * is "the intent was consumed, and by this purchaser" — and for the negative cases, "the intent
 * is still pending and a refusal was recorded against it". Each case therefore fails for its own
 * reason whether or not the gateway answers, and the positive control below proves the consuming
 * path is reached at all.
 */
class PlanIntentBoundProvidersTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    public static function providers(): array
    {
        return [
            'Cashfree' => ['Cashfree', 'cashfreePayment.success', 'query:order_id'],
            'Mollie' => ['Mollie', 'plan.mollie', 'session:mollie_payment_id'],
        ];
    }

    private function seedProviderSettings(): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'cashfree_key' => 'test-key-not-real',
            'cashfree_secret' => 'test-secret-not-real',
            'cashfree_mode' => 'sandbox',
            'mollie_api_key' => 'test_not_a_real_mollie_key',
        ]);
    }

    private function intentFor(string $provider, $merchant, Plan $plan, string $amount = '499.00'): string
    {
        $reference = $provider === 'Mollie' ? 'tr_' . uniqid() : strtoupper(uniqid());

        $written = PlanPurchaseIntent::create([
            'provider' => $provider,
            'reference' => $reference,
            'user_id' => $merchant->id,
            'plan_id' => $plan->id,
            'expected_amount' => $amount,
            'currency' => 'USD',
        ]);

        $this->assertSame($reference, $written, 'premise broken: the intent was not recorded, so nothing below is measuring the binding');

        return $reference;
    }

    private function callReturn(string $route, string $how, string $reference, int $planId, $user)
    {
        [$kind, $field] = explode(':', $how, 2);
        $test = $this->actingAs($user);

        if ($kind === 'session') {
            return $test->withSession([$field => $reference])->get(route($route, Crypt::encrypt($planId)));
        }

        return $test->get(route($route, [$field => $reference, 'plan_id' => $planId]));
    }

    /**
     * THE POSITIVE CONTROL, FIRST. Without it every refusal below is satisfied by a handler that
     * refuses everything — which is exactly what an unreachable gateway produces.
     *
     * @dataProvider providers
     */
    public function test_premise_an_honest_return_reaches_the_binding_and_consumes_its_intent(
        string $provider,
        string $route,
        string $how
    ): void {
        $this->seedProviderSettings();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');
        $reference = $this->intentFor($provider, $merchant, $plan);

        $this->callReturn($route, $how, $reference, $plan->id, $merchant);

        $this->assertSame(
            PlanPurchaseIntent::CONSUMED,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            $provider . ': the honest return did NOT reach the intent binding. Every assertion in '
            . 'this file would then pass against a handler with no binding at all, because the '
            . 'gateway is unreachable from this container and the handler refuses regardless.'
        );
    }

    /**
     * THE SUBSTITUTION ITSELF. The intent names the cheap plan; the return names the dear one.
     *
     * The entitlement is asserted rather than the recorded order, because recording the right
     * number while assigning the wrong plan is the same defect with better bookkeeping. And the
     * dear plan must not be activated **whatever** the gateway says — including when it says
     * nothing, which is this container's case.
     *
     * @dataProvider providers
     */
    public function test_a_return_naming_a_dearer_plan_cannot_activate_it(
        string $provider,
        string $route,
        string $how
    ): void {
        $this->seedProviderSettings();
        $merchant = $this->makeMerchant();
        $cheap = $this->makePlan('10');
        $dear = $this->makePlan('999');
        $reference = $this->intentFor($provider, $merchant, $cheap, '10.00');

        /*
         * ══════════════════════════════════════════════════════════════════════════════════════
         * THE DISTINGUISHER, and the first version of this case did not have one.
         * ══════════════════════════════════════════════════════════════════════════════════════
         *
         * I wrote this asserting only that the DEAR plan was not activated, confirmed it passed,
         * and then mutated the controller to read `$request->plan_id` again — **and it still
         * passed**. Of course it did: no gateway is reachable from this container, so the handler
         * refuses either way and nothing is ever activated. The assertion was measuring the
         * network failure, which is the exact trap `PlanActivationReplayGuardTest` was rewritten
         * once to escape, and I walked straight into it.
         *
         * What the network CANNOT produce is a refusal that names the intent's plan. So: the
         * intent names a plan that has been DELETED, while the plan the route names still exists.
         * With the binding, `$plan` comes from the intent, resolves to nothing, and the handler
         * logs *"the intent names a plan that no longer exists"*. Without the binding, `$plan` is
         * the route's plan, which is present, so that line is unreachable — it cannot be produced
         * by an unreachable gateway, a thrown SDK, or anything else on this path.
         */
        $deleted = $this->makePlan('10');
        $deletedId = $deleted->id;
        $intentOnDeleted = $this->intentFor($provider, $merchant, $deleted, '10.00');
        Plan::where('id', $deletedId)->delete();

        $this->callReturn($route, $how, $intentOnDeleted, $dear->id, $merchant);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains(
                (string) $message,
                'the intent names a plan that no longer exists'
            ))
            ->atLeast()->once();

        /* And the plain consequence, which cannot distinguish on its own but must still hold. */
        $this->callReturn($route, $how, $reference, $dear->id, $merchant);

        $this->assertNotSame(
            (int) $dear->id,
            (int) ($merchant->fresh()->plan_id ?? 0),
            $provider . ': F229 — the return activated the plan named in the caller-supplied '
            . 'parameter rather than the plan the recorded intent says the money was for. Paying '
            . '10 bought 999.'
        );

        $this->assertSame(
            0,
            DB::table('plan_orders')->where('plan_id', $dear->id)->count(),
            $provider . ': a plan order was recorded against the plan that was NOT paid for'
        );
    }

    /**
     * A STRANGER CANNOT ACTIVATE AGAINST SOMEBODY ELSE'S INTENT — and cannot spoil it either.
     *
     * Both halves, because a guard that refuses the stranger by closing the owner's purchase has
     * turned a cross-user read into a denial of service. `consumeForPlanBinding()` checks the
     * purchaser BEFORE it returns anything about the intent, so a probe learns nothing either.
     *
     * @dataProvider providers
     */
    public function test_a_stranger_can_neither_consume_nor_spoil_another_accounts_intent(
        string $provider,
        string $route,
        string $how
    ): void {
        $this->seedProviderSettings();
        $owner = $this->makeMerchant();
        $stranger = $this->makeMerchant();
        $plan = $this->makePlan('499');
        $reference = $this->intentFor($provider, $owner, $plan);

        $this->callReturn($route, $how, $reference, $plan->id, $stranger);

        $this->assertNull($stranger->fresh()->plan_id, $provider . ': a stranger activated a plan on somebody else\'s intent');
        $this->assertNull($owner->fresh()->plan_id, $provider . ': the owner was activated by a stranger\'s request');

        $this->assertSame(
            PlanPurchaseIntent::PENDING,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            $provider . ': a stranger CLOSED the owner\'s intent, so the owner\'s own return can '
            . 'never complete the purchase they paid for'
        );
    }

    /**
     * ONE REFERENCE, ONE ACTIVATION — the conditional UPDATE from `pending`, through the route.
     *
     * @dataProvider providers
     */
    public function test_a_replayed_reference_cannot_consume_the_intent_twice(
        string $provider,
        string $route,
        string $how
    ): void {
        $this->seedProviderSettings();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');
        $reference = $this->intentFor($provider, $merchant, $plan);

        $this->callReturn($route, $how, $reference, $plan->id, $merchant);
        $this->assertSame(
            PlanPurchaseIntent::CONSUMED,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            $provider . ': premise broken, the first return did not consume the intent'
        );

        $ordersAfterFirst = (int) DB::table('plan_orders')->count();

        $this->callReturn($route, $how, $reference, $plan->id, $merchant);

        $this->assertSame(
            $ordersAfterFirst,
            (int) DB::table('plan_orders')->count(),
            $provider . ': replaying the reference wrote a second plan order'
        );
    }

    /**
     * A RETURN WITH NO RECORDED INTENT ACTIVATES NOTHING.
     *
     * This is the state every return was in before this change, and the one an attacker arranges
     * by inventing a reference. UNVERIFIABLE is a refusal, never a pass.
     *
     * @dataProvider providers
     */
    public function test_a_return_with_no_recorded_intent_activates_nothing(
        string $provider,
        string $route,
        string $how
    ): void {
        $this->seedProviderSettings();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');

        $this->callReturn($route, $how, 'INVENTED-' . uniqid(), $plan->id, $merchant);

        $this->assertNull($merchant->fresh()->plan_id, $provider . ': an invented reference activated a plan');
        $this->assertSame(0, (int) DB::table('plan_orders')->count(), $provider . ': an invented reference wrote a plan order');
    }

    /**
     * THE REFUSAL MUST RETURN, AND THIS ONE IS A SOURCE ASSERTION BECAUSE NOTHING HERE CAN SEE IT.
     *
     * Stated rather than hidden: I mutated the Cashfree handler to `if (false)` around its
     * non-VERIFIED branch and **every test in this file still passed**. The reason is the same
     * unreachable gateway — with the branch gone a stranger's request proceeds, the outbound call
     * fails, and nothing is activated, so the behavioural assertions are satisfied for the wrong
     * reason. In production that branch is what stops a stranger's return activating the OWNER's
     * plan when the gateway *does* answer.
     *
     * So the control is pinned from the source instead: on a non-VERIFIED state each handler must
     * record the refusal and `return`. Asked of the method with comments stripped, because both
     * handlers now explain the branch in prose directly above it.
     *
     * @dataProvider boundHandlers
     */
    public function test_a_non_verified_intent_state_refuses_and_returns(string $file, string $method): void
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

        $this->assertStringContainsString(
            'PlanPurchaseIntent::consumeForPlanBinding',
            $code,
            $file . ': the intent binding is gone from ' . $method . ', so the plan is read from '
            . 'caller-supplied input again'
        );

        $guard = strpos($code, 'PlanPurchaseIntent::VERIFIED');
        $this->assertNotFalse(
            $guard,
            $file . ': nothing compares the intent state against VERIFIED, so a refusal is not a '
            . 'refusal — it falls through to the activation'
        );

        /* The branch must end in a return before the plan is taken from the intent. */
        $afterGuard = substr($code, $guard, 900);
        $this->assertStringContainsString(
            'return redirect()',
            $afterGuard,
            $file . ': the non-VERIFIED branch does not return. With the gateway unreachable this '
            . 'file cannot see the difference — a stranger\'s request simply fails outbound — but '
            . 'in production it would proceed and activate the intent owner\'s plan for whoever '
            . 'presented the reference.'
        );
    }

    public static function boundHandlers(): array
    {
        return [
            'Cashfree' => ['CashfreeController.php', 'cashfreePaymentSuccess'],
            'Mollie' => ['MolliePaymentController.php', 'getPaymentStatus'],
        ];
    }

    /**
     * AND BENEFIT IS BLOCKED, ASSERTED SO THE GAP CANNOT BE FORGOTTEN.
     *
     * A BLOCKED provider recorded only in prose becomes a provider everyone assumes was done. This
     * asserts the measurable fact that makes it blocked — `BenefitPaymentController` has no intent
     * binding — so when somebody adds one, this fails and tells them to close the finding.
     *
     * It is NOT an assertion that Benefit is safe. It is the opposite: a marker that it is not.
     */
    public function test_benefit_is_still_unbound_and_that_is_recorded_not_forgotten(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/BenefitPaymentController.php'));

        $code = implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all($source)
        ));

        $this->assertStringNotContainsString(
            'PlanPurchaseIntent::consumeForPlanBinding',
            $code,
            'Benefit now HAS an intent binding. That is good news and this test is the wrong shape '
            . 'for it: close the BLOCKED status in the handoff and the ledger, add Benefit to this '
            . 'file\'s data provider, and delete this case. Its reference is `$request->tap_id`, '
            . 'which the server does not know at initiation — so whoever did this must have '
            . 'established how tap echoes a merchant reference. Record that evidence.'
        );
    }
}
