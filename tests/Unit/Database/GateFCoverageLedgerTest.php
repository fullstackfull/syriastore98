<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * GATE F COVERAGE LEDGER. Closes the coverage gap reported last phase, and corrects the
 * way it was stated.
 *
 * THE GAP, AS MEASURED HERE rather than assumed. A full run
 *     vendor/bin/phpunit --testsuite Feature --display-skipped
 * reported 9 skips WHEN THIS WAS WRITTEN. RE-MEASURED on p1e/agent5-qa at de8646b7 it
 * reports 17, and the split is 15 + 2: fifteen name one of the six money tables, and two
 * belong to a different undeclared-schema family -- `zid_orders`
 * (DeliverySyriaWebhookTest) and `newsletters.user_id` (CustomerAccountBoundaryTest, an
 * undeclared COLUMN rather than a table).
 *
 * THE NAMING GAP THIS FILE RECORDS IS NOW CLOSED, and that is the point of leaving the
 * history visible: all six money tables are named by at least one skip today, where only
 * the three below were when this ledger was written. The tests here are kept because they
 * are what makes that true rather than incidental -- they fail if any of the six stops
 * being named. The three originally covered were:
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
     * THE SIX GATE F MONEY TABLES.
     *
     * THIS USED TO BE TWO LISTS -- `SKIP_REPORTED` (three tables whose absence the suite reported
     * as a skip) and `NO_REPORTED_SKIP` (three whose absence was invisible) -- and this class was a
     * ratchet on that COVERAGE GAP. GATE F closed on 2026-11-02 and the gap closed with it: all six
     * are declared by migrations whose structure was measured against a restored copy of
     * production, so no table is absent and none can report a skip.
     *
     * The partition is therefore gone and the class is INVERTED, not deleted -- the same treatment
     * GateFSchemaContainmentTest got, for the same reason. What it ratchets now is the property
     * that replaced the gap, below.
     */
    private const MONEY_TABLES = [
        'currency_rates',
        'delivery_earnings',
        'store_order_financials',
        'store_wallet_transactions',
        'store_wallets',
        'store_withdrawals',
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
        $expected = [
            'currency_rates',
            'delivery_earnings',
            'store_order_financials',
            'store_wallet_transactions',
            'store_wallets',
            'store_withdrawals',
        ];

        $actual = self::MONEY_TABLES;
        sort($actual);

        $this->assertSame($expected, $actual, 'The ledger no longer names exactly the six GATE F '
            . 'money tables.');

        // AND IT MUST AGREE WITH THE MEASURED SCHEMA, not merely with itself. Two hand-maintained
        // lists that drift apart are how this project has been bitten before.
        $measured = \App\Support\Database\GateFProductionSchema::MONEY_TABLES;
        sort($measured);

        $this->assertSame($expected, $measured, 'App\\Support\\Database\\GateFProductionSchema'
            . '::MONEY_TABLES and this ledger disagree about which tables are money tables. The '
            . 'measured schema is authoritative; fix the ledger, not the measurement.');
    }

    /**
     * THE INVERTED RATCHET, AND THE ONE THAT FOUND SOMETHING.
     *
     * No test may BRANCH on whether a GATE F money table exists, and none may CREATE one. It may
     * assert that the table is there -- `assertTrue(Schema::hasTable(...))` -- and that is the only
     * permitted shape.
     *
     * WHY THIS IS THE PROPERTY. While the tables were absent, eleven test files carried
     * `if (! Schema::hasTable('<money table>')) { Schema::create(...); }` so they could run at all.
     * Those blocks declared COLUMNS ONLY -- none of production's unique keys, none of its foreign
     * keys. GATE F made every one of them dead (the migration runs first), which is benign today
     * and a loaded gun tomorrow: remove or rename one migration and the suite goes GREEN against a
     * fabricated money schema. GateFSchemaContainmentTest cannot see them, because a
     * `Schema::create()` inside a test is not a discoverable migration -- so this is the only place
     * the property can live.
     *
     * Measured on 2026-11-02: 15 such sites across 11 files, 12 of them inside a fallback that
     * built the table. All removed in the same change that inverted this test.
     *
     * Comments are stripped before the scan, because several of those files now EXPLAIN in prose
     * what they no longer do -- the third time a ratchet in this repository has had to learn that.
     */
    public function test_no_test_creates_or_branches_on_a_gate_f_money_table(): void
    {
        $offenders = [];

        foreach ($this->testFiles() as $file) {
            $code = $this->codeOnly($file);
            $rel = str_replace($this->basePath() . '/', '', $file);

            if (str_ends_with($rel, 'tests/Unit/Database/GateFCoverageLedgerTest.php')) {
                continue; // this file names the tables in order to forbid them
            }

            foreach (self::MONEY_TABLES as $table) {
                $quoted = preg_quote($table, '/');

                if (preg_match_all('/Schema::create\(\s*[\'"]' . $quoted . '[\'"]/', $code, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as $hit) {
                        $offenders[] = $rel . ':' . $this->lineOf($code, $hit[1]) . ' CREATES ' . $table;
                    }
                }

                if (! preg_match_all('/Schema::hasTable\(\s*[\'"]' . $quoted . '[\'"]\s*\)/', $code, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($m[0] as $hit) {
                    // THE STATEMENT, NOT THE LINE. The permitted shape is a multi-line
                    // assertTrue(...) whose `assert` sits on an earlier line than the hasTable()
                    // call, so a line-local test reports every correct call as an offender -- which
                    // is exactly what the first version of this scan did, on all fifteen sites.
                    $statement = $this->statementBefore($code, $hit[1]);

                    if (preg_match('/\bassert[A-Za-z]*\s*\(/', $statement)) {
                        continue;
                    }

                    $offenders[] = $rel . ':' . $this->lineOf($code, $hit[1]) . ' BRANCHES ON ' . $table;
                }
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, 'A test creates a GATE F money table, or branches on '
            . 'whether one exists. Neither is allowed since GATE F closed on 2026-11-02. A '
            . 'Schema::create() in a test is NOT a discoverable migration: it carries none of '
            . 'production\'s unique keys or foreign keys, so it would make this suite green '
            . 'against a fabricated money schema if the real migration were ever reverted. And a '
            . 'branch on absence is dead code whose condition can no longer be false, which reads '
            . 'as coverage being deliberately skipped. Assert presence instead: '
            . 'assertTrue(Schema::hasTable(...)) with a message naming the migration.');
    }

    /**
     * NON-VACUITY for the scan above, because an empty file list would satisfy it silently.
     */
    public function test_the_money_table_scan_reads_the_suite(): void
    {
        $files = $this->testFiles();

        $this->assertGreaterThan(400, count($files), 'the scanner found almost no test files');

        $hits = 0;

        foreach ($files as $file) {
            if (str_contains($this->codeOnly($file), 'delivery_earnings')) {
                $hits++;
            }
        }

        $this->assertGreaterThan(
            3,
            $hits,
            'the scanner reads no test file mentioning delivery_earnings, so the assertion above '
            . 'is passing over an empty set'
        );
    }

    private function lineOf(string $code, int $offset): int
    {
        return substr_count(substr($code, 0, $offset), "\n") + 1;
    }

    /**
     * The text from the end of the previous statement or block to $offset -- i.e. the statement the
     * match sits inside. `assert` anywhere in it means the call is an assertion about presence;
     * otherwise it is a branch.
     */
    private function statementBefore(string $code, int $offset): string
    {
        $start = 0;

        foreach ([';', '{', '}'] as $boundary) {
            $found = strrpos(substr($code, 0, $offset), $boundary);

            if ($found !== false && $found > $start) {
                $start = $found;
            }
        }

        return substr($code, $start, $offset - $start);
    }

    /** @return list<string> */
    private function testFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath() . '/tests', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** PHP source with every comment blanked, so prose about a pattern is not the pattern. */
    private function codeOnly(string $file): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * Every one of the six is REAL EXPOSURE, not dead code. This is what makes the missing
     * schema matter: live code reads and writes all six, so an absent table is a runtime
     * failure waiting on a code path, not a tidy-up.
     */
    public function test_every_gate_f_table_is_reachable_from_live_application_code(): void
    {
        foreach (self::MONEY_TABLES as $table) {
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
     * THE INVERSE OF WHAT THIS TEST USED TO ASSERT, and it is the reason the previous commit's Unit
     * suite went red.
     *
     * It used to pin that `OrderMutationTest` STILL hid its `delivery_earnings` assertion behind
     * `Schema::hasTable()`, and its own docblock set the exit condition: *"When GATE F opens and the
     * guard becomes unnecessary, this test fails, and whoever removes it must move
     * delivery_earnings out of NO_REPORTED_SKIP in the same commit."* GATE F opened, the guard was
     * removed, and this test failed exactly as designed -- which is the whole value of having
     * written the exit condition down.
     *
     * So it now asserts the opposite: the guard is GONE and the financial assertion runs for every
     * caller. The sibling in `OrderAssignPermissionTest` is checked too, because it cited this one
     * as its precedent and carried the same wrapper.
     */
    public function test_the_delivery_earnings_assertions_are_no_longer_hidden(): void
    {
        foreach ([
            'tests/Feature/Security/OrderMutationTest.php',
            'tests/Feature/Security/OrderAssignPermissionTest.php',
        ] as $rel) {
            $path = $this->basePath() . '/' . $rel;

            $this->assertFileExists($path);

            $code = $this->codeOnly($path);

            $this->assertDoesNotMatchRegularExpression(
                '/if\s*\([^)]*Schema::hasTable\(\s*[\'"]delivery_earnings[\'"]/',
                $code,
                $rel . ' guards an assertion on whether `delivery_earnings` exists again. GATE F '
                . 'declared it on 2026-11-02, so that condition can no longer be false and the '
                . 'wrapper reads as coverage being skipped.'
            );

            $this->assertStringNotContainsString(
                'if ($hasEarnings)',
                $code,
                $rel . ' hides its delivery_earnings assertion behind $hasEarnings again.'
            );

            // ONLY OrderMutationTest asserted on the earnings ROWS. OrderAssignPermissionTest's
            // hidden assertion was about `orders.deliveryboy_id`, so requiring a DeliveryEarning
            // reference there would be asserting something that was never true -- which the first
            // version of this test did, and which its own failure caught.
            if (str_ends_with($rel, 'OrderMutationTest.php')) {
                $this->assertStringContainsString(
                    'DeliveryEarning',
                    $code,
                    $rel . ' no longer references DeliveryEarning at all, so the assertion this '
                    . 'test exists to protect has been deleted rather than made unconditional.'
                );
            } else {
                $this->assertStringContainsString(
                    'deliveryboy_id',
                    $code,
                    $rel . ' no longer asserts the assignment completed, so the assertion that was '
                    . 'hidden behind the hasTable() guard has been deleted rather than freed.'
                );
            }
        }
    }
}
