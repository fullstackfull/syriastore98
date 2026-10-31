<?php

namespace Tests\Feature\Security;

use App\Services\PlanPurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * F229 / D-31 REQUIREMENT 1 — the server-owned purchase intent, driven directly.
 *
 * The intent is the binding the plan-return family never had. Paytm is the sharpest case and the
 * reason the design is shaped this way: its checksum covers `TXNAMOUNT`, so the amount is genuinely
 * provider-attested, but `ReceivePaymentProvider::beginTransaction()` **drops the `plan` key**, so
 * no checksum can ever bind the plan. The plan arrives instead as a `Crypt` route parameter, and
 * `APP_KEY` is in git history and unrotated — an encoding, not a control. Hence the substitution:
 * pay once for the cheapest plan, name a dearer one on the first return.
 *
 * These cases drive the SERVICE. The end-to-end route behaviour is in
 * `PaytmIntentBoundActivationTest`, and the two are deliberately separate: several states here —
 * two concurrent consumptions, an intent owned by another account — cannot be produced by one HTTP
 * request, and a test that pretended otherwise would be measuring the harness.
 *
 * **Money is compared as scaled integer strings, not floats and not `bccomp`.** Requirement 3
 * forbids imprecise float comparison; `bcmath` is NOT loaded here, is not declared in
 * `composer.json`, and is absent from CI's extension list (`ci.yml:70`, `:811`) — so a `bccomp`
 * would fatal rather than compare. The first draft of the service used it. That is measured, not
 * assumed: `extension_loaded('bcmath')` is false.
 */
class PlanPurchaseIntentTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'Paytm';

    public function test_premise_the_intent_record_is_installed_in_this_suite(): void
    {
        $this->assertTrue(
            PlanPurchaseIntent::available(),
            'premise: the plan_purchase_intents table is absent, so nothing below measures anything'
        );
    }

    // ---------------------------------------------------------------------------------------
    // REQUIREMENT 1 — what the intent must bind
    // ---------------------------------------------------------------------------------------

    public function test_an_intent_binds_the_purchaser_plan_coupon_amount_and_currency(): void
    {
        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan('499');
        $coupon = $this->makeCoupon('INTENT1', 10, 5);

        $reference = PlanPurchaseIntent::create([
            'provider' => self::PROVIDER,
            'user_id' => $merchant->id,
            'plan_id' => $plan->id,
            'coupon_id' => $coupon->id,
            'coupon_code' => 'INTENT1',
            'expected_amount' => 449.10,
            'currency' => 'USD',
        ]);

        $this->assertNotNull($reference, 'the intent was not recorded');

        $row = DB::table('plan_purchase_intents')->where('reference', $reference)->first();

        $this->assertSame((int) $merchant->id, (int) $row->user_id, 'the purchaser is not bound');
        $this->assertSame((int) $plan->id, (int) $row->plan_id, 'the plan is not bound');
        $this->assertSame((int) $coupon->id, (int) $row->coupon_id, 'the coupon is not bound');
        $this->assertSame('INTENT1', $row->coupon_code, 'the coupon code is not bound');
        $this->assertSame('USD', $row->currency, 'the currency is not bound');
        $this->assertSame(PlanPurchaseIntent::PENDING, $row->status);
        /*
         * THE STORED RENDERING IS ENGINE-DEPENDENT, AND THAT IS WHY THE COMPARATOR IGNORES IT.
         *
         * An earlier version of this assertion expected the literal '449.1000'. **SQLite returns
         * '449.1'** — it does not enforce `DECIMAL(16, 4)`'s scale, which is the type-affinity
         * difference CLAUDE.md warns about and the reason P01-SEC-13 survived a green suite. MySQL
         * does return '449.1000'; `verify-plan-purchase-intent-on-mysql.php` asserts that on the
         * engine where it is true.
         *
         * So the thing worth pinning here is not the rendering but that the stored value is the
         * intended money under the comparison the production code actually performs — which
         * normalises both sides to scaled integers and is therefore immune to the difference.
         */
        $this->assertSame(
            PlanPurchaseIntent::VERIFIED,
            PlanPurchaseIntent::verifyAndConsume(
                self::PROVIDER, $reference, $merchant->id, '449.10', 'USD', 'TXN-SCALE'
            )[0],
            'the stored expected amount does not compare equal to the money it was created with, '
            . 'so the intent cannot be satisfied by the payment it describes'
        );
    }

    /**
     * THE REFERENCE IS RANDOM, NOT THE CLOCK — and that is a fix, not a preference.
     *
     * The Paytm initiator used `date('Y-m-d') . '-' . strtotime(...)`, which is second-resolution:
     * two purchases in the same second produced the same reference. F228 measured the same defect
     * on `plan_orders.order_id`, whose UNIQUE constraint was keying on `time()` rather than on
     * anything the provider said, so two posts a second apart both landed. A collision here would
     * merge two payments onto one intent, which is the worst available outcome.
     */
    public function test_two_intents_in_the_same_second_do_not_collide(): void
    {
        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan('499');

        $references = [];

        for ($i = 0; $i < 25; $i++) {
            $references[] = PlanPurchaseIntent::create([
                'provider' => self::PROVIDER,
                'user_id' => $merchant->id,
                'plan_id' => $plan->id,
                'expected_amount' => 499,
                'currency' => 'USD',
            ]);
        }

        $this->assertCount(25, array_unique($references), 'two intents share a reference');
        $this->assertNotContains(null, $references, 'an intent failed to record');
    }

    // ---------------------------------------------------------------------------------------
    // REQUIREMENTS 3 and 4 — amount and currency, matched exactly
    // ---------------------------------------------------------------------------------------

    public function test_a_matching_payment_is_verified_and_consumes_the_intent(): void
    {
        [$merchant] = $this->makeTenant();
        $reference = $this->intentFor($merchant, '499.00');

        [$state, $intent, $reason] = PlanPurchaseIntent::verifyAndConsume(
            self::PROVIDER, $reference, $merchant->id, '499.00', 'USD', 'TXN-1'
        );

        $this->assertSame(PlanPurchaseIntent::VERIFIED, $state, 'a matching payment was refused: ' . $reason);
        $this->assertSame(PlanPurchaseIntent::CONSUMED,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'));
        $this->assertSame('TXN-1',
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('provider_txn_id'));
    }

    /** THE SUBSTITUTION ITSELF: paying less than the intent says must refuse. */
    public function test_a_cheaper_payment_than_the_intent_is_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $reference = $this->intentFor($merchant, '499.00');

        [$state, , $reason] = PlanPurchaseIntent::verifyAndConsume(
            self::PROVIDER, $reference, $merchant->id, '1.00', 'USD', 'TXN-2'
        );

        $this->assertSame(
            PlanPurchaseIntent::REFUSED_MISMATCH,
            $state,
            'a payment of 1.00 against an intent of 499.00 was accepted. This is F229: pay once '
            . 'for the cheapest plan, name a dearer one on the return.'
        );
        $this->assertStringContainsString('amount mismatch', $reason);
        $this->assertSame(PlanPurchaseIntent::PENDING,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            'a refused payment consumed the intent, so the buyer cannot complete it correctly later');
    }

    public function test_the_wrong_currency_is_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $reference = $this->intentFor($merchant, '499.00');

        [$state, , $reason] = PlanPurchaseIntent::verifyAndConsume(
            self::PROVIDER, $reference, $merchant->id, '499.00', 'INR', 'TXN-3'
        );

        $this->assertSame(PlanPurchaseIntent::REFUSED_MISMATCH, $state, 'a currency mismatch was accepted');
        $this->assertStringContainsString('currency mismatch', $reason);
    }

    /**
     * EXACT NUMERIC HANDLING — the requirement-3 cases, each one a way a float would get it wrong.
     *
     * `499.00`, `499` and `499.0000` are the same money and must match. `499.00001` is NOT, and
     * must NOT be rounded into a match — truncating to the comparison scale is precisely how a
     * short payment comes to look exact. `1e3` and `abc` are not decimal numbers and refuse rather
     * than being coerced to 1000 and 0.
     */
    public function test_equal_amounts_written_differently_all_match(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (['499.00', '499', '499.0000', '499.00000'] as $reported) {
            $reference = $this->intentFor($merchant, '499.00');

            [$state, , $reason] = PlanPurchaseIntent::verifyAndConsume(
                self::PROVIDER, $reference, $merchant->id, $reported, 'USD', 'TXN-EQ'
            );

            $this->assertSame(
                PlanPurchaseIntent::VERIFIED,
                $state,
                'the reported amount ' . var_export($reported, true) . ' is the same money as '
                . '499.00 and was refused: ' . $reason
            );
        }
    }

    public function test_amounts_that_are_not_exactly_equal_or_not_decimal_numbers_are_refused(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (['499.01', '498.99', '499.00001', '1e3', 'abc', '', '499,00'] as $reported) {
            $reference = $this->intentFor($merchant, '499.00');

            [$state] = PlanPurchaseIntent::verifyAndConsume(
                self::PROVIDER, $reference, $merchant->id, $reported, 'USD', 'TXN-NE'
            );

            $this->assertSame(
                PlanPurchaseIntent::REFUSED_MISMATCH,
                $state,
                'the reported amount ' . var_export($reported, true) . ' was accepted against an '
                . 'intent of 499.00. Note 499.00001 in particular: truncating to the comparison '
                . 'scale would make a short payment look exact.'
            );
        }
    }

    /**
     * THE ONE CASE THAT ACTUALLY SEPARATES EXACT COMPARISON FROM FLOAT EQUALITY.
     *
     * **This is written because the other amount tests do NOT separate them, which was measured
     * rather than assumed.** Replacing the whole comparison with naive
     * `(float) $a != (float) $b` leaves all of them passing: `abc`, `''` and `499,00` are caught
     * earlier by the `is_numeric()` guard, `1e3` differs by enough that a float sees it, and
     * `499.01` is an ordinary difference. So those cases pin the PARSE, not the comparison.
     *
     * Float64 carries about 15–16 significant decimal digits. At plan-price magnitudes the way to
     * exceed that is precision rather than size, and `(float) '499.0000' == (float)
     * '499.00000000000001'` is **TRUE** — the two strings are different money and a float
     * comparison cannot tell. The exact comparator refuses it, because non-zero digits beyond the
     * comparison scale are a real difference and rounding them away is how a short payment comes
     * to look exact.
     *
     * Admitted plainly: a provider is unlikely to report fifteen decimal places. The reason this
     * test exists is that requirement 3 forbids imprecise floating-point comparison of money as a
     * **rule**, and a rule with no test behind it is how the `GatewayReference` `bool` came to mean
     * three different things. This is the test that makes the rule enforceable.
     */
    public function test_a_difference_float_equality_cannot_see_is_still_refused(): void
    {
        [$merchant] = $this->makeTenant();

        $this->assertTrue(
            (float) '499.0000' == (float) '499.00000000000001',
            'premise: float equality now distinguishes these, so this test no longer separates '
            . 'exact comparison from float comparison and should be replaced by one that does'
        );

        $reference = $this->intentFor($merchant, '499.00');

        [$state] = PlanPurchaseIntent::verifyAndConsume(
            self::PROVIDER, $reference, $merchant->id, '499.00000000000001', 'USD', 'TXN-FP'
        );

        $this->assertSame(
            PlanPurchaseIntent::REFUSED_MISMATCH,
            $state,
            'an amount a float comparison cannot distinguish from the intent was ACCEPTED. The '
            . 'comparison has fallen back to float equality, which requirement 3 forbids.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // REQUIREMENT 5 — replay, double activation, cross-user reuse, concurrency
    // ---------------------------------------------------------------------------------------

    public function test_a_replayed_payment_cannot_consume_the_intent_twice(): void
    {
        [$merchant] = $this->makeTenant();
        $reference = $this->intentFor($merchant, '499.00');

        [$first] = PlanPurchaseIntent::verifyAndConsume(
            self::PROVIDER, $reference, $merchant->id, '499.00', 'USD', 'TXN-R'
        );
        [$second, , $reason] = PlanPurchaseIntent::verifyAndConsume(
            self::PROVIDER, $reference, $merchant->id, '499.00', 'USD', 'TXN-R'
        );

        $this->assertSame(PlanPurchaseIntent::VERIFIED, $first);
        $this->assertSame(
            PlanPurchaseIntent::REFUSED_MISMATCH,
            $second,
            'one payment consumed the same intent twice, so one payment can activate two plans'
        );
        $this->assertStringContainsString('already consumed', $reason);
    }

    /**
     * CROSS-USER ISOLATION, and the ORDER of the checks is part of the claim.
     *
     * A stranger presenting somebody else's reference is refused on the PURCHASER check, which
     * runs before the amount is compared — so the refusal reason cannot tell them what that
     * purchase was worth. A probe learns nothing it did not already have.
     */
    public function test_another_account_cannot_consume_someone_elses_intent(): void
    {
        [$owner] = $this->makeTenant();
        [$stranger] = $this->makeTenant();
        $reference = $this->intentFor($owner, '499.00');

        [$state, , $reason] = PlanPurchaseIntent::verifyAndConsume(
            self::PROVIDER, $reference, $stranger->id, '499.00', 'USD', 'TXN-X'
        );

        $this->assertSame(PlanPurchaseIntent::REFUSED_MISMATCH, $state, 'a stranger consumed another account\'s intent');
        $this->assertStringContainsString('different purchaser', $reason);
        $this->assertStringNotContainsString('499', $reason, 'the refusal disclosed the purchase amount to a stranger');

        $this->assertSame(PlanPurchaseIntent::PENDING,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            'a stranger\'s attempt consumed the owner\'s intent, denying them their own purchase');
    }

    public function test_an_unknown_reference_is_unverifiable_and_not_a_refusal(): void
    {
        [$merchant] = $this->makeTenant();

        [$state, $intent, $reason] = PlanPurchaseIntent::verifyAndConsume(
            self::PROVIDER, 'no-such-reference', $merchant->id, '499.00', 'USD', 'TXN-U'
        );

        $this->assertSame(
            PlanPurchaseIntent::UNVERIFIABLE,
            $state,
            'an unknown reference must be UNVERIFIABLE -- absence of evidence -- rather than a '
            . 'mismatch, because the two mean different things to the operator reading the log. '
            . 'Requirement 8 makes both a refusal at the call site.'
        );
        $this->assertNull($intent);
        $this->assertNotSame('', $reason);
    }

    /** An intent recorded for one provider is not consumable through another's return. */
    public function test_an_intent_is_scoped_to_its_provider(): void
    {
        [$merchant] = $this->makeTenant();
        $reference = $this->intentFor($merchant, '499.00');

        [$state] = PlanPurchaseIntent::verifyAndConsume(
            'Benefit', $reference, $merchant->id, '499.00', 'USD', 'TXN-P'
        );

        $this->assertSame(PlanPurchaseIntent::UNVERIFIABLE, $state,
            'a Paytm intent was consumable through a Benefit return');
    }

    // ---------------------------------------------------------------------------------------
    // REQUIREMENTS 5 and 9 — the recovery record, and nothing more than a record
    // ---------------------------------------------------------------------------------------

    public function test_a_refusal_is_recorded_with_its_reason_and_surfaced_for_review(): void
    {
        [$merchant] = $this->makeTenant();
        $reference = $this->intentFor($merchant, '499.00');

        PlanPurchaseIntent::recordRefusal(self::PROVIDER, $reference, 'amount mismatch: a test', 'TXN-REC');

        $row = DB::table('plan_purchase_intents')->where('reference', $reference)->first();

        $this->assertSame(PlanPurchaseIntent::REFUSED, $row->status);
        $this->assertStringContainsString('amount mismatch', $row->failure_reason);
        $this->assertSame('TXN-REC', $row->provider_txn_id,
            'the provider reference was not recorded, so the refusal cannot be reconciled against a statement');

        DB::table('plan_purchase_intents')->where('reference', $reference)
            ->update(['created_at' => now()->subHours(2)]);

        $this->assertNotCount(0, PlanPurchaseIntent::unreconciled(30),
            'a captured payment that activated nothing is not surfaced for review');
    }

    /** A refusal must never overwrite a successful activation. */
    public function test_a_later_refusal_cannot_relabel_a_consumed_intent(): void
    {
        [$merchant] = $this->makeTenant();
        $reference = $this->intentFor($merchant, '499.00');

        PlanPurchaseIntent::verifyAndConsume(self::PROVIDER, $reference, $merchant->id, '499.00', 'USD', 'TXN-OK');
        PlanPurchaseIntent::recordRefusal(self::PROVIDER, $reference, 'a late retry', 'TXN-OK');

        $this->assertSame(
            PlanPurchaseIntent::CONSUMED,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            'a buyer who paid and refreshed the page had their successful activation relabelled a failure'
        );
    }

    public function test_attaching_the_order_is_idempotent_and_cannot_be_repointed(): void
    {
        [$merchant] = $this->makeTenant();
        $reference = $this->intentFor($merchant, '499.00');

        PlanPurchaseIntent::attachOrder(self::PROVIDER, $reference, 4242);
        PlanPurchaseIntent::attachOrder(self::PROVIDER, $reference, 9999);

        $this->assertSame(4242,
            (int) DB::table('plan_purchase_intents')->where('reference', $reference)->value('plan_order_id'),
            'a retried return re-pointed the intent at a second order');
    }

    // ---------------------------------------------------------------------------------------
    // REQUIREMENT 8 — absence of the record is a refusal, never a pass
    // ---------------------------------------------------------------------------------------

    /**
     * WITH NO RECORD INSTALLED, NOTHING VERIFIES — which is production's shape by design.
     *
     * D-31 is development/test-only, so `available()` is false in production. A caller that read
     * that as "no intent needed" would activate every plan unverified — the exact inversion of
     * what this exists for. So creation returns null (the purchase must not start) and
     * verification answers UNVERIFIABLE (the activation must not proceed).
     */
    public function test_with_no_record_installed_creation_and_verification_both_refuse(): void
    {
        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan('499');

        \Illuminate\Support\Facades\Schema::drop('plan_purchase_intents');

        $this->assertFalse(PlanPurchaseIntent::available(), 'premise: the table is still installed');

        $this->assertNull(
            PlanPurchaseIntent::create([
                'provider' => self::PROVIDER, 'user_id' => $merchant->id, 'plan_id' => $plan->id,
                'expected_amount' => 499, 'currency' => 'USD',
            ]),
            'an intent was reported created with no table to create it in, so the purchase would '
            . 'start against an intent that does not exist'
        );

        [$state] = PlanPurchaseIntent::verifyAndConsume(
            self::PROVIDER, 'anything', $merchant->id, '499.00', 'USD', 'TXN-N'
        );

        $this->assertSame(PlanPurchaseIntent::UNVERIFIABLE, $state,
            'with no record installed the verification answered something other than UNVERIFIABLE');
    }

    public function test_an_intent_missing_its_purchaser_plan_or_currency_is_not_recorded(): void
    {
        [$merchant] = $this->makeTenant();
        $plan = $this->makePlan('499');

        $base = [
            'provider' => self::PROVIDER, 'user_id' => $merchant->id, 'plan_id' => $plan->id,
            'expected_amount' => 499, 'currency' => 'USD',
        ];

        foreach ([
            'no provider' => ['provider' => ''],
            'no currency' => ['currency' => ''],
            'no purchaser' => ['user_id' => null],
            'no plan' => ['plan_id' => 'not-a-number'],
            'no amount' => ['expected_amount' => 'free'],
        ] as $what => $override) {
            $this->assertNull(
                PlanPurchaseIntent::create(array_merge($base, $override)),
                'an intent with ' . $what . ' was recorded, so a payment could be taken against '
                . 'an intent that binds nothing'
            );
        }
    }

    private function intentFor($merchant, string $amount): string
    {
        $plan = $this->makePlan('499');

        return PlanPurchaseIntent::create([
            'provider' => self::PROVIDER,
            'user_id' => $merchant->id,
            'plan_id' => $plan->id,
            'expected_amount' => $amount,
            'currency' => 'USD',
        ]);
    }
}
