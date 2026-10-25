<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-RUNTIME-01 -- the merchant tables driven for real, both legs.
 * =============================================================================================
 *
 * WHY THIS FILE EXISTS. Every Phase 02 claim before it was verified by a contrast checker, a
 * headless browser over a STATIC preview, `php -l` and `node --check` -- never by booting
 * Laravel, because `composer install` could not complete (ledger blocker B1: the environment
 * denied `git.yoomoney.ru`). A static preview cannot tell you whether yajra resolves a column,
 * whether the AJAX leg returns rows, or whether `getCurrentStore()` resolved the tenant the
 * screen belongs to. With the dependency install restored, it can be measured, so it is.
 *
 * AND IT IS A TENANCY TEST, NOT ONLY A RENDERING ONE. `Product` is NOT in
 * `config/tenancy.php`'s `scoped_models` and registers no `StoreScope`, so it adds NO predicate
 * for any principal -- the skill's §2 table says so and a live cross-tenant stock drain (S19)
 * came from exactly that. `ProductDataTable::query()`'s own
 * `where('store_id', getCurrentStore())` is therefore the ONLY thing keeping one merchant's
 * catalogue out of another's list. That makes the AJAX leg the real test: the HTML leg returns a
 * scaffold with no rows in it at all, so a test that only renders the page proves nothing about
 * isolation.
 *
 * yajra picks its leg on `$this->request()->ajax() && $this->request()->wantsJson()`
 * (laravel-datatables-buttons/src/Services/DataTable.php::render), which is why the AJAX drives
 * below set both headers. A request with only one of them silently takes the HTML branch and
 * returns a 200 full of no data -- the shape of a test that passes while measuring nothing.
 */
class DataTableRuntimeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The two headers yajra's render() requires together before it will serve JSON. */
    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    /**
     * DataTables sends its own query string. Only the parts yajra reads are sent here -- the
     * column array is what `EloquentDataTable` resolves against, and omitting it returns rows
     * with no fields.
     */
    private function dataTableQuery(array $columns): array
    {
        $query = ['draw' => 1, 'start' => 0, 'length' => 10];

        foreach (array_values($columns) as $i => $name) {
            $query["columns[{$i}][data]"] = $name;
            $query["columns[{$i}][name]"] = $name;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'asc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        return $query;
    }

    /**
     * COLUMN NAMES READ FROM THE MIGRATION, not guessed. The first version of this fixture used
     * `quantity`, which does not exist -- the stock column is `product_stock`
     * (2023_12_26_092822_create_products_table.php:33) -- and the insert failed with
     * "table products has no column named quantity". `track_stock` is declared with NO default
     * and NO nullable, so it has to be supplied or the insert fails under a strict engine.
     *
     * `product_type` must be NULL: `ProductDataTable::query()` filters on
     * `where('product_type', null)`, so a row with any other value is invisible to the table and
     * every assertion below would be measuring an empty set.
     */
    private function makeProduct(int $storeId, int $createdBy, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 0,
            'product_stock' => 5,
            'product_type' => null,
            'status' => 1,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    // =========================================================================================
    // The HTML leg -- the page actually renders through the real Blade engine and real yajra
    // =========================================================================================

    public function test_the_product_list_renders_its_datatable_scaffold(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $this->makeProduct($store->id, $merchant->id, 'Own Product');

        $response = $this->actingAs($merchant)->get('/product');

        // 404 and 500 are both excluded explicitly: a row assertion is satisfied by either, and
        // this project has been bitten by exactly that.
        $this->assertNotSame(404, $response->getStatusCode(), 'the product route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the product screen must not 500');
        $response->assertOk();

        // The scaffold yajra's HtmlBuilder emits, with the table id buildDataTable() sets.
        $response->assertSee('product-table', false);
    }

    // =========================================================================================
    // The AJAX leg -- where the rows, and the tenant predicate, actually are
    // =========================================================================================

    public function test_the_product_datatable_ajax_leg_returns_the_callers_own_rows(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $this->makeProduct($store->id, $merchant->id, 'Own Product');

        $response = $this->actingAs($merchant)
            ->withHeaders(self::AJAX)
            ->getJson('/product?' . http_build_query($this->dataTableQuery(['name', 'price'])));

        $response->assertOk();

        $payload = $response->json();

        // PREMISE, asserted before the isolation claim: yajra really served the AJAX leg. The
        // HTML branch returns a view, not this envelope, so its absence means the drive was
        // wrong and anything below it would be measuring the wrong thing.
        $this->assertArrayHasKey('draw', $payload, 'yajra served the HTML leg, not the AJAX leg');
        $this->assertArrayHasKey('data', $payload);

        // AND THE ROWS ARE REAL. recordsTotal > 0 is the liveness check that makes the
        // cross-tenant assertion below meaningful -- an empty table excludes a foreign row for
        // free.
        $this->assertSame(1, (int) $payload['recordsTotal'], 'the caller must see their own product');
        $this->assertStringContainsString('Own Product', json_encode($payload['data']));
    }

    /**
     * THE ISOLATION CLAIM. `Product` has no global scope, so this measures
     * `ProductDataTable::query()`'s own predicate and nothing else.
     */
    public function test_the_product_datatable_does_not_serve_another_tenants_catalogue(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');

        $this->makeProduct($storeA->id, $merchantA->id, 'Alpha Own Product');
        $foreign = $this->makeProduct($storeB->id, $merchantB->id, 'Beta Secret Product');

        // PREMISE: the foreign row exists and `Product` really is unscoped, so an unfiltered
        // query would return it. Asserted through DB::table() -- an Eloquent read on a Cachable
        // model can serve a cached instance.
        $this->assertSame(
            2,
            \DB::table('products')->whereNull('product_type')->count(),
            'premise: both rows are in the table, so the predicate is what excludes one'
        );

        $response = $this->actingAs($merchantA)
            ->withHeaders(self::AJAX)
            ->getJson('/product?' . http_build_query($this->dataTableQuery(['name', 'price'])));

        $response->assertOk();
        $payload = $response->json();

        $this->assertArrayHasKey('draw', $payload, 'premise: the AJAX leg was served');
        $this->assertSame(1, (int) $payload['recordsTotal']);

        $body = json_encode($payload['data']);
        $this->assertStringContainsString('Alpha Own Product', $body);
        $this->assertStringNotContainsString('Beta Secret Product', $body);

        // The foreign row still exists; it is excluded, not absent.
        $this->assertNotNull(\DB::table('products')->find($foreign->id));
    }

    /**
     * THE ORDER TABLE TOO, because `Order` is the other unscoped model the merchant workspace
     * lists and its DataTable is the one P2-04 is about to restyle. Measured now so the restyle
     * has a baseline rather than an assumption.
     */
    public function test_the_order_datatable_ajax_leg_is_tenant_scoped(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Order');

        // `product_order_id`, NOT `order_id`. There is no `order_id` column -- `Order::create`
        // silently drops the key, so `$foreign->order_id` came back null and
        // assertStringNotContainsString('') passed/failed on nothing. Measured from the AJAX
        // payload itself, which carries `product_order_id` and a derived `order_id_string`.
        $own = $this->makeOrder($storeA, ['product_order_id' => 'ORD-OWN-777001']);
        $foreign = $this->makeOrder($storeB, ['product_order_id' => 'ORD-FOREIGN-777002']);

        $this->assertSame(2, \DB::table('orders')->count(), 'premise: both orders exist');

        $response = $this->actingAs($merchantA)
            ->withHeaders(self::AJAX)
            ->getJson('/order?' . http_build_query($this->dataTableQuery(['order_id', 'price'])));

        $this->assertNotSame(404, $response->getStatusCode(), 'the order route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the order screen must not 500');

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('draw', $payload, 'premise: the AJAX leg was served');

        $body = json_encode($payload['data'] ?? []);

        $this->assertNotSame('', (string) $own->product_order_id, 'premise: the fixture set an identifier');

        $this->assertStringContainsString($own->product_order_id, $body);
        $this->assertStringNotContainsString($foreign->product_order_id, $body);
    }

    /**
     * A STAFF MEMBER WITHOUT THE PERMISSION IS REFUSED, driven over HTTP rather than by asking
     * the policy. `OrderController::index()` redirects back with an error rather than 403ing, so
     * the assertion is that it is NOT the table -- a 200 full of rows would be the failure.
     */
    public function test_a_staff_member_without_manage_products_does_not_get_the_product_table(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->makeProduct($store->id, $owner->id, 'Own Product');

        $response = $this->actingAs($staff)
            ->withHeaders(self::AJAX)
            ->get('/product?' . http_build_query($this->dataTableQuery(['name', 'price'])));

        /*
         * `get`, not `getJson`, AND the body is read raw -- because the refusal is NOT JSON.
         * ProductController::index():53-54 answers `redirect()->back()->with('error', ...)`, so
         * a refused caller gets a 302 with an HTML body. `$response->json()` on that throws
         * "Invalid JSON was returned from the route" and FAILS THE TEST, which is how this
         * assertion first reported a refusal that was working correctly. The decoder was wrong,
         * not the controller.
         *
         * So: the drive must not have produced a DataTables envelope, and must not carry the
         * product name. 404 and 500 are excluded too -- either would satisfy "no rows" without
         * the permission check having anything to do with it.
         */
        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the refusal must not be a crash');
        $this->assertSame(302, $response->getStatusCode(), 'the refusal is a redirect back');

        $body = $response->getContent();

        $this->assertStringNotContainsString('"recordsTotal"', (string) $body,
            'a staff member holding no product permission was served a DataTables envelope');
        $this->assertStringNotContainsString('Own Product', (string) $body,
            'a staff member holding no product permission was served product data');
    }

    /**
     * AND THE SAME STAFF MEMBER, ONCE GRANTED, DOES GET IT -- so the test above is measuring the
     * permission and not something incidental about staff requests.
     */
    public function test_the_same_staff_member_gets_the_table_once_granted(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->makeProduct($store->id, $owner->id, 'Own Product');

        $this->grantPermission($staff, 'Manage Products');

        $response = $this->actingAs($staff)
            ->withHeaders(self::AJAX)
            ->getJson('/product?' . http_build_query($this->dataTableQuery(['name', 'price'])));

        $response->assertOk();
        $payload = $response->json();

        $this->assertArrayHasKey('draw', $payload, 'premise: the AJAX leg was served');
        $this->assertSame(1, (int) $payload['recordsTotal']);
        $this->assertStringContainsString('Own Product', json_encode($payload['data']));
    }
}
