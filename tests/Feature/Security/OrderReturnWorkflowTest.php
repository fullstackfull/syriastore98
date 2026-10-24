<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * THE RETURN WORKFLOW: one credit per line, no 500 on a shape it cannot read, and a money column
 * it no longer destroys.  P1-INV-15 (#60), OWNER DECISION 5.
 * =================================================================================================
 *
 * THE OWNER'S WORDS: "Implement a reliable, tenant-safe, transaction-safe return workflow. Restock
 * only when a return is actually accepted. Prevent duplicate stock credits and duplicate refunds.
 * Keep physical inventory movements separate from financial refund settlement. Preserve correct tax
 * and POS handling."
 *
 * FIVE DEFECTS, ALL PRE-EXISTING, all found by the adversarial review of P1-INV-02:
 *
 *   (A) A POS order 500s. POS writes `{"data":[{name, quantity, ...}], ...}` with no `product_id`
 *       and no `qty` on its lines (PosController:120-139, :231), and the restock loop reads
 *       `$product['product_id']` unconditionally.
 *   (B) A NULL `product_json` 500s. P1-INV-02's null guard covers the missing ORDER, not the
 *       missing json, and `json_encode($product_json)` is then an undefined variable.
 *   (C) `tax_price` was written as 0 on EVERY return: `$tax_price` was initialised and never
 *       assigned. A money column, destroyed by an inventory action.
 *   (D) No transaction around the restock plus the two saves, though the SIBLING endpoint has
 *       wrapped exactly that pair since P1-INV-02.
 *   (E) A DOUBLE CREDIT ACROSS THE TWO ENDPOINTS. `restoreReturnedStock()` loops every line with
 *       no reference to the line's return flag, so the two-step merchant workflow -- mark the
 *       line through `order_return`, then approve the request through `order_return_request` --
 *       credited that line's stock twice.
 *
 * WHICH ENDPOINT OWNS THE CREDIT was the open product question, and decision 5 answers it: the
 * goods move once, when the return is accepted, and neither endpoint may credit a line the other
 * has already credited. The mechanism is a per-LINE marker inside `product_json`
 * (`Order::LINE_STOCK_RESTORED`) that both endpoints write and both consult -- per line rather than
 * per order, because a four-line order can have one line marked returned and then the whole request
 * approved, which an order-level flag cannot express.
 *
 * WHY THE ASSERTIONS READ THROUGH `DB::table()`. `Product` and `Order` are `Cachable`, and an
 * Eloquent read in a sibling package once reported a hijack that had never reached the database.
 */
class OrderReturnWorkflowTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function simpleProductIn($store, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => 'p-' . uniqid(), 'slug' => 'p-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function productStock(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('product_stock');
    }

    private function deliveredOrder($store, array $overrides = [])
    {
        return $this->makeOrder($store, array_merge([
            'delivered_status' => 1,
            'tax_price' => 17.50,
        ], $overrides));
    }

    private function lineJson(Product $product, int $qty = 9): string
    {
        return json_encode([[
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => $qty,
            'final_price' => 90,
            'return' => 0,
        ]]);
    }

    private function orderRow(int $orderId): object
    {
        return DB::table('orders')->where('id', $orderId)->first();
    }

    /**
     * THE PREMISE: the ordinary merchant return still works, and it restocks.
     *
     * Every "nothing moved" assertion below is satisfied by a handler that refuses everyone, so
     * this control has to hold first or the file measures nothing.
     */
    public function test_an_ordinary_merchant_return_still_restocks(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $order = $this->deliveredOrder($store, ['product_json' => $this->lineJson($product, 9)]);

        $response = $this->actingAs($merchant->fresh())->post('/order-return', [
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => 0,
        ]);

        $this->assertNotSame(404, $response->status(), 'Premise: the route exists.');
        $this->assertSame(59, $this->productStock($product->id),
            'Premise: an own-store return restocks. 50 + 9 = 59.');
    }

    /**
     * (E) ONE RETURN, ONE STOCK CREDIT -- ACROSS BOTH ENDPOINTS.
     *
     * The two-step merchant workflow, driven end to end: mark the line returned, then approve the
     * return request. MEASURED before the fix, both endpoints crediting the same 9 units: 50 -> 59
     * -> 68. The goods moved once in the warehouse and twice in the database.
     */
    public function test_the_two_endpoints_do_not_credit_the_same_line_twice(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $order = $this->deliveredOrder($store, ['product_json' => $this->lineJson($product, 9)]);

        $this->actingAs($merchant->fresh())->post('/order-return', [
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => 0,
        ]);

        $this->assertSame(59, $this->productStock($product->id),
            'Premise: the first endpoint credited the line. Without this the second assertion '
            . 'holds whether or not the guard works.');

        $this->actingAs($merchant->fresh())->post('/order-return-request', [
            'id' => $order->id, 'status' => 2,
        ]);

        $this->assertSame(59, $this->productStock($product->id),
            'the approval credited the same line a second time: the two-step merchant workflow '
            . 'inflates inventory by the returned quantity every time it is used.');
    }

    /** AND THE MARKER IS DURABLE: it is written into the order row, not held in memory. */
    public function test_the_credit_marker_is_persisted_on_the_line(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $order = $this->deliveredOrder($store, ['product_json' => $this->lineJson($product, 9)]);

        $this->actingAs($merchant->fresh())->post('/order-return', [
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => 0,
        ]);

        $lines = json_decode((string) $this->orderRow($order->id)->product_json, true);

        $this->assertIsArray($lines, 'Premise: the order still carries readable lines.');
        $this->assertSame(1, (int) ($lines[0][Order::LINE_STOCK_RESTORED] ?? 0),
            'the line was credited but not marked, so nothing downstream can tell that these '
            . 'goods are already back in stock.');
    }

    /**
     * AND THE APPROVAL STILL CREDITS A LINE NOBODY HAS CREDITED YET.
     *
     * PRESERVE FIRST. The marker must stop the SECOND credit, not the first: a customer-requested
     * return that the merchant simply approves has no prior `order_return` call, and those goods
     * must still come back.
     */
    public function test_an_approval_alone_still_credits_the_stock(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $order = $this->deliveredOrder($store, ['product_json' => $this->lineJson($product, 9)]);

        $this->actingAs($merchant->fresh())->post('/order-return-request', [
            'id' => $order->id, 'status' => 2,
        ]);

        $this->assertSame(59, $this->productStock($product->id),
            'an approval with no prior line-level return no longer restocks. That is the ordinary '
            . 'customer-requested return, and this fix must not have removed it.');
    }

    /**
     * AND THE OTHER ORDER OF THE SAME TWO STEPS.
     *
     * A REMOVAL CHECK IS WHY THIS EXISTS. Deleting the line that PERSISTS the marker on the
     * approval side survived the first mutation run: every test drove mark-then-approve, where
     * the marker is written by the other endpoint. Approve-then-mark is the mirror journey and
     * the only one that depends on the approval writing it.
     *
     * It is also the more dangerous order, because the approval does NOT set each line's `return`
     * flag -- so `product_return()`'s own replay guard, which reads exactly that flag, does not
     * fire. Without the persisted marker the second credit lands with nothing to stop it.
     */
    public function test_approving_first_and_marking_the_line_second_also_credits_once(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $order = $this->deliveredOrder($store, ['product_json' => $this->lineJson($product, 9)]);

        $this->actingAs($merchant->fresh())->post('/order-return-request', [
            'id' => $order->id, 'status' => 2,
        ]);

        $this->assertSame(59, $this->productStock($product->id),
            'Premise: the approval credited the line.');

        $lines = json_decode((string) $this->orderRow($order->id)->product_json, true);
        $this->assertSame(1, (int) ($lines[0][Order::LINE_STOCK_RESTORED] ?? 0),
            'Premise: the approval persisted the marker. Without it the assertion below cannot '
            . 'distinguish the guard from the line never having been credited.');

        $this->actingAs($merchant->fresh())->post('/order-return', [
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => 0,
        ]);

        $this->assertSame(59, $this->productStock($product->id),
            'marking the line returned after the request was already approved credited the same '
            . 'goods a second time. The approval does not set the line\'s `return` flag, so '
            . 'product_return()\'s own replay guard does not fire here -- the persisted marker is '
            . 'the only thing standing between this journey and an inflated inventory.');
    }

    /**
     * (A) A POS-SHAPED ORDER IS REFUSED, NOT A 500.
     *
     * POS lines are `{name, quantity, ...}` with no `product_id` and no `qty`. The assertion is
     * BOTH that the response is not a 500 and that no stock moved: a crash that happens to leave
     * the stock alone is not the same as a refusal, and only checking one of the two would accept
     * either outcome.
     */
    public function test_a_pos_shaped_order_is_refused_rather_than_crashing(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $order = $this->deliveredOrder($store, [
            // The shape PosController writes, verbatim in structure.
            'product_json' => json_encode([
                ['name' => 'Walk-in item', 'quantity' => 2, 'orignal_price' => 45],
            ]),
        ]);

        $response = $this->actingAs($merchant->fresh())->post('/order-return', [
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => 0,
        ]);

        $this->assertNotSame(500, $response->status(),
            'a POS order crashes the return endpoint. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(50, $this->productStock($product->id),
            'and it must not half-apply: no stock may move on a shape the loop cannot read.');
    }

    /** (B) A NULL product_json is refused, for the same reason and with the same two assertions. */
    public function test_a_null_product_json_is_refused_rather_than_crashing(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $order = $this->deliveredOrder($store, ['product_json' => null]);

        $response = $this->actingAs($merchant->fresh())->post('/order-return', [
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => 0,
        ]);

        $this->assertNotSame(500, $response->status(),
            'an order with no product_json crashes the return endpoint. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(50, $this->productStock($product->id));
    }

    /**
     * (C) A RETURN NO LONGER WRITES 0.00 INTO THE ORDER'S TAX.
     *
     * `$tax_price` was initialised to 0 and never assigned, then written to the column. So every
     * return destroyed the order's recorded tax, whatever it had been. The fixture sets 17.50 so
     * the assertion can tell "unchanged" from "happened to be zero already".
     */
    public function test_a_return_does_not_zero_the_orders_tax(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $order = $this->deliveredOrder($store, ['product_json' => $this->lineJson($product, 9)]);

        $this->assertEqualsWithDelta(17.50, (float) $this->orderRow($order->id)->tax_price, 0.005,
            'Premise: the order carries a non-zero tax before the return.');

        $this->actingAs($merchant->fresh())->post('/order-return', [
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => 0,
        ]);

        $this->assertEqualsWithDelta(17.50, (float) $this->orderRow($order->id)->tax_price, 0.005,
            'the return wrote 0.00 over the order\'s tax. That is a money column destroyed by an '
            . 'inventory action, and the owner\'s decision says to leave it untouched rather than '
            . 'guess at an apportionment.');
    }

    /**
     * (C) AND THE RETURN STILL RECORDS WHAT IT IS SUPPOSED TO.
     *
     * Leaving tax alone must not have left everything alone. `return_price` and the line's own
     * `return` flag are what this endpoint exists to write.
     */
    public function test_a_return_still_records_the_returned_value_and_flags_the_line(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $product = $this->simpleProductIn($store, 50);
        $order = $this->deliveredOrder($store, ['product_json' => $this->lineJson($product, 9)]);

        $this->actingAs($merchant->fresh())->post('/order-return', [
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => 0,
        ]);

        $row = $this->orderRow($order->id);
        $lines = json_decode((string) $row->product_json, true);

        $this->assertSame(1, (int) ($lines[0]['return'] ?? 0),
            'the line is no longer flagged as returned, so the replay guard and every display '
            . 'that reads this flag are broken.');
        $this->assertEqualsWithDelta(90.0, (float) $row->return_price, 0.005,
            'the returned value is no longer recorded.');
    }

    /**
     * THE SHAPE TEST ITSELF, driven directly, because the endpoint tests above cannot distinguish
     * "refused for the right reason" from "refused for another".
     */
    public function test_the_shape_test_accepts_a_real_line_and_rejects_a_pos_line(): void
    {
        $this->assertTrue(Order::linesAreReturnable([
            ['product_id' => 1, 'variant_id' => 0, 'qty' => 2],
        ]), 'a normal storefront line must be returnable.');

        $this->assertTrue(Order::linesAreReturnable([
            ['product_id' => 1, 'qty' => 2],
        ]), 'variant_id is legitimately absent on a non-variant line and must not be required.');

        $this->assertFalse(Order::linesAreReturnable([
            ['name' => 'Walk-in item', 'quantity' => 2],
        ]), 'a POS line carries neither product_id nor qty and must be refused.');

        $this->assertFalse(Order::linesAreReturnable(['not-an-array']),
            'a scalar where a line should be is not returnable.');
    }
}
