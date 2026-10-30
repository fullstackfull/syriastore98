<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * F196 — THE VARIANT-REMOVAL REFUSAL RAN **AFTER** THE PRODUCT WAS ALREADY SAVED.
 * =============================================================================================
 *
 * D-20 made a variant-destroying narrowing refusable, and the refusal says
 *
 *     "Nothing has been changed."
 *
 * It was not true. `ProductController::update()` reaches its two in-place removal guards only
 * after it has already run, in order:
 *
 *     Utility::updateStorageLimit(...)            -- `$user->storage_limit += $size; $user->save()`
 *     Utility::upload_file(cover_image)           -- and StoredUpload::delete() on the old path
 *     Utility::upload_file(preview_video)
 *     Utility::upload_file(downloadable_product)
 *     ProductImage::create(...)                   -- when `product_image` is posted
 *     $product->price = 0;  $product->product_stock = 0;
 *     $product->save()
 *
 * So a refused save persisted the product row — INCLUDING ZEROING `price` AND `product_stock`,
 * which the variant branch does unconditionally — moved `updated_at`, charged the merchant's
 * storage quota, and destroyed their existing cover image. Then it told them nothing had changed.
 *
 * NO LINE NUMBERS IN THIS FILE'S COMMENTS ON PURPOSE: the fix itself moved every line in
 * `update()` by about 190, and an earlier version of this docblock cited nine that were already
 * wrong by the time it was committed. The ordering is asserted instead, against comment-stripped
 * source, by `test_the_removal_preflight_runs_before_every_upload_and_save_in_update()`.
 *
 * THE SECOND HARM IS WORSE THAN THE FIRST, and it is why F184 could not be built on top of this.
 * The refusal is meant to be followed by the merchant confirming and saving again. The form still
 * carries the `ss_last_seen_at` token it was rendered with, and the optimistic-lock guard compares
 * it to `products.updated_at` — which the REFUSED save has just moved. So the retry was answered
 * with
 *
 *     "Someone else saved this product while you were editing it."
 *
 * blaming a person who does not exist, for a write the merchant's own refused request performed.
 * Confirm-and-retry was therefore impossible, not merely unbuilt.
 *
 * WHAT THIS FILE ASSERTS, per the owner's §3.3: on a refusal the whole `products` row and every
 * `product_variants` row are byte-identical to before, no `product_images` row is added, no
 * uploaded file is kept, and the unchanged token still satisfies the lock on the retry.
 *
 * WHY IT COMPARES WHOLE ROWS rather than the columns I expected to move: "I know which columns the
 * handler touches" is a claim about 900 lines of branching, and this project has already paid for
 * two of those. A whole-row comparison cannot be wrong about which column moved, and its failure
 * message names whichever one did.
 */
class ProductUpdateRefusalPersistsNothingTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

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

    /**
     * A variant product with four combinations, a NON-ZERO parent price and stock, and an
     * `updated_at` deliberately parked a day in the past.
     *
     * BOTH OF THOSE ARE WHAT MAKE THE DEFECT MEASURABLE RATHER THAN INVISIBLE:
     *
     *   - a parent price of 0 would make `$product->price = 0` a no-op, so `save()` would find the
     *     model clean and `updated_at` would not move at all;
     *   - and `updated_at` written at `now()` has one-second resolution here, so a test that saved
     *     and compared within the same second would report "unchanged" against the live defect.
     *
     * A day's gap cannot be produced by the clock, so the comparison is answering about the
     * handler rather than about how fast the suite runs.
     *
     * @return array{0:\App\Models\User,1:\App\Models\Product,2:int,3:list<string>}
     */
    private function arrange(): array
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
                /* "1" and not "on": `"on" == 1` is false on PHP 8.3, so the development fixture's
                   spelling does not open the rebuild gate and would test the safe branch. */
                'for_variation_' . $attributeId => '1',
            ];
        }

        $product = Product::forceCreate([
            'name' => 'F196 Scarf',
            'slug' => 'f196-scarf-' . uniqid(),
            'description' => 'x',
            /* Non-zero on purpose. See arrange()'s note. */
            'price' => 10,
            'product_stock' => 7,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
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
                    'sku' => sprintf('F196-%04d', $n),
                    'weight' => null,
                    'stock_order_status' => null,
                    'stock_status' => null,
                    'description' => null,
                    'variation_option' => '',
                    'store_id' => $store->id,
                ]);
            }
        }

        /*
         * PARK `updated_at` IN THE PAST, THROUGH ELOQUENT, WITH `$timestamps` OFF.
         *
         * `Product` IS `Cachable` — CLAUDE.md named only `User`, and that gap cost a measurement
         * here within the hour: a `DB::table('products')->update(...)` in the cover-image case was
         * invisible to the handler's own route-model binding, which served the row this fixture
         * had already read into the cache. The probe that found it printed
         * `cover_image_path=''` and `StoredUpload::resolve()=NULL` inside the request, so the
         * delete it was written to detect was a no-op and the case passed against a live bite.
         *
         * Writing through Eloquent flushes that cache. `$timestamps = false` is what lets an
         * explicit past `updated_at` survive `save()`, which would otherwise stamp `now()` and
         * erase the gap this file depends on.
         */
        $product->timestamps = false;
        $product->created_at = now()->subDays(2);
        $product->updated_at = now()->subDay();
        $product->save();
        $product->timestamps = true;

        return [$merchant, $product->fresh(), $categoryId, $variants];
    }

    /** @param list<string> $variants */
    private function savePayload(Product $product, int $categoryId, array $variants): array
    {
        $attributeIds = array_values(array_filter(array_map('intval', explode(',', (string) $product->attribute_id))));

        $payload = [
            'name' => 'F196 Scarf',
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
            'attribute_no' => $attributeIds,
            'options_datas' => $variants,
        ];

        foreach ($attributeIds as $i => $attributeId) {
            $payload['for_variation_' . $attributeId] = 1;
            $payload['visible_attribute_' . $attributeId] = 1;
            $payload['attribute_options_' . $attributeId] = $i === 0 ? self::SIZES : self::COLOURS;
        }

        foreach ($variants as $variant) {
            $row = \DB::table('product_variants')
                ->where('product_id', $product->id)->where('variant', $variant)->first();
            $key = str_replace('.', '_', $variant);
            $payload['product_sku_' . $key] = '-' . $variant;
            $payload['product_weight_' . $key] = $row->weight ?? '';
            $payload['product_variation_price_' . $key] = $row->variation_price ?? '';
            $payload['product_sale_price_' . $key] = $row->price ?? '';
            $payload['product_description_' . $key] = $row->description ?? '';
            $payload['product_stock_' . $key] = $row->stock ?? '';
            $payload['low_stock_threshold_' . $key] = $row->low_stock_threshold ?? '';
            $payload['shipping_id_' . $key] = '';
        }

        return $payload;
    }

    /**
     * THE SAVE, sent the way the browser sends it — with the files in the FILES bag.
     *
     * `$this->put($uri, $data)` passes an EMPTY files array to `call()`, so an `UploadedFile`
     * placed in `$data` is never uploaded: it lands in the request bag as an object. The handler's
     * `if ($request->cover_image)` is then truthy for the wrong reason and `$request->hasFile()`
     * is false, so the branch behaves in a way no browser can produce. Both cover-image cases in
     * this file were vacuous until they went through here — they passed under a bite that moved
     * the preflight behind the upload, which is exactly the defect they exist to detect.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,\Illuminate\Http\UploadedFile>  $files
     */
    private function save($merchant, Product $product, array $payload, array $files = [])
    {
        return $this->actingAs($merchant)
            ->call('PUT', route('product.update', $product->id), $payload, [], $files);
    }

    /**
     * A REFUSAL IS ONLY A WITNESS IF IT IS THE RIGHT REFUSAL.
     *
     * `update()` returns `flag => 'error'` for a dozen different reasons — a validation failure, a
     * storage-limit rejection, a failed upload, a stale form token. Asserting the flag alone would
     * let any of those stand in for the variant-removal refusal, and a case that then checks "the
     * row did not change" would pass because the handler stopped somewhere else entirely. The
     * removal refusal is the only one that carries `ss_variant_removal`, so that is the needle.
     */
    private function assertRemovalRefusal($response, string $why = ''): void
    {
        $body = $response->json();

        $this->assertSame('error', $body['flag'] ?? null,
            ($why !== '' ? $why . ' — ' : '') . 'the save was not refused: ' . json_encode($body));
        $this->assertNotNull($body['ss_variant_removal'] ?? null,
            ($why !== '' ? $why . ' — ' : '')
            . 'the refusal is not the VARIANT-REMOVAL refusal, so whatever this case asserts next'
            . ' is being answered by some other early return: ' . json_encode($body));
    }

    private function productRow(int $id): array
    {
        return (array) \DB::table('products')->where('id', $id)->first();
    }

    /** @return array<string,array<string,mixed>> variant => row */
    private function variantRows(int $productId): array
    {
        $out = [];
        foreach (\DB::table('product_variants')->where('product_id', $productId)->orderBy('id')->get() as $row) {
            $out[$row->variant] = (array) $row;
        }

        return $out;
    }

    /** @param array<string,mixed> $before */
    private function assertRowUnchanged(array $before, array $after, string $what): void
    {
        $moved = [];

        foreach ($before as $column => $value) {
            /* `array_key_exists`, NOT `??`: the null-coalescing operator treats a NULL VALUE as a
               missing key, so every nullable column that is null in both rows would be reported as
               having turned into the placeholder. That is not a hypothetical — the first run of
               this file reported thirteen such phantom changes and buried the two real ones. */
            $now = array_key_exists($column, $after) ? $after[$column] : '<<absent>>';

            if ($now !== $value) {
                $moved[] = sprintf('%s: %s -> %s', $column, var_export($value, true), var_export($now, true));
            }
        }

        $this->assertSame([], $moved,
            $what . ' — a REFUSED save persisted these columns while telling the merchant nothing'
            . ' had changed: ' . implode('; ', $moved));
    }

    // =============================================================================================
    // the attribute path
    // =============================================================================================

    /**
     * THE DEFECT, on the path D-20 governs.
     */
    public function test_a_refused_attribute_narrowing_persists_no_column_of_the_product_row(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $before = $this->productRow($product->id);
        $this->assertSame(4, count($variants), 'premise: four combinations exist');
        $this->assertNotEquals(0, (float) $before['price'],
            'premise: the parent price is non-zero, or the zeroing this detects is invisible');

        $payload = $this->savePayload($product, $categoryId, $variants);
        /* The narrowing: two of the four combinations are dropped. */
        $payload['options_datas'] = ['S-Red', 'M-Red'];

        $response = $this->save($merchant, $product, $payload);

        $response->assertOk();
        $this->assertRemovalRefusal($response, 'premise: the narrowing must be refused');

        $this->assertRowUnchanged($before, $this->productRow($product->id), 'products row');
    }

    /**
     * THE MONEY-BEARING HALF, stated on its own because it is the one a reader will not predict:
     * the variant branch zeroes `price` and `product_stock` unconditionally, so a
     * refusal left the product priced at zero.
     */
    public function test_a_refused_attribute_narrowing_does_not_zero_the_products_price_or_stock(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $payload = $this->savePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];

        $this->save($merchant, $product, $payload)->assertOk();

        $after = $this->productRow($product->id);

        $this->assertEquals(10, $after['price'],
            'a refused save zeroed the product price');
        $this->assertEquals(7, $after['product_stock'],
            'a refused save zeroed the product stock');
    }

    /**
     * THE RETRY, which is the whole reason F184 waited on this.
     *
     * Both requests carry the SAME `ss_last_seen_at` — the one the form was rendered with. If the
     * refusal moved `updated_at`, the second request is answered with the stale-form message and
     * the merchant can never confirm.
     *
     * `ss_last_seen_at` IS NOT BYPASSED TO MAKE THIS PASS, which is the owner's explicit
     * condition: the guard still runs, still compares, and still refuses a genuine conflict — the
     * last case in this file proves that. What changes is that the merchant's own refused request
     * is no longer the thing that creates the conflict.
     */
    public function test_a_refusal_does_not_invalidate_the_form_token_the_retry_carries(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $token = $product->updated_at->toIso8601String();
        $payload = $this->savePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];
        $payload['ss_last_seen_at'] = $token;

        $first = $this->save($merchant, $product, $payload);
        $first->assertOk();
        $this->assertRemovalRefusal($first, 'premise: the first save is refused');
        $this->assertStringNotContainsString('Someone else', (string) $first->json('msg'),
            'the FIRST save was answered with a conflict, so the fixture is racing itself');

        /* The merchant confirms. Same token: the page did not reload. */
        $payload['ss_confirm_variant_removal'] = 1;

        $second = $this->save($merchant, $product, $payload);
        $second->assertOk();

        $this->assertStringNotContainsString('Someone else', (string) $second->json('msg'),
            'the confirmed retry was told someone else had saved the product — the only writer was'
            . ' the merchant\'s own REFUSED first request, so confirm-and-retry is impossible');
        $this->assertSame('success', $second->json('flag'),
            'the confirmed retry did not succeed: ' . json_encode($second->json()));
        $this->assertSame(['S-Red', 'M-Red'], array_keys($this->variantRows($product->id)),
            'the confirmed retry must remove exactly the uncovered combinations');
    }

    /**
     * AND THE LOCK STILL WORKS. Without this the case above could be satisfied by deleting the
     * conflict guard, which is the exact bypass the owner forbade.
     */
    public function test_a_genuine_concurrent_edit_is_still_refused_as_a_conflict(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $stale = $product->updated_at->copy()->subHours(3)->toIso8601String();
        $payload = $this->savePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];
        $payload['ss_confirm_variant_removal'] = 1;
        $payload['ss_last_seen_at'] = $stale;

        $response = $this->save($merchant, $product, $payload);

        $response->assertOk();
        $this->assertSame('error', $response->json('flag'), json_encode($response->json()));
        $this->assertStringContainsString('Someone else', (string) $response->json('msg'),
            'a token that genuinely predates the row was accepted — the F196 reordering must not'
            . ' weaken the P2-03-5 conflict guard');
        $this->assertCount(4, $this->variantRows($product->id),
            'a conflict refusal deleted variants');
    }

    // =============================================================================================
    // the second rebuild
    // =============================================================================================

    /**
     * THE CHOICE PATH, which has the same ordering and which no view emits — so it is reachable
     * only by a hand-posted payload, exactly as F195's bypass was.
     */
    public function test_a_refused_choice_narrowing_persists_no_column_of_the_product_row(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $before = $this->productRow($product->id);

        $payload = $this->savePayload($product, $categoryId, $variants);
        unset($payload['options_datas']);
        $payload['choice_no'] = [1];
        /*
         * ONE ELEMENT, COMMA-SEPARATED — and that is not a style choice. The handler does
         * `explode(',', implode('|', $request['choice_options_1']))`, so a two-element array
         * collapses into the SINGLE axis value `'S-Red|M-Red'`: one junk combination name, and all
         * four rows uncovered. The review of this slice caught that, and the older F195 test has
         * the same inaccuracy (recorded in the F199/F196 notes). Written this way it is a genuine
         * two-of-four narrowing, which is what the case claims to be.
         */
        $payload['choice_options_1'] = ['S-Red,M-Red'];

        $response = $this->save($merchant, $product, $payload);

        $response->assertOk();
        $this->assertRemovalRefusal($response, 'premise: the choice-path narrowing must be refused');
        $this->assertSame(2, $response->json('ss_variant_removal.count'),
            'premise: the payload must really cover two of the four combinations, or this case is'
            . ' about a junk name rather than about a narrowing: '
            . json_encode($response->json('ss_variant_removal')));

        $this->assertRowUnchanged($before, $this->productRow($product->id), 'products row (choice path)');
        $this->assertCount(4, $this->variantRows($product->id), 'a refused choice narrowing deleted rows');
    }

    /**
     * F199 — A CHOICE PAYLOAD WHOSE COMBINATION NAMES ALL REDUCE TO THE EMPTY STRING WIPED EVERY
     * VARIANT, SILENTLY, WITH `flag => 'success'`.
     *
     * PRE-EXISTING, NOT INTRODUCED BY F196 — `git show ba34fce8:…ProductController.php` carries the
     * same `if ($ssName !== '')` filter, so the extraction inherited it faithfully. Found
     * by the mandated review of the F196 reordering.
     *
     * MEASURED OUTCOME, pre-fix: HTTP 200, `{"flag":"success","msg":"Product update successfully."}`,
     * and the product's four variants — their prices, SKUs and stock — replaced by a single row
     * named `''`. On SQLite as well as on MariaDB: this is not an engine artefact.
     *
     * THE TRACE: `choice_options_1[] = ''` gives
     * `implode('|', [''])` → `''` → `explode(',', '')` → `['']`, so `$options = [['']]` and
     * `combinations()` returns `[[0 => '']]`. The guard builds the name `''`, the filter drops it,
     * the covered set is `[]` — and an empty covered set means *"nothing was posted, so there is
     * nothing to check"*, which is how the no-`choice_no` case is skipped.
     *
     * But the REBUILD does not use the filtered set. Its gate is `count($combinations[0]) > 0`,
     * computed from the unfiltered build, and that is **1**. So it proceeds, writes
     * `$sku_array = ['']`, and runs `whereNotIn('variant', [''])` — which matches every real row.
     *
     * So detection and deletion disagreed about the same payload: the guard saw nothing posted, the
     * delete saw one combination posted and removed everything else. D-20 condition 5 is *"if the
     * delete's safety is not established, refuse with a clear message. No silent hard delete."*
     *
     * THE FIX IS TO STOP FILTERING. The covered set now mirrors `$sku_array` exactly — the empty
     * name included — so a payload that posts one empty combination is read as posting one empty
     * combination, the removal set is every row that is not named `''`, and the merchant gets the
     * refusal naming all of them. The legitimate "no `choice_no` posted" case is still skipped,
     * because it is distinguished by `$options === []` rather than by the names being blank.
     *
     * A space does the same thing as an empty string (`str_replace(' ', '', ' ')`), so both are
     * driven.
     */
    #[DataProvider('emptyChoiceNamePayloads')]
    public function test_a_choice_payload_of_empty_names_cannot_wipe_every_variant(string $posted): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $before = $this->variantRows($product->id);
        $this->assertCount(4, $before, 'premise: four rows exist');

        $payload = $this->savePayload($product, $categoryId, $variants);
        unset($payload['options_datas']);
        $payload['choice_no'] = [1];
        $payload['choice_options_1'] = [$posted];
        /*
         * THE CHOICE PATH'S OWN KEY NAMES — `sku_`, `price_`, `stock_`, not the attribute path's
         * `product_sku_` / `product_sale_price_` / `product_stock_`. With the empty combination
         * name the suffix is empty, so these are the keys the rebuild reads for the row it is
         * about to create. WITHOUT THEM THE REPRODUCTION IS WEAKER AND MISLEADING: the insert gets
         * a NULL price, the NOT NULL column refuses it, and the request 500s before the delete —
         * so a payload omitting them looks like a crash rather than the data loss it is one step
         * away from. Measured: with them, the pre-fix code answers
         * `{"flag":"success","msg":"Product update successfully."}` and leaves one row named ''.
         */
        $payload['sku_'] = '-X';
        $payload['price_'] = 5;
        $payload['stock_'] = 1;

        $response = $this->save($merchant, $product, $payload);
        $response->assertOk();

        $this->assertRemovalRefusal($response,
            'a payload posting one blank combination name destroyed every variant and reported'
            . ' success');

        $after = $this->variantRows($product->id);
        $this->assertSame(array_keys($before), array_keys($after),
            'every variant row and its price, SKU and stock must survive a payload the handler'
            . ' cannot make sense of');
    }

    /** @return array<string,array{0:string}> */
    public static function emptyChoiceNamePayloads(): array
    {
        return [
            'the empty string' => [''],
            'a single space, which str_replace reduces to the empty string' => [' '],
        ];
    }

    // =============================================================================================
    // side effects that are not database writes
    // =============================================================================================

    /**
     * THE UPLOAD — and specifically the half of it that no database rollback can undo.
     *
     * The cover-image branch uploads the replacement, calls `StoredUpload::delete()` on the OLD
     * path — removing the merchant's existing picture from the filesystem — and then assigns
     * `cover_image_path` / `cover_image_url`. All of it runs before either removal guard, so a
     * refused save destroyed a file and answered *"Nothing has been changed."*
     *
     * THE COLUMNS ALONE ARE NOT A WITNESS, and a bite proved it rather than my reading it: with the
     * preflight moved one upload too late — inside this very branch, just after the assignment —
     * a column-only version of this case still PASSED, because the assignment is in memory and the
     * refusal returns before any `save()`. The file deletion is the observable that the ordering
     * actually moves, so the file is what this asserts, with the columns alongside it.
     *
     * THE PATH MUST BE A REAL FILE INSIDE `base_path('uploads')`. `StoredUpload::resolve()` returns
     * null for a path that is not a file, for one outside that root, for a platform default and for
     * another tenant's folder — and in every one of those cases the delete is a silent no-op and
     * this case would pass against the live defect. `StoredPathPolicy` refuses to let Eloquent
     * store an unaccepted path at all, which is what rejected a made-up `uploads/original/cover.png`
     * in the first version. So the premise is asserted, not assumed.
     */
    public function test_a_refused_narrowing_does_not_delete_or_replace_the_existing_cover_image(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $relative = 'uploads/' . $product->store_id . '/f196-' . uniqid() . '.png';
        $absolute = base_path($relative);

        \File::ensureDirectoryExists(dirname($absolute));
        \File::put($absolute, 'not-a-real-png, and it does not need to be');

        try {
            $product->timestamps = false;
            $product->cover_image_path = $relative;
            $product->updated_at = now()->subDay();
            $product->save();
            $product->timestamps = true;

            $before = $this->productRow($product->id);
            $storageBefore = (int) \DB::table('users')->where('id', $merchant->id)->value('storage_limit');

            $this->assertNotNull(
                \App\Services\StoredUpload::resolve($relative, $merchant->creatorId()),
                'premise: StoredUpload would really delete this path for this owner. If it resolves'
                . ' to null the delete is a no-op and this case cannot fail, whatever the handler does'
            );

            $payload = $this->savePayload($product, $categoryId, $variants);
            $payload['options_datas'] = ['S-Red', 'M-Red'];
            $response = $this->save($merchant, $product, $payload, [
                'cover_image' => UploadedFile::fake()->image('replacement.png', 8, 8),
            ]);
            $response->assertOk();
            $this->assertRemovalRefusal($response);

            $this->assertFileExists($absolute,
                'a REFUSED save DELETED the merchant\'s existing cover-image file. No database'
                . ' rollback recovers it, and the response said "Nothing has been changed."');

            $after = $this->productRow($product->id);

            $this->assertSame($relative, $after['cover_image_path'],
                'a refused save persisted a new cover_image_path');
            $this->assertSame($before['cover_image_url'], $after['cover_image_url'],
                'a refused save persisted a new cover_image_url');
            $this->assertSame($before['updated_at'], $after['updated_at'],
                'a refused save with a file attached still moved updated_at');

            /*
             * THE EIGHTH PERSISTED SIDE EFFECT, and it is not in `products` at all — which is why
             * the whole-row comparison did not name it and the review did.
             * `Utility::updateStorageLimit()` does `$user->storage_limit += $size; $user->save()`,
             * and it ran before both guards. So a refused narrowing with a file attached charged
             * the merchant's storage quota permanently, for a file they never got to keep. Read
             * raw: `User` is Cachable.
             */
            $this->assertSame($storageBefore,
                (int) \DB::table('users')->where('id', $merchant->id)->value('storage_limit'),
                'a refused save charged the merchant\'s storage quota for a file it did not keep');
        } finally {
            @unlink($absolute);
        }
    }

    /**
     * AND NO GALLERY ROW — with a gallery file actually attached, which the first version of this
     * case did not do.
     *
     * The `ProductImage::create()` sits inside `if (!empty($request->product_image))`, so a payload
     * carrying no `product_image` never reaches it and the row count is `0 === 0` under the fix,
     * under a revert and under every bite. The review of this slice called it: the case could not
     * fail. It is only a witness with the files bag populated, because
     * `Utility::keyWiseUpload_file()` reads `$request->file('product_image')[$key]`.
     */
    public function test_a_refused_narrowing_adds_no_product_image_row(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $before = (int) \DB::table('product_images')->where('product_id', $product->id)->count();

        $payload = $this->savePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];

        $response = $this->save($merchant, $product, $payload, [
            'product_image' => [UploadedFile::fake()->image('gallery.png', 8, 8)],
        ]);

        $response->assertOk();
        $this->assertRemovalRefusal($response);

        $this->assertSame($before, (int) \DB::table('product_images')->where('product_id', $product->id)->count(),
            'a refused save added a gallery row for an image the merchant never got to keep');
    }

    /**
     * THE ONE QUESTION THE REVIEW COULD NOT SETTLE BY READING: can the preflight refuse a save the
     * old code allowed?
     *
     * The preflight checks BOTH posted sets on every request, whereas each in-place guard only ran
     * inside its own rebuild branch. The only way the old code could see a SMALLER removal set is
     * if the choice delete had already removed rows before the attribute guard ran — and for that
     * the choice guard must have ALLOWED those removals, which needs an explicit confirmation, no
     * cart or wishlist reference, and no order naming the product. The preflight's extra rows are a
     * subset of exactly those, so they cannot produce a refusal the old code did not also produce.
     *
     * That is a derivation, and the review marked it *probable* rather than certain. This case
     * executes it: both sets posted, covering DIFFERENT pairs, with the confirmation. If the
     * preflight refused where the old code proceeded, the attribute editor would be unusable for
     * this payload and this case fails.
     */
    public function test_both_posted_sets_with_a_confirmation_are_still_allowed_through(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $payload = $this->savePayload($product, $categoryId, $variants);
        /* The choice set covers S-Red and M-Red; the attribute set covers S-Red and S-Blue. Two
           different pairs, so each path's removal set contains rows the other keeps. */
        $payload['choice_no'] = [1];
        $payload['choice_options_1'] = ['S-Red,M-Red'];
        $payload['options_datas'] = ['S-Red', 'S-Blue'];
        /*
         * THE CHOICE PATH'S UPSERT KEYS FOR EACH NAME IT COVERS. They are `sku_<name>`,
         * `price_<name>` and `stock_<name>` — not the attribute path's `product_*` spellings that
         * savePayload() builds — and omitting them is a 500 rather than a refusal, because the
         * rebuild writes an explicit NULL into `product_variants.price`. That is pre-existing and
         * is not what this case is about, so they are supplied.
         */
        foreach (['S-Red', 'M-Red'] as $name) {
            $payload['sku_' . $name] = '-' . $name;
            $payload['price_' . $name] = 50;
            $payload['stock_' . $name] = 5;
        }
        $payload['ss_confirm_variant_removal'] = 1;

        $response = $this->save($merchant, $product, $payload);
        $response->assertOk();

        $this->assertNull($response->json('ss_variant_removal'),
            'the preflight REFUSED a confirmed save that the in-place guards allowed, so checking'
            . ' both posted sets on every request is a behaviour change and not just an earlier'
            . ' one: ' . json_encode($response->json()));
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));
    }

    // =============================================================================================
    // the variants themselves
    // =============================================================================================

    public function test_a_refused_narrowing_leaves_every_variant_row_byte_identical(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        $before = $this->variantRows($product->id);
        $this->assertCount(4, $before, 'premise: four rows exist');

        $payload = $this->savePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red'];

        $this->save($merchant, $product, $payload)->assertOk();

        $after = $this->variantRows($product->id);
        $this->assertSame(array_keys($before), array_keys($after), 'a refused narrowing changed the row set');

        foreach ($before as $variant => $row) {
            $this->assertRowUnchanged($row, $after[$variant], "product_variants row $variant");
        }
    }

    /**
     * THE HARD BLOCK, which no confirmation carries past — and which must also leave the row
     * untouched, because it is the case a merchant hits deliberately after confirming.
     */
    public function test_the_unverifiable_order_history_block_changes_nothing_even_when_confirmed(): void
    {
        [$merchant, $product, $categoryId, $variants] = $this->arrange();

        /* `ssRefuseVariantRemoval()` reads `orders.product_json`, not an order-line table: the
           storefront writes the whole basket as json and records no `variant_id` on it, which is
           precisely why "this variant was never ordered" is unprovable and the block is hard. */
        \DB::table('orders')->insert([
            'product_order_id' => 'F196-' . uniqid(),
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
                'name' => 'F196 Scarf',
                'qty' => 1,
                'price' => 100,
                'final_price' => 100,
                'total_orignal_price' => 100,
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->productRow($product->id);

        $payload = $this->savePayload($product, $categoryId, $variants);
        $payload['options_datas'] = ['S-Red', 'M-Red'];
        $payload['ss_confirm_variant_removal'] = 1;

        $response = $this->save($merchant, $product, $payload);

        $response->assertOk();
        $this->assertRemovalRefusal($response,
            'a CONFIRMED narrowing was allowed although the product appears in order history, and'
            . ' confirmation does not make an unsafe operation safe');

        $this->assertRowUnchanged($before, $this->productRow($product->id), 'products row (hard block)');
        $this->assertCount(4, $this->variantRows($product->id), 'the hard block deleted rows anyway');
    }

    // =============================================================================================
    // the ordering itself, asserted against executable code
    // =============================================================================================

    /**
     * THE PREFLIGHT CALL PRECEDES EVERY SIDE EFFECT IN `update()`.
     *
     * A behavioural test can only prove the cases it drives; this one answers about the ordering
     * itself, so a later edit that moves an upload above the preflight fails here rather than in
     * production. Comments are stripped first because this project has written a test that matched
     * its own prose six times.
     */
    public function test_the_removal_preflight_runs_before_every_upload_and_save_in_update(): void
    {
        $source = $this->sourceWithoutComments(app_path('Http/Controllers/ProductController.php'));

        $update = strpos($source, 'public function update(');
        $this->assertNotFalse($update, 'update() not found');

        $preflight = strpos($source, '$this->ssVariantRemovalPreflight(', $update);
        $this->assertNotFalse($preflight,
            'update() does not call ssVariantRemovalPreflight() at all — the F196 reordering is'
            . ' absent, so every behavioural case above is passing for some other reason');

        foreach ([
            "Utility::upload_file(\$request, 'cover_image'" => 'the cover-image upload',
            "Utility::upload_file(\$request, 'preview_video'" => 'the preview-video upload',
            "Utility::upload_file(\$request, 'downloadable_product'" => 'the downloadable upload',
            '$product->save();' => 'the product save',
        ] as $needle => $what) {
            $at = strpos($source, $needle, $update);
            $this->assertNotFalse($at, "premise: $what is still in update() — the needle `$needle` is stale");
            $this->assertLessThan($at, $preflight,
                "$what runs BEFORE the removal preflight, so a refusal still persists it");
        }

        /* And it runs AFTER validation, which is the owner's stated separation of concerns: a
           malformed payload must be reported as malformed rather than as a removal refusal. */
        $validator = strpos($source, '$validator->fails()', $update);
        $this->assertNotFalse($validator, 'premise: update() still validates');
        $this->assertLessThan($preflight, $validator,
            'the preflight runs before validation, so an invalid payload is answered with a'
            . ' removal refusal instead of its validation error');
    }

    /**
     * ONE DEFINITION OF "WHAT IS BEING REMOVED", shared by the preflight and both in-place guards.
     *
     * The in-place guards are kept as defence in depth, and that is only safe while they ask the
     * same question as the preflight. The last time detection and deletion diverged in this method
     * — `getCurrentStore()` in the query, no store predicate in the delete — an operator editing
     * another tenant's product deleted rows the guard had reported as nothing.
     */
    public function test_the_removal_set_is_computed_in_exactly_one_place(): void
    {
        $source = $this->sourceWithoutComments(app_path('Http/Controllers/ProductController.php'));

        $this->assertSame(1, substr_count($source, 'private function ssVariantsBeingRemoved('),
            'the removal-set query must have exactly one definition');

        /*
         * AND NO SECOND, HAND-ROLLED COPY — counted as a SHAPE rather than as formatted text.
         *
         * The first version of this assertion counted a two-line needle including its twelve
         * spaces of indentation, which is the helper's own body. A hand-rolled copy would live
         * inside one of the rebuild branches at 24 to 28 spaces and would not have been counted,
         * so the assertion could not detect the thing its message described. The review of this
         * slice caught it. `->reject(function` is the distinctive shape of this query and nothing
         * else in the file uses it.
         */
        $this->assertSame(1, substr_count($source, '->reject(function'),
            'a second copy of the removal-set query exists; detection and deletion drift apart'
            . ' exactly when there are two of them');
    }
}
