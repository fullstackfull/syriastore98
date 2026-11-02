<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-07-13 — THE WRITE SIDE. Reading order money was gated; changing order state was not.
 * =================================================================================================
 *
 * Found while measuring P2-07-11 ("quick daily operations"), which is how a merchant changes an
 * order's status from the app: `showPaymentStatusBottomSheet` is reachable from the order list, the
 * order detail screen and a customer's detail screen, and it posts to
 * `api/admin/orders/update-status`.
 *
 * THAT HANDLER HAD NO PERMISSION CHECK. `Api\DashboardController::updateStatus` resolves the store
 * from `getCurrentStore()` — derived from the authenticated principal, so the TENANT boundary is
 * sound — and then writes `delivered_status` and `payment_status` with nothing asking whether this
 * principal may. So a staff member with no order permission at all could mark any order in their
 * employer's store Delivered, or mark it **Paid**.
 *
 * THAT IS WORSE THAN THE READ SIDE THIS SLICE STARTED WITH. A read exposes a figure; marking an
 * unpaid order Paid changes what the merchant believes they are owed.
 *
 * THE GATE IS THE WEB'S OWN POLICY, NOT A COPIED PERMISSION NAME. `OrderPolicy::updateStatus`
 * already exists, already requires `Manage Order`, already checks the platform operator FIRST
 * (because `isAbleTo()` refuses them), and already re-checks `sameStore`. `OrderController` calls
 * `$this->authorize('updateStatus', $order)` at four sites. Using the same ability means the two
 * surfaces cannot drift about who may change an order — which is exactly the drift P2-04-4 found
 * between two of the web's own screens.
 *
 * AND THE REFUSAL IS 403, because the shipped app force-clears its session on any 401.
 */
class AdminApiOrderWritePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Store, 2: Order} */
    private function tenant(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $order = $this->makeOrder($store, [
            'delivered_status' => 0,
            'payment_status' => 'Unpaid',
        ]);

        return [User::find($merchant->id), $store, $order];
    }

    private function send(User $user, Order $order, array $payload)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/orders/update-status', array_merge([
            'product_order_id' => $order->product_order_id,
        ], $payload));
    }

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    /** THE DEFECT: a staff member with no order permission moved an order to Delivered. */
    public function test_a_staff_member_without_the_order_permission_cannot_change_the_status(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store, 'Manage Product Category');

        $response = $this->send($staff, $order, ['delivered_status' => 1]);

        $this->assertSame(403, $response->getStatusCode(),
            'a staff member with no order permission changed an order status. Body: '
            . substr((string) $response->getContent(), 0, 200));

        $this->assertSame(0, (int) $order->fresh()->delivered_status,
            'the refusal did not actually prevent the write');
    }

    /**
     * AND THE MONEY-BEARING HALF, which is the worse one: marking an unpaid order Paid changes
     * what the merchant believes they are owed.
     */
    public function test_a_staff_member_without_the_order_permission_cannot_mark_an_order_paid(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store, 'Manage Product Category');

        $response = $this->send($staff, $order, ['payment_status' => 'Paid']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Unpaid', (string) $order->fresh()->payment_status,
            'an unpaid order was marked Paid by a principal with no order permission');
    }

    /** NEVER 401: a 401 force-clears the session in the shipped app. */
    public function test_the_refusal_is_not_401(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store, 'Manage Product Category');

        $this->assertNotSame(401, $this->send($staff, $order, ['delivered_status' => 1])->getStatusCode(),
            'a permission refusal answered 401, which logs the merchant out of the app');
    }

    /** THE COUNTERPART: the store owner must still be able to do their daily work. */
    public function test_the_store_owner_can_still_change_the_status(): void
    {
        [$merchant, , $order] = $this->tenant();
        $this->grantPermission($merchant, 'Manage Order');

        $response = $this->send(User::find($merchant->id), $order, ['delivered_status' => 1]);

        $this->assertNotSame(403, $response->getStatusCode(),
            'the store owner was refused their own order. Body: '
            . substr((string) $response->getContent(), 0, 200));
        $this->assertSame(1, (int) $order->fresh()->delivered_status,
            'the owner\'s write did not land');
    }

    /** And a staff member who DOES hold the permission. */
    public function test_a_permitted_staff_member_can_change_the_status(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store, 'Manage Order');

        $this->assertNotSame(403, $this->send($staff, $order, ['delivered_status' => 1])->getStatusCode());
        $this->assertSame(1, (int) $order->fresh()->delivered_status);
    }

    /**
     * THE PLATFORM OPERATOR, because `isAbleTo()` refuses them and `OrderPolicy::allow` checks
     * `isPlatformAdmin` first for exactly that reason.
     */
    public function test_the_operator_is_not_refused(): void
    {
        $operator = $this->makePlatformAdmin();
        $store = $this->makeStore($operator, ['default_language' => 'en']);
        $operator->current_store = $store->id;
        $operator->save();

        $order = $this->makeOrder($store, ['delivered_status' => 0, 'payment_status' => 'Unpaid']);

        $this->assertNotSame(403,
            $this->send(User::find($operator->id), $order, ['delivered_status' => 1])->getStatusCode(),
            'the platform operator was refused an order write');
    }

    /**
     * THE API'S OWN MANUAL-ORDER-ENTRY HOLE, the same shape as the web's `OrderController::store()`
     * and found by asking whether the app had a twin of it.
     *
     * `createOrderViaApi` (route `api/admin/createOrderViaApi`, called from the app's
     * create-order flow) spans 328 lines, contains TWO order-creating calls, and — measured by
     * grepping its whole body — **zero** permission checks of any kind. `resolveOwnedStore()`
     * gives it a sound tenant boundary and nothing else, so a staff member with no order
     * permission could create a real order, with a real `final_price` and `payment_status`, in
     * their employer's store.
     *
     * ASSERTED ON THE DATABASE, not on the status code: the handler's failure modes overlap with
     * its success modes closely enough that a status assertion could pass while the row landed.
     */
    public function test_the_api_order_create_is_refused_without_the_order_permission(): void
    {
        [$merchant, $store] = $this->tenant();
        $staff = $this->staffIn($merchant, $store, 'Manage Product Category');

        $before = Order::withoutGlobalScopes()->count();

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $staff->createToken('auth_token')->plainTextToken
        )->postJson(
            '/api/admin/createOrderViaApi?store_id=' . $store->id,
            $this->validApiOrderPayload($store)
        );

        $this->assertSame($before, Order::withoutGlobalScopes()->count(),
            'a principal with no order permission created an order through the API');

        $this->assertNotSame(401, $response->getStatusCode(),
            'a permission refusal answered 401, which logs the merchant out of the app');
    }

    /**
     * THE COUNTERPART, and the reason the test above means anything: the SAME payload through a
     * permitted principal must still create the order. Without this, a payload the handler simply
     * rejects would satisfy the refusal test and prove nothing.
     */
    public function test_a_permitted_principal_can_still_create_an_order_through_the_api(): void
    {
        [$merchant, $store] = $this->tenant();
        $this->grantPermission($merchant, 'Manage Order');
        $merchant = User::find($merchant->id);

        $before = Order::withoutGlobalScopes()->count();

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        )->postJson(
            '/api/admin/createOrderViaApi?store_id=' . $store->id,
            $this->validApiOrderPayload($store)
        );

        $this->assertSame($before + 1, Order::withoutGlobalScopes()->count(),
            'the gate refused a principal who holds Manage Order, which breaks order creation '
            . 'from the app. Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /** @return array<string, mixed> */
    private function validApiOrderPayload(Store $store): array
    {
        $product = \App\Models\Product::forceCreate([
            'name' => 'apiorder', 'slug' => 'apiorder-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'product_stock' => 9, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        // `status, 1` is part of the customer rule, so an inactive customer would be rejected for
        // the wrong reason.
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Api', 'last_name' => 'Buyer',
            'email' => 'apiorder' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'status' => 1, 'store_id' => $store->id,
        ]);

        return [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 10]],
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'first_name' => 'Api', 'last_name' => 'Buyer',
            'email' => 'apiorder@example.test', 'telephone' => '1',
            'address' => 'x',
        ];
    }

    /** The tenant boundary is unchanged, and still refuses before the permission question. */
    public function test_another_tenant_still_cannot_touch_the_order(): void
    {
        [, , $order] = $this->tenant();

        $other = $this->makeMerchant();
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);
        $other->current_store = $otherStore->id;
        $other->save();
        $this->grantPermission(User::find($other->id), 'Manage Order');

        $response = $this->send(User::find($other->id), $order, ['delivered_status' => 1]);

        $this->assertNotSame(200, $response->getStatusCode(),
            "another tenant changed this store's order");
        $this->assertSame(0, (int) $order->fresh()->delivered_status);
    }
}
