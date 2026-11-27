<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TWO WRITES THAT LOOK IDENTICAL, AND ONLY ONE LEAVES A STALE CACHED READ.
 *
 * CLAUDE.md §1 records that a raw write "leaves Eloquent reads, route-model binding included,
 * serving the pre-write row", with three measured false readings behind it. It names
 * `DB::table('products')->update()` as the shape. It does NOT distinguish
 * `Model::query()->decrement()`, which fires no model event either and therefore looks like the
 * same hazard -- and `App\Services\ProductStockService` performs exactly that, five times, on
 * the stock column.
 *
 * MEASURED 2026-11-27, both directions, and they differ:
 *
 *     Product::query()->whereKey($id)->decrement(...)   Eloquent reads 7, table holds 7   FLUSHED
 *     DB::table('products')->where(...)->decrement(...)  Eloquent reads 10, table holds 7  STALE
 *
 * The reason is that `Product::query()` returns the caching package's `CachedBuilder`, which
 * invalidates on its own write methods; `DB::table()` never reaches the model layer at all.
 *
 * WHY THIS IS WORTH A TEST RATHER THAN A NOTE. The question arrives as a lead on a MONEY path --
 * "do stock decrements leave a stale read, i.e. can a later request oversell?" -- and the answer
 * is no. Without a measurement that negative decays, and it is wrong in BOTH directions to guess:
 * adding a manual `flushCache()` beside those five writes is harmless noise, but "simplifying"
 * one of them to `DB::table()` on the belief that they are equivalent would introduce the stale
 * read that is not there today. This asserts the distinction so neither move is made blind.
 */
class BuilderWriteCacheFlushTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $slug): int
    {
        return DB::table('products')->insertGetId([
            'name' => 'cache probe',
            'store_id' => 1,
            'created_by' => 1,
            'product_stock' => 10,
            'track_stock' => 1,
            'status' => 1,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_model_builder_decrement_does_not_leave_a_stale_cached_read(): void
    {
        $id = $this->product('cache-probe-model');
        (new Product)->flushCache();

        /* Warm the cache the way an earlier request in the same checkout would. */
        $this->assertSame(10, (int) Product::find($id)->product_stock, 'PREMISE: the warming read '
            . 'did not see the planted stock, so nothing below is measuring the cache.');

        Product::query()->whereKey($id)->decrement('product_stock', 3);

        $this->assertSame(
            7,
            (int) DB::table('products')->where('id', $id)->value('product_stock'),
            'PREMISE: the decrement did not reach the table.'
        );

        $this->assertSame(
            7,
            (int) Product::find($id)->product_stock,
            'A later Eloquent read saw the PRE-write stock after a model-builder decrement. That '
            . 'would make ProductStockService a stale-read oversell path. It is not one today '
            . 'because `Product::query()` returns the caching package\'s CachedBuilder.'
        );
    }

    public function test_a_raw_db_decrement_DOES_leave_a_stale_cached_read(): void
    {
        $id = $this->product('cache-probe-raw');
        (new Product)->flushCache();

        $this->assertSame(10, (int) Product::find($id)->product_stock, 'PREMISE: the warming read '
            . 'did not see the planted stock.');

        DB::table('products')->where('id', $id)->decrement('product_stock', 3);

        $this->assertSame(
            7,
            (int) DB::table('products')->where('id', $id)->value('product_stock'),
            'PREMISE: the raw decrement did not reach the table.'
        );

        /*
         * THE CONVERSE, AND IT IS THE HALF THAT KEEPS THE FIRST CASE HONEST. If this ever stops
         * being stale, the distinction above has dissolved and the paragraph in CLAUDE.md that
         * rests on it -- plus three recorded false readings -- needs re-measuring rather than
         * trusting.
         */
        $this->assertSame(
            10,
            (int) Product::find($id)->product_stock,
            'A raw DB::table() decrement no longer leaves a stale cached read. That is a CHANGE in '
            . 'the caching package\'s behaviour, not a fix: re-measure CLAUDE.md §1\'s model-caching '
            . 'paragraph, which three measured false readings rest on, before relying on either shape.'
        );
    }
}
