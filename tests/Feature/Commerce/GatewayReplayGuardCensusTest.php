<?php

namespace Tests\Feature\Commerce;

use Tests\TestCase;

/**
 * E14 (ledger row P2-04-17), THE PAYMENT HALF — the census the row's acceptance needs and did not
 * have.
 *
 * E14's rule is "no duplication of payments or inventory movements". The STOCK half is closed and
 * executed elsewhere (RefundStockIdempotencyTest, GatewayStockDecrementRoutingTest — 16 cases).
 * The PAYMENT half had no file and no code, and an independent audit of the Phase 02 residuals
 * said so. This is that file.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IT MEASURES, AND WHY A CENSUS RATHER THAN A FIX.
 *
 * `GuardsGatewayReturn` is this codebase's replay guard: it takes a `Cache::add()` claim keyed on
 * the checkout nonce and the gateway's own transaction reference, so a second arrival of the same
 * gateway return is refused instead of writing a second order. Measured here: **8 of the 27
 * first-party controllers that write an Order carry it.**
 *
 * Nineteen gateway controllers do not. That is the F46/F50 BYPASS SHAPE, which this repository has
 * already been bitten by twice and which the row's own acceptance exists to prevent: *fix the file
 * somebody read, and leave the control bypassable by choosing another payment method.* A merchant
 * whose store is configured for Stripe, Razorpay, Paytabs, Mollie, Paystack, Midtrans, Flutterwave,
 * Cashfree, Coingate, Benefit, Aamarpay, Mercado, Yookassa, Skrill, SSLCommerz, Paytr, Paytm,
 * CyberSource or PaymentWall has no replay guard on its gateway return at all.
 *
 * **IT IS A CENSUS AND NOT A FIX, DELIBERATELY.** Wiring nineteen gateway controllers is a material
 * change to the payment path, on nineteen integrations whose returns cannot be exercised from this
 * container — there are no gateway credentials here and no sandbox for any of them. The owner's
 * standing rule reserves "material changes to prices, payments, stock or business rules" to
 * themselves, and a nineteen-file change to money handling that nothing here can drive is exactly
 * what that rule is for. So this makes the gap MEASURED, NAMED AND UNFORGETTABLE instead of
 * shipping nineteen unverified edits: the three lists below are ratcheted in both directions, so a
 * new gateway controller that writes an Order lands in none of them and FAILS, and wiring one means
 * moving it between lists on purpose.
 *
 * `GatewayReference` (F216 / D-29.3) is the durable half of the same guarantee and is already
 * built, with `UNIQUE (store_id, gateway, reference)` proven on MariaDB under concurrency. It is
 * not wired into these nineteen either, and for the same reason — its table is dev/test-only until
 * the owner authorises the migration.
 */
class GatewayReplayGuardCensusTest extends TestCase
{
    /**
     * Controllers that write an Order on a GATEWAY RETURN and carry `GuardsGatewayReturn`.
     * This is the set the replay guard actually protects.
     */
    private const GUARDED = [
        'AuthorizeNetPaymnetController',
        'CinetPayController',
        'EasebuzzController',
        'KhaltiPaymnetController',
        'NMIPayController',
        'PaiementProPaymentController',
        'PayUPaymentController',
        'PaymentController',
    ];

    /**
     * Controllers that write an Order on a GATEWAY RETURN and carry NO replay guard.
     *
     * THIS LIST IS THE FINDING. Each one is a path where the same gateway return arriving twice —
     * a retried webhook, a refreshed success page, a replayed callback URL — writes a second order
     * against one capture.
     */
    private const UNGUARDED_GATEWAY_RETURN = [
        'AamarpayController',
        'BenefitPaymentController',
        'CashfreeController',
        'CoingateController',
        'CyberSourceController',
        'FlutterwaveController',
        'MercadoPaymentController',
        'MidtransController',
        'MolliePaymentController',
        'PaymentWallPaymentController',
        'PaystackPaymentController',
        'PaytabsController',
        'PaytmPaymentController',
        'PaytrController',
        'RazorpayPaymentController',
        'SSLCommerzPaymentController',
        'SkrillPaymentController',
        'StripePaymentController',
        'YookassaController',
    ];

    /**
     * Controllers that write an Order but NOT on a gateway return, so a gateway-return replay guard
     * is not the right control for them. Each carries the reason, read off the writing method
     * rather than inferred from the file name.
     */
    private const NOT_A_GATEWAY_RETURN = [
        'OrderController' => 'The merchant typing an order by hand (`store`). The caller is an '
            . 'authenticated merchant on a CSRF-protected form, not a gateway posting a callback, '
            . 'so there is no third-party arrival to replay. Its own duplication control is the '
            . 'merchant seeing the order they just created.',

        'PosController' => 'The till. Same shape as the above — an authenticated merchant at a '
            . 'point of sale, with no gateway return involved.',

        'PlanRequestController' => '`acceptRequest`: the platform operator accepting a merchant\'s '
            . 'SaaS plan request. An operator action, and a PLAN purchase rather than a customer '
            . 'order — the distinction F147 was found on, when a SaaS plan purchase had been '
            . 'miscounted as one of the sixteen order writers.',

        'StoreController' => '`upgradePlan` / `activePlan`: SaaS plan purchases, again operator or '
            . 'merchant initiated rather than a gateway callback.',
    ];

    /** @return string[] every first-party controller that writes an Order */
    private function orderWritingControllers(): array
    {
        $found = [];

        foreach (glob(base_path('app/Http/Controllers/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            // The same two forms the F147/F148 writer census settled on, which found five
            // controllers a narrower grep had missed.
            if (preg_match('/new\s+(\\\\App\\\\Models\\\\)?Order\s*\(|Order::create\s*\(/', $source)) {
                $found[] = basename($file, '.php');
            }
        }

        sort($found);

        return $found;
    }

    private function carriesTheGuard(string $controller): bool
    {
        $source = (string) file_get_contents(
            base_path('app/Http/Controllers/' . $controller . '.php')
        );

        return str_contains($source, 'GuardsGatewayReturn');
    }

    public function test_every_order_writing_controller_is_classified(): void
    {
        $classified = array_merge(
            self::GUARDED,
            self::UNGUARDED_GATEWAY_RETURN,
            array_keys(self::NOT_A_GATEWAY_RETURN)
        );

        $unclassified = array_values(array_diff($this->orderWritingControllers(), $classified));

        $this->assertSame(
            [],
            $unclassified,
            "A CONTROLLER WRITES AN ORDER AND THIS CENSUS DOES NOT KNOW ABOUT IT.\n\n"
            . "Decide which of the three lists it belongs in, and say why:\n"
            . "  GUARDED                  — it is a gateway return and it uses GuardsGatewayReturn\n"
            . "  UNGUARDED_GATEWAY_RETURN — it is a gateway return and it does NOT (a finding)\n"
            . "  NOT_A_GATEWAY_RETURN     — it writes an order from a merchant or operator action\n\n"
            . "Do not pick the third one to make this pass. E14's rule is 'no duplication of "
            . "payments', and the shape it exists to stop is a control that holds on the gateway "
            . "somebody checked and not on the one the merchant chose.\n  "
            . implode("\n  ", $unclassified)
        );
    }

    public function test_no_classification_outlives_the_controller_it_names(): void
    {
        $writers = $this->orderWritingControllers();

        foreach (array_merge(
            self::GUARDED,
            self::UNGUARDED_GATEWAY_RETURN,
            array_keys(self::NOT_A_GATEWAY_RETURN)
        ) as $controller) {
            $this->assertContains(
                $controller,
                $writers,
                $controller . ' is classified here but no longer writes an Order. Remove its entry '
                . '— a stale classification is how the next unclassified writer hides.'
            );
        }
    }

    public function test_the_guarded_set_is_exactly_what_it_claims(): void
    {
        foreach (self::GUARDED as $controller) {
            $this->assertTrue(
                $this->carriesTheGuard($controller),
                $controller . ' is listed as guarded and does not use GuardsGatewayReturn. If the '
                . 'guard was removed, say why on the record and move it to the unguarded list — do '
                . 'not quietly reclassify a path that lost its replay protection.'
            );
        }

        foreach (self::UNGUARDED_GATEWAY_RETURN as $controller) {
            $this->assertFalse(
                $this->carriesTheGuard($controller),
                $controller . ' now DOES use GuardsGatewayReturn. That is good news: move it to '
                . 'GUARDED and reduce the unguarded count below, so the remaining gap stays honest.'
            );
        }
    }

    public function test_the_measured_gap_is_eight_guarded_against_nineteen_unguarded(): void
    {
        /*
         * The headline figure, pinned so it cannot drift silently in either direction. If this
         * fails because the unguarded count went DOWN, that is progress and the number should be
         * lowered deliberately. If it went UP, a new gateway was added without a replay guard.
         */
        $this->assertCount(8, self::GUARDED, 'controllers with a gateway-return replay guard');

        $this->assertCount(
            19,
            self::UNGUARDED_GATEWAY_RETURN,
            'E14 PAYMENT HALF, OPEN: nineteen gateway controllers write an Order on a gateway '
            . 'return with no replay guard. Wiring them is a material change to the payment path '
            . 'across nineteen integrations that cannot be exercised from this container, so it is '
            . 'the owner\'s decision rather than a fix to be taken here.'
        );

        $this->assertCount(4, self::NOT_A_GATEWAY_RETURN, 'non-gateway order writers');

        $this->assertCount(
            31,
            $this->orderWritingControllers(),
            'the whole first-party order-writing population'
        );
    }

    public function test_the_guard_itself_still_does_what_the_census_credits_it_with(): void
    {
        /*
         * The census counts a `use` statement. That is worth nothing if the trait stopped taking a
         * claim — so the one thing the whole count rests on is asserted directly.
         */
        $trait = (string) file_get_contents(
            base_path('app/Http/Controllers/Concerns/GuardsGatewayReturn.php')
        );

        $this->assertStringContainsString(
            'Cache::add(',
            $trait,
            'GuardsGatewayReturn no longer takes a claim, so every "guarded" count in this file is '
            . 'crediting a `use` statement with protection it does not provide.'
        );

        $this->assertStringContainsString(
            'already claimed',
            $trait,
            'and it must still refuse a second arrival'
        );
    }

    public function test_the_durable_half_exists_and_is_deliberately_not_wired_here(): void
    {
        /*
         * F216 / D-29.3 built the durable guarantee — `UNIQUE (store_id, gateway, reference)`,
         * proven on MariaDB under concurrency. Recording that it exists AND that it is not wired
         * into these nineteen keeps the two facts from being confused: the mechanism being ready is
         * not the same as the nineteen paths being protected.
         */
        $this->assertFileExists(
            base_path('app/Services/GatewayReference.php'),
            'the durable payment reference must still exist'
        );

        foreach (self::UNGUARDED_GATEWAY_RETURN as $controller) {
            $source = (string) file_get_contents(
                base_path('app/Http/Controllers/' . $controller . '.php')
            );

            $this->assertStringNotContainsString(
                'GatewayReference',
                $source,
                $controller . ' now uses GatewayReference. If the durable guard has been wired in, '
                . 'this census must say so rather than still reporting the path as unprotected.'
            );
        }
    }
}
