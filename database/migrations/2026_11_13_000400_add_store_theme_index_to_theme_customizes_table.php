<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 03 M3-B — THE ONE CHANGE TO A PRE-EXISTING TABLE, AND WHY IT IS THE SMALLEST ONE.
 * =================================================================================================
 *
 * APPROVED FOR DESIGN, DEVELOPMENT AND ISOLATED DATABASE TESTING. **No production migration has
 * been run and none may be.** This is the only M3 migration that touches a table which already
 * holds live merchant data, so it gets stated in full.
 *
 * WHAT IS REALLY THERE — MEASURED ON MySQL, NOT INFERRED FROM SQLITE
 * -------------------------------------------------------------------------------------------------
 * `PRAGMA index_list(theme_customizes)` on the SQLite fixture reports **no indexes at all**, and
 * concluding from that would have been wrong. Migrated into a throwaway MariaDB 10.11 and read back
 * with `SHOW CREATE TABLE`:
 *
 *     PRIMARY KEY (`id`),
 *     KEY `theme_customizes_store_id_foreign` (`store_id`),
 *     CONSTRAINT `theme_customizes_store_id_foreign`
 *       FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE CASCADE
 *
 * A foreign key REQUIRES an index and InnoDB creates it. "`store_id` is unindexed" is a false
 * reading taken from SQLite — the same class of error as P01-SEC-13, where SQLite's type affinity
 * hid a real MySQL defect.
 *
 * WHAT IS GENUINELY MISSING: nothing covers `theme_id`. The storefront's per-request lookup
 * (`app/Helper/helper.php:3576`) filters on BOTH columns:
 *
 *     select `value`, `name` from `theme_customizes` where `store_id` = ? and `theme_id` = ?
 *
 * and can only use the single-column key, so it reads every row of that store and discards the
 * other themes'. That query runs on EVERY storefront page view.
 *
 * WHY THIS IS NOT A UNIQUE INDEX, WHICH IS THE INTERESTING PART
 * -------------------------------------------------------------------------------------------------
 * `(store_id, theme_id, name)` is what the application assumes is unique — `updateOrCreate` at
 * `ThemeCustomizeController:132` and `updateOrInsert` at `Api/DashboardController:9032` both treat
 * it as a key — and the development fixture agrees: `GROUP BY store_id, theme_id, name HAVING
 * COUNT(*) > 1` returns nothing across its 53 rows.
 *
 * **It is still not made UNIQUE here**, for one reason: production cannot be measured from this
 * environment, and the owner's standing restrictions forbid reaching it. A unique index meets the
 * first duplicate pair and FAILS THE MIGRATION for that deployment — mid-run, on a table the
 * storefront depends on. A migration whose success depends on an unmeasured property of live data
 * is not a safe migration, however likely the property is.
 *
 * And the cost of a duplicate is real, not theoretical. Measured at M3-A: the reader `pluck()`s the
 * group while the writers take `limit 1`, and they do not agree on which row wins — a merchant's
 * save reports success while the storefront keeps rendering the shadow row. So the duplicate
 * question matters and is answered the safe way round:
 * `tests/Support/Security/bin/report-theme-customize-duplicates.php` reports, read-only, whether a
 * given database is clean. A unique constraint is a separate, owner-approved step once some real
 * database has been shown to satisfy it.
 *
 * OPERATIONAL COST, STATED SO THE OWNER'S DECISION IS INFORMED
 * -------------------------------------------------------------------------------------------------
 * Measured on MariaDB 10.11: this index is created with `ALGORITHM=INPLACE, LOCK=NONE`, so adding it
 * does not block concurrent reads or writes on `theme_customizes`. The table is also small by
 * construction — one row per customised setting per store, with ~300 editable settings per theme.
 *
 * THE TRAP THIS MIGRATION FELL INTO, FOUND BY ACTUALLY RUNNING THE ROLLBACK
 * -------------------------------------------------------------------------------------------------
 * `(store_id, theme_id)` has `store_id` as its LEFTMOST column, so InnoDB can use it to enforce the
 * existing foreign key — and on adding it, **MariaDB SILENTLY DROPS the FK's own single-column
 * index** `theme_customizes_store_id_foreign` as redundant. Measured, `SHOW CREATE TABLE` after
 * `up()`:
 *
 *     PRIMARY KEY (`id`),
 *     KEY `theme_customizes_store_theme_index` (`store_id`,`theme_id`),   <-- the only one left
 *     CONSTRAINT `theme_customizes_store_id_foreign` FOREIGN KEY (`store_id`) ...
 *
 * The foreign key now depends on THIS index, so the obvious `down()` — drop what `up()` added —
 * FAILS, and fails MID-BATCH:
 *
 *     SQLSTATE[HY000]: General error: 1553 Cannot drop index
 *     'theme_customizes_store_theme_index': needed in a foreign key constraint
 *
 * A `down()` that looks obviously safe and is not is the same shape as the GATE F drafts that
 * dropped a column *because* it existed and destroyed 45 real `cost_price` values — which is why
 * this project RUNS the rollback instead of reasoning about it.
 *
 * So `down()` restores the foreign key's own index FIRST and only then drops the composite one.
 * Measured: that returns `theme_customizes` to its exact original definition.
 */
return new class extends Migration
{
    /** Named explicitly so `down()` can only ever remove the index THIS migration added. */
    private const INDEX = 'theme_customizes_store_theme_index';

    public function up(): void
    {
        if (! Schema::hasTable('theme_customizes')) {
            /*
             * Not an error. A fresh install runs `2025_05_22_034433_create_theme_customizes_table`
             * first, so this branch means something reordered the migrations, and silently doing
             * nothing is better than failing a deployment over an index.
             */
            return;
        }

        if ($this->indexExists(self::INDEX)) {
            return;
        }

        Schema::table('theme_customizes', function (Blueprint $table) {
            $table->index(['store_id', 'theme_id'], self::INDEX);
        });
    }

    /** The index InnoDB made for the foreign key, which `up()` renders redundant and MariaDB then
     *  silently removes. `down()` must put it back before it can drop ours. */
    private const FK_INDEX = 'theme_customizes_store_id_foreign';

    /**
     * REVERSIBLE WITHOUT DATA LOSS — but NOT by simply dropping what `up()` added. See the trap in
     * the docblock: the foreign key ends up depending on this migration's index, so dropping it
     * alone raises MySQL error 1553 and aborts the batch.
     *
     * The order IS the fix:
     *   1. re-create the foreign key's own single-column index, so the constraint has cover again;
     *   2. then drop the composite index this migration added.
     *
     * Dropping an index destroys no row and no value, unlike the GATE F column drafts — but a
     * `down()` that throws is still a broken rollback, and a half-reverted batch is worse than none.
     */
    public function down(): void
    {
        if (! Schema::hasTable('theme_customizes') || ! $this->indexExists(self::INDEX)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        /*
         * MySQL/MariaDB only: SQLite creates no index of its own for a foreign key, so there is
         * nothing to restore there. Guarded by existence so a re-run cannot collide.
         */
        if (in_array($driver, ['mysql', 'mariadb'], true) && ! $this->indexExists(self::FK_INDEX)) {
            Schema::table('theme_customizes', function (Blueprint $table) {
                $table->index('store_id', self::FK_INDEX);
            });
        }

        /* Now the constraint has cover again, so ours is droppable. */
        Schema::table('theme_customizes', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }

    /**
     * Laravel's schema builder has no portable "does this index exist", and the two engines this
     * project runs on answer the question differently: MySQL/MariaDB through `SHOW INDEX`, SQLite
     * through `PRAGMA index_list`. Guessing wrong in either direction makes the migration fail on
     * a re-run, so both are asked explicitly and anything else returns false (attempt the create,
     * and let the engine refuse a genuine duplicate).
     */
    private function indexExists(string $name): bool
    {
        $connection = Schema::getConnection();
        $driver     = $connection->getDriverName();

        if ($driver === 'sqlite') {
            foreach ($connection->select("PRAGMA index_list('theme_customizes')") as $row) {
                if (($row->name ?? null) === $name) {
                    return true;
                }
            }

            return false;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = $connection->select(
                'SELECT 1 FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [$connection->getDatabaseName(), 'theme_customizes', $name]
            );

            return $rows !== [];
        }

        return false;
    }
};
