<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * D-26 — one image per product variant, and every condition the owner attached to it.
 * ===================================================================================
 *
 * The approval is short and almost all of it is negative: bind the image to the correct variant
 * and the correct store; do not lose the variant's price, stock or SKU; do not change the
 * variant's identity; prevent access to another store's media. Each of those is a test here,
 * because each names a way the obvious implementation goes wrong.
 *
 * THE TENANCY ONE IS NOT THEORETICAL. `ProductVariant` registers no `StoreScope` and is not in
 * `config/tenancy.php`'s `scoped_models`, so `ProductVariant::find($id)` reaches every tenant's
 * variants and nothing narrows it — the same shape as S19's measured cross-tenant stock drain.
 * A handler that trusted the posted `variant_id`, or that scoped by a posted `product_id`, would
 * be writing media into another merchant's catalogue.
 */
class VariantImageOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A FILENAME NO PRODUCT CONTENT WOULD EVER CARRY, so the cleanup below can be exact.
     *
     * The first version of this test uploaded `swatch.png`, which is also what the live browser
     * probe uses, and deleting `*_swatch.png` would have been a guess about which files were
     * whose.
     */
    private const FIXTURE_FILENAME = 'ss-phpunit-variant-fixture.png';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `Storage::fake('public')` DOES NOT CONTAIN THIS TEST, and believing it did is what put
         * a generated PNG into a commit.
         *
         * `ProductController::variant_image_store()` uploads through `Utility::upload_file()`,
         * which writes to the application's own disk at `uploads/<store_id>/` — not through the
         * `public` disk this fakes. So every run of this file wrote TWO real files into the
         * repository, one per tenant it creates. `/uploads/1/` is gitignored (it is the dev
         * fixture's store), which hid the first; the second landed in `uploads/2/`, which is NOT
         * ignored because other store ids hold tracked product content — and a `git add -A` swept
         * it into `8c462acd`.
         *
         * The fake is kept because it does contain anything that genuinely uses the `public`
         * disk, but it is not the control: `tearDown()` is.
         */
        Storage::fake('public');
    }

    /**
     * REMOVE WHAT THIS TEST CAUSED TO BE WRITTEN, and prove it was removed.
     *
     * Scoped by the fixture filename, so it cannot touch a file this test did not create. The
     * assertion matters as much as the unlink: if the upload destination ever moves, this fails
     * instead of silently leaving artefacts in the working tree for the next `git add -A`.
     */
    protected function tearDown(): void
    {
        $root = base_path('uploads');
        $left = [];

        if (is_dir($root)) {
            foreach (glob($root . '/*/*' . self::FIXTURE_FILENAME) ?: [] as $file) {
                @unlink($file);

                if (file_exists($file)) {
                    $left[] = $file;
                }
            }

            $still = glob($root . '/*/*' . self::FIXTURE_FILENAME) ?: [];
        } else {
            $still = [];
        }

        parent::tearDown();

        $this->assertSame([], $left, 'a fixture upload could not be removed: ' . implode(', ', $left));
        $this->assertSame(
            [],
            $still,
            'this test left uploaded fixtures in the working tree, where a `git add -A` will '
            . 'commit them: ' . implode(', ', $still)
        );
    }

    private function makeCategory(int $storeId): int
    {
        return (int) \DB::table('categories')->insertGetId([
            'name' => 'Textiles',
            'slug' => 'textiles-' . uniqid(),
            'trending' => 0,
            'status' => 1,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0:Product,1:ProductVariant} */
    private function productWithVariant(\App\Models\User $owner, \App\Models\Store $store): array
    {
        $product = Product::forceCreate([
            'name' => 'Scarf',
            'slug' => 'scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'category_id' => $this->makeCategory($store->id),
            'status' => 1,
            'variant_product' => 1,
            'store_id' => $store->id,
            'created_by' => $owner->id,
        ]);

        $variant = ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'M-Natural',
            'sku' => 'Scarf-M-Natural',
            'price' => 43,
            'variation_price' => 41,
            'stock' => 6,
            'store_id' => $store->id,
        ]);

        return [$product, $variant];
    }

    private function upload(\App\Models\User $as, int $variantId, ?UploadedFile $file = null)
    {
        return $this->actingAs($as)->post(route('product.variant.image.store'), [
            'variant_id' => $variantId,
            'variant_image' => $file ?? UploadedFile::fake()->image(self::FIXTURE_FILENAME, 40, 40),
        ]);
    }

    // =========================================================================================
    // The binding
    // =========================================================================================

    public function test_an_image_is_bound_to_the_variant_and_the_products_store(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        [$product, $variant] = $this->productWithVariant($merchant, $store);

        $this->upload($merchant, (int) $variant->id)->assertOk();

        $rows = \DB::table('product_images')->where('variant_id', $variant->id)->get();

        $this->assertCount(1, $rows, 'exactly one image row for the variant');
        $this->assertSame((int) $product->id, (int) $rows[0]->product_id);
        $this->assertSame((int) $store->id, (int) $rows[0]->store_id);
    }

    public function test_a_second_upload_replaces_rather_than_accumulates(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        [, $variant] = $this->productWithVariant($merchant, $store);

        $this->upload($merchant, (int) $variant->id)->assertOk();
        $first = \DB::table('product_images')->where('variant_id', $variant->id)->value('id');

        $this->upload($merchant, (int) $variant->id,
            UploadedFile::fake()->image('other-' . self::FIXTURE_FILENAME, 40, 40))->assertOk();

        $rows = \DB::table('product_images')->where('variant_id', $variant->id)->get();

        /*
         * One image per variant is enforced HERE, not by a unique index — see the migration's
         * docblock for why an index Phase 03 would have to drop is not cheap. So the enforcement
         * is behaviour and needs a test.
         */
        $this->assertCount(1, $rows, 'the second upload must replace the first, not add to it');
        $this->assertNotSame((int) $first, (int) $rows[0]->id, 'the old row was not replaced');
    }

    public function test_the_variants_price_stock_and_sku_are_untouched_by_a_media_operation(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        [, $variant] = $this->productWithVariant($merchant, $store);

        $before = \DB::table('product_variants')->where('id', $variant->id)->first();

        $this->upload($merchant, (int) $variant->id)->assertOk();
        $this->actingAs($merchant)->delete(route('product.variant.image.destroy'), [
            'variant_id' => $variant->id,
        ])->assertOk();

        $after = \DB::table('product_variants')->where('id', $variant->id)->first();

        /*
         * D-26's explicit condition, and the reason the column went on `product_images` rather
         * than on `product_variants`: a media operation must not be able to write to the row that
         * holds a variant's money and stock. That table's rebuild path has destroyed financial
         * values twice already (F82, F105).
         *
         * Compared as whole rows rather than field by field, so a future column cannot slip
         * through — and `updated_at` is included deliberately: nothing here should even touch
         * the variant row.
         */
        $this->assertEquals($before, $after, 'a variant image operation modified the variant row');
    }

    public function test_product_level_gallery_images_are_untouched(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        [$product, $variant] = $this->productWithVariant($merchant, $store);

        $gallery = ProductImage::forceCreate([
            'product_id' => $product->id,
            /*
             * THE STORE'S OWN FOLDER. `ProductImage` carries a `saving` hook calling
             * `StoredPathPolicy::productImage()` (P1-SEC-28), which judges the path against the
             * owning store's folder — so a fixture writing `uploads/pro_image/...` throws
             * "The file path is not accepted." and the test fails for a reason that has nothing
             * to do with what it is testing. The handler's first draft made the same mistake.
             */
            'image_path' => 'uploads/' . $store->id . '/gallery.png',
            'image_url' => 'http://example.test/gallery.png',
            'store_id' => $store->id,
        ]);

        $this->upload($merchant, (int) $variant->id)->assertOk();
        $this->actingAs($merchant)->delete(route('product.variant.image.destroy'), [
            'variant_id' => $variant->id,
        ])->assertOk();

        /*
         * `variant_id IS NULL` means "belongs to the product", which is what every pre-existing
         * row is. A variant operation that swept those would delete a merchant's gallery.
         */
        $this->assertDatabaseHas('product_images', ['id' => $gallery->id]);
    }

    // =========================================================================================
    // Tenancy
    // =========================================================================================

    public function test_a_merchant_cannot_attach_an_image_to_another_stores_variant(): void
    {
        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        [$merchantB, $storeB] = $this->makeTenant();
        [, $victim] = $this->productWithVariant($merchantB, $storeB);

        $this->upload($merchantA, (int) $victim->id)->assertStatus(403);

        $this->assertDatabaseMissing('product_images', ['variant_id' => $victim->id]);
    }

    public function test_a_merchant_cannot_delete_another_stores_variant_image(): void
    {
        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        [$merchantB, $storeB] = $this->makeTenant();
        [$victimProduct, $victim] = $this->productWithVariant($merchantB, $storeB);

        $image = ProductImage::forceCreate([
            'product_id' => $victimProduct->id,
            'variant_id' => $victim->id,
            'image_path' => 'uploads/' . $storeB->id . '/victim.png',
            'image_url' => 'http://example.test/victim.png',
            'store_id' => $storeB->id,
        ]);

        $this->actingAs($merchantA)->delete(route('product.variant.image.destroy'), [
            'variant_id' => $victim->id,
        ])->assertStatus(403);

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }

    public function test_a_missing_variant_is_refused_the_same_way_as_a_foreign_one(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');

        $response = $this->upload($merchant, 999999);

        /*
         * 403 with the same body as the ownership refusal, not a 404: otherwise the difference
         * tells a caller which variant ids exist. The same shape `product_attribute_delete()`
         * records for its own refusals.
         */
        $response->assertStatus(403);
        $this->assertSame('error', $response->json('flag'));
    }

    // =========================================================================================
    // Permission
    // =========================================================================================

    public function test_a_merchant_without_edit_products_is_refused_before_the_variant_is_resolved(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [, $variant] = $this->productWithVariant($merchant, $store);

        $this->upload($merchant, (int) $variant->id)->assertStatus(403);
        $this->assertDatabaseMissing('product_images', ['variant_id' => $variant->id]);

        $this->actingAs($merchant)->delete(route('product.variant.image.destroy'), [
            'variant_id' => $variant->id,
        ])->assertStatus(403);
    }

    /**
     * F206 [2026-10-30] — THE OPERATOR NO LONGER WRITES A MERCHANT'S VARIANT MEDIA.
     *
     * This asserted the opposite until today, and its careful decoy-tenant setup was there for a
     * real reason: it proved the row is stamped with the PRODUCT'S store rather than with
     * `getCurrentStore()`'s operator literal of 1 — the F157 trap, which an earlier operator test
     * missed by letting the two coincide.
     *
     * The owner has now decided that the operator does not write a merchant's catalogue at all, and
     * catalogue media is blocked with it (a judgement call, recorded in the F206 ledger row). So
     * the operator is refused here, and the decoy tenant is KEPT — because the store-stamping
     * question it protects is still live for the principal who may write, and the control below
     * asks it of them.
     */
    public function test_the_platform_operator_may_not_write_variant_media(): void
    {
        /*
         * A DECOY TENANT FIRST, so the product is not in store 1 — otherwise `getCurrentStore()`'s
         * operator literal and the product's store coincide and the control below cannot tell the
         * two candidate predicates apart.
         */
        $this->makeTenant();

        [$merchantB, $storeB] = $this->makeTenant();
        [, $variant] = $this->productWithVariant($merchantB, $storeB);

        $this->assertNotSame(1, (int) $storeB->id, 'premise: the product is NOT in store 1');

        $operator = $this->makePlatformAdmin();

        $this->upload($operator, (int) $variant->id)->assertStatus(403);
        $this->assertDatabaseMissing('product_images', ['variant_id' => $variant->id]);

        $this->actingAs($operator)->delete(route('product.variant.image.destroy'), [
            'variant_id' => $variant->id,
        ])->assertStatus(403);

        /*
         * THE CONTROL, and it still asks the F157 question. The owning merchant, holding the
         * permission, uploads — and the row must carry the PRODUCT'S store, not the acting user's
         * current one.
         */
        $this->grantPermission($merchantB, 'Edit Products');
        $merchantB = \App\Models\User::find($merchantB->id);

        $this->upload($merchantB, (int) $variant->id)->assertOk();

        $row = \DB::table('product_images')->where('variant_id', $variant->id)->first();

        $this->assertNotNull($row, 'the permitted owner can no longer upload variant media, so the '
            . 'refusals above prove nothing about WHO was refused');
        $this->assertSame(
            (int) $storeB->id,
            (int) $row->store_id,
            "the row was stamped with getCurrentStore()'s literal instead of the product's own "
            . "store -- the F157 trap"
        );
    }
}
