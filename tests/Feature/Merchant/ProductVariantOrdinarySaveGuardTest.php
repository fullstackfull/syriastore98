<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F105 -- AN ORDINARY PRODUCT SAVE MUST NOT TOUCH A VARIANT THE MERCHANT DID NOT EDIT.
 * ====================================================================================
 *
 * Owner decision D-09, design A1, with the binding condition that it is not enough to build an
 * in-place edit path: the ordinary save must also stop rebuilding variants merely because
 * `for_variation=1` sits in the product's stored data. The case to reproduce was given
 * literally -- twelve variants with non-zero prices, saved without touching a single field,
 * with every price, quantity and the variant count required to survive.
 *
 * THE LITERAL CASE PASSES ON UNFIXED CODE, AND THAT IS THE WHOLE DIFFICULTY OF THIS FILE.
 * The values panel echoes each row's stored price back into the form, so when the rebuild writes
 * the posted value it writes the SAME NUMBER. A test that asserts only prices, quantities and
 * the row count therefore reports success against the defect it was written to catch. The
 * assertions that actually detect the rebuild are the columns the panel CANNOT echo faithfully:
 *
 *   - `sku`        -- rewritten unconditionally from the PRODUCT NAME plus the posted token, and
 *                     the panel posts `'-' . $variant` as that token. A hand-typed `SKU-0001`
 *                     came back as `Scarf-S-Red`. This is the one column that always moves.
 *   - `weight`, `stock_order_status`, `stock_status` -- NULL in the row, so either the panel
 *                     renders a blank field or (for stock_status) renders no field at all, and
 *                     the old `!empty($request[k]) ? ... : DEFAULT` wrote 0 or ''.
 *
 * Both halves are asserted below, labelled, so that the owner's condition is checked AND the
 * check has teeth.
 *
 * ASSERTIONS GO THROUGH `DB::table`. `ProductVariant` is `Cachable` and is not in
 * `scoped_models`, so an Eloquent read can serve a pre-write row under `CACHE_STORE=array` --
 * this project has had a variant assertion pass for that reason before. The FIXTURE is built
 * with `forceCreate` for the same reason, in the other direction: a raw insert would leave the
 * handler's own `ProductVariant::where(...)->first()` reading a stale row.
 */
class ProductVariantOrdinarySaveGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The twelve combinations, as a two-attribute product really produces them. */
    private const SIZES = ['S', 'M', 'L', 'XL'];
    private const COLOURS = ['Red', 'Indigo', 'Sand'];

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

    /**
     * A product stored EXACTLY as one that reproduces F105: `variant_product = 1` and a
     * `product_attribute` whose `for_variation_N` is the string "1".
     *
     * THE FLAG'S SPELLING IS LOAD-BEARING. The development fixture stores `"on"`, and
     * `"on" == 1` is false on PHP 8.3, so a product copied from that fixture does NOT open the
     * gate and does NOT reproduce the defect. Writing "1" here is what makes this a reproduction
     * rather than a test of the safe branch.
     *
     * @param  array<string,mixed>  $variantOverrides  applied to every variant row
     * @return array{0:\App\Models\User,1:\App\Models\Product,2:int,3:list<string>}
     */
    private function arrangeTwelve(array $variantOverrides = []): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        $categoryId = $this->makeCategory($store->id);

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
                ProductVariant::forceCreate(array_merge([
                    'product_id' => $product->id,
                    'variant' => $variant,
                    /* Distinct and non-zero so a write that lands on the wrong row is visible as
                       a wrong number rather than as a coincidence. */
                    'variation_price' => 100 + $n,
                    'price' => 200 + $n,
                    'stock' => 30 + $n,
                    'low_stock_threshold' => $n,
                    /* A SKU the merchant typed, not one the writer would compute. */
                    'sku' => sprintf('SKU-%04d', $n),
                    /* NULL on purpose: these are the columns the panel cannot echo, and so the
                       ones that detect the rebuild. */
                    'weight' => null,
                    'stock_order_status' => null,
                    'stock_status' => null,
                    'description' => null,
                    'variation_option' => '',
                    'store_id' => $store->id,
                ], $variantOverrides));
            }
        }

        $this->assertCount(12, $variants, 'premise: the owner\'s case is twelve combinations');

        return [$merchant, $product, $categoryId, $variants];
    }

    /**
     * The payload the EDIT PAGE itself produces on an ordinary save, field for field.
     *
     * This is the measured shape, not a contrivance: the page calls `attribute_option_data()`
     * from `$(document).ready`, which renders the values panel INSIDE the form before the
     * merchant has touched anything, and that panel carries `options_datas[]` -- the third of
     * the three keys the rebuild gate needs. An earlier probe of this page reported that no gate
     * key was posted at all; it was wrong, because it matched only the FIRST occurrence of a
     * repeated multipart name. Driving the real form produced 154 named parts carrying all three.
     *
     * @param  list<string>  $variants
     * @return array<string,mixed>
     */
    private function ordinarySavePayload(Product $product, int $categoryId, array $variants): array
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
            /* The gate's third key, listing EVERY existing combination -- which is what the
               panel emits, because it is built from the variant rows themselves. */
            'options_datas' => $variants,
        ];

        foreach ($attributeIds as $attributeId) {
            /* The template emits a hidden `0` and a checked `1` under one name and PHP keeps the
               last, so the value that reaches the handler is 1. F84: `visible_attribute_N` is an
               unguarded array read, so omitting it is a 500. */
            $payload['for_variation_' . $attributeId] = 1;
            $payload['visible_attribute_' . $attributeId] = 1;
            $payload['attribute_options_' . $attributeId] = $attributeId === array_key_first(array_flip($attributeIds))
                ? self::SIZES
                : self::COLOURS;
        }

        foreach ($variants as $variant) {
            $row = \DB::table('product_variants')
                ->where('product_id', $product->id)->where('variant', $variant)->first();
            $key = str_replace('.', '_', $variant);
            /* Each field exactly as `attribute_combinations_data.blade.php` echoes it, INCLUDING
               the blanks: `Form::number` on a NULL column renders an empty value, and the
               browser posts that empty string. Those blanks are what the old writer turned into
               zeroes. */
            $payload['product_sku_' . $key] = '-' . $variant;
            $payload['product_weight_' . $key] = $row->weight ?? '';
            $payload['product_variation_price_' . $key] = $row->variation_price ?? '';
            $payload['product_sale_price_' . $key] = $row->price ?? '';
            $payload['product_description_' . $key] = $row->description ?? '';
            $payload['product_stock_' . $key] = $row->stock ?? '';
            $payload['low_stock_threshold_' . $key] = $row->low_stock_threshold ?? '';
            /* The shipping select's first entry is the prepended '' ("Same as parent"), and the
               stored literal is not one of its keys, so nothing is selected and '' is posted. */
            $payload['shipping_id_' . $key] = '';
            /* `stock_status_N` is rendered ONLY when the row's stock_status is non-NULL, and the
               radio group for `stock_order_status_N` posts nothing when no value matches. Both
               keys are therefore ABSENT here, which is the case the old writer wrote over. */
        }

        return $payload;
    }

    /** @return array<string,array<string,mixed>> variant => row */
    private function readAll(int $productId): array
    {
        $out = [];
        foreach (\DB::table('product_variants')->where('product_id', $productId)->get() as $row) {
            $out[$row->variant] = (array) $row;
        }

        return $out;
    }

    /**
     * THE OWNER'S CASE, LITERALLY: twelve variants, an ordinary save, nothing touched.
     */
    public function test_an_ordinary_save_changes_no_column_of_any_untouched_variant(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $before = $this->readAll($product->id);
        $this->assertCount(12, $before, 'premise: twelve rows exist before the save');

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        /* The premise that makes this a reproduction: the gate's three keys ARE present, because
           the page posts them on an ordinary save. */
        $this->assertArrayHasKey('attribute_no', $payload);
        $this->assertArrayHasKey('options_datas', $payload);
        $this->assertSame(1, $payload['for_variation_' . explode(',', (string) $product->attribute_id)[0]]);

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        $after = $this->readAll($product->id);

        // -- the owner's three assertions ------------------------------------------------------
        $this->assertCount(12, $after, 'the variant count must be unchanged');
        foreach ($before as $variant => $row) {
            $this->assertArrayHasKey($variant, $after, "variant $variant was deleted by an ordinary save");
            $this->assertEquals($row['variation_price'], $after[$variant]['variation_price'],
                "the price of $variant changed on a save that touched no field");
            $this->assertEquals($row['stock'], $after[$variant]['stock'],
                "the quantity of $variant changed on a save that touched no field");
        }

        // -- the assertions that actually detect the rebuild -----------------------------------
        /* These are the columns the panel cannot echo faithfully. Without them the three
           assertions above pass against the unfixed writer, because it writes the echoed price
           straight back. */
        foreach ($before as $variant => $row) {
            foreach (['sku', 'weight', 'stock_order_status', 'stock_status', 'price',
                      'low_stock_threshold', 'description', 'variation_option'] as $column) {
                $this->assertSame($row[$column], $after[$variant][$column],
                    "column `$column` of variant $variant was rewritten by an ordinary save: "
                    . var_export($row[$column], true) . ' became '
                    . var_export($after[$variant][$column], true));
            }
        }
    }

    /**
     * THE MEASURED F105 SHAPE. The development fixture's variants hold a NULL `variation_price`,
     * so the damage measured in the browser was NULL -> 0, not one number becoming another. That
     * is a different assertion from the one above and it is the one that fails on revert.
     */
    public function test_an_ordinary_save_does_not_turn_a_null_price_into_zero(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve([
            'variation_price' => null,
            'weight' => null,
        ]);

        $this->assertNull($this->readAll($product->id)['S-Red']['variation_price'],
            'premise: the fixture holds a NULL price, as the development data does');

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        foreach ($this->readAll($product->id) as $variant => $row) {
            $this->assertNull($row['variation_price'],
                "the NULL price of $variant was written as " . var_export($row['variation_price'], true));
            $this->assertNull($row['weight'],
                "the NULL weight of $variant was written as " . var_export($row['weight'], true));
        }
    }

    /**
     * THE PREMISE TEST, without which every "nothing changed" assertion above is vacuous: a
     * guard that simply disabled the rebuild in all cases would satisfy both tests above.
     *
     * A DELIBERATE narrowing of the posted combination set is still a configuration change and
     * must still rebuild -- including the delete of the rows the new set no longer covers. That
     * is the behaviour this slice deliberately preserves; what it removes is the same delete
     * firing on a save that posted the set it already had.
     */
    public function test_a_deliberately_narrower_combination_set_still_rebuilds_and_deletes(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $kept = ['S-Red', 'M-Red'];
        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = $kept;
        $payload['product_variation_price_S-Red'] = '99.50';

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        $after = $this->readAll($product->id);
        $this->assertSame($kept, array_keys($after),
            'a narrower posted set is a configuration change: the uncovered rows are deleted');
        $this->assertEquals(99.50, $after['S-Red']['variation_price'],
            'and the posted price is written, which is the path that always did persist');
    }

    /**
     * The merchant edits ONE variant's price from the values panel and presses Save. This is
     * F82: it used to report success and write nothing. It must now persist -- and must still
     * leave the other eleven rows alone, which is the half that makes it a fix rather than a
     * trade.
     */
    public function test_a_price_typed_into_the_values_panel_persists_without_disturbing_the_rest(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $before = $this->readAll($product->id);
        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['product_variation_price_M-Indigo'] = '77.25';
        $payload['product_stock_M-Indigo'] = '4';

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        $after = $this->readAll($product->id);
        $this->assertCount(12, $after);
        $this->assertEquals(77.25, $after['M-Indigo']['variation_price'],
            'F82: the edit the merchant typed must actually be stored');
        $this->assertEquals(4, $after['M-Indigo']['stock']);

        unset($before['M-Indigo'], $after['M-Indigo']);
        foreach ($before as $variant => $row) {
            foreach (['variation_price', 'price', 'stock', 'sku', 'weight', 'stock_order_status',
                      'stock_status', 'low_stock_threshold'] as $column) {
                $this->assertSame($row[$column], $after[$variant][$column],
                    "editing one variant changed `$column` of $variant");
            }
        }
    }

    /**
     * A TYPED ZERO AND A BLANK MUST NOT MEAN THE SAME THING. `!empty("0")` is false, so the old
     * writer treated both as "no value" and wrote the column's default -- which is F83.
     *
     * ASSERTING THE TYPED ZERO ALONE PROVES NOTHING, and this test said so only after it was
     * run against the unfixed writer and passed. For every numeric column here the default IS
     * zero, so "typed 0 becomes 0" is true either way. The discriminating claim is the PAIR:
     * the same column, on two variants, must come out 0 where the merchant typed 0 and unchanged
     * where the field was blank. Only the second half can tell the two writers apart, which is
     * why both are in one test rather than two.
     */
    public function test_a_typed_zero_is_stored_and_a_blank_leaves_the_column_alone(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve([
            /* NULL, so "unchanged" and "the default" are visibly different outcomes. */
            'variation_price' => null,
        ]);

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['product_variation_price_L-Sand'] = '0';      // typed
        $payload['product_variation_price_S-Red'] = '';        // blank, as the panel echoes NULL
        $payload['product_stock_L-Sand'] = '0';
        $payload['low_stock_threshold_L-Sand'] = '0';

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        $rows = $this->readAll($product->id);
        $this->assertEquals(0, $rows['L-Sand']['variation_price'],
            'a typed 0 must be stored, not discarded as "empty"');
        $this->assertEquals(0, $rows['L-Sand']['stock']);
        $this->assertEquals(0, $rows['L-Sand']['low_stock_threshold']);
        $this->assertNull($rows['S-Red']['variation_price'],
            'and a blank field must leave the column as it was -- this is the half that fails '
            . 'against the `!empty()` writer, and so the half that makes the test bite');
    }

    /**
     * THE TEMPLATE PREMISE. A PHP array payload cannot express a POST key sent twice, so the
     * tests above post `for_variation_N = 1` directly. That is only faithful if the page really
     * does emit the name twice and really does render the values panel on load -- both of which
     * are what make an ordinary save open the gate at all.
     */
    public function test_the_edit_page_still_posts_the_gate_keys_on_an_untouched_save(): void
    {
        $edit = file_get_contents(resource_path('views/product/edit.blade.php'));
        $panel = file_get_contents(resource_path('views/product/attribute_combinations_data.blade.php'));
        $this->assertNotFalse($edit);
        $this->assertNotFalse($panel);

        $this->assertStringContainsString("Form::hidden('for_variation_'", $edit,
            'the hidden 0 partner: without it the checkbox posts nothing when unchecked');
        $this->assertStringContainsString("Form::checkbox('for_variation_'", $edit,
            'the checkbox that posts 1 under the SAME name, which is why $request->has() cannot '
            . 'discriminate an ordinary save from a configuration change');
        $this->assertStringContainsString('attribute_option_data()', $edit,
            'the values panel is fetched from $(document).ready, with no interaction');
        $this->assertStringContainsString('options_datas[]', $panel,
            'and the panel it injects carries the gate\'s third key, inside the form');
    }
}
