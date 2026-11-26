<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `PRICE-006`'s PREVIEW SURFACE — THE DOOR, not the arithmetic.
 * =================================================================================================
 *
 * `ProductController::price_explanation()` returns a merchant's own commercial configuration in
 * JSON: list price, sale price, every discount that applies, every tax band by name and rate, and
 * price-list tier prices. That is exactly the data a competitor on the same platform would want.
 *
 * `Product` is NOT in `config/tenancy.php`'s `scoped_models` and registers no effective global
 * scope, so `Product::find()` reaches every tenant's catalogue and the handler's own `store_id`
 * predicate is the ONLY control. `PriceExplanationTest` owns the breakdown; this file owns the
 * door, and it is a separate file because a figure assertion and a refusal assertion fail for
 * different reasons and should not share a fixture.
 *
 * THE REFUSALS ARE KEYED ON THE REASON, not only the status. A gateless handler's own
 * "product not found" branch answers 404 too, so a 404 from a missing row and a 404 from the
 * tenant predicate are the same status and opposite facts — every refusal below also asserts that
 * the victim's figures are absent from the body.
 *
 * @see \App\Services\Commerce\PriceExplanation
 * @see \Tests\Feature\Security\ProductPreviewAuthorizationTest  the sibling door, same gate shape
 */
class ProductPriceExplanationAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/product-price-explanation/';

    private function makeProduct(int $storeId, int $createdBy, float $price, float $salePrice): Product
    {
        return Product::forceCreate([
            'name' => 'PX-' . uniqid(),
            'slug' => 'px-' . uniqid(),
            'description' => 'x',
            'price' => $price,
            'sale_price' => $salePrice,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 5,
            'low_stock_threshold' => 2,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | the tenant predicate
    |--------------------------------------------------------------------------
    */

    public function test_a_merchant_cannot_read_another_tenants_price_breakdown(): void
    {
        [$merchantA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');
        $foreign = $this->makeProduct($storeB->id, $merchantB->id, 1234.56, 999.99);

        $response = $this->actingAs($merchantA)->get(self::URI . $foreign->id);

        $this->assertNotSame(500, $response->getStatusCode(), 'the refusal must not be a crash.');
        $this->assertContains($response->getStatusCode(), [403, 404]);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('1234.56', $body,
            'the victim\'s LIST price must not appear. A status-only assertion would pass on a '
            . 'handler that answered 404 and leaked the body anyway.');
        $this->assertStringNotContainsString('999.99', $body,
            'nor their sale price, which is the commercially sensitive half.');
    }

    public function test_a_merchant_reads_their_own_breakdown(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $own = $this->makeProduct($store->id, $merchant->id, 200.00, 150.00);

        $response = $this->actingAs($merchant)->get(self::URI . $own->id);

        $this->assertSame(200, $response->getStatusCode(), sprintf(
            'control: the owner IS admitted. Without this every refusal above is consistent with '
            . 'a route that refuses everyone. Body: %s', (string) $response->getContent()
        ));

        $this->assertSame(150.00, (float) $response->json('explanation.unit_charged'),
            'and the preview reports the price the engine will charge — the sale price overrides '
            . 'the list price, which is the thing PRICE-006 asks to be explained.');
        $this->assertTrue($response->json('explanation.reconciled'),
            'and the breakdown accounts for it.');
    }

    /*
    |--------------------------------------------------------------------------
    | the permission, in both directions on ONE principal
    |--------------------------------------------------------------------------
    */

    public function test_a_staff_member_without_manage_products_is_refused_their_own_tenants_product(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $own = $this->makeProduct($store->id, $merchant->id, 77.77, 66.66);

        $response = $this->actingAs($staff)->get(self::URI . $own->id);

        $this->assertSame(403, $response->getStatusCode(), sprintf(
            'a staff member of the SAME tenant without `Manage Products` is refused, so the gate '
            . 'is the permission and not the tenancy. Body: %s', (string) $response->getContent()
        ));
        $this->assertStringNotContainsString('66.66', (string) $response->getContent());
    }

    public function test_the_same_staff_member_is_admitted_once_granted(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $own = $this->makeProduct($store->id, $merchant->id, 77.77, 66.66);

        $this->grantPermission($staff, 'Manage Products');

        $this->assertSame(200, $this->actingAs($staff)->get(self::URI . $own->id)->getStatusCode(),
            'the SAME principal, the SAME product, the permission the only difference — so the '
            . '403 above is the permission\'s doing and not the fixture\'s.');
    }

    /**
     * ANONYMOUS, BOTH WAYS -- and the second way is the one that is not optional.
     *
     * The route sits inside `Route::middleware(['auth', 'xss', 'setlocate', 'verified'])`
     * (`routes/web.php:478`, measured by brace-tracking the file rather than read off a comment),
     * so over HTTP the `auth` middleware answers and **the handler's own `$user === null` limb is
     * never reached**. That is `GAP-307`'s shape exactly: a handler-level check behind a
     * middleware that answers first is invisible to an HTTP test, and the route refuses whether or
     * not the handler checks anything. So the limb is ALSO driven directly, with nobody signed in.
     */
    public function test_an_anonymous_caller_is_refused_by_the_middleware_and_by_the_handler(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $own = $this->makeProduct($store->id, $merchant->id, 55.55, 44.44);

        $response = $this->get(self::URI . $own->id);

        $this->assertNotSame(200, $response->getStatusCode(),
            'over HTTP the `auth` middleware refuses first -- this assertion is about the route, '
            . 'and it says nothing about the handler.');
        $this->assertStringNotContainsString('44.44', (string) $response->getContent());

        // THE HANDLER'S OWN LIMB, with the middleware out of the path.
        $this->assertFalse(auth()->check(), 'premise: nobody is signed in.');

        $controller = app(\App\Http\Controllers\ProductController::class);
        $direct = $controller->price_explanation(request(), (string) $own->id);

        $this->assertSame(403, $direct->getStatusCode(), sprintf(
            'the handler refuses an unauthenticated caller on its own, so the control does not '
            . 'depend on the route keeping its middleware -- and a later declaration of the same '
            . 'method+URI silently replaces the earlier one in this codebase. Body: %s',
            (string) $direct->getContent()
        ));
        $this->assertStringNotContainsString('44.44', (string) $direct->getContent());
    }

    /*
    |--------------------------------------------------------------------------
    | the id, and the variant id
    |--------------------------------------------------------------------------
    */

    public function test_a_non_integer_id_is_refused_rather_than_coerced(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $own = $this->makeProduct($store->id, $merchant->id, 31.31, 21.21);

        // `<id>abc` — MySQL coerces that to the numeric prefix in a `WHERE id = ?` comparison,
        // which is why the handler uses filter_var and not is_numeric.
        $response = $this->actingAs($merchant)->get(self::URI . $own->id . 'abc');

        $this->assertNotSame(200, $response->getStatusCode(), sprintf(
            'Body: %s', (string) $response->getContent()
        ));
        $this->assertStringNotContainsString('21.21', (string) $response->getContent());
    }

    /**
     * A VARIANT ID BELONGING TO ANOTHER STORE'S PRODUCT IS REFUSED.
     *
     * `ProductVariant` is not scoped either, and `qty` and `variant_id` arrive from the query
     * string — caller-supplied input, which this codebase has turned into four separate defects.
     * The variant is checked against the product the handler has already authorised, so a foreign
     * variant id cannot be used to price another store's row through an authorised product.
     */
    public function test_a_variant_id_from_another_store_is_refused(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');

        $mine = $this->makeProduct($storeA->id, $merchantA->id, 10.00, 9.00);
        $theirs = $this->makeProduct($storeB->id, $merchantB->id, 8888.88, 7777.77);

        $foreignVariant = \App\Models\ProductVariant::forceCreate([
            'product_id' => $theirs->id, 'variant' => 'secret', 'price' => 7777.77,
            'sku' => 'PX-' . uniqid(), 'stock' => 1, 'store_id' => $storeB->id,
        ]);

        $response = $this->actingAs($merchantA)
            ->get(self::URI . $mine->id . '?variant_id=' . $foreignVariant->id);

        $this->assertNotSame(200, $response->getStatusCode(), sprintf(
            'a variant id that does not belong to the authorised product is refused. Body: %s',
            (string) $response->getContent()
        ));
        $this->assertStringNotContainsString('7777.77', (string) $response->getContent());
    }

    /**
     * `qty` IS AN INPUT TO THE PRICE, NOT A MULTIPLIER, so a nonsense value must not become one.
     *
     * The price-list engine selects its tier BY quantity. A preview is a read, so a bad quantity
     * clamps to 1 rather than erroring — and the clamp is asserted, because a `qty=0` reaching the
     * engine would divide by zero when the wholesale arm derives a unit price from a line total.
     */
    public function test_a_nonsense_quantity_clamps_instead_of_crashing(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $own = $this->makeProduct($store->id, $merchant->id, 40.00, 30.00);

        foreach (['0', '-5', 'abc', ''] as $qty) {
            $response = $this->actingAs($merchant)->get(self::URI . $own->id . '?qty=' . $qty);

            $this->assertSame(200, $response->getStatusCode(), sprintf(
                'qty=%s must not 500. Body: %s', var_export($qty, true), (string) $response->getContent()
            ));
            $this->assertSame(1, (int) $response->json('explanation.qty'), sprintf(
                'qty=%s clamps to 1.', var_export($qty, true)
            ));
            $this->assertSame(30.00, (float) $response->json('explanation.unit_charged'));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | THE HANDLER, CALLED DIRECTLY
    |--------------------------------------------------------------------------
    */

    /**
     * A HANDLER-LEVEL CHECK BEHIND AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP TEST — the
     * middleware answers and the handler is never reached, so the route refuses whether or not the
     * handler checks anything (`GAP-307`, two independent instances on 2026-11-26). The handler is
     * the only control on this route, so it is also driven with the HTTP stack out of the picture.
     */
    public function test_the_handler_refuses_a_foreign_product_when_called_directly(): void
    {
        [$merchantA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');
        $foreign = $this->makeProduct($storeB->id, $merchantB->id, 4321.00, 3210.00);

        $this->actingAs($merchantA);
        // `actingAs` also calls shouldUse(), and getCurrentStore() reads the default guard --
        // a direct call can otherwise run under a tenant resolution a real request never has.
        $this->app['auth']->shouldUse('web');

        $controller = app(\App\Http\Controllers\ProductController::class);
        $response = $controller->price_explanation(request(), (string) $foreign->id);

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('3210', (string) $response->getContent());
    }

    public function test_the_handler_admits_the_owner_when_called_directly(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $own = $this->makeProduct($store->id, $merchant->id, 60.00, 50.00);

        $this->actingAs($merchant);
        $this->app['auth']->shouldUse('web');

        $controller = app(\App\Http\Controllers\ProductController::class);
        $response = $controller->price_explanation(request(), (string) $own->id);

        $this->assertSame(200, $response->getStatusCode(), sprintf(
            'control for the direct call. Body: %s', (string) $response->getContent()
        ));
    }

    /**
     * THE PLATFORM OPERATOR IS ADMITTED, and this is the limb an `isAbleTo()`-only gate breaks.
     *
     * Laratrust carries no super-admin bypass and `permissions_as_gates => false`, so
     * `isAbleTo('Manage Products')` is FALSE for a `super admin` holding no role. Operator-first
     * ordering is therefore mandatory, and asserting it is how a later "tightening" that drops the
     * operator limb is caught — it would read as a lockout, not as a refusal.
     */
    public function test_the_platform_operator_is_admitted_to_any_stores_breakdown(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $own = $this->makeProduct($store->id, $merchant->id, 90.00, 70.00);

        $operator = $this->makePlatformAdmin();

        $this->assertFalse($operator->isAbleTo('Manage Products'), sprintf(
            'premise, and the reason the gate is ordered the way it is: isAbleTo() REFUSES the '
            . 'platform operator. If this ever reads true, the operator limb below is no longer '
            . 'load-bearing and the reader should check why. Operator type: %s', (string) $operator->type
        ));

        $this->actingAs($operator);
        $this->app['auth']->shouldUse('web');

        $controller = app(\App\Http\Controllers\ProductController::class);
        $response = $controller->price_explanation(request(), (string) $own->id);

        $this->assertSame(200, $response->getStatusCode(), sprintf(
            'the operator reads any store\'s breakdown through the operator limb, not through a '
            . 'permission they do not hold. Body: %s', (string) $response->getContent()
        ));
        // A direct call returns a JsonResponse, which has no `json()` -- the payload is decoded
        // from its content rather than asserted through the HTTP test helper.
        $payload = (array) json_decode((string) $response->getContent(), true);

        $this->assertSame(70.00, round((float) ($payload['explanation']['unit_charged'] ?? 0), 2),
            'and the figure is the operator reading ANOTHER store\'s product, which is the '
            . 'capability the operator limb exists for.');
    }
}
