<?php

namespace App\Support\Metrics;

/**
 * SECTION 8 -- What the merchant earned, net of the cost of goods.
 *
 * Source: `store_order_financials.gross_profit`, which is
 * `(product_amount - discount_amount) - product_cost`.
 *
 * TWO CAVEATS, AND THEY BEHAVE DIFFERENTLY ON PURPOSE.
 *
 * `EXCLUDES_SHIPPING_AND_TAX` is unconditional, and IT IS ONLY HALF TRUE. Shipping is excluded for every store: the
 * column is `(product_amount - discount_amount) - product_cost` and shipping is its own column. TAX is excluded only
 * when the merchant enters prices WITHOUT tax. A store set to enter prices inclusive of tax (`TaxOption price_type =
 * inclusive`) has tax inside `product_price` (`Cart::cart_list*` carry the tax-bearing line prices into
 * `PaymentService`'s `$product_price`), so for that store this figure INCLUDES the tax and the caveat's claim is
 * false. Found by independent review of P5-16; the `/finance` tile no longer makes the claim, but this class's caveat
 * (`MetricCaveat::MESSAGES`) still does and is recorded as `GAP-213(d)`. A merchant comparing margin against a bank
 * deposit will find it smaller for an exclusive-tax store, and that is correct rather than missing money.
 *
 * `COST_UNKNOWN` is attached only when rows in the answer actually carry `product_cost = 0`, and
 * `notes()['rows_without_cost']` says how many. `product_cost` is a snapshot of
 * `products.cost_price` taken at order time; for any financial row written before the P1-SEC-44
 * fix it may be 0 or, historically, ANOTHER STORE'S cost. Where it is 0 the column reports the
 * whole sale as profit -- not an approximation, a 100% margin on goods that cost something. That
 * is the defect this flag exists to make visible, and attaching it to answers that do not have the
 * problem is how a warning becomes furniture.
 *
 * WHICH ORDERS COUNT IS A STATED CHOICE, as in section 1. The definition names no status
 * predicate, and section 0 is explicit that counting orders nobody paid for is the error the whole
 * document exists to prevent -- so the default is settled orders only, and whichever way the flag
 * falls `notes()['basis']` says which was used. Never silently pick one.
 */
final class StoreRevenueMargin extends Metric implements SingleFigureMetric
{
    public function __construct(private readonly bool $settledOnly = true)
    {
    }

    public function key(): string
    {
        return 'store_revenue_margin';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §8';
    }

    public function for(MetricScope $scope, MetricWindow $window): MetricResult
    {
        $query = $this->marginRows($scope);
        $window->applyTo($query, $this->dateColumn());

        $aggregate = $this->sumByCurrency(
            $query,
            'store_order_financials.currency_code',
            'store_order_financials.gross_profit'
        );

        $rowsWithoutCost = $this->rowsWithoutCost($scope, $window);

        /*
         * ANA-006(c). TWO UNCONDITIONAL OMISSIONS, NOT ONE. Shipping and tax were already
         * disclosed; payment-processing fees were not, on any surface, although the arithmetic
         * omits them just as completely -- `store_order_financials` has no fee column at all.
         * Both are unconditional because the absence is total: there is no per-order signal that
         * would let either be shown "only when it applies".
         */
        $caveats = [MetricCaveat::EXCLUDES_SHIPPING_AND_TAX, MetricCaveat::EXCLUDES_PAYMENT_FEES];

        if ($rowsWithoutCost > 0) {
            $caveats[] = MetricCaveat::COST_UNKNOWN;
        }

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_ENTITLEMENT,
            $this->dateColumn(),
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            $caveats,
            [
                'basis' => $this->settledOnly ? 'settled_only' : 'all_orders',
                'rows_without_cost' => $rowsWithoutCost,
            ],
        );
    }

    /**
     * Settled margin is dated by when the money arrived; all-orders margin by when the order was
     * placed, because an unsettled row has no `paid_at` to be dated by at all.
     */
    private function dateColumn(): string
    {
        return $this->settledOnly
            ? 'store_order_financials.paid_at'
            : 'store_order_financials.created_at';
    }

    private function marginRows(MetricScope $scope): \Illuminate\Database\Query\Builder
    {
        $query = $this->financials($scope);

        if ($this->settledOnly) {
            /*
             * `paid` only -- NOT the wider eligibility of section 2. A cancelled-but-captured
             * order's margin was reversed: the goods came back and the entitlement was debited.
             * Section 2 counts that money because the SHOPPER really paid it; margin must not,
             * because the MERCHANT did not keep it.
             */
            $query->where('store_order_financials.payment_status', 'paid');
        }

        return $query;
    }

    private function rowsWithoutCost(MetricScope $scope, MetricWindow $window): int
    {
        $query = $this->marginRows($scope)
            ->where('store_order_financials.product_cost', '<=', 0);

        $window->applyTo($query, $this->dateColumn());

        return $query->count();
    }
}
