<?php

namespace Tests\Feature\Payments;

use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PaymentService::processPayHere must not raise when the PayHere SDK is absent.
 *
 * `lahirulhr/laravel-payhere` cannot be installed from composer.lock -- its upstream
 * repository is gone (docs/BUILD-REPRODUCIBILITY.md) -- so in every tree built from this
 * lock `Lahirulhr\PayHere\PayHere` does not exist. Calling a missing class raises \Error,
 * and \Error is NOT an \Exception, so the method's own `catch (\Exception)` let it escape:
 * a storefront customer who chose PayHere at checkout got an HTTP 500 instead of the
 * intended redirect-with-error.
 *
 * NOTE for whoever resolves the upstream blocker: once the package becomes installable,
 * the first test below no longer describes this tree and must be revisited together with
 * `.github/known-test-outcomes.json`. The second test is independent of that and stays.
 *
 * Deliberately does NOT use RefreshDatabase: the guard must return before the method's
 * first database call, and running with no migrated schema is what demonstrates that.
 */
class PayHereAbsentCheckoutGuardTest extends TestCase
{
    public function test_payhere_checkout_redirects_with_an_error_instead_of_raising_when_the_package_is_absent(): void
    {
        $this->assertFalse(
            class_exists(\Lahirulhr\PayHere\PayHere::class),
            'Precondition: this tree is built from a lock whose PayHere package cannot be '
            . 'downloaded, so the class must be absent. See docs/BUILD-REPRODUCIBILITY.md.'
        );

        $this->session([]);

        $method = new ReflectionMethod(PaymentService::class, 'processPayHere');
        $method->setAccessible(true);

        $response = $method->invoke(
            new PaymentService(),
            Request::create('/checkout', 'POST', []),
            'any-store-slug',
            null
        );

        // The entitlement first, then the status code: the customer must be sent back with
        // an error, and no checkout side effect may have been recorded on the way out.
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('PayHere', (string) session()->get('error'));
        $this->assertFalse(
            session()->has('request_data'),
            'The guard must short-circuit BEFORE processPayHere writes request_data to the '
            . 'session or stores a CheckoutAttachment row.'
        );
        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_the_payhere_checkout_block_catches_throwable_not_only_exception(): void
    {
        $method = new ReflectionMethod(PaymentService::class, 'processPayHere');

        $source = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        // Comments are stripped first: this method's own comments discuss `catch (\Exception)`
        // by name, and a naive substring search over the raw source would match the prose
        // instead of the code and report the opposite of the truth.
        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        $this->assertStringContainsString('PayHere::checkOut()', $code);
        $this->assertStringContainsString(
            'catch (\Throwable',
            $code,
            'An \Error raised inside the PayHere block -- a missing class, a missing method '
            . 'on the facade -- must be caught and redirected, not returned as HTTP 500.'
        );
        $this->assertStringNotContainsString(
            'catch (\Exception',
            $code,
            'catch (\Exception) does not catch \Error; this method must not narrow back to it.'
        );
    }
}
