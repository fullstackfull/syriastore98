<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * S12(a) and S12(b) -- THE TWO ORDER-DETAIL API ENDPOINTS.
 *
 * Both were reported by the P01-SEC-2 adversarial audit and reproduced before being fixed:
 *
 *   POST api/{slug}/order-detail  ApiController@order_detail       any token, any tenant
 *   GET  api/admin/order-detail   DashboardController@orderDetail  any token, driver included
 *
 * Neither is reachable by App\Http\Middleware\OrderDocumentAccessGuard: that guard is appended to
 * the `web` middleware group and these routes are in the `api` group, so the guard's handle() is
 * never invoked for them. P01-SEC-2 recorded them as knowingly open for that reason; this closes
 * them at the call site instead, which is where an api-group route's authorization has to live.
 *
 * THE CONTRACT IS THE CONSTRAINT, not an afterthought. The brief requires the existing path,
 * method, successful response shape and token compatibility to survive. So:
 *   - the routes and verbs are untouched;
 *   - a legitimate caller still gets the identical `{status: 1, message, data}` envelope, asserted
 *     field by field below rather than merely "200 OK";
 *   - a denial reuses each endpoint's OWN not-found envelope -- HTTP 200 with `status: 0` --
 *     which is a path the shipped app already handles, so no app release is required;
 *   - no denial is 401, because repo B's lib/network/network.dart maps any 401 onto a forced
 *     logout that clears preferences. Asserted explicitly.
 */
class ApiOrderDetailAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function tenantWithPlan(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $plan = Plan::forceCreate([
            'name' => 'API Plan ' . uniqid(),
            'price' => 0,
            'duration' => 'Unlimited',
            'max_stores' => -1,
            'max_products' => -1,
            'max_users' => -1,
            'themes' => '[]',
            'storage_limit' => '-1',
        ]);

        $merchant->plan_id = $plan->id;
        $merchant->save();

        return [$merchant->fresh(), $store];
    }

    private function makeCustomer(int $storeId, array $attributes = []): Customer
    {
        return Customer::forceCreate(array_merge([
            'first_name' => 'Shopper',
            'last_name' => 'Api',
            'email' => 'apishopper' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $storeId,
            'status' => 1,
        ], $attributes));
    }

    /** @return array<string, string> */
    private function bearer($tokenable): array
    {
        return [
            'Authorization' => 'Bearer ' . $tokenable->createToken('audit')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    // ===================================================================================
    // S12(b) -- GET api/admin/order-detail
    // ===================================================================================

    public function test_a_merchant_token_cannot_read_another_tenants_order_over_the_admin_api(): void
    {
        [$ownerA, $storeA] = $this->tenantWithPlan();
        $orderA = $this->makeOrder($storeA, ['product_order_id' => 'VICTIM-A']);

        [$ownerB] = $this->tenantWithPlan();

        $response = $this->withHeaders($this->bearer($ownerB))
            ->getJson('/api/admin/order-detail?order_id=' . $orderA->id);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 0);
        $this->assertStringNotContainsString('VICTIM-A', $response->getContent(),
            "Another tenant's order number came back in the body.");
    }

    public function test_a_customer_token_cannot_read_an_order_over_the_admin_api(): void
    {
        [$owner, $store] = $this->tenantWithPlan();
        $order = $this->makeOrder($store, ['product_order_id' => 'VICTIM-C']);
        $shopper = $this->makeCustomer($store->id);

        $response = $this->withHeaders($this->bearer($shopper))
            ->getJson('/api/admin/order-detail?order_id=' . $order->id);

        $response->assertJsonPath('status', 0);
        $this->assertStringNotContainsString('VICTIM-C', $response->getContent());
    }

    public function test_a_merchant_can_still_read_their_own_order_over_the_admin_api(): void
    {
        [$owner, $store] = $this->tenantWithPlan();
        $order = $this->makeOrder($store, ['product_order_id' => 'MINE-ADMIN']);

        $response = $this->withHeaders($this->bearer($owner))
            ->getJson('/api/admin/order-detail?order_id=' . $order->id);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 1);
        $response->assertJsonStructure(['status', 'message', 'data']);
        $this->assertStringContainsString('MINE-ADMIN', $response->getContent(),
            'The legitimate admin-API caller no longer receives their own order.');
    }

    public function test_the_platform_operator_can_still_read_any_order_over_the_admin_api(): void
    {
        [$owner, $store] = $this->tenantWithPlan();
        $order = $this->makeOrder($store, ['product_order_id' => 'OPERATOR-OK']);

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $response = $this->withHeaders($this->bearer($operator))
            ->getJson('/api/admin/order-detail?order_id=' . $order->id);

        $response->assertJsonPath('status', 1);
        $this->assertStringContainsString('OPERATOR-OK', $response->getContent());
    }

    // ===================================================================================
    // S12(a) -- POST api/{slug}/order-detail
    // ===================================================================================

    public function test_a_shopper_cannot_read_another_shoppers_order_over_the_storefront_api(): void
    {
        [$owner, $store] = $this->tenantWithPlan();
        $shopperA = $this->makeCustomer($store->id);
        $shopperB = $this->makeCustomer($store->id);

        $orderB = $this->makeOrder($store, [
            'customer_id' => $shopperB->id,
            'is_guest' => 0,
            'product_order_id' => 'VICTIM-SHOPPER-B',
        ]);

        $response = $this->withHeaders($this->bearer($shopperA))
            ->postJson('/api/' . $store->slug . '/order-detail', ['order_id' => $orderB->id]);

        $response->assertJsonPath('status', 0);
        $this->assertStringNotContainsString('VICTIM-SHOPPER-B', $response->getContent());
    }

    public function test_a_shopper_cannot_read_an_order_from_another_store_over_the_storefront_api(): void
    {
        [$ownerA, $storeA] = $this->tenantWithPlan();
        [$ownerB, $storeB] = $this->tenantWithPlan();

        $orderA = $this->makeOrder($storeA, ['product_order_id' => 'VICTIM-STORE-A']);
        $shopperB = $this->makeCustomer($storeB->id);

        $response = $this->withHeaders($this->bearer($shopperB))
            ->postJson('/api/' . $storeB->slug . '/order-detail', ['order_id' => $orderA->id]);

        $response->assertJsonPath('status', 0);
        $this->assertStringNotContainsString('VICTIM-STORE-A', $response->getContent());
    }

    public function test_a_shopper_can_still_read_their_own_order_over_the_storefront_api(): void
    {
        [$owner, $store] = $this->tenantWithPlan();
        $shopper = $this->makeCustomer($store->id);
        $order = $this->makeOrder($store, [
            'customer_id' => $shopper->id,
            'is_guest' => 0,
            'product_order_id' => 'MINE-STOREFRONT',
        ]);

        $response = $this->withHeaders($this->bearer($shopper))
            ->postJson('/api/' . $store->slug . '/order-detail', ['order_id' => $order->id]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 1);
        $response->assertJsonStructure(['status', 'message', 'data']);
        $this->assertStringContainsString('MINE-STOREFRONT', $response->getContent(),
            'The legitimate shopper no longer receives their own order -- the fix broke the app.');
    }

    /**
     * A GUEST ORDER CARRIES customer_id = 0, so no signed-in shopper can claim one by matching on
     * zero. This is the test that would catch a `==` creeping in where `===` and a > 0 check are
     * required.
     */
    public function test_a_guest_order_cannot_be_claimed_by_a_signed_in_shopper(): void
    {
        [$owner, $store] = $this->tenantWithPlan();
        $shopper = $this->makeCustomer($store->id);
        $guestOrder = $this->makeOrder($store, [
            'customer_id' => 0,
            'is_guest' => 1,
            'product_order_id' => 'VICTIM-GUEST',
        ]);

        $response = $this->withHeaders($this->bearer($shopper))
            ->postJson('/api/' . $store->slug . '/order-detail', ['order_id' => $guestOrder->id]);

        $response->assertJsonPath('status', 0);
        $this->assertStringNotContainsString('VICTIM-GUEST', $response->getContent());
    }

    // ===================================================================================
    // CONTRACT PRESERVATION
    // ===================================================================================

    /**
     * NO DENIAL MAY BE 401. repo B's lib/network/network.dart maps any 401 onto a forced logout
     * that clears stored preferences, so an authorization refusal answering 401 would sign the
     * user out of the app. Both endpoints are asserted, on the cross-tenant path.
     */
    public function test_no_authorization_denial_answers_401(): void
    {
        [$ownerA, $storeA] = $this->tenantWithPlan();
        [$ownerB, $storeB] = $this->tenantWithPlan();
        $orderA = $this->makeOrder($storeA);
        $shopperB = $this->makeCustomer($storeB->id);

        $this->withHeaders($this->bearer($ownerB))
            ->getJson('/api/admin/order-detail?order_id=' . $orderA->id)
            ->assertStatus(200);

        $this->withHeaders($this->bearer($shopperB))
            ->postJson('/api/' . $storeB->slug . '/order-detail', ['order_id' => $orderA->id])
            ->assertStatus(200);
    }

    /**
     * THE ROUTES THEMSELVES ARE UNCHANGED -- same uri, same verb, same middleware. A fix that
     * quietly moved either endpoint would break the shipped app regardless of its authorization.
     */
    public function test_both_endpoints_keep_their_path_verb_and_middleware(): void
    {
        $routes = \Illuminate\Support\Facades\Route::getRoutes();

        $admin = collect($routes)->first(fn ($r) => $r->uri() === 'api/admin/order-detail');
        $this->assertNotNull($admin, 'api/admin/order-detail is no longer registered.');
        $this->assertContains('GET', $admin->methods());
        $this->assertContains('custom.auth', $admin->gatherMiddleware());

        $storefront = collect($routes)->first(fn ($r) => $r->uri() === 'api/{slug}/order-detail');
        $this->assertNotNull($storefront, 'api/{slug}/order-detail is no longer registered.');
        $this->assertContains('POST', $storefront->methods());
        $this->assertContains('custom.auth', $storefront->gatherMiddleware());
    }
}
