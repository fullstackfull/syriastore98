<?php

namespace App\Support\Metrics;

use App\Services\PaymentVerificationService;
use InvalidArgumentException;

/**
 * SECTION 3 -- Orders awaiting money, split by WHY.
 *
 * "Three sub-metrics, never one. Collapsing them is how a COD book looks like bad debt." A COD
 * order waiting on a courier, a bank transfer waiting on a human, and an order nobody intends to
 * pay for are three different business facts with three different responses. One number over the
 * three tells a merchant their receivables are at risk when most of it is cash in a bag.
 *
 * It implements `MultiFigureMetric` and NOT `SingleFigureMetric`, so there is no `for()` to call
 * and the collapse cannot happen by habit.
 *
 * CANCELLED ORDERS ARE EXCLUDED, and the exclusion needs no predicate of its own: a cancelled
 * order's financial row is moved to `cancelled` by P4-F124 rather than left at `unpaid`, which is
 * exactly why that change was made. The test drives a cancelled COD order and asserts it appears
 * in none of the three.
 *
 * THE THREE PREDICATES DO NOT PARTITION THE ROWS. Two kinds of outstanding money match none of
 * them, and BOTH are disclosed by count rather than hidden. Inventing a fourth sub-metric would be
 * inventing a requirement; staying silent would lose the money.
 *
 *   `partially_paid_outside_all_three` -- a row at `partially_paid` still owes its remainder, and
 *   the definition names no predicate that reaches it.
 *
 *   `pending_with_unclassified_payment_type` -- FOUND BY REVIEW, and the larger of the two in
 *   practice. A row sits at `pending` whenever `PaymentVerificationService::
 *   resolveOrderPaymentStatus()` cannot verify the capture, which is every gateway order with no
 *   session verification record. Its `orders.payment_type` is whatever the client sent -- validated
 *   `required` and nothing more -- so for anything that is not a COD spelling or exactly
 *   `bank_transfer` the row holds the full customer total outstanding and appears in NO bucket.
 *   A NULL `payment_type` counts here too: in SQL `NULL NOT IN (...)` is NULL, not true, so the
 *   null limb is written out rather than left to the inequality.
 *
 * Worth knowing before trusting the second bucket: `bank_transfer` is in
 * `PaymentVerificationService::OFFLINE_PAYMENT_TYPES`, so the storefront records such an order
 * **Paid at creation**. `AWAITING_MANUAL_SETTLEMENT` is therefore populated by a merchant moving a
 * row back to `pending` through the finance screen, not by checkout. That is a defect in
 * `OFFLINE_PAYMENT_TYPES` and outside this slice; it is named here because it decides whether the
 * bucket measures what its name says.
 */
final class UnpaidOrders extends Metric implements MultiFigureMetric
{
    public const AWAITING_COD_COLLECTION = 'awaiting_cod_collection';

    public const AWAITING_MANUAL_SETTLEMENT = 'awaiting_manual_settlement';

    public const GENUINELY_UNPAID = 'genuinely_unpaid';

    public const SUB_METRICS = [
        self::AWAITING_COD_COLLECTION,
        self::AWAITING_MANUAL_SETTLEMENT,
        self::GENUINELY_UNPAID,
    ];

    public function key(): string
    {
        return 'unpaid_orders';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §3';
    }

    /** @return array<string, MetricResult> */
    public function all(MetricScope $scope, MetricWindow $window): array
    {
        $results = [];

        foreach (self::SUB_METRICS as $subMetric) {
            $results[$subMetric] = $this->subMetric($subMetric, $scope, $window);
        }

        return $results;
    }

    public function subMetric(string $subMetric, MetricScope $scope, MetricWindow $window): MetricResult
    {
        if (! in_array($subMetric, self::SUB_METRICS, true)) {
            throw new InvalidArgumentException(
                'Unknown unpaid sub-metric "' . $subMetric . '". Section 3 defines exactly: '
                . implode(', ', self::SUB_METRICS) . '.'
            );
        }

        $query = $this->awaitingMoney($scope);
        $paymentType = $this->normalisedPaymentTypeSql('orders.payment_type');

        switch ($subMetric) {
            case self::AWAITING_COD_COLLECTION:
                $query->where('store_order_financials.payment_status', 'pending')
                    ->whereIn(
                        $this->rawColumn($paymentType),
                        PaymentVerificationService::COD_PAYMENT_TYPES
                    );
                break;

            case self::AWAITING_MANUAL_SETTLEMENT:
                $query->where('store_order_financials.payment_status', 'pending')
                    ->where($this->rawColumn($paymentType), 'bank_transfer');
                break;

            case self::GENUINELY_UNPAID:
                $query->where('store_order_financials.payment_status', 'unpaid');
                break;
        }

        $window->applyTo($query, 'orders.order_date');

        $aggregate = $this->sumByCurrency(
            $query,
            'store_order_financials.currency_code',
            'store_order_financials.remaining_amount'
        );

        return MetricResult::make(
            $this->key() . '.' . $subMetric,
            MetricResult::RECORD_ENTITLEMENT,
            'orders.order_date',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            [],
            [
                'sub_metric' => $subMetric,
                'cancelled_excluded' => true,
                'partially_paid_outside_all_three' => $this->partiallyPaidCount($scope, $window),
                'pending_with_unclassified_payment_type' =>
                    $this->unclassifiedPendingCount($scope, $window),
            ],
        );
    }

    /**
     * The shared base: financial rows joined to their order, so `payment_type` is reachable.
     *
     * The tenant predicate is already on `store_order_financials` from `financials()`; the join
     * adds `orders`, which carries its own `store_id`, and the two are asserted equal rather than
     * assumed -- a financial row pointing at another tenant's order would otherwise pull that
     * order's `payment_type` into this merchant's figures.
     */
    private function awaitingMoney(MetricScope $scope): \Illuminate\Database\Query\Builder
    {
        return $this->financials($scope)
            ->join('orders', function ($join) {
                $join->on('orders.id', '=', 'store_order_financials.order_id')
                    ->on('orders.store_id', '=', 'store_order_financials.store_id');
            });
    }

    private function partiallyPaidCount(MetricScope $scope, MetricWindow $window): int
    {
        $query = $this->awaitingMoney($scope)
            ->where('store_order_financials.payment_status', 'partially_paid');

        $window->applyTo($query, 'orders.order_date');

        return $query->count();
    }

    /**
     * Rows at `pending` whose payment type matches neither the COD set nor `bank_transfer`.
     *
     * These are in none of the three sub-metrics and are real outstanding money. Counted, not
     * bucketed -- see the class docblock.
     */
    private function unclassifiedPendingCount(MetricScope $scope, MetricWindow $window): int
    {
        $paymentType = $this->normalisedPaymentTypeSql('orders.payment_type');

        $query = $this->awaitingMoney($scope)
            ->where('store_order_financials.payment_status', 'pending')
            ->where(function ($unclassified) use ($paymentType) {
                $unclassified
                    // `NULL NOT IN (...)` is NULL, which is not true, so a null payment type would
                    // fall out of the count that exists to catch exactly this kind of row.
                    ->whereNull('orders.payment_type')
                    ->orWhere(function ($named) use ($paymentType) {
                        $named
                            ->whereNotIn(
                                $this->rawColumn($paymentType),
                                PaymentVerificationService::COD_PAYMENT_TYPES
                            )
                            ->where($this->rawColumn($paymentType), '<>', 'bank_transfer');
                    });
            });

        $window->applyTo($query, 'orders.order_date');

        return $query->count();
    }

    /**
     * `DB::raw()` over a `string` parameter -- so the parameter comes from a closed set.
     *
     * The one entry is `normalisedPaymentTypeSql('orders.payment_type')`'s output, which is the
     * only thing either caller passes. Writing it out rather than calling that method here keeps
     * the check a comparison against a constant: a guard that computed its own allowed value from
     * the same code path it is guarding would admit whatever that path produced.
     *
     * The refusal precedes `DB::raw()`, not the query that would execute it.
     */
    private function rawColumn(string $expression): \Illuminate\Contracts\Database\Query\Expression
    {
        if (! in_array($expression, self::RAW_COLUMN_EXPRESSIONS, true)) {
            throw new InvalidArgumentException(
                'The unpaid-orders metric cannot read "' . $expression . '" as a column. That '
                . 'text would be concatenated into raw SQL, so the expression is chosen from a '
                . 'closed set: ' . implode(', ', self::RAW_COLUMN_EXPRESSIONS) . '.'
            );
        }

        return \Illuminate\Support\Facades\DB::raw($expression);
    }

    /**
     * `rawColumn()`'s closed set, kept next to its only consumer.
     *
     * Position is style here and nothing more -- no document or test cites a line number in this
     * file (measured). `Metric`'s equivalents are at the foot of THAT class because two documents
     * cite lines in it, which is a different reason.
     */
    private const RAW_COLUMN_EXPRESSIONS = [
        'LOWER(TRIM(orders.payment_type))',
    ];
}
