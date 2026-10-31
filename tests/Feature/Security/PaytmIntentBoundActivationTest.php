<?php

namespace Tests\Feature\Security;

use App\Services\PaytmTransactionVerifier;
use App\Services\PlanPurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * F229 / D-31 — THE SUBSTITUTION, DRIVEN THROUGH THE REAL PAYTM ROUTE.
 *
 * `PlanPurchaseIntentTest` drives the service. **This file is the one the headline claim needed and
 * did not have**: independent review found that no test failed when `$plan = $ssIntendedPlan;` was
 * reverted, because the positive control in `PlanActivationReplayGuardTest` uses the *same* plan
 * for the intent and for the `Crypt` route parameter — so the two values were identical and reading
 * the wrong one was invisible. A claim with no failing test behind it is the exact shape this
 * repository keeps getting caught by.
 *
 * It also drives the refusal paths that review found **crashing**. Every `Log::` call in
 * `PaytmPaymentController` resolved to `AWS\CRT\Log` — a `final class` with no `warning` or `error`
 * static — so the refusals threw `Error`, which `catch (\Exception)` does not catch. The suite was
 * green over five such sites because nothing reached any of them. These cases reach four.
 *
 * ## Why the verifier is a double here
 *
 * The provider check is server-to-server against `/merchant-status/getTxnStatus`, and the SDK's
 * transport is `callAPI()` — a global function using curl, which `Http::fake()` cannot intercept.
 * `PaytmTransactionVerifier` exists as a seam precisely so this path is drivable; the double is how
 * the seam is used, not a way around the control. A test that reached the real network would be
 * measuring the network.
 */
class PaytmIntentBoundActivationTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    private const PAYTM_KEY = 'paytm-test-merchant-key';

    // ---------------------------------------------------------------------------------------
    // THE SUBSTITUTION — the case the commit claimed to close and did not pin
    // ---------------------------------------------------------------------------------------

    /**
     * PAY FOR THE CHEAP PLAN, NAME THE DEAR ONE ON THE RETURN.
     *
     * This is F229 itself. The intent is recorded against the cheap plan; the return's `Crypt` route
     * parameter names the dear one. `APP_KEY` is in git history and unrotated, so that parameter is
     * an encoding and not a control — the caller can mint it for any plan id.
     *
     * The entitlement must follow the INTENT. Asserted on both the recorded order and the user's
     * actual plan, because recording the right number while assigning the wrong plan would be the
     * same defect with better bookkeeping.
     */
    public function test_a_return_naming_a_dearer_plan_activates_the_plan_that_was_paid_for(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $cheap = $this->makePlan('10');
        $dear = $this->makePlan('999');
        $merchant = $this->makeMerchant();

        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm',
            'user_id' => $merchant->id,
            'plan_id' => $cheap->id,
            'expected_amount' => '10.00',
            'currency' => 'USD',
        ]);

        $this->fakeVerifier('10.00', 'USD');

        /* The route names the DEAR plan. The money was for the cheap one. */
        $this->actingAs($merchant)->post(
            '/plan-paytm-payment/' . Crypt::encrypt($dear->id),
            $this->paytmBody(['ORDERID' => $reference, 'TXNAMOUNT' => '10.00'])
        );

        $recorded = DB::table('plan_orders')->first();

        $this->assertNotNull($recorded, 'premise: no plan order was written, so nothing was activated either way');

        $this->assertSame(
            (int) $cheap->id,
            (int) $recorded->plan_id,
            'F229: the return activated the plan named in the FORGEABLE route parameter rather than '
            . 'the plan the recorded intent says the money was for. Paying 10 bought 999.'
        );

        $this->assertSame(
            (int) $cheap->id,
            (int) $merchant->fresh()->plan_id,
            'the recorded order names the paid-for plan but the ENTITLEMENT followed the route '
            . 'parameter, which is the same defect with tidier bookkeeping'
        );
    }

    /** And the honest purchase still works, so the fix is not "refuse everything". */
    public function test_a_return_naming_the_plan_that_was_paid_for_activates_it(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm', 'user_id' => $merchant->id, 'plan_id' => $plan->id,
            'expected_amount' => '499.00', 'currency' => 'USD',
        ]);

        $this->fakeVerifier('499.00', 'USD');

        $this->actingAs($merchant)->post(
            '/plan-paytm-payment/' . Crypt::encrypt($plan->id),
            $this->paytmBody(['ORDERID' => $reference])
        );

        $this->assertSame(1, (int) DB::table('plan_orders')->count(), 'an honest purchase was refused');
        $this->assertSame(
            PlanPurchaseIntent::CONSUMED,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status')
        );
    }

    /**
     * THE PRODUCTION SHAPE OF THE CURRENCY FIELD — and the case whose absence cost a 100% failure.
     *
     * Every double in this file supplied the intent's OWN currency, so nothing ever drove what
     * Paytm actually sends: **no `CURRENCY` field at all**, which the controller passes as `''`.
     * Against the first implementation that was a mismatch, the mismatch was recorded, and
     * `recordRefusal()` is terminal — so every legitimate Paytm plan purchase would have been
     * refused permanently AFTER the money was captured. A suite of fifteen cases all agreed,
     * because all fifteen handed the code the one value production never sends.
     */
    public function test_a_provider_that_reports_no_currency_still_activates_the_paid_for_plan(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm', 'user_id' => $merchant->id, 'plan_id' => $plan->id,
            'expected_amount' => '499.00', 'currency' => 'USD',
        ]);

        /* `currency => null`, exactly as the verifier returns it when the field is absent. */
        $this->fakeVerifier('499.00', null);

        $this->actingAs($merchant)->post(
            '/plan-paytm-payment/' . Crypt::encrypt($plan->id),
            $this->paytmBody(['ORDERID' => $reference])
        );

        $this->assertSame(
            1,
            (int) DB::table('plan_orders')->count(),
            'a provider reporting NO currency was refused. On Paytm that is every legitimate '
            . 'purchase, and the refusal lands after the money is taken.'
        );
        $this->assertSame((int) $plan->id, (int) $merchant->fresh()->plan_id);
        $this->assertSame(
            PlanPurchaseIntent::CONSUMED,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status')
        );
    }

    /**
     * A CURRENCY THAT REALLY DIFFERS REFUSES — and leaves the purchase RECOVERABLE.
     *
     * Fail closed (nothing activated) without destroying the payment: the intent stays `pending`
     * and carries its reason, so `unreconciled()` surfaces it for an operator.
     *
     * A CORRECTION TO WHAT THIS SAID, caught by independent verification: it claimed an operator
     * could "finish it once the platform's currency setting and the provider's settlement currency
     * are reconciled". They cannot. `plan_purchase_intents.currency` is written once by `create()`
     * and no method ever UPDATEs it, so changing the platform setting cannot affect an existing
     * row and every retry produces the identical refusal. What "recoverable" buys is narrower and
     * worth stating exactly: the row is not closed, so a human CAN still act on it — by issuing a
     * refund, or by correcting the setting and having the buyer purchase again. It is not
     * self-healing. Whether Paytm reports a currency at all is an open
     * question one captured sandbox response settles; until then this is the honest handling, and
     * it is the reason `REFUSED_RECOVERABLE` exists as a state of its own.
     */
    public function test_a_differing_currency_activates_nothing_and_leaves_the_intent_recoverable(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm', 'user_id' => $merchant->id, 'plan_id' => $plan->id,
            'expected_amount' => '499.00', 'currency' => 'USD',
        ]);

        $this->fakeVerifier('499.00', 'INR');

        $this->actingAs($merchant)->post(
            '/plan-paytm-payment/' . Crypt::encrypt($plan->id),
            $this->paytmBody(['ORDERID' => $reference])
        );

        $this->assertSame(0, (int) DB::table('plan_orders')->count(), 'a currency mismatch activated a plan');
        $this->assertNull($merchant->fresh()->plan_id);

        $this->assertSame(
            PlanPurchaseIntent::PENDING,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            'the refusal was RECORDED, which closes the intent for good and destroys a payment the '
            . 'provider has already captured. A recoverable refusal must not be recorded.'
        );
    }

    /**
     * RE-PRESENTING A SUCCESSFUL RETURN WRITES NOTHING — and, the part that matters, DOES NOT
     * EXTEND THE EXPIRY.
     *
     * The refresh short-circuit had no test at all: reverting it failed nothing, because the one
     * existing replay case seeds no intent and bypasses it. And the hole it exists to close is the
     * expiry, not the order count — `User::assignPlan()` recomputes `plan_expire_date` from
     * `Carbon::now()`, so a guard that only prevents a second `plan_orders` row still hands out a
     * free monthly renewal to anyone re-POSTing a body they already hold. Both are asserted.
     */
    public function test_re_presenting_a_successful_return_writes_nothing_and_does_not_extend_the_plan(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        /*
         * `duration = 'Month'`, and this is a correction to what I first wrote rather than a
         * convenience. `User::assignPlan()` sets `plan_expire_date` only when `$plan->duration`
         * is exactly `'Month'`, `'Year'` or `'Unlimited'` (`User.php:250-265`), and the shared
         * fixture stores **`'Monthly'`** -- which matches none of them, so the expiry stayed null
         * and the assertion below could not fail however broken the guard was.
         *
         * WHICH VALUE PRODUCTION STORES IS AN OPEN QUESTION, and it bounds how severe the renewal
         * hole is: no plan create/edit view in this repository was found to offer `duration`
         * options, and `PlanRequestController:81` uses the value as a column prefix
         * (`$plan->{$duration . '_price'}`) for columns the plans migration does not declare. If
         * production stores `'Monthly'` then `plan_expire_date` is never set by this path and the
         * free-renewal vector does not exist; if it stores `'Month'` then it does. Recorded as a
         * question rather than resolved by assumption -- and the guard is pinned here in the shape
         * where the hole is real, which is the right thing to do either way.
         */
        $plan->duration = 'Month';
        $plan->save();

        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm', 'user_id' => $merchant->id, 'plan_id' => $plan->id,
            'expected_amount' => '499.00', 'currency' => 'USD',
        ]);

        $this->fakeVerifier('499.00', 'USD');
        $body = $this->paytmBody(['ORDERID' => $reference]);

        $this->actingAs($merchant)->post('/plan-paytm-payment/' . Crypt::encrypt($plan->id), $body);

        $this->assertSame(1, (int) DB::table('plan_orders')->count(), 'premise broken: the first purchase did not land');

        $expiryAfterPaying = DB::table('users')->where('id', $merchant->id)->value('plan_expire_date');
        $this->assertNotNull($expiryAfterPaying, 'premise broken: no expiry was set, so an extension cannot be detected');

        /* A month later, the same signed body. The buyer holds it: it was POSTed through their own
           browser. */
        $this->travel(31)->days();
        $this->actingAs($merchant)->post('/plan-paytm-payment/' . Crypt::encrypt($plan->id), $body);

        $this->assertSame(
            1,
            (int) DB::table('plan_orders')->count(),
            'a re-presented return wrote a second plan order'
        );

        $this->assertSame(
            $expiryAfterPaying,
            DB::table('users')->where('id', $merchant->id)->value('plan_expire_date'),
            'A RE-PRESENTED RETURN EXTENDED THE PLAN. assignPlan() recomputes plan_expire_date '
            . 'from now rather than from the paid-for period, so re-POSTing a body the buyer '
            . 'already holds keeps a paid plan alive indefinitely on one payment.'
        );
    }

    /**
     * F4 — A STRANGER CANNOT SPOIL AN INTENT WHEN THE PROVIDER CANNOT CONFIRM.
     *
     * The missing cell in this file's own matrix: cross-user crossed with a non-VERIFIED verifier.
     * Neither existing case can fail on it. The stranger case drives a VERIFIED verifier, so it
     * reaches the purchaser check inside `verifyAndConsume()`, which binds the owner before any
     * refusal is recorded. The UNVERIFIABLE case drives the owner, so the unscoped refusal lands
     * on the right row anyway.
     *
     * Only the combination reaches the one `recordRefusal()` call that round 2 left unscoped —
     * the one above the provider check, which runs BEFORE any ownership test, on a reference taken
     * from the request. Unscoped, a stranger presenting a body for somebody else's reference
     * flipped the OWNER's pending intent to `refused`, terminally: the owner's own return then
     * finds no pending row, forever.
     *
     * Reachability is low and stated as such: the reference is pinned inside the AES-verified POST
     * body, so substituting a victim's requires the merchant key. This is hardening. It is worth
     * having because the round-2 rationale was that the asymmetry was indefensible, and two thirds
     * of the asymmetry was still there.
     */
    public function test_a_stranger_cannot_spoil_an_intent_when_the_provider_cannot_confirm(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $owner = $this->makeMerchant();
        $stranger = $this->makeMerchant();

        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm', 'user_id' => $owner->id, 'plan_id' => $plan->id,
            'expected_amount' => '499.00', 'currency' => 'USD',
        ]);

        /* The provider cannot be reached, which is the path with the unscoped refusal. */
        $this->app->instance(PaytmTransactionVerifier::class, new class extends PaytmTransactionVerifier {
            public function verify(string $reference): array
            {
                return [
                    'state' => PaytmTransactionVerifier::UNVERIFIABLE,
                    'amount' => null, 'currency' => null, 'txn_id' => null,
                    'reason' => 'the status call failed',
                ];
            }
        });

        $this->actingAs($stranger)->post(
            '/plan-paytm-payment/' . Crypt::encrypt($plan->id),
            $this->paytmBody(['ORDERID' => $reference])
        );

        $this->assertSame(
            PlanPurchaseIntent::PENDING,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            'a stranger closed the owner\'s intent terminally, so the owner\'s own return can '
            . 'never complete their purchase'
        );

        $this->assertSame(0, (int) DB::table('plan_orders')->count(), 'the stranger activated something');
        $this->assertNull($owner->fresh()->plan_id);
        $this->assertNull($stranger->fresh()->plan_id);
    }

    /**
     * THE ORDER OF THE LAST THREE WRITES, ASSERTED FROM THE SOURCE.
     *
     * Not a hedge: this one genuinely cannot be staged. `attachOrder()` must run AFTER a
     * successful `assignPlan()`, and to observe the difference behaviourally a test would have to
     * make `assignPlan()` fail *after* the `plan_orders` row is written — which means either a
     * plan whose modules are malformed in a way the suite cannot build, or a partial mock of the
     * `User` model the handler resolves from the session. Both measure the double rather than the
     * code.
     *
     * WHAT THE ORDER BUYS, and why it is worth pinning. With `attachOrder()` first,
     * `plan_purchase_intents.plan_order_id` means "an order exists" instead of "this purchase
     * completed", and two separate things break on that:
     *
     *   * the refresh short-circuit keys on `plan_order_id`, so a buyer whose order landed and
     *     whose entitlement did not is told "Plan activated Successfully." forever, with no write
     *     — the permanent stranding `GuardsPlanActivation.php:437-443` had already ruled out;
     *   * `unreconciled()`'s captured-payment clause requires `plan_order_id IS NULL`, so the one
     *     state requirement 5 exists to surface is the one state it cannot see.
     *
     * Asked of the METHOD and not of the file, because `attachOrder` and `assignPlan` both appear
     * elsewhere in this controller and a file-scoped `strpos` comparison would answer about
     * whichever pair it found first — the mistake that made F227's original guard claim false.
     */
    public function test_the_intent_is_attached_only_after_the_entitlement_is_assigned(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/PaytmPaymentController.php'));

        $start = strpos($source, 'public function getPaymentStatus(');
        $this->assertNotFalse($start, 'the return handler has been renamed; re-read this assertion');
        $method = substr($source, $start);
        $next = strpos($method, 'public function ', 10);
        $method = $next === false ? $method : substr($method, 0, $next);

        /* Comments out first: this method's own docblocks name both calls in order to explain the
           ordering, and a census that cannot tell an explanation from an instance is worthless. */
        $code = implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all('<?php ' . $method)
        ));

        $assign = strpos($code, '$user->assignPlan(');
        $attach = strpos($code, 'PlanPurchaseIntent::attachOrder(');

        $this->assertNotFalse($assign, 'assignPlan has moved out of this method');
        $this->assertNotFalse($attach, 'attachOrder has moved out of this method');

        $this->assertLessThan(
            $attach,
            $assign,
            'attachOrder() runs BEFORE assignPlan(), so plan_order_id means "an order exists" '
            . 'rather than "this purchase completed": a buyer whose entitlement did not land is '
            . 'told it did on every retry, and unreconciled() cannot see the one state '
            . 'requirement 5 exists to surface.'
        );

        $this->assertStringContainsString(
            'if (! empty($assignPlan[\'is_success\'])) {',
            $code,
            'the attachment is no longer conditional on the entitlement having landed, which '
            . 'restores the same defect by a different route'
        );
    }

    // ---------------------------------------------------------------------------------------
    // THE REFUSAL PATHS — all four were crashing, and nothing reached them
    // ---------------------------------------------------------------------------------------

    /**
     * REQUIREMENT 2: the provider must confirm. UNVERIFIABLE is a refusal, never a pass.
     *
     * This reaches the path that threw `Error: Call to undefined method AWS\CRT\Log::warning()`
     * before the import was corrected — so it pins the refusal *and* the fact that the refusal is
     * reportable rather than a 500.
     */
    public function test_an_unconfirmed_payment_activates_nothing_and_refuses_reportably(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm', 'user_id' => $merchant->id, 'plan_id' => $plan->id,
            'expected_amount' => '499.00', 'currency' => 'USD',
        ]);

        /* The provider cannot be reached -- production's shape when the status endpoint is down. */
        $this->app->instance(PaytmTransactionVerifier::class, new class extends PaytmTransactionVerifier {
            public function verify(string $reference): array
            {
                return [
                    'state' => PaytmTransactionVerifier::UNVERIFIABLE,
                    'amount' => null, 'currency' => null, 'txn_id' => null,
                    'reason' => 'the status call failed',
                ];
            }
        });

        $response = $this->actingAs($merchant)->post(
            '/plan-paytm-payment/' . Crypt::encrypt($plan->id),
            $this->paytmBody(['ORDERID' => $reference])
        );

        $response->assertRedirect();
        $this->assertLessThan(500, $response->getStatusCode(),
            'the refusal produced a server error rather than a refusal. Before the Log import was '
            . 'corrected this path threw an uncatchable Error.');

        $this->assertSame(0, (int) DB::table('plan_orders')->count(),
            'an unconfirmed payment activated a plan');

        $this->assertSame(
            PlanPurchaseIntent::REFUSED,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'),
            'the refusal left no record, so a captured payment that activated nothing is invisible '
            . 'to PlanPurchaseIntent::unreconciled()'
        );
    }

    /** REQUIREMENTS 3 and 4: a payment smaller than the intent refuses. */
    public function test_a_payment_smaller_than_the_intent_activates_nothing(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm', 'user_id' => $merchant->id, 'plan_id' => $plan->id,
            'expected_amount' => '499.00', 'currency' => 'USD',
        ]);

        /* The provider honestly reports what was actually paid: one unit. */
        $this->fakeVerifier('1.00', 'USD');

        $this->actingAs($merchant)->post(
            '/plan-paytm-payment/' . Crypt::encrypt($plan->id),
            $this->paytmBody(['ORDERID' => $reference, 'TXNAMOUNT' => '1.00'])
        );

        $this->assertSame(0, (int) DB::table('plan_orders')->count(),
            'a payment of 1.00 against a 499.00 intent activated the plan');
        $this->assertSame(PlanPurchaseIntent::REFUSED,
            DB::table('plan_purchase_intents')->where('reference', $reference)->value('status'));
    }

    /** REQUIREMENT 5: another account cannot spend somebody else's intent. */
    public function test_a_stranger_cannot_activate_against_another_accounts_intent(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $owner = $this->makeMerchant();
        $stranger = $this->makeMerchant();

        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm', 'user_id' => $owner->id, 'plan_id' => $plan->id,
            'expected_amount' => '499.00', 'currency' => 'USD',
        ]);

        $this->fakeVerifier('499.00', 'USD');

        $this->actingAs($stranger)->post(
            '/plan-paytm-payment/' . Crypt::encrypt($plan->id),
            $this->paytmBody(['ORDERID' => $reference])
        );

        $this->assertSame(0, (int) DB::table('plan_orders')->count(),
            'a stranger activated a plan on another account\'s purchase intent');
        $this->assertNull($stranger->fresh()->plan_id, 'the stranger was given an entitlement');
    }

    /** A return with no recorded intent at all is UNVERIFIABLE, and refuses. */
    public function test_a_return_with_no_recorded_intent_activates_nothing(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        $this->fakeVerifier('499.00', 'USD');

        $this->actingAs($merchant)->post(
            '/plan-paytm-payment/' . Crypt::encrypt($plan->id),
            $this->paytmBody(['ORDERID' => 'a-reference-the-server-never-issued'])
        );

        $this->assertSame(0, (int) DB::table('plan_orders')->count(),
            'a return naming a reference the server never issued activated a plan');
    }

    // ---------------------------------------------------------------------------------------
    // THE INITIATOR'S FAIL-CLOSED PATH — production's shape, and it was the worst crash
    // ---------------------------------------------------------------------------------------

    /**
     * WITH NO INTENT RECORD, THE PURCHASE DOES NOT START — and says so instead of 500ing.
     *
     * This is production's actual shape: the migration is development-and-test only, so
     * `available()` is false and `create()` returns null on every Paytm plan purchase. Before the
     * `Log` import was corrected this path threw `Error` and the merchant got an uncaught 500 with
     * no log line — fail-closed, but unreportable and nothing like the documented refusal.
     *
     * Requirement 8 asks for the cost of fail-closed handling to be documented. This test IS that
     * documentation, executable: on an installation without the record, Paytm plan purchases refuse.
     */
    public function test_with_no_intent_record_the_purchase_refuses_rather_than_erroring(): void
    {
        $this->seedSuperAdminCurrency('USD', ['paytm_merchant_key' => self::PAYTM_KEY]);

        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        \Illuminate\Support\Facades\Schema::drop('plan_purchase_intents');

        $this->assertFalse(PlanPurchaseIntent::available(), 'premise: the record is still installed');

        $response = $this->actingAs($merchant)->post(route('plan.pay.with.paytm'), [
            'plan_id' => Crypt::encrypt($plan->id),
            'mobile_number' => '0100000000',
        ]);

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'the fail-closed refusal produced a server error. The purchase correctly did not '
            . 'start, but an uncaught Error is not a refusal: it leaves no log line and nothing '
            . 'for an operator to read.'
        );

        $this->assertSame(0, (int) DB::table('plan_orders')->count(),
            'a purchase started with no intent record to bind it');
    }

    // ---------------------------------------------------------------------------------------

    /** `?string` so `null` -- the production shape of an absent `CURRENCY` -- is drivable. */
    private function fakeVerifier(string $amount, ?string $currency): void
    {
        $this->app->instance(PaytmTransactionVerifier::class, new class($amount, $currency) extends PaytmTransactionVerifier {
            public function __construct(private string $amount, private ?string $currency)
            {
            }

            public function verify(string $reference): array
            {
                return [
                    'state' => PaytmTransactionVerifier::VERIFIED,
                    'amount' => $this->amount,
                    'currency' => $this->currency,
                    'txn_id' => 'PAYTM-CONFIRMED',
                    'reason' => '',
                ];
            }
        });
    }

    private function paytmBody(array $overrides = []): array
    {
        $body = array_merge([
            'MID' => 'MERCH123',
            'ORDERID' => 'unset',
            'TXNAMOUNT' => '499.00',
            'CURRENCY_CODE' => 'USD',
            'STATUS' => 'TXN_SUCCESS',
            'RESPCODE' => '01',
            'RESPMSG' => 'Txn Success',
            'TXNID' => 'PAYTMTXN-' . strtoupper(uniqid()),
            'BANKTXNID' => 'BNK-1',
            'PAYMENTMODE' => 'CC',
        ], $overrides);

        /* The vendor library is pulled in by a bare `require` at the top of PaytmWalletProvider, so
           touching the class first lets the autoloader run it before getChecksumFromArray(). */
        class_exists(\Anand\LaravelPaytmWallet\Providers\PaytmWalletProvider::class);

        $body['CHECKSUMHASH'] = getChecksumFromArray($body, self::PAYTM_KEY);

        return $body;
    }
}
