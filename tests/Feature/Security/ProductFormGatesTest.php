<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * F160 — `ProductController::create()` HAD NO PERMISSION GATE, AND 500s WITH NO STORE.
 *
 * FOUND WHILE SCOPING D-24a, which is the point worth recording. The owner's approval for adding
 * create-actions to the command palette requires checking "employee permission and current store,
 * both on render and on request". Checking whether that was satisfiable for إنشاء منتج meant reading
 * the target, and the target had no check of any kind:
 *
 *     public function create()
 *     {
 *         $store = getStoreById(getCurrentStore());
 *         $link = env('APP_URL') . ($store->slug ? ...
 *
 * `store()` right below it is gated on `Create Product`, so nothing could be WRITTEN. But a form is
 * a read, and this one renders the store's categories, taxes, shipping methods, brands, labels, tags
 * and product attributes. A staff member holding no product permission could open it and read all of
 * them. **This is the same shape as F157's finding on `CouponController::create()`/`edit()`** —
 * which is why the sweep continued past the coupon controller rather than stopping there.
 *
 * AND THE SECOND HALF IS A CRASH. `getCurrentStore()` returns `current_store`, else the hardcoded
 * literal 1 for a `super admin`, else `Store::where('created_by', $user->id)->first()?->id` — NULL
 * for a staff member who owns no store. `getStoreById(null)` is then null and `$store->slug` throws.
 * So the page 500s for exactly the principal the permission gate is meant to turn away, which is why
 * both halves are fixed in one change: the gate alone would leave a 500 reachable by anyone who
 * holds `Create Product` without a resolvable store.
 *
 * AND THE SWEEP FOUND TWO MORE OF THE SAME PAIR IN THE SAME CONTROLLER. `edit()` and `show()` each
 * carried the P1-INV-01 OWNERSHIP check and no permission check — a tenancy question answered and an
 * authorization question not asked — so a colleague holding no product right could render this
 * store's product in full: name, prices, cost, stock and every variant row. Identical to what F157
 * found on `CouponController::edit()`, and for the identical reason: the submission was refused, so
 * nobody noticed the form was not.
 *
 * EVERY GATE MIRRORS A PRECEDENT IN THIS CONTROLLER RATHER THAN A CHOICE OF MINE, which is what
 * keeps this a tightening instead of a policy change:
 *
 *   create()  `Create Product`, no operator branch   = `store()` (:469)
 *   edit()    `Edit Products`,  no operator branch   = `update()` (:1541), where the operator
 *                                                      exemption covers only OWNERSHIP
 *   show()    `Manage Products`, operator-FIRST      = `product_preview()` (:1369), the other
 *                                                      single-product read
 */
class ProductFormGatesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'f160-' . uniqid(), 'slug' => 'f160-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    public function test_staff_without_the_permission_cannot_open_the_product_create_form(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);

        $response = $this->actingAs($staff)->get('/product/create');

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'a staff member with no product permission rendered the product editor, which exposes '
            . 'the store categories, taxes, shipping methods, brands, labels, tags and attributes'
        );
    }

    public function test_the_form_does_not_leak_the_catalogue_configuration_to_unpermitted_staff(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);

        $secretBrand = 'brand-' . uniqid();
        DB::table('product_brands')->insert([
            'name' => $secretBrand, 'slug' => 'b-' . uniqid(), 'status' => 1,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $body = (string) $this->actingAs($staff)->get('/product/create')->getContent();

        $this->assertStringNotContainsString(
            $secretBrand,
            $body,
            'the refused response still carried a catalogue value from the store'
        );
    }

    /**
     * THE OTHER TWO FORMS, EACH WITH ITS OWN PERMISSION. A staff member inside the right store —
     * so the ownership check that already existed cannot be what refuses them — holding no product
     * permission at all.
     *
     * @dataProvider readForms
     */
    public function test_staff_without_the_permission_cannot_open_the_other_product_forms(string $uri): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $product = $this->productIn($store);

        $response = $this->actingAs($staff)->get(str_replace('{id}', (string) $product->id, $uri));

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            $uri . ': rendered for a same-store employee holding no product permission, so the '
            . 'ownership check was the only gate'
        );
    }

    /**
     * AND THE PERMITTED PRINCIPAL STILL GETS THEM. Without this the gates could be blanket
     * refusals and every case above would pass.
     *
     * @dataProvider readForms
     */
    public function test_the_right_permission_still_opens_each_product_form(string $uri, string $permission): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, $permission);
        $product = $this->productIn($store);

        $response = $this->actingAs($merchant)->get(str_replace('{id}', (string) $product->id, $uri));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            $uri . ': ' . $permission . ' must still open this form'
        );
    }

    /**
     * THE PERMISSIONS ARE NOT INTERCHANGEABLE, and this is the case that proves each gate names its
     * own: `Edit Products` must not open the `show` page's gate and `Manage Products` must not open
     * `edit`'s. A single shared "any product right" check would pass everything above and fail here.
     *
     * @dataProvider readForms
     */
    public function test_each_form_requires_its_own_permission_and_not_a_neighbours(string $uri, string $permission, string $neighbour): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, $neighbour);
        $product = $this->productIn($store);

        $response = $this->actingAs($merchant)->get(str_replace('{id}', (string) $product->id, $uri));

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            $uri . ': opened with ' . $neighbour . ' instead of ' . $permission
        );
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function readForms(): array
    {
        return [
            'edit' => ['/product/{id}/edit', 'Edit Products', 'Manage Products'],
            'show' => ['/product/{id}', 'Manage Products', 'Edit Products'],
        ];
    }

    /**
     * THE PERMITTED PRINCIPAL MUST STILL GET THE FORM. Without this the gate could be a blanket
     * refusal and every case above would pass.
     */
    public function test_a_merchant_with_the_permission_still_opens_the_form(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Product');

        $response = $this->actingAs($merchant)->get('/product/create');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'the permitted merchant must still reach the product editor'
        );
    }

    /**
     * THE CRASH HALF. A principal who holds the permission but has no resolvable store used to
     * reach `$store->slug` on null. It must refuse, not throw.
     */
    public function test_a_permitted_principal_with_no_resolvable_store_is_refused_not_crashed(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => null]);
        $this->grantPermission($staff, 'Create Product');

        $this->assertNull(
            getStoreById(\DB::table('users')->where('id', $staff->id)->value('current_store')),
            'premise: this principal resolves no store'
        );

        $this->withoutExceptionHandling();

        $thrown = null;
        try {
            $this->actingAs($staff)->get('/product/create');
        } catch (\Throwable $e) {
            $thrown = get_class($e) . ': ' . $e->getMessage();
        }

        $this->assertNull($thrown, 'the product editor must refuse a store-less principal, not throw');
    }
}
