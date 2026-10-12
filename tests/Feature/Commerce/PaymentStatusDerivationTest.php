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
        foreach (PaymentVerificationService::OFFLINE_PAYMENT_TYPES as $type) {
            $this->assertSame(
                PaymentVerificationService::STATUS_PAID,
                $this->verifier->resolveOrderPaymentStatus($type, null, '1250.50', self::class),
                $type . ' settles off-platform and must not change status here'
            );
        }

        $this->assertSame(
            PaymentVerificationService::STATUS_PAID,
            $this->verifier->resolveOrderPaymentStatus('stripe', null, '1250.50', self::class)
        );
    }

    /** Paymera is the only enforced type today, and the list is explicit. */
    public function test_paymera_is_the_enforced_gateway(): void
    {
        $this->assertSame(['paymera'], PaymentVerificationService::ENFORCED_PAYMENT_TYPES);
    }
}
