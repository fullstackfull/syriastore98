<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F82 / F83 -- what a save actually does to a variant's price and stock.
 * ======================================================================
 *
 * This file does not fix anything. It is the CHARACTERISATION of two measured behaviours that
 * the owner's standing limits reserve ("تغيير سلوك الدفع أو التسعير أو المخزون أو الطلبات"), so
 * that neither can change without a deliberate edit to the expectations below. Both were found
 * by driving the real edit page in a browser; `docs/development/phase-2/P2-03-4-VARIANT-PERSISTENCE-DECISION.md`
 * carries the evidence and the decision point.
 *
 * F82 -- A VARIANT EDIT FROM THE EDIT PAGE IS SILENTLY DISCARDED.
 *     `update()` has TWO variant rebuild paths and each is gated on request keys the edit page
 *     does not post on load:
 *         ProductController.php:1828   `if ($request->has('choice_no'))`
 *         ProductController.php:1910   `attribute_no[]` + `for_variation_<no> == 1` + `options_datas`
 *     The per-variant fields (`product_variation_price_<variant>`, `product_stock_<variant>`, ...)
 *     are read ONLY inside those paths. A browser POST from the edit page carries the per-variant
 *     fields and neither gate's keys, so the handler returns `{"flag":"success"}` and writes
 *     NOTHING to `product_variants`. Measured: a POST carrying
 *     `product_variation_price_M/L-Indigo = "77.25"` left the row at `variation_price = NULL`.
 *
 * F83 -- THE PATH THAT DOES PERSIST REBUILDS, AND THE REBUILD HARD-DELETES.
 *     Once the gate opens, the handler upserts one row per POSTED combination and then runs
 *         ProductVariant::where('product_id', ...)->whereNotIn('variant', $com)->delete()
 *     at :1874 and :2021. Every variant absent from the posted set is deleted outright -- not
 *     soft-deleted, so `DB::table` cannot see it either. Measured in the browser: a product with
 *     8 variants saved with a 4-combination option set came back with 4 rows and the other 4 gone.
 *     And because each field is read as `!empty($request[...]) ? ... : 0`, a covered variant whose
 *     price field is absent or blank is written as `0`, not left alone.
 *
 * WHY THIS IS A TEST AND NOT A FIX. Making the edit page post the gate's keys would make every
 * product save run a rebuild -- i.e. it would route ordinary saves through a path that deletes
 * variant rows and zeroes prices. That is a change to what a save does to pricing and stock, and
 * it is reserved. The safe fix is one of two commercial choices, stated in the decision document.
 *
 * ASSERTIONS GO THROUGH `DB::table`, NOT ELOQUENT. `ProductVariant` is not in `scoped_models` so
 * the scope adds no predicate, but a raw read is also immune to the model cache -- and "the row
 * is gone" is exactly the assertion this project has had pass for the wrong reason before.
 */
class ProductVariantPersistenceTest extends SecurityTestCase
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

    /** @return array{0:\App\Models\User,1:\App\Models\Product,2:int} */
    private function arrange(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
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
            'product_type' => null,
            'status' => 1,
            'variant_product' => 1,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        // Two variants holding values a careless write would destroy.
        foreach ([['S-Red', 40, 11], ['M-Red', 50, 12]] as [$variant, $price, $stock]) {
            ProductVariant::forceCreate([
                'product_id' => $product->id,
                'variant' => $variant,
                'variation_price' => $price,
                'price' => $price,
                'stock' => $stock,
                'sku' => 'SKU-' . $variant,
                'store_id' => $store->id,
            ]);
        }

        return [$merchant, $product, $categoryId];
    }

    private function payload(int $categoryId, array $extra = []): array
    {
        return array_merge([
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
        ], $extra);
    }

    /** @return array<string,mixed>|null */
    private function readVariant(int $productId, string $variant): ?array
    {
        $row = \DB::table('product_variants')
            ->where('product_id', $productId)
            ->where('variant', $variant)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * F82. The edit page's own payload shape: per-variant fields, neither gate's keys.
     */
    public function test_a_variant_price_edit_without_the_gate_keys_is_discarded_and_still_reports_success(): void
    {
        [$merchant, $product, $categoryId] = $this->arrange();

        $before = $this->readVariant($product->id, 'S-Red');
        $this->assertNotNull($before, 'premise: the variant exists');
        $this->assertEquals(40, $before['variation_price'], 'premise: it holds the old price');

        $payload = $this->payload($categoryId, [
            // Exactly what the edit page posts for an edited variant panel.
            'product_variation_price_S-Red' => '99.50',
            'product_stock_S-Red' => '3',
            'product_sku_S-Red' => '-X',
        ]);
        // The two gates, both absent -- this is the measured browser POST, not a contrivance.
        $this->assertArrayNotHasKey('choice_no', $payload);
        $this->assertArrayNotHasKey('attribute_no', $payload);
        $this->assertArrayNotHasKey('options_datas', $payload);

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);

        $response->assertOk();
        $this->assertSame('success', $response->json('flag'),
            'the handler reports success: ' . json_encode($response->json()));

        $after = $this->readVariant($product->id, 'S-Red');
        $this->assertNotNull($after, 'the row must still exist');
        $this->assertEquals(40, $after['variation_price'],
            'F82: the posted 99.50 is DISCARDED -- if this now reads 99.50 the behaviour changed, '
            . 'which is a pricing change and needs the decision in P2-03-4-VARIANT-PERSISTENCE-DECISION.md');
        $this->assertEquals(11, $after['stock'], 'F82: the posted stock is discarded too');
    }

    /**
     * F83. The gate opened -- the posted combination persists AND the uncovered one is deleted.
     */
    public function test_the_rebuild_path_persists_the_posted_variant_and_hard_deletes_the_rest(): void
    {
        [$merchant, $product, $categoryId] = $this->arrange();

        $this->assertNotNull($this->readVariant($product->id, 'M-Red'),
            'premise: the variant that the rebuild will not cover exists first');

        $payload = $this->payload($categoryId, [
            'attribute_no' => [1],
            // F84: `$input['visible_attribute_' . $no]` at :1668/:1762 is an UNGUARDED array read,
            // so posting `attribute_no` without this key is a 500. A browser always sends it (a
            // hidden `0` partner beside the checkbox, edit.blade.php:592), so it is not reachable
            // from the UI -- the same shape as `trending` in ProductUpdateNullGuardsTest.
            'visible_attribute_1' => 0,
            'for_variation_1' => 1,
            'options_datas' => ['S-Red'],          // only ONE of the two combinations
            'product_variation_price_S-Red' => '99.50',
            'product_stock_S-Red' => '3',
            'product_sku_S-Red' => '-X',
        ]);

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        $covered = $this->readVariant($product->id, 'S-Red');
        $this->assertNotNull($covered);
        $this->assertEquals(99.50, $covered['variation_price'],
            'the posted price persists once the gate is open -- this is the only path that writes');
        $this->assertEquals(3, $covered['stock']);

        $this->assertNull($this->readVariant($product->id, 'M-Red'),
            'F83: a variant absent from `options_datas` is deleted by :2021 -- a raw read, so this '
            . 'is a hard delete, not a soft one');
    }

    /**
     * F83, the other half: a covered variant whose price field is blank is written as 0, not left
     * alone. This is the `!empty($request[...]) ? ... : 0` rule, and it is why routing ordinary
     * saves through the rebuild would zero prices.
     */
    public function test_a_covered_variant_with_no_posted_price_is_written_as_zero(): void
    {
        [$merchant, $product, $categoryId] = $this->arrange();

        $this->assertEquals(40, $this->readVariant($product->id, 'S-Red')['variation_price'],
            'premise: a non-zero price to lose');

        $payload = $this->payload($categoryId, [
            'attribute_no' => [1],
            // F84: `$input['visible_attribute_' . $no]` at :1668/:1762 is an UNGUARDED array read,
            // so posting `attribute_no` without this key is a 500. A browser always sends it (a
            // hidden `0` partner beside the checkbox, edit.blade.php:592), so it is not reachable
            // from the UI -- the same shape as `trending` in ProductUpdateNullGuardsTest.
            'visible_attribute_1' => 0,
            'for_variation_1' => 1,
            'options_datas' => ['S-Red', 'M-Red'],  // both covered, so nothing is deleted
            // ...and NO `product_variation_price_*` for either.
        ]);

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        $this->assertNotNull($this->readVariant($product->id, 'M-Red'),
            'both combinations were posted, so neither is deleted -- isolating the zeroing');
        $this->assertEquals(0, $this->readVariant($product->id, 'S-Red')['variation_price'],
            'F83: an absent price field is written as 0, destroying the stored price');
        $this->assertEquals(0, $this->readVariant($product->id, 'M-Red')['variation_price']);
    }

    /**
     * A STATIC RATCHET over the delete sites. The two findings above are about these two lines; a
     * third rebuild path added later would carry the same hazard and this test is where somebody
     * notices. It asserts the COUNT and the SHAPE, so both a new site and a changed predicate on
     * an existing one fail it.
     */
    public function test_every_variant_rebuild_delete_is_a_whereNotIn_on_the_posted_combination_set(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ProductController.php'));
        $this->assertNotFalse($source, 'premise: the controller is readable');

        // `ProductVariant::...->delete()` statements, each as one flattened string.
        $flat = preg_replace('/\s+/', ' ', $source);
        preg_match_all('/ProductVariant::where\(\'product_id\'[^;]*?->delete\(\)/', $flat, $m);
        $deletes = $m[0];

        $this->assertNotEmpty($deletes, 'premise: the deletes are found at all');

        $rebuildDeletes = array_values(array_filter($deletes,
            fn (string $s): bool => str_contains($s, 'whereNotIn')));
        $wholeProductDeletes = array_values(array_filter($deletes,
            fn (string $s): bool => ! str_contains($s, 'whereNotIn')));

        $this->assertCount(2, $rebuildDeletes,
            "expected exactly the two known rebuild deletes (:1874 choice_no, :2021 options_datas). "
            . "A third means a new rebuild path that also hard-deletes uncovered variants -- read "
            . "P2-03-4-VARIANT-PERSISTENCE-DECISION.md before accepting it. Found:\n"
            . implode("\n", $rebuildDeletes));

        foreach ($rebuildDeletes as $stmt) {
            $this->assertMatchesRegularExpression('/whereNotIn\(\s*\'variant\'/', $stmt,
                'the rebuild delete must key on `variant`: ' . $stmt);
        }

        // The unconditional delete in `destroy()` is a different thing and must stay exactly one.
        $this->assertCount(1, $wholeProductDeletes,
            'exactly one unconditional variant delete is expected (product deletion): '
            . implode("\n", $wholeProductDeletes));
    }
}
