<?php

namespace App\Support\Metrics;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * The period a metric covers, and the column it is dated by.
 *
 * The column is NOT a detail. The same order is dated four different ways in this schema --
 * `orders.order_date` (when it was placed), `orders.cancel_date` (a DATE, when it was cancelled),
 * `store_order_financials.paid_at` (when money arrived) and `store_wallet_transactions.created_at`
 * (when the movement was written) -- and they routinely fall in different periods. An order placed
 * on the 31st and paid on the 1st belongs to one month's GMV and the next month's receipts. Each
 * metric names its own date column for that reason; see `MetricResult::datedBy()`, which carries
 * the name into the result so a report can print it.
 *
 * Both bounds are inclusive and both are optional. `cancel_date` is a DATE and the rest are
 * DATETIME, so the bounds are widened to the whole day -- `startOfDay()` and `endOfDay()` -- which
 * is right for both. Comparing a datetime bound against a DATE column would otherwise silently
 * drop the last day of every range.
 */
final class MetricWindow
{
    private function __construct(
        private readonly ?CarbonInterface $from,
        private readonly ?CarbonInterface $to,
    ) {
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            throw new InvalidArgumentException(
                'A metric window ends before it starts: ' . $from->toDateString()
                . ' .. ' . $to->toDateString() . '.'
            );
        }
    }

    public static function allTime(): self
    {
        return new self(null, null);
    }

    public static function between(?CarbonInterface $from, ?CarbonInterface $to): self
    {
        return new self($from, $to);
    }

    public static function since(CarbonInterface $from): self
    {
        return new self($from, null);
    }

    public function isAllTime(): bool
    {
        return $this->from === null && $this->to === null;
    }

    public function from(): ?CarbonInterface
    {
        return $this->from;
    }

    public function to(): ?CarbonInterface
    {
        return $this->to;
    }

    /**
     * Constrain a query to this window on the named column.
     *
     * A null column means the metric is not datable at all -- `store_wallets.available_balance` is
     * a running balance, not a period figure -- and the window is then ignored rather than quietly
     * applied to `updated_at`, which would report "the balance of stores touched this month".
     *
     * An Expression is accepted because section 4 has to be dated by
     * `COALESCE(orders.cancel_date, store_order_financials.updated_at)`: one cancellation door
     * writes no `cancel_date` at all, so a plain column would drop those rows from every bounded
     * window. Passing that as a string would have Laravel quote it as a column name.
     */
    public function applyTo(Builder $query, string|Expression|null $column): Builder
    {
        if ($column === null || $this->isAllTime()) {
            return $query;
        }

        if ($this->from !== null) {
            $query->where($column, '>=', $this->from->copy()->startOfDay());
        }

        if ($this->to !== null) {
            $query->where($column, '<=', $this->to->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * The equally long window immediately BEFORE this one, or null when there is not one.
     *
     * ANA-002 asks for comparisons, and a comparison is two windows. This is the second one, and
     * the only thing it must never do is invent a period: a figure compared against the wrong
     * baseline is worse than no comparison, because it reads as a trend.
     *
     * NULL FOR AN UNBOUNDED WINDOW, and that is the whole point rather than a limitation. A window
     * with no `from`, or no `to`, or neither, HAS NO LENGTH -- `since($from)` runs to whenever the
     * query happens to execute -- so there is no "equally long" period before it to compute. The
     * honest answer is that no comparison exists, and the caller must then say so on the screen
     * instead of printing a delta against something it made up.
     *
     * THE ARITHMETIC IS IN WHOLE DAYS, because that is what `applyTo()` actually queries: it
     * widens `from` with `startOfDay()` and `to` with `endOfDay()`, since `orders.cancel_date` is a
     * DATE while the rest are DATETIME. So a window is an inclusive span of whole days, its length
     * is `diffInDays + 1`, and the previous window ENDS THE DAY BEFORE this one begins -- no
     * overlap, no gap. For 1 .. 30 November (30 days) that is 2 .. 31 October (30 days), not
     * 1 .. 30 October, which would be 31 days and would double-count nothing but silently compare
     * unequal periods.
     *
     * A month-length window therefore does NOT map onto the previous CALENDAR month, and it is not
     * meant to: a 30-day window compared against a 31-day one is the comparison bug this returns a
     * fixed-length period to avoid. A caller that wants calendar months should build both windows
     * with `between()` itself.
     */
    public function previous(): ?self
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }

        $firstDay = $this->from->copy()->startOfDay();
        $lastDay = $this->to->copy()->startOfDay();

        $days = $firstDay->diffInDays($lastDay) + 1;

        return new self(
            $firstDay->copy()->subDays($days),
            $firstDay->copy()->subDay(),
        );
    }

    public function describe(): string
    {
        if ($this->isAllTime()) {
            return 'all time';
        }

        return ($this->from?->toDateString() ?? 'the beginning')
            . ' .. ' . ($this->to?->toDateString() ?? 'now');
    }
}
