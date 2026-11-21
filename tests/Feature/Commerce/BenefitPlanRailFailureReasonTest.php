<?php

namespace Tests\Feature\Commerce;

use Tests\TestCase;

/**
 * THE PLAN-RAIL BENEFIT CATCH NO LONGER ANSWERS EVERY FAILURE WITH A CURRENCY SENTENCE. AUD-4.
 * =================================================================================================
 *
 * Found by the PR #140 deletion audit. `BenefitPaymentController::initiatePayment()` -- the SaaS PLAN
 * rail, a merchant buying a subscription -- wrapped its Tap charge in a catch that returned
 *
 *     redirect()->back()->with('error', __('Currency Not Supported.Contact To Your Site Admin'))
 *
 * for EVERY `\Throwable`, and recorded nothing. A wrong platform key, an unreachable provider, a 5xx
 * and a malformed body all read as a currency problem, and the log could not say which. The storefront
 * twin (`PaymentService::processBenefit`) had been narrowed in an earlier commit; this rail had not.
 *
 * WHAT IS PINNED, AND WHAT IS DELIBERATELY NOT.
 *
 *   * the failure goes through `PaymentFailure::redirectBack($th, 'benefit')`, which LOGS the gateway,
 *     the exception class and the first 300 characters of its message and answers with a fixed
 *     sentence;
 *   * the currency sentence is attached ONLY to a `ClientException` (every 4xx) and appears exactly
 *     once in the controller's code.
 *
 * NOT PINNED: that a 4xx means "currency". It does not -- a 401 from a wrong key is also a 4xx, and the
 * comment at the catch says so. The condition was left at "any 4xx" because Tap's codes for a rejected
 * currency cannot be verified from here (no credential exists in this container), and a guess would
 * silence the sentence where it IS true. This file therefore claims only that the sentence stopped
 * covering connection failures, 5xx and malformed bodies, and that the reason is now recorded.
 *
 * SOURCE-LEVEL, for the reason `GatewayCallbackDestinationTest` gives: the Guzzle client is constructed
 * inline, so nothing can be intercepted. Comments are stripped, so a sentence quoted in a docblock
 * cannot satisfy or break an assertion.
 */
class BenefitPlanRailFailureReasonTest extends TestCase
{
    private const CONTROLLER = 'app/Http/Controllers/BenefitPaymentController.php';

    private const SENTENCE = "__('Currency Not Supported.Contact To Your Site Admin')";

    /** Whitespace removed from the code AND from the needle, so a string literal compares like the rest. */
    private function squash(string $text): string
    {
        return preg_replace('/\s+/', '', $text);
    }

    /** The code of `initiatePayment()` only, because `call_back()` below it reads the same key. */
    private function initiatePayment(): string
    {
        $code = $this->code();
        $start = strpos($code, 'publicfunctioninitiatePayment(');
        $end = strpos($code, 'publicfunctioncall_back(');

        $this->assertIsInt($start, 'initiatePayment() is gone or renamed -- re-read this test');
        $this->assertIsInt($end, 'call_back() is gone or renamed, so the slice below has no end');
        $this->assertGreaterThan($start, $end);

        return substr($code, $start, $end - $start);
    }

    private function code(): string
    {
        $absolute = base_path(self::CONTROLLER);
        $this->assertFileExists($absolute, self::CONTROLLER.' has moved -- re-read this test');

        $out = '';

        foreach (token_get_all((string) file_get_contents($absolute)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $this->squash($out);
    }

    public function test_premise_the_charge_request_is_still_wrapped_by_the_catch_this_file_reads(): void
    {
        $code = $this->initiatePayment();

        $this->assertSame(1, substr_count($code, "'https://api.tap.company/v2/charges'"),
            'the Tap charge POST has moved, so the assertions below describe another place');
        $this->assertSame(1, substr_count($code, "['benefit_secret_key']"),
            'initiatePayment() no longer reads the platform key, and the comment at the catch says it does');
    }

    public function test_the_failure_is_recorded_through_the_shared_helper(): void
    {
        $this->assertSame(
            1,
            substr_count($this->initiatePayment(), "\\App\\Services\\PaymentFailure::redirectBack(\$th,'benefit')"),
            'the charge catch no longer records the failure through PaymentFailure::redirectBack(), '
            .'so a wrong key, an outage and a bad body are again indistinguishable in the log'
        );
    }

    public function test_the_currency_sentence_is_attached_only_to_a_client_exception(): void
    {
        $code = $this->initiatePayment();
        $sentence = $this->squash(self::SENTENCE);

        $this->assertSame(1, substr_count($code, $sentence),
            'the currency sentence must appear exactly once in initiatePayment()');

        $conditional = $this->squash(
            "\$th instanceof \\GuzzleHttp\\Exception\\ClientException ? \$failure->with('error', ".self::SENTENCE.") : \$failure;"
        );

        $this->assertSame(1, substr_count($code, $conditional),
            'the currency sentence is no longer conditional on a 4xx, so every failure reads as a currency problem again');
    }
}
