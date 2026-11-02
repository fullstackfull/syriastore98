<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F168 — saving a product resolved its posted attribute TERMS to option ids with no tenancy.
 * ==========================================================================================
 *
 * `ProductController::update()` turns the terms its form posts back into option ids, twice
 * (:2056 and :2149, the variant and non-variant arms), and both did it with
 *
 *     ProductAttributeOption::where('terms', $fValue)->first()
 *
 * `ProductAttributeOption` registers no `StoreScope` and is not in `config/tenancy.php`'s
 * `scoped_models`, so that predicate is the whole predicate: `first()` returns whichever row in
 * the WHOLE TABLE matches the term, and with no `orderBy` that is in practice the lowest id —
 * the OLDEST option. The resolved ids are then persisted, as
 * `$product->product_attribute = json_encode($input['attribute_options'])`.
 *
 * SO THE OLDER STORE WINS, WHICH MAKES THIS NEAR-CERTAIN RATHER THAN THEORETICAL. Attribute terms
 * are generic by nature — `Red`, `M`, `Natural`, `Large` — so two stores sharing one is the normal
 * case, not a collision. Whichever store created its option first owns the id every other store's
 * products get written with.
 *
 * WHAT IT COSTS, and it is not invisible: the edit screen reads those ids straight back as
 * `ProductAttributeOption::whereIn('id', $idsArray)->pluck('terms')` (edit.blade.php:544-550). So
 * while the two terms agree the page looks right — and then the other tenant renames their option
 * and this merchant's product silently displays the other store's word, or deletes it and the
 * attribute vanishes from this product's form altogether. A tenant's catalogue structure ends up
 * depending on a row in another tenant's catalogue.
 *
 * The sibling `attribute_combination()` already carries the store predicate for this exact model,
 * added as P1-INV-10 (c); these two sites were missed because they resolve in the other direction
 * — term to id rather than id to term.
 *
 * THE PREDICATE IS THE PRODUCT'S OWN STORE. `update()` has already proved the caller owns the
 * product (P1-INV-01), and the options linked to a product must be its own store's options.
 * `getCurrentStore()` would be wrong here for the reason F157 recorded: it hands a `super admin`
 * with an empty `current_store` the hardcoded literal `1`, so an operator editing another store's
 * product would resolve against store 1.
 *
 * ASSERTIONS READ THE RAW COLUMN. `Product` is not in `scoped_models`, but a raw read is also
 * immune to the model cache, and "the right id was stored" is exactly the kind of claim this
 * project has had pass for the wrong reason.
 */
class AttributeTermResolutionTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

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

    /** @return array{0:int,1:int} the attribute id and the option id, both in $storeId */
    private function attributeWithTerm(int $storeId, string $term): array
    {
        $attributeId = (int) \DB::table('product_attributes')->insertGetId([
            'name' => 'Colour',
            'slug' => 'colour-' . uniqid(),
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $optionId = (int) \DB::table('product_attribute_options')->insertGetId([
            'attribute_id' => $attributeId,
            'terms' => $term,
            'order' => 0,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$attributeId, $optionId];
    }

    /**
     * The option ids a product's `product_attribute` column actually holds.
     *
     * SHARED, because the second test used to assert over the raw JSON string with
     * `assertStringNotContainsString` and that was wrong twice over: one of its two needles
     * (`'|' . $id . '|'`) can never match, since `implode('|', ...)` only puts an id BETWEEN
     * pipes with three or more ids and the test posts one term; and the other (`'"' . $id . '"'`)
     * matched whatever else the JSON happened to contain — with a browser-shaped payload, where
     * every value is a string, `"1"` matches the `visible_attribute_N` value and the test fails
     * for a reason that has nothing to do with tenancy.
     */
    private function storedOptionIds(int $productId): array
    {
        $stored = (string) \DB::table('products')->where('id', $productId)->value('product_attribute');

        $this->assertNotSame('', $stored, 'premise: the save wrote the attribute column');

        $decoded = json_decode($stored, true);
        $this->assertIsArray($decoded, 'premise: the column holds JSON');

        $ids = [];
        foreach ($decoded as $entry) {
            foreach ((array) ($entry['values'] ?? []) as $group) {
                foreach (explode('|', (string) $group) as $id) {
                    if ($id !== '') {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        return $ids;
    }

    public function test_a_posted_term_resolves_to_the_products_own_stores_option(): void
    {
        /*
         * THE FOREIGN OPTION IS CREATED FIRST, DELIBERATELY. Its id is therefore lower, which is
         * what `->first()` with no ordering returns — so this fixture reproduces the production
         * case (an older store) rather than relying on which row the engine happens to pick.
         */
        [, $otherStore] = $this->makeTenant();
        [, $foreignOptionId] = $this->attributeWithTerm($otherStore->id, 'Red');

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        [$ownAttributeId, $ownOptionId] = $this->attributeWithTerm($store->id, 'Red');

        $this->assertLessThan(
            $ownOptionId,
            $foreignOptionId,
            'premise: the foreign option is the older row, so an unordered first() prefers it'
        );

        $categoryId = $this->makeCategory($store->id);

        $product = Product::forceCreate([
            'name' => 'Scarf',
            'slug' => 'scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 0,
            'attribute_id' => (string) $ownAttributeId,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $response = $this->actingAs($merchant)->put('/product/' . $product->id, [
            'name' => 'Scarf',
            'category_id' => $categoryId,
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
            'attribute_no' => [$ownAttributeId],
            'for_variation_' . $ownAttributeId => 0,
            'visible_attribute_' . $ownAttributeId => 1,
            /* TERMS, which is what the edit form posts -- its <option> carries no value
               attribute, so the browser submits the option's text. See F166. */
            'attribute_options_' . $ownAttributeId => ['Red'],
        ]);

        $this->assertNotSame(500, $response->status(),
            'premise: the save completed at all. Body: ' . substr((string) $response->getContent(), 0, 300));

        $ids = $this->storedOptionIds((int) $product->id);

        $this->assertContains($ownOptionId, $ids,
            "the merchant's own 'Red' option was not the one stored");
        $this->assertNotContains($foreignOptionId, $ids,
            "another store's option id was written into this product's attributes");
    }

    /**
     * THE VARIANT ARM, which carries the identical predicate and was untested.
     *
     * `update()` resolves posted terms to option ids in TWO places — once for a non-variant
     * product and once for a variant one — and the F168 fix had to be applied to both. Only the
     * non-variant arm was driven, so reverting the variant arm alone left the suite green. The
     * two blocks are byte-identical apart from their surroundings, which is exactly the shape
     * that gets half-fixed.
     */
    public function test_the_variant_arm_resolves_within_the_products_own_store_too(): void
    {
        [, $otherStore] = $this->makeTenant();
        [, $foreignOptionId] = $this->attributeWithTerm($otherStore->id, 'Red');

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        [$ownAttributeId, $ownOptionId] = $this->attributeWithTerm($store->id, 'Red');

        $this->assertLessThan($ownOptionId, $foreignOptionId,
            'premise: the foreign option is the older row, so an unordered first() prefers it');

        $categoryId = $this->makeCategory($store->id);

        $product = \App\Models\Product::forceCreate([
            'name' => 'Scarf',
            'slug' => 'scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 1,
            'attribute_id' => (string) $ownAttributeId,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $response = $this->actingAs($merchant)->put('/product/' . $product->id, [
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
            'attribute_no' => [$ownAttributeId],
            'for_variation_' . $ownAttributeId => 1,
            'visible_attribute_' . $ownAttributeId => 1,
            'attribute_options_' . $ownAttributeId => ['Red'],
            /* The variant arm's own gate key — see ProductVariantOrdinarySaveGuardTest. */
            'options_datas' => ['Red'],
            'product_variation_price_Red' => 10,
            'product_sale_price_Red' => 9,
            'product_stock_Red' => 3,
            'product_sku_Red' => 'Scarf-Red',
        ]);

        $this->assertNotSame(500, $response->status(),
            'premise: the save completed at all. Body: ' . substr((string) $response->getContent(), 0, 300));

        $ids = $this->storedOptionIds((int) $product->id);

        $this->assertContains($ownOptionId, $ids,
            "the variant arm did not store the merchant's own 'Red' option");
        $this->assertNotContains($foreignOptionId, $ids,
            "the variant arm wrote another store's option id into this product");
    }

    public function test_a_term_that_exists_only_in_another_store_resolves_to_nothing(): void
    {
        /*
         * The negative control, and the reason it is not a refusal: a term the merchant's own
         * store does not have must contribute NO id rather than borrowing the foreign one. The
         * save still succeeds — this is the merchant's own product and the rest of the payload is
         * theirs — it simply records no option for that attribute, which is the same shape the
         * handler already produced for any term that failed to resolve.
         */
        [, $otherStore] = $this->makeTenant();
        [, $foreignOptionId] = $this->attributeWithTerm($otherStore->id, 'Ultramarine');

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        [$ownAttributeId] = $this->attributeWithTerm($store->id, 'Red');

        $categoryId = $this->makeCategory($store->id);

        $product = Product::forceCreate([
            'name' => 'Scarf',
            'slug' => 'scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 0,
            'attribute_id' => (string) $ownAttributeId,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $response = $this->actingAs($merchant)->put('/product/' . $product->id, [
            'name' => 'Scarf',
            'category_id' => $categoryId,
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
            'attribute_no' => [$ownAttributeId],
            'for_variation_' . $ownAttributeId => 0,
            'visible_attribute_' . $ownAttributeId => 1,
            'attribute_options_' . $ownAttributeId => ['Ultramarine'],
        ]);

        /*
         * THE PREMISE, WHICH THIS TEST DID NOT HAVE AND NEEDED MOST. It asserts an ABSENCE, so
         * anything that stops the request reaching the resolution loop satisfies it for free —
         * and `update()` has eleven early returns between its permission gate and that loop
         * (validation, the concurrent-edit conflict, the plan storage limit, seven upload
         * failures), most of which answer HTTP 200 with an error payload rather than a redirect.
         * Driven with the fix reverted AND a validation failure injected, the old version of this
         * test still passed: it was asserting a prohibition over an empty string.
         */
        $this->assertNotSame(500, $response->status(),
            'premise: the save completed at all. Body: ' . substr((string) $response->getContent(), 0, 300));

        $ids = $this->storedOptionIds((int) $product->id);

        $this->assertNotContains(
            $foreignOptionId,
            $ids,
            "another store's option id was borrowed for a term this store does not have"
        );
    }
}
