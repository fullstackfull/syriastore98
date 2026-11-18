<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Support\Metrics\LedgerCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * FROM WHEN IS THE FINANCIAL LEDGER COMPLETE FOR A STORE? Owner decision D1 (Option A), P5-16.
 * =================================================================================================
 *
 * `store_order_financials` has exactly one migration and no backfill, and it is written only by
 * `OrderObserver::created()`. So every order placed before that table existed has no row, and a
 * figure that reads the ledger -- "Payments received", "Estimated gross profit" -- reports ZERO for
 * the months before it, which a merchant reads as "my business stopped".
 *
 * D1 settles what to do about it: show the ledger figures **only for the period the ledger can vouch
 * for, state where that period starts, and never backfill**. This is the rule that finds the start.
 *
 * THE RULE, and why it is not a hard-coded date. The start is the day AFTER the latest order the
 * ledger cannot see. A constant would go stale and would say nothing about a single order lost to a
 * wallet-missing race in the middle of the history; counting orders without a row cannot.
 *
 *   * no orders at all                       -> complete: there is nothing the ledger could miss
 *   * every order has a row                  -> complete: all-time is verified
 *   * some orders lack a row, some do not    -> verified from the day after the latest one that lacks
 *   * orders exist and NONE has a row       -> nothing verified: there is no period to state
 *
 * An order with no `order_date` and no ledger row cannot be placed in any window. It cannot move a
 * start date, so it is COUNTED and disclosed instead of being silently ignored or silently fatal.
 */
class LedgerCoverageTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** An order on a given day; `$ledger = false` deletes the row the observer wrote, which is what a pre-ledger order looks like. */
    private function orderOn(Store $store, ?string $date, bool $ledger = true): int
    {
        $order = $this->makeOrder($store, ['order_date' => $date]);

        if (! $ledger) {
            DB::table('store_order_financials')->where('order_id', $order->id)->delete();
        }

        return $order->id;
    }

    public function test_premise_the_observer_writes_a_ledger_row_for_a_new_order(): void
    {
        [, $store] = $this->makeTenant();
        $id = $this->orderOn($store, '2026-11-05 10:00:00');

        $this->assertSame(1, DB::table('store_order_financials')->where('order_id', $id)->count(),
            'OrderObserver::created() no longer writes a ledger row, so every fixture below is measuring nothing');
    }

    public function test_a_store_with_no_orders_is_complete(): void
    {
        [, $store] = $this->makeTenant();

        $coverage = LedgerCoverage::forStore($store->id);

        $this->assertTrue($coverage->isComplete());
        $this->assertNull($coverage->verifiedFrom());
        $this->assertSame(0, $coverage->ordersWithoutRow());
        $this->assertTrue($coverage->window()->isAllTime());
    }

    public function test_when_every_order_has_a_row_all_time_is_verified(): void
    {
        [, $store] = $this->makeTenant();
        $this->orderOn($store, '2026-11-05 10:00:00');
        $this->orderOn($store, '2026-11-09 18:30:00');

        $coverage = LedgerCoverage::forStore($store->id);

        $this->assertTrue($coverage->isComplete());
        $this->assertNull($coverage->verifiedFrom());
        $this->assertTrue($coverage->window()->isAllTime());
    }

    public function test_orders_before_the_ledger_existed_move_the_start_to_the_day_after_the_latest_of_them(): void
    {
        [, $store] = $this->makeTenant();
        $this->orderOn($store, '2026-09-10 09:00:00', ledger: false);
        $this->orderOn($store, '2026-10-30 23:59:00', ledger: false);   // the latest the ledger cannot see
        $this->orderOn($store, '2026-11-02 08:00:00');                  // covered
        $this->orderOn($store, '2026-11-08 08:00:00');                  // covered

        $coverage = LedgerCoverage::forStore($store->id);

        $this->assertFalse($coverage->isComplete());
        $this->assertSame(2, $coverage->ordersWithoutRow());
        $this->assertSame('2026-10-31 00:00:00', $coverage->verifiedFrom()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31', $coverage->window()->from()->format('Y-m-d'));
        $this->assertNull($coverage->window()->to(), 'the verified window is open-ended: it runs to now');
    }

    public function test_a_gap_in_the_middle_of_the_history_moves_the_start_past_it(): void
    {
        // The reason this is not a hard-coded cut-over date: one order lost to a race AFTER the
        // ledger began still means the periods around it cannot all be vouched for.
        [, $store] = $this->makeTenant();
        $this->orderOn($store, '2026-11-02 08:00:00');
        $this->orderOn($store, '2026-11-12 14:00:00', ledger: false);   // the gap
        $this->orderOn($store, '2026-11-15 08:00:00');

        $coverage = LedgerCoverage::forStore($store->id);

        $this->assertSame(1, $coverage->ordersWithoutRow());
        $this->assertSame('2026-11-13', $coverage->verifiedFrom()->format('Y-m-d'));
    }

    public function test_when_orders_exist_and_none_has_a_row_nothing_is_verified(): void
    {
        [, $store] = $this->makeTenant();
        $this->orderOn($store, '2026-09-10 09:00:00', ledger: false);
        $this->orderOn($store, '2026-10-01 09:00:00', ledger: false);

        $coverage = LedgerCoverage::forStore($store->id);

        $this->assertFalse($coverage->isComplete());
        $this->assertTrue($coverage->hasNothingVerified());
        $this->assertNull($coverage->window(), 'there is no period to state, so there is no window to read');
        $this->assertSame(2, $coverage->ordersWithoutRow());
    }

    public function test_another_stores_orders_never_move_this_stores_start(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->orderOn($storeA, '2026-11-02 08:00:00');
        $this->orderOn($storeB, '2026-11-20 08:00:00', ledger: false);  // a gap that belongs to B

        $a = LedgerCoverage::forStore($storeA->id);
        $b = LedgerCoverage::forStore($storeB->id);

        $this->assertTrue($a->isComplete(), 'B\'s missing row leaked into A\'s answer');
        $this->assertSame(0, $a->ordersWithoutRow());
        $this->assertTrue($b->hasNothingVerified());
    }

    public function test_an_undated_order_without_a_row_is_counted_and_cannot_move_the_start(): void
    {
        [, $store] = $this->makeTenant();
        $this->orderOn($store, null, ledger: false);                    // no date, no row: cannot be placed in any window
        $this->orderOn($store, '2026-10-20 08:00:00', ledger: false);
        $this->orderOn($store, '2026-11-02 08:00:00');

        $coverage = LedgerCoverage::forStore($store->id);

        $this->assertSame(2, $coverage->ordersWithoutRow());
        $this->assertSame(1, $coverage->undatedOrdersWithoutRow());
        $this->assertSame('2026-10-21', $coverage->verifiedFrom()->format('Y-m-d'),
            'the dated gap sets the start; the undated one is disclosed, not used');
    }

    public function test_a_ledger_row_that_belongs_to_another_store_does_not_cover_this_stores_order(): void
    {
        // The join must be on the order's OWN store. A row for the same order id under another
        // store_id (a data-integrity fault, but one a tenancy-minded rule must survive) is not cover.
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $aOrder = $this->orderOn($storeA, '2026-11-02 08:00:00', ledger: false);
        $bOrder = $this->orderOn($storeB, '2026-11-03 08:00:00');

        // Copy B's own valid row (wallet, currency and every NOT NULL column included) onto A's order id.
        $row = (array) DB::table('store_order_financials')->where('order_id', $bOrder)->first();
        unset($row['id']);
        $row['order_id'] = $aOrder;
        DB::table('store_order_financials')->insert($row);

        $this->assertSame($storeB->id, (int) DB::table('store_order_financials')->where('order_id', $aOrder)->value('store_id'),
            'premise: the foreign row is filed under store B');
        $this->assertSame(1, LedgerCoverage::forStore($storeA->id)->ordersWithoutRow(),
            'a ledger row filed under another store was counted as cover for this store\'s order');
    }
}
