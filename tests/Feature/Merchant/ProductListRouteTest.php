<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-R-01 — GET /product-list answered HTTP 500 for every merchant.
 * =================================================================================================
 *
 * A POST-CLOSURE PHASE 02 DEFECT, found during the Phase 2.5 surface audit and repaired under the
 * owner's decision of 2026-11-03 ("you may repair confirmed defects in existing merchant-facing
 * functionality ... when those defects prevent normal use, visual verification, or accessibility").
 *
 * WHAT IT WAS. `ProductController::list()` ended all three of its branches with
 *
 *     return view('product.list', compact('products', 'settings', 'msg', 'filterCategories'));
 *
 * and `$filterCategories` is never assigned anywhere in that method. Under PHP 8 `compact()` raises
 * `compact(): Undefined variable $filterCategories`, Laravel's handler promotes the warning to an
 * `ErrorException`, and the response is a 500 before a single byte of the view is rendered. The
 * screen was unreachable in every language, theme and width — which is why no amount of CSS work
 * could have verified it.
 *
 * WHERE IT CAME FROM. `ProductController::index()` — the DataTable screen at GET /product — really
 * does build `$filterCategories` (:521) for `product/partials/filters.blade.php`, which reads it at
 * :30, :58 and :106. The variable was carried into `list()`'s compact without the line that defines
 * it. `product/list.blade.php` does not include that partial and never mentions the variable, so
 * the repair is to stop passing what nothing receives rather than to run a category query whose
 * result no view would read.
 *
 * THE CONTROLS. The repair touches an argument list inside a permission-gated handler, so the two
 * ways to "fix" it wrongly are to widen the gate or to drop the tenant predicate. Both are asserted
 * against below, and both fail loudly if a later edit removes
 * `->where('store_id', getCurrentStore())` or the `isAbleTo('Manage Products')` test.
 */
class ProductListRouteTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Products');
        $this->merchant = User::find($this->merchant->id);
    }

    private function categoryIn(Store $store): int
    {
        return DB::table('categories')->insertGetId([
            'name' => 'cat-' . uniqid(), 'slug' => 'cat-' . uniqid(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function productIn(Store $store, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name, 'slug' => 'p-' . uniqid(),
            'product_stock' => 7, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_type' => null,
            'category_id' => $this->categoryIn($store),
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    // =============================================================================================
    //  THE REPAIR ITSELF
    // =============================================================================================

    /**
     * THE REGRESSION. Before the repair this is a 500 and nothing else in the file can run.
     */
    public function test_the_product_list_screen_renders_for_a_merchant_who_may_manage_products(): void
    {
        $response = $this->actingAs($this->merchant)->get('/product-list');

        $this->assertSame(200, $response->status(),
            'GET /product-list did not render. Before P2.5-R-01 this was a 500 raised by '
            . 'compact() on the undefined $filterCategories in ProductController::list().');
    }

    /** The page is not merely a 200 — it carries the merchant's own catalogue. */
    public function test_the_screen_shows_the_merchants_products(): void
    {
        $this->productIn($this->store, 'Damascene Brocade Scarf');

        $this->actingAs($this->merchant)->get('/product-list')
            ->assertOk()
            ->assertSee('Damascene Brocade Scarf', false);
    }

    /**
     * THE PAGINATION CONTRACT the handler declares. `paginate(11)` means a twelfth product must
     * not be on page one; a repair that swapped paginate() for get() would pass every other test
     * here and quietly change the screen.
     */
    public function test_the_list_is_paginated_at_eleven(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->productIn($this->store, sprintf('Paginated Product %02d', $i));
        }

        $response = $this->actingAs($this->merchant)->get('/product-list');
        $response->assertOk();

        $products = $response->original->getData()['products'];
        $this->assertSame(11, $products->perPage());
        $this->assertCount(11, $products->items());
    }

    // =============================================================================================
    //  NEGATIVE CONTROL 1 — THE PERMISSION GATE STILL REFUSES
    // =============================================================================================

    /**
     * A staff member of the same store WITHOUT `Manage Products` must still be turned away. If a
     * future edit "fixes" a failure here by loosening the gate, this fails.
     */
    public function test_a_principal_without_the_products_permission_is_refused(): void
    {
        $outsider = $this->makeStaff($this->merchant, ['name' => 'No Products']);
        $outsider->current_store = $this->store->id;
        $outsider->save();
        $outsider = User::find($outsider->id);

        $this->actingAs($outsider)->get('/product-list')->assertRedirect();
    }

    /** And a guest is sent to login rather than rendered the screen. */
    public function test_a_guest_cannot_reach_the_screen(): void
    {
        $this->get('/product-list')->assertRedirect();
    }

    // =============================================================================================
    //  NEGATIVE CONTROL 2 — THE TENANT PREDICATE STILL FILTERS
    // =============================================================================================

    /**
     * `Product` is NOT in config/tenancy.php's scoped_models, so the global scope adds no predicate
     * for it — the only thing keeping another tenant's catalogue off this screen is the explicit
     * `->where('store_id', getCurrentStore())` in the handler. This asserts it is still there.
     */
    public function test_another_tenants_products_are_not_on_the_screen(): void
    {
        $other = $this->makeMerchant(['email' => 'other-' . uniqid() . '@example.test']);
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);
        $this->productIn($otherStore, 'Aleppo Soap Of Another Tenant');
        $this->productIn($this->store, 'My Own Product');

        $response = $this->actingAs($this->merchant)->get('/product-list');
        $response->assertOk()
            ->assertSee('My Own Product', false)
            ->assertDontSee('Aleppo Soap Of Another Tenant', false);
    }

    // =============================================================================================
    //  PREMISE — the sibling screen that DOES use $filterCategories still gets it
    // =============================================================================================

    /**
     * The repair removes the name from `list()`. It must not remove it from `index()`, where
     * product/partials/filters.blade.php genuinely reads it. This is the guard on over-reach.
     */
    public function test_the_datatable_screen_still_receives_its_filter_categories(): void
    {
        $this->productIn($this->store, 'Indexed Product');

        $response = $this->actingAs($this->merchant)->get('/product');
        $this->assertSame(200, $response->status(), 'premise: GET /product rendered');

        $data = $response->original->getData();
        $this->assertArrayHasKey('filterCategories', $data,
            'ProductController::index() stopped passing $filterCategories, so the product '
            . 'filter partial lost its category options');
    }
}
