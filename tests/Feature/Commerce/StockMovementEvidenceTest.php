<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductStockService;
use App\Services\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * E13 / D-30 — THE DURABLE STOCK EVIDENCE, and the three-state answer D-15 requires.
 *
 * D-15 forbids inferring stock movement from order status, payment status, a flag or the existence
 * of an order. The 2026-10-26 analysis found the evidence undesignable because a record written in
 * the shared service would be **silent on five raw writers**. D-21 removed those writers;
 * `E13-ARCHITECTURE-REEVALUATION-2026-10-31.md` re-measures it and finds `ProductStockService` is
 * now the ONLY mutator in `app/`. This file is the evidence that follows from that.
 *
 * **THE CASES THAT MATTER ARE THE NEGATIVE ONES.** Any implementation records a successful
 * decrement. What makes this usable for E13 is that it distinguishes:
 *
 *   - a decrement that APPLIED                     -> MOVED
 *   - a decrement that was REFUSED for shortfall   -> NOT_MOVED, and recorded as a refusal
 *   - a decrement then a restore                   -> NOT_MOVED on balance
 *   - an order the record never observed            -> **UNKNOWN**
 *   - the record not installed at all               -> **UNKNOWN**
 *
 * The last two are the whole point. `UNKNOWN` is what a caller gets when the evidence cannot speak,
 * and D-15 requires that caller to REFUSE rather than guess. A reader that answered "no rows, so no
 * movement" would be making exactly the forbidden inference — on every order placed before this
 * table existed, and on every environment where the migration has not run, which includes
 * production by D-30's own prohibition.
 */
class StockMovementEvidenceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function service(): ProductStockService
    {
        return new ProductStockService();
    }

    private function product($store, int $stock, string $status = 'in_stock'): Product
    {
        $merchant = \App\Models\User::where('type', 'admin')->first();

        $id = (int) DB::table('products')->insertGetId([
            'name' => 'P' . uniqid(),
            'slug' => 'p-' . uniqid(),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
            'track_stock' => 1,
            'product_stock' => $stock,
            'stock_status' => $status,
            'variant_product' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Product::withoutGlobalScopes()->find($id);
    }

    // ───────────────────────────────────────────────── the premise

    public function test_the_record_exists_in_this_suite_but_is_development_only(): void
    {
        $this->assertTrue(
            Schema::hasTable('stock_movements'),
            'the suite migrates it, so the cases below are not vacuous'
        );

        $this->assertTrue(StockMovement::available());

        /*
         * AND THE MIGRATION SAYS IT IS DEV/TEST ONLY. D-30 permits development migrations and
         * forbids production ones, so the reading code must treat absence as UNKNOWN rather than
         * as "no movement" — which the case at the end of this file drives directly.
         */
        $migration = (string) file_get_contents(
            base_path('database/migrations/2026_10_31_000100_create_stock_movements_table.php')
        );

        $this->assertStringContainsString('DEVELOPMENT AND TEST ONLY', $migration);
    }

    // ───────────────────────────────────────── an applied decrement is evidence

    public function test_an_applied_decrement_is_recorded_and_reads_as_moved(): void
    {
        [, $store] = $this->makeTenant();
        $product = $this->product($store, 10);
        $order = $this->makeOrder($store);

        $result = $this->service()->decrementProductStock($product, 3, false, false, [
            'store_id' => $store->id,
            'order_id' => $order->id,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        $this->assertTrue($result['applied'] ?? $result[0] ?? false, 'premise: the decrement applied');

        $this->assertSame(
            StockMovement::MOVED,
            StockMovement::movedOutFor($store->id, $order->id, $product->id),
            'an applied decrement must read as MOVED'
        );

        $rows = StockMovement::forOrder($store->id, $order->id);
        $this->assertCount(1, $rows);
        $this->assertSame(StockMovement::OUT, $rows->first()->direction);
        $this->assertSame(3, (int) $rows->first()->quantity);
        $this->assertSame(1, (int) $rows->first()->applied);
        $this->assertSame(StockMovement::REASON_ORDER_PLACE, $rows->first()->reason);
    }

    // ───────────────────────────── A REFUSAL IS EVIDENCE, and a different fact

    public function test_a_refused_decrement_is_recorded_as_not_applied(): void
    {
        /*
         * THE CASE E13 EXISTS FOR. "The order tried and failed" is a different fact from "the order
         * took stock". A replacement built on the second while the first is true deducts stock
         * TWICE. So the refusal is recorded, with applied = false, and reads as NOT_MOVED.
         */
        [, $store] = $this->makeTenant();
        $product = $this->product($store, 1);
        $order = $this->makeOrder($store);

        $result = $this->service()->decrementProductStock($product, 5, false, true, [
            'store_id' => $store->id,
            'order_id' => $order->id,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        $this->assertFalse((bool) ($result['applied'] ?? true), 'premise: the shortfall was refused');

        $rows = StockMovement::forOrder($store->id, $order->id);
        $this->assertCount(1, $rows, 'the refusal must be recorded, not omitted');
        $this->assertSame(0, (int) $rows->first()->applied);
        $this->assertSame(ProductStockService::INSUFFICIENT, $rows->first()->outcome);

        $this->assertSame(
            StockMovement::NOT_MOVED,
            StockMovement::movedOutFor($store->id, $order->id, $product->id),
            'a refused decrement must read as NOT_MOVED — the order observed, nothing taken. '
            . 'This is what makes it different from UNKNOWN.'
        );
    }

    public function test_a_decrement_then_a_restore_reads_as_not_moved_on_balance(): void
    {
        // What a cancellation that DID restore looks like. A replacement must not restore again.
        [, $store] = $this->makeTenant();
        $product = $this->product($store, 10);
        $order = $this->makeOrder($store);
        $ctx = ['store_id' => $store->id, 'order_id' => $order->id];

        $this->service()->decrementProductStock($product, 4, false, false,
            $ctx + ['reason' => StockMovement::REASON_ORDER_PLACE]);

        $this->assertSame(
            StockMovement::MOVED,
            StockMovement::movedOutFor($store->id, $order->id, $product->id)
        );

        $this->service()->restoreProductStock($product->id, 4,
            $ctx + ['reason' => StockMovement::REASON_ORDER_CANCEL]);

        $this->assertSame(
            StockMovement::NOT_MOVED,
            StockMovement::movedOutFor($store->id, $order->id, $product->id),
            'taken and given back is NOT_MOVED on balance, which is what a replacement needs to '
            . 'know so it does not restore a second time'
        );

        $this->assertCount(2, StockMovement::forOrder($store->id, $order->id));
    }

    // ───────────────────────────────── UNKNOWN, the state that forces a refusal

    public function test_an_order_the_record_never_observed_reads_as_unknown(): void
    {
        /*
         * THE FORBIDDEN INFERENCE, DRIVEN. An order placed before this table existed has no rows
         * and DID move stock. If this returned NOT_MOVED, every such order would look safe to
         * replace — and E13 would deduct its stock a second time. It must say UNKNOWN.
         */
        [, $store] = $this->makeTenant();
        $product = $this->product($store, 10);
        $order = $this->makeOrder($store);

        $this->assertSame(
            StockMovement::UNKNOWN,
            StockMovement::movedOutFor($store->id, $order->id, $product->id),
            'no rows for an unobserved order must be UNKNOWN, NEVER NOT_MOVED. D-15: do not infer '
            . 'stock movement from the absence of a record.'
        );
    }

    public function test_an_observed_order_with_no_movement_on_THIS_line_reads_as_not_moved(): void
    {
        // The distinction that makes UNKNOWN meaningful: if we observed the order at all, we can
        // speak for a line it never touched.
        [, $store] = $this->makeTenant();
        $touched = $this->product($store, 10);
        $untouched = $this->product($store, 10);
        $order = $this->makeOrder($store);

        $this->service()->decrementProductStock($touched, 1, false, false, [
            'store_id' => $store->id, 'order_id' => $order->id,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        $this->assertSame(StockMovement::MOVED,
            StockMovement::movedOutFor($store->id, $order->id, $touched->id));

        $this->assertSame(
            StockMovement::NOT_MOVED,
            StockMovement::movedOutFor($store->id, $order->id, $untouched->id),
            'the order WAS observed, so a line it never moved is NOT_MOVED rather than UNKNOWN'
        );
    }

    public function test_an_uninstrumented_call_site_records_nothing_and_reads_as_unknown(): void
    {
        /*
         * NO CONTEXT, NO ROW. A movement recorded without an order cannot answer E13's question,
         * and a row that looks like an answer and is not is worse than no row. So an un-threaded
         * call site is silent, and the order then reads UNKNOWN — a recorded gap, not a false
         * negative.
         */
        [, $store] = $this->makeTenant();
        $product = $this->product($store, 10);
        $order = $this->makeOrder($store);

        $this->service()->decrementProductStock($product, 2);   // no evidence argument

        $this->assertCount(0, StockMovement::forOrder($store->id, $order->id));
        $this->assertSame(
            StockMovement::UNKNOWN,
            StockMovement::movedOutFor($store->id, $order->id, $product->id)
        );
    }

    public function test_the_reader_answers_unknown_when_the_record_is_absent(): void
    {
        /*
         * PRODUCTION'S STATE, driven. D-30 forbids the production migration, so there the table
         * does not exist — and every reader must get UNKNOWN rather than a reassuring zero.
         */
        [, $store] = $this->makeTenant();
        $product = $this->product($store, 10);
        $order = $this->makeOrder($store);
        $ctx = ['store_id' => $store->id, 'order_id' => $order->id];

        $this->service()->decrementProductStock($product, 1, false, false, $ctx);
        $this->assertSame(StockMovement::MOVED,
            StockMovement::movedOutFor($store->id, $order->id, $product->id));

        Schema::drop('stock_movements');

        $this->assertFalse(StockMovement::available(), 'premise: the record is gone');
        $this->assertSame(
            StockMovement::UNKNOWN,
            StockMovement::movedOutFor($store->id, $order->id, $product->id),
            'with the table absent the answer must be UNKNOWN. A caller that read NOT_MOVED here '
            . 'would treat every order in production as safe to replace.'
        );

        // And recording must not throw with the table gone -- a checkout must not fail because its
        // evidence cannot be written.
        $this->service()->decrementProductStock($product, 1, false, false, $ctx);
        $this->assertTrue(true, 'recording with no table did not raise');
    }

    // ────────────────────────────────────────────────────── tenancy

    public function test_the_record_is_store_scoped_in_both_directions(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $productA = $this->product($storeA, 10);
        $orderA = $this->makeOrder($storeA);

        $this->service()->decrementProductStock($productA, 2, false, false, [
            'store_id' => $storeA->id, 'order_id' => $orderA->id,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        // Store B asking about store A's order id must learn nothing.
        $this->assertSame(
            StockMovement::UNKNOWN,
            StockMovement::movedOutFor($storeB->id, $orderA->id, $productA->id),
            'another store must not read this movement — and UNKNOWN, not NOT_MOVED, because B '
            . 'genuinely has no evidence rather than evidence of nothing'
        );

        $this->assertCount(0, StockMovement::forOrder($storeB->id, $orderA->id));
        $this->assertCount(1, StockMovement::forOrder($storeA->id, $orderA->id));
    }

    public function test_a_movement_with_no_store_is_refused_rather_than_written(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        StockMovement::record([
            'order_id' => $order->id,
            'product_id' => 1,
            'direction' => StockMovement::OUT,
            'quantity' => 1,
            'applied' => true,
        ]);

        $this->assertSame(
            0,
            (int) DB::table('stock_movements')->count(),
            'a movement we cannot attribute to a store is not evidence, and writing it would put '
            . "one tenant's row where another's query might read it"
        );
    }

    // ───────────────────────────────────────────────────── variants

    public function test_a_variant_movement_is_recorded_against_the_variant_not_the_product(): void
    {
        [, $store] = $this->makeTenant();
        $product = $this->product($store, 0);
        $order = $this->makeOrder($store);

        $variantId = (int) DB::table('product_variants')->insertGetId([
            /* Columns copied from AdminApiOrderStockOwnershipTest's working fixture: `variant` and
               `sku` are both NOT NULL, which two successive failures here established. */
            'product_id' => $product->id, 'store_id' => $store->id,
            'variant' => 'red', 'sku' => 'sm-' . uniqid(), 'price' => 10,
            'variation_option' => 'manage_stock', 'stock' => 8, 'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variant = ProductVariant::withoutGlobalScopes()->find($variantId);

        $this->service()->decrementVariantStock($variant, 3, false, false, [
            'store_id' => $store->id, 'order_id' => $order->id,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        $row = StockMovement::forOrder($store->id, $order->id)->first();

        $this->assertNotNull($row);
        $this->assertSame($variantId, (int) $row->variant_id);
        $this->assertNull($row->product_id, 'a variant movement names the variant, not the product');

        $this->assertSame(
            StockMovement::MOVED,
            StockMovement::movedOutFor($store->id, $order->id, null, $variantId)
        );

        /*
         * AND THE PRODUCT-LEVEL QUESTION MUST NOT ANSWER FOR THE VARIANT. The product row never
         * moved; a reader asking about it on an observed order gets NOT_MOVED, which is correct and
         * is why `movedOutFor()` adds `whereNull('variant_id')` to the product branch.
         */
        $this->assertSame(
            StockMovement::NOT_MOVED,
            StockMovement::movedOutFor($store->id, $order->id, $product->id)
        );
    }
}
