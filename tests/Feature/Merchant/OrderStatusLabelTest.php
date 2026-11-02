<?php

namespace Tests\Feature\Merchant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F44 -- the order list names every status it is given, and invents none.
 * =============================================================================================
 *
 * THE MARKUP THIS REPLACED RETURNED `''` FOR ANYTHING OUTSIDE 0-8, so an order carrying a tenth
 * status rendered with NO STATUS AT ALL: a blank cell in the one column a merchant scans the
 * list by. `tests/Feature/Lynomia/LynomiaCommerceDataTest` establishes that out-of-range values
 * do occur in this column, so this is not hypothetical.
 *
 * AND THE REWRITE NEARLY INTRODUCED THE OPPOSITE DEFECT. Casting with `(int)` first makes
 * `(int) null` into `0`, which is `Pending` -- a confident claim about fulfilment manufactured
 * out of an absent value, exactly like F19's epoch dates. Raised by the tenant/authz review as a
 * question; fixed rather than deferred, because the answer is not a preference.
 *
 * NULL IS NOT REACHABLE UNDER THE DECLARED SCHEMA and is guarded anyway.
 * `2024_01_23_061635_create_orders_table.php:33` declares `integer('delivered_status')->default(0)`,
 * i.e. NOT NULL. But CLAUDE.md's GATE F records that the migrations are NOT the schema -- 15
 * tables live code reads have no migration at all, and the restored production dump has already
 * refuted this file once. A one-line guard against a value the production schema might allow is
 * cheaper than finding out. The reachable case is the one driven below.
 *
 * DRIVEN THROUGH THE AJAX LEG, which is the only leg that builds rows -- see
 * `OrderListCellEscapingTest` for the trap that makes the page leg look like it works.
 */
class OrderStatusLabelTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function listJson(\App\Models\User $merchant): string
    {
        $response = $this->actingAs($merchant)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->get('/order?draw=1&start=0&length=10');

        $this->assertNotSame(404, $response->getStatusCode(), 'the order list must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the order list must not 500');
        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringStartsWith('{', ltrim($body),
            'premise: the ajax leg answered with JSON; the page shell carries no rows');

        return $body;
    }

    public function test_an_unrecognised_status_is_named_rather_than_left_blank(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        // 99 is outside the nine values the column holds today.
        $this->makeOrder($store, ['product_order_id' => 'ORD-99', 'delivered_status' => 99]);

        $body = $this->listJson($merchant);

        $this->assertStringContainsString('ORD-99', $body, 'premise: the order is in the list');

        $this->assertStringContainsString('Status 99', $body,
            'an order with an unrecognised status rendered no status at all, which is how the '
            . 'previous markup behaved and the reason this column was rewritten');
    }

    public function test_each_of_the_nine_known_statuses_is_named(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $expected = [
            0 => 'Pending', 1 => 'Delivered', 2 => 'Cancel', 3 => 'Return', 4 => 'Confirmed',
            5 => 'Picked Up', 6 => 'Shipped', 7 => 'Partially Paid', 8 => 'Pre Order',
        ];

        foreach (array_keys($expected) as $status) {
            $this->makeOrder($store, [
                'product_order_id' => 'ORD-S' . $status,
                'delivered_status' => $status,
            ]);
        }

        $body = $this->listJson($merchant);

        foreach ($expected as $status => $label) {
            $this->assertStringContainsString('ORD-S' . $status, $body,
                "premise: the order for status {$status} is in the list");
            $this->assertStringContainsString($label, $body,
                "status {$status} rendered without its label");
        }

        /*
         * AND NO ROW CLAIMS A STATUS IT DOES NOT HAVE. Without this the case above is satisfied
         * by a column that prints all nine labels on every row, which is a different bug wearing
         * the same assertions.
         */
        $this->assertSame(1, substr_count($body, 'Pre Order'),
            'the label appears more than once, so it is not per-row');
    }

    /**
     * THE NULL GUARD IS DEFENSIVE AND CANNOT BE DRIVEN HERE -- so this pins the reason instead
     * of pretending to drive it.
     *
     * `(int) null` is `0`, which is `Pending`, so a handler that casts before looking up would
     * report a fulfilment state for an order that has none. The guard short-circuits NULL and
     * `''` to a neutral `Status not set`. But `delivered_status` is declared
     * `integer(...)->default(0)` -- NOT NULL -- so SQLite refuses the write and the branch is
     * unreachable through any route this suite can take. A conditionally-skipped test would be
     * worse than none: it would join the declared skip set the known-outcome gate asserts, for a
     * condition that is not a blocker.
     *
     * SO THE ASSERTION IS THE PREMISE ITSELF. If `delivered_status` ever becomes nullable --
     * and CLAUDE.md's GATE F records that the migrations are NOT the schema, with 15 live tables
     * declared by no migration and a production dump that has already refuted this file once --
     * this test fails and says to go and drive the branch for real.
     */
    public function test_the_null_branch_is_unreachable_because_the_column_forbids_null(): void
    {
        $columns = \DB::select("PRAGMA table_info('orders')");
        $status = null;

        foreach ($columns as $column) {
            if ($column->name === 'delivered_status') {
                $status = $column;
                break;
            }
        }

        $this->assertNotNull($status, 'premise: orders.delivered_status exists');

        $this->assertSame(1, (int) $status->notnull,
            'orders.delivered_status is now NULLABLE, so the NULL branch in '
            . 'OrderDataTable::dataTable() is reachable and needs a test that drives it: insert '
            . 'a NULL, request the list through the ajax leg, and assert the cell reads '
            . '"Status not set" rather than "Pending".');
    }
}
