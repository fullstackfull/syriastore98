<?php

namespace Tests\Feature\Metrics;

use App\Support\Metrics\CancelledOrders;
use App\Support\Metrics\CollectedCustomerPayments;
use App\Support\Metrics\CompletedRefunds;
use App\Support\Metrics\CouponRedemption;
use App\Support\Metrics\CustomerLedgerMoney;
use App\Support\Metrics\GrossMerchandiseValue;
use App\Support\Metrics\MerchantWalletEntitlement;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\NetFinancialReceipts;
use App\Support\Metrics\ShippingAndTax;
use App\Support\Metrics\StoreRevenueMargin;
use App\Support\Metrics\UnpaidOrders;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * THE METRIC LAYER'S RAW-SQL PARAMETERS ARE A CLOSED SET.
 * ======================================================
 *
 * WHAT THIS CLOSES, AND WHAT IT DOES NOT. Nothing here fixes a live defect. A census of every
 * raw-SQL call site in `app/` -- inverted so that STATIC is the whitelist and anything else is
 * dynamic by default -- found **13** sites where the SQL TEXT is not a compile-time literal, and
 * **8** of those where the text arrives as a parameter of the enclosing method rather than as a
 * local. Seven of the eight are this layer. Every caller of all seven was read: each passes a
 * string literal, a class constant, or the output of another method in this layer that does the
 * same. **No request input reaches any of them.** This file exists because that is a property of
 * the current call sites and of nothing else -- the parameters are typed `string`, the
 * concatenation is unconditional, and the eighth site (`ApiController::search`, which
 * concatenates `$request->rating` straight into `whereRaw`) is what the same shape looks like
 * once somebody does pass input.
 *
 * THE SHAPE IS COPIED FROM `MerchantAnalyticsFigures::groupedVisits()`, which already had it:
 * a `private const` array of literals, a strict `in_array(..., true)` with a `throw`, and -- the
 * part that is easy to get wrong -- **the refusal placed before the first SQL CONSTRUCTION, not
 * merely before its execution**. `selectRaw()` does not run a query; it stores a string on the
 * builder. A guard that fired at `->get()` would already have put the attacker's text into the
 * statement. `test_the_refusal_precedes_the_first_sql_construction` is the measurement of that
 * ordering: it hands the method a fresh builder and asserts the builder is still untouched after
 * the throw.
 *
 * WHY THE AMOUNT SET IS NOT A LIST OF COLUMN NAMES. The two aggregators have 15 call sites
 * between them (13 of `sumByCurrency`, 2 of `sumByCustomerAndCurrency` -- a different 15 from the
 * dynamic sites above, and counted separately). THREE of those pass a whole `CASE WHEN ... END`
 * expression, the signed wallet movement. So a guard shaped "must look like `table.column`" would
 * refuse a legitimate caller, which is the worse defect of the two available here. The set is
 * therefore the exact expressions, verbatim.
 *
 * THE CONTROL CASES ARE THE POINT OF THIS FILE. A guard that refuses a real caller is worse than
 * no guard, so every guarded parameter has two of them:
 *
 *   1. `test_every_value_a_real_call_site_passes_is_admitted` reads the call sites out of the
 *      source (comments stripped -- a census that reads comments can be satisfied by prose about
 *      the thing it measures) and asserts the guard admits each. A NEW caller passing an
 *      unlisted expression fails here rather than at runtime on a merchant's finance page.
 *   2. `test_every_guarded_metric_still_produces_its_figure` drives all 13 real callers end to
 *      end against the real schema, so the guarded SQL is actually built and executed.
 *
 * Both reds under a mutation that empties the allowlist. That mutation is the one this file is
 * written against: it is the failure mode of an allowlist, and a test suite that stays green
 * through it is not testing the allowlist at all.
 */
class MetricRawSqlAllowlistTest extends TestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const STORE = 1;

    /**
     * Text that is a plausible column name and an injection at the same time.
     *
     * Not a nonsense string: the guard has to refuse something that would otherwise PARSE, or the
     * test passes on a value the SQL engine would have rejected anyway.
     */
    private const HOSTILE = "orders.currency_code UNION SELECT password FROM users -- ";

    /**
     * The one call site whose argument is not a literal, with what it resolves to.
     *
     * `ShippingAndTax::subMetric()` passes `self::COLUMNS[$subMetric]`. The source census below
     * cannot read that, so it is declared here WITH its resolved values and asserted admitted
     * like any other. The census also asserts that the number of call sites it could read plus
     * this manifest accounts for every call site, so a second unreadable argument fails rather
     * than disappearing.
     *
     * @var array<string, list<string>>
     */
    private const NON_LITERAL_CALL_SITES = [
        'ShippingAndTax::subMetric -- self::COLUMNS[$subMetric]' => [
            'store_order_financials.shipping_amount',
            'store_order_financials.tax_amount',
        ],
    ];

    // ---------------------------------------------------------------- refusals

    public function test_sum_by_currency_refuses_a_currency_column_outside_the_closed_set(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->callSumByCurrency(DB::table('orders'), self::HOSTILE, 'orders.final_price');
    }

    public function test_sum_by_currency_refuses_an_amount_expression_outside_the_closed_set(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->callSumByCurrency(DB::table('orders'), 'orders.currency_code', self::HOSTILE);
    }

    public function test_sum_by_customer_and_currency_refuses_a_currency_column_outside_the_closed_set(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->callSumByCustomerAndCurrency(
            DB::table('orders'),
            self::HOSTILE,
            'store_order_financials.paid_amount'
        );
    }

    public function test_sum_by_customer_and_currency_refuses_an_amount_expression_outside_the_closed_set(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->callSumByCustomerAndCurrency(
            DB::table('orders'),
            'store_order_financials.currency_code',
            self::HOSTILE
        );
    }

    public function test_cancelled_orders_refuses_a_date_expression_outside_the_closed_set(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $method = new ReflectionMethod(CancelledOrders::class, 'fallbackDatedCount');
        $method->invoke(
            new CancelledOrders(),
            MetricScope::store(self::STORE),
            MetricWindow::allTime(),
            self::HOSTILE
        );
    }

    public function test_unpaid_orders_refuses_a_raw_column_expression_outside_the_closed_set(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $method = new ReflectionMethod(UnpaidOrders::class, 'rawColumn');
        $method->invoke(new UnpaidOrders(), self::HOSTILE);
    }

    public function test_normalised_payment_type_sql_refuses_a_column_outside_the_closed_set(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $method = new ReflectionMethod(UnpaidOrders::class, 'normalisedPaymentTypeSql');
        $method->invoke(new UnpaidOrders(), self::HOSTILE);
    }

    /**
     * Every refusal names the value it refused.
     *
     * A guard whose message does not carry the offending text sends whoever hits it to read the
     * allowlist instead of the call site, and the two are in different files.
     */
    public function test_each_refusal_names_the_value_it_refused(): void
    {
        $refusals = [
            'Metric::sumByCurrency (currency column)' => fn () => $this->callSumByCurrency(
                DB::table('orders'), self::HOSTILE, 'orders.final_price'
            ),
            'Metric::sumByCurrency (amount expression)' => fn () => $this->callSumByCurrency(
                DB::table('orders'), 'orders.currency_code', self::HOSTILE
            ),
            'CustomerLedgerMoney::sumByCustomerAndCurrency (currency column)' => fn () => $this->callSumByCustomerAndCurrency(
                DB::table('orders'), self::HOSTILE, 'store_order_financials.paid_amount'
            ),
            'CustomerLedgerMoney::sumByCustomerAndCurrency (amount expression)' => fn () => $this->callSumByCustomerAndCurrency(
                DB::table('orders'), 'store_order_financials.currency_code', self::HOSTILE
            ),
            'CancelledOrders::fallbackDatedCount' => fn () => (new ReflectionMethod(
                CancelledOrders::class, 'fallbackDatedCount'
            ))->invoke(
                new CancelledOrders(), MetricScope::store(self::STORE), MetricWindow::allTime(), self::HOSTILE
            ),
            'UnpaidOrders::rawColumn' => fn () => (new ReflectionMethod(
                UnpaidOrders::class, 'rawColumn'
            ))->invoke(new UnpaidOrders(), self::HOSTILE),
            'Metric::normalisedPaymentTypeSql' => fn () => (new ReflectionMethod(
                UnpaidOrders::class, 'normalisedPaymentTypeSql'
            ))->invoke(new UnpaidOrders(), self::HOSTILE),
        ];

        foreach ($refusals as $what => $call) {
            try {
                $call();
                $this->fail($what . ': the guard admitted an expression that is not in its allowlist');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    self::HOSTILE,
                    $e->getMessage(),
                    $what . ': the refusal does not name the value it refused'
                );
            }
        }
    }

    /**
     * THE ORDERING THAT MATTERS.
     *
     * `selectRaw()` stores a string on the builder; `groupBy(DB::raw(...))` stores an expression.
     * Neither runs anything. So a guard that fired on execution would be too late -- the text
     * would already be in the statement, and whatever ran it next (a `count()`, a clone, a
     * `toSql()` in a log line) would carry it.
     *
     * The measurement is the builder itself: `columns` and `groups` are both still null after the
     * throw, which they could not be if either call had been reached.
     */
    public function test_the_refusal_precedes_the_first_sql_construction(): void
    {
        foreach (['sumByCurrency', 'sumByCustomerAndCurrency'] as $method) {
            $query = DB::table('orders');

            $this->assertNull($query->columns, 'premise: a fresh builder selects nothing yet');
            $this->assertNull($query->groups, 'premise: a fresh builder groups by nothing yet');

            try {
                $method === 'sumByCurrency'
                    ? $this->callSumByCurrency($query, self::HOSTILE, 'orders.final_price')
                    : $this->callSumByCustomerAndCurrency($query, self::HOSTILE, 'store_order_financials.paid_amount');

                $this->fail($method . ': expected a refusal');
            } catch (InvalidArgumentException) {
                // the point of the test is what the builder looks like now
            }

            $this->assertNull(
                $query->columns,
                $method . ': the refusal came AFTER selectRaw() -- the refused text is already on the builder'
            );
            $this->assertNull(
                $query->groups,
                $method . ': the refusal came AFTER groupBy() -- the refused text is already on the builder'
            );
        }
    }

    // ---------------------------------------------------------------- controls

    /**
     * CONTROL 1 -- every value a real call site passes is admitted.
     *
     * Read out of the source with comments stripped, because this layer's docblocks quote the
     * very expressions being searched for: `Metric`'s own docblock contains
     * `store_order_financials.currency_code` in prose, and a census that could not tell those
     * apart would pass with every call site deleted.
     */
    public function test_every_value_a_real_call_site_passes_is_admitted(): void
    {
        $readable = 0;
        $total = 0;

        foreach ($this->metricSources() as $relative) {
            $code = $this->sourceWithoutComments($relative);

            foreach (['sumByCurrency', 'sumByCustomerAndCurrency'] as $sink) {
                $total += preg_match_all('/->' . $sink . '\(/', $code);
            }

            /*
             * Argument 0 is the builder, 1 the currency column, 2 the amount expression. Only a
             * call whose 1 and 2 are both literal concatenations is readable here; the count
             * assertion below is what stops an unreadable one from going unnoticed.
             *
             * THE LITERAL SUB-PATTERN TRACKS ITS OWN QUOTE. A first draft used `[^\'"]*`, which
             * cannot read the signed-movement expression -- a DOUBLE-quoted literal containing
             * `= \'credit\'` -- so three of the fifteen call sites fell into the unreadable
             * bucket and would have had to be declared by hand. Reading them is better than
             * exempting them.
             */
            $literal = '(?:(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\.?\s*)+';

            $pattern = '/->(?:sumByCurrency|sumByCustomerAndCurrency)\(\s*'
                . '[^,]+,\s*'
                . '(' . $literal . '),\s*'
                . '(' . $literal . ')\s*,?\s*\)/';

            if (! preg_match_all($pattern, $code, $calls, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($calls as $call) {
                $readable++;

                $currency = $this->joinLiterals($call[1]);
                $amount = $this->joinLiterals($call[2]);

                $this->assertSumByCurrencyAdmits($currency, $amount, $relative);
            }
        }

        $this->assertGreaterThanOrEqual(
            12,
            $total,
            'premise: the census found too few call sites to mean anything -- it is probably not reading the layer'
        );

        $this->assertSame(
            $total,
            $readable + count(self::NON_LITERAL_CALL_SITES),
            'a call site passes an argument this census cannot read, so the value it concatenates into raw '
            . 'SQL is invisible here: add it to NON_LITERAL_CALL_SITES with what it resolves to'
        );

        foreach (self::NON_LITERAL_CALL_SITES as $where => $resolved) {
            foreach ($resolved as $amount) {
                $this->assertSumByCurrencyAdmits('store_order_financials.currency_code', $amount, $where);
            }
        }
    }

    /**
     * THE CENSUS ABOVE READS ONE DIRECTORY, so this asserts that is where the callers are.
     *
     * `sumByCurrency()` is `protected`, so only a subclass of `Metric` can reach it, and every
     * subclass is in `app/Support/Metrics/` today -- but that is a fact about today, and a census
     * scoped to a directory reads exactly like one that looked everywhere and found nothing. A
     * caller added outside it would pass an expression no control case ever sees.
     *
     * Scanned over the whole of `app/` and `packages/` rather than reasoned from the keyword:
     * `protected` is not a guarantee about WHERE a subclass lives.
     */
    public function test_the_census_covers_every_place_a_caller_could_be(): void
    {
        $stray = [];

        foreach (['app', 'packages'] as $tree) {
            $directory = new \RecursiveDirectoryIterator(
                base_path($tree),
                \FilesystemIterator::SKIP_DOTS
            );

            foreach (new \RecursiveIteratorIterator($directory) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(base_path() . '/', '', $file->getPathname());

                if (str_starts_with($relative, 'app/Support/Metrics/')) {
                    continue;
                }

                $needle = '/->(?:sumByCurrency|sumByCustomerAndCurrency)\(|extends\s+Metric\b/';

                /*
                 * THE RAW TEXT FIRST, as a cheap superset. Comments are still in it, so a hit may
                 * be prose -- the stripper below settles that. Filtering here is not only speed:
                 * `sourceWithoutComments()` asserts that stripping did not empty the file, and
                 * somewhere under `packages/` is a .php file that is nothing but a comment, which
                 * fired that assertion and failed this test for a reason that had nothing to do
                 * with metrics.
                 */
                if (! preg_match($needle, (string) file_get_contents($file->getPathname()))) {
                    continue;
                }

                if (preg_match($needle, $this->sourceWithoutComments($relative))) {
                    $stray[] = $relative;
                }
            }
        }

        $this->assertSame(
            [],
            $stray,
            'a metric, or a call to one of the two guarded aggregators, lives outside '
            . 'app/Support/Metrics/ -- so test_every_value_a_real_call_site_passes_is_admitted no '
            . 'longer reads every call site. Widen its glob before relying on it again.'
        );
    }

    /**
     * CONTROL 2 -- the single-value guards admit the value their real caller passes.
     *
     * Each is the only value in the repository, so each is also the whole of its allowlist. They
     * are asserted separately from the figures below because a figure of 0.00 on an empty table
     * and a refusal are both "no money", and only one of them is a defect.
     */
    public function test_the_single_value_guards_admit_their_real_callers_value(): void
    {
        $normalised = (new ReflectionMethod(UnpaidOrders::class, 'normalisedPaymentTypeSql'))
            ->invoke(new UnpaidOrders(), 'orders.payment_type');

        $this->assertSame(
            'LOWER(TRIM(orders.payment_type))',
            $normalised,
            'normalisedPaymentTypeSql no longer produces what UnpaidOrders feeds to rawColumn()'
        );

        $expression = (new ReflectionMethod(UnpaidOrders::class, 'rawColumn'))
            ->invoke(new UnpaidOrders(), $normalised);

        /*
         * `Expression` has no `__toString` on Laravel 11 -- a `(string)` cast raises -- so the
         * value comes back through the grammar, which is also how the builder reads it.
         */
        $this->assertSame(
            $normalised,
            $expression->getValue(DB::connection()->getQueryGrammar()),
            'rawColumn() refused or altered the one expression its real caller passes'
        );

        $count = (new ReflectionMethod(CancelledOrders::class, 'fallbackDatedCount'))->invoke(
            new CancelledOrders(),
            MetricScope::store(self::STORE),
            MetricWindow::allTime(),
            'COALESCE(orders.cancel_date, store_order_financials.updated_at)'
        );

        $this->assertSame(
            0,
            $count,
            'fallbackDatedCount() refused the date expression CancelledOrders::for() passes it'
        );
    }

    /**
     * CONTROL 3 -- all 13 real callers, driven end to end.
     *
     * The tables are empty, so every figure is zero; that is deliberate. What is being measured
     * is that the guarded SQL was BUILT and EXECUTED against the real schema -- an engine error
     * or a refusal both fail here, and the figures themselves are asserted with real data by
     * `MetricLayerTest`, which this does not duplicate.
     */
    public function test_every_guarded_metric_still_produces_its_figure(): void
    {
        $scope = MetricScope::store(self::STORE);
        $window = MetricWindow::allTime();

        $single = [
            // currency column: orders.currency_code
            GrossMerchandiseValue::class,
            CouponRedemption::class,
            // currency column: store_order_financials.currency_code
            CancelledOrders::class,
            CollectedCustomerPayments::class,
            StoreRevenueMargin::class,
            // currency column: store_wallet_transactions.currency_code
            CompletedRefunds::class,
            // currency column: store_wallets.currency_code
            MerchantWalletEntitlement::class,
        ];

        foreach ($single as $class) {
            $result = (new $class())->for($scope, $window);

            $this->assertSame(
                0,
                $result->rowCount(),
                $class . ': the metric did not reach its own query'
            );
        }

        // Three sub-metrics, each one a separate guarded read.
        foreach ((new UnpaidOrders())->all($scope, $window) as $subMetric => $result) {
            $this->assertSame(0, $result->rowCount(), 'UnpaidOrders.' . $subMetric);
        }

        // The `self::COLUMNS[$subMetric]` call site.
        foreach ((new ShippingAndTax())->all($scope, $window) as $subMetric => $result) {
            $this->assertSame(0, $result->rowCount(), 'ShippingAndTax.' . $subMetric);
        }

        // The signed `CASE WHEN ... END` movement expression, on both of its classes.
        $receipts = (new NetFinancialReceipts())->reconcile($scope, $window);
        $this->assertIsArray($receipts, 'NetFinancialReceipts: the reconciliation did not run');

        $ledger = (new CustomerLedgerMoney())->netRetainedForCustomers($scope, $window, [1]);
        $this->assertIsArray($ledger, 'CustomerLedgerMoney: the per-customer read did not run');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The question here is the ALLOWLIST DECISION, not whether the SQL is valid.
     *
     * These values are qualified column names from four different tables, and some pair a
     * currency column from one table with an amount from a joined one. Driven against a bare
     * `DB::table('orders')` most of them are perfectly good SQL about columns that are not in
     * scope, so the engine refuses them -- which is the engine having been REACHED, i.e. the
     * allowlist having admitted the value. That is the whole of what this control asserts; the
     * real queries, with their real joins, are driven by
     * `test_every_guarded_metric_still_produces_its_figure`.
     *
     * So only a refusal FROM THE GUARD fails here, and it is identified by its message naming the
     * value -- not merely by its class, which would also catch an `InvalidArgumentException`
     * raised for some unrelated reason and read it as this one.
     */
    private function assertSumByCurrencyAdmits(string $currency, string $amount, string $where): void
    {
        try {
            $this->callSumByCurrency(DB::table('orders'), $currency, $amount);
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), $currency) || str_contains($e->getMessage(), $amount)) {
                $this->fail(
                    $where . ': the allowlist refuses a value a real call site passes -- ' . $e->getMessage()
                );
            }

            throw $e;
        } catch (\Throwable) {
            // The engine was reached, so the guard admitted the value. See the docblock.
        }

        $this->addToAssertionCount(1);
    }

    /** @return array{byCurrency: array<string, float>, rowCount: int} */
    private function callSumByCurrency(Builder $query, string $currency, string $amount): array
    {
        $method = new ReflectionMethod(GrossMerchandiseValue::class, 'sumByCurrency');

        return $method->invoke(new GrossMerchandiseValue(), $query, $currency, $amount);
    }

    /** @return array<int, array{byCurrency: array<string, float>, rowCount: int}> */
    private function callSumByCustomerAndCurrency(Builder $query, string $currency, string $amount): array
    {
        $method = new ReflectionMethod(CustomerLedgerMoney::class, 'sumByCustomerAndCurrency');

        return $method->invoke(new CustomerLedgerMoney(), $query, $currency, $amount);
    }

    /**
     * `'a ' . 'b'` as PHP would have joined it, via PHP's own tokenizer.
     *
     * NOT A SPLIT ON `.`, and not a pattern either. Splitting on the dot took the table off the
     * front of every qualified column name -- `store_order_financials.currency_code` became
     * `store_order_financialurrency_code`, which SQLite then rejected as an unknown column (a
     * first draft of this helper, caught by its own red run). A pattern then has to track which
     * quote opened each literal, because the signed-movement expression is double-quoted and
     * contains single ones. The tokenizer knows both without being told.
     */
    private function joinLiterals(string $expression): string
    {
        $out = '';

        foreach (token_get_all('<?php ' . $expression . ';') as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out .= substr($token[1], 1, -1);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function metricSources(): array
    {
        $out = [];

        foreach (glob(base_path('app/Support/Metrics/*.php')) ?: [] as $path) {
            $out[] = 'app/Support/Metrics/' . basename($path);
        }

        sort($out);

        return $out;
    }
}
