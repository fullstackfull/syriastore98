<?php

namespace App\Support\Metrics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * THE BASE OF THE PHASE 05 METRIC LAYER.
 *
 * `PHASE-05-EXECUTION-PLAN.md` P5-2: one query object per metric, each with its tenant predicate
 * at the call site, its currency handling and its caveat flag, and **no controller computes money
 * inline**. `ReportController` is 3,660 lines containing zero `payment_status` predicates, which
 * is how every money figure it produces came to count orders nobody paid for; this layer exists so
 * that the predicates live in one reviewable place with the reasoning attached.
 *
 * WHY THE FOUR TABLE ACCESSORS BELOW ARE THE ONLY RAW QUERIES IN THIS NAMESPACE.
 *
 * Each one applies `whereIn('store_id', ...)` in the SAME expression that names the table, so a
 * subclass cannot obtain an unscoped builder even by accident. That matters more here than
 * anywhere else in the application: `Order` is not in `config/tenancy.php`'s `scoped_models`, so
 * no global scope will add `store_id` for any principal, and `store_order_financials` has no
 * Eloquent model at all. A forgotten predicate on these tables does not error and does not return
 * nothing -- it returns the whole platform's money under one merchant's heading.
 *
 * `order_coupon_details` is reached by JOINING from `orders` rather than queried directly,
 * because -- measured -- that table has NO `store_id` column. Its tenant predicate can only come
 * from the order it belongs to.
 *
 * ALL FOUR ARE `final`, AND THAT KEYWORD IS THE CONSTRAINT. Without it "a subclass cannot obtain
 * an unscoped builder" would be a convention: an eleventh metric could override `orders()` and
 * return `DB::table('orders')`. Review found exactly that gap between what this docblock claimed
 * and what the code enforced. The census ratchet would still have caught that particular shape --
 * a new unscoped `DB::table('orders')` moves the unscoped half of its per-table row -- but a
 * backstop is not the same as an impossibility.
 */
abstract class Metric
{
    /** A stable machine key for this metric. Never translated; reports branch on it. */
    abstract public function key(): string;

    /** The section of `PHASE-05-METRIC-DEFINITIONS.md` this metric implements. */
    abstract public function definition(): string;

    /*
     * THERE IS DELIBERATELY NO `abstract for()` HERE.
     *
     * Section 3 -- unpaid orders -- is "three sub-metrics, never one. Collapsing them is how a COD
     * book looks like bad debt." A base class that required every metric to produce ONE figure
     * would make collapsing them the path of least resistance, and the comment forbidding it would
     * be the only thing standing in the way. Instead the shape is in the type: a metric that
     * yields one figure implements `SingleFigureMetric`, a metric that must not be collapsed
     * implements `MultiFigureMetric`, and `UnpaidOrders` has no `for()` to call.
     */

    /**
     * `orders` -- the SETTLEMENT record: what the customer was asked for.
     *
     * FLOAT columns (`final_price`, `delivery_price`, `tax_price`), so any figure taken from here
     * carries MetricCaveat::FLOAT_PRECISION. Prefer `financials()` wherever both hold the amount.
     */
    final protected function orders(MetricScope $scope): Builder
    {
        return DB::table('orders')->whereIn('orders.store_id', $scope->storeIds());
    }

    /**
     * `store_order_financials` -- the ENTITLEMENT record: what the merchant is owed.
     *
     * DECIMAL(15,2) throughout and carries its own `currency_code`. This is the preferred source
     * for every money figure that exists on both tables.
     */
    final protected function financials(MetricScope $scope): Builder
    {
        return DB::table('store_order_financials')
            ->whereIn('store_order_financials.store_id', $scope->storeIds());
    }

    /**
     * `store_wallet_transactions` -- the MOVEMENT record: what actually moved, typed and reasoned,
     * written exactly once by a locked idempotent path. Self-reconciling, and therefore the
     * preferred computation for net receipts (section 6).
     */
    final protected function transactions(MetricScope $scope): Builder
    {
        return DB::table('store_wallet_transactions')
            ->whereIn('store_wallet_transactions.store_id', $scope->storeIds());
    }

    /** `store_wallets` -- the running entitlement balance. Not a period figure; see section 7. */
    final protected function wallets(MetricScope $scope): Builder
    {
        return DB::table('store_wallets')->whereIn('store_wallets.store_id', $scope->storeIds());
    }

    /**
     * Aggregate an amount per currency in one query.
     *
     * Returns the per-currency sums and the row count together, because a metric that reports
     * 0.00 needs to be able to say whether that is "no rows" or "rows that summed to nothing" --
     * a distinction the finance screens have got wrong before.
     *
     * ONE COLLATION NOTE, because the two engines disagree and only one direction is safe.
     * The GROUP BY is on the raw `currency_code`. Under MySQL's `utf8mb4_general_ci` -- the
     * measured production collation -- 'USD' and 'usd' collapse into ONE group; under the test
     * suite's SQLite they are two, which would raise a spurious MIXED_CURRENCY and make `total()`
     * throw. Production is the forgiving side, so the risk is a misleading TEST, never a wrong
     * figure for a merchant. Recorded rather than normalised, because `UPPER()` here would defeat
     * the index on a column this layer groups by on every call.
     *
     * @return array{byCurrency: array<string, float>, rowCount: int}
     */
    protected function sumByCurrency(
        Builder $query,
        string $currencyColumn,
        string $amountExpression,
    ): array {
        $rows = $query
            ->selectRaw(
                $currencyColumn . ' as metric_currency, '
                . 'SUM(' . $amountExpression . ') as metric_amount, '
                . 'COUNT(*) as metric_rows'
            )
            ->groupBy(DB::raw($currencyColumn))
            ->get();

        $byCurrency = [];
        $rowCount = 0;

        foreach ($rows as $row) {
            $currency = $row->metric_currency;
            // The SAME constant MetricResult::make() buckets under, so the two cannot drift --
            // a mismatch here would hide the unrecorded-currency rows from
            // assumingUnknownCurrencyIs() and silently drop them from every tile.
            $key = ($currency === null || $currency === '')
                ? MetricResult::UNKNOWN_CURRENCY
                : (string) $currency;

            $byCurrency[$key] = round(($byCurrency[$key] ?? 0.0) + (float) $row->metric_amount, 2);
            $rowCount += (int) $row->metric_rows;
        }

        return ['byCurrency' => $byCurrency, 'rowCount' => $rowCount];
    }

    /**
     * The SQL that normalises a free-text payment type for comparison.
     *
     * `PaymentVerificationService::isCashOnDelivery()` compares against
     * `strtolower(trim($paymentType))`, and the stored values are not consistently cased. Matching
     * the raw column against a lowercase list would miss "COD" and "Cash On Delivery", both of
     * which are in this database. Reproduced in SQL rather than re-invented.
     */
    protected function normalisedPaymentTypeSql(string $column): string
    {
        return 'LOWER(TRIM(' . $column . '))';
    }
}
