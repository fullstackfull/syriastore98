<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOMEWHERE TO STORE THE ARABIC FOLD OF A PRODUCT NAME.  `CUST-003` / `GAP-190` / `J38`.
 * =================================================================================================
 *
 * `CUST-003` is «بحث ذكي ومرادفات وفلاتر ديناميكية». Owner decision `J38` states why this is a
 * schema change and not a one-line fix:
 *
 *   > *Product search is one `LIKE %term%` on `products.name`. **Normalising the query alone is
 *   > insufficient — `LIKE` cannot normalise the haystack** — so the slice is a stored
 *   > `name_normalised` column, a hook on every product writer, and a backfill.*
 *
 * A shopper typing `شاشه` is compared, byte for byte, against a name the merchant stored as
 * `شاشة`. No collation helps: MySQL's default `utf8mb4_*_ci` is case- and accent-insensitive for
 * Latin and does nothing whatever about Arabic orthography. The fold has to be in the table.
 *
 * -------------------------------------------------------------------------------------------------
 * `text`, NOT `string(255)` BESIDE `name` — AND THAT IS A MEASUREMENT
 * -------------------------------------------------------------------------------------------------
 *
 * `products.name` is `$table->string('name')`, i.e. `varchar(255)`, so a mirror column of the same
 * width looks obvious. **Folding is not length-preserving.** Measured 2026-11-27 on this PHP:
 *
 *     mb_strtolower("\u{0130}")   // 'İ'  ->  "i\u{0307}"   ONE character in, TWO out
 *
 * A 255-character name of such letters folds to 510, and production runs MySQL with
 * `strict => false`, which would **silently truncate** it. `text` cannot truncate, is stored
 * off-page by InnoDB so it adds nothing to a row-size limit on a table that already carries many
 * columns, and loses nothing here because:
 *
 * **NO INDEX, DELIBERATELY.** Every reader is `LIKE '%term%'` — a LEADING wildcard, which no B-tree
 * index can serve, so the usual "TEXT needs a prefix length to be indexed" objection does not
 * apply. An index here would cost write time on every product save and be used by nothing. If this
 * search ever becomes prefix- or fulltext-matched, the index is a separate, measured change.
 *
 * -------------------------------------------------------------------------------------------------
 * NULLABLE, NO DEFAULT, NO BACKFILL IN THIS MIGRATION
 * -------------------------------------------------------------------------------------------------
 *
 * NULL means *"nothing has folded this row yet"*, which is the truthful state of every row that
 * exists when this runs. It is not a third value and nothing reads it as one:
 * `App\Support\Search\ProductNameIndex::applyNameFilter()` keeps the raw `name LIKE` limb first, so
 * a row with a NULL fold is found exactly as it is found today. **The feature degrades to current
 * behaviour rather than losing rows**, and `ArabicProductSearchTest` asserts that at all three
 * doors.
 *
 * **Writing a column across every tenant's catalogue is an owner operation, not a migration's.**
 * `CLAUDE.md` §2 puts "persistent migrations or destructive live-data operations" among the things
 * engineering escalates. The backfill is therefore a separate, explicit, dry-run-by-default command
 * — `php artisan products:backfill-name-normalised` — which the owner runs when they choose. This
 * migration adds an empty column and changes no row.
 *
 * -------------------------------------------------------------------------------------------------
 * `down()` IS A DOCUMENTED NO-OP
 * -------------------------------------------------------------------------------------------------
 *
 * `products` is a production table — `docs/GATE-F-VERIFIED-SCHEMA.md` measured it — so this file is
 * squarely in the case `CLAUDE.md` §1 warns about: *"two drafts dropped a column BECAUSE it existed
 * and a rollback destroyed 45 `cost_price` values."* `up()` is idempotent via `hasColumn()`, so a
 * rollback leaves nothing stuck: the column simply stays, holding data no reader needs
 * (`ProductNameIndex::isStored()` is the only thing that asks whether it is there, and a present
 * column is the happy path). A fresh environment is left with a spare column after a rollback,
 * which is cosmetic. Dropping it by hand is always safe — unlike `cost_price`, nothing is stored
 * here that is not recomputable from `products.name` in one pass.
 *
 * @see \App\Support\Search\ProductNameIndex             the fold, and the predicate that reads it
 * @see \App\Observers\ProductNameIndexObserver          the one hook that fills it
 * @see \App\Console\Commands\BackfillProductNameNormalised  the owner-run backfill
 */
return new class extends Migration
{
    private const TABLE = 'products';

    private const COLUMN = 'name_normalised';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            /*
             * Not an error and not an inference: a sorted run reaches
             * `2023_12_26_092822_create_products_table.php` long before this file, so an absent
             * table here means a database this migration has nothing to say about.
             */
            return;
        }

        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            /*
             * `after('name')` is a MySQL modifier; SQLite's grammar ignores it, which is why the
             * suite is unaffected by it either way. It is here so the column reads next to the one
             * it mirrors in a `SHOW CREATE TABLE`.
             */
            $table->text(self::COLUMN)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        /*
         * A DOCUMENTED NO-OP — see the docblock. `products` is a production table and a conditional
         * drop on rollback is the exact mechanism that destroyed 45 real `cost_price` values.
         */
    }
};
