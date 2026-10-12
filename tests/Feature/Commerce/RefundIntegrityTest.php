<?php

namespace Tests\Feature\Commerce;

use App\Services\RefundIntegrityService;
use Tests\TestCase;

/**
 * NEW-refund-no-gateway-call.
 *
 * `OrderRefundController::RefundAmonut()` stores `refund_status => 'Refunded'`
 * and returns "Refund Amount successfully!" with NO gateway call anywhere in
 * the refund path — there is no refund driver in any of PaymentService's
 * payment arms and no refund endpoint in the repository. So the merchant is
 * told a credit was issued, the order reads 'Refunded', and the customer's card
 * was never touched. It also takes the amount raw from the request and compares
 * it against nothing, so a refund larger than the order can be recorded.
 *
 * SCOPE, STATED PLAINLY. OrderRefundController is Agent 1's file this round, so
 * it is NOT edited here. What is implementable without owner input is
 * implemented as a service and proven below; wiring the controller to it is
 * reported as a cross-agent dependency, and until that wiring lands the
 * CONTROLLER still writes 'Refunded' for a gateway order. This suite proves the
 * seam, not the controller — and says so rather than implying the defect is
 * closed.
 *
 * @see \App\Services\RefundIntegrityService
 */
class RefundIntegrityTest extends TestCase
{
    private RefundIntegrityService $refunds;

    protected function setUp(): void
    {
        parent::setUp();

        // Instantiated, not merely linted: a service that cannot be
        // constructed is not evidence of anything.
        $this->refunds = new RefundIntegrityService();
    }

    // -----------------------------------------------------------------
    // The absence of a gateway refund is explicit.
    // -----------------------------------------------------------------

    /**
     * THE CORE CASE: an online gateway order must not be recorded as settled,
     * because no money moved.
     */
    public function test_a_gateway_refund_is_not_recorded_as_settled(): void
    {
        foreach (['paymera', 'stripe', 'payu', 'khalti', 'cinetpay', 'easebuzz'] as $type) {
            $status = $this->refunds->resolveRefundStatus($type, 100, __METHOD__);

            $this->assertSame(
                RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED,
                $status,
                $type . ': no refund driver exists, so this must not claim a settlement'
            );

            $this->assertNotSame(
                RefundIntegrityService::STATUS_SETTLED,
                $status,
                $type . ': recording Refunded would assert money moved when it did not'
            );

            $this->assertFalse($this->refunds->mayRecordAsSettled($type));
        }
    }

    /**
     * The status chosen must be one the application ALREADY understands.
     *
     * This is the check the brief's warning is about: a status the views and
     * the datatable have never seen would pass any test that merely asserts a
     * string, and break the refund screen in production. 'Accept' is written
     * today by OrderRefundController::updateRefundStatus, so it is a real
     * value, not one minted by this suite.
     */
    /**
     * CORRECTED ASSERTION, and the reason is recorded rather than quietly
     * swapped.
     *
     * This test used to assert that the literal text `'refund_status' => 'Accept'`
     * appeared in OrderRefundController's SOURCE. That mechanism was wrong in two
     * directions:
     *
     *   - It passed on a mere textual match, so the string appearing inside a
     *     comment would have satisfied it while nothing wrote the status.
     *   - It failed the moment the controller stopped writing the value as an
     *     inline literal. It now writes it through its own named state
     *     vocabulary (OrderRefundController::STATE_ACCEPT / STATE_REFUNDED), so
     *     the literal is gone while the behaviour it was standing in for is
     *     strictly more explicit than before.
     *
     * The PROPERTY the old assertion was reaching for is kept and is now checked
     * directly: the two statuses this service can return must be the same two
     * values the refund controller recognises as states, and both must be
     * reachable targets in its transition table. That is what makes "the views
     * and the datatable already render this" true, and it cannot be satisfied by
     * a comment.
     */
    public function test_the_not_settled_status_is_a_status_the_application_already_writes(): void
    {
        $this->assertSame('Accept', RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED);
        $this->assertSame('Refunded', RefundIntegrityService::STATUS_SETTLED);

        // The controller's own vocabulary, not a string in its source.
        $this->assertSame(
            RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED,
            \App\Http\Controllers\OrderRefundController::STATE_ACCEPT,
            'the not-settled status must be a state the refund controller recognises'
        );

        $this->assertSame(
            RefundIntegrityService::STATUS_SETTLED,
            \App\Http\Controllers\OrderRefundController::STATE_REFUNDED
        );

        // And both must be states the controller can actually move a refund to,
        // so neither is a value only this service believes in.
        $reachable = array_unique(array_merge(
            ...array_values(\App\Http\Controllers\OrderRefundController::LEGAL_TRANSITIONS)
        ));

        $this->assertContains(
            RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED,
            $reachable,
            'the not-settled status must be reachable in the controller transition table'
        );

        $this->assertContains(
            RefundIntegrityService::STATUS_SETTLED,
            $reachable
        );
    }

    /**
     * The service is actually CONNECTED.
     *
     * When this suite was written RefundIntegrityService had no production
     * caller at all: `grep -rn RefundIntegrityService app/` matched only its own
     * class definition, so every property proven above was proven about code
     * that never ran. The whole suite passed while the live refund endpoint
     * still stored a request-supplied amount and still claimed 'Refunded'
     * without a gateway.
     *
     * This asserts the wiring itself, because "the service is correct" and "the
     * service is used" are different claims and only the second one protects a
     * merchant.
     */
    public function test_the_refund_controller_actually_uses_this_service(): void
    {
        $method = new \ReflectionMethod(
            \App\Http\Controllers\OrderRefundController::class,
            'RefundAmonut'
        );

        $source = implode("\n", array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString('RefundIntegrityService', $source,
            'the refund writer must go through the integrity service, not past it');
        $this->assertStringContainsString('cappedRefundAmount', $source,
            'the stored amount must be the capped one, not the requested one');
        $this->assertStringContainsString('resolveRefundStatus', $source,
            'the stored status must be the honest one, not the literal Refunded');
    }

    /**
     * OFFLINE PAYMENTS ARE PRESERVED. The merchant hands cash back by hand, so
     * 'Refunded' is a true statement and must not be downgraded — otherwise
     * this "fix" would break every cash-on-delivery refund in the platform.
     */
    public function test_an_offline_payment_is_still_recorded_as_settled(): void
    {
        foreach (['cod', 'COD', 'cash', 'Cash_On_Delivery', 'bank_transfer', 'whatsapp', 'telegram'] as $type) {
            $this->assertSame(
                RefundIntegrityService::STATUS_SETTLED,
                $this->refunds->resolveRefundStatus($type, 100, __METHOD__),
                $type . ' is settled by hand, so Refunded is accurate'
            );

            $this->assertTrue($this->refunds->mayRecordAsSettled($type));
        }
    }

    /** An unknown or absent payment type fails closed, not open. */
    public function test_an_unknown_payment_type_fails_closed(): void
    {
        foreach ([null, '', '   ', 'something_new', 'REFUNDED'] as $type) {
            $this->assertSame(
                RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED,
                $this->refunds->resolveRefundStatus($type, 100, __METHOD__),
                'an unrecognised payment type must not be assumed settled'
            );
        }
    }

    /** No refund driver exists for anything. Asserted, so adding one is deliberate. */
    public function test_no_gateway_refund_driver_exists(): void
    {
        foreach ([null, 'paymera', 'stripe', 'cod'] as $type) {
            $this->assertFalse($this->refunds->hasGatewayRefundDriver($type));
        }
    }

    // -----------------------------------------------------------------
    // The amount cap, which is implementable now and is implemented.
    // -----------------------------------------------------------------

    /** THE CORE CASE: a refund larger than the order is capped at the order total. */
    public function test_a_refund_larger_than_the_order_is_capped(): void
    {
        $result = $this->refunds->cappedRefundAmount(9999.00, 1250.50);

        $this->assertSame(1250.50, $result['amount']);
        $this->assertTrue($result['capped']);
        $this->assertSame(9999.00, $result['requested']);
        $this->assertSame(1250.50, $result['limit']);
    }

    /** A legitimate partial refund passes through untouched. */
    public function test_a_partial_refund_is_not_altered(): void
    {
        $result = $this->refunds->cappedRefundAmount(400.00, 1250.50);

        $this->assertSame(400.00, $result['amount']);
        $this->assertFalse($result['capped']);
    }

    /** A full refund is allowed: the cap is a ceiling, not a reduction. */
    public function test_a_full_refund_is_allowed(): void
    {
        $result = $this->refunds->cappedRefundAmount(1250.50, 1250.50);

        $this->assertSame(1250.50, $result['amount']);
        $this->assertFalse($result['capped']);
    }

    /**
     * A negative amount must never become a CHARGE. It collapses to zero.
     */
    public function test_a_negative_amount_cannot_become_a_charge(): void
    {
        foreach ([-500, '-500', -0.01] as $requested) {
            $result = $this->refunds->cappedRefundAmount($requested, 1250.50);

            $this->assertSame(0.0, $result['amount']);
            $this->assertGreaterThanOrEqual(0.0, $result['amount']);
        }
    }

    /** Unusable input is zero, not an uncapped passthrough. */
    public function test_unusable_amounts_collapse_to_zero(): void
    {
        foreach ([null, '', 'abc', [], new \stdClass()] as $requested) {
            $this->assertSame(0.0, $this->refunds->cappedRefundAmount($requested, 1250.50)['amount']);
        }
    }

    /**
     * The cap normalises the request string THE SAME WAY the controller does,
     * so the ceiling is applied to the number the controller would have stored
     * rather than to a different reading of it.
     *
     * The controller's own normalisation is
     * `preg_replace('/[^0-9.]/', '', $request->refund_amount)`, which is why
     * '$9,999.00' reads as 9999.00 and why a minus sign is stripped — a detail
     * that matters, because it means the controller could never receive a
     * negative value but could certainly receive an inflated one.
     */
    public function test_the_cap_normalises_a_currency_string_as_the_controller_does(): void
    {
        $this->assertSame(1250.50, $this->refunds->cappedRefundAmount('$9,999.00', 1250.50)['amount']);
        $this->assertSame(400.0, $this->refunds->cappedRefundAmount('400', 1250.50)['amount']);
        $this->assertSame(400.5, $this->refunds->cappedRefundAmount('SYP 400.50', 1250.50)['amount']);
    }

    /** An order with no usable total permits no refund at all. */
    public function test_an_order_without_a_total_permits_no_refund(): void
    {
        foreach ([0, null, '', 'abc', -10] as $total) {
            $result = $this->refunds->cappedRefundAmount(100, $total);

            $this->assertSame(0.0, $result['amount'], 'nothing may be refunded against no total');
            $this->assertTrue($result['capped']);
        }
    }

    // -----------------------------------------------------------------
    // The owner gate, recorded rather than guessed.
    // -----------------------------------------------------------------

    /**
     * The gateway requirements are a real, structured record — and they contain
     * no invented endpoint or credential.
     */
    public function test_the_gateway_requirements_are_recorded_without_inventing_anything(): void
    {
        $requirements = $this->refunds->gatewayRequirements();

        $this->assertArrayHasKey('paymera', $requirements);
        $this->assertArrayHasKey('all_other_gateways', $requirements);
        $this->assertArrayHasKey('offline', $requirements);

        $this->assertSame('blocked_on_owner', $requirements['paymera']['status']);
        $this->assertSame('no_gateway_needed', $requirements['offline']['status']);
        $this->assertNotEmpty($requirements['paymera']['needs']);

        // Nothing here may look like a fabricated refund endpoint or secret.
        $encoded = json_encode($requirements);

        $this->assertStringNotContainsString('https://', $encoded,
            'no refund endpoint may be invented');
        $this->assertDoesNotMatchRegularExpression('/sk_|pk_|Bearer\s+\S/', $encoded,
            'no credential may be invented');
    }

    /**
     * Every payment type this service treats as settled-by-hand is one the COD
     * collection path also recognises, so the two cannot drift into disagreeing
     * about which orders involve a gateway.
     */
    public function test_the_offline_list_agrees_with_the_payment_verification_seam(): void
    {
        foreach (\App\Services\PaymentVerificationService::OFFLINE_PAYMENT_TYPES as $type) {
            $this->assertContains(
                $type,
                RefundIntegrityService::OFFLINE_PAYMENT_TYPES,
                $type . ' is settled off-platform for payment, so it is for refund too'
            );
        }
    }
}
