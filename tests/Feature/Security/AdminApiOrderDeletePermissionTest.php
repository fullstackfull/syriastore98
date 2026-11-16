<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\OrderBillingDetail;
use App\Models\OrderCouponDetail;
use App\Models\OrderTaxDetail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * P4-F107 — `DELETE /api/admin/deleteorder` HARD-DELETES AN ORDER WITH NO PERMISSION CHECK.
 * =================================================================================================
 *
 * `Api\DashboardController::DeleteOrder` resolves the store through `resolveOwnedStore()`, so the
 * TENANT boundary is sound — a previous repair closed the "any token deletes any order on the
 * platform" shape and the comment in the handler records it. What it never gained is a check of
 * whether this principal MAY delete.
 *
 * So any staff member of the merchant's own tenant, holding a role with no permissions at all,
 * could irreversibly destroy an order plus its `order_tax_details`, `order_coupon_details` and
 * `order_billing_details` rows by sending one integer. There is no soft delete on this path and no
 * audit row: the order is gone.
 *
 * THE GATE IS `MerchantPermissions::allowsAny()` AND NOT `isAbleTo()`, which is this codebase's
 * single most repeated trap: Laratrust runs with `permissions_as_gates => false` and there is no
 * super-admin bypass, so `isAbleTo('Delete Order')` REFUSES the platform operator. `allowsAny()`
 * tests the operator first and returns the wildcard. `test_the_operator_is_not_refused()` below is
 * what holds that, and it is the case a naive fix fails.
 *
 * `$store->id` AND NOT `current_store`, for the reason `MerchantPermissions::names()` states in its
 * own docblock: this handler serves whichever store `?store_id=` names, and `resolveOwnedStore()`
 * admits any store in the caller's tenant. A gate keyed on `current_store` would evaluate store A's
 * grants against store B's rows.
 *
 * AND THE REFUSAL IS 403, NEVER 401. `ApiResponser::error()` answers HTTP 200 by default, and the
 * shipped Flutter app force-clears its session on any 401 — so a 401 here is a user-visible
 * regression that logs merchants out. `test_the_refusal_is_not_a_401()` is its own case for that
 * reason rather than an extra assertion inside another.
 *
 * WHAT THE FIX COSTS, stated rather than discovered later. The shipped app calls this route
 * (`order_repository.dart::deleteOrderData`, which sends `store_id` and `order_id` as QUERY
 * parameters on a DELETE, not as a body — so the gate must not assume a body), and a merchant OWNER
 * does not implicitly hold `Delete Order`. Gating it therefore can refuse a live merchant whose
 * role lacks that permission. That is the same trade S2 records for the web side, and it is the
 * right direction: an un-permissioned irreversible delete is the worse failure.
 */
class AdminApiOrderDeletePermissionTest extends SecurityTestCase
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

        /*
         * THE CHILD ROWS, AND THEIR TABLES ASSERTED FIRST.
         *
         * Without the premise below a missing table would make the child-row inserts throw, or an
         * `assertDatabaseHas` answer about a table that is not there, and the case would report on
         * the parent row alone while claiming to cover all four.
         */
        foreach (['order_tax_details', 'order_coupon_details', 'order_billing_details'] as $table) {
            $this->assertTrue(Schema::hasTable($table),
                $table . ' does not exist in the test schema, so every assertion about it below '
                . 'is vacuous');
        }

        (new OrderTaxDetail())->forceFill([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'tax_name' => 'VAT',
            'tax_discount_amount' => 1.00,
            'tax_final_amount' => 1.00,
        ])->save();

        (new OrderCouponDetail())->forceFill([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'coupon_id' => 1,
            'coupon_name' => 'Ten',
            'coupon_code' => 'TEN',
            'coupon_discount_type' => 'flat',
            'coupon_discount_number' => 10.00,
            'coupon_discount_amount' => 10.00,
            'coupon_final_amount' => 10.00,
        ])->save();

        (new OrderBillingDetail())->forceFill([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'first_name' => 'Buyer',
            'last_name' => 'Person',
            'email' => 'buyer@example.test',
        ])->save();

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

    /**
     * QUERY PARAMETERS, NOT A BODY — the shape the shipped Flutter client really sends.
     *
     * `order_repository.dart::deleteOrderData` puts `store_id` and `order_id` on the URL of a
     * DELETE. Driving it as a JSON body would exercise the same `$request->order_id` read but
     * would not prove the gate works for the client that exists.
     */
    private function send(User $user, Store $store, Order $order)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deleteorder?store_id=' . $store->id . '&order_id=' . $order->id);
    }

    private function orderSurvives(Order $order): bool
    {
        return Order::withoutGlobalScopes()->whereKey($order->id)->exists();
    }

    /** THE DEFECT: a staff member with no order permission destroyed an order and its money rows. */
    public function test_a_staff_member_without_the_order_permission_cannot_delete_an_order(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->send($staff, $store, $order);

        $this->assertTrue($this->orderSurvives($order),
            'a staff member holding no permissions at all hard-deleted an order. There is no soft '
            . 'delete and no audit row on this path: the order is gone. Body: '
            . substr((string) $response->getContent(), 0, 200));

        $this->assertDatabaseHas('order_tax_details', ['order_id' => $order->id]);
        $this->assertDatabaseHas('order_coupon_details', ['order_id' => $order->id]);
        $this->assertDatabaseHas('order_billing_details', ['order_id' => $order->id]);
    }

    /**
     * THE CASE THAT CATCHES A GATE WIRED TO THE WRONG PERMISSION, or one that refuses everybody.
     *
     * Without it, "nobody can delete an order any more" passes the case above perfectly.
     */
    public function test_a_permitted_staff_member_can_still_delete(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store, 'Delete Order');

        $response = $this->send($staff, $store, $order);

        $this->assertFalse($this->orderSurvives($order),
            'a staff member who DOES hold `Delete Order` was refused, so the gate names a '
            . 'permission nobody has or refuses everyone. Body: '
            . substr((string) $response->getContent(), 0, 200));

        $this->assertDatabaseMissing('order_tax_details', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('order_coupon_details', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('order_billing_details', ['order_id' => $order->id]);
    }

    /**
     * THE STORE'S OWNER IS NOT REFUSED, even holding no role at all.
     *
     * THIS CASE EXISTS BECAUSE THE FIRST VERSION OF THE GATE DID REFUSE THEM, and two older tests
     * caught it — `AdminApiTenancyTest::test_delete_order_still_works_for_the_owning_merchant` and
     * `DeliveryAndOrderApiAuthorizationTest::test_status_change_still_works_for_the_owning_merchant`,
     * the latter saying in its own message "The predicate is too tight -- that is a defect, not a
     * fix." Pinned HERE as well so the limb has a test in the suite that owns the gate, rather than
     * only in the suites that happened to notice its absence.
     *
     * IT IS NOT AN ESCALATION: the owner is the principal who creates the roles and grants the
     * permissions in their own tenant. The gate's value is against STAFF, which the case above
     * holds.
     */
    public function test_the_store_owner_is_not_refused_without_a_permission(): void
    {
        [$merchant, $store, $order] = $this->tenant();

        $response = $this->send(User::find($merchant->id), $store, $order);

        $this->assertFalse($this->orderSurvives($order),
            "the store's own merchant was refused deletion of their own order while holding no "
            . 'role. Body: ' . substr((string) $response->getContent(), 0, 200));
    }

    /**
     * THE OPERATOR IS NOT REFUSED — the single most repeated trap in this codebase.
     *
     * Laratrust runs with `permissions_as_gates => false` and there is no super-admin bypass, so a
     * gate spelled `isAbleTo('Delete Order')` refuses the platform operator, who holds no roles.
     * This case fails against that spelling and passes against `MerchantPermissions::allowsAny()`,
     * which tests operator status first.
     */
    public function test_the_operator_is_not_refused(): void
    {
        [, $store, $order] = $this->tenant();

        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->current_store = $store->id;
        $operator->save();

        $response = $this->send(User::find($operator->id), $store, $order);

        $this->assertFalse($this->orderSurvives($order),
            'the platform operator was refused. `isAbleTo()` alone refuses them because they hold '
            . 'no roles; the gate must test operator status first. Body: '
            . substr((string) $response->getContent(), 0, 200));
    }

    /**
     * A 401 HERE IS A USER-VISIBLE REGRESSION, so it is its own case.
     *
     * The shipped Flutter app force-clears its session on any 401 from this base URL. A refusal
     * that logs the merchant out of the whole app is a worse outcome than the refusal itself.
     */
    public function test_the_refusal_is_not_a_401(): void
    {
        [$merchant, $store, $order] = $this->tenant();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->send($staff, $store, $order);

        $this->assertNotSame(401, $response->getStatusCode(),
            'the refusal is a 401, which force-logs-out the shipped app. Use 403.');
    }

    /**
     * AND A FOREIGN TENANT IS STILL REFUSED — the boundary a previous repair closed.
     *
     * Pinned here because this slice edits the same handler: a permission gate placed ABOVE the
     * tenant check, or one that replaced it, would leave the older and worse defect open while the
     * new cases all passed.
     */
    public function test_another_tenants_order_is_still_not_deletable(): void
    {
        [, , $victimOrder] = $this->tenant();

        $outsider = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsider, ['default_language' => 'en']);
        $outsider->current_store = $outsiderStore->id;
        $outsider->save();

        $this->grantPermission(User::find($outsider->id), 'Delete Order');

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . User::find($outsider->id)->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deleteorder?store_id=' . $outsiderStore->id
            . '&order_id=' . $victimOrder->id);

        $this->assertTrue($this->orderSurvives($victimOrder),
            'another tenant deleted this order. Body: '
            . substr((string) $response->getContent(), 0, 200));
    }
}
