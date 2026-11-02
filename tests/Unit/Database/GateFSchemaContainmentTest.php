<?php

namespace Tests\Unit\Database;

use App\Support\Database\GateFProductionSchema;
use PHPUnit\Framework\TestCase;

/**
 * GATE F -- THE RATCHET, INVERTED ON 2026-11-02 BECAUSE GATE F CLOSED.
 *
 * ================================================================================================
 *  WHAT THIS FILE USED TO ASSERT, AND WHY IT NO LONGER DOES.
 * ================================================================================================
 *
 * Until 2026-11-02 this class asserted the OPPOSITE of what it asserts now: that **no discoverable
 * migration creates any of the six GATE F money tables**. The reason was good. Their column types
 * were not derivable from `$fillable`, so any migration for them would have been a GUESS -- and
 * because the surrounding migrations use `Schema::hasTable()` guards, a guess that ran first would
 * make the later CORRECT migration a silent no-op and freeze the wrong types permanently and
 * invisibly.
 *
 * Its own docblock set the exit condition, verbatim:
 *
 *   > *"WHEN GATE F OPENS, this test must be updated deliberately, in the same commit that adds
 *   > the real migrations, by removing the table from self::GATE_F_TABLES. Do not weaken the
 *   > assertion to make a new migration pass."*
 *
 * That is what happened, and this is that commit. The types are no longer guesses: they were
 * MEASURED from `information_schema` on a restored copy of the production dump on 2026-10-24 and
 * recorded in `docs/GATE-F-VERIFIED-SCHEMA.md` section 2, which
 * `App\Support\Database\GateFProductionSchema` copies verbatim.
 *
 * ================================================================================================
 *  WHY THE FILE WAS NOT SIMPLY DELETED.
 * ================================================================================================
 *
 * Emptying `GATE_F_TABLES` would have left `test_no_discoverable_migration_creates_a_gate_f_money_table`
 * iterating over nothing and passing vacuously -- which is how a ratchet becomes decoration. So
 * the direction is reversed instead, and the new properties are strictly stronger than the old one:
 *
 *   1. every money table is created by EXACTLY ONE discoverable migration (so a second, competing
 *      definition is caught -- the original hazard, now in the only form it can still take);
 *   2. every GATE F migration verifies the EXISTING STRUCTURE and not merely the table's name;
 *   3. no GATE F migration's `down()` drops anything;
 *   4. the provisional drafts are still parked and still marked superseded;
 *   5. the GATE F skip reasons are GONE from the suites that carried them, so reintroducing one
 *      fails here rather than quietly reopening a declared hole.
 *
 * This remains a pure filesystem test -- no application, no database, no connection -- so it cannot
 * be defeated by the state of a test database.
 */
class GateFSchemaContainmentTest extends TestCase
{
    /**
     * The six money-bearing tables. Narrower on purpose than the 15 SCHEMA-01 tables: these are
     * the ones whose decimal precision is the thing that must not be guessed.
     */
    private const GATE_F_TABLES = [
        'store_wallets',
        'store_wallet_transactions',
        'store_withdrawals',
        'store_order_financials',
        'currency_rates',
        'delivery_earnings',
    ];

    /** The suites whose GATE F skips were the project's standing record of the gap. */
    private const FORMERLY_SKIPPING_SUITES = [
        'tests/Feature/Commerce/FinancialSurfaceCoverageTest.php',
        'tests/Feature/Security/FinancePermissionTest.php',
        'tests/Feature/Security/WithdrawalAuthorizationTest.php',
        'tests/Feature/Tenancy/CrossTenantModelIsolationTest.php',
        'tests/Feature/Tenancy/ModelInitializationTest.php',
    ];

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every migration file Laravel can discover, across both trees. Mirrors
     * MigrationHygieneTest::allMigrationFiles() so the two tests agree on what "a migration" is.
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
            . 'assertions below would pass vacuously.'
        );
        $this->assertGreaterThan(
            200,
            count($this->discoverableMigrationFiles()),
            'The combined migration scan found too few files to be the real tree.'
        );
    }

    /**
     * THE PRIMARY ASSERTION, INVERTED. Each money table is created by EXACTLY ONE discoverable
     * migration.
     *
     * "Exactly one" rather than "at least one" is deliberate: the original hazard was a second,
     * competing definition with guessed types running first and making the correct one a no-op.
     * That hazard has not gone away -- it has only changed shape, because the correct definition
     * now exists and a duplicate would still shadow it.
     */
    public function test_every_gate_f_money_table_is_created_by_exactly_one_discoverable_migration(): void
    {
        $creators = array_fill_keys(self::GATE_F_TABLES, []);

        foreach ($this->discoverableMigrationFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::GATE_F_TABLES as $table) {
                // Schema::create / createIfNotExists on this exact table name, either quote style,
                // tolerating whitespace. The anchor is the closing quote, so `store_wallets` does
                // not match `store_wallets_archive`.
                $pattern = '/Schema::\s*(?:connection\s*\([^)]*\)\s*->\s*)?create(?:IfNotExists)?\s*\(\s*[\'"]'
                    . preg_quote($table, '/')
                    . '[\'"]/i';

                if (preg_match($pattern, $source)) {
                    $creators[$table][] = str_replace($this->basePath() . '/', '', $file);
                }
            }
        }

        foreach (self::GATE_F_TABLES as $table) {
            $this->assertCount(
                1,
                $creators[$table],
                "`{$table}` must be created by exactly one discoverable migration, and is created "
                . 'by ' . count($creators[$table]) . ": \n  "
                . (implode("\n  ", $creators[$table]) ?: '(nothing)') . "\n\n"
                . (count($creators[$table]) === 0
                    ? 'ZERO means the GATE F migration has been reverted or renamed. The table '
                        . 'exists in production with live data, so a fresh environment would no '
                        . 'longer get it and the declared-skip set in '
                        . '.github/known-test-outcomes.json no longer matches reality.'
                    : 'MORE THAN ONE is the original GATE F hazard in the only form it can still '
                        . 'take: whichever runs first creates the table, the other becomes a '
                        . 'hasTable() no-op, and if the loser is the measured one the wrong types '
                        . 'become permanent and invisible. Delete the duplicate; do not reorder it.')
            );
        }
    }

    /**
     * THE DIRECTIVE'S OWN REQUIREMENT, RATCHETED: `Schema::hasTable()` alone is not proof of safety.
     *
     * All 15 tables already exist in production, so every GATE F migration must be a no-op there.
     * A bare `hasTable()` early return achieves that and proves nothing: it answers "is there
     * something by this name", not "is skipping the CREATE safe". If a table with the wrong types
     * were present, that guard would return early, `migrate:status` would report the migration as
     * "Ran", and the wrong schema would become permanent AND invisible -- the migration history
     * turned into a false record.
     *
     * So each of the 15 must call `GateFSchemaGuard::assertCompatible()`, which compares the live
     * structure against the measured one and throws, naming every difference.
     */
    public function test_every_gate_f_migration_verifies_the_existing_structure_not_only_its_name(): void
    {
        $missing = [];

        foreach (GateFProductionSchema::tableNames() as $table) {
            $files = $this->migrationsCreating($table);

            if ($files === []) {
                $missing[] = "{$table}: no discoverable migration creates it at all";

                continue;
            }

            foreach ($files as $relative) {
                $source = (string) file_get_contents($this->basePath() . '/' . $relative);

                if (! str_contains($source, 'GateFSchemaGuard::assertCompatible')) {
                    $missing[] = "{$relative} guards on the table's NAME but never calls "
                        . 'GateFSchemaGuard::assertCompatible()';
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "A GATE F migration skips an existing table without verifying its structure:\n  "
            . implode("\n  ", $missing)
            . "\n\nAdd `GateFSchemaGuard::assertCompatible('<table>');` inside the hasTable() "
            . 'branch. Do not delete this assertion to make a migration pass.'
        );
    }

    /**
     * No GATE F migration may drop anything in `down()`.
     *
     * MEASURED, NOT REASONED. Two of the original drafts guarded `up()` on `hasColumn()` --
     * returning early when the column already existed -- and then dropped the column in `down()`
     * BECAUSE it existed. Run against a copy of production, `migrate:rollback` executed them in
     * 30.28ms and 46.82ms and destroyed all 45 `products.cost_price` values (summing 1170.65) and
     * both `delivery_boys` commission columns with their data, having created nothing.
     *
     * Every one of the 15 tables exists in production WITH LIVE DATA, so a `down()` that drops one
     * is a destructive production operation wearing a routine command's clothes.
     */
    public function test_no_gate_f_migration_drops_anything_on_rollback(): void
    {
        $destructive = [];

        foreach (GateFProductionSchema::tableNames() as $table) {
            foreach ($this->migrationsCreating($table) as $relative) {
                $source = (string) file_get_contents($this->basePath() . '/' . $relative);

                // Comments explaining what the down() deliberately does NOT do mention these
                // names, so comments are stripped before the check -- the same trap that has
                // caught two other ratchets in this repository.
                $code = $this->codeOnly($source);

                foreach (['dropIfExists', 'Schema::drop', 'dropColumn', 'truncate'] as $needle) {
                    if (str_contains($code, $needle)) {
                        $destructive[] = "{$relative} calls {$needle}()";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $destructive,
            "A GATE F migration can destroy production data on rollback:\n  "
            . implode("\n  ", $destructive)
            . "\n\nEvery one of these tables holds live merchant data. Leave down() a documented "
            . 'no-op and drop the table by hand, on purpose, with a backup.'
        );
    }

    /**
     * The provisional drafts must still be parked, and must still be drafts.
     *
     * KEPT AFTER GATE F CLOSED, for two reasons. `MigrationHygieneTest::test_gate_f_provisional_drafts_are_not_discoverable_as_migrations`
     * asserts the directory is non-empty, so deleting it breaks a different ratchet. And the drafts
     * are the record of what the definitions looked like BEFORE they were measured -- 22 money-scale
     * contradictions, four enums turned into `varchar(255)`, zero of production's 3 unique keys and
     * 12 foreign keys. That record is why `GateFProductionSchema` is generated from a measurement
     * rather than written by hand, and it should not be thrown away now that it is embarrassing.
     */
    public function test_every_gate_f_table_still_has_a_parked_draft(): void
    {
        $pending = $this->basePath() . '/database/migrations/_gate_f_pending';

        $this->assertDirectoryExists(
            $pending,
            'The GATE F staging directory is gone. It is the only record of what the definitions '
            . 'looked like before they were measured against production.'
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

        $this->assertFileExists(
            $pending . '/README.md',
            'The staging directory has no README, so nothing explains that these drafts are '
            . 'SUPERSEDED by the promoted migrations rather than still owed.'
        );

        $this->assertStringContainsString(
            'SUPERSEDED',
            (string) file_get_contents($pending . '/README.md'),
            'The staging README does not say the drafts are SUPERSEDED. A reader who finds a '
            . 'provisional money-table definition with no such note may promote it over the '
            . 'measured one.'
        );
    }

    /**
     * THE SKIP RATCHET, ALSO INVERTED: the GATE F skip reasons must now be GONE.
     *
     * This test used to assert that these suites still contained `markTestSkipped` with a GATE F
     * reason naming its blocking table -- because those skips were the project's only standing
     * record that six money tables had no schema.
     *
     * They are not a record any more, they would be a lie: `.github/known-test-outcomes.json`
     * asserts its declared outcome set in BOTH directions, so a declared skip that stops skipping
     * fails CI until the entry is deleted. The sixteen GATE F entries were deleted when these
     * bodies were written. Reintroducing a GATE F skip would reopen a hole that no longer has a
     * cause, so it fails here.
     *
     * THE ONE THAT STAYS, and why. `CustomerAccountBoundaryTest` still skips on
     * `newsletters.user_id`, which is SCHEMA-01 but NOT one of the 15 GATE F tables: it is an
     * undeclared COLUMN whose type was never measured, so declaring it would be exactly the guess
     * GATE F exists to forbid. That suite is therefore not in the list below.
     */
    public function test_the_gate_f_skip_reasons_are_gone_from_the_suites_that_carried_them(): void
    {
        $reopened = [];

        foreach (self::FORMERLY_SKIPPING_SUITES as $relative) {
            $path = $this->basePath() . '/' . $relative;

            $this->assertFileExists(
                $path,
                "{$relative} is gone. It held the GATE F cases that are now the verification of "
                . 'the money surface, so deleting it removes coverage rather than a skip.'
            );

            $code = $this->codeOnly((string) file_get_contents($path));

            // A skip whose reason names GATE F, in the shape these suites used.
            if (preg_match('/markTestSkipped\s*\(\s*[^)]{0,400}GATE F/i', $code)) {
                $reopened[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $reopened,
            "A GATE F skip has been reintroduced in:\n  " . implode("\n  ", $reopened)
            . "\n\nGATE F closed on 2026-11-02: the fifteen tables have measured migrations and "
            . 'the sixteen declared skips were deleted from .github/known-test-outcomes.json. A '
            . 'new GATE F skip would be an undeclared skip, which fails the known-outcome gate -- '
            . 'and it would claim a blocker that no longer exists.'
        );
    }

    /**
     * Relative paths of the discoverable migrations that create `$table`.
     *
     * @return list<string>
     */
    private function migrationsCreating(string $table): array
    {
        $out = [];

        $pattern = '/Schema::\s*(?:connection\s*\([^)]*\)\s*->\s*)?create(?:IfNotExists)?\s*\(\s*[\'"]'
            . preg_quote($table, '/')
            . '[\'"]/i';

        foreach ($this->discoverableMigrationFiles() as $file) {
            if (preg_match($pattern, (string) file_get_contents($file))) {
                $out[] = str_replace($this->basePath() . '/', '', $file);
            }
        }

        return $out;
    }

    /**
     * Blank out comments so a ratchet cannot be satisfied -- or tripped -- by prose.
     *
     * This repository has been bitten twice by a guard that matched its own explanatory comment, so
     * the lexer is used rather than a regex over the raw text.
     */
    private function codeOnly(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat(' ', strlen($token[1]))
                    : $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
