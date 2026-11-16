<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A VARIANT PRODUCT'S OPTIONS MUST BE OFFERED TO THE SHOPPER, WHICHEVER FORM SAVED THEM. P4-F119.
 * ==============================================================================================
 *
 * `products.product_attribute` holds a `values` array per attribute, and the two merchant forms put
 * DIFFERENT THINGS IN IT:
 *
 *   CREATE posts option IDs      `resources/views/product/create.blade.php:861` --
 *                                `<option class="option-item" value="' + key + '">`
 *   EDIT posts TERMS             `resources/views/product/edit.blade.php:689` --
 *                                `<option @if(...) selected @endif>{{ $f }}</option>`, no `value`
 *
 * The storefront partial resolved them with an ID lookup, so a product that had ever been saved
 * through the edit form rendered its variant selectors EMPTY.
 *
 * AND AN EMPTY SELECTOR IS AN UNSELLABLE PRODUCT. `front-theme.js:1237` serialises `.variant_form`
 * on change and writes the `variant_id` the server returns onto the add-to-cart button; with
 * nothing to select, `Cart::AddToCart` refuses at `app/Models/Cart.php:158-160` with "Please Select
 * a variant in a product." Nothing in the merchant UI reports that the product stopped selling.
 *
 * MEASURED on the running dev server before the fix, flushing the Cachable product cache between
 * reads (without that flush the first probe read a stale row and said ids did not work either --
 * the trap CLAUDE.md names):
 *
 *     values = ["1","2","3","4"]       Size -> [Select an option, S, M, L, M/L]
 *     values = ["S","M","L","M/L"]     Size -> [Select an option]
 *
 * WHY THESE TESTS ASSERT THE RENDERED PAGE. Whether an option is offered is a property of the
 * document, not of a Blade line: the same template renders differently for the two stored shapes,
 * which is the whole defect. A source-literal scanner cannot see it, and this repository has taken
 * a false reading from one five times (see `StripsSourceComments`).
 */
class VariantOptionsRenderTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $other;
    private Product $product;
    private int $sizeAttributeId;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = User::forceCreate([
            'name' => 'Variant Merchant', 'email' => 'variant-merchant@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->store = Store::create([
            'name' => 'Variant Store', 'email' => 'variant-store@example.test',
            'slug' => 'variant-store', 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->other = Store::create([
            'name' => 'Other Store', 'email' => 'other-store@example.test',
            'slug' => 'other-variant-store', 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $this->store->id;
        $merchant->save();

        $this->sizeAttributeId = (int) DB::table('product_attributes')->insertGetId([
            'name' => 'Size', 'slug' => 'size', 'store_id' => $this->store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['S' => 0, 'M' => 1, 'L' => 2] as $term => $order) {
            DB::table('product_attribute_options')->insert([
                'attribute_id' => $this->sizeAttributeId, 'terms' => $term, 'order' => $order,
                'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->product = Product::forceCreate([
            'name' => 'Scarf', 'slug' => 'scarf', 'variant_product' => 1,
            'price' => 40, 'sale_price' => 40, 'product_stock' => 10, 'track_stock' => 1,
            'status' => 1, 'stock_status' => 'in_stock', 'description' => 'A scarf.',
            'store_id' => $this->store->id, 'created_by' => $merchant->id,
        ]);

        foreach (['S', 'M', 'L'] as $term) {
            DB::table('product_variants')->insert([
                'product_id' => $this->product->id, 'variant' => $term,
                'sku' => 'SCARF-' . $term, 'price' => 40, 'stock' => 5,
                'store_id' => $this->store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param  list<string>  $values  whatever the merchant form stored in `values` */
    private function storeAttributeValues(array $values): void
    {
        $this->product->product_attribute = json_encode([[
            'attribute_id' => (string) $this->sizeAttributeId,
            'values' => $values,
            'visible_attribute_' . $this->sizeAttributeId => 1,
            'for_variation_' . $this->sizeAttributeId => 1,
        ]]);
        // Through Eloquent, so the Cachable flush happens: a raw DB::table() update leaves the
        // page reading the pre-write row.
        $this->product->save();
    }

    /** @return list<string> the option texts the page offers for the Size selector */
    private function offeredOptions(): array
    {
        $response = $this->get('/' . $this->store->slug . '/product/' . $this->product->slug);

        $this->assertSame(200, $response->getStatusCode(),
            'Premise: the product page rendered. Status ' . $response->getStatusCode() . '.');

        $html = $response->getContent();

        if (! preg_match('/<select[^>]*name="varint\[Size\]"(.*?)<\/select>/s', $html, $m)) {
            return [];
        }

        preg_match_all('/<option[^>]*>(.*?)<\/option>/s', $m[1], $options);

        return array_values(array_filter(
            array_map('trim', $options[1]),
            fn ($text) => $text !== '' && $text !== 'Select an option'
        ));
    }

    /**
     * THE PREMISE AND THE GUARD IN ONE: the create-form shape worked before this change and must
     * keep working after it.
     */
    #[Test]
    public function test_options_saved_as_ids_are_offered(): void
    {
        $ids = DB::table('product_attribute_options')
            ->where('attribute_id', $this->sizeAttributeId)
            ->orderBy('order')->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->storeAttributeValues($ids);

        $this->assertSame(['S', 'M', 'L'], $this->offeredOptions(),
            'The create-form shape (option ids) no longer renders its options, so the fix has '
            . 'broken the case that used to work.');
    }

    /**
     * THE DEFECT. The edit form posts terms, and the storefront looked them up by id.
     */
    #[Test]
    public function test_options_saved_as_terms_are_offered(): void
    {
        $this->storeAttributeValues(['S', 'M', 'L']);

        $this->assertSame(['S', 'M', 'L'], $this->offeredOptions(),
            'A variant product whose attribute values were saved by the EDIT form offers no '
            . 'options at all, so the shopper cannot choose a variant and add-to-cart refuses '
            . 'with "Please Select a variant in a product." The product has silently stopped '
            . 'selling.');
    }

    /**
     * THE SHAPE `ProductController` ACTUALLY WRITES: `explode(',', implode('|', $posted))`, so a
     * single stored element can carry several tokens joined by `|`.
     */
    #[Test]
    public function test_a_pipe_joined_group_is_split_into_its_options(): void
    {
        $this->storeAttributeValues(['S|M', 'L']);

        $this->assertSame(['S', 'M', 'L'], $this->offeredOptions(),
            'The `|`-joined group ProductController stores was treated as one token.');
    }

    /**
     * TENANCY. `ProductAttributeOption` has a `store_id` and is NOT in `config/tenancy.php`'s
     * `scoped_models`, so nothing underneath adds a predicate: a term lookup without one would
     * offer another tenant's option on this store's product page.
     */
    #[Test]
    public function test_another_stores_option_with_the_same_term_is_not_offered(): void
    {
        $foreignAttributeId = (int) DB::table('product_attributes')->insertGetId([
            'name' => 'Size', 'slug' => 'size-foreign', 'store_id' => $this->other->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_attribute_options')->insert([
            'attribute_id' => $foreignAttributeId, 'terms' => 'XXL', 'order' => 0,
            'store_id' => $this->other->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->storeAttributeValues(['S', 'XXL']);

        $this->assertSame(['S'], $this->offeredOptions(),
            "Another store's option term was offered on this store's product page.");
    }

    /**
     * FAIL CLOSED. A token naming no option of this attribute offers nothing — echoing it raw
     * would present a choice the cart cannot resolve to a variant, which is the defect's symptom
     * dressed as a fix.
     */
    #[Test]
    public function test_a_token_that_names_no_option_is_not_offered(): void
    {
        $this->storeAttributeValues(['S', 'not-an-option', '999999']);

        $this->assertSame(['S'], $this->offeredOptions(),
            'A token that resolves to no option of this attribute was offered anyway.');
    }

    /**
     * THE AMBIGUOUS CASE, PINNED. A size named `38` is a numeric TERM, and an option whose ID is
     * 38 can exist in the same store. The term reading wins — see the note in
     * `App\Support\Commerce\VariantOptionTerms`.
     */
    #[Test]
    public function test_a_numeric_term_resolves_as_a_term(): void
    {
        $numericTermId = (int) DB::table('product_attribute_options')->insertGetId([
            'attribute_id' => $this->sizeAttributeId, 'terms' => '38', 'order' => 3,
            'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Give the SAME attribute an option whose id is the number '38' names, if one exists; the
        // assertion is that '38' resolves to the term, not to whatever option carries that id.
        $collidingTerm = DB::table('product_attribute_options')
            ->where('attribute_id', $this->sizeAttributeId)->where('id', 38)->value('terms');

        $this->storeAttributeValues(['38']);

        $this->assertSame(['38'], $this->offeredOptions(),
            'The numeric term 38 resolved to '
            . ($collidingTerm === null ? 'nothing' : 'the option whose id is 38 (' . $collidingTerm . ')')
            . ' instead of to the term itself. Option id under test: ' . $numericTermId . '.');
    }
}
