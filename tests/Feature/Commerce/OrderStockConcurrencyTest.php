<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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
 * WHY THIS CLASS BUILDS ITS OWN SCHEMA
 * ------------------------------------
 * It used to use `RefreshDatabase`, and that is why all nine of its tests were
 * ERRORS rather than passes: `RefreshDatabase` runs the WHOLE migration set,
 * and `database/migrations/2024_05_16_054044_add_id_to_currency_table.php`
 * cannot run on sqlite at all —
 *
 *     SQLSTATE[HY000]: General error: 1 Cannot add a PRIMARY KEY column
 *     (alter table "currency" add column "id" integer primary key autoincrement not null)
 *
 * so the suite died during migration and never reached a single assertion. That
 * migration is `database/migrations/**`, which belongs to Agent 2 and is on an
 * unmerged branch, so this file does NOT depend on it being fixed: it creates
 * the two tables it actually needs, with the columns the real migrations
 * declare (including `products.created_by`, which is NOT NULL and which the old
 * fixture omitted — the second reason these rows would not have inserted).
 *
 * The connection is the sqlite/:memory: one that phpunit.xml forces. No
 * persistent database is touched, and nothing here depends on an unmerged
 * branch.
 *
 * @see \App\Services\ProductStockService
 */
class OrderStockConcurrencyTest extends TestCase
{
    private ProductStockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        $this->createMinimalSchema();

        $this->stock = new ProductStockService();
    }

    /**
     * Only the columns `ProductStockService` and the Product/ProductVariant
     * models touch, matching the real migrations' nullability so that a column
     * the production fixture must supply cannot be silently skipped here.
     *
     * `created_by` is NOT NULL in 2023_12_26_092822_create_products_table.php
     * and is reproduced as NOT NULL deliberately.
     */
    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_variants');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('track_stock');            // NOT NULL in the real migration
            $table->string('stock_order_status')->nullable();
            $table->float('price')->default(0);
            $table->float('sale_price')->default(0);
            $table->integer('product_stock')->default(0);
            $table->integer('low_stock_threshold')->default(0);
            $table->string('stock_status')->default(0);
            $table->boolean('variant_product')->default(1);
            $table->double('average_rating', 8, 2)->default(0);
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->integer('created_by');             // NOT NULL in the real migration
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->integer('stock')->default(0);
            $table->string('stock_order_status')->nullable();
            $table->string('variation_option')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_variants');

        parent::tearDown();
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
        $this->assertSame(1, $this->stockOf($product->id));
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

        $this->assertSame(10, (int) $readerA->product_stock);
        $this->assertSame(10, (int) $readerB->product_stock, 'both callers hold the same stale read');

        $this->assertTrue($this->stock->decrementProductStock($readerA, 3)['applied']);
        $this->assertTrue($this->stock->decrementProductStock($readerB, 3)['applied']);

        $this->assertSame(4, $this->stockOf($product->id));
    }

    /**
     * THE OVERSELL RACE, which is the property the single conditional UPDATE
     * actually buys and which the outcome of two decrements cannot show.
     *
     * Ten checkouts all read stock 5 BEFORE any of them writes — the real
     * interleaving — and each then takes one unit. Exactly five may succeed.
     *
     * Under the read-modify-write this replaces, every one of the ten would
     * have succeeded (each computed 5 - 1 = 4 from its own stale read) and the
     * row would have finished at 4 with ten units sold. The assertions below
     * fail loudly in that case: 10 !== 5 applied, and 4 !== 0 remaining.
     */
    public function test_ten_concurrent_checkouts_cannot_oversell_five_units(): void
    {
        $product = $this->makeProduct(['product_stock' => 5, 'stock_order_status' => 'not_allow']);

        // Every caller reads first; nobody has written yet.
        //
        // These are hydrated from raw rows rather than via `Product::find()`,
        // because the model-caching trait hands the SAME object back for
        // repeated finds — ten `find()` calls give ten references to one
        // instance, so a decrement that refreshes it would update all of
        // them and the reads would stop being stale. Ten separate web
        // requests each hold their own object, and that is what is modelled.
        $readers = [];
        for ($i = 0; $i < 10; $i++) {
            $row = DB::table('products')->where('id', $product->id)->first();
            $readers[] = (new Product())->newFromBuilder((array) $row);
        }

        foreach ($readers as $index => $reader) {
            $this->assertSame(5, (int) $reader->product_stock, "reader {$index} must hold the stale read");
        }

        $applied = 0;
        $rejected = 0;

        foreach ($readers as $reader) {
            if ($this->stock->decrementProductStock($reader, 1)['applied']) {
                $applied++;
            } else {
                $rejected++;
            }
        }

        $this->assertSame(5, $applied, 'exactly the five units in stock may be sold');
        $this->assertSame(5, $rejected, 'the other five checkouts must be refused');
        $this->assertSame(0, $this->stockOf($product->id), 'stock must land exactly at zero');
    }

    /**
     * The decrement is ONE statement, and it carries the availability guard in
     * its own WHERE clause.
     *
     * This is the mechanism, asserted directly rather than inferred from an
     * outcome: a decrement split into a SELECT and a later UPDATE would pass
     * the outcome tests above whenever the test runner happens not to
     * interleave them, and would still lose updates in production.
     */
    public function test_the_decrement_is_a_single_guarded_update_statement(): void
    {
        $product = $this->makeProduct(['product_stock' => 5, 'stock_order_status' => 'not_allow']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->stock->decrementProductStock($product, 2);

        $writes = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => stripos(trim($q['query']), 'update') === 0
        ));

        DB::disableQueryLog();

        $this->assertCount(1, $writes, 'the decrement must be exactly one UPDATE');

        $sql = strtolower($writes[0]['query']);

        $this->assertStringContainsString('"product_stock" = "product_stock" -', $sql,
            'the new value must be computed by the database, not in PHP');
        $this->assertStringContainsString('"product_stock" >=', $sql,
            'the availability guard must be in the WHERE clause of that same statement');
    }

    /** Stock never goes negative for a product that forbids overselling. */
    public function test_stock_never_goes_negative_when_forbidden(): void
    {
        $product = $this->makeProduct(['product_stock' => 2, 'stock_order_status' => 'not_allow']);

        $this->assertFalse($this->stock->decrementProductStock($product, 3)['applied']);
        $this->assertSame(2, $this->stockOf($product->id));
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
            $this->assertSame(-2, $this->stockOf($product->id));
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
        $this->assertSame(5, $this->stockOf($product->id));
    }

    /** Zero and non-numeric quantities are no-ops, as they were before. */
    public function test_zero_and_nonnumeric_quantities_are_noops(): void
    {
        $product = $this->makeProduct(['product_stock' => 5]);

        foreach ([0, '', 'abc', null] as $qty) {
            $this->assertFalse($this->stock->decrementProductStock($product, $qty)['applied']);
        }

        $this->assertSame(5, $this->stockOf($product->id));
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

        $this->assertSame(5, $this->stockOf($product->id));
    }

    /** Stock given back when the order it was taken for cannot be written. */
    public function test_a_decrement_can_be_given_back(): void
    {
        $product = $this->makeProduct(['product_stock' => 10]);

        $result = $this->stock->decrementProductStock($product, 4);
        $this->stock->restoreProductStock($product->id, $result['quantity']);

        $this->assertSame(10, $this->stockOf($product->id));
    }

    /** The variant path behaves identically on its own `stock` column. */
    public function test_variant_stock_is_decremented_atomically(): void
    {
        $product = $this->makeProduct(['product_stock' => 0]);
        $variant = $this->makeVariant($product, ['stock' => 5, 'stock_order_status' => 'not_allow']);

        $this->assertTrue($this->stock->decrementVariantStock($variant, 5)['applied']);
        $this->assertSame(0, $this->variantStockOf($variant->id));

        $this->assertFalse($this->stock->decrementVariantStock($variant, 1)['applied']);
        $this->assertSame(0, $this->variantStockOf($variant->id));
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
            'created_by' => 1,
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

    /**
     * Read stock straight from the table.
     *
     * Deliberately NOT `Product::find()`: these models use the model-caching
     * trait, and a cached read could report a value the database does not hold,
     * which is the one thing these assertions must not be able to do.
     */
    private function stockOf($productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('product_stock');
    }

    private function variantStockOf($variantId): int
    {
        return (int) DB::table('product_variants')->where('id', $variantId)->value('stock');
    }
}
