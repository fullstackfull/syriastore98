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
    public function test_the_amount_enforced_gateways_are_exactly_these(): void
    {
        /*
         * 'nmi' JOINED paymera under the owner's Phase 01 final execution directive. Its arm makes
         * the capture itself and records the provider's own `response == 1` through
         * recordVerification() (NMIPayController::getNMIProductStatus), so it satisfies the
         * recorder invariant asserted above this method -- which is the condition this test's
         * message has always demanded of a new entry.
         *
         * AND THIS LIST NO LONGER DECIDES PAID-WITHOUT-VERIFICATION. Under the same decision every
         * gateway type is Pending without a record; membership here means the captured AMOUNT is
         * additionally reconciled against the order total.
         */
        /*
         * 'khalti' JOINED 2026-10-24, and it is the first entry added to CORRECT a gap rather
         * than to tighten a working path. Khalti's verify call is bound to the server's
         * cartlist_final_price, but the handler recomputes the order's final_price from a fresh
         * cart read at return time -- and the basket is mutable across the round trip. So a
         * genuine verification can cover a different order than the one being written, and
         * membership here is what makes the two figures be compared. Measured before enrolling:
         * on a plain checkout they agree (100.0 against 100), so the happy path is unaffected.
         */
        $this->assertSame(
            ['paymera', 'nmi', 'khalti'],
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
     * A GATEWAY WITH NO VERIFICATION RECORD IS PENDING, enforced or not.
     *
     * REVERSED BY OWNER DECISION, Phase 01 final execution directive: "Any unverified gateway
     * payment must remain Pending, never Paid. Do not create a falsely paid order, and preserve a
     * safe reconciliation path for customers who may have been charged."
     *
     * This method used to assert the opposite, and its old message recorded why: "recording it
     * Pending would stop merchant wallets being credited for a live payment method." That was the
     * right call while the business change was unmade -- when an order counts as paid decides when
     * a merchant is credited -- and the owner has now made it.
     *
     * NMI IS DROPPED FROM THE FIXTURE because it is now enforced; its own behaviour is asserted by
     * test_an_enforced_gateway_without_a_record_is_not_paid above, which covers every entry in the
     * list rather than a hand-written sample.
     *
     * THE RECONCILIATION PATH IS NOT ASSERTED HERE. This method measures the STATUS; that the
     * order is still written is the end-to-end question and lives in
     * UnverifiedGatewayStaysPendingTest alongside the off-platform and COD carve-outs.
     */
    public function test_a_gateway_with_no_verification_record_is_not_paid(): void
    {
        $verifier = new PaymentVerificationService();

        /*
         * 'khalti' WAS IN THIS SAMPLE AND IS NOT ANY MORE: it joined ENFORCED_PAYMENT_TYPES on
         * 2026-10-24, and this method's own fixture assertion below is what caught that -- which
         * is what a fixture assertion is for. 'authorizenet' replaces it: same batch, also records
         * a real provider result, and deliberately NOT amount-enforced, because its captured
         * figure and the order's final_price have not been measured against each other the way
         * Khalti's were.
         */
        foreach (['stripe', 'paypal', 'authorizenet', 'CinetPay', 'payu', 'easebuzz'] as $type) {
            $this->assertNotContains(
                strtolower($type),
                PaymentVerificationService::ENFORCED_PAYMENT_TYPES,
                'fixture assumption: this gateway must not be amount-enforced, so that what is '
                    . 'measured below is the no-record rule and not the amount comparison'
            );

            $this->assertSame(
                PaymentVerificationService::STATUS_PENDING,
                $verifier->resolveOrderPaymentStatus($type, null, '100.00', __METHOD__),
                $type . ' was recorded Paid with nothing having confirmed a capture'
            );
        }
    }

    /**
     * The nine remaining literal `Paid` writes are still present, located by CONTENT.
     *
     * This is a LOCATOR, so the next reader does not have to re-derive the list, and it fails
     * if one is fixed or a tenth appears -- which is the signal to re-read the survey in the
     * class docblock rather than trust it.
     *
     * IT NO LONGER PINS LINE NUMBERS, and that is a repair rather than a relaxation. The
     * previous version mapped each file to a line, and three of the nine shifted the moment
     * another agent added docblocks and guards ABOVE them in the same controllers
     * (Easebuzz 635->657, PaiementPro 629->657, CinetPay 720->731). That produced a 9th
     * Feature failure in a file no agent owned, on a branch an acceptance review had already
     * cleared -- a line pin in a 900-line controller is a tripwire for the next editor, not a
     * test of the property.
     *
     * Counting occurrences per file is also STRICTLY STRONGER than a line pin: a pin passes
     * when a TENTH literal write is added elsewhere in a surveyed file, and this does not.
     *
     * The paths are deliberately not edited here: every one belongs to another workstream.
     */
    public function test_the_surveyed_literal_paid_writes_are_still_present(): void
    {
        $base = base_path();

        /*
         * NINE -> THREE. SIX OF THE SURVEYED LITERALS ARE GONE, and this is the reduction the
         * survey existed to make visible rather than a weakening of it.
         *
         * P01-COMMERCE-SEC-01 wired the six storefront gateway-return handlers --
         * AuthorizeNetPaymnet, CinetPay, Easebuzz, KhaltiPaymnet, PaiementProPayment and
         * PayUPayment -- to resolveOrderPaymentStatus() through
         * App\Http\Controllers\Concerns\GuardsGatewayReturn::gatewayReturnPaymentStatus(). The
         * survey above anticipated exactly this and said why it had not been done: "Their files
         * belong to another agent in this phase, so none was changed." That agent is the one who
         * made this change.
         *
         * NO BEHAVIOUR CHANGED when they were wired: none of those payment types is in
         * ENFORCED_PAYMENT_TYPES, so the resolver returns the same 'Paid' the literal did. What
         * changed is that each write is now LOGGED as unverified, and that enforcing one of them
         * becomes a one-line change in the service instead of another edit to six controllers.
         *
         * THE THREE THAT REMAIN are untouched because they are still another workstream's:
         * NMIPayController and PosController are not gateway-return handlers in this package's
         * scope, and the LotteryPrizes one is vendored module code under the modification guard.
         *
         * The per-file counting is kept exactly as the original docblock argues for -- it is
         * strictly stronger than a line pin, and it still fails if a tenth literal appears in any
         * surveyed file.
         */
        /*
         * THREE -> TWO. P01-COMMERCE-SEC-02 wired the NMI literal to the resolver, and this row
         * moves from $sites to $wired rather than being deleted -- which is what the failure
         * message this assertion prints asks the next agent to do.
         *
         * NMI IS THE FIRST OF THE NINE WITH A REAL VERIFICATION BEHIND IT. The docblock above
         * records "no verification call of any kind in the file. ... No seam to extend." That was
         * true of the FILE and not of the HANDLER: getNMIProductStatus() makes the capture itself
         * and its order write was already nested inside NMI's own `response == 1`. So the seam
         * was the success test, and the fix was to RECORD that answer through
         * PaymentVerificationService::recordVerification() before deriving the status from it.
         * The derived status is still 'Paid' today -- 'nmi' is not in ENFORCED_PAYMENT_TYPES --
         * but it is the first one that is attributable to a gateway rather than asserted.
         *
         * PosController and the vendored LotteryPrizes one are unchanged and still out of scope:
         * neither is a gateway path at all, and the second is under the WorkDo modification guard.
         */
        // file => how many literal `payment_status = 'Paid'` writes it is surveyed to hold.
        $sites = [
            'app/Http/Controllers/PosController.php' => 1,
            'packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryResultController.php' => 1,
        ];

        // The ones now wired to the resolver must hold NO literal write at all.
        $wired = [
            'app/Http/Controllers/AuthorizeNetPaymnetController.php',
            'app/Http/Controllers/PayUPaymentController.php',
            'app/Http/Controllers/KhaltiPaymnetController.php',
            'app/Http/Controllers/EasebuzzController.php',
            'app/Http/Controllers/PaiementProPaymentController.php',
            'app/Http/Controllers/CinetPayController.php',
            'app/Http/Controllers/NMIPayController.php',
        ];

        $this->assertCount(2, $sites, 'two surveyed sites remain outside this package');
        $this->assertSame(2, array_sum($sites), 'two literal Paid writes remain');

        $pattern = "/payment_status['\"]?\s*(=>|=)\s*['\"]Paid['\"]/";

        foreach ($sites as $relative => $expected) {
            $path = $base . '/' . $relative;

            $this->assertFileExists($path);

            $found = [];
            foreach (file($path) as $i => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = $i + 1;
                }
            }

            $this->assertCount(
                $expected,
                $found,
                $relative . ' holds ' . count($found) . ' literal Paid write(s), surveyed as '
                . $expected . ' (found at line(s) ' . (implode(', ', $found) ?: 'none') . '). '
                . 'If one was FIXED, good -- update the survey in this class\'s docblock and '
                . 'this list; do not just delete the row. If one was ADDED, that is a new '
                . 'hardcoded Paid write and it needs the derivation helper instead.'
            );
        }

        /*
         * AND THE SIX ARE WIRED, asserted both ways: no literal write left, and the shared seam
         * actually called. Either half alone would be satisfiable without the other -- a file can
         * lose its literal by having the whole branch deleted, and it can gain the call while
         * keeping a second literal elsewhere.
         */
        foreach ($wired as $relative) {
            $path = $base . '/' . $relative;
            $this->assertFileExists($path);

            $contents = file_get_contents($path);

            $literals = [];
            foreach (file($path) as $i => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $literals[] = $i + 1;
                }
            }

            $this->assertSame([], $literals,
                $relative . ' still writes payment_status = \'Paid\' as a literal, at line(s) '
                . implode(', ', $literals) . '. It is wired to the resolver, so a literal beside '
                . 'that call is a second, unverified path to Paid.');

            $this->assertStringContainsString('gatewayReturnPaymentStatus(', $contents,
                $relative . ' does not call the shared payment-status seam, so its order writer '
                . 'derives nothing and an ENFORCED_PAYMENT_TYPES entry could not affect it.');
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

            /*
             * COMMENTS ARE STRIPPED BEFORE ANYTHING IS MATCHED, and this is load-bearing rather
             * than tidy.
             *
             * This scan is a source-grep ratchet, and CLAUDE.md's standing rule for one is "strip
             * comment lines ... a docblock quoting the string a ratchet greps for makes the ratchet
             * satisfiable by prose". It bit HERE in the mirror-image form: a docblock in
             * PaiementProPaymentController explaining why that handler no longer records a
             * verification contains the words `recordVerification() ran BEFORE ...`, and the
             * non-literal probe below -- `recordVerification\(\s*(?![\'"])\S` -- matched the
             * ` ran` after the empty parentheses and reported the file as an unresolvable CALL.
             * There is no call in that file at all any more.
             *
             * So prose could FAIL this ratchet as easily as satisfy it, and in both directions the
             * fix is the same: look only at code. Stripping comments cannot weaken the guard --
             * `recordVerification(` inside a comment was never a call -- and it stops a payment
             * type that is merely NAMED in a docblock from counting as enrolled.
             */
            $source = implode('', array_map(
                static fn ($token) => is_array($token)
                    ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                    : $token,
                token_get_all(file_get_contents($file->getPathname()))
            ));

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
