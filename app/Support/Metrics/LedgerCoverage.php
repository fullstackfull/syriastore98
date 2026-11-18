<?php

namespace App\Support\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FROM WHEN IS A STORE'S FINANCIAL LEDGER COMPLETE? Owner decision D1 (Option A), P5-16.
 * =================================================================================================
 *
 * `store_order_financials` has exactly one migration and no backfill, and it is written only by
 * `OrderObserver::created()`. Every order placed before that table existed has no row, so a figure
 * that reads the ledger reports ZERO for the months before it -- correctly, because the ledger
 * genuinely has no rows, and uselessly, because a merchant reads it as "my business stopped".
 *
 * D1 settles it: **show the ledger figures only for the period the ledger can vouch for, state where
 * that period starts, and never backfill.** This class finds the start, and it is the only place that
 * does.
 *
 * THE RULE. The verified period starts on the day AFTER the latest order the ledger cannot see.
 *
 *   * no orders at all                      -> complete; nothing exists for the ledger to miss
 *   * every order has a row                 -> complete; all-time is verified
 *   * some orders lack a row, some do not   -> verified from the day after the latest one that lacks
 *   * orders exist and NONE has a row       -> nothing is verified; there is no period to state and
 *                                              therefore no window to read
 *
 * WHY IT IS COUNTED AND NOT A CONSTANT. `MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE` makes the same
 * point: a hard-coded cut-over date goes stale and says nothing about one order lost to a
 * wallet-missing race in the middle of the history. Counting orders without a row is measurable,
 * self-adjusting, and gives the right answer for both. The cost is that a single lost order late in
 * the history pushes the start past it, which is the honest consequence: the periods around it can no
 * longer be vouched for as a whole.
 *
 * THE START IS THE NEXT DAY, NOT THE NEXT SECOND. An order is dated, a window is dated, and a day that
 * contains an unseen order is a day whose total the ledger cannot give. Starting at the following
 * midnight never includes a partly-covered day.
 *
 * AN ORDER WITH NO `order_date` AND NO ROW cannot be placed in any window (`orders.order_date` is
 * nullable). It cannot move a start date, so it is COUNTED separately and disclosed rather than
 * silently ignored or allowed to withhold every figure for the store. The consumer is expected to say
 * so; the count is `undatedOrdersWithoutRow()`.
 *
 * TENANCY. The scope is an explicit `store_id`, on the orders AND on the join to the ledger. A ledger
 * row filed under another store for the same order id is not cover, by test. There is no global scope
 * in play: this uses the query builder on purpose, like every metric in this namespace.
 */
final class LedgerCoverage
{
    private function __construct(
        private readonly int $storeId,
        private readonly int $orders,
        private readonly int $withoutRow,
        private readonly int $undatedWithoutRow,
        private readonly ?CarbonImmutable $verifiedFrom,
    ) {}

    public static function forStore(int $storeId): self
    {
        $orders = DB::table('orders')->where('orders.store_id', $storeId);
        $total = (clone $orders)->count();

        if ($total === 0) {
            return new self($storeId, 0, 0, 0, null);
        }

        $uncovered = (clone $orders)
            ->leftJoin('store_order_financials', function ($join) {
                $join->on('store_order_financials.order_id', '=', 'orders.id')
                    ->on('store_order_financials.store_id', '=', 'orders.store_id');
            })
            ->whereNull('store_order_financials.id');

        $withoutRow = (clone $uncovered)->count();

        if ($withoutRow === 0) {
            return new self($storeId, $total, 0, 0, null);
        }

        $undated = (clone $uncovered)->whereNull('orders.order_date')->count();
        $latest = (clone $uncovered)->max('orders.order_date');

        $from = $latest === null
            ? null
            : CarbonImmutable::parse((string) $latest)->addDay()->startOfDay();

        return new self($storeId, $total, $withoutRow, $undated, $from);
    }

    /** Every order has a row, or there are no orders: all-time is verified. */
    public function isComplete(): bool
    {
        return $this->withoutRow === 0;
    }

    /** Orders exist and the ledger holds none of them: there is no verified period to state. */
    public function hasNothingVerified(): bool
    {
        return $this->orders > 0 && $this->withoutRow === $this->orders;
    }

    /** The first moment of the verified period; null when complete (no restriction) or when nothing is verified. */
    public function verifiedFrom(): ?CarbonImmutable
    {
        return $this->verifiedFrom;
    }

    public function ordersWithoutRow(): int
    {
        return $this->withoutRow;
    }

    public function undatedOrdersWithoutRow(): int
    {
        return $this->undatedWithoutRow;
    }

    /**
     * The window a ledger figure may be read over, or null when there is none.
     *
     * Open-ended on purpose: the verified period runs from its start to now.
     */
    public function window(): ?MetricWindow
    {
        if ($this->hasNothingVerified()) {
            return null;
        }

        if ($this->isComplete() || $this->verifiedFrom === null) {
            return MetricWindow::allTime();
        }

        return MetricWindow::since($this->verifiedFrom);
    }
}
