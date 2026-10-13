<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE PLAN-PURCHASE INVENTORY, AS A RATCHET IN BOTH DIRECTIONS.
 * =============================================================================================
 *
 * WHY THIS FILE WAS REWRITTEN, and the lesson in it. Its previous version pinned the INVENTORY
 * of unrepaired handlers by asserting that each one still contained the literal text
 * `$order->price = $getAmount;` -- the idea being that repairing a handler would redden its
 * entry so a fix could not be silent.
 *
 * It did not work. The repair changed where $getAmount COMES FROM
 * (`$getAmount = $request->get_amount` became `$getAmount = $this->serverOwnedPlanPrice(...)`)
 * and left the assignment line untouched, so all sixteen tests stayed green across a change that
 * closed fifteen free-activation paths. Worse, the census helper traced one hop from the
 * assignment and found `$request` inside `serverOwnedPlanPrice($plan, $request->coupon_code)`,
 * so it still counted every repaired handler as request-priced.
 *
 * That is the same failure as the storefront smoke test that passed with the tenancy defect
 * reintroduced: the assertion was on a PROXY for the property, not the property. A text pin on
 * an assignment is a proxy for where a value comes from. This version asserts the thing itself.
 *
 * WHAT IT NOW PINS.
 *
 *   THE CENSUS, INVERTED. No plan-purchase writer may record $order->price from request data at
 *   all. Previously nineteen-to-twenty-two did; the measured count is now zero, excluding three
 *   handlers that price from SESSION state and are named individually with the reason. A new
 *   gateway that lands with a request-sourced price reddens this immediately, and so does a
 *   regression in an existing one.
 *
 *   THE GUARD, PER FORGEABLE HANDLER. Covered behaviourally by PlanPaymentFailClosedTest (403,
 *   no plan_orders row, no entitlement change, per provider) and structurally by
 *   PlanPaymentPostureTest. This file does not duplicate that.
 *
 *   THE VERIFICATION MARKERS of the eight handlers that genuinely ask their gateway, so that
 *   losing one is caught. CLASSIFICATION IS HAND-READ, NOT COMPUTED: pattern matching got it
 *   wrong four separate times here. Tap looked ungated because its gate reads
 *   `->status != "CAPTURED"`; Benefit looked ungated until its outbound Guzzle call was read;
 *   Paynow, Paytm and Xendit looked unverified to a scan for a raw HTTP client because they
 *   verify through a helper or an SDK; and PayTR looked SAFE because PlanpayWithPaytr assigns
 *   `$request['payment_status']` from its own curl result -- in a different Request, in a
 *   different HTTP lifecycle, which the callback route does not share.
 */
class PlanPaymentVerificationInventoryTest extends TestCase
{
    /**
     * Handlers repaired across this phase and the last. Their guards are proven behaviourally:
     * eSewa and Sofort by PlanPaymentBypassTest, the other fifteen by PlanPaymentFailClosedTest.
     */
    private const REPAIRED = [
        'ESewaPaymentController',
        'SofortController',
        'NepalstePaymnetController',
        'PayHerePaymnetController',
        'PhonePePaymentController',
        'PaddlePaymentController',
        'FedPayPaymentController',
        'EasebuzzController',
        'DPOPayController',
        'YookassaController',
        'SenangPayController',
        'PaytrController',
        'PaiementProPaymentController',
        'OzowController',
        'CoingateController',
        'PaytabsController',
        'SSLCommerzPaymentController',
    ];

    /**
     * Handlers that DO verify with the provider before activating, so a forged return cannot
     * activate a plan. The value is the marker that proves it.
     *
     * CinetPay and Powertranz joined this list this cycle. Both were previously recorded as
     * "not hand-verified"; reading them resolves them onto the safe side, and the other three in
     * that residue (PayTR, PaiementPro, Ozow) resolved onto the forgeable side and are now in
     * REPAIRED above. The residue is empty.
     *
     * Each of these ALSO used to record a request-supplied price -- a data-integrity defect
     * rather than a free activation -- and that is now closed for all eight. No amount-mismatch
     * refusal was added on these paths: the amount a verified provider posts back may
     * legitimately be in minor units, and this environment cannot reach one to find out.
     */
    public static function verifiedHandlers(): array
    {
        return [
            'Tap: provider charge status'       => ['TapPaymnetController', 'CAPTURED'],
            'Cashfree: provider order status'   => ['CashfreeController', 'payment_status == "SUCCESS"'],
            'Benefit: outbound charges lookup'  => ['BenefitPaymentController', "\$data->gateway->response->code"],
            'Paynow: provider status helper'    => ['PaynowController', 'paynowgetstatus('],
            'Paytm: SDK transaction check'      => ['PaytmPaymentController', 'isSuccessful()'],
            'Xendit: invoice status lookup'     => ['XenditPaymentController', "'PAID'"],
            'CinetPay: outbound status lookup'  => ['CinetPayController', "\$response_body['code'] == '00'"],
            'Powertranz: outbound SPI payment'  => ['PowertranzPaymentController', "\$response_data['IsoResponseCode'] == \"00\""],

            // Also outside the old census, and all three resolve onto the SAFE side on reading.
            'CyberSource: outbound payments API' => ['CyberSourceController', '"AUTHORIZED"'],
            'Mollie: SDK payment lookup'         => ['MolliePaymentController', '$payment->isPaid()'],
            'Stripe: live charge creation'       => ['StripePaymentController', 'Stripe\\Charge::create'],

            // -------------------------------------------------------------------------------
            // SEVEN OF THE NINETEEN HANDLERS THAT HAD NEVER BEEN CLASSIFIED AT ALL.
            //
            // Nineteen assignPlan() callers had never had their activation gate read. Eleven
            // are now fail-closed on the config/payments.php map, one (BankTransfer) is a
            // platform-operator approval rather than a provider return, and these seven ask
            // their provider before activating.
            //
            // FOUR OF THE SEVEN TAKE THE MONEY ON THIS VERY REQUEST -- AuthorizeNet's
            // authCaptureTransaction, Braintree's sale, NMI's type=sale and PayPal's capture --
            // which is the strongest verification available anywhere in this codebase: the
            // charge succeeds or the gate fails. The other three re-fetch the transaction from
            // the provider with the merchant secret.
            //
            // AND THE TWO THAT PATTERN-MATCHING WOULD HAVE GOT WRONG, in both directions.
            // Khalti reads as ungated to a scan for an HTTP client because it verifies through
            // App\Khalti\Khalti::verifyPayment() (app/Khalti/Khalti.php:16-42, a curl POST to
            // khalti.com/api/v2/payment/verify/ carrying the merchant secret, whose HTTP status
            // is the gate) -- the same helper-indirection trap as Paynow. Razorpay reads as
            // VERIFIED to the same scan because it genuinely curls api.razorpay.com -- and then
            // never looks at the answer, so it is on the disabled map instead. The outbound
            // call is not the property; requiring its result is.
            //
            // A RESIDUAL WEAKNESS IS RECORDED, NOT HIDDEN. Flutterwave and Paystack gate on the
            // verify call's envelope (`$response['status'] == 'success'`, `$result['status'] ==
            // true`) rather than on the transaction state that the same response carries --
            // Paystack's own handler reads `$result['data']['status']` as the payment status two
            // lines later. So a reference from the caller's own FAILED or ABANDONED checkout may
            // satisfy these gates. That is a narrowing of an existing verification, not a free
            // activation of the kind the eleven had, and tightening a gate on a live integration
            // needs the provider's documented response contract -- which this environment cannot
            // reach. Left as-is deliberately; see the report and config/payments.php.
            // -------------------------------------------------------------------------------
            'AuthorizeNet: SDK authCapture'      => ['AuthorizeNetPaymnetController', 'getResultCode() == "Ok"'],
            'Braintree: SDK sale'                => ['BraintreeController', '$gateway->transaction()->sale('],
            'Flutterwave: outbound verify'       => ['FlutterwaveController', 'getpaidx/api/v2/verify'],
            'Khalti: provider verify helper'     => ['KhaltiPaymnetController', 'verifyPayment('],
            'NMI: outbound sale'                 => ['NMIPayController', "\$parsedResponse['response'] == 1"],
            'PayPal: outbound capture'           => ['PaypalPaymentController', 'capturePaymentOrder('],
            'Paystack: outbound verify'          => ['PaystackPaymentController', 'api.paystack.co/transaction/verify'],
        ];
    }

    /**
     * THE NINETEEN THAT HAD NEVER BEEN CLASSIFIED, AND WHERE EACH ONE LANDED.
     *
     * [provider map key | null, verification marker | null]. Exactly one of the two is set:
     *
     *   a map key   => FORGEABLE. The handler must call assertPlanActivationEnabled() for that
     *                  key, and that key's shipped default must be DISABLED.
     *   a marker    => PROVIDER-VERIFIED. The controller must still carry the marker, and must
     *                  be recorded in config('payments.plan_activation_provider_verified').
     *
     * WHY THIS IS A PIN AND NOT A SCAN. The classification is hand-read -- this question has
     * been misread five times on this project -- so what is pinned is the ANSWER, in both
     * directions. Moving a handler from one column to the other reddens this file, which is the
     * point: a silent FIX (verification implemented, guard quietly dropped) and a silent
     * REGRESSION (guard removed, nothing implemented) look identical to a test that only
     * asserts the guard is present.
     *
     * BankTransferController is the nineteenth and is in neither column; see
     * test_bank_transfer_activation_is_gated_by_platform_operator_authorization.
     */
    public static function newlyClassifiedHandlers(): array
    {
        return [
            // The eleven forgeable ones.
            'Aamarpay'    => ['AamarpayController', 'aamarpay', null],
            'IyziPay'     => ['IyziPayController', 'iyzipay', null],
            'Mercado'     => ['MercadoPaymentController', 'mercado', null],
            'Midtrans'    => ['MidtransController', 'midtrans', null],
            'PayFast'     => ['PayFastController', 'payfast', null],
            'PaymentWall' => ['PaymentWallPaymentController', 'paymentwall', null],
            'PayU'        => ['PayUPaymentController', 'payu', null],
            'Razorpay'    => ['RazorpayPaymentController', 'razorpay', null],
            'Skrill'      => ['SkrillPaymentController', 'skrill', null],
            'Sspay'       => ['SspayController', 'sspay', null],
            'Toyyibpay'   => ['ToyyibpayController', 'toyyibpay', null],

            // The seven provider-verified ones.
            'AuthorizeNet' => ['AuthorizeNetPaymnetController', null, 'getResultCode() == "Ok"'],
            'Braintree'    => ['BraintreeController', null, '$gateway->transaction()->sale('],
            'Flutterwave'  => ['FlutterwaveController', null, 'getpaidx/api/v2/verify'],
            'Khalti'       => ['KhaltiPaymnetController', null, 'verifyPayment('],
            'NMI'          => ['NMIPayController', null, "\$parsedResponse['response'] == 1"],
            'PayPal'       => ['PaypalPaymentController', null, 'capturePaymentOrder('],
            'Paystack'     => ['PaystackPaymentController', null, 'api.paystack.co/transaction/verify'],
        ];
    }

    #[DataProvider('newlyClassifiedHandlers')]
    public function test_a_newly_classified_handler_stays_on_the_side_it_was_read_onto(
        string $controller,
        ?string $mapKey,
        ?string $marker
    ): void {
        $src = $this->source($controller);

        if ($mapKey !== null) {
            $this->assertStringContainsString(
                "assertPlanActivationEnabled('" . $mapKey . "'",
                $src,
                $controller . ' no longer calls assertPlanActivationEnabled(\'' . $mapKey . '\'). '
                . 'This handler activates a paid subscription on something the caller holds -- a '
                . 'query parameter, a pre-payment blob this server sealed and handed out, a '
                . 'session key its own abandoned checkout wrote, or in two cases nothing at all. '
                . 'Without the guard the subscription is FREE. If real provider verification has '
                . 'been implemented, move this row to the marker column and the provider out of '
                . 'config(payments.plan_activation) into plan_activation_provider_verified.'
            );

            $this->assertNotNull(
                config('payments.plan_activation.' . $mapKey),
                $mapKey . ' has been removed from config(payments.plan_activation). A missing key '
                . 'is treated as DISABLED by GuardsPlanActivation, so this is not an open door -- '
                . 'but the entry carried the REASON and the RE-ENABLE CONDITIONS.'
            );

            return;
        }

        $this->assertStringContainsString(
            $marker,
            $src,
            $controller . ' no longer carries the provider verification that put it on the SAFE '
            . 'side of this census (' . $marker . '). It is NOT on the config/payments.php map, '
            . 'because it asks its provider before activating -- so if that is gone, nothing is '
            . 'standing in the way and this handler now activates paid plans on caller-supplied '
            . 'data.'
        );

        $this->assertArrayHasKey(
            $controller,
            (array) config('payments.plan_activation_provider_verified'),
            $controller . ' is no longer recorded as provider-verified in config/payments.php, so '
            . 'the file no longer says WHY it is absent from the disabled map.'
        );
    }

    /**
     * THE TWO VERIFIED HANDLERS WHOSE RECORDED PRICE WAS THE CALLER'S.
     *
     * Both ask their provider before activating, so neither is on the config/payments.php map
     * and neither gate was touched -- but both recorded an amount the caller chose, and
     * AuthorizeNet's was worse than a bad row: the same figure was CHARGED TO THE CARD.
     *
     *   AuthorizeNet  $amount came from json_decode($input['data'])['amount'], a POSTED hidden
     *                 field (plans/request.blade.php:34), and was handed to
     *                 setAmount() before being recorded. So
     *                 {"id":<expensive plan>,"amount":0.01} bought any plan for a cent -- and
     *                 the provider verification confirmed it honestly, because the cent really
     *                 was charged. It is computed once now, so the figure charged and the figure
     *                 recorded cannot disagree.
     *   PayPal        $order->price came from the {amount} ROUTE parameter
     *                 ({id}/{amount}/plan-paypal-payment/, routes/web.php:601).
     *
     * ASSERTED STRUCTURALLY, AND SCOPED TO THE WRITING METHOD, because neither path can be
     * driven here: AuthorizeNet's SDK and srmklive/paypal both carry their own HTTP clients, so
     * Http::fake() cannot reach either capture. A file-wide assertion would be satisfied by a
     * serverOwnedPlanPrice() call in some OTHER method, which is precisely the kind of proxy
     * assertion that let the previous version of this file stay green across a
     * fifteen-handler repair.
     *
     * The FORGEABLE SOURCE is asserted absent as well as the helper present: adding the helper
     * while leaving the old expression in place would restore the defect and satisfy a
     * presence-only check.
     */
    public static function repairedVerifiedPrices(): array
    {
        return [
            'AuthorizeNet: amount from a posted blob' => [
                'AuthorizeNetPaymnetController',
                'planPayWithAuthorizeNetData',
                "\$amount = \$data['amount']",
            ],
            'PayPal: amount from the route' => [
                'PaypalPaymentController',
                'planGetPaymentStatus',
                '$order->price = $amount',
            ],
        ];
    }

    #[DataProvider('repairedVerifiedPrices')]
    public function test_a_verified_handler_records_the_plans_price_and_not_the_callers(
        string $controller,
        string $method,
        string $forgeableSource
    ): void {
        $body = $this->bodyOfMethod($this->codeOnly($this->source($controller)), $method);

        $this->assertNotNull(
            $body,
            $controller . '::' . $method . ' has been renamed or removed; this pin must follow '
            . 'it rather than be deleted.'
        );

        $this->assertStringContainsString(
            'serverOwnedPlanPrice(',
            $body,
            $controller . '::' . $method . ' no longer prices through '
            . 'GuardsPlanActivation::serverOwnedPlanPrice(). This provider DOES verify with its '
            . 'gateway, so nothing refuses the request -- the recorded amount is simply whatever '
            . 'the caller supplied again'
            . ($controller === 'AuthorizeNetPaymnetController'
                ? ', and on this handler that figure is also the one charged to the card.'
                : '.')
        );

        $this->assertStringNotContainsString(
            $forgeableSource,
            $body,
            $controller . '::' . $method . ' has taken `' . $forgeableSource . '` back. Adding '
            . 'serverOwnedPlanPrice() while leaving the caller-supplied source in place restores '
            . 'the defect and still satisfies a presence-only check, which is why both halves '
            . 'are asserted.'
        );
    }

    /** One named method's body, or null. Brace-matched; see methodsIn(). */
    private function bodyOfMethod(string $src, string $method): ?string
    {
        foreach ($this->methodsIn($src) as [$name, , $bodyStart, $bodyEnd]) {
            if ($name === $method) {
                return substr($src, $bodyStart, $bodyEnd - $bodyStart);
            }
        }

        return null;
    }

    /**
     * THE NINETEENTH HANDLER: AN APPROVAL, NOT A RETURN.
     *
     * BankTransferController::planPayWithbank records a PENDING order with an uploaded receipt
     * and assigns nothing. The activation is orderapprove() (:119), which sets
     * payment_status = 'Approved' and calls assignPlan() on whatever PlanOrder id it is given --
     * so what has to stand in its way is AUTHORIZATION, not provider verification. Before the
     * middleware was attached, any authenticated merchant could approve THEIR OWN pending
     * bank-transfer order and receive a paid plan for nothing.
     *
     * Asserted on the route table because that is where the gate lives: BankTransferController
     * is an upstream payments file and the smaller the structural change, the smaller the merge
     * surface.
     */
    public function test_bank_transfer_activation_is_gated_by_platform_operator_authorization(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('order.approve');

        $this->assertNotNull(
            $route,
            'route("order.approve") has gone. It is the only activation path for a bank-transfer '
            . 'plan purchase; if it has been renamed, this assertion has to follow it.'
        );

        $this->assertContains(
            \App\Http\Middleware\PlatformOperatorOnly::class,
            $route->gatherMiddleware(),
            'route("order.approve") no longer carries PlatformOperatorOnly. '
            . 'BankTransferController::orderapprove() sets payment_status = "Approved" and calls '
            . 'assignPlan() on any PlanOrder id with no authorization of its own, so without this '
            . 'middleware any authenticated merchant can approve their own pending bank-transfer '
            . 'order and receive a paid plan without paying.'
        );
    }

    private function source(string $controller): string
    {
        $path = app_path('Http/Controllers/' . $controller . '.php');
        $this->assertFileExists($path, $controller . ' has moved or been deleted; update this inventory.');

        return (string) file_get_contents($path);
    }

    /**
     * THE SAME SOURCE WITH COMMENTS AND STRING LITERALS REMOVED, for the price scan.
     *
     * Learned the hard way, twice over. The scan matched `$price = $request->total_price`
     * inside a COMMENT that was explaining the very defect being fixed, and reported the
     * repaired controller as still broken -- with half a paragraph of prose as the "offending
     * expression". A scanner that reads comments as code produces false alarms in exactly the
     * files someone has just taken the trouble to document.
     *
     * token_get_all() is used rather than a regex because PHP's own lexer is the only thing that
     * reliably knows where a comment ends.
     */
    private function codeOnly(string $src): string
    {
        $out = '';

        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    // Keep the newlines so reported line context stays roughly honest.
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

    /**
     * The text of one parenthesised call, from its opening '(' to the matching ')'.
     *
     * A FIXED-SIZE WINDOW IS NOT GOOD ENOUGH, measured: a 2000-character window opened at
     * PaytabsController's PlanOrder::create(...) ran 35 lines past the end of the call and
     * swallowed
     *   route('plan.paytabs.callback', ['plan_id' => ..., 'price' => $request->total_price])
     * which is a route parameter, not a PlanOrder field -- so the census reported a repaired
     * controller as broken. Counting parentheses costs four lines and removes the guesswork.
     *
     * Comments and strings are already gone by the time this runs (see codeOnly()), so a
     * parenthesis inside a string literal cannot unbalance the count.
     */
    private function balancedCall(string $src, int $openParenAt): string
    {
        $depth = 0;
        $len   = strlen($src);

        for ($i = $openParenAt; $i < $len; $i++) {
            if ($src[$i] === '(') {
                $depth++;
            } elseif ($src[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($src, $openParenAt, $i - $openParenAt + 1);
                }
            }
        }

        return substr($src, $openParenAt);
    }

    #[DataProvider('verifiedHandlers')]
    public function test_a_verified_plan_purchase_handler_still_verifies(
        string $controller,
        string $marker
    ): void {
        $this->assertStringContainsString(
            $marker,
            $this->source($controller),
            $controller . ' no longer carries its provider verification marker (' . $marker
            . '). Losing it would put this handler into the same class as the fifteen that had to '
            . 'be disabled -- and unlike those, this one is NOT on the config/payments.php map, '
            . 'so nothing else is standing in the way.'
        );
    }

    /**
     * THE CENSUS, INVERTED: NOTHING MAY PRICE A PLAN PURCHASE FROM THE REQUEST.
     *
     * The scan is deliberately WIDER than the real defect and the exceptions are NAMED, because
     * a wide net with named exceptions misses less than a narrow net -- the previous version's
     * one-hop tracer was narrowed by accident and went blind to a fifteen-handler repair.
     */
    public function test_no_plan_purchase_writer_records_a_request_sourced_price(): void
    {
        $writers = [];
        $flagged = [];

        foreach ((glob(app_path('Http/Controllers') . '/*.php') ?: []) as $file) {
            $src = $this->codeOnly((string) file_get_contents($file));

            if (! str_contains($src, 'assignPlan(')) {
                continue;
            }

            // FOUR WRITE SHAPES, NOT ONE. The previous scan required `$order->price =` and so
            // never saw CoinGate (`$planorder->price`), PayTabs and CyberSource
            // (`PlanOrder::create([... 'price' => ...])`) or SSLCommerz (no price write at all,
            // because it flips an existing row). Three of those four turned out to be
            // free-activation paths.
            if (
                ! preg_match('/\$order->price\s*=/', $src)
                && ! preg_match('/\$planorder->price\s*=/', $src)
                && ! preg_match("/PlanOrder::create\s*\(/", $src)
            ) {
                continue;
            }

            $writers[] = basename($file, '.php');

            $why = $this->requestSourcedPrice($src);

            if ($why !== null) {
                $flagged[basename($file, '.php')] = $why;
            }
        }

        /**
         * SESSION-PRICED, AND THEREFORE ALREADY SERVER-OWNED. Named rather than detected,
         * because the detector traces one hop from the price variable to the array literal that
         * built the session payload, and that literal mentions $request->coupon -- so all three
         * read as request-priced to any automatic rule that is not told otherwise.
         *
         *   SenangPayController   price from $senangPaySession['price'], written by
         *                         planPayWithSenangpay from $plan->price. KEPT ON PURPOSE rather
         *                         than recomputed: the session holds the amount the server
         *                         actually charged at checkout, which is strictly more accurate
         *                         than a recompute if the plan's price changes mid-flight.
         *   BraintreeController   plan path reads $session from $request->session()->get() at
         *                         :112 and prices from $session->amount at :143. The
         *                         `$session = $request->toArray()` at :67 belongs to the STORE
         *                         payment path.
         *   MyFatoorahController  prices from $MyFatoorahSession['price'] (:160), read from the
         *                         session at :117, and verifies through
         *                         MyFatoorahPaymentStatus::getPaymentStatus() at :143.
         */
        $sessionPriced = [
            'SenangPayController',
            'BraintreeController',
            'MyFatoorahController',

            /**
             * PROVIDER-PRICED, which is better than either session or recompute, and also a
             * cross-method false positive of the one-hop tracer.
             *
             * MolliePaymentController::getPaymentStatus prices from `$payment->amount->value`
             * (:211), where `$payment = $mollie->payments->get(session()->get('mollie_payment_id'))`
             * (:179) -- i.e. the amount MOLLIE reports for the payment it actually took. The
             * tracer flags it because a DIFFERENT method, planPayWithMollie, assigns
             * `$payment = $mollie->payments->create([...])` (:130) and that array literal
             * mentions $request->plan_id in its redirectUrl. Same file-scoped one-hop limitation
             * as Braintree and MyFatoorah.
             *
             * Not rewritten to a recompute on purpose: the provider's own figure is the most
             * authoritative amount available, and this handler verifies with Mollie
             * ($payment->isPaid()) before using it.
             */
            'MolliePaymentController',
        ];

        $unexpected = array_diff_key($flagged, array_flip($sessionPriced));

        $message = '';
        foreach ($unexpected as $controller => $why) {
            $message .= "\n  " . $controller . "\n      " . $why;
        }

        $this->assertSame(
            [],
            array_keys($unexpected),
            "A plan-purchase writer records the PlanOrder price from REQUEST-SUPPLIED data:"
            . $message
            . "\n\nThat is the pricing half of the free-activation family that cost fifteen "
            . "handlers this phase. Price it from \$plan->price plus a server-validated coupon "
            . "-- GuardsPlanActivation::serverOwnedPlanPrice() does exactly that -- and then ask "
            . "the harder question: before assignPlan(), does this handler check anything the "
            . "caller cannot forge? If not, it also needs an entry on the "
            . "config/payments.php map and a case in PlanPaymentFailClosedTest."
        );

        // Guard the floor. If this collapses, the scan has broken rather than the problem having
        // gone away. 42 plan-purchase writers were measured when this inventory was retaken.
        $this->assertGreaterThanOrEqual(
            40,
            count($writers),
            'Only ' . count($writers) . ' plan-purchase writers were found; the widened scan '
            . 'measured more than forty, so the scan has probably broken and this file is now '
            . 'asserting nothing.'
        );
    }

    /**
     * THE HOLE THAT LET NINETEEN HANDLERS HIDE: A PRICE TAKEN FROM A ROUTE PARAMETER.
     *
     * The census above looks for `$request->` and `$request[`. That is not the only way a
     * caller's number reaches a PlanOrder. Laravel binds route segments to METHOD PARAMETERS,
     * so
     *     Route::get('plan-sspay-payment/{plan_id}/{amount}/{couponCode}', ...)
     *     public function SspayPlanGetPayment(Request $request, $planId, $getAmount, $couponCode)
     *     $order->price = $getAmount;
     * is request-sourced pricing with no `$request` anywhere near it -- and it was invisible to
     * every scan this file has ever run. Four handlers priced this way (Sspay, Toyyibpay,
     * IyziPay and PayPal), and PayFast reached the same place through a Crypt blob passed as a
     * route parameter. That is the same class of mistake as the previous version of this file
     * pinning an assignment instead of the value's origin: the net was narrower than the defect.
     *
     * WHAT THIS ASSERTS, and why it is not simply "never do this": a parameter-priced writer is
     * acceptable ONLY while its provider is DISABLED on the config/payments.php map, because
     * then the handler refuses before it can record anything. So every hit must be a provider
     * that is both guarded and shipped-disabled. A new gateway that lands pricing from its URL
     * with no guard reddens this immediately; so does removing the guard from one of the five.
     *
     * The parameter list is read from the enclosing method, not file-wide: a name that is a
     * parameter of some OTHER method in the same controller is an ordinary local variable here,
     * and treating it as caller input is how a census starts crying wolf.
     */
    public function test_a_plan_purchase_writer_that_prices_from_a_route_parameter_must_be_disabled(): void
    {
        /**
         * The five that price from a parameter and are refused before the write. Each value is
         * the config/payments.php key that must be guarding it.
         *
         * Aamarpay and PayFast reach it through a Crypt::decrypt() of the parameter -- which is
         * unforgeable (it is sealed with APP_KEY) but is minted at checkout START, before any
         * money moves, and then handed out; PayFast's is rendered into a hidden input in the
         * caller's own browser. Sealed is not the same as paid.
         */
        $parameterPricedButGuarded = [
            'AamarpayController'   => 'aamarpay',
            'IyziPayController'    => 'iyzipay',
            'PayFastController'    => 'payfast',
            'SspayController'      => 'sspay',
            'ToyyibpayController'  => 'toyyibpay',
        ];

        $flagged = [];

        foreach ((glob(app_path('Http/Controllers') . '/*.php') ?: []) as $file) {
            $src = $this->codeOnly((string) file_get_contents($file));

            if (! str_contains($src, 'assignPlan(')) {
                continue;
            }

            $why = $this->parameterSourcedPrice($src);

            if ($why !== null) {
                $flagged[basename($file, '.php')] = $why;
            }
        }

        $unexpected = array_diff_key($flagged, $parameterPricedButGuarded);

        $message = '';
        foreach ($unexpected as $controller => $why) {
            $message .= "\n  " . $controller . "\n      " . $why;
        }

        $this->assertSame(
            [],
            array_keys($unexpected),
            'A plan-purchase writer records the PlanOrder price from one of its own METHOD '
            . "PARAMETERS -- i.e. from the URL:" . $message
            . "\n\nThat is request-sourced pricing with no \$request in sight, which is exactly "
            . 'how nineteen handlers stayed out of this census. Price it from $plan->price plus a '
            . 'server-validated coupon (GuardsPlanActivation::serverOwnedPlanPrice()), and then '
            . 'ask the harder question: before assignPlan(), does this handler check anything the '
            . 'caller cannot forge? If not, it needs an entry on the config/payments.php map and '
            . 'a case in PlanPaymentNewlyGuardedTest.'
        );

        // AND THE EXEMPTIONS MUST STILL BE EARNING THEIR EXEMPTION. A named exception that
        // stops being guarded is worse than no exception at all: it reads as "known and
        // handled" while being neither.
        foreach ($parameterPricedButGuarded as $controller => $key) {
            $this->assertArrayHasKey(
                $controller,
                $flagged,
                $controller . ' no longer prices from a route parameter. If it has been repaired '
                . 'to price from the plan, remove it from this list -- leaving it here hides the '
                . 'next handler that does this.'
            );

            $this->assertStringContainsString(
                "assertPlanActivationEnabled('" . $key . "'",
                $this->source($controller),
                $controller . ' prices a plan purchase from a route parameter and NO LONGER '
                . 'carries its fail-closed guard. One authenticated request now records any '
                . 'amount it likes against any plan -- and activates it.'
            );

            $this->assertFalse(
                filter_var(config('payments.plan_activation.' . $key), FILTER_VALIDATE_BOOLEAN),
                $controller . ' prices from a route parameter and ' . $key . ' is now ENABLED, so '
                . 'the guard lets the forged amount through. Enabling this provider requires the '
                . 'price to come from the plan first.'
            );
        }
    }

    /**
     * Does this file assign $order->price from a parameter of the method that writes it?
     *
     * Returns the offending chain (for the failure message) or null. One hop, within the
     * enclosing method body only -- the same depth as requestSourcedPrice(), for the same
     * reason: two hops across method boundaries is where the previous tracer produced the
     * false positives that Braintree, MyFatoorah and Mollie are named for.
     */
    private function parameterSourcedPrice(string $src): ?string
    {
        $methods = $this->methodsIn($src);
        $writes  = [];

        foreach (['/\$order->price\s*=\s*([^;]+);/s', '/\$planorder->price\s*=\s*([^;]+);/s'] as $pattern) {
            if (preg_match_all($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as $hit) {
                    $writes[] = [$hit[0], $hit[1]];
                }
            }
        }

        $offset = 0;

        while (($at = strpos($src, 'PlanOrder::create(', $offset)) !== false) {
            $call   = $this->balancedCall($src, $at + strlen('PlanOrder::create'));
            $offset = $at + strlen('PlanOrder::create(');

            if (preg_match_all("/'price'\\s*=>\\s*([^,\\n]+)/", $call, $m)) {
                foreach ($m[1] as $expr) {
                    $writes[] = [$expr, $at];
                }
            }
        }

        foreach ($writes as [$expr, $position]) {
            if ($this->isServerOwnedPriceExpression($expr)) {
                continue;
            }

            $method = $this->enclosingMethod($methods, $position);

            if ($method === null) {
                continue;
            }

            [$name, $params, $bodyStart, $bodyEnd] = $method;

            // `Request $request` is a parameter too, but it is the framework's object and is
            // already covered by requestSourcedPrice(); counting it here would double-report.
            $params = array_values(array_diff($params, ['request']));

            preg_match_all('/\$([A-Za-z_]\w*)/', $expr, $vm);

            $direct = array_values(array_intersect($vm[1], $params));

            if ($direct !== []) {
                return $name . '(): $order->price = ' . trim($expr)
                    . '   <=   route parameter $' . $direct[0];
            }

            $body = substr($src, $bodyStart, $bodyEnd - $bodyStart);

            foreach ($vm[1] as $variable) {
                if (! preg_match_all('/\$' . $variable . '\s*=\s*([^;]+);/s', $body, $am)) {
                    continue;
                }

                foreach ($am[1] as $assign) {
                    if ($this->isServerOwnedPriceExpression($assign)) {
                        continue;
                    }

                    preg_match_all('/\$([A-Za-z_]\w*)/', $assign, $am2);

                    $hit = array_values(array_intersect($am2[1], $params));

                    if ($hit !== []) {
                        return $name . '(): $order->price = ' . trim($expr) . '   <=   '
                            . trim($assign) . '   <=   route parameter $' . $hit[0];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Sources that are server-owned whatever else the expression mentions.
     *
     * serverOwnedPlanPrice()  the shared helper.
     * priceFromPlanOrFail()   eSewa's and Sofort's equivalent from the previous phase, which
     *                         additionally refuses a mismatched claimed amount. Its signature is
     *                         ($plan, $request) -- the request carries only the coupon CODE --
     *                         so without recognising it by name those two read as request-priced.
     * $plan->                 the plan's own price. MercadoPaymentController is
     *                         `$price == null ? 0 : $price` where `$price = $plan->price`, and
     *                         $plan is its route parameter: plan-sourced through a parameter is
     *                         still plan-sourced.
     * session()/Session::get  server-side state reached through the request object.
     */
    private function isServerOwnedPriceExpression(string $expression): bool
    {
        foreach (['serverOwnedPlanPrice(', 'priceFromPlanOrFail(', '$plan->', 'session()', 'Session::get'] as $marker) {
            if (str_contains($expression, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every method in the file as [name, parameter names, body-start offset, body-end offset].
     *
     * Brace-matched rather than guessed at, for the same reason balancedCall() counts
     * parentheses: a fixed window over a 967-line controller lands in the middle of another
     * method and reports its parameters as this one's.
     */
    private function methodsIn(string $src): array
    {
        $methods = [];
        $offset  = 0;

        while (($at = strpos($src, 'function', $offset)) !== false) {
            $offset = $at + strlen('function');

            $paren = strpos($src, '(', $at);

            if ($paren === false) {
                break;
            }

            $signature = $this->balancedCall($src, $paren);

            preg_match_all('/\$([A-Za-z_]\w*)/', $signature, $m);

            $brace = strpos($src, '{', $paren + strlen($signature));

            if ($brace === false) {
                continue;
            }

            $depth  = 0;
            $end    = strlen($src);
            $length = strlen($src);

            for ($i = $brace; $i < $length; $i++) {
                if ($src[$i] === '{') {
                    $depth++;
                } elseif ($src[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $i;

                        break;
                    }
                }
            }

            $methods[] = [
                trim(substr($src, $at + strlen('function'), $paren - $at - strlen('function'))),
                $m[1],
                $brace,
                $end,
            ];
        }

        return $methods;
    }

    /** The innermost method containing $position (closures nest inside methods). */
    private function enclosingMethod(array $methods, int $position): ?array
    {
        $best = null;

        foreach ($methods as $method) {
            if ($position > $method[2] && $position < $method[3]) {
                if ($best === null || $method[2] > $best[2]) {
                    $best = $method;
                }
            }
        }

        return $best;
    }

    /**
     * Is $order->price assigned from request data, directly or through one hop?
     *
     * Returns the offending expression (for the failure message) or null.
     *
     * TWO SOURCES COUNT AS SERVER-OWNED and are skipped:
     *   serverOwnedPlanPrice(   the shared helper, which prices from $plan->price plus a
     *                           server-validated coupon. Its own argument list mentions
     *                           $request (the coupon CODE, which is only ever used to look a
     *                           coupon up), which is why it must be recognised explicitly
     *                           rather than left to the $request substring test.
     *   session()/Session::get  server-side state reached THROUGH the request object, so a bare
     *                           '$request->' test misreads it as caller input.
     */
    private function requestSourcedPrice(string $src): ?string
    {
        $exprs = [];

        foreach (['/\$order->price\s*=\s*([^;]+);/s', '/\$planorder->price\s*=\s*([^;]+);/s'] as $pattern) {
            if (preg_match_all($pattern, $src, $m)) {
                $exprs = array_merge($exprs, $m[1]);
            }
        }

        // THE ARRAY-LITERAL FORM, PlanOrder::create([... 'price' => <expr> ...]) -- scanned
        // only INSIDE such a call, not file-wide.
        //
        // A file-wide scan for `'price' =>` over-matches, and it did: PaytabsController builds
        // its callback URL as
        //   route('plan.paytabs.callback', ['plan_id' => ..., 'price' => $request->total_price])
        // which is a ROUTE PARAMETER named price, not a PlanOrder field, and the handler on the
        // other end ignores it and prices from $plan->price. Flagging it would have sent the
        // next reader to fix something that is not broken -- and a census that cries wolf is a
        // census people learn to override.
        $offset = 0;

        while (($at = strpos($src, 'PlanOrder::create(', $offset)) !== false) {
            $call   = $this->balancedCall($src, $at + strlen('PlanOrder::create'));
            $offset = $at + strlen('PlanOrder::create(');

            if (preg_match_all("/'price'\\s*=>\\s*([^,\\n]+)/", $call, $m)) {
                $exprs = array_merge($exprs, $m[1]);
            }
        }

        if ($exprs === []) {
            return null;
        }

        foreach ($exprs as $expr) {
            if (str_contains($expr, 'serverOwnedPlanPrice(')) {
                continue;
            }

            if (str_contains($expr, '$request->') || str_contains($expr, '$request[')) {
                return trim($expr);
            }

            // The expression names the plan's own price, so it is plan-sourced whatever else
            // appears in it. BankTransferController is
            // `isset($coupons) ? $plan->discounted_price : $plan->price`; without this the first
            // variable matched is $coupons, which IS assigned from a PlanCoupon lookup keyed on
            // $request->coupon, and the handler is misread as request-priced.
            if (str_contains($expr, '$plan->')) {
                continue;
            }

            if (! preg_match('/\$([A-Za-z_]\w*)/', $expr, $vm)) {
                continue;
            }

            if (preg_match_all('/\$' . $vm[1] . '\s*=\s*([^;]+);/s', $src, $am)) {
                foreach ($am[1] as $assign) {
                    if (str_contains($assign, 'serverOwnedPlanPrice(')) {
                        continue;
                    }

                    if (str_contains($assign, 'session()') || str_contains($assign, 'Session::get')) {
                        continue;
                    }

                    if (str_contains($assign, '$request->') || str_contains($assign, '$request[')) {
                        return trim($expr) . '   <=   ' . trim($assign);
                    }
                }
            }
        }

        return null;
    }

    /**
     * The repaired handlers all route their pricing through the one shared helper.
     *
     * This is what stops the money rule being reimplemented fourteen times and drifting -- in
     * particular the coupon lookup, which must NOT filter on is_active (the checkout initiator
     * deactivates a coupon at its limit, so filtering would recompute a HIGHER price than the
     * server advertised and refuse a paying customer).
     *
     * eSewa and Sofort are exempt: they were repaired in the previous phase with their own
     * priceFromPlanOrFail(), which ADDITIONALLY refuses a return whose claimed amount disagrees
     * -- safe there because this server builds their success_url, so the units are known. Those
     * two are proven by seven tests in PlanPaymentBypassTest and were deliberately left alone
     * rather than refactored during a closure cycle.
     */
    public function test_every_newly_repaired_handler_uses_the_one_shared_pricing_rule(): void
    {
        $exempt = ['ESewaPaymentController', 'SofortController'];

        // SenangPay keeps its session price (see the census above), so it does not price through
        // the helper -- but it must still carry the guard, which PlanPaymentPostureTest asserts.
        $exempt[] = 'SenangPayController';

        // SSLCommerz writes NO price at all: planGetSSLCommerzStatus UPDATES an existing
        // PlanOrder (found by tran_id) and flips its payment_status. There is nothing for a
        // pricing rule to own. That is also precisely why it was invisible to the old census,
        // which required a price assignment. Its guard is asserted by PlanPaymentPostureTest and
        // its refusal by PlanPaymentFailClosedTest.
        $exempt[] = 'SSLCommerzPaymentController';

        foreach (self::REPAIRED as $controller) {
            if (in_array($controller, $exempt, true)) {
                continue;
            }

            $this->assertStringContainsString(
                'serverOwnedPlanPrice(',
                $this->source($controller),
                $controller . ' no longer prices through GuardsPlanActivation::serverOwnedPlanPrice(). '
                . 'If it now prices from $plan->price inline, that is not wrong, but the coupon '
                . 'rule has been duplicated and will drift -- in particular the deliberate '
                . 'absence of an is_active filter when pricing.'
            );
        }
    }
}
