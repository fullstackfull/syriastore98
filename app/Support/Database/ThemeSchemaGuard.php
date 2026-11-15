<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * PHASE 03 M3-E — A TABLE THAT ALREADY EXISTS IS NOT A TABLE THAT IS CORRECT.
 * =================================================================================================
 *
 * THE DEFECT THIS EXISTS TO CLOSE, measured rather than imagined.
 *
 * The three M3-B migrations opened with a blind early return:
 *
 *     if (Schema::hasTable('theme_published_versions')) {
 *         return;
 *     }
 *
 * MySQL DDL is not transactional, so a migration that fails PART WAY leaves its `CREATE TABLE`
 * committed and its constraints missing — and nothing records the migration as applied, so the next
 * deployment attempts it again. Reproduced on MariaDB 10.11.14:
 *
 *     (partial table in place: unique key and store FK present, composite FK absent)
 *     $ php artisan migrate
 *     2026_11_13_000200_create_theme_published_versions_table ........ 0.54ms DONE
 *     exit 0
 *
 *     SELECT constraint_name FROM information_schema.referential_constraints ...
 *     -> tpv_store_id_foreign          (and nothing else)
 *
 * **`tpv_version_identity_foreign` was permanently absent while `migrate:status` showed green.**
 * That is the constraint `2026_11_13_000100:167` calls *"THE KEY THAT MAKES CROSS-TENANT PUBLICATION
 * IMPOSSIBLE, not merely unlikely"* — the engine-level backstop for the moment a hand-written
 * `where('store_id', ...)` is forgotten. An interrupted upgrade silently removed it, and the blind
 * early return then recorded the migration as done so it could never be repaired by re-running.
 *
 * `hasTable()` answers "does a table of this name exist". The migration needed "is the table the one
 * I would have created". Those are different questions, and conflating them is the same
 * inverted-absence mistake this project keeps recording: `PRAGMA index_list` reporting no index
 * where InnoDB had one, `getGlobalScopes()` not answering "is it scoped", `Schema::hasColumn()`
 * versus a migration's claim about the schema.
 *
 * SO THIS GUARD REFUSES RATHER THAN ASSUMES. It names every disagreement it finds and throws, which
 * stops the deployment with something a human can act on, instead of recording a lie. It is the same
 * posture as `GateFSchemaGuard::assertCompatible()` and as M3-D's
 * `assertExistingColumnIsCompatible()` — a guard that cannot see the truth must not report success.
 *
 * SQLITE IS EXEMPT, DELIBERATELY AND NARROWLY. `PRAGMA index_list` does not report an index InnoDB
 * creates for a foreign key (measured at M3-B on `theme_customizes`, and again at M3-D on
 * `theme_customize_orders`: MariaDB reported two indexes where SQLite reported none), and SQLite
 * does not enforce or expose foreign keys the same way. Asserting constraint names there would fail
 * against a database that is actually fine. The suite runs on SQLite; the guard's job is production.
 */
final class ThemeSchemaGuard
{
    /**
     * Refuse unless an existing table carries every index and foreign key named here.
     *
     * @param  array<int,string>  $indexes      index names that must exist
     * @param  array<int,string>  $foreignKeys  referential-constraint names that must exist
     * @param  array<int,string>  $columns      column names that must exist
     *
     * @throws RuntimeException naming every disagreement
     */
    public static function assertCompatible(
        string $table,
        array $columns = [],
        array $indexes = [],
        array $foreignKeys = []
    ): void {
        if (! Schema::hasTable($table)) {
            return;     // nothing to check; the caller is about to create it
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;     // see the class docblock
        }

        $problems = [];

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $problems[] = "column `{$column}` is missing";
            }
        }

        $present = self::indexNames($table);

        foreach ($indexes as $index) {
            if (! in_array($index, $present, true)) {
                $problems[] = "index `{$index}` is missing";
            }
        }

        $constraints = self::foreignKeyNames($table);

        foreach ($foreignKeys as $fk) {
            if (! in_array($fk, $constraints, true)) {
                $problems[] = "foreign key `{$fk}` is missing";
            }
        }

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "`%s` ALREADY EXISTS BUT IS NOT THE TABLE THIS MIGRATION WOULD HAVE CREATED:\n  - %s\n\n"
            . "This is what an INTERRUPTED migration leaves behind: MySQL DDL is not transactional,\n"
            . "so a failure part way commits the CREATE TABLE and loses the constraints. Re-running\n"
            . "used to report DONE in under a millisecond and record the migration as applied, which\n"
            . "left the gap permanent -- including `tpv_version_identity_foreign`, the engine-level\n"
            . "backstop against cross-tenant publication.\n\n"
            . "This migration refuses rather than record a lie. Reconcile `%s` deliberately: either\n"
            . "add the missing objects, or drop the table if it holds nothing worth keeping (check\n"
            . "first -- it may hold version history), then migrate again.",
            $table, implode("\n  - ", $problems), $table));
    }

    /** @return array<int,string> */
    private static function indexNames(string $table): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->index_name,
            DB::select(
                'SELECT DISTINCT index_name FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            )
        );
    }

    /** @return array<int,string> */
    private static function foreignKeyNames(string $table): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->constraint_name,
            DB::select(
                'SELECT constraint_name FROM information_schema.referential_constraints
                  WHERE constraint_schema = DATABASE() AND table_name = ?',
                [$table]
            )
        );
    }
}
