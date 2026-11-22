<?php

namespace App\Support\Metrics;

/**
 * SECTION 1 -- Gross merchandise value.
 *
 * The total customer-facing value of orders PLACED in the period, before any settlement,
 * cancellation or refund is considered. **A demand measure, not a money measure**, and the only
 * metric for which `SUM(orders.final_price)` is a legitimate computation.
 *
 * WHETHER CANCELLATIONS COUNT IS A CHOICE, NOT A DEFAULT. GMV conventionally includes them; this
 * platform has no prior convention. Section 1 is explicit: "The report must label which it used.
 * Never silently pick one." So the flag is constructor-mandatory in spirit -- it has a default,
 * but whichever way it falls the answer carries `notes()['cancellations']`, and a surface that
 * prints the figure without the label is the defect this design exists to make visible.
 */
final class GrossMerchandiseValue extends Metric implements SingleFigureMetric
{
    public function __construct(private readonly bool $includeCancelled = true)
    {
    }

    public function key(): string
    {
        return 'gmv';
    }

    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §1';
    }

    public function for(MetricScope $scope, MetricWindow $window): MetricResult
    {
        $query = $this->placedOrders($scope, $window);

        $aggregate = $this->sumByCurrency(
            $query,
            'orders.currency_code',
            'orders.final_price'
        );

        return MetricResult::make(
            $this->key(),
            MetricResult::RECORD_SETTLEMENT,
            'orders.order_date',
            $aggregate['byCurrency'],
            $aggregate['rowCount'],
            $scope,
            $window,
            // `orders.final_price` is FLOAT -- about seven significant digits. GMV is the one
            // metric with no DECIMAL alternative, because an order that was never given a
            // financial row has no entitlement figure at all.
            [MetricCaveat::FLOAT_PRECISION],
            ['cancellations' => $this->includeCancelled ? 'included' : 'excluded'],
        );
    }

    /**
     * THE SAME FIGURE, PER STORE AND PER CURRENCY -- for a ranking, which is the one thing a
     * `MetricResult` cannot express.
     *
     * It is the same metric and deliberately not a second class: same record, same date column,
     * same cancellations flag, same FLOAT caveat, same section of the definitions document. A
     * ranking that reached for its own SQL is how the operator dashboard's "Top Selling Store"
     * panel came to disagree with every other figure about currency (GAP-162): it summed
     * `final_price` across every store and ordered by the raw number, so a store trading in a
     * large-nominal currency outranked every dollar store by arithmetic alone.
     *
     * GROUPED BY CURRENCY AS WELL AS BY STORE, AND THE CALLER MUST KEEP THEM APART. Nothing in this
     * schema stamps an exchange rate onto an order, so two amounts in different currencies are
     * not comparable -- comparing them is the same error as adding them, which
     * `MetricResult::total()` already refuses. This method returns the buckets and ranks nothing.
     *
     * A null `currency` means the order never recorded one. It is returned as null rather than
     * guessed here, because the honest fallback is the STORE's own currency and this class does
     * not read settings; see `PlatformAnalyticsFigures::topStoresByOrderValue()`.
     *
     * OPTIONALLY NARROWED, so a caller that already knows which rows it needs does not pay for the
     * rest. Both narrowings exist for `PlatformAnalyticsFigures::topStoresByOrderValue()`, which
     * reads this method only for the stores whose bucket the DATABASE CANNOT KNOW -- see
     * `topStoresForCurrencies()` below for why that is, and what it replaced.
     *
     * `$onlyStoreIds` and `$onlyCurrencies` NARROW; they never widen. An empty array is a caller
     * asking for nothing, which is a programming error rather than an empty answer -- the same
     * reasoning `MetricScope` applies to an empty scope -- so it throws instead of compiling to
     * `0 = 1` and reporting a store that took no orders.
     *
     * @param  list<int>|null  $onlyStoreIds  stores to read, or null for the whole scope
     * @param  list<?string>|null  $onlyCurrencies  RAW recorded spellings to read, or null for all
     * @return list<array{store_id: int, currency: ?string, amount: float, orders: int}>
     */
    public function perStore(
        MetricScope $scope,
        MetricWindow $window,
        ?array $onlyStoreIds = null,
        ?array $onlyCurrencies = null,
    ): array {
        $query = $this->placedOrders($scope, $window);

        if ($onlyStoreIds !== null) {
            if ($onlyStoreIds === []) {
                throw new \InvalidArgumentException(
                    'perStore() was narrowed to an empty set of stores. An empty IN list compiles '
                    . 'to "0 = 1" and reports no orders, which is indistinguishable from stores '
                    . 'that took none; the caller should not have asked.'
                );
            }

            $query->whereIn('orders.store_id', array_map('intval', $onlyStoreIds));
        }

        if ($onlyCurrencies !== null) {
            $this->restrictToCurrencies($query, $onlyCurrencies);
        }

        $rows = $query
            ->selectRaw(
                'orders.store_id as metric_store, '
                . 'orders.currency_code as metric_currency, '
                . 'SUM(orders.final_price) as metric_amount, '
                . 'COUNT(*) as metric_rows'
            )
            ->groupBy('orders.store_id', 'orders.currency_code')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $currency = $row->metric_currency;

            $buckets[] = [
                'store_id' => (int) $row->metric_store,
                'currency' => ($currency === null || trim((string) $currency) === '') ? null : (string) $currency,
                'amount' => round((float) $row->metric_amount, 2),
                'orders' => (int) $row->metric_rows,
            ];
        }

        return $buckets;
    }

    /**
     * THE DISTINCT CURRENCY SPELLINGS RECORDED IN THE PERIOD -- one row per spelling, never per
     * store.
     *
     * The first of the bounded reads that replaced `perStore()` on the operator's landing page.
     * `perStore()` answers one row per (store, currency) pair for EVERY store in the scope, and
     * the operator's scope is every store on the platform (`MetricScope::stores($everyStoreId)`),
     * so that read, the `Store` name read and the `Setting` read all grew with the number of
     * tenants -- on the first page an operator sees. The panel it replaced ranked and limited in
     * SQL and returned five rows; the metric layer moved the ranking into PHP because the bucket
     * key is not the column (see `PlatformAnalyticsFigures::topStoresByOrderValue()`), and the
     * bound was lost in the move. These three methods give it back without giving up the exact
     * answer.
     *
     * RAW, EXACTLY AS STORED, and that is the point: the caller normalises with
     * `CurrencySymbol::code()` and so learns which spellings share a currency and which name no
     * currency at all. Normalising here -- or in SQL -- would put a second copy of that rule in a
     * second language, which is the defect class this codebase keeps paying for.
     *
     * @return list<?string> null is a row that recorded no currency; '' is one that recorded empty
     */
    public function distinctCurrencies(MetricScope $scope, MetricWindow $window): array
    {
        $rows = $this->placedOrders($scope, $window)
            ->distinct()
            ->pluck('orders.currency_code');

        $seen = [];
        $values = [];

        foreach ($rows as $value) {
            // `distinct()` is the engine's; a second pass costs nothing and makes the method's
            // contract true whatever a driver does with a NULL in a DISTINCT.
            $key = $value === null ? "\0null" : (string) $value;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $values[] = $value === null ? null : (string) $value;
        }

        return $values;
    }

    /**
     * ONE CURRENCY'S TOP STORES BY ORDER VALUE -- RANKED AND LIMITED BY THE DATABASE.
     *
     * `$rawCurrencies` is every recorded SPELLING that normalises to the one currency, so the sum
     * is complete for that currency and a store appears once: 'USD' and 'usd' are one currency and
     * are summed here rather than ranked against each other. That is why the caller passes a SET
     * and not a code -- a per-spelling `LIMIT 5` would be wrong, because a store sixth in two
     * spellings can be first once they are added.
     *
     * SUMMED ONCE AT FULL PRECISION, then rounded. `perStore()` rounds each (store, currency)
     * group and leaves the caller to add the groups up, which rounds twice; this does not.
     *
     * THE TIE-BREAK IS THE STORE ID, ASCENDING -- the same rule the caller applies in PHP, so the
     * two agree about which of two stores level on value takes the last place, and the list does
     * not reshuffle between requests.
     *
     * @param  list<?string>  $rawCurrencies  recorded spellings of ONE currency
     * @return list<array{store_id: int, amount: float, orders: int}> highest value first
     */
    public function topStoresForCurrencies(
        MetricScope $scope,
        MetricWindow $window,
        array $rawCurrencies,
        int $limit,
    ): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException(
                'topStoresForCurrencies() needs a limit of at least one; received ' . $limit . '.'
            );
        }

        $query = $this->placedOrders($scope, $window);
        $this->restrictToCurrencies($query, $rawCurrencies);

        $rows = $query
            ->selectRaw(
                'orders.store_id as metric_store, '
                . 'SUM(orders.final_price) as metric_amount, '
                . 'COUNT(*) as metric_rows'
            )
            ->groupBy('orders.store_id')
            ->orderByDesc('metric_amount')
            ->orderBy('orders.store_id')
            ->limit($limit)
            ->get();

        $ranked = [];

        foreach ($rows as $row) {
            $ranked[] = [
                'store_id' => (int) $row->metric_store,
                'amount' => round((float) $row->metric_amount, 2),
                'orders' => (int) $row->metric_rows,
            ];
        }

        return $ranked;
    }

    /**
     * THE RECORDED-SPELLING PREDICATE, including the null one.
     *
     * A null `currency_code` is a real case -- `test_an_order_with_no_recorded_currency_takes_its_
     * stores_own_currency` drives it -- and `whereIn` can never match it, because in SQL
     * `NULL IN (...)` is NULL. So the null member becomes `OR currency_code IS NULL`, grouped so
     * the `OR` cannot escape into the scope and window predicates beside it.
     *
     * AN EMPTY SET THROWS rather than matching everything. A closure that adds no predicate leaves
     * the query as it was, so this helper would silently widen a one-currency read to every
     * currency on the platform -- a cross-currency ranking, which is the exact defect the per-
     * currency panel exists to prevent, arriving as the absence of a clause.
     *
     * @param  list<?string>  $rawCurrencies
     */
    private function restrictToCurrencies(\Illuminate\Database\Query\Builder $query, array $rawCurrencies): void
    {
        $values = [];
        $includesNull = false;

        foreach ($rawCurrencies as $value) {
            if ($value === null) {
                $includesNull = true;

                continue;
            }

            $values[] = (string) $value;
        }

        if ($values === [] && ! $includesNull) {
            throw new \InvalidArgumentException(
                'A currency restriction was given no spellings. An empty set would add no '
                . 'predicate and so read EVERY currency, ranking amounts that are not comparable.'
            );
        }

        $query->where(function ($set) use ($values, $includesNull) {
            if ($values !== []) {
                $set->whereIn('orders.currency_code', $values);
            }

            if ($includesNull) {
                $set->orWhereNull('orders.currency_code');
            }
        });
    }

    /** The orders this metric counts: the scope, the window, and the cancellations choice. */
    private function placedOrders(MetricScope $scope, MetricWindow $window): \Illuminate\Database\Query\Builder
    {
        $query = $this->orders($scope);
        $window->applyTo($query, 'orders.order_date');

        if (! $this->includeCancelled) {
            /*
             * THE NULL LIMB IS NOT REACHABLE UNDER THE MIGRATION AS DECLARED, AND IS KEPT ANYWAY.
             *
             * Measured: `create_orders_table` declares `delivered_status` as
             * `integer()->default(0)` -- NOT NULL -- and SQLite rejects a null insert, so the
             * verification test for this limb could not even be written. It stays for two reasons.
             * CLAUDE.md's first schema rule is that THE MIGRATIONS ARE NOT THE SCHEMA: this file
             * has already been wrong about a column's presence in both directions, and nothing
             * here has measured the production `orders` table. And in SQL `NULL != 2` is NULL,
             * which is not true, so if the column ever is null -- in production, or through a left
             * join added later -- a plain inequality would silently drop those orders from a GMV
             * figure rather than fail. The limb costs one predicate; the failure it prevents is
             * invisible.
             */
            $query->where(function ($notCancelled) {
                $notCancelled->whereNull('orders.delivered_status')
                    ->orWhere('orders.delivered_status', '!=', 2);
            });
        }

        return $query;
    }
}
