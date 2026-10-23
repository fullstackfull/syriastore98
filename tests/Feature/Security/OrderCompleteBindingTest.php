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
     * EVERY GATEWAY RETURN THAT FLASHES THE NUMBER ALSO BINDS THE PRIMARY KEY.
     *
     * Keyed on the two constructs together, per file: a `->with('data', ...)` redirect to
     * `order.complete` and a `OrderCompleteBinding::remember(` call. Comments are stripped first --
     * a docblock naming either would otherwise satisfy this, which is the trap this project records
     * for source ratchets.
     *
     * The count is EXACT. A floor would let one of PaymentController's three sites lose its binding
     * unnoticed.
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
             * FIVE, and the count is worth spelling out because the first version of this test said
             * six and failed: the file also NAMES the service in a comment, and `withoutComments()`
             * removes that before counting. The five real calls are the two Paymera replay branches
             * (holds), the Paymera pending-record read (bound), the generic storefront writer and
             * the whatsapp/telegram binding (remember).
             */
            'app/Http/Controllers/PaymentController.php' => 5,
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
