<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\StoreInventoryValuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-150 item 1 / `P05-02` -- A CHARACTERISATION OF /finance's INVENTORY FIGURES.
 * =================================================================================================
 *
 * The approved requirement is that **no controller computes money inline**: the metric layer is the
 * single definition. `StoreFinanceController::index()` computed the store's inventory units, cost
 * and retail value in a nested PHP loop over every product and, for a variant product, over every
 * variant. This file exists so that moving that arithmetic into
 * `App\Support\Metrics\StoreInventoryValuation` can be shown to have changed **no figure**.
 *
 * IT IS WRITTEN AND RUN BEFORE THE EXTRACTION, AND NOT ADJUSTED AFTER IT. Every number below was
 * measured against the inline loop at HEAD. A refactor of a money surface that quietly moves a
 * figure is worse than the loop it replaces, so if one of these moves the extraction is wrong --
 * the expectation is not. The fixture GREW after the extraction, twice, because a mutation of the
 * metric survived without the missing case -- and each time the new expectations were re-measured
 * against the inline loop before being accepted, not taken from the new code. The two sides agree
 * on all seven figures.
 *
 * IT DRIVES THE REAL SCREEN, not the metric. `$this->get('/finance')` through the merchant's own
 * session with the real `View Finance Dashboard` permission, asserting the seven view variables the
 * Blade actually prints (`finance/index.blade.php:172-239`). A test against the new class alone
 * would pass while the controller still ran its loop, which is the one failure mode that matters
 * here. Three methods at the end DO read the class directly, and say why: they cover guarantees
 * the screen cannot reach, because it only ever passes one store and never sees the caveats.
 *
 * ONE FIXTURE EXERCISES EVERY BRANCH THE LOOP HAD, because the figures are sums and a per-branch
 * fixture cannot show that two branches still add up together:
 *
 *   #1 simple product on sale            price 100, sale 80, cost 60, stock 10
 *   #2 simple product NOT on sale        price  25, sale  0, cost 7.50, stock 4   (the `sale_price`
 *                                        column is NOT NULL DEFAULT 0, so this is the ordinary case)
 *   #3 simple product with NO cost       price  70, cost 0.00, stock 3            (the zero-versus-
 *                                        unknown case -- see below)
 *   #4 simple product OUT OF STOCK       price 999, cost 500, stock 0             (excluded entirely)
 *   #5 variant product, costed           cost 5, four variants: priced/unpriced/zero-stock/null-stock
 *   #6 variant product with NO cost      cost 0.00, one variant, stock 5
 *   #7 variant product with NO VARIANTS  cost 0.00, product_stock 50              (contributes
 *                                        nothing: the branch is chosen by `variant_product`, never
 *                                        by where the stock happens to sit)
 *   #8 SIMPLE product with a variant row price 40, cost 2, stock 9, plus one variant row of its
 *                                        own, which the loop never reads -- the mirror of #7
 *   #9 variant product, only variant     cost 0.00, one variant, stock 0          (contributes
 *      out of stock                      nothing, and is NOT a valued or an uncosted product)
 *
 * #8 and #9 were added AFTER the extraction, each because a mutation of the metric survived without
 * it: a variant query missing `variant_product = 1` valued #8's stray row, and one relaxing
 * `stock > 0` counted #9 as valued and uncosted. Both are recorded here rather than quietly added,
 * because a fixture that grew to catch a mutation is the honest history of this test.
 *
 * WHY #3 AND #6 ARE NOT AN EDGE CASE. `products.cost_price` is `decimal(15,2) NOT NULL DEFAULT 0.00`
 * -- measured on MariaDB 10.11, and recorded in `docs/GATE-F-VERIFIED-SCHEMA.md` from a restored
 * production dump -- so "this item cost nothing" and "nobody ever entered a cost" are THE SAME
 * STORED VALUE. Such a product contributes its whole retail value to Potential Profit with nothing
 * subtracted. The figure cannot be corrected without the missing data, so the controller already
 * qualifies it with a measured count and amount; those two numbers are characterised here too,
 * because an extraction that got the money right and the disclosure wrong would be just as bad.
 *
 * WHY THERE IS A SECOND STORE. `Product` and `Category` carry `StoreScope` but are absent from
 * `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and **no
 * global scope adds `store_id` for any principal** -- `getGlobalScopes()` answering `[StoreScope]`
 * is the wrong question (CLAUDE.md). The predicate is hand-written, and an extraction that drops it
 * reports the whole platform's capital under one merchant's heading without failing. Store B holds
 * deliberately large, distinctive figures so a leak is unmistakable, and it also holds
 * **a variant row of its own attached to store A's product** -- the cross-pairing the inline loop
 * excluded with a second `where('store_id', ...)` on the variant query, which a join written from
 * `product_id` alone would silently admit.
 *
 * FIXTURES ARE WRITTEN THROUGH ELOQUENT. `Product` and `ProductVariant` are both `Cachable`, and a
 * raw `DB::table()->insert()` leaves the controller's own read serving a pre-write row for the rest
 * of the test under `CACHE_STORE=array` (CLAUDE.md, model caching).
 */
class StoreInventoryValuationMetricTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The one permission `StoreFinanceController::index()` gates on. */
    private const FINANCE_PERMISSIONS = ['View Finance Dashboard'];

    /**
     * THE FIGURES AS THE INLINE LOOP PRODUCED THEM, hand-computed from the fixture above and
     * confirmed by execution against HEAD before anything was extracted.
     *
     *   units  = 10 + 4 + 3 + 8 + 5 + 9                        = 39
     *   cost   = 60*10 + 7.50*4 + 0*3 + 5*8 + 0*5 + 2*9        = 688.00
     *   retail = 80*10 + 25*4 + 70*3 + (80*2 + 50*6) + 15*5 + 40*9
     *          = 800 + 100 + 210 + 460 + 75 + 360              = 2005.00
     *   profit = 2005.00 - 688.00                              = 1317.00
     *   valued products   = #1 #2 #3 #5 #6 #8                  = 6
     *   uncosted products = #3 #6                              = 2
     *   uncosted retail   = 210 + 75                           = 285.00
     *
     * #4 (out of stock), #7 (a variant product with no variants) and #9 (a variant product whose
     * only variant is out of stock) contribute to nothing; #8's stray variant row is not read.
     */
    private const EXPECTED = [
        'inventoryUnits' => 39,
        'inventoryCost' => 688.00,
        'inventoryRetailValue' => 2005.00,
        'inventoryPotentialProfit' => 1317.00,
        'inventoryValuedProducts' => 6,
        'inventoryUncostedProducts' => 2,
        'inventoryUncostedRetail' => 285.00,
    ];

    /** @return array{0: User, 1: Store} */
    private function merchantOnFinance(): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (self::FINANCE_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name], ['display_name' => $name]);
            $this->grantPermission(User::find($merchant->id), $name);
        }

        /* Several handlers read the platform admin through Cache::remember('admin_details'). */
        Cache::forget('admin_details');

        return [User::find($merchant->id), $store];
    }

    private function makeProduct(Store $store, array $attributes): Product
    {
        $name = 'GAP-150 Product ' . uniqid();
        $catName = 'GAP-150 Cat ' . uniqid();

        $category = Category::forceCreate([
            'name' => $catName,
            'slug' => Str::slug($catName) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ]);

        return Product::forceCreate(array_merge([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . uniqid(),
            'description' => 'x', 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $category->id,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ], $attributes));
    }

    private function makeVariant(Product $product, int $storeId, array $attributes): ProductVariant
    {
        return ProductVariant::forceCreate(array_merge([
            'product_id' => $product->id,
            'variant' => 'V-' . uniqid(),
            'sku' => 'SKU-' . uniqid(),
            'store_id' => $storeId,
        ], $attributes));
    }

    /**
     * The whole fixture, for store A and for the foreign store B that must stay out of A's figures.
     *
     * @return array{0: User, 1: Store, 2: Store}
     */
    private function seedCatalogues(): array
    {
        [$merchantA, $storeA] = $this->merchantOnFinance();
        [, $storeB] = $this->merchantOnFinance();

        /* #1 on sale: valued at the sale price. */
        $this->makeProduct($storeA, [
            'price' => 100, 'sale_price' => 80, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        /* #2 not on sale -- `sale_price` takes its NOT NULL 0 default, as a real product does. */
        $this->makeProduct($storeA, [
            'price' => 25, 'cost_price' => 7.50, 'product_stock' => 4,
        ]);

        /* #3 no cost recorded, which the column cannot distinguish from a cost of zero. */
        $this->makeProduct($storeA, [
            'price' => 70, 'cost_price' => 0, 'product_stock' => 3,
        ]);

        /* #4 out of stock: contributes nothing and is not among the valued products. */
        $this->makeProduct($storeA, [
            'price' => 999, 'cost_price' => 500, 'product_stock' => 0,
        ]);

        /* #5 variant product. The parent row's own price and stock are IGNORED by this branch. */
        $costedVariantProduct = $this->makeProduct($storeA, [
            'price' => 1, 'sale_price' => 0, 'cost_price' => 5,
            'product_stock' => 77, 'variant_product' => 1,
        ]);

        /* its own variation price wins */
        $this->makeVariant($costedVariantProduct, $storeA->id, [
            'price' => 100, 'variation_price' => 80, 'stock' => 2,
        ]);

        /* `variation_price` IS nullable, so this one falls through to the variant's `price` */
        $this->makeVariant($costedVariantProduct, $storeA->id, [
            'price' => 50, 'variation_price' => null, 'stock' => 6,
        ]);

        /* zero stock: skipped */
        $this->makeVariant($costedVariantProduct, $storeA->id, [
            'price' => 40, 'variation_price' => 30, 'stock' => 0,
        ]);

        /* null stock, where the column allows it: `(int) (null ?? 0)` is 0, so also skipped */
        $this->makeNullStockVariant($costedVariantProduct, $storeA->id);

        /* #6 variant product with no cost recorded. */
        $uncostedVariantProduct = $this->makeProduct($storeA, [
            'price' => 1, 'cost_price' => 0, 'product_stock' => 0, 'variant_product' => 1,
        ]);

        $this->makeVariant($uncostedVariantProduct, $storeA->id, [
            'price' => 15, 'variation_price' => null, 'stock' => 5,
        ]);

        /* #7 declared a variant product and given none: the parent's 50 units are NOT valued. */
        $this->makeProduct($storeA, [
            'price' => 12, 'cost_price' => 0, 'product_stock' => 50, 'variant_product' => 1,
        ]);

        /*
         * #8 A SIMPLE PRODUCT THAT NEVERTHELESS HAS A VARIANT ROW -- the mirror of #7, and added
         * after a mutation survived without it. The loop reached `ProductVariant` only inside
         * `if ($product->variant_product == 1)`, so this product's 9 units are valued from its
         * PARENT row and its variant row is never read at all. A variant query that joined on
         * `product_id` and forgot `variant_product = 1` would value both -- 13 more units and
         * 6,500.00 more retail from one row -- and nothing else in this fixture would notice,
         * because every other product with variant rows is declared a variant product.
         */
        $simpleProductWithAStrayVariant = $this->makeProduct($storeA, [
            'price' => 40, 'cost_price' => 2, 'product_stock' => 9, 'variant_product' => 0,
        ]);

        $this->makeVariant($simpleProductWithAStrayVariant, $storeA->id, [
            'price' => 500, 'variation_price' => null, 'stock' => 13,
        ]);

        /*
         * #9 A VARIANT PRODUCT WHOSE ONLY VARIANT IS OUT OF STOCK, and uncosted besides. Also
         * added after a mutation survived: #5's zero-stock variants sit beside in-stock ones, so
         * relaxing `stock > 0` to `>= 0` changed no count. This product has nothing else, so a
         * relaxed predicate makes it a VALUED product and an UNCOSTED one, moving two counters.
         */
        $noStockVariantProduct = $this->makeProduct($storeA, [
            'price' => 1, 'cost_price' => 0, 'product_stock' => 0, 'variant_product' => 1,
        ]);

        $this->makeVariant($noStockVariantProduct, $storeA->id, [
            'price' => 33, 'variation_price' => null, 'stock' => 0,
        ]);

        /* ---- store B, which must appear in nothing above ---- */

        $this->makeProduct($storeB, [
            'price' => 999, 'cost_price' => 500, 'product_stock' => 7,
        ]);

        $foreignVariantProduct = $this->makeProduct($storeB, [
            'price' => 1, 'cost_price' => 0, 'product_stock' => 0, 'variant_product' => 1,
        ]);

        $this->makeVariant($foreignVariantProduct, $storeB->id, [
            'price' => 123, 'variation_price' => null, 'stock' => 11,
        ]);

        /*
         * THE CROSS-PAIRING. A variant row owned by store B, hanging off STORE A's variant
         * product. `product_variants.store_id` and `products.store_id` are two independent
         * columns and nothing in the schema forces them to agree, so a join written from
         * `product_id` alone would value store B's row into store A's capital. The inline loop
         * excluded it with a second `where('store_id', $storeId)` on the variant query.
         */
        $this->makeVariant($costedVariantProduct, $storeB->id, [
            'price' => 1000, 'variation_price' => null, 'stock' => 100,
        ]);

        return [$merchantA, $storeA, $storeB];
    }

    /**
     * A variant whose `stock` is NULL where the engine permits it, and 0 where it does not.
     *
     * `create_product_variants_table` declares `integer('stock')->default(0)` -- NOT NULL -- so on
     * the suite's SQLite the null insert is rejected outright. The inline loop read
     * `(int) ($variant->stock ?? 0)`, so the branch exists in the code either way, and the
     * REACHABLE equivalent (a variant that records no usable stock) is what is seeded when the
     * column refuses null. `test_the_null_stock_variant_premise` states which of the two happened
     * rather than leaving the fixture to claim a case it did not create.
     */
    private function makeNullStockVariant(Product $product, int $storeId): void
    {
        try {
            $this->makeVariant($product, $storeId, [
                'price' => 20, 'variation_price' => 10, 'stock' => null,
            ]);
        } catch (\Throwable $e) {
            $this->makeVariant($product, $storeId, [
                'price' => 20, 'variation_price' => 10, 'stock' => 0,
            ]);
        }
    }

    /**
     * THE PREMISE. `assertSame(30, ...)` is satisfied just as well by a fixture that never wrote a
     * variant, so the shape of the catalogue is asserted before the arithmetic over it. Read
     * through `withoutGlobalScopes()` and a raw count, because the eleven models that really do
     * filter are why an "it is there" assertion can answer against the resolved tenant rather than
     * the table.
     */
    public function test_the_fixture_really_exercises_both_branches_and_both_stores(): void
    {
        [, $storeA, $storeB] = $this->seedCatalogues();

        $this->assertSame(9, (int) DB::table('products')->where('store_id', $storeA->id)->count(),
            'Store A must hold all nine products of the fixture.');

        $this->assertSame(4, (int) DB::table('products')
            ->where('store_id', $storeA->id)->where('variant_product', 1)->count(),
            'Four of store A\'s products take the VARIANT branch (#5 #6 #7 #9).');

        $this->assertSame(5, (int) DB::table('products')
            ->where('store_id', $storeA->id)->where('variant_product', 0)->count(),
            'Five of store A\'s products take the SIMPLE branch (#1 #2 #3 #4 #8).');

        $this->assertSame(7, (int) DB::table('product_variants')
            ->where('store_id', $storeA->id)->count(),
            'Store A owns seven variant rows: four on #5, one on #6, one on #9 and the stray one '
            . 'on the SIMPLE product #8.');

        $this->assertSame(1, (int) DB::table('product_variants')
            ->where('product_variants.store_id', $storeA->id)
            ->whereIn('product_id', DB::table('products')
                ->where('store_id', $storeA->id)->where('variant_product', 0)->pluck('id'))
            ->count(),
            'One variant row hangs off a product that is NOT declared a variant product. Without '
            . 'it, a variant query missing `variant_product = 1` changes no figure here.');

        $this->assertSame(1, (int) DB::table('product_variants')
            ->where('store_id', $storeB->id)
            ->whereIn('product_id', DB::table('products')->where('store_id', $storeA->id)->pluck('id'))
            ->count(),
            'The cross-pairing row must exist, or the tenancy assertion below proves nothing.');

        /*
         * `variant_product` IS PART OF THE FIRST LIMB, and leaving it out is how this premise
         * first read 3 against a correct fixture: #7 is a VARIANT product carrying 50 units on
         * its parent row, which the loop never values, so a limb keyed on `product_stock > 0`
         * alone counts a product the figures exclude. The branch is chosen by `variant_product`
         * and by nothing else.
         */
        $this->assertSame(2, (int) DB::table('products')
            ->where('store_id', $storeA->id)->where('cost_price', '<=', 0)
            ->where('variant_product', 0)
            ->where('product_stock', '>', 0)->count()
            + (int) DB::table('products')
                ->where('store_id', $storeA->id)->where('cost_price', '<=', 0)
                ->where('variant_product', 1)
                ->whereIn('id', DB::table('product_variants')
                    ->where('store_id', $storeA->id)->where('stock', '>', 0)->pluck('product_id'))
                ->count(),
            'Exactly two of store A\'s valued products record no cost (#3 simple, #6 variant).'
        );
    }

    /** Which of the two states `makeNullStockVariant()` could actually create, stated not assumed. */
    public function test_the_null_stock_variant_premise(): void
    {
        [, $storeA] = $this->seedCatalogues();

        $this->assertTrue(Schema::hasColumn('product_variants', 'stock'),
            'Everything below is about that column.');

        /* The variant `makeNullStockVariant()` seeded, identified by its distinctive price of 20. */
        $seeded = DB::table('product_variants')
            ->where('store_id', $storeA->id)->where('price', 20)->get();

        $this->assertCount(1, $seeded, 'Exactly one null-stock candidate is seeded.');

        $storedNull = $seeded[0]->stock === null;

        /*
         * EXACTLY ONE OF THE TWO, NAMED -- not "either passed". `assertTrue($a || $b)` over two
         * measurements is satisfied by a fixture that created neither case correctly, which is the
         * shape of vacuous assertion this project keeps paying for.
         *
         * MEASURED on the suite's SQLite: `create_product_variants_table` declares
         * `integer('stock')->default(0)`, which is NOT NULL on both engines, so the null insert is
         * REFUSED and the substitution branch runs. The loop's `(int) ($variant->stock ?? 0)` and
         * the metric's `stock > 0` therefore both answer on a stored 0 rather than on a NULL, and
         * the `?? 0` limb is unreachable through this schema. Said here rather than claimed in a
         * fixture comment, because a figure that depends on an unreachable branch is a figure
         * nothing is really testing.
         */
        $this->assertFalse($storedNull,
            'product_variants.stock is NOT NULL as declared, so a NULL cannot be stored. If this '
            . 'ever fails the column was made nullable and the null limb became reachable -- '
            . 'which is worth knowing, not worth silently tolerating.');

        $this->assertSame(0, (int) $seeded[0]->stock,
            'The reachable equivalent is a variant recording no usable stock, which every figure '
            . 'must exclude.');
    }

    /**
     * THE CHARACTERISATION. Every figure the Blade prints, against the inline loop at HEAD.
     *
     * THE TYPE IS ASSERTED SEPARATELY FROM THE VALUE, because the value assertions cast and so
     * cannot see the type. Each money figure must reach the view as a FLOAT and each counter as an
     * INT: `MySQL` returns `SUM()` over a `DECIMAL` column as a STRING -- measured, `"30.51"` --
     * and an extraction that passed that through would print identically through
     * `number_format()` while `$inventoryPotentialProfit >= 0`, the test that picks the profit
     * tile's credit-or-debit colour (`finance/index.blade.php:208`), compared a string instead.
     * The loop produced floats and ints, so this holds on both sides of the extraction.
     */
    public function test_the_inventory_figures_are_what_the_inline_loop_produced(): void
    {
        [$merchantA] = $this->seedCatalogues();

        $response = $this->actingAs($merchantA)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            self::EXPECTED['inventoryUnits'],
            (int) $response->viewData('inventoryUnits'),
            '10 + 4 + 3 units from the simple branch, 8 + 5 from the variant branch.'
        );

        $this->assertSame(
            self::EXPECTED['inventoryCost'],
            round((float) $response->viewData('inventoryCost'), 2),
            'cost_price x stock: 600 + 30 + 0 + 40 + 0. The decimal column, so this half is exact.'
        );

        $this->assertSame(
            self::EXPECTED['inventoryRetailValue'],
            round((float) $response->viewData('inventoryRetailValue'), 2),
            'Sale price where there is one, list price where there is not, variation price where '
            . 'the variant carries one: 800 + 100 + 210 + 460 + 75.'
        );

        $this->assertSame(
            self::EXPECTED['inventoryPotentialProfit'],
            round((float) $response->viewData('inventoryPotentialProfit'), 2),
            'Retail 1645.00 minus cost 670.00.'
        );

        $this->assertSame(
            self::EXPECTED['inventoryValuedProducts'],
            (int) $response->viewData('inventoryValuedProducts'),
            'Five products were valued: the out-of-stock one and the variant product with no '
            . 'variants are not among them.'
        );

        $this->assertSame(
            self::EXPECTED['inventoryUncostedProducts'],
            (int) $response->viewData('inventoryUncostedProducts'),
            'Two valued products record no cost. The uncosted product with NO STOCK and the '
            . 'uncosted variant product with NO VARIANTS must not be counted.'
        );

        $this->assertSame(
            self::EXPECTED['inventoryUncostedRetail'],
            round((float) $response->viewData('inventoryUncostedRetail'), 2),
            '210.00 of simple retail and 75.00 of variant retail rest on an unrecorded cost.'
        );

        foreach ([
            'inventoryCost', 'inventoryRetailValue', 'inventoryPotentialProfit',
            'inventoryUncostedRetail',
        ] as $money) {
            $this->assertIsFloat($response->viewData($money), sprintf(
                '%s must reach the view as a float. A decimal STRING out of MySQL prints the same '
                . 'through number_format() and compares differently in the profit tile\'s '
                . '`>= 0` colour test.',
                $money
            ));
        }

        foreach ([
            'inventoryUnits', 'inventoryValuedProducts', 'inventoryUncostedProducts',
        ] as $counter) {
            $this->assertIsInt($response->viewData($counter), $counter . ' must reach the view as an int.');
        }
    }

    /**
     * TENANCY, THROUGH THE SCREEN. The same assertion set, stated as "store B is absent".
     *
     * Separate from the characterisation above on purpose: that test would also pass if store A's
     * figures were right and store B's were added on top of them -- it asserts totals, and a leak
     * changes a total. This one names the numbers a leak would produce, so the failure message says
     * which tenant arrived rather than only that a sum is wrong.
     */
    public function test_no_part_of_the_foreign_catalogue_is_valued_into_this_merchants_figures(): void
    {
        [$merchantA] = $this->seedCatalogues();

        $response = $this->actingAs($merchantA)->get('/finance');
        $response->assertStatus(200);

        $units = (int) $response->viewData('inventoryUnits');
        $cost = round((float) $response->viewData('inventoryCost'), 2);
        $retail = round((float) $response->viewData('inventoryRetailValue'), 2);

        $this->assertSame(39, $units, sprintf(
            'Store A holds 39 units. 46 means store B\'s 7 simple units were counted, 50 its 11 '
            . 'variant units, and 139 the cross-paired variant row\'s 100. Got %d.',
            $units
        ));

        $this->assertSame(688.00, $cost, sprintf(
            'Store A\'s capital is 688.00. 4188.00 would mean store B\'s 3500.00 arrived with it, '
            . 'and 1188.00 that the cross-paired row was costed at store A\'s 5.00 x 100. Got %s.',
            number_format($cost, 2)
        ));

        $this->assertSame(2005.00, $retail, sprintf(
            'Store A\'s retail value is 2005.00. 8998.00 would mean store B\'s 6993.00 of simple '
            . 'retail arrived, 3358.00 its 1353.00 of variant retail, and 102005.00 the '
            . 'cross-paired row\'s 100000.00. Got %s.',
            number_format($retail, 2)
        ));

        $this->assertSame(6, (int) $response->viewData('inventoryValuedProducts'),
            'Only store A\'s six valued products. 8 would mean store B\'s two arrived.');

        $this->assertSame(2, (int) $response->viewData('inventoryUncostedProducts'),
            'Only store A\'s two. 3 would mean store B\'s uncosted variant product arrived.');

        $this->assertSame(285.00, round((float) $response->viewData('inventoryUncostedRetail'), 2),
            'Only store A\'s 285.00. 1638.00 would mean store B\'s 1353.00 of uncosted variant '
            . 'retail arrived with it.');
    }

    /**
     * ====  COVERAGE OF THE EXTRACTED CLASS ITSELF, BEYOND WHAT THE SCREEN CAN REACH  ====
     *
     * Everything above drives `/finance`, which only ever passes ONE store. The three tests below
     * read `StoreInventoryValuation` directly, because two of its guarantees are not observable
     * through that screen and both survived a mutation without them. They are NOT part of the
     * characterisation: there is nothing at HEAD to compare them against, since the figures they
     * check did not exist as a separate object.
     */

    /**
     * THE CROSS-STORE PAIRING, UNDER AN OPERATOR SCOPE -- the case the `whereColumn` exists for.
     *
     * With one store in scope, `whereIn('product_variants.store_id', [A])` already excludes a
     * variant row owned by B, so removing the store-agreement `whereColumn` changes nothing and a
     * mutation dropping it SURVIVES. Name two stores and the two predicates stop covering each
     * other: both A and B are admissible on both sides, so only the requirement that the variant
     * and its product belong to the SAME store keeps B's row off A's product.
     *
     * The legitimate combined figure is each store's own stock: 39 units for A, plus B's 7 simple
     * and 11 variant units = 57. The cross-paired row would add its 100.
     */
    public function test_a_variant_row_is_never_paired_with_another_stores_product(): void
    {
        [, $storeA, $storeB] = $this->seedCatalogues();

        $both = StoreInventoryValuation::for(
            MetricScope::stores([$storeA->id, $storeB->id]),
            MetricWindow::allTime()
        );

        $this->assertSame(57, $both->units(), sprintf(
            'A holds 39 units and B holds 18. 157 means the variant row store B owns on store A\'s '
            . 'product was valued, which only the store-agreement predicate on the join prevents. '
            . 'Got %d.',
            $both->units()
        ));

        /* 2005.00 (A) + 999*7 (B simple) + 123*11 (B variant) = 2005 + 6993 + 1353 = 10351.00 */
        $this->assertSame(10351.00, $both->retailValue(),
            'The cross-paired row would add 1000.00 x 100 = 100000.00 to this.');
    }

    /**
     * THE CAVEATS, WHICH ARE THE FIGURE'S OWN STATEMENT OF WHAT IT CANNOT SEE.
     *
     * `COST_UNKNOWN` must be CONDITIONAL. A caveat attached whether or not a cost is missing
     * distinguishes nothing and teaches a merchant to ignore caveats, which is the explicit
     * reasoning `MetricCaveat` gives for keeping `COUPON_REDEMPTION_COVERAGE` out of the blocking
     * list. A mutation making it unconditional survived until this test existed.
     */
    public function test_the_figure_discloses_an_unrecorded_cost_only_when_there_is_one(): void
    {
        [, $storeA] = $this->seedCatalogues();

        $withGaps = StoreInventoryValuation::forStore($storeA->id);

        $this->assertTrue($withGaps->hasCaveat(MetricCaveat::COST_UNKNOWN),
            'Two valued products record no cost, so the figure must say so.');

        $this->assertSame(2, $withGaps->uncostedProducts());
        $this->assertSame(285.00, $withGaps->uncostedRetail());

        /* A store where every valued product carries a cost must NOT carry the caveat. */
        [, $tidyStore] = $this->merchantOnFinance();

        $this->makeProduct($tidyStore, [
            'price' => 10, 'cost_price' => 4, 'product_stock' => 2,
        ]);

        /*
         * An UNCOSTED but OUT-OF-STOCK product, to pin that the caveat follows the products the
         * figures actually valued rather than the catalogue. The loop's `$productUnits > 0` guard
         * is what made that true, and it is the same distinction the /finance disclosure draws.
         */
        $this->makeProduct($tidyStore, [
            'price' => 99, 'cost_price' => 0, 'product_stock' => 0,
        ]);

        $tidy = StoreInventoryValuation::forStore($tidyStore->id);

        $this->assertSame(1, $tidy->valuedProducts());
        $this->assertSame(0, $tidy->uncostedProducts());
        $this->assertFalse($tidy->hasCaveat(MetricCaveat::COST_UNKNOWN),
            'Nothing valued here lacks a cost, so claiming the gap would be false.');
    }

    /**
     * THE PRECISION CAVEAT FOLLOWS THE MEASURED COLUMN TYPES, not the migration's wording.
     *
     * `products.price`, `products.sale_price`, `product_variants.price` and
     * `product_variants.variation_price` all emit a MySQL `double` -- `float()` in Laravel 11 does,
     * and `double('price', 10, 2)` emits a bare `double` with the arguments dropped; both read back
     * off `SHOW CREATE TABLE` on MariaDB 10.11. `products.cost_price` is `decimal(15,2)`. So the
     * retail figures carry a precision caveat and the cost figure must not be described by one:
     * `MONEY_FLOAT_PRECISION` is the plural form, and the notes say which figures it covers.
     */
    public function test_the_precision_caveat_names_the_double_figures_and_not_the_decimal_one(): void
    {
        [, $storeA] = $this->seedCatalogues();

        $figures = StoreInventoryValuation::forStore($storeA->id);

        $this->assertTrue($figures->hasCaveat(MetricCaveat::MONEY_FLOAT_PRECISION),
            'Retail value, potential profit and uncosted retail are all summed from `double` '
            . 'columns, so the row carries the plural precision caveat.');

        $this->assertFalse($figures->hasCaveat(MetricCaveat::FLOAT_PRECISION),
            'The singular form says "This figure", which cannot name which of four money figures '
            . 'in this row it means. That is why MONEY_FLOAT_PRECISION exists.');

        $notes = $figures->notes();

        $this->assertSame(
            ['retail_value', 'potential_profit', 'uncosted_retail'],
            $notes['float_precision_figures'],
            'The caveat is plural, so the notes must name exactly which figures it covers.'
        );

        $this->assertSame(['cost'], $notes['exact_figures'],
            'The cost figure is summed from decimal(15,2) and is exact. Attaching a precision '
            . 'caveat to it would be false, and a caveat on everything distinguishes nothing.');

        $this->assertFalse($notes['currency_recorded'],
            'Neither `products` nor `product_variants` has a currency column, so the figures are '
            . 'in whatever currency the merchant typed and nothing here may claim one.');
    }

    /**
     * EDITING A COST PRICE MOVES THE COST FIGURE ON THE NEXT LOAD -- the model cache must not
     * stand between the two.
     *
     * The loop read `cost_price` off a `Product` query, and `Product` is one of the 79 `Cachable`
     * models, so a `Product` save flushed the tag that query was keyed under and the figure
     * refreshed. The extraction reads the same column through a query rooted on `ProductVariant`
     * for the variant branch, which a `Product` save does NOT flush -- measured on
     * `CACHE_STORE=array`, 10 -> 20 on a product holding 3 units left the figure at 30.00 where the
     * table said 60.00. `StoreInventoryValuation` calls `disableCache()` for that reason, and
     * without this test the mutation that puts the cache back SURVIVES: every other test here reads
     * the screen once, after the fixture, where a stale cache is indistinguishable from a fresh one.
     *
     * BOTH BRANCHES, in one test, because only the variant one was affected and a test covering
     * only it would not notice if the repair broke the other.
     */
    public function test_a_cost_price_edit_reaches_the_figure_on_the_next_load(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $simple = $this->makeProduct($store, [
            'price' => 100, 'cost_price' => 4, 'product_stock' => 5,
        ]);

        $variantProduct = $this->makeProduct($store, [
            'price' => 1, 'cost_price' => 10, 'product_stock' => 0, 'variant_product' => 1,
        ]);

        $this->makeVariant($variantProduct, $store->id, [
            'price' => 50, 'variation_price' => null, 'stock' => 3,
        ]);

        $before = $this->actingAs($merchant)->get('/finance');
        $before->assertStatus(200);

        /* 4 x 5 = 20 from the simple branch, 10 x 3 = 30 from the variant branch. */
        $this->assertSame(50.00, round((float) $before->viewData('inventoryCost'), 2));

        /* Through Eloquent, which is how the product form writes it. */
        $simple->cost_price = 6;
        $simple->save();

        $variantProduct->cost_price = 20;
        $variantProduct->save();

        $after = $this->actingAs($merchant)->get('/finance');
        $after->assertStatus(200);

        /* 6 x 5 = 30 and 20 x 3 = 60. */
        $this->assertSame(
            90.00,
            round((float) $after->viewData('inventoryCost'), 2),
            'A cost edit must reach the figure. 50.00 means nothing refreshed, 80.00 that only the '
            . 'variant branch did, and 60.00 that only the simple branch did -- the last is the '
            . 'measured shape of the model-cache staleness this guards.'
        );

        /* The retail value depends on neither edit, so it pins that nothing else moved. */
        $this->assertSame(
            650.00,
            round((float) $after->viewData('inventoryRetailValue'), 2),
            '100 x 5 plus 50 x 3, unchanged by a cost edit.'
        );
    }

    /**
     * POTENTIAL PROFIT IS ROUNDED AFTER THE SUBTRACTION, AND THAT IS NOT COSMETIC.
     *
     * The loop rounded cost and retail by assignment and then took `round($retail - $cost, 2)` of
     * the already-rounded pair. Dropping the outer `round()` is invisible on any fixture whose
     * figures are whole: `2005.00 - 688.00` is exact in binary floating point. It is not invisible
     * here. 0.30 minus 0.10 is `0.19999999999999998`, so this is the fixture that can tell the two
     * apart -- a mutation removing that `round()` survived every other test in this file.
     *
     * `number_format()` would print either as `0.20`, so nothing on the tile would look wrong. The
     * value a `FigureCsv`-style export or any other consumer reads would be the one that differs,
     * which is why the figure is pinned rather than the rendering.
     */
    public function test_potential_profit_is_rounded_after_the_subtraction(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->makeProduct($store, [
            'price' => 0.30, 'cost_price' => 0.10, 'product_stock' => 1,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(0.1, round((float) $response->viewData('inventoryCost'), 2));
        $this->assertSame(0.3, round((float) $response->viewData('inventoryRetailValue'), 2));

        $this->assertSame(
            0.2,
            $response->viewData('inventoryPotentialProfit'),
            'NOT rounded, this is 0.19999999999999998. Asserted without a round() of its own, '
            . 'because rounding here would hide exactly what is being pinned.'
        );
    }

    /**
     * A STORE WITH AN EMPTY CATALOGUE READS ZERO, AND THE SCREEN STILL RENDERS.
     *
     * Not a formality: the extraction replaces a `foreach` over an empty collection -- which
     * trivially yields the initialised zeros -- with database aggregates, and `SUM()` over no rows
     * is NULL, not 0. A missing `COALESCE` would hand the Blade a null, `number_format(null)`
     * raises a deprecation, and the profit tile's `>= 0` branch would silently flip.
     */
    public function test_a_store_with_no_products_reads_zero_rather_than_null(): void
    {
        [$merchant] = $this->merchantOnFinance();

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        foreach ([
            'inventoryUnits', 'inventoryValuedProducts', 'inventoryUncostedProducts',
        ] as $counter) {
            $this->assertNotNull($response->viewData($counter), $counter . ' must not be null.');
            $this->assertSame(0, (int) $response->viewData($counter));
        }

        foreach ([
            'inventoryCost', 'inventoryRetailValue', 'inventoryPotentialProfit',
            'inventoryUncostedRetail',
        ] as $money) {
            $this->assertNotNull($response->viewData($money), $money . ' must not be null.');
            $this->assertSame(0.0, round((float) $response->viewData($money), 2));
        }
    }
}
