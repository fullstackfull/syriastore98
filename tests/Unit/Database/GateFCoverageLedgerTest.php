<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * GATE F COVERAGE LEDGER. Closes the coverage gap reported last phase, and corrects the
 * way it was stated.
 *
 * THE GAP, AS MEASURED HERE rather than assumed. A full run
 *     vendor/bin/phpunit --testsuite Feature --display-skipped
 * reports 9 skips. EIGHT of them are GATE F; the ninth is `zid_orders`, which is a
 * different undeclared table and not one of the money six. Those eight skips name only
 * THREE distinct money tables:
 *
 *     store_wallets              -- skipped in FinancePermissionTest,
 *                                   WithdrawalAuthorizationTest,
 *                                   CrossTenantModelIsolationTest, ModelInitializationTest
 *     store_wallet_transactions  -- skipped in CrossTenantModelIsolationTest,
 *                                   ModelInitializationTest
 *     store_withdrawals          -- skipped in CrossTenantModelIsolationTest,
 *                                   ModelInitializationTest
 *
 * The other three money tables produce NO SKIP AT ALL, so the suite's skip count makes
 * GATE F look better covered than it is. That is the finding, and it is the opposite way
 * round from how it was recorded last phase (which named the three ALREADY-covered tables
 * as the gap). Per table, with the reason each is invisible:
 *
 *     delivery_earnings        tests/Feature/Security/OrderMutationTest.php:160-173 reads
 *                              Schema::hasTable('delivery_earnings') into $hasEarnings and
 *                              wraps its financial-record assertion in `if ($hasEarnings)`.
 *                              The surrounding authorization assertions DO run, and that
 *                              trade-off is deliberate and argued in the comment at :147
 *                              -- it is not a defect. But the consequence is that the
 *                              financial assertion never executes and NOTHING reports it,
 *                              so it cannot be seen in the skip count.
 *     store_order_financials    Referenced only by a comment
 *                              (FinancePermissionTest.php:323) and by a tripwire that
 *                              asserts the table does NOT exist
 *                              (TenantModelClassificationTest.php:362). No behavioural
 *                              test, despite 7 live call sites in
 *                              app/Services/OrderFinancialService.php and
 *                              app/Http/Controllers/StoreFinanceController.php:137.
 *     currency_rates            Referenced only as a static model-to-table mapping
 *                              (TenantModelClassificationTest.php:341). No behavioural
 *                              test of any kind, despite live call sites in
 *                              app/Services/StoreCurrencyConversionService.php:57,147 and
 *                              four in app/Http/Controllers/Api/DashboardController.php.
 *
 * WHAT THIS TEST IS FOR. It does not invent coverage and it does not create the tables --
 * GateFSchemaContainmentTest forbids that and this test must never be used to get round
 * it. It makes the gap EXPLICIT and RATCHETED, so that:
 *   - the uncovered set cannot silently grow;
 *   - all six tables are proven to be live code paths, so none can be waved away as dead
 *     code that needs no schema;
 *   - when GATE F opens and real coverage lands, whoever lands it is forced to move the
 *     table between the two lists here, which keeps the ledger honest.
 *
 * Pure filesystem test: no application, no database, no connection.
 */
class GateFCoverageLedgerTest extends TestCase
{
    /**
     * GATE F money tables for which the suite reports a SKIP naming the table. Measured
     * from --display-skipped, not assumed.
     */
    private const SKIP_REPORTED = [
        'store_wallets',
        'store_wallet_transactions',
        'store_withdrawals',
    ];

    /**
     * GATE F money tables with NO reported skip. This is the coverage gap. A table leaves
     * this list only when it gains a test that actually reports its absence.
     */
    private const NO_REPORTED_SKIP = [
        'store_order_financials',
        'currency_rates',
        'delivery_earnings',
    ];

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Count live, non-test call sites for a table: a DB::table()/from() on the literal, or
     * an Eloquent model whose $table is that literal together with at least one static use
     * of that model class outside its own file.
     *
     * @return list<string>
     */
    private function liveCallSites(string $table): array
    {
        $hits = [];

        $roots = [$this->basePath() . '/app'];
        $files = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }

        // Model classes bound to this table, so Eloquent usage counts as a call site.
        $models = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match('/\$table\s*=\s*[\'"]' . preg_quote($table, '/') . '[\'"]/', $src)) {
                $models[] = basename($file, '.php');
            }
        }

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $rel = str_replace($this->basePath() . '/', '', $file);

            // Direct query-builder access on the literal table name.
            if (preg_match('/(?:DB::|->)\s*(?:table|from)\s*\(\s*[\'"]' . preg_quote($table, '/') . '[\'"]/', $src)) {
                $hits[] = $rel . ' (query builder)';
            }

            foreach ($models as $model) {
                if (basename($file, '.php') === $model) {
                    continue; // the model's own file is a declaration, not a call site
                }
                if (preg_match('/\b' . preg_quote($model, '/') . '\s*::/', $src)) {
                    $hits[] = $rel . ' (' . $model . '::)';
                }
            }
        }

        return array_values(array_unique($hits));
    }

    public function test_the_ledger_covers_exactly_the_six_gate_f_money_tables(): void
    {
        $all = array_merge(self::SKIP_REPORTED, self::NO_REPORTED_SKIP);

        sort($all);
        $expected = [
            'currency_rates',
            'delivery_earnings',
            'store_order_financials',
            'store_wallet_transactions',
            'store_wallets',
            'store_withdrawals',
        ];

        $this->assertSame(
            $expected,
            $all,
            'The ledger no longer partitions exactly the six GATE F money tables. It must '
            . 'stay in step with GateFSchemaContainmentTest::GATE_F_TABLES -- do not drop a '
            . 'table from the ledger to make the gap look smaller.'
        );

        $this->assertSame(
            [],
            array_intersect(self::SKIP_REPORTED, self::NO_REPORTED_SKIP),
            'A table is recorded as both skip-reported and not-skip-reported.'
        );
    }

    /**
     * THE GAP ITSELF, ratcheted. Three of the six money tables have no reported skip. If
     * that number grows, coverage of GATE F has quietly fallen.
     */
    public function test_the_uncovered_set_has_not_grown(): void
    {
        $this->assertLessThanOrEqual(
            3,
            count(self::NO_REPORTED_SKIP),
            'More GATE F money tables now have no reported skip than when this gap was '
            . 'measured. Coverage of the gate has fallen.'
        );

        $this->assertCount(
            3,
            self::SKIP_REPORTED,
            'A GATE F money table stopped reporting its skip. Check it was not made to '
            . 'pass by inventing a schema -- see GateFSchemaContainmentTest.'
        );
    }

    /**
     * Every one of the six is REAL EXPOSURE, not dead code. This is what makes the missing
     * schema matter: live code reads and writes all six, so an absent table is a runtime
     * failure waiting on a code path, not a tidy-up.
     */
    public function test_every_gate_f_table_is_reachable_from_live_application_code(): void
    {
        foreach (array_merge(self::SKIP_REPORTED, self::NO_REPORTED_SKIP) as $table) {
            $sites = $this->liveCallSites($table);

            $this->assertNotEmpty(
                $sites,
                "No live call site was found for `{$table}`. Either the scanner below is "
                . 'broken or the table really is dead code -- the second would be a reason '
                . 'to retire it from GATE F deliberately, not to leave this test failing.'
            );
        }
    }

    /**
     * Non-vacuity. The scanner must find call sites that were read off the tree by hand,
     * or every assertion above passes while checking nothing.
     */
    public function test_the_call_site_scanner_is_not_vacuous(): void
    {
        $financials = $this->liveCallSites('store_order_financials');
        $this->assertNotEmpty($financials);
        $this->assertTrue(
            (bool) preg_grep('#app/Services/OrderFinancialService\.php#', $financials),
            'The scanner missed app/Services/OrderFinancialService.php, which contains '
            . "DB::table('store_order_financials') at lines 28, 90, 228, 272, 350 and 519."
        );

        $rates = $this->liveCallSites('currency_rates');
        $this->assertNotEmpty(
            $rates,
            'The scanner missed currency_rates, which is reached through the CurrencyRate '
            . 'model from app/Services/StoreCurrencyConversionService.php:57,147.'
        );
    }

    /**
     * Pins the one case that is invisible rather than absent: OrderMutationTest guards its
     * delivery_earnings assertion with Schema::hasTable() and reports no skip, so the
     * assertion silently does not run. That is a deliberate, argued trade-off -- see the
     * comment at OrderMutationTest.php:147 -- and this test does NOT ask for it to change.
     *
     * It asserts only that the pattern is still there. When GATE F opens and the guard
     * becomes unnecessary, this test fails, and whoever removes it must move
     * delivery_earnings out of NO_REPORTED_SKIP in the same commit.
     */
    public function test_the_hidden_delivery_earnings_assertion_is_still_recorded_as_hidden(): void
    {
        $path = $this->basePath() . '/tests/Feature/Security/OrderMutationTest.php';

        if (! is_file($path)) {
            $this->markTestSkipped(
                'tests/Feature/Security/OrderMutationTest.php is absent from this worktree, '
                . 'so the conditional delivery_earnings assertion cannot be checked. That '
                . 'file is owned by another agent.'
            );
        }

        $src = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/Schema::hasTable\(\s*[\'"]delivery_earnings[\'"]\s*\)/',
            $src,
            'OrderMutationTest no longer guards delivery_earnings with Schema::hasTable(). '
            . 'If the table now exists, confirm its schema was not invented '
            . '(GateFSchemaContainmentTest) and move delivery_earnings out of '
            . 'self::NO_REPORTED_SKIP here.'
        );

        $this->assertStringContainsString(
            'if ($hasEarnings)',
            $src,
            'The conditional that hides the delivery_earnings financial assertion has '
            . 'changed shape. Re-measure which GATE F tables report a skip and update the '
            . 'ledger in this file.'
        );
    }
}
