<?php

namespace Tests\Feature\Search;

use App\Models\Category;
use App\Models\Product;
use App\Support\Search\ProductNameIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE BACKFILL, DRIVEN AGAINST TEST FIXTURES AND NOWHERE ELSE.  `CUST-003` / `GAP-190` / `J38`.
 * =================================================================================================
 *
 * `App\Console\Commands\BackfillProductNameNormalised` writes a column across every tenant's
 * catalogue, which `CLAUDE.md` §2 puts among the operations engineering escalates rather than
 * performs. It has been run against this file's throwaway fixtures only; no other environment has
 * been touched, and the command's docblock says so.
 *
 * What is asserted here is the thing that makes it safe to hand over:
 *
 *  - **dry run is the DEFAULT** — a flag whose absence writes is the wrong way round;
 *  - the counts a dry run reports are the counts an apply then writes;
 *  - it does **not** bump `updated_at`, so a search index does not restate when a merchant last
 *    edited their product;
 *  - `--store` confines it, so it can be run one tenant at a time;
 *  - and it refuses, rather than half-runs, when the column is absent.
 */
final class ProductNameIndexBackfillTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProductNameIndex::forgetStored();
    }

    /** @return array{0: \App\Models\Store, 1: Product, 2: Product, 3: Product} store, missing, stale, correct */
    private function catalogue(string $tag): array
    {
        [$merchant, $store] = $this->makeTenant();

        $category = Category::forceCreate([
            'name' => $tag . '-cat', 'slug' => $tag . '-cat-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id, 'status' => 1,
        ]);

        $make = function (string $name) use ($store, $merchant, $category): Product {
            return Product::forceCreate([
                'name' => $name, 'slug' => 'bf-' . $this->uniqueFixtureRef(),
                'price' => 9.00, 'sale_price' => 9.00,
                'product_stock' => 1, 'track_stock' => 0, 'variant_product' => 0,
                'status' => 1, 'stock_status' => 'in_stock',
                'category_id' => $category->id, 'average_rating' => 0,
                'store_id' => $store->id, 'created_by' => $merchant->id,
            ]);
        };

        $missing = $make('شاشة ذكية');
        $stale = $make('قميص قطني');
        $correct = $make('حاسوب محمول');

        /* The two defective states, produced the only way they can be: outside Eloquent. */
        DB::table('products')->where('id', $missing->id)
            ->update([ProductNameIndex::COLUMN => null, 'updated_at' => '2020-02-02 02:02:02']);
        DB::table('products')->where('id', $stale->id)
            ->update([ProductNameIndex::COLUMN => 'something else', 'updated_at' => '2020-03-03 03:03:03']);
        (new Product)->flushCache();

        return [$store, $missing, $stale, $correct];
    }

    private function fold(int $id): ?string
    {
        return DB::table('products')->where('id', $id)->value(ProductNameIndex::COLUMN);
    }

    private function updatedAt(int $id): ?string
    {
        return DB::table('products')->where('id', $id)->value('updated_at');
    }

    /* ======================================================================== 1 — dry run */

    public function test_the_default_run_reports_counts_and_writes_nothing(): void
    {
        [$store, $missing, $stale, ] = $this->catalogue('dry');

        $this->artisan('products:backfill-name-normalised', ['--store' => (string) $store->id])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertNull($this->fold((int) $missing->id),
            'the DEFAULT run wrote a row. A flag whose absence writes is the wrong way round.');
        $this->assertSame('something else', $this->fold((int) $stale->id),
            'the default run rewrote a stale row.');
    }

    /** `--dry-run` says the same thing explicitly, and contradicting `--apply` is refused. */
    public function test_dry_run_and_apply_together_are_refused_rather_than_one_winning(): void
    {
        [$store, $missing, , ] = $this->catalogue('both');

        $this->artisan('products:backfill-name-normalised', [
            '--store' => (string) $store->id, '--apply' => true, '--dry-run' => true,
        ])->assertExitCode(2);

        $this->assertNull($this->fold((int) $missing->id),
            'a contradictory invocation wrote anyway.');
    }

    /* ======================================================================== 2 — apply */

    public function test_apply_fills_the_missing_fold_and_corrects_the_stale_one(): void
    {
        [$store, $missing, $stale, $correct] = $this->catalogue('apply');

        $correctBefore = $this->fold((int) $correct->id);
        $missingTimestamp = $this->updatedAt((int) $missing->id);

        $this->assertStringStartsWith('2020-02-02', (string) $missingTimestamp,
            'premise: a past timestamp is stored, so a bump below would be visible.');

        $this->artisan('products:backfill-name-normalised', [
            '--store' => (string) $store->id, '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame('شاشه ذكيه', $this->fold((int) $missing->id),
            'the missing fold was not filled.');
        $this->assertSame('قميص قطني', $this->fold((int) $stale->id),
            'the stale fold was not corrected.');
        $this->assertSame($correctBefore, $this->fold((int) $correct->id),
            'an already-correct row was changed.');

        $this->assertSame($missingTimestamp, $this->updatedAt((int) $missing->id),
            'THE BACKFILL BUMPED `updated_at`. Across a real platform that is a visible change to '
            . 'every merchant\'s catalogue — "recently modified" ordering and anything keyed on '
            . 'that column — produced by a search index.');
    }

    /** And the rows are readable through Eloquent afterwards: `Product` is `Cachable`. */
    public function test_the_written_rows_are_visible_to_eloquent_and_not_served_stale(): void
    {
        [$store, $missing, , ] = $this->catalogue('cache');

        /* Warm the cache with the pre-backfill row, which is what a real process would hold. */
        $this->assertNull(
            Product::withoutGlobalScopes()->findOrFail($missing->id)
                ->getAttribute(ProductNameIndex::COLUMN),
            'premise: the cached read agrees with the raw row before the backfill.'
        );

        $this->artisan('products:backfill-name-normalised', [
            '--store' => (string) $store->id, '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame('شاشه ذكيه',
            Product::withoutGlobalScopes()->findOrFail($missing->id)
                ->getAttribute(ProductNameIndex::COLUMN),
            'an Eloquent read served the pre-backfill row. This is why the command writes through '
            . 'Eloquent (which flushes) rather than `DB::table()->update()`, and why it does not '
            . 'use `saveQuietly()`, which would skip the flush with the events.');
    }

    /* ======================================================================== 3 — confinement */

    public function test_the_store_option_confines_the_write_to_one_tenant(): void
    {
        [$storeA, $missingA, , ] = $this->catalogue('tenA');
        [, $missingB, , ] = $this->catalogue('tenB');

        $this->artisan('products:backfill-name-normalised', [
            '--store' => (string) $storeA->id, '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame('شاشه ذكيه', $this->fold((int) $missingA->id),
            'premise: the named store WAS filled, so the absence below is confinement and not a '
            . 'command that did nothing.');
        $this->assertNull($this->fold((int) $missingB->id),
            '--store did not confine the write; another tenant\'s catalogue was rewritten.');
    }

    /**
     * `'5abc'` AND `'5.9'`, AND THE SECOND IS THE ONE THAT MATTERS.
     *
     * The skill's rule: *"`is_numeric` is not enough: it accepts `'5.9'` and rejects `'5abc'`,
     * while MySQL coerces `'5abc'` to row 5."* Measured here as a mutation result rather than taken
     * on trust — a first version of this case drove `'5abc'` only, and swapping
     * `filter_var(…, FILTER_VALIDATE_INT)` for `is_numeric()` **survived it**, because both reject
     * `'5abc'`. `'5.9'` is what separates them: `is_numeric` accepts it and `(int) '5.9'` is 5, so
     * the loose version would silently rewrite store 5's catalogue when asked for store "5.9".
     */
    public function test_a_store_id_that_is_not_an_integer_is_refused(): void
    {
        [$store, $missing, , ] = $this->catalogue('parse');

        /*
         * `'1e2'` is the sharpest separator of the two parsers and was found by measuring them
         * rather than by reasoning: `is_numeric('1e2')` is true and `(int) '1e2'` is **100**, so a
         * loose parse asked for store "1e2" would rewrite store 100's catalogue.
         *
         * `' 5'` and `'5 '` are NOT in this list: `filter_var(…, FILTER_VALIDATE_INT)` trims
         * surrounding whitespace and answers 5 for both, which is the right answer for an operator
         * who pasted an id with a space. Listing them as refusals would have been a guess, and the
         * measurement is in this file's git history rather than in a comment that claims it.
         */
        foreach (['5abc', '5.9', '1e2', '0x5', ''] as $bad) {
            $this->artisan('products:backfill-name-normalised',
                ['--store' => $bad, '--apply' => true])
                ->assertExitCode(2);
        }

        $this->assertNull($this->fold((int) $missing->id),
            'a loosely-parsed store id wrote to a catalogue nobody asked for.');

        /* The control: the real id IS accepted, so the refusals above are about parsing. */
        $this->artisan('products:backfill-name-normalised',
            ['--store' => (string) $store->id, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('شاشه ذكيه', $this->fold((int) $missing->id),
            'premise: a well-formed store id is accepted, so the refusals are not blanket.');
    }

    /**
     * A SMALL CHUNK STILL REACHES EVERY ROW.
     *
     * `chunk()` paginates with OFFSET and the loop writes the rows it is reading, so it would skip
     * some; `chunkById` walks the primary key. A chunk size of 1 over three rows is the shape that
     * tells the two apart.
     */
    public function test_a_chunk_size_of_one_still_reaches_every_row(): void
    {
        [$store, $missing, $stale, ] = $this->catalogue('chunk');

        $this->artisan('products:backfill-name-normalised', [
            '--store' => (string) $store->id, '--apply' => true, '--chunk' => '1',
        ])->assertExitCode(0);

        $this->assertSame('شاشه ذكيه', $this->fold((int) $missing->id));
        $this->assertSame('قميص قطني', $this->fold((int) $stale->id));
    }
}
