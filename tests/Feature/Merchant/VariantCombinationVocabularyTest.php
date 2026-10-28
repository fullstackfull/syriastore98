<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * THE TWO COMBINATION ENDPOINTS TAKE THE SAME PARAMETER NAME WITH DIFFERENT VOCABULARIES.
 * =======================================================================================
 *
 * `attribute_options_N[]` carries option **IDS** to `products.attribute_combination` (create) and
 * option **TERMS** to `products.attribute_combination/edit` (edit). Each handler matches its own
 * page and both are correct; what was missing was anything saying so.
 *
 * THIS FILE EXISTS BECAUSE THE ASYMMETRY LOOKS LIKE A BUG AND I "FIXED" IT. Reading the two
 * handlers side by side, the edit one appears to push raw ids into the variant key while the
 * create one resolves them — so I made the edit one resolve too, and measured a browser probe
 * rendering `options_datas[] = "2-4"` with price 0 and stock 0 against a stored `M-Natural`
 * holding 43 and 6. That measurement was real and the conclusion was wrong: the probe was POSTING
 * NUMERIC IDS, which is the create page's shape, to the edit endpoint. The real edit form posts
 * terms, and the old code was right. `MerchantProductOwnershipTest`'s positive control caught it.
 *
 * So the vocabularies are pinned here from BOTH ends — the pages that produce them and the
 * handlers that consume them — because a wrong fix here silently empties a merchant's variant
 * table, and an empty table is not an error anybody sees.
 */
class VariantCombinationVocabularyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /*
     * COMMENTS STRIPPED BEFORE ANY SOURCE ASSERTION — the fifth instance of this project's
     * recurring bite, and this file was the one that proved it still bites. Driven as an
     * experiment: deleting `value="{{ $key }}"` from the create page and changing
     * `pluck('terms', 'id')` to `pluck('terms')`, while leaving `{{-- was: ... --}}` and `//`
     * comments quoting both removed lines, left both scanners below GREEN. They were reading the
     * explanation of the code rather than the code.
     */
    use StripsSourceComments;

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

    /** @return array{merchant:\App\Models\User,store:\App\Models\Store,product:Product,sizeId:int,colourId:int} */
    private function arrange(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');

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
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $sizeAttr = (int) \DB::table('product_attributes')->insertGetId([
            'name' => 'Size', 'slug' => 'size-' . uniqid(), 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $colourAttr = (int) \DB::table('product_attributes')->insertGetId([
            'name' => 'Colour', 'slug' => 'colour-' . uniqid(), 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sizeId = (int) \DB::table('product_attribute_options')->insertGetId([
            'attribute_id' => $sizeAttr, 'terms' => 'M', 'order' => 0,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $colourId = (int) \DB::table('product_attribute_options')->insertGetId([
            'attribute_id' => $colourAttr, 'terms' => 'Natural', 'order' => 0,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('merchant', 'store', 'product', 'sizeId', 'colourId');
    }

    private function payload(array $a, array $options, bool $withId = true): array
    {
        $body = ['sku' => 'Scarf', 'price' => 100];

        if ($withId) {
            $body['id'] = $a['product']->id;
        }

        $body['attribute_no'] = array_keys($options);

        foreach ($options as $no => $values) {
            $body['for_variation_' . $no] = 1;
            $body['attribute_options_' . $no] = $values;
            $body['options_' . $no] = $values;
        }

        return $body;
    }

    // =========================================================================================
    // The premise: each page's select decides what its handler receives
    // =========================================================================================

    public function test_the_edit_pages_option_elements_carry_no_value_so_they_submit_their_text(): void
    {
        $source = $this->sourceWithoutComments('resources/views/product/edit.blade.php');

        $this->assertStringContainsString(
            'name="attribute_options_{{ $choice_option->attribute_id }}[]"',
            $source,
            'the edit page no longer posts this parameter at all'
        );

        /*
         * `<option @if (in_array($f, $get_datas)) selected @endif>{{ $f }}</option>` — no `value`,
         * so the browser submits the TEXT. Adding a `value` here would change what the handler
         * receives without touching the handler, which is why this is asserted on the page.
         */
        $this->assertMatchesRegularExpression(
            '/<option\s+@if\s*\(in_array\(\$f, \$get_datas\)\)\s*selected\s*@endif>/',
            $source,
            'the edit page\'s option now carries a value attribute, so attribute_combination_edit() '
            . 'receives something other than the term it treats the value as'
        );

        $this->assertMatchesRegularExpression(
            "/\\\$option = \\\\App\\\\Models\\\\ProductAttributeOption::where\(\s*'attribute_id',/",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/->pluck\('terms'\)\s*->toArray\(\);\s*\\\$attribute_id/s",
            $source,
            'the edit page\'s option text is no longer the option TERM'
        );
    }

    public function test_the_create_pages_options_carry_the_option_id(): void
    {
        $source = $this->sourceWithoutComments('resources/views/product/create.blade.php');

        /*
         * The create page builds its options in JavaScript from `attribute_option()`'s response,
         * which is `pluck('terms', 'id')` — so `key` is the ID and `value` is the term.
         */
        $this->assertStringContainsString(
            '\'<option class="option-item" value="\' + key + \'">\'',
            $source,
            'the create page no longer posts option ids, so attribute_combination() must change too'
        );

        $controller = $this->sourceWithoutComments('app/Http/Controllers/ProductController.php');

        $this->assertStringContainsString(
            "->get()->pluck('terms', 'id')->toArray()",
            $controller,
            'attribute_option() no longer keys its response by id'
        );
    }

    // =========================================================================================
    // The handlers, each driven in the shape its own page sends
    // =========================================================================================

    public function test_the_edit_endpoint_keys_variants_by_the_terms_it_is_sent(): void
    {
        $a = $this->arrange();

        ProductVariant::forceCreate([
            'product_id' => $a['product']->id,
            'variant' => 'M-Natural',
            'sku' => 'Scarf-M-Natural',
            'price' => 43,
            'variation_price' => 41,
            'stock' => 6,
            'store_id' => $a['store']->id,
        ]);

        /* TERMS, which is what the edit form posts. */
        $html = $this->actingAs($a['merchant'])->put(
            route('products.attribute_combination_edit'),
            $this->payload($a, [1 => ['M'], 2 => ['Natural']])
        )->assertOk()->getContent();

        $this->assertStringContainsString('value="M-Natural"', $html);

        /*
         * AND THE STORED VARIANT'S MONEY AND STOCK COME BACK. `$product->ProductVariant($str)` is
         * `where('variant', $str)` exactly (app/Models/Product.php:142), so if the key ever stops
         * matching the stored `variant`, the merchant is shown zeros for a variant that holds
         * real values — and a save from that screen writes the zeros.
         */
        $this->assertMatchesRegularExpression(
            '/name="product_sale_price_M-Natural"[^>]*value="43(\.0+)?"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="product_stock_M-Natural"[^>]*value="6"/',
            $html
        );
    }

    public function test_the_edit_endpoint_offers_the_server_side_delete_for_a_stored_variant(): void
    {
        $a = $this->arrange();

        $variant = ProductVariant::forceCreate([
            'product_id' => $a['product']->id,
            'variant' => 'M-Natural',
            'sku' => 'Scarf-M-Natural',
            'price' => 43,
            'stock' => 6,
            'store_id' => $a['store']->id,
        ]);

        $html = $this->actingAs($a['merchant'])->put(
            route('products.attribute_combination_edit'),
            $this->payload($a, [1 => ['M'], 2 => ['Natural']])
        )->assertOk()->getContent();

        /*
         * The second consequence of a key that misses: `$ProductVariantData` is null, so the view
         * takes its `@else` branch and offers only the client-side `.delete_option` — the real
         * variant row cannot be deleted from that screen at all.
         */
        $this->assertStringContainsString(route('product.attribute.delete', $variant->id), $html);
    }

    public function test_the_create_endpoint_keys_variants_by_the_terms_of_the_ids_it_is_sent(): void
    {
        $a = $this->arrange();

        /* IDS, which is what the create page's JavaScript posts. */
        $html = $this->actingAs($a['merchant'])->post(
            route('products.attribute_combination'),
            $this->payload($a, [1 => [$a['sizeId']], 2 => [$a['colourId']]], false)
        )->assertOk()->getContent();

        $this->assertStringContainsString('value="M-Natural"', $html);
        $this->assertStringNotContainsString(
            'value="' . $a['sizeId'] . '-' . $a['colourId'] . '"',
            $html,
            'the create endpoint must resolve ids to terms, not key variants by the raw ids'
        );
    }

    // =========================================================================================
    // F165
    // =========================================================================================

    public function test_a_missing_product_is_refused_rather_than_raising_a_500(): void
    {
        $this->arrange();

        /*
         * F165. `productIsOutsideCurrentStore()` returns false for the platform operator BEFORE
         * its own `empty($product)` test, so a non-existent id reached the view with
         * `$product === null` and the view's `$product->ProductVariant($str)` raised
         * "Call to a member function on null" — a 500 rather than a refusal. The refusal shape is
         * the same as the ownership one, so a missing id and a foreign id stay indistinguishable.
         */
        $operator = $this->makePlatformAdmin();

        $missing = $this->actingAs($operator)->put(route('products.attribute_combination_edit'), [
            'id' => 999999,
            'sku' => 'Scarf',
            'price' => 100,
            'attribute_no' => [1],
            'for_variation_1' => 1,
            'attribute_options_1' => ['M'],
            'options_1' => ['M'],
        ]);

        $missing->assertRedirect();
        $missing->assertSessionHas('error');

        /*
         * AND THE POSITIVE CONTROL, because a bare `assertRedirect()` is satisfied by ANY
         * refusal — a permission denial, a middleware bounce, a route that stopped existing. The
         * same operator asking for a product that DOES exist must get a 200, or this test is
         * measuring something other than the missing-product guard.
         */
        $a = $this->arrange();

        $this->actingAs($operator)->put(
            route('products.attribute_combination_edit'),
            $this->payload($a, [1 => ['M'], 2 => ['Natural']])
        )->assertOk();
    }
}
