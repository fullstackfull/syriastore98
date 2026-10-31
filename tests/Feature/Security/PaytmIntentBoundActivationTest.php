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

    private function fakeVerifier(string $amount, string $currency): void
    {
        $this->app->instance(PaytmTransactionVerifier::class, new class($amount, $currency) extends PaytmTransactionVerifier {
            public function __construct(private string $amount, private string $currency)
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
