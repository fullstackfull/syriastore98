<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * PHASE 02 LEDGER F114 — A SHIPPING CLASS ID IS WRITTEN FROM CALLER INPUT WITH NO OWNERSHIP CHECK.
 * =================================================================================================
 *
 * `docs/development/phase-2/PHASE-02-TASK-LEDGER.md:426`, RECORDED NOT FIXED since 2026-10-26, found
 * during the security review of the D-09 change on a line that change rewrote.
 *
 * **Not to be confused with `P4-F114`**, which is a different defect in a different register
 * (`docs/development/phase-4/findings/flutter-contract.md:178`, `update-customer` answering 401) and
 * is closed. Two registers number findings from one shared `F###` space with no namespace — the same
 * collision class CLAUDE.md records for "Phase 6". This file is the PHASE 02 one.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE GLOBAL SCOPE CANNOT BE THE CHECK — MEASURED, NOT READ OFF A LIST.
 *
 * `Shipping` carries `StoreScope` through `BelongsToStore`, so `getGlobalScopes()` answers
 * `[StoreScope]` and looks protected. It is **not in `config/tenancy.php`'s `scoped_models`** (11
 * entries), so `StoreScope::isActiveFor()` short-circuits and the model adds **no predicate for any
 * principal** — not a merchant, not the operator. Exactly the `Product`/`Category` trap, and
 * `test_shipping_is_not_scoped_so_the_scope_cannot_be_the_check` measures it against the booted
 * container rather than trusting this paragraph.
 *
 * -------------------------------------------------------------------------------------------------
 * THE KEY FAMILY IS UNBOUNDED, WHICH IS WHY THE FIX IS AT THE READ SITES.
 *
 * `shipping_id_*` is **not** `shipping_id_1`, `shipping_id_2`. The suffix is
 * `str_replace('.', '_', $variantCombination)` — arbitrary merchant text such as `shipping_id_S-Red`
 * — so the family is one key per combination and cannot be enumerated in a validator. Nine writers
 * read a shipping class id from the request across two controllers:
 *
 *     ProductController::store()    parent  :816  :1091  :1171      variant  :1408
 *     ProductController::update()   parent  :2066 :2536              variant  :2850
 *     Api\DashboardController       AddProduct :3912   UpdateProduct :4110   (validated `nullable` only)
 *
 * None validated the id at all before this change — no `exists:`, no ownership predicate.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS DOES *NOT* CHANGE, AND WHY THAT BOUNDED THE FIX.
 *
 * The ledger's wording is *"and priced against"*. **That half is already closed on the read side by
 * F283** (`Api\ApiController::shippingClassOfStore`), which distinguishes three outcomes because the
 * caller charges differently for each: `null` "unclassified" → the no-cost bucket, `false` "not this
 * store's" → **+0**, a model → its bucket. So a foreign id already prices at +0, and F283's own
 * comment names this write hole as the still-open half — *"nothing validates that a product's
 * `shipping_id` belongs to its own store … and legacy rows already are"* in that state.
 *
 * Two consequences that shaped the fix rather than being discovered after it:
 *
 *   **A refusal must not coerce the value to null.** `null` and `false` take DIFFERENT buckets, so
 *   rewriting a foreign id to null would move a charge from +0 to `product_no_cost` — a pricing
 *   change, which is reserved. The refusal therefore **keeps the stored value** (the idiom
 *   `ssVariantValue()` already uses for a blank) and writes nothing new.
 *
 *   **A non-existent id must still pass through.** F283 reads "does not exist anywhere" as the
 *   no-cost bucket, and refusing it would change that too. The guard asks the OWNERSHIP question
 *   only: it refuses an id that exists and belongs to somebody else, and is silent about everything
 *   else. `test_an_id_that_exists_nowhere_is_still_written` pins that boundary in both directions.
 *
 *   **A NULL `store_id` row must still be accepted.** `shippings.store_id` is nullable (migration
 *   `2023_12_19_054936:19`) and legacy rows may not carry it; F283 and
 *   `CartController::get_shipping_method()`'s P01-COMMERCE-SEC-01 predicate both allow NULL. A
 *   writer that refused them would diverge from the reader and lock merchants out of their own
 *   legacy classes.
 */
class ProductShippingClassOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SIZES = ['S', 'M'];

    private const COLOURS = ['Red', 'Blue'];

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

    private function makeShipping(?int $storeId, string $name): Shipping
    {
        return Shipping::forceCreate([
            'name' => $name,
            'description' => $name,
            'slug' => strtolower($name) . '-' . uniqid(),
            'store_id' => $storeId,
        ]);
    }

    /**
     * Store A with its own class, store B with the HOSTILE class, and a legacy class owned by
     * nobody.
     *
     * @return array{merchant:User,store:\App\Models\Store,category:int,mine:Shipping,foreign:Shipping,legacy:Shipping,outsider:User}
     */
    private function twoTenants(): array
    {
        /*
         * BURN STORE ID 1 BEFORE BUILDING THE ONE UNDER TEST.
         *
         * `getCurrentStore()` returns the hardcoded literal **1** for a `super admin` with an empty
         * `current_store` (`helper.php:636`). If the store under test IS store 1, then "the
         * product's store", "the caller's store" and "the operator's fallback" are ONE number, and
         * the operator case below cannot tell a guard keyed on the product from one keyed on the
         * caller. CLAUDE.md records that exact collision costing four surviving mutants in
         * `PlatformUploaderDoorsTest`, whose remedy (`aStoreWhoseIdIsNobodyElses()`) this mirrors.
         *
         * The premise assertion in the operator test is what proves this decoy worked; it failed
         * loudly before the decoy existed.
         */
        $decoyMerchant = $this->makeMerchant();
        $this->makeStore($decoyMerchant);

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission(User::find($merchant->id), 'Edit Products');
        $this->grantPermission(User::find($merchant->id), 'Create Product');

        /*
         * A PLAN, or the CREATE path answers HTTP 500 for every principal with `Attempt to read
         * property "max_products" on null` — the same fixture `OperatorCatalogueWriteTest:96` builds
         * and for the same reason. Without it the create case would assert "the hostile id was not
         * written" against a request that 500'd before reaching any writer, which is trap 1: the
         * endpoint fails for everyone, so the row assertion holds either way. The premise assertion
         * in that case is what reported this rather than letting it pass.
         */
        $merchant = User::find($merchant->id);
        $merchant->plan_id = \DB::table('plans')->insertGetId([
            'name' => 'f114-plan-' . uniqid(),
            'price' => 0,
            'themes' => '[]',
            'max_products' => 1000,
            'storage_limit' => '100',
            'shipping_method' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $merchant->save();

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);

        return [
            'merchant' => User::find($merchant->id),
            'store' => $store,
            'category' => $this->makeCategory($store->id),
            'mine' => $this->makeShipping($store->id, 'MineClass'),
            'foreign' => $this->makeShipping($outsiderStore->id, 'ForeignClass'),
            'legacy' => $this->makeShipping(null, 'LegacyClass'),
            'outsider' => User::find($outsiderMerchant->id),
        ];
    }

    /** A simple (non-variant) product of this store. */
    private function makeSimpleProduct($store, User $merchant, $shippingId): Product
    {
        return Product::forceCreate([
            'name' => 'Scarf',
            'slug' => 'scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'shipping_id' => $shippingId,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);
    }

    /**
     * A variant product with the real attribute fixture, so the variant writer is actually
     * reached rather than skipped.
     *
     * @return array{0:Product,1:list<string>}
     */
    private function makeVariantProduct($store, User $merchant): array
    {
        $attributes = [];

        foreach ([['Size', self::SIZES], ['Colour', self::COLOURS]] as [$label, $terms]) {
            $attributeId = (int) \DB::table('product_attributes')->insertGetId([
                'name' => $label,
                'slug' => strtolower($label) . '-' . uniqid(),
                'store_id' => $store->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $optionIds = [];
            foreach ($terms as $term) {
                $optionIds[] = (int) \DB::table('product_attribute_options')->insertGetId([
                    'attribute_id' => $attributeId,
                    'terms' => $term,
                    'store_id' => $store->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $attributes[$attributeId] = $optionIds;
        }

        $storedAttribute = [];
        foreach ($attributes as $attributeId => $optionIds) {
            $storedAttribute[] = [
                'attribute_id' => (string) $attributeId,
                'values' => [implode('|', $optionIds)],
                /* "1" as a STRING: the development fixture stores "on", and `"on" == 1` is false
                   on PHP 8.3, so a product copied from that fixture never opens the rebuild gate
                   and the variant writer is never reached. */
                'visible_attribute_' . $attributeId => '1',
                'for_variation_' . $attributeId => '1',
            ];
        }

        $product = Product::forceCreate([
            'name' => 'Scarf',
            'slug' => 'scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 1,
            'attribute_id' => implode(',', array_keys($attributes)),
            'product_attribute' => json_encode($storedAttribute),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $variants = [];
        $n = 0;
        foreach (self::SIZES as $size) {
            foreach (self::COLOURS as $colour) {
                $n++;
                $variant = $size . '-' . $colour;
                $variants[] = $variant;
                ProductVariant::forceCreate([
                    'product_id' => $product->id,
                    'variant' => $variant,
                    'variation_price' => 100 + $n,
                    'price' => 200 + $n,
                    'stock' => 30 + $n,
                    'low_stock_threshold' => $n,
                    'sku' => sprintf('SKU-%04d', $n),
                    /* The column under test starts at the application's own default literal. */
                    'shipping' => 'same_as_parent',
                    'variation_option' => '',
                    'store_id' => $store->id,
                ]);
            }
        }

        return [$product, $variants];
    }

    /** The edit page's own payload for a variant product, field for field. */
    private function variantSavePayload(Product $product, int $categoryId, array $variants, array $shippingByVariant = []): array
    {
        $attributeIds = array_filter(array_map('intval', explode(',', (string) $product->attribute_id)));

        $payload = [
            'name' => 'Scarf',
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 1,
            'price' => 10,
            'sale_price' => 0,
            'cost_price' => 5,
            'trending' => 0,
            'track_stock' => 1,
            'product_stock' => 7,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
            'attribute_no' => array_values($attributeIds),
            'options_datas' => $variants,
        ];

        $first = array_key_first(array_flip($attributeIds));
        foreach ($attributeIds as $attributeId) {
            $payload['for_variation_' . $attributeId] = 1;
            $payload['visible_attribute_' . $attributeId] = 1;
            $payload['attribute_options_' . $attributeId] = $attributeId === $first ? self::SIZES : self::COLOURS;
        }

        foreach ($variants as $variant) {
            $key = str_replace('.', '_', $variant);
            $payload['product_sku_' . $key] = '-' . $variant;
            $payload['product_weight_' . $key] = '';
            $payload['product_variation_price_' . $key] = '';
            $payload['product_sale_price_' . $key] = '';
            $payload['product_description_' . $key] = '';
            $payload['product_stock_' . $key] = '';
            $payload['low_stock_threshold_' . $key] = '';
            $payload['shipping_id_' . $key] = $shippingByVariant[$variant] ?? '';
        }

        return $payload;
    }

    /** `products.shipping_id` read raw — `Product` is `Cachable`, and this must be the table. */
    private function storedShippingId(int $productId)
    {
        return \DB::table('products')->where('id', $productId)->value('shipping_id');
    }

    /** `product_variants.shipping` read raw, for the same reason. */
    private function storedVariantShipping(int $productId, string $variant)
    {
        return \DB::table('product_variants')
            ->where('product_id', $productId)->where('variant', $variant)->value('shipping');
    }

    // ═════════════════════════════════════════════════════════════════════════════ THE PREMISES

    /**
     * `Shipping` IS NOT SCOPED, measured against the booted container.
     *
     * `getGlobalScopes()` is not the same question as "does it filter" — CLAUDE.md records that
     * asking the first one about `Product` and `Category` is how two real defects survived. Both
     * halves are asserted here so the premise of this whole file is a measurement.
     */
    public function test_shipping_is_not_scoped_so_the_scope_cannot_be_the_check(): void
    {
        $this->assertNotContains(
            Shipping::class,
            config('tenancy.scoped_models'),
            'Shipping has been added to scoped_models. If that is deliberate the guard this file '
            . 'pins may now be redundant — but re-measure before removing it, because '
            . 'null_tenant => "open" still means no predicate when no tenant resolves.'
        );

        $this->assertStringNotContainsString('store_id', Shipping::query()->toSql(),
            'Shipping::query() now carries a store_id predicate, so the premise of this file has '
            . 'changed. getGlobalScopes() answers [StoreScope] either way, which is why this '
            . 'asserts the compiled SQL instead.');
    }

    /** The hostile value really is another tenant's row, and the fixture is not degenerate. */
    public function test_the_hostile_shipping_row_belongs_to_another_store(): void
    {
        $f = $this->twoTenants();

        $this->assertNotSame((int) $f['store']->id, (int) $f['foreign']->store_id,
            'fixture: the "foreign" class belongs to the store under test, so nothing hostile is '
            . 'being driven');
        $this->assertNotNull($f['foreign']->store_id, 'fixture: the foreign class has no store');
        $this->assertNull($f['legacy']->store_id, 'fixture: the legacy class was given a store');
        $this->assertSame((int) $f['store']->id, (int) $f['mine']->store_id);
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE DEFECT

    /** A PARENT product's shipping class cannot be moved to another store's row on UPDATE. */
    public function test_a_parent_product_cannot_take_another_stores_shipping_class_on_update(): void
    {
        $f = $this->twoTenants();
        $product = $this->makeSimpleProduct($f['store'], $f['merchant'], $f['mine']->id);

        $response = $this->actingAs($f['merchant'])->put(route('product.update', $product->id), [
            'name' => 'Scarf',
            'category_id' => $f['category'],
            'status' => 1,
            'variant_product' => 0,
            'price' => 10,
            'sale_price' => 0,
            'cost_price' => 5,
            'trending' => 0,
            'track_stock' => 1,
            'product_stock' => 7,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
            'shipping_id' => $f['foreign']->id,
        ]);

        $this->assertNotSame(404, $response->status(), 'the route does not exist, so the row '
            . 'assertion below would be satisfied by a 404');

        $this->assertNotEquals(
            (int) $f['foreign']->id,
            (int) $this->storedShippingId((int) $product->id),
            'ANOTHER STORE\'S SHIPPING CLASS ID WAS WRITTEN ONTO THIS PRODUCT. `Shipping` is not '
            . 'in scoped_models, so no global scope stands between the posted value and the '
            . 'column, and nothing validated it. Phase 02 ledger F114.'
        );

        $this->assertEquals((int) $f['mine']->id, (int) $this->storedShippingId((int) $product->id),
            'the refusal did not keep the merchant\'s own stored class. It must KEEP the stored '
            . 'value rather than null it: F283 charges +0 for a foreign class and the no-cost '
            . 'bucket for null, so coercing to null would move a price.');
    }

    /** And it cannot be set to another store's row on CREATE either. */
    public function test_a_parent_product_cannot_take_another_stores_shipping_class_on_create(): void
    {
        $f = $this->twoTenants();

        /*
         * THE CREATE PATH REQUIRES BOTH IMAGE FIELDS. The first version of this case omitted them
         * and the validator answered *"The cover image field is required"* — the premise assertion
         * below is what reported that, rather than the case quietly passing on a product that was
         * never created.
         */
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->actingAs($f['merchant'])->post(route('product.store'), [
            'name' => 'NewScarf',
            'category_id' => $f['category'],
            'status' => 1,
            'variant_product' => 0,
            'price' => 10,
            'sale_price' => 0,
            'cost_price' => 5,
            'trending' => 0,
            'track_stock' => 1,
            'product_stock' => 7,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
            'shipping_id' => $f['foreign']->id,
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ]);

        $this->assertNotSame(404, $response->status());

        $created = \DB::table('products')->where('name', 'NewScarf')->first();
        $this->assertNotNull($created,
            'premise: no product was created, so nothing about the shipping column was measured. '
            . 'Body: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertNotEquals((int) $f['foreign']->id, (int) $created->shipping_id,
            'a product was CREATED holding another store\'s shipping class id');
    }

    /**
     * THE LEDGER'S OWN WORDING: a VARIANT's `shipping_id_*`.
     *
     * Driven through the real edit-page payload so the variant writer is actually reached — the
     * rebuild gate needs `attribute_no`, `options_datas` and `for_variation_N`, and a product
     * whose stored flag is `"on"` rather than `"1"` never opens it.
     */
    public function test_a_variant_cannot_take_another_stores_shipping_class(): void
    {
        $f = $this->twoTenants();
        [$product, $variants] = $this->makeVariantProduct($f['store'], $f['merchant']);

        $this->assertSame('same_as_parent', $this->storedVariantShipping((int) $product->id, 'S-Red'),
            'premise: the variant does not start at the default literal, so "it did not change" '
            . 'cannot be told apart from "it was already the hostile value"');

        $payload = $this->variantSavePayload($product, $f['category'], $variants, [
            // the hostile value on one combination, and a legitimate one on another
            'S-Red' => (string) $f['foreign']->id,
            'M-Blue' => (string) $f['mine']->id,
        ]);

        /*
         * AND THE PARENT KEY ON THE SAME REQUEST, which reaches a DIFFERENT writer: `update()`'s
         * parent assignment inside the variant-product branch. The per-site revert measurement
         * showed that site surviving with every test green, because this payload originally sent no
         * `shipping_id` at all and so gave that writer nothing to refuse. Driving both keys in one
         * request is what makes the variant branch's parent write covered.
         */
        $payload['shipping_id'] = $f['foreign']->id;

        $this->assertArrayHasKey('options_datas', $payload, 'premise: the rebuild gate key is absent');

        $response = $this->actingAs($f['merchant'])->put(route('product.update', $product->id), $payload);
        $this->assertNotSame(404, $response->status());
        $this->assertNotSame(500, $response->status(),
            'the save 500d, so "the column did not change" holds either way. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertNotEquals(
            (string) $f['foreign']->id,
            (string) $this->storedVariantShipping((int) $product->id, 'S-Red'),
            'ANOTHER STORE\'S SHIPPING CLASS ID WAS WRITTEN ONTO A VARIANT — the Phase 02 ledger '
            . 'F114 defect, verbatim. `shipping_id_S-Red` went from the request to '
            . '`product_variants.shipping` with no ownership check, and `Shipping` is not in '
            . 'scoped_models so nothing else stood in the way.'
        );

        /*
         * AND THE LEGITIMATE ONE ON THE SAME REQUEST STILL LANDED. Without this the test passes
         * against a writer that stopped writing the column at all, which is not a fix — it is the
         * D-09 family's own recorded failure mode.
         */
        $this->assertEquals(
            (string) $f['mine']->id,
            (string) $this->storedVariantShipping((int) $product->id, 'M-Blue'),
            'the merchant\'s OWN shipping class was not written to the other combination in the '
            . 'same request, so the guard is refusing everything rather than refusing a foreign row'
        );

        /* The parent write in this same branch — a different site from the simple-product one. */
        $this->assertNotEquals(
            (int) $f['foreign']->id,
            (int) $this->storedShippingId((int) $product->id),
            'the variant-product branch of update() wrote another store\'s shipping class to the '
            . 'PARENT product. That is a second writer from the one the simple-product case covers.'
        );
    }

    /**
     * THE VARIANT-PRODUCT *CREATE* PATH — three more writers, all found uncovered by the per-site
     * revert measurement and none of them reachable by any case above.
     *
     * `store()`'s variant branch is a different limb from the simple-product one and has its own
     * parent write, plus the per-combination writer the ledger's wording actually names. Its keys
     * are not the edit page's: it reads `attribute_no`, `for_variation_<id>` and a flat `options`
     * array, and names each variant after the raw option term — so the per-combination key here is
     * `shipping_id_S`, not `shipping_id_S-Red`. (`attribute_options_<id>` is computed into `$name`
     * and then never read, so it is deliberately not sent.)
     *
     * This case carries the HOSTILE value on the parent and on one term, and a LEGITIMATE value on
     * the other term, so refusing-everything is distinguishable from refusing-a-foreign-row.
     */
    public function test_the_variant_create_path_refuses_another_stores_shipping_class(): void
    {
        $f = $this->twoTenants();

        $attributeId = (int) \DB::table('product_attributes')->insertGetId([
            'name' => 'Size',
            'slug' => 'size-' . uniqid(),
            'store_id' => $f['store']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->actingAs($f['merchant'])->post(route('product.store'), [
            'name' => 'VariantScarf',
            'category_id' => $f['category'],
            'status' => 1,
            'variant_product' => 1,
            'price' => 10,
            'sale_price' => 0,
            'cost_price' => 5,
            'trending' => 0,
            'track_stock' => 1,
            'product_stock' => 7,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
            'shipping_id' => $f['foreign']->id,
            'attribute_no' => [$attributeId],
            'for_variation_' . $attributeId => 1,
            /* F84: the visible-attribute key is an unguarded array read, so omitting it 500s. */
            'visible_attribute_' . $attributeId => 1,
            'options' => ['S', 'M'],
            'shipping_id_S' => (string) $f['foreign']->id,
            'shipping_id_M' => (string) $f['mine']->id,
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ]);

        $body = (string) $response->getContent();
        $created = \DB::table('products')->where('name', 'VariantScarf')->first();

        $this->assertNotNull($created,
            'premise: no product was created, so none of the three writers was reached. Body: '
            . substr($body, 0, 300));

        $variants = \DB::table('product_variants')->where('product_id', $created->id)->get();
        $this->assertGreaterThan(0, $variants->count(),
            'premise: no variant rows were written, so the per-combination writer was never '
            . 'reached and the assertion below would hold either way. Body: ' . substr($body, 0, 300));

        $this->assertNotEquals((int) $f['foreign']->id, (int) $created->shipping_id,
            'the variant-CREATE path wrote another store\'s shipping class to the parent product');

        $byVariant = $variants->keyBy('variant');

        $this->assertArrayHasKey('S', $byVariant->all(),
            'premise: the variant named after the posted option term does not exist, so the key '
            . '`shipping_id_S` named nothing');

        $this->assertNotEquals(
            (string) $f['foreign']->id,
            (string) $byVariant['S']->shipping,
            'the variant-CREATE per-combination writer stored another store\'s shipping class id — '
            . 'the Phase 02 ledger F114 column, on the create path'
        );

        $this->assertEquals(
            (string) $f['mine']->id,
            (string) $byVariant['M']->shipping,
            'the merchant\'s OWN class was not written to the other term in the same request, so '
            . 'the guard is refusing everything rather than refusing a foreign row'
        );
    }

    /**
     * WHY ONE OF THE NINE WRITERS HAS NO TEST OF ITS OWN: ITS BRANCH IS UNREACHABLE.
     *
     * The per-site revert measurement (each guarded line restored on its own, suite re-run) showed
     * eight of the nine sites killing at least one case, and one surviving with everything green:
     * `store()`'s parent write inside the `} else {` of `if ($request->cover_image)`. That limb runs
     * only for a create with NO cover image, and `cover_image` is `required` in BOTH of `store()`'s
     * rule sets — so the validator refuses the request before any writer runs and the limb is dead
     * code on this path.
     *
     * This case pins that reachability claim BEHAVIOURALLY rather than by grepping the rules, so it
     * cannot be satisfied by prose, and it is a tripwire: if `cover_image` ever becomes optional,
     * that writer becomes live and needs a case of its own. It is guarded by the same shared helper
     * either way — what is missing is coverage, not the fix.
     */
    public function test_a_create_with_no_cover_image_never_reaches_a_writer(): void
    {
        $f = $this->twoTenants();

        $response = $this->actingAs($f['merchant'])->post(route('product.store'), [
            'name' => 'NoCoverScarf',
            'category_id' => $f['category'],
            'status' => 1,
            'variant_product' => 1,
            'price' => 10,
            'sale_price' => 0,
            'cost_price' => 5,
            'trending' => 0,
            'track_stock' => 1,
            'product_stock' => 7,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
            'shipping_id' => $f['foreign']->id,
            // deliberately no cover_image
        ]);

        $this->assertStringContainsString('cover image', strtolower((string) $response->getContent()),
            'a create with no cover image was NOT refused for the cover image. That makes '
            . "store()'s no-cover-image limb reachable, and its parent shipping write now needs a "
            . 'case of its own: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertNull(\DB::table('products')->where('name', 'NoCoverScarf')->first(),
            'a product was created by a request the validator refused');
    }

    /**
     * THE ADMIN API'S *CREATE* WRITER, which the per-site revert measurement found uncovered.
     *
     * `AddProduct` is a separate site from `UpdateProduct` and its own comment records that it "was
     * not in this census group and is NOT gated here", so it cannot be assumed to behave like its
     * sibling. Driven with a merchant token; the driver token case is on the update sibling.
     */
    public function test_the_admin_api_create_writer_refuses_another_stores_shipping_class(): void
    {
        $f = $this->twoTenants();

        $merchant = $f['merchant'];
        $merchant->current_store = $f['store']->id;
        $merchant->save();

        /* `cover_image` is `required|image` here, so this posts multipart rather than JSON. */
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('flutter')->plainTextToken
        )->post('/api/admin/addproduct', [
            'store_id' => $f['store']->id,
            'name' => 'ApiScarf',
            'category_id' => $f['category'],
            'description' => 'a description',
            'price' => 10,
            'product_stock' => 7,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'shipping_id' => $f['foreign']->id,
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ]);

        $this->assertNotSame(404, $response->status(), 'api/admin/addproduct is not routed');

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('field is required', $body,
            'the request was refused by the VALIDATOR, so the shipping writer was never reached '
            . 'and the assertion below would hold either way. Body: ' . substr($body, 0, 300));

        $created = \DB::table('products')->where('name', 'ApiScarf')->first();
        $this->assertNotNull($created,
            'premise: no product was created, so nothing was measured. Body: ' . substr($body, 0, 300));

        $this->assertNotEquals((int) $f['foreign']->id, (int) $created->shipping_id,
            'the admin API CREATE writer stored another store\'s shipping class id');
    }

    // ═══════════════════════════════════════════════════════════════════ THE CONTROLS: NO LOCKOUT

    /** The merchant's own class is still accepted — the lockout this family has produced before. */
    public function test_the_merchants_own_shipping_class_is_still_accepted(): void
    {
        $f = $this->twoTenants();
        $product = $this->makeSimpleProduct($f['store'], $f['merchant'], null);

        $this->actingAs($f['merchant'])->put(route('product.update', $product->id), [
            'name' => 'Scarf', 'category_id' => $f['category'], 'status' => 1,
            'variant_product' => 0, 'price' => 10, 'sale_price' => 0, 'cost_price' => 5,
            'trending' => 0, 'track_stock' => 1, 'product_stock' => 7,
            'stock_status' => 'in_stock', 'low_stock_threshold' => 0,
            'shipping_id' => $f['mine']->id,
        ]);

        $this->assertEquals((int) $f['mine']->id, (int) $this->storedShippingId((int) $product->id),
            'the guard refused the merchant\'s OWN shipping class. That is a lockout, not a '
            . 'tightening — the same shape FinancePermission recorded for six finance names.');
    }

    /**
     * A LEGACY CLASS WITH NO `store_id` IS STILL ACCEPTED.
     *
     * `shippings.store_id` is nullable and F283's reader allows NULL explicitly, mirroring
     * `CartController::get_shipping_method()`'s P01-COMMERCE-SEC-01 predicate. A writer that
     * refused them would disagree with the reader and lock merchants out of their own legacy data.
     */
    public function test_a_legacy_shipping_class_with_no_store_is_still_accepted(): void
    {
        $f = $this->twoTenants();
        $product = $this->makeSimpleProduct($f['store'], $f['merchant'], null);

        $this->actingAs($f['merchant'])->put(route('product.update', $product->id), [
            'name' => 'Scarf', 'category_id' => $f['category'], 'status' => 1,
            'variant_product' => 0, 'price' => 10, 'sale_price' => 0, 'cost_price' => 5,
            'trending' => 0, 'track_stock' => 1, 'product_stock' => 7,
            'stock_status' => 'in_stock', 'low_stock_threshold' => 0,
            'shipping_id' => $f['legacy']->id,
        ]);

        $this->assertEquals((int) $f['legacy']->id, (int) $this->storedShippingId((int) $product->id),
            'a shipping class whose store_id is NULL was refused. The READER accepts those '
            . '(ApiController::shippingClassOfStore allows orWhereNull), so refusing them here '
            . 'makes the writer disagree with the reader and breaks legacy rows.');
    }

    /**
     * AN ID THAT EXISTS NOWHERE IS STILL WRITTEN — the pricing boundary, in both directions.
     *
     * F283 reads "does not exist anywhere" as the no-cost bucket and "exists but is not this
     * store's" as +0. Those are different charges, so a guard that refused a non-existent id would
     * move a price. This guard asks the OWNERSHIP question only and is silent about existence.
     */
    public function test_an_id_that_exists_nowhere_is_still_written(): void
    {
        $f = $this->twoTenants();
        $product = $this->makeSimpleProduct($f['store'], $f['merchant'], $f['mine']->id);

        $absent = ((int) \DB::table('shippings')->max('id')) + 5000;
        $this->assertFalse(Shipping::where('id', $absent)->exists(), 'fixture: the id exists');

        $this->actingAs($f['merchant'])->put(route('product.update', $product->id), [
            'name' => 'Scarf', 'category_id' => $f['category'], 'status' => 1,
            'variant_product' => 0, 'price' => 10, 'sale_price' => 0, 'cost_price' => 5,
            'trending' => 0, 'track_stock' => 1, 'product_stock' => 7,
            'stock_status' => 'in_stock', 'low_stock_threshold' => 0,
            'shipping_id' => $absent,
        ]);

        $this->assertEquals($absent, (int) $this->storedShippingId((int) $product->id),
            'a shipping id that exists NOWHERE was refused. That is out of scope and it moves a '
            . 'price: F283 charges the no-cost bucket for an unresolvable class and +0 for a '
            . 'foreign one, so turning the first into the second is a pricing change.');
    }

    /**
     * THE OPERATOR TRAP, AND WHY IT CANNOT BITE HERE — MEASURED, NOT ASSUMED.
     *
     * `getCurrentStore()` returns the hardcoded literal **1** for a `super admin` with an empty
     * `current_store` (`helper.php:636`), so a guard keyed on the CALLER's store would refuse every
     * class of every store an operator touches. That is exactly how F113(b) cost the operator's
     * variant cleanup, and why the sibling line twenty rows below the variant writer stamps
     * `$product->store_id` rather than `getCurrentStore()`.
     *
     * **THIS CASE ORIGINALLY ASSERTED THAT THE OPERATOR COULD STILL SET THE CLASS, AND THAT WAS
     * WRONG — the capability does not exist.** Measured on both surfaces: `ProductController::
     * update()` and `Api\DashboardController::UpdateProduct` BOTH refuse the operator first, with
     * *"The platform operator can view a store's catalogue but cannot change its products, prices,
     * stock or variants."* So the operator never reaches a shipping writer and the trap is
     * foreclosed by a gate that runs earlier.
     *
     * The case is kept, inverted, for two reasons. It records WHY the trap does not bite, so the
     * next reader does not re-derive it; and it is a tripwire — if that catalogue rule is ever
     * relaxed, the operator starts reaching these writers, and whoever does that needs to know the
     * shipping guard's store argument is load-bearing at that moment. The guard takes the store from
     * the PRODUCT anyway, so it is already correct for that day.
     */
    public function test_the_operator_is_refused_before_the_shipping_guard_is_ever_asked(): void
    {
        $f = $this->twoTenants();
        $product = $this->makeSimpleProduct($f['store'], $f['merchant'], null);

        \Cache::forget('admin_details');
        $operator = $this->makePlatformAdmin();

        /*
         * THE PREMISE THAT MAKES THIS MEASURE ANYTHING. If the store under test were store 1, then
         * "the product's store" and "the operator's getCurrentStore() fallback" would be ONE number
         * and no case here could tell a product-keyed guard from a caller-keyed one. The decoy
         * tenant in twoTenants() exists for this, and this assertion is what proves it worked — it
         * failed loudly ("1 is not equal to 1") before the decoy was added.
         */
        $this->assertSame(1, (int) getCurrentStore($operator->id),
            'premise: the operator no longer falls back to the literal 1, so the trap this case '
            . 'documents has changed shape and the reasoning needs re-measuring');
        $this->assertNotSame(1, (int) $f['store']->id,
            'premise: the store under test IS store 1, so the operator fallback and the product\'s '
            . 'store are the same number and this case is blind');

        $response = $this->actingAs($operator)->put(route('product.update', $product->id), [
            'name' => 'Scarf', 'category_id' => $f['category'], 'status' => 1,
            'variant_product' => 0, 'price' => 10, 'sale_price' => 0, 'cost_price' => 5,
            'trending' => 0, 'track_stock' => 1, 'product_stock' => 7,
            'stock_status' => 'in_stock', 'low_stock_threshold' => 0,
            'shipping_id' => $f['mine']->id,
        ]);

        /*
         * Asserted on the REASON, not merely on "nothing changed". A refusal with a different cause
         * — including the shipping guard itself — would otherwise read as the same result, which is
         * the "refusal asserted without its reason" failure the skill records.
         */
        $this->assertStringContainsString(
            'cannot change its products',
            (string) $response->getContent(),
            'the operator was NOT refused by the catalogue rule. If they now reach the shipping '
            . 'writer, the guard\'s store argument becomes load-bearing: it must stay '
            . '$product->store_id, because getCurrentStore() answers the literal 1 for an operator '
            . 'and would refuse every class of every store they edit. Body: '
            . substr((string) $response->getContent(), 0, 300)
        );

        $this->assertNull($this->storedShippingId((int) $product->id),
            'the refused operator save still wrote the shipping column');
    }

    // ══════════════════════════════════════════════════════════ THE ADMIN API TWIN, WITH TOKENS

    /**
     * THE SAME DEFECT ON THE ADMIN API, DRIVEN WITH A MERCHANT TOKEN AND A DRIVER TOKEN.
     *
     * `Api\DashboardController::UpdateProduct` writes the same column from the same request key and
     * validated it only as `nullable` — F283's comment names it. It is token-authenticated, so the
     * guard-class rule applies: `custom.auth` logs a token's owner into whichever guard matches its
     * **tokenable class**, so a `DeliveryBoy` token passes the middleware with `auth()->user()`
     * null. A merchant token takes the handler's real path; a driver token must not write at all.
     */
    public function test_the_admin_api_product_writer_refuses_another_stores_shipping_class(): void
    {
        $f = $this->twoTenants();
        $product = $this->makeSimpleProduct($f['store'], $f['merchant'], $f['mine']->id);

        $merchant = $f['merchant'];
        $merchant->current_store = $f['store']->id;
        $merchant->save();

        $driver = \App\Models\DeliveryBoy::forceCreate([
            'name' => 'F114 Driver',
            'email' => 'f114-driver@example.test',
            'password' => \Hash::make('not-a-real-credential'),
            'contact' => '0100000444',
            'type' => 'deliveryboy',
            'store_id' => $f['store']->id,
            'created_by' => $merchant->id,
        ]);

        $principals = [
            'merchant' => $merchant->createToken('flutter')->plainTextToken,
            'driver' => $driver->createToken('flutter')->plainTextToken,
        ];

        foreach ($principals as $label => $bearer) {
            /*
             * EVERY REQUIRED RULE SUPPLIED. The first version of this case sent a short payload and
             * PASSED — vacuously: `UpdateProduct`'s validator answered *"The description field is
             * required"* and the writer was never reached, which is trap 3 in the skill's list
             * ("a precondition was never met, so the handler was never reached"). The premise
             * assertion below is what makes that visible instead of reassuring.
             */
            $response = $this->withHeader('Authorization', 'Bearer ' . $bearer)
                ->postJson('/api/admin/updateproduct', [
                    'product_id' => $product->id,
                    'store_id' => $f['store']->id,
                    'name' => 'Scarf',
                    'category_id' => $f['category'],
                    'description' => 'a description',
                    'price' => 10,
                    'product_stock' => 7,
                    'trending' => 0,
                    'variant_product' => 0,
                    'status' => 1,
                    'shipping_id' => $f['foreign']->id,
                ]);

            $this->assertNotSame(404, $response->status(), 'api/admin/updateproduct is not routed');

            $body = (string) $response->getContent();

            $this->assertStringNotContainsString('field is required', $body, sprintf(
                'the %s request was refused by the VALIDATOR, so the shipping writer was never '
                . 'reached and the assertion below would hold whether or not the guard exists. '
                . 'Body: %s',
                $label,
                substr($body, 0, 300)
            ));

            $this->assertNotEquals(
                (int) $f['foreign']->id,
                (int) $this->storedShippingId((int) $product->id),
                sprintf(
                    'a %s token wrote ANOTHER STORE\'S shipping class id through the admin API. '
                    . 'This is the same defect as the panel writer and the admin API validated the '
                    . 'id only as `nullable`.',
                    $label
                )
            );
        }
    }
}
