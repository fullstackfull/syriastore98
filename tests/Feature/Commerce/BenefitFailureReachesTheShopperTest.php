<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P4-F046 -- does a Benefit failure message actually REACH the shopper?
 *
 * FOUND BY INDEPENDENT REVIEW, AND IT UNDERCUT THE COMMIT THAT PRECEDED IT. P4-F046 narrowed
 * processBenefit()'s catch into four arms, each returning a redirect carrying a message that says
 * what really went wrong. The commit claimed those messages "say something true to the shopper".
 * They do not, and neither did the single message they replaced.
 *
 * THE CHAIN, measured: `PaymentService::processBenefit()` returns a `RedirectResponse` on every
 * failure path, and its only caller -- `PaymentController::processPayment()` -- lists `benefit` in
 * NONE of its four dispatch groups, so it falls to the final `else`:
 *
 *     return new RedirectResponse($response);
 *
 * `Symfony\Component\HttpFoundation\RedirectResponse::__construct()` is typed `string`, the file
 * has no `declare(strict_types=1)`, and `Response::__toString()` exists -- so the object is
 * COERCED into the entire HTTP message ("HTTP/1.0 302 Found\r\n...") and installed as the
 * `Location` header. A header carrying CRLF is refused at send time. The shopper gets a broken
 * response, never the message.
 *
 * THE SAME CODEBASE ALREADY KNEW. `paymera` carries the exact `instanceof` guard three lines
 * above, added after this shape was measured as "a 500 on an ordinary guest checkout". `benefit`
 * had none.
 *
 * THE FIX IS IN THE CALLER AND IS GATEWAY-AGNOSTIC, deliberately: every payment type that falls
 * to that `else` and returns a redirect has the same defect, so guarding on the TYPE fixes them
 * all, where guarding on `$paymentMethod == 'benefit'` would fix one and -- see below -- break a
 * different test.
 *
 * WHY THIS TEST DRIVES THE PRIVATE METHOD BY REFLECTION rather than a real checkout: a real one
 * needs a store, a basket, billing details and an outbound call to api.tap.company that this
 * container has no credential for. The defect is entirely in the CALLER'S CONTRACT -- what
 * processPayment() does with what the service hands back -- so the service is replaced by a mock
 * and the contract is driven directly. That is the whole mechanism and nothing else.
 *
 * NOTE FOR WHOEVER EDITS THE FIX: `GatewayCallbackDestinationTest` asserts that
 * PaymentController.php contains no `== 'benefit'`, because that string would signal a capture
 * verification branch that does NOT exist. Writing this fix as `if ($paymentMethod == 'benefit')`
 * turns that test red with a message about capture verification -- a confusing false signal. The
 * `instanceof` form does not trip it.
 */
class BenefitFailureReachesTheShopperTest extends TestCase
{
    private function drive(string $paymentType, $serviceReturn)
    {
        $this->mock(PaymentService::class, function ($mock) use ($serviceReturn) {
            $mock->shouldReceive('process')->andReturn($serviceReturn);
        });

        $controller = app(PaymentController::class);

        $method = new ReflectionMethod(PaymentController::class, 'processPayment');
        $method->setAccessible(true);

        return $method->invoke(
            $controller,
            Request::create('/', 'POST', ['payment_type' => $paymentType]),
            'a-store',
            []
        );
    }

    public function test_a_benefit_failure_redirect_is_returned_intact_rather_than_stringified(): void
    {
        $refusal = redirect('/checkout')->with('error', 'Currency Not Supported.Contact To Your Site Admin');

        $result = $this->drive('benefit', $refusal);

        $this->assertInstanceOf(RedirectResponse::class, $result);

        // Absolute, because redirect() resolves against the app URL. What matters is that the
        // target is the refusal's own URL and NOT this object's HTTP message text.
        $this->assertStringEndsWith(
            '/checkout',
            $result->getTargetUrl(),
            'The refusal was stringified into its own HTTP message and installed as the Location '
            . 'header, so the shopper never sees the reason. PaymentController::processPayment '
            . "needs the `instanceof` guard `paymera` already has."
        );

        $this->assertStringNotContainsString(
            'HTTP/',
            $result->getTargetUrl(),
            'The target URL contains a raw HTTP message, which is the coercion this guards against.'
        );
    }

    public function test_a_successful_benefit_url_still_becomes_a_redirect_to_that_url(): void
    {
        // The guard must not change the success path: a plain string URL is what processBenefit
        // returns when Tap hands back a payment page, and it must still become a redirect to it.
        $result = $this->drive('benefit', 'https://checkout.tap.company/pay/abc123');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('https://checkout.tap.company/pay/abc123', $result->getTargetUrl());
    }
}
