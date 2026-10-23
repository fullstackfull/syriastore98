<?php

namespace Tests\Feature\Commerce;

use ReflectionClassConstant;
use Tests\Feature\Security\SecurityTestCase;

/**
 * EVERY HANDLER THAT CAN WRITE AN ORDER FROM A STORED CHECKOUT STATE EITHER SETTLES A PENDING
 * BALANCE OR IS DECLARED AS UNABLE TO.  P1-ORD-02 criterion 2.
 * =================================================================================================
 *
 * WHY THIS FILE EXISTS. The first cut of the pending-balance fix guarded the eight sites that call
 * `PartialPayments::ManagePartialPayment()` and stopped there. A read-only review found a ninth
 * handler that reads the same stored state and writes an order and has NO partial-payment branch at
 * all: `KhaltiPaymnetController::getOrderPaymentStatus`. So `prepareFinalRequestData()` happily
 * priced a khalti checkout at the outstanding balance, khalti charged that figure, and the return
 * then wrote a FULL-PRICE order from the cart and left the balance unsettled.
 *
 * That is the fourth time in this project a completeness claim has been keyed on the wrong thing --
 * the recorded census failure mode. The remedy the skill prescribes is to key on the HANDLER rather
 * than on a list someone typed, and to make the scan fail loudly rather than silently when the
 * codebase moves. That is what this file does.
 *
 * WHAT IS MEASURED. Two sets, both derived from the source at run time:
 *
 *   WRITERS  files in app/ whose text reads a stored checkout state (`storedCheckoutState(`)
 *   SETTLERS files in app/ that call `PartialPayments::ManagePartialPayment(`
 *
 * SETTLERS must be a subset of WRITERS plus the two non-handler files that define the mechanism,
 * and every WRITER that is not a SETTLER must be named in the declared exception list, whose own
 * payment types are what `prepareFinalRequestData()` refuses. A new gateway, a removed branch or a
 * khalti that gains one all move a set and fail here with the reason attached.
 *
 * THE LIMIT, STATED. This is a TEXT census. It proves a handler carries the construct, not that the
 * construct is reached on every path through it. The behavioural half is
 * PendingPaymentAmountSourceTest, which drives the real routes.
 */
class PendingPaymentGatewayCensusTest extends SecurityTestCase
{
    /** Files that define the mechanism rather than answer a request. */
    private const MECHANISM = [
        'app/Http/Controllers/Concerns/GuardsGatewayReturn.php',
        'app/Http/Controllers/Concerns/ResolvesPendingPartialPayment.php',
        'app/Http/Controllers/Concerns/ResolvesPartialPaymentDeposit.php',
    ];

    /**
     * Handlers that read a stored checkout state and CANNOT settle a pending balance, each mapped
     * to the `payment_type` a checkout chooses it with.
     *
     * The payment type is what `prepareFinalRequestData()` refuses, so this mapping is the link
     * between the file census below and the declared constant that does the refusing.
     */
    private const DECLARED_NON_SETTLERS = [
        'app/Http/Controllers/KhaltiPaymnetController.php' => 'khalti',
    ];

    /** @return array<string> relative paths, sorted */
    private function filesContaining(string $needle): array
    {
        $root = base_path();
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $found[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        sort($found);

        return $found;
    }

    /**
     * THE PREMISE: the scan finds something. A regex or a rename that matched nothing would make
     * every assertion below vacuously true.
     */
    public function test_the_census_reaches_the_handlers_it_claims_to(): void
    {
        $writers = $this->filesContaining('storedCheckoutState(');
        $settlers = $this->filesContaining('PartialPayments::ManagePartialPayment(');

        $this->assertGreaterThanOrEqual(6, count($writers),
            'The stored-state census found almost nothing, so the comparison below would be '
            . 'vacuous. The needle, not the codebase, is the likely cause. Found: ' . count($writers));
        $this->assertGreaterThanOrEqual(6, count($settlers),
            'The settle-branch census found almost nothing. Found: ' . count($settlers));
    }

    /**
     * EVERY STORED-STATE ORDER WRITER IS CLASSIFIED: it settles, or it is declared as unable to.
     */
    public function test_every_stored_state_handler_either_settles_or_is_declared(): void
    {
        $writers = array_values(array_diff($this->filesContaining('storedCheckoutState('), self::MECHANISM));
        $settlers = array_values(array_diff($this->filesContaining('PartialPayments::ManagePartialPayment('), self::MECHANISM));

        $unclassified = array_values(array_diff($writers, $settlers, array_keys(self::DECLARED_NON_SETTLERS)));

        $this->assertSame([], $unclassified,
            "These handlers read a stored checkout state and write an order, but neither settle a\n"
            . "pending balance nor appear in DECLARED_NON_SETTLERS. A pending-balance checkout on\n"
            . "one of them is priced at the outstanding amount, charged, and then written as a\n"
            . "full-price order with the balance left owing -- which is exactly how khalti was\n"
            . "missed. Classify each one:\n  " . implode("\n  ", $unclassified));
    }

    /**
     * AND THE DECLARATION IS NOT STALE: a declared non-settler that has since gained a settle
     * branch must be removed from the list, or the refusal keeps turning away a journey that works.
     */
    public function test_no_declared_non_settler_has_quietly_gained_a_settle_branch(): void
    {
        $settlers = $this->filesContaining('PartialPayments::ManagePartialPayment(');

        foreach (array_keys(self::DECLARED_NON_SETTLERS) as $file) {
            $this->assertFileExists(base_path($file),
                "DECLARED_NON_SETTLERS names a file that no longer exists: {$file}. The declaration "
                . 'and the refusal it justifies are both stale.');

            $this->assertNotContains($file, $settlers,
                "{$file} now calls ManagePartialPayment(), so it CAN settle a balance -- but it is "
                . 'still declared as unable to, and prepareFinalRequestData() still refuses its '
                . 'payment type. Remove it from DECLARED_NON_SETTLERS and from '
                . 'ResolvesPendingPartialPayment::PENDING_PAYMENT_UNSUPPORTED_TYPES together.');
        }
    }

    /**
     * THE TWO LISTS AGREE. The file census above is what a reader can verify; the constant is what
     * actually refuses a checkout. If they drift, the refusal protects a handler that is fine and
     * leaves the one that is not.
     */
    public function test_the_refused_payment_types_are_exactly_the_declared_non_settlers(): void
    {
        $constant = new ReflectionClassConstant(
            \App\Http\Controllers\Concerns\ResolvesPendingPartialPayment::class,
            'PENDING_PAYMENT_UNSUPPORTED_TYPES'
        );
        // No setAccessible(): ReflectionClassConstant has no such method, and getValue() reads a
        // private constant regardless.
        $refused = $constant->getValue();
        sort($refused);

        $declared = array_values(self::DECLARED_NON_SETTLERS);
        sort($declared);

        $this->assertSame($declared, $refused,
            'ResolvesPendingPartialPayment::PENDING_PAYMENT_UNSUPPORTED_TYPES and this file\'s '
            . 'DECLARED_NON_SETTLERS no longer name the same payment types. One of them is wrong, '
            . 'and the one that matters at run time is the constant.');
    }

    /**
     * AND EVERY REFUSED TYPE IS A REAL ONE. A typo in the constant is a refusal that never fires.
     */
    public function test_every_refused_payment_type_is_one_the_checkout_accepts(): void
    {
        $controller = new \ReflectionMethod(\App\Http\Controllers\PaymentController::class, 'getValidPaymentTypes');
        $controller->setAccessible(true);
        $valid = $controller->invoke(app(\App\Http\Controllers\PaymentController::class));
        $validLower = array_map('strtolower', $valid);

        foreach (self::DECLARED_NON_SETTLERS as $file => $type) {
            $this->assertContains(strtolower($type), $validLower,
                "DECLARED_NON_SETTLERS maps {$file} to payment type '{$type}', which "
                . 'getValidPaymentTypes() does not accept -- so the refusal in '
                . 'prepareFinalRequestData() can never fire and the handler is unprotected.');
        }
    }
}
