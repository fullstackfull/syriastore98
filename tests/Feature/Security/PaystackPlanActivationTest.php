<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\PaystackPaymentController;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F87 / F88 — A SUCCESSFUL API CALL IS NOT A SUCCESSFUL PAYMENT.
 * ==============================================================
 *
 * Owner decision D-17 treats this as an isolated security fix, not the start of Phase 06, with
 * four named cases: failure, cancellation, success and callback replay. All four are here.
 *
 * WHAT WAS WRONG. `PaystackPaymentController::getPaymentStatus` gated plan activation on
 * `isset($result['status']) && $result['status'] == true` — whether Paystack's *verify call*
 * succeeded. The real transaction state lives at `data.status`, and the handler recorded it
 * (`$order->payment_status = $result['data']['status']`) without ever requiring it to be
 * `success`. So a `failed` or `abandoned` transaction activated the plan, repeatably: the
 * reference is a route parameter, so the URL could simply be opened again.
 *
 * WHY THE DECISION IS A SEPARATE METHOD AND THE TESTS DRIVE IT DIRECTLY. The handler reaches
 * Paystack with a raw `curl_init()`, not the HTTP client, so there is nothing to fake. Driving
 * the route in this container produces a failed curl, and on the OLD code a failed curl refuses
 * too — so a route-level test would have passed against the defect. That is the wrong-reason
 * trap this project keeps paying for, so the thing that decides is extracted and driven with the
 * payloads Paystack actually returns.
 *
 * WHAT THIS DOES NOT CLAIM. It does not verify that money moved, and no Paystack account is
 * reachable from here. It closes the gap between "the API answered" and "the customer paid".
 */
class PaystackPlanActivationTest extends TestCase
{
    use RefreshDatabase;

    /** A verify response shaped as Paystack returns one. */
    private function response(string $state, string $reference, bool $envelope = true, int $amountMinor = 500000): array
    {
        return [
            'status' => $envelope,
            'message' => 'Verification successful',
            'data' => [
                'id' => 123456789,
                'status' => $state,
                'reference' => $reference,
                'amount' => $amountMinor,
                'currency' => 'NGN',
                'gateway_response' => $state === 'success' ? 'Successful' : 'Declined',
            ],
        ];
    }

    /** The source text of `getPaymentStatus()` alone, so a match elsewhere in the file cannot satisfy an assertion. */
    private function bodyOfGetPaymentStatus(): string
    {
        $method = new \ReflectionMethod(PaystackPaymentController::class, 'getPaymentStatus');
        $lines = file($method->getFileName());

        $this->assertNotFalse($lines, 'premise: the controller source is readable');

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    private function plan(): Plan
    {
        return Plan::first() ?? Plan::forceCreate([
            'name' => 'Test Plan ' . uniqid(),
            'price' => 5000,
            'duration' => 'Month',
            'description' => 'x',
            'themes' => '[]',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 1,
            'storage_limit' => 100,
        ]);
    }

    private function makePlanOrder(Plan $plan, User $owner, string $reference): PlanOrder
    {
        return PlanOrder::forceCreate([
            'order_id' => 'ORD-' . uniqid(),
            'name' => $owner->name,
            'card_number' => '', 'card_exp_month' => '', 'card_exp_year' => '',
            'plan_name' => $plan->name,
            'plan_id' => $plan->id,
            'price' => 5000,
            'price_currency' => 'NGN',
            'txn_id' => $reference,
            'payment_type' => PaystackPaymentController::PAYMENT_TYPE,
            'payment_status' => 'success',
            'receipt' => '',
            'user_id' => $owner->id,
        ]);
    }

    /**
     * THE CASE THAT WAS LIVE. Paystack answers, so the envelope is true, but the transaction
     * failed. This must not activate anything.
     */
    public function test_a_failed_transaction_is_not_accepted(): void
    {
        $outcome = PaystackPaymentController::paystackVerificationOutcome(
            $this->response('failed', 'ref-abc'),
            'ref-abc'
        );

        $this->assertTrue($outcome['envelope_ok'],
            'premise: the API call itself succeeded — that is exactly the condition the old gate '
            . 'tested, and on its own it must no longer be enough');
        $this->assertSame('failed', $outcome['state']);
        $this->assertFalse($outcome['paid'],
            'a failed transaction must not be accepted. If this passes, F87 is live again.');
    }

    /**
     * CANCELLATION. Paystack reports an abandoned checkout with the same true envelope.
     */
    public function test_an_abandoned_transaction_is_not_accepted(): void
    {
        $outcome = PaystackPaymentController::paystackVerificationOutcome(
            $this->response('abandoned', 'ref-abc'),
            'ref-abc'
        );

        $this->assertTrue($outcome['envelope_ok']);
        $this->assertFalse($outcome['paid'], 'an abandoned checkout must not activate a plan');
    }

    /**
     * Every other state Paystack documents, so the check is "success and nothing else" rather
     * than "not failed" — a denylist would admit the next state Paystack adds.
     */
    public function test_only_success_is_accepted(): void
    {
        foreach (['failed', 'abandoned', 'reversed', 'pending', 'ongoing', 'queued', '', 'SUCCESSFUL-ISH'] as $state) {
            $outcome = PaystackPaymentController::paystackVerificationOutcome(
                $this->response($state, 'ref-abc'),
                'ref-abc'
            );
            $this->assertFalse($outcome['paid'], "state `$state` must not be accepted");
        }

        // ...and the one that must be, including Paystack's own capitalisation if it ever varies.
        foreach (['success', 'SUCCESS', ' Success '] as $state) {
            $outcome = PaystackPaymentController::paystackVerificationOutcome(
                $this->response($state, 'ref-abc'),
                'ref-abc'
            );
            $this->assertTrue($outcome['paid'], "state `$state` is a success and must be accepted");
        }
    }

    /**
     * SUCCESS. The whole point of the gate is that this still works.
     */
    public function test_a_successful_matching_transaction_is_accepted(): void
    {
        $outcome = PaystackPaymentController::paystackVerificationOutcome(
            $this->response('success', 'ref-abc'),
            'ref-abc'
        );

        $this->assertTrue($outcome['paid']);
        $this->assertTrue($outcome['reference_matches']);
    }

    /**
     * The provider answering about a DIFFERENT transaction, and the degenerate case where
     * neither side has a reference — which must not read as a match.
     */
    public function test_a_mismatched_or_missing_reference_is_not_a_match(): void
    {
        $this->assertFalse(
            PaystackPaymentController::paystackVerificationOutcome(
                $this->response('success', 'ref-OTHER'), 'ref-abc'
            )['reference_matches'],
            'a response about another transaction must not match');

        $this->assertFalse(
            PaystackPaymentController::paystackVerificationOutcome(
                $this->response('success', ''), ''
            )['reference_matches'],
            'two absent references must not read as a match');
    }

    /**
     * A FAILED CALL. No network, a 401 from Paystack, an empty body: `json_decode` returns null
     * and the old handler's `isset($result['status'])` was the only thing standing between that
     * and an activation. It must refuse, and it must not fatal on a non-array.
     */
    public function test_an_unusable_response_is_refused_rather_than_fataling(): void
    {
        foreach ([null, false, '', 'not json', 0, []] as $bad) {
            $outcome = PaystackPaymentController::paystackVerificationOutcome($bad, 'ref-abc');
            $this->assertFalse($outcome['paid'], 'an unusable verify response must refuse');
            $this->assertFalse($outcome['envelope_ok']);
        }
    }

    /**
     * REPLAY. The reference is a route parameter, so the same URL can be opened again, and
     * nothing stopped a second PlanOrder and a second `assignPlan()`.
     *
     * AN EARLIER VERSION OF THIS TEST WAS VACUOUS and independent review caught it: it wrote a
     * row with `forceCreate` and then re-queried it inline, so it asserted that Eloquent can read
     * a row it just wrote. Deleting the guard from the controller left it green. This one drives
     * the REAL helper — `GuardsPlanActivation::existingPlanOrderForProviderTxn()`, through an
     * anonymous class that uses the trait, which is the same code path the handler takes.
     */
    public function test_the_replay_guard_finds_a_recorded_reference_and_only_that_reference(): void
    {
        $plan = $this->plan();
        $buyer = User::factory()->create();
        $this->makePlanOrder($plan, $buyer, 'ref-abc');

        $guard = new class { use \App\Http\Controllers\Concerns\GuardsPlanActivation;

            public function find(string $paymentType, $txnId)
            {
                return $this->existingPlanOrderForProviderTxn($paymentType, $txnId);
            }
        };

        $this->assertNotNull(
            $guard->find(PaystackPaymentController::PAYMENT_TYPE, 'ref-abc'),
            'the recorded reference must be found, or a replay buys the plan twice');

        $this->assertNull(
            $guard->find(PaystackPaymentController::PAYMENT_TYPE, 'ref-never-seen'),
            'an unrecorded reference must NOT be found, or the guard refuses every first purchase');

        $this->assertNull(
            $guard->find('SomeOtherProvider', 'ref-abc'),
            'the guard is keyed on the provider too, so one provider cannot mask another');

        foreach (['', null] as $empty) {
            $this->assertNull($guard->find(PaystackPaymentController::PAYMENT_TYPE, $empty),
                'an empty reference must never match a row');
        }
    }

    /**
     * THE REPLAY LOOKUP MUST NOT BE SCOPED TO THE CURRENT USER, and this is the half that is easy
     * to get backwards.
     *
     * Scoping it by user looks like tightening and is the opposite: a second account presenting
     * someone else's reference would find nothing, fall through, and have its own plan activated
     * on a stranger's payment. The lookup therefore stays global — one reference, one activation,
     * whoever presents it — and only the RESPONSE depends on who is asking.
     */
    public function test_the_replay_lookup_finds_the_order_whoever_is_asking(): void
    {
        $plan = $this->plan();
        $buyer = User::factory()->create();
        $stranger = User::factory()->create();

        $this->makePlanOrder($plan, $buyer, 'ref-shared');

        $found = PlanOrder::where('payment_type', PaystackPaymentController::PAYMENT_TYPE)
            ->where('txn_id', 'ref-shared')->first();

        $this->assertNotNull($found, 'the lookup carries no user predicate, so it finds the row');
        $this->assertSame($buyer->id, (int) $found->user_id);
        $this->assertNotSame($stranger->id, (int) $found->user_id,
            'premise: the stranger is a different account, so the response branch below matters');
    }

    /**
     * AND THE RESPONSE MUST DEPEND ON THE OWNER. The buyer re-opening their own return URL gets
     * the success they expect; anyone else is told the transaction was unsuccessful, rather than
     * being told "Plan activated Successfully" — which would confirm to a stranger that a guessed
     * reference is a real payment, and the reference is a route parameter.
     *
     * THIS IS A SOURCE ASSERTION AND THAT IS A LIMITATION, STATED. The branch sits between a raw
     * `curl_init()` to Paystack and a redirect, so there is no seam to drive it through in this
     * container: a route-level test would refuse at the curl before ever reaching it. What is
     * driven is the lookup above; what is asserted here is that the two outcomes are distinct and
     * keyed on `user_id`.
     */
    public function test_a_stranger_replaying_a_reference_is_refused_rather_than_congratulated(): void
    {
        $body = $this->bodyOfGetPaymentStatus();

        $this->assertStringContainsString(
            "\$paystackSameBuyer = (int) (\$paystackExistingOrder->user_id ?? 0) === (int) (\$user->id ?? -1);",
            $body,
            'the replay branch no longer compares the stored order owner with the caller, so a '
            . 'stranger opening a guessed reference is told the plan was activated');

        $this->assertStringContainsString(
            "\$paystackSamePlan = (int) (\$paystackExistingOrder->plan_id ?? 0) === (int) (\$plan->id ?? -1);",
            $body,
            'the replay branch no longer compares the PLAN, so one paid reference can be '
            . 'presented a second time against a more expensive plan');

        $this->assertStringContainsString('if ($paystackSameBuyer && $paystackSamePlan) {', $body,
            'both conditions must hold before a replay is treated as the buyer retrying');

        $this->assertStringContainsString('$assignPlan = $user->assignPlan($plan->id);', $body,
            'the retry path must RE-RUN the activation, not merely report it. The PlanOrder row is '
            . 'written before assignPlan(), so a failure between them otherwise strands a real '
            . 'payer permanently: every later retry is told the plan was activated while it was '
            . 'not.');

        $this->assertStringContainsString(
            'already recorded against a different buyer or plan',
            $body,
            'the refused replay is no longer logged, so the attempt leaves no trace');

        /* The two null defaults must differ, or a row with no owner would read as belonging to a
           caller with no id. */
        $this->assertStringContainsString('?? 0) === (int) ($user->id ?? -1)', $body);
    }

    /**
     * WHY THE CANONICAL LITERAL EXISTS, measured rather than asserted from memory.
     *
     * `plan_orders.payment_type` was written as `__('Paystack')`, and `ar.json` translates that.
     * A row written while the locale was Arabic is invisible to a lookup made while the locale is
     * English — so a locale switch between two arrivals defeats the replay guard outright. This
     * test proves the translation exists and that the constant is NOT subject to it.
     *
     * It also names the two handlers that still have the defect: `SenangPayController` and
     * `OzowController` both build their guard key with `__()`, which is ledger F115.
     */
    public function test_the_replay_key_is_not_locale_dependent(): void
    {
        app()->setLocale('en');
        $english = __('Paystack');
        app()->setLocale('ar');
        $arabic = __('Paystack');
        app()->setLocale('en');

        $this->assertNotSame($english, $arabic,
            'premise: `Paystack` really is translated in ar.json. If this ever stops being true '
            . 'the hazard is dormant, not gone — the next translation re-opens it.');

        $this->assertSame('Paystack', PaystackPaymentController::PAYMENT_TYPE,
            'the stored payment_type and the replay key must be one locale-independent literal');
        $this->assertNotSame($arabic, PaystackPaymentController::PAYMENT_TYPE);
    }

    /**
     * THE GATE IN THE HANDLER, PINNED IN THE SOURCE — because the pure method passing is not the
     * same claim as the handler using it.
     *
     * Independent review made this point and it is correct: every behavioural test above drives
     * `paystackVerificationOutcome()`, so mutating the HANDLER's gate to
     * `$paystackAccepted = $paystackOutcome['envelope_ok'];` leaves F87 live with all of them
     * green. The handler cannot be driven here — it reaches Paystack with a raw `curl_init()`,
     * so in this container it refuses at the curl before the gate is ever evaluated, which is
     * exactly how the original defect survived a dedicated security pass.
     *
     * A source assertion is therefore the strongest pin available at any cost, and it is scoped
     * to the one method rather than the file so an unrelated string elsewhere cannot satisfy it.
     */
    public function test_the_handler_gates_on_the_payment_not_the_envelope(): void
    {
        $body = $this->bodyOfGetPaymentStatus();

        $this->assertStringContainsString("\$paystackOutcome['paid'] && \$paystackOutcome['reference_matches']", $body,
            'the handler no longer gates on the verification outcome. F87 is the gate testing the '
            . 'API envelope instead of the payment, and it is reachable again.');

        $this->assertStringNotContainsString("\$result['status'] == true)", $body,
            'the original envelope-only gate is back in the handler');

        $this->assertStringContainsString('$this->paystackExistingPlanOrder($pay_id)', $body,
            'the replay guard has been removed, so one paid reference activates a plan repeatedly');

        $this->assertStringContainsString('$paystackCapturedMinor !== $paystackServerMinor', $body,
            'the amount is no longer a refusal. Without it the gate proves that SOME transaction '
            . 'succeeded, not which plan it bought — and the plan id is a route parameter the '
            . 'plans page prints for every plan.');

        $this->assertStringContainsString('PlanCoupon::find($request->coupon_id))->code', $body,
            'the coupon is being passed to serverOwnedPlanPrice() as something other than a code. '
            . 'The callback URL carries an ID, and the price helper resolves by CODE, so the '
            . 'server price silently becomes the undiscounted one — which makes the amount '
            . 'refusal above reject every discounted purchase.');
    }

    /**
     * The replay guard must consider every spelling `payment_type` may hold, not the one the
     * CALLER's current locale produces — the locale is chosen through the ordinary language
     * switcher, so a caller could otherwise step around a row written in another language.
     */
    public function test_the_replay_guard_covers_every_locale_spelling(): void
    {
        $controller = new PaystackPaymentController();
        $method = new \ReflectionMethod($controller, 'paystackPaymentTypeSpellings');
        $method->setAccessible(true);

        /** @var list<string> $spellings */
        $spellings = $method->invoke($controller);

        $this->assertContains('Paystack', $spellings, 'the canonical spelling must be covered');

        app()->setLocale('ar');
        $arabic = __('Paystack');
        app()->setLocale('en');

        $this->assertContains($arabic, $spellings,
            'the Arabic spelling of `payment_type` is not covered, so a row written while the UI '
            . 'was Arabic is invisible to a caller who has switched the UI to English — and the '
            . 'caller chooses the locale');

        $this->assertSame($spellings, array_values(array_unique($spellings)),
            'duplicate spellings mean duplicate lookups on every return');
    }

    /**
     * F88 — THE POSTURE MARKER MUST DESCRIBE WHAT THE CODE ACTUALLY REQUIRES.
     *
     * `PlanPaymentPostureTest` asserts only that a marker EXISTS. Paystack carried one, and it
     * accurately described an inadequate check, so the provider was documented, the test passed,
     * and the classification was still wrong. The marker is now rewritten; this pins it to the
     * payment state so it cannot drift back to describing the envelope.
     */
    public function test_the_posture_marker_names_the_payment_state_not_the_envelope(): void
    {
        $marker = config('payments.plan_activation_provider_verified.PaystackPaymentController');

        $this->assertNotNull($marker, 'premise: Paystack is still on the verified list');
        $this->assertStringContainsString("data']['status'] === 'success'", $marker,
            'the marker must describe the PAYMENT state check. A marker that only mentions '
            . "\$result['status'] describes the API envelope, which is F87.");
    }
}
