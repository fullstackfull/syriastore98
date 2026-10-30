<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-INV-01 / S37-1 — the merchant catalogue surface.
 * =============================================================================================
 *
 * `ProductController::update` takes a route-model-bound `Product $product` and the only
 * authorization in the method is `isAbleTo('Edit Products')` — which answers *may this user edit
 * products* and never *may this user edit THIS product*. `App\Models\Product` is not in
 * `config/tenancy.php`'s `scoped_models` and registers no effective global scope (CLAUDE.md), so
 * the binding resolves across every tenant.
 *
 * Its sibling `destroy()` already carries the ownership check this adds, with the same shape:
 * `isAbleTo` first, platform operator exempt via `UserPolicy::PLATFORM_ADMIN`, then
 * `getCurrentStore()` against `$product->store_id`. `update()`, `edit()` and `show()` did not.
 *
 * WHY THE PAYLOAD CARRIES THE FIELDS IT DOES. Omit any one of them and this file stops measuring
 * ownership, so the set is load-bearing and is listed in full:
 *
 *   - `name`, `category_id`, `status`, `variant_product`, `price`, `cost_price` are required by
 *     the validator (`ProductController.php:1051-1060`). Omit one and `update()` returns its
 *     `$msg` error array with NO write, so a cross-store test passes because validation refused,
 *     not because ownership did. `cost_price` is the trap of the group: it is **required and
 *     never written** — `update()` has no `cost_price` assignment at all, and
 *     `products.cost_price` has no migration (a known GATE F gap, P1-MIG-03).
 *   - `stock_status` (:1146), `trending` (:1207) and `track_stock` (:1211/:1215) are written
 *     STRAIGHT THROUGH from request input into **NOT NULL** columns
 *     (`create_products_table.php:39`, `:26`, `:29` — and `track_stock` has no default at all).
 *     Omit one and the UPDATE sets NULL, which SQLite REFUSES with a constraint violation: the
 *     save fails, the victim is unchanged, and a refusal test passes because the write died.
 *
 * MySQL with `'strict' => false` (`config/database.php:55` — the production setting) COERCES those
 * NULLs instead, so the write lands there. **Production is more exposed than this test database**,
 * which is the reverse of the direction this project is usually bitten in. Measured both ways in
 * `tests/Support/Security/bin/verify-product-ownership-on-mysql.php`, which also shows the
 * cross-store write completing on MySQL with the two NOT NULL fields OMITTED — the payload that
 * cannot complete on SQLite at all.
 *
 * The third field set was named by an adversarial review of this file: the first version of this
 * docblock said "these three" and listed two.
 *
 * `grantPermission(..., 'Edit Products')` IS NOW LOAD-BEARING FOR ALL OF THESE ROUTES, and it
 * used to be load-bearing for only three. `update()` calls `isAbleTo('Edit Products')`, so
 * without the grant it refuses before ownership is ever consulted and the cross-store assertions
 * would pass vacuously. The P1-INV-07 routes -- `file_delete`, `product_attribute_delete` and
 * `attribute_combination_edit` -- called `isAbleTo` NOWHERE, which this docblock recorded as a
 * finding and which **P1-INV-10 (e) has since closed**: all three now carry a permission limb
 * through `ProductController::lacksProductPermission()`, operator-first. So the grant in those
 * tests is no longer decoration, and the paragraph that said "removing it there changes no
 * outcome" is no longer true -- removing it turns those tests into the permission-refusal tests
 * at the foot of this file.
 *
 * `edit()` and `show()` are the surprise in that sentence and are left as found. Neither calls
 * `isAbleTo` at all (:934 and :881 -- only `index()`, `store()`, `update()` and `destroy()` do),
 * so the product edit and view SCREENS carry no permission requirement of their own. That is why
 * (e)'s limb on `attribute_combination_edit` accepts `Manage Products` as well as
 * `Edit Products`: show.blade.php:730 PUTs to it from a screen a view-only staff role can reach,
 * and gating a render on `Edit Products` alone would take the variations panel away from them.
 */
class MerchantProductOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Absolute paths this test wrote, removed in tearDown WHATEVER the outcome.
     *
     * These tests have to write real files: `file_delete()` resolves `base_path($image_path)` and
     * unlinks it, so a fixture under /tmp would not exercise the handler. `uploads/` is NOT
     * gitignored, so a failing assertion that skipped an inline cleanup left junk in the working
     * tree -- which it did, until this was added.
     *
     * @var list<string>
     */
    private array $scratchFiles = [];

    /** Directories this test created, pruned only if empty. @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach ($this->scratchDirs as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->scratchFiles = [];
        $this->scratchDirs = [];

        parent::tearDown();
    }

    /** A category in the given store, because `category_id` is a required field. */
    private function categoryIn($store): int
    {
        return DB::table('categories')->insertGetId([
            'name' => 'cat-' . uniqid(), 'slug' => 'cat-' . uniqid(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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

    /**
     * Distinctive variant stock values, asserted on by BOTH combination tests.
     *
     * The combination screen echoes the resolved variant's stock into a form field
     * (attribute_combinations_edit.blade.php:204) and its prices at :129 and :137. A value that
     * appears nowhere else in the rendered page is what makes "the victim's data did not leak"
     * a real measurement rather than a tautology.
     */
    private const VICTIM_VARIANT_STOCK = 987654;
    private const OWN_VARIANT_STOCK = 123456;

    /**
     * The form post that makes `attribute_combination_edit` produce ONE combination named $option.
     *
     * `attribute_no` names the attribute, `for_variation_<no>` must be 1 or the option is
     * skipped entirely, and `attribute_options_<no>` must be an array. Drop any one of the three
     * and `$combinations[0]` is empty, the view's guard at line 2 renders nothing, and a
     * "the victim's stock is absent" assertion passes against a blank page.
     */
    private function combinationRequest(int $productId, string $option = 'Red'): array
    {
        return [
            'id' => $productId,
            'attribute_no' => ['7'],
            'for_variation_7' => 1,
            'attribute_options_7' => [$option],
            'price' => 100.00,
            'sku' => 'combo',
        ];
    }

    /** The payload the product edit form posts for a non-variant product. */
    private function payload(int $categoryId, string $name, int $stock): array
    {
        return [
            'name' => $name,
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 0,
            'price' => 100.00,
            'sale_price' => 90.00,
            'cost_price' => 40.00,
            'product_stock' => $stock,
            'track_stock' => 1,
            'trending' => 0,
            // `track_stock` above and `stock_status` below are two of the three columns
            // `update()` writes STRAIGHT THROUGH from request input into NOT NULL columns:
            // stock_status (ProductController.php:1146), trending (:1207) and track_stock
            // (:1211/:1215) -- all three are in this payload. Omit one and the save FAILS on
            // SQLite with a constraint violation, which would make the refusal test above pass
            // because the write died, not because it was refused. MySQL with `strict => false`
            // coerces the NULLs, so production is MORE exposed than this test database.
            // Measured, both ways, in verify-product-ownership-on-mysql.php.
            'stock_status' => 'in_stock',
        ];
    }

    private static function fresh(Product $p): Product
    {
        return Product::withoutGlobalScopes()->findOrFail($p->id);
    }

    /**
     * A MERCHANT CANNOT REWRITE OR RESTOCK ANOTHER STORE'S PRODUCT.
     *
     * The principal is entirely legitimate: merchant A, signed in, with `Edit Products` on their
     * own store. Only the bound id belongs to someone else.
     */
    public function test_a_merchant_cannot_update_another_stores_product(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $victim = $this->productIn($storeB, 'victim-original', 50);
        $categoryB = $this->categoryIn($storeB);

        $this->actingAs($merchantA)
            ->put('/product/' . $victim->id, $this->payload($categoryB, 'HIJACKED', 7));

        $after = self::fresh($victim);

        $this->assertSame('victim-original', $after->name,
            'Merchant A rewrote another store\'s product name.');
        $this->assertSame(50, (int) $after->product_stock,
            'Merchant A restocked another store\'s product.');
        $this->assertSame((int) $storeB->id, (int) $after->store_id,
            'The product changed hands.');
    }

    /**
     * THE POSITIVE CONTROL. Without it, the test above is satisfied by a handler that refuses
     * everything — or, worse, by one whose write silently fails on this database engine.
     */
    public function test_a_merchant_can_still_update_their_own_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $own = $this->productIn($storeA, 'own-original', 50);
        $categoryA = $this->categoryIn($storeA);

        $response = $this->actingAs($merchantA)
            ->put('/product/' . $own->id, $this->payload($categoryA, 'own-renamed', 12));

        $after = self::fresh($own);

        $this->assertSame('own-renamed', $after->name,
            'A merchant can no longer rename their OWN product, so the refusal test above proves '
            . 'nothing. Response: ' . $response->getStatusCode() . ' ' . substr($response->getContent(), 0, 300));
        $this->assertSame(12, (int) $after->product_stock,
            'A merchant can no longer restock their own product.');
    }
    /**
     * AND THE SAME BINDING IS NOT A READ LEAK EITHER.
     *
     * `edit()` and `show()` had no ownership check at all, so another store's product rendered
     * into this merchant's form — name, prices, cost, stock and variant rows. A read rather than
     * a write, through the identical binding.
     *
     * @dataProvider readRouteProvider
     */
    public function test_a_merchant_cannot_read_another_stores_product_form(string $suffix): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $victim = $this->productIn($storeB, 'victim-secret-name', 50);

        $response = $this->actingAs($merchantA)->get('/product/' . $victim->id . $suffix);

        $this->assertSame(302, $response->status(),
            'The refusal must be the handler\'s own redirect. A 500 would also be "not 200", and '
            . 'a 500 for every caller would satisfy this assertion AND the positive control below '
            . '-- the exact shape CLAUDE.md records as a vacuous test. Asserting 302 excludes it.');
        $this->assertStringNotContainsString('victim-secret-name', (string) $response->getContent(),
            'Another store\'s product name leaked into the response body.');
    }

    public static function readRouteProvider(): array
    {
        return ['show' => [''], 'edit' => ['/edit']];
    }

    /**
     * THE POSITIVE CONTROL FOR THE READ ROUTES. Without it, the test above is satisfied by a
     * handler that refuses its owner too.
     */
    public function test_a_merchant_can_still_open_their_own_product_form(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');
        $own = $this->productIn($storeA, 'own-visible-name', 50);

        $response = $this->actingAs($merchantA)->get('/product/' . $own->id . '/edit');

        $this->assertSame(200, $response->status(),
            'A merchant can no longer open their OWN product form, so the read-refusal test above '
            . 'proves nothing. Asserting 200 rather than "not 302" is what excludes a 500 for '
            . 'everyone. Session error: ' . json_encode(session('error')));
        $this->assertStringContainsString('own-visible-name', (string) $response->getContent(),
            'The form rendered without the product in it, so the 200 does not prove the owner can '
            . 'actually read their own product.');
    }
    // =====================================================================================
    //  P1-INV-07 — three more unchecked routes on this controller, found by the adversarial
    //  review of P1-INV-01. Two of them DELETE, and one of those deletes from disk.
    // =====================================================================================

    /** An image row for $product, with a real file on disk under uploads/. */
    private function imageFor(Product $product, string $marker): array
    {
        $dir = base_path('uploads/' . $product->store_id);
        $dirExisted = is_dir($dir);
        @mkdir($dir, 0775, true);
        $relative = 'uploads/' . $product->store_id . '/' . $marker . '-' . uniqid() . '.txt';
        file_put_contents(base_path($relative), 'pixels');
        $this->scratchFiles[] = base_path($relative);
        if (! $dirExisted) {
            $this->scratchDirs[] = $dir;
        }

        $id = DB::table('product_images')->insertGetId([
            'product_id' => $product->id, 'image_path' => $relative,
            'image_url' => '/' . $relative, 'store_id' => $product->store_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, $relative];
    }

    /**
     * A variant row. The defaults are what the DELETE tests want; the combination tests pass
     * both arguments because the view RESOLVES a variant by name and echoes its stock.
     *
     * `Product::ProductVariant($str)` matches on `product_variants.variant` (Product.php:131) and
     * the view builds `$str` from the posted attribute option names -- so for a combination test
     * `$variant` must equal the option name posted by `combinationRequest()`. Without that
     * pairing the view renders the accordion with `$ProductVariantData === null` and echoes no
     * variant data at all, which is how the first version of the combination test came to assert
     * on a string the page could never have contained whether the fix was present or not.
     */
    /**
     * The trash door with the F184 consent echo, built from the policy rather than hardcoded.
     *
     * Both POSITIVE CONTROLS below used to post nothing and assert 200, which was the contract
     * before F184 required an explicit confirmation. They are updated rather than relaxed: each
     * still proves the permitted principal CAN delete — which is what makes the refusal tests
     * above mean something — and now also proves that the confirmation path is the one that works.
     * `VariantRemovalPolicy::consentToken()` is the same call the two variant panels make when
     * they render `data-variant`, so these cannot drift from what a browser actually sends.
     */
    private function trashWithConsent(\App\Models\User $actor, int $variantId)
    {
        $variant = \App\Models\ProductVariant::withoutGlobalScopes()->findOrFail($variantId);

        return $this->actingAs($actor)->delete('/product-attribute-delete/' . $variantId, [
            'ss_confirm_variant' => \App\Services\VariantRemovalPolicy::consentToken($variant),
        ]);
    }

    private function variantFor(Product $product, string $variant = 'red', int $stock = 50): int
    {
        return DB::table('product_variants')->insertGetId([
            'product_id' => $product->id, 'variant' => $variant, 'sku' => 'v-' . uniqid(),
            'price' => 10, 'stock' => $stock, 'store_id' => $product->store_id,
            'variation_option' => 'enabled,manage_stock', 'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A MERCHANT CANNOT UNLINK ANOTHER STORE'S MEDIA FROM DISK.
     *
     * `file_delete` had no permission check and no ownership check, and it calls
     * `File::delete(base_path($image->image_path))`. This asserts the FILE as well as the row,
     * because the file is the part that cannot be undone.
     */
    public function test_a_merchant_cannot_delete_another_stores_product_image(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $victim = $this->productIn($storeB, 'victim-img-owner', 50);
        [$imageId, $relative] = $this->imageFor($victim, 'victim');

        $response = $this->actingAs($merchantA)->delete('/products/' . $imageId . '/delete');

        $this->assertSame(403, $response->status(),
            'The refusal must be a non-2xx: the caller hides the thumbnail inside its success '
            . 'handler regardless of the payload, so a 200 would tell the user it was deleted.');
        $this->assertFileExists(base_path($relative),
            "Another store's uploaded file was unlinked from disk — the irreversible half.");
        $this->assertDatabaseHas('product_images', ['id' => $imageId]);

    }

    /** THE POSITIVE CONTROL: the owner can still delete their own image, row and file. */
    public function test_a_merchant_can_delete_their_own_product_image(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $own = $this->productIn($storeA, 'own-img-owner', 50);
        [$imageId, $relative] = $this->imageFor($own, 'own');

        $response = $this->actingAs($merchantA)->delete('/products/' . $imageId . '/delete');

        $this->assertSame(200, $response->status(),
            'A merchant can no longer delete their own product image, so the refusal test above '
            . 'proves nothing. Body: ' . substr($response->getContent(), 0, 200));
        $this->assertFileDoesNotExist(base_path($relative), 'The owner\'s file was not unlinked.');
        $this->assertDatabaseMissing('product_images', ['id' => $imageId]);
    }

    /**
     * A STORED PATH THAT POINTS OUTSIDE `uploads/` IS NOT FOLLOWED.
     *
     * Ownership says whose row this is; it says nothing about where the stored string points.
     * `image_path` is database input handed to the filesystem, so a value containing `..` would
     * resolve outside the uploads tree and `File::delete()` would follow it. The row is still
     * removed — that is the user's actual intent — and nothing outside `uploads/` is touched.
     */
    public function test_an_image_path_outside_the_uploads_tree_is_not_unlinked(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $own = $this->productIn($storeA, 'own-traversal', 50);

        // A file that must survive, outside uploads/, reached by traversal from inside it.
        $outside = base_path('storage/app/p1inv07-must-survive-' . uniqid() . '.txt');
        @mkdir(dirname($outside), 0775, true);
        file_put_contents($outside, 'do not delete me');
        $this->scratchFiles[] = $outside;

        /*
         * THE INTERMEDIATE DIRECTORY MUST EXIST, and this is not pedantry: `unlink()` resolves
         * `uploads/{id}/../../storage/app/x` through the real filesystem, so if `uploads/{id}` is
         * absent the call fails with ENOENT and the outside file survives FOR THE WRONG REASON.
         *
         * Measured: this test's removal check bit the first time only because an earlier test in
         * the file had left `uploads/{id}/` behind. Once that leak was fixed by tearDown, removing
         * the containment guard stopped failing the test -- the traversal could no longer resolve.
         * Creating the directory here makes the control the only thing standing between the
         * request and the file.
         */
        $uploadDir = base_path('uploads/' . $storeA->id);
        $uploadDirExisted = is_dir($uploadDir);
        @mkdir($uploadDir, 0775, true);
        if (! $uploadDirExisted) {
            $this->scratchDirs[] = $uploadDir;
        }

        $traversal = 'uploads/' . $storeA->id . '/../../storage/app/' . basename($outside);
        $imageId = DB::table('product_images')->insertGetId([
            'product_id' => $own->id, 'image_path' => $traversal,
            'store_id' => $storeA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFileExists($outside, 'fixture: the outside file should exist before the call');

        $response = $this->actingAs($merchantA)->delete('/products/' . $imageId . '/delete');

        $this->assertSame(200, $response->status(),
            'The owner\'s own row should still be removable. Body: '
            . substr($response->getContent(), 0, 200));
        $this->assertFileExists($outside,
            'A path escaping uploads/ was followed and a file outside the uploads tree was '
            . 'unlinked.');
        $this->assertDatabaseMissing('product_images', ['id' => $imageId]);

    }

    /** A MERCHANT CANNOT DELETE ANOTHER STORE'S VARIANT — or the stock it carries. */
    /**
     * AND A PATH NAMING ANOTHER TENANT'S FOLDER IS NOT FOLLOWED EITHER -- the property P1-INV-09
     * added to this route and the one its old containment did not have.
     *
     * `file_delete()` used to carry its own containment: a private `isInsideUploadsRoot()` plus
     * `File::delete(realpath(...))`. That refused a path LEAVING uploads/ and nothing else, so a
     * row whose `image_path` names `uploads/<another store id>/<their file>` -- which never leaves
     * uploads/ and needs no `..` -- was unlinked. `StoredFilePathSafetyTest`'s census recorded the
     * exemption and leaned on the WRITE-side policy to keep `image_path` in the product's own
     * folder. The write side is a real control, but it is a DIFFERENT control: a legacy row, a row
     * written before that policy, or a row written by a vendored importer is not covered by it.
     *
     * The route now goes through `StoredUpload::delete()`, whose rule 3 asks whether the folder
     * belongs to a store this owner runs. There is NO traversal in this fixture, which is the
     * point: the old guard said yes to it.
     */
    public function test_an_image_path_naming_another_tenants_folder_is_not_unlinked(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $own = $this->productIn($storeA, 'own-foreign-folder', 50);

        // Store B's real upload, in store B's own folder. No `..`, no metacharacter: a path the
        // old containment accepted without hesitation.
        $dirB = base_path('uploads/' . $storeB->id);
        $dirBExisted = is_dir($dirB);
        @mkdir($dirB, 0775, true);
        $foreign = 'uploads/' . $storeB->id . '/victim-' . uniqid() . '.txt';
        file_put_contents(base_path($foreign), 'store B pixels');
        $this->scratchFiles[] = base_path($foreign);
        if (! $dirBExisted) {
            $this->scratchDirs[] = $dirB;
        }

        // The ROW hangs off store A's product, so the ownership check above it passes: this test
        // isolates the PATH question, exactly as the traversal test does.
        $imageId = DB::table('product_images')->insertGetId([
            'product_id' => $own->id, 'image_path' => $foreign,
            'store_id' => $storeA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFileExists(base_path($foreign), 'fixture: store B\'s file must exist before the call');

        $response = $this->actingAs($merchantA)->delete('/products/' . $imageId . '/delete');

        $this->assertSame(200, $response->status(),
            'The owner\'s own row should still be removable. Body: ' . substr($response->getContent(), 0, 200));
        $this->assertFileExists(base_path($foreign),
            "A row on store A's product named store B's folder and store B's file was unlinked.");
        $this->assertDatabaseMissing('product_images', ['id' => $imageId]);
    }

    public function test_a_merchant_cannot_delete_another_stores_variant(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $victim = $this->productIn($storeB, 'victim-variant-owner', 50);
        $variantId = $this->variantFor($victim);

        $response = $this->actingAs($merchantA)->delete('/product-attribute-delete/' . $variantId);

        $this->assertSame(403, $response->status(),
            'The refusal must be a non-2xx: the caller announces "Variant Deleted Successfully!" '
            . 'and removes the row on any 2xx without reading the body.');
        $this->assertDatabaseHas('product_variants', ['id' => $variantId]);
    }

    /** THE POSITIVE CONTROL for the variant delete. */
    public function test_a_merchant_can_delete_their_own_variant(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $own = $this->productIn($storeA, 'own-variant-owner', 50);
        $variantId = $this->variantFor($own);

        /* F184: WITHOUT the consent echo this is refused, and that refusal is the feature. */
        $unconfirmed = $this->actingAs($merchantA)->delete('/product-attribute-delete/' . $variantId);
        $this->assertSame(409, $unconfirmed->status(),
            'the trash door deleted on a bare request with no confirmation. Body: '
            . substr($unconfirmed->getContent(), 0, 200));
        $this->assertDatabaseHas('product_variants', ['id' => $variantId]);

        $response = $this->trashWithConsent($merchantA, $variantId);

        $this->assertSame(200, $response->status(),
            'A merchant can no longer delete their own variant, so the refusal test above proves '
            . 'nothing. Body: ' . substr($response->getContent(), 0, 200));
        $this->assertDatabaseMissing('product_variants', ['id' => $variantId]);
    }

    /**
     * AND THE REQUEST-INPUT READ LEAK.
     *
     * `attribute_combination_edit` resolves `Product::find($request->id)` — not a route-model
     * binding — so P1-INV-01's guard does not apply to it, and the foreign product went straight
     * into the view.
     */
    public function test_a_merchant_cannot_open_another_stores_attribute_combination(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $victim = $this->productIn($storeB, 'victim-combination-secret', 50);
        $this->variantFor($victim, 'Red', self::VICTIM_VARIANT_STOCK);

        $response = $this->actingAs($merchantA)
            ->put('/products/attribute_combination/edit', $this->combinationRequest($victim->id));

        // THE LEAK ASSERTION GOES FIRST, DELIBERATELY. With the guard removed the handler
        // answers 200 and renders the victim's variant, so a status assertion placed first
        // fails and PHPUnit never reaches the assertion that states the actual security claim.
        // Ordered this way, the removal check reports "another store's variant stock leaked",
        // which is the defect; the status assertion below still catches a 500 pretending to be
        // a refusal.
        $this->assertStringNotContainsString((string) self::VICTIM_VARIANT_STOCK,
            (string) $response->getContent(),
            'Another store\'s variant stock leaked into the response.');
        $this->assertSame(302, $response->status(),
            'The refusal must be the handler\'s own redirect, not a 500. Body: '
            . substr((string) $response->getContent(), 0, 200));
    }

    /** THE POSITIVE CONTROL: the owner's own combination screen still answers. */
    public function test_a_merchant_can_open_their_own_attribute_combination(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $own = $this->productIn($storeA, 'own-combination', 50);
        $this->variantFor($own, 'Red', self::OWN_VARIANT_STOCK);

        $response = $this->actingAs($merchantA)
            ->put('/products/attribute_combination/edit', $this->combinationRequest($own->id));

        $this->assertSame(200, $response->status(),
            'The owner is no longer served their own combination screen, so the refusal test '
            . 'above proves nothing. Session error: ' . json_encode(session('error'))
            . ' Body: ' . substr((string) $response->getContent(), 0, 200));
        $this->assertStringContainsString((string) self::OWN_VARIANT_STOCK,
            (string) $response->getContent(),
            'The owner\'s own variant stock is not in their own combination screen, so the '
            . 'cross-store assertion on that same value measures nothing.');
    }

    // =============================================================================================
    // P1-INV-10 (c) — the unscoped attribute-option lookup
    // =============================================================================================

    /**
     * An attribute option row with a `terms` value that appears nowhere else on the page.
     *
     * `attribute_combination()` (the POST sibling of the PUT handler tested above) differs from it
     * in exactly the way that matters here: it treats `attribute_options_<no>` as a list of option
     * IDS, looks each one up and echoes the row's `terms`. The PUT handler takes the posted values
     * as the names directly. So the leak this item closes is only reachable through the POST.
     *
     * @return array{0: int, 1: string} the option id and its terms
     */
    private function attributeOptionIn($store, string $marker): array
    {
        $attributeId = DB::table('product_attributes')->insertGetId([
            'name' => 'attr-' . $marker, 'slug' => 'attr-' . uniqid(),
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $terms = 'TERMS-' . strtoupper($marker) . '-' . uniqid();

        $optionId = DB::table('product_attribute_options')->insertGetId([
            'attribute_id' => $attributeId, 'terms' => $terms, 'order' => 0,
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$optionId, $terms];
    }

    /**
     * The POST form that makes `attribute_combination` resolve ONE option id.
     *
     * Same three load-bearing keys as `combinationRequest()`, and for the same reason: drop
     * `for_variation_7` or let `attribute_options_7` be a scalar and the `foreach` never runs, so
     * a "the victim's terms are absent" assertion passes against an empty combination table
     * whether the predicate is there or not.
     */
    private function optionCombinationRequest(int $optionId): array
    {
        return [
            'attribute_no' => ['7'],
            'for_variation_7' => 1,
            'attribute_options_7' => [$optionId],
            'price' => 100.00,
            'sku' => 'combo',
            'product_stock' => 5,
        ];
    }

    /**
     * ANOTHER STORE'S OPTION TERMS DO NOT REACH THE COMBINATION TABLE. P1-INV-10 (c).
     *
     * `ProductAttributeOption::where('id', $id)->first()` carried no store predicate, while the
     * sibling that POPULATES the very dropdown these ids come from (`attribute_option()`, :1841)
     * and `ProductAttributeOptionController::ownedOption()` both do. `ProductAttributeOption`
     * registers no StoreScope and is absent from `config/tenancy.php`'s `scoped_models`, so
     * nothing else narrowed it: a merchant who posted another tenant's option ids got that
     * tenant's `terms` rendered back.
     *
     * A read, and only of attribute term strings. Recorded as what it is.
     */
    public function test_another_stores_attribute_option_contributes_no_terms(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        [$victimOptionId, $victimTerms] = $this->attributeOptionIn($storeB, 'victim');

        $response = $this->actingAs($merchantA)
            ->post('/products/attribute_combination', $this->optionCombinationRequest($victimOptionId));

        // The leak assertion first, so a removal check reports the defect rather than a status.
        $this->assertStringNotContainsString(
            $victimTerms,
            (string) $response->getContent(),
            "Another store's attribute option terms were rendered into this merchant's "
            . 'combination table.'
        );
        $this->assertNotSame(404, $response->status(),
            'Route gone: the absence assertion above would pass against a 404 (census-failure '
            . 'mode 2).');
        $this->assertNotSame(500, $response->status(),
            'The handler now fails for everyone, so the absence assertion proves nothing. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * THE POSITIVE CONTROL: the merchant's OWN option still builds a combination.
     *
     * This is the assertion that catches the predicate being too tight -- which is the failure
     * this phase has already shipped twice. Without it, "the terms are absent" would pass by
     * refusing everybody.
     */
    public function test_the_owners_own_attribute_options_still_build_combinations(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        [$ownOptionId, $ownTerms] = $this->attributeOptionIn($storeA, 'own');

        $response = $this->actingAs($merchantA)
            ->post('/products/attribute_combination', $this->optionCombinationRequest($ownOptionId));

        $this->assertSame(200, $response->status(),
            'The owner is no longer served their own combination table. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertStringContainsString(
            $ownTerms,
            (string) $response->getContent(),
            "The merchant's own option terms are missing from their own combination table, so "
            . 'the cross-store assertion on that same kind of value measures nothing.'
        );
    }

    // =============================================================================================
    // P1-INV-10 (e) — the permission limbs, and the lockout control on each of them
    // =============================================================================================
    //
    // Each pair below is one route. The NEGATIVE drives a merchant who owns the target and holds
    // no product permission: before (e) that call succeeded, because ownership was the only
    // question these three handlers asked. The POSITIVE CONTROL drives the PLATFORM OPERATOR with
    // no permission grant at all, which is the assertion that catches the lockout: Laratrust has
    // no super-admin bypass (CLAUDE.md), so an `isAbleTo`-only limb would refuse the operator and
    // every negative test above would still pass.

    /** A merchant who OWNS the image but holds no product permission is refused. */
    public function test_a_merchant_without_edit_products_cannot_delete_their_own_product_image(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        // No grantPermission() call: that omission IS the test.

        $own = $this->productIn($storeA, 'own-img-no-permission', 50);
        [$imageId, $relative] = $this->imageFor($own, 'own-no-perm');

        $response = $this->actingAs($merchantA)->delete('/products/' . $imageId . '/delete');

        $this->assertSame(403, $response->status(),
            'A merchant with no product permission still reached file_delete. Body: '
            . substr((string) $response->getContent(), 0, 200));
        $this->assertFileExists(base_path($relative), 'The file was unlinked anyway.');
        $this->assertDatabaseHas('product_images', ['id' => $imageId]);
    }

    /**
     * THE LOCKOUT CONTROL. The platform operator holds no role and is still served.
     *
     * `makePlatformAdmin()` creates `type = 'super admin'` with no role and therefore no
     * permissions at all, which is the live shape measured in
     * verify-product-ownership-on-mysql.php. If this test fails, `lacksProductPermission()` has
     * been reordered to put `isAbleTo` first and the operator can no longer manage product media
     * anywhere on the platform.
     */
    public function test_the_platform_operator_can_still_delete_a_product_image(): void
    {
        [, $storeA] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();

        $this->assertFalse($operator->isAbleTo('Edit Products'),
            'fixture: the operator must hold NO product permission, or this test cannot '
            . 'distinguish the exemption from an ordinary grant.');

        $product = $this->productIn($storeA, 'operator-img', 50);
        [$imageId, $relative] = $this->imageFor($product, 'operator');

        $response = $this->actingAs($operator)->delete('/products/' . $imageId . '/delete');

        $this->assertSame(200, $response->status(),
            'THE PLATFORM OPERATOR IS LOCKED OUT of product media management. Body: '
            . substr((string) $response->getContent(), 0, 200));
        $this->assertFileDoesNotExist(base_path($relative));
        $this->assertDatabaseMissing('product_images', ['id' => $imageId]);
    }

    /** A merchant who OWNS the variant but holds no product permission is refused. */
    public function test_a_merchant_without_edit_products_cannot_delete_their_own_variant(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $own = $this->productIn($storeA, 'own-variant-no-permission', 50);
        $variantId = $this->variantFor($own, 'red', self::OWN_VARIANT_STOCK);

        $response = $this->actingAs($merchantA)->delete('/product-attribute-delete/' . $variantId);

        $this->assertSame(403, $response->status(),
            'A merchant with no product permission still reached product_attribute_delete. '
            . 'Body: ' . substr((string) $response->getContent(), 0, 200));
        $this->assertDatabaseHas('product_variants', ['id' => $variantId]);
    }

    /**
     * THE LOCKOUT CONTROL for the variant delete.
     *
     * Also the assertion that the limb was placed BEFORE `findOrFail()` without breaking the
     * 404 a genuinely missing id has always produced -- see the companion test below.
     */
    public function test_the_platform_operator_can_still_delete_a_product_variant(): void
    {
        [, $storeA] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();

        $product = $this->productIn($storeA, 'operator-variant', 50);
        $variantId = $this->variantFor($product, 'red', self::OWN_VARIANT_STOCK);

        /* F184 applies to the operator too: no confirmation, no delete. */
        $unconfirmed = $this->actingAs($operator)->delete('/product-attribute-delete/' . $variantId);
        $this->assertSame(409, $unconfirmed->status(),
            'the operator deleted a variant with no confirmation. Body: '
            . substr((string) $unconfirmed->getContent(), 0, 200));
        $this->assertDatabaseHas('product_variants', ['id' => $variantId]);

        $response = $this->trashWithConsent($operator, $variantId);

        $this->assertSame(200, $response->status(),
            'THE PLATFORM OPERATOR IS LOCKED OUT of variant management. Body: '
            . substr((string) $response->getContent(), 0, 200));
        $this->assertDatabaseMissing('product_variants', ['id' => $variantId]);
    }

    /**
     * A PERMITTED CALLER STILL GETS 404 FOR AN ID THAT DOES NOT EXIST.
     *
     * The permission limb sits ahead of `findOrFail($id)` so that a caller WITHOUT permission
     * cannot use 404-vs-403 to enumerate variant ids. The cost of that ordering would be turning
     * every missing id into a 403 for everybody, which would read as a permission problem when it
     * is not. This asserts the ordering kept both answers.
     */
    public function test_a_permitted_caller_still_gets_404_for_a_variant_that_does_not_exist(): void
    {
        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $absent = (int) (DB::table('product_variants')->max('id') ?? 0) + 5000;

        $response = $this->actingAs($merchantA)->delete('/product-attribute-delete/' . $absent);

        $this->assertSame(404, $response->status(),
            'A missing variant id now answers ' . $response->status() . ' for a caller who '
            . 'holds the permission, so findOrFail no longer reports absence as absence.');
    }

    /** A merchant with NEITHER product permission cannot open the combination screen. */
    public function test_a_merchant_without_any_product_permission_cannot_open_a_combination_screen(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $own = $this->productIn($storeA, 'own-combo-no-permission', 50);
        $this->variantFor($own, 'Red', self::OWN_VARIANT_STOCK);

        $response = $this->actingAs($merchantA)
            ->put('/products/attribute_combination/edit', $this->combinationRequest($own->id));

        $this->assertSame(302, $response->status(),
            'A merchant with no product permission still reached attribute_combination_edit. '
            . 'Body: ' . substr((string) $response->getContent(), 0, 200));
        $this->assertStringNotContainsString((string) self::OWN_VARIANT_STOCK,
            (string) $response->getContent(),
            'The refusal still rendered the combination view.');
    }

    /**
     * THE COMPATIBILITY CONTROL: `Manage Products` ALONE is enough for the render.
     *
     * This is the assertion the limb's shape exists for. `show()` (:881) and `edit()` (:934) call
     * `isAbleTo` nowhere, and show.blade.php:730 PUTs to this route from the read-only screen, so
     * a staff role holding `Manage Products` for the catalogue list and nothing more must still
     * see the variations panel. If this fails, the limb was narrowed to `Edit Products` and that
     * role lost a screen it had before this task.
     */
    public function test_manage_products_alone_opens_the_combination_screen(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Products');

        $this->assertFalse($merchantA->fresh()->isAbleTo('Edit Products'),
            'fixture: this principal must NOT hold Edit Products, or the test cannot tell the '
            . 'two names apart.');

        $own = $this->productIn($storeA, 'own-combo-manage-only', 50);
        $this->variantFor($own, 'Red', self::OWN_VARIANT_STOCK);

        $response = $this->actingAs($merchantA->fresh())
            ->put('/products/attribute_combination/edit', $this->combinationRequest($own->id));

        $this->assertSame(200, $response->status(),
            'A Manage Products-only role lost the variations panel. Session error: '
            . json_encode(session('error')) . ' Body: '
            . substr((string) $response->getContent(), 0, 200));
    }

    /**
     * A STORE-LESS SESSION GETS NO OPTION TERMS. P1-INV-10 (c), the fail-open edge.
     *
     * ADDED BECAUSE A MUTANT SURVIVED. Removing the `empty($currentStore)` guard while keeping
     * `->where('store_id', $currentStore)` left all 22 tests passing, because none of them drove a
     * principal with no current store. That is the exact shape the guard exists for: Laravel turns
     * `where($column, null)` into `whereNull`, so without the guard a store-less caller matches
     * every option row whose own `store_id` is NULL — fail OPEN, not closed.
     *
     * THE FIXTURE IS THE DELICATE PART. `getCurrentStore()` (app/Helper/helper.php:401-415) does
     * not simply read `current_store`: when it is empty it falls back to
     * `Store::where('created_by', $user->id)->first()`. So `makeTenant()` cannot be used here —
     * its merchant owns a store and would resolve one. This uses `makeMerchant()`, which creates
     * the user and no store, and asserts the premise rather than assuming it.
     */
    public function test_a_store_less_session_gets_no_option_terms(): void
    {
        $merchant = $this->makeMerchant();
        $this->grantPermission($merchant, 'Edit Products');

        $this->assertNull(
            getCurrentStore($merchant->id),
            'FIXTURE PREMISE: this principal must resolve NO current store, or the guard under '
            . 'test is never reached and this test passes for the wrong reason. getCurrentStore() '
            . 'falls back to an owned Store, so the merchant must own none.'
        );

        // A store-less option row: exactly what `whereNull('store_id')` would match.
        $attributeId = DB::table('product_attributes')->insertGetId([
            'name' => 'attr-orphan', 'slug' => 'attr-' . uniqid(),
            'store_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orphanTerms = 'TERMS-ORPHAN-' . uniqid();
        $orphanOptionId = DB::table('product_attribute_options')->insertGetId([
            'attribute_id' => $attributeId, 'terms' => $orphanTerms, 'order' => 0,
            'store_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($merchant->fresh())
            ->post('/products/attribute_combination', $this->optionCombinationRequest($orphanOptionId));

        $this->assertStringNotContainsString(
            $orphanTerms,
            (string) $response->getContent(),
            'A caller with no store resolved an option row that belongs to no store. '
            . "`where('store_id', null)` is `whereNull`, not `matches nothing`."
        );
        $this->assertNotSame(500, $response->status(),
            'The handler now fails for a store-less caller, so the absence assertion above proves '
            . 'nothing. Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * A CALLER WITHOUT PERMISSION CANNOT TELL A MISSING VARIANT FROM A PRESENT ONE. P1-INV-10 (e).
     *
     * ADDED BECAUSE A MUTANT SURVIVED. Moving the permission limb to AFTER `findOrFail($id)` left
     * all 22 tests passing: the refusal test above drives an EXISTING variant (403 either way) and
     * the 404 test drives a PERMITTED caller (404 either way). Only the remaining combination —
     * no permission, absent id — distinguishes the two orderings, and nothing drove it.
     *
     * Without the ordering, 403-vs-404 is an enumeration oracle: a caller who may touch nothing
     * still learns which variant ids exist.
     */
    public function test_an_unpermitted_caller_gets_403_not_404_for_a_missing_variant(): void
    {
        [$merchantA] = $this->makeTenant();
        // No grantPermission(): that omission and the absent id together ARE the test.

        $absent = (int) (DB::table('product_variants')->max('id') ?? 0) + 5000;

        $response = $this->actingAs($merchantA)->delete('/product-attribute-delete/' . $absent);

        $this->assertSame(403, $response->status(),
            'A caller with no product permission got ' . $response->status() . ' for an absent '
            . 'variant id. If that is 404, the permission limb runs after findOrFail() and '
            . '403-vs-404 tells an unpermitted caller which ids exist.');
    }

    /** THE LOCKOUT CONTROL for the combination screen. */
    public function test_the_platform_operator_can_still_open_a_combination_screen(): void
    {
        [, $storeA] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();

        $product = $this->productIn($storeA, 'operator-combo', 50);
        $this->variantFor($product, 'Red', self::OWN_VARIANT_STOCK);

        $response = $this->actingAs($operator)
            ->put('/products/attribute_combination/edit', $this->combinationRequest($product->id));

        $this->assertSame(200, $response->status(),
            'THE PLATFORM OPERATOR IS LOCKED OUT of the combination screen. Session error: '
            . json_encode(session('error')) . ' Body: '
            . substr((string) $response->getContent(), 0, 200));
    }
}
