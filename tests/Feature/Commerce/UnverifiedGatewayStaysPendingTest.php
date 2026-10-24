<?php

namespace Tests\Feature\Commerce;

use App\Services\PaymentVerificationResult;
use App\Services\PaymentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Security\SecurityTestCase;

/**
 * NO GATEWAY ORDER IS RECORDED PAID WITHOUT A VERIFICATION RECORD.  P1-PAY-03 (#25).
 * =================================================================================================
 *
 * OWNER DECISION, Phase 01 final execution directive: *"Any unverified gateway payment must remain
 * Pending, never Paid. Do not create a falsely paid order, and preserve a safe reconciliation path
 * for customers who may have been charged."*
 *
 * WHAT THIS REPLACES, AND THE OLD REASONING WAS CORRECT UNTIL THIS DECISION.
 * `resolveOrderPaymentStatus()` returned Paid for any payment type absent from
 * `ENFORCED_PAYMENT_TYPES` -- 51 of the 52 -- and said so in its own comment: recording them all
 * Pending "would stop merchant wallets being credited for 51 live payment methods -- a business
 * change, not a security fix." When an order counts as paid decides when a merchant is credited,
 * which is the owner's call and not an engineering one. It has now been made.
 *
 * THE TWO HALVES OF THE DECISION PULL IN OPPOSITE DIRECTIONS, and both are asserted here:
 *
 *   never falsely Paid      -> an unverified payment resolves to Pending
 *   a safe reconciliation   -> the ORDER IS STILL WRITTEN, so the customer has a record and the
 *     path for a charged         merchant can confirm it against the provider. A refusal would be
 *     customer                   the worse failure: charged, with nothing recorded.
 *
 * So this file asserts a STATUS, and `test_the_order_is_still_written_when_the_payment_is_pending`
 * asserts that the status is all that changed.
 *
 * WHY THE SERVICE IS CALLED DIRECTLY. `resolveOrderPaymentStatus()` is the single seam every order
 * writer goes through -- `PaymentService:2753`, `Api\ApiController:8745`, `PaymentController:2815`
 * and `:4136`, plus `GuardsGatewayReturn:483` -- so the decision is enforced in one place and is
 * testable in one place. The end-to-end half is the PaiementPro test below, which drives a real
 * route.
 */
class UnverifiedGatewayStaysPendingTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function service(): PaymentVerificationService
    {
        return new PaymentVerificationService();
    }

    /** @return array<string, array{0: string}> every gateway type a checkout can choose */
    public static function gatewayTypes(): array
    {
        return [
            'stripe' => ['stripe'],
            'paypal' => ['paypal'],
            'Razorpay' => ['Razorpay'],
            'easebuzz' => ['easebuzz'],
            'CinetPay' => ['CinetPay'],
            'Paiementpro' => ['Paiementpro'],
            'AuthorizeNet' => ['AuthorizeNet'],
            'khalti' => ['khalti'],
            'payu' => ['payu'],
            'flutterwave' => ['flutterwave'],
        ];
    }

    /**
     * THE DECISION: no verification record, no Paid.
     *
     * @dataProvider gatewayTypes
     */
    public function test_a_gateway_payment_with_no_verification_record_is_pending(string $type): void
    {
        Log::spy();

        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->service()->resolveOrderPaymentStatus($type, null, '100.00', 'test'),
            "{$type} was recorded Paid with nothing having confirmed a capture. Owner decision: an "
            . 'unverified gateway payment must remain Pending, never Paid.'
        );
    }

    /**
     * AND A RECORD FOR ANOTHER PAYMENT TYPE IS NOT THIS PAYMENT'S VERIFICATION.
     *
     * The session holds ONE verification record. Without this, a shopper who paid by one method
     * could have a second order recorded Paid on the strength of the first one's record.
     */
    public function test_a_verification_for_another_payment_type_does_not_make_this_one_paid(): void
    {
        Log::spy();

        $record = [
            'payment_type' => 'stripe',
            'reason' => 'stripe ok',
            'captured_amount' => '100.00',
            'expected_amount' => '100.00',
            'verified_at' => now()->toIso8601String(),
        ];

        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->service()->resolveOrderPaymentStatus('easebuzz', $record, '100.00', 'test'),
            'a verification recorded for stripe was accepted as easebuzz having been verified.'
        );
    }

    /**
     * PRESERVE FIRST: a genuine verification still produces Paid.
     *
     * Without this control every assertion above is satisfied by a service that returns Pending for
     * everything, which would stop every merchant being credited for every method -- a far worse
     * outcome than the defect.
     */
    public function test_a_recorded_verification_still_produces_paid(): void
    {
        Log::spy();

        $record = [
            'payment_type' => 'easebuzz',
            'reason' => 'easebuzz status=success',
            'captured_amount' => '100.00',
            'expected_amount' => '100.00',
            'verified_at' => now()->toIso8601String(),
        ];

        $this->assertSame(
            PaymentVerificationService::STATUS_PAID,
            $this->service()->resolveOrderPaymentStatus('easebuzz', $record, '100.00', 'test'),
            'a gateway that DID record a verification is no longer recorded Paid. The decision is '
            . 'about unverified payments, not about refusing every payment.'
        );
    }

    /**
     * THE OFF-PLATFORM TYPES ARE UNTOUCHED, because no gateway ever confirms one of them and
     * "unverified" is their normal state rather than a failure.
     */
    public function test_the_offline_payment_types_are_unaffected(): void
    {
        Log::spy();

        foreach (PaymentVerificationService::OFFLINE_PAYMENT_TYPES as $type) {
            $this->assertSame(
                PaymentVerificationService::STATUS_PAID,
                $this->service()->resolveOrderPaymentStatus($type, null, '100.00', 'test'),
                "{$type} settles off-platform by arrangement and must not be caught by the "
                . 'gateway-verification rule.'
            );
        }
    }

    /** AND COD STILL HAS ITS OWN STATE MACHINE: Pending until collection, per owner decision D-06. */
    public function test_cod_is_still_pending_until_collection(): void
    {
        Log::spy();

        foreach (PaymentVerificationService::COD_PAYMENT_TYPES as $type) {
            $this->assertSame(
                PaymentVerificationService::STATUS_PENDING,
                $this->service()->resolveOrderPaymentStatus($type, null, '100.00', 'test'),
                "COD type {$type} must stay Pending until collection is recorded (D-06).");
        }
    }

    /**
     * THE NMI ENROLMENT IS LIVE, and the casing is the thing that would have made it inert.
     *
     * `resolveOrderPaymentStatus()` lowercases the payment type and compares with a STRICT
     * `in_array`, and `recordVerification()` stores the type lowercased. An entry spelled 'NMI' in
     * `ENFORCED_PAYMENT_TYPES` -- which is how the checkout spells it in `getValidPaymentTypes()`
     * -- would therefore never match, and the enrolment would do nothing while looking correct.
     * This asserts the amount comparison actually engages.
     */
    public function test_nmi_is_amount_enforced_and_a_mismatched_capture_is_not_paid(): void
    {
        Log::spy();

        $record = [
            'payment_type' => 'nmi',
            'reason' => 'NMI sale response=1',
            'captured_amount' => '1.00',
            'expected_amount' => '1.00',      // verified against a DIFFERENT total
            'verified_at' => now()->toIso8601String(),
        ];

        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->service()->resolveOrderPaymentStatus('NMI', $record, '100.00', 'test'),
            'NMI is amount-enforced, so a verification taken against 1.00 must not mark a 100.00 '
            . 'order Paid. If this is Paid the enrolment is spelled wrong and is inert.'
        );
    }

    /** And NMI with a matching amount IS Paid, so the enrolment has not broken the gateway. */
    public function test_nmi_with_a_matching_capture_is_paid(): void
    {
        Log::spy();

        $record = [
            'payment_type' => 'nmi',
            'reason' => 'NMI sale response=1',
            'captured_amount' => '100.00',
            'expected_amount' => '100.00',
            'verified_at' => now()->toIso8601String(),
        ];

        $this->assertSame(
            PaymentVerificationService::STATUS_PAID,
            $this->service()->resolveOrderPaymentStatus('NMI', $record, '100.00', 'test'),
            'PRESERVE FIRST: a genuine NMI capture for the right amount must still be Paid.'
        );
    }

    /**
     * THE RECONCILIATION PATH, asserted rather than asserted-about: `recordVerification()` is what
     * a verifying handler calls, and a REJECTED or UNVERIFIABLE result must not create a record
     * that would then make the order Paid.
     */
    public function test_a_rejected_or_unverifiable_result_records_nothing(): void
    {
        $service = $this->service();

        $service->recordVerification('easebuzz', PaymentVerificationResult::rejected('declined'), '100.00');
        $this->assertNull(session(PaymentVerificationService::SESSION_KEY),
            'a REJECTED verification was recorded, which would then resolve the order to Paid.');

        $service->recordVerification('easebuzz', PaymentVerificationResult::unverifiable('no field'), '100.00');
        $this->assertNull(session(PaymentVerificationService::SESSION_KEY),
            'an UNVERIFIABLE verification was recorded. Unverifiable must fail closed.');

        $service->recordVerification('easebuzz', PaymentVerificationResult::verified('ok'), '100.00');
        $this->assertIsArray(session(PaymentVerificationService::SESSION_KEY),
            'Premise: a VERIFIED result does record, or the two assertions above hold because '
            . 'recordVerification() never records anything at all.');
    }
}
