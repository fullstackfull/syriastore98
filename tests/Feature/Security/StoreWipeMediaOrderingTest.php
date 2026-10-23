<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/**
 * P1-INV-25 — the store-wide wipes delete categories BEFORE the product loop that frees the media.
 *
 * WHERE THIS CAME FROM. The independent authorization review of the P1-INV-12 (D13) change, in the
 * call sites D13 deliberately EXCLUDED from its refusal. That exclusion stands: deleting a whole
 * store is meant to take its catalogue with it, and both live paths are authorized. What the review
 * found is an ORDERING defect inside them, which is a different question from whether they should
 * delete at all.
 *
 * THE MECHANISM, and it is entirely a consequence of the FK D13 was built around.
 * `products.category_id` is `ON DELETE CASCADE` against `categories.id`
 * (2023_12_26_092822_create_products_table.php:55). Every wipe runs, in this order:
 *
 *     Category::where('store_id', $store->id)->delete();      // <-- the cascade removes products
 *     ...
 *     $products = Product::where('store_id', $store->id)->get();   // <-- now EMPTY
 *     foreach ($products as $p) { $productController->destroy($p); }   // <-- never runs
 *
 * So `ProductController::destroy()` — the only thing that unlinks the image files and corrects the
 * merchant's used-storage figure — is never reached. The rows go, the FILES STAY.
 *
 * AND `users.storage_limit` IS USED STORAGE, NOT THE ALLOWANCE, despite the name. It is
 * INCREMENTED on upload and compared against `plans.storage_limit` as the ceiling
 * (`Utility.php:786-787`), DECREMENTED on delete (`:812`, and `changeproductStorageLimit` at
 * `:873`), and rendered as a percentage of the plan (`HomeController:273`). So a merchant who
 * deletes a store keeps being charged, for ever, for storage whose rows no longer exist — and can
 * be refused new uploads for space they are not using. That is the half of this defect that costs
 * the merchant money rather than disk.
 *
 * THE CENSUS CORRECTION THIS FILE CARRIES. The task row named three call sites. **Two are routed
 * and one is dead code**:
 *
 *   - `Api\DashboardController::DeleteStore` (:4223) — LIVE, `DELETE api/admin/deletestore`
 *     (routes/api.php:330), which is what the shipped admin Flutter app calls;
 *   - `StoreController::ownerstoredestroy` (:780) — LIVE, `DELETE ownerstore-remove/{id}`
 *     (routes/web.php:928);
 *   - `Api\DashboardController::DeleteStore_backup` (:4354) — **no route**. Left alone
 *     deliberately: "fixing" dead code in parallel creates a second path to keep in step, which is
 *     the mistake the Paymera docblock already records for its own two unrouted copies.
 *
 * WHAT THESE TESTS ASSERT. Acceptance criterion 1 says the defect is MEASURED before anything is
 * changed, so the first two tests were written and run against the unfixed code and recorded what
 * it did: the files survived and the used-storage figure did not move. They now assert the fixed
 * behaviour, and reverting the reorder fails them.
 */
class StoreWipeMediaOrderingTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var list<string> paths this test created, cleaned up whether or not the handler removed them */
    private array $createdPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        // The positive controls deliberately leave another tenant's files in place, so the test
        // itself has to clean up or the uploads directory grows with every run.
        foreach ($this->createdPaths as $path) {
            if (File::exists(base_path($path))) {
                File::delete(base_path($path));
            }
        }
        $this->createdPaths = [];

        parent::tearDown();
    }

    /**
     * A merchant with TWO stores, because every live wipe refuses the last one.
     *
     * @return array{0: User, 1: Store, 2: Store}
     */
    private function merchantWithTwoStores(): array
    {
        [$merchant, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($merchant);

        $planId = DB::table('plans')->insertGetId([
            'name' => 'wipe-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => 1000, 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->storage_limit = 0;
        $merchant->save();

        return [$merchant, $storeA, $storeB];
    }

    /**
     * A product in a category, with REAL files on disk under the tenant's uploads directory.
     *
     * The files have to be real: the whole defect is about whether they are unlinked, and
     * `changeproductStorageLimit()` sizes them with `File::size()`, so a path pointing at nothing
     * would make the storage assertion pass for the wrong reason.
     *
     * @return array{0: Product, 1: string, 2: string}  product, cover path, gallery path
     */
    private function productWithMediaIn(Store $store, User $merchant): array
    {
        // NO `created_by` HERE, and that is not an omission: `categories` has no such column
        // (2023_12_25_093024_create_categories_table.php lists only name, slug, the three image
        // paths, parent_id, trending, status, store_id and timestamps). The model's $fillable
        // lies about it, which is the trap CLAUDE.md records — the first run of this fixture
        // failed with "table categories has no column named created_by".
        $category = Category::forceCreate([
            'name' => 'wipe-cat-' . uniqid(), 'slug' => 'wipe-cat-' . uniqid(),
            'status' => 1, 'store_id' => $store->id,
        ]);

        /*
         * `uploads/<STORE id>/...`, not `uploads/<user id>`, and the first version of this fixture
         * had it wrong — StoredPathPolicy refused the path with "The file path is not accepted."
         * The two services agree and it is worth stating: the folder segment is a STORE id, while
         * the `$ownerId` argument is the tenant owner's USER id, because
         * StoredUpload::mayTouchStoreFolder() resolves the pair with
         * `Store::where('id', $storeId)->where('created_by', $ownerId)`. Getting it wrong here
         * would have made the unlink a no-op for a reason that has nothing to do with this defect.
         */
        $dir = 'uploads/' . $store->id;
        File::ensureDirectoryExists(base_path($dir));

        $cover = $dir . '/wipe-cover-' . uniqid() . '.txt';
        $gallery = $dir . '/wipe-gallery-' . uniqid() . '.txt';
        /*
         * EXACT MEBIBYTES, not a few kilobytes, and that is what makes the idempotence test
         * readable: `changeproductStorageLimit()` records `number_format($bytes / 1048576, 2)`,
         * so 2 KiB + 4 KiB rounds to "0.01" and a DOUBLE credit would read "0.00" -- the two
         * outcomes collapse at that scale. At 1 MiB + 2 MiB the figure is 3.00, one pass leaves
         * 0.00 and a double pass would leave -3.00.
         */
        File::put(base_path($cover), str_repeat('c', 1048576));
        File::put(base_path($gallery), str_repeat('g', 2 * 1048576));

        $product = Product::forceCreate([
            'name' => 'wipe-prod-' . uniqid(), 'slug' => 'wipe-prod-' . uniqid(),
            'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'status' => 1,
            'category_id' => $category->id,
            'cover_image_path' => $cover,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        DB::table('product_images')->insert([
            'product_id' => $product->id, 'image_path' => $gallery,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The used-storage figure as it would stand after those two uploads: exactly 3.00 MB.
        $merchant->storage_limit = (float) ($merchant->storage_limit + 3.00);
        $merchant->save();

        $this->createdPaths[] = $cover;
        $this->createdPaths[] = $gallery;

        return [$product, $cover, $gallery];
    }

    /**
     * THE PREMISE, asserted rather than assumed: the cascade really does remove the products.
     *
     * Without this, every assertion below could be passing because the wipe simply did nothing.
     * It also pins the fact the whole defect rests on — deleting a category takes its products
     * with it — which is the same FK D13 was built around, so if that constraint is ever changed
     * this test says so first.
     */
    public function test_premise_deleting_a_category_cascades_its_products_away(): void
    {
        [$merchant, $storeA] = $this->merchantWithTwoStores();
        [$product] = $this->productWithMediaIn($storeA, $merchant);

        /*
         * ASSERTED THROUGH `DB::table()`, AND THE FIRST VERSION OF THIS TEST DID NOT BE.
         * `Product` is `Cachable`, so `Product::withoutGlobalScopes()->find($id)` served the
         * PRE-DELETE row and this premise FAILED against a cascade that had in fact fired. That
         * would have mis-diagnosed the whole defect -- it read as "the cascade does not happen",
         * which is the opposite of the truth. A raw probe on the same fixture showed the row
         * really was gone. This is failure mode 4 in the project skill, and CLAUDE.md states the
         * rule outright: assert through DB::table().
         */
        $this->assertTrue(DB::table('products')->where('id', $product->id)->exists(),
            'The fixture did not create a product, so nothing below measures anything.');

        DB::table('categories')->where('store_id', $storeA->id)->delete();

        $this->assertFalse(DB::table('products')->where('id', $product->id)->exists(),
            'Deleting the category did NOT remove the product, so `products.category_id` is no '
            . 'longer ON DELETE CASCADE in this engine. That changes P1-INV-25 entirely — and it '
            . 'changes P1-INV-12 (D13) too, whose refusals exist because of this cascade.');
    }

    /**
     * THE ADMIN API WIPE FREES THE MEDIA AND CORRECTS THE USED-STORAGE FIGURE.
     *
     * `DELETE api/admin/deletestore` is what the shipped admin app calls.
     *
     * MEASURED AGAINST THE UNFIXED CODE FIRST, as acceptance criterion 1 requires: both files
     * survived the wipe and `users.storage_limit` did not move, because the cascade had already
     * emptied the collection the destroy loop iterates.
     */
    public function test_the_admin_api_store_wipe_unlinks_product_media(): void
    {
        [$merchant, $storeA, $storeB] = $this->merchantWithTwoStores();
        [$product, $cover, $gallery] = $this->productWithMediaIn($storeA, $merchant);

        $usedBefore = (float) DB::table('users')->where('id', $merchant->id)->value('storage_limit');
        $this->assertGreaterThan(0, $usedBefore,
            'The fixture recorded no used storage, so the credit assertion below would hold '
            . 'whatever the handler did.');

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deletestore', ['store_id' => $storeA->id]);

        // Premise: the wipe ran. ApiResponser answers HTTP 200 with status 0 on refusal, so the
        // status code alone proves nothing — the store row is the evidence.
        $this->assertFalse(DB::table('stores')->where('id', $storeA->id)->exists(),
            'The store was not deleted, so this test measures a refusal rather than the wipe. '
            . 'Response: ' . $response->getContent());
        $this->assertTrue(DB::table('stores')->where('id', $storeB->id)->exists(),
            'The wipe removed the OTHER store too.');

        $this->assertFileDoesNotExist(base_path($cover),
            "The product's cover image survived the store wipe. The category delete cascades the "
            . 'products away before the destroy loop reads them, so the loop iterates an empty '
            . 'collection and nothing is ever unlinked.');
        $this->assertFileDoesNotExist(base_path($gallery),
            'The gallery image survived the store wipe, for the same reason.');

        $this->assertFalse(DB::table('product_images')->where('product_id', $product->id)->exists(),
            'The product_images ROWS survived the wipe. `product_images` declares no foreign key '
            . 'to `products`, so nothing cascades them away — the reclaimer has to delete them '
            . 'explicitly or they are left orphaned pointing at files that are gone.');

        $this->assertLessThan($usedBefore, (float) DB::table('users')->where('id', $merchant->id)->value('storage_limit'),
            'The merchant is still being charged for the deleted store\'s storage. '
            . '`users.storage_limit` is USED storage, compared against `plans.storage_limit` as '
            . 'the ceiling, so this merchant can be refused uploads for space they no longer use. '
            . 'Before: ' . $usedBefore . ' after: ' . $merchant->fresh()->storage_limit);
    }

    /**
     * AND THE WEB WIPE DOES THE SAME, because it is the same code shape one surface over.
     *
     * `DELETE ownerstore-remove/{id}`. Driven separately rather than folded into a data provider:
     * the two handlers take their store from different places (a body key versus a route
     * parameter) and authorize differently, so one provider would hide which of them was tested.
     */
    public function test_the_web_store_wipe_unlinks_product_media(): void
    {
        [$merchant, $storeA, $storeB] = $this->merchantWithTwoStores();
        [, $cover, $gallery] = $this->productWithMediaIn($storeA, $merchant);

        $usedBefore = (float) DB::table('users')->where('id', $merchant->id)->value('storage_limit');

        $merchant->current_store = $storeA->id;
        $merchant->save();

        $response = $this->actingAs($merchant)
            ->delete('/ownerstore-remove/' . $storeA->id);

        $this->assertFalse(DB::table('stores')->where('id', $storeA->id)->exists(),
            'The web wipe did not delete the store, so this test measures a refusal. '
            . 'Answered HTTP ' . $response->getStatusCode()
            . ' error=' . var_export(session('error'), true));
        $this->assertTrue(DB::table('stores')->where('id', $storeB->id)->exists(),
            'The web wipe removed the OTHER store too.');

        $this->assertFileDoesNotExist(base_path($cover),
            "The product's cover image survived the web store wipe.");
        $this->assertFileDoesNotExist(base_path($gallery),
            'The gallery image survived the web store wipe.');

        $this->assertLessThan($usedBefore, (float) DB::table('users')->where('id', $merchant->id)->value('storage_limit'),
            'The web wipe left the merchant charged for the deleted store\'s storage.');
    }

    /**
     * THE STORAGE IS CREDITED ONCE, ON THE PATH PRODUCTION ACTUALLY TAKES — criterion 4.
     *
     * THIS REPLACES A TEST THAT COULD NOT BITE, and the replacement is the point. The first
     * version drove the wipe twice and asserted the figure did not move on the second call — but
     * the store is gone after the first wipe, so the second request is REFUSED before it reaches
     * anything. It asserted "the row did not change" after an endpoint that fails for everyone,
     * which is failure mode 1 in the project skill. It would have passed against the unfixed code.
     *
     * AND THE REAL RISK WAS SOMEWHERE ELSE ENTIRELY. A wipe now makes TWO passes over the same
     * product: `reclaimStore()`, and then the kept `destroy()` loop. The loop only runs if the
     * caller holds `Delete Products` — and every other test in this file uses `makeMerchant()`,
     * which assigns no role, so in all of them `destroy()` refuses silently and the second pass
     * NEVER HAPPENS. Production merchants are given the global `admin` role at registration
     * (RegisteredUserController:154, :182), so production takes the two-pass path that nothing
     * here was driving. That is the gap this test closes.
     *
     * The assertion is exact rather than directional: 3.00 MB of media must leave the figure at
     * 0.00, not -3.00.
     */
    public function test_the_storage_is_credited_once_even_though_the_wipe_makes_two_passes(): void
    {
        [$merchant, $storeA] = $this->merchantWithTwoStores();
        $this->productWithMediaIn($storeA, $merchant);

        // The production shape: the merchant CAN delete products, so the destroy() loop really
        // runs and the reclaimer is reached a second time for the same product.
        $this->grantPermission($merchant, 'Delete Products');
        $merchant->current_store = $storeA->id;
        $merchant->save();

        $usedBefore = (float) DB::table('users')->where('id', $merchant->id)->value('storage_limit');
        $this->assertSame(3.0, round($usedBefore, 2),
            'The fixture did not record 3.00 MB of used storage, so the exact arithmetic below '
            . 'means nothing. Found: ' . $usedBefore);

        $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deletestore', ['store_id' => $storeA->id]);

        $this->assertFalse(DB::table('stores')->where('id', $storeA->id)->exists(),
            'The wipe did not run, so this measures a refusal and not the two-pass path.');

        $after = round((float) DB::table('users')->where('id', $merchant->id)->value('storage_limit'), 2);

        $this->assertSame(0.0, $after,
            'The used-storage figure is not exactly one product\'s worth lower. 3.00 MB was '
            . 'recorded and 0.00 is correct; -3.00 means the two passes BOTH credited it and the '
            . 'merchant is now owed storage they never used. Found: ' . $after);
    }

    /**
     * AND IT ACCUMULATES CORRECTLY ACROSS THE LOOP — two products, both credited, once each.
     *
     * Every other fixture here puts ONE product in the wiped store, so nothing measured the loop
     * running more than once. That matters specifically because
     * `Utility::changeproductStorageLimit()` does `User::find()` → mutate → `save()` once PER
     * PRODUCT on a `Cachable` model, which is the read-modify-write shape CLAUDE.md records two
     * measured false readings for. The Eloquent save flushes, so it should be correct — "should
     * be" is what this test replaces.
     */
    public function test_the_storage_correction_accumulates_across_every_product_in_the_store(): void
    {
        [$merchant, $storeA] = $this->merchantWithTwoStores();
        $this->productWithMediaIn($storeA, $merchant);
        $this->productWithMediaIn($storeA, $merchant);

        $usedBefore = round((float) DB::table('users')->where('id', $merchant->id)->value('storage_limit'), 2);
        $this->assertSame(6.0, $usedBefore,
            'Two products should have recorded 6.00 MB. Found: ' . $usedBefore);

        $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deletestore', ['store_id' => $storeA->id]);

        $this->assertFalse(DB::table('stores')->where('id', $storeA->id)->exists(),
            'The wipe did not run.');

        $after = round((float) DB::table('users')->where('id', $merchant->id)->value('storage_limit'), 2);
        $this->assertSame(0.0, $after,
            'The loop credited only one of the two products, or the per-product save lost an '
            . 'update. 6.00 MB was recorded; 3.00 means one product was missed. Found: ' . $after);
    }

    /**
     * THE HTTP PRODUCT DELETE STILL FREES ITS MEDIA — the path the extraction moved.
     *
     * A TEST GAP THIS PACKAGE FOUND AND CLOSED, not a new control, and it is labelled so: it
     * passes against the pre-change code too, because it covers the inline block the extraction
     * replaced. A mutation run deleting that call outright SURVIVED before this existed. Do not
     * count it as evidence FOR the change — count it as the regression net under the refactor.
     *
     * The merchant is granted `Delete Products` deliberately: that is the gate this handler keeps,
     * and the whole point of the package is that the WIPES must not depend on it while this
     * handler still does.
     */
    public function test_the_http_product_delete_still_frees_its_media(): void
    {
        [$merchant, $storeA] = $this->merchantWithTwoStores();
        [$product, $cover, $gallery] = $this->productWithMediaIn($storeA, $merchant);

        $this->grantPermission($merchant, 'Delete Products');
        $merchant->current_store = $storeA->id;
        $merchant->save();

        $usedBefore = (float) DB::table('users')->where('id', $merchant->id)->value('storage_limit');

        $response = $this->actingAs($merchant)->delete('/product/' . $product->id);

        $this->assertFalse(DB::table('products')->where('id', $product->id)->exists(),
            'The product was not deleted, so this test measures a refusal rather than the delete. '
            . 'Answered HTTP ' . $response->getStatusCode()
            . ' error=' . var_export(session('error'), true));

        $this->assertFileDoesNotExist(base_path($cover),
            "The HTTP product delete left the product's cover image on disk.");
        $this->assertFileDoesNotExist(base_path($gallery),
            'The HTTP product delete left the gallery image on disk.');
        $this->assertLessThan($usedBefore, (float) DB::table('users')->where('id', $merchant->id)->value('storage_limit'),
            'The HTTP product delete did not correct the used-storage figure.');
    }

    /**
     * THE RECLAIMER CANNOT TOUCH ANOTHER TENANT'S FILE *OR* ITS ROWS.
     *
     * WRITTEN TO PIN A PIECE OF REASONING, AND IT REFUTED IT. The first version of the service
     * carried no ownership check, on the argument that `StoredUpload`'s rule 3 refuses a path in
     * another tenant's store folder anyway. This test showed rule 3 guards the FILE and not the
     * ROW: asked directly with a foreign product, the service deleted another tenant's
     * `product_images` rows. Hence `mayReclaim()`, and hence the second assertion here.
     */
    public function test_the_reclaimer_cannot_touch_another_tenants_file(): void
    {
        [$merchant] = $this->merchantWithTwoStores();
        [$other, $otherStore] = $this->makeTenant();
        [$otherProduct, $otherCover] = $this->productWithMediaIn($otherStore, $other);

        $otherUsedBefore = (float) DB::table('users')->where('id', $other->id)->value('storage_limit');

        // Asked directly, with the WRONG owner: merchant A's id against store B's product.
        \App\Services\ProductMediaReclaimer::reclaim($otherProduct, $merchant->creatorId());

        $this->assertFileExists(base_path($otherCover),
            "The reclaimer unlinked a file in another tenant's store folder.");
        $this->assertSame($otherUsedBefore, (float) DB::table('users')->where('id', $other->id)->value('storage_limit'),
            "Another tenant's used-storage figure moved.");
        $this->assertTrue(DB::table('product_images')->where('product_id', $otherProduct->id)->exists(),
            "Another tenant's product_images ROWS were deleted. StoredUpload guards the file and "
            . 'not the row, which is why the service needs its own ownership guard — if this '
            . 'fails, that guard is gone.');
    }

    /**
     * WIPING ONE STORE MUST NOT TAKE THE MERCHANT'S OTHER STORE'S MEDIA.
     *
     * THE TEST THAT MADE A TWICE-SURVIVING MUTANT BITE, and the two survivals had different
     * causes. Removing `reclaimStore()`'s `where('store_id', ...)` predicate failed no test. The
     * first time that was a GAP (nothing asserted the `product_images` rows). After the ownership
     * guard it survived AGAIN, genuinely equivalent for a different TENANT — but NOT for the same
     * merchant's other store: `mayReclaim()` admits any store with this `created_by`, correctly,
     * so a predicate-less `reclaimStore()` wiping store A would unlink store B's images and charge
     * the correction against them. No ownership check can catch that; the store predicate is the
     * only thing that can. The fixture's second store had no products, which is why it went
     * unseen for two rounds.
     */
    public function test_wiping_one_store_leaves_the_merchants_other_stores_media_alone(): void
    {
        [$merchant, $storeA, $storeB] = $this->merchantWithTwoStores();
        $this->productWithMediaIn($storeA, $merchant);
        [$keptProduct, $keptCover, $keptGallery] = $this->productWithMediaIn($storeB, $merchant);

        $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deletestore', ['store_id' => $storeA->id]);

        $this->assertFalse(DB::table('stores')->where('id', $storeA->id)->exists(),
            'The wipe did not run, so the assertions below prove nothing.');

        $this->assertFileExists(base_path($keptCover),
            "Wiping store A unlinked store B's cover image. Both stores belong to the SAME "
            . 'merchant, so the ownership guard cannot catch this — only the store predicate in '
            . 'reclaimStore() can.');
        $this->assertFileExists(base_path($keptGallery),
            "Wiping store A unlinked store B's gallery image.");
        $this->assertTrue(DB::table('product_images')->where('product_id', $keptProduct->id)->exists(),
            "Wiping store A deleted store B's product_images rows.");
        $this->assertTrue(DB::table('products')->where('id', $keptProduct->id)->exists(),
            "Wiping store A deleted store B's product.");
    }

    /**
     * AND IT HOLDS WITH THE MODEL CACHE WARM — acceptance criterion 1 asks for both states.
     *
     * The row flagged that `Product` is `Cachable` and a raw FK cascade flushes nothing, so the
     * old code's `get()` could serve a PRE-cascade cached collection — which would make the
     * defect's severity depend on cache state rather than on the code. The fix makes the question
     * moot, because the reclaim now runs before anything is deleted, and this pins that.
     */
    public function test_the_wipe_frees_the_media_with_the_model_cache_already_warm(): void
    {
        [$merchant, $storeA] = $this->merchantWithTwoStores();
        [, $cover, $gallery] = $this->productWithMediaIn($storeA, $merchant);

        $primed = Product::where('store_id', $storeA->id)->get();
        $this->assertCount(1, $primed,
            'The priming query returned nothing, so the cache is not warm and this is a duplicate '
            . 'of the cold-cache test.');

        $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deletestore', ['store_id' => $storeA->id]);

        $this->assertFalse(DB::table('stores')->where('id', $storeA->id)->exists(),
            'The wipe did not run.');
        $this->assertFileDoesNotExist(base_path($cover),
            'With the model cache warm, the cover image survived the wipe.');
        $this->assertFileDoesNotExist(base_path($gallery),
            'With the model cache warm, the gallery image survived the wipe.');
    }

    /**
     * LIMB 2, PINNED AS KNOWN-BAD AND ACCEPTED — acceptance criterion 3.
     *
     * THE ASYMMETRY: the wipes select CATEGORIES by `store_id`, but the constraint fires on
     * `category_id`. A `products` row carrying store B's `store_id` while pointing at a category
     * of store A is destroyed when A is wiped — the same "the FOREIGN KEY does not read store_id"
     * asymmetry P1-INV-12's guards were widened for.
     *
     * THE DECISION: ACCEPTED, NOT NARROWED. Reaching it needs a re-tenanting bug (the open
     * P1-INV-04) or a hand-written row, and narrowing means WRITING to another tenant's rows —
     * nulling their `category_id` — to protect against a latent cross-tenant read. The media of
     * such a row is NOT leaked, which this test also asserts, because that half would be a NEW
     * defect rather than an inherited one.
     */
    public function test_limb_two_known_bad_a_foreign_product_pointing_at_this_stores_category_is_still_destroyed(): void
    {
        [$merchantA, $storeA] = $this->merchantWithTwoStores();
        [$merchantB, $storeB] = $this->makeTenant();

        $categoryOfA = Category::forceCreate([
            'name' => 'limb2-cat-' . uniqid(), 'slug' => 'limb2-cat-' . uniqid(),
            'status' => 1, 'store_id' => $storeA->id,
        ]);

        $dir = 'uploads/' . $storeB->id;
        File::ensureDirectoryExists(base_path($dir));
        $foreignCover = $dir . '/limb2-' . uniqid() . '.txt';
        File::put(base_path($foreignCover), str_repeat('f', 1024));
        $this->createdPaths[] = $foreignCover;

        $foreign = Product::forceCreate([
            'name' => 'limb2-prod-' . uniqid(), 'slug' => 'limb2-prod-' . uniqid(),
            'product_stock' => 1, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 1, 'sale_price' => 1, 'status' => 1,
            'category_id' => $categoryOfA->id,
            'cover_image_path' => $foreignCover,
            'store_id' => $storeB->id, 'created_by' => $merchantB->id,
        ]);

        $this->withHeader(
            'Authorization',
            'Bearer ' . $merchantA->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deletestore', ['store_id' => $storeA->id]);

        $this->assertFalse(DB::table('stores')->where('id', $storeA->id)->exists(),
            'The wipe did not run, so the pin below records nothing.');

        $this->assertFalse(DB::table('products')->where('id', $foreign->id)->exists(),
            'KNOWN-BAD PIN, and it now reads the other way: a product belonging to another store '
            . 'SURVIVED the wipe of the store whose category it pointed at. That is an improvement '
            . '— update this test and P1-INV-25 acceptance criterion 3, which recorded the '
            . 'asymmetry as ACCEPTED on the grounds that reaching it needs P1-INV-04.');

        $this->assertFileExists(base_path($foreignCover),
            "The wipe unlinked another store's product media. reclaimStore() reads products by "
            . 'store_id precisely so a foreign row is never touched — if this fails, the change '
            . 'has introduced a NEW cross-tenant media deletion rather than inherited the row '
            . 'cascade.');
    }

    /**
     * THE PLATFORM OPERATOR CAN STILL RECLAIM, which is why the operator limb comes FIRST.
     *
     * `creatorId()` returns the operator's own id and that id owns no merchant store, so an
     * ownership-first guard would refuse the platform operator on EVERY product — a lockout, not a
     * tightening. It would also contradict `ProductController::destroy()`, which exempts
     * `UserPolicy::PLATFORM_ADMIN`, and `StoredUpload::mayTouchStoreFolder()`, which exempts a
     * `super admin` from rule 3. Without this test, reversing the limbs passes the suite while
     * silently breaking the operator.
     */
    public function test_the_platform_operator_can_still_reclaim_any_stores_media(): void
    {
        [$merchant, $storeA] = $this->merchantWithTwoStores();
        [$product, $cover] = $this->productWithMediaIn($storeA, $merchant);

        $operator = $this->makePlatformAdmin();

        $this->assertNotSame((int) $operator->id, (int) $storeA->created_by,
            'The operator owns this store in the fixture, so the exemption below would not be '
            . 'what makes the reclaim succeed.');

        \App\Services\ProductMediaReclaimer::reclaim($product, $operator->creatorId());

        $this->assertFileDoesNotExist(base_path($cover),
            'The platform operator was refused by the ownership guard. The operator limb must '
            . 'come FIRST: creatorId() owns no merchant store, so ownership-first refuses them on '
            . 'every product.');
    }

    /**
     * THE STORAGE CORRECTION IS CHARGED TO THE STORE'S OWNER, NOT TO WHOEVER RAN THE WIPE.
     *
     * A DEFECT THE REVIEW OF THIS PACKAGE FOUND IN MY OWN FIX. Both wipes first passed
     * `$user->creatorId()`. `StoreController::resolveOwnedStore()` drops its `created_by`
     * predicate for a `super admin` (:681-683), so the platform operator can wipe ANY merchant's
     * store — and `Utility::changeproductStorageLimit()` debits `User::find($company_id)`, i.e.
     * whichever id it is handed. So the operator's own figure went down while the merchant stayed
     * charged for ever: exactly the harm this task exists to remove, reintroduced on the operator
     * path.
     *
     * CONSTRUCTING IT TAKES ONE UNUSUAL DATA STATE, which is why it was latent rather than live:
     * the web wipe's last-store count asks about the CALLER's own stores, and no store writer ever
     * sets `created_by` to a super admin, so an operator normally owns 0 stores and is refused
     * before reaching the reclaim. This test gives the operator two stores of their own — so the
     * count passes — and then has them wipe a store owned by a MERCHANT, which is the only shape
     * where the caller's id and the store's owner differ.
     *
     * Without this test the fix is unfalsifiable: for an ordinary merchant caller
     * `creatorId() === $store->created_by`, so the two forms are identical on every reachable
     * path and the mutant would survive as equivalent.
     */
    public function test_the_operator_wiping_a_merchants_store_charges_the_merchant_not_the_operator(): void
    {
        [$merchant, $merchantStore] = $this->makeTenant();
        $this->productWithMediaIn($merchantStore, $merchant);

        $operator = $this->makePlatformAdmin();

        // Two stores of the operator's own, so ownerstoredestroy's last-store count admits them.
        $this->makeStore($operator);
        $this->makeStore($operator);

        $merchantUsedBefore = round((float) DB::table('users')->where('id', $merchant->id)->value('storage_limit'), 2);
        $operatorUsedBefore = round((float) DB::table('users')->where('id', $operator->id)->value('storage_limit'), 2);

        $this->assertSame(3.0, $merchantUsedBefore,
            'The merchant does not hold the 3.00 MB this test is about. Found: ' . $merchantUsedBefore);

        $operator->current_store = $merchantStore->id;
        $operator->save();

        $response = $this->actingAs($operator)->delete('/ownerstore-remove/' . $merchantStore->id);

        $this->assertFalse(DB::table('stores')->where('id', $merchantStore->id)->exists(),
            'The operator did not reach the wipe, so this test measures a refusal and the '
            . 'accounting assertions below prove nothing. Answered HTTP '
            . $response->getStatusCode() . ' error=' . var_export(session('error'), true));

        $this->assertSame(0.0, round((float) DB::table('users')->where('id', $merchant->id)->value('storage_limit'), 2),
            "The MERCHANT is still charged for the storage of the store that was just deleted. "
            . 'The correction must be charged to the store\'s owner, not to whoever ran the wipe.');

        $this->assertSame($operatorUsedBefore, round((float) DB::table('users')->where('id', $operator->id)->value('storage_limit'), 2),
            "The OPERATOR's own used-storage figure was debited for a merchant's files. "
            . '`changeproductStorageLimit()` debits whichever id it is handed, so passing the '
            . "caller's id instead of \$store->created_by charges the wrong user — and can take "
            . 'the operator negative.');
    }

    /**
     * THE POSITIVE CONTROL: another merchant's store and media are untouched.
     *
     * Not optional. Every assertion above is satisfied by a wipe that deletes too much, and the
     * reorder moves a `Product::where('store_id', ...)` query to a new place in the method — which
     * is exactly the kind of move that loses a predicate.
     */
    public function test_the_wipe_does_not_touch_another_merchants_store_or_media(): void
    {
        [$merchant, $storeA] = $this->merchantWithTwoStores();
        $this->productWithMediaIn($storeA, $merchant);

        [$other, $otherStore] = $this->makeTenant();
        [$otherProduct, $otherCover, $otherGallery] = $this->productWithMediaIn($otherStore, $other);
        $otherUsedBefore = (float) DB::table('users')->where('id', $other->id)->value('storage_limit');

        $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deletestore', ['store_id' => $storeA->id]);

        $this->assertFalse(DB::table('stores')->where('id', $storeA->id)->exists(),
            'The wipe did not run, so the untouched assertions below prove nothing.');

        $this->assertTrue(DB::table('stores')->where('id', $otherStore->id)->exists(),
            "Another merchant's store was deleted by this wipe.");
        $this->assertTrue(DB::table('products')->where('id', $otherProduct->id)->exists(),
            "Another merchant's product was deleted by this wipe.");
        $this->assertFileExists(base_path($otherCover),
            "Another merchant's cover image was unlinked by this wipe.");
        $this->assertFileExists(base_path($otherGallery),
            "Another merchant's gallery image was unlinked by this wipe.");
        $this->assertSame($otherUsedBefore, (float) DB::table('users')->where('id', $other->id)->value('storage_limit'),
            "Another merchant's used-storage figure was changed by this wipe.");
    }
}
