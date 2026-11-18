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
 * ANY SINGLE GAP RESETS THE START, PERMANENTLY, and that is a reading of D1 worth the owner confirming
 * (GAP-213). Unrowed orders are not only history: `OrderObserver::created()` swallows every `\Exception`
 * from `createForOrder()` -- a missing currency rate, for one -- logs a `critical`, and the row is never
 * written again (`syncFromOrder` returns at once when there is none). So a recent failure moves the start to
 * the day after it and stays there. The alternative is to repair the missing row, which is a backfill, which
 * D1 forbids; so the rule stays conservative and says so.
 *
 * A START IN THE FUTURE IS NOT A PERIOD. An unrowed order dated today puts the start at tomorrow's midnight,
 * and `paid_at >= tomorrow` matches nothing, which a figure would print as 0.00 -- "no sales" for a store
 * whose earlier history is sound. `startsInTheFuture()` says so and `window()` then returns null, the same
 * "not available" as a store with nothing verified. Found by independent review.
 *
 * THE PERIOD IS SET BY ORDER DATE AND THE FIGURES ARE DATED BY PAYMENT, and the gap between the two is real:
 * a cash-on-delivery order placed before the start and collected after it has no ledger row and never gets
 * one, so its cash is in neither figure and never reached the wallet. The rule cannot see that (it would need
 * the order's payment date, which the ledger exists to record), so the tile must say that orders without a
 * record are excluded EVEN IF PAID INSIDE THE PERIOD. See `FinanceLedgerFigures`.
 *
 * TIMEZONE. Both sides of every comparison are PHP-formatted strings in the same session, so a constant
 * difference between the app and the database zones cancels. The codebase also flips PHP's default zone to
 * Asia/Kolkata inside some product accessors (`Product.php`, `ProductVariant.php`, `FlashSale.php`), which can
 * leave an `order_date` stamped up to five and a half hours ahead of the zone this class parses it in. That
 * is not caused here and was not traced to checkout; the effect is a start that may be a day early or late.
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
        // MetricScope refuses these; accepting 0 here answered "no orders, complete" for a store that is nothing.
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('LedgerCoverage needs a positive store id, got '.$storeId.'.');
        }

        /*
         * ONE STATEMENT. Four (orders, unrowed, undated, latest) became one aggregate over the anti-join, on
         * the review's finding that the cost scales with the store's orders. `COUNT(DISTINCT ...)` rather than
         * `COUNT(*)`: the schema says one ledger row per order, and a join that ever fanned out must not
         * double-count an order. The join names the store on BOTH sides, so a row filed under another store is
         * not cover (LedgerCoverageTest pins it).
         */
        $row = DB::table('orders')
            ->leftJoin('store_order_financials', function ($join) {
                $join->on('store_order_financials.order_id', '=', 'orders.id')
                    ->on('store_order_financials.store_id', '=', 'orders.store_id');
            })
            ->where('orders.store_id', $storeId)
            ->selectRaw(
                'COUNT(DISTINCT orders.id) as total_orders, '
                .'COUNT(DISTINCT CASE WHEN store_order_financials.id IS NULL THEN orders.id END) as orders_without_row, '
                .'COUNT(DISTINCT CASE WHEN store_order_financials.id IS NULL AND orders.order_date IS NULL THEN orders.id END) as undated_without_row, '
                .'MAX(CASE WHEN store_order_financials.id IS NULL THEN orders.order_date END) as latest_unrowed'
            )
            ->first();

        $total = (int) ($row->total_orders ?? 0);
        $withoutRow = (int) ($row->orders_without_row ?? 0);
        $undated = (int) ($row->undated_without_row ?? 0);
        $latest = $row->latest_unrowed ?? null;

        if ($withoutRow === 0) {
            return new self($storeId, $total, 0, 0, null);
        }

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

    /**
     * The first moment of the verified period. Null when complete (no restriction), when nothing is verified,
     * and ALSO when every order without a row is undated: with no dated gap there is nothing to start after,
     * `window()` is all-time, and `undatedOrdersWithoutRow()` carries the disclosure.
     */
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

    /** The verified period has not begun: the latest order the ledger cannot see is dated today (or later). */
    public function startsInTheFuture(): bool
    {
        return $this->verifiedFrom !== null && $this->verifiedFrom->greaterThan(CarbonImmutable::now());
    }

    /**
     * The window a ledger figure may be read over, or null when there is none.
     *
     * Open-ended on purpose: the verified period runs from its start to now.
     */
    public function window(): ?MetricWindow
    {
        if ($this->hasNothingVerified() || $this->startsInTheFuture()) {
            return null;
        }

        if ($this->isComplete() || $this->verifiedFrom === null) {
            return MetricWindow::allTime();
        }

        return MetricWindow::since($this->verifiedFrom);
    }
}
