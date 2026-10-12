<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * NEW-sqlite-migration-blocker / SCHEMA-09 / NEW-migration-hygiene — recurrence prevention.
 *
 * PURE FILESYSTEM ASSERTIONS. This class extends PHPUnit\Framework\TestCase directly and
 * deliberately does NOT boot Laravel and does NOT touch a database, so it runs before the
 * production schema dump arrives (GATE F) and cannot be blocked by it. That is the whole point:
 * 05-REGRESSION-TEST-PLAN.md calls the MySQL-only-SQL scan "the one that prevents recurrence,
 * and the cheaper of the two".
 *
 * Both migration trees are scanned: the 105-file root tree and the 102 files under the 69
 * WorkDo addon packages (211 files in total at the time of writing). The addon trees are READ
 * ONLY -- nothing under packages/workdo may be edited -- so a failure originating there is a
 * report-upstream, not a fix-in-place.
 */
class MigrationHygieneTest extends TestCase
{
    /**
     * SQL fragments that only MySQL/MariaDB understands. A migration containing any of these
     * MUST also contain a driver guard, or it aborts `migrate:fresh` on SQLite.
     */
    private const MYSQL_ONLY_TOKENS = [
        'SHOW INDEX',
        'SHOW TABLES',
        'SHOW COLUMNS',
        'information_schema',
        'foreign_key_checks',
        'MODIFY COLUMN',
        'CHANGE COLUMN',
        'DROP FOREIGN KEY',
    ];

    /**
     * Known, accepted duplicate basename. Laravel keys the `migrations` table by BASENAME, so
     * whichever of these two migrates second is recorded as already-run and SILENTLY SKIPPED.
     *
     * Accepted rather than fixed because both files live under packages/workdo, which this
     * programme may not edit, and because the two copies are byte-identical so the skip is
     * currently harmless. Listed here so a NEW duplicate -- which would not be harmless -- fails
     * this test immediately. Renaming either file would make it no longer recorded as run, so it
     * WOULD execute on the next migrate; that is only safe once the 3-argument `Schema::hasColumn`
     * bug in both copies (`:14` and `:28`) is corrected in the same change.
     */
    private const ACCEPTED_DUPLICATE_BASENAMES = [
        '2024_08_30_102330_add_social_type_and_social_id_column_in_customers_table.php',
    ];

    /** @return array<int, string> absolute paths of every migration in both trees */
    private function allMigrationFiles(): array
    {
        $base = dirname(__DIR__, 3);

        $files = glob($base . '/database/migrations/*.php') ?: [];

        foreach (glob($base . '/packages/workdo/*/src/database/migrations/*.php') ?: [] as $packageFile) {
            $files[] = $packageFile;
        }

        return $files;
    }

    /** @return array<int, string> absolute paths of root-tree migrations only */
    private function rootMigrationFiles(): array
    {
        return glob(dirname(__DIR__, 3) . '/database/migrations/*.php') ?: [];
    }

    public function test_both_migration_trees_are_discoverable(): void
    {
        // Guards against the scans below passing vacuously because a glob silently matched nothing.
        $this->assertGreaterThan(100, count($this->rootMigrationFiles()));
        $this->assertGreaterThan(200, count($this->allMigrationFiles()));
    }

    /**
     * THE RECURRENCE TEST. Any migration issuing MySQL-only SQL must guard on the driver.
     */
    public function test_no_migration_contains_unguarded_mysql_only_sql(): void
    {
        $offenders = [];

        foreach ($this->allMigrationFiles() as $file) {
            $contents = file_get_contents($file);

            $found = array_values(array_filter(
                self::MYSQL_ONLY_TOKENS,
                fn (string $token) => stripos($contents, $token) !== false
            ));

            if ($found === []) {
                continue;
            }

            // A driver guard is the only accepted mitigation. `getDriverName()` is the single
            // idiom used across this codebase, so requiring it keeps the check unambiguous.
            if (! str_contains($contents, 'getDriverName')) {
                $offenders[basename($file)] = $found;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These migrations issue MySQL-only SQL with no getDriverName() guard, so `migrate:fresh` "
            . "aborts on SQLite and the whole suite goes red:\n"
            . print_r($offenders, true)
        );
    }

    /**
     * The two files Increment 2 repaired must keep their guards. Pinned by name so a future
     * "cleanup" that removes a guard fails here rather than in a confusing schema error.
     */
    public function test_the_two_known_mysql_only_migrations_are_still_guarded(): void
    {
        $root = dirname(__DIR__, 3) . '/database/migrations/';

        $expected = [
            '2024_06_05_094055_drop_role_name_unique_in_roles_table.php',
            '2024_05_29_102438_add_customers_tbale_forienkey_in_user_coupons_table.php',
        ];

        foreach ($expected as $name) {
            $this->assertFileExists($root . $name);

            $contents = file_get_contents($root . $name);

            $this->assertStringContainsString(
                'getDriverName',
                $contents,
                "{$name} has lost its driver guard; `migrate:fresh` will abort on SQLite again."
            );

            // MariaDB reports a driver name distinct from 'mysql' in Laravel 11. A guard that
            // tests only for 'mysql' would silently skip the real body on a MariaDB host.
            $this->assertStringContainsString(
                'mariadb',
                $contents,
                "{$name}'s driver guard omits 'mariadb', so the MySQL body would be skipped on MariaDB."
            );
        }
    }

    /**
     * Laravel keys the `migrations` table by basename, so a duplicate means one file is silently
     * never executed.
     */
    public function test_no_unexpected_duplicate_migration_basenames(): void
    {
        $counts = array_count_values(array_map('basename', $this->allMigrationFiles()));

        $duplicates = array_keys(array_filter($counts, fn (int $n) => $n > 1));

        sort($duplicates);
        $accepted = self::ACCEPTED_DUPLICATE_BASENAMES;
        sort($accepted);

        $this->assertSame(
            $accepted,
            $duplicates,
            'Duplicate migration basenames changed. Laravel records migrations by basename, so a '
            . 'duplicate is SILENTLY SKIPPED on the second run. Either this is a new defect, or the '
            . 'accepted list in this test needs an explicit, reasoned update.'
        );
    }

    /**
     * Every catch-up migration added by Increment 2 must be guarded, because the whole safety
     * argument for running them against the real schema later is that they are no-ops there.
     */
    public function test_increment_2_catch_up_migrations_are_all_guarded(): void
    {
        $root = dirname(__DIR__, 3) . '/database/migrations/';

        $catchUps = [
            '2026_10_12_000101_add_soft_deletes_to_customers_table.php',
            '2026_10_12_000102_add_currency_and_created_by_to_orders_table.php',
            '2026_10_12_000103_add_deliverysyria_secret_to_users_table.php',
            '2026_10_12_000104_reconcile_user_coupons_user_id_on_non_mysql.php',
        ];

        foreach ($catchUps as $name) {
            $this->assertFileExists($root . $name);

            $contents = file_get_contents($root . $name);

            $this->assertTrue(
                str_contains($contents, 'Schema::hasColumn') || str_contains($contents, 'Schema::hasTable'),
                "{$name} is not guarded by Schema::hasColumn/hasTable, so it is NOT a no-op against a "
                . 'database that already holds the object.'
            );
        }
    }

    /**
     * No root migration may create the same table twice unguarded. `failed_jobs` is created by two
     * migrations but BOTH guard with `Schema::hasTable`, so it is benign; this test proves the
     * guard is still there rather than just tolerating the duplicate.
     */
    public function test_tables_created_by_two_root_migrations_are_guarded(): void
    {
        $creators = [];

        foreach ($this->rootMigrationFiles() as $file) {
            $contents = file_get_contents($file);

            preg_match_all("/Schema::create\(\s*'([A-Za-z0-9_]+)'/", $contents, $matches);

            foreach ($matches[1] as $table) {
                // A REBUILD is not a duplicate creator. A migration that renames the table aside
                // and recreates it (the only portable way to change a column's nullability or
                // repoint a foreign key on SQLite) legitimately contains a `Schema::create` for a
                // table another migration already created. Recognised by the rename, not excused
                // by name, so a genuine second unguarded creator still fails this test.
                if (str_contains($contents, "Schema::rename('" . $table . "'")) {
                    continue;
                }

                $creators[$table][] = $file;
            }
        }

        foreach ($creators as $table => $files) {
            $files = array_values(array_unique($files));

            if (count($files) < 2) {
                continue;
            }

            foreach ($files as $file) {
                $this->assertStringContainsString(
                    'hasTable',
                    file_get_contents($file),
                    sprintf(
                        'Table "%s" is created by %d root migrations and %s does not guard with '
                        . 'Schema::hasTable, so `migrate` from zero will fail on the second create.',
                        $table,
                        count($files),
                        basename($file)
                    )
                );
            }
        }
    }

    /**
     * SCHEMA-08 — `users.plan_id` has a column TYPE that depends on execution history.
     *
     * `2014_10_12_000000_create_users_table.php:28-34` declares `plan_id` as
     * `unsignedBigInteger` + FK when `plans` exists and as `string` otherwise. It is the FIRST
     * migration to run and `plans` is created much later, so the `else` branch ALWAYS wins on a
     * from-zero build: a rebuild gets VARCHAR(255) with no FK and no index, while production is
     * almost certainly BIGINT UNSIGNED with the FK.
     *
     * This test does NOT demand a fix. Editing that migration is explicitly forbidden -- it has
     * already run everywhere, so an edit would only change what NEW environments build and would
     * WIDEN the production-versus-source gap. The normalising migration is GATE F work because it
     * needs to know what production actually holds.
     *
     * What the test pins is that this remains the ONLY instance in the root tree, so the
     * anti-pattern cannot spread while the real fix is gated.
     */
    public function test_plan_id_type_drift_remains_the_only_root_instance(): void
    {
        $offenders = [];

        foreach ($this->rootMigrationFiles() as $file) {
            $contents = file_get_contents($file);

            if (! str_contains($contents, 'Schema::hasTable(')) {
                continue;
            }

            $elseAt = strpos($contents, 'else');

            if ($elseAt === false) {
                continue;
            }

            $before = $this->columnTypes(substr($contents, 0, $elseAt));
            $after = $this->columnTypes(substr($contents, $elseAt));

            foreach ($before as $column => $type) {
                if (isset($after[$column]) && $after[$column] !== $type) {
                    $offenders[basename($file)][$column] = $type . ' vs ' . $after[$column];
                }
            }
        }

        $this->assertSame(
            [
                '2014_10_12_000000_create_users_table.php' => [
                    'plan_id' => 'unsignedBigInteger vs string',
                ],
            ],
            $offenders,
            'A root migration declares the same column with DIFFERENT TYPES on the two sides of a '
            . 'Schema::hasTable() test, so the resulting type depends on execution order. That is '
            . "SCHEMA-08 and it defeats any \"migrate ran, we are fine\" check. Only the known "
            . "users.plan_id instance is accepted:\n" . print_r($offenders, true)
        );
    }

    /**
     * Map column name -> Blueprint type for one chunk of migration source.
     *
     * Index and constraint builders are excluded: `$table->unique('name')` and
     * `$table->foreign('plan_id')` take a COLUMN name as their argument but declare no type, and
     * counting them produces false positives (verified -- without this exclusion the scan reports
     * `name: string vs unique` for the roles migration and misreads plan_id's type as 'foreign').
     *
     * @return array<string, string>
     */
    private function columnTypes(string $chunk): array
    {
        $notAColumnType = [
            'unique', 'index', 'primary', 'foreign', 'spatialIndex', 'fullText', 'rawIndex',
            'dropUnique', 'dropIndex', 'dropPrimary', 'dropColumn', 'dropForeign',
            'renameColumn', 'renameIndex', 'comment',
        ];

        preg_match_all('/\$table->([A-Za-z]+)\(\s*\'([A-Za-z0-9_]+)\'/', $chunk, $matches, PREG_SET_ORDER);

        $types = [];

        foreach ($matches as $match) {
            if (in_array($match[1], $notAColumnType, true)) {
                continue;
            }

            $types[$match[2]] = $match[1];
        }

        return $types;
    }
}
