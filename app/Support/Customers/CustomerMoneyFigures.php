<?php

namespace App\Support\Customers;

use App\Models\Customer;
use App\Models\OrderBillingDetail;
use App\Support\Metrics\CustomerLedgerMoney;
use App\Support\Metrics\MetricResult;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use Illuminate\Support\Facades\DB;

/**
 * GAP-156 -- THE ONE PLACE EVERY CUSTOMER MONEY FIGURE IS ASKED FOR, so that the timeline, the
 * list, the list's FILTER and the admin API cannot drift from each other.
 * =============================================================================================
 *
 * WHY A RESOLVER EXISTS AT ALL, rather than each surface calling `CustomerLedgerMoney` directly.
 *
 * Two things have to agree for a customer money figure to mean anything, and they are decided in
 * different places:
 *
 *   WHICH ORDERS are this customer's   -- `Customer::ownStoreOrders()` already answers this, and
 *                                         the answer has two limbs (below).
 *   WHICH MONEY counts                 -- `CustomerLedgerMoney`, from the ledger, per §15.
 *
 * If a surface got the second right and the first wrong, its figure would differ from the
 * `Total Spend` beside it for a reason that has nothing to do with payment, and a merchant would
 * read an ATTRIBUTION difference as a PAYMENT one. The owner's instruction forbids exactly that
 * class of silent mislabelling, and it is easier to violate from this direction than from the one
 * the instruction is usually about.
 *
 * AND THE FILTER IS THE SHARPER HALF. `CustomerListFilter` selects customers by comparing a
 * figure; the column displays a figure. If those two came from different code the list could show
 * one number and filter on another, which is worse than either being wrong on its own -- a
 * merchant would build a segment from a rule the screen does not state. One resolver, used by
 * both, makes that impossible rather than merely unlikely.
 *
 * THE TWO ATTRIBUTION LIMBS, copied in BEHAVIOUR from `Customer::ownStoreOrders()` and not in
 * code, because that method returns a query and this needs ids:
 *
 *   1. DIRECT -- orders whose `customer_id` is this customer's id. The common case.
 *   2. GUEST FALLBACK -- only when the customer has NO direct orders: unattributed orders
 *      (`customer_id = 0`) in this store whose billing e-mail matches the customer's. A guest
 *      order can belong to a recognisable person; `MetricCaveat::UNIDENTIFIED_CUSTOMERS_EXCLUDED`
 *      records why "guest" and "unidentifiable" are not the same thing.
 *
 * The limbs are mutually exclusive and limb 2 is a FALLBACK, exactly as the shipped method has it:
 * a customer with one direct order and fifty e-mail matches gets the one. That may or may not be
 * the ideal rule, but it is the rule this product already applies to `Total Spend`, and changing
 * it is a product decision rather than a correctness fix. **This class deliberately does not
 * change it** -- it makes the ledger figures follow it.
 *
 * TENANCY. Every figure is taken under `MetricScope::store($customer->store_id)` -- the CUSTOMER's
 * own store, never `getCurrentStore()`. That distinction is not pedantic: `getCurrentStore()`
 * answers the literal 1 for an operator whose `current_store` is empty, and
 * `CustomerController::customerTimeline()` deliberately admits the operator to ANY store's
 * customer. Scoping by the viewer would read store 1's ledger for a store-7 shopper.
 */
final class CustomerMoneyFigures
{
    /**
     * Both figures for one customer, with the attribution resolved the way the product resolves
     * it.
     *
     * @return array{collected: MetricResult, retained: MetricResult, attribution: string}
     */
    public static function forCustomer(Customer $customer, ?MetricWindow $window = null): array
    {
        $storeId = (int) ($customer->store_id ?? 0);
        $customerId = (int) $customer->id;
        $window ??= MetricWindow::allTime();

        /*
         * FAILS CLOSED ON A STORELESS CUSTOMER, matching `ownStoreOrders()`, which returns
         * `whereRaw('1 = 0')` for one. A `MetricScope` cannot be built from store 0 at all -- it
         * throws rather than compiling to `0 = 1` -- so the refusal has to happen here, before the
         * scope exists.
         */
        if ($storeId <= 0 || $customerId <= 0) {
            return self::nothing('unresolvable');
        }

        $scope = MetricScope::store($storeId);
        $figures = new CustomerLedgerMoney();

        if (self::hasDirectOrders($storeId, $customerId)) {
            return [
                'collected' => $figures->collected($scope, $window, $customerId),
                'retained' => $figures->netRetained($scope, $window, $customerId),
                'attribution' => 'customer_id',
            ];
        }

        $orderIds = self::guestOrderIds($storeId, (string) $customer->email);

        return [
            'collected' => $figures->collectedForOrders($scope, $window, $orderIds),
            'retained' => $figures->netRetainedForOrders($scope, $window, $orderIds),
            'attribution' => $orderIds === [] ? 'none' : 'billing_email',
        ];
    }

    /**
     * The same, for a page of customers, with ONE grouped query for the direct ones.
     *
     * The direct limb is the common case and is read in bulk; the guest fallback is per customer,
     * because its order set differs per e-mail and cannot be grouped. That is not a compromise --
     * `Customer::ownStoreOrders()` already does a per-customer `exists()` check and then a
     * per-customer e-mail match, so this is at worst the same number of queries for the fallback
     * customers and strictly fewer for everyone else. The shipped list calls `total_spend()` and
     * `Ordercount()` three times per row between the column and the AOV.
     *
     * @param  iterable<Customer>  $customers
     * @return array<int, array{collected: MetricResult, retained: MetricResult, attribution: string}>
     */
    public static function forCustomers(iterable $customers, ?MetricWindow $window = null): array
    {
        $window ??= MetricWindow::allTime();

        /** @var array<int, Customer> $byId */
        $byId = [];

        foreach ($customers as $customer) {
            $byId[(int) $customer->id] = $customer;
        }

        if ($byId === []) {
            return [];
        }

        $figures = new CustomerLedgerMoney();
        $results = [];

        /*
         * GROUPED BY STORE, because a page of customers can span stores for the operator and a
         * `MetricScope` is a tenant predicate, not a filter. Taking one scope over all of them
         * would read every listed store's ledger for every listed customer and rely on the
         * customer id to sort it out -- which is the shape this layer exists to prevent.
         */
        $direct = [];

        foreach ($byId as $customerId => $customer) {
            $storeId = (int) ($customer->store_id ?? 0);

            if ($storeId <= 0 || $customerId <= 0) {
                $results[$customerId] = self::nothing('unresolvable');

                continue;
            }

            if (self::hasDirectOrders($storeId, $customerId)) {
                $direct[$storeId][] = $customerId;

                continue;
            }

            $scope = MetricScope::store($storeId);
            $orderIds = self::guestOrderIds($storeId, (string) $customer->email);

            $results[$customerId] = [
                'collected' => $figures->collectedForOrders($scope, $window, $orderIds),
                'retained' => $figures->netRetainedForOrders($scope, $window, $orderIds),
                'attribution' => $orderIds === [] ? 'none' : 'billing_email',
            ];
        }

        foreach ($direct as $storeId => $customerIds) {
            $scope = MetricScope::store($storeId);

            $collected = $figures->collectedForCustomers($scope, $window, $customerIds);
            $retained = $figures->netRetainedForCustomers($scope, $window, $customerIds);

            foreach ($customerIds as $customerId) {
                $results[$customerId] = [
                    'collected' => $collected[$customerId],
                    'retained' => $retained[$customerId],
                    'attribution' => 'customer_id',
                ];
            }
        }

        return $results;
    }

    /**
     * The scalar a numeric filter or a column compares, in the customer's OWN currency, or null.
     *
     * NULL IS A REAL ANSWER AND NOT ZERO. A customer whose ledger holds nothing, whose orders the
     * ledger cannot see, or whose money sits in more than one currency has no single comparable
     * number. Returning 0.0 for any of those would make `Total Spend less than 10` select them,
     * which is how a merchant ends up with a segment built from absences.
     *
     * MIXED CURRENCY RETURNS NULL rather than a total, because `MetricResult::total()` refuses
     * more than one bucket and this must not be the place that quietly adds them up.
     */
    public static function comparable(MetricResult $result): ?float
    {
        $byCurrency = $result->byCurrency();

        if ($byCurrency === [] || count($byCurrency) > 1) {
            return null;
        }

        return (float) reset($byCurrency);
    }

    /**
     * DELIBERATELY WINDOW-INDEPENDENT. Which limb attributes a customer must not change as a
     * merchant drags the date range: a shopper with one direct order last year would otherwise
     * flip to the e-mail limb for a "last 7 days" view and be credited with somebody else's guest
     * orders. `ownStoreOrders()` has no window either, so this matches it.
     */
    private static function hasDirectOrders(int $storeId, int $customerId): bool
    {
        return DB::table('orders')
            ->where('store_id', $storeId)
            ->where('customer_id', $customerId)
            ->exists();
    }

    /**
     * Unattributed orders in THIS store whose billing e-mail is this customer's.
     *
     * The store predicate is on `orders`, not on the billing table, for the reason
     * `Customer::ownStoreOrders()` records: `order_billing_details` has no `store_id` and cannot
     * be scoped on its own. An empty or missing e-mail matches nothing rather than everything --
     * the condition that would otherwise turn "this customer has no e-mail" into "every
     * unattributed order in the store is theirs".
     *
     * @return list<int>
     */
    private static function guestOrderIds(int $storeId, string $email): array
    {
        $email = trim($email);

        if ($email === '') {
            return [];
        }

        return DB::table('orders')
            ->where('orders.store_id', $storeId)
            ->where('orders.customer_id', 0)
            ->whereIn('orders.id', OrderBillingDetail::where('email', $email)->pluck('order_id'))
            ->pluck('orders.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array{collected: MetricResult, retained: MetricResult, attribution: string} */
    private static function nothing(string $attribution): array
    {
        /*
         * A customer with no store has no tenant to read, and `MetricScope` refuses store 0. The
         * figures are therefore absent rather than zero, and `comparable()` turns that into null
         * rather than into a number a filter would match on.
         */
        /*
         * A SCOPE IS REQUIRED TO BUILD A RESULT AND THIS FIGURE HAS NO TENANT, which is a real
         * awkwardness rather than a tidy one. `MetricScope` refuses store 0 by design, so store 1
         * stands in -- and store 1 is a REAL tenant, so `describe()` would otherwise tell a reader
         * this figure came from somebody else's store. Nothing is read: both results are empty and
         * `rowCount` is 0. The note below is what stops the provenance string being believed, and
         * a surface should branch on `attribution === 'unresolvable'` rather than print the scope.
         */
        $scope = MetricScope::store(1);
        $window = MetricWindow::allTime();

        return [
            'collected' => MetricResult::make(
                CustomerLedgerMoney::COLLECTED, MetricResult::RECORD_ENTITLEMENT,
                'store_order_financials.paid_at', [], 0, $scope, $window, [],
                ['attribution' => $attribution, 'orders_attributed' => 0, 'scope_unresolved' => true],
            ),
            'retained' => MetricResult::make(
                CustomerLedgerMoney::NET_RETAINED, MetricResult::RECORD_MOVEMENT,
                'store_wallet_transactions.created_at', [], 0, $scope, $window, [],
                ['attribution' => $attribution, 'orders_attributed' => 0, 'scope_unresolved' => true],
            ),
            'attribution' => $attribution,
        ];
    }
}
