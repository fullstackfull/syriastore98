<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Services\ImportMappingOwnership;
use App\Services\SafeRemoteFetch;
use Codexshaper\WooCommerce\Facades\Category as WooCategory;
use Codexshaper\WooCommerce\Facades\Coupon as WooCoupon;
use Codexshaper\WooCommerce\Facades\Customer as WooCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * P1-INV-13 -- the WooCommerce / Shopify re-sync handlers rewrote whatever local row a mapping named.
 * =============================================================================================
 *
 * F1 WoocomCouponController::edit dereferenced a missing mapping, never compared the coupon with the caller's store (Coupon carries no
 *    global scope that applies) and re-stamped `store_id` on an UPDATE -- the transfer primitive P1-INV-04 removed from the product handler.
 * F2 ShopifyProductController::edit: same, for Product.
 * F3 WoocomCustomerController::edit: relied on the Customer global scope for isolation, turned every failure into 'This email already used.',
 *    and re-stamped `store_id` / `created_by`.
 * F4 WoocomCategoryController::edit threw the resolved category away and built `new Category()`: every re-sync INSERTED a duplicate.
 *
 * The check is App\Services\ImportMappingOwnership, called from all four. The Shopify controller talks to Shopify with raw cURL and cannot
 * be driven offline, so F2 is proven through the shared service plus a census that pins the call; the three Woo handlers are driven through
 * their real routes with the SDK facades mocked. The Customer cases run with the Customer scope REMOVED, so it is the handler that is measured.
 */
class ImportMappingOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SafeRemoteFetch::resolveUsing(null);

        parent::tearDown();
    }

    private function mapping($store, string $module, int $remoteId, int $localId, string $table = 'woocommerce_conections', string $remoteColumn = 'woocomerce_id'): void
    {
        DB::table($table)->insert(['store_id' => $store->id, 'module' => $module, $remoteColumn => $remoteId, 'original_id' => $localId,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function couponIn($store, string $name = 'victim-coupon'): int
    {
        return DB::table('coupons')->insertGetId(['coupon_name' => $name, 'coupon_code' => strtoupper($name), 'coupon_type' => 'percentage', 'discount_amount' => 10,
            'status' => 1, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function customerIn($store, string $email = 'victim@example.test', ?int $createdBy = null): int
    {
        return DB::table('customers')->insertGetId(['first_name' => 'Victim', 'last_name' => 'Person', 'email' => $email, 'type' => 'customer', 'register_type' => 'email',
            'store_id' => $store->id, 'created_by' => $createdBy ?? $store->created_by, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function categoryIn($store, string $name = 'victim-cat'): int
    {
        return DB::table('categories')->insertGetId(['name' => $name, 'slug' => 'collections/' . $name, 'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function productIn($store, string $name = 'victim-product'): int
    {
        return DB::table('products')->insertGetId(['name' => $name, 'slug' => 'p-' . uniqid(), 'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 9, 'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock', 'store_id' => $store->id,
            'created_by' => $store->created_by, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---------------------------------------------------------------------------------------------
    // the shared check
    // ---------------------------------------------------------------------------------------------

    public function test_the_service_returns_the_row_only_for_the_store_that_owns_it(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $own = $this->productIn($mine);
        $foreign = $this->productIn($theirs);

        $this->assertSame($own, ImportMappingOwnership::owned((object) ['original_id' => $own], Product::class, $mine->id)?->id);
        $this->assertNull(ImportMappingOwnership::owned((object) ['original_id' => $foreign], Product::class, $mine->id), 'A foreign row was returned.');
        $this->assertNull(ImportMappingOwnership::owned(null, Product::class, $mine->id), 'No mapping.');
        $this->assertNull(ImportMappingOwnership::owned((object) ['original_id' => null], Product::class, $mine->id), 'A mapping with no local id.');
        $this->assertNull(ImportMappingOwnership::owned((object) ['original_id' => 999999], Product::class, $mine->id), 'A mapping to a row that is gone.');
        $this->assertNull(ImportMappingOwnership::owned((object) ['original_id' => $own], Product::class, null), 'No store resolved.');
        $this->assertNull(ImportMappingOwnership::owned((object) ['original_id' => $own], Product::class, ''), 'An empty store id.');
    }

    public function test_a_row_with_no_store_never_matches_an_unresolved_store_and_ids_are_compared_exactly(): void
    {
        [, $mine] = $this->makeTenant();
        $orphan = DB::table('products')->insertGetId(['name' => 'orphan', 'slug' => 'o-' . uniqid(), 'product_stock' => 1, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 1, 'sale_price' => 1, 'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock', 'store_id' => null, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $own = $this->productIn($mine);

        foreach ([null, ''] as $unresolved) {
            $this->assertNull(ImportMappingOwnership::owned((object) ['original_id' => $orphan], Product::class, $unresolved), 'A row with no store matched an unresolved store.');
        }
        // MySQL reads '5abc' as 5 in a numeric comparison; the service compares the ids as strings
        $this->assertNull(ImportMappingOwnership::owned((object) ['original_id' => $own], Product::class, $mine->id . 'abc'));
        $this->assertNull(ImportMappingOwnership::owned((object) ['original_id' => $own], Product::class, '0'));
    }

    public function test_the_service_does_not_lean_on_a_global_scope(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $foreignCustomer = $this->customerIn($theirs);
        $ownCustomer = $this->customerIn($mine, 'own@example.test');

        // Customer IS scoped: with no tenant resolved the scope adds nothing, and the unscoped read still returns the foreign row...
        $this->assertNotNull(Customer::withoutGlobalScopes()->find($foreignCustomer), 'Premise: the row exists.');
        // ...so the refusal below is the service's comparison, not the scope's
        $this->assertNull(ImportMappingOwnership::owned((object) ['original_id' => $foreignCustomer], Customer::class, $mine->id));
        $this->assertSame($ownCustomer, ImportMappingOwnership::owned((object) ['original_id' => $ownCustomer], Customer::class, $mine->id)?->id);
    }

    // ---------------------------------------------------------------------------------------------
    // F1 coupon
    // ---------------------------------------------------------------------------------------------

    private function couponPayload(): array
    {
        return ['id' => 55, 'code' => 'HIJACK', 'discount_type' => 'percent', 'amount' => '99', 'usage_limit_per_user' => 1, 'date_expires' => null];
    }

    public function test_a_coupon_resync_is_refused_when_the_mapping_names_another_stores_coupon(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Coupon');
        $victim = $this->couponIn($theirs);
        $this->mapping($mine, 'coupon', 55, $victim);       // the caller's OWN mapping row, pointing at a foreign coupon
        WooCoupon::shouldReceive('find')->andReturn($this->couponPayload());

        $response = $this->actingAs($merchant)->get('/woocom_coupon/55/edit');

        $response->assertSessionHas('error', __('Coupon Not Found.'));
        $row = DB::table('coupons')->where('id', $victim)->first();
        $this->assertSame('victim-coupon', $row->coupon_name, 'The foreign coupon was rewritten.');
        $this->assertSame((int) $theirs->id, (int) $row->store_id, 'The foreign coupon was re-owned.');
    }

    public function test_a_coupon_resync_with_no_mapping_is_refused_not_a_crash(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Coupon');
        WooCoupon::shouldReceive('find')->andReturn($this->couponPayload());

        $response = $this->actingAs($merchant)->get('/woocom_coupon/55/edit');

        $this->assertNotSame(500, $response->status(), 'A missing mapping crashed the handler.');
        $response->assertSessionHas('error', __('Coupon Not Found.'));
    }

    public function test_a_coupon_resync_updates_the_callers_own_coupon_and_leaves_its_owner_alone(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Coupon');
        $own = $this->couponIn($mine, 'own-coupon');
        $this->mapping($mine, 'coupon', 55, $own);
        WooCoupon::shouldReceive('find')->andReturn($this->couponPayload());

        $response = $this->actingAs($merchant)->get('/woocom_coupon/55/edit');

        $response->assertSessionHas('success');
        $row = DB::table('coupons')->where('id', $own)->first();
        $this->assertSame('HIJACK', $row->coupon_name, 'Premise: the own coupon must still be updated.');
        $this->assertSame((int) $mine->id, (int) $row->store_id);
    }

    // ---------------------------------------------------------------------------------------------
    // F3 customer
    // ---------------------------------------------------------------------------------------------

    private function customerPayload(): array
    {
        return ['id' => 66, 'first_name' => 'Hijacked', 'last_name' => 'Name', 'email' => 'attacker@example.test', 'avatar_url' => '',
            'billing' => (object) ['phone' => '0000'], 'shipping' => (object) ['phone' => '0000']];
    }

    public function test_a_customer_resync_is_refused_with_its_own_message_when_the_mapping_names_another_stores_customer(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Customer');
        $victim = $this->customerIn($theirs);
        $this->mapping($mine, 'customer', 66, $victim);
        WooCustomer::shouldReceive('find')->andReturn($this->customerPayload());

        $response = $this->actingAs($merchant)->get('/woocom_customer/66/edit');

        $response->assertSessionHas('error', __('Customer Not Found.'));
        $row = DB::table('customers')->where('id', $victim)->first();
        $this->assertSame('victim@example.test', $row->email, 'The foreign customer\'s EMAIL (a recovery-ownership field) was rewritten.');
        $this->assertSame('Victim', $row->first_name);
        $this->assertSame((int) $theirs->id, (int) $row->store_id);
        $this->assertSame((int) $theirs->created_by, (int) $row->created_by, 'The foreign customer was re-attributed.');
    }

    public function test_a_customer_resync_with_no_mapping_says_so_and_does_not_say_the_email_is_in_use(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Customer');
        WooCustomer::shouldReceive('find')->andReturn($this->customerPayload());

        $response = $this->actingAs($merchant)->get('/woocom_customer/66/edit');

        $response->assertSessionHas('error', __('Customer Not Found.'));
        $this->assertNotSame(__('This email already used.'), session('error'), 'The refusal was disguised as an e-mail clash.');
    }

    public function test_a_customer_resync_updates_the_callers_own_customer_without_re_stamping_it(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Customer');
        // created by SOMEONE ELSE (a staff member who first synced it): a re-stamp to the importing user would then be visible
        $own = $this->customerIn($mine, 'own@example.test', 424242);
        $originalCreator = DB::table('customers')->where('id', $own)->value('created_by');
        $this->assertNotSame((int) $merchant->id, (int) $originalCreator, 'Premise: the creator differs from the caller.');
        $this->mapping($mine, 'customer', 66, $own);
        WooCustomer::shouldReceive('find')->andReturn($this->customerPayload());

        $response = $this->actingAs($merchant)->get('/woocom_customer/66/edit');

        $response->assertSessionHas('success');
        $row = DB::table('customers')->where('id', $own)->first();
        $this->assertSame('Hijacked', $row->first_name, 'Premise: the own customer must still be updated.');
        $this->assertSame((int) $mine->id, (int) $row->store_id);
        $this->assertSame((int) $originalCreator, (int) $row->created_by, 'An UPDATE re-attributed the customer to the importing user.');
    }

    // ---------------------------------------------------------------------------------------------
    // F4 category
    // ---------------------------------------------------------------------------------------------

    private function categoryFixture($store): void
    {
        DB::table('settings')->insert(['name' => 'storage_setting', 'value' => 'local', 'created_by' => $store->created_by, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now()]);
        Storage::fake('theme');
        Http::fake(['*' => Http::response(self::pngBytes(), 200, ['Content-Length' => (string) strlen(self::pngBytes())])]);
        SafeRemoteFetch::resolveUsing(fn () => ['93.184.216.34']);
        WooCategory::shouldReceive('find')->andReturn(['id' => 12, 'name' => 'Renamed', 'image' => (object) ['src' => 'https://img.example.test/c.jpg']]);
    }

    /** A real image: the shared upload sink stores nothing else (ImportedImage). */
    private static function pngBytes(): string
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    public function test_a_category_resync_updates_the_mapped_category_instead_of_inserting_a_duplicate(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Category');
        $own = $this->categoryIn($mine, 'old-name');
        $this->mapping($mine, 'category', 12, $own);
        $this->categoryFixture($mine);
        $before = DB::table('categories')->count();

        $response = $this->actingAs($merchant)->get('/woocom_category/12/edit');

        $response->assertSessionHas('success');
        $this->assertSame($before, DB::table('categories')->count(), 'A re-sync inserted a duplicate category.');
        $this->assertSame('Renamed', DB::table('categories')->where('id', $own)->value('name'));
    }

    public function test_a_category_resync_is_refused_when_the_mapping_names_another_stores_category(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Category');
        $victim = $this->categoryIn($theirs);
        $this->mapping($mine, 'category', 12, $victim);
        $this->categoryFixture($mine);
        $before = DB::table('categories')->count();

        $response = $this->actingAs($merchant)->get('/woocom_category/12/edit');

        $response->assertSessionHas('error', __('Category Not Found.'));
        $this->assertSame('victim-cat', DB::table('categories')->where('id', $victim)->value('name'));
        $this->assertSame($before, DB::table('categories')->count());
        $this->assertSame([], Storage::disk('theme')->allFiles(), 'The handler fetched and stored an image before refusing.');
    }

    public function test_a_category_resync_with_no_mapping_is_refused_not_a_crash(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Category');
        $this->categoryFixture($mine);

        $response = $this->actingAs($merchant)->get('/woocom_category/12/edit');

        $this->assertNotSame(500, $response->status());
        $response->assertSessionHas('error', __('Category Not Found.'));
    }

    // ---------------------------------------------------------------------------------------------
    // THE OTHER HALF OF CRITERION 1: a FOREIGN MAPPING pointing at the caller's OWN record
    // ---------------------------------------------------------------------------------------------
    //
    // The row's required tests ask for this case "per site" and it was the one shape no test drove.
    // It is the ONLY input that isolates the mapping query's own `where('store_id', ...)`: with a
    // foreign mapping naming a FOREIGN row, `ImportMappingOwnership::owned()` refuses on its own, so
    // dropping the predicate changes nothing -- which is why mutants removing it from all four
    // handlers SURVIVED the first thirteen tests in this file. With a foreign mapping naming the
    // caller's own row, `owned()` APPROVES (the row really is the caller's), and only the predicate
    // stands between store B's mapping and a re-sync of store A's record under it.
    //
    // WHY IT MATTERS RATHER THAN BEING TIDINESS: the remote payload is fetched with store B's
    // credentials' remote id, so what lands in store A's row is whatever store B's remote catalogue
    // holds under that id. A merchant who learns another store's remote ids can rewrite their own
    // coupon, customer or category from someone else's shop -- and on the customer handler that
    // write includes the e-mail address, which is a recovery-ownership write.

    public function test_a_coupon_resync_is_refused_when_the_mapping_itself_belongs_to_another_store(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Coupon');

        $own = $this->couponIn($mine, 'own-coupon');
        $this->mapping($theirs, 'coupon', 55, $own);        // ANOTHER store's mapping row, naming MY coupon
        WooCoupon::shouldReceive('find')->andReturn($this->couponPayload());

        $response = $this->actingAs($merchant)->get('/woocom_coupon/55/edit');

        $response->assertSessionHas('error', __('Coupon Not Found.'));
        $row = DB::table('coupons')->where('id', $own)->first();
        $this->assertSame('own-coupon', $row->coupon_name,
            "A mapping row owned by another store was used to rewrite the caller's own coupon: the "
            . 'mapping query is not scoped to the caller\'s store.');
    }

    public function test_a_customer_resync_is_refused_when_the_mapping_itself_belongs_to_another_store(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Customer');

        $own = $this->customerIn($mine, 'own@example.test');
        $this->mapping($theirs, 'customer', 66, $own);
        WooCustomer::shouldReceive('find')->andReturn($this->customerPayload());

        $response = $this->actingAs($merchant)->get('/woocom_customer/66/edit');

        $row = DB::table('customers')->where('id', $own)->first();
        $this->assertSame('own@example.test', $row->email,
            "A mapping row owned by another store rewrote the caller's own customer -- including the "
            . 'e-mail, which is a recovery-ownership write.');
        $this->assertSame('Victim', $row->first_name);
    }

    public function test_a_category_resync_is_refused_when_the_mapping_itself_belongs_to_another_store(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Woocommerce Category');
        $this->categoryFixture($mine);

        $own = DB::table('categories')->insertGetId(['name' => 'own-category', 'slug' => 'own-category',
            'store_id' => $mine->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->mapping($theirs, 'category', 12, $own);
        $before = (int) DB::table('categories')->count();

        $response = $this->actingAs($merchant)->get('/woocom_category/12/edit');

        $response->assertSessionHas('error', __('Category Not Found.'));
        $row = DB::table('categories')->where('id', $own)->first();
        $this->assertSame('own-category', $row->name,
            "A mapping row owned by another store rewrote the caller's own category.");
        $this->assertSame($before, (int) DB::table('categories')->count(), 'A category was inserted.');
    }

    // ---------------------------------------------------------------------------------------------
    // the Shopify twin, which cannot be driven offline: pinned by a census
    // ---------------------------------------------------------------------------------------------

    /** The body of `edit()`, comments stripped: prose must not satisfy or trip a source ratchet. */
    private function editBody(string $source): string
    {
        $start = strpos($source, 'public function edit(');

        if ($start === false) {
            $start = (int) strpos($source, 'public function edit ');
        }

        $this->assertNotFalse($start, 'edit() is gone, so this census would pass vacuously.');

        $next = strpos($source, 'public function ', $start + 10);
        $body = $next === false ? substr($source, $start) : substr($source, $start, $next - $start);

        $out = '';

        foreach (token_get_all('<?php ' . $body) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    public function test_every_re_sync_handler_resolves_its_local_row_through_the_ownership_service(): void
    {
        $expect = [
            'WoocomCouponController.php' => 1,
            'WoocomCustomerController.php' => 1,
            'WoocomCategoryController.php' => 1,
            'ShopifyProductController.php' => 1,
        ];

        foreach ($expect as $file => $count) {
            $source = (string) file_get_contents(app_path('Http/Controllers/' . $file));
            $this->assertSame($count, preg_match_all('/ImportMappingOwnership::owned\(/', $source), $file);
            $this->assertStringNotContainsString('$upddata->original_id;', $source, $file . ' still dereferences an unchecked mapping.');

            /*
             * AND THE MAPPING QUERY ITSELF IS STORE-SCOPED. `owned()` compares the ROW the mapping
             * names with the caller's store, which refuses a foreign mapping naming a foreign row --
             * but APPROVES a foreign mapping naming the caller's OWN row, because the row really is
             * theirs. Only this predicate refuses that, and the three Woo handlers now have a test
             * driving it. ShopifyProductController cannot be driven offline (raw cURL to Shopify
             * before the guard; the cURL options themselves are P1-SEC-06's criterion 3, open
             * there), so for F2 this assertion IS the control's only ratchet -- which is stated
             * rather than left for a reader to assume the drive exists.
             */
            $edit = $this->editBody($source);
            $this->assertMatchesRegularExpression(
                '/\$upddata\s*=\s*[^;]*(?:::|->)where\(\s*[\'"]store_id[\'"]/',
                $edit,
                $file . ": the mapping resolve in edit() is not scoped to the caller's store, so another "
                . "store's mapping row can name -- and re-sync -- a record this caller owns."
            );
        }

        // REVIEW FINDING: the Shopify product re-sync fetched the image, charged the storage quota and wrote the file BEFORE it refused a
        // foreign or missing mapping, so a refused request still cost the merchant quota and left an orphan upload. The refusal comes first.
        $shopify = (string) file_get_contents(app_path('Http/Controllers/ShopifyProductController.php'));
        $editStart = strpos($shopify, 'public function edit(');
        $this->assertNotFalse($editStart);
        $edit = substr($shopify, $editStart);
        $owned = strpos($edit, 'ImportMappingOwnership::owned(');
        $this->assertNotFalse($owned);
        foreach (['SafeRemoteFetch::head(', 'Utility::updateStorageLimit(', 'Utility::upload_woo_file('] as $sideEffect) {
            $first = strpos($edit, $sideEffect);
            $this->assertNotFalse($first, $sideEffect);
            $this->assertLessThan($first, $owned, "ShopifyProductController::edit reaches {$sideEffect} before it has refused a mapping the store does not own.");
        }

        // no update path re-stamps ownership: the coupon and customer handlers carry no store_id/created_by assignment in edit()
        $coupon = (string) file_get_contents(app_path('Http/Controllers/WoocomCouponController.php'));
        $edit = substr($coupon, strpos($coupon, 'public function edit'), strpos($coupon, 'public function update') - strpos($coupon, 'public function edit'));
        $this->assertStringNotContainsString('->store_id', $edit, 'WoocomCouponController::edit writes store_id on an UPDATE.');
    }
}
