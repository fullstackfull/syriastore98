<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `Order::product_return()` CRASHES ON A SIBLING LINE IT DOES NOT EVEN TOUCH -- AFTER IT HAS
 * ALREADY WRITTEN AND SAVED THE RETURN FLAG.
 * =================================================================================================
 *
 * FOUND WHILE BUILDING THE GAP-135 FIXTURE, in the same class as GAP-247: a 500 on an ordinary
 * stored data shape that no test had ever constructed.
 *
 * The method's own precondition, `Order::linesAreReturnable()` (app/Models/Order.php:1035-1048),
 * requires `product_id` and `qty` to EXIST on every line. It does not require `qty` to be a
 * number, and it says nothing about `return` or `final_price`. Three reads downstream assume more
 * than that, and all three are in the SUMMATION loop that runs AFTER the flag write at :1400-1401:
 *
 *     $qty += $product['qty'];                 // :1411  TypeError on a non-numeric qty (PHP 8)
 *     if ($product['return'] == 1) {           // :1412  undefined key on a line with no flag
 *         $rp[] = $product['final_price'];     // :1413  undefined key on a flagged line with none
 *
 * WHY THE ORDERING MAKES IT WORSE THAN A 500. `$Order->product_json` is written with this call's
 * `return => 1` and SAVED at :1400-1401, before the loop. The money write is at the end. So a
 * throw in between leaves the line permanently marked returned with no `return_price` and no
 * `final_price` adjustment -- and the replay guard at :1144-1155 then refuses every retry with
 * "The product has already been returned." The goods are recorded as returned and the customer is
 * never credited, with no UI-reachable recovery.
 *
 * AND `$qty` AND `$rp` ARE DEAD. Measured over the whole method: both are assigned in that loop
 * and read nowhere, by anything. The TypeError risk exists purely to compute two values nobody
 * uses.
 *
 * THE OTHER HALF IS AN AGREEMENT THIS FILE PINS. `returnedAmountOf()` (:1064-1069) is documented
 * as "THE SAME FORMULA AS THE SUMMATION IN product_return()", with `SecondReturnTotalTest`
 * asserting the two cannot drift -- and it already reads `(float) ($line['final_price'] ?? 0)`.
 * On a flagged line with no `final_price` the two therefore disagreed in the worst possible way:
 * one answered 0 and the other raised.
 *
 * REACHABILITY, stated rather than implied. Every shipped order writer builds its lines through
 * `Cart::cart_list`'s `product_list`, which always sets `qty`, `final_price` and `return`
 * (app/Models/Cart.php:555-620), and so does `ProductController::addToCart` (:5088-5103). So this
 * is LATENT for those writers, exactly like GAP-247 -- and the same three re-writers of a stored
 * `product_json` are not all so careful: `StoreCurrencyConversionService:161-163` has a branch for
 * a `product_json` that decoded to a SINGLE associative line rather than a list, which is evidence
 * that non-canonical shapes exist in real data. It is fixed as a crash class, not as a live
 * incident, and this docblock says which.
 */
class OrderReturnMalformedLineTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** `delivered_status = 1`; `product_return()` refuses anything else (app/Models/Order.php:1064). */
    private const DELIVERED = 1;

    /**
     * PREMISE. A two-line order, both lines well formed, returning one of them works and moves the
     * money. Without this every case below could pass because the fixture never reached the loop.
     */
    public function test_premise_a_well_formed_two_line_order_returns_one_line_and_settles_the_money(): void
    {
        [$store, $order, $good, ] = $this->twoLineOrder([
            'qty' => 1,
            'final_price' => 40.0,
            'return' => 0,
        ]);

        $answer = Order::product_return([
            'order_id' => $order->id,
            'product_id' => $good->id,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $answer['status'] ?? null,
            'the premise return was refused, so nothing below reaches the summation loop. Answer: '
            . json_encode($answer));

        $this->assertSame(40.0, (float) $this->column($order, 'return_price'),
            'the returned line did not settle at its own value. ' . $this->snapshot($order));

        $this->assertSame(60.0, (float) $this->column($order, 'final_price'),
            'final_price was not reduced by the returned line. ' . $this->snapshot($order));
    }

    /**
     * CASE 1 -- A NON-NUMERIC `qty` ON A LINE THIS CALL DOES NOT TOUCH.
     *
     * `linesAreReturnable()` admits it (the key exists) and the restock casts it, so the only thing
     * that ever looked at it as a number was the dead accumulator. Today: `TypeError: Unsupported
     * operand types: int + string`, AFTER the flag was saved.
     */
    public function test_a_sibling_line_with_a_non_numeric_quantity_does_not_break_the_return(): void
    {
        [, $order, $good, ] = $this->twoLineOrder([
            'qty' => 'two',
            'final_price' => 40.0,
            'return' => 0,
        ]);

        $answer = Order::product_return([
            'order_id' => $order->id,
            'product_id' => $good->id,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $answer['status'] ?? null,
            "a sibling line's non-numeric qty broke the return of a different, well-formed line. "
            . 'Answer: ' . json_encode($answer));

        $this->assertSettled($order, 60.0, 40.0);
    }

    /**
     * CASE 2 -- A SIBLING LINE WITH NO `return` KEY AT ALL.
     *
     * The replay guard above reads `($line['return'] ?? 0)`; this loop read `$product['return']`
     * bare. Two reads of the same stored key, one defended and one not.
     */
    public function test_a_sibling_line_with_no_return_key_does_not_break_the_return(): void
    {
        [, $order, $good, ] = $this->twoLineOrder([
            'qty' => 1,
            'final_price' => 40.0,
            // no 'return' key
        ]);

        $answer = Order::product_return([
            'order_id' => $order->id,
            'product_id' => $good->id,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $answer['status'] ?? null,
            "a sibling line with no `return` key broke the return. Answer: " . json_encode($answer));

        $this->assertSettled($order, 60.0, 40.0);
    }

    /**
     * CASE 3 -- A LINE ALREADY FLAGGED RETURNED THAT CARRIES NO `final_price`.
     *
     * This is the one where the two formulas disagreed: `returnedAmountOf()` already reads it as 0
     * and the summation raised. The expected arithmetic therefore treats the malformed prior line
     * as contributing 0 -- which is what the sibling method has always said it contributes.
     *
     * The order's stored `final_price` is 60.0 here, because a prior return of the 40.0 line is
     * what the flag claims happened; its own value is unreadable, so `returnedAmountOf()` adds 0
     * back and the base stays 60.0. Returning the 60.0 line then settles at 60.0 and leaves 0.0.
     */
    public function test_a_prior_flagged_line_with_no_final_price_counts_as_zero_rather_than_crashing(): void
    {
        [, $order, $good, ] = $this->twoLineOrder([
            'qty' => 1,
            'return' => 1,
            // no 'final_price' key, although the line claims to have been returned
        ], ['final_price' => 60.0], 60.0);

        $answer = Order::product_return([
            'order_id' => $order->id,
            'product_id' => $good->id,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $answer['status'] ?? null,
            'a prior flagged line with no final_price broke the return of a well-formed line, '
            . 'although returnedAmountOf() reads the same key as 0. Answer: ' . json_encode($answer));

        $this->assertSame(60.0, (float) $this->column($order, 'return_price'),
            'the returned line did not settle at its own value. ' . $this->snapshot($order));

        $this->assertSame(0.0, (float) $this->column($order, 'final_price'),
            'final_price did not fall to 0 after the only readable line came back. '
            . $this->snapshot($order));
    }

    /**
     * AND NOTHING IS LEFT HALF-DONE. The flag write happens before the money write, so the shape
     * this file really guards against is "flagged but never credited". Asserted directly.
     */
    private function assertSettled(Order $order, float $expectedFinal, float $expectedReturn): void
    {
        $lines = json_decode((string) $this->column($order, 'product_json'), true);

        $this->assertIsArray($lines, 'product_json no longer decodes. ' . $this->snapshot($order));

        $flagged = array_values(array_filter(
            $lines,
            static fn ($line): bool => is_array($line) && (int) ($line['return'] ?? 0) === 1
        ));

        $this->assertNotSame([], $flagged,
            'the return flag was not written, so the call did nothing. ' . $this->snapshot($order));

        $this->assertSame($expectedReturn, (float) $this->column($order, 'return_price'),
            'a line is flagged returned and return_price does not record it -- the exact '
            . 'half-written state a throw between the two writes produces. ' . $this->snapshot($order));

        $this->assertSame($expectedFinal, (float) $this->column($order, 'final_price'),
            'final_price was not settled. ' . $this->snapshot($order));
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * An order at Delivered carrying TWO lines: one well-formed "good" line that the call returns,
     * and one sibling built from `$sibling` exactly as given -- missing keys included, which is the
     * point.
     *
     * Written through Eloquent. `Order` and `Product` are both `Cachable`, and `product_return()`
     * reads the order through `Order::find()`, so a raw `DB::table('orders')->update()` here would
     * leave the method operating on the pre-write `product_json`.
     *
     * @param  array<string, mixed>  $sibling
     * @param  array<string, mixed>  $goodOverrides
     * @return array{0: Store, 1: Order, 2: Product, 3: Product}
     */
    private function twoLineOrder(array $sibling, array $goodOverrides = [], float $orderTotal = 100.0): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);

        $good = $this->trackedProduct($store, 'good');
        $other = $this->trackedProduct($store, 'other');

        $goodLine = array_merge([
            'product_id' => $good->id,
            'variant_id' => 0,
            'qty' => 1,
            'final_price' => 40.0,
            'return' => 0,
        ], $goodOverrides);

        $order = $this->makeOrder($store, [
            'delivered_status' => self::DELIVERED,
            'return_status' => 0,
            'final_price' => $orderTotal,
            'return_price' => 0,
            'product_json' => json_encode([
                $goodLine,
                array_merge(['product_id' => $other->id, 'variant_id' => 0], $sibling),
            ]),
        ]);

        return [$store, $order, $good, $other];
    }

    private function trackedProduct(Store $store, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => $name . '-' . uniqid(),
            'price' => 40,
            'sale_price' => 40,
            'cost_price' => 10,
            'product_stock' => 5,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    /** Off the stored row, never off the model. */
    private function column(Order $order, string $column)
    {
        return DB::table('orders')->where('id', $order->id)->value($column);
    }

    private function snapshot(Order $order): string
    {
        $row = DB::table('orders')->where('id', $order->id)->first();

        return 'MEASURED ROW: final_price=' . var_export($row->final_price ?? null, true)
            . ' return_price=' . var_export($row->return_price ?? null, true)
            . ' product_json=' . substr((string) ($row->product_json ?? ''), 0, 400);
    }
}
