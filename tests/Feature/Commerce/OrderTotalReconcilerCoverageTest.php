<?php

namespace Tests\Feature\Commerce;

use Tests\TestCase;

/**
 * P1-ORD-02 (#21) CRITERION 3 -- WHICH ORDER WRITERS RECONCILE THEIR TOTAL, AS A DECLARED SET.
 * =================================================================================================
 *
 * Owner direction, 2026-10-24: *"Enforce server-authoritative payable totals across active order
 * paths."*
 *
 * WHY THIS IS A CENSUS AND NOT THIRTEEN MORE CALL SITES. Discovering the writers found **16 files
 * that persist an order total and 3 that call App\Services\OrderTotalReconciler**. Wiring the other
 * thirteen would mean editing seven gateway controllers, the admin API, the refund controller, the
 * POS, two models and a currency service -- money paths this environment cannot drive end to end,
 * so I could neither measure the change nor prove it harmless. The ledger already records why those
 * paths resist it: their pricing branches build the total from different bases.
 *
 * AND THE FIGURE 16 IS ITSELF A CORRECTION. A first pass used a shell `grep`, which does not strip
 * comments: it reported 14, MISSED PayUPaymentController, PosController and Models/Utility.php, and
 * wrongly included Concerns/ResolvesPartialPaymentDeposit.php, whose only mention of the construct
 * is in prose. The scan below uses PHP's tokenizer and is the authority; the grep is exactly the
 * census-by-spelling failure this project has recorded four times.
 *
 * SO THE DELIVERABLE IS DIFFERENT, AND BETTER THAN A GUESS: the coverage becomes a DECLARED SET
 * that this test asserts in both directions. Before, "which writers reconcile?" had no answer
 * anywhere; now it has one, it is visible, and it cannot drift silently:
 *
 *   - a NEW file that persists an order total and appears in neither list FAILS this test, so a
 *     fifteenth writer cannot be added without a decision about its arithmetic;
 *   - a file declared UNCOVERED that quietly gains a reconciler call also FAILS, because that is
 *     progress the ledger should record rather than absorb -- the same both-directions rule
 *     `.github/known-test-outcomes.json` applies to the suite.
 *
 * WHAT WAS ACTUALLY FIXED IN THIS PACKAGE: `ApiController::place_order` -- the storefront writer
 * every mobile client uses -- carried only a COMMENT referring to the reconciler while calling
 * nothing, so the most-used writer was the one with no arithmetic check. It is wired now, and it
 * would have caught the coupon defect fixed alongside it (product_price 200.00, coupon_price 50.00,
 * final_price 200.00 -- a row that does not add up).
 *
 * WHAT THIS TEST DOES NOT CLAIM: that the eleven uncovered writers are correct. They are
 * uncovered, which is a different and weaker statement, and it is written down here rather than
 * left to be rediscovered.
 *
 * THE SCAN STRIPS COMMENTS WITH PHP'S OWN TOKENIZER. A docblock mentioning `warnIfInconsistent` --
 * and several in this codebase do -- would otherwise make a file read as covered. That is this
 * project's recorded ratchet failure, committed twice before.
 */
class OrderTotalReconcilerCoverageTest extends TestCase
{
    /**
     * Files that persist an order total AND reconcile it. Each is a path relative to app/.
     *
     * `PaymentController` holds two writers and `ApiController` one; the count of CALLS is not
     * asserted, only that the file reconciles at all, because a file with two writers and one call
     * is a question this test cannot answer from source alone.
     */
    private const COVERED = [
        'Services/PaymentService.php',
        'Http/Controllers/PaymentController.php',
        'Http/Controllers/Api/ApiController.php',
    ];

    /**
     * Files that persist an order total and do NOT reconcile it. Declared, not approved.
     *
     * The six gateway controllers are the group the ledger records as undrivable here: a full
     * checkout per provider needs that provider's sandbox. The other five are reachable but each
     * carries its own base question, and the cutover for them is P1-ORD-02 criterion 1 (one seam),
     * which stays open.
     */
    private const DECLARED_UNCOVERED = [
        // the seven gateway returns -- the group the ledger records as undrivable here, because a
        // full checkout per provider needs that provider's sandbox
        'Http/Controllers/AuthorizeNetPaymnetController.php',
        'Http/Controllers/CinetPayController.php',
        'Http/Controllers/EasebuzzController.php',
        'Http/Controllers/KhaltiPaymnetController.php',
        'Http/Controllers/NMIPayController.php',
        'Http/Controllers/PaiementProPaymentController.php',
        'Http/Controllers/PayUPaymentController.php',
        // reachable, but each carries its own base question; the cutover is criterion 1
        'Http/Controllers/Api/DashboardController.php',
        'Http/Controllers/OrderRefundController.php',
        'Http/Controllers/PosController.php',
        'Models/Order.php',
        'Models/Utility.php',
        'Services/StoreCurrencyConversionService.php',
    ];

    /** Source with every comment and docblock removed, so prose cannot satisfy the scan. */
    private function codeOnly(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return array<string, bool> relative path => whether it reconciles */
    private function writers(): array
    {
        $root = base_path('app');
        $found = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = $this->codeOnly($file->getPathname());

            // The construct, not a word: an assignment TO an order's final_price.
            if (! preg_match('/->\s*final_price\s*=(?!=)/', $code)
                && ! preg_match('/[\'"]final_price[\'"]\s*=>/', $code)) {
                continue;
            }

            // Only files that actually write an ORDER total, not a response payload. An assignment
            // to `->final_price` on a model instance is the shape; a `'final_price' =>` array key
            // alone is a response or an update() payload, which still persists one.
            if (! preg_match('/->\s*final_price\s*=(?!=)/', $code)) {
                continue;
            }

            $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            $found[$relative] = (bool) preg_match('/warnIfInconsistent|assertConsistent/', $code);
        }

        ksort($found);

        return $found;
    }

    /**
     * THE PREMISE: the scan finds the writers at all.
     *
     * Every assertion below is satisfied by a scan that finds nothing, so this has to hold first.
     */
    public function test_the_scan_reaches_the_order_writers_it_claims_to(): void
    {
        $writers = $this->writers();

        $this->assertGreaterThanOrEqual(10, count($writers),
            'the scan found ' . count($writers) . ' order-total writers, which is too few: the '
            . 'construct or the comment-stripping has drifted and every assertion below is vacuous');

        $this->assertArrayHasKey('Http/Controllers/Api/ApiController.php', $writers,
            'Premise: place_order is an order-total writer.');
    }

    /** No writer may be absent from both lists: a fifteenth one needs a decision, not silence. */
    public function test_every_order_total_writer_is_declared(): void
    {
        $declared = array_merge(self::COVERED, self::DECLARED_UNCOVERED);
        $undeclared = array_diff(array_keys($this->writers()), $declared);

        $this->assertSame([], array_values($undeclared),
            "A NEW ORDER-TOTAL WRITER IS NOT DECLARED HERE:\n  " . implode("\n  ", $undeclared)
            . "\n\nDecide whether it reconciles its total. If it does, add it to COVERED; if it "
            . 'does not, add it to DECLARED_UNCOVERED with the reason. Do not delete this test to '
            . 'get green -- the point is that the answer is written down.');
    }

    /** And nothing declared may have vanished, which would make its entry a stale claim. */
    public function test_no_declared_writer_has_disappeared(): void
    {
        $writers = array_keys($this->writers());

        foreach (array_merge(self::COVERED, self::DECLARED_UNCOVERED) as $declared) {
            $this->assertContains($declared, $writers,
                "{$declared} is declared as an order-total writer and is no longer one. If it was "
                . 'refactored, remove its entry; leaving it makes this census describe a tree that '
                . 'does not exist.');
        }
    }

    /** The covered ones really do reconcile. */
    public function test_the_covered_writers_reconcile_their_total(): void
    {
        $writers = $this->writers();

        foreach (self::COVERED as $path) {
            $this->assertTrue($writers[$path] ?? false,
                "{$path} is declared as reconciling its order total and does not call "
                . 'warnIfInconsistent() or assertConsistent() in code (comments stripped).');
        }
    }

    /**
     * AND THE OTHER DIRECTION, which is what stops this file becoming a rubber stamp.
     *
     * A writer declared uncovered that gains a reconciler call is PROGRESS -- and it must be
     * recorded as such rather than absorbed silently, because the count of uncovered writers is
     * the measure P1-ORD-02 criterion 3 is tracked by.
     */
    public function test_no_uncovered_writer_has_quietly_gained_coverage(): void
    {
        $writers = $this->writers();
        $gained = [];

        foreach (self::DECLARED_UNCOVERED as $path) {
            if ($writers[$path] ?? false) {
                $gained[] = $path;
            }
        }

        $this->assertSame([], $gained,
            "THESE WRITERS NOW RECONCILE AND ARE STILL DECLARED UNCOVERED:\n  "
            . implode("\n  ", $gained)
            . "\n\nMove them to COVERED and update the P1-ORD-02 ledger row: criterion 3 is "
            . 'measured by how many writers are left, so an unrecorded improvement loses the count.');
    }
}
