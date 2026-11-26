<?php

namespace Tests\Unit\Commerce;

use App\Models\Order;
use Tests\TestCase;

/**
 * GAP-140 / `P04-P6-04` -- THE TWO PROTOCOL PRIMITIVES THE REFUND DOOR NOW SHARES, with no
 * database and no HTTP in the way.
 *
 * The Feature twin (`tests/Feature/Commerce/RefundStockCrossDoorTest`) drives the real doors and is
 * the evidence that the double credit was live: 16 units where 13 were due, measured at HEAD. This
 * file asserts the two things that file cannot show directly.
 *
 *   1. `creditableReturnQty()` -- "how many of this line's units may a return door credit". The
 *      answer for a line a refund has emptied is 0, and a door that credits 0 units must NOT then
 *      stamp `LINE_STOCK_RESTORED`: the marker means "these goods are already back in" and every
 *      return, cancel and refund surface reads it as evidence, so a false claim there is what
 *      stops the units the line really lost from ever coming back. It also answers 0 for the
 *      untyped shapes a JSON blob can hold, which is the same rule the two sibling doors already
 *      apply by hand.
 *
 *   2. `linesWithStockRestoredStamp()` -- how the marker is set, shared by the doors that set it.
 *      It stamps only the keys it is given, never a line that is already stamped, and answers null
 *      when nothing would change, so a door that credited nothing writes nothing at all: no
 *      marker, no `updated_at` bump and no observer pass.
 *
 * The selection rule stays with each door, deliberately: the cancel loop resolves
 * (product_id, variant_id) pairs because `Order::order_detail()` hands it a derived copy of the
 * lines, while the refund door asks which lines its credit left with nothing outstanding. Those are
 * different questions over the same column, and each door persists the answer under its own
 * freshness rules -- so only the DECISION is shared.
 *
 * `Order::lineStockAlreadyRestored()` is asserted here too, because the whole protocol rests on the
 * two of them agreeing about one stored key.
 */
class OrderReturnMarkerHonestyTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function lineQuantities(): array
    {
        return [
            'a line a full refund has emptied' => [['qty' => 0, 'refunded_qty' => 3], 0],
            'a line with units still on it' => [['qty' => 3], 3],
            'a partially refunded line' => [['qty' => 1, 'refunded_qty' => 2], 1],
            'a numeric string, as JSON stores it' => [['qty' => '2'], 2],
            'a negative quantity cannot subtract stock' => [['qty' => -5], 0],
            'a non-numeric quantity is not coerced silently' => [['qty' => 'two'], 0],
            'a null quantity' => [['qty' => null], 0],
            'an array quantity cannot reach an (int) cast' => [['qty' => [3]], 0],
            'no qty key at all' => [['product_id' => 7], 0],
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lineQuantities')]
    public function test_the_creditable_quantity_of_a_line(array $line, int $expected): void
    {
        $this->assertSame($expected, Order::creditableReturnQty($line));
    }

    public function test_a_line_a_refund_has_emptied_is_not_creditable_although_it_lost_units(): void
    {
        // The shape the storefront refund request leaves behind: the units are off `qty` and
        // recorded in `refunded_qty`, and the refund door is the one that owes them.
        $line = ['product_id' => 7, 'qty' => 0, 'refunded_qty' => 3];

        $this->assertSame(0, Order::creditableReturnQty($line));
        $this->assertFalse(
            Order::lineStockAlreadyRestored($line),
            'an emptied line must not read as already restored, or the refund door would credit nothing'
        );
    }

    public function test_the_marker_is_read_off_the_one_stored_key(): void
    {
        $this->assertSame('stock_restored', Order::LINE_STOCK_RESTORED);
        $this->assertTrue(Order::lineStockAlreadyRestored([Order::LINE_STOCK_RESTORED => 1]));
        $this->assertTrue(Order::lineStockAlreadyRestored([Order::LINE_STOCK_RESTORED => '1']));
        $this->assertFalse(Order::lineStockAlreadyRestored([Order::LINE_STOCK_RESTORED => 0]));
        $this->assertFalse(Order::lineStockAlreadyRestored([]));
    }

    public function test_the_writer_stamps_only_the_lines_it_is_given(): void
    {
        $stamped = Order::linesWithStockRestoredStamp([
            ['product_id' => 1, 'qty' => 0],
            ['product_id' => 2, 'qty' => 0],
        ], [1]);

        $this->assertIsArray($stamped);
        $this->assertFalse(Order::lineStockAlreadyRestored($stamped[0]), 'a line nobody credited was stamped');
        $this->assertTrue(Order::lineStockAlreadyRestored($stamped[1]));
    }

    public function test_the_writer_does_not_stamp_a_line_that_is_already_stamped(): void
    {
        $this->assertNull(
            Order::linesWithStockRestoredStamp(
                [['product_id' => 1, 'qty' => 0, Order::LINE_STOCK_RESTORED => 1]],
                [0]
            ),
            'an already-stamped line was reported as a change, so the column is rewritten for nothing'
        );
    }

    public function test_the_writer_writes_nothing_when_there_is_nothing_to_stamp(): void
    {
        $lines = [['product_id' => 1, 'qty' => 0]];

        $this->assertNull(Order::linesWithStockRestoredStamp($lines, []), 'an empty key list stamped something');
        $this->assertNull(Order::linesWithStockRestoredStamp($lines, [9]), 'a key that names no line stamped something');
        $this->assertNull(Order::linesWithStockRestoredStamp([], [0]), 'an order with no lines stamped something');
    }

    /**
     * A key is matched as a STRING, because `json_decode` of an object-shaped `product_json`
     * ("0", "1", ...) and of a list (0, 1, ...) hand this method different key types for the same
     * stored column, and `StoreCurrencyConversionService` records that non-list shapes exist.
     */
    public function test_a_string_keyed_line_is_matched_like_a_list_one(): void
    {
        $stamped = Order::linesWithStockRestoredStamp(['0' => ['product_id' => 1, 'qty' => 0]], [0]);

        $this->assertIsArray($stamped);
        $this->assertTrue(Order::lineStockAlreadyRestored($stamped['0']));
    }

    /**
     * A NON-ARRAY LINE IS SKIPPED RATHER THAN RAISING, which is not hypothetical: the return
     * workflow's own shape test exists because `product_json` holds shapes this code cannot read.
     */
    public function test_a_line_that_is_not_an_array_is_skipped(): void
    {
        $this->assertNull(Order::linesWithStockRestoredStamp(['not a line'], [0]));
    }

    /**
     * A STAMPED LINE KEEPS EVERYTHING ELSE IT CARRIED. The column is the order's lines, not a flag
     * store: a writer that dropped `refunded_qty` while adding the marker would take the refund
     * door's own allowance with it.
     */
    public function test_a_stamped_line_keeps_its_other_keys(): void
    {
        $stamped = Order::linesWithStockRestoredStamp([
            ['product_id' => 7, 'variant_id' => 3, 'qty' => 0, 'refunded_qty' => 2, 'final_price' => 10.5, 'return' => 1],
        ], [0]);

        $this->assertIsArray($stamped);
        $this->assertSame([
            'product_id' => 7,
            'variant_id' => 3,
            'qty' => 0,
            'refunded_qty' => 2,
            'final_price' => 10.5,
            'return' => 1,
            Order::LINE_STOCK_RESTORED => 1,
        ], $stamped[0]);
    }
}
