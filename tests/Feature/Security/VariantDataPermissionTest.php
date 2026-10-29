<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F175 — `attribute_combination_data()` had NO permission check at all.
 * =====================================================================
 *
 * It is the renderer the product editor injects from `$(document).ready` and the one the SHOW
 * screen loads, so it is the panel a merchant sees first — and it read:
 *
 *     ProductVariant::where('product_id', $request->id)
 *         ->where('store_id', getCurrentStore())->get()
 *
 * with no gate above it. Every sibling on this controller carries one: `attribute_combination_edit()`
 * takes `Manage Products` OR `Edit Products`, `product_attribute_delete()` and `file_delete()` take
 * `Edit Products`. This one was missed.
 *
 * WHAT THAT EXPOSES is a merchant's commercial data: every variant's PRICE, SALE PRICE, STOCK, SKU,
 * low-stock threshold and shipping, rendered in full, to any authenticated principal in the `web`
 * group — a staff member hired for support or content with no product permission at all.
 *
 * `Manage Products` OR `Edit Products`, NOT `Edit Products` ALONE, and the reason is in the two
 * callers rather than in a preference. `resources/views/product/show.blade.php:743` loads this
 * endpoint, and `ProductController::show()` is gated on `Manage Products` — so gating the data it
 * needs on `Edit Products` would break the read-only screen for a role that is allowed to use it.
 * The sibling records the same reasoning for the same reason.
 *
 * THE REFUSAL RENDERS AN EMPTY PANEL RATHER THAN AN ERROR, and that is a contract decision taken
 * deliberately. Both callers do `$('…').html(data)` with NO `error` handler
 * (`edit.blade.php:1034`, `show.blade.php`), so a 403 would leave the container untouched and
 * `$('#loader').fadeOut()` — which lives only in `success` — would never run: a permanent spinner.
 * An empty render keeps the contract exactly, leaks nothing, and is indistinguishable from "this
 * product has no variants", which is the safe direction. The refusal is recorded in the log
 * instead, as every other ownership refusal on this controller is.
 */
class VariantDataPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SECRET_PRICE = 44321;
    private const SECRET_STOCK = 7731;
    private const SECRET_SKU = 'SKU-DO-NOT-LEAK';

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

    /** @return array{0:Product,1:ProductVariant} a product with one variant carrying distinctive values */
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
            'sku' => self::SECRET_SKU,
            'price' => self::SECRET_PRICE,
            'variation_price' => self::SECRET_PRICE,
            'stock' => self::SECRET_STOCK,
            'store_id' => $store->id,
        ]);

        return [$product, $variant];
    }

    private function request(\App\Models\User $as, int $productId)
    {
        return $this->actingAs($as)->post(route('products.attribute_combination_data'), [
            'id' => $productId,
        ]);
    }

    /** Does the rendered panel disclose this product's commercial values? */
    private function discloses($response): bool
    {
        $html = (string) $response->getContent();

        return str_contains($html, self::SECRET_SKU)
            || str_contains($html, (string) self::SECRET_PRICE)
            || str_contains($html, (string) self::SECRET_STOCK);
    }

    // =========================================================================================
    // 1. The authorised merchant — the positive control, without which every absence below is free
    // =========================================================================================

    public function test_an_authorised_merchant_sees_their_own_variants(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        [$product] = $this->productWithVariant($merchant, $store);

        $response = $this->request($merchant, (int) $product->id);

        $response->assertOk();
        $this->assertTrue(
            $this->discloses($response),
            'premise: the owner is served their own variant panel. Without this, every refusal '
            . 'test below passes for free.'
        );
    }

    // =========================================================================================
    // 2. The authorised employee
    // =========================================================================================

    public function test_an_employee_with_the_permission_sees_the_variants(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product] = $this->productWithVariant($merchant, $store);

        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Manage Products');

        $response = $this->request($staff, (int) $product->id);

        $response->assertOk();
        $this->assertTrue($this->discloses($response),
            'an employee who holds Manage Products must still be able to use the editor');
    }

    public function test_an_employee_with_edit_products_also_sees_the_variants(): void
    {
        /*
         * BOTH NAMES, because the two callers are gated differently: the SHOW screen takes
         * `Manage Products` and the EDIT screen takes `Edit Products`. A gate on either name alone
         * would break one of them.
         */
        [$merchant, $store] = $this->makeTenant();
        [$product] = $this->productWithVariant($merchant, $store);

        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Edit Products');

        $response = $this->request($staff, (int) $product->id);

        $response->assertOk();
        $this->assertTrue($this->discloses($response));
    }

    // =========================================================================================
    // 3. The employee with no product permission — the defect
    // =========================================================================================

    public function test_an_employee_without_any_product_permission_sees_no_prices_or_stock(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product] = $this->productWithVariant($merchant, $store);

        /*
         * A real role, not a user with no role at all: `grantPermission` creates one, so this
         * staff member HAS a role and simply lacks any product permission — which is what a
         * support or content employee looks like.
         */
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Manage Customer');

        $response = $this->request($staff, (int) $product->id);

        /*
         * 200 with an empty panel, not a 403: both callers inject the body with `.html(data)` and
         * neither defines an `error` handler, so a 403 would leave a permanent loader spinning.
         * See this file's docblock.
         */
        $response->assertOk();
        $this->assertFalse(
            $this->discloses($response),
            "an employee with no product permission read this product's variant prices, stock and SKU"
        );
    }

    // =========================================================================================
    // 4. Another store
    // =========================================================================================

    public function test_a_merchant_cannot_read_another_stores_variants(): void
    {
        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Products');

        [$merchantB, $storeB] = $this->makeTenant();
        [$victim] = $this->productWithVariant($merchantB, $storeB);

        $response = $this->request($merchantA, (int) $victim->id);

        $response->assertOk();
        $this->assertFalse($this->discloses($response),
            "another store's variant prices and stock were rendered");
    }

    public function test_a_store_less_principal_reads_nothing(): void
    {
        /*
         * `getCurrentStore()` returns null for a principal with no store, and
         * `where('store_id', null)` becomes `whereNull` in Laravel — which matches every
         * STORE-LESS variant row rather than nothing. That is the fail-OPEN edge this codebase
         * has recorded repeatedly, so it is driven rather than assumed.
         */
        [$merchant, $store] = $this->makeTenant();
        [$product] = $this->productWithVariant($merchant, $store);

        /* A variant with no store at all — the row `whereNull` would match. */
        ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'Orphan',
            'sku' => self::SECRET_SKU . '-ORPHAN',
            'price' => self::SECRET_PRICE,
            'stock' => self::SECRET_STOCK,
            'store_id' => null,
        ]);

        $stray = $this->makeMerchant();
        $this->grantPermission($stray, 'Manage Products');
        $stray->current_store = null;
        $stray->save();

        $response = $this->request($stray, (int) $product->id);

        $response->assertOk();
        $this->assertFalse($this->discloses($response),
            'a principal with no store context read variant data');
    }

    // =========================================================================================
    // 5. The platform operator
    // =========================================================================================

    public function test_the_platform_operator_reads_the_products_own_variants(): void
    {
        /*
         * A DECOY TENANT FIRST, so the product is not in store 1 — otherwise
         * `getCurrentStore()`'s operator literal and the product's store coincide and this test
         * cannot tell the two predicates apart.
         *
         * The operator's existing read access is PRESERVED, not extended: this grants no write
         * capability anywhere.
         */
        $this->makeTenant();

        [$merchantB, $storeB] = $this->makeTenant();
        [$product] = $this->productWithVariant($merchantB, $storeB);

        $this->assertNotSame(1, (int) $storeB->id, 'premise: the product is NOT in store 1');

        $operator = $this->makePlatformAdmin();

        $response = $this->request($operator, (int) $product->id);

        $response->assertOk();
        $this->assertTrue(
            $this->discloses($response),
            "the operator was served store 1's variants instead of the product's own -- the "
            . 'F157 trap, where `getCurrentStore()` hands a super admin the hardcoded literal 1'
        );
    }

    // =========================================================================================
    // 6. A product that does not exist
    // =========================================================================================

    public function test_a_missing_product_renders_an_empty_panel_rather_than_failing(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        $response = $this->request($merchant, 999999);

        $response->assertOk();
        $this->assertFalse($this->discloses($response));
    }

    public function test_the_operator_also_gets_an_empty_panel_for_a_missing_product(): void
    {
        /*
         * `productIsOutsideCurrentStore()` returns false for the operator BEFORE its own
         * `empty($product)` test, so the operator is the principal who can reach the body with a
         * null product — which is how F165 produced a 500 two methods away.
         */
        $this->makeTenant();
        $operator = $this->makePlatformAdmin();

        $response = $this->request($operator, 999999);

        $this->assertNotSame(500, $response->status(),
            'a missing product id raised a server error for the operator');
        $response->assertOk();
    }

    // =========================================================================================
    // 7. A variant that belongs to a different product
    // =========================================================================================

    public function test_the_panel_contains_only_the_requested_products_variants(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        [$product] = $this->productWithVariant($merchant, $store);
        [$other] = $this->productWithVariant($merchant, $store);

        /*
         * A variant of the merchant's OTHER product, in the same store, with a distinctive key.
         * The request names only a product id, so the question is whether the response can carry
         * a variant that is not that product's -- which a predicate on `store_id` alone would.
         */
        ProductVariant::forceCreate([
            'product_id' => $other->id,
            'variant' => 'OTHER-PRODUCT-VARIANT',
            'sku' => 'SKU-OTHER-PRODUCT',
            'price' => 999,
            'stock' => 111,
            'store_id' => $store->id,
        ]);

        $html = (string) $this->request($merchant, (int) $product->id)->getContent();

        $this->assertStringContainsString('M-Natural', $html, 'premise: the panel rendered at all');
        $this->assertStringNotContainsString('OTHER-PRODUCT-VARIANT', $html,
            "a variant of a different product was rendered in this product's panel");
        $this->assertStringNotContainsString('SKU-OTHER-PRODUCT', $html);
    }
}
