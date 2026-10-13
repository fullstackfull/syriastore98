<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * GATE F CONTAINMENT. Complements, and does not duplicate,
 * MigrationHygieneTest::test_gate_f_provisional_drafts_are_not_discoverable_as_migrations.
 *
 * That test asks whether the DRAFT FILES can run. This one asks the opposite and, it
 * turns out, more easily violated question: has anyone created these six tables
 * SOMEWHERE ELSE?
 *
 * WHY THIS GUARD EXISTS. Six money-bearing tables -- store_wallets,
 * store_wallet_transactions, store_withdrawals, store_order_financials, currency_rates
 * and delivery_earnings -- are read and written by live code and created by no migration.
 * Their column types are not derivable from $fillable, so inventing them is FORBIDDEN.
 *
 * RE-MEASURED on p1e/agent5-qa at de8646b7 with
 * `vendor/bin/phpunit --testsuite Feature --display-skipped`, because the counts here were
 * stale: the suite now reports 17 skips, not 9. FIFTEEN of the 17 name one of the six
 * money tables -- FinancialSurfaceCoverageTest 7, CrossTenantModelIsolationTest 3,
 * ModelInitializationTest 3, FinancePermissionTest 1, WithdrawalAuthorizationTest 1. The
 * other TWO are a different undeclared-schema family and not the money six:
 * DeliverySyriaWebhookTest (`zid_orders`) and CustomerAccountBoundaryTest
 * (`newsletters.user_id`, an undeclared COLUMN rather than a table).
 *
 * The coverage gap GateFCoverageLedgerTest was written to record is now CLOSED on the
 * naming side: all six money tables are named by at least one skip, where previously only
 * three were. Every skip reason was read individually and all 17 still hold; none is
 * skipping for a reason that has lapsed.
 *
 * That makes those skips a standing temptation: a single Schema::create() anywhere in
 * either migration tree would turn fifteen reported gaps into fifteen green ticks while
 * freezing guessed column types into the schema. Worse, the existing migrations are
 * written with Schema::hasTable() guards, so once a wrong definition has created a table,
 * the later CORRECT migration becomes a silent no-op and the wrong types become permanent
 * and invisible. The drafts' own staging directory documents this.
 *
 * So the invariant is asserted directly: until the production schema dump arrives and
 * GATE F opens deliberately, these six tables must be created by NOTHING that Laravel can
 * discover, in EITHER migration tree (the root tree and the 69 addon modules under
 * packages/workdo).
 *
 * This is a pure filesystem test -- no application, no database, no connection -- so it
 * cannot be defeated by the state of a test database.
 *
 * WHEN GATE F OPENS, this test must be updated deliberately, in the same commit that adds
 * the real migrations, by removing the table from self::GATE_F_TABLES. Do not weaken the
 * assertion to make a new migration pass.
 */
class GateFSchemaContainmentTest extends TestCase
{
    /**
     * The six tables held behind GATE F. Narrower on purpose than the 15 undeclared
     * tables SCHEMA-01 lists: these are the money-bearing six whose drafts exist and
     * whose isolation tests skip on them.
     */
    private const GATE_F_TABLES = [
        'store_wallets',
        'store_wallet_transactions',
        'store_withdrawals',
        'store_order_financials',
        'currency_rates',
        'delivery_earnings',
    ];

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every migration file Laravel can discover, across both trees. Mirrors
     * MigrationHygieneTest::allMigrationFiles() so the two tests agree on what "a
     * migration" is.
     *
     * @return array<int, string>
     */
    private function discoverableMigrationFiles(): array
    {
        $base = $this->basePath();

        $files = glob($base . '/database/migrations/*.php') ?: [];

        foreach (glob($base . '/packages/workdo/*/src/database/migrations/*.php') ?: [] as $packageFile) {
            $files[] = $packageFile;
        }

        return $files;
    }

    public function test_the_migration_scan_is_not_vacuous(): void
    {
        // If either glob silently matched nothing, every assertion below would pass while
        // checking nothing at all.
        $this->assertGreaterThan(
            100,
            count(glob($this->basePath() . '/database/migrations/*.php') ?: []),
            'The root migration tree scan found almost nothing. The glob is wrong, so the '
            . 'containment assertions below would pass vacuously.'
        );
        $this->assertGreaterThan(
            200,
            count($this->discoverableMigrationFiles()),
            'The combined migration scan found too few files to be the real tree.'
        );
    }

    /**
     * THE PRIMARY ASSERTION. No discoverable migration, in either tree, may create any of
     * the six GATE F tables.
     */
    public function test_no_discoverable_migration_creates_a_gate_f_money_table(): void
    {
        $offenders = [];

        foreach ($this->discoverableMigrationFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::GATE_F_TABLES as $table) {
                // Match Schema::create / Schema::createIfNotExists on this exact table
                // name, in either quote style, tolerating whitespace. The anchor is the
                // closing quote, so store_wallets does not match store_wallets_archive.
                $pattern = '/Schema::\s*(?:connection\s*\([^)]*\)\s*->\s*)?create(?:IfNotExists)?\s*\(\s*[\'"]'
                    . preg_quote($table, '/')
                    . '[\'"]/i';

                if (preg_match($pattern, $source)) {
                    $offenders[] = $table . ' <- ' . str_replace($this->basePath() . '/', '', $file);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A discoverable migration creates a GATE F money table. Inventing these six\n"
            . "schemas is forbidden: their column types are not derivable from \$fillable,\n"
            . "and because the surrounding migrations use Schema::hasTable() guards, a\n"
            . "wrong definition that runs first makes the later CORRECT migration a silent\n"
            . "no-op and freezes the guessed types permanently. If GATE F has opened\n"
            . "deliberately, remove the table from self::GATE_F_TABLES in the same commit\n"
            . "that adds its real migration -- do not weaken this assertion:\n"
            . print_r($offenders, true)
        );
    }

    /**
     * The drafts must still be present and still be drafts. If someone "resolved" GATE F
     * by deleting the staging directory, the containment test above would start passing
     * for the wrong reason -- nothing creates the tables because nothing remembers they
     * are needed.
     */
    public function test_every_gate_f_table_still_has_a_parked_draft(): void
    {
        $pending = $this->basePath() . '/database/migrations/_gate_f_pending';

        $this->assertDirectoryExists(
            $pending,
            'The GATE F staging directory is gone. The six money tables are still '
            . 'undeclared, so losing the drafts loses the only record of what is owed.'
        );

        $drafts = glob($pending . '/*.php.draft') ?: [];

        $this->assertNotEmpty($drafts, 'The GATE F staging directory holds no drafts.');

        $blob = '';
        foreach ($drafts as $draft) {
            $blob .= (string) file_get_contents($draft);
        }

        foreach (self::GATE_F_TABLES as $table) {
            $this->assertStringContainsString(
                $table,
                $blob,
                "No parked draft mentions `{$table}`. Either the draft was deleted or the "
                . 'table was renamed; both need a deliberate decision, not silence.'
            );
        }
    }

    /**
     * The skips that report GATE F must keep NAMING the table and the reason. A skip with
     * no reason is indistinguishable from a disabled test, and these eight skips are the
     * project's only standing record that six money tables have no schema.
     *
     * Asserted against the suites that actually skip, which were measured from a real run
     * with --display-skipped rather than assumed.
     */
    public function test_the_gate_f_skips_still_state_their_table_and_their_reason(): void
    {
        $suites = [
            'tests/Feature/Security/FinancePermissionTest.php',
            'tests/Feature/Security/WithdrawalAuthorizationTest.php',
            'tests/Feature/Tenancy/CrossTenantModelIsolationTest.php',
            'tests/Feature/Tenancy/ModelInitializationTest.php',
        ];

        foreach ($suites as $relative) {
            $path = $this->basePath() . '/' . $relative;

            $this->assertFileExists(
                $path,
                "{$relative} is gone. It held GATE F skips that are the only record of an "
                . 'undeclared money table.'
            );

            $source = (string) file_get_contents($path);

            $this->assertStringContainsString(
                'markTestSkipped',
                $source,
                "{$relative} no longer skips anything. If its GATE F tables were made to "
                . 'pass, check that the schema was not invented.'
            );

            $this->assertMatchesRegularExpression(
                '/markTestSkipped\s*\(\s*\R?\s*[\'"]/',
                $source,
                "{$relative} calls markTestSkipped() without a literal reason string. A "
                . 'skip with no reason cannot be told apart from a quietly disabled test.'
            );

            $this->assertMatchesRegularExpression(
                '/GATE F|no migration|declared by no migration/i',
                $source,
                "{$relative} skips without naming the migration gap as the cause."
            );
        }
    }
}
