<?php

namespace Tests\Feature\Commerce;

use App\Services\PaymentVerificationService;
use Tests\TestCase;

/**
 * ENFORCED_PAYMENT_TYPES MAY ONLY NAME A GATEWAY THAT ACTUALLY RECORDS A
 * VERIFICATION.
 *
 * WHY THIS GUARD EXISTS. `ENFORCED_PAYMENT_TYPES` holds exactly one gateway,
 * 'paymera', and the obvious next step is to add more. Adding one is NOT a
 * one-line change, and getting it wrong is worse than leaving it alone in both
 * directions:
 *
 *   - Add a gateway whose arm never calls recordVerification(), and
 *     resolveOrderPaymentStatus() finds no verification record and returns
 *     Pending for EVERY order on that gateway. Merchant wallets stop being
 *     credited and orders look unpaid. A control that breaks checkout is a
 *     defect, not a fix.
 *
 *   - Add a gateway whose order writer does not call
 *     resolveOrderPaymentStatus() at all, and nothing happens on that gateway
 *     while the entry looks like protection. A false sense of coverage is worse
 *     than a known gap.
 *
 * So the invariant is asserted mechanically: every type listed must have a
 * recordVerification() call for it somewhere in app/. That is the thing that
 * makes the entry mean anything.
 *
 * WHAT WAS MEASURED ABOUT THE OTHER GATEWAYS, so the next agent does not redo
 * it. Nine `payment_status = 'Paid'` literals remain outside the resolver-wired
 * writers. Their files belong to another agent in this phase, so none was
 * changed; what follows is the survey, by file:line.
 *
 *  - KhaltiPaymnetController.php:632 -- THE ONLY ONE WITH A REAL SERVER-SIDE
 *    SEAM. `$khalti->verifyPayment($secret, $token, $amount)` runs at :590 and
 *    the whole order write sits inside `if ($response['status_code'] == '200')`.
 *    So Khalti's Paid write IS gated on a server-to-server call.
 *
 *    AND YET 'khalti' MUST NOT BE ADDED HERE, and the reason is specific rather
 *    than cautious: that controller writes `payment_status = 'Paid'` as a
 *    literal and never calls resolveOrderPaymentStatus(), so an entry here could
 *    not affect it. PaymentService::processKhalti() returns the `payment.khalti`
 *    VIEW rather than writing an order, so the khalti order is written only by
 *    that controller. Adding 'khalti' would therefore change nothing about real
 *    Khalti orders, while flipping to Pending any order the three
 *    resolver-wired writers happened to write with a session payment_type of
 *    'khalti'. Pure downside. Wiring Khalti properly means calling
 *    recordVerification() in that controller, which this agent does not own.
 *
 *  - CinetPayController.php:720 -- the Paid write is gated ONLY on
 *    `$request->responsecode == 0` (:353), a CLIENT-SUPPLIED query parameter.
 *    The file DOES contain a real `api-checkout.cinetpay.com/v2/payment/check`
 *    call (:320-343), but it is in a different method and
 *    `grep -n '$this->check' ` on that file returns nothing, so the verification
 *    capability exists and is NOT WIRED. That is the most promising candidate
 *    for the next phase and the one needing no new provider knowledge.
 *
 *  - AuthorizeNetPaymnetController.php:674, PayUPaymentController.php:628,
 *    NMIPayController.php:605, EasebuzzController.php:635 -- no verification
 *    call of any kind in the file. Searched for `Http::`, `curl_exec`,
 *    `->verify`, `verifyPayment`, `->capture`, `validateHash`, `hash_hmac`,
 *    `checkStatus`, `TransactionStatus` and `verify_payment`; zero matches in
 *    each. No seam to extend.
 *
 *  - PaiementProPaymentController.php:629 -- no seam on the order path. The one
 *    `curl_exec` in the file (:107) is in `planPayWithPaiementpro()`, a PLAN
 *    purchase, and it is the payment-INIT call, not a verification.
 *
 *  - PosController.php:244 -- NOT a gateway payment. `payment_type = __('POS')`
 *    and a cashier took the money in person, so 'Paid' is a human asserting an
 *    out-of-band fact and there is no gateway to ask. Same category as the
 *    manual merchant-admin order in OrderController::store().
 *
 *  - LotteryPrizes/.../LotteryResultController.php:136 -- also not a gateway
 *    payment: `payment_type = 'Lottery Product'`, written when a draw is
 *    resolved, for a ticket that was already paid for. Also under
 *    packages/workdo/.
 *
 * NOTHING HERE CLAIMS ANY GATEWAY IS VERIFIED. No provider is reachable from
 * this container and none was contacted, so every gateway above is OPEN or
 * BLOCKED on its own provider contract, never "verified".
 */
class EnforcedPaymentTypesIntegrityTest extends TestCase
{
    /**
     * THE INVARIANT. Every enforced type must have a recordVerification() call.
     *
     * Without this, an entry added here silently records every order on that
     * gateway as Pending.
     */
    public function test_every_enforced_type_has_a_verification_recorder(): void
    {
        $recorded = $this->paymentTypesThatRecordAVerification();

        $this->assertNotEmpty(
            $recorded,
            'at least one gateway must record a verification, or the seam is dead code'
        );

        foreach (PaymentVerificationService::ENFORCED_PAYMENT_TYPES as $type) {
            $this->assertContains(
                $type,
                $recorded,
                sprintf(
                    "'%s' is enforced but nothing in app/ calls recordVerification('%s', ...). "
                        . 'Every order on that gateway would be recorded Pending, which stops '
                        . 'merchant wallets being credited. Wire the gateway arm first, then '
                        . 'list it here. Recorders found: %s',
                    $type,
                    $type,
                    implode(', ', $recorded)
                )
            );
        }
    }

    /**
     * Paymera is enforced, and it is the only one -- a deliberate state, not an
     * oversight. See the class docblock for the per-gateway survey behind it.
     *
     * This is a CHANGE DETECTOR, not a claim that one gateway is enough. If a
     * later phase wires another gateway it should update this list and this
     * assertion together, having read the invariant above.
     */
    public function test_paymera_is_the_only_enforced_gateway_today(): void
    {
        $this->assertSame(
            ['paymera'],
            PaymentVerificationService::ENFORCED_PAYMENT_TYPES,
            'if this list changed, the recorder invariant above must hold for the new '
                . 'entry, and the gateway must genuinely have been verified against its '
                . 'provider contract -- which cannot be done from this container'
        );
    }

    /**
     * An enforced gateway with NO verification record is recorded Pending, not
     * Paid. This is the behaviour that makes the invariant above load-bearing.
     */
    public function test_an_enforced_gateway_without_a_record_is_not_paid(): void
    {
        $verifier = new PaymentVerificationService();

        foreach (PaymentVerificationService::ENFORCED_PAYMENT_TYPES as $type) {
            $this->assertSame(
                PaymentVerificationService::STATUS_PENDING,
                $verifier->resolveOrderPaymentStatus($type, null, '100.00', __METHOD__),
                'an enforced gateway with no verification must never be recorded Paid'
            );
        }
    }

    /**
     * A gateway that is NOT enforced keeps its pre-existing behaviour exactly.
     *
     * This is the preservation half: 50-odd live payment methods must go on
     * working while their verification seams do not exist.
     */
    public function test_an_unenforced_gateway_keeps_its_existing_behaviour(): void
    {
        $verifier = new PaymentVerificationService();

        foreach (['stripe', 'paypal', 'khalti', 'CinetPay', 'payu', 'NMI', 'easebuzz'] as $type) {
            $this->assertNotContains(
                strtolower($type),
                PaymentVerificationService::ENFORCED_PAYMENT_TYPES,
                'fixture assumption: this gateway must not be enforced'
            );

            $this->assertSame(
                PaymentVerificationService::STATUS_PAID,
                $verifier->resolveOrderPaymentStatus($type, null, '100.00', __METHOD__),
                $type . ' has no verification seam, so its behaviour must be preserved '
                    . 'rather than changed -- recording it Pending would stop merchant '
                    . 'wallets being credited for a live payment method'
            );
        }
    }

    /**
     * The nine remaining literal Paid writes are still where the survey says
     * they are.
     *
     * This is a LOCATOR, so the next agent does not have to re-derive the list,
     * and it fails if one of them is fixed or moved -- which is the signal to
     * re-read the survey in the class docblock rather than trust it.
     *
     * The paths are deliberately NOT edited by this agent: every one belongs to
     * another agent in this phase.
     */
    public function test_the_surveyed_literal_paid_writes_are_still_present(): void
    {
        $base = base_path();

        $sites = [
            'app/Http/Controllers/AuthorizeNetPaymnetController.php' => 674,
            'app/Http/Controllers/PayUPaymentController.php' => 628,
            'app/Http/Controllers/NMIPayController.php' => 605,
            'app/Http/Controllers/KhaltiPaymnetController.php' => 632,
            'app/Http/Controllers/EasebuzzController.php' => 635,
            'app/Http/Controllers/PosController.php' => 244,
            'app/Http/Controllers/PaiementProPaymentController.php' => 629,
            'app/Http/Controllers/CinetPayController.php' => 720,
            'packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryResultController.php' => 136,
        ];

        $this->assertCount(9, $sites, 'the survey covers nine sites');

        foreach ($sites as $relative => $line) {
            $path = $base . '/' . $relative;

            $this->assertFileExists($path);

            $lines = file($path);

            $this->assertArrayHasKey(
                $line - 1,
                $lines,
                $relative . ' is shorter than the surveyed line ' . $line
            );

            $this->assertMatchesRegularExpression(
                "/payment_status['\"]?\s*(=>|=)\s*['\"]Paid['\"]/",
                $lines[$line - 1],
                $relative . ':' . $line . ' no longer holds a literal Paid write. If it was '
                    . 'fixed, good -- update the survey in this class\'s docblock and this '
                    . 'list. Do not just delete the row.'
            );
        }
    }

    /**
     * Which payment types any code in app/ records a verification for.
     *
     * Reads the literal first argument of each `recordVerification(` call. A
     * non-literal first argument cannot be resolved statically and is reported
     * as a failure of this helper rather than silently ignored, because an
     * unresolvable recorder would make the invariant above vacuous.
     *
     * @return list<string>
     */
    private function paymentTypesThatRecordAVerification(): array
    {
        $types = [];
        $unresolvable = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (!str_contains($source, 'recordVerification(')) {
                continue;
            }

            // Skip the service that DEFINES it; a definition is not a caller.
            if (str_contains($source, 'public function recordVerification(')) {
                continue;
            }

            if (preg_match_all(
                '/recordVerification\(\s*([\'"])([a-zA-Z0-9_\-]+)\1/',
                $source,
                $matches
            )) {
                foreach ($matches[2] as $type) {
                    $types[] = strtolower($type);
                }
            }

            // A call whose first argument is not a plain string literal.
            if (preg_match('/recordVerification\(\s*(?![\'"])\S/', $source)) {
                $unresolvable[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $unresolvable,
            'a recordVerification() call with a non-literal payment type cannot be '
                . 'checked statically, which would make the enforcement invariant vacuous. '
                . 'Either pass a literal or extend this helper deliberately.'
        );

        return array_values(array_unique($types));
    }
}
