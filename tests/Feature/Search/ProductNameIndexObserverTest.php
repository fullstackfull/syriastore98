<?php

namespace Tests\Feature\Search;

use App\Models\Category;
use App\Models\Product;
use App\Support\Search\ProductNameIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE ONE HOOK THAT FILLS `products.name_normalised`, AND THE FOUR CASES IT HAS TO GET RIGHT.
 * =================================================================================================
 * `CUST-003` / `GAP-190` / owner decision `J38`.
 *
 * `App\Observers\ProductNameIndexObserver` runs on `Product::saving`, which is the only place that
 * sees all fifteen writers of `products.name` — six of them inside `packages/workdo/`, which must
 * not be edited. Its docblock carries the census; this file drives the behaviour.
 *
 * THE CASE THAT IS EASY TO GET WRONG is the fourth: a hook that fills a NULL fold
 * **unconditionally** turns every `$product->save()` on a legacy row into a real UPDATE and bumps
 * `updated_at` across the whole catalogue, because Laravel issues no statement at all when nothing
 * is dirty. That is a visible data change — "recently modified" ordering, anything reading the
 * column — produced by a feature that is supposed to touch nothing but search.
 */
final class ProductNameIndexObserverTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProductNameIndex::forgetStored();
    }

    private function product(string $name): Product
    {
        [$merchant, $store] = $this->makeTenant();

        $category = Category::forceCreate([
            'name' => 'obs-cat', 'slug' => 'obs-cat-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id, 'status' => 1,
        ]);

        return Product::forceCreate([
            'name' => $name, 'slug' => 'obs-' . $this->uniqueFixtureRef(),
            'price' => 10.00, 'sale_price' => 10.00,
            'product_stock' => 5, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'stock_status' => 'in_stock',
            'category_id' => $category->id, 'average_rating' => 0,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
    }

    /** Read raw: `Product` is `Cachable`, and the column is what is being asserted about. */
    private function storedFold(int $id): ?string
    {
        return DB::table('products')->where('id', $id)->value(ProductNameIndex::COLUMN);
    }

    private function storedUpdatedAt(int $id): ?string
    {
        return DB::table('products')->where('id', $id)->value('updated_at');
    }

    /* ======================================================================== 1 */

    public function test_the_fold_is_written_when_a_product_is_created(): void
    {
        $product = $this->product('شاشة ذكية');

        $this->assertSame('شاشه ذكيه', $this->storedFold((int) $product->id),
            'the fold was not written on INSERT. `wasChanged()` is false on a freshly inserted row, '
            . 'which is why this hook is on `saving` and not on `saved`.');
    }

    /* ======================================================================== 2 */

    public function test_the_fold_is_rewritten_when_the_name_changes(): void
    {
        $product = $this->product('شاشة ذكية');

        $product->name = 'مفتاح ٥';
        $product->save();

        $this->assertSame('مفتاح 5', $this->storedFold((int) $product->id),
            'the fold still holds the OLD name. A stale fold is not a loss of rows — the raw limb '
            . 'is the authority — but it is a silent one, so it is asserted.');
    }

    /* ======================================================================== 3 */

    /**
     * THE FREE INCREMENTAL BACKFILL: a row already being written for another reason gets its fold.
     *
     * This is what makes the owner-run backfill a convenience rather than a precondition.
     */
    public function test_a_row_already_being_written_for_another_reason_gets_its_missing_fold(): void
    {
        $product = $this->product('قميص قطني');

        DB::table('products')->where('id', $product->id)
            ->update([ProductNameIndex::COLUMN => null]);
        (new Product)->flushCache();

        $this->assertNull($this->storedFold((int) $product->id),
            'premise: the fold really is NULL, which is the state of every row before the backfill.');

        $fresh = Product::withoutGlobalScopes()->findOrFail($product->id);
        $fresh->price = 11.00;
        $fresh->save();

        $this->assertSame('قميص قطني', $this->storedFold((int) $product->id),
            'a product saved for an unrelated reason did not pick up its missing fold.');
    }

    /* ======================================================================== 4 */

    /**
     * AND A SAVE WITH NOTHING DIRTY STAYS A NO-OP — NO UPDATE, NO `updated_at` BUMP.
     *
     * Driven on a row whose fold is NULL, because that is the only state in which an
     * unconditional hook would fire. A past `updated_at` is written with `timestamps = false`, the
     * trick `CLAUDE.md` records for exactly this.
     */
    public function test_a_save_with_nothing_dirty_does_not_become_a_write(): void
    {
        $product = $this->product('حاسوب محمول');

        DB::table('products')->where('id', $product->id)->update([
            ProductNameIndex::COLUMN => null,
            'updated_at' => '2020-01-01 00:00:00',
        ]);
        (new Product)->flushCache();

        $before = $this->storedUpdatedAt((int) $product->id);

        $this->assertStringStartsWith('2020-01-01', (string) $before,
            'premise: the past timestamp is really stored, so a bump below would be visible.');

        $fresh = Product::withoutGlobalScopes()->findOrFail($product->id);

        $this->assertFalse($fresh->isDirty(),
            'premise: nothing is dirty, so Laravel would issue no statement at all.');

        $fresh->save();

        $this->assertSame($before, $this->storedUpdatedAt((int) $product->id),
            'a save with nothing dirty bumped `updated_at`. An unconditional fold write does that '
            . 'to every legacy row on the platform, which is a data change dressed as a search '
            . 'feature.');

        $this->assertNull($this->storedFold((int) $product->id),
            'and the fold stays NULL, which the raw `name LIKE` limb covers. The backfill command '
            . 'is what fills these deliberately, under the owner\'s hand.');
    }

    /* ======================================================================== 5 */

    /** An empty name stores NULL, not `''` — absence is not a value. */
    public function test_an_empty_name_stores_null_rather_than_an_empty_string(): void
    {
        $product = $this->product('x');

        $product->name = '';
        $product->save();

        $this->assertNull($this->storedFold((int) $product->id),
            'an empty name must fold to NULL. `\'\'` would be a stored claim that the fold of this '
            . 'name is the empty string, and `LIKE \'%%\'` matches every row.');
    }

    /* ======================================================================== 6 — no column */

    /**
     * **A DATABASE WITH NO FOLD COLUMN MUST STILL BE ABLE TO SAVE A PRODUCT, AND STILL SEARCH.**
     *
     * This is the state of **production** until the owner runs
     * `2026_11_27_000100_add_name_normalised_to_products_table.php`, and it is the one way this
     * slice could break WRITING rather than merely fail to improve searching: setting an attribute
     * for a column that is not there makes every product save on the platform throw.
     *
     * The suite always migrates, so without this case `ProductNameIndex::isStored()` is never false
     * and the guard that protects that state is untested — a mutation removing it survives. The
     * column is therefore dropped inside the test, which is the only way to produce the state.
     */
    public function test_a_database_with_no_fold_column_still_saves_and_still_searches(): void
    {
        $product = $this->product('شاشة ذكية');

        \Illuminate\Support\Facades\Schema::table('products', function ($table) {
            $table->dropColumn(ProductNameIndex::COLUMN);
        });
        ProductNameIndex::forgetStored();

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('products', ProductNameIndex::COLUMN),
            'premise: the column really is gone, so this case measures the guard.'
        );
        $this->assertFalse(ProductNameIndex::isStored(),
            'premise: the memo was cleared and the probe now answers false.');

        (new Product)->flushCache();

        $fresh = Product::withoutGlobalScopes()->findOrFail($product->id);
        $fresh->name = 'قميص جديد';
        $fresh->save();

        $this->assertSame('قميص جديد',
            DB::table('products')->where('id', $product->id)->value('name'),
            'a product could not be renamed on a database without the fold column. That is every '
            . 'product save on the platform, until the migration runs.');

        /* And the search predicate emits today's single-limb query rather than a broken one. */
        $query = DB::table('products')->where('id', $product->id);
        ProductNameIndex::applyNameFilter($query, 'قميص');

        $this->assertStringNotContainsString(ProductNameIndex::COLUMN, $query->toSql(),
            'the predicate named a column this database does not have.');
        $this->assertSame(1, $query->count(),
            'the single-limb query did not answer. Search must degrade to today, not to nothing.');
    }

    /**
     * AND AN UNREADABLE SCHEMA ANSWERS `false`, NOT `true`.
     *
     * `isStored()` decides whether the folded limb is emitted and whether the observer sets the
     * attribute, so its failure direction is a real choice. `false` means "behave exactly as the
     * platform behaved before this slice"; `true` would mean "emit a predicate naming a column
     * that may not exist" — a broken query on a storefront door, and an attribute write that
     * throws on every product save. This gate WIDENS a result set, so it fails open on the result
     * and closed on the column, which is the reverse of `RefundPolicy::allows()` and the same
     * direction as `OrderReturnInspection::blocksRestock()`.
     *
     * The error is produced with a connection that cannot be opened, because `Schema::hasColumn()`
     * on a merely MISSING table returns `false` without throwing — so a dropped table cannot reach
     * the `catch`.
     */
    public function test_an_unreadable_schema_answers_that_the_column_is_absent(): void
    {
        $original = config('database.default');

        config(['database.connections.fold_unreadable' => [
            'driver' => 'sqlite',
            'database' => '/proc/self/no/such/directory/db.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::setDefaultConnection('fold_unreadable');
        ProductNameIndex::forgetStored();

        try {
            $threw = false;

            try {
                \Illuminate\Support\Facades\Schema::hasColumn('products', ProductNameIndex::COLUMN);
            } catch (\Throwable) {
                $threw = true;
            }

            $this->assertTrue($threw,
                'premise: this connection really does throw when the schema is read. Without that '
                . 'the case never reaches the catch and measures nothing.');

            ProductNameIndex::forgetStored();

            $this->assertFalse(ProductNameIndex::isStored(),
                'an unreadable schema answered that the column EXISTS. That emits a predicate '
                . 'naming a column which may not be there — a 500 on a storefront search — and '
                . 'makes the observer set an attribute that can break every product save.');
        } finally {
            DB::setDefaultConnection($original);
            ProductNameIndex::forgetStored();
        }
    }

    /* ======================================================================== 7 */

    /**
     * THE MASS-ASSIGNMENT PATH, WHICH IS THE ONE WRITER THIS WORKSTREAM CANNOT EDIT.
     *
     * `packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php` builds
     * `new Product(['name' => $row['product_name'], …])` — the name arrives from a spreadsheet cell
     * with no uploader, no controller and no validator in between. The observer has to see it, and
     * a constructor array is a different code path from an attribute assignment.
     */
    public function test_a_mass_assigned_name_is_folded_too(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $category = Category::forceCreate([
            'name' => 'mass-cat', 'slug' => 'mass-cat-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id, 'status' => 1,
        ]);

        $product = new Product([
            'name' => 'مسوّدة ٱلأطفال',
            'slug' => 'mass-' . $this->uniqueFixtureRef(),
            'category_id' => $category->id,
            'price' => 1, 'sale_price' => 1, 'status' => 1,
            'track_stock' => 0, 'product_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $store->id,
        ]);
        $product->created_by = $merchant->id;
        $product->save();

        $this->assertSame('مسوده الاطفال', $this->storedFold((int) $product->id),
            'the CSV importer\'s mass-assignment path wrote a name with no fold.');
    }
}
