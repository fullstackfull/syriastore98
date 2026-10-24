<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-INV-05 CRITERION 3 -- "cancellation and return restore EXACTLY what the order took."
 * =================================================================================================
 *
 * WHY THIS FILE EXISTS AND WHY IT IS NOT ALREADY COVERED. `OrderReturnWorkflowTest` asserts exact
 * restored quantities (50 + 9 = 59) and it is a good file, but every one of its orders has its
 * stock SET by the fixture and its `product_json` WRITTEN BY HAND. Nothing in the suite drives the
 * DECREMENT and the RESTORE as a pair, so "the quantity restored equals the quantity removed" has
 * never been measured -- only each half separately, against numbers a test chose. That gap is
 * precisely criterion 3, which the ledger has carried as NOT DONE since 2026-10-23 with the note
 * that atomicity is settled and quantity is "a different question".
 *
 * WHAT THE PAIR ACTUALLY IS. `ProductStockService::decrementProductStock()` is ALL-OR-NOTHING: one
 * conditional `UPDATE ... SET product_stock = product_stock - ?`, and an affected-row count of 0 is
 * a refusal with nothing applied. There is no partial decrement. So the quantity it removes is
 * always the normalised line quantity, and `Order::product_return()` restores `(int) $line['qty']`
 * from the order's own json. Both sides end up applying `(int)` to the same value, which is why the
 * round trip is expected to be exact for every input shape -- including `"3"`, `3.7` and `"3.7"`,
 * all of which both sides read as 3.
 *
 * THE ONE PLACE IT COULD HAVE GONE WRONG, and the reason test 4 exists. A line whose decrement was
 * REFUSED must never be restorable, or the return credits units the order never took. The two
 * storefront writers cannot produce that (they either abandon the checkout or retry with
 * `allowOversell`, which always applies), and `OrderController::store()` THROWS inside its
 * transaction so no order row survives an unapplied line. But `OrderController::store()` writes its
 * lines with the key `'quantity'` and NO `'qty'` -- a third json shape, distinct from both the
 * storefront shape and the POS shape that `OrderReturnWorkflowTest` already covers. Read against
 * `(int) $line['qty']` that shape restores 0 while the surrounding code marks the line returned and
 * reduces `final_price`: money down, inventory not back.
 *
 * It does not do that, because `linesAreReturnable()` requires `product_id` AND `qty` and refuses
 * the whole call before the loop.
 *
 * AND THE MUTANT CORRECTED THAT READING, so it is recorded rather than quietly fixed. I expected
 * the unguarded behaviour to be the silent half-return described above. MEASURED (M3, the `qty`
 * requirement removed from `linesAreReturnable()`): the call returns HTTP **500**. PHP raises on the
 * undefined `qty` key before `(int)` can turn it into 0, so the money never moves either. The
 * guard's value is therefore turning a CRASH into an explicit refusal with a message the merchant
 * can read -- which is what (A) and (B) in `OrderReturnWorkflowTest` already say about the POS and
 * null shapes, and this is a third instance of the same class.
 *
 * Test 4 still asserts the stock, `final_price` and the per-line return marker. Under M3 those
 * never ran, because the 500 assertion above them fired first -- so a SECOND mutant was needed to
 * show they are not idle. M4 accepts the shape AND reads `qty` tolerantly in all three places
 * inside `product_return()` (`$line['qty'] ?? 0`), which is what a future "just stop it crashing"
 * change would look like. MEASURED: the half-return appears exactly as described -- `final_price`
 * reduced, stock untouched -- and it is the money assertion that catches it, not the stock one.
 *
 * MUTANTS, all four executed, all four bite, none survived:
 *   M1  restoreProductStock gives back $quantity + 1        -> tests 1 and 3 fail
 *   M2  restoreVariantStock becomes a no-op                 -> test 2 fails
 *   M3  linesAreReturnable stops requiring qty              -> test 4 fails on the 500
 *   M4  M3 plus tolerant `qty ?? 0` reads in product_return -> test 4 fails on final_price
 *
 * WHY THE ASSERTIONS READ THROUGH `DB::table()`. `Product` and `Order` are `Cachable`; an Eloquent
 * read can serve a mutated in-memory instance and has produced a false reproduction in this project
 * before.
 *
 * ENGINE. Deliberately the suite's SQLite rather than `tests/Support/Security/bin/`: nothing here
 * depends on a concurrent interleave. The engine-dependent halves of this row -- that the decrement
 * and the restock are atomic under a real race -- are criteria 1 and 2 and live in
 * `verify-stock-oversell-concurrency-on-mysql.php` sections A-F, on MySQL, where they belong.
 * Putting a quantity-arithmetic assertion there too would buy nothing and hide it from CI.
 */
class ReturnRestoresExactlyWhatWasTakenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function stockService(): ProductStockService
    {
        return app(ProductStockService::class);
    }

    private function simpleProductIn($store, int $stock): Product
    {
        return Product::forceCreate([
            'name' => 'p-' . uniqid(), 'slug' => 'p-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock', 'stock_order_status' => 'not_allow',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function productStock(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('product_stock');
    }

    private function variantStock(int $variantId): int
    {
        return (int) DB::table('product_variants')->where('id', $variantId)->value('stock');
    }

    private function deliveredOrder($store, array $overrides = []): Order
    {
        return $this->makeOrder($store, array_merge([
            'delivered_status' => 1,
            'tax_price' => 17.50,
        ], $overrides));
    }

    private function returnLine(Order $order, int $productId, int $variantId, $merchant)
    {
        return $this->actingAs($merchant->fresh())->post('/order-return', [
            'order_id' => $order->id, 'product_id' => $productId, 'variant_id' => $variantId,
        ]);
    }

    /**
     * (1) THE ROUND TRIP, DRIVEN THROUGH BOTH REAL PRIMITIVES, IS NET ZERO.
     *
     * This is criterion 3 in one test for the simple-product path. The decrement is the real
     * service call an order writer makes, not a fixture write, so the starting figure is what the
     * order genuinely took.
     */
    public function test_the_round_trip_is_net_zero_for_a_simple_product(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);

        // THE ORDER TAKES 7, through the primitive every writer uses.
        $taken = $this->stockService()->decrementProductStock($product, 7);

        $this->assertTrue($taken['applied'], 'Premise: the decrement applied.');
        $this->assertSame(7, (int) $taken['quantity'], 'Premise: it took the whole 7, not a part.');
        $this->assertSame(43, $this->productStock($product->id),
            'Premise: 50 - 7 = 43. Without this the net-zero assertion below holds vacuously.');

        $order = $this->deliveredOrder($store, [
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 7,
                'final_price' => 90, 'return' => 0,
            ]]),
        ]);

        $response = $this->returnLine($order, $product->id, 0, $merchant);

        $this->assertNotSame(404, $response->status(), 'Premise: the route exists.');
        $this->assertSame(50, $this->productStock($product->id),
            'THE RETURN DID NOT RESTORE WHAT THE ORDER TOOK: 43 + 7 must be exactly 50. A figure '
            . 'above 50 inflates the merchant\'s inventory; below 50 destroys stock they hold.');
    }

    /** (2) THE SAME PAIR ON THE VARIANT PATH, which is a different column and a different method. */
    public function test_the_round_trip_is_net_zero_for_a_variant(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 0);
        DB::table('products')->where('id', $product->id)->update(['variant_product' => 1]);

        $variant = ProductVariant::forceCreate([
            'product_id' => $product->id, 'variant' => 'red', 'sku' => 'sku-' . uniqid(),
            'price' => 100.00, 'stock' => 30, 'store_id' => $store->id,
        ]);

        $taken = $this->stockService()->decrementVariantStock($variant, 4);

        $this->assertTrue($taken['applied'], 'Premise: the variant decrement applied.');
        $this->assertSame(26, $this->variantStock($variant->id), 'Premise: 30 - 4 = 26.');

        $order = $this->deliveredOrder($store, [
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => $variant->id, 'qty' => 4,
                'final_price' => 90, 'return' => 0,
            ]]),
        ]);

        $this->returnLine($order, $product->id, $variant->id, $merchant);

        $this->assertSame(30, $this->variantStock($variant->id),
            'the variant round trip is not net zero: 26 + 4 must be exactly 30.');
    }

    /**
     * (3) A MULTI-LINE ORDER CREDITS ONE LINE FOR ITS OWN QUANTITY AND LEAVES THE OTHER ALONE.
     *
     * "Exactly what the order took" is per line. A loop that restored every line, or that restored
     * the wrong line's quantity, passes test 1 and fails here.
     */
    public function test_returning_one_line_restores_only_that_lines_quantity(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $returned = $this->simpleProductIn($store, 50);
        $kept     = $this->simpleProductIn($store, 80);

        $this->stockService()->decrementProductStock($returned, 7);
        $this->stockService()->decrementProductStock($kept, 3);

        $this->assertSame(43, $this->productStock($returned->id), 'Premise: 50 - 7.');
        $this->assertSame(77, $this->productStock($kept->id), 'Premise: 80 - 3.');

        $order = $this->deliveredOrder($store, [
            'product_json' => json_encode([
                ['product_id' => $returned->id, 'variant_id' => 0, 'qty' => 7, 'final_price' => 90, 'return' => 0],
                ['product_id' => $kept->id,     'variant_id' => 0, 'qty' => 3, 'final_price' => 40, 'return' => 0],
            ]),
        ]);

        $this->returnLine($order, $returned->id, 0, $merchant);

        $this->assertSame(50, $this->productStock($returned->id),
            'the returned line was not credited its own 7 units.');
        $this->assertSame(77, $this->productStock($kept->id),
            'A LINE NOBODY RETURNED WAS CREDITED. 77 must stay 77 -- the loop is restoring every '
            . 'line rather than the one named.');
    }

    /**
     * (4) THE THIRD JSON SHAPE -- `quantity` WITHOUT `qty` -- IS REFUSED, AND THE REFUSAL IS TOTAL.
     *
     * `OrderController::store()` (the merchant-admin writer) writes `'quantity' => $qty` and no
     * `'qty'`. Read against `(int) $line['qty']` that is 0 units restored, so the dangerous outcome
     * is not a crash but a HALF-RETURN: the line marked returned and `final_price` reduced while the
     * warehouse gets nothing back.
     *
     * The three assertions are the three things a half-return would move. Asserting only the stock
     * would miss the money, which is the half that cannot be undone by a stock correction.
     */
    public function test_a_merchant_admin_shaped_line_is_refused_and_nothing_moves(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $this->stockService()->decrementProductStock($product, 7);
        $this->assertSame(43, $this->productStock($product->id), 'Premise: the order took 7.');

        // The OrderController::store() shape, verbatim in the keys that matter.
        $order = $this->deliveredOrder($store, [
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'quantity' => 7,
                'final_price' => 90, 'return' => false,
            ]]),
            'final_price' => 107.50,
        ]);

        $response = $this->returnLine($order, $product->id, 0, $merchant);

        $this->assertNotSame(404, $response->status(), 'Premise: the route exists.');
        $this->assertNotSame(500, $response->status(),
            'the unreadable shape 500s instead of being refused.');

        $this->assertSame(43, $this->productStock($product->id),
            'stock moved on a shape the workflow cannot read: it restored a quantity it did not '
            . 'take, or credited 0 while reporting success.');

        $row = DB::table('orders')->where('id', $order->id)->first();

        $this->assertSame('107.50', number_format((float) $row->final_price, 2, '.', ''),
            'A HALF-RETURN: final_price was reduced for a line whose stock was never restored. '
            . 'This is the money half, and a later stock correction does not undo it.');

        $lines = json_decode((string) $row->product_json, true);
        $this->assertNotSame(1, (int) ($lines[0]['return'] ?? 0),
            'the line was marked returned although the refusal means nothing was returned.');
    }

    /**
     * (5) AND THE SHAPE TEST IS NOT REFUSING EVERYTHING.
     *
     * Test 4's assertions are all "nothing moved", which a handler that refuses every caller
     * satisfies. Test 1 is the liveness control for the stock half; this is the control for the
     * MONEY half, so that "final_price did not change" in test 4 is known to mean the refusal and
     * not a workflow that never touches final_price at all.
     */
    public function test_an_accepted_return_does_reduce_final_price(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);

        $order = $this->deliveredOrder($store, [
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 7,
                'final_price' => 90, 'return' => 0,
            ]]),
            'final_price' => 107.50,
        ]);

        $before = (float) DB::table('orders')->where('id', $order->id)->value('final_price');

        $this->returnLine($order, $product->id, 0, $merchant);

        $after = (float) DB::table('orders')->where('id', $order->id)->value('final_price');

        $this->assertLessThan($before, $after,
            'Control: an ACCEPTED return reduces final_price. If this does not hold, test 4\'s '
            . 'final_price assertion proves nothing, because the column never moves either way.');
    }
}
