<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P4-F037 — `api/admin/status-change` AND `api/admin/order-cancel` HAVE NO PERMISSION CHECK.
 * =================================================================================================
 *
 * Both handlers resolve their order through `resolveChangeableOrder()`, which is a TENANT check and
 * a delivery-driver allowance — and nothing else. It asks whether the actor's tenant owns the
 * order's store; it never asks whether the actor may change an order.
 *
 * So a staff member of the merchant's own tenant, holding a role with no permissions at all, could
 * move any of that tenant's orders to Delivered, or **cancel** them. Cancelling is money-bearing:
 * it routes through `Order::order_status_change()`, which writes `payment_status = 'Unpaid'` and
 * makes `OrderObserver` reverse the merchant's wallet credit, and it restores the stock.
 *
 * THE DELIVERY DRIVER IS DELIBERATELY NOT GATED. A `DeliveryBoy` is not a Laratrust subject —
 * `isAbleTo()` on one is a fatal, and `MerchantPermissions::names()` returns `[]` for anything that
 * is not a `User`. Marking the parcel they are carrying as delivered is the driver's whole job, and
 * the assignment (`orders.deliveryboy_id`) is the authorization. `test_the_assigned_driver_is_not
 * _gated()` holds that, and it is the case a gate written as "everyone needs Manage Order" breaks —
 * which would take the driver app offline.
 *
 * THE READ SIBLING AT `resolveChangeableOrder()`'s third call site IS NOT TOUCHED. Gating order
 * READS is a separate decision with its own cost in the shipped app, and the finding does not claim
 * it. Writes are gated here; that boundary is deliberate and stated so nobody reads this slice as
 * "the helper is now a permission check".
 */
class AdminApiOrderStatusPermissionTest extends SecurityTestCase
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
            'payment_status' => 'Paid',
        ]);

        return [User::find($merchant->id), $store, $order];
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

    private function asUser(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        );
    }

    /**
     * `delivered_status` READ THROUGH THE QUERY BUILDER, not the model.
     *
     * `Order` is `Cachable` and the suite runs under `CACHE_STORE=array`, so an Eloquent read
     * after the handler's write can serve the pre-write row for the rest of the process.
     *
     * AND THE REQUEST FIELD IS NOT WHAT ITS NAME SAYS. `statusChange` passes `$request->delivered`
     * as `order_status`, which the engine matches against the WORD list ('confirmed', 'cancel',
     * 'delivered', ...). Sending `1` is refused by the engine with "That order status is not
     * recognised" and changes nothing — so the first draft of this suite's defect case passed
     * against the ungated handler, for the wrong reason. Measured, and the reason this comment
     * exists.
     */
    private function deliveredStatusOf(Order $order): int
    {
        return (int) Order::withoutGlobalScopes()->whereKey($order->id)->value('delivered_status');
    }

    /** THE DEFECT, on the status writer. */
    public function test_a_staff_member_without_the_order_permission_cannot_change_delivery_status(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->asUser($staff)
            ->postJson('/api/admin/status-change', ['id' => $order->id, 'delivered' => 'confirmed']);

        $this->assertSame(0, $this->deliveredStatusOf($order),
            'a staff member holding no permissions at all moved an order to Delivered. Body: '
            . substr((string) $response->getContent(), 0, 200));

        $this->assertNotSame(401, $response->getStatusCode(),
            'a 401 force-logs-out the shipped app');
    }

    /** THE DEFECT, on the money-bearing cancel writer. */
    public function test_a_staff_member_without_the_order_permission_cannot_cancel_an_order(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->asUser($staff)
            ->postJson('/api/admin/order-cancel', ['order_id' => $order->id]);

        $this->assertSame(0, $this->deliveredStatusOf($order),
            'a staff member holding no permissions at all cancelled an order. Cancelling is '
            . "money-bearing: it reverses the merchant's wallet credit and returns the goods to "
            . 'the shelf. Body: ' . substr((string) $response->getContent(), 0, 200));

        $this->assertNotSame(401, $response->getStatusCode(),
            'a 401 force-logs-out the shipped app');
    }

    /** The case that catches a gate naming a permission nobody holds, or refusing everyone. */
    public function test_a_permitted_staff_member_can_still_change_the_status(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store, 'Manage Order');

        $response = $this->asUser($staff)
            ->postJson('/api/admin/status-change', ['id' => $order->id, 'delivered' => 'confirmed']);

        $this->assertSame(4, $this->deliveredStatusOf($order),
            'a staff member who DOES hold `Manage Order` was refused. `confirmed` writes '
            . 'delivered_status = 4 (app/Models/Order.php). Body: '
            . substr((string) $response->getContent(), 0, 200));
    }

    /**
     * THE OPERATOR IS NOT REFUSED — `isAbleTo()` alone refuses them, because they hold no roles.
     */
    public function test_the_operator_is_not_refused(): void
    {
        [, $store, $order] = $this->tenant();

        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->current_store = $store->id;
        $operator->save();

        $response = $this->asUser(User::find($operator->id))
            ->postJson('/api/admin/status-change', ['id' => $order->id, 'delivered' => 'confirmed']);

        $this->assertSame(4, $this->deliveredStatusOf($order),
            'the platform operator was refused. Body: '
            . substr((string) $response->getContent(), 0, 200));
    }

    /**
     * THE ASSIGNED DRIVER IS NOT GATED, and this is the case a careless fix breaks.
     *
     * A `DeliveryBoy` is not a Laratrust subject — `MerchantPermissions::names()` returns `[]` for
     * anything that is not a `User`, and `isAbleTo()` on one is a fatal. Requiring a permission of
     * every caller would refuse every driver on the platform and take the driver app offline.
     */
    public function test_the_assigned_driver_is_not_gated(): void
    {
        [$merchant, $store, $order] = $this->tenant();

        $driver = \App\Models\DeliveryBoy::create([
            'name' => 'Driver',
            'email' => 'driver-' . $order->id . '@example.test',
            'password' => bcrypt('secret'),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $order->deliveryboy_id = $driver->id;
        $order->save();

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $driver->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/status-change', ['id' => $order->id, 'delivered' => 'confirmed']);

        $this->assertSame(4, $this->deliveredStatusOf($order),
            'the ASSIGNED driver was refused. A driver holds no Laratrust role, so a gate that '
            . 'requires one of every caller takes the driver app offline. Body: '
            . substr((string) $response->getContent(), 0, 200));
    }

    /**
     * AND A FOREIGN TENANT IS STILL REFUSED.
     *
     * Pinned because this slice edits the resolver these handlers share: a permission gate placed
     * so that it REPLACED the tenant check would leave the older and worse defect open while every
     * new case passed.
     */
    public function test_another_tenants_order_is_still_not_changeable(): void
    {
        [, , $victimOrder] = $this->tenant();

        $outsider = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsider, ['default_language' => 'en']);
        $outsider->current_store = $outsiderStore->id;
        $outsider->save();
        $this->grantPermission(User::find($outsider->id), 'Manage Order');

        $response = $this->asUser(User::find($outsider->id))
            ->postJson('/api/admin/status-change', ['id' => $victimOrder->id, 'delivered' => 1]);

        $this->assertSame(0, $this->deliveredStatusOf($victimOrder),
            'another tenant changed this order. Body: '
            . substr((string) $response->getContent(), 0, 200));
    }
}
