<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stock decrement is atomic, cannot oversell a product that forbids it, and
 * still permits a backorder on one that allows it.
 *
 * The defect: every order writer did
 *
 *     $remain_stock = $Product->product_stock - $qtyy;
 *     $Product->product_stock = $remain_stock;
 *     $Product->save();
 *
 * which is a lost update — two concurrent checkouts both write `stock - qty`
 * and the second overwrites the first.
 *
 * THIS CLASS NEEDS A DATABASE. It uses RefreshDatabase against the
 * sqlite/:memory: connection that tests/bootstrap.php forces; it never touches
 * a persistent database. It is the one file in this package whose execution is
 * BLOCKED in the current checkout, because PHPUnit cannot be installed here.
 *
 * @see \App\Services\ProductStockService
 */
class OrderStockConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private ProductStockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = new ProductStockService();
    }

    /** A product that forbids overselling cannot go below zero. */
    public function test_order_cannot_oversell_stock(): void
    {
        $product = $this->makeProduct(['product_stock' => 5, 'stock_order_status' => 'not_allow']);

        $first = $this->stock->decrementProductStock($product, 4);

        $this->assertTrue($first['applied']);
        $this->assertSame(1, $first['remaining']);

        $second = $this->stock->decrementProductStock($product, 4);

        $this->assertFalse($second['applied'], 'the second order must not be fulfilled');
        $this->assertSame(ProductStockService::INSUFFICIENT, $second['reason']);
        $this->assertSame(1, (int) Product::find($product->id)->product_stock);
    }

    /**
     * THE LOST UPDATE, directly. Two callers each holding a model read at stock
     * 10 must leave 4, not 7: the second decrement must not be computed from
     * the first caller's stale in-memory value.
     */
    public function test_concurrent_decrements_do_not_lose_an_update(): void
    {
        $product = $this->makeProduct(['product_stock' => 10, 'stock_order_status' => 'not_allow']);

        $readerA = Product::find($product->id);
        $readerB = Product::find($product->id);

        $this->assertTrue($this->stock->decrementProductStock($readerA, 3)['applied']);
        $this->assertTrue($this->stock->decrementProductStock($readerB, 3)['applied']);

        $this->assertSame(4, (int) Product::find($product->id)->product_stock);
    }

    /** Stock never goes negative for a product that forbids overselling. */
    public function test_stock_never_goes_negative_when_forbidden(): void
    {
        $product = $this->makeProduct(['product_stock' => 2, 'stock_order_status' => 'not_allow']);

        $this->assertFalse($this->stock->decrementProductStock($product, 3)['applied']);
        $this->assertSame(2, (int) Product::find($product->id)->product_stock);
    }

    /**
     * BACKORDERS ARE PRESERVED. A product that permits ordering below stock
     * still decrements, and may still go negative, exactly as before. This test
     * exists so the oversell guard is never widened into a silent removal of
     * the backorder feature.
     */
    public function test_backorder_products_still_decrement_below_zero(): void
    {
        foreach ([null, 'notify_customer'] as $status) {
            $product = $this->makeProduct(['product_stock' => 1, 'stock_order_status' => $status]);

            $result = $this->stock->decrementProductStock($product, 3);

            $this->assertTrue($result['applied'], 'backorder must still be allowed');
            $this->assertSame(-2, (int) Product::find($product->id)->product_stock);
        }
    }

    /**
     * A negative quantity must never reach `decrement()`, which would INCREASE
     * stock — minting inventory from a tampered cart quantity.
     */
    public function test_a_negative_quantity_cannot_mint_stock(): void
    {
        $product = $this->makeProduct(['product_stock' => 5]);

        $result = $this->stock->decrementProductStock($product, -100);

        $this->assertFalse($result['applied']);
        $this->assertSame(ProductStockService::SKIPPED, $result['reason']);
        $this->assertSame(5, (int) Product::find($product->id)->product_stock);
    }

    /** Zero and non-numeric quantities are no-ops, as they were before. */
    public function test_zero_and_nonnumeric_quantities_are_noops(): void
    {
        $product = $this->makeProduct(['product_stock' => 5]);

        foreach ([0, '', 'abc', null] as $qty) {
            $this->assertFalse($this->stock->decrementProductStock($product, $qty)['applied']);
        }

        $this->assertSame(5, (int) Product::find($product->id)->product_stock);
    }

    /**
     * The caller's in-memory model is refreshed, because the low-stock and
     * out-of-stock thresholds immediately after the decrement are evaluated
     * against it — and would otherwise read the pre-order figure.
     */
    public function test_the_in_memory_model_is_refreshed_after_the_decrement(): void
    {
        $product = $this->makeProduct(['product_stock' => 9]);

        $this->stock->decrementProductStock($product, 4);

        $this->assertSame(5, (int) $product->product_stock);

        // And a later save() on the same instance must not write a stale value.
        $product->save();

        $this->assertSame(5, (int) Product::find($product->id)->product_stock);
    }

    /** Stock given back when the order it was taken for cannot be written. */
    public function test_a_decrement_can_be_given_back(): void
    {
        $product = $this->makeProduct(['product_stock' => 10]);

        $result = $this->stock->decrementProductStock($product, 4);
        $this->stock->restoreProductStock($product->id, $result['quantity']);

        $this->assertSame(10, (int) Product::find($product->id)->product_stock);
    }

    /** The variant path behaves identically on its own `stock` column. */
    public function test_variant_stock_is_decremented_atomically(): void
    {
        $product = $this->makeProduct(['product_stock' => 0]);
        $variant = $this->makeVariant($product, ['stock' => 5, 'stock_order_status' => 'not_allow']);

        $this->assertTrue($this->stock->decrementVariantStock($variant, 5)['applied']);
        $this->assertSame(0, (int) ProductVariant::find($variant->id)->stock);

        $this->assertFalse($this->stock->decrementVariantStock($variant, 1)['applied']);
        $this->assertSame(0, (int) ProductVariant::find($variant->id)->stock);
    }

    /**
     * Minimal rows, written through the query builder so that model defaults,
     * observers and the Cachable trait play no part in the fixture.
     */
    private function makeProduct(array $attributes = []): Product
    {
        $id = DB::table('products')->insertGetId(array_merge([
            'name' => 'Stock fixture',
            'track_stock' => 1,
            'stock_order_status' => null,
            'product_stock' => 0,
            'low_stock_threshold' => 0,
            'price' => 0,
            'sale_price' => 0,
            'variant_product' => 0,
            'store_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return Product::findOrFail($id);
    }

    private function makeVariant(Product $product, array $attributes = []): ProductVariant
    {
        $id = DB::table('product_variants')->insertGetId(array_merge([
            'product_id' => $product->id,
            'stock' => 0,
            'variation_option' => 'manage_stock',
            'store_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return ProductVariant::findOrFail($id);
    }
}
