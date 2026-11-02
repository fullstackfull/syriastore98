<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * P1-SEC-42 -- ONE DELIVERY COMMISSION ROW PER ORDER, ON BOTH HANDLERS THAT WRITE ONE.
 *
 * ===============================================================================================
 *  A LIVE MONEY DEFECT THAT GATE F MADE REACHABLE. MEASURED, THEN FIXED, THEN RE-MEASURED.
 * ===============================================================================================
 *
 * Both assignment handlers delete the existing commission row only when the driver CHANGES:
 *
 *     if ($order->deliveryboy_id != $request->delivery_boy) {
 *         DeliveryEarning::where('order_id', $order->id)->delete();
 *     }
 *
 * and both then inserted a new one gated on nothing of the kind. So re-submitting the SAME driver
 * skipped the delete and still inserted. Measured through the real web route before the fix, with
 * a `local pickup` method, `orders.delivery_price` 20 and a driver at 10%:
 *
 *     1 POST -> 1 row, SUM(commission_amount) = 2.00
 *     2 POSTs -> 2 rows, 4.00
 *     3 POSTs -> 3 rows, 6.00        <-- 2.00 is what is owed
 *
 * A merchant double-clicking the assign button is enough, and so is an AJAX retry.
 *
 * WHY IT WAS INVISIBLE. `delivery_earnings` was declared by no migration, so the insert answered
 * HTTP 500 to every caller and nothing could be duplicated. GATE F's
 * 2026_11_02_000600_create_delivery_earnings_table.php is what made the path live -- the same
 * direction as the `currency_rates` defect found the same day: behaviour that depended on a table
 * being ABSENT. Directive E's prohibition list names this shape directly ("duplicate
 * payments/withdrawals/stock movements").
 *
 * FOUND BY INDEPENDENT REVIEW, not by me, and not taken on the report: the duplication was
 * re-measured here with a throwaway probe through the real route before a line was changed.
 *
 * WHAT THE FIX IS, AND WHY IT IS NOT AN UNCONDITIONAL DELETE. The insert is now gated on the
 * ABSENCE of a row for that order, evaluated AFTER the delete. An unconditional delete and an
 * `updateOrCreate` both also stop the duplication -- and both destroy money state, because a row
 * already moved to `paid` would come back as `pending`. Doing nothing when the earning is already
 * recorded is the only one of the three that is idempotent in the `status` column too, and the
 * last test below is what holds that.
 *
 * THE WEB HANDLER AND THE FLUTTER ONE ARE BOTH COVERED, because the defect was in both:
 * `OrderController::order_assign` (POST /order-assign) and
 * `Api\DashboardController::assignOrder` (POST api/admin/assignOrders). The API one wraps its
 * writes in `DB::beginTransaction()`, which does not help and is worth stating: each request is
 * its own transaction and each commits a valid-looking duplicate. A transaction makes a write
 * atomic, not idempotent.
 */
class DeliveryCommissionIdempotencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PERCENTAGE = 10;

    private const DELIVERY_PRICE = 20;

    /** 20 * 10% */
    private const OWED = 2.0;

    /**
     * A shipping method named `local pickup`, which is the only name either handler pays
     * commission on (`strtolower(trim($shippingMethod->method_name)) === 'local pickup'`).
     *
     * Built through the raw tables because `shipping_zones` requires `zone_name` and
     * `shipping_method`, and `shipping_methods` requires `zone_id` -- a fixture that guessed at
     * either would fail inside a money assertion and read as a money result.
     */
    private function localPickupMethodId(int $storeId): int
    {
        $zoneId = DB::table('shipping_zones')->insertGetId([
            'zone_name' => 'commission-fixture-zone',
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

    private function commissionDriver(int $storeId, int $createdBy, string $email): DeliveryBoy
    {
        $driver = new DeliveryBoy();
        $driver->forceFill([
            'name' => 'COMMISSION-FIXTURE',
            'email' => $email,
            'password' => Hash::make('driver-password-fixture'),
            'contact' => '0100000000',
            'profile_image' => 'uploads/profile/avatar.png',
            'type' => 'deliveryboy',
            'store_id' => $storeId,
            'created_by' => $createdBy,
            // Both columns are GATE F additions
            // (2026_11_02_000500_add_commission_columns_to_delivery_boys_table.php). Without them
            // the commission branch is unreachable and every assertion below would pass vacuously,
            // which the premise test guards against.
            'is_commission_enabled' => 1,
            'commission_percentage' => self::PERCENTAGE,
        ])->save();

        return $driver;
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: \App\Models\Order, 3: DeliveryBoy} */
    private function commissionScenario(string $driverEmail): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $order = $this->makeOrder($store, [
            'delivery_id' => $this->localPickupMethodId((int) $store->id),
            'delivery_price' => self::DELIVERY_PRICE,
        ]);

        return [$merchant, $store, $order, $this->commissionDriver((int) $store->id, (int) $store->created_by, $driverEmail)];
    }

    private function earnings(int $orderId): \Illuminate\Database\Query\Builder
    {
        return DB::table('delivery_earnings')->where('order_id', $orderId);
    }

    /**
     * PREMISE. Without this, every assertion below passes against a handler that never pays a
     * commission at all -- which is exactly how this family of tests has passed for the wrong
     * reason before.
     */
    public function test_premise_the_commission_path_is_reachable_at_all(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('delivery_earnings'),
            'delivery_earnings is absent, so the commission branch cannot run and nothing below '
            . 'would prove anything. GATE F declared it on 2026-11-02.'
        );

        foreach (['commission_percentage', 'is_commission_enabled'] as $column) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('delivery_boys', $column),
                "delivery_boys.{$column} is absent, so is_commission_enabled can never be 1 and "
                . 'the commission branch is unreachable.'
            );
        }

        [$merchant, , $order, $driver] = $this->commissionScenario('premise@example.test');

        $this->actingAs($merchant->fresh())
            ->post('/order-assign', ['order_id' => $order->id, 'delivery_boy' => $driver->id])
            ->assertOk();

        $this->assertSame(
            1,
            $this->earnings((int) $order->id)->count(),
            'A single assignment wrote NO commission row, so this fixture does not reach the '
            . 'commission branch and the duplication assertions below are vacuous. Check the '
            . 'shipping method name, delivery_price and the two driver commission columns.'
        );

        $this->assertEquals(
            self::OWED,
            (float) $this->earnings((int) $order->id)->sum('commission_amount'),
            'the single commission is not 20 * 10%'
        );
    }

    /**
     * THE WEB HANDLER. Three identical submissions must leave one row and one commission.
     */
    public function test_reassigning_the_same_driver_on_the_web_does_not_duplicate_the_commission(): void
    {
        [$merchant, , $order, $driver] = $this->commissionScenario('web-dup@example.test');

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($merchant->fresh())
                ->post('/order-assign', ['order_id' => $order->id, 'delivery_boy' => $driver->id])
                ->assertOk();
        }

        $this->assertSame(
            1,
            $this->earnings((int) $order->id)->count(),
            'THE DRIVER WAS CREDITED MORE THAN ONCE FOR ONE ASSIGNMENT. The insert in '
            . 'OrderController::order_assign() is ungated again while the delete above it is '
            . 'gated on a CHANGE of driver. This is P1-SEC-42.'
        );

        $this->assertEquals(
            self::OWED,
            (float) $this->earnings((int) $order->id)->sum('commission_amount'),
            'the total owed is not 20 * 10% after three identical submissions'
        );
    }

    /**
     * THE FLUTTER ADMIN HANDLER, which had the identical defect and its own transaction around it.
     */
    public function test_reassigning_the_same_driver_through_the_admin_api_does_not_duplicate_it(): void
    {
        [$merchant, $store, $order, $driver] = $this->commissionScenario('api-dup@example.test');

        $token = \App\Models\User::find($merchant->id)->createToken('auth_token')->plainTextToken;

        // `store_id` is a QUERY parameter on this handler, and it is the caller's own input:
        // resolveOwnedStore() is what proves the caller owns it (NEW-ADMINAPI-UNOWNED-STORE-01).
        // Passing the merchant's OWN store is therefore the accepted path, which is what this test
        // needs -- the ownership refusal has its own coverage in AdminApiTenancyTest.
        $uri = '/api/admin/assignOrders?store_id=' . $store->id;

        for ($i = 0; $i < 3; $i++) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson($uri, [
                    'order_id' => $order->id,
                    'delivery_boy' => $driver->id,
                ]);

            $this->assertNotSame(404, $response->getStatusCode(), 'the admin API route must exist');
            $this->assertSame(
                200,
                $response->getStatusCode(),
                'the admin API refused an assignment on the caller\'s own store, so the duplication '
                . 'assertion below would be vacuous: ' . $response->getContent()
            );
        }

        $this->assertSame(
            1,
            $this->earnings((int) $order->id)->count(),
            'THE DRIVER WAS CREDITED MORE THAN ONCE through Api\\DashboardController::assignOrder(). '
            . 'Its DB::beginTransaction() does not prevent this -- each request is its own '
            . 'transaction and each commits a valid-looking duplicate. This is P1-SEC-42.'
        );

        $this->assertEquals(
            self::OWED,
            (float) $this->earnings((int) $order->id)->sum('commission_amount'),
            'the total owed is not 20 * 10% after three identical admin-API submissions'
        );
    }

    /**
     * AND THE FIX MUST NOT HAVE TURNED THE COMMISSION OFF, which is the way an idempotency guard
     * usually goes wrong: a genuine CHANGE of driver must still move the money to the new driver.
     */
    public function test_changing_the_driver_still_moves_the_commission_to_the_new_driver(): void
    {
        [$merchant, $store, $order, $first] = $this->commissionScenario('change-first@example.test');

        $this->actingAs($merchant->fresh())
            ->post('/order-assign', ['order_id' => $order->id, 'delivery_boy' => $first->id])
            ->assertOk();

        $this->assertSame((int) $first->id, (int) $this->earnings((int) $order->id)->value('delivery_boy_id'));

        $second = $this->commissionDriver((int) $store->id, (int) $store->created_by, 'change-second@example.test');

        $this->actingAs($merchant->fresh())
            ->post('/order-assign', ['order_id' => $order->id, 'delivery_boy' => $second->id])
            ->assertOk();

        $this->assertSame(
            1,
            $this->earnings((int) $order->id)->count(),
            'changing the driver left two commission rows on one order'
        );

        $this->assertSame(
            (int) $second->id,
            (int) $this->earnings((int) $order->id)->value('delivery_boy_id'),
            'THE COMMISSION STILL BELONGS TO THE PREVIOUS DRIVER. The idempotency guard has '
            . 'suppressed the replacement as well as the duplicate -- it must be evaluated AFTER '
            . 'the delete, not before it.'
        );
    }

    /**
     * THE ORDER OF THE OWNERSHIP GUARD AND THE DESTRUCTIVE DELETE, which nothing else proves.
     *
     * `order_assign` refuses a foreign driver at OrderController.php:2360-2367 and only THEN runs
     * `DeliveryEarning::where('order_id', $order->id)->delete()` at :2371. Those two could be
     * written the other way round without any existing test noticing, because every other test in
     * this family drives a virgin order with no commission row to lose -- pointed out by
     * independent review of the wave-4 rewrite, which had exactly that gap.
     *
     * If the delete ran first, a merchant could destroy the commission already owed on their own
     * order by submitting ANOTHER store's driver id and taking the 403. The refusal would look
     * correct and the money would be gone.
     *
     * This is also why the test uses ONE order for both halves: the commission has to already
     * exist when the refused request arrives.
     */
    public function test_a_refused_cross_store_assignment_does_not_destroy_the_recorded_commission(): void
    {
        [$merchant, , $order, $mine] = $this->commissionScenario('guard-order-own@example.test');
        [, $otherStore] = $this->makeTenant();
        $theirs = $this->commissionDriver(
            (int) $otherStore->id,
            (int) $otherStore->created_by,
            'guard-order-foreign@example.test'
        );

        $this->actingAs($merchant->fresh())
            ->post('/order-assign', ['order_id' => $order->id, 'delivery_boy' => $mine->id])
            ->assertOk();

        $this->assertSame(1, $this->earnings((int) $order->id)->count(), 'premise: a commission exists');

        $refused = $this->actingAs($merchant->fresh())
            ->post('/order-assign', ['order_id' => $order->id, 'delivery_boy' => $theirs->id]);

        $this->assertSame(403, $refused->getStatusCode(), 'the foreign driver was not refused');

        $this->assertSame(
            1,
            $this->earnings((int) $order->id)->count(),
            'A REFUSED ASSIGNMENT DESTROYED THE COMMISSION ALREADY OWED. The ownership guard is '
            . 'now running AFTER the DeliveryEarning delete, so taking a 403 with another store\'s '
            . 'driver id wipes the earning on the caller\'s own order.'
        );

        $this->assertSame(
            (int) $mine->id,
            (int) $this->earnings((int) $order->id)->value('delivery_boy_id'),
            'the surviving commission no longer belongs to the driver who earned it'
        );

        $this->assertSame(
            (int) $mine->id,
            (int) DB::table('orders')->where('id', $order->id)->value('deliveryboy_id'),
            'the order itself was re-stamped with the foreign driver'
        );
    }

    /**
     * THE REASON THE GUARD IS `exists()` AND NOT A DELETE-AND-REINSERT.
     *
     * An unconditional delete, or an `updateOrCreate`, also stops the duplication -- and both
     * would reset a commission already moved to `paid` back to `pending`, which is a money
     * regression dressed as a fix. This test fails against either of those designs and is the
     * only thing in the suite that does.
     */
    public function test_a_commission_already_marked_paid_survives_a_resubmission(): void
    {
        [$merchant, , $order, $driver] = $this->commissionScenario('paid@example.test');

        $this->actingAs($merchant->fresh())
            ->post('/order-assign', ['order_id' => $order->id, 'delivery_boy' => $driver->id])
            ->assertOk();

        $this->earnings((int) $order->id)->update(['status' => 'paid']);
        (new \App\Models\DeliveryEarning())->newQuery(); // no cache to flush: DeliveryEarning is not Cachable

        $this->actingAs($merchant->fresh())
            ->post('/order-assign', ['order_id' => $order->id, 'delivery_boy' => $driver->id])
            ->assertOk();

        $this->assertSame(
            1,
            $this->earnings((int) $order->id)->count(),
            'the resubmission duplicated a paid commission'
        );

        $this->assertSame(
            'paid',
            $this->earnings((int) $order->id)->value('status'),
            'A COMMISSION ALREADY PAID WAS RESET TO PENDING by re-submitting the same driver. The '
            . 'guard has been changed to an unconditional delete or an updateOrCreate, either of '
            . 'which destroys money state while appearing to fix the duplication.'
        );
    }
}
