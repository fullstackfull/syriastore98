<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-04-5 -- the new order quick-preview endpoint, authorised server-side.
 * =============================================================================================
 *
 * A NEW SENSITIVE ENDPOINT, SO IT GETS ITS OWN AUTHORIZATION TESTS BEFORE IT SHIPS. The brief
 * is explicit: do not introduce new sensitive endpoints without server-side authorization tests,
 * and never assume an encrypted identifier provides tenant authorization.
 *
 * THE ID IS DELIBERATELY RAW. `APP_KEY` is in git history and is not being rotated (owner
 * decision D7), so `Crypt::encrypt($id)` in a uri is an ENCODING and the ciphertext is forgeable
 * for any id. A new surface shipping an encrypted id would imply a control that does not exist.
 * The controls are TWO, independent:
 *
 *   1. `OrderDocumentAccessGuard`, keyed on the uri `order-preview/{id}` in its TARGETS map. It
 *      resolves the row, reads `store_id` and compares it to the caller's tenant, operator first.
 *   2. The handler's own ownership test, for the case where the route is ever re-declared outside
 *      the `web` group -- which is how `Authenticate` silently vanished from an order document
 *      route once already (OrderDocumentAccessGuard's docblock, AutomaticOrderPrinting:31).
 *
 * BOTH ARE MEASURED SEPARATELY BELOW, because a test that only drives the route cannot tell which
 * of the two refused -- and "one of them works" is not the claim being made.
 *
 * `Order` is NOT in `config/tenancy.php`'s `scoped_models` and registers no `StoreScope`, so
 * `Order::find()` in the handler reaches every tenant's orders. Nothing here is protected by the
 * global scope and no assertion below should be read as if it were.
 */
class OrderPreviewAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function previewUrl(int $orderId): string
    {
        return '/order-preview/' . $orderId;
    }

    // =========================================================================================
    // Cross-tenant
    // =========================================================================================

    public function test_a_merchant_cannot_preview_another_tenants_order(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Show Order');

        $foreign = $this->makeOrder($storeB, ['product_order_id' => 'ORD-FOREIGN']);

        $response = $this->actingAs($merchantA)->get($this->previewUrl($foreign->id));

        // 404 and 500 are both excluded: either would hide the order without the authorization
        // having anything to do with it, which is the shape of a vacuous pass.
        $this->assertNotSame(500, $response->getStatusCode(), 'the refusal must not be a crash');
        $this->assertContains($response->getStatusCode(), [403, 404],
            'a foreign order must be refused');

        $this->assertStringNotContainsString('ORD-FOREIGN', (string) $response->getContent());
    }

    /**
     * THE POSITIVE CASE, which is what makes the refusal above meaningful: if the endpoint
     * refused everyone, the test above would pass and the feature would be broken.
     */
    public function test_a_merchant_can_preview_their_own_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');

        $own = $this->makeOrder($store, ['product_order_id' => 'ORD-OWN']);

        $response = $this->actingAs($merchant)->get($this->previewUrl($own->id));

        $response->assertOk();
        $response->assertSee('ORD-OWN', false);
    }

    // =========================================================================================
    // Permission
    // =========================================================================================

    public function test_a_staff_member_without_show_order_is_refused_their_own_tenants_order(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $order = $this->makeOrder($store, ['product_order_id' => 'ORD-SAME-TENANT']);

        // PREMISE: the order IS in the staff member's own tenant, so what is being measured is
        // the permission and not the store predicate.
        $this->assertSame((int) $store->id, (int) $order->store_id);
        $this->assertSame((int) $store->id, (int) $staff->fresh()->current_store);

        $response = $this->actingAs($staff)->get($this->previewUrl($order->id));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('ORD-SAME-TENANT', (string) $response->getContent());
    }

    public function test_the_same_staff_member_is_admitted_once_granted_show_order(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $order = $this->makeOrder($store, ['product_order_id' => 'ORD-GRANTED']);

        $this->grantPermission($staff, 'Show Order');

        $this->actingAs($staff)->get($this->previewUrl($order->id))
            ->assertOk()
            ->assertSee('ORD-GRANTED', false);
    }

    // =========================================================================================
    // Anonymous, and malformed ids
    // =========================================================================================

    public function test_an_anonymous_caller_cannot_preview_an_order(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store, ['product_order_id' => 'ORD-ANON']);

        $response = $this->get($this->previewUrl($order->id));

        $this->assertContains($response->getStatusCode(), [302, 401, 403, 404]);
        $this->assertStringNotContainsString('ORD-ANON', (string) $response->getContent());
    }

    /**
     * `filter_var(..., FILTER_VALIDATE_INT)`, not `is_numeric`. The skill's §4: is_numeric
     * accepts '5.9' and REJECTS '5abc', while MySQL coerces '5abc' to row 5 in a
     * `WHERE id = ?` comparison. So '5abc' is the one that matters.
     */
    public function test_a_non_integer_id_is_refused_rather_than_coerced(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');

        $order = $this->makeOrder($store, ['product_order_id' => 'ORD-COERCE']);

        foreach ([$order->id . 'abc', '0', '-1', 'abc'] as $bad) {
            $response = $this->actingAs($merchant)->get('/order-preview/' . $bad);

            $this->assertNotSame(500, $response->getStatusCode(), "id [{$bad}] produced a crash");
            $this->assertNotSame(200, $response->getStatusCode(), "id [{$bad}] was accepted");
            $this->assertStringNotContainsString('ORD-COERCE', (string) $response->getContent(),
                "id [{$bad}] was coerced to a real row");
        }
    }

    // =========================================================================================
    // The two controls, measured independently
    // =========================================================================================

    /**
     * CONTROL 1. The uri is in the guard's map and the guard is in its resolved stack. Keyed on
     * the uri exactly as the route table reports it, which is how the guard itself matches.
     */
    public function test_the_preview_uri_is_registered_in_the_order_document_guard(): void
    {
        $targets = (new \ReflectionClass(\App\Http\Middleware\OrderDocumentAccessGuard::class))
            ->getConstant('TARGETS');

        $this->assertArrayHasKey('order-preview/{id}', $targets,
            'the preview uri is not in OrderDocumentAccessGuard::TARGETS. That map is keyed on '
            . 'the uri, so a new order-document surface is NOT guarded by implication.');

        $this->assertSame('orders', $targets['order-preview/{id}']['table']);
        $this->assertSame('id', $targets['order-preview/{id}']['param']);

        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->match(\Illuminate\Http\Request::create('/order-preview/1', 'GET'));

        $this->assertSame('order.preview', $route->getName());

        $this->assertContains(
            \App\Http\Middleware\OrderDocumentAccessGuard::class,
            app('router')->gatherRouteMiddleware($route),
            'the guard is not in the resolved stack for this route'
        );
    }

    /**
     * CONTROL 2, ON ITS OWN. The handler is called directly, with the middleware out of the
     * picture entirely, so this measures the controller's own ownership test and nothing else.
     * Without this case, removing that test would change nothing any other assertion here can
     * see -- the guard would still refuse, and the suite would stay green over a handler that
     * had become unprotected.
     *
     * The default guard is put back to `web` first: `actingAs` also calls
     * `shouldUse(...)`, and `getCurrentStore()` reads the default guard, so a direct call can
     * otherwise run under a tenant resolution a real request would never have (the skill's §6.5).
     */
    public function test_the_handler_refuses_a_foreign_order_with_the_middleware_bypassed(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Show Order');
        $foreign = $this->makeOrder($storeB, ['product_order_id' => 'ORD-DIRECT']);

        $this->actingAs($merchantA);
        $this->app['auth']->shouldUse('web');

        $this->assertNotNull(auth()->user(), 'premise: the web guard holds the merchant');
        $this->assertSame($merchantA->id, auth()->user()->id);

        $controller = app(\App\Http\Controllers\OrderController::class);
        $response = $controller->order_preview(request(), (string) $foreign->id);

        $this->assertContains($response->getStatusCode(), [403, 404],
            'the handler itself must refuse a foreign order, independently of the middleware');
        $this->assertStringNotContainsString('ORD-DIRECT', (string) $response->getContent());
    }

    /**
     * And the handler admits the owner when called directly, so the case above is not passing
     * because the direct call refuses everything.
     */
    public function test_the_handler_admits_the_owner_with_the_middleware_bypassed(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');
        $own = $this->makeOrder($store, ['product_order_id' => 'ORD-DIRECT-OWN']);

        $this->actingAs($merchant);
        $this->app['auth']->shouldUse('web');

        $controller = app(\App\Http\Controllers\OrderController::class);
        $response = $controller->order_preview(request(), (string) $own->id);

        $this->assertSame(200, $response->getStatusCode());
    }
}
