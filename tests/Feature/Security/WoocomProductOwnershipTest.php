<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Codexshaper\WooCommerce\Facades\Category as WooCategory;
use Codexshaper\WooCommerce\Facades\Product as WooProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-INV-04 / S37-4 — the WooCommerce import path that re-owns a foreign product.
 * =============================================================================================
 *
 * This was the one S37 site recorded as CODE-VERIFIED ONLY, and the acceptance criteria asked
 * for it to be "reproduced or refuted by execution before the fix is called proven". Driving it
 * needs a WooCommerce API response, which is why it had not been: `Product::find($id)` at
 * `WoocomProductController::edit:309` is NOT `App\Models\Product` -- it is
 * `Codexshaper\WooCommerce\Facades\Product`, the remote SDK. The handler distinguishes them by
 * using the fully-qualified `\App\Models\Product::find()` for the local row at `:370`.
 *
 * Because they are Laravel FACADES they mock, so the path runs here with no network call.
 *
 * THE DEFECT. `edit($id)` takes a REMOTE WooCommerce product id, fetches that product from the
 * caller's own WooCommerce store, then resolves the LOCAL product through the mapping table:
 *
 *     $woocommerceProduct = (clone $woocommerceConectionQuery)
 *         ->where('module', 'product')
 *         ->where('woocomerce_id', $jsonData['id'])
 *         ->first();                                    // :368 -- NO store predicate
 *     $product = \App\Models\Product::find($woocommerceProduct->original_id);
 *
 * Two lines above, the CATEGORY lookup on the same query builder carries
 * `->where('store_id', getCurrentStore())` (`:325`), and the Shopify twin
 * (`ShopifyProductController:533`) carries it too -- so this is an omission, not a design.
 *
 * WHY IT IS REACHABLE RATHER THAN THEORETICAL. WooCommerce product ids are per-store and
 * sequential, so two merchants both using the integration will have COLLIDING `woocomerce_id`
 * values as a matter of course -- both will have a product 42. `->first()` has no ordering, so
 * which tenant's mapping row wins is arbitrary.
 *
 * AND IT IS THE MOST DESTRUCTIVE OF THE FOUR S37 SITES, because it does not merely overwrite:
 *
 *     $product->store_id   = getCurrentStore();      // :383 -- RE-OWNS the row
 *     $product->created_by = auth()->user()->id;     // :384 -- RE-ATTRIBUTES it
 *
 * alongside name, slug, description, cover image, category, stock and price. The victim's
 * product leaves their catalogue and appears in the attacker's.
 */
class WoocomProductOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The remote WooCommerce id both tenants happen to use. */
    private const WOO_ID = 42;

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        \App\Services\SafeRemoteFetch::resolveUsing(null);

        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->scratch = [];

        parent::tearDown();
    }

    private function productIn($store, string $name, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => $name, 'slug' => 'p-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function mapping($store, string $module, int $wooId, int $originalId): int
    {
        return DB::table('woocommerce_conections')->insertGetId([
            'store_id' => $store->id,
            'module' => $module,
            'woocomerce_id' => $wooId,
            'original_id' => $originalId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The remote payload, carrying an image so the handler reaches its write -- see
     * `satisfyImageUpload()` for why that is the production-shaped precondition and not a
     * convenience.
     */
    private function stubWooResponse(int $wooCategoryId, string $imageSrc): void
    {
        $payload = [
            'id' => self::WOO_ID,
            'name' => 'HIJACKED-BY-WOO',
            'slug' => 'hijacked-by-woo',
            'description' => 'overwritten',
            'short_description' => 'overwritten',
            'regular_price' => '10',
            'sale_price' => '8',
            'price' => '8',
            'stock_quantity' => 7,
            'variations' => [],
            'attributes' => [],
            'default_attributes' => [],
            'images' => [(object) ['src' => $imageSrc]],
            'categories' => [(object) ['id' => $wooCategoryId]],
        ];

        WooProduct::shouldReceive('find')->andReturn($payload);
        // The category loop reads ['parent'] and ['id'] off this, array-style.
        WooCategory::shouldReceive('find')->andReturn(['id' => $wooCategoryId, 'parent' => 0]);
    }

    /**
     * Everything the handler would have overwritten — read with a RAW QUERY, deliberately.
     *
     * THIS IS NOT FUSSINESS; AN ELOQUENT READ HERE PRODUCED A FALSE REPRODUCTION. The first
     * version of this test used `Product::withoutGlobalScopes()->findOrFail()` and reported the
     * victim's name as `HIJACKED-BY-WOO` — i.e. it "reproduced" the cross-tenant overwrite. The
     * raw row was unchanged and the request had answered **500**: `App\Models\Product` is
     * `Cachable`, the handler had mutated its in-memory instance and then crashed before
     * `save()`, and the model read served that mutated instance. Measured side by side:
     *
     *   STATUS=500
     *   MODEL after: {"name":"HIJACKED-BY-WOO", ...}
     *   RAW   after: name=victim-woo-product
     *
     * CLAUDE.md warns about exactly this and names it as having caused two earlier false
     * readings. A security test that cannot tell a database write from a discarded in-memory
     * mutation is worse than no test, so every assertion in this file goes through
     * `DB::table('products')`.
     */
    private function snapshot(Product $p): array
    {
        $row = DB::table('products')->where('id', $p->id)->first();
        $this->assertNotNull($row, 'The product row vanished.');

        return [
            'name' => $row->name,
            'store_id' => (int) $row->store_id,
            'created_by' => (int) $row->created_by,
            'product_stock' => (int) $row->product_stock,
            'price' => (float) $row->price,
        ];
    }

    /**
     * Make the handler's image branch COMPLETE, so the write is actually reached.
     *
     * Measured: with no image in the payload the handler never writes at all — `$uplaod` is
     * undefined at `:377` (`$product->cover_image_path = $uplaod['url']`), PHP raises a warning,
     * this environment turns it into a 500, and that happens BEFORE `$product->save()`. The same
     * is true when `upload_woo_file()` fails, because its failure return has no `url` key. So the
     * attack S37-4 describes is latent behind a crash on any payload whose image does not upload.
     *
     * That crash is NOT a protection and must not be mistaken for one.
     *
     * CORRECTION, FROM AN ADVERSARIAL REVIEW OF THIS FIX, AND IT MAKES THE DEFECT MORE LIVE THAN
     * THE FIRST VERSION OF THIS COMMENT CLAIMED. I wrote that the `settings` row below is "the
     * production precondition". It is not: `Utility::Setting()` SEEDS ITS RETURN FROM A DEFAULTS
     * ARRAY CONTAINING `'storage_setting' => 'local'` (`app/Models/Utility.php:61`), and the
     * no-rows fallback (`:152-154`) leaves that default intact. So `upload_woo_file()` enters its
     * real branch — and the write lands — ON A DEFAULT INSTALL WITH NO STORAGE SETTINGS
     * CONFIGURED AT ALL. The row inserted here is belt-and-braces, not what makes the path
     * reachable. Verified by reading both lines.
     *
     * The rest of the fixture is what keeps this offline: a faked `theme` disk, a faked HTTP
     * client for the HEAD, and a real local file for `file_get_contents()` to read, so nothing
     * touches the network.
     */
    private function satisfyImageUpload($store): string
    {
        DB::table('settings')->insert([
            'name' => 'storage_setting', 'value' => 'local',
            'created_by' => $store->created_by, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\Storage::fake('theme');
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response('x', 200, ['Content-Length' => '10']),
        ]);

        // P1-SEC-06: the image is now fetched through App\Services\SafeRemoteFetch, which accepts http/https only and refuses a private
        // address. This fixture used to hand the handler a LOCAL FILE PATH for file_get_contents() -- exactly what the SSRF fix closes --
        // so it now hands it a public URL whose host "resolves" to a public address; the faked HTTP client answers both the size probe and
        // the download, and nothing touches the network.
        \App\Services\SafeRemoteFetch::resolveUsing(fn () => ['93.184.216.34']);

        return 'https://img.example.test/p.jpg';
    }

    /**
     * A MERCHANT CANNOT OVERWRITE OR RE-OWN ANOTHER STORE'S PRODUCT THROUGH THE WOO IMPORT.
     *
     * Merchant A is entirely legitimate: signed in, holding `Edit Woocommerce Product`, editing
     * a product id that exists in their own WooCommerce store. Only the MAPPING ROW the lookup
     * finds belongs to someone else.
     */
    public function test_a_merchant_cannot_re_own_another_stores_product_through_woo_edit(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Woocommerce Product');

        // Store A's own category mapping, so the handler's category precondition is satisfied
        // for the CALLER -- otherwise it refuses before reaching the product lookup and the test
        // would pass without measuring anything.
        $ownCategoryId = DB::table('categories')->insertGetId([
            'name' => 'a-cat', 'slug' => 'a-cat-' . uniqid(), 'status' => 1,
            'store_id' => $storeA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mapping($storeA, 'category', 900, $ownCategoryId);

        // THE VICTIM: store B's product, and store B's mapping row carrying the SAME remote id.
        $victim = $this->productIn($storeB, 'victim-woo-product', 50);
        $this->mapping($storeB, 'product', self::WOO_ID, $victim->id);

        $before = $this->snapshot($victim);

        $this->stubWooResponse(900, $this->satisfyImageUpload($storeA));

        $response = $this->actingAs($merchantA->fresh())
            ->get('/woocom_product/' . self::WOO_ID . '/edit');

        $after = $this->snapshot($victim);

        $this->assertSame($before['store_id'], $after['store_id'],
            'Another store\'s product was RE-OWNED: store_id moved from ' . $before['store_id']
            . ' to ' . $after['store_id'] . '.');
        $this->assertSame($before['created_by'], $after['created_by'],
            'Another store\'s product was re-attributed to the caller.');
        $this->assertSame($before['name'], $after['name'],
            'Another store\'s product was renamed to the caller\'s WooCommerce payload.');
        $this->assertSame($before['product_stock'], $after['product_stock'],
            'Another store\'s stock was overwritten from the caller\'s WooCommerce payload.');
        $this->assertSame($before['price'], $after['price'],
            'Another store\'s price was overwritten.');
        $this->assertNotSame(500, $response->status(),
            'The refusal must not be a crash. Body: '
            . substr((string) $response->getContent(), 0, 300));
        /*
         * PIN THE REFUSAL TO THE CONTROL UNDER TEST. "before == after and not a 500" is also
         * satisfied by a 302 from the PERMISSION branch (:287) or the CATEGORY branch (:334,
         * :337), so without this the test would pass if the fixture ever stopped reaching the
         * guards at all. `Product Not Found.` is produced in edit() only by the two new guards
         * (:400, :426) and the empty-payload branch, and is distinct from both other refusals --
         * verified by grepping every refusal string in the controller.
         *
         * Today the premise is also proven ELSEWHERE IN THIS FILE, because both positive tests
         * use the same grant and the same category fixture and assert the name DID change. That
         * is proof in the FILE, not in the TEST: delete either positive and all three negatives
         * go vacuous silently. Raised by review as the one change worth making here.
         */
        $this->assertSame(__('Product Not Found.'), session('error'),
            'The request was refused by something other than the ownership guards -- a missing '
            . 'permission or the category precondition would satisfy every other assertion in '
            . 'this test identically. Session error: ' . json_encode(session('error')));

    }

    /**
     * THE POSITIVE CONTROL: the owner's own Woo product still updates.
     *
     * Without this every assertion above is satisfied by a handler that refuses everyone, and
     * the whole point of the fix is that WooCommerce import keeps working for its owner.
     */
    public function test_a_merchant_can_still_update_their_own_woo_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Woocommerce Product');

        $ownCategoryId = DB::table('categories')->insertGetId([
            'name' => 'a-cat', 'slug' => 'a-cat-' . uniqid(), 'status' => 1,
            'store_id' => $storeA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mapping($storeA, 'category', 900, $ownCategoryId);

        $own = $this->productIn($storeA, 'own-woo-product', 50);
        $this->mapping($storeA, 'product', self::WOO_ID, $own->id);

        $this->stubWooResponse(900, $this->satisfyImageUpload($storeA));

        $response = $this->actingAs($merchantA->fresh())
            ->get('/woocom_product/' . self::WOO_ID . '/edit');

        $after = $this->snapshot($own);

        $this->assertSame('HIJACKED-BY-WOO', $after['name'],
            'The owner can no longer update their own WooCommerce product, so the refusals above '
            . 'prove nothing. Status ' . $response->status() . ', session error: '
            . json_encode(session('error')));
        $this->assertSame(7, $after['product_stock'],
            'The owner\'s own stock was not updated from the payload, so the destructive path '
            . 'did not run and the negative test measures nothing.');
        $this->assertSame((int) $storeA->id, $after['store_id'],
            'The owner\'s own product changed store.');
    }

    /**
     * ISOLATING THE PRODUCT-OWNERSHIP GUARD, which the store predicate cannot cover.
     * ==========================================================================================
     *
     * Removal checks on the first version of this file showed all three controls PASSING when
     * removed one at a time: the store predicate on the mapping lookup and the ownership check on
     * the resolved product each catch the headline attack on their own, so neither was isolated
     * and "a test must bite" was not satisfied for either.
     *
     * This drives the case only the PRODUCT guard can refuse: a mapping row that genuinely
     * belongs to the caller's store, whose `original_id` points at another tenant's product.
     * That is reachable because `woocommerce_conections.original_id` is a bare integer with NO
     * foreign key (`2024_01_30_050713_create_woocommerce_conections_table.php:20`), so nothing in
     * the schema ties a store-scoped mapping row to a product in that store.
     */
    public function test_a_store_scoped_mapping_pointing_at_a_foreign_product_is_refused(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Woocommerce Product');

        $ownCategoryId = DB::table('categories')->insertGetId([
            'name' => 'a-cat', 'slug' => 'a-cat-' . uniqid(), 'status' => 1,
            'store_id' => $storeA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mapping($storeA, 'category', 900, $ownCategoryId);

        $victim = $this->productIn($storeB, 'victim-via-own-mapping', 50);

        // The mapping row is the CALLER'S -- it passes the store predicate -- but it points at
        // store B's product.
        $this->mapping($storeA, 'product', self::WOO_ID, $victim->id);

        $before = $this->snapshot($victim);

        $this->stubWooResponse(900, $this->satisfyImageUpload($storeA));

        $response = $this->actingAs($merchantA->fresh())
            ->get('/woocom_product/' . self::WOO_ID . '/edit');

        $after = $this->snapshot($victim);

        $this->assertSame($before, $after,
            'A mapping row inside the caller\'s own store pointed at another tenant\'s product '
            . 'and the write went through. The store predicate cannot see this -- only the check '
            . 'on the RESOLVED PRODUCT can. Before: ' . json_encode($before)
            . ' After: ' . json_encode($after));
        $this->assertNotSame(500, $response->status(),
            'The refusal must not be a crash. Body: '
            . substr((string) $response->getContent(), 0, 300));
        // Pin the refusal to the control under test -- see the headline case.
        $this->assertSame(__('Product Not Found.'), session('error'),
            'Refused by something other than the ownership guards. Session error: '
            . json_encode(session('error')));

    }

    /**
     * ISOLATING THE REMOVED OWNERSHIP REWRITE.
     *
     * `$product->store_id = getCurrentStore()` and `$product->created_by = auth()->user()->id`
     * are gone from the update path. For a merchant editing their own product the store_id line
     * was a no-op, so removing it alone changes nothing observable -- which is why the removal
     * check on it did not bite.
     *
     * `created_by` IS observable, and within one tenant: when a STAFF member runs the import on
     * their merchant's product, the old code re-attributed that product from the merchant to the
     * staff member. Nothing cross-tenant, but a silent loss of authorship on every import, and
     * it is the assertion that makes the removal measurable.
     */
    public function test_an_import_by_staff_does_not_re_attribute_the_product(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Edit Woocommerce Product');

        $ownCategoryId = DB::table('categories')->insertGetId([
            'name' => 's-cat', 'slug' => 's-cat-' . uniqid(), 'status' => 1,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mapping($store, 'category', 900, $ownCategoryId);

        $product = $this->productIn($store, 'staff-imported', 50);
        $this->mapping($store, 'product', self::WOO_ID, $product->id);

        $before = $this->snapshot($product);

        $this->stubWooResponse(900, $this->satisfyImageUpload($store));

        $this->actingAs($staff->fresh())
            ->get('/woocom_product/' . self::WOO_ID . '/edit');

        $after = $this->snapshot($product);

        // THE PREMISE: the import really ran, so the assertion below is about what was NOT
        // written rather than about a refusal.
        $this->assertSame('HIJACKED-BY-WOO', $after['name'],
            'The staff import did not apply at all, so this case measures nothing.');

        $this->assertSame($before['created_by'], $after['created_by'],
            'A staff import re-attributed the product from the merchant (' . $before['created_by']
            . ') to the importer (' . $after['created_by'] . ').');
        $this->assertSame($before['store_id'], $after['store_id'],
            'A staff import changed the product\'s store.');
    }

    /**
     * ISOLATING THE STORE PREDICATE, which the product guard cannot cover.
     *
     * The mirror of the case above: another tenant's mapping row pointing at the CALLER'S OWN
     * product. The product guard passes -- the product really is theirs -- so only the store
     * predicate on the mapping lookup can refuse it. Without the predicate, `->first()` on a
     * colliding `woocomerce_id` picks arbitrarily (there is no ordering), so the caller's own
     * product gets overwritten from a remote record it was never mapped to.
     *
     * The three controls therefore cover three different shapes, and each of the three cases in
     * this file fails when its own control is removed. The headline case fails only when BOTH
     * predicates go, which is what defence in depth means and is recorded as such rather than
     * presented as three independent proofs.
     */
    public function test_a_foreign_mapping_pointing_at_the_callers_product_is_refused(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Woocommerce Product');

        $ownCategoryId = DB::table('categories')->insertGetId([
            'name' => 'a-cat', 'slug' => 'a-cat-' . uniqid(), 'status' => 1,
            'store_id' => $storeA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mapping($storeA, 'category', 900, $ownCategoryId);

        $mine = $this->productIn($storeA, 'my-own-product', 50);

        // Store B's mapping row, pointing at store A's product. Only the store predicate sees it.
        $this->mapping($storeB, 'product', self::WOO_ID, $mine->id);

        $before = $this->snapshot($mine);

        $this->stubWooResponse(900, $this->satisfyImageUpload($storeA));

        $response = $this->actingAs($merchantA->fresh())
            ->get('/woocom_product/' . self::WOO_ID . '/edit');

        $after = $this->snapshot($mine);

        $this->assertSame($before, $after,
            'A mapping row belonging to another store resolved and overwrote the caller\'s own '
            . 'product. Only the store predicate on the mapping lookup can refuse this. Before: '
            . json_encode($before) . ' After: ' . json_encode($after));
        $this->assertNotSame(500, $response->status(),
            'The refusal must not be a crash. Body: '
            . substr((string) $response->getContent(), 0, 300));
        // Pin the refusal to the control under test -- see the headline case.
        $this->assertSame(__('Product Not Found.'), session('error'),
            'Refused by something other than the ownership guards. Session error: '
            . json_encode(session('error')));

    }
}
