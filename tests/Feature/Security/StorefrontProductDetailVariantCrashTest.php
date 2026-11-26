<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * GAP-247 / P3-F118 — `product-detail` ANSWERED 500 FOR A VARIANT PRODUCT WITH NO ATTRIBUTE JSON.
 * =============================================================================================
 *
 *     if ($product['variant_product'] == 1) {
 *         if (!empty($product['product_attribute'])) {
 *             ...                       // $option is assigned ONLY in here
 *         }
 *         $data['variant'] = $option;   // ... and read out here, unconditionally
 *     }
 *
 * `ApiController:5900` reads `$option`; it is assigned only inside the `!empty($product_attribute)`
 * branch at `:5867-5899`. So a product flagged as a variant product whose `product_attribute` is
 * empty or null leaves `$option` undefined, Laravel turns the warning into an `ErrorException`, and
 * the shopper gets an HTTP 500 on an ordinary product page.
 *
 * ITS GUEST TWIN IS IMMUNE, and that is where the fix comes from rather than from invention:
 * `product_detail_guest` initialises `$data['variant'] = []` at `:6000`, so the same data shape
 * renders an empty variant list instead of crashing. This change makes the authenticated twin do
 * the same, which is the smaller of the two possible fixes — the alternative, moving the assignment
 * inside the branch, would change the payload's SHAPE for every variant product by dropping the
 * `variant` key, and a shipped client reading `data.variant` would then see a missing key rather
 * than an empty list.
 *
 * ---------------------------------------------------------------------------------------------
 * REACHABILITY, STATED HONESTLY BECAUSE IT IS WHY THIS WAS A ROW AND NOT AN EMERGENCY.
 *
 * The merchant panel always writes `product_attribute` when it writes `variant_product = 1`
 * (`ProductController:848`, `:1100`, `:1168`, `:2363`), so reaching this state needs a product
 * created by the importer, by the API, or by a partial write — not by the ordinary panel path. No
 * fixture in the suite set `variant_product => 1` on this path before this file, which is exactly
 * why 12,040 Feature tests never drove it. **The fixture is the substance of this change**: the
 * one-line fix is obvious, and the reason the defect survived is that nothing built the row.
 *
 * Found by the independent review of the GAP-233/234/235 fix. One thing it corrected and this file
 * records so nobody "fixes" the wrong line: `:5870`'s property read on an array is NOT a crash —
 * `empty()` suppresses it.
 */
class StorefrontProductDetailVariantCrashTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function variantProductWithNoAttribute(Store $store, ?string $attribute): Product
    {
        return Product::forceCreate([
            'name' => 'F118-VARIANT-' . uniqid(), 'slug' => 'f118-' . uniqid(),
            'description' => 'x', 'detail' => 'x',
            'product_stock' => 5, 'track_stock' => 1,
            /* THE WHOLE POINT: flagged as a variant product, with no attribute JSON. */
            'variant_product' => 1,
            'product_attribute' => $attribute,
            'stock_status' => 'in_stock', 'trending' => 0, 'status' => 1,
            'price' => 100, 'sale_price' => 100,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function shopperOf(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'F118', 'last_name' => 'Shopper',
            'email' => 'f118-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /** @return array<string,array{0:?string}> the two empty spellings the column really holds */
    public static function emptyAttributes(): array
    {
        return ['null' => [null], 'empty string' => ['']];
    }

    /**
     * THE DEFECT, on the authenticated route, for both spellings of "empty".
     *
     * @param ?string $attribute
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyAttributes')]
    public function test_a_variant_product_with_no_attribute_json_does_not_crash(?string $attribute): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $product = $this->variantProductWithNoAttribute($store, $attribute);
        $shopper = $this->shopperOf($store);

        /* Premise: the row really is in the state this test is about. Without this the case could
         * pass against a fixture the model silently defaulted to something else. */
        $this->assertSame(1, (int) Product::withoutGlobalScopes()->where('id', $product->id)->value('variant_product'),
            'premise: the fixture is not flagged as a variant product');
        $this->assertEmpty(Product::withoutGlobalScopes()->where('id', $product->id)->value('product_attribute'),
            'premise: the fixture carries attribute JSON, so this is not the state under test');

        $bearer = $shopper->createToken('app')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->postJson('/api/' . $store->slug . '/product-detail', ['id' => $product->id]);

        $this->assertNotSame(500, $response->status(),
            'product-detail answered HTTP 500 for a variant product whose product_attribute is '
            . 'empty. `$option` is assigned only inside the `!empty($product_attribute)` branch '
            . '(ApiController:5867-5899) and read unconditionally at :5900, so an unset column '
            . 'leaves it undefined and Laravel raises an ErrorException. The guest twin '
            . 'initialises `$data[\'variant\'] = []` at :6000 and is immune.');
    }

    /** AND THE GUEST TWIN STAYS IMMUNE — the control that proves the two are now consistent rather
     *  than the authenticated one having merely been made to match a broken sibling. */
    public function test_the_guest_twin_is_also_fine_with_the_same_row(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $product = $this->variantProductWithNoAttribute($store, null);
        $shopper = $this->shopperOf($store);

        $bearer = $shopper->createToken('app')->plainTextToken;

        $this->assertNotSame(500, $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->postJson('/api/' . $store->slug . '/product-detail-guest', ['id' => $product->id])
            ->status(), 'the guest twin crashed too, so the shared data shape is the problem');
    }

    /**
     * CONTROL: a NON-variant product still answers, so the fix did not disturb the ordinary path.
     *
     * THE CONTROL I FIRST WROTE WAS A VARIANT PRODUCT *WITH* ATTRIBUTE JSON, AND IT FAILED BEFORE
     * THE FIX — which would have made this file exactly the thing a review caught earlier the same
     * day: a control that fails pre-fix is as misleading as a test that cannot fail post-fix.
     *
     * It failed for a FIXTURE reason, not a code one, and the probe is worth recording. My JSON was
     * `{"Size":["S","M"]}`; the real column holds a LIST OF OBJECTS, each
     * `{"attribute_id": <id>, "values": [...], "visible_attribute_<id>": ..., "for_variation_<id>": ...}`
     * (built at `ProductController:712-739`, written at `:849`). `foreach (json_decode(...) as $value)`
     * over my object therefore yielded an ARRAY, and `$value->attribute_id` at `ApiController:5875`
     * raised *"Attempt to read property \"attribute_id\" on array"*. I briefly read that as a
     * second live defect — every variant product 500ing — and it is not: `$product` really is a
     * plain array from `:5821`'s `toArray()`, but `$value` comes from the JSON, not from
     * `$product`.
     *
     * A FULLY-POPULATED VARIANT FIXTURE IS DELIBERATELY NOT BUILT HERE. It needs a real
     * `product_attributes` row (so `Utility::ProductAttribute($value->attribute_id)` resolves and
     * `json_decode($p_variant)->id` is non-null), matching `ProductAttributeOption` rows, and a
     * `DefaultVariantData` relation. That is a fixture worth having — it would make the whole
     * variant branch suite-reachable for the first time — but it is its own piece of work, not a
     * control for a one-line initialisation, and building it badly would re-create the problem
     * above. Recorded so the next person has the shape and the reason rather than rediscovering
     * both.
     */
    public function test_a_non_variant_product_still_answers(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $shopper = $this->shopperOf($store);

        $plain = Product::forceCreate([
            'name' => 'F118-PLAIN-' . uniqid(), 'slug' => 'f118-plain-' . uniqid(),
            'description' => 'x', 'detail' => 'x',
            'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0,
            'product_attribute' => null, 'stock_status' => 'in_stock', 'trending' => 0,
            'status' => 1, 'price' => 100, 'sale_price' => 100,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $shopper->createToken('app')->plainTextToken)
            ->postJson('/api/' . $store->slug . '/product-detail', ['id' => $plain->id]);

        $this->assertNotSame(500, $response->status(),
            'an ORDINARY non-variant product now crashes, so the initialisation broke the common path');
        $this->assertSame(1, (int) $response->json('status'),
            'an ordinary product stopped being served');
    }
}
