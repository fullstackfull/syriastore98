<?php

namespace App\Support\Metrics;

/**
 * SECTION 5 -- Money actually returned to customers. **A refund request is not a refund.**
 *
 * EXPLICITLY NOT `order_refunds.refund_status`, and section 5 calls this "the sharpest exclusion
 * in the document". That column holds `Processing` (a shopper asked), `Accept` (a merchant agreed,
 * **no money moved**) and `Refunded` (money moved). Only the third is money. For every online
 * gateway the resolved status is `Accept` and the `Accept -> Refunded` edge is UNREACHABLE BY
 * CONSTRUCTION, because `hasGatewayRefundDriver()` is a hardcoded `return false` and no refund
 * request has ever left this server. A metric built on `refund_status` would therefore report
 * every agreed-but-unpaid refund as money returned -- the single largest overstatement available
 * in this schema.
 *
 * MEASURED ON THE MOVEMENT RECORD. `store_wallet_transactions` of type `order_refunded` is the
 * money that actually left the merchant's entitlement, written exactly once by a locked idempotent
 * path -- which is also what makes "a replayed refund contributes once" true by construction
 * rather than by a DISTINCT in this query.
 *
 * PARTIAL REFUNDS ARE EXCLUDED ENTIRELY and the caveat is unconditional. Per
 * `docs/finance/P4-F127-PARTIAL-REFUND-CAPABILITY.md` the ledger cannot represent them safely, so
 * no metric may imply it has counted them. Unlike section 4's date caveat this one does not depend
 * on the data: the capability is absent, so the omission is certain.
 */
final class CompletedRefunds extends Metric implements SingleFigureMetric
{
    /** The movement type written when a refund actually settles. */
    public const TRANSACTION_TYPE = 'order_refunded';

    public function key(): string
    {
        return 'completed_refunds';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §5';
    }

    public function for(MetricScope $scope, MetricWindow $window): MetricResult
    {
        $query = $this->transactions($scope)
            ->where('store_wallet_transactions.type', self::TRANSACTION_TYPE)
            ->where('store_wallet_transactions.reference_type', 'order')
            // A cancelled movement is a row that was written and then voided; it returned nothing.
            ->where('store_wallet_transactions.status', '<>', 'cancelled');

        $window->applyTo($query, 'store_wallet_transactions.created_at');

        $aggregate = $this->sumByCurrency(
            $query,
            'store_wallet_transactions.currency_code',
            'store_wallet_transactions.amount'
        );

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_MOVEMENT,
            'store_wallet_transactions.created_at',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            [MetricCaveat::PARTIAL_REFUNDS_EXCLUDED],
            ['source' => 'store_wallet_transactions.type=' . self::TRANSACTION_TYPE,
             'order_refunds_refund_status_ignored' => true],
        );
    }
}
