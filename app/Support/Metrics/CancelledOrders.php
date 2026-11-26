<?php

namespace App\Support\Metrics;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SECTION 4 -- Orders cancelled in the period, with the value involved.
 *
 * THE DATE IS THE DIFFICULT PART. `DashboardController::orderCancel` writes NO `cancel_date` --
 * measured; every other cancellation door stamps it. A report dated purely by `cancel_date` would
 * therefore silently under-count cancellations from that one door, and silently is the operative
 * word: the rows exist, they are cancelled, and they simply fall outside every bounded window.
 *
 * So the date is `COALESCE(orders.cancel_date, store_order_financials.updated_at)`, and the result
 * SAYS SO: `notes()['dated_by_fallback']` carries how many rows needed the fallback, and
 * `MetricCaveat::CANCEL_DATE_INCOMPLETE` is attached only when that count is non-zero. A caveat
 * attached unconditionally is one a reader learns to ignore.
 *
 * The fallback is `updated_at` on the LEDGER row rather than on the order, because the financial
 * row's move to `cancelled` is what this metric counts, and that is the write that stamped it.
 */
final class CancelledOrders extends Metric implements SingleFigureMetric
{
    /**
     * The date expressions this metric may concatenate into raw SQL -- a closed set, in the shape
     * `MerchantAnalyticsFigures::groupedVisits()` established.
     *
     * The single entry is the `COALESCE` the class docblock above explains. It is a const rather
     * than only a local in `for()` so that `fallbackDatedCount()`, which takes the expression as
     * a `string` parameter, has something to check it against.
     */
    private const DATE_EXPRESSIONS = [
        'COALESCE(orders.cancel_date, store_order_financials.updated_at)',
    ];

    public function key(): string
    {
        return 'cancelled_orders';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §4';
    }

    public function for(MetricScope $scope, MetricWindow $window): MetricResult
    {
        $dateExpression = 'COALESCE(orders.cancel_date, store_order_financials.updated_at)';

        $query = $this->cancelled($scope);
        $window->applyTo($query, DB::raw($dateExpression));

        $aggregate = $this->sumByCurrency(
            $query,
            'store_order_financials.currency_code',
            'store_order_financials.customer_total'
        );

        $fallbackCount = $this->fallbackDatedCount($scope, $window, $dateExpression);

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_ENTITLEMENT,
            $dateExpression,
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            $fallbackCount > 0 ? [MetricCaveat::CANCEL_DATE_INCOMPLETE] : [],
            ['dated_by_fallback' => $fallbackCount],
        );
    }

    private function cancelled(MetricScope $scope): \Illuminate\Database\Query\Builder
    {
        return $this->financials($scope)
            ->join('orders', function ($join) {
                $join->on('orders.id', '=', 'store_order_financials.order_id')
                    ->on('orders.store_id', '=', 'store_order_financials.store_id');
            })
            ->where('store_order_financials.payment_status', 'cancelled');
    }

    private function fallbackDatedCount(
        MetricScope $scope,
        MetricWindow $window,
        string $dateExpression,
    ): int {
        /*
         * The date expression is concatenated into raw SQL by `DB::raw()` below, so it comes from
         * a closed set rather than from the caller. `for()` passes the one entry; this guard is
         * for the second caller, and it fires before the expression reaches `DB::raw()`.
         */
        if (! in_array($dateExpression, self::DATE_EXPRESSIONS, true)) {
            throw new InvalidArgumentException(
                'Cancellations cannot be dated by "' . $dateExpression . '". That text would be '
                . 'concatenated into raw SQL, so the expression is chosen from a closed set: '
                . implode(', ', self::DATE_EXPRESSIONS) . '.'
            );
        }

        $query = $this->cancelled($scope)->whereNull('orders.cancel_date');
        $window->applyTo($query, DB::raw($dateExpression));

        return $query->count();
    }
}
