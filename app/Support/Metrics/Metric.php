<?php

namespace App\Support\Metrics;

use App\Support\Money\CurrencySymbol;
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
 * WHY THE FOUR TABLE ACCESSORS BELOW ARE THE ONLY RAW QUERIES IN THIS NAMESPACE **THAT A METRIC CAN REACH**. There is one
 * other, deliberately outside this class: `LedgerCoverage` (P5-16) reads `orders` joined to the ledger to find where the
 * ledger can be trusted from, which none of these accessors can express (an anti-join). It is NOT protected by
 * construction: its store predicate is hand-written, once on `orders` and once on each side of the join, and is pinned by
 * `LedgerCoverageTest` (the foreign-store tests) and by section J of the MariaDB script. Anything that adds a sixth must say
 * so here.
 *
 * THE FOUR ACCESSORS, AND WHY THEY EXIST.
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
     * The bucket key for a stored currency value -- THE SAME ONE `MetricResult::make()` USES.
     *
     * P5-R9 / FIN-SYM-3. The two sites that built this key by hand both carried a comment saying
     * it was "the SAME constant `MetricResult::make()` buckets under, so the two cannot drift",
     * and both only matched for NULL and `''`. For every other value they kept the raw column
     * text, so `usd` and `USD` were two keys here and one key after `make()`.
     *
     * FOR TWELVE OF THE THIRTEEN CALL SITES THAT WAS INERT, not merely invisible: they hand the
     * raw array straight to `make()`, which applies this same function, so the composite key never
     * differed. `NetFinancialReceipts` is the exception -- it COMPARES two raw arrays and
     * PUBLISHES one (`reconcile()`, and the `status_based_by_currency` note). Measured there by
     * restoring the old inline rule, on §6's own named dataset with only the spelling varied:
     *
     *   {"movement":{"usd":200},"status":{"USD":200},
     *    "divergence":{"usd":200,"USD":-200},"agrees":false}
     *
     * -- so §6:187's "disagreement is a reconciliation defect" fired on a dataset that agreed.
     * Spelling the FINANCIALS column instead splits the status leg against itself,
     * `{"USD":350,"usd":-150}`: two of its three terms arrive through `MetricResult` already
     * normalised and the third does not. (350, not 260: §2 admits `cancelled`, `refunded` and
     * `partially_paid` rows with `paid_amount > 0`, so its collected term is 440 on that dataset,
     * not 200. The 260 an earlier draft of this docblock carried was arithmetic, not a reading.)
     *
     * WHAT THIS DID **NOT** DO: warn a merchant. `NetFinancialReceipts` has no caller anywhere in
     * `app/` -- the only repository hits outside the class are prose in three docblocks, this one
     * included. The defect was that the cross-check the definitions document mandates contradicted
     * that document's own currency rule; no shipped surface read the result. Said here because an
     * earlier draft claimed the merchant-visible version, which is not established.
     *
     * `CurrencySymbol::code()` is called rather than re-implemented: it is this application's one
     * definition of "is a currency code", so a stored SYMBOL is not a code and joins `unknown`
     * rather than becoming its own currency. Note what that definition actually tests -- `code()`
     * runs on `clean()`'s output, which strips `< > " '`, backticks and control characters FIRST,
     * so `<US"D>`, `"USD"` and a NUL-interrupted `USD` all normalise to `USD`. That is pre-existing
     * `make()` behaviour, not something introduced here, and it is why this says "what `code()`
     * accepts" rather than "three ASCII letters".
     *
     * NOTHING IS CONVERTED HERE -- two values that normalise alike are one currency, which is
     * currency identity, not a rate.
     */
    protected static function currencyBucketKey($stored): string
    {
        // No `(string)` cast: `clean()` already returns '' for anything that is not
        // string|int|float, whereas a cast would raise on an array and throw on an object with no
        // __toString. `MetricResult::make()` carries the cast; this does not need it.
        $code = CurrencySymbol::code($stored);

        return $code === '' ? MetricResult::UNKNOWN_CURRENCY : $code;
    }

    /**
     * Aggregate an amount per currency in one query.
     *
     * Returns the per-currency sums and the row count together, because a metric that reports
     * 0.00 needs to be able to say whether that is "no rows" or "rows that summed to nothing" --
     * a distinction the finance screens have got wrong before.
     *
     * ONE COLLATION NOTE, CORRECTED 2026-11-24. The GROUP BY is on the raw `currency_code`. Under
     * MySQL's `utf8mb4_general_ci` -- the measured production collation -- 'USD' and 'usd' collapse
     * into ONE group; under the test suite's SQLite they are two. An earlier version of this note
     * said the SQLite split "would raise a spurious MIXED_CURRENCY and make `total()` throw", so
     * that the only risk was a misleading test. THAT WAS NEVER TRUE: `make()` has folded case
     * since FIN-SYM, so a spelling split could not reach a caveat or a total -- and now
     * `currencyBucketKey()` folds it here too, so this layer's keys agree whatever either engine
     * does with the grouping. `UPPER()` is still not pushed into the SQL, because it would defeat
     * the index on a column this layer groups by on every call.
     *
     * @return array{byCurrency: array<string, float>, rowCount: int}
     */
    protected function sumByCurrency(
        Builder $query,
        string $currencyColumn,
        string $amountExpression,
    ): array {
        /*
         * BEFORE THE FIRST SQL CONSTRUCTION, not before its execution. `selectRaw()` does not run
         * anything -- it stores a string on the builder -- so a guard placed after it would have
         * already put the refused text into the statement, where a `count()`, a clone or a
         * `toSql()` in a log line would carry it. See `assertCurrencyColumn()` at the foot of
         * this class.
         */
        self::assertCurrencyColumn($currencyColumn);
        self::assertAmountExpression($amountExpression);

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
            $key = self::currencyBucketKey($row->metric_currency);

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
        self::assertPaymentTypeColumn($column);

        return 'LOWER(TRIM(' . $column . '))';
    }

    /*
     * ======================================================================================
     * THE CLOSED SETS -- every value this layer may concatenate into raw SQL.
     * ======================================================================================
     *
     * WHAT THESE DO AND DO NOT CLOSE. They fix no live defect. A census of every raw-SQL call
     * site in `app/`, inverted so that STATIC is the whitelist and anything else counts as
     * dynamic, found 13 sites where the SQL TEXT is not a compile-time literal and 8 where that
     * text arrives as a PARAMETER rather than as a local. (13, not the 15 the first pass of that
     * census reported: the two extra were `HostClaims`' `DB::raw(1)`, a NUMERIC literal, which the
     * inverted rule calls dynamic until numbers are added to the whitelist beside strings.)
     * Seven of the eight are this layer, and
     * every caller of all seven was read: each passes a string literal, a class constant, or the
     * output of another method here that does the same. No request input reaches any of them
     * today. The eighth -- `ApiController::search()`, which concatenates `$request->rating`
     * straight into `whereRaw` -- is what this shape looks like once somebody does pass input,
     * and is why the property is written down instead of left as a property of the call sites.
     *
     * THE SHAPE IS `MerchantAnalyticsFigures::groupedVisits()`'s, which already had it: a closed
     * array of literals, a strict `in_array(..., true)`, a `throw`, and the refusal placed before
     * the first SQL construction. `ShippingAndTax::subMetric()` and `UnpaidOrders::subMetric()`
     * carry the same shape over their sub-metric keys, so it is already this layer's house style.
     *
     * WHY THEY ARE AT THE FOOT OF THE CLASS. Two documents cite absolute line numbers in this
     * file, both below line 101, and an insertion above a citation invalidates it whatever its
     * size. Declaring these here instead of above `key()` moves nothing. A class constant is
     * legal anywhere in the body.
     *
     * ADDING A METRIC MEANS ADDING ITS EXPRESSION HERE, and that is the point rather than a cost:
     * the set is small, it is reviewable in one place, and `MetricRawSqlAllowlistTest` reads every
     * call site out of the source and fails if one passes something not listed -- so the omission
     * surfaces in the suite rather than on a merchant's finance page.
     */

    /**
     * The currency columns a metric may GROUP BY.
     *
     * One per source table, and they are the same four tables the accessors above name. A fifth
     * entry would therefore be a currency column on a table this class does not reach -- which is
     * the thing the class docblock asks to be declared there, so declare it in both places.
     */
    private const CURRENCY_COLUMNS = [
        'orders.currency_code',
        'store_order_financials.currency_code',
        'store_wallet_transactions.currency_code',
        'store_wallets.currency_code',
    ];

    /**
     * The amount expressions a metric may SUM.
     *
     * NOT A LIST OF COLUMN NAMES, and a guard shaped "must look like `table.column`" would be
     * wrong rather than merely strict: the last entry is the signed wallet movement, a whole
     * `CASE WHEN ... END` passed by `NetFinancialReceipts` and by
     * `CustomerLedgerMoney::netRetainedForCustomers()`. Refusing a legitimate caller is the worse
     * of the two defects available here, so the set is the exact expressions, verbatim.
     *
     * The two `shipping_amount` / `tax_amount` entries arrive through
     * `ShippingAndTax::COLUMNS[$subMetric]` rather than as literals at the call site -- that
     * lookup is itself guarded by an `array_key_exists` over a closed const map.
     */
    private const AMOUNT_EXPRESSIONS = [
        'order_coupon_details.coupon_discount_amount',
        'orders.final_price',
        'store_order_financials.customer_total',
        'store_order_financials.gross_profit',
        'store_order_financials.paid_amount',
        'store_order_financials.remaining_amount',
        'store_order_financials.shipping_amount',
        'store_order_financials.tax_amount',
        'store_wallet_transactions.amount',
        'store_wallets.available_balance',
        "CASE WHEN store_wallet_transactions.direction = 'credit' "
        . 'THEN store_wallet_transactions.amount '
        . 'ELSE -store_wallet_transactions.amount END',
    ];

    /** The columns `normalisedPaymentTypeSql()` may wrap. One caller, one column. */
    private const PAYMENT_TYPE_COLUMNS = [
        'orders.payment_type',
    ];

    /**
     * `\InvalidArgumentException` is written out rather than imported, for the same reason the
     * constants are down here: a `use` line is an insertion above every cited line in the file.
     */
    final protected static function assertCurrencyColumn(string $column): void
    {
        if (! in_array($column, self::CURRENCY_COLUMNS, true)) {
            throw new \InvalidArgumentException(
                'A metric cannot group money by "' . $column . '". That text would be '
                . 'concatenated into raw SQL, so the column is chosen from a closed set: '
                . implode(', ', self::CURRENCY_COLUMNS) . '.'
            );
        }
    }

    final protected static function assertAmountExpression(string $expression): void
    {
        if (! in_array($expression, self::AMOUNT_EXPRESSIONS, true)) {
            throw new \InvalidArgumentException(
                'A metric cannot sum "' . $expression . '". That text would be concatenated into '
                . 'raw SQL, so the expression is chosen from a closed set of '
                . count(self::AMOUNT_EXPRESSIONS) . ' recorded in Metric::AMOUNT_EXPRESSIONS.'
            );
        }
    }

    final protected static function assertPaymentTypeColumn(string $column): void
    {
        if (! in_array($column, self::PAYMENT_TYPE_COLUMNS, true)) {
            throw new \InvalidArgumentException(
                'A metric cannot normalise the payment type of "' . $column . '". That text would '
                . 'be concatenated into raw SQL, so the column is chosen from a closed set: '
                . implode(', ', self::PAYMENT_TYPE_COLUMNS) . '.'
            );
        }
    }
}
