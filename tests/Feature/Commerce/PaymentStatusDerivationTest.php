<?php

namespace Tests\Feature\Commerce;

use App\Services\PaymentVerificationResult;
use App\Services\PaymentVerificationService;
use Tests\TestCase;

/**
 * An order's payment_status is DERIVED from a verification result.
 *
 * The defect these cover: three order writers set
 * `$order->payment_status = 'Paid'` as a literal, with nothing between the
 * customer's browser and that assignment having asked a gateway whether any
 * money moved — and the storefront payment-return path is directly reachable.
 *
 * No database is needed; the session and config are enough.
 *
 * @see \App\Services\PaymentVerificationService
 */
class PaymentStatusDerivationTest extends TestCase
{
    private PaymentVerificationService $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new PaymentVerificationService();
    }

    /**
     * An enforced gateway with NO verification record is not paid. This is the
     * forged-return-URL case.
     */
    public function test_enforced_gateway_without_a_verification_is_not_paid(): void
    {
        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus('paymera', null, '1250.50', self::class)
        );
    }

    /** A verification taken for a different gateway does not transfer. */
    public function test_a_verification_for_another_gateway_does_not_count(): void
    {
        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus(
                'paymera',
                ['payment_type' => 'cod', 'expected_amount' => '1250.50'],
                '1250.50',
                self::class
            )
        );
    }

    /**
     * A verification taken for one amount does not authorise an order for a
     * different amount. This is the reconciliation limb of the status check.
     */
    public function test_a_verification_for_another_amount_does_not_count(): void
    {
        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus(
                'paymera',
                ['payment_type' => 'paymera', 'expected_amount' => '1250.50'],
                '9999.00',
                self::class
            )
        );
    }

    /** A matching verification does authorise Paid. */
    public function test_a_matching_verification_yields_paid(): void
    {
        $this->assertSame(
            PaymentVerificationService::STATUS_PAID,
            $this->verifier->resolveOrderPaymentStatus(
                'paymera',
                ['payment_type' => 'paymera', 'expected_amount' => '1250.50'],
                '1250.50',
                self::class
            )
        );
    }

    /**
     * The amount comparison is exact. A float artefact for the order total does
     * not match a verified amount.
     */
    public function test_the_amount_comparison_is_exact(): void
    {
        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus(
                'paymera',
                ['payment_type' => 'paymera', 'expected_amount' => '12341.00'],
                '12341.0000000000018',
                self::class
            )
        );
    }

    /**
     * IDEMPOTENCY. One verified payment authorises exactly ONE order, so a
     * replayed payment-return cannot mint a second paid order.
     */
    public function test_a_verification_record_is_single_use(): void
    {
        $this->verifier->recordVerification(
            'paymera',
            PaymentVerificationResult::verified('ok', '1250.50', '1250.50'),
            '1250.50'
        );

        $first = $this->verifier->consumeVerification();

        $this->assertIsArray($first);
        $this->assertSame('paymera', $first['payment_type']);
        $this->assertNull($this->verifier->consumeVerification(), 'the record must not be replayable');
    }

    /** Only a VERIFIED result is ever recorded. */
    public function test_rejected_and_unverifiable_results_record_nothing(): void
    {
        $this->verifier->recordVerification(
            'paymera',
            PaymentVerificationResult::rejected('amount_mismatch'),
            '1250.50'
        );
        $this->assertNull($this->verifier->consumeVerification());

        $this->verifier->recordVerification(
            'paymera',
            PaymentVerificationResult::unverifiable('not_configured'),
            '1250.50'
        );
        $this->assertNull($this->verifier->consumeVerification());
    }

    /**
     * The record lives in the SESSION, not in the request, so a client cannot
     * post itself a verification.
     */
    public function test_the_verification_record_is_server_side(): void
    {
        $this->verifier->recordVerification(
            'paymera',
            PaymentVerificationResult::verified('ok', '1', '1'),
            '1'
        );

        $this->assertTrue(session()->has(PaymentVerificationService::SESSION_KEY));
    }

    /**
     * BEHAVIOUR PRESERVED, DELIBERATELY. Off-platform settlement and the
     * gateways with no verification seam keep their current status, because
     * recording them all Pending would stop merchant wallets being credited
     * across live payment methods. Both are reported as owner actions; this test
     * pins the current behaviour so the change is never made by accident.
     */
    public function test_offline_and_unseamed_payment_types_keep_their_behaviour(): void
    {
        // COD IS DELIBERATELY NOT IN THIS LIST ANY MORE (owner decision D-06): it has its own state
        // machine and is Pending until collection, so it is not an "offline settles-immediately" type.
        // This assertion is what caught the constant still listing it -- the COD branch runs first in
        // resolveOrderPaymentStatus(), so the stale entry changed no behaviour and only misled readers.
        $this->assertNotContains('cod', PaymentVerificationService::OFFLINE_PAYMENT_TYPES,
            'COD must not be listed as an offline type that records Paid at once');

        foreach (PaymentVerificationService::OFFLINE_PAYMENT_TYPES as $type) {
            $this->assertSame(
                PaymentVerificationService::STATUS_PAID,
                $this->verifier->resolveOrderPaymentStatus($type, null, '1250.50', self::class),
                $type . ' settles off-platform and must not change status here'
            );
        }

        /*
         * REVERSED BY OWNER DECISION, Phase 01 final execution directive: "Any unverified gateway
         * payment must remain Pending, never Paid."
         *
         * This assertion used to read STATUS_PAID, and the docblock above still records why: an
         * unseamed gateway kept its Paid status because recording 51 live methods Pending would
         * stop merchant wallets being credited, which is a business change and not an engineering
         * one. The owner has now made that change, so the expectation inverts. The rest of this
         * test is unchanged, and the off-platform loop above is what keeps the decision from
         * over-reaching into types no gateway ever confirms.
         */
        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus('stripe', null, '1250.50', self::class),
            'stripe with no verification record must be PENDING. An unverified gateway payment is '
            . 'never recorded Paid.'
        );
    }

    /**
     * The amount-enforced types, and the list is explicit.
     *
     * 'nmi' JOINED paymera under the same decision: its arm makes the capture itself and records
     * the provider's own `response == 1` through recordVerification(), so its captured amount can
     * be reconciled against the order total rather than merely existing. Lowercase because
     * resolveOrderPaymentStatus() compares against `strtolower(trim($paymentType))` with a strict
     * in_array -- an entry spelled 'NMI' would be silently inert.
     */
    public function test_the_amount_enforced_gateways_are_explicit(): void
    {
        $this->assertSame(['paymera', 'nmi'], PaymentVerificationService::ENFORCED_PAYMENT_TYPES);
    }

    /**
     * THE POS AND LOTTERY DECISION, which the brief called for explicitly.
     *
     * Two of the nine remaining literal `payment_status = 'Paid'` writes are
     * NOT gateway returns, and were carried forward as needing a decision
     * rather than a fix:
     *
     *   - `app/Http/Controllers/PosController.php:244`, whose line above it
     *     sets `payment_type = __('POS')` (:243). A point-of-sale sale is cash
     *     taken across a counter before the sale is rung up. No gateway is
     *     involved, so there is no capture to verify and no
     *     PaymentVerificationResult that could exist.
     *
     *   - `packages/workdo/LotteryPrizes/src/app/Http/Controllers/
     *     LotteryResultController.php:136`, inside an order whose
     *     `payment_type` is `'Lottery Product'` (:134) and whose
     *     `product_order_status` is `'lottery_order'` (:137). That order is a
     *     PRIZE AWARD created by the draw, not a purchase -- the ticket was
     *     paid for earlier, through a different flow. Nothing is outstanding.
     *
     * THE DECISION: neither is wired, and this test is the evidence for why
     * rather than an assertion that it is fine.
     *
     *   1. SUBSTANTIVELY, wiring them changes nothing. Measured below: for both
     *      payment types the service already returns exactly 'Paid', because
     *      neither is in ENFORCED_PAYMENT_TYPES. Wiring would add a log line
     *      and no behaviour.
     *   2. STRUCTURALLY, neither file may be edited from here. PosController is
     *      not in this agent's ownership, and LotteryResultController is under
     *      `packages/workdo/`, which `.github/scripts/guard-no-workdo-changes.sh`
     *      fails the build for touching -- vendored commercial module code that
     *      the owner's preserve-first instruction puts off limits.
     *
     * WHAT THIS TEST IS FOR: it makes the decision falsifiable. The equivalence
     * holds only while these two types stay unenforced. The moment either is
     * added to ENFORCED_PAYMENT_TYPES, these assertions go red and name the two
     * sites that then genuinely diverge -- which is the point at which the
     * wiring stops being a no-op and becomes an owner decision about editing a
     * vendored module.
     */
    public function test_the_pos_and_lottery_literals_are_equivalent_to_the_derivation_today(): void
    {
        foreach (['POS', 'Lottery Product'] as $type) {
            $this->assertNotContains(
                strtolower($type),
                PaymentVerificationService::ENFORCED_PAYMENT_TYPES,
                $type . ' has become an enforced type, so its literal Paid write is no '
                    . 'longer equivalent to the derivation. Read this test\'s docblock: the '
                    . 'two sites it names now need wiring, and one of them is vendored '
                    . 'module code, so that is an owner decision.'
            );

            $this->assertSame(
                PaymentVerificationService::STATUS_PAID,
                $this->verifier->resolveOrderPaymentStatus($type, null, '1250.50', self::class),
                $type . ' is settled off-gateway, so the derivation must agree with the '
                    . 'literal the unwired site writes'
            );
        }
    }

    /**
     * The contrast that stops the test above being vacuous.
     *
     * If the service returned 'Paid' for everything, the equivalence proved
     * above would be worthless. It does not: an ENFORCED type with no
     * verification record derives to 'Pending', so the agreement measured above
     * is a property of the unenforced set and not of the service.
     */
    public function test_the_derivation_does_diverge_for_an_enforced_type(): void
    {
        $this->assertNotSame(
            PaymentVerificationService::STATUS_PAID,
            $this->verifier->resolveOrderPaymentStatus('paymera', null, '1250.50', self::class),
            'an enforced gateway with no verification record must not derive to Paid, '
                . 'or every equivalence measured in this file is vacuous'
        );
    }

    // -----------------------------------------------------------------
    // THE FOUR ORDER WRITERS. The literal must be gone from all of them.
    // -----------------------------------------------------------------

    /**
     * ApiController's order-save path derives the status too.
     *
     * POST /api/{slug}/order-save still carried
     * `$order->payment_status = 'Paid'` at ApiController:7133 after the first
     * round reported the payment-verification finding FIXED, which is why that
     * claim was too broad. It is an order-creating path, so the literal is gone
     * and the seam is used instead.
     *
     * Asserted by INSTANTIATING the controller and driving the accessor the
     * order writer calls, not by linting: a private accessor that does not
     * resolve would pass php -l and fatal on every order.
     */
    public function test_the_api_order_save_path_has_a_working_verifier_seam(): void
    {
        $controller = new \App\Http\Controllers\Api\ApiController();

        $accessor = new \ReflectionMethod(
            \App\Http\Controllers\Api\ApiController::class,
            'paymentVerifier'
        );

        $verifier = $accessor->invoke($controller);

        $this->assertInstanceOf(PaymentVerificationService::class, $verifier);

        // The same instance each call, so consumeVerification() is single-use
        // across one request rather than being reset by a second accessor call.
        $this->assertSame($verifier, $accessor->invoke($controller));

        // And it behaves: an enforced gateway with no verification is Pending.
        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $verifier->resolveOrderPaymentStatus('paymera', null, '1250.50', __METHOD__)
        );
    }

    /**
     * No literal `payment_status = 'Paid'` survives in either file this agent
     * owns that writes an order.
     *
     * Structural, and labelled so. It is the regression this round existed to
     * catch: the first round's claim was made on the strength of having fixed
     * three of four sites, and nothing asserted the fourth.
     */
    public function test_no_order_writer_in_these_files_asserts_paid_as_a_literal(): void
    {
        foreach ([
            'app/Http/Controllers/Api/ApiController.php',
            'app/Http/Controllers/PaymentController.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));

            // Strip comments, so the explanatory notes that QUOTE the old line
            // are not mistaken for the line itself.
            $stripped = '';

            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    // A single-character token, e.g. ';' or '='.
                    $stripped .= $token;
                    continue;
                }

                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $stripped .= $token[1];
            }

            $this->assertDoesNotMatchRegularExpression(
                '/payment_status\s*=\s*[\'"]Paid[\'"]/',
                $stripped,
                $file . ' must derive payment_status, never assert it'
            );
        }
    }
}
