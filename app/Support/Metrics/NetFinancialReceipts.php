<?php

namespace App\Support\Metrics;

/**
 * SECTION 6 -- Net financial receipts. The closest thing to "money we kept" at the order level.
 *
 * TWO COMPUTATIONS, AND THEY MUST AGREE.
 *
 *   status-based   = section 2 - section 5 - (cancelled rows with paid_amount > 0)
 *   movement-based = SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END)
 *                    over store_wallet_transactions WHERE reference_type = 'order'
 *
 * The movement sum is the PREFERRED computation and the one `for()` returns, because it needs no
 * inclusion rules at all: every movement is typed, reasoned, and written exactly once by a locked
 * idempotent path. The status-based computation has to re-derive what the transactions already
 * record, which is why it is kept -- as a check on the other, not as an alternative to it.
 *
 * Section 6 is unambiguous about what a disagreement means: "Disagreement is a reconciliation
 * defect and must fail the build, not be averaged." So nothing here averages, nothing picks the
 * larger, and nothing swallows it. `reconcile()` reports both figures and whether they agree; the
 * test fails the build on the named dataset; and a screen that renders anyway gets
 * `MetricCaveat::RECONCILIATION_DIVERGENCE` attached to the figure it prints, so the merchant is
 * told their records disagree rather than shown one of them.
 *
 * ONE KNOWN WAY THEY CAN LEGITIMATELY DIVERGE, stated so a future reader does not treat it as a
 * new bug: over a BOUNDED window the two legs are dated differently by construction -- the
 * movement sum by `store_wallet_transactions.created_at`, the collected leg by
 * `store_order_financials.paid_at`. An order captured on the 31st whose wallet credit was written
 * on the 1st falls in different months for the two. All-time, which is how the verification test
 * drives it, has no such ambiguity.
 *
 * AND THE STATUS-BASED COMPUTATION IS ITSELF DATE-HYBRID, which review pointed out this docblock
 * did not say. Two of its three terms -- collected payments and cancelled-but-captured payments --
 * are dated by `store_order_financials.paid_at`, but the third, completed refunds, is section 5
 * and is therefore dated by `store_wallet_transactions.created_at`. So even "the status side" is
 * not dated by one clock. Over a bounded window that is a third legitimate source of divergence,
 * and it is the reason `reconcile()` reports the per-currency DIFFERENCE rather than a bare
 * boolean: a disagreement should be investigable, not merely noticed.
 */
final class NetFinancialReceipts extends Metric implements SingleFigureMetric
{
    /** Agreement tolerance, in the smallest unit of a DECIMAL(15,2) column. */
    public const EPSILON = 0.005;

    public function key(): string
    {
        return 'net_financial_receipts';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §6';
    }

    public function for(MetricScope $scope, MetricWindow $window): MetricResult
    {
        $movement = $this->movementBased($scope, $window);
        $status = $this->statusBased($scope, $window);

        $divergence = $this->divergence($movement, $status);

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_MOVEMENT,
            'store_wallet_transactions.created_at',
            $movement['byCurrency'],
            $movement['rowCount'],
            $scope,
            $window,
            $divergence === [] ? [] : [MetricCaveat::RECONCILIATION_DIVERGENCE],
            [
                'computation' => 'movement',
                'cross_check' => 'status',
                'status_based_by_currency' => $status['byCurrency'],
                'divergence_by_currency' => $divergence,
            ],
        );
    }

    /**
     * Both computations side by side, with the verdict.
     *
     * This is what the verification test drives and what a reconciliation report should print. It
     * returns the DIFFERENCE per currency rather than a single boolean so that a disagreement can
     * be investigated instead of merely noticed.
     *
     * @return array{movement: array<string,float>, status: array<string,float>,
     *               divergence: array<string,float>, agrees: bool}
     */
    public function reconcile(MetricScope $scope, MetricWindow $window): array
    {
        $movement = $this->movementBased($scope, $window);
        $status = $this->statusBased($scope, $window);
        $divergence = $this->divergence($movement, $status);

        return [
            'movement' => $movement['byCurrency'],
            'status' => $status['byCurrency'],
            'divergence' => $divergence,
            'agrees' => $divergence === [],
        ];
    }

    /**
     * The self-reconciling computation: the signed sum of every order-referenced movement.
     *
     * `status <> 'cancelled'` excludes a movement row that was written and then voided. Including
     * it would count money that was recorded and then un-recorded.
     *
     * @return array{byCurrency: array<string,float>, rowCount: int}
     */
    private function movementBased(MetricScope $scope, MetricWindow $window): array
    {
        $query = $this->transactions($scope)
            ->where('store_wallet_transactions.reference_type', 'order')
            ->where('store_wallet_transactions.status', '<>', 'cancelled');

        $window->applyTo($query, 'store_wallet_transactions.created_at');

        return $this->sumByCurrency(
            $query,
            'store_wallet_transactions.currency_code',
            "CASE WHEN store_wallet_transactions.direction = 'credit' "
            . 'THEN store_wallet_transactions.amount '
            . 'ELSE -store_wallet_transactions.amount END'
        );
    }

    /**
     * The definition's own arithmetic, composed from the metrics that implement its three terms.
     *
     * Composed rather than re-queried on purpose: if section 2's inclusion rules change, this
     * check changes with them. A hand-written copy of those predicates here would drift and then
     * report the drift as a reconciliation defect in the data.
     *
     * @return array{byCurrency: array<string,float>, rowCount: int}
     */
    private function statusBased(MetricScope $scope, MetricWindow $window): array
    {
        $collected = (new CollectedCustomerPayments())->for($scope, $window);
        $refunded = (new CompletedRefunds())->for($scope, $window);
        $cancelledCaptured = $this->cancelledCapturedPayments($scope, $window);

        $byCurrency = [];

        foreach ($collected->byCurrency() as $currency => $amount) {
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0) + $amount;
        }

        foreach ($refunded->byCurrency() as $currency => $amount) {
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0) - $amount;
        }

        foreach ($cancelledCaptured['byCurrency'] as $currency => $amount) {
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0) - $amount;
        }

        foreach ($byCurrency as $currency => $amount) {
            $byCurrency[$currency] = round($amount, 2);
        }

        return [
            'byCurrency' => $byCurrency,
            'rowCount' => $collected->rowCount()
                + $refunded->rowCount()
                + $cancelledCaptured['rowCount'],
        ];
    }

    /**
     * The third term: money the customer really paid on an order that was then cancelled.
     *
     * Dated by `paid_at` to match the collected leg it reverses -- dating it by the cancellation
     * instead would make the two terms fall in different windows and manufacture a divergence.
     *
     * @return array{byCurrency: array<string,float>, rowCount: int}
     */
    private function cancelledCapturedPayments(MetricScope $scope, MetricWindow $window): array
    {
        $query = $this->financials($scope)
            ->where('store_order_financials.payment_status', 'cancelled')
            ->where('store_order_financials.paid_amount', '>', 0);

        $window->applyTo($query, 'store_order_financials.paid_at');

        return $this->sumByCurrency(
            $query,
            'store_order_financials.currency_code',
            'store_order_financials.paid_amount'
        );
    }

    /**
     * Per-currency difference between the two computations, empty when they agree.
     *
     * Every currency present in EITHER is checked. A currency the movement record knows nothing
     * about is the most serious disagreement available and must not fall out of the comparison for
     * want of a key.
     *
     * @return array<string, float>
     */
    private function divergence(array $movement, array $status): array
    {
        $currencies = array_unique(array_merge(
            array_keys($movement['byCurrency']),
            array_keys($status['byCurrency'])
        ));

        $divergence = [];

        foreach ($currencies as $currency) {
            $difference = round(
                ($movement['byCurrency'][$currency] ?? 0.0)
                - ($status['byCurrency'][$currency] ?? 0.0),
                2
            );

            if (abs($difference) >= self::EPSILON) {
                $divergence[$currency] = $difference;
            }
        }

        return $divergence;
    }
}
