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
     * THE PREMISE TEST, without which every "nothing changed" assertion above is vacuous: a guard
     * that simply disabled the rebuild in all cases would satisfy both tests above.
     *
     * A DELIBERATE narrowing must still be RECOGNISED as a configuration change — and under the
     * owner's D-20 ruling it is now REFUSED rather than performed, with the variants that would
     * be lost named. The two halves are asserted separately because they fail for different
     * reasons: "the handler noticed" and "the handler refused".
     */
    public function test_a_deliberately_narrower_combination_set_is_refused_and_names_what_would_be_lost(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $kept = ['S-Red', 'M-Red'];
        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = $kept;

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();

        $this->assertSame('error', $response->json('flag'),
            'a narrowing that would destroy ten variants was accepted: ' . json_encode($response->json()));

        $msg = (string) $response->json('msg');

        $this->assertStringContainsString('10', $msg,
            'the refusal must state HOW MANY variants would be lost');

        foreach (['L-Sand', 'XL-Indigo'] as $lost) {
            $this->assertStringContainsString($lost, $msg,
                "the refusal must NAME the variants that would be lost; `$lost` is missing");
        }

        $this->assertStringContainsString('SKU-', $msg,
            'the refusal must say what each variant carries, not just its name — the merchant is '
            . 'deciding about real data');

        /* CONDITION 6: nothing lost on failure. The check runs before any variant is written, so
           a refusal must leave all twelve rows byte-identical. */
        $after = $this->readAll($product->id);
        $this->assertCount(12, $after, 'a refused narrowing deleted rows anyway');

        foreach ($this->readAll($product->id) as $variant => $row) {
            $this->assertNotNull($row, "variant $variant vanished on a refusal");
        }
    }

    /**
     * AND THE CONFIRMED NARROWING STILL WORKS, **for the case where safety is PROVABLE** —
     * condition 3. Without this, the refusals could be satisfied by a handler that refuses every
     * narrowing forever, which would make the attribute editor unusable rather than safe.
     *
     * The fixture's product appears in no order, and that is what makes this the permitted case:
     * the order line shape always carries `product_id`, so "no order ever contained this product"
     * is establishable, and it entails that no order contained any of its variants.
     */
    public function test_a_confirmed_narrowing_removes_exactly_the_uncovered_variants(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $kept = ['S-Red', 'M-Red'];
        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = $kept;
        $payload['ss_confirm_variant_removal'] = 1;

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        $after = $this->readAll($product->id);
        $this->assertSame($kept, array_keys($after),
            'a CONFIRMED narrowing must remove exactly the uncovered variants and nothing else');
    }

    /**
     * THE OWNER'S CORRECTION, 2026-10-27: **CONFIRMATION DOES NOT SUBSTITUTE FOR PROOF.**
     *
     * > لا أوافق على الحذف النهائي لمتغير قد تكون له مراجع تاريخية غير قابلة للتحقق، حتى مع تأكيد
     * > التاجر. التأكيد شرط للتغييرات المسموحة، لكنه لا يلغي شرط إثبات السلامة.
     *
     * An earlier version of this slice let an explicit confirmation carry a delete whose safety
     * was unprovable, on the reasoning that the merchant had been told. That is exactly the
     * trade the owner refused: a merchant agreeing to a warning does not make order history
     * checkable, and the data lost is commercial.
     *
     * So when ANY order names this product, the delete is blocked outright and the confirmation
     * flag is ignored — there is nothing a merchant could usefully confirm, because nobody can
     * say whether the variant is in that history.
     */
    public function test_a_confirmed_narrowing_is_blocked_when_the_product_appears_in_an_order(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        /* One order naming this PRODUCT, with no variant id on the line — the storefront writer's
           real shape, and the whole reason the history cannot be checked. */
        \DB::table('orders')->insert([
            'product_order_id' => 'POI-' . uniqid(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_id' => $product->id,
            'delivery_id' => 0,
            'delivered_status' => 0,
            'return_status' => 0,
            'reward_points' => 0,
            'order_date' => now(),
            'payment_status' => 'Paid',
            'final_price' => 100,
            'store_id' => $product->store_id,
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'name' => 'Scarf',
                'qty' => 1,
                'price' => 100,
                'final_price' => 100,
                'total_orignal_price' => 100,
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];
        $payload['ss_confirm_variant_removal'] = 1;

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();

        $this->assertSame('error', $response->json('flag'),
            'a CONFIRMED narrowing deleted variants of a product that appears in order history. '
            . 'Confirmation is a condition for permitted changes; it does not establish safety.');

        $msg = (string) $response->json('msg');
        $this->assertStringContainsString('order', strtolower($msg),
            'the block must tell the merchant WHY — that order history names this product');
        $this->assertStringContainsString('out of stock', strtolower($msg),
            'the block must point at the way to stop selling a variant WITHOUT losing its record, '
            . 'or the merchant is left with no route at all');

        $this->assertCount(12, $this->readAll($product->id),
            'a blocked narrowing deleted rows anyway');
    }

    /**
     * CONDITION 4, AND THE STORE THE REFERENCE CHECK ACTUALLY LOOKS IN.
     *
     * The two order queries in `ssRefuseVariantRemoval()` were scoped
     * `->where('store_id', getCurrentStore())` — the CALLER's store, not the PRODUCT's. The
     * product being edited is the subject of the question, and its references live in its own
     * store, so the two coincide only when the caller's current store happens to be the product's.
     *
     * They do not coincide for the platform operator, and CLAUDE.md names the reason: **the helper
     * returns the hardcoded literal `1`** for a `super admin` whose `current_store` is empty
     * (`helper.php:636`). So an operator narrowing a store-7 product would have had its history
     * checked against **store 1's** orders, found none, and been offered a confirmation for a
     * delete whose safety was never established. The same shape with `null` — a merchant with no
     * resolvable store — compiles to `whereNull('store_id')` and matches nothing, which is the
     * open-tenant failure mode CLAUDE.md warns about by name.
     *
     * The predicate is now `$product->store_id`: the store of the row being edited, derived from
     * the record and not from the caller.
     */
    public function test_the_order_history_check_looks_in_the_products_store_not_the_callers(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        /*
         * THE FIXTURE'S FIRST TENANT IS STORE 1, which is exactly the store the helper's
         * hardcoded fallback names — so with one tenant the bug is invisible: the wrong answer and
         * the right one coincide. A SECOND tenant is created and the product moved into it, so
         * `getCurrentStore()`'s literal 1 and the product's store are genuinely different.
         *
         * The first version of this case asserted the premise against the unmoved product and
         * failed on it, which is the premise doing its job.
         */
        [, $otherStore] = $this->makeTenant();
        $product->store_id = $otherStore->id;
        $product->save();
        \DB::table('product_variants')->where('product_id', $product->id)
            ->update(['store_id' => $otherStore->id]);

        $this->assertNotSame(1, (int) $product->store_id,
            'the premise: the product must not live in store 1, or the trap cannot be exercised');

        \DB::table('orders')->insert([
            'product_order_id' => 'POI-' . uniqid(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_id' => $product->id,
            'delivery_id' => 0,
            'delivered_status' => 0,
            'return_status' => 0,
            'reward_points' => 0,
            'order_date' => now(),
            'payment_status' => 'Paid',
            'final_price' => 100,
            'store_id' => $product->store_id,
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'name' => 'Scarf',
                'qty' => 1,
                'price' => 100,
                'final_price' => 100,
                'total_orignal_price' => 100,
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /* Eloquent, not a raw update: `User` is Cachable, so a raw write leaves the handler's own
           read serving the pre-write row — a trap this project has measured twice. */
        $merchant->type = 'super admin';
        $merchant->current_store = null;
        $merchant->save();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $this->assertSame(1, (int) getCurrentStore($merchant->id),
            'the premise: the helper really answers the hardcoded 1 for this principal');

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];
        $payload['ss_confirm_variant_removal'] = 1;

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();

        $this->assertSame(
            'error',
            $response->json('flag'),
            'the order-history check looked in the CALLER\'s store, not the product\'s, so an'
            . ' operator was offered a confirmed delete whose safety had been checked against'
            . ' another tenant\'s orders.'
        );
        /* The BRANCH, not just the flag -- this case is a conjunction (it fails if any of the
           three predicates regresses), so naming the branch says WHICH refusal fired. */
        $this->assertSame(
            'unverifiable_order_history',
            $response->json('ss_variant_removal.blocked'),
            'the refusal fired, but not the one this case is about'
        );

        $this->assertCount(12, $this->readAll($product->id), 'a blocked narrowing deleted rows anyway');
    }

    /**
     * CONDITION 4 — a reference that CAN be seen is a hard refusal, and confirming does not make
     * it safe. A cart line holding one of the variants about to be deleted is exactly that.
     */
    public function test_a_variant_still_in_a_cart_cannot_be_removed_even_with_confirmation(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $doomed = $this->readAll($product->id)['L-Sand'];

        \DB::table('carts')->insert([
            'customer_id' => 1,
            'product_id' => $product->id,
            'variant_id' => $doomed['id'],
            'qty' => 1,
            'price' => 10,
            'store_id' => $product->store_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];
        $payload['ss_confirm_variant_removal'] = 1;

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();

        $this->assertSame('error', $response->json('flag'),
            'a variant sitting in a shopper cart was deleted on a confirmed narrowing');
        $this->assertStringContainsString('L-Sand', (string) $response->json('msg'),
            'the refusal must name the referenced variant');
        $this->assertStringContainsString('cart', strtolower((string) $response->json('msg')),
            'the refusal must say WHAT references it, or the merchant cannot clear the block');

        $this->assertCount(12, $this->readAll($product->id),
            'a blocked narrowing still deleted rows');
    }

    /**
     * THE SECOND NARROWING PATH — `choice_no`, which bypassed the D-20 guard entirely.
     *
     * `update()` has two complete variant rebuilds. The attribute path is the one D-20 was built
     * around; this one deleted every uncovered row with no store predicate, no reference check, no
     * confirmation and no message. `edit.blade.php` posts no `choice_no`, so no browser reaches
     * it — but nothing validates it away either, so a merchant holding `Edit Products` can
     * hand-post the fields and destroy their own product's variants with the guard never running.
     *
     * The owner's D-20 condition 5 is *"if the delete's safety is not established, refuse with a
     * clear message. No silent hard delete."* A silent hard delete is exactly what this was.
     *
     * Found by the mandated review of the attribute path's store-predicate fix.
     */
    public function test_the_choice_no_path_cannot_delete_variants_without_the_guard(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);

        /*
         * The hand-built shape no view emits: a `choice_no` rebuild naming two combinations, which
         * would uncover the other ten. No confirmation flag.
         *
         * ONE ELEMENT, COMMA-SEPARATED, and this was wrong until the F196 review caught it. The
         * handler does `explode(',', implode('|', $request['choice_options_1']))`, so a TWO-element
         * array collapses into the single axis value `'S-Red|M-Red'` — one junk combination name,
         * and all twelve rows uncovered rather than ten. The case still refused, so it was never a
         * false pass; it was just not the narrowing its own comment described, which is the kind of
         * inaccuracy that makes a later reader trust the wrong premise.
         */
        $payload['variant_product'] = 1;
        $payload['choice_no'] = [1];
        $payload['choice_options_1'] = ['S-Red,M-Red'];
        unset($payload['options_datas']);

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();

        $this->assertSame(
            'error',
            $response->json('flag'),
            'the `choice_no` path deleted variants with no confirmation and no reference check —'
            . ' the silent hard delete the owner\'s condition 5 forbids'
        );

        $this->assertSame(
            10,
            $response->json('ss_variant_removal.count'),
            'premise: the payload must really cover TWO of the twelve combinations, leaving ten in'
            . ' the removal set — otherwise this case is about a junk name rather than a narrowing: '
            . json_encode($response->json('ss_variant_removal'))
        );

        $this->assertCount(
            12,
            $this->readAll($product->id),
            'a refused narrowing on the choice_no path still removed rows'
        );
    }

    /**
     * **AN OPERATOR'S NO-OP SAVE MUST NOT MOVE THE VARIANTS INTO ANOTHER STORE.**
     *
     * `$product_stock->store_id = getCurrentStore()` ran on every upserted row, and the upsert
     * resolves by `product_id` + `variant` with no store predicate — so it REWROTE
     * `product_variants.store_id` on existing rows. For an operator with an empty `current_store`
     * the helper returns the hardcoded literal 1 (`helper.php:636`), so an ordinary save of a
     * store-2 product, changing nothing, stamped all twelve variants with store 1 while
     * `products.store_id` stayed 2.
     *
     * The consequence is not cosmetic: every store-scoped reader then stops seeing them — the
     * D-20 detection, `Api\DashboardController::UpdateVariant`'s own `where('store_id', …)`, and
     * the store teardown's `ProductVariant::where('store_id', …)->delete()`, so a drifted row
     * survives the deletion of its own store.
     *
     * `store()`'s equivalent write is deliberately NOT changed: there the product is being created
     * by this caller, so the caller's store IS the product's.
     *
     * Found by the mandated review of the detection fix.
     */
    public function test_an_operator_save_does_not_move_the_variants_into_another_store(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        [, $otherStore] = $this->makeTenant();
        $product->store_id = $otherStore->id;
        $product->save();
        \DB::table('product_variants')->where('product_id', $product->id)
            ->update(['store_id' => $otherStore->id]);

        $merchant->type = 'super admin';
        $merchant->current_store = null;
        $merchant->save();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $this->assertSame(1, (int) getCurrentStore($merchant->id),
            'the premise: the helper really answers the hardcoded 1 for this principal');
        $this->assertNotSame(1, (int) $product->store_id,
            'the premise: the product is not in store 1, or the trap cannot be exercised');

        /* An ORDINARY save: every combination posted back, nothing removed. */
        $response = $this->actingAs($merchant)
            ->put(route('product.update', $product->id), $this->ordinarySavePayload($product, $categoryId, $variants));
        $response->assertOk();

        $stores = \DB::table('product_variants')
            ->where('product_id', $product->id)
            ->pluck('store_id')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(
            [(int) $product->store_id],
            array_map('intval', $stores),
            'a no-op save moved this product\'s variants into the CALLER\'s store. They now'
            . ' disagree with their product, so every store-scoped reader stops seeing them —'
            . ' including the D-20 detection that decides whether a later narrowing is safe.'
        );
    }

    /**
     * THE DRIFTED VARIANT — the gap the review named, and the case that measures the consequence
     * of the `:2614` write.
     *
     * `product_variants.store_id` is nullable with no default, `ProductVariant` carries no scope
     * and is absent from `config/tenancy.php`'s `scoped_models`, so nothing populates it
     * implicitly. Two producers existed: the `:2614` write, which stamped the CALLER's store onto
     * existing rows (so an operator's no-op save drifted every variant of a store-7 product to
     * store 1), and the `choice_no` path, which created variants without setting it at all. Both
     * are fixed; legacy rows can still carry NULL.
     *
     * WHAT THIS ASSERTS IS THE SAFE DIRECTION, NOT THE IDEAL ONE. A drifted row is invisible to
     * the store-scoped detection, so a narrowing neither names it nor deletes it: the merchant is
     * told the removal succeeded and the row survives. That is strictly better than the old
     * behaviour — the unpredicated delete destroyed it silently while the caller-scoped detection
     * had already failed to name it — and it is recorded here as a measured limit rather than
     * left to be discovered.
     */
    public function test_a_variant_whose_store_drifted_is_neither_named_nor_deleted(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $drifted = $this->readAll($product->id)['L-Sand'];
        \DB::table('product_variants')->where('id', $drifted['id'])->update(['store_id' => null]);

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];
        $payload['ss_confirm_variant_removal'] = 1;

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();

        $after = \DB::table('product_variants')->where('product_id', $product->id)->pluck('variant')->all();

        $this->assertContains(
            'L-Sand',
            $after,
            'the drifted row was deleted by a predicate that never examined it. Detection and'
            . ' deletion must ask the SAME question: the old code detected in the caller\'s store'
            . ' and deleted with no predicate at all, which is how a row could be destroyed'
            . ' without ever appearing in the refusal.'
        );

        /* And the rows that did NOT drift were removed exactly as the confirmed narrowing asks. */
        $this->assertNotContains('L-Red', $after, 'an uncovered, undrifted variant must still go');
        $this->assertContains('S-Red', $after, 'a covered variant must stay');
    }

    /**
     * CONDITION 4 — A WISHLIST REFERENCE, which the implementation checks and no test covered.
     *
     * `ssRefuseVariantRemoval()` names three reference kinds: a cart line, a wishlist entry and an
     * order line that carries the id. Carts and orders each had a case; wishlists had none, so
     * the branch that counts them had never been executed by a test and could have been deleted
     * without anything failing.
     *
     * It is the same hazard as the cart: a shopper is holding this exact variant, and confirming
     * does not make deleting it safe.
     */
    public function test_a_variant_in_a_wishlist_cannot_be_removed_even_with_confirmation(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $doomed = $this->readAll($product->id)['L-Sand'];

        \DB::table('wishlists')->insert([
            'customer_id' => 1,
            'product_id' => $product->id,
            'variant_id' => $doomed['id'],
            'status' => 1,
            'store_id' => $product->store_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];
        $payload['ss_confirm_variant_removal'] = 1;

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();

        $this->assertSame('error', $response->json('flag'),
            'a variant sitting in a shopper wishlist was deleted on a confirmed narrowing');
        $this->assertStringContainsString('L-Sand', (string) $response->json('msg'),
            'the refusal must name the referenced variant');
        $this->assertStringContainsString('wishlist', strtolower((string) $response->json('msg')),
            'the refusal must say WHAT references it, or the merchant cannot clear the block');

        $this->assertCount(12, $this->readAll($product->id),
            'a blocked narrowing still deleted rows');
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
     * CONDITION 6 — PERMISSIONS. A merchant without `Edit Products` must not be able to delete
     * variants by narrowing, confirmed or not. Asserted because "confirmed" is a request field
     * and a request field is never an authorisation.
     */
    public function test_a_merchant_without_edit_products_cannot_remove_variants(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        /* There is no revokePermission() helper, so the grant made by arrangeTwelve() is
           removed the way grantPermission() made it: detach the role it created, then flush the
           user's model cache — `User` is `Cachable`, and without the flush `isAbleTo()` answers
           from the pre-revocation copy and the test passes for the wrong reason. */
        $role = \App\Models\Role::where('name', 'granted-role-' . $merchant->id)->first();
        $this->assertNotNull($role, 'premise: arrangeTwelve() granted the permission through a role');
        $merchant->removeRole($role->name);
        $merchant->flushCache();
        $merchant->refresh();

        $this->assertFalse($merchant->isAbleTo('Edit Products'),
            'premise: the permission really is gone. Without this the assertion below would pass '
            . 'for a merchant who still has it and simply posted something the handler refused '
            . 'for an unrelated reason.');

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red'];
        $payload['ss_confirm_variant_removal'] = 1;

        $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);

        $this->assertCount(12, $this->readAll($product->id),
            'a merchant without Edit Products removed eleven variants by posting a narrower set '
            . 'with the confirmation flag. The flag is request input, not an authorisation.');
    }

    /**
     * CONCURRENCY — what is covered and what is NOT, stated rather than implied.
     *
     * The owner's condition names concurrency. It cannot be driven here: the suite runs SQLite
     * `:memory:` with `CACHE_STORE=array`, so `Cache::lock` is process-local and no Feature test
     * can stage two merchants saving one product. What DOES exist is the pre-existing
     * `ss_last_seen_at` conflict guard, which refuses a save whose form was rendered before
     * someone else's save — the stale-form race, which is the realistic shape of this hazard for
     * a narrowing (a merchant confirms a removal list that another session has already changed).
     * This asserts the guard is still wired, so the one protection that exists cannot be removed
     * silently.
     */
    public function test_the_stale_form_guard_still_covers_a_narrowing(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrangeTwelve();

        $payload = $this->ordinarySavePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];
        $payload['ss_confirm_variant_removal'] = 1;
        /* A token from before the product's current state. */
        $payload['ss_last_seen_at'] = $product->updated_at->copy()->subMinutes(5)->toISOString();

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();

        $this->assertSame('error', $response->json('flag'),
            'a confirmed narrowing from a stale form was accepted, so the merchant confirmed a '
            . 'list of variants that may no longer be the list that gets deleted');

        $this->assertCount(12, $this->readAll($product->id),
            'the stale save deleted variants anyway');
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
