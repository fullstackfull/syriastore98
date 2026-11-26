<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductStockService;
use App\Support\Commerce\BackorderPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * MASTER PLAN `INV-006` (GAP-182) — "الطلب المسبق الاختياري والصريح" / OPTIONAL AND EXPLICIT
 * PRE-ORDER, read with its own clause-mate `INV-004` "منع البيع فوق المتاح افتراضياً" / PREVENT
 * SELLING ABOVE AVAILABLE BY DEFAULT.
 * ==================================================================================================
 *
 * Plan text read: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:270`,
 * section 04-B (المخزون والمستودعات), the sixth bullet of `INV-001`…`INV-010`. Not a document
 * requirement — it sits between "prevent selling above available by default" (:268) and "negative
 * stock is not available for immediate sale" (:273), so what it governs is WHEN a shopper may order
 * beyond stock.
 *
 * -------------------------------------------------------------------------------------------------
 * THE HOST'S OWN MECHANISM FOR THIS IS `stock_order_status`, NOT THE `PreOrder` MODULE.
 *
 * `products.stock_order_status` and `product_variants.stock_order_status` carry a three-way merchant
 * choice — `not_allow` / `notify_customer` / `allow` — rendered as the "Allow BackOrders:" radio
 * group in `resources/views/product/{create,edit}.blade.php` and in both variant-combination views.
 * `ProductStockService::forbidsOversell()` is the single reader that matters for a decrement:
 *
 *     return ($model->stock_order_status ?? null) === 'not_allow';
 *
 * So **only the exact word `not_allow` prevents overselling.** Every other value the column can
 * hold — NULL, `''`, `0`, a typo, an unrecognised word — reads as "allow", silently. That inverts
 * both INV-004's default and INV-006's "explicit": the shopper orders beyond stock because nobody
 * ever made a choice, which is the opposite of an explicit pre-order.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT WAS MEASURED BEFORE THIS FILE EXISTED, and why each half needs its own case.
 *
 *   * `resources/views/product/create.blade.php` ships `not_allow` with `checked="checked"`, so a
 *     product created by clicking IS explicit. That is the behaviour this file preserves, not the
 *     one it changes — `test_the_web_create_form_default_is_still_what_lands` is the control.
 *   * `Api\DashboardController::AddProduct()` — the shipped Flutter admin app's product-create
 *     endpoint, `POST api/admin/addproduct` — **never writes the column at all**. Grep the method:
 *     it writes `track_stock` and `stock_status` and nothing else about backorders. So every product
 *     the admin app has ever created holds NULL and is oversellable with no merchant decision.
 *   * `ProductController::store()` and `::update()` assign `$request->stock_order_status` straight
 *     through, and the column appears in **neither** of `store()`'s two `$rules` arrays — no
 *     `required`, no `in:`. An omitted key therefore writes NULL and an arbitrary word writes
 *     itself, both reading as "allow".
 *   * `update()`'s assignment is unconditional, so a PUT that omits the key **demotes an explicit
 *     `not_allow` product to oversellable**. That is a loss of a stored merchant choice, not just a
 *     missing default, and it has its own case.
 *   * The variant writers default the column to the integer `0` when the key is absent
 *     (`ProductController::store()` variant limb, and `ssVariantValue(..., 0, ...)` in `update()`),
 *     and `0` is not `not_allow`.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE DELIBERATELY DOES NOT ASSERT, because it is an owner decision and not engineering's.
 *
 *   * **Existing rows are not touched.** Making `forbidsOversell()` answer true for a stored NULL
 *     would change the behaviour of every product already created through the admin app — a live
 *     stock-rule change on real data, which CLAUDE.md §2 reserves to the owner. The fix under test
 *     is on the WRITE side only: a product written from here on carries one of the three words.
 *   * **The `PreOrder` module is a separate surface and is not under test here.** It adds a
 *     per-store master switch (`pre_orders.enable_pre_order`, default `'off'`) and per-product rows
 *     (`pre_order_settings.status`, default 0) with an `available_date`, and no WorkDo module loads
 *     in this suite, so a case driving it would be vacuous. It is reported as present rather than
 *     asserted here.
 */
class BackorderChoiceIsExplicitTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A merchant with a store, a category and a plan.
     *
     * The plan is not optional: without it `ProductController::store()` answers HTTP 500 with
     * `Attempt to read property "max_products" on null` for every principal, and a "the column is
     * explicit" assertion would then hold against a request that never reached a writer — trap 1 in
     * the skill's list. The premise assertions in each case are what report that.
     *
     * Store id 1 is burned first for the reason `ProductShippingClassOwnershipTest::twoTenants()`
     * records: `getCurrentStore()` returns the literal 1 for a `super admin` with an empty
     * `current_store`, so a store under test that IS store 1 collapses several distinct numbers into
     * one.
     *
     * @return array{merchant:User,store:\App\Models\Store,category:int}
     */
    private function tenant(): array
    {
        $decoy = $this->makeMerchant();
        $this->makeStore($decoy);

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission(User::find($merchant->id), 'Create Product');
        $this->grantPermission(User::find($merchant->id), 'Edit Products');

        $merchant = User::find($merchant->id);
        $merchant->plan_id = \DB::table('plans')->insertGetId([
            'name' => 'inv006-plan-' . uniqid(),
            'price' => 0,
            'themes' => '[]',
            'max_products' => 1000,
            'storage_limit' => '100',
            'shipping_method' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $merchant->save();

        return [
            'merchant' => User::find($merchant->id),
            'store' => $store,
            'category' => (int) \DB::table('categories')->insertGetId([
                'name' => 'Textiles',
                'slug' => 'textiles-' . uniqid(),
                'trending' => 0,
                'status' => 1,
                'store_id' => $store->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
        ];
    }

    /** Read raw: `Product` is `Cachable`, so an Eloquent read can serve a pre-write instance. */
    private function stored(int $productId)
    {
        return \DB::table('products')->where('id', $productId)->value('stock_order_status');
    }

    private function makeTrackedProduct($store, User $merchant, $backorder): Product
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
            'status' => 1,
            'variant_product' => 0,
            'stock_order_status' => $backorder,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE PREMISES

    /**
     * ONLY THE EXACT WORD `not_allow` PREVENTS AN OVERSELL — measured against the shipped reader.
     *
     * This is the premise the whole file rests on. If `ProductStockService` is ever widened to read
     * NULL as "refuse", the defect this file pins stops being a defect and the cases below must be
     * re-read rather than kept passing.
     */
    public function test_only_the_recognised_refusal_word_stops_an_oversell(): void
    {
        $f = $this->tenant();
        $service = app(ProductStockService::class);

        foreach ([null, '', '0', 'allow', 'notify_customer', 'nope'] as $value) {
            $product = $this->makeTrackedProduct($f['store'], $f['merchant'], $value);
            $product->product_stock = 1;
            $product->save();

            $outcome = $service->decrementProductStock($product, 5);

            $this->assertSame(
                ProductStockService::APPLIED,
                $outcome['reason'],
                'a product whose stock_order_status is ' . var_export($value, true) . ' refused an '
                . 'oversell, so this file\'s premise — that only `not_allow` refuses — is wrong and '
                . 'every case below needs re-reading'
            );

            $this->assertLessThan(0, (int) $outcome['remaining'],
                'the decrement was "applied" without the stock going negative, so this case is not '
                . 'measuring an oversell at all');
        }

        $refusing = $this->makeTrackedProduct($f['store'], $f['merchant'], BackorderPolicy::NOT_ALLOW);
        $refusing->product_stock = 1;
        $refusing->save();

        $this->assertSame(
            ProductStockService::INSUFFICIENT,
            $service->decrementProductStock($refusing, 5)['reason'],
            'premise: `not_allow` did NOT refuse an oversell, so the reader is not what this file '
            . 'believes it is'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE DEFECT

    /**
     * `POST api/admin/addproduct` — THE SHIPPED FLUTTER ADMIN APP'S CREATE ENDPOINT.
     *
     * The worst of the four, because it is not a hostile request: the app simply does not send the
     * key, so every product it creates is oversellable with no merchant decision anywhere.
     */
    public function test_the_admin_api_create_stores_an_explicit_backorder_choice(): void
    {
        $f = $this->tenant();
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $f['merchant']->createToken('flutter')->plainTextToken
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
            'track_stock' => 1,
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ]);

        $this->assertNotSame(404, $response->status(), 'api/admin/addproduct is not routed');
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('field is required', $body,
            'the VALIDATOR refused the request, so no writer was reached. Body: ' . substr($body, 0, 300));

        $created = \DB::table('products')->where('name', 'ApiScarf')->first();
        $this->assertNotNull($created,
            'premise: no product was created, so nothing was measured. Body: ' . substr($body, 0, 300));
        $this->assertSame(1, (int) $created->track_stock,
            'premise: the product does not track stock, so the backorder choice governs nothing');

        $this->assertSame(
            BackorderPolicy::NOT_ALLOW,
            (string) $created->stock_order_status,
            'THE ADMIN API CREATED A STOCK-TRACKED PRODUCT WITH NO BACKORDER CHOICE. '
            . '`Api\DashboardController::AddProduct()` never writes `stock_order_status`, and '
            . '`ProductStockService::forbidsOversell()` reads anything but the word `not_allow` as '
            . '"allow" — so this product oversells, with nobody having decided that. Master plan '
            . 'INV-004 (prevent selling above available BY DEFAULT) and INV-006 (pre-order is '
            . 'OPTIONAL AND EXPLICIT), GAP-182.'
        );
    }

    /** `POST api/admin/updateproduct` must not demote an explicit refusal either. */
    public function test_the_admin_api_update_keeps_an_explicit_refusal(): void
    {
        $f = $this->tenant();
        $product = $this->makeTrackedProduct($f['store'], $f['merchant'], BackorderPolicy::NOT_ALLOW);

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $f['merchant']->createToken('flutter')->plainTextToken
        )->post('/api/admin/updateproduct', [
            'store_id' => $f['store']->id,
            'product_id' => $product->id,
            'id' => $product->id,
            'name' => 'Scarf',
            'category_id' => $f['category'],
            'description' => 'a description',
            'price' => 10,
            'product_stock' => 7,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'track_stock' => 1,
        ]);

        $this->assertNotSame(404, $response->status(), 'api/admin/updateproduct is not routed');

        $this->assertSame(
            BackorderPolicy::NOT_ALLOW,
            (string) $this->stored((int) $product->id),
            'an admin-API update that said nothing about backorders changed the stored choice'
        );
    }

    /**
     * THE WEB WRITER TAKES AN ARBITRARY WORD, and an arbitrary word reads as "allow".
     *
     * `stock_order_status` is in neither of `store()`'s `$rules` arrays, so there is no `in:` rule
     * between the request and the column.
     */
    public function test_an_unrecognised_backorder_word_is_not_stored(): void
    {
        $f = $this->tenant();
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->actingAs($f['merchant'])->post(route('product.store'), [
            'name' => 'WordScarf',
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
            'stock_order_status' => 'allow_everything',
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ]);

        $this->assertNotSame(404, $response->status());
        $created = \DB::table('products')->where('name', 'WordScarf')->first();
        $this->assertNotNull($created, 'premise: no product was created. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertContains(
            (string) $created->stock_order_status,
            BackorderPolicy::CHOICES,
            'an unrecognised backorder word was stored verbatim. Every value but `not_allow` reads '
            . 'as "allow" at ProductStockService::forbidsOversell(), so an unrecognised word is a '
            . 'silent permission to oversell that no merchant chose — INV-006, GAP-182.'
        );
        $this->assertSame(BackorderPolicy::NOT_ALLOW, (string) $created->stock_order_status,
            'an unrecognised word must fall back to the strict choice the create form itself '
            . 'ships checked, not to a permissive one');
    }

    /** The web create writer, with the key simply absent. */
    public function test_a_web_create_with_no_backorder_key_is_not_left_implicit(): void
    {
        $f = $this->tenant();
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->actingAs($f['merchant'])->post(route('product.store'), [
            'name' => 'SilentScarf',
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
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ]);

        $this->assertNotSame(404, $response->status());
        $created = \DB::table('products')->where('name', 'SilentScarf')->first();
        $this->assertNotNull($created, 'premise: no product was created. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(BackorderPolicy::NOT_ALLOW, (string) $created->stock_order_status,
            'a stock-tracked product was created with no backorder choice at all, so it oversells '
            . 'by omission — INV-004\'s "by default", inverted');
    }

    /**
     * AN UPDATE THAT SAYS NOTHING MUST NOT DEMOTE A STORED REFUSAL.
     *
     * `ProductController::update()` assigns `$request->stock_order_status` unconditionally, so an
     * omitted key wrote NULL over an explicit `not_allow`. That is a merchant's stored decision
     * being lost, which is a different defect from a missing default.
     */
    public function test_a_web_update_that_omits_the_key_keeps_the_stored_refusal(): void
    {
        $f = $this->tenant();
        $product = $this->makeTrackedProduct($f['store'], $f['merchant'], BackorderPolicy::NOT_ALLOW);

        $this->assertSame(BackorderPolicy::NOT_ALLOW, (string) $this->stored((int) $product->id),
            'premise: the fixture does not start at the refusal, so "it is still not_allow" cannot '
            . 'be told apart from "it never was"');

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
        ]);

        $this->assertNotSame(404, $response->status());
        $this->assertNotSame(500, $response->status(), 'the save 500d, so the column assertion '
            . 'holds either way. Body: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(BackorderPolicy::NOT_ALLOW, (string) $this->stored((int) $product->id),
            'AN UPDATE THAT SAID NOTHING ABOUT BACKORDERS TURNED A "DO NOT ALLOW" PRODUCT INTO AN '
            . 'OVERSELLABLE ONE. The merchant\'s explicit choice was lost to an unconditional '
            . 'assignment of an absent request key — INV-006, GAP-182.'
        );
    }

    /**
     * THE VARIANT *CREATE* PATH — two further writers, neither reachable by any case above.
     *
     * `store()` splits on `$request->variant_product`, so a simple-product create never touches the
     * variant limb's parent write, and the per-combination writer defaulted the column to the
     * INTEGER `0`. Both were found uncovered by the per-site revert measurement (each guarded line
     * restored on its own, the file re-run): three of seven sites killed a case and four survived.
     *
     * The create keys here are `store()`'s own, not the edit page's: `attribute_no`,
     * `for_variation_<id>`, `visible_attribute_<id>` and a flat `options` array, with each variant
     * named after the raw option term — so the per-combination key is `stock_order_status_S`.
     */
    public function test_the_variant_create_path_stores_an_explicit_choice(): void
    {
        $f = $this->tenant();
        \Illuminate\Support\Facades\Storage::fake('public');

        $attributeId = (int) \DB::table('product_attributes')->insertGetId([
            'name' => 'Size',
            'slug' => 'size-' . uniqid(),
            'store_id' => $f['store']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
            'attribute_no' => [$attributeId],
            'for_variation_' . $attributeId => 1,
            /* F84: the visible-attribute key is an unguarded array read, so omitting it 500s. */
            'visible_attribute_' . $attributeId => 1,
            'options' => ['S', 'M'],
            /*
             * `variation_option_<term>` MUST CARRY `manage_stock`, or the per-combination writer is
             * never reached: `store()` guards it with `in_array('manage_stock', $variation_option)`
             * and takes an `else` limb that stores the untracked literal instead. The first version
             * of this case omitted it and the variant came back `''` — the assertion below is what
             * reported that, rather than the case passing against a writer it never ran.
             */
            'variation_option_S' => ['manage_stock'],
            'variation_option_M' => ['manage_stock'],
            /* `S` says nothing — the defect. `M` says `allow` — the control, in the same request,
               so "refuses everything" is distinguishable from "fills in what was missing". */
            'stock_order_status_M' => BackorderPolicy::ALLOW,
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ]);

        $body = (string) $response->getContent();
        $created = \DB::table('products')->where('name', 'VariantScarf')->first();
        $this->assertNotNull($created, 'premise: no product was created, so neither writer was '
            . 'reached. Body: ' . substr($body, 0, 300));

        $this->assertSame(BackorderPolicy::NOT_ALLOW, (string) $created->stock_order_status,
            'the variant-CREATE limb wrote no explicit backorder choice onto the PARENT product. '
            . 'That is a different writer from the simple-product limb — `store()` splits on '
            . '`$request->variant_product` — and the per-site revert showed it uncovered.');

        $variants = \DB::table('product_variants')->where('product_id', $created->id)->get()->keyBy('variant');
        $this->assertGreaterThan(0, $variants->count(), 'premise: no variant rows were written, so '
            . 'the per-combination writer was never reached. Body: ' . substr($body, 0, 300));
        $this->assertArrayHasKey('S', $variants->all(),
            'premise: the variant named after the posted option term does not exist');

        $this->assertSame(BackorderPolicy::NOT_ALLOW, (string) $variants['S']->stock_order_status,
            'a stock-managed VARIANT was created with no backorder choice. This writer defaulted '
            . 'the column to the integer `0`, which `ProductStockService::forbidsOversell()` reads '
            . 'as "oversell permitted" — INV-006, GAP-182.');

        $this->assertSame(BackorderPolicy::ALLOW, (string) $variants['M']->stock_order_status,
            'the explicit `allow` on the other combination in the same request was not honoured, '
            . 'so the guard is overwriting choices rather than filling in missing ones');
    }

    /**
     * THE VARIANT *UPDATE* WRITER — the sixth site, and the only one whose default came through a
     * helper.
     *
     * `ssVariantValue($request, $key, $stored, 0, $isNew)` answers the stored value for an absent
     * key on an existing variant and the literal `0` for a new one, and `0` is not a recognised
     * choice. The fixture therefore starts a variant at the ambiguous `0` and drives a save that
     * says nothing about it.
     */
    public function test_the_variant_update_writer_normalises_an_ambiguous_stored_value(): void
    {
        $f = $this->tenant();

        $attributeId = (int) \DB::table('product_attributes')->insertGetId([
            'name' => 'Size',
            'slug' => 'size-' . uniqid(),
            'store_id' => $f['store']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $optionIds = [];
        foreach (['S', 'M'] as $term) {
            $optionIds[] = (int) \DB::table('product_attribute_options')->insertGetId([
                'attribute_id' => $attributeId,
                'terms' => $term,
                'store_id' => $f['store']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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
            'status' => 1,
            'variant_product' => 1,
            'attribute_id' => (string) $attributeId,
            /* "1" as a STRING: the development fixture stores "on", and `"on" == 1` is false on
               PHP 8.3, so a product copied from that fixture never opens the rebuild gate and the
               variant writer is never reached. */
            'product_attribute' => json_encode([[
                'attribute_id' => (string) $attributeId,
                'values' => [implode('|', $optionIds)],
                'visible_attribute_' . $attributeId => '1',
                'for_variation_' . $attributeId => '1',
            ]]),
            'store_id' => $f['store']->id,
            'created_by' => $f['merchant']->id,
        ]);

        foreach (['S', 'M'] as $n => $variant) {
            \App\Models\ProductVariant::forceCreate([
                'product_id' => $product->id,
                'variant' => $variant,
                'variation_price' => 100 + $n,
                'price' => 200 + $n,
                'stock' => 30 + $n,
                'low_stock_threshold' => 0,
                'sku' => sprintf('SKU-%04d', $n),
                'shipping' => 'same_as_parent',
                'variation_option' => '',
                /* The ambiguous value the old writer produced. */
                'stock_order_status' => '0',
                'store_id' => $f['store']->id,
            ]);
        }

        $this->assertSame('0', (string) \DB::table('product_variants')
            ->where('product_id', $product->id)->where('variant', 'S')->value('stock_order_status'),
            'premise: the variant does not start at the ambiguous `0`, so "it is now not_allow" '
            . 'cannot be told apart from "it always was"');

        $payload = [
            'name' => 'Scarf',
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
            'attribute_no' => [$attributeId],
            'for_variation_' . $attributeId => 1,
            'visible_attribute_' . $attributeId => 1,
            'attribute_options_' . $attributeId => ['S', 'M'],
            'options_datas' => ['S', 'M'],
        ];
        foreach (['S', 'M'] as $variant) {
            $payload['product_sku_' . $variant] = '-' . $variant;
            $payload['product_weight_' . $variant] = '';
            $payload['product_variation_price_' . $variant] = '';
            $payload['product_sale_price_' . $variant] = '';
            $payload['product_description_' . $variant] = '';
            $payload['product_stock_' . $variant] = '';
            $payload['low_stock_threshold_' . $variant] = '';
            $payload['shipping_id_' . $variant] = '';
        }
        /* `S` says nothing; `M` says `notify_customer` — the control in the same request. */
        $payload['stock_order_status_M'] = BackorderPolicy::NOTIFY_CUSTOMER;

        $response = $this->actingAs($f['merchant'])->put(route('product.update', $product->id), $payload);
        $this->assertNotSame(500, $response->status(), 'the save 500d, so the column assertions '
            . 'hold either way. Body: ' . substr((string) $response->getContent(), 0, 300));

        $stored = \DB::table('product_variants')->where('product_id', $product->id)
            ->get()->keyBy('variant');

        $this->assertSame(BackorderPolicy::NOT_ALLOW, (string) $stored['S']->stock_order_status,
            'the variant-UPDATE writer carried the ambiguous `0` forward on a row it was '
            . 'rewriting, leaving the variant oversellable with no choice made — INV-006, GAP-182');

        $this->assertSame(BackorderPolicy::NOTIFY_CUSTOMER, (string) $stored['M']->stock_order_status,
            'the explicit choice on the other combination in the same request was not stored');

        /*
         * AND THE PARENT ROW ON THE SAME REQUEST. `update()`'s parent write sits AFTER the
         * simple/variant `if` chain closes, so it is on the common path and a variant product's
         * parent goes through it too -- read from the source rather than assumed, because a guard
         * that only covered the simple branch would leave half the writes open. The fixture's
         * parent starts at NULL (the state every admin-app product is in), and a save the merchant
         * asked for resolves it.
         */
        $this->assertSame(BackorderPolicy::NOT_ALLOW, (string) $this->stored((int) $product->id),
            'the parent row of a VARIANT product was left in the ambiguous state by an update, so '
            . 'the parent write is not on the common path after all');
    }

    /**
     * `POST api/admin/updateproduct` ON AN AMBIGUOUS ROW — the seventh site.
     *
     * `test_the_admin_api_update_keeps_an_explicit_refusal` cannot cover this writer: with the
     * writer absent the stored `not_allow` survives untouched, so that case passes either way (the
     * per-site revert measured it surviving). What separates the two readings is a row whose stored
     * value is the NULL every pre-change admin-app product carries: a save that says nothing must
     * resolve it, because the merchant is rewriting the row.
     */
    public function test_the_admin_api_update_resolves_an_ambiguous_stored_value(): void
    {
        $f = $this->tenant();
        $product = $this->makeTrackedProduct($f['store'], $f['merchant'], null);

        $this->assertNull($this->stored((int) $product->id),
            'premise: the fixture does not start at NULL, so this case is not measuring the state '
            . 'every product the admin app created before this change is in');

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $f['merchant']->createToken('flutter')->plainTextToken
        )->post('/api/admin/updateproduct', [
            'store_id' => $f['store']->id,
            'product_id' => $product->id,
            'id' => $product->id,
            'name' => 'Scarf',
            'category_id' => $f['category'],
            'description' => 'a description',
            'price' => 10,
            'product_stock' => 7,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'track_stock' => 1,
        ]);

        $this->assertNotSame(404, $response->status(), 'api/admin/updateproduct is not routed');

        $this->assertSame(BackorderPolicy::NOT_ALLOW, (string) $this->stored((int) $product->id),
            'the admin-API update rewrote the row and left `stock_order_status` NULL, so the '
            . 'product still oversells with no merchant decision — INV-006, GAP-182'
        );
    }

    // ══════════════════════════════════════════════════════════════════ THE CONTROLS: NO LOCKOUT

    /**
     * EVERY RECOGNISED CHOICE STILL LANDS, both ways round.
     *
     * Without this the file passes against a writer that stores `not_allow` unconditionally, which
     * is not "optional and explicit" — it is the backorder feature removed, and removing a
     * merchant-facing behaviour is exactly what `ProductStockService`'s own docblock says this fix
     * must not do.
     */
    public function test_each_recognised_backorder_choice_is_still_written(): void
    {
        $f = $this->tenant();

        foreach (BackorderPolicy::CHOICES as $choice) {
            $product = $this->makeTrackedProduct($f['store'], $f['merchant'], BackorderPolicy::NOT_ALLOW);

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
                'stock_order_status' => $choice,
            ]);

            $this->assertNotSame(500, $response->status(), 'the save 500d for ' . $choice);

            $this->assertSame($choice, (string) $this->stored((int) $product->id),
                'the merchant chose ' . $choice . ' and it was not stored, so the guard is '
                . 'overwriting a real choice rather than filling in a missing one');
        }
    }

    /**
     * AND THE ADMIN API CAN STILL SET A PERMISSIVE CHOICE EXPLICITLY.
     *
     * The point of INV-006 is that the pre-order is optional, so the permissive answer has to remain
     * reachable — it just has to be said out loud.
     */
    public function test_the_admin_api_can_still_allow_backorders_explicitly(): void
    {
        $f = $this->tenant();
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $f['merchant']->createToken('flutter')->plainTextToken
        )->post('/api/admin/addproduct', [
            'store_id' => $f['store']->id,
            'name' => 'ApiAllowScarf',
            'category_id' => $f['category'],
            'description' => 'a description',
            'price' => 10,
            'product_stock' => 7,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'track_stock' => 1,
            'stock_order_status' => BackorderPolicy::ALLOW,
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ]);

        $this->assertNotSame(404, $response->status());
        $created = \DB::table('products')->where('name', 'ApiAllowScarf')->first();
        $this->assertNotNull($created, 'premise: no product was created. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(BackorderPolicy::ALLOW, (string) $created->stock_order_status,
            'an explicit "allow" from the admin app was not honoured, so this change removed the '
            . 'backorder feature instead of making it explicit');
    }

    /**
     * A PRODUCT THAT DOES NOT TRACK STOCK KEEPS THE EMPTY-STRING LITERAL the rest of the
     * application reads.
     *
     * `ProductController` writes `''` on that limb and `Cart`/`ApiController` branch on
     * `track_stock == 0 && stock_status == 'out_of_stock'` instead. Writing `not_allow` there would
     * be a change to a surface INV-006 does not govern.
     */
    public function test_an_untracked_product_is_left_at_the_existing_literal(): void
    {
        $f = $this->tenant();
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->actingAs($f['merchant'])->post(route('product.store'), [
            'name' => 'UntrackedScarf',
            'category_id' => $f['category'],
            'status' => 1,
            'variant_product' => 0,
            'price' => 10,
            'sale_price' => 0,
            'cost_price' => 5,
            'trending' => 0,
            'track_stock' => 0,
            'stock_status' => 'in_stock',
            'cover_image' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'product_image' => [\Illuminate\Http\UploadedFile::fake()->image('one.jpg')],
        ]);

        $this->assertNotSame(404, $response->status());
        $created = \DB::table('products')->where('name', 'UntrackedScarf')->first();
        $this->assertNotNull($created, 'premise: no product was created. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame('', (string) $created->stock_order_status,
            'an untracked product no longer carries the empty-string literal the writers have '
            . 'always stored on that limb');
    }

    /** The shipped create form's own default is what a clicked create still lands. */
    public function test_the_web_create_form_default_is_still_what_lands(): void
    {
        $source = file_get_contents(resource_path('views/product/create.blade.php'));
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) $source);

        $this->assertMatchesRegularExpression(
            '/value="not_allow"\s+name="stock_order_status"\s*\n?\s*class="[^"]*"\s*checked="checked"/',
            (string) $source,
            'the create form no longer ships `not_allow` checked, so the default this file aligns '
            . 'the other writers to has moved and the alignment needs re-deciding'
        );
    }
}
