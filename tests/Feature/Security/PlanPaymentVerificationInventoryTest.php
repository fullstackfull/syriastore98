<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AN INVENTORY RATCHET OVER THE PLAN-PURCHASE RETURN HANDLERS.
 *
 * WHY THIS EXISTS. Two of these handlers were repaired this phase
 * (ESewaPaymentController::success and SofortController::success): each took the plan AND the
 * price from the query string, hardcoded a success status and called assignPlan(), so one
 * authenticated GET activated any paid plan for zero. An audit of all 55 assignPlan() callers
 * then found EIGHT MORE with the same shape. They are NOT repaired, and this file is why that
 * is a deliberate decision rather than an oversight.
 *
 * WHY THE EIGHT WERE NOT PATCHED. Their return parameters are POSTED BY THE PROVIDER, and the
 * field names and units differ per gateway (`get_amount`, `amount`, `response.Result`; major
 * units in some, minor units in others). No payment provider is reachable from this
 * environment, so a reject-on-mismatch guard applied blind could refuse every GENUINE payment
 * on eight gateways at once. eSewa and Sofort were safe to fix precisely because THIS codebase
 * builds their success_url, so the parameter names and units are server-generated and known.
 * Repairing the rest needs each provider's verification contract, which is the standing
 * BLOCKED -- EXTERNAL DEPENDENCY finding on payment providers.
 *
 * WHAT THIS TEST DOES. It pins the inventory from both sides. If a new gateway lands with the
 * same shape, the census test goes red. If one of the eight is repaired, its own case goes red
 * and the fixer must move it into the repaired list -- so a fix cannot be silent either. And
 * the three handlers that DO verify server-side are pinned too, so a regression that removes
 * verification is caught.
 *
 * CLASSIFICATION WAS HAND-VERIFIED, and two classifier errors were corrected in the process:
 * TapPaymnetController first looked ungated because its gate reads `->status != "CAPTURED"`,
 * and BenefitPaymentController looked ungated until its outbound Guzzle call was read. Pattern
 * matching alone got this wrong twice, which is the reason the lists below are explicit names
 * rather than a regex verdict computed at runtime.
 */
class PlanPaymentVerificationInventoryTest extends TestCase
{
    /** Handlers repaired this phase. Their guards are proven by PlanPaymentBypassTest. */
    private const REPAIRED = [
        'ESewaPaymentController',
        'SofortController',
    ];

    /**
     * Handlers that record a plan purchase on request-supplied data with no verification the
     * caller cannot forge. [file, the exact request-sourced price assignment, what gates it].
     */
    public static function unverifiedHandlers(): array
    {
        return [
            'Nepalste: no gate whatsoever' => [
                'NepalstePaymnetController', '$order->price = $getAmount;', 'none',
            ],
            'PayHere: no gate; its md5 is outbound-only' => [
                'PayHerePaymnetController', '$order->price = $getAmount;', 'none',
            ],
            'PhonePe: no gate' => [
                'PhonePePaymentController', '$order->price = $getAmount;', 'none',
            ],
            'Paddle: no gate' => [
                'PaddlePaymentController', '$order->price = $getAmount;', 'none',
            ],
            'FedPay: gate reads $request->status' => [
                'FedPayPaymentController', '$order->price = $getAmount;', "\$request->status == 'approved'",
            ],
            'Easebuzz: gate reads $request->error' => [
                'EasebuzzController', '$order->price          = $price == null ? 0 : $price;', "\$request->error ==",
            ],
            'DPOPay: gate reads $request[response][Result]' => [
                'DPOPayController', '$order->price = $getAmount;', "\$request['response']['Result']",
            ],
            'Yookassa: gate is a session value, client built but never called' => [
                'YookassaController', '$order->price = $getAmount;', "\\Session::get('payment_id')",
            ],

            // A DISTINCT SUB-CLASS, and the reason it still belongs here. SenangPay takes the
            // plan AND the price from server-side session state, so neither can be forged and
            // the recorded amount is always right. But the ONLY thing standing between a
            // caller and an activated plan is `$request->status_id == 1`, which the caller
            // supplies. A merchant who starts a SenangPay checkout (populating the session),
            // abandons the payment and then hits the callback with status_id=1 gets the plan
            // at the correct price without paying. Price integrity intact, payment
            // verification absent.
            'SenangPay: session-priced, but gated only on $request->status_id' => [
                'SenangPayController',
                "\$order->price = !empty(\$senangPaySession['price']) ? \$senangPaySession['price'] : 0;",
                '$request->status_id == 1',
            ],
        ];
    }

    /**
     * Handlers that DO verify with the provider before activating, so a forged return cannot
     * activate a plan. Each still records a REQUEST-SUPPLIED price, which is a lesser
     * data-integrity problem (the plan_orders row can carry a wrong amount) and not a free
     * activation. Pinned so that losing the verification would be caught.
     *
     * Three of these were initially mis-flagged as unverified by a scan for an outbound HTTP
     * client, because they verify through a helper or an SDK rather than a raw client:
     * Paynow via $this->paynowgetstatus(), Paytm via $transaction->isSuccessful(), Xendit via
     * an invoice lookup. That is the second time pattern matching got this classification
     * wrong, which is why these lists are explicit.
     */
    public static function verifiedHandlers(): array
    {
        return [
            'Tap: provider charge status'      => ['TapPaymnetController', 'CAPTURED'],
            'Cashfree: provider order status'  => ['CashfreeController', 'payment_status == "SUCCESS"'],
            'Benefit: outbound charges lookup' => ['BenefitPaymentController', "\$data->gateway->response->code"],
            'Paynow: provider status helper'   => ['PaynowController', 'paynowgetstatus('],
            'Paytm: SDK transaction check'     => ['PaytmPaymentController', 'isSuccessful()'],
            'Xendit: invoice status lookup'    => ['XenditPaymentController', "'PAID'"],
        ];
    }

    private function source(string $controller): string
    {
        $path = base_path('app/Http/Controllers/' . $controller . '.php');
        $this->assertFileExists($path, $controller . ' has moved or been deleted; update this inventory.');

        return (string) file_get_contents($path);
    }

    #[DataProvider('unverifiedHandlers')]
    public function test_an_unverified_plan_purchase_handler_is_still_on_the_inventory(
        string $controller,
        string $priceAssignment,
        string $gate
    ): void {
        $src = $this->source($controller);

        $this->assertStringContainsString(
            $priceAssignment,
            $src,
            $controller . " no longer records the plan price from request-supplied data.\n\n"
            . "IF YOU REPAIRED IT: good -- move it from unverifiedHandlers() into REPAIRED and "
            . "add its proof to PlanPaymentBypassTest, the way eSewa and Sofort were done. This "
            . "assertion exists so a repair cannot be silent.\n\n"
            . "Its gate was: " . $gate
        );

        if ($gate !== 'none') {
            $this->assertStringContainsString(
                $gate,
                $src,
                $controller . "'s gate has changed. It used to be " . $gate
                . ', which the caller supplies and can therefore forge. If the new gate is a '
                . 'real provider check, move this entry to verifiedHandlers().'
            );
        }
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
            . '). Losing it would put this handler into the same class as the eight unverified '
            . 'ones.'
        );
    }

    /**
     * THE CENSUS: every plan-purchase writer that records a REQUEST-SUPPLIED price must be
     * classified by this file. A new gateway with that shape must not slip in unnoticed.
     *
     * The scope is deliberately the request-sourced ones. A handler that records
     * $plan->price is not part of this problem at all, and there are many of those -- 42
     * plan-purchase writers were measured in total, of which 19 recorded a request-supplied
     * price. Flagging all 42 would make this test noise.
     */
    public function test_every_plan_purchase_writer_with_a_request_sourced_price_is_classified(): void
    {
        $writers = [];

        foreach ((glob(base_path('app/Http/Controllers') . '/*.php') ?: []) as $file) {
            $src = (string) file_get_contents($file);

            if (! str_contains($src, 'assignPlan(') || ! preg_match('/\$order->price\s*=/', $src)) {
                continue;
            }

            if ($this->priceIsRequestSourced($src)) {
                $writers[] = basename($file, '.php');
            }
        }

        sort($writers);

        // NAMED, NOT SILENTLY COUNTED AS SAFE. These record a request-supplied price and carry
        // some gate, but were not hand-verified, so this inventory makes no claim about them.
        // They are the honest residue of the audit and the next place to look.
        $notHandVerified = [
            'PaytrController',
            'PaiementProPaymentController',
            'CinetPayController',
            'OzowController',
            'PowertranzPaymentController',
        ];

        // HAND-VERIFIED SAFE, and kept as an explicit exclusion rather than by loosening the
        // detector -- a wider net with named exceptions misses less than a narrow net.
        //
        // Both are false positives of file-scoped one-hop analysis: the detector sees EVERY
        // assignment to the price variable anywhere in the file, including ones belonging to
        // a different method.
        //   BraintreeController   the plan path reads $session from
        //                         $request->session()->get($request->order_id) at :112 and
        //                         prices from $session->amount at :143. The
        //                         `$session = $request->toArray()` at :67 is the STORE
        //                         payment path, not the plan path.
        //   MyFatoorahController  prices from $MyFatoorahSession['price'] (:160), read from
        //                         the session at :117, and it also verifies through
        //                         MyFatoorahPaymentStatus::getPaymentStatus() at :143.
        $detectorFalsePositives = ['BraintreeController', 'MyFatoorahController'];

        $known = array_merge(
            self::REPAIRED,
            $detectorFalsePositives,
            array_map(fn ($row) => $row[0], array_values(self::unverifiedHandlers())),
            array_map(fn ($row) => $row[0], array_values(self::verifiedHandlers())),
            $notHandVerified
        );

        $unclassified = array_values(array_diff($writers, $known));

        $this->assertSame(
            [],
            $unclassified,
            "A plan-purchase writer records a REQUEST-SUPPLIED price and is not classified:\n  "
            . implode("\n  ", $unclassified)
            . "\n\nClassify it before merging. The question is single: before assignPlan(), does "
            . 'it check anything the caller cannot forge? If not, it is a P0 of the same family '
            . 'as ESewaPaymentController::success and belongs in unverifiedHandlers().'
        );

        // Guard the floor: if this collapses, the scan has broken rather than the problem
        // having gone away. 19 were measured when the inventory was taken.
        $this->assertGreaterThanOrEqual(
            14,
            count($writers),
            'Only ' . count($writers) . ' request-sourced plan-purchase writers were found; 19 '
            . 'were measured when this inventory was taken, so the scan has probably broken.'
        );
    }

    /**
     * Is $order->price assigned from request data, directly or through one hop?
     *
     * One hop is enough in this codebase: every instance found was either
     * `$order->price = $request->x` or `$var = $request->x; ... $order->price = $var`
     * (the variable is almost always $getAmount). Deeper chains would need real dataflow
     * analysis, which is why the hand-verified lists above are the authority and this is only
     * the net that decides what MUST be hand-verified.
     */
    private function priceIsRequestSourced(string $src): bool
    {
        if (! preg_match_all('/\$order->price\s*=\s*([^;]+);/', $src, $m)) {
            return false;
        }

        foreach ($m[1] as $expr) {
            if (str_contains($expr, '$request->') || str_contains($expr, '$request[')) {
                return true;
            }

            // The expression names the plan's own price, so it is plan-sourced whatever else
            // appears in it. BankTransferController is
            // `isset($coupons) ? $plan->discounted_price : $plan->price`; without this the
            // first variable matched is $coupons, which IS assigned from a PlanCoupon lookup
            // keyed on $request->coupon, and the handler is misread as request-priced.
            if (str_contains($expr, '$plan->')) {
                continue;
            }

            if (! preg_match('/\$([A-Za-z_]\w*)/', $expr, $vm)) {
                continue;
            }

            if (preg_match_all('/\$' . $vm[1] . '\s*=\s*([^;]+);/', $src, $am)) {
                foreach ($am[1] as $assign) {
                    // Server-side state, not caller input. $request->session() reads the
                    // session THROUGH the request object, so a bare '$request->' test misreads
                    // it as caller input.
                    if (str_contains($assign, 'session()') || str_contains($assign, 'Session::get')) {
                        continue;
                    }

                    if (str_contains($assign, '$request->') || str_contains($assign, '$request[')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
