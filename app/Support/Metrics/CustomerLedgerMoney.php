<?php

namespace App\Support\Metrics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * GAP-156 / P05-08 -- WHAT A CUSTOMER IS WORTH, FROM THE LEDGER, AS TWO FIGURES THAT ARE NOT THE
 * SAME FIGURE.
 * =============================================================================================
 *
 * Every money-bearing customer figure in this product reads `SUM(orders.final_price)` with no
 * payment predicate at all: the timeline tile (`ActivityLog::totalspend`), the customer list and
 * its AOV (`Customer::total_spend` -> `ownStoreOrders`), the list's own FILTER axis, and the admin
 * API. So a shopper who placed a 1,000 order that was cancelled before delivery reads as a
 * 1,000-spend customer, and a merchant can build a "VIP" segment out of money nobody ever paid.
 *
 * THE PREDICATE CANNOT LIVE ON `orders`, AND THAT IS AN OWNER DECISION, NOT A PREFERENCE.
 * Decision Option A, quoted verbatim in `Order::order_status_change()`:
 *
 *     "`orders.payment_status` represents the customer's actual payment settlement. Cancellation
 *      must not rewrite a captured payment as Unpaid merely to trigger wallet reversal. Represent
 *      cancellation and financial reversal reasons in the existing financial ledger structures."
 *
 * So the cancel branch does NOT touch `orders.payment_status` -- the order goes on recording what
 * the shopper really did -- and `OrderEntitlementReversal` writes the reversal into
 * `store_order_financials.payment_status` instead. A `payment_status` filter on `orders` therefore
 * cannot exclude a cancelled order, however it is written. The money has to come from the ledger.
 *
 * TWO FIGURES, AND THE WHOLE POINT IS THAT THEY DIFFER.
 *
 *   collected()     SECTION 2 semantics, per customer. What this shopper actually handed over,
 *                   INCLUDING money collected on an order that was later cancelled or refunded.
 *                   The customer did pay; that does not stop being true afterwards. This is the
 *                   figure for "has this person ever paid us", and it is the one a loyalty or
 *                   recognition question wants.
 *
 *   netRetained()   SECTION 6 semantics, per customer. What the merchant KEPT: the signed sum of
 *                   every order-referenced wallet movement for this customer's orders, so a
 *                   reversal subtracts itself. This is the figure for "what is this person worth
 *                   to the business", and it is the one a revenue question wants.
 *
 * A cancelled-after-payment order makes them disagree by exactly the captured amount. NEITHER IS
 * LABELLED AS THE OTHER ANYWHERE, and `MetricResult::key()` differs so a surface cannot print one
 * under the other's heading by accident.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DECIDE. It defines no customer lifetime value, no VIP
 * threshold and no refund policy. Those are business choices and none of them is settled; this
 * class supplies the two defined quantities and stops. `PHASE-05-METRIC-DEFINITIONS.md` §2 and §6
 * are the definitions, and the `definition()` strings point at them rather than restating them.
 *
 * NO FAN-OUT, AND IT IS PROVABLE RATHER THAN ASSERTED. `store_order_financials` carries
 * `UNIQUE KEY uq_store_order_financial_order (order_id)` (measured, `GateFProductionSchema`), so
 * the join to `orders` is one-to-one and a SUM over it cannot double-count. The movement join runs
 * the other way -- MANY transactions to ONE order -- so each transaction row still appears exactly
 * once. A test drives both with two rows per order to prove it rather than trusting this comment.
 *
 * TENANCY IS STRUCTURAL ON ONE SIDE AND EXPLICIT ON THE OTHER, IN TWO DIFFERENT SENSES. The
 * builder comes from `Metric::financials()` / `Metric::transactions()`, which are `final` and bind
 * `whereIn('store_id', $scope->storeIds())` in the same expression that names the table. The JOIN
 * then adds BOTH kinds of predicate, and they do different jobs:
 *
 *   whereIn('orders.store_id', $scope->storeIds())   puts the order INSIDE the scope
 *   ->on('orders.store_id', '=', '<other>.store_id') ties the two tables TO EACH OTHER
 *
 * The first alone is not enough and a review caught this file claiming it was. For one store the
 * two are equivalent; for a MULTI-store scope -- which this layer really builds, at
 * `HomeController:288` and in `PlatformAnalyticsFigures` -- a row whose order and ledger disagree
 * about the store satisfies the `whereIn` on both sides and is readmitted, with no store dimension
 * in the grouping to attribute it by. `CustomerRetention:69` and `LedgerCoverage:115` already use
 * the column-to-column form; this now matches them rather than merely citing them.
 *
 * `customer_id <= 0` IS REFUSED RATHER THAN AGGREGATED. `orders.customer_id` is
 * `INTEGER DEFAULT 0`, so "nobody attached" and "customer zero" are the same value.
 * `MetricCaveat::UNIDENTIFIED_CUSTOMERS_EXCLUDED` records what that costs a STORE-wide figure; for
 * a figure ABOUT one customer it is worse than a caveat, because id 0 would return one aggregate
 * holding every unattributed order the store ever took and present it as a person's history. It
 * throws.
 */
final class CustomerLedgerMoney extends Metric
{
    /** Section 2, per customer: what they paid, reversals included. */
    public const COLLECTED = 'customer_collected_payments';

    /** Section 6, per customer: what the merchant kept, reversals subtracted. */
    public const NET_RETAINED = 'customer_net_retained_receipts';

    public function key(): string
    {
        return 'customer_ledger_money';
    }

    /**
     * §15, which this class alone claims. The first version of this method cited "§2 (collected)
     * and §6 (net retained)" -- true as prose and WRONG as a citation:
     * `MetricDefinitionsAreDocumentedTest` requires `^PHASE-05-METRIC-DEFINITIONS\.md §\d+$` and
     * asserts each section is claimed by exactly ONE class, so a two-section string fails the
     * regex and citing §2 alone would collide with `CollectedCustomerPayments`. That test is in
     * the BLOCKING Unit suite, so the mistake would have reddened CI before any of this class's
     * own cases ran. §15 defines the two per-customer figures; §2 and §6 keep their own classes.
     */
    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §15';
    }

    /**
     * SECTION 2, FOR ONE CUSTOMER. Money this shopper really handed over, including money on an
     * order that was later cancelled or refunded.
     *
     * The inclusion rule is section 2's own, re-expressed here against the same columns rather
     * than re-invented: `paid_amount` is the amount, and the status only decides eligibility --
     * `paid` outright, plus the reversed-but-captured states where `paid_amount` is still above
     * zero because P4-F124 deliberately preserves it through a reversal.
     */
    public function collected(MetricScope $scope, MetricWindow $window, int $customerId): MetricResult
    {
        $byCustomer = $this->collectedForCustomers($scope, $window, [$customerId]);

        return $byCustomer[$customerId];
    }

    /**
     * SECTION 6, FOR ONE CUSTOMER. What the merchant kept once reversals are taken off.
     *
     * Movement-based, which is section 6's PREFERRED computation and the reason it is preferred:
     * every movement is typed, reasoned and written exactly once by a locked idempotent path, so
     * the figure needs no inclusion rules of its own. A cancellation or a completed refund writes
     * its own debit, and the signed sum subtracts it without this class having to know the rule.
     */
    public function netRetained(MetricScope $scope, MetricWindow $window, int $customerId): MetricResult
    {
        $byCustomer = $this->netRetainedForCustomers($scope, $window, [$customerId]);

        return $byCustomer[$customerId];
    }

    /**
     * THE BULK READ, AND IT IS NOT AN OPTIMISATION -- IT IS WHAT MAKES THE CUSTOMER LIST POSSIBLE.
     *
     * `CustomerDataTable` renders a page of customers and would otherwise call a per-customer
     * metric once per row, then AGAIN for the AOV column, then again for the filter. The shipped
     * code already has that shape (`total_spend()` is called THREE times per row --
     * `CustomerDataTable:93`, `:96` and `:97`) and it is one of the reasons the list is slow. One grouped query
     * answers the whole page.
     *
     * EVERY REQUESTED ID GETS A RESULT, including a customer with no orders at all. A missing key
     * would make the caller write `?? 0` at each use site and silently turn "this store has no
     * record of that customer" into "that customer spent nothing", which are different statements.
     *
     * @param  list<int>  $customerIds
     * @return array<int, MetricResult>
     */
    public function collectedForCustomers(MetricScope $scope, MetricWindow $window, array $customerIds): array
    {
        $ids = $this->identifiableIds($customerIds);

        $query = $this->applyCapturedInclusion($this->customerFinancials($scope, $ids));

        /*
         * Dated by `paid_at`, not by the order date: money that arrived in March for a February
         * order is March's collection. A null `paid_at` cannot be placed in any window, so a
         * bounded window excludes it -- correct, because an unknown capture date is not evidence
         * of a capture inside the period.
         */
        $window->applyTo($query, 'store_order_financials.paid_at');

        return $this->resultsPerCustomer(
            $this->sumByCustomerAndCurrency(
                $query,
                'store_order_financials.currency_code',
                'store_order_financials.paid_amount'
            ),
            $ids,
            self::COLLECTED,
            MetricResult::RECORD_ENTITLEMENT,
            'store_order_financials.paid_at',
            $scope,
            $window,
            [
                'includes_reversed_but_captured' => true,
                'is_not_net_of_reversals' => true,
                // §11: state the option, never silently pick one. `CollectedCustomerPayments`
                // records the same choice in its own notes and says why: such a row holds real
                // money the shopper handed over, so excluding it would commit the exact
                // under-count section 2 exists to prevent.
                'partially_paid' => 'included',
            ],
            $this->ordersWithoutLedgerRowPerCustomer($scope, $window, $ids)
        );
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, MetricResult>
     */
    public function netRetainedForCustomers(MetricScope $scope, MetricWindow $window, array $customerIds): array
    {
        $ids = $this->identifiableIds($customerIds);

        $query = $this->customerTransactions($scope, $ids)
            // `status <> 'cancelled'` excludes a movement that was written and then voided;
            // counting it would be counting money that was recorded and then un-recorded.
            ->where('store_wallet_transactions.status', '<>', 'cancelled');

        $window->applyTo($query, 'store_wallet_transactions.created_at');

        return $this->resultsPerCustomer(
            $this->sumByCustomerAndCurrency(
                $query,
                'store_wallet_transactions.currency_code',
                "CASE WHEN store_wallet_transactions.direction = 'credit' "
                . 'THEN store_wallet_transactions.amount '
                . 'ELSE -store_wallet_transactions.amount END'
            ),
            $ids,
            self::NET_RETAINED,
            MetricResult::RECORD_MOVEMENT,
            'store_wallet_transactions.created_at',
            $scope,
            $window,
            [
                'computation' => 'movement',
                'is_net_of_reversals' => true,
                // Scope-level, not per-customer, and the SAME on every result by construction: a
                // movement whose order does not resolve cannot be attributed to a customer -- that
                // is what makes it uncountable here. It is why the sum of every customer's
                // retained figure need not equal `NetFinancialReceipts` for the same store and
                // window, and the figure says so rather than leaving the gap to be discovered.
                'movements_without_resolvable_order' =>
                    $this->movementsWithoutResolvableOrder($scope, $window),
            ]
        );
    }

    /**
     * SECTION 2'S INCLUSION RULE, IN ONE PLACE, applied by both the customer-id and the order-set
     * path.
     *
     * It was written out twice and review flagged the copy. `Customer::ownStoreOrders()`'s own
     * docblock records where that road goes -- "the same five lines twice and BOTH were missing
     * the same predicate" -- and a money predicate is the worst kind to keep two of, because a
     * drift between them does not error, it just reports two different answers to the same
     * question.
     */
    private function applyCapturedInclusion(Builder $query): Builder
    {
        return $query->where(function ($captured) {
            $captured
                ->where('store_order_financials.payment_status', 'paid')
                ->orWhere(function ($reversedButCaptured) {
                    // The F124 limb: an order the shopper paid for and then cancelled still
                    // records the payment. Dropping it would under-count the exact thing §2
                    // exists to count. `partially_paid` is included and §15 declares it.
                    $reversedButCaptured
                        ->whereIn('store_order_financials.payment_status', [
                            'cancelled', 'refunded', 'partially_paid',
                        ])
                        ->where('store_order_financials.paid_amount', '>', 0);
                });
        });
    }

    /**
     * THE SAME TWO FIGURES OVER AN EXPLICIT ORDER SET, for the attribution this product already
     * has and this class must not re-answer.
     *
     * `Customer::ownStoreOrders()` decides which orders are a given shopper's, and it has TWO
     * limbs: directly by `orders.customer_id`, and -- for a customer with no such orders -- by
     * matching UNATTRIBUTED orders (`customer_id = 0`) on the billing e-mail. The customer-id
     * methods above cannot express the second limb, because `customer_id <= 0` is exactly what
     * they refuse, and refusing it is right: id 0 is every unattributed order the store ever took.
     *
     * WHY THAT MATTERS MORE THAN IT LOOKS. If these figures covered a different ORDER SET from the
     * `Total Spend` printed beside them, the two numbers would differ for a reason that has
     * nothing to do with payment -- and a merchant comparing them would read an ATTRIBUTION
     * difference as a PAYMENT one. That is the precise mislabelling §15 forbids, arrived at from
     * the other direction.
     *
     * So the surface resolves the order set the way the product already resolves it, and hands it
     * here. The tenant predicate is unchanged and still structural: the builder is
     * `financials($scope)` / `transactions($scope)`, the join still ties the two tables to each
     * other by `store_id`, and the order ids only NARROW that -- they can never widen it, so a
     * caller cannot reach another tenant's money by passing its order ids.
     *
     * @param  list<int>  $orderIds
     */
    public function collectedForOrders(MetricScope $scope, MetricWindow $window, array $orderIds): MetricResult
    {
        $ids = $this->usableOrderIds($orderIds);

        if ($ids === []) {
            return $this->emptyResult(
                self::COLLECTED, MetricResult::RECORD_ENTITLEMENT,
                'store_order_financials.paid_at', $scope, $window,
                [
                    'includes_reversed_but_captured' => true,
                    'is_not_net_of_reversals' => true,
                    'partially_paid' => 'included',
                    'attribution' => 'order_ids',
                    'orders_attributed' => 0,
                    // Note parity: a surface reading this key must not get null from one limb and
                    // a number from the other, or it will print "—" for every guest-attributed
                    // shopper and a figure for everyone else.
                    'orders_without_ledger_row' => 0,
                ]
            );
        }

        $query = $this->applyCapturedInclusion($this->orderScopedFinancials($scope, $ids));

        $window->applyTo($query, 'store_order_financials.paid_at');

        $aggregate = $this->sumByCurrency(
            $query,
            'store_order_financials.currency_code',
            'store_order_financials.paid_amount'
        );

        // ONE count, not two. It was computed here and again inside the caveat helper -- review
        // found it, and this path runs per customer on the list, so a doubled query is a doubled
        // query per row.
        $uncovered = $this->ordersWithoutLedgerRowForOrders($scope, $window, $ids);

        return MetricResult::make(
            self::COLLECTED,
            MetricResult::RECORD_ENTITLEMENT,
            'store_order_financials.paid_at',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            $uncovered > 0 ? [MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE] : [],
            [
                'includes_reversed_but_captured' => true,
                'is_not_net_of_reversals' => true,
                'partially_paid' => 'included',
                'attribution' => 'order_ids',
                'orders_attributed' => count($ids),
                'orders_without_ledger_row' => $uncovered,
            ],
        );
    }

    /** @param  list<int>  $orderIds */
    public function netRetainedForOrders(MetricScope $scope, MetricWindow $window, array $orderIds): MetricResult
    {
        $ids = $this->usableOrderIds($orderIds);

        if ($ids === []) {
            return $this->emptyResult(
                self::NET_RETAINED, MetricResult::RECORD_MOVEMENT,
                'store_wallet_transactions.created_at', $scope, $window,
                [
                    'computation' => 'movement',
                    'is_net_of_reversals' => true,
                    'attribution' => 'order_ids',
                    'orders_attributed' => 0,
                    'movements_without_resolvable_order' => 0,
                ]
            );
        }

        $query = $this->transactions($scope)
            ->join('orders', function ($join) {
                $join->on('orders.id', '=', 'store_wallet_transactions.reference_id')
                    ->on('orders.store_id', '=', 'store_wallet_transactions.store_id')
                    ->where('store_wallet_transactions.reference_type', '=', 'order');
            })
            ->whereIn('orders.store_id', $scope->storeIds())
            ->whereIn('orders.id', $ids)
            ->where('store_wallet_transactions.status', '<>', 'cancelled');

        $window->applyTo($query, 'store_wallet_transactions.created_at');

        $aggregate = $this->sumByCurrency(
            $query,
            'store_wallet_transactions.currency_code',
            "CASE WHEN store_wallet_transactions.direction = 'credit' "
            . 'THEN store_wallet_transactions.amount '
            . 'ELSE -store_wallet_transactions.amount END'
        );

        return MetricResult::make(
            self::NET_RETAINED,
            MetricResult::RECORD_MOVEMENT,
            'store_wallet_transactions.created_at',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            [],
            [
                'computation' => 'movement',
                'is_net_of_reversals' => true,
                'attribution' => 'order_ids',
                'orders_attributed' => count($ids),
                'movements_without_resolvable_order' =>
                    $this->movementsWithoutResolvableOrder($scope, $window),
            ],
        );
    }

    /**
     * AN EMPTY ORDER SET IS A REAL ANSWER HERE, unlike an empty CUSTOMER list.
     *
     * Asking "what did these orders collect" with no orders is a legitimate question with the
     * answer "nothing" -- a customer whose guest-order match found none. Asking "what did these
     * customers collect" with no customers is a programming error, because `whereIn('…', [])`
     * compiles to `0 = 1` and would report zero for a question nobody asked. The two cases differ
     * and this class answers them differently rather than applying one rule to both.
     */
    private function emptyResult(
        string $key,
        string $record,
        string $datedBy,
        MetricScope $scope,
        MetricWindow $window,
        array $notes,
    ): MetricResult {
        return MetricResult::make($key, $record, $datedBy, [], 0, $scope, $window, [], $notes);
    }

    /**
     * THREE PREDICATES, AND THE THIRD IS DELIBERATELY REDUNDANT.
     *
     *   financials($scope)                               the LEDGER row is inside the scope
     *   ->on('orders.store_id', '=', '…store_id')        the ORDER agrees with its ledger row
     *   ->whereIn('orders.store_id', …)                  the ORDER is inside the scope
     *
     * The first two entail the third, so removing it changes no row -- a mutation confirmed that
     * and it SURVIVES CORRECTLY, which is recorded here so nobody reads the survivor as a test
     * gap and goes looking for the fixture that would catch it. There is none, by construction.
     *
     * It stays because the entailment is a property of the OTHER two lines, not of this one: if
     * the join condition were ever weakened to `on('orders.id', …)` alone -- which is how the
     * first draft of this class was written -- the third predicate becomes the only thing holding
     * the order inside the tenant. Keeping it costs nothing and removes a way for a later edit to
     * be quietly wrong.
     *
     * @param  list<int>  $ids
     */
    private function orderScopedFinancials(MetricScope $scope, array $ids): Builder
    {
        return $this->financials($scope)
            ->join('orders', function ($join) {
                $join->on('orders.id', '=', 'store_order_financials.order_id')
                    ->on('orders.store_id', '=', 'store_order_financials.store_id');
            })
            ->whereIn('orders.store_id', $scope->storeIds())
            ->whereIn('orders.id', $ids);
    }

    /** @param  list<int>  $ids */
    private function ordersWithoutLedgerRowForOrders(MetricScope $scope, MetricWindow $window, array $ids): int
    {
        $query = $this->orders($scope)
            ->leftJoin('store_order_financials', function ($join) {
                $join->on('store_order_financials.order_id', '=', 'orders.id')
                    ->on('store_order_financials.store_id', '=', 'orders.store_id');
            })
            ->whereNull('store_order_financials.id')
            ->whereIn('orders.id', $ids);

        $window->applyTo($query, 'orders.order_date');

        return $query->count();
    }

    /**
     * Order ids, normalised. Unlike a customer id, zero and negatives are simply not ids -- they
     * are dropped rather than refused, because an empty set is a real answer for this question.
     *
     * @param  list<int>  $orderIds
     * @return list<int>
     */
    private function usableOrderIds(array $orderIds): array
    {
        $ids = [];

        foreach ($orderIds as $orderId) {
            if (! is_numeric($orderId)) {
                continue;
            }

            $id = (int) $orderId;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        sort($ids);

        return array_values($ids);
    }

    /**
     * The ledger rows of these customers' orders, tenant-scoped on BOTH sides of the join.
     *
     * @param  list<int>  $ids
     */
    private function customerFinancials(MetricScope $scope, array $ids): Builder
    {
        return $this->financials($scope)
            ->join('orders', function ($join) {
                $join->on('orders.id', '=', 'store_order_financials.order_id')
                    // THE TENANT CONDITION IS COLUMN-TO-COLUMN, NOT SCOPE-TO-COLUMN, and that is
                    // the difference between holding for one store and holding for any scope. The
                    // `whereIn` below pins both sides INTO the scope; this pins them to EACH
                    // OTHER. With a multi-store scope a row whose order and ledger disagree about
                    // the store satisfies both `whereIn`s and would be readmitted with nothing to
                    // attribute it by, because the grouping has no store dimension. Review caught
                    // that the docblock claimed otherwise. `CustomerRetention:69` and
                    // `LedgerCoverage:115` are the precedent, and they use this stronger form.
                    ->on('orders.store_id', '=', 'store_order_financials.store_id');
            })
            ->whereIn('orders.store_id', $scope->storeIds())
            ->whereIn('orders.customer_id', $ids);
    }

    /**
     * The order-referenced wallet movements of these customers' orders.
     *
     * `reference_type = 'order'` keeps a withdrawal with `reference_id = 7` from joining to order
     * 7. It sits in the JOIN rather than in a `where` -- but for an INNER join those filter
     * identically, and `NetFinancialReceipts:116` writes the same condition as a `where` and gets
     * the same rows, so the placement is NOT what makes this correct; the condition existing is.
     * What the placement buys is that a later top-level `orWhere` cannot disjoin it away, and it
     * would become load-bearing in the other direction if this ever became a `leftJoin`, where a
     * non-order movement would survive with null order columns.
     *
     * @param  list<int>  $ids
     */
    private function customerTransactions(MetricScope $scope, array $ids): Builder
    {
        return $this->transactions($scope)
            ->join('orders', function ($join) {
                $join->on('orders.id', '=', 'store_wallet_transactions.reference_id')
                    ->on('orders.store_id', '=', 'store_wallet_transactions.store_id')
                    ->where('store_wallet_transactions.reference_type', '=', 'order');
            })
            ->whereIn('orders.store_id', $scope->storeIds())
            ->whereIn('orders.customer_id', $ids);
    }

    /**
     * One grouped query for the whole page: customer, then currency.
     *
     * The currency is grouped, never collapsed. Two currencies for one customer stay two buckets
     * and `MetricResult` refuses to total them, which is what stops a figure from quietly becoming
     * the sum of amounts in different money.
     *
     * @return array<int, array{byCurrency: array<string, float>, rowCount: int}>
     */
    private function sumByCustomerAndCurrency(
        Builder $query,
        string $currencyColumn,
        string $amountExpression,
    ): array {
        /*
         * THE SAME TWO CLOSED SETS `Metric::sumByCurrency()` USES, and deliberately not a second
         * pair here: this method takes the same two parameter kinds, over the same tables, and
         * two lists that are supposed to agree are two lists that will drift. The assertions are
         * `final` on `Metric`, so no subclass can replace them with a looser pair either.
         *
         * Before the first SQL construction, not before its execution -- `selectRaw()` and
         * `groupBy(DB::raw(...))` below both only store text on the builder.
         */
        self::assertCurrencyColumn($currencyColumn);
        self::assertAmountExpression($amountExpression);

        $rows = $query
            ->selectRaw(
                'orders.customer_id as metric_customer, '
                . $currencyColumn . ' as metric_currency, '
                . 'SUM(' . $amountExpression . ') as metric_amount, '
                . 'COUNT(*) as metric_rows'
            )
            ->groupBy(DB::raw('orders.customer_id'), DB::raw($currencyColumn))
            ->get();

        $byCustomer = [];

        foreach ($rows as $row) {
            $customerId = (int) $row->metric_customer;
            // The SAME bucket key `MetricResult::make()` uses -- see Metric::currencyBucketKey(),
            // which is where that promise is actually kept. This site made it for NULL and `''`
            // only, so one customer's `usd` and `USD` rows were two buckets.
            $key = self::currencyBucketKey($row->metric_currency);

            $byCustomer[$customerId]['byCurrency'][$key] = round(
                ($byCustomer[$customerId]['byCurrency'][$key] ?? 0.0) + (float) $row->metric_amount,
                2
            );

            $byCustomer[$customerId]['rowCount'] =
                ($byCustomer[$customerId]['rowCount'] ?? 0) + (int) $row->metric_rows;
        }

        return $byCustomer;
    }

    /**
     * @param  array<int, array{byCurrency: array<string, float>, rowCount: int}>  $aggregates
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $notes
     * @return array<int, MetricResult>
     */
    private function resultsPerCustomer(
        array $aggregates,
        array $ids,
        string $key,
        string $record,
        string $datedBy,
        MetricScope $scope,
        MetricWindow $window,
        array $notes,
        ?array $ordersWithoutLedgerRow = null,
    ): array {
        $results = [];

        foreach ($ids as $customerId) {
            $aggregate = $aggregates[$customerId] ?? ['byCurrency' => [], 'rowCount' => 0];

            $perCustomerNotes = $notes + ['customer_id' => $customerId];
            $caveats = [];

            /*
             * THE BOUNDARY THAT MAKES THIS FIGURE HONEST RATHER THAN MERELY DIFFERENT.
             *
             * GAP-156 replaces a figure that OVER-reported, and the failure mode of its
             * replacement is to UNDER-report to 0.00 without saying why. `store_order_financials`
             * is written only by `OrderObserver::created()` and was never backfilled, so a shopper
             * whose orders all predate that table reads as having paid nothing -- and
             * `isAuthoritative()` would return true while the ledger could not see a single one of
             * their orders. The count is per customer, so the caveat fires for the shopper it is
             * about rather than for everyone whenever any order is uncovered.
             */
            if ($ordersWithoutLedgerRow !== null) {
                $uncovered = $ordersWithoutLedgerRow[$customerId] ?? 0;
                $perCustomerNotes['orders_without_ledger_row'] = $uncovered;

                if ($uncovered > 0) {
                    $caveats[] = MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE;
                }
            }

            $results[$customerId] = MetricResult::make(
                $key,
                $record,
                $datedBy,
                $aggregate['byCurrency'],
                $aggregate['rowCount'],
                $scope,
                $window,
                $caveats,
                $perCustomerNotes,
            );
        }

        return $results;
    }

    /**
     * Orders of these customers that the ledger cannot see, per customer.
     *
     * COUNTED, NEVER DATED, which is §9's rule and `CustomerRetention`'s shape: "this many orders
     * have no financial row" stays true as the ledger fills, where a hardcoded cut-over date goes
     * stale and says nothing about a wallet-missing race.
     *
     * Dated by `orders.order_date` rather than by `paid_at`, because an order with no ledger row
     * HAS no `paid_at` -- dating the count by the column the figure uses would make it always zero
     * and the caveat would never fire.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function ordersWithoutLedgerRowPerCustomer(
        MetricScope $scope,
        MetricWindow $window,
        array $ids,
    ): array {
        $query = $this->orders($scope)
            ->leftJoin('store_order_financials', function ($join) {
                $join->on('store_order_financials.order_id', '=', 'orders.id')
                    ->on('store_order_financials.store_id', '=', 'orders.store_id');
            })
            ->whereNull('store_order_financials.id')
            ->whereIn('orders.customer_id', $ids);

        $window->applyTo($query, 'orders.order_date');

        $counts = [];

        foreach ($query->selectRaw('orders.customer_id as metric_customer, COUNT(*) as metric_rows')
            ->groupBy(DB::raw('orders.customer_id'))->get() as $row) {
            $counts[(int) $row->metric_customer] = (int) $row->metric_rows;
        }

        return $counts;
    }

    /**
     * Order-referenced movements in this scope and window whose order does not resolve.
     *
     * `store_wallet_transactions.reference_id` carries NO foreign key -- measured, the table
     * declares only the store and wallet constraints -- while `store_order_financials` cascades on
     * order delete. So an order-referenced movement can outlive its order, and the inner join in
     * `customerTransactions()` drops it silently. Reported rather than hidden, because it is the
     * reason a per-customer retained figure does not have to add up to section 6.
     */
    private function movementsWithoutResolvableOrder(MetricScope $scope, MetricWindow $window): int
    {
        $query = $this->transactions($scope)
            ->where('store_wallet_transactions.reference_type', 'order')
            ->where('store_wallet_transactions.status', '<>', 'cancelled')
            ->leftJoin('orders', function ($join) {
                $join->on('orders.id', '=', 'store_wallet_transactions.reference_id')
                    ->on('orders.store_id', '=', 'store_wallet_transactions.store_id');
            })
            ->whereNull('orders.id');

        $window->applyTo($query, 'store_wallet_transactions.created_at');

        return $query->count();
    }

    /**
     * @param  list<int>  $customerIds
     * @return list<int>
     */
    private function identifiableIds(array $customerIds): array
    {
        $ids = [];

        foreach ($customerIds as $customerId) {
            if (! is_numeric($customerId)) {
                throw new InvalidArgumentException(
                    'A customer money figure takes customer ids; received '
                    . get_debug_type($customerId) . '.'
                );
            }

            $id = (int) $customerId;

            if ($id <= 0) {
                throw new InvalidArgumentException(
                    'A customer money figure needs an identifiable customer; received ' . $id
                    . '. `orders.customer_id` defaults to 0, so id 0 is not a person: it is every '
                    . 'order the store could not attribute to anyone, and reporting their combined '
                    . 'money as one shopper\'s history is the most flattering wrong number this '
                    . 'schema can produce.'
                );
            }

            $ids[$id] = $id;
        }

        if ($ids === []) {
            throw new InvalidArgumentException(
                'A customer money figure must name at least one customer. An empty list would '
                . 'compile to "0 = 1" and report zero, which is indistinguishable from a customer '
                . 'who has paid nothing.'
            );
        }

        sort($ids);

        return array_values($ids);
    }
}
