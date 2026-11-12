<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Directive §3 — the reported cross-tenant product price and quantity reads.
 * =================================================================================================
 *
 * Two handlers take a product id straight from request input and resolve it with a bare
 * `Product::find()`:
 *
 *   * `ProductController::getProductsVariantQuantity()`, GET `get-products-variant-quantity`,
 *     inside `Route::middleware(['auth', 'xss', 'setlocate', 'verified'])` in routes/web.php — an
 *     authenticated MERCHANT route with no permission check and no tenant predicate;
 *   * `ProductController::product_price()`, POST `{storeSlug?}/product_price`, inside
 *     `Route::middleware([ActiveTheme::class, 'themelanguage'])` in routes/customer.php — a PUBLIC
 *     storefront endpoint.
 *
 * WHY THE GLOBAL SCOPE DOES NOT SAVE EITHER. `Product` has `StoreScope` registered, which reads
 * like protection and is not. `StoreScope::isActiveFor()` short-circuits for any model absent from
 * `config/tenancy.php`'s `scoped_models`, and `Product` is absent — so the scope adds **no
 * predicate for any principal**: not a customer, not a merchant, not the operator. `Product::find()`
 * reaches every tenant's catalogue. This is recorded in CLAUDE.md and it is why asserting through
 * `withoutGlobalScopes()` is pointless here: there is no scope to bypass.
 *
 * THE TWO HANDLERS ARE NOT EQUALLY SEVERE, and the tests say which is which rather than implying
 * they are the same finding.
 *
 *   * The quantity endpoint is the real confidentiality leak. It returns `quantity` as an
 *     INTEGER — a competitor's exact variant stock level — to any authenticated merchant, for any
 *     product id in the platform. A storefront publishes "In Stock" or "Out Of Stock"; it does not
 *     publish the number. There is no public route to this figure, so nothing else makes it
 *     available.
 *   * `product_price` is a public endpoint returning data that is already public: anyone may visit
 *     store B's own storefront and read B's prices. The defect there is an ISOLATION failure, not
 *     a disclosure — store A's settings, tax rules and flash-sale conditions get applied to store
 *     B's product, and the result feeds the storefront cart UI. It is fixed because computing one
 *     tenant's price under another tenant's rules is wrong, not because the price was secret.
 *
 * Both predicates are derived from the AUTHENTICATED PRINCIPAL or from the storefront identity
 * being browsed, never from a second request field — a predicate built from two caller-supplied
 * values only compares the caller against themselves, which CLAUDE.md records as having produced
 * four separate defects.
 */
class ProductReadTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Raw-insert a product. Deliberately NOT through Eloquent create with observers: this mirrors
     * the production rows these handlers read, and the fixture needs no events.
     *
     * @return object{id: int, store_id: int}
     */
    private function productIn(
        int $storeId,
        int $createdBy,
        string $name,
        int $stock,
        float $price,
        bool $hasVariants = true
    ): Product {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'p-' . $name . '-' . $storeId,
            'product_stock' => $stock,
            'track_stock' => 1,
            'variant_product' => $hasVariants ? 1 : 0,
            'price' => $price,
            'sale_price' => $price,
            'status' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    private function variantFor(Product $product, string $variant, int $stock): ProductVariant
    {
        // `sku` is NOT NULL with no default and `product_variants` has no `created_by` column.
        // Both found by running this fixture, not by reading the model.
        return ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => $variant,
            'sku' => 'sku-' . $product->id . '-' . str_replace('-', '', $variant),
            'stock' => $stock,
            'price' => $product->price,
            'store_id' => $product->store_id,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // getProductsVariantQuantity -- the merchant-authenticated inventory read
    // ---------------------------------------------------------------------------------------

    public function test_a_merchant_cannot_read_another_tenants_variant_stock_quantity(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $victim = $this->productIn($storeB->id, $merchantB->id, 'victim', 500, 999.00);
        $victimVariant = $this->variantFor($victim, 'Red-XL', 437);

        $merchantA->forceFill(['current_store' => $storeA->id])->save();
        $merchantA->flushCache();

        $response = $this->actingAs($merchantA)->getJson('get-products-variant-quantity?' . http_build_query([
            'product_id' => $victim->id,
            'variants' => 'Red-XL',
            'session_key' => 'cart-' . $storeA->id,
        ]));

        // The exact figure is what must not cross. Asserting on the status would pass against a
        // handler that answered 200 with the number in it.
        $body = $response->getContent();

        $this->assertStringNotContainsString(
            '437',
            $body,
            "Store B's variant stock level (437) crossed to a merchant of store A through "
            . 'get-products-variant-quantity. The handler resolves $request->product_id with a '
            . 'bare Product::find(), and Product is NOT in config/tenancy.php scoped_models, so '
            . "StoreScope adds no predicate. Constrain the lookup to the authenticated "
            . "principal's store -- getCurrentStore() -- not to any request field. Body was:\n"
            . $body
        );

        $this->assertStringNotContainsString(
            '999',
            $body,
            "Store B's product price crossed to a merchant of store A through the same handler, "
            . 'which dereferences $product->price before it checks the product exists.'
        );
    }

    public function test_a_merchant_can_still_read_their_own_variant_stock_quantity(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $own = $this->productIn($store->id, $merchant->id, 'own', 500, 120.00);
        $this->variantFor($own, 'Blue-S', 312);

        $merchant->forceFill(['current_store' => $store->id])->save();
        $merchant->flushCache();

        $response = $this->actingAs($merchant)->getJson('get-products-variant-quantity?' . http_build_query([
            'product_id' => $own->id,
            'variants' => 'Blue-S',
            'session_key' => 'cart-' . $store->id,
        ]));

        $response->assertOk();

        $this->assertSame(
            312,
            $response->json('quantity'),
            'A merchant reading their OWN product variant must still get the real quantity. If '
            . 'this fails the tenant predicate is too tight and has broken the feature -- which '
            . 'is worse than the leak it was meant to close, because someone will revert it.'
        );

        $this->assertTrue(
            $response->json('status'),
            'status must stay true on the legitimate path; the UI gates the quantity display on it.'
        );
    }

    public function test_a_missing_product_id_does_not_error_the_quantity_endpoint(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $merchant->forceFill(['current_store' => $store->id])->save();
        $merchant->flushCache();

        // The handler dereferenced $product->price BEFORE its own `if ($product && ...)` check,
        // so an unknown id was a 500. A tenant predicate makes that path reachable far more
        // often -- every cross-tenant id now resolves to null -- so the null handling has to be
        // right or the fix trades a leak for an error.
        $response = $this->actingAs($merchant)->getJson('get-products-variant-quantity?' . http_build_query([
            'product_id' => 99999999,
            'variants' => 'Nope',
            'session_key' => 'cart-' . $store->id,
        ]));

        $response->assertOk();
        $this->assertFalse($response->json('status'));
        $this->assertSame(0, $response->json('quantity'));
    }

    /**
     * FOUND IN THE FIX ITSELF, not in the shipped code.
     *
     * The first version of the predicate was a bare `Product::where('store_id', getCurrentStore())`.
     * Two facts combine to make that fail OPEN rather than closed:
     *
     *   1. `getCurrentStore()` (app/Helper/helper.php:758) returns NULL for a principal who has no
     *      `current_store`, is not `super admin`, and owns no store — `Store::where('created_by',
     *      $user->id)->first()` then yields nothing and it returns null. It also returns null
     *      implicitly when there is no authenticated user at all.
     *   2. Laravel's `Builder::where($column, null)` does not compare against NULL — it rewrites
     *      itself to `whereNull($column)`. And `products.store_id` is `nullable()`
     *      (database/migrations/2023_12_26_092822_create_products_table.php:49).
     *
     * So the predicate became `where store_id is null`, which MATCHES every product carrying a
     * NULL `store_id` instead of matching nothing. That is the `null_tenant => 'open'` failure
     * mode CLAUDE.md records, arrived at by a different road: a predicate that reads like a tenant
     * check and silently selects the unowned rows.
     *
     * The handler now refuses an unresolvable tenant explicitly instead of building a query from
     * null.
     */
    public function test_a_principal_with_no_resolvable_store_reads_nothing(): void
    {
        // A product with no owner at all. `store_id` is nullable, so this row is legal.
        $orphan = Product::forceCreate([
            'name' => 'orphan',
            'slug' => 'p-orphan',
            'product_stock' => 500,
            'track_stock' => 1,
            'variant_product' => 1,
            'price' => 424242.00,
            'sale_price' => 424242.00,
            'status' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => null,
            'created_by' => 1,
        ]);
        $this->variantFor($orphan, 'Orphan-One', 171717);

        // A principal for whom getCurrentStore() resolves to NULL: not the operator, no
        // current_store, and no store of their own.
        $stateless = $this->makeMerchant(['current_store' => null]);
        $stateless->flushCache();

        $this->assertNull(
            getCurrentStore($stateless->id),
            'premise: this principal must have no resolvable store, or the test below proves '
            . 'nothing. If getCurrentStore() has gained a fallback, re-read the null handling in '
            . 'getProductsVariantQuantity() before trusting this file.'
        );

        $response = $this->actingAs($stateless)->getJson('get-products-variant-quantity?' . http_build_query([
            'product_id' => $orphan->id,
            'variants' => 'Orphan-One',
            'session_key' => 'cart-none',
        ]));

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringNotContainsString(
            '171717',
            $body,
            'A principal with no resolvable store read the stock level of an unowned product. '
            . "`where('store_id', null)` became `whereNull('store_id')` and selected exactly the "
            . "rows nobody owns. Refuse the null tenant; do not build a query from it. Body:\n" . $body
        );

        $this->assertStringNotContainsString(
            '424242',
            $body,
            'the same path leaked the unowned product\'s price'
        );

        $this->assertFalse($response->json('status'), 'status must be false when no tenant resolves');
    }

    // ---------------------------------------------------------------------------------------
    // product_price -- the public storefront isolation failure
    // ---------------------------------------------------------------------------------------

    /**
     * Driven with a NON-VARIANT product deliberately.
     *
     * The first version of this test used a variant product and asserted
     * `assertNotEquals(777.0, $response->json('original_price') ?? 0)`. It PASSED against the
     * unfixed handler, and it was wrong to. The handler answered 200 with
     * `{"status":"error","message":"Product variant is not available."}` and no `original_price`
     * key at all, so `?? 0` compared 0 against 777 and the assertion held while store B's product
     * had in fact been read -- the same response carried B's `variant_product` and B's
     * `variant_id`. A variant product never reaches the pricing branch in this fixture (its own
     * store's product does not either), so that shape could only ever pass vacuously.
     *
     * A non-variant product takes the branch that actually returns the money: measured on the
     * legitimate path it answers `original_price`, `sub_total`, `final_price`, `currency` and
     * `total_tax_price`. That makes the leak assertable as a positive fact rather than an absence.
     */
    public function test_one_storefront_does_not_price_another_tenants_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $foreign = $this->productIn($storeB->id, $merchantB->id, 'foreign', 500, 777.00, false);

        // Store A's storefront, store B's product. No authentication: this route is public.
        $response = $this->postJson($storeA->slug . '/product_price', [
            'product_id' => $foreign->id,
            'qty' => 1,
        ]);

        $response->assertOk();

        $this->assertNull(
            $response->json('original_price'),
            "Store A's storefront priced store B's product: it returned an original_price of "
            . var_export($response->json('original_price'), true) . " for a product owned by "
            . "store {$storeB->id}. The price itself is public -- anyone may read it on B's own "
            . "storefront -- so this is an isolation failure rather than a disclosure: store A's "
            . "settings, tax rules and flash-sale conditions were applied to another tenant's "
            . 'product, and the result feeds A\'s cart UI. Constrain the lookup to the store '
            . 'whose storefront is being browsed.'
        );

        // NOTE WHAT THIS SECOND LIMB DOES AND DOES NOT PROVE. With a non-variant fixture
        // `variant_product` is 0 both before and after the fix, because `:4221` is
        // `$product->variant_product ?? 0` and the row's own value IS 0 -- so on THIS case it is
        // decoration, not evidence. An earlier version of this comment claimed it was "the
        // assertion the first version of this test should have made", which an independent review
        // correctly called an overclaim. It is kept as a cheap shape check, and the case that
        // actually makes it bite is the variant-fixture test immediately below.
        $this->assertSame(
            0,
            (int) $response->json('variant_product'),
            'variant_product should be 0 for a product absent from this storefront'
        );
    }

    /**
     * The companion to the case above, and the one where `variant_product` is real evidence.
     *
     * A VARIANT product never reaches the pricing branch in this fixture -- measured, its own
     * store's product does not either -- so `original_price` is absent whatever the predicate
     * does, and the first version of this file passed vacuously for exactly that reason. But the
     * unfixed handler still READ store B's row before refusing, and said so: it answered
     * `variant_product: 1` and `variant_id: 1`, both taken from B's rows, inside a response whose
     * `status` was `"error"`. That is the leak visible on a response that looks like a refusal.
     */
    public function test_a_refusal_shaped_response_still_must_not_carry_another_tenants_row(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $foreign = $this->productIn($storeB->id, $merchantB->id, 'variantforeign', 500, 555.00, true);
        $foreignVariant = $this->variantFor($foreign, 'Green-M', 88);

        $response = $this->postJson($storeA->slug . '/product_price', [
            'product_id' => $foreign->id,
            'varint' => 'Green-M',
            'qty' => 1,
        ]);

        $response->assertOk();

        $this->assertSame(
            0,
            (int) $response->json('variant_product'),
            'The handler read store B\'s product row and echoed its variant_product back as '
            . var_export($response->json('variant_product'), true) . '. The response may well say '
            . '"status": "error" -- it did before the fix -- but it had already disclosed that the '
            . "foreign product exists and is a variant product. A refusal that reports another "
            . "tenant's data is not a refusal."
        );

        $this->assertSame(
            0,
            (int) $response->json('variant_id'),
            'The handler resolved store B\'s ProductVariant row and returned its id ('
            . var_export($response->json('variant_id'), true) . '). The variant lookup is keyed on '
            . 'the caller-supplied product id, so refusing the product is what stops it.'
        );
    }

    public function test_a_storefront_still_prices_its_own_product(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $own = $this->productIn($store->id, $merchant->id, 'ownprice', 500, 250.00, false);

        $response = $this->postJson($store->slug . '/product_price', [
            'product_id' => $own->id,
            'qty' => 1,
        ]);

        $response->assertOk();

        $this->assertSame(
            '250.00',
            $response->json('original_price'),
            'The storefront must still price its own catalogue. A predicate that refuses the '
            . 'legitimate path has broken the shop, not secured it -- and someone will revert it. '
            . 'Measured on the unfixed handler, this path returns original_price 250.00, '
            . 'sub_total 250, final_price 250.00 and total_tax_price 0.00.'
        );

        $this->assertSame(
            250,
            $response->json('sub_total'),
            'the subtotal must survive the predicate too'
        );
    }

    /**
     * `ProductController::VariantDelete()` is dead code, and this pins both halves of why that
     * matters, because the two facts are only dangerous together.
     *
     * It gates on `auth()->user()->can('Delete Variants')`, and Laratrust runs with
     * `permissions_as_gates => false`, so a permission is not a Gate ability and `can()` is always
     * false. The handler therefore refuses a merchant who genuinely holds the permission — a
     * broken feature, but fail-closed, so not a live vulnerability.
     *
     * The trap is that `can()` → `isAbleTo()` is the obvious tidy-up, and it would activate a
     * handler that resolved a caller-supplied `$product_id` with a bare `Product::find()` and then
     * WROTE to it, rewriting `variants_json` on another tenant's product. The predicate was added
     * while the handler was still unreachable, so the permission fix cannot silently ship a
     * cross-tenant write.
     */
    public function test_the_variant_delete_gate_never_opens_and_carries_a_predicate_anyway(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Delete Variants');
        $merchant->flushCache();
        $armed = \App\Models\User::find($merchant->id);

        $this->assertTrue(
            $armed->isAbleTo('Delete Variants'),
            'premise: this merchant really does hold the permission under Laratrust'
        );

        $this->assertFalse(
            $armed->can('Delete Variants'),
            'can() has started returning true for a Laratrust permission, which means '
            . 'config/laratrust.php permissions_as_gates was flipped to true. That makes '
            . 'VariantDelete REACHABLE. Verify its store predicate is still present and add a '
            . 'cross-tenant refusal case for it before changing this assertion.'
        );

        // The predicate must be in the source whether or not the gate opens, so that a future
        // `can` -> `isAbleTo` fix does not have to remember the tenancy fix too.
        $source = file_get_contents(base_path('app/Http/Controllers/ProductController.php'));
        $body = substr($source, strpos($source, 'public function VariantDelete'));
        $body = substr($body, 0, strpos($body, 'public function ', 10) ?: 4000);

        $this->assertStringContainsString(
            "Product::where('store_id', \$currentStore)->find(\$product_id)",
            $body,
            'VariantDelete() resolves $product_id without a store predicate again. Product is not '
            . 'in config/tenancy.php scoped_models, so a bare Product::find() reaches every '
            . "tenant's catalogue -- and this method WRITES, rewriting variants_json. The "
            . 'predicate was added while the handler was unreachable precisely so that whoever '
            . 'fixes the can()/isAbleTo() gate does not ship a cross-tenant write with it.'
        );
    }

    public function test_premise_product_really_is_unscoped_so_the_predicate_must_be_explicit(): void
    {
        // If Product were ever added to scoped_models this test file's reasoning changes, and the
        // handlers' explicit predicates would become belt-and-braces rather than the only guard.
        // Pin the premise so that change is noticed here rather than inferred.
        $this->assertNotContains(
            Product::class,
            config('tenancy.scoped_models', []),
            'Product has been added to config/tenancy.php scoped_models. StoreScope would now '
            . 'filter it, which is a real change to this file\'s premise: re-read the explicit '
            . 'predicates in product_price() and getProductsVariantQuantity() and decide whether '
            . 'they are still the only thing standing between a merchant and another tenant\'s '
            . 'inventory.'
        );

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();
        $foreign = $this->productIn($storeB->id, $merchantB->id, 'premise', 7, 11.00);

        $this->actingAs($merchantA);
        $merchantA->forceFill(['current_store' => $storeA->id])->save();

        $this->assertNotNull(
            Product::find($foreign->id),
            'Product::find() no longer reaches another tenant\'s row, so the premise recorded in '
            . 'CLAUDE.md has changed. Verify against the raw table before trusting this.'
        );

        $this->assertSame(
            1,
            (int) DB::table('products')->where('id', $foreign->id)->count(),
            'the fixture row is really in the table'
        );
    }
}
