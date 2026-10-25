<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-03-6 -- the product quick preview, authorised server-side.
 * =============================================================================================
 *
 * `Product` is NOT in `config/tenancy.php`'s `scoped_models` and registers no effective global
 * scope, so `Product::find()` in the handler reaches EVERY tenant's catalogue. The handler's own
 * predicate is the ONLY control on this route -- there is no middleware equivalent of
 * `OrderDocumentAccessGuard` for products, and adding one would be a category error: that guard
 * is about order documents.
 *
 * So unlike the order preview, this surface has ONE control, and these cases are the whole of
 * its coverage. The live cross-tenant stock drain of S19 came from forgetting exactly this
 * predicate on exactly this model.
 *
 * The id is RAW: `Crypt::encrypt()` is an encoding here, not a control, because APP_KEY is in
 * git history and unrotated.
 */
class ProductPreviewAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeProduct(int $storeId, int $createdBy, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 5,
            'low_stock_threshold' => 2,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    public function test_a_merchant_cannot_preview_another_tenants_product(): void
    {
        [$merchantA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');
        $foreign = $this->makeProduct($storeB->id, $merchantB->id, 'Beta Secret Scarf');

        $response = $this->actingAs($merchantA)->get('/product-preview/' . $foreign->id);

        $this->assertNotSame(500, $response->getStatusCode(), 'the refusal must not be a crash');
        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('Beta Secret Scarf', (string) $response->getContent());
    }

    public function test_a_merchant_can_preview_their_own_product(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        $own = $this->makeProduct($store->id, $merchant->id, 'Alpha Own Scarf');

        $this->actingAs($merchant)->get('/product-preview/' . $own->id)
            ->assertOk()
            ->assertSee('Alpha Own Scarf', false);
    }

    public function test_a_staff_member_without_manage_products_is_refused_their_own_tenants_product(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $product = $this->makeProduct($store->id, $owner->id, 'Same Tenant Scarf');

        // PREMISE: the product IS in the staff member's own tenant, so the permission is what
        // is being measured and not the store predicate.
        $this->assertSame((int) $store->id, (int) $staff->fresh()->current_store);

        $response = $this->actingAs($staff)->get('/product-preview/' . $product->id);

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('Same Tenant Scarf', (string) $response->getContent());
    }

    public function test_the_same_staff_member_is_admitted_once_granted(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $product = $this->makeProduct($store->id, $owner->id, 'Granted Scarf');

        $this->grantPermission($staff, 'Manage Products');

        $this->actingAs($staff)->get('/product-preview/' . $product->id)
            ->assertOk()
            ->assertSee('Granted Scarf', false);
    }

    public function test_an_anonymous_caller_cannot_preview_a_product(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->makeProduct($store->id, $merchant->id, 'Anon Scarf');

        $response = $this->get('/product-preview/' . $product->id);

        $this->assertContains($response->getStatusCode(), [302, 401, 403, 404]);
        $this->assertStringNotContainsString('Anon Scarf', (string) $response->getContent());
    }

    /** `filter_var`, not `is_numeric`: MySQL coerces '5abc' to row 5 in a `WHERE id = ?`. */
    public function test_a_non_integer_id_is_refused_rather_than_coerced(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        $product = $this->makeProduct($store->id, $merchant->id, 'Coerce Scarf');

        foreach ([$product->id . 'abc', '0', '-1', 'abc'] as $bad) {
            $response = $this->actingAs($merchant)->get('/product-preview/' . $bad);

            $this->assertNotSame(500, $response->getStatusCode(), "id [{$bad}] produced a crash");
            $this->assertNotSame(200, $response->getStatusCode(), "id [{$bad}] was accepted");
            $this->assertStringNotContainsString('Coerce Scarf', (string) $response->getContent());
        }
    }

    /**
     * THE HANDLER IS THE ONLY CONTROL, so it is also measured with the HTTP stack out of the
     * picture -- otherwise removing the predicate would still look safe if some unrelated
     * middleware happened to refuse.
     */
    public function test_the_handler_refuses_a_foreign_product_when_called_directly(): void
    {
        [$merchantA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');
        $foreign = $this->makeProduct($storeB->id, $merchantB->id, 'Direct Beta Scarf');

        $this->actingAs($merchantA);
        // `actingAs` also calls shouldUse(), and getCurrentStore() reads the default guard --
        // a direct call can otherwise run under a tenant resolution a real request never has.
        $this->app['auth']->shouldUse('web');

        $controller = app(\App\Http\Controllers\ProductController::class);
        $response = $controller->product_preview(request(), (string) $foreign->id);

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('Direct Beta Scarf', (string) $response->getContent());
    }

    public function test_the_handler_admits_the_owner_when_called_directly(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $own = $this->makeProduct($store->id, $merchant->id, 'Direct Own Scarf');

        $this->actingAs($merchant);
        $this->app['auth']->shouldUse('web');

        $controller = app(\App\Http\Controllers\ProductController::class);
        $this->assertSame(200, $controller->product_preview(request(), (string) $own->id)->getStatusCode());
    }
}
