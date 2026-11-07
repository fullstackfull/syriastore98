<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-C (F-C-09) — the customer TIMELINE lists activity, and sums money, from every store.
 * ================================================================================================
 *
 * `CustomerController::customerTimeline()` gets the hard part RIGHT and the easy part wrong. It
 * carries a correct ownership check on the customer ROW -- operator first, then a real store
 * comparison (`:399-409`) -- and then asks three questions with no store predicate at all:
 *
 *     ActivityLog::where('customer_id', $ids)->groupBy('customer_id')->first()   :411
 *     ActivityLog::where('customer_id', $ids)->get()                             :415
 *     ActivityLog::totalspend($ids)                                              :417
 *
 * The check decides WHICH CUSTOMER may be opened, not WHOSE ACTIVITY is listed. That is the same
 * distinction that produced F-SEC-01 through F-SEC-09 and F-C-07: a permission or an ownership
 * test on one object says nothing about the rows a later query returns.
 *
 * `ActivityLog` HAS a `store_id` column -- it is in `$fillable` and `order_entry()` sets it
 * (`ActivityLog.php:18,37`) -- and the model is absent from `scoped_models`, so nothing supplies
 * the predicate implicitly.
 *
 * WHAT THE FEED LEAKS IS NOT JUST "ACTIVITY". `order_entry()` writes `remark` as JSON carrying
 * `order_id`, `order_date`, `product`, `price` and `payment_type` (`ActivityLog.php:25-35`), so a
 * merchant reading one of their own customers' timelines sees ANOTHER STORE'S order references,
 * products and PRICES.
 *
 * And `ActivityLog::totalspend()` is `Order::where('customer_id', $userId)` with a
 * `SUM(final_price)` select expression and no store predicate, so Total Spend and Total Orders on
 * this page sum across the platform.
 *
 * ALREADY DECLARED OPEN for the totalspend half: `ResidualTenancyExposureTest`'s raw-expression
 * test records it by path and states the harm in as many words. The ACTIVITY FEED half was in no
 * ratchet at all.
 *
 * SAME STORE AUTHORITY AS F-C-07, and for the same measured reason: the customer's own
 * `store_id`, not `getCurrentStore()`, because `getCurrentStore()` returns the literal 1 for a
 * super admin with an empty `current_store` -- and this route deliberately admits the operator to
 * any store's customer, so a `getCurrentStore()` predicate would show them store 1's activity on a
 * store-7 customer's page. A fabrication, not a refusal.
 */
class CustomerTimelineTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * THE NEEDLE IS THE ORDER REFERENCE, NOT THE PRODUCT NAME, and that correction came from the
     * positive control failing. For a `place order` log the view renders `product` through
     * `ActivityLog::products()` (`customer/timeline.blade.php:280`), which resolves it as a
     * product ID -- so a string in that field renders as nothing. `order_id` (`:274`) and `price`
     * (`:283`) are rendered verbatim, so those are what a test can see.
     *
     * Without the positive control this file would have passed on an `assertDontSee` that held
     * because the field never rendered at all.
     */
    private function logOrderFor(int $customerId, int $storeId, string $reference, float $price): ActivityLog
    {
        return ActivityLog::forceCreate([
            'customer_id' => $customerId,
            'log_type' => 'place order',
            'remark' => json_encode([
                'order_id' => $reference,
                'order_date' => now()->toDateString(),
                'product' => 0,
                'price' => $price,
                'payment_type' => 'cod',
            ]),
            'store_id' => $storeId,
        ]);
    }

    public function test_the_timeline_does_not_list_another_stores_activity_for_the_same_customer(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Show Customer');

        $customer = Customer::forceCreate([
            'first_name' => 'Timeline', 'last_name' => 'Shopper',
            'email' => 'timeline-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeA->id,
            // `regiester_date` (the column really is spelled that way) IS LOAD-BEARING FOR THIS
            // TEST: `customer/timeline.blade.php:62` gates the ENTIRE timeline on
            // `!empty($customer_data->regiester_date)` and otherwise renders a "never registered"
            // branch with no activity at all. Without it the first draft of this file passed its
            // `assertDontSee` because NOTHING rendered -- caught by the positive control, which is
            // the only reason I know. A fixture fact, not a product defect.
            'regiester_date' => now()->toDateString(),
        ]);

        $ownRef = 'OWN-REF-' . uniqid();
        $foreignRef = 'FOREIGN-REF-' . uniqid();

        $this->logOrderFor($customer->id, $storeA->id, $ownRef, 11.00);
        $this->logOrderFor($customer->id, $storeB->id, $foreignRef, 999.00);

        $response = $this->actingAs($merchantA)->get('/customer-timeline/' . $customer->id);

        $response->assertStatus(200);

        // The other store's order reference and its PRICE must both be absent.
        $response->assertDontSee($foreignRef, false);
        $response->assertDontSee('999', false);

        // POSITIVE CONTROL: a fix that listed nothing would satisfy both assertions above, and
        // the first draft of this test did exactly that -- see logOrderFor()'s docblock.
        $response->assertSee($ownRef, false);
    }

    public function test_the_timeline_total_spend_sums_only_this_stores_orders(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Show Customer');

        $customer = Customer::forceCreate([
            'first_name' => 'Spend', 'last_name' => 'Shopper',
            'email' => 'spend-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeA->id,
            // `regiester_date` (the column really is spelled that way) IS LOAD-BEARING FOR THIS
            // TEST: `customer/timeline.blade.php:62` gates the ENTIRE timeline on
            // `!empty($customer_data->regiester_date)` and otherwise renders a "never registered"
            // branch with no activity at all. Without it the first draft of this file passed its
            // `assertDontSee` because NOTHING rendered -- caught by the positive control, which is
            // the only reason I know. A fixture fact, not a product defect.
            'regiester_date' => now()->toDateString(),
        ]);

        $this->makeOrder($storeA, ['customer_id' => $customer->id, 'final_price' => 25.00]);
        $this->makeOrder($storeB, ['customer_id' => $customer->id, 'final_price' => 775.00]);

        $totals = ActivityLog::totalspend($customer->id, (int) $storeA->id);

        $this->assertSame(
            1,
            (int) $totals['total_orders'],
            "Total Orders on the timeline counted another store's order."
        );
        $this->assertSame(
            25.0,
            (float) $totals['total_spend'],
            "Total Spend on the timeline summed another store's revenue."
        );
    }

    /**
     * FAILS CLOSED when no store is given, rather than falling back to every store. Without this
     * the helper's old signature would keep working platform-wide for any caller that forgot the
     * argument, which is how the defect would come back.
     */
    public function test_total_spend_with_no_store_sums_nothing(): void
    {
        [, $storeA] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'NoStore', 'last_name' => 'Arg',
            'email' => 'nostorearg-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeA->id,
            // `regiester_date` (the column really is spelled that way) IS LOAD-BEARING FOR THIS
            // TEST: `customer/timeline.blade.php:62` gates the ENTIRE timeline on
            // `!empty($customer_data->regiester_date)` and otherwise renders a "never registered"
            // branch with no activity at all. Without it the first draft of this file passed its
            // `assertDontSee` because NOTHING rendered -- caught by the positive control, which is
            // the only reason I know. A fixture fact, not a product defect.
            'regiester_date' => now()->toDateString(),
        ]);

        $this->makeOrder($storeA, ['customer_id' => $customer->id, 'final_price' => 500.00]);

        $totals = ActivityLog::totalspend($customer->id, null);

        $this->assertSame(0, (int) $totals['total_orders']);
        $this->assertSame(0.0, (float) $totals['total_spend']);
    }
}
