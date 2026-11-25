<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-142 / P4-F108 — `POST api/admin/assignOrders` HAD NO PERMISSION CHECK, AND IT MOVES MONEY.
 * =================================================================================================
 *
 * This is the money-write row of GAP-142 and the reason it is first. `assignOrder()` resolved
 * TENANCY correctly — `resolveOwnedStore()` refuses a foreign store id, and the order and the
 * driver are each looked up with a `store_id` predicate — and then asked NOTHING about who inside
 * the store may act. Three writes followed, for any authenticated member of the tenant holding no
 * order permission of any kind:
 *
 *   1. `DeliveryEarning::where('order_id', ...)->delete()` — the driver's accrued commission for
 *      that order, destroyed. There is no soft delete on `delivery_earnings` and no audit row.
 *   2. `orders.deliveryboy_id` — the parcel reassigned to a different rider.
 *   3. a fresh `delivery_earnings` insert crediting the NEW rider, when the order ships by local
 *      pickup and that rider has commission enabled.
 *
 * So an unpermitted staff member could move a commission from one rider to another, or simply
 * delete it by reassigning to a rider with commission disabled. That is why the refusal assertions
 * below are not status-only: **a 403 with the write already done is the failure mode that
 * matters**, so each one reads the money back.
 *
 * -------------------------------------------------------------------------------------------------
 * THE PERMISSION NAME IS `Manage Order`, WHICH IS THE WEB TWIN'S OWN NAME, NOT AN ANALOGY.
 *
 * `OrderController::order_assign()` (`:2487`) authorises through `$this->authorize('assign', $order)`
 * and `OrderPolicy::assign()` (`:105-108`) is `allow($actor, $order, 'Manage Order')`. This gate
 * therefore names exactly what the web route for the same action names — the singular merchant
 * family (`Utility.php`), not the plural platform one, which no gate in `app/` reads.
 *
 * `Manage Deliveryboy` was considered and rejected: it is the permission for managing the RIDER
 * RECORD (`DeliveryBoyController`), not for changing an order. Naming it would have let a staff
 * member who may edit a rider's phone number move that rider's commissions.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE GATE HAS AN OWNER LIMB WHEN THE WEB POLICY DOES NOT, AND THIS IS MEASURED.
 *
 * `OrderPolicy::allow()` has no owner limb: a non-operator must hold the name. Copying that here
 * would have broken a shipped contract, and the test that proves it already exists —
 * `AdminApiTenancyTest::test_assign_order_lets_the_owner_past_the_gate_and_completes` drives this
 * route as a merchant built by `twoTenants()`, which grants **no permission at all**. A
 * permission-only gate turns that green test red. That is the regression CLAUDE.md records this
 * family producing, and `callerIsStoreOwner()` exists for it.
 *
 * It is not an escalation: the store's owner is the principal who creates the roles and grants the
 * permissions in their own tenant, so there is no right they cannot give themselves through the
 * product.
 *
 * -------------------------------------------------------------------------------------------------
 * THE OPERATOR IS ADMITTED, and that matches the web twin exactly — `OrderPolicy::allow()` returns
 * true for a platform admin BEFORE the `isAbleTo()` call, precisely because `isAbleTo()` alone
 * refuses the operator (no super-admin bypass; operator status is a string on `users.type`).
 * `MerchantPermissions::allowsAny()` answers `WILDCARD` for them, which is where that decision
 * lives on the API side.
 *
 * -------------------------------------------------------------------------------------------------
 * THE REFUSAL IS AN EXPLICIT 403. `ApiResponser::error()` answers HTTP **200** with `{status: 0}`,
 * so a refusal through it is a 200 no client distinguishes from success; and a 401 force-clears the
 * shipped Flutter app's session. `orderPermissionDenied()` is the 403 shape `DeleteOrder` and
 * `ViewOrder` already use. This handler's own pre-existing refusals are already real 403s
 * (`response()->json(..., 403)`), so a status assertion here is not reading an envelope quirk.
 */
class AdminApiAssignOrderPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PERCENTAGE = 10;

    private const DELIVERY_PRICE = 20;

    /** 20 * 10% — what the FIRST rider is owed, and must still be owed after a refusal. */
    private const OWED = 2.0;

    /**
     * A shipping method literally named `local pickup`, the only name `assignOrder()` pays
     * commission on (`strtolower(trim($shippingMethod->method_name)) === 'local pickup'`).
     *
     * Built through the raw tables for the reason `DeliveryCommissionIdempotencyTest` gives:
     * `shipping_zones` requires `zone_name` and `shipping_method`, `shipping_methods` requires
     * `zone_id`, and a fixture that guessed at either would fail INSIDE a money assertion and read
     * as a money result.
     */
    private function localPickupMethodId(int $storeId): int
    {
        $zoneId = DB::table('shipping_zones')->insertGetId([
            'zone_name' => 'assign-gate-fixture-zone',
            'shipping_method' => 'local pickup',
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('shipping_methods')->insertGetId([
            'method_name' => 'Local Pickup',
            'zone_id' => $zoneId,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function commissionDriver(Store $store, string $email): DeliveryBoy
    {
        $driver = new DeliveryBoy();
        $driver->forceFill([
            'name' => 'ASSIGN-GATE-FIXTURE',
            'email' => $email,
            'password' => Hash::make('not-a-real-credential'),
            'contact' => '0100000000',
            'profile_image' => 'uploads/profile/avatar.png',
            'type' => 'deliveryboy',
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            // Both columns are GATE F additions. Without them the commission branch is
            // unreachable and every money assertion below would pass vacuously, which the premise
            // test guards against.
            'is_commission_enabled' => 1,
            'commission_percentage' => self::PERCENTAGE,
        ])->save();

        return $driver;
    }

    /**
     * A tenant whose order is ALREADY assigned to rider one and ALREADY carries rider one's
     * commission. Both are necessary: an unassigned order makes the `deliveryboy_id` write
     * undetectable, and an order with no earning row makes the DELETE at `:10364` undetectable.
     *
     * @return array{0:User,1:Store,2:Order,3:DeliveryBoy,4:DeliveryBoy}
     */
    private function assignedScenario(string $ref): array
    {
        [$merchant, $store] = $this->makeTenant();

        $riderOne = $this->commissionDriver($store, 'rider-one-' . $ref . '@example.test');
        $riderTwo = $this->commissionDriver($store, 'rider-two-' . $ref . '@example.test');

        $order = $this->makeOrder($store, [
            'delivery_id' => $this->localPickupMethodId((int) $store->id),
            'delivery_price' => self::DELIVERY_PRICE,
            'deliveryboy_id' => $riderOne->id,
        ]);

        DB::table('delivery_earnings')->insert([
            'delivery_boy_id' => $riderOne->id,
            'order_id' => $order->id,
            'shipping_method_id' => $order->delivery_id,
            'shipping_cost' => self::DELIVERY_PRICE,
            'commission_percentage' => self::PERCENTAGE,
            'commission_amount' => self::OWED,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [User::find($merchant->id), $store, $order, $riderOne, $riderTwo];
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

    private function assign(User $user, Store $store, Order $order, DeliveryBoy $rider)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/assignOrders?store_id=' . $store->id, [
            'order_id' => $order->id,
            'delivery_boy' => $rider->id,
        ]);
    }

    /**
     * The earnings for an order, read through the QUERY BUILDER.
     *
     * `DeliveryEarning` is one of the sixteen models in `app/Models/` that are NOT `Cachable`, so
     * this is not a cache-avoidance measure — it is so that "the row is still there" is a statement
     * about the TABLE and not about whichever tenant happens to be resolved.
     */
    private function earnings(int $orderId): \Illuminate\Database\Query\Builder
    {
        return DB::table('delivery_earnings')->where('order_id', $orderId);
    }

    private function assignedRider(int $orderId): ?int
    {
        $row = Order::withoutGlobalScopes()->find($orderId);

        return $row === null ? null : (int) $row->deliveryboy_id;
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE PREMISE

    /**
     * WITHOUT THIS, EVERY MONEY ASSERTION BELOW PASSES AGAINST A HANDLER THAT MOVES NO MONEY.
     *
     * The commission branch needs four things at once: the `delivery_earnings` table, the two GATE
     * F columns on `delivery_boys`, a shipping method named `local pickup`, and a non-zero
     * `delivery_price`. If any is absent the handler assigns the rider and credits nothing, and
     * "the commission did not move" would be true of a no-op. This case proves the fixture reaches
     * the branch by driving a LEGITIMATE assignment and reading the new credit.
     */
    public function test_premise_the_commission_branch_is_reachable_with_this_fixture(): void
    {
        $this->assertTrue(Schema::hasTable('delivery_earnings'),
            'delivery_earnings is absent, so the commission writes cannot happen and nothing below '
            . 'would prove anything. GATE F declared it on 2026-11-02.');

        foreach (['commission_percentage', 'is_commission_enabled'] as $column) {
            $this->assertTrue(Schema::hasColumn('delivery_boys', $column),
                "delivery_boys.{$column} is absent, so is_commission_enabled can never be 1 and the "
                . 'commission branch is unreachable.');
        }

        [$merchant, $store, $order, $riderOne, $riderTwo] = $this->assignedScenario('premise');

        $this->assertSame((int) $riderOne->id, $this->assignedRider((int) $order->id),
            'fixture: the order did not start out assigned to rider one, so the reassignment '
            . 'assertions cannot distinguish a write from a no-op');
        $this->assertEqualsWithDelta(self::OWED, (float) $this->earnings((int) $order->id)->sum('commission_amount'), 0.001,
            'fixture: rider one does not start out credited, so "the commission was destroyed" is '
            . 'not a statement this suite can make');

        $response = $this->assign($merchant, $store, $order, $riderTwo);

        $this->assertSame(200, $response->status(),
            'the owner could not assign at all, so this fixture proves nothing about the gate: '
            . substr((string) $response->getContent(), 0, 400));

        $this->assertSame((int) $riderTwo->id, $this->assignedRider((int) $order->id),
            'a legitimate assignment did not move the rider, so this fixture never reaches the '
            . 'write the gate is supposed to protect');

        $this->assertSame(1, $this->earnings((int) $order->id)->count(),
            'a legitimate assignment left something other than exactly one earning row, so the '
            . 'commission branch is not behaving as this suite assumes');
        $this->assertSame((int) $riderTwo->id, (int) $this->earnings((int) $order->id)->value('delivery_boy_id'),
            'a legitimate assignment did not move the COMMISSION to the new rider, so the fixture '
            . 'does not reach the money writes at all and every refusal assertion below is vacuous');
    }

    // ════════════════════════════════════════════════════════════════════════════════ THE DEFECT

    /**
     * A staff member holding no order permission reassigned the parcel AND moved the commission.
     */
    public function test_a_staff_member_without_an_order_permission_cannot_assign_an_order(): void
    {
        [$merchant, $store, $order, $riderOne, $riderTwo] = $this->assignedScenario('defect');
        $staff = $this->staffIn($merchant, $store);

        $response = $this->assign($staff, $store, $order, $riderTwo);

        $this->assertSame(403, $response->status(),
            'a staff member holding no order permission reassigned an order through the admin API. '
            . 'resolveOwnedStore() settles tenancy and settles nothing about WHO in the store may '
            . 'move a parcel and its commission. Body: '
            . substr((string) $response->getContent(), 0, 400));
    }

    /**
     * AND THE MONEY DID NOT MOVE — asserted separately, because a 403 returned AFTER the writes is
     * the failure mode that matters and a status-only assertion cannot see it.
     */
    public function test_a_refused_assignment_leaves_the_commission_exactly_where_it_was(): void
    {
        [$merchant, $store, $order, $riderOne, $riderTwo] = $this->assignedScenario('money');
        $staff = $this->staffIn($merchant, $store);

        $this->assign($staff, $store, $order, $riderTwo);

        $this->assertSame(1, $this->earnings((int) $order->id)->count(),
            'the refused call changed the NUMBER of earning rows for this order. The handler opens '
            . 'with DeliveryEarning::where(order_id)->delete() and there is no soft delete and no '
            . 'audit row on delivery_earnings, so this is unrecoverable money.');

        $this->assertSame((int) $riderOne->id, (int) $this->earnings((int) $order->id)->value('delivery_boy_id'),
            'the refused call moved the commission from rider one to rider two');

        $this->assertEqualsWithDelta(self::OWED, (float) $this->earnings((int) $order->id)->sum('commission_amount'), 0.001,
            'the refused call changed how much is owed on this order');

        $this->assertSame((int) $riderOne->id, $this->assignedRider((int) $order->id),
            'the refused call still wrote orders.deliveryboy_id, so the parcel is with a rider the '
            . 'caller was not permitted to give it to');
    }

    /** 403, NEVER 401: the shipped Flutter app force-clears its session on any 401. */
    public function test_the_refusal_is_403_so_the_shipped_app_does_not_log_the_merchant_out(): void
    {
        [$merchant, $store, $order, , $riderTwo] = $this->assignedScenario('status');
        $staff = $this->staffIn($merchant, $store);

        $response = $this->assign($staff, $store, $order, $riderTwo);

        $this->assertNotSame(401, $response->status(),
            'a 401 here logs the merchant out of the whole app, which is a user-visible regression '
            . 'in a different product');
        $this->assertSame(403, $response->status());
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE CONTROLS

    /**
     * The store's own owner still assigns, holding NO order permission row.
     *
     * This is not a hypothetical control: `AdminApiTenancyTest::test_assign_order_lets_the_owner
     * _past_the_gate_and_completes` drives exactly this principal, so a permission-only gate turns
     * an already-green test red.
     */
    public function test_the_store_owner_still_assigns_their_own_order(): void
    {
        [$merchant, $store, $order, , $riderTwo] = $this->assignedScenario('owner');

        $response = $this->assign($merchant, $store, $order, $riderTwo);

        $this->assertSame(200, $response->status(),
            'the gate refused the store\'s OWN owner, which is the exact regression this family '
            . 'has already produced once: ' . substr((string) $response->getContent(), 0, 400));
        $this->assertSame((int) $riderTwo->id, $this->assignedRider((int) $order->id),
            'the owner got a 200 but the assignment did not happen');
    }

    /** Staff who hold `Manage Order` — the web twin's own name — still assign. */
    public function test_staff_holding_manage_order_still_assign(): void
    {
        [$merchant, $store, $order, , $riderTwo] = $this->assignedScenario('staff');
        $staff = $this->staffIn($merchant, $store, 'Manage Order');

        $response = $this->assign($staff, $store, $order, $riderTwo);

        $this->assertSame(200, $response->status(),
            'a staff member who DOES hold `Manage Order` was refused, so the gate names a '
            . 'permission that admits nobody. `OrderPolicy::assign()` is the web twin and it names '
            . 'this exact permission. Body: ' . substr((string) $response->getContent(), 0, 400));
        $this->assertSame((int) $riderTwo->id, $this->assignedRider((int) $order->id));
    }

    /** The platform operator, whom `isAbleTo()` alone refuses, still assigns. */
    public function test_the_platform_operator_still_assigns(): void
    {
        [, $store, $order, , $riderTwo] = $this->assignedScenario('operator');

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $response = $this->assign($operator, $store, $order, $riderTwo);

        $this->assertSame(200, $response->status(),
            'the platform operator was refused. `isAbleTo()` has no super-admin bypass, which is '
            . 'why the gate goes through MerchantPermissions -- it answers WILDCARD for the '
            . 'operator, and the web twin OrderPolicy::allow() admits them first for the same '
            . 'reason. Body: ' . substr((string) $response->getContent(), 0, 400));
    }

    // ══════════════════════════════════════════════════════════════════════ TENANCY IS UNCHANGED

    /**
     * The pre-existing tenancy control still holds, and must not be MISTAKEN for the new one. A
     * member of another tenant holding `Manage Order` in their OWN store is refused by
     * `resolveOwnedStore()` long before any permission question — so this proves the two axes are
     * independent rather than that either works.
     *
     * Asserted on the status AND on the money. This handler's tenancy refusal is a real 403 rather
     * than one of this API's HTTP-200 `{status: 0}` envelopes, so both are available here; the
     * money assertion is the one that would survive a change of refusal shape.
     */
    public function test_another_tenants_member_is_still_refused_and_moves_no_money(): void
    {
        [, $store, $order, $riderOne, $riderTwo] = $this->assignedScenario('tenancy');

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);
        $outsiderMerchant->current_store = $outsiderStore->id;
        $outsiderMerchant->save();
        $outsider = $this->staffIn(User::find($outsiderMerchant->id), $outsiderStore, 'Manage Order');

        $response = $this->assign($outsider, $store, $order, $riderTwo);

        /*
         * READ THE ENVELOPE FIRST, so that `assertSame(403)` below is known to be the right
         * question on THIS handler. On most of this API a refusal is an HTTP 200 carrying
         * `{status: 0}` (`ApiResponser::error()`), and asserting a status code there reads the
         * wrong field -- the trap that made the first draft of
         * `AdminApiOrderViewPermissionTest`'s tenancy case fail against working code.
         * `assignOrder()` is one of the handlers that does NOT use that envelope: every refusal in
         * it is an explicit `response()->json(['status' => 'error', ...], 4xx)`. Asserting the
         * literal `'error'` pins that, so if anyone converts this handler to `$this->error()` the
         * status assertion stops being silently vacuous.
         */
        $this->assertSame('error', $response->json('status'),
            'this handler no longer refuses with its own {status: "error"} envelope, so the status '
            . 'assertion below may be reading the wrong field -- on an ApiResponser::error() '
            . 'refusal the HTTP status is 200');
        $this->assertSame(403, $response->status(),
            'a member of another tenant was admitted to this store\'s order while holding '
            . '`Manage Order` in their OWN store');

        $this->assertSame((int) $riderOne->id, (int) $this->earnings((int) $order->id)->value('delivery_boy_id'),
            'the cross-tenant call moved another tenant\'s commission');
        $this->assertSame((int) $riderOne->id, $this->assignedRider((int) $order->id),
            'the cross-tenant call reassigned another tenant\'s parcel');
    }
}
