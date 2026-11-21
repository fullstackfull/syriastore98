<?php

namespace Tests\Feature\Merchant;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P5-10 — /finance's four inventory tiles, and a `??` fallback that can never fire.
 * =================================================================================================
 *
 * `StoreFinanceController::index()` values every in-stock product twice, at cost and at retail,
 * and renders the difference as "Potential Profit". The retail half reads:
 *
 *     $sellingPrice = (float) ($product->sale_price ?? $product->price ?? 0);     // :1041-1046
 *
 * `??` fires on NULL and on nothing else. **`products.sale_price` is `float NOT NULL DEFAULT 0`**
 * (`database/migrations/..._create_products_table.php:32`), so for a product sold at its list
 * price -- the ordinary case, nothing on sale -- `sale_price` is `0.0`, the coalesce is satisfied
 * by it, and **`$product->price` is never consulted**. That product contributes `0` to
 * `inventoryRetailValue` while still contributing its full `cost_price * stock` to
 * `inventoryCost`, so "Potential Profit" is `0 - cost`: NEGATIVE, and painted
 * `ss-money--debit` red by `finance/index.blade.php:172`.
 *
 * THE VARIANT PATH THREE LINES EARLIER IS THE PROOF OF INTENT, not a second bug.
 * `:1017-1021` is the same shape -- `$variant->variation_price ?? $variant->price ?? 0` -- but
 * `product_variants.variation_price` is `double(10,2) NULLABLE`, so there the `??` DOES fall
 * through and the figure is right. One spelling, two column definitions, one of them wrong. That
 * asymmetry is asserted below, because a fix that "corrects" both would break the working half.
 *
 * WHAT THE REST OF THE CODEBASE DOES, which is where the replacement expression comes from:
 * every other reader treats `0` as "no sale price", not as a price of zero --
 * `app/Models/Product.php:323` `if (!empty($product->sale_price))`,
 * `resources/views/front_end/hooks/product_price.blade.php:4` `@if (!empty($product->sale_price))`,
 * `resources/views/product/partials/preview.blade.php:59` `@if ((float) ($product->sale_price ?? 0) > 0)`,
 * and `Api/ApiController.php:499, :781, :804` `!empty(...) ? ... : 0`. So this is not a judgement
 * call about what a zero sale price means; the product already decided, and one handler disagrees.
 *
 * SCOPE. These tiles are DERIVED ON READ from `products` and `product_variants`. Nothing here
 * writes, and no stored amount, order total, ledger row or customer-visible price moves. The
 * block arrived with the Phase 2.5 merge (4e878b8d, 2026-11-12); it is not Phase 05's own code.
 *
 * ANA-006 ("do not claim precise profitability when cost or fee data is missing") is the OTHER
 * half and is asserted separately: `products.cost_price` is `decimal(15,2) NOT NULL DEFAULT 0.00`,
 * so a cost that was never recorded reads `0.00` and is indistinguishable from an item that
 * genuinely cost nothing -- which inflates "Potential Profit" by the whole retail value of every
 * such product, in the opposite direction from the `??` defect. The screen must say so.
 *
 * MUTATION RESULTS, five run, four reddened. Restoring the `??` chain, relaxing `> 0.0` to
 * `>= 0.0`, swapping the ternary arms and dropping the `store_id` predicate each go red here.
 *
 * `> 0.0` -> `!= 0.0` SURVIVED AT FIRST, AND MY STATED REASON FOR ACCEPTING THAT WAS WRONG.
 * The claim was: the two differ only for a NEGATIVE sale price, and every write path validates
 * `'sale_price' => 'nullable|numeric|min:0|...'`, so no negative is reachable. The four HOST
 * validators do enforce it -- ProductController:631 and :1868, Api/DashboardController:3093 and
 * :3256 -- but **the census was keyed on `app/` and missed three MODULE writers of the same
 * `products` table**, found by independent review:
 *
 *   packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php:30
 *       'sale_price' => 'required|numeric'   -- numeric, but NO min:0; :117-118 writes it
 *   packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionProductController.php:118
 *       'sale_price' => 'required'           -- no numeric, no min:0; written at :288, :1094
 *   packages/workdo/DigitalProduct/src/app/Http/Controllers/DigitalProductController.php:98
 *       'sale_price' => 'required'           -- same, written at :199, :474
 *
 * So a negative IS reachable, `> 0.0` IS load-bearing, and the right response is a test rather
 * than a paragraph. `test_a_negative_sale_price_falls_through_to_the_list_price` pins it and the
 * mutation now reddens. The old expression valued such a product at the negative itself, so the
 * new behaviour is also the better one -- but that was never the argument I had made.
 *
 * THE CENSUS FAILURE IS THE LESSON, not the negative number: this repository's recurring defect
 * is an inventory keyed on one directory or one spelling, and I reproduced it exactly.
 *
 * ONE AMBIGUITY IS NOT RESOLVED BY THIS CHANGE AND CANNOT BE. `min:0` admits a DELIBERATE
 * `sale_price` of 0 ("free"), and the column's default for "never set" is also 0, so the schema
 * cannot tell the two apart. This tile now reads 0 as "no sale price" and values the row at its
 * list price -- the same reading as Product.php:323, product_price.blade.php:4 and
 * preview.blade.php:59. A genuinely free product is therefore valued at list price in this one
 * figure. Resolving it needs a nullable column or a separate flag, which is a schema change and
 * out of scope here.
 */
class FinanceInventoryValuationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const FINANCE_PERMISSIONS = [
        'View Finance Dashboard',
    ];

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

    /**
     * Written through Eloquent on purpose: Product is Cachable, and a raw write would leave
     * the controller's own read serving a pre-write row (CLAUDE.md, model caching).
     */
    private function makeProduct(Store $store, array $attributes): Product
    {
        $name = 'P5-10 Product ' . uniqid();
        $catName = 'P5-10 Cat ' . uniqid();

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

    /**
     * THE PREMISE. Without this the two arithmetic tests below could pass for the wrong reason:
     * if `sale_price` were nullable the shipped `??` would already be correct and the failure
     * would have to be somewhere else.
     */
    public function test_a_product_created_without_a_sale_price_holds_zero_and_not_null(): void
    {
        [, $store] = $this->merchantOnFinance();

        $product = $this->makeProduct($store, [
            'price' => 100, 'product_stock' => 10,
        ]);

        $this->assertNotNull(
            $product->fresh()->sale_price,
            'products.sale_price is NOT NULL DEFAULT 0, so `sale_price ?? price` can never reach '
            . 'the second operand. If this assertion ever fails the column was made nullable and '
            . 'this whole finding needs re-measuring.'
        );

        $this->assertSame(
            0.0,
            (float) $product->fresh()->sale_price,
            'The default is 0, which is what the shipped coalesce silently accepts as a price.'
        );
    }

    /**
     * THE DEFECT. A product nobody put on sale must be valued at its list price.
     */
    public function test_a_product_sold_at_list_price_is_valued_at_its_list_price(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        /* No sale_price passed at all: the column takes its 0 default, as a real product would. */
        $this->makeProduct($store, [
            'price' => 100, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            600.0,
            (float) $response->viewData('inventoryCost'),
            'Cost is 60 x 10 and is not in dispute -- it is the half that was always right.'
        );

        $this->assertSame(
            1000.0,
            (float) $response->viewData('inventoryRetailValue'),
            'A product with no sale price is worth its list price, 100 x 10. Shipped behaviour '
            . 'values it at 0 because `sale_price ?? price` is satisfied by the 0 default.'
        );

        $this->assertSame(
            400.0,
            (float) $response->viewData('inventoryPotentialProfit'),
            'Retail 1000 minus cost 600. Shipped behaviour renders -600.00 in debit red.'
        );
    }

    /**
     * THE GUARD AGAINST THE NAIVE FIX. Reading `price` unconditionally would be just as wrong:
     * a genuinely discounted product must be valued at the discount it will actually sell for.
     */
    public function test_a_discounted_product_is_valued_at_its_sale_price(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->makeProduct($store, [
            'price' => 100, 'sale_price' => 80, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            800.0,
            (float) $response->viewData('inventoryRetailValue'),
            'A product on sale at 80 is worth 80, not its 100 list price.'
        );

        $this->assertSame(
            200.0,
            (float) $response->viewData('inventoryPotentialProfit'),
            'Retail 800 minus cost 600.'
        );
    }

    /**
     * A NEGATIVE sale price is not a price, so the row is valued at its list price.
     *
     * This exists because a mutation (`> 0.0` -> `!= 0.0`) survived and my written reason for
     * accepting that was wrong: the host validators enforce `min:0`, but `ImportExport`
     * (`required|numeric`, no `min:0`), `AuctionProduct` and `DigitalProduct` (`required` alone)
     * all write this column and do not. The fixture writes through `forceCreate` for the same
     * reason those modules can: nothing between the value and the column enforces a floor.
     *
     * The old expression valued such a product at the negative itself, subtracting from the
     * store's retail value. That is the one input family where this change alters behaviour
     * beyond the zero case, and it alters it for the better.
     */
    public function test_a_negative_sale_price_falls_through_to_the_list_price(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->makeProduct($store, [
            'price' => 100, 'sale_price' => -50, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            1000.0,
            (float) $response->viewData('inventoryRetailValue'),
            'A negative sale price is junk, not a discount: value the row at its list price, '
            . '100 x 10. The shipped expression returned -500.00 here.'
        );
    }

    /**
     * THE ASYMMETRY, asserted rather than asserted-about. The variant branch keeps `??` and is
     * left untouched, because `product_variants.variation_price` is `double(10,2) NULLABLE`
     * (create_product_variants_table:21) so its fallthrough really does reach `price`. If that
     * column is ever made NOT NULL this test goes red and the variant branch needs the same
     * repair the simple-product branch just got.
     */
    public function test_a_variant_with_no_variation_price_falls_through_to_its_own_price(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $product = $this->makeProduct($store, [
            'price' => 1, 'cost_price' => 60, 'product_stock' => 0, 'variant_product' => 1,
        ]);

        ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'Large', 'sku' => 'SKU-' . uniqid(),
            'price' => 100,
            'variation_price' => null,
            'stock' => 10,
            'store_id' => $store->id,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            1000.0,
            (float) $response->viewData('inventoryRetailValue'),
            'variation_price IS nullable, so `variation_price ?? price` reaches price: 100 x 10.'
        );
    }

    /**
     * And when the variant DOES carry its own price, that price wins -- the mirror of the
     * discounted-product case, so the untouched branch is pinned in both directions.
     */
    public function test_a_variant_with_its_own_variation_price_uses_it(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $product = $this->makeProduct($store, [
            'price' => 1, 'cost_price' => 60, 'product_stock' => 0, 'variant_product' => 1,
        ]);

        ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'Large', 'sku' => 'SKU-' . uniqid(),
            'price' => 100,
            'variation_price' => 80,
            'stock' => 10,
            'store_id' => $store->id,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            800.0,
            (float) $response->viewData('inventoryRetailValue'),
            'The variant price of 80 wins over the parent-row price of 100: 80 x 10.'
        );
    }

    /**
     * Out-of-stock products are excluded by `if ($stock <= 0) { continue; }` and must stay
     * excluded: this pins that the repair does not widen the population being valued.
     */
    public function test_a_product_with_no_stock_contributes_nothing(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->makeProduct($store, [
            'price' => 100, 'cost_price' => 60, 'product_stock' => 0,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(0, (int) $response->viewData('inventoryUnits'));
        $this->assertSame(0.0, (float) $response->viewData('inventoryCost'));
        $this->assertSame(0.0, (float) $response->viewData('inventoryRetailValue'));
    }

    /**
     * ANA-006 -- "do NOT claim precise profitability when cost or fee data is missing".
     *
     * `products.cost_price` is `decimal(15,2) NOT NULL DEFAULT 0.00`, so a cost that was never
     * entered reads 0.00 and is arithmetically indistinguishable from an item that genuinely
     * cost nothing. Such a product contributes `retail - 0` to Potential Profit: its WHOLE
     * retail value, counted as pure profit. The figure cannot be made correct without the
     * missing data, so the requirement is that it stop claiming to be precise.
     *
     * The disclosure is MEASURED and self-adjusting -- a count and an amount taken from the same
     * pass that builds the tiles -- rather than a fixed sentence, so it stays true as merchants
     * fill cost in, and disappears by itself. Same shape as ENTITLEMENT_RECORD_INCOMPLETE in
     * the metric layer.
     */
    public function test_the_profit_figure_discloses_how_much_of_it_rests_on_unrecorded_cost(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        /* Costed: contributes an honest 400.00 of profit. */
        $this->makeProduct($store, [
            'price' => 100, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        /* No cost_price passed at all -- the column takes its 0.00 default. */
        $this->makeProduct($store, [
            'price' => 50, 'product_stock' => 4,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            1,
            (int) $response->viewData('inventoryUncostedProducts'),
            'One of the two in-stock products has no recorded cost.'
        );

        $this->assertSame(
            200.0,
            (float) $response->viewData('inventoryUncostedRetail'),
            'That product contributes 50 x 4 = 200.00 of retail with nothing subtracted, so it '
            . 'is exactly the amount by which Potential Profit may be overstated.'
        );

        $html = $this->flatten($response->getContent());

        $this->assertStringContainsString(
            'ss-help',
            $html,
            'The disclosure must be VISIBLE. A screen-reader-only hint does not stop a sighted '
            . 'merchant reading an overstated profit as a precise one, which is what ANA-006 '
            . 'forbids.'
        );

        $this->assertMatchesRegularExpression(
            '/Cost is not recorded for\s*1\s*of\s*2\s*in-stock products/i',
            $html,
            'The disclosure must name how many products it is talking about, not say "some".'
        );

        $this->assertMatchesRegularExpression(
            '/contribute\s*200\.00\s*of retail value/i',
            $html,
            'and must name the amount at stake, so the merchant can size the uncertainty.'
        );
    }

    /**
     * Whitespace-collapsed RAW html. `strip_tags()` MUST NOT be used on this page and that is
     * not a style preference: the merchant layout inlines scripts containing bare `<`
     * comparisons -- `for (let i = 0; i < node.classList.length; i++)` and
     * `if (window.innerWidth < ...)` among them -- and `strip_tags()` treats each as the start
     * of a tag and discards everything up to the next `>`. Measured here: 80,007 bytes of
     * response became 18,679, and the disclosure sentence was inside a discarded span.
     *
     * That silently inverts an ABSENCE assertion, which is why both the positive and the
     * negative test below go through this helper: `assertStringNotContainsString(...,
     * strip_tags($html))` would have passed whether or not the caveat was on the page.
     */
    private function flatten(string $html): string
    {
        return preg_replace('/\s+/', ' ', $html);
    }

    /**
     * SILENT WHEN THERE IS NOTHING TO DISCLOSE. A caveat that is always on the screen is
     * furniture, and merchants stop reading it -- the same reasoning the `ss-attention`
     * component states for itself.
     */
    public function test_no_disclosure_is_rendered_when_every_product_has_a_recorded_cost(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->makeProduct($store, [
            'price' => 100, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(0, (int) $response->viewData('inventoryUncostedProducts'));
        $this->assertSame(0.0, (float) $response->viewData('inventoryUncostedRetail'));

        $this->assertStringNotContainsString(
            'Cost is not recorded for',
            $this->flatten($response->getContent()),
            'Nothing to disclose, so nothing is said.'
        );

        $this->assertStringNotContainsString(
            'ss-help__title',
            $this->flatten($response->getContent()),
            'and the whole help block is absent, not merely emptied of its sentence.'
        );
    }

    /**
     * An out-of-stock product with no cost is not valued into any tile, so it must not be
     * counted into the disclosure either -- otherwise the caveat would describe products that
     * contribute nothing to the figure it is qualifying.
     */
    public function test_an_out_of_stock_product_without_cost_is_not_disclosed(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->makeProduct($store, [
            'price' => 100, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        $this->makeProduct($store, [
            'price' => 50, 'product_stock' => 0,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(0, (int) $response->viewData('inventoryUncostedProducts'));
        $this->assertSame(0.0, (float) $response->viewData('inventoryUncostedRetail'));
    }

    /**
     * THE SAME EXCLUSION FOR A VARIANT PRODUCT, and this is the case that actually exercises
     * the `$productUnits > 0` guard.
     *
     * FOUND BY A MUTATION THAT DID NOT REDDEN. Replacing that guard with `true` left all ten
     * tests green, because for a SIMPLE product the branch's own `continue` has already skipped
     * the per-product tail -- so the guard looked dead. It is not: a variant product whose
     * variants are all out of stock reaches the tail anyway, since the inner `continue` only
     * skips the variant. Without the guard such a product is counted as uncosted and the
     * merchant is told cost is missing for a product that contributes nothing to any tile.
     */
    public function test_a_variant_product_with_no_stock_anywhere_is_not_disclosed(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->makeProduct($store, [
            'price' => 100, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        /* No cost_price, variant product, and its only variant is out of stock. */
        $product = $this->makeProduct($store, [
            'price' => 50, 'product_stock' => 0, 'variant_product' => 1,
        ]);

        ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'Large', 'sku' => 'SKU-' . uniqid(),
            'price' => 50,
            'variation_price' => null,
            'stock' => 0,
            'store_id' => $store->id,
        ]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            1,
            (int) $response->viewData('inventoryValuedProducts'),
            'Only the in-stock product was valued, so only it may be described.'
        );

        $this->assertSame(
            0,
            (int) $response->viewData('inventoryUncostedProducts'),
            'The uncosted product contributes nothing to any tile, so the caveat that qualifies '
            . 'those tiles must not count it.'
        );
    }

    /**
     * THE SECOND UNDISCLOSED PROFIT FIGURE ON THE SAME SCREEN.
     *
     * `/finance` renders profit twice: the inventory tile above, and a per-order `Profit` column
     * that prints `store_order_financials.gross_profit` raw (`index.blade.php:463-467`). That
     * column has the identical defect from the identical cause -- `product_cost` is
     * `decimal(15,2) NOT NULL DEFAULT '0.00'` (GateFProductionSchema:137), so a cost that was
     * never captured reads 0.00 and the row reports the WHOLE order value as margin.
     *
     * The row does print its own `product_cost` next to the profit, so a merchant could in
     * principle notice the 0.00 -- but nothing says why it is zero, and nothing aggregates it,
     * so the screen still presents a precise two-decimal profit per row. Disclosing the tile
     * and not this column would have been half a fix on one surface.
     *
     * Counted over the STORE's whole ledger, not the visible page: the table is paginated 15 at
     * a time, and a caveat that changed as you paged would be describing the page rather than
     * the books.
     */
    public function test_the_per_order_profit_column_discloses_rows_with_no_recorded_cost(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->recordFinancial($store, ['product_cost' => 40.0, 'gross_profit' => 60.0]);
        $this->recordFinancial($store, ['product_cost' => 0.0,  'gross_profit' => 100.0]);
        $this->recordFinancial($store, ['product_cost' => 0.0,  'gross_profit' => 100.0]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(3, (int) $response->viewData('orderFinancialsTotal'));
        $this->assertSame(2, (int) $response->viewData('orderFinancialsWithoutCost'));

        $this->assertMatchesRegularExpression(
            '/Cost is not recorded for\s*2\s*of\s*3\s*orders/i',
            $this->flatten($response->getContent()),
            'The per-order Profit column must say how many of its rows report a margin it '
            . 'cannot actually compute.'
        );
    }

    /**
     * And silent when the ledger is complete, for the same reason the tile caveat is.
     */
    public function test_no_order_cost_disclosure_when_every_ledger_row_has_a_cost(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->recordFinancial($store, ['product_cost' => 40.0, 'gross_profit' => 60.0]);

        $response = $this->actingAs($merchant)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(1, (int) $response->viewData('orderFinancialsTotal'));
        $this->assertSame(0, (int) $response->viewData('orderFinancialsWithoutCost'));

        $this->assertStringNotContainsString(
            'Cost is not recorded for',
            $this->flatten($response->getContent())
        );
    }

    /**
     * TENANCY, for the ledger half. `store_order_financials` has NO model at all, so nothing in
     * the framework adds a `store_id` predicate for any principal -- the controller's explicit
     * one is the only thing there is, and a caveat computed without it would leak another
     * store's bookkeeping quality into this merchant's screen.
     */
    public function test_another_stores_ledger_rows_are_not_counted_into_the_disclosure(): void
    {
        [$merchantA, $storeA] = $this->merchantOnFinance();
        [, $storeB] = $this->merchantOnFinance();

        $this->recordFinancial($storeA, ['product_cost' => 40.0, 'gross_profit' => 60.0]);

        $this->recordFinancial($storeB, ['product_cost' => 0.0, 'gross_profit' => 500.0]);
        $this->recordFinancial($storeB, ['product_cost' => 0.0, 'gross_profit' => 500.0]);

        $response = $this->actingAs($merchantA)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            1,
            (int) $response->viewData('orderFinancialsTotal'),
            "Store B's two ledger rows must not be counted into store A's total."
        );

        $this->assertSame(
            0,
            (int) $response->viewData('orderFinancialsWithoutCost'),
            "nor its two uncosted rows into store A's caveat."
        );
    }

    /**
     * A GATE F ledger row for a store, created THE WAY PRODUCTION CREATES ONE.
     *
     * Two things were measured the hard way here and are worth recording, because both make a
     * hand-rolled insert wrong rather than merely inconvenient:
     *
     *  1. `store_order_financials.order_id` carries a FOREIGN KEY, so an invented id fails with
     *     "FOREIGN KEY constraint failed". A real order is needed.
     *  2. Creating that order is already enough: `OrderObserver::created()` writes the ledger
     *     row itself. Inserting a second one fails on "UNIQUE constraint failed:
     *     store_order_financials.order_id".
     *
     * So the fixture makes an order and then adjusts the row the product wrote, which is both
     * the only thing that works and a more faithful fixture than one that fabricates the row.
     */
    private function recordFinancial(Store $store, array $attributes = []): void
    {
        $orderId = $this->makeOrder($store)->id;

        $updated = DB::table('store_order_financials')
            ->where('order_id', $orderId)
            ->where('store_id', $store->id)
            ->update($attributes + ['updated_at' => now()]);

        $this->assertSame(
            1,
            $updated,
            'OrderObserver::created() did not write a store_order_financials row for the new '
            . 'order, so this fixture is no longer measuring what it claims to measure.'
        );
    }

    /**
     * BILINGUAL. The merchant admin is Arabic (RTL) and English (LTR), and CLAUDE.md requires a
     * UI change to be checked in both directions -- so this drives the real page with
     * `users.language = 'ar'`, which is what `SetLocate:35` reads, rather than asserting a
     * translation function in isolation.
     *
     * The placeholders are the point: `:uncosted`, `:valued` and `:amount` have to survive
     * translation, and a mistyped placeholder in the Arabic string would render the literal
     * `:amount` to a merchant instead of a number. Asserting the Arabic text WITH the
     * substituted values is what catches that; asserting the key resolves would not.
     */
    public function test_the_disclosure_renders_in_arabic_with_its_numbers_substituted(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $merchant->language = 'ar';
        $merchant->save();

        $this->makeProduct($store, [
            'price' => 100, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        $this->makeProduct($store, [
            'price' => 50, 'product_stock' => 4,
        ]);

        $html = $this->flatten(
            $this->actingAs($merchant->fresh())->get('/finance')->getContent()
        );

        $this->assertStringContainsString(
            'لم تُسجَّل التكلفة',
            $html,
            'The disclosure must be translated, not left in English on an Arabic page.'
        );

        foreach ([':uncosted', ':valued', ':amount'] as $placeholder) {
            $this->assertStringNotContainsString(
                $placeholder,
                $html,
                "The Arabic string left {$placeholder} unsubstituted, so a merchant is shown the "
                . 'placeholder instead of the number.'
            );
        }

        $this->assertMatchesRegularExpression(
            '/لم تُسجَّل التكلفة لـ 1 من أصل 2/u',
            $html,
            'and the two counts must be the same ones the English sentence states.'
        );

        /*
         * NOT `assertStringContainsString('200.00', …)`, which was furniture: in this fixture
         * `inventoryRetailValue` is 100x10 + 50x4 = 1200, the Retail Value tile renders
         * "1,200.00", and that CONTAINS "200.00" -- so the assertion passed whether or not
         * `:amount` ever substituted. Caught in independent review. Anchor it to the Arabic
         * sentence instead, which is the thing under test.
         */
        $this->assertMatchesRegularExpression(
            '/تساهم هذه المنتجات بـ\s*200\.00/u',
            $html,
            'The amount must appear inside the Arabic disclosure, not merely somewhere on a page '
            . 'that also prints 1,200.00 in a tile.'
        );
    }

    /**
     * RTL SAFETY OF THIS BLOCK SPECIFICALLY. `.ss-help`'s accent is `border-inline-start`, a
     * logical property, so it sits on the right in Arabic and the left in English from one
     * declaration. A `border-left` here would pin the accent to the wrong edge in Arabic and
     * nothing else in the suite would notice.
     */
    public function test_the_help_block_uses_a_logical_border_so_it_flips_for_arabic(): void
    {
        $css = file_get_contents(base_path('public/css/store-syria-ds.css'));

        $this->assertMatchesRegularExpression(
            '/\.ss-help\s*\{[^}]*border-inline-start\s*:/s',
            $css,
            '.ss-help must use border-inline-start, not border-left.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.ss-help\s*\{[^}]*border-left\s*:/s',
            $css,
            'A physical border-left would not flip in RTL.'
        );
    }

    /**
     * TENANCY. `Product` is NOT in config/tenancy.php's `scoped_models`, so it adds no predicate
     * for anyone (CLAUDE.md): the controller's explicit `where('store_id', ...)` is the only
     * thing keeping another merchant's catalogue out of this merchant's capital figure.
     */
    public function test_another_stores_catalogue_is_not_valued_into_this_merchants_tiles(): void
    {
        [$merchantA, $storeA] = $this->merchantOnFinance();
        [, $storeB] = $this->merchantOnFinance();

        $this->makeProduct($storeA, [
            'price' => 100, 'cost_price' => 60, 'product_stock' => 10,
        ]);

        $this->makeProduct($storeB, [
            'price' => 999, 'cost_price' => 500, 'product_stock' => 7,
        ]);

        $response = $this->actingAs($merchantA)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            10,
            (int) $response->viewData('inventoryUnits'),
            "Store B's 7 units must not appear in store A's inventory count."
        );

        $this->assertSame(
            600.0,
            (float) $response->viewData('inventoryCost'),
            "Store B's 3500.00 of capital must not appear in store A's figure."
        );

        /*
         * THE TWO ASSERTIONS ABOVE COVER ONLY PRE-EXISTING VIEW DATA, which independent review
         * caught: `inventoryUnits` and `inventoryCost` both predate this slice, so that half of
         * this test is green with the whole change reverted and says nothing about the three NEW
         * variables. Store C below is uncosted and in stock, so it WOULD be counted into
         * `inventoryUncostedProducts` by any future unscoped second query over `Product` --
         * which is the thing worth pinning, since `Product` is absent from `scoped_models` and
         * the controller's explicit `where('store_id', …)` is the only control there is.
         */
        [, $storeC] = $this->merchantOnFinance();

        $this->makeProduct($storeC, [
            'price' => 70, 'product_stock' => 3,
        ]);

        $response = $this->actingAs($merchantA)->get('/finance');
        $response->assertStatus(200);

        $this->assertSame(
            1,
            (int) $response->viewData('inventoryValuedProducts'),
            "Only store A's single product was valued."
        );

        $this->assertSame(
            0,
            (int) $response->viewData('inventoryUncostedProducts'),
            "Store C's uncosted product must not be counted into store A's caveat."
        );

        $this->assertSame(
            0.0,
            (float) $response->viewData('inventoryUncostedRetail'),
            "nor its 210.00 of uncosted retail into store A's exposed amount."
        );
    }
}
