<?php

namespace Tests\Feature\Security;

use App\Services\OrderCompleteBinding;
use Tests\TestCase;

/**
 * P1-ORD-13 -- WHICH ORDERS A SESSION MAY SEE ON THE ANONYMOUS THANK-YOU PAGE.
 * =============================================================================================
 *
 * `orders.product_order_id` is `'0' . date('YmdHis')` for a guest, unindexed and not unique, so two
 * orders placed in one store in one second share it. Resolving the flashed NUMBER therefore had two
 * possible answers and both were wrong: `first()` showed one buyer the OTHER'S order (measured,
 * review lens E, P8), and P1-ORD-08's refusal sent BOTH buyers back to the landing page having just
 * paid. The order's PRIMARY KEY is the identifier, and this service is how the buyer's own session
 * comes to hold it.
 *
 * TWO THINGS THIS FILE MEASURES that the end-to-end tests cannot:
 *
 *  1. THE PARSE. A mutant replacing FILTER_VALIDATE_INT with `(int) $value ?: null` SURVIVED the
 *     end-to-end suite, because no request there carries an odd-shaped binding. The parse is what
 *     stands between a session value and a bare `whereIn('id', ...)`, and the session store is not
 *     a typed column.
 *  2. THE TEN CALL SITES. A mutant deleting the binding from one gateway return SURVIVED too:
 *     driving nine gateway returns end to end needs nine live providers, which this environment has
 *     not got. The census below is what keeps the sites from drifting, and it is keyed on the
 *     construct rather than on a comment.
 */
class OrderCompleteBindingTest extends TestCase
{
    public function test_a_remembered_id_is_held_and_a_stranger_id_is_not(): void
    {
        $this->startSession();

        OrderCompleteBinding::remember(7);

        $this->assertTrue(OrderCompleteBinding::holds(7));
        $this->assertTrue(OrderCompleteBinding::holds('7'), 'a session driver round-trip may store it as a string');
        $this->assertFalse(OrderCompleteBinding::holds(8));
        $this->assertSame([7], OrderCompleteBinding::bound());
    }

    /**
     * THE PARSE, in both directions. FILTER_VALIDATE_INT and not a cast, which is this project's
     * recorded rule: MySQL coerces '7abc' to 7 in a `WHERE id = ?` comparison, so a value the guard
     * reads as nothing and the engine reads as an id is not a guard at all.
     *
     * @dataProvider refusedProvider
     */
    public function test_a_value_that_is_not_an_exact_positive_integer_is_refused($value, string $why): void
    {
        $this->startSession();

        OrderCompleteBinding::remember($value);

        $this->assertSame([], OrderCompleteBinding::bound(), $why);
        $this->assertFalse(OrderCompleteBinding::holds($value), $why);
    }

    public static function refusedProvider(): array
    {
        return [
            ['7abc', "'7abc' is row 7 to MySQL, so a cast would admit it"],
            ['7.0', 'a decimal is not an integer id'],
            ['7e0', "(int) '7e0' is 7, so a cast would admit it"],
            ['0', 'there is no order 0'],
            ['-7', 'a negative id'],
            ['', 'empty'],
            [null, 'nothing'],
            [[7], 'an array'],
            [true, 'a boolean casts to 1 and would name order 1'],
        ];
    }

    /** A poisoned session value is ignored on the way OUT as well as in. */
    public function test_a_session_value_written_by_something_else_is_sanitised_on_read(): void
    {
        $this->startSession();

        session()->put(OrderCompleteBinding::SESSION_KEY, [5, '6', '7abc', 0, -1, null, ['x'], true, '8']);

        $this->assertSame([5, 6, 8], OrderCompleteBinding::bound(),
            'A value that is not an exact positive integer reached the id list, where it becomes a '
            . 'whereIn() argument.');
    }

    /** The list is capped, or a long-lived session grows without bound. */
    public function test_the_list_is_capped_and_keeps_the_most_recent(): void
    {
        $this->startSession();

        foreach (range(1, 25) as $id) {
            OrderCompleteBinding::remember($id);
        }

        $bound = OrderCompleteBinding::bound();

        $this->assertCount(20, $bound);
        $this->assertContains(25, $bound, 'the most recent order must survive the cap');
        $this->assertNotContains(1, $bound, 'the oldest must be dropped');
    }

    /** Remembering the same order twice does not grow the list. */
    public function test_remembering_the_same_order_twice_is_idempotent(): void
    {
        $this->startSession();

        OrderCompleteBinding::remember(9);
        OrderCompleteBinding::remember(9);

        $this->assertSame([9], OrderCompleteBinding::bound());
    }

    /**
     * EVERY PRODUCER OF THE THANK-YOU PAGE IS DISCOVERED, AND EACH ONE BINDS.
     *
     * TWO ASSERTIONS, BECAUSE THE FIRST CUT OF THIS TEST HAD ONLY THE SECOND AND ITS DOCBLOCK
     * CLAIMED BOTH. It said it was "keyed on the two constructs together" and then counted
     * `OrderCompleteBinding::` calls against a HARD-CODED FILE LIST -- so a new file that sends a
     * shopper to `order.complete` without binding was not detected at all, and the list itself was
     * the only census. That is recorded in this project's skill as the way censuses fail: keyed on
     * the spelling somebody happened to look at, not on the thing being counted. The tenant-authz
     * review of the first cut found it, and found the one real gap it was hiding: Khalti.
     *
     *   (1) DISCOVERY. Every file under app/ that names `order.complete` after comments are
     *       stripped must either carry an OrderCompleteBinding call or be listed below with the
     *       reason it needs none. A new gateway return fails this until somebody decides which.
     *   (2) DRIFT. The per-file counts, exact. A floor would let one of PaymentController's sites
     *       lose its binding unnoticed.
     *
     * Comments are stripped for both -- a docblock naming either construct would otherwise satisfy
     * them, which is this project's recorded trap for source ratchets. It is also what keeps
     * app/Events/CinetPayPayment.php out of the discovered set: it names `order.complete` only in
     * prose.
     */
    public function test_every_producer_of_the_thank_you_page_is_discovered_and_binds(): void
    {
        /** Files that reach the thank-you page but write no order, so there is nothing to bind. */
        $noOrderWritten = [];

        $discovered = [];

        foreach ($this->phpFilesUnder(app_path()) as $absolute) {
            $code = $this->withoutComments((string) file_get_contents($absolute));

            if (str_contains($code, 'order.complete') || str_contains($code, 'khalti_order_id')) {
                $relative = ltrim(str_replace(base_path(), '', $absolute), '/');

                if (! in_array($relative, $noOrderWritten, true)) {
                    $discovered[] = $relative;
                }
            }
        }

        sort($discovered);

        $this->assertGreaterThanOrEqual(9, count($discovered),
            'The discovery scan found almost nothing, so the assertion below would be vacuous. The '
            . 'scan, not the codebase, is the likely cause. Found: ' . implode(', ', $discovered));

        $unbound = [];

        foreach ($discovered as $relative) {
            $code = $this->withoutComments((string) file_get_contents(base_path($relative)));

            if (preg_match('/OrderCompleteBinding::(remember|holds|bound)\s*\(/', $code) !== 1) {
                $unbound[] = $relative;
            }
        }

        $this->assertSame([], $unbound,
            "These files send a shopper to the thank-you page and bind no primary key, so a "
            . "same-second collision in one store is unresolvable for them -- the buyer is refused "
            . "the order they just paid for:\n  " . implode("\n  ", $unbound));

        // OrderController is the page's READER, discovered because it reads khalti_order_id.
        $this->assertContains('app/Http/Controllers/OrderController.php', $discovered);
        $this->assertContains('app/Http/Controllers/KhaltiPaymnetController.php', $discovered,
            'Khalti was the one producer with no binding; it must stay in the discovered set.');
    }

    /** @return list<string> */
    private function phpFilesUnder(string $root): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The per-file counts, exact, so a site cannot quietly lose its binding while the file keeps one
     * elsewhere. This is the DRIFT half; discovery is the test above.
     */
    public function test_every_gateway_return_binds_the_order_it_flashes(): void
    {
        $expected = [
            'app/Services/PaymentService.php' => 1,
            'app/Http/Controllers/AuthorizeNetPaymnetController.php' => 1,
            'app/Http/Controllers/PayUPaymentController.php' => 1,
            'app/Http/Controllers/NMIPayController.php' => 1,
            'app/Http/Controllers/EasebuzzController.php' => 1,
            'app/Http/Controllers/PaiementProPaymentController.php' => 1,
            'app/Http/Controllers/CinetPayController.php' => 1,
            /*
             * SIX, and the count is worth spelling out because the first version of this test said
             * six when the real number was five and failed: the file also NAMES the service in a
             * comment, and `withoutComments()` removes that before counting. The calls are the two
             * Paymera replay branches (holds), the generic storefront writer and the
             * whatsapp/telegram binding (remember), and bound() TWICE in orderWrittenBy() -- the
             * before/after pair that names the order a write created without trusting "the latest in
             * the store". SESSION_KEY is referenced there too and is deliberately NOT counted: the
             * pattern asks for calls, and a constant read is not one.
             */
            'app/Http/Controllers/PaymentController.php' => 6,
            // P1-ORD-13 FOLLOW-UP: the one provider that had none (the buyer's order is written in
            // the buyer's own session here, so the key is recordable exactly as at the other nine).
            'app/Http/Controllers/KhaltiPaymnetController.php' => 1,
            // the page's READER: orderCompleteBoundIds() is the single place the ids are read back.
            'app/Http/Controllers/OrderController.php' => 1,
        ];

        foreach ($expected as $relative => $count) {
            $code = $this->withoutComments((string) file_get_contents(base_path($relative)));

            $this->assertSame(
                $count,
                preg_match_all('/OrderCompleteBinding::(remember|holds|bound)\s*\(/', $code),
                "{$relative} does not carry the expected number of OrderCompleteBinding calls. A "
                . 'gateway return that flashes the order number without binding its primary key '
                . 'leaves the same-second collision unresolvable for that provider.'
            );
        }
    }

    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
