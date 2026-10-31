<?php

namespace Tests\Feature\Security;

use App\Models\PlanOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ONE PROVIDER REFERENCE, ONE PLAN ACTIVATION — for the three providers whose reference is in
 * hand BEFORE the outbound verification call.
 * =============================================================================================
 *
 * THE DEFECT, measured 2026-10-31 on three verified-but-unbound plan returns.
 *
 * `GuardsPlanActivation` already states the rule and already has the mechanism — Paystack
 * (D-12/F87) refuses a replayed reference under every shipped spelling of `payment_type`. The
 * trait's own docblock records why it is not called more widely: *"WHERE NO PROVIDER TRANSACTION
 * IDENTIFIER IS AVAILABLE, this is not called and no claim of idempotency is made. Only two of
 * the fifteen forgeable handlers read one that is identifiable without guessing a field name off
 * the wire."*
 *
 * That limit is real for a field buried in a provider's response body. It does **not** apply to
 * these three, where the reference is the very value the handler uses to CALL the provider, read
 * straight off the request or the session before any network traffic:
 *
 *   `BenefitPaymentController::call_back`        `$request->tap_id`  (used at :165)
 *   `CashfreeController::cashfreePaymentSuccess` `$request->order_id` (used at :206)
 *   `MolliePaymentController::getPaymentStatus`  `session('mollie_payment_id')` (used at :179)
 *
 * Each verified the payment and then activated whatever plan the caller named, any number of
 * times. Benefit and Cashfree recorded `txn_id = ''`, so a replay was not even *visible* in
 * `plan_orders` afterwards. Mollie recorded `$payment->id` honestly and nothing looked at it —
 * and its single-use clean-up, `session()->forget('mollie_payment_id')` at `:256`, is
 * **unreachable**: every branch above it returns (`:242`, `:247`, `:253`), so one paid Mollie
 * payment stayed spendable for as long as the session lived.
 *
 * WHAT THIS CLOSES AND WHAT IT DOES NOT. It closes *replay* — presenting one real payment again,
 * for the same plan or a dearer one. It does **not** close a single substitution: paying for the
 * cheapest plan once and naming an expensive one on the first return still succeeds, because the
 * only field that could tell those apart is the captured amount, and the trait deliberately
 * declines a blind amount comparison: *"a verified provider may legitimately post an amount back
 * in minor units, and a blind comparison would refuse real payments on a live integration."*
 * That half is recorded as F228 for an owner decision, not re-decided here.
 *
 * WHY THE GUARD RUNS BEFORE THE OUTBOUND CALL, and why that is what makes this testable at all.
 * No payment provider is reachable from this container — Benefit and Cashfree use a raw
 * `GuzzleHttp\Client` and Mollie its own SDK, none of which `Http::fake()` intercepts. A guard
 * placed after the verification could not be driven here, and a test that drove it would be
 * measuring the network failure. Placed first, the refusal is reached with no traffic at all, so
 * every case below is a real measurement of the real route. **It is also the better placement on
 * its own merits:** a known-spent reference should not cost an outbound call.
 *
 * THE LOOKUP IS NOT SCOPED TO THE CALLER, and `test_a_reference_spent_by_another_account_is_still
 * _refused` is why: scoping it would let a second account present the same reference, find
 * nothing, and have its own plan activated on a stranger's payment. One reference, one
 * activation, whoever presents it.
 */
class PlanActivationReplayGuardTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    /** The three, as `[route name, canonical payment_type, how the reference arrives]`. */
    public static function providers(): array
    {
        return [
            'Benefit'  => ['benefit.call_back', 'Benefit', 'query:tap_id'],
            'Cashfree' => ['cashfreePayment.success', 'Cashfree', 'query:order_id'],
            'Mollie'   => ['plan.mollie', 'Mollie', 'session:mollie_payment_id'],
        ];
    }

    private function seedProviderSettings(): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'benefit_secret_key' => 'sk_test_not_a_real_key',
            'mollie_api_key'     => 'test_not_a_real_key',
            'cashfree_mode'      => 'sandbox',
        ]);
    }

    /** Drive the provider's return with the given reference. */
    private function callReturn(string $route, string $how, string $reference, int $planId, $user)
    {
        [$kind, $field] = explode(':', $how, 2);

        $test = $this->actingAs($user);

        if ($kind === 'session') {
            $test = $test->withSession([$field => $reference]);

            return $test->get(route($route, Crypt::encrypt($planId)));
        }

        return $test->get(route($route, [$field => $reference, 'plan_id' => $planId]));
    }

    /**
     * THE DISTINGUISHER, AND THE REASON THIS FILE WAS REWRITTEN ONCE BEFORE IT WORKED.
     *
     * Every "no second plan order" assertion below passed against the UNGUARDED code on the first
     * run — 12 of 12 — because no payment provider is reachable from this container, so the
     * handler's outbound call fails, lands in its own catch, and writes nothing. The tests were
     * measuring the network failure. That is the exact shape CLAUDE.md names: a test that passes
     * for the wrong reason, and the owner's rule *"do not accept tests that pass because the
     * tested route was never reached"*.
     *
     * A refusal the network failure CANNOT produce is the guard's own log line. So every case
     * asserts it, and an absent guard fails on this before it fails on anything else.
     */
    public const REFUSAL_LOG = 'Plan activation refused: this provider reference has already activated a plan.';

    /** The same buyer returning to the same plan is the ORDINARY case, so it is info, not warning. */
    public const REACTIVATION_LOG = 'Plan activation re-run for an already-recorded reference from the same buyer.';

    private function assertTheGuardRefused(string $paymentType): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === self::REFUSAL_LOG)
            ->atLeast()->once();
    }

    private function assertTheGuardReactivated(string $paymentType): void
    {
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === self::REACTIVATION_LOG)
            ->atLeast()->once();
    }

    /**
     * NEITHER LINE WAS EMITTED.
     *
     * `Log::shouldNotHaveReceived('warning', [self::REFUSAL_LOG])` CANNOT FAIL and was the first
     * version of this: the guard calls `Log::warning($message, $context)` with two arguments, so a
     * one-element args expectation never matches and the assertion is free. Found by independent
     * review. Mockery has no negative `withArgs`, so the spy's own calls are inspected instead.
     */
    private function assertTheGuardStayedSilent(string $paymentType): void
    {
        foreach (['warning' => self::REFUSAL_LOG, 'info' => self::REACTIVATION_LOG] as $level => $line) {
            try {
                Log::shouldHaveReceived($level)
                    ->withArgs(fn ($message, $context = []) => $message === $line)
                    ->atLeast()->once();
                $emitted = true;
            } catch (\Throwable $e) {
                $emitted = false;
            }

            $this->assertFalse(
                $emitted,
                $paymentType . ': the guard emitted its ' . $level . ' line for a reference it has '
                . 'never seen.'
            );
        }
    }

    private function recordSpentReference(string $paymentType, string $reference, int $planId, int $userId): PlanOrder
    {
        return PlanOrder::create([
            'order_id'       => 'SPENT-' . uniqid(),
            'name'           => 'Earlier buyer',
            'card_number'    => '',
            'card_exp_month' => '',
            'card_exp_year'  => '',
            'plan_name'      => 'Paid Plan',
            'plan_id'        => $planId,
            'price'          => '499',
            'price_currency' => 'USD',
            'payment_type'   => $paymentType,
            'payment_status' => 'success',
            'txn_id'         => $reference,
            'receipt'        => '',
            'user_id'        => $userId,
        ]);
    }

    /**
     * THE DEFECT, DRIVEN: a reference already recorded against a plan order must not activate a
     * second one.
     *
     * @dataProvider providers
     */
    public function test_a_replayed_provider_reference_writes_no_second_plan_order(
        string $route,
        string $paymentType,
        string $how
    ): void {
        $this->seedProviderSettings();
        $merchant = $this->makeMerchant();
        $cheap = $this->makePlan('10');
        $dear = $this->makePlan('499');

        $reference = 'REF-' . strtoupper(uniqid());
        $this->recordSpentReference($paymentType, $reference, $cheap->id, $merchant->id);

        $before = (int) DB::table('plan_orders')->count();
        $planBefore = $merchant->fresh()->plan_id;

        // The same reference, now naming the EXPENSIVE plan.
        $this->callReturn($route, $how, $reference, $dear->id, $merchant);

        $this->assertSame(
            $before,
            (int) DB::table('plan_orders')->count(),
            $paymentType . ': a replayed provider reference wrote a second plan order.'
        );

        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            $paymentType . ': a replayed provider reference activated a plan.'
        );

        $this->assertTheGuardRefused($paymentType);
    }

    /**
     * AND IT MUST NOT MATTER WHO PRESENTS IT. The row belongs to another account entirely.
     *
     * @dataProvider providers
     */
    public function test_a_reference_spent_by_another_account_is_still_refused(
        string $route,
        string $paymentType,
        string $how
    ): void {
        $this->seedProviderSettings();
        $stranger = $this->makeMerchant();
        $attacker = $this->makeMerchant();
        $dear = $this->makePlan('499');

        $reference = 'REF-' . strtoupper(uniqid());
        $this->recordSpentReference($paymentType, $reference, $dear->id, $stranger->id);

        $before = (int) DB::table('plan_orders')->count();

        $this->callReturn($route, $how, $reference, $dear->id, $attacker);

        $this->assertSame(
            $before,
            (int) DB::table('plan_orders')->count(),
            $paymentType . ': a reference spent by another account activated this one\'s plan.'
        );

        $this->assertNull(
            $attacker->fresh()->plan_id,
            $paymentType . ': the attacker was given a plan on a stranger\'s payment.'
        );

        $this->assertTheGuardRefused($paymentType);
    }

    /**
     * THE REFUSAL MUST BE RECOGNISED UNDER EVERY SHIPPED SPELLING OF `payment_type`.
     *
     * The handlers record `__('Benefit')`, `__('Cashfree')`, `__('Mollie')`, so the stored value
     * is whatever the buyer's locale translated it to. A lookup on the English spelling alone
     * misses a row written by an Arabic-locale buyer, and the replay goes through.
     *
     * @dataProvider providers
     */
    public function test_the_refusal_holds_when_the_earlier_row_used_a_translated_payment_type(
        string $route,
        string $paymentType,
        string $how
    ): void {
        $this->seedProviderSettings();
        $merchant = $this->makeMerchant();
        $cheap = $this->makePlan('10');
        $dear = $this->makePlan('499');

        $translated = (string) trans($paymentType, [], 'ar');

        /*
         * NO SILENT EXEMPTION. The first version returned `assertTrue(true)` here, which would
         * have voided the case without a sound if `ar.json` ever lost one of these keys. Found by
         * independent review. All three keys have distinct Arabic values today, so a missing one
         * is a fact somebody should have to look at.
         */
        $this->assertNotSame(
            '',
            trim($translated),
            $paymentType . ' has no Arabic translation, so this case cannot measure the locale '
            . 'sweep. Add the key to resources/lang/ar.json or retarget this case at a locale '
            . 'that has it — do not let it pass vacuously.'
        );
        $this->assertNotSame(
            $paymentType,
            $translated,
            $paymentType . ' now translates to itself in Arabic, so a canonical-only lookup would '
            . 'pass this case. Retarget it at a locale whose spelling really differs.'
        );

        // Recorded against the CHEAP plan, presented against the DEAR one: a genuine refusal, so
        // the warning line is the right signal rather than the same-buyer re-activation.
        $reference = 'REF-' . strtoupper(uniqid());
        $this->recordSpentReference($translated, $reference, $cheap->id, $merchant->id);

        $before = (int) DB::table('plan_orders')->count();

        $this->callReturn($route, $how, $reference, $dear->id, $merchant);

        $this->assertSame(
            $before,
            (int) DB::table('plan_orders')->count(),
            $paymentType . ': a row stored under the ' . $translated . ' spelling did not stop the '
            . 'replay. The lookup must cover every shipped locale.'
        );

        $this->assertTheGuardRefused($paymentType);
    }

    /**
     * THE SAME BUYER RETURNING TO THE SAME PLAN GETS THE SUCCESS THEY ALREADY EARNED.
     *
     * A refusal is right for a substituted plan and for a stranger; it is wrong for the buyer who
     * paid, refreshed the confirmation page, and is asking about the plan they already own. They
     * are answered with success and nothing is written — no second row, no second `assignPlan()`.
     *
     * THIS CASE WAS ADDED BECAUSE A BITE TEST FOUND IT MISSING. Deleting the `$samePlan &&
     * $sameBuyer` branch from `refusePlanActivationReplay()` left all thirteen other cases green,
     * so that branch was carrying behaviour nothing measured.
     *
     * @dataProvider providers
     */
    public function test_the_same_buyer_returning_to_the_same_plan_is_answered_with_success(
        string $route,
        string $paymentType,
        string $how
    ): void {
        $this->seedProviderSettings();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');

        $reference = 'REF-' . strtoupper(uniqid());
        $this->recordSpentReference($paymentType, $reference, $plan->id, $merchant->id);

        $before = (int) DB::table('plan_orders')->count();

        $response = $this->callReturn($route, $how, $reference, $plan->id, $merchant);

        $response->assertRedirect(route('plan.index'));

        $this->assertSame(
            'Plan activated Successfully.',
            session('success'),
            $paymentType . ': the buyer who already paid for this plan was refused rather than '
            . 'answered with the success they earned.'
        );

        $this->assertSame(
            $before,
            (int) DB::table('plan_orders')->count(),
            $paymentType . ': answering the same buyer wrote a second plan order. The idempotent '
            . 'answer must RE-RUN the activation, not record a second sale.'
        );

        /*
         * AND THE ENTITLEMENT MUST BE REPAIRED, NOT MERELY CLAIMED.
         *
         * The fixture buyer has a recorded plan ORDER and no `plan_id` — exactly the state a
         * request killed between the row and `assignPlan()` leaves behind. The first version of
         * this guard answered "Plan activated Successfully." and did nothing, which would have
         * stranded that buyer on every retry. `PaystackPaymentController:341-367` had already
         * decided the opposite and written out why; independent review caught that the shared
         * helper contradicted it.
         */
        $this->assertSame(
            $plan->id,
            (int) $merchant->fresh()->plan_id,
            $paymentType . ': the buyer was told the plan was activated while it was not. The '
            . 'guard must re-run assignPlan() for the same buyer and the same plan.'
        );

        $this->assertTheGuardReactivated($paymentType);
    }

    /**
     * THE POSITIVE CONTROL, and without it every case above is satisfied by a handler that
     * refuses everything.
     *
     * With no prior row the guard must not fire. What happens next is the provider call, which
     * cannot succeed from this container — so the measurement is that the handler got PAST the
     * guard and into its own error handling, not that it activated a plan.
     *
     * @dataProvider providers
     */
    public function test_a_first_arrival_is_not_refused_by_the_guard(
        string $route,
        string $paymentType,
        string $how
    ): void {
        $this->seedProviderSettings();
        $merchant = $this->makeMerchant();
        $plan = $this->makePlan('499');

        $response = $this->callReturn($route, $how, 'REF-' . strtoupper(uniqid()), $plan->id, $merchant);

        // Past the guard means the outbound call was attempted and failed, which these handlers
        // turn into an error redirect (or, with no catch on the path, a 500). Either proves the
        // guard did not short-circuit a genuine first arrival. What it must NOT be is the
        // idempotent success the replay path returns.
        $this->assertNotSame(
            'Plan activated Successfully.',
            session('success'),
            $paymentType . ': a FIRST arrival was answered with the already-activated success. The '
            . 'guard is firing on a reference it has never seen.'
        );

        $this->assertTheGuardStayedSilent($paymentType);
    }

    /**
     * MOLLIE'S SINGLE-USE CLEAN-UP MUST NOT BE UNREACHABLE CODE.
     *
     * `session()->forget('mollie_payment_id')` existed and never ran: it sits after an if/else in
     * which every branch returns. The durable guard above is the real control, but a session key
     * that is never cleared is the defect that made the replay reachable in the first place, and a
     * structural assertion is the only honest one available — the success path needs a live Mollie
     * API and this container has none, which is stated rather than papered over.
     */
    /**
     * MOLLIE'S SINGLE-USE CLEAN-UP MUST RUN, AND ONLY ONCE THE ENTITLEMENT EXISTS.
     *
     * The original defect was placement: `session()->forget('mollie_payment_id')` sat after an
     * if/else in which every branch returned, so it never executed and one paid Mollie payment
     * stayed spendable for the life of the session.
     *
     * The FIRST FIX moved it above `assignPlan()`, and that traded one defect for a worse one —
     * Mollie's payment id lives in the session and nowhere else, so a failed `assignPlan()` would
     * leave the buyer with no key, no entitlement, and no path to claim a plan they had paid for.
     * Independent review caught it. So the requirement has two sides and both are asserted.
     *
     * THE FIRST VERSION OF THIS TEST COULD NOT FAIL IN THE DIRECTION IT CLAIMED. It anchored on
     * the FIRST `->save(` in the file, which is `$userCoupon->save()` at line 82 in a different
     * method — so `assertGreaterThan(82, $forgetLine)` was true for any placement anywhere in
     * `getPaymentStatus`, and the failure message would have reported line 82 as "the PlanOrder
     * save". Also found by that review. The anchor is now `assignPlan`, which is the thing the
     * clean-up must actually follow.
     *
     * It is a structural assertion and labelled as one: the success path needs a live Mollie API
     * and this container has none.
     */
    public function test_mollies_session_clear_runs_only_after_a_successful_activation(): void
    {
        $tokens = $this->codeTokensOf('app/Http/Controllers/MolliePaymentController.php');
        $count = count($tokens);

        /*
         * THE BLOCK, NOT A LINE NUMBER — AND IT TOOK THREE GOES TO GET THIS ANCHOR RIGHT.
         *
         * v1 anchored on the first `->save(` in the file, which is `$userCoupon->save()` at :82 in
         * a different method, so the assertion was true for any placement anywhere (found by
         * independent review). v2 anchored on the first `assignPlan`, which is :96 in the
         * free-plan branch of `planPayWithMollie` — so a bite that moved the clean-up back above
         * the real `assignPlan` at :275 STILL PASSED. Same mistake, one occurrence over.
         *
         * The requirement is not "after some line". It is "inside the block that only runs when
         * the entitlement exists". So this finds the `is_success` test whose block contains the
         * success message, walks its braces, and requires the clean-up to be in there.
         */
        $blockStart = null;
        $blockEnd = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING
                || ! str_contains($token[1], 'is_success')
            ) {
                continue;
            }

            // Walk forward to this test's opening brace, then to its matching close.
            $depth = 0;
            $open = null;

            for ($j = $i; $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    if ($open === null) {
                        $open = is_array($tokens[$j]) ? $tokens[$j][2] : null;
                        $openIndex = $j;
                    }
                    $depth++;
                } elseif ($tokens[$j] === '}') {
                    $depth--;

                    if ($depth === 0 && isset($openIndex)) {
                        $candidateStart = $openIndex;
                        $candidateEnd = $j;

                        $holdsSuccess = false;

                        for ($k = $candidateStart; $k <= $candidateEnd; $k++) {
                            if (is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING
                                && str_contains($tokens[$k][1], 'Plan activated Successfully.')
                            ) {
                                $holdsSuccess = true;
                            }
                        }

                        if ($holdsSuccess) {
                            $blockStart = $candidateStart;
                            $blockEnd = $candidateEnd;
                        }

                        unset($openIndex);

                        break;
                    }
                }
            }

            if ($blockStart !== null) {
                break;
            }
        }

        $this->assertNotNull(
            $blockStart,
            'MolliePaymentController no longer has an $assignPlan[\'is_success\'] block containing '
            . 'the activation success message. If the shape changed, re-anchor this assertion — do '
            . 'not delete it.'
        );

        $forgetIndex = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'forget'
                && isset($tokens[$i + 2]) && is_array($tokens[$i + 2])
                && $tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
                && str_contains($tokens[$i + 2][1], 'mollie_payment_id')
            ) {
                $forgetIndex = $i;
            }
        }

        $this->assertNotNull(
            $forgetIndex,
            'MolliePaymentController no longer clears mollie_payment_id at all. One paid Mollie '
            . 'payment id left in the session is one reusable plan activation — the original '
            . 'defect, which was that this call sat after an if/else whose every branch returned.'
        );

        $this->assertTrue(
            $forgetIndex > $blockStart && $forgetIndex < $blockEnd,
            'session()->forget(\'mollie_payment_id\') is OUTSIDE the successful-activation block. '
            . 'Before it, a failed assignPlan() leaves the buyer with no key, no plan, and no way '
            . 'to claim a payment they made — Mollie\'s payment id lives in the session and nowhere '
            . 'else. After the block, it never runs at all, which is the defect this started as.'
        );
    }

    /**
     * THE GUARD'S KEY AND THE RECORDED `txn_id` MUST BE THE SAME VARIABLE.
     *
     * THIS CASE EXISTS BECAUSE THE FIX SHIPPED WRONG ONCE. The Cashfree arm was keyed on
     * `$request->get('order_id')` — the id this server mints and publishes in the return URL —
     * while recording `$respons->cf_payment_id`, cashfree's own payment id. Two different values,
     * so the guard looked for a row that is never written and **the Cashfree arm protected
     * nothing**, while its three data-provider cases passed on a hand-seeded shape production
     * never produces. Independent review found it; a count assertion never could.
     *
     * The repair was structural rather than careful: each handler hoists its reference into ONE
     * local, passes that local to the guard, and writes that same local to `txn_id`. This asserts
     * that shape, so the mismatch cannot come back silently in any of the three.
     */
    public function test_each_guarded_handler_records_the_very_reference_it_keys_on(): void
    {
        $handlers = [
            'app/Http/Controllers/BenefitPaymentController.php' => 'Benefit',
            'app/Http/Controllers/CashfreeController.php' => 'Cashfree',
            'app/Http/Controllers/MolliePaymentController.php' => 'Mollie',
            'app/Http/Controllers/PaytmPaymentController.php' => 'Paytm',
        ];

        foreach ($handlers as $file => $provider) {
            $tokens = $this->codeTokensOf($file);
            $count = count($tokens);

            $keyVariable = null;
            $recordedVariables = [];

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (! is_array($token)) {
                    continue;
                }

                // The guard call: refusePlanActivationReplay('<Provider>', $theKey, ...)
                if ($token[0] === T_STRING && $token[1] === 'refusePlanActivationReplay') {
                    $args = [];

                    for ($j = $i + 1; $j < $count; $j++) {
                        $next = $tokens[$j];

                        if ($next === ')') {
                            break;
                        }

                        if (is_array($next) && $next[0] === T_VARIABLE) {
                            $args[] = $next[1];
                        }
                    }

                    // $this is not an argument; the first VARIABLE after it is the reference.
                    $args = array_values(array_filter($args, static fn ($v) => $v !== '$this'));
                    $keyVariable = $args[0] ?? null;
                }

                // Any assignment whose target is ->txn_id
                if ($token[0] === T_STRING && $token[1] === 'txn_id'
                    && isset($tokens[$i - 1]) && is_array($tokens[$i - 1])
                    && $tokens[$i - 1][0] === T_OBJECT_OPERATOR
                ) {
                    $seen = [];

                    for ($j = $i + 1; $j < $count && $j < $i + 24; $j++) {
                        $next = $tokens[$j];

                        if ($next === ';') {
                            break;
                        }

                        if (is_array($next) && $next[0] === T_VARIABLE) {
                            $seen[] = $next[1];
                        }
                    }

                    if ($seen !== []) {
                        $recordedVariables[] = $seen;
                    }
                }
            }

            $this->assertNotNull(
                $keyVariable,
                $provider . ': no refusePlanActivationReplay() call found, or its reference is not '
                . 'a local variable. Pass the reference as ONE local so the recorded value cannot '
                . 'drift away from the looked-up one.'
            );

            $this->assertNotEmpty(
                $recordedVariables,
                $provider . ': no txn_id assignment reads a variable at all.'
            );

            $recordsTheKey = false;

            foreach ($recordedVariables as $seen) {
                if (in_array($keyVariable, $seen, true)) {
                    $recordsTheKey = true;
                }
            }

            $this->assertTrue(
                $recordsTheKey,
                $provider . ': the guard is keyed on ' . $keyVariable . ' and no txn_id assignment '
                . 'writes it. A guard that looks up a value the handler never records protects '
                . 'nothing — which is exactly how the Cashfree arm shipped broken once.'
            );
        }
    }

    /* ============================================================================================
     * PAYTM — THE FOURTH PROVIDER, ADDED AFTER A SCRATCH PROBE MEASURED IT RATHER THAN REASONED
     * ABOUT IT.
     *
     * Paytm is the one provider in this family whose verification is genuinely cryptographic:
     * `PaytmWalletProvider::response()` AES-verifies `CHECKSUMHASH` against the server-held
     * merchant key and throws on a mismatch. It was left out of the first pass for that reason.
     * Executing it changed the answer twice:
     *
     *   MEASURED: replaying the identical signed body wrote TWO plan orders, both carrying
     *   `txn_id = 'PAYTMTXN-0001'`. The checksum stays valid on a re-POST, so cryptography is no
     *   defence against replay — and because the reference IS recorded, the same guard the other
     *   three now use catches it.
     *
     *   MEASURED: with `paytm_merchant_key` ABSENT from settings, `paymentConfig()` (:31) passes
     *   `''` as the key, so the checksum degrades to a hash over a key the caller knows too. A
     *   forged `TXN_SUCCESS` body signed with the empty key activated a 499 plan: one order,
     *   `payment_status = 'success'`, no payment anywhere. That is a free paid plan on any
     *   installation that has not configured Paytm — which is every installation that does not use
     *   it.
     *
     * The replay guard runs AFTER the checksum here, not before. The other three guard first
     * because the reference is what they are about to spend an outbound call on; Paytm's check is
     * local and free, so verifying first costs nothing and denies a probe the chance to learn
     * which transaction ids exist.
     * ========================================================================================= */

    private const PAYTM_KEY = 'test_merchant_key_not_real';

    /** A Paytm return body, checksummed with the given key the way the real gateway would. */
    private function paytmBody(array $overrides = [], ?string $key = self::PAYTM_KEY): array
    {
        $body = array_merge([
            'MID' => 'MERCH123',
            'ORDERID' => '2026-10-31-paytm',
            'TXNAMOUNT' => '499.00',
            'CURRENCY_CODE' => 'INR',
            'STATUS' => 'TXN_SUCCESS',
            'RESPCODE' => '01',
            'RESPMSG' => 'Txn Success',
            'TXNID' => 'PAYTMTXN-' . strtoupper(uniqid()),
            'BANKTXNID' => 'BNK-1',
            'PAYMENTMODE' => 'CC',
        ], $overrides);

        // The vendor library is pulled in by a bare `require` at the top of PaytmWalletProvider,
        // so touching the class first lets the autoloader run it before getChecksumFromArray().
        class_exists(\Anand\LaravelPaytmWallet\Providers\PaytmWalletProvider::class);

        $body['CHECKSUMHASH'] = getChecksumFromArray($body, (string) $key);

        return $body;
    }

    private function paytmReturn(array $body, int $planId, $user)
    {
        return $this->actingAs($user)->post('/plan-paytm-payment/' . Crypt::encrypt($planId), $body);
    }

    /**
     * AN UNCONFIGURED MERCHANT KEY MUST REFUSE, NOT VERIFY AGAINST THE EMPTY STRING.
     *
     * MEASURED BEFORE THE FIX: one plan order, `payment_status = 'success'`, price 499, and the
     * plan assigned — from a body this test minted itself, with no Paytm account involved.
     */
    public function test_paytm_refuses_a_return_when_no_merchant_key_is_configured(): void
    {
        // Deliberately NO paytm_merchant_key row.
        $this->seedSuperAdminCurrency('USD');
        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        $this->paytmReturn($this->paytmBody([], ''), $plan->id, $merchant);

        $this->assertSame(
            0,
            (int) DB::table('plan_orders')->count(),
            'Paytm activated a plan with no merchant key configured. The checksum was computed '
            . 'over the empty string, which the caller knows too, so it proved nothing.'
        );

        $this->assertNull(
            $merchant->fresh()->plan_id,
            'Paytm assigned a paid plan on a self-signed return.'
        );
    }

    /**
     * AND THE CONFIGURED CASE MUST STILL WORK — otherwise the test above is satisfied by a handler
     * that refuses everything, which is how this whole family of guards passes for nothing.
     */
    public function test_paytm_still_activates_a_correctly_checksummed_return(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);
        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        /*
         * F229 / D-31 WIDENED WHAT "CORRECTLY" MEANS HERE, and this fixture is the difference.
         *
         * This case used to need only a well-checksummed body. It now needs two more things,
         * because the owner's instruction is that *"a valid checksum by itself is not sufficient
         * proof of a successful payment"*:
         *
         *   1. A RECORDED INTENT. The server decides what is being bought before the buyer
         *      leaves, and the return reads the plan from that record rather than from the
         *      `Crypt` route parameter -- which is forgeable, since `APP_KEY` is in git history.
         *      Without an intent there is nothing the payment can be matched against, so the
         *      activation refuses.
         *   2. A PROVIDER CONFIRMATION. The return asks Paytm directly, server to server. That
         *      call is unreachable from this container, so the verifier is bound to a double that
         *      answers VERIFIED with the figures the intent expects. **The double is the only way
         *      this path is testable at all**: the SDK's transport is `callAPI()`, a global using
         *      curl, which `Http::fake()` cannot intercept -- which is exactly why
         *      `PaytmTransactionVerifier` exists as a seam rather than being inlined.
         *
         * The ASSERTION is unchanged: a legitimate purchase must still activate. What changed is
         * that "legitimate" now includes the provider agreeing and the server having said in
         * advance what it was selling.
         */
        $reference = \App\Services\PlanPurchaseIntent::create([
            'provider' => 'Paytm',
            'user_id' => $merchant->id,
            'plan_id' => $plan->id,
            'expected_amount' => '499.00',
            'currency' => 'USD',
        ]);

        $this->assertNotNull($reference, 'premise: the purchase intent was not recorded');

        $this->app->instance(\App\Services\PaytmTransactionVerifier::class, new class extends \App\Services\PaytmTransactionVerifier {
            public function verify(string $reference): array
            {
                return [
                    'state' => \App\Services\PaytmTransactionVerifier::VERIFIED,
                    'amount' => '499.00',
                    'currency' => 'USD',
                    'txn_id' => 'PAYTM-CONFIRMED',
                    'reason' => '',
                ];
            }
        });

        $this->paytmReturn($this->paytmBody(['ORDERID' => $reference]), $plan->id, $merchant);

        $this->assertSame(
            1,
            (int) DB::table('plan_orders')->count(),
            'A correctly checksummed Paytm return, with a recorded intent and a provider that '
            . 'confirms the payment, no longer activates the plan. The refusal is too wide.'
        );

        $this->assertSame(
            \App\Services\PlanPurchaseIntent::CONSUMED,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            'the intent was not consumed by the activation it authorised, so the same payment '
            . 'could be presented again'
        );
    }

    /**
     * A REPLAYED PAYTM TRANSACTION ID IS REFUSED.
     *
     * THE FIRST VERSION OF THIS CASE POSTED THE SAME BODY TWICE AND PASSED AGAINST THE UNGUARDED
     * HANDLER — and it was right to be suspicious, because a scratch probe doing exactly that had
     * MEASURED two rows. The difference is the clock. `$orderID = time()` at
     * `PaytmPaymentController::planPayWithPaytmReturn()` and `plan_orders.order_id` is UNIQUE, so two posts inside the
     * same second collide on the constraint and the second is swallowed by the handler's own
     * catch; the probe's two posts happened to straddle a second boundary (`order_id` 1793436424
     * then 1793436425) and both landed. So a double-post test measures **what second it ran in**,
     * and the unique index is not a replay control: it keys on a per-call `time()`, not on
     * anything the provider said.
     *
     * Seeding the spent reference instead is deterministic and measures the guard itself, which is
     * what the other three providers' cases do.
     */
    public function test_paytm_refuses_a_transaction_id_that_already_activated_a_plan(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);
        $cheap = $this->makePlan('10');
        $dear = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        $body = $this->paytmBody();
        $this->recordSpentReference('Paytm', (string) $body['TXNID'], $cheap->id, $merchant->id);

        $before = (int) DB::table('plan_orders')->count();

        // The same transaction, now presented against the dearer plan.
        $this->paytmReturn($body, $dear->id, $merchant);

        $this->assertSame(
            $before,
            (int) DB::table('plan_orders')->count(),
            'A Paytm transaction id that already activated a plan activated a second one. A valid '
            . 'checksum stays valid on a re-POST, so cryptography is no defence against replay.'
        );

        $this->assertNotSame(
            $dear->id,
            (int) $merchant->fresh()->plan_id,
            'A replayed Paytm transaction upgraded the buyer to the dearer plan.'
        );

        $this->assertTheGuardRefused('Paytm');
    }

    /** @return array<int,array{0:int,1:string,2:int}|string> the file's tokens, comments dropped */
    private function codeTokensOf(string $relativePath): array
    {
        $source = (string) file_get_contents(base_path($relativePath));

        return array_values(array_filter(
            token_get_all($source),
            fn ($t) => ! (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
        ));
    }
}
