<?php

namespace Tests\Feature\Commerce;

use App\Services\PaymentVerificationResult;
use App\Services\PaymentVerificationService;
use Tests\TestCase;

/**
 * The Paymera callback must verify what it is being told.
 *
 * Before this change `handlePaymeraCallback` tested only
 * `($statusData['status'] ?? null) != 'A'`, never compared a captured amount
 * against the order total, and performed NO inbound signature verification —
 * then set payment_status to 'Paid' and created the order. So a caller who
 * could reach the callback URL could have an order created and marked paid.
 *
 * GATE G(4) — WHAT IS AND IS NOT COVERED HERE.
 * The control flow, the fail-closed posture and the exact-decimal amount
 * comparison are implemented and asserted below. The RESPONSE FIELD NAMES are
 * owner-supplied and exist only in Paymera's own integration document, which
 * was not and must not be obtained by contacting the gateway. The signature
 * cases are therefore written against CONFIGURED field names — the scheme is
 * exercised end to end with a test secret — and the single unknown is which
 * real field name and algorithm to configure in production. That unknown is
 * reported as an owner action, NOT as a passing test and NOT as deferred.
 *
 * @see \App\Services\PaymentVerificationService::verifyPaymeraCapture()
 * @see \App\Services\PaymentVerificationService::verifyPaymeraSignature()
 */
class PaymeraCallbackVerificationTest extends TestCase
{
    private PaymentVerificationService $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new PaymentVerificationService();
    }

    /** THE CORE CASE: a mismatched captured amount does not mark an order paid. */
    public function test_callback_with_mismatched_amount_does_not_mark_order_paid(): void
    {
        $result = $this->verifier->verifyPaymeraCapture(
            ['status' => 'A', 'amount' => '1.00'],
            '1250.50',
            125050
        );

        $this->assertSame(PaymentVerificationResult::REJECTED, $result->outcome);
        $this->assertFalse($result->allowsPaid());

        // And a rejected verification cannot become a Paid order.
        $this->verifier->recordVerification('paymera', $result, '1250.50');

        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus(
                'paymera',
                $this->verifier->consumeVerification(),
                '1250.50',
                self::class
            )
        );
    }

    /** A capture equal to the order total verifies. */
    public function test_a_matching_capture_verifies(): void
    {
        $this->assertTrue(
            $this->verifier->verifyPaymeraCapture(
                ['status' => 'A', 'amount' => '1250.50'],
                '1250.50',
                125050
            )->allowsPaid()
        );
    }

    /**
     * The amount UNIT is unresolved (GATE G(3)): processPaymera strips '.' from
     * the total, so 1250.50 is sent as 125050 while 1250 is sent as 1250. A
     * capture equal to the exact integer that was sent is therefore also
     * accepted — and nothing else is. The unit itself is not guessed.
     */
    public function test_a_capture_equal_to_the_integer_actually_sent_verifies(): void
    {
        $this->assertTrue(
            $this->verifier->verifyPaymeraCapture(
                ['status' => 'A', 'amount' => 125050],
                '1250.50',
                125050
            )->allowsPaid()
        );

        // But not an arbitrary integer.
        $this->assertFalse(
            $this->verifier->verifyPaymeraCapture(
                ['status' => 'A', 'amount' => 125051],
                '1250.50',
                125050
            )->allowsPaid()
        );
    }

    /** A missing or unparseable amount is FAILURE, never success. */
    public function test_an_unreadable_capture_amount_fails_closed(): void
    {
        foreach ([['status' => 'A'], ['status' => 'A', 'amount' => 'N/A'], ['status' => 'A', 'amount' => null]] as $payload) {
            $result = $this->verifier->verifyPaymeraCapture($payload, '1250.50', 125050);

            $this->assertSame(PaymentVerificationResult::UNVERIFIABLE, $result->outcome);
            $this->assertFalse($result->allowsPaid());
        }
    }

    /** The owner-supplied field name, once configured, is the only one read. */
    public function test_the_configured_amount_field_is_authoritative(): void
    {
        config(['services.paymera.callback_amount_field' => 'capturedTotal']);

        $this->assertTrue(
            $this->verifier->verifyPaymeraCapture(
                ['amount' => '1.00', 'capturedTotal' => '1250.50'],
                '1250.50',
                null
            )->allowsPaid()
        );

        $this->assertSame(
            PaymentVerificationResult::UNVERIFIABLE,
            $this->verifier->verifyPaymeraCapture(
                ['amount' => '1250.50'],
                '1250.50',
                null
            )->outcome,
            'with a field name configured, a payload lacking it is unverifiable'
        );
    }

    /**
     * BLOCKED LIMB, ASSERTED AS FAR AS IT CAN BE. With no signature scheme
     * configured the callback cannot verify a signature, and that is treated as
     * failure. This is the state the system is in until GATE G(4) is answered.
     */
    public function test_callback_without_a_valid_signature_is_rejected(): void
    {
        config([
            'services.paymera.callback_signature_field' => null,
            'services.paymera.callback_signature_algorithm' => null,
            'services.paymera.callback_signature_secret' => null,
            'services.paymera.callback_signature_payload' => null,
        ]);

        $result = $this->verifier->verifyPaymeraSignature(['sig' => 'anything']);

        $this->assertSame(PaymentVerificationResult::UNVERIFIABLE, $result->outcome);
        $this->assertFalse($result->allowsPaid());
        $this->assertSame('paymera_signature_scheme_not_configured', $result->reason);

        // And it blocks by default, rather than waving the callback through.
        $this->assertTrue($this->verifier->paymeraSignatureIsRequired());
    }

    /**
     * The scheme itself works once configured: a correct HMAC verifies and a
     * tampered one does not. Only the real field name, algorithm and secret
     * delivery are outstanding.
     */
    public function test_the_signature_scheme_verifies_and_rejects_correctly(): void
    {
        $this->configureSignatureScheme();

        $payload = ['paymentId' => 'p-1', 'amount' => '1250.50'];
        $valid = hash_hmac('sha256', 'p-1|1250.50', $this->testSecret());

        $this->assertTrue(
            $this->verifier->verifyPaymeraSignature($payload + ['sig' => $valid])->allowsPaid()
        );

        $this->assertSame(
            PaymentVerificationResult::REJECTED,
            $this->verifier->verifyPaymeraSignature($payload + ['sig' => 'deadbeef'])->outcome
        );

        $this->assertSame(
            PaymentVerificationResult::REJECTED,
            $this->verifier->verifyPaymeraSignature($payload)->outcome,
            'an absent signature is a rejection'
        );
    }

    /** A signature over different values does not cover a tampered payload. */
    public function test_a_signature_does_not_cover_a_tampered_amount(): void
    {
        $this->configureSignatureScheme();

        $valid = hash_hmac('sha256', 'p-1|1250.50', $this->testSecret());

        $this->assertSame(
            PaymentVerificationResult::REJECTED,
            $this->verifier->verifyPaymeraSignature([
                'paymentId' => 'p-1',
                'amount' => '1.00',
                'sig' => $valid,
            ])->outcome
        );
    }

    /** A signed field missing from the payload is a rejection, not a pass. */
    public function test_an_incomplete_signed_payload_is_rejected(): void
    {
        $this->configureSignatureScheme();

        $valid = hash_hmac('sha256', 'p-1|1250.50', $this->testSecret());

        $this->assertSame(
            PaymentVerificationResult::REJECTED,
            $this->verifier->verifyPaymeraSignature(['paymentId' => 'p-1', 'sig' => $valid])->outcome
        );
    }

    /** An algorithm PHP cannot compute is unverifiable, not acceptable. */
    public function test_an_unsupported_algorithm_fails_closed(): void
    {
        $this->configureSignatureScheme();
        config(['services.paymera.callback_signature_algorithm' => 'not-an-algorithm']);

        $this->assertSame(
            PaymentVerificationResult::UNVERIFIABLE,
            $this->verifier->verifyPaymeraSignature(['sig' => 'x'])->outcome
        );
    }

    /**
     * A test-only secret. This is generated here and is not, and must not be,
     * any real credential; no credential value appears in this repository.
     */
    private function testSecret(): string
    {
        return 'test-only-paymera-signing-key';
    }

    private function configureSignatureScheme(): void
    {
        config([
            'services.paymera.callback_signature_field' => 'sig',
            'services.paymera.callback_signature_algorithm' => 'sha256',
            'services.paymera.callback_signature_secret' => $this->testSecret(),
            'services.paymera.callback_signature_payload' => ['paymentId', 'amount'],
        ]);
    }
}
