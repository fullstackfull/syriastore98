<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F82 / F83 -- what a save does to a variant's price and stock, now that D-09 has been applied.
 * ============================================================================================
 *
 * THIS FILE USED TO PIN THE DEFECTIVE BEHAVIOUR, and the owner's D-09 instruction was to convert
 * it rather than leave it contradicting the fix. What each test now asserts, and why, is on the
 * test. Two of its original claims were WRONG and are corrected here rather than quietly
 * dropped:
 *
 *   - IT CALLED A PAYLOAD CARRYING NEITHER GATE KEY "the measured browser POST". It is not. The
 *     edit page calls `attribute_option_data()` from `$(document).ready`, which injects the
 *     values panel into the form before the merchant touches anything, and that panel carries
 *     `options_datas[]`. Driving the real form produced 154 named parts carrying ALL THREE gate
 *     keys. The original probe matched only the FIRST occurrence of a repeated multipart name
 *     and so missed them. The real browser shape is driven in
 *     `ProductVariantOrdinarySaveGuardTest`; what remains here is the non-browser shape, which
 *     is still worth pinning because it is still inert.
 *   - IT CONCLUDED THAT THE SAFE FIX WAS A COMMERCIAL CHOICE between making the page post the
 *     gate keys and leaving the edit discarded. That framing missed the third option, which is
 *     the one taken: the page already posts them, so the fix is to make the WRITER
 *     non-destructive and to gate the DELETE on the posted combination set actually differing
 *     from the rows that exist. See `docs/development/phase-2/P2-03-4-VARIANT-PERSISTENCE-DECISION.md`.
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
     * A PAYLOAD WITH NEITHER GATE KEY IS INERT, and reports success. This is the NON-BROWSER
     * shape -- a hand-made PUT, or an API client -- not what the edit page sends; the page's own
     * payload is driven in `ProductVariantOrdinarySaveGuardTest`, where the typed price now
     * persists.
     *
     * It is kept because "inert" is the safe outcome for a request that named no combinations:
     * with no `options_datas` there is no set to compare against and nothing the handler can
     * safely conclude, so writing nothing is correct. If this ever starts writing, a caller that
     * posts a bare per-variant field has gained the ability to change prices without naming the
     * combination set, which is a different and larger change.
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
            'a request naming no combination set writes nothing -- if this now reads 99.50 then a '
            . 'caller can change a price without naming the set, which is a wider change than D-09');
        $this->assertEquals(11, $after['stock'], 'the posted stock is not written either');
    }

    /**
     * THE CONFIGURATION PATH STILL REBUILDS, AND STILL DELETES -- deliberately preserved by
     * D-09, which removed the accidental case, not the intentional one.
     *
     * The posted set here names ONE of the two combinations while both exist, so it genuinely
     * differs from the rows on disk. That is what a merchant narrowing an attribute's option set
     * produces, and the uncovered row is deleted exactly as before. What no longer happens is
     * the same delete firing on a save that posted the set it already had, which is the case the
     * edit page produces on every ordinary save.
     *
     * (What SHOULD happen to an uncovered variant on a deliberate narrowing -- hard delete, or a
     * refusal naming the rows that would be lost -- is a commercial decision and is recorded as
     * open in the decision document. This test pins today's answer so a change to it is
     * deliberate.)
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
     * F83's other half, INVERTED BY THE FIX. A covered variant whose price field is absent used
     * to be written as 0 -- the `!empty($request[k]) ? ... : 0` rule -- which is what made the
     * ordinary save destructive. It must now be left alone.
     *
     * This is the assertion that fails if the fix is reverted, so it is the one that proves it.
     */
    public function test_a_covered_variant_with_no_posted_price_keeps_its_stored_price(): void
    {
        [$merchant, $product, $categoryId] = $this->arrange();

        $this->assertEquals(40, $this->readVariant($product->id, 'S-Red')['variation_price'],
            'premise: a non-zero price that a careless write would lose');

        $payload = $this->payload($categoryId, [
            'attribute_no' => [1],
            // F84: `$input['visible_attribute_' . $no]` at :1668/:1762 is an UNGUARDED array read,
            // so posting `attribute_no` without this key is a 500. A browser always sends it (a
            // hidden `0` partner beside the checkbox, edit.blade.php:592), so it is not reachable
            // from the UI -- the same shape as `trending` in ProductUpdateNullGuardsTest.
            'visible_attribute_1' => 0,
            'for_variation_1' => 1,
            // Both covered, so the posted set equals the stored set and nothing is deleted.
            'options_datas' => ['S-Red', 'M-Red'],
            // ...and NO `product_variation_price_*` for either, which is the point.
        ]);

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        $this->assertNotNull($this->readVariant($product->id, 'M-Red'),
            'the posted set equals the stored set, so neither row is deleted');
        $this->assertEquals(40, $this->readVariant($product->id, 'S-Red')['variation_price'],
            'an absent price field must leave the stored price alone, not write the default');
        $this->assertEquals(50, $this->readVariant($product->id, 'M-Red')['variation_price']);
        $this->assertEquals(11, $this->readVariant($product->id, 'S-Red')['stock'],
            'and the same for quantities');
        $this->assertEquals('SKU-S-Red', $this->readVariant($product->id, 'S-Red')['sku'],
            'and for the hand-typed SKU, which this writer used to recompute from the product name');
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

    /**
     * WHAT ACTUALLY PROTECTS AN ORDINARY SAVE, asserted in the source -- and the reason this test
     * does not assert what an earlier version of it did.
     *
     * The first attempt at D-09 put a set-difference gate in front of the `options_datas` delete
     * and this test pinned its shape. Independent review proved the gate **could not change the
     * delete's outcome**: `whereNotIn('variant', $com)` already removes exactly the rows in
     * `array_diff(existing, posted)`, so "the sets differ" is a restatement of "this delete has
     * something to delete". The gate was removed, and so was a `store_id` predicate added to the
     * delete alongside it, which bought nothing (the delete is confined by `product_id` and the
     * product is ownership-checked) and cost the platform operator's cleanup entirely, because
     * `getCurrentStore()` returns the literal 1 for an admin with no `current_store`.
     *
     * So the protection is in the WRITER, and that is what is pinned here: every per-variant
     * column must go through `ssVariantValue()`, because a single surviving
     * `!empty($request[...]) ? ... : DEFAULT` is enough to re-open F105 for that column.
     */
    public function test_every_per_variant_column_is_written_through_the_non_destructive_helper(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ProductController.php'));
        $this->assertNotFalse($source, 'premise: the controller is readable');

        $this->assertStringContainsString('private function ssVariantValue(', $source,
            'the helper that carries the rule has been removed or renamed');

        /* The `options_datas` writer, bounded by the two landmarks that open and close it. */
        $from = strpos($source, '$ssKey = str_replace');
        $this->assertNotFalse($from, 'premise: the writer block was found');
        $to = strpos($source, "whereNotIn('variant', \$com)", $from);
        $this->assertNotFalse($to, 'premise: the end of the writer block was found');
        $block = substr($source, $from, $to - $from);

        foreach (['price', 'variation_price', 'stock', 'low_stock_threshold', 'weight',
                  'stock_order_status', 'description', 'stock_status'] as $column) {
            $this->assertMatchesRegularExpression(
                '/\$product_stock->' . $column . ' = \$this->ssVariantValue\(/',
                $block,
                "`$column` is no longer written through ssVariantValue(), so an ordinary save can "
                . 'write its default over a value the merchant did not send');
        }

        $this->assertStringNotContainsString('!empty($request[', $block,
            'a `!empty($request[...])` has come back into the variant writer. That idiom reads a '
            . 'blank echoed field as "no value" and writes the column default, which is exactly '
            . 'how F105 zeroed 11 of 12 variant prices on a save that touched nothing.');
    }

    /**
     * A census hazard this file carries and the review that found it: the delete sweep above
     * regexes the whole flattened source, COMMENTS INCLUDED, and this project quotes removed code
     * verbatim in docblocks (`Api/DashboardController.php:1806-1810` does). One such comment
     * containing a `ProductVariant::where('product_id'...->delete()` would move the count and read
     * as a new rebuild path.
     *
     * `token_get_all()` is the project's own answer to this -- it is how the payment-callback
     * census separated real `recordVerification` call sites from commented ones. Asserting that
     * the two censuses agree is cheaper than rewriting the first, and it fails the moment a
     * comment starts contributing.
     */
    public function test_the_delete_census_counts_code_and_not_comments(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ProductController.php'));
        $this->assertNotFalse($source);

        $stripped = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $stripped .= $token[1];
            } else {
                $stripped .= $token;
            }
        }

        $pattern = '/ProductVariant::where\(\'product_id\'[^;]*?->delete\(\)/';
        preg_match_all($pattern, preg_replace('/\s+/', ' ', $source), $withComments);
        preg_match_all($pattern, preg_replace('/\s+/', ' ', $stripped), $codeOnly);

        $this->assertSame(count($codeOnly[0]), count($withComments[0]),
            'a COMMENT in ProductController now matches the variant-delete census, so the counts '
            . "in test_every_variant_rebuild_delete_... are measuring prose. Code-only found "
            . count($codeOnly[0]) . ', comment-inclusive found ' . count($withComments[0]) . '.');

        $this->assertCount(3, $codeOnly[0],
            'three real variant deletes are expected: two whereNotIn rebuilds and one '
            . 'unconditional product deletion. Found: ' . implode(' | ', $codeOnly[0]));
    }
}
