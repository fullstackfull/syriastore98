<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-06-2 -- the global search is not an enumeration oracle.
 * =============================================================================================
 *
 * A SEARCH BOX IS THE WORST SHAPE FOR AN AUTHORIZATION MISTAKE. It reaches products, orders and
 * customers from one text field, returns a little of each, and the caller chooses the term --
 * so a missing predicate is not a page somebody has to find, it is a tool for walking every
 * tenant's data a few rows at a time. Every case below drives the real endpoint over HTTP.
 *
 * THE TENANCY FACTS THIS RESTS ON, from the project skill:
 *   - `Product` and `Order` are NOT in `config/tenancy.php`'s `scoped_models` and register no
 *     effective global scope, so the controller's own `store_id` predicate is the ONLY control.
 *   - `Customer` IS scoped -- but `null_tenant => 'open'` means the scope adds NO predicate at
 *     all for a principal who resolves no tenant, which is why the unresolved-tenant case below
 *     is the most important one in the file and not an edge case.
 */
class MerchantSearchAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function url(string $term): string
    {
        return '/merchant-search?' . http_build_query(['q' => $term]);
    }

    private function makeProduct(int $storeId, int $createdBy, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 0,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 5,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    private function makeCustomer(int $storeId, string $name): Customer
    {
        // `customers` has first_name/last_name, NOT `name` -- read from the live schema after
        // an insert failed on it. The display title the controller builds is the two joined.
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        return Customer::forceCreate([
            'first_name' => $first,
            'last_name' => $last,
            'email' => \Illuminate\Support\Str::slug($name) . '-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $storeId,
        ]);
    }

    /** Every title across every section, so a leak anywhere is caught. */
    private function titles(array $payload): array
    {
        $out = [];
        foreach ($payload['sections'] ?? [] as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $out[] = $item['title'];
            }
        }

        return $out;
    }

    // =========================================================================================
    // Cross-tenant
    // =========================================================================================

    public function test_a_merchant_does_not_find_another_tenants_products(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');

        $this->makeProduct($storeA->id, $merchantA->id, 'Alpha Damask Scarf');
        $this->makeProduct($storeB->id, $merchantB->id, 'Beta Damask Scarf');

        // PREMISE: both rows exist and the term matches both, so what excludes one is the
        // predicate and not the search term. Read raw -- Product is Cachable.
        $this->assertSame(2, \DB::table('products')->where('name', 'LIKE', '%Damask%')->count());

        $response = $this->actingAs($merchantA)->getJson($this->url('Damask'));
        $response->assertOk();

        $titles = $this->titles($response->json());

        $this->assertContains('Alpha Damask Scarf', $titles);
        $this->assertNotContains('Beta Damask Scarf', $titles, "merchant A found merchant B's product");
    }

    public function test_a_merchant_does_not_find_another_tenants_customers(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Customer');

        $this->makeCustomer($storeA->id, 'Alpha Shopper');
        $this->makeCustomer($storeB->id, 'Beta Shopper');

        $this->assertSame(2, \DB::table('customers')->where('last_name', 'LIKE', '%Shopper%')->count());

        $titles = $this->titles($this->actingAs($merchantA)->getJson($this->url('Shopper'))->json());

        $this->assertContains('Alpha Shopper', $titles);
        $this->assertNotContains('Beta Shopper', $titles, "merchant A found merchant B's customer");
    }

    public function test_a_merchant_does_not_find_another_tenants_orders(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Order');

        $this->makeOrder($storeA, ['product_order_id' => 'ORD-ALPHA-1']);
        $this->makeOrder($storeB, ['product_order_id' => 'ORD-BETA-1']);

        $titles = $this->titles($this->actingAs($merchantA)->getJson($this->url('ORD-'))->json());

        $this->assertContains('#ORD-ALPHA-1', $titles);
        $this->assertNotContains('#ORD-BETA-1', $titles, "merchant A found merchant B's order");
    }

    // =========================================================================================
    // Permission, per section
    // =========================================================================================

    public function test_a_section_the_caller_may_not_see_is_absent_not_empty(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        // Orders only. No product and no customer permission.
        $this->grantPermission($staff, 'Manage Order');

        $this->makeProduct($store->id, $owner->id, 'Searchable Scarf');
        $this->makeCustomer($store->id, 'Searchable Shopper');
        $this->makeOrder($store, ['product_order_id' => 'ORD-SEARCHABLE']);

        $payload = $this->actingAs($staff)->getJson($this->url('Searchable'))->json();
        $keys = array_column($payload['sections'] ?? [], 'key');

        $this->assertNotContains('products', $keys,
            'an empty "Products" heading tells a staff member that products exist and they may '
            . 'not see them');
        $this->assertNotContains('customers', $keys);

        $this->assertNotContains('Searchable Scarf', $this->titles($payload));
        $this->assertNotContains('Searchable Shopper', $this->titles($payload));
    }

    public function test_the_same_staff_member_sees_the_section_once_granted(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $this->makeProduct($store->id, $owner->id, 'Searchable Scarf');

        $this->assertNotContains(
            'Searchable Scarf',
            $this->titles($this->actingAs($staff)->getJson($this->url('Searchable'))->json()),
            'premise: without the permission the row must not be found'
        );

        $this->grantPermission($staff, 'Manage Products');

        $this->assertContains(
            'Searchable Scarf',
            $this->titles($this->actingAs($staff)->getJson($this->url('Searchable'))->json())
        );
    }

    // =========================================================================================
    // THE UNRESOLVED TENANT -- the case that decides whether this is a leak
    // =========================================================================================

    /**
     * A PRINCIPAL WITH NO CURRENT STORE GETS NOTHING, NOT EVERYTHING.
     *
     * `Customer` is globally scoped, but `null_tenant => 'open'` means the scope contributes NO
     * predicate when no tenant resolves -- so without the explicit refusal in the controller,
     * this caller's customer query would be an unscoped read of every tenant's shoppers. That is
     * the difference between "no results" and "all results", and it is why the controller
     * refuses rather than relying on `where('store_id', null)` matching nothing.
     */
    public function test_a_principal_with_no_current_store_gets_no_rows_from_any_section(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');
        $this->grantPermission($merchantA, 'Manage Customer');
        $this->grantPermission($merchantA, 'Manage Order');

        $this->makeProduct($storeA->id, $merchantA->id, 'Orphan Scarf');
        $this->makeCustomer($storeA->id, 'Orphan Shopper');
        $this->makeCustomer($storeB->id, 'Foreign Shopper');

        /*
         * Strand the principal. `stores.created_by` carries a FOREIGN KEY to users, so
         * reassigning it to 0 fails the constraint -- measured. The store is DELETED instead,
         * which is what "owns no store" actually means, and its products go with it.
         *
         * Written through Eloquent so the Cachable User's cache is flushed: a raw update would
         * leave the handler's own read serving the pre-write row, which is a measured false
         * reading in this project.
         */
        $merchantA->forceFill(['current_store' => null])->save();
        \DB::table('products')->where('store_id', $storeA->id)->delete();
        \DB::table('stores')->where('created_by', $merchantA->id)->delete();
        (new \App\Models\User)->flushCache();

        // PREMISE: the principal really resolves no tenant now. Without this the test could pass
        // because a store still resolved and simply had no matching rows.
        $this->assertEmpty(getCurrentStore($merchantA->id), 'premise: no tenant may resolve');

        $payload = $this->actingAs($merchantA)->getJson($this->url('Orphan'))->json();

        $this->assertSame([], $payload['sections'] ?? ['not-empty'],
            'a principal who resolves no tenant was served rows; for Customer the global scope '
            . 'adds nothing under null_tenant => open, so this is an unscoped read');

        $foreign = $this->actingAs($merchantA)->getJson($this->url('Shopper'))->json();
        $this->assertNotContains('Foreign Shopper', $this->titles($foreign));
    }

    // =========================================================================================
    // Anonymous, term handling, and the LIKE wildcards
    // =========================================================================================

    /**
     * THE HAZARD ITSELF, PINNED -- this is why the controller refuses rather than trusting the
     * scope, and it is the case that fails if `config/tenancy.php` ever changes.
     *
     * MEASURED, not assumed: for a principal who resolves no tenant, `Customer::query()` compiles
     * to `select * from customers where last_name LIKE ? and deleted_at is null` -- NO store
     * predicate at all -- and returns BOTH tenants' rows. `Customer` is in `scoped_models`, so
     * the scope is registered; `null_tenant => 'open'` is what makes it contribute nothing.
     *
     * If this test ever starts failing because the scope began filtering, that is good news and
     * the controller's refusal becomes belt without braces -- but it should be read and decided,
     * not discovered when a search box starts returning other people's shoppers.
     */
    public function test_the_global_scope_does_NOT_protect_a_principal_with_no_tenant(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->makeCustomer($storeA->id, 'Alpha Shopper');
        $this->makeCustomer($storeB->id, 'Beta Shopper');

        $merchantA->forceFill(['current_store' => null])->save();
        \DB::table('products')->where('store_id', $storeA->id)->delete();
        \DB::table('stores')->where('created_by', $merchantA->id)->delete();
        (new \App\Models\User)->flushCache();

        $this->actingAs($merchantA);
        // The default guard is put back to `web`: `actingAs` also calls `shouldUse()`, and
        // StoreScope::resolveTenant() reads the default guard, so a direct-call test can
        // otherwise run under a TIGHTER scope than a real request ever has.
        $this->app['auth']->shouldUse('web');

        $this->assertEmpty(getCurrentStore($merchantA->id), 'premise: no tenant resolves');

        $names = Customer::query()
            ->where('last_name', 'LIKE', '%Shopper%')
            ->get()
            ->map(fn (Customer $c) => $c->first_name)
            ->all();

        sort($names);

        $this->assertSame(['Alpha', 'Beta'], $names,
            'The global scope now filters for a tenant-less principal. That is a CHANGE in '
            . 'config/tenancy.php (null_tenant), not a bug here -- read it, then decide whether '
            . "MerchantSearchController's explicit refusal is still needed.");
    }

    /**
     * A NOTE ON WHAT THE CASES ABOVE CAN AND CANNOT PROVE, recorded because the bite test said
     * so and the alternative is a comfortable illusion.
     *
     * The endpoint is protected by TWO controls that overlap: the explicit
     * `where('store_id', $storeId)` in each query, and the early refusal when no tenant
     * resolves. MEASURED by removing each in turn: with EITHER one gone the suite stays green,
     * because `where('store_id', null)` compiles to `store_id IS NULL` and matches nothing, and
     * because `Customer`'s global scope covers the resolved-tenant case on its own.
     *
     * So no case in this file can fail on a SINGLE control being removed -- only on both. That
     * is a real limit of testing redundant controls through the endpoint, it is stated here
     * rather than papered over, and the test above is what keeps the underlying hazard visible.
     */
    public function test_an_anonymous_caller_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $this->makeCustomer($store->id, 'Anon Shopper');

        $response = $this->getJson($this->url('Shopper'));

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
        $this->assertStringNotContainsString('Anon Shopper', (string) $response->getContent());
    }

    /**
     * `%` AND `_` ARE LIKE WILDCARDS, so an unescaped term widens the match instead of
     * narrowing it -- `%` alone returns the whole table within the caller's own store, and `_`
     * matches any single character. Escaped, they are literals and match nothing here.
     */
    public function test_like_wildcards_in_the_term_are_literals(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        $this->makeProduct($store->id, $merchant->id, 'Plain Scarf');

        foreach (['%%', 'a_b', '%'] as $term) {
            $titles = $this->titles($this->actingAs($merchant)->getJson($this->url($term))->json());

            $this->assertNotContains('Plain Scarf', $titles,
                "the term [{$term}] was treated as a wildcard rather than as text");
        }

        // And a literal percent in a NAME is still findable, so the escaping did not break
        // searching for one.
        $this->makeProduct($store->id, $merchant->id, '50% Linen Mix');
        $titles = $this->titles($this->actingAs($merchant)->getJson($this->url('50%'))->json());
        $this->assertContains('50% Linen Mix', $titles);
    }

    public function test_a_term_shorter_than_two_characters_runs_no_query(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $this->makeProduct($store->id, $merchant->id, 'Aardvark Scarf');

        $payload = $this->actingAs($merchant)->getJson($this->url('A'))->json();

        $this->assertSame([], $payload['sections'] ?? ['not-empty']);
    }
}
