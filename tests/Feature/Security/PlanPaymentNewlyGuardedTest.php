<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ELEVEN MORE PLAN-PURCHASE HANDLERS THAT SOLD A PAID SUBSCRIPTION FOR NOTHING.
 * =============================================================================================
 *
 * Nineteen assignPlan() callers had never had their ACTIVATION GATE classified. Reading them
 * resolved eight onto the safe side -- seven ask their provider before activating (four of
 * those by taking the money on the request itself) and BankTransfer is a platform-operator
 * approval rather than a provider return. These eleven are the residue.
 *
 * WHAT IS DIFFERENT ABOUT THIS ELEVEN, and why it was worth reading each one rather than
 * grepping: NONE of them can forge the amount upward into revenue. Each prices from
 * $plan->price, from a blob this server sealed, or from server-side session state. So the
 * census that found the first fifteen -- which looked for a request-sourced PRICE -- could
 * never have found these. The defect is not a mispriced order. It is that THE SUBSCRIPTION IS
 * FREE:
 *
 *   NO GATE AT ALL             IyziPay (which also takes the USER from $request->user_id, so
 *                              the caller chooses whose entitlement changes), PayFast.
 *   A QUERY PARAMETER          Mercado (`status=approved&flag=success`), Sspay and Toyyibpay
 *                              (`status_id=1`) -- and Sspay and Toyyibpay register ONE url as
 *                              both billReturnUrl and billCallbackUrl, so there is not even a
 *                              server-to-server channel to tell apart from the browser.
 *   THE BROWSER'S OWN JSON     Midtrans, which reads status_code out of the Snap.js client
 *                              result the page posts back.
 *   A PRE-PAYMENT BEARER       Aamarpay and PayU, where the gate is a Crypt blob this server
 *   TOKEN                      sealed at checkout START. Sealed is not paid: PayU's is rendered
 *                              into a hidden input in the caller's own browser
 *                              (plans/payu_request.blade.php:14) and PayFast's whole success URL
 *                              is returned to the caller in a JSON response before any payment.
 *   A SESSION KEY THE          Skrill -- `session()->has('skrill_data')`, written by the
 *   CALLER'S OWN ABANDONED     initiator before the browser ever leaves for Skrill. Yookassa's
 *   CHECKOUT WROTE             defect exactly.
 *   A VERIFICATION THAT IS     Razorpay, which curls api.razorpay.com and then never reads the
 *   PERFORMED AND DISCARDED    answer -- there is no conditional at all between the curl and
 *                              assignPlan(). The PayTabs pattern.
 *   AN SDK THAT IS NOT HERE    PaymentWall, whose gate WOULD be a real charge but whose
 *                              \Paymentwall_Charge class does not exist in this environment, so
 *                              its contract cannot be read or tested. The Yookassa precedent.
 *
 * WHAT IS PROVEN HERE, per provider:
 *
 *   REFUSED WHILE DISABLED. 403 -- never 401, because repo B's lib/network/network.dart turns
 *   any 401 into a forced logout that clears stored preferences -- AND no plan_orders row, AND
 *   the merchant's plan_id unchanged. The rows and the entitlement are asserted BEFORE the
 *   status code, because a status-code assertion can be vacuous and this project has already
 *   shipped three that were.
 *
 *   AND THE REFUSAL IS THE ONLY THING IN THE WAY. For nine of the eleven the same request with
 *   the provider ENABLED completes and activates the plan. That is what stops the negative test
 *   above from passing for the wrong reason: without it, a 403 could just as well mean the
 *   request never had a chance of working in this fixture. It also states the cost of the env
 *   flag plainly -- turning one of these on restores a free activation.
 *
 * THE CURRENCY FIXTURE IS SEEDED IN THE NEGATIVE TESTS TOO, for that same reason: without a
 * CURRENCY_NAME row the completion path dies at $admin_payment_setting['CURRENCY_NAME'], so an
 * unguarded handler would 500 rather than sell a plan, and the 403 would prove nothing.
 *
 * WHAT IS NOT CLAIMED. None of this verifies that money moved. No payment provider is reachable
 * from this environment. All eleven are FAIL-CLOSED -- DISABLED PENDING VERIFICATION, not
 * verified; config/payments.php carries each one's reason and its exact re-enable conditions.
 */
class PlanPaymentNewlyGuardedTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    /**
     * Every newly guarded provider: [config key, HTTP verb, route name, a builder for the
     * parameters that satisfy its own gate].
     *
     * THE PARAMETERS ARE BUILT AT RUNTIME, not listed, because five of these eleven are gated
     * on a Crypt blob or a route parameter that has to name the plan created by the fixture --
     * and a data provider is static and runs before any fixture exists. A closure keeps the
     * gate and its parameters side by side, which is the point: each row is a readable statement
     * of what the handler used to accept.
     */
    public static function forgeableProviders(): array
    {
        return [
            // The gate is `$data['response'] == "success"` on a blob sealed by paywithaamarpay()
            // at checkout start. Crypt is the app's own, so this is exactly the URL aamarpay
            // would be handed -- and the caller gets it back in their browser history.
            'Aamarpay (pre-payment sealed blob)' => ['aamarpay', 'get', 'pay.aamarpay.success',
                fn (Plan $plan, User $merchant) => ['data' => Crypt::encrypt([
                    'response' => 'success',
                    'coupon'   => 0,
                    'plan_id'  => $plan->id,
                    'price'    => 0,
                    'order_id' => 'AAMARPAY-ORDER-1',
                ])]],

            // No gate at all -- and the user comes from the request body.
            'IyziPay (no gate; user from request)' => ['iyzipay', 'post', 'iyzipay.payment.callback',
                fn (Plan $plan, User $merchant) => [
                    'id' => $plan->id, 'amount' => 0, 'coupan_code' => 0,
                    'user_id' => $merchant->id,
                ]],

            // Two query parameters, and the plan id is not even encrypted on this route.
            'Mercado (gate is status + flag)' => ['mercado', 'get', 'plan.mercado',
                fn (Plan $plan, User $merchant) => [
                    'plan' => $plan->id, 'status' => 'approved', 'flag' => 'success',
                ]],

            // The browser posts back the Snap.js result; the plan and the order are independent
            // caller-supplied values.
            'Midtrans (gate is the browser JSON)' => ['midtrans', 'get', 'plan.get.midtrans.status',
                fn (Plan $plan, User $merchant) => [
                    'json'     => '{"status_code":200,"status_message":"Success"}',
                    'plan_id'  => $plan->id,
                    'order_id' => 'MIDTRANS-ORDER-1',
                ]],

            // No gate, and index() returns this very URL to the caller in a JSON response
            // before any payment.
            'PayFast (no gate; URL handed to caller)' => ['payfast', 'get', 'payfast.payment.success',
                fn (Plan $plan, User $merchant) => ['success' => Crypt::encrypt([
                    'plan_id'     => $plan->id,
                    'plan_name'   => $plan->name,
                    'order_id'    => 'PAYFAST-ORDER-1',
                    'plan_amount' => 0,
                ])]],

            // The gate would be a real charge, but \Paymentwall_Charge is absent here.
            'PaymentWall (SDK absent, contract unreadable)' => ['paymentwall', 'post', 'plan.pay.with.paymentwall',
                fn (Plan $plan, User $merchant) => [
                    'plan' => Crypt::encrypt($plan->id),
                    'email' => $merchant->email, 'brick_token' => 'tok', 'brick_fingerprint' => 'fp',
                ]],

            // `$data['status'] == 'true'` on a blob the caller's own checkout form carried.
            'PayU (pre-payment sealed blob)' => ['payu', 'get', 'plan.payu.status',
                fn (Plan $plan, User $merchant) => [
                    'plan_id' => $plan->id,
                    'data'    => Crypt::encrypt(['status' => 'true', 'order_id' => 'PAYU-ORDER-1']),
                ]],

            // Curls the provider, never reads the answer. No network is needed to exploit it --
            // that is the point of the positive case below.
            'Razorpay (verifies, then discards it)' => ['razorpay', 'get', 'plan.razorpay',
                fn (Plan $plan, User $merchant) => [
                    'txref' => 'pay_FORGED1', 'plan_id' => Crypt::encrypt($plan->id),
                ]],

            // `session()->has('skrill_data')`, written by the initiator before leaving for Skrill.
            'Skrill (gate is a pre-payment session key)' => ['skrill', 'get', 'plan.skrill',
                fn (Plan $plan, User $merchant) => [
                    'plan' => Crypt::encrypt($plan->id), 'ORDERID' => $plan->id,
                ]],

            // `status_id=1`, with the plan AND the price as route parameters.
            'Sspay (gate is status_id)' => ['sspay', 'get', 'plan.sspay.callback',
                fn (Plan $plan, User $merchant) => [
                    'plan_id' => $plan->id, 'amount' => 0, 'couponCode' => 0, 'status_id' => 1,
                ]],

            'Toyyibpay (gate is status_id)' => ['toyyibpay', 'get', 'plan.toyyibpay.callback',
                fn (Plan $plan, User $merchant) => [
                    'plan_id' => $plan->id, 'amount' => 0, 'couponCode' => 0, 'status_id' => 1,
                ]],
        ];
    }

    // =========================================================================================
    //  REFUSED WHILE THE PROVIDER IS DISABLED
    // =========================================================================================

    #[DataProvider('forgeableProviders')]
    public function test_a_newly_guarded_provider_refuses_to_activate_a_plan_while_disabled(
        string $provider,
        string $verb,
        string $routeName,
        \Closure $params
    ): void {
        $this->seedSuperAdminCurrency();

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $planBefore   = $merchant->plan_id;
        $ordersBefore = DB::table('plan_orders')->count();

        $response = $this->actingAs($merchant)
            ->{$verb}(route($routeName, $params($plan, $merchant)));

        // THE SUBSTANTIVE ASSERTIONS COME FIRST. A 403 that arrives after a row has been
        // written is not a refusal, and asserting the status first would shadow that.
        $this->assertSame(
            $ordersBefore,
            DB::table('plan_orders')->count(),
            $provider . ': a refused return still wrote a plan_orders row -- a paid plan was '
            . 'recorded as purchased. The guard must be the FIRST statement of the handler, '
            . 'before the PlanOrder is built.'
        );

        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            $provider . ': a refused return still changed the merchant\'s entitlement -- a paid '
            . 'subscription was ACTIVATED FOR FREE. The guard must run before assignPlan().'
        );

        // 403, never 401: a 401 reaching repo B's network layer forces a logout and clears the
        // merchant's stored preferences on a refused payment return.
        $response->assertForbidden();
    }

    // =========================================================================================
    //  THE GUARD IS THE ONLY THING IN THE WAY
    // =========================================================================================

    /**
     * The same requests, with the provider enabled, reach activation and sell the plan.
     *
     * This is what makes the refusals above mean something: without it, a 403 could just as
     * well mean the request never had a chance of working in this fixture.
     *
     * Only three of the eleven can be driven end to end here, and every exclusion is itself a
     * measured finding rather than a convenience -- each has its own test below:
     *
     *   PayU, Skrill, Midtrans  need one more piece of server-side state each (the session entry
     *                           or the pending order their own initiator writes). Proven
     *                           individually, because seeding that state IS the point in each
     *                           case -- it is what an abandoned checkout leaves behind.
     *   Sspay, Toyyibpay        cannot INSERT on sqlite at all: neither handler sets txn_id, and
     *                           plan_orders.txn_id is NOT NULL
     *                           (2023_12_11_102124_create_plan_orders_table.php). MySQL outside
     *                           strict mode silently substitutes ''; sqlite refuses. Nothing to
     *                           do with the guard.
     *   IyziPay                 runs a MySQL-only BINARY query after the order is saved and
     *                           before assignPlan().
     *   Razorpay                needs the provider to answer; with no network its own
     *                           catch (\Exception) swallows the result.
     *   PaymentWall             \Paymentwall_Charge does not exist here.
     *
     * For those five, test_an_enabled_provider_gets_past_the_guard() asserts the other half of
     * the attribution: with the provider enabled the response is no longer 403, so the 403 in
     * the refusal test is the guard and nothing else.
     */
    public static function activatableProviders(): array
    {
        $rows = self::forgeableProviders();

        foreach (self::unfinishableHere() as $name) {
            unset($rows[$name]);
        }

        unset(
            $rows['PayU (pre-payment sealed blob)'],
            $rows['Skrill (gate is a pre-payment session key)'],
            $rows['Midtrans (gate is the browser JSON)'],
        );

        return $rows;
    }

    /** Data-provider row names that cannot complete an INSERT or a charge in this environment. */
    private static function unfinishableHere(): array
    {
        return [
            'IyziPay (no gate; user from request)',
            'PaymentWall (SDK absent, contract unreadable)',
            'Razorpay (verifies, then discards it)',
            'Sspay (gate is status_id)',
            'Toyyibpay (gate is status_id)',
        ];
    }

    public static function unfinishableProviders(): array
    {
        return array_intersect_key(self::forgeableProviders(), array_flip(self::unfinishableHere()));
    }

    #[DataProvider('activatableProviders')]
    public function test_the_guard_is_the_only_thing_refusing_a_forged_return(
        string $provider,
        string $verb,
        string $routeName,
        \Closure $params
    ): void {
        $this->seedSuperAdminCurrency();
        $this->enableProvider($provider);

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $this->actingAs($merchant)
            ->{$verb}(route($routeName, $params($plan, $merchant)))
            ->assertStatus(302);

        // THE ENTITLEMENT IS THE DAMAGE, so it is what is asserted. Not the status code: these
        // handlers return a redirect on success and on most of their failure branches alike.
        $this->assertSame(
            (string) $plan->id,
            (string) $merchant->fresh()->plan_id,
            $provider . ': with the provider enabled this forged return did NOT activate the '
            . 'plan, so the 403 asserted in the refusal test above may not be the guard at all '
            . '-- it could be any later failure in this fixture. Either the handler changed or '
            . 'this positive case needs rebuilding; until it passes, the refusal proves nothing.'
        );

        $this->assertNotNull(
            DB::table('plan_orders')->where('plan_id', $plan->id)->first(),
            $provider . ': the plan was activated but no plan_orders row was written.'
        );
    }

    /**
     * THE ATTRIBUTION HALF FOR THE FIVE THAT CANNOT FINISH HERE.
     *
     * A 403 is only evidence about the guard if the guard is the only thing that produces one.
     * These five fail later for reasons that have nothing to do with activation -- a NOT NULL
     * column, a MySQL-only query, an absent SDK, an unreachable provider -- so the proof is that
     * enabling the provider changes the 403 into that later failure. If the response were still
     * 403 with the provider enabled, the refusal test would be measuring something else.
     */
    #[DataProvider('unfinishableProviders')]
    public function test_an_enabled_provider_gets_past_the_guard(
        string $provider,
        string $verb,
        string $routeName,
        \Closure $params
    ): void {
        $this->seedSuperAdminCurrency();
        $this->enableProvider($provider);

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $response = $this->actingAs($merchant)
            ->{$verb}(route($routeName, $params($plan, $merchant)));

        $this->assertNotSame(
            403,
            $response->status(),
            $provider . ': the request is STILL refused with 403 after the provider was enabled, '
            . 'so the 403 in the refusal test is not attributable to '
            . 'assertPlanActivationEnabled() -- something else on this path is aborting and the '
            . 'refusal proves nothing about the guard.'
        );
    }

    /**
     * RAZORPAY'S VERIFICATION IS PERFORMED AND THEN DISCARDED -- asserted on the code, because
     * it cannot be asserted on the behaviour here.
     *
     * getPaymentStatus() curls https://api.razorpay.com/v1/payments/{pay_id} and then falls
     * straight through to the PlanOrder write and assignPlan() with NO conditional between
     * them: $response is read only for `$response->amount / 100` and the txn_id, so the
     * payment's `status` is never judged. The payment id is a route parameter, so a merchant's
     * own ABANDONED or FAILED payment -- which Razorpay returns happily, with an amount -- is
     * enough.
     *
     * WHY NOT DRIVE THE ROUTE. With no outbound network the handler does not reach activation at
     * all: curl_exec() returns false, `$response->amount` on null becomes an ErrorException
     * under Laravel's error handler, and its own catch (\Exception) redirects with 'Plan not
     * found!'. Faking it is not available either -- this is raw curl, not the Http facade. So
     * the honest proof is the absence of the gate, and that is what is asserted. The behavioural
     * half is covered by test_an_enabled_provider_gets_past_the_guard().
     *
     * THIS GOES RED ON A FIX AS WELL AS ON A REGRESSION. If someone adds the missing
     * `$response->status` check, this test fails and sends them to move razorpay off the
     * config/payments.php map and into the verified inventory -- which is exactly the
     * conversation that should happen.
     */
    public function test_razorpay_never_judges_the_provider_response_it_fetches(): void
    {
        $src = $this->codeWithoutComments(
            (string) file_get_contents(app_path('Http/Controllers/RazorpayPaymentController.php'))
        );

        $curl = strpos($src, 'curl_exec');
        $this->assertNotFalse($curl, 'RazorpayPaymentController no longer calls curl_exec.');

        $assign = strpos($src, 'assignPlan(', $curl);
        $this->assertNotFalse($assign, 'No assignPlan() after the curl; the handler has changed '
            . 'shape and this test must be re-read rather than adjusted.');

        $between = substr($src, $curl, $assign - $curl);

        $this->assertDoesNotMatchRegularExpression(
            '/\b(if|while)\s*\([^;{]*\$response/',
            $between,
            'RazorpayPaymentController now tests $response before assignPlan(). If that is a '
            . "real gate on the payment's status, this provider is no longer in the same class "
            . 'as the other ten: move razorpay out of config(payments.plan_activation) into '
            . "config(payments.plan_activation_provider_verified), add it to "
            . 'PlanPaymentVerificationInventoryTest::verifiedHandlers() with its marker, and '
            . 'remove it from this file.'
        );
    }

    /**
     * SSPAY AND TOYYIBPAY CANNOT RECORD AN ORDER ON THIS CONNECTION AT ALL.
     *
     * Neither handler sets txn_id, and plan_orders.txn_id is NOT NULL
     * (2023_12_11_102124_create_plan_orders_table.php). So the INSERT fails here with
     *   SQLSTATE[23000]: NOT NULL constraint failed: plan_orders.txn_id
     * which their `catch (Exception)` turns into a redirect. MySQL outside strict mode
     * substitutes '' and the free activation completes, which is the configuration this product
     * ships on -- so this is a portability defect, NOT a reason to think the handlers are safe.
     *
     * Recorded as a test rather than a comment because it is the reason those two are excluded
     * from the end-to-end positive proof, and an unexplained exclusion is how a positive test
     * quietly stops covering something.
     */
    public function test_sspay_and_toyyibpay_omit_the_not_null_txn_id_column(): void
    {
        foreach (['SspayController', 'ToyyibpayController'] as $controller) {
            $src = $this->codeWithoutComments(
                (string) file_get_contents(app_path('Http/Controllers/' . $controller . '.php'))
            );

            $this->assertStringNotContainsString(
                '$order->txn_id',
                $src,
                $controller . ' now sets txn_id, so its INSERT should succeed on sqlite and it '
                . 'belongs in the end-to-end positive proof (activatableProviders()) instead of '
                . 'in unfinishableHere().'
            );
        }

        $migration = (string) file_get_contents(
            database_path('migrations/2023_12_11_102124_create_plan_orders_table.php')
        );

        $this->assertStringContainsString(
            "\$table->string('txn_id', 100);",
            $migration,
            'plan_orders.txn_id is no longer declared NOT NULL, so the exclusion above has lost '
            . 'its reason.'
        );
    }

    /**
     * Skrill: the gate is a session key its own initiator wrote before the browser left.
     */
    public function test_skrill_activates_on_a_session_key_its_own_abandoned_checkout_wrote(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('skrill');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        // Exactly what planPayWithSkrill() puts there at :176, before redirecting to Skrill.
        // Nothing about this says the payment completed -- only that it was started.
        $this->withSession(['skrill_data' => [
            'amount'   => 499,
            'trans_id' => md5('abandoned'),
            'currency' => 'USD',
        ]]);

        $this->actingAs($merchant)->get(route('plan.skrill', [
            'plan'    => Crypt::encrypt($plan->id),
            'ORDERID' => $plan->id,
        ]))->assertStatus(302);

        $this->assertSame(
            (string) $plan->id,
            (string) $merchant->fresh()->plan_id,
            'Skrill did not activate on a bare session key, so the classification that put it on '
            . 'the disabled map needs re-reading.'
        );
    }

    /**
     * Midtrans: the browser's own JSON, and a plan that need not match the order.
     *
     * The order is found by $request['order_id'] and flipped to 'succeeded', while the plan
     * comes from $request['plan_id'] -- two independent caller-supplied values. So a merchant
     * can take the order_id of their own CHEAP pending order and name the most expensive plan.
     * That is asserted here, not merely the activation, because it is a second defect on the
     * same path and the guard closes both.
     */
    public function test_midtrans_assigns_a_plan_the_paid_order_never_named(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('midtrans');

        $merchant  = $this->makeMerchant();
        $cheapPlan = $this->makePlan('5');
        $richPlan  = $this->makePlan('999');

        // The merchant's own pending order, for the CHEAP plan, as paywithMidtrans() writes it.
        PlanOrder::create([
            'order_id'       => 'MIDTRANS-CHEAP-1',
            'plan_name'      => $cheapPlan->name,
            'plan_id'        => $cheapPlan->id,
            'price'          => 5,
            'price_currency' => 'USD',
            'txn_id'         => '',
            'payment_type'   => 'Midtrans',
            'payment_status' => 'pending',
            'user_id'        => $merchant->id,
        ]);

        $this->actingAs($merchant)->get(route('plan.get.midtrans.status', [
            'json'     => '{"status_code":200,"status_message":"Success"}',
            'plan_id'  => $richPlan->id,
            'order_id' => 'MIDTRANS-CHEAP-1',
        ]))->assertStatus(302);

        $this->assertSame(
            (string) $richPlan->id,
            (string) $merchant->fresh()->plan_id,
            'Midtrans did not assign the expensive plan from a cheap order, so the cross-plan '
            . 'half of this classification needs re-reading.'
        );

        // And the books still say five: the expensive entitlement against the cheap price, which
        // is internally consistent and therefore invisible in a report.
        $this->assertEqualsWithDelta(
            5.0,
            (float) DB::table('plan_orders')->where('order_id', 'MIDTRANS-CHEAP-1')->value('price'),
            0.009,
            'The cheap order no longer carries the cheap price.'
        );
    }

    // =========================================================================================
    //  THE TWO THAT CANNOT REACH ACTIVATION HERE -- AND WHY THAT IS NOT A DEFENCE
    // =========================================================================================

    /**
     * IyziPay records the purchase and then dies before assigning the plan, on any non-MySQL
     * connection.
     *
     * iyzipayCallback() runs `PlanCoupon::whereRaw('BINARY `code` = ?')` -- unconditionally,
     * AFTER $order->save() and BEFORE assignPlan(). BINARY is MySQL-only. The same defect was
     * found in five other handlers and is why GuardsPlanActivation::findPlanCouponExact()
     * exists.
     *
     * This is the WORST of the two halves: the merchant is invoiced and gets nothing. It is also
     * not a reason to think the handler is safe -- on the MySQL connection this product ships
     * on, the query succeeds and the free activation completes.
     *
     * Asserted on the OUTCOME (a row written, no entitlement, a 500) rather than by catching a
     * throwable: Laravel's test client routes exceptions through the handler, so a try/catch
     * around the request catches the test's own failure instead of the handler's error -- which
     * is exactly what the first draft of this test did.
     */
    public function test_iyzipay_records_the_order_then_dies_before_assigning_the_plan(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('iyzipay');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $this->assertNotSame(
            'mysql',
            DB::connection()->getDriverName(),
            'This test documents the sqlite behaviour of a MySQL-only query; on MySQL the free '
            . 'activation completes instead and this assertion must be re-read, not adjusted.'
        );

        $response = $this->actingAs($merchant)->post(route('iyzipay.payment.callback', [
            'id' => $plan->id, 'amount' => 0, 'coupan_code' => 'ANY',
        ]), ['user_id' => $merchant->id]);

        $this->assertSame(
            500,
            $response->status(),
            'iyzipayCallback no longer fails on the BINARY coupon query. If the query has been '
            . 'made portable, move IyziPay into activatableProviders() and assert the activation '
            . 'instead -- the handler still has no gate.'
        );

        // The order IS written; the entitlement is NOT. Invoiced and not served.
        $this->assertNotNull(
            DB::table('plan_orders')->where('plan_id', $plan->id)->first(),
            'The order was not recorded, so the failure happens earlier than described here.'
        );

        $this->assertNotSame(
            (string) $plan->id,
            (string) $merchant->fresh()->plan_id,
            'The plan WAS assigned, so the failure happens after assignPlan() and this '
            . 'description is wrong.'
        );
    }

    /**
     * PayU: the gate is a sealed blob, and the price comes from a session its own initiator
     * wrote.
     *
     * planPayWithPayU() seals ['status' => 'true', 'order_id' => $pay_id] into the surl and puts
     * the checkout under the session key "'<pay_id>'" -- with the literal quotes, which is
     * reproduced exactly here because that is the key the handler reads. Both halves are what an
     * ABANDONED checkout leaves behind: the browser has the surl (rendered into a hidden input
     * at plans/payu_request.blade.php:14) and the server has the session entry. Neither says a
     * payment happened.
     */
    public function test_payu_activates_on_a_sealed_blob_and_its_own_abandoned_session(): void
    {
        $this->seedSuperAdminCurrency();
        $this->enableProvider('payu');

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $this->withSession(["'PAYU-ORDER-1'" => [
            'txnid'       => 'PAYU-ORDER-1',
            'amount'      => 499,
            'coupon_code' => '',
        ]]);

        $this->actingAs($merchant)->get(route('plan.payu.status', [
            'plan_id' => $plan->id,
            'data'    => Crypt::encrypt(['status' => 'true', 'order_id' => 'PAYU-ORDER-1']),
        ]))->assertStatus(302);

        $this->assertSame(
            (string) $plan->id,
            (string) $merchant->fresh()->plan_id,
            'PayU did not activate on a sealed pre-payment blob, so the classification that put '
            . 'it on the disabled map needs re-reading.'
        );

        $this->assertNotNull(
            DB::table('plan_orders')->where('plan_id', $plan->id)->first(),
            'PayU recorded no order.'
        );
    }

    /**
     * PaymentWall's gate cannot be verified here because its SDK is not here.
     *
     * The handler's gate -- $charge->isSuccessful() and $charge->isCaptured() -- would be a real
     * provider charge, which is why this one is on the map for a DIFFERENT reason from the other
     * ten: not because the gate is forgeable, but because the contract is unreadable and
     * untestable from this repo. The Yookassa precedent exactly.
     *
     * Measured here rather than asserted from memory, and the private key is checked too: even
     * with the SDK installed, planPayWithPaymentWall sets the literal 'sdrsefrszdef' as the
     * private key instead of reading the admin setting, so the charge could not authenticate.
     */
    public function test_paymentwall_cannot_reach_activation_because_its_sdk_is_absent(): void
    {
        $this->assertFalse(
            class_exists('Paymentwall_Charge'),
            'A Paymentwall SDK is now installed. The classification in config/payments.php rests '
            . 'on its absence, so re-read the gate: if isSuccessful()/isCaptured() can now be '
            . 'exercised, this provider may belong in '
            . 'PlanPaymentVerificationInventoryTest::verifiedHandlers() instead.'
        );

        $src = (string) file_get_contents(app_path('Http/Controllers/PaymentWallPaymentController.php'));

        $this->assertStringContainsString(
            "'private_key' => 'sdrsefrszdef'",
            $src,
            'The hardcoded PaymentWall private key has been replaced. That is a fix -- update '
            . "config/payments.php's reason for this provider, which cites it."
        );
    }

    /**
     * The file's code with comments removed.
     *
     * NOT OPTIONAL for the structural assertions in this file, and the reason is the trap this
     * exact pattern has already sprung on this project: a scan for `catch (\Exception` matches
     * the phrase inside an explanatory COMMENT about that very defect, and reports the opposite
     * of the truth. PHP's own lexer is the only thing that reliably knows where a comment ends.
     */
    private function codeWithoutComments(string $src): string
    {
        $out = '';

        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
