<?php

namespace Tests\Feature\Security;

use App\Exports\OrderExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-11 criterion 2 -- `getCurrentStore()` HARDCODES STORE 1 FOR THE OPERATOR, and the order
 * export is where that is reachable and observable.
 * =============================================================================================
 *
 * `getCurrentStore()` (helper.php:391-416) returns a literal `1` for a `users.type` of
 * 'super admin' whose `current_store` is empty. The criterion offers two ways to discharge that:
 * stop hardcoding it, or show every caller is safe with it. `OrderExport::collection()` is a
 * caller that is NOT safe with it, in two different ways:
 *
 *   1. `getStoreById(1)` is `Store::where('id', 1)->first()` -- NULL on any deployment whose first
 *      store was deleted, or whose stores simply do not start at 1 -- and the next line reads
 *      `$store->id`. That is the 500 this row names, and `fileExport`'s own
 *      `if (empty(getCurrentStore()))` guard CANNOT catch it, because getCurrentStore() returned 1
 *      rather than nothing.
 *   2. Where store 1 DOES exist and is not the operator's, the file downloads as `Order.xlsx`
 *      containing that store's orders, with nothing in the response saying which store it is.
 *
 * THE HELPER IS DELIBERATELY NOT CHANGED. config/tenancy.php:60-63 records two storefront outages
 * shipped by changing a helper this central, and `getCurrentStore()` is read by almost every
 * handler. The fix is at the caller: resolve the store, refuse when it does not exist.
 */
class OperatorOrderExportStoreTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The operator branch really is reachable: an operator with no current_store resolves store 1. */
    public function test_the_helper_resolves_store_one_for_an_operator_with_no_current_store(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->actingAs($operator);

        $this->assertSame(1, (int) getCurrentStore(),
            'The premise of this file: getCurrentStore() no longer hardcodes 1 for the operator, so '
            . 'criterion 2 is discharged by the helper and these tests measure nothing.');
    }

    /**
     * THE 500. No store carries id 1, the operator has no current_store, so the export dereferences
     * null. A refusal is the correct outcome: there is no store to export.
     */
    public function test_an_operator_with_no_current_store_and_no_store_one_does_not_crash_the_export(): void
    {
        // A tenant whose store is NOT id 1 -- the shape of any deployment that has deleted its first.
        [, $store] = $this->makeTenant();
        DB::table('stores')->where('id', $store->id)->update(['id' => 7]);

        $this->assertNull(DB::table('stores')->where('id', 1)->first(), 'Fixture premise: no store id 1.');

        $operator = $this->makePlatformAdmin(['current_store' => null]);
        $this->actingAs($operator);

        $this->assertSame(1, (int) getCurrentStore(), 'Premise: the hardcoded 1 is what is resolved.');

        $threw = null;

        try {
            (new OrderExport())->collection();
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNull($threw,
            'OrderExport dereferenced a store that does not exist: ' . ($threw ? $threw->getMessage() : ''));
    }

    /** And the route itself answers rather than 500ing. */
    public function test_the_export_route_refuses_instead_of_crashing(): void
    {
        [, $store] = $this->makeTenant();
        DB::table('stores')->where('id', $store->id)->update(['id' => 7]);

        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $response = $this->actingAs($operator)->get('/order/export');

        $this->assertNotSame(500, $response->status(),
            'The export route 500s for an operator with no current store on a deployment with no store 1.');
    }

    /** The legitimate journey: an operator WITH a current store exports that store's orders. */
    public function test_an_operator_with_a_current_store_still_exports_that_store(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $mine = $this->makeOrder($storeA, ['product_order_id' => 'MINE-1']);
        $theirs = $this->makeOrder($storeB, ['product_order_id' => 'THEIRS-1']);

        $operator = $this->makePlatformAdmin(['current_store' => $storeA->id]);
        $this->actingAs($operator);

        $rows = (new OrderExport())->collection();
        $ids = $rows->pluck('product_order_id')->all();

        $this->assertContains('MINE-1', $ids, 'The operator cannot export the store they are in.');
        $this->assertNotContains('THEIRS-1', $ids, 'The export crossed into another store.');
    }
}
