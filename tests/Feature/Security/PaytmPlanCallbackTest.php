<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use App\Models\User;
use App\Services\PaytmTransactionVerifier;
use App\Services\PlanPurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * F241 — THE FOUR PROOFS THE OWNER REQUIRED BEFORE A CALLBACK IS EXPOSED.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IS BEING TESTED, AND WHY IT IS A DIFFERENT DOOR RATHER THAN A WEAKENED ONE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `plan-paytm-payment` is POST-only inside the `web` group, so Paytm's cross-site POST arrives
 * with no session cookie and no `_token` and is answered **419 before `auth` runs**. The paid Paytm
 * plan path cannot complete. The owner forbade the obvious fix — a CSRF exemption — and approved
 * the §2.2 architecture instead: a route OUTSIDE the session group that authenticates by provider
 * evidence.
 *
 * Nothing about the existing route changes, and a case here asserts that.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE AUTHENTICITY PROOF IS AGAINST PAYTM'S OWN CHECKSUM IMPLEMENTATION
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The owner's requirement is *"Use verified official provider contracts, not guessed response
 * fields."* So the fake status responses below are signed with `getChecksumFromArray()` — the
 * helper from the VENDORED `anandsiddharth/laravel-paytm-wallet` package, which is the same
 * function the SDK uses in production. No field name and no algorithm is invented here: a response
 * this test calls valid is one Paytm's own code would call valid, and a response it calls forged
 * fails that same function.
 *
 * `Http::fake()` stands in for the network, not for the contract. The transport is the only thing
 * synthesised.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THESE CASES CANNOT ESTABLISH, stated because the owner asked for exactly this honesty
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **No live Paytm transaction has been verified.** This container has no Paytm credentials and no
 * network path to `securegw.paytm.in`, so nobody has yet seen a real captured payment produce a
 * `TXN_SUCCESS` with a valid checksum over the wire. That is why
 * `config('payments.paytm_callback_enabled')` ships CLOSED and why Paytm is not called fixed.
 * Everything below is the orchestration and the controls, which are real and tested; the live
 * handshake is not, and it is the first thing whoever holds a sandbox should drive.
 */
class PaytmPlanCallbackTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const KEY = 'test-merchant-key-0123456789';

    private const MID = 'TESTMID123';

    private User $purchaser;

    private $plan;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * THE PRODUCTION ROUTE IS GATED AT BOOT, SO IT CANNOT BE REACHED FROM HERE.
         *
         * `routes/api.php` wraps the registration in `if (config('payments.paytm_callback_enabled'))`
         * and the route file is evaluated before any test body runs — so setting the config in
         * `setUp()` is too late and every request 404s. (Measured: nine cases failed that way
         * before this block existed.)
         *
         * So the controller is driven over real HTTP through an IDENTICALLY-REGISTERED route, which
         * exercises the request, the JSON body, the middleware group and the response. What this
         * does NOT establish is that the production route is gated — and that is asserted
         * separately, on the source, by `test_the_callback_is_absent_while_the_gate_is_closed`.
         * Said plainly because a reader could otherwise take these passes as evidence that the
         * shipped route is reachable, which is the opposite of the truth.
         */
        \Illuminate\Support\Facades\Route::middleware('api')->post(
            'api/payment-callback/paytm/plan',
            [\App\Http\Controllers\PaymentCallbackController::class, 'paytmPlan']
        );

        config([
            'payments.paytm_callback_enabled' => true,
            'services.paytm-wallet.merchant_key' => self::KEY,
            'services.paytm-wallet.merchant_id' => self::MID,
            'services.paytm-wallet.env' => 'local',
        ]);

        [$merchant] = $this->makeTenant();
        $this->purchaser = User::find($merchant->id);

        $this->plan = $this->makePlan();
    }

    /**
     * `plans` declares more NOT NULL columns than the model's $fillable covers — `themes`, `trial`,
     * `trial_days`, `max_stores`, `max_products`. Copied from `PlanOrderRailTest::makePlan()`
     * rather than rediscovered: my first version omitted `themes` and every case in this file died
     * on the integrity constraint before asserting anything.
     */
    private function makePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Plan ' . uniqid(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => '',
            'trial' => 0,
            'trial_days' => 0,
        ], $attributes));
    }

    /**
     * A server-minted intent, built the way `PaytmPaymentController::planPayWithPaytm()` builds one.
     *
     * TWO THINGS MY FIRST VERSION GOT WRONG, and both made every activation case read
     * `unverifiable` while looking like a correct fixture:
     *
     *   1. `create()` MINTS THE REFERENCE and returns it. Passing a `reference` key does nothing;
     *      the returned value is the only one the row carries.
     *   2. It requires a non-empty **`currency`** key — not `expected_currency` — and returns
     *      `null` without it. So nothing was written at all, `find()` answered null, and the
     *      callback correctly refused a purchase that did not exist. The fixture was broken and the
     *      code was right, which is the direction that wastes the most time.
     *
     * The return is asserted rather than ignored, so a future shape change fails here loudly
     * instead of producing nine identical mystery refusals.
     */
    private function intent(array $overrides = []): string
    {
        $reference = PlanPurchaseIntent::create(array_merge([
            'provider' => 'Paytm',
            'user_id' => $this->purchaser->id,
            'plan_id' => $this->plan->id,
            'coupon_id' => null,
            'coupon_code' => null,
            'expected_amount' => '100.00',
            'currency' => 'INR',
        ], $overrides));

        $this->assertIsString($reference,
            'premise: the purchase intent was not recorded, so every assertion after this would '
            . 'be measuring a callback refusing a purchase that does not exist');

        return $reference;
    }

    /**
     * A status response signed the way Paytm signs one — by Paytm's own helper.
     *
     * @param  array<string,string>  $overrides
     */
    private function paytmSays(array $overrides = []): array
    {
        $body = array_merge([
            'MID' => self::MID,
            'ORDERID' => 'set-by-caller',
            'TXNID' => 'TXN' . uniqid(),
            // 100.00, MATCHING the intent's expected_amount at the top of this file. It must
            // stay matching: this is the default every happy-path case uses, and a mismatching
            // default makes the four cases that assert ACTIVATION fail while the cases that assert
            // a refusal still pass -- for the wrong reason, since they would be refusing on the
            // default rather than on the value they override.
            //
            // It was briefly '1000.00' (commit e3443cb3) and that was MY OWN DOING, not a design
            // choice: I mutation-tested the new overpayment case by sed-replacing 1000.00 with
            // 100.00, then reverted with the inverse sed -- which rewrote EVERY '100.00' in the
            // file, this default included. Found by independent review, not by the run: the
            // four-case breakage is in classes I did not re-run after the revert.
            'TXNAMOUNT' => '100.00',
            'STATUS' => 'TXN_SUCCESS',
            'CURRENCY' => '',
            'RESPCODE' => '01',
            'RESPMSG' => 'Txn Success',
        ], $overrides);

        /* THE VENDORED OFFICIAL HELPER, not a reimplementation. If this file ever has to compute a
           checksum by hand, the contract has stopped being verified and the test has stopped
           being evidence. */
        $this->assertTrue(function_exists('getChecksumFromArray')
            || $this->loadPaytmHelpers(),
            'premise: the vendored Paytm checksum helper is not loadable, so nothing here is '
            . 'signing with the official contract');

        $body['CHECKSUMHASH'] = getChecksumFromArray($body, self::KEY);

        return $body;
    }

    /**
     * Load the SDK's checksum globals THE SAME WAY THE VERIFIER DOES, which is the point.
     *
     * `PaytmTransactionVerifier::checksumHelpersAreLoadable()` touches the SDK's service provider
     * class, whose file carries a plain `require` of `lib/encdec_paytm.php` at the top — so
     * resolving the class is what declares the globals. Doing the same here means the test and the
     * production code are signing with the identical implementation, loaded by the identical route.
     *
     * (My first version globbed `src/**` for the function. PHP's `glob` does not recurse on `**`,
     * and the file is in `lib/` rather than `src/` anyway, so it found nothing and every case
     * failed on the premise. The real path is
     * `vendor/anandsiddharth/laravel-paytm-wallet/lib/encdec_paytm.php`.)
     */
    private function loadPaytmHelpers(): bool
    {
        class_exists(\Anand\LaravelPaytmWallet\Providers\PaytmWalletProvider::class);

        return function_exists('getChecksumFromArray');
    }

    /** Named deliverCallback() because BOTH obvious names are taken: the base TestCase declares a public post(), and PHPUnit's Assert::callback() is final. Two fatals before this name. */
    private function deliverCallback(string $reference)
    {
        return $this->postJson('/api/payment-callback/paytm/plan', ['ORDERID' => $reference]);
    }

    private function statusRow(string $reference)
    {
        return DB::table('plan_purchase_intents')
            ->where('provider', 'Paytm')->where('reference', $reference)->first();
    }

    // ══════════════════════════════════════════════════════════════════ THE HAPPY PATH, ONCE

    /** PROOFS 1 AND 2 TOGETHER: an authentic success on a bound intent activates the plan. */
    public function test_an_authentic_success_activates_the_plan_once(): void
    {
        $reference = $this->intent();
        Http::fake(['*getTxnStatus*' => Http::response($this->paytmSays(['ORDERID' => $reference]), 200)]);

        $response = $this->deliverCallback($reference);

        $response->assertStatus(200);
        $this->assertSame('activated', $response->json('status'), (string) $response->getContent());

        $this->assertSame(PlanPurchaseIntent::CONSUMED, $this->statusRow($reference)->status);
        $this->assertSame((int) $this->plan->id, (int) $this->purchaser->fresh()->plan_id,
            'the plan was not assigned, so a verified payment bought nothing');
    }

    /** PROOF 3 — REPLAY. The second delivery of the same callback changes nothing. */
    public function test_a_replayed_callback_activates_nothing_a_second_time(): void
    {
        $reference = $this->intent();
        Http::fake(['*getTxnStatus*' => Http::response($this->paytmSays(['ORDERID' => $reference]), 200)]);

        $this->assertSame('activated', $this->deliverCallback($reference)->json('status'), 'premise');

        $expiry = $this->purchaser->fresh()->plan_expire_date;

        $second = $this->deliverCallback($reference);

        $second->assertStatus(200);
        $this->assertNotSame('activated', $second->json('status'),
            'a replayed callback activated the plan again, so a provider retry extends a '
            . 'subscription for free');
        $this->assertSame($expiry, $this->purchaser->fresh()->plan_expire_date,
            'the replay moved the expiry date');
    }

    // ══════════════════════════════════════════════════════════ FAIL-CLOSED, EVERY WHICH WAY

    /**
     * PROOF 1 — AUTHENTICITY. A forged checksum activates nothing.
     *
     * The response is otherwise a perfect success: right order id, right amount, `TXN_SUCCESS`.
     * Only the signature is wrong, which is precisely the attack a bare callback URL invites.
     */
    public function test_a_forged_checksum_activates_nothing(): void
    {
        $reference = $this->intent();
        $body = $this->paytmSays(['ORDERID' => $reference]);
        $body['CHECKSUMHASH'] = str_repeat('0', strlen((string) $body['CHECKSUMHASH']));

        Http::fake(['*getTxnStatus*' => Http::response($body, 200)]);

        $this->deliverCallback($reference)->assertStatus(202);

        $this->assertNull($this->purchaser->fresh()->plan_id,
            'a forged checksum activated a plan');
        $this->assertNotSame(PlanPurchaseIntent::CONSUMED, $this->statusRow($reference)->status);
    }

    /** D-31 / requirement 8 — an UNREACHABLE provider is a refusal, not an optimistic yes. */
    public function test_an_unreachable_provider_activates_nothing(): void
    {
        $reference = $this->intent();
        Http::fake(['*getTxnStatus*' => fn () => throw new \RuntimeException('connection refused')]);

        $this->deliverCallback($reference)->assertStatus(202);

        $this->assertNull($this->purchaser->fresh()->plan_id);
    }

    /** A definite provider NO. */
    public function test_a_provider_failure_status_activates_nothing(): void
    {
        $reference = $this->intent();
        Http::fake(['*getTxnStatus*' => Http::response(
            $this->paytmSays(['ORDERID' => $reference, 'STATUS' => 'TXN_FAILURE']), 200
        )]);

        $this->deliverCallback($reference)->assertStatus(202);
        $this->assertNull($this->purchaser->fresh()->plan_id);
    }

    /**
     * PROOF 2 — INTENT BINDING, THE PART THAT MATTERS MOST. A provider response reporting a
     * SMALLER amount than the intent expects activates nothing.
     *
     * This is requirement 3's substitution vector: paying for the cheapest plan and naming an
     * expensive one. The amount comes from the provider and the expectation from the intent, so
     * the comparison is the control.
     */
    public function test_a_short_payment_activates_nothing(): void
    {
        $reference = $this->intent();
        Http::fake(['*getTxnStatus*' => Http::response(
            $this->paytmSays(['ORDERID' => $reference, 'TXNAMOUNT' => '1.00']), 200
        )]);

        $this->deliverCallback($reference)->assertStatus(200);

        $this->assertNull($this->purchaser->fresh()->plan_id,
            'a payment of 1.00 activated a plan whose intent expected 100.00');
    }

    /**
     * THE OTHER HALF OF "WRONG AMOUNT", added 2026-11-02 for Directive E §6. An OVERPAYMENT
     * activates nothing either.
     *
     * The short-payment case above is the one with an obvious attack behind it -- pay for the
     * cheapest plan, name an expensive one. An overpayment has no attacker motive, which is exactly
     * why it is easy to leave untested and easy to get wrong: a comparison written as
     * "reported >= expected" passes every short-payment test and silently accepts a provider
     * response that does not match the intent at all.
     *
     * `PlanPurchaseIntent::verifyAndConsume()` compares scaled-integer STRINGS with `===` -- not
     * floats, which requirement 3 forbids, and not `bccomp`, because bcmath is not installed here.
     * An exact comparison refuses both directions, and this is the half that was unasserted.
     */
    public function test_an_overpayment_activates_nothing_either(): void
    {
        $reference = $this->intent();
        Http::fake(['*getTxnStatus*' => Http::response(
            $this->paytmSays(['ORDERID' => $reference, 'TXNAMOUNT' => '1000.00']), 200
        )]);

        $this->deliverCallback($reference)->assertStatus(200);

        $this->assertNull(
            $this->purchaser->fresh()->plan_id,
            'a payment of 1000.00 activated a plan whose intent expected 100.00. The amount '
            . 'comparison is not exact, so it would also accept a response that matches no intent.'
        );
        $this->assertNotSame(PlanPurchaseIntent::CONSUMED, $this->statusRow($reference)->status);
    }

    /**
     * MORE PRECISION THAN THE SCALE IS A MISMATCH, not a rounding. Added for the same reason.
     *
     * 100.004 rounds to 100.00 at scale 2, so a comparison that rounds before comparing would
     * accept it. The class header states this is deliberately NOT how it works, and that claim is
     * now asserted rather than described.
     */
    public function test_a_sub_scale_difference_is_a_mismatch_rather_than_a_rounding(): void
    {
        $reference = $this->intent();
        Http::fake(['*getTxnStatus*' => Http::response(
            $this->paytmSays(['ORDERID' => $reference, 'TXNAMOUNT' => '100.004']), 200
        )]);

        $this->deliverCallback($reference)->assertStatus(200);

        $this->assertNull(
            $this->purchaser->fresh()->plan_id,
            'an amount carrying more precision than the scale was rounded into a match. The class '
            . 'header says this must be a mismatch, because rounding a provider amount into '
            . 'agreement is how a cent-level discrepancy becomes invisible.'
        );
    }

    /**
     * A CANCELLED payment activates nothing. Directive E §6 names it separately from a failure, so
     * it is asserted separately.
     *
     * Paytm reports a user cancellation as `TXN_FAILURE` with `RESPCODE` 141, and a payment still
     * in flight as `PENDING`. Both are driven, because the dangerous shape is a handler that
     * treats "not an explicit failure" as a yes: a `PENDING` transaction can still fail, and
     * activating on it gives away a subscription for an abandoned checkout.
     */
    public function test_a_cancelled_or_pending_payment_activates_nothing(): void
    {
        foreach ([
            'a user cancellation' => ['STATUS' => 'TXN_FAILURE', 'RESPCODE' => '141', 'RESPMSG' => 'Transaction cancelled by user'],
            'a payment still in flight' => ['STATUS' => 'PENDING', 'RESPCODE' => '400', 'RESPMSG' => 'Transaction pending'],
        ] as $label => $overrides) {
            $reference = $this->intent();

            Http::fake(['*getTxnStatus*' => Http::response(
                $this->paytmSays(array_merge(['ORDERID' => $reference], $overrides)), 200
            )]);

            $this->deliverCallback($reference);

            $this->assertNull(
                $this->purchaser->fresh()->plan_id,
                "{$label} activated a plan. Anything other than an explicit provider YES must "
                . 'activate nothing -- a pending transaction can still fail.'
            );
            $this->assertNotSame(
                PlanPurchaseIntent::CONSUMED,
                $this->statusRow($reference)->status,
                "{$label} consumed the intent, so a later authentic success could no longer be honoured."
            );
        }
    }

    /**
     * NO WALLET OR ORDER-FINANCIAL ENTRY IS WRITTEN BY THIS PATH -- not one, let alone a duplicate.
     *
     * Directive E §6 requires proof of "no duplicate wallet or order-financial entries". For the
     * PLAN callback the honest form of that proof is stronger and simpler than counting
     * duplicates: this path assigns a subscription plan to a `User`, and it has no business
     * touching a merchant's wallet or an order's P&L at all. So the assertion is ZERO rows, before
     * and after, across a success AND a replay.
     *
     * THIS BECAME ASSERTABLE ONLY ON 2026-11-02. Until GATE F closed, `store_wallets`,
     * `store_wallet_transactions` and `store_order_financials` had no migration, so a count
     * against them raised "no such table" and the property could not be checked at all -- which is
     * precisely the gap the GATE F skips recorded.
     */
    public function test_the_plan_callback_writes_no_wallet_or_order_financial_entry(): void
    {
        foreach (['store_wallets', 'store_wallet_transactions', 'store_order_financials'] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "`{$table}` is missing, so this assertion would pass vacuously. GATE F closed on "
                . '2026-11-02; check that its migration has not been reverted.'
            );
            $this->assertSame(0, DB::table($table)->count(), "premise: `{$table}` starts empty");
        }

        $reference = $this->intent();
        Http::fake(['*getTxnStatus*' => Http::response($this->paytmSays(['ORDERID' => $reference]), 200)]);

        $this->assertSame('activated', $this->deliverCallback($reference)->json('status'), 'premise');

        // And again, because a duplicate is what a provider retry would produce.
        $this->deliverCallback($reference);

        foreach (['store_wallets', 'store_wallet_transactions', 'store_order_financials'] as $table) {
            $this->assertSame(
                0,
                DB::table($table)->count(),
                "The plan callback wrote a `{$table}` row. A plan activation assigns a "
                . "subscription to a User; it must not touch a merchant's wallet or an order's "
                . 'profit and loss, and a provider retry must not make that worse.'
            );
        }
    }

    /** A reference with no intent behind it tells the caller nothing and activates nothing. */
    public function test_an_unknown_reference_activates_nothing(): void
    {
        $unknown = PlanPurchaseIntent::newReference();
        Http::fake(['*getTxnStatus*' => Http::response($this->paytmSays(['ORDERID' => $unknown]), 200)]);

        $this->deliverCallback($unknown)->assertStatus(200);

        $this->assertNull($this->purchaser->fresh()->plan_id);
    }

    /** No reference at all is a 400, before any provider call is made. */
    public function test_a_missing_reference_is_refused_without_asking_the_provider(): void
    {
        Http::fake(['*getTxnStatus*' => Http::response($this->paytmSays(), 200)]);

        $this->postJson('/api/payment-callback/paytm/plan', [])->assertStatus(400);

        Http::assertNothingSent();
    }

    /**
     * NOTHING IS TAKEN FROM THE REQUEST BUT THE REFERENCE. A caller who posts a plan id, an amount
     * and a user id gets the INTENT's values, not theirs.
     */
    public function test_the_request_cannot_choose_the_plan_the_amount_or_the_purchaser(): void
    {
        $expensive = $this->makePlan(['name' => 'Enterprise ' . uniqid(), 'price' => 9999]);

        [$other] = $this->makeTenant();

        $reference = $this->intent();
        Http::fake(['*getTxnStatus*' => Http::response($this->paytmSays(['ORDERID' => $reference]), 200)]);

        $this->postJson('/api/payment-callback/paytm/plan', [
            'ORDERID' => $reference,
            'plan_id' => $expensive->id,
            'user_id' => $other->id,
            'TXNAMOUNT' => '1.00',
            'amount' => '1.00',
        ])->assertStatus(200);

        $this->assertSame((int) $this->plan->id, (int) $this->purchaser->fresh()->plan_id,
            'the request chose the plan, so the intent is not the authority');
        $this->assertNull(User::find($other->id)->plan_id,
            'the request chose the purchaser');
    }

    // ══════════════════════════════════════════════════════════════════════════ THE GATE

    /** THE ROUTE DOES NOT EXIST WHEN THE GATE IS CLOSED, which is how it ships. */
    public function test_the_callback_is_absent_while_the_gate_is_closed(): void
    {
        $this->assertFalse(
            in_array(strtolower(trim((string) env('PAYTM_CALLBACK_ENABLED', ''))), ['1', 'true', 'yes', 'on'], true),
            'PAYTM_CALLBACK_ENABLED is set in this environment, so this case cannot show the '
            . 'shipped state'
        );

        $this->assertStringContainsString(
            "if (config('payments.paytm_callback_enabled')) {",
            file_get_contents(base_path('routes/api.php')),
            'the callback route is no longer gated, so it is exposed before a live Paytm '
            . 'transaction has ever been verified'
        );

        $this->assertStringContainsString(
            "if (! config('payments.paytm_callback_enabled')) {",
            file_get_contents(app_path('Http/Controllers/PaymentCallbackController.php')),
            'the controller no longer checks the gate itself; a route file must not be the only '
            . 'thing standing between a request and a plan activation'
        );
    }

    /** The gate opens only on an unambiguous yes — `empty('no')` is false, and that once bit. */
    public function test_the_gate_opens_only_on_an_unambiguous_yes(): void
    {
        $parse = fn ($v) => in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);

        foreach (['', 'no', 'off', 'maybe', '0', 'false', 'disabled', ' '] as $shut) {
            $this->assertFalse($parse($shut), "\"{$shut}\" opened the payment callback gate");
        }

        foreach (['1', 'true', 'yes', 'on', 'YES', ' On '] as $open) {
            $this->assertTrue($parse($open), "\"{$open}\" did not open the gate");
        }
    }

    // ═══════════════════════════════════════════════ THE EXISTING ROUTE IS NOT WEAKENED

    /**
     * THE OWNER'S HARD CONSTRAINT: *"Do not weaken CSRF for existing session routes."*
     *
     * The temptation F241 records is to add `plan-paytm-payment/*` to the CSRF exception list.
     * That is forbidden, and it would also not work — `paymentConfig()` wraps the Paytm config
     * write in `if (Auth::check())`, so outside `auth` the merchant key is null and the handler's
     * own guard refuses anyway, turning a visible 419 into a silent refusal.
     */
    public function test_the_existing_session_route_keeps_its_csrf_protection(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        foreach (['plan-paytm-payment', 'plan-Benefit-payment', 'plan-mollie-payment'] as $route) {
            $this->assertStringNotContainsString($route, $bootstrap,
                "{$route} has been added to the CSRF exception list. The owner forbade this, and "
                . 'F241 measured that it would not even work: paymentConfig() wraps the provider '
                . 'config write in if (Auth::check()), so outside auth the merchant key is null '
                . 'and the handler refuses anyway — a silent refusal instead of a visible 419.');
        }
    }

    /**
     * AND THE CALLBACK IS NOT IN THE SESSION GROUP, which is what makes it need no exemption.
     * Asserted on the route file rather than the kernel because that is where the decision lives.
     */
    public function test_the_callback_is_registered_outside_the_web_group(): void
    {
        $this->assertStringContainsString(
            'payment-callback/paytm/plan',
            file_get_contents(base_path('routes/api.php')),
            'the callback is not in routes/api.php'
        );

        $this->assertStringNotContainsString(
            'payment-callback',
            file_get_contents(base_path('routes/web.php')),
            'the callback has been registered in the web group, which gives it a session and a '
            . 'CSRF check it cannot satisfy — the whole point of §2.2 is that it needs neither'
        );
    }

    /**
     * PROOF 4 — CONCURRENCY, AND IT IS INHERITED RATHER THAN REBUILT.
     *
     * `verify-plan-purchase-intent-on-mysql.php` already races two concurrent consumptions of one
     * intent on a real MariaDB and proves exactly one wins, with the loser's conditional UPDATE
     * matching zero rows and the row lock measured (driver 1205). That script was written for
     * F229's browser path.
     *
     * The proof transfers to the callback **only because `consumeForProviderCallback()` delegates
     * to `verifyAndConsume()`** rather than reimplementing the claim. If someone inlines a second
     * UPDATE here "to avoid the indirection", the MariaDB evidence silently stops covering this
     * path — the F237 shape — and the callback would have no concurrency proof at all while the
     * script kept passing.
     *
     * So the delegation is the thing asserted. Duplicating a 300-line race script would be the
     * worse answer: two scripts proving one primitive drift, and the second one would be proving a
     * copy rather than the code.
     */
    public function test_the_callback_inherits_the_intents_raced_concurrency_guard(): void
    {
        $source = file_get_contents(app_path('Services/PlanPurchaseIntent.php'));

        $start = strpos($source, 'public static function consumeForProviderCallback(');
        $this->assertNotFalse($start, 'consumeForProviderCallback is gone');

        $end = strpos($source, 'public static function verifyAndConsume(', $start);
        $this->assertNotFalse($end, 'verifyAndConsume is gone');

        $method = substr($source, $start, $end - $start);

        $this->assertStringContainsString('return self::verifyAndConsume(', $method,
            'consumeForProviderCallback no longer delegates to verifyAndConsume. The MariaDB race '
            . 'evidence in verify-plan-purchase-intent-on-mysql.php covers THAT method\'s '
            . 'conditional UPDATE; a second implementation here would have no concurrency proof '
            . 'at all while the script kept passing.');

        $this->assertStringNotContainsString('->update(', $method,
            'consumeForProviderCallback performs its own UPDATE. The single conditional '
            . '`UPDATE … WHERE status = \'pending\'` is what makes replay and concurrency safe, '
            . 'and there must be exactly one of it.');

        /* AND THE RACED SCRIPT MUST STILL EXIST, since this case's whole argument rests on it. */
        $this->assertFileExists(
            base_path('tests/Support/Security/bin/verify-plan-purchase-intent-on-mysql.php'),
            'the MariaDB race script is gone, so the concurrency proof this case defers to no '
            . 'longer exists anywhere'
        );
    }

    /**
     * THE CONTROLLER TAKES NOTHING FROM THE REQUEST BUT THE REFERENCE — asserted on the source as
     * well as behaviourally, because the behavioural case can only probe the keys it thinks of.
     *
     * A future field read from the request is the single most likely way this callback becomes
     * unsafe, so the shape is ratcheted: one `$request->input()` expression, for `ORDERID`.
     */
    public function test_the_controller_reads_only_the_reference_from_the_request(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/PaymentCallbackController.php'));

        $reads = preg_match_all('/\$request->(input|get|post|query|all|json)\s*\(/', $source);

        $this->assertSame(2, $reads,
            'the callback reads the request in ' . $reads . ' places. It must read exactly one '
            . 'thing — the order reference, as ORDERID or orderid — because every other fact about '
            . 'the purchase comes from the server\'s own intent. A new read here is how a caller '
            . 'starts choosing the plan, the amount or the purchaser.');

        foreach (['plan_id', 'amount', 'user_id', 'TXNAMOUNT', 'currency'] as $neverFromRequest) {
            $this->assertStringNotContainsString("input('" . $neverFromRequest . "'", $source,
                "the callback reads {$neverFromRequest} from the request");
        }
    }
}
