<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-INV-08 / S38-1 — the merchant category surface.
 * =============================================================================================
 *
 * `CategoryController::destroy` is the most destructive cross-tenant route found on this
 * milestone, and it is destructive in a way a database rollback cannot undo.
 *
 * `Category` DOES carry `BelongsToStore` (`app/Models/Category.php:14`) but is ABSENT from
 * `config/tenancy.php`'s `scoped_models`, and absence from the allowlist is the half that
 * decides: `StoreScope::isActiveFor()` short-circuits for an unlisted model, so the trait adds no
 * predicate for any principal and `Category::find($id)` reaches every tenant. CLAUDE.md insists
 * on both halves of that check for exactly this reason -- an earlier draft of this docblock said
 * the model carries no trait, which is false. The method's only
 * authorization was `isAbleTo('Delete Product Category')` — *may this user delete categories*,
 * never *may this user delete THIS category*. It then iterated `$mainCategory->product_details`
 * (a plain `hasMany(Product::class, 'category_id')` on the equally unscoped `Product`) and called
 * `Product::productImageDelete()` on each, which UNLINKS FROM DISK every product image, the cover
 * image, the downloadable product and the preview video, and deletes all `product_images` and all
 * `product_variants` rows — the stock with them.
 *
 * THE CHILD GUARD WAS PART OF THE DEFECT, NOT A MITIGATION. The only `getCurrentStore()` in the
 * method asked whether any child of the VICTIM's category belonged to the ATTACKER's store. For a
 * foreign category that is false even when the victim's category does have children, so the one
 * predicate present waved the deletion through and orphaned them. Both the ownership guard and
 * the child guard's store are therefore measured here, separately.
 *
 * WHAT THIS FILE ASSERTS ON, AND WHY IT IS THE FILESYSTEM. A test that only checked rows would
 * pass against a fix that refused the row deletes and still unlinked the files. These tests write
 * real files under `uploads/{store_id}` and assert `is_file()` after the request, because the file
 * is the part that cannot be recovered.
 *
 * ASSERTIONS GO THROUGH withoutGlobalScopes(). `Category`, `Product`, `ProductImage` and
 * `ProductVariant` add no predicate, but `Cart` DOES (it carries `BelongsToStore` and is
 * allowlisted at `config/tenancy.php:298`), so a plain `Cart::find()` in a test answers against
 * whichever tenant happens to be resolved rather than against the table — an "it survived"
 * assertion would pass whether or not the fix works. CLAUDE.md records that exact false reading
 * costing a real measurement on a cart test.
 *
 * WHAT THE CART ROWS ACTUALLY PROVE HERE. In an authenticated merchant request `StoreScope`
 * already narrows `Cart::where('product_id', …)->delete()` to the caller's own store, so the
 * victim's basket rows were never the exposure on this route — an earlier draft of S38-1 claimed
 * they were and was wrong. They are asserted anyway, through `withoutGlobalScopes()`, because
 * that protection is the scope's and `StoreScope` fails open when no tenant resolves; if a later
 * change moves this code anywhere a tenant does not resolve, this assertion is what notices. The
 * `productImageDelete()` hardening that would make it independent of the scope is P1-INV-09, not
 * this package — stated so the boundary is not mistaken for an oversight.
 */
class MerchantCategoryOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $scratchFiles = [];

    /** @var list<string> */
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

    private function categoryIn($store, string $name, int $parentId = 0): Category
    {
        return Category::forceCreate([
            'name' => $name,
            'slug' => 'collections/' . $name . '-' . uniqid(),
            'parent_id' => $parentId,
            'trending' => 0,
            'status' => 1,
            'image_path' => null,
            'store_id' => $store->id,
        ]);
    }

    /**
     * A real file under `uploads/{store_id}`, and the relative path the row stores.
     *
     * `productImageDelete()` resolves `base_path($path)` and unlinks it, so a fixture under /tmp
     * would never be touched and the test would pass without the handler doing anything.
     *
     * @return array{0: string, 1: string} [relative path, absolute path]
     */
    private function fileFor($store, string $label): array
    {
        // A DIRECTORY NAME NO REAL STORE CAN OWN. `productImageDelete()` reads the path out of
        // the row, so it need not be the real `uploads/<store_id>`: using the real one meant a
        // crashed run left scratch files in `uploads/1/`, and `uploads/` is tracked. Raised by an
        // adversarial review of this file.
        $dir = base_path('uploads/p1inv08-' . $store->id . '-' . uniqid());
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->scratchDirs[] = $dir;
        }

        $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $dir)
            . '/' . $label . '-' . uniqid() . '.jpg';
        $absolute = base_path($relative);
        file_put_contents($absolute, 'x');
        $this->scratchFiles[] = $absolute;

        return [$relative, $absolute];
    }

    /**
     * A product in $category carrying every artefact `productImageDelete()` destroys.
     *
     * @return array{product: Product, image_id: int, variant_id: int, cart_id: int, files: list<string>}
     */
    private function productWithEverything($store, Category $category, string $name): array
    {
        [$coverRel, $coverAbs] = $this->fileFor($store, $name . '-cover');
        [$imageRel, $imageAbs] = $this->fileFor($store, $name . '-image');

        $product = Product::forceCreate([
            'name' => $name, 'slug' => 'p-' . uniqid(),
            'category_id' => $category->id,
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock',
            'cover_image_path' => $coverRel,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $imageId = DB::table('product_images')->insertGetId([
            'product_id' => $product->id, 'image_path' => $imageRel,
            'image_url' => '/' . $imageRel, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $product->id, 'variant' => 'red', 'sku' => 'v-' . uniqid(),
            'price' => 10, 'stock' => 50, 'store_id' => $store->id,
            'variation_option' => 'enabled,manage_stock', 'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cartId = DB::table('carts')->insertGetId([
            'customer_id' => 777, 'product_id' => $product->id, 'variant_id' => $variantId,
            'qty' => 2, 'price' => 100, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'product' => $product,
            'image_id' => $imageId,
            'variant_id' => $variantId,
            'cart_id' => $cartId,
            'files' => [$coverAbs, $imageAbs],
        ];
    }

    /** Everything the victim had, still there. Scopes off, deliberately — see the class docblock. */
    private function assertVictimIntact(array $fixture, Category $category, string $why): void
    {
        foreach ($fixture['files'] as $path) {
            $this->assertFileExists($path, $why . ' — a file was unlinked from disk: ' . $path);
        }

        $this->assertNotNull(
            DB::table('product_images')->where('id', $fixture['image_id'])->first(),
            $why . ' — the product_images row is gone.');
        $this->assertNotNull(
            DB::table('product_variants')->where('id', $fixture['variant_id'])->first(),
            $why . ' — the product_variants row (and its stock) is gone.');
        $this->assertNotNull(
            DB::table('carts')->where('id', $fixture['cart_id'])->first(),
            $why . ' — a shopper\'s cart row is gone.');
        $this->assertNotNull(
            Category::withoutGlobalScopes()->find($category->id),
            $why . ' — the category row itself is gone.');
    }

    /**
     * A MERCHANT CANNOT DELETE ANOTHER STORE'S CATEGORY, ITS PRODUCTS' MEDIA OR THEIR VARIANTS.
     *
     * The principal is entirely legitimate: merchant A, signed in, holding
     * `Delete Product Category` on their own store. Only the posted id belongs to someone else.
     */
    public function test_a_merchant_cannot_delete_another_stores_category(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Product Category');

        $victimCategory = $this->categoryIn($storeB, 'victim-category');
        $fixture = $this->productWithEverything($storeB, $victimCategory, 'victimprod');

        $response = $this->actingAs($merchantA)->delete('/category/' . $victimCategory->id);

        $this->assertVictimIntact($fixture, $victimCategory,
            'A cross-store category delete was carried out');
        $this->assertSame(302, $response->status(),
            'The refusal must be the controller\'s own redirect, not a 500. Body: '
            . substr((string) $response->getContent(), 0, 200));
    }

    /**
     * THE CHILD GUARD IS NOT A BYPASS.
     *
     * The guard originally asked `store_id = getCurrentStore()`, so for a foreign category it
     * found no children and fell through to the deletion. This drives the case that guard was
     * supposed to stop, from the wrong store: a victim category that DOES have a child.
     */
    public function test_a_victim_category_with_children_is_still_refused(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Product Category');

        $victimParent = $this->categoryIn($storeB, 'victim-parent');
        $victimChild = $this->categoryIn($storeB, 'victim-child', $victimParent->id);
        $fixture = $this->productWithEverything($storeB, $victimParent, 'victimchildprod');

        $response = $this->actingAs($merchantA)->delete('/category/' . $victimParent->id);

        $this->assertVictimIntact($fixture, $victimParent,
            'A cross-store delete of a category WITH children was carried out');
        $this->assertNotNull(Category::withoutGlobalScopes()->find($victimChild->id),
            'The victim\'s child category was orphaned or removed.');
        $this->assertSame(302, $response->status());
    }

    /** THE POSITIVE CONTROL: the owner can still delete their own category and its media. */
    public function test_a_merchant_can_still_delete_their_own_category(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Product Category');

        $own = $this->categoryIn($storeA, 'own-category');
        $fixture = $this->productWithEverything($storeA, $own, 'ownprod');

        $response = $this->actingAs($merchantA)->delete('/category/' . $own->id);

        $this->assertSame(302, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 200));
        $this->assertNull(Category::withoutGlobalScopes()->find($own->id),
            'The owner can no longer delete their own category, so the refusal tests above prove '
            . 'nothing. Session error: ' . json_encode(session('error')));
        $this->assertNull(
            DB::table('product_variants')->where('id', $fixture['variant_id'])->first(),
            'The owner\'s own variant rows were not cleaned up, so this control does not show '
            . 'the destructive path running.');
        foreach ($fixture['files'] as $path) {
            $this->assertFileDoesNotExist($path,
                'The owner\'s own media was not unlinked, so this control does not show the '
                . 'filesystem path running at all — which is the path the negative tests claim '
                . 'is blocked.');
        }
    }

    /**
     * THE CHILD GUARD STILL WORKS FOR THE OWNER'S OWN CATEGORIES.
     *
     * Re-pointing the guard at `$category->store_id` must not have disabled it. For an own-store
     * category the two values are the same, so this must still refuse.
     */
    public function test_the_owner_still_cannot_delete_their_own_category_with_children(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Product Category');

        $parent = $this->categoryIn($storeA, 'own-parent');
        $child = $this->categoryIn($storeA, 'own-child', $parent->id);
        $fixture = $this->productWithEverything($storeA, $parent, 'ownparentprod');

        $this->actingAs($merchantA)->delete('/category/' . $parent->id);

        $this->assertVictimIntact($fixture, $parent,
            'The child-category guard stopped protecting the owner\'s own categories');
        $this->assertNotNull(Category::withoutGlobalScopes()->find($child->id));
    }

    /**
     * A MERCHANT CANNOT REWRITE ANOTHER STORE'S CATEGORY.
     *
     * `update()` is route-model-bound, so this is the same shape P1-INV-01 closed on
     * `ProductController::update`. `parent_id` is in the payload because reparenting a foreign
     * category under one of the caller's own ids is part of what the defect allowed.
     */
    public function test_a_merchant_cannot_update_another_stores_category(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Product Category');

        $ownParent = $this->categoryIn($storeA, 'attacker-parent');
        $victim = $this->categoryIn($storeB, 'victim-untouched');

        $response = $this->actingAs($merchantA)->put('/category/' . $victim->id, [
            'name' => 'HIJACKED',
            'parent_id' => $ownParent->id,
            'trending' => 1,
            'status' => 0,
        ]);

        $fresh = Category::withoutGlobalScopes()->findOrFail($victim->id);
        $this->assertSame('victim-untouched', $fresh->name,
            'Another store\'s category was renamed.');
        $this->assertSame(0, (int) $fresh->parent_id,
            'Another store\'s category was reparented under the caller\'s own tree.');
        $this->assertSame(1, (int) $fresh->status,
            'Another store\'s category had its status rewritten.');
        $this->assertSame(302, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 200));
    }

    /** THE POSITIVE CONTROL: the owner can still rename their own category. */
    public function test_a_merchant_can_still_update_their_own_category(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Product Category');

        $own = $this->categoryIn($storeA, 'own-renameable');

        $response = $this->actingAs($merchantA)->put('/category/' . $own->id, [
            'name' => 'RENAMED',
            'parent_id' => 0,
            'trending' => 1,
            'status' => 1,
        ]);

        $this->assertSame(302, $response->status());
        $this->assertSame('RENAMED',
            Category::withoutGlobalScopes()->findOrFail($own->id)->name,
            'The owner can no longer rename their own category, so the refusal test above proves '
            . 'nothing. Session error: ' . json_encode(session('error')));
    }

    /**
     * THE PLATFORM OPERATOR IS THE ONLY PRINCIPAL FOR WHOM THE CHILD GUARD'S STORE IS LIVE.
     * ==========================================================================================
     *
     * Named by an adversarial review of this file, and it is the finding that mattered most about
     * the tests: `test_a_victim_category_with_children_is_still_refused` CANNOT bite the
     * child-guard change as shipped, because the ownership guard returns first and `:376` is
     * never reached. Reverting the child guard alone leaves all six tests above green. The
     * removal check that found it needed TWO mutations — ownership off AND the guard reverted —
     * which proves defence in depth but does NOT prove the child guard's store value is covered.
     *
     * The operator is the gap. `categoryIsOutsideCurrentStore()` exempts them, so they are the
     * one principal who reaches `:376` with `$category->store_id != getCurrentStore()`. And the
     * values really do differ for them: `getCurrentStore()` returns a HARDCODED 1 for a
     * `super admin` with no `current_store` (`app/Helper/helper.php:403-404`), whichever store
     * they are administering.
     *
     * So this test measures the change's only live behavioural effect. OLD code: children were
     * sought in the operator's store, none were found, the deletion proceeded and store B's
     * children were orphaned. NEW code: children are sought in store B, found, and the delete is
     * refused with the child-category message. A NARROWING for the operator — recorded in the
     * ledger and the issue as a deliberate behaviour change, with "delete the leaves first" as
     * the workaround.
     *
     * `grantPermission` IS load-bearing here, unlike on the P1-INV-07 routes: `Utility::addNewData`
     * grants the seeded `super admin` role a list that does NOT include the category permissions
     * (they go to `admin`), so without the grant the outer check refuses and this measures
     * nothing. `SecurityTestCase::setUp` already calls `satisfyUpdaterGate()`, which is what
     * stops XSS::handle redirecting a super admin before the controller runs.
     */
    public function test_the_operator_is_refused_on_a_foreign_category_with_children(): void
    {
        $operator = $this->makePlatformAdmin();
        // STORE 1 MUST NOT BE THE VICTIM. getCurrentStore() returns a hardcoded 1 for a super
        // admin with no current_store, and under RefreshDatabase the first store created IS id 1
        // -- so without this throwaway tenant the victim store and the operator's resolved store
        // are the same row and the test measures nothing. The premise assertion below caught
        // exactly that on the first run of this test.
        $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($operator, 'Delete Product Category');

        $victimParent = $this->categoryIn($storeB, 'operator-victim-parent');
        $victimChild = $this->categoryIn($storeB, 'operator-victim-child', $victimParent->id);
        $fixture = $this->productWithEverything($storeB, $victimParent, 'operatorprod');

        // The premise this test rests on: the two store values genuinely differ for the operator,
        // so `:376` is being asked a different question than it was before the change. Asserted
        // rather than assumed -- if a future change made getCurrentStore() resolve to store B for
        // this principal, every assertion below would pass while measuring nothing.
        $this->assertNotSame((int) $storeB->id, (int) getCurrentStore($operator->id),
            'getCurrentStore() already resolves to the victim store for this operator, so the '
            . 'child guard is being asked the same question either way and this test is vacuous.');

        $response = $this->actingAs($operator)->delete('/category/' . $victimParent->id);

        $this->assertVictimIntact($fixture, $victimParent,
            'The operator destroyed a foreign category that HAS children');
        $this->assertNotNull(Category::withoutGlobalScopes()->find($victimChild->id),
            'The operator orphaned the victim\'s child category -- which is exactly what the old '
            . 'child guard allowed, because it looked for children in the operator\'s own store.');
        $this->assertSame(302, $response->status());
    }

    /**
     * THE OPERATOR'S POSITIVE CONTROL: the exemption still works.
     *
     * Without this, the test above is satisfied by an operator who is refused for the WRONG
     * reason -- by the ownership guard rather than the child guard. A leaf category in the same
     * foreign store must still delete.
     */
    public function test_the_operator_can_still_delete_a_foreign_leaf_category(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->makeTenant(); // see the test above: store 1 must not be the victim
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($operator, 'Delete Product Category');

        $leaf = $this->categoryIn($storeB, 'operator-leaf');
        $fixture = $this->productWithEverything($storeB, $leaf, 'operatorleafprod');

        $this->assertNotSame((int) $storeB->id, (int) getCurrentStore($operator->id),
            'The victim store is the operator\'s resolved store, so this control would pass even '
            . 'without the exemption.');

        $response = $this->actingAs($operator)->delete('/category/' . $leaf->id);

        $this->assertSame(302, $response->status());
        $this->assertNull(Category::withoutGlobalScopes()->find($leaf->id),
            'The operator exemption is not working, so the refusal test above proves only that '
            . 'the OWNERSHIP guard refused -- not that the child guard did. Session error: '
            . json_encode(session('error')));
        $this->assertNull(
            DB::table('product_variants')->where('id', $fixture['variant_id'])->first(),
            'The destructive path did not run for the operator.');
    }

    /**
     * THE UPLOAD PATH IS GATED TOO, AND MEASURED RATHER THAN READ.
     *
     * Raised by review: the cross-store update test sends no file, so
     * `Utility::updateStorageLimit` (which writes `users.storage_limit`),
     * `Utility::changeStorageLimit` (which File::globs and File::deletes the victim's EXISTING
     * image) and `Utility::upload_file` never executed — moving the guard below them would have
     * failed nothing. This drives the same request WITH a file.
     */
    public function test_a_cross_store_update_with_an_image_unlinks_nothing_and_debits_nobody(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Product Category');

        [$victimRel, $victimAbs] = $this->fileFor($storeB, 'victim-category-art');
        $victim = $this->categoryIn($storeB, 'victim-with-art');
        $victim->image_path = $victimRel;
        $victim->save();

        $quotaBefore = \App\Models\User::withoutGlobalScopes()
            ->findOrFail($merchantA->id)->storage_limit;

        $response = $this->actingAs($merchantA)->put('/category/' . $victim->id, [
            'name' => 'HIJACKED-WITH-ART',
            'parent_id' => 0,
            'trending' => 1,
            'status' => 1,
            'image' => \Illuminate\Http\UploadedFile::fake()->image('attacker.jpg'),
        ]);

        $this->assertFileExists($victimAbs,
            'Another store\'s category artwork was unlinked from disk by a cross-store update.');
        $this->assertSame('victim-with-art',
            Category::withoutGlobalScopes()->findOrFail($victim->id)->name,
            'Another store\'s category was renamed on the upload path.');
        $this->assertSame($victimRel,
            Category::withoutGlobalScopes()->findOrFail($victim->id)->image_path,
            'Another store\'s category image_path was repointed into the caller\'s media tree.');
        $this->assertSame($quotaBefore,
            \App\Models\User::withoutGlobalScopes()->findOrFail($merchantA->id)->storage_limit,
            'The caller\'s storage quota moved, so Utility::updateStorageLimit ran before the '
            . 'refusal -- the guard is placed too late.');
        $this->assertSame(302, $response->status());
    }

    /**
     * A FOREIGN PRODUCT SHARING THE CALLER'S CATEGORY IS NOT SWEPT UP.
     *
     * Raised by review as LATENT, and tested as latent rather than claimed as exploitable: the
     * `product_details` relation filters by `category_id` only, so after the ownership guard the
     * category is the caller's but the products are not necessarily. There is no shipped write
     * path that plants such a row today -- the one that could re-tenant a product,
     * `WoocomProductController::edit`, is an open task (#30) -- so this fixture writes the row
     * directly, which is the honest way to test a latent exposure: it measures the predicate,
     * not a reachable attack.
     */
    public function test_a_foreign_product_in_the_callers_category_keeps_its_media(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Product Category');

        $own = $this->categoryIn($storeA, 'shared-category');

        // The caller's own product in their own category: must still be cleaned up.
        $mine = $this->productWithEverything($storeA, $own, 'mineinshared');

        // A store-B product pointed at store A's category. Anomalous by construction.
        $foreign = $this->productWithEverything($storeB, $own, 'foreigninshared');
        \Illuminate\Support\Facades\DB::table('products')
            ->where('id', $foreign['product']->id)
            ->update(['category_id' => $own->id, 'store_id' => $storeB->id]);

        $response = $this->actingAs($merchantA)->delete('/category/' . $own->id);

        $this->assertSame(302, $response->status());

        // The premise: the delete really ran, so "the foreign row survived" is not just a refusal.
        $this->assertNull(Category::withoutGlobalScopes()->find($own->id),
            'The category was not deleted, so this test shows a refusal rather than a narrowed '
            . 'loop. Session error: ' . json_encode(session('error')));
        $this->assertNull(DB::table('product_variants')->where('id', $mine['variant_id'])->first(),
            'The caller\'s own product was not cleaned up, so the loop did not run at all.');
        foreach ($mine['files'] as $path) {
            $this->assertFileDoesNotExist($path,
                'The caller\'s own media was not unlinked, so the loop did not reach the '
                . 'filesystem and the assertion below measures nothing.');
        }

        // WHAT THE PREDICATE BUYS: the foreign product's FILES are still on disk. That is the
        // irreversible half, and it is the half the loop controls.
        foreach ($foreign['files'] as $path) {
            $this->assertFileExists($path,
                'Another store\'s product media was unlinked by a legitimate own-category '
                . 'delete: ' . $path);
        }

        /*
         * AND WHAT IT CANNOT BUY, PINNED HERE SO IT IS NOT MISTAKEN FOR SAFETY.
         *
         * `products.category_id` is declared `ON DELETE CASCADE` against `categories.id`
         * (`database/migrations/..._create_products_table.php:55`), and
         * `product_variants.product_id` cascades again off `products`. So `$category->delete()`
         * removes EVERY product row in the category and every variant under it, whatever the
         * store, below the application entirely — no predicate in this controller can reach it.
         *
         * Verified on the real engine, not inferred from SQLite:
         * tests/Support/Security/bin/verify-category-product-cascade-on-mysql.php — 3 checks, 0
         * failures on MySQL 8.0.46, including `information_schema.REFERENTIAL_CONSTRAINTS`
         * reporting DELETE_RULE = CASCADE.
         *
         * These two assertions therefore pin a KNOWN-BAD behaviour rather than a control, which
         * is why they are labelled: if a later change makes the cascade stop firing, this test
         * fails and the next reader is sent here instead of discovering it in production. The
         * cascade is raised as its own task — it also means an earlier draft of S38-1 and of #49
         * was WRONG to say "the products rows themselves survive"; they do not.
         */
        $this->assertNull(
            Product::withoutGlobalScopes()->find($foreign['product']->id),
            'The FK cascade no longer removes a foreign product when its category is deleted. '
            . 'That is an IMPROVEMENT, not a regression — but it changes a documented limit, so '
            . 'update this test, the S38-1 queue entry and the cascade task before going green.');
        $this->assertNull(
            DB::table('product_variants')->where('id', $foreign['variant_id'])->first(),
            'The product_variants cascade off products no longer fires. Same note as above.');
    }
}
