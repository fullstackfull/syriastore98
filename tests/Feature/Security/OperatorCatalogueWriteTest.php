<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F206 — THE PLATFORM OPERATOR READS A MERCHANT'S CATALOGUE; THEY DO NOT WRITE IT.
 * =================================================================================================
 *
 * The owner's decision, 2026-10-30. Before it, F195 had made a dead operator exemption in
 * `ProductController::update()` reachable — correctly, by the project's own operator-first rule —
 * and the repository's own MariaDB script recorded the consequence as a capability change rather
 * than an accident. The owner has now decided the capability itself: **read and diagnose, yes;
 * write, no, absent explicit and specific delegation.**
 *
 * WHY THIS FILE EXISTS RATHER THAN A FEW ASSERTIONS ADDED TO AN EXISTING ONE
 * -------------------------------------------------------------------------------------------------
 * The operator reaches a merchant's catalogue through **five different mechanisms**, and only one
 * of them is the permission helper that the first draft of this fix was going to narrow:
 *
 *   A. `MerchantPermissions::allowsAny()`'s `['*']` wildcard plus an ownership exemption —
 *      `ProductController::update`, `Api\DashboardController::DeleteVariant`, and (through
 *      `VariantRemovalPolicy::authorize`'s own operator limb) `UpdateVariant` and
 *      `product_attribute_delete`.
 *   B. **No permission check at all** — `Api\DashboardController::{UpdateProduct, AddProduct,
 *      DeleteProduct}`, which stand on `resolveOwnedStore()` alone, and that drops its `created_by`
 *      predicate for the operator.
 *   C. `ProductController`'s two private helpers — the media writers.
 *   D. `BulkDeleteTenantGuard`, which returns `$next($request)` for the operator **before** its
 *      permission map, its model map and its tenant predicate. The widest door, and it touches
 *      none of the above.
 *   E. `SettingController::currencySettings`, which rewrites **every product price and every
 *      variant price in a store** through `StoreCurrencyConversionService`.
 *
 * A fix scoped to mechanism A would have left eight doors open while reading as complete. That is
 * why each door gets a case here, and why the cases drive the real routes rather than the guard.
 *
 * THE CONTROL CASES ARE NOT OPTIONAL. Every refusal is paired with a merchant who still succeeds,
 * because a guard that refuses everybody is indistinguishable from one that works, and this project
 * has already shipped one test that passed because its payload failed validation before
 * authorization was ever consulted.
 */
final class OperatorCatalogueWriteTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The category row `plainProduct()` created; the save payload must name a real one (FK). */
    private int $categoryId = 0;

    /**
     * `currency_rates` has NO MIGRATION — it is one of the GATE-F tables that live code reads and
     * writes while nothing declares them, and the draft sits in
     * `database/migrations/_gate_f_pending/0008_draft_create_currency_rates_table.php.draft`.
     *
     * Without it EVERY Admin API catalogue read answers HTTP 500 with `no such table:
     * currency_rates` — for the merchant exactly as for the operator, measured both ways. So a
     * read test written without this would either assert a 500 and call it "readable", or compare
     * two 500s and call that "unchanged". Building it here is the same thing
     * `WebSettingsSecretsTest` and `ChatgptKeyDisclosureTest` already do, for the same reason.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! \Illuminate\Support\Facades\Schema::hasTable('currency_rates')) {
            \Illuminate\Support\Facades\Schema::create('currency_rates', function ($t) {
                $t->id();
                $t->unsignedBigInteger('store_id')->nullable();
                $t->string('currency_code');
                $t->string('currency_name')->nullable();
                $t->string('symbol')->nullable();
                $t->decimal('rate_per_usd', 20, 8)->default(1);
                $t->boolean('is_active')->default(1);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    /** The merchant, their store, a product in it, and a variant of that product. */
    private function arrange(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $merchant = User::find($merchant->id);

        $this->grantPermission($merchant, 'Edit Products');
        $this->grantPermission($merchant, 'Manage Products');
        $this->grantPermission($merchant, 'Delete Products');

        /* A PLAN, or `AddProduct` answers HTTP 500 for EVERY principal with
           `Attempt to read property "max_products" on null` — measured for the merchant exactly
           as for the operator. Without it that case asserts a 500 and calls it a refusal, which
           is why the whole-guard revert check did not move it. Same fixture `WebSettingsSecretsTest`
           builds, for the same reason. */
        $merchant->plan_id = \DB::table('plans')->insertGetId([
            'name' => 'f206-plan-' . uniqid(),
            'price' => 0,
            'themes' => '[]',
            'max_products' => 1000,
            'storage_limit' => '100',
            'shipping_method' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $merchant->save();
        $merchant = User::find($merchant->id);

        $product = Product::forceCreate([
            'name' => 'F206 Scarf',
            'slug' => 'f206-' . uniqid(),
            'description' => 'x',
            'price' => 100,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 25,
            'status' => 1,
            'variant_product' => 1,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $variant = ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'S-Red',
            'variation_price' => 101,
            'price' => 201,
            'stock' => 31,
            'sku' => 'F206-0001',
            'variation_option' => '',
            'store_id' => $store->id,
        ])->fresh();

        return [$merchant, $store, $product->fresh(), $variant];
    }

    /**
     * A product the `update()` door can actually save, and the payload that saves it.
     *
     * `variant_product = 0` deliberately: the variant branch needs the whole attribute machinery
     * (`attribute_no`, `for_variation_N`, `attribute_options_N`, and a `product_*_KEY` quintet per
     * combination) and a payload missing any of it is refused by the VALIDATOR, before
     * authorization is ever consulted. A first version of this file did exactly that, and the
     * operator's refusal test passed while the MERCHANT'S control test failed — the test was
     * measuring the validator, not the guard. That is the shape §5 forbids and the shape the F195
     * review already caught once in this repository.
     *
     * The required set is read from the rule branch at ProductController.php:1757-1776: name,
     * category_id, status, variant_product, price, cost_price — with `cost_price <= price`.
     */
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

    private function plainProduct(Store $store, User $merchant): Product
    {
        $this->categoryId = $this->makeCategory($store->id);

        return Product::forceCreate([
            'name' => 'F206 Plain',
            'slug' => 'f206p-' . uniqid(),
            'description' => 'x',
            'price' => 100,
            'cost_price' => 40,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 25,
            'status' => 1,
            'variant_product' => 0,
            'category_id' => $this->categoryId,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ])->fresh();
    }

    /**
     * @return array<string,mixed>
     *
     * `cost_price` is derived from `price`, not fixed — and that is a correction, not a tidy-up.
     * The rule is `cost_price <= price`, so a hardcoded 40 against a `price` of 1 is refused BY THE
     * VALIDATOR before authorization is consulted. The operator's refusal test passed that way and
     * was measuring the validator: driven directly, the operator's save actually returns
     * `{"flag":"success"}` and rewrites the price from 100 to 1. Third time this payload-versus-
     * validator shape has appeared in this repository.
     */
    private function savePayload(string $name, int $price = 150, int $stock = 25): array
    {
        return [
            'name' => $name,
            'category_id' => $this->categoryId,
            'status' => 1,
            'variant_product' => 0,
            'price' => $price,
            'sale_price' => '',
            'cost_price' => min(40, $price),
            'product_weight' => '',
            'product_stock' => $stock,
            'track_stock' => 1,
            'stock_status' => 'in_stock',
            'trending' => 0,
            'description' => 'x',
        ];
    }

    /** A platform operator with no `current_store` of their own — the real shape. */
    private function operator(): User
    {
        return $this->makePlatformAdmin();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function api(User $user, string $uri, array $payload, string $method = 'postJson')
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($user))
            ->{$method}($uri, $payload);
    }

    /**
     * `status: 0` is this API's refusal, and it rides on HTTP 200 so no client is logged out.
     *
     * THE 5xx CLAUSE IS NOT BELT-AND-BRACES; WITHOUT IT THIS HELPER ACCEPTS A CRASH AS A REFUSAL.
     * `$response->json('status')` is `null` on a 500, and `(int) null` is `0` — exactly the value
     * a refusal carries. Measured: `AddProduct` answered HTTP 500 for every principal
     * (`Attempt to read property "max_products" on null`, a missing plan in the fixture), and this
     * assertion passed. The whole-guard revert check is what exposed it: that one case did not
     * move when the guard was neutered, because it had never been reaching the guard at all.
     *
     * A refusal must also not be a 500 on its own merits — an operator meeting a crash cannot tell
     * a withdrawn capability from a broken server.
     */
    private function assertApiRefused($response, string $why): void
    {
        $this->assertLessThan(500, $response->getStatusCode(),
            $why . ' — and it answered a SERVER ERROR rather than a refusal, so this assertion '
            . 'would otherwise accept a crash as a control: '
            . substr((string) $response->getContent(), 0, 260));

        $this->assertSame(0, (int) $response->json('status'),
            $why . ' — body: ' . substr((string) $response->getContent(), 0, 260));

        $this->assertNotSame(401, $response->getStatusCode(),
            'a NEW 401 appeared on a path the shipped Flutter app calls, which forces a logout');
    }

    private function freshProduct($id): ?Product
    {
        return Product::withoutGlobalScopes()->find($id);
    }

    // =============================================================================================
    // MECHANISM A — the wildcard plus an ownership exemption
    // =============================================================================================

    /**
     * The web save door. This is the one F195 opened and the one the owner's decision names first.
     *
     * The refusal must be the AJAX shape, NOT a redirect: `product/edit.blade.php` submits by
     * `$.ajax` and its success handler reads `data.flag` / `data.msg` without navigating, so a
     * redirect would reach the merchant as a blank toast.
     */
    public function test_the_operator_cannot_save_a_merchants_product_through_the_web_editor(): void
    {
        [$merchant, $store] = $this->arrange();
        $product = $this->plainProduct($store, $merchant);

        $response = $this->actingAs($this->operator())->put(
            route('product.update', $product->id),
            $this->savePayload('OPERATOR RENAMED THIS', 1, 0)
        );

        $this->assertStringNotContainsString('"flag":"success"', (string) $response->getContent(),
            'the operator saved a merchant product through the web editor');

        $after = $this->freshProduct($product->id);
        $this->assertSame('F206 Plain', (string) $after->name, 'the operator renamed a merchant product');
        $this->assertSame(100, (int) $after->price, 'the operator rewrote a merchant price');
        $this->assertSame(25, (int) $after->product_stock, 'the operator rewrote a merchant stock level');
    }

    /** And the merchant must still be able to save, or the guard is a lockout rather than a control. */
    public function test_the_merchant_can_still_save_their_own_product(): void
    {
        [$merchant, $store] = $this->arrange();
        $product = $this->plainProduct($store, $merchant);

        $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->savePayload('MERCHANT RENAMED THIS')
        );

        $this->assertSame('MERCHANT RENAMED THIS', (string) $this->freshProduct($product->id)->name,
            'the owner of the product can no longer save it, so the refusal above proves nothing');
    }

    public function test_the_operator_cannot_delete_a_variant_through_the_web_trash_door(): void
    {
        [, , , $variant] = $this->arrange();

        $response = $this->actingAs($this->operator())->delete(
            route('product.attribute.delete', $variant->id),
            ['ss_confirm_variant' => \App\Services\VariantRemovalPolicy::consentToken($variant)]
        );

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode(),
            'the operator deleted a merchant variant through the trash door — body: '
            . substr((string) $response->getContent(), 0, 200));
        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->find($variant->id),
            'the merchant variant is gone');
    }

    public function test_the_operator_cannot_delete_a_variant_through_the_admin_api(): void
    {
        [, $store, , $variant] = $this->arrange();

        $response = $this->api($this->operator(), '/api/admin/deletevariant', [
            'store_id' => $store->id,
            'variant_id' => $variant->id,
            'confirm_variant' => \App\Services\VariantRemovalPolicy::consentToken($variant),
        ], 'deleteJson');

        $this->assertApiRefused($response, 'the operator deleted a merchant variant through the API');
        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->find($variant->id),
            'the merchant variant is gone');
    }

    /**
     * The rename door. It writes `variant`, which is the key BOTH variant rebuilds match their
     * deletes on — so an operator rename arms the merchant's next ordinary save to hard-delete the
     * row. That is why a rename belongs in a write block even though it deletes nothing itself.
     */
    public function test_the_operator_cannot_rename_a_merchants_variant_through_the_admin_api(): void
    {
        [, $store, , $variant] = $this->arrange();

        $response = $this->api($this->operator(), '/api/admin/updatevariant', [
            'store_id' => $store->id,
            'productvariant_id' => $variant->id,
            'name' => 'OPERATOR-RENAMED',
        ]);

        $this->assertApiRefused($response, 'the operator renamed a merchant variant');
        $this->assertSame('S-Red',
            (string) ProductVariant::withoutGlobalScopes()->find($variant->id)->variant,
            'the rename landed');
    }

    // =============================================================================================
    // MECHANISM B — the API doors with no permission check at all
    // =============================================================================================

    /**
     * `UpdateProduct` is the highest-value write in the whole enumeration: it writes `price`,
     * `sale_price`, `cost_price`, `product_stock`, `track_stock`, `stock_status`, `status`, `name`,
     * `category_id` and `tax_id`, and it had **no permission check of any kind** — only
     * `resolveOwnedStore()`, which drops its `created_by` predicate for the operator. It is not
     * reachable through `MerchantPermissions` at all, so a fix scoped there would have missed it.
     */
    public function test_the_operator_cannot_rewrite_a_merchants_price_and_stock_through_the_admin_api(): void
    {
        [, $store, $product] = $this->arrange();

        /* A COMPLETE payload. An incomplete one is refused by the validator — measured:
           "The category id field is required." — and the test then passes while proving nothing
           about authorization. Rules read from DashboardController.php:2903-2925. */
        $response = $this->api($this->operator(), '/api/admin/updateproduct', [
            'store_id' => $store->id,
            'product_id' => $product->id,
            'name' => 'OPERATOR RENAMED',
            'category_id' => $product->category_id ?: $this->makeCategory($store->id),
            'description' => 'x',
            'price' => 1,
            'product_stock' => 0,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
        ]);

        $this->assertApiRefused($response, 'the operator rewrote a merchant product through the API');

        $after = $this->freshProduct($product->id);
        $this->assertSame(100, (int) $after->price, 'the operator rewrote a merchant price');
        $this->assertSame(25, (int) $after->product_stock, 'the operator rewrote a merchant stock level');
        $this->assertSame('F206 Scarf', (string) $after->name, 'the operator renamed a merchant product');
    }

    public function test_the_operator_cannot_delete_a_merchants_product_through_the_admin_api(): void
    {
        [, $store, $product] = $this->arrange();

        $response = $this->api($this->operator(), '/api/admin/deleteproduct', [
            'store_id' => $store->id,
            'product_id' => $product->id,
        ], 'deleteJson');

        $this->assertApiRefused($response, 'the operator deleted a merchant product through the API');
        $this->assertNotNull($this->freshProduct($product->id), 'the merchant product is gone');
    }

    public function test_the_operator_cannot_add_a_product_to_a_merchants_store(): void
    {
        [, $store] = $this->arrange();
        $before = Product::withoutGlobalScopes()->where('store_id', $store->id)->count();

        /* `cover_image` is `required|image` and `product_image` is `required|array`, so the files
           must go through the FILES bag — `$this->postJson($uri, $data)` would leave an
           UploadedFile in the request bag as an object and the validator would refuse before
           authorization was consulted. Measured: "The cover image field is required." */
        $cover = \Illuminate\Http\UploadedFile::fake()->image('cover.png');
        $gallery = \Illuminate\Http\UploadedFile::fake()->image('g1.png');

        /* The token goes in the SERVER array, not through `withHeader()`. `call()` builds its
           request from `$this->serverVariables` merged with the `$server` argument and never
           reads `defaultHeaders`, which is what `withHeader()` writes — so the bearer token was
           silently dropped and the door answered 401. Caught by this file's own
           `assertNotSame(401, ...)` guard, which exists because a new 401 forces a logout in the
           shipped Flutter app. */
        $response = $this->call('POST', '/api/admin/addproduct', [
                'store_id' => $store->id,
                'name' => 'OPERATOR ADDED THIS',
                'category_id' => $this->makeCategory($store->id),
                'description' => 'x',
                'price' => 5,
                'product_stock' => 1,
                'trending' => 0,
                'variant_product' => 0,
                'status' => 1,
            ], [], ['cover_image' => $cover, 'product_image' => [$gallery]], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($this->operator()),
                'HTTP_ACCEPT' => 'application/json',
            ]);

        $this->assertApiRefused($response, 'the operator added a product to a merchant store');
        $this->assertSame($before, Product::withoutGlobalScopes()->where('store_id', $store->id)->count(),
            'a product row appeared in the merchant store');
    }

    // =============================================================================================
    // THE READ PATHS THE OWNER WANTS PRESERVED
    // =============================================================================================

    /**
     * *"Keep the viewing permissions the operator needs."* These are the operator's actual
     * diagnostic surface, and they share their helpers with the writers above — which is exactly
     * why the block had to go at each writer rather than inside `lacksProductPermission()` or
     * `productIsOutsideCurrentStore()`.
     */
    public function test_the_operator_can_still_read_a_merchants_product(): void
    {
        [, , $product] = $this->arrange();
        $operator = $this->operator();

        $show = $this->actingAs($operator)->get(route('product.show', $product->id));
        $this->assertLessThan(400, $show->getStatusCode(),
            'the operator can no longer OPEN a merchant product to diagnose it, which the owner '
            . 'asked to preserve — status ' . $show->getStatusCode());
    }

    /**
     * `productlist` cannot be driven in this suite and the reason is worth recording rather than
     * silently choosing another endpoint: it resolves a display currency through `currency_rates`,
     * one of the GATE-F tables that **no migration declares**, so it answers HTTP 500 with
     * `no such table: currency_rates` for every principal including the merchant. That is a
     * pre-existing schema gap, not a permission outcome, and a test asserting a read "works" there
     * would be asserting a 500.
     *
     * `viewproduct` does not touch it, so the operator's API read is proven on that door, with the
     * merchant as the control: if BOTH answer the same way, the door is readable and the F206
     * block did not reach it.
     */
    public function test_the_operator_can_still_read_the_catalogue_through_the_admin_api(): void
    {
        [$merchant, $store, $product] = $this->arrange();

        $uri = '/api/admin/viewproduct?store_id=' . $store->id . '&product_id=' . $product->id;

        $asMerchant = $this->api($merchant, $uri, [], 'getJson');
        $this->assertSame(1, (int) $asMerchant->json('status'),
            'the control failed: the MERCHANT cannot read their own product through this door, so '
            . 'the operator assertion below would prove nothing — '
            . substr((string) $asMerchant->getContent(), 0, 200));

        $asOperator = $this->api($this->operator(), $uri, [], 'getJson');
        $this->assertSame(1, (int) $asOperator->json('status'),
            'the operator lost API read access to a merchant product, which the owner asked to '
            . 'preserve: ' . substr((string) $asOperator->getContent(), 0, 200));
    }

    // =============================================================================================
    // THE OTHER PRINCIPALS — so the block is about the operator and nothing else moved
    // =============================================================================================

    public function test_authorised_staff_in_the_store_can_still_write(): void
    {
        [$merchant, $store, $product] = $this->arrange();

        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();
        $this->grantPermission($staff, 'Edit Products');
        $staff = User::find($staff->id);

        $product = $this->plainProduct($store, $merchant);

        $this->actingAs($staff)->put(
            route('product.update', $product->id),
            $this->savePayload('STAFF RENAMED THIS', 120)
        );

        $this->assertSame('STAFF RENAMED THIS', (string) $this->freshProduct($product->id)->name,
            'permitted staff lost the ability to save, so this change reached further than the operator');
    }

    /** A merchant of ANOTHER store is still refused — F195's tenancy work must not have moved. */
    public function test_a_merchant_of_another_store_is_still_refused(): void
    {
        [$merchant, $store] = $this->arrange();
        $product = $this->plainProduct($store, $merchant);

        $other = $this->makeMerchant();
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);
        $other->current_store = $otherStore->id;
        $other->save();
        $this->grantPermission($other, 'Edit Products');
        $other = User::find($other->id);

        $this->actingAs($other)->put(
            route('product.update', $product->id),
            $this->savePayload('ANOTHER TENANT RENAMED THIS', 7, 0)
        );

        $after = $this->freshProduct($product->id);
        $this->assertSame('F206 Plain', (string) $after->name,
            'a merchant of another store wrote this product — F195\'s tenancy guard has regressed');
        $this->assertSame(100, (int) $after->price, 'a cross-tenant price write landed');
    }

    // =============================================================================================
    // THE GUARD ITSELF
    // =============================================================================================

    public function test_the_guard_refuses_the_operator_and_nobody_else(): void
    {
        [$merchant] = $this->arrange();

        $this->assertTrue(\App\Services\OperatorCatalogueWrite::refuses($this->operator()),
            'the guard does not recognise the platform operator');
        $this->assertFalse(\App\Services\OperatorCatalogueWrite::refuses($merchant),
            'the guard refuses a merchant, so it is not a catalogue-operator guard but a lockout');
        $this->assertFalse(\App\Services\OperatorCatalogueWrite::refuses(null),
            'the guard answers for a null principal, which no door should rely on');
    }

    /**
     * The message must name the sanctioned route, not merely refuse — an operator meeting this is
     * usually trying to help a merchant.
     */
    public function test_the_refusal_tells_the_operator_what_to_do_instead(): void
    {
        $message = \App\Services\OperatorCatalogueWrite::message();

        $this->assertNotSame('', trim($message), 'the refusal carries no message');
        $this->assertSame($message, \App\Services\OperatorCatalogueWrite::ajaxRefusal()['msg'],
            'the AJAX shape and the plain message have drifted apart');
        $this->assertSame('error', \App\Services\OperatorCatalogueWrite::ajaxRefusal()['flag'],
            'the product editor reads data.flag and will treat anything else as success');
    }
}
