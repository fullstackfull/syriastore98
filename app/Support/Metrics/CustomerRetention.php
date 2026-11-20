<?php

namespace App\Support\Metrics;

/**
 * P5-6 -- RETENTION AND REPEAT PURCHASE, derived from `orders` and nothing invented.
 *
 * The plan's gate note is the whole design constraint: *"a repeat-purchase rate computed over
 * unsettled orders is not a retention figure."* A customer who placed two orders and paid for
 * neither did not come back; they tried twice. So settlement has to be considered -- and the
 * record that knows about settlement authoritatively, `store_order_financials`, has NO HISTORY
 * before it was created. That tension is resolved by stating the basis rather than picking one:
 *
 *   settled only (default)  counts orders whose ledger row says `paid`. Authoritative, and blind
 *                           to every order with no ledger row -- which is reported by count, not
 *                           by a hardcoded cut-over date.
 *   all orders              counts every order whatever its payment state. Covers all history and
 *                           overstates retention by however much of the book was never paid.
 *
 * Whichever is used, `notes()['basis']` says which, as §1 of the metric definitions requires of
 * every stated option. Never silently pick one.
 *
 * WHAT IS EXCLUDED IS AN ORDER WITH NOBODY ATTACHED -- NOT A GUEST ORDER. `orders.customer_id`
 * is `INTEGER DEFAULT 0`, so "no customer" and "customer zero" are the same value and only a
 * comparison separates them. Counting id 0 would give one "customer" holding every unattributed
 * order ever placed -- a repeat rate near 100% and the most flattering wrong number here. The
 * count of what was excluded is reported, so a store taking many such orders is told its
 * retention figure covers a slice of its business.
 *
 * THIS FIRST USED `is_guest = 0` AS WELL, AND THAT WAS WRONG. A guest order CAN carry a real
 * customer id: `PaymentService` sets `$orderCustomerId = ! empty($request->customer_id) ?
 * $request->customer_id : 0` on the guest branch, and its own comment records that the id is
 * "the Customer the handler found or created from the billing info -- which is legitimate". Such
 * a shopper IS recognisable on a later visit, so excluding them would have discarded real
 * repeat-purchase signal -- most of it for a store whose business is mostly guest checkout.
 *
 * The mutation battery found it: removing the `is_guest` predicate changed NOTHING, because the
 * fixture's guests also carried id 0. Chasing that overlap is what surfaced the real shape, and
 * is why a mutation that does not redden is worth more attention than one that does.
 */
final class CustomerRetention extends Metric
{
    public function __construct(private readonly bool $settledOnly = true)
    {
    }

    public function key(): string
    {
        return 'customer_retention';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §12';
    }

    public function for(MetricScope $scope, MetricWindow $window): RetentionFigures
    {
        $query = $this->identifiedOrders($scope);

        if ($this->settledOnly) {
            /*
             * The ledger is the authority on settlement. An INNER join is correct and is also
             * what makes this basis blind to pre-ledger orders -- which is disclosed below rather
             * than hidden, because the alternative (a LEFT join treating a missing row as paid)
             * would count exactly the orders whose payment state is unknown.
             */
            $query->join('store_order_financials', function ($join) {
                $join->on('store_order_financials.order_id', '=', 'orders.id')
                    ->on('store_order_financials.store_id', '=', 'orders.store_id');
            })->where('store_order_financials.payment_status', 'paid');
        }

        $window->applyTo($query, 'orders.order_date');

        $perCustomer = $query
            ->groupBy('orders.customer_id')
            ->selectRaw('orders.customer_id as retention_customer, COUNT(*) as retention_orders')
            ->get();

        $identified = $perCustomer->count();
        $repeat = $perCustomer->filter(fn ($row): bool => (int) $row->retention_orders >= 2)->count();
        $orders = (int) $perCustomer->sum(fn ($row) => (int) $row->retention_orders);

        $unattributed = $this->unattributedOrderCount($scope, $window);
        $withoutLedgerRow = $this->ordersWithoutLedgerRow($scope, $window);

        $caveats = [];

        if ($unattributed > 0) {
            $caveats[] = MetricCaveat::UNIDENTIFIED_CUSTOMERS_EXCLUDED;
        }

        if ($this->settledOnly && $withoutLedgerRow > 0) {
            $caveats[] = MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE;
        }

        return new RetentionFigures(
            $this->key(),
            $this->settledOnly ? MetricResult::RECORD_ENTITLEMENT : MetricResult::RECORD_SETTLEMENT,
            'orders.order_date',
            $identified,
            $repeat,
            $orders,
            $scope,
            $window,
            $caveats,
            [
                'basis' => $this->settledOnly ? 'settled_only' : 'all_orders',
                'orders_without_identifiable_customer' => $unattributed,
                'orders_without_ledger_row' => $withoutLedgerRow,
            ],
        );
    }

    /**
     * Orders that belong to a customer who can be recognised again.
     *
     * ONE predicate, deliberately. `is_guest` is NOT used -- see the class docblock: a guest order
     * carrying a real customer id identifies a shopper who can be recognised again, and excluding
     * it would throw away the signal this metric exists to measure.
     */
    private function identifiedOrders(MetricScope $scope): \Illuminate\Database\Query\Builder
    {
        return $this->orders($scope)
            ->where('orders.customer_id', '>', 0);
    }

    private function unattributedOrderCount(MetricScope $scope, MetricWindow $window): int
    {
        $query = $this->orders($scope)->where('orders.customer_id', '<=', 0);

        $window->applyTo($query, 'orders.order_date');

        return $query->count();
    }

    /**
     * Orders in the window the entitlement record cannot see.
     *
     * Counted rather than dated: `store_order_financials` has no backfill, so the honest statement
     * is "this many orders have no financial row", which stays true as the ledger fills. A
     * hardcoded cut-over date would go stale and would say nothing about a wallet-missing race.
     */
    private function ordersWithoutLedgerRow(MetricScope $scope, MetricWindow $window): int
    {
        $query = $this->orders($scope)
            ->leftJoin('store_order_financials', function ($join) {
                $join->on('store_order_financials.order_id', '=', 'orders.id')
                    ->on('store_order_financials.store_id', '=', 'orders.store_id');
            })
            ->whereNull('store_order_financials.id');

        $window->applyTo($query, 'orders.order_date');

        return $query->count();
    }
}
