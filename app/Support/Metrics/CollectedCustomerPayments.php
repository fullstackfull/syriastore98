<?php

namespace App\Support\Metrics;

/**
 * SECTION 2 -- Money the customer actually settled. The answer to "what did shoppers pay us?"
 *
 * THE TRAP THIS METRIC EXISTS TO AVOID: filtering on `payment_status = 'paid'` alone UNDER-COUNTS,
 * because P4-F124 deliberately preserves `paid_amount` and `paid_at` through a reversal. An order
 * the customer paid for and then cancelled sits at `cancelled` with its money still recorded. The
 * customer did pay; that fact does not stop being true because the order was later reversed. So
 * this metric reads `paid_amount`, and the status only decides which rows are eligible.
 *
 * `partially_paid` IS INCLUDED, and the definition is silent on it, so the choice is stated rather
 * than hidden. It is a reachable state -- `OrderFinancialService` normalises a gateway's 'partial'
 * to it -- and such a row holds real money the shopper handed over. Excluding it would commit the
 * exact under-count the section is written to prevent. `notes()['partially_paid']` says so.
 */
final class CollectedCustomerPayments extends Metric implements SingleFigureMetric
{
    public function key(): string
    {
        return 'collected_customer_payments';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §2';
    }

    public function for(MetricScope $scope, MetricWindow $window): MetricResult
    {
        $query = $this->financials($scope)
            ->where(function ($captured) {
                $captured
                    ->where('store_order_financials.payment_status', 'paid')
                    ->orWhere(function ($reversedButCaptured) {
                        // The F124 limb. Without it a paid-then-cancelled order vanishes from
                        // "what did shoppers pay us", which is the under-count §2 names.
                        $reversedButCaptured
                            ->whereIn('store_order_financials.payment_status', [
                                'cancelled', 'refunded', 'partially_paid',
                            ])
                            ->where('store_order_financials.paid_amount', '>', 0);
                    });
            });

        /*
         * Dated by `paid_at`, NOT by the order date. Money that arrived in March for an order
         * placed in February is March's collection. A `paid_at` of null cannot be dated at all, so
         * a bounded window excludes it -- which is correct: an unknown capture date is not
         * evidence of a capture inside the period.
         */
        $window->applyTo($query, 'store_order_financials.paid_at');

        $aggregate = $this->sumByCurrency(
            $query,
            'store_order_financials.currency_code',
            'store_order_financials.paid_amount'
        );

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_ENTITLEMENT,
            'store_order_financials.paid_at',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            [],
            [
                'includes_reversed_but_captured' => true,
                'partially_paid' => 'included',
            ],
        );
    }
}
