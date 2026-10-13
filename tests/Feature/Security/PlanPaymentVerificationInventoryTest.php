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
 * closed twelve free-activation paths. Worse, the census helper traced one hop from the
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
     * eSewa and Sofort by PlanPaymentBypassTest, the other twelve by PlanPaymentFailClosedTest.
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
        ];
    }

    private function source(string $controller): string
    {
        $path = app_path('Http/Controllers/' . $controller . '.php');
        $this->assertFileExists($path, $controller . ' has moved or been deleted; update this inventory.');

        return (string) file_get_contents($path);
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
            . '). Losing it would put this handler into the same class as the twelve that had to '
            . 'be disabled -- and unlike those, this one is NOT on the config/payments.php map, '
            . 'so nothing else is standing in the way.'
        );
    }

    /**
     * THE CENSUS, INVERTED: NOTHING MAY PRICE A PLAN PURCHASE FROM THE REQUEST.
     *
     * The scan is deliberately WIDER than the real defect and the exceptions are NAMED, because
     * a wide net with named exceptions misses less than a narrow net -- the previous version's
     * one-hop tracer was narrowed by accident and went blind to a twelve-handler repair.
     */
    public function test_no_plan_purchase_writer_records_a_request_sourced_price(): void
    {
        $writers = [];
        $flagged = [];

        foreach ((glob(app_path('Http/Controllers') . '/*.php') ?: []) as $file) {
            $src = (string) file_get_contents($file);

            if (! str_contains($src, 'assignPlan(') || ! preg_match('/\$order->price\s*=/', $src)) {
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
            . "\n\nThat is the pricing half of the free-activation family that cost twelve "
            . "handlers this phase. Price it from \$plan->price plus a server-validated coupon "
            . "-- GuardsPlanActivation::serverOwnedPlanPrice() does exactly that -- and then ask "
            . "the harder question: before assignPlan(), does this handler check anything the "
            . "caller cannot forge? If not, it also needs an entry on the "
            . "config/payments.php map and a case in PlanPaymentFailClosedTest."
        );

        // Guard the floor. If this collapses, the scan has broken rather than the problem having
        // gone away. 42 plan-purchase writers were measured when this inventory was retaken.
        $this->assertGreaterThanOrEqual(
            35,
            count($writers),
            'Only ' . count($writers) . ' plan-purchase writers were found; 42 were measured '
            . 'when this inventory was retaken, so the scan has probably broken and this file '
            . 'is now asserting nothing.'
        );
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
        if (! preg_match_all('/\$order->price\s*=\s*([^;]+);/s', $src, $m)) {
            return null;
        }

        foreach ($m[1] as $expr) {
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
