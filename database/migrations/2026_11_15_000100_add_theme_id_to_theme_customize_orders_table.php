<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 03 M3-D — SECTION ORDERING PER THEME. ADDITIVE, NULLABLE, NO UNIQUE KEY.
 * =================================================================================================
 *
 * OWNER DECISION THIS IMPLEMENTS, and its limit. Developing this migration in isolated
 * development/test databases is approved. **Applying it to production is NOT.** Nothing here runs
 * against a live host; the verification script creates and drops its own throwaway database.
 *
 * WHAT IS BROKEN WITHOUT IT
 * -------------------------------------------------------------------------------------------------
 * `theme_customize_orders` is `id, page_slug, orders, store_id, timestamps` and nothing else. Both
 * handlers nevertheless filtered on `theme_id`, which M3-C measured as `ERROR 1054` on MySQL --
 * section ordering answered 500 in production, while SQLite silently degraded the identifier to a
 * string literal so no test could see it. M3-C removed the predicates, which fixed the 500 and left
 * ordering keyed on `(store_id, page_slug)`: **shared across every theme a merchant tries.** A
 * merchant who reorders stylique's home page reorders greentic's too. This column closes that.
 *
 * WHY THE COLUMN IS NULLABLE AND WHY NOTHING IS BACKFILLED
 * -------------------------------------------------------------------------------------------------
 * The owner's condition was explicit: *"Do not blindly assign legacy rows to arbitrary themes."*
 *
 * The obvious backfill is `UPDATE ... SET theme_id = (SELECT theme_id FROM stores ...)`, and it is
 * wrong. `stores.theme_id` is the theme active **today**. A legacy order row was written while some
 * theme was active **then**, and this table never recorded which. For any merchant who has since
 * switched theme, that backfill does not recover a fact -- it INVENTS one, and a false record is
 * worse than an absent one because the application cannot tell it is false.
 *
 * So legacy rows keep `theme_id = NULL`, and the application reads NULL as *"applies to whichever
 * theme has no row of its own"* -- an explicit fallback rather than a claim about history. New
 * writes always carry a resolved, authorised theme. The consequence, stated rather than discovered:
 * the first time a merchant reorders a page under theme A, theme A gets its own row and every other
 * theme keeps falling back to the legacy one. That is the gentlest possible upgrade, and
 * `ThemeCustomizeOrder::resolveFor()` is where it lives.
 *
 * WHY THERE IS NO UNIQUE KEY, which is the owner's other condition
 * -------------------------------------------------------------------------------------------------
 * `(store_id, theme_id, page_slug)` is what the application treats as a key, and a UNIQUE index on
 * it is exactly what this must NOT add. A unique index meets the first duplicate pair and FAILS THE
 * MIGRATION mid-run. Whether such pairs exist is a property of live data, which this environment
 * cannot see: both databases audited here hold **0 rows**, so they prove nothing about production.
 * `report-theme-customize-order-duplicates.php` is the read-only audit the owner can run there, and
 * a unique key is a separate, later decision that needs its output first.
 *
 * The index added IS non-unique and exists to serve the resolution query
 * (`store_id`, then `theme_id` or NULL, then `page_slug`).
 *
 * THE ROLLBACK TRAP M3-B MEASURED, AND WHAT WAS MEASURED HERE
 * -------------------------------------------------------------------------------------------------
 * M3-B found that adding a composite index beginning with a foreign-key column let MariaDB drop the
 * FK's own single-column index, and `down()` then failed mid-batch with errno 1553. **It reproduces
 * here**, and the mechanism was measured precisely, because a first reproduction attempt appeared to
 * CONTRADICT it:
 *
 *     index declared EXPLICITLY (`KEY tco_store_id_foreign (store_id)` in the CREATE)
 *       -> adding the covering composite KEEPS it, and dropping the composite SUCCEEDS
 *
 *     index exists ONLY because the FK auto-created one (what Laravel's `$table->foreign()` does)
 *       -> adding the covering composite DISCARDS it, and dropping the composite then fails:
 *          ERROR 1553 (HY000): Cannot drop index 'tco_store_theme_page_index':
 *                              needed in a foreign key constraint
 *
 * `theme_customize_orders` is the second case. Measured after a full `migrate`, `SHOW CREATE TABLE`
 * holds the CONSTRAINT and exactly one index serving it:
 *
 *     PRIMARY KEY (`id`),
 *     KEY `tco_store_theme_page_index` (`store_id`,`theme_id`,`page_slug`),
 *     CONSTRAINT `theme_customize_orders_store_id_foreign` FOREIGN KEY (`store_id`) ...
 *
 * -- `theme_customize_orders_store_id_foreign` is gone as an INDEX. So `down()` does not assume: it
 * restores a single-column index BEFORE dropping the composite. Both directions are measured by
 * `verify-theme-order-theme-id-migration-on-mysql.php` (20 checks), and FALSIFIED: deleting the
 * restore makes the rollback fail at this file's `dropIndex` call.
 *
 * WHAT A ROLLBACK COSTS, stated because `down()` here is NOT a no-op like M3-B's were. Dropping the
 * column discards the THEME ATTRIBUTION of any row written since the migration. **No `orders` value
 * is lost** -- those rows survive as legacy rows -- but a store that had per-theme ordering comes
 * back with several rows per `(store_id, page_slug)` and the pre-M3-D code resolves that with
 * `->first()`. That is recoverable and visible; destroying merchant content would not be, which is
 * why the column is the only thing dropped.
 */
return new class extends Migration
{
    private const TABLE = 'theme_customize_orders';
    private const INDEX = 'tco_store_theme_page_index';
    private const FK_INDEX = 'theme_customize_orders_store_id_foreign';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            /*
             * Not an error worth stopping a deployment for: this table belongs to the theme
             * customiser, and an installation that has never had it has nothing to order.
             */
            return;
        }

        if (Schema::hasColumn(self::TABLE, 'theme_id')) {
            /*
             * Already present. Do NOT assume it is this migration's column -- M3-B's rule. Assert
             * the shape agrees and then do nothing, so a database that got here another way is
             * either compatible or names its disagreement.
             */
            $this->assertExistingColumnIsCompatible();

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            /*
             * 191, matching `theme_versions.theme_id` and `theme_customizes.theme_id`. NOT 255:
             * `stores.theme_id` is `varchar(191)` and a wider column here could hold a value the
             * store column cannot, which is a disagreement waiting to be found the hard way.
             */
            $table->string('theme_id', 191)->nullable()->after('page_slug');
        });

        /* The resolution query's index. Non-unique, deliberately -- see the docblock. */
        if (! $this->indexExists(self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['store_id', 'theme_id', 'page_slug'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'theme_id')) {
            return;
        }

        /*
         * THE FOREIGN KEY MUST KEEP AN INDEX TO STAND ON. Restoring one BEFORE dropping the
         * composite, because the other order is what failed with errno 1553 at M3-B.
         */
        if (! $this->isSqlite() && ! $this->indexExists(self::FK_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('store_id', self::FK_INDEX);
            });
        }

        if ($this->indexExists(self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('theme_id');
        });
    }

    /* ================================================================================= helpers */

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    private function indexExists(string $name): bool
    {
        $connection = Schema::getConnection();

        if ($this->isSqlite()) {
            foreach ($connection->select('PRAGMA index_list(' . self::TABLE . ')') as $row) {
                if (($row->name ?? null) === $name) {
                    return true;
                }
            }

            return false;
        }

        return $connection->select(
            'SELECT 1 FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [self::TABLE, $name]
        ) !== [];
    }

    /**
     * A `theme_id` that is already there must be usable as this migration's column, or say why not.
     *
     * The refusing direction is deliberate: a silent "it exists, carry on" is how a column of the
     * wrong width or nullability becomes a truncation or a NOT NULL violation later, far from here.
     */
    private function assertExistingColumnIsCompatible(): void
    {
        if ($this->isSqlite()) {
            return;     // SQLite's type affinity makes width and nullability unenforceable anyway
        }

        $column = DB::selectOne(
            'SELECT data_type, character_maximum_length AS len, is_nullable
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [self::TABLE, 'theme_id']
        );

        if ($column === null) {
            return;
        }

        $problems = [];

        if (strtolower((string) $column->data_type) !== 'varchar') {
            $problems[] = "data_type is {$column->data_type}, expected varchar";
        }

        if ((int) $column->len < 191) {
            $problems[] = "length is {$column->len}, expected at least 191 "
                . '(a shorter column truncates a theme id silently in non-strict mode)';
        }

        if (strtoupper((string) $column->is_nullable) !== 'YES') {
            $problems[] = 'the column is NOT NULL, but legacy rows must be able to hold NULL as '
                . 'the cross-theme fallback';
        }

        if ($problems !== []) {
            throw new RuntimeException(
                self::TABLE . '.theme_id already exists but is not compatible with M3-D: '
                . implode('; ', $problems)
                . '. Reconcile it deliberately rather than letting this migration assume.'
            );
        }
    }
};
