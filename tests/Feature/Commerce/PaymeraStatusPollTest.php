<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NEW-paymera-poll-unauthenticated.
 *
 * `PaymentController::pollPaymentStatus()` read
 * `config('services.paymera.username')` straight into an Authorization header:
 *
 *     'Authorization' => 'Bearer ' . config('services.paymera.username'),
 *
 * and there is no `paymera` block anywhere under `config/` —
 * `grep -rn paymera config/` returns nothing at all. So the header that went
 * out was the literal "Bearer " with an empty credential and every status poll
 * was unauthenticated.
 *
 * That is not a cosmetic defect. The reply to that call is the ONLY thing
 * deciding whether the order gets created and recorded as paid: whatever an
 * unauthenticated endpoint chose to return was treated as Paymera's
 * authoritative word on whether money moved.
 *
 * The fix FAILS CLOSED, and these tests hold it to that: with no credential no
 * call is made at all, and the sentinel returned can never be mistaken for a
 * successful capture by any of the five call sites, each of which tests for
 * `status == 'A'`.
 *
 * No credential VALUE appears here or in the logs the fix writes — the test
 * secret below is a literal fixture, not a real credential.
 */
class PaymeraStatusPollTest extends TestCase
{
    private function controller(): PaymentController
    {
        return new PaymentController(app(PaymentService::class));
    }

    private function poll(PaymentController $controller, string $paymentId = 'pay_1')
    {
        $method = new \ReflectionMethod(PaymentController::class, 'pollPaymentStatus');

        return $method->invoke($controller, $paymentId);
    }

    /**
     * THE CORE CASE. With no credential configured — which is the state of
     * this repository today — no request is made and the sentinel comes back.
     */
    public function test_an_unconfigured_credential_makes_no_call_and_fails_closed(): void
    {
        config(['services.paymera.username' => null]);

        Http::fake();

        $result = $this->poll($this->controller());

        Http::assertNothingSent();

        $this->assertSame(
            PaymentController::PAYMERA_STATUS_UNAUTHENTICATED,
            $result['status'],
            'an unauthenticated poll must report that it could not establish a status'
        );

        $this->assertNotSame('A', $result['status'], 'and must never look like a capture');
    }

    /** An empty or whitespace credential is no credential. */
    public function test_a_blank_credential_is_treated_as_absent(): void
    {
        foreach (['', '   ', null] as $credential) {
            config(['services.paymera.username' => $credential]);

            Http::fake();

            $this->assertSame(
                PaymentController::PAYMERA_STATUS_UNAUTHENTICATED,
                $this->poll($this->controller())['status']
            );

            Http::assertNothingSent();
        }
    }

    /**
     * THE PROPERTY THAT MATTERS AT EVERY CALL SITE. All five callers of
     * pollPaymentStatus() gate on `status == 'A'`. The sentinel must therefore
     * never equal 'A' — under == as well as ===, since four of the five use the
     * loose comparison and PHP's loose rules are where this kind of guard dies.
     */
    public function test_the_sentinel_cannot_pass_any_call_site_status_check(): void
    {
        $sentinel = PaymentController::PAYMERA_STATUS_UNAUTHENTICATED;

        $this->assertFalse($sentinel == 'A');
        $this->assertFalse($sentinel === 'A');
        $this->assertNotEmpty($sentinel, 'an empty sentinel would be ambiguous with a missing key');
    }

    /** A configured credential is actually used, as a bearer token. */
    public function test_a_configured_credential_is_sent_as_a_bearer_token(): void
    {
        config(['services.paymera.username' => 'fixture-credential']);

        Http::fake(['*' => Http::response(['status' => 'A', 'amount' => '10.00'], 200)]);

        $result = $this->poll($this->controller(), 'pay_99');

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/api/payment-status/pay_99')
                && $request->hasHeader('Authorization', 'Bearer fixture-credential');
        });

        $this->assertSame('A', $result['status']);
    }

    /** An HTTP error is the absence of an answer, not a decline and not a capture. */
    public function test_an_unsuccessful_response_fails_closed(): void
    {
        config(['services.paymera.username' => 'fixture-credential']);

        foreach ([401, 403, 500, 502] as $status) {
            Http::fake(['*' => Http::response(['status' => 'A'], $status)]);

            $this->assertSame(
                PaymentController::PAYMERA_STATUS_UNAUTHENTICATED,
                $this->poll($this->controller())['status'],
                "HTTP {$status} must not be read as a capture even when the body says 'A'"
            );
        }
    }

    /**
     * An unreachable gateway is not a successful payment. Before the fix a
     * thrown ConnectionException propagated out of the callback as a 500; now
     * it fails closed with the sentinel.
     */
    public function test_an_unreachable_gateway_fails_closed(): void
    {
        config(['services.paymera.username' => 'fixture-credential']);

        Http::fake(static function () {
            throw new \Illuminate\Http\Client\ConnectionException('cannot connect');
        });

        $this->assertSame(
            PaymentController::PAYMERA_STATUS_UNAUTHENTICATED,
            $this->poll($this->controller())['status']
        );
    }

    /**
     * THE WHOLE CONTROLLER SEAM, FAILING CLOSED.
     *
     * `verifyPaymeraPayment()` is the single gate both routed Paymera entry
     * points pass through before an order is created. This drives it in the
     * configuration the repository is ACTUALLY in today — no signature scheme
     * configured, enforcement left at its default of on — and asserts that the
     * result does not permit a Paid order, and that feeding that result to the
     * order writer yields Pending.
     *
     * This is the test the brief asks for: an unverifiable callback does not
     * mark an order paid.
     */
    public function test_an_unverifiable_callback_does_not_mark_an_order_paid(): void
    {
        // The state of config/ today: no paymera block at all.
        config(['services.paymera' => null]);

        $verify = new \ReflectionMethod(PaymentController::class, 'verifyPaymeraPayment');

        $result = $verify->invoke(
            $this->controller(),
            ['expected_total' => '1250.50', 'amount_sent' => 125050, 'request_data' => ['store_id' => 1]],
            ['status' => 'A', 'amount' => '1250.50'],   // the gateway says it captured the right amount
            ['anything' => 'the caller controls this'], // and the callback carries no signature
            'ref-1'
        );

        $this->assertFalse(
            $result->allowsPaid(),
            'with no signature scheme configured the callback must not authorise a Paid order, '
            . 'even when the amount itself matches'
        );

        // And the order writer agrees: this becomes Pending, not Paid.
        $verifier = new \App\Services\PaymentVerificationService();
        $verifier->recordVerification('paymera', $result, '1250.50');

        $this->assertSame(
            \App\Services\PaymentVerificationService::STATUS_PENDING,
            $verifier->resolveOrderPaymentStatus(
                'paymera',
                $verifier->consumeVerification(),
                '1250.50',
                __METHOD__
            )
        );
    }

    /**
     * The owner's documented escape hatch behaves as documented: with
     * signature enforcement explicitly switched off, the amount check alone
     * decides — and a WRONG amount still fails closed.
     *
     * This exists so the escape hatch cannot quietly become "accept anything".
     */
    public function test_with_signature_enforcement_off_a_wrong_amount_still_fails_closed(): void
    {
        config([
            'services.paymera.require_callback_signature' => false,
            'services.paymera.callback_amount_field' => 'amount',
        ]);

        $verify = new \ReflectionMethod(PaymentController::class, 'verifyPaymeraPayment');

        $pending = ['expected_total' => '1250.50', 'amount_sent' => 125050, 'request_data' => ['store_id' => 1]];

        $wrong = $verify->invoke(
            $this->controller(),
            $pending,
            ['status' => 'A', 'amount' => '1.00'],
            [],
            'ref-2'
        );

        $this->assertFalse($wrong->allowsPaid(), 'a tampered amount must still be refused');

        $right = $verify->invoke(
            $this->controller(),
            $pending,
            ['status' => 'A', 'amount' => '1250.50'],
            [],
            'ref-3'
        );

        $this->assertTrue(
            $right->allowsPaid(),
            'and the hatch must still let a correctly-captured payment through, '
            . 'or switching it off would simply break checkout'
        );
    }

    /** A non-array body (null, a bare string) is unusable, so it fails closed. */
    public function test_an_unusable_response_body_fails_closed(): void
    {
        config(['services.paymera.username' => 'fixture-credential']);

        Http::fake(['*' => Http::response('not json at all', 200)]);

        $this->assertSame(
            PaymentController::PAYMERA_STATUS_UNAUTHENTICATED,
            $this->poll($this->controller())['status']
        );
    }
}
