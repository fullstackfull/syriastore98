<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA-09 / NEW-sqlite-migration-blocker — driver portability fix.
 *
 * ORIGINAL DEFECT: every statement in this migration is MySQL-only.
 *   - `SET foreign_key_checks = 0;`                       -> syntax error on SQLite
 *   - `SELECT ... FROM information_schema.KEY_COLUMN_USAGE` -> no such table on SQLite
 *   - `ALTER TABLE ... DROP FOREIGN KEY <name>`           -> syntax error on SQLite
 * and, verified empirically against SQLite 3.45.1, the `dropColumn('user_id')` block could
 * not succeed on SQLite either, because SQLite refuses to drop a column that is named in a
 * foreign-key definition:
 *     "error in table user_coupons after drop column: unknown column user_id in foreign key definition"
 * (`user_coupons.user_id` carries an inline FK to `users` from
 *  `2023_12_29_100550_create_user_coupons_table.php:23`.) The refusal is unconditional — it
 * happens with `PRAGMA foreign_keys` both ON and OFF. SQLite also has no
 * `ALTER TABLE ... ADD CONSTRAINT`, so the third block's `$table->foreign(...)` is a no-op there.
 *
 * FIX: the entire MySQL body is preserved BYTE FOR BYTE and now runs only on MySQL/MariaDB.
 * No new logic is added to this already-executed historical migration. The non-MySQL route to
 * the same end state (nullable `user_id`, FK -> `customers`) needs a table rebuild, which
 * belongs in a NEW additive migration rather than here; it is
 * `2026_10_12_000104_reconcile_user_coupons_user_id_on_non_mysql.php`.
 *
 * MySQL BLAST RADIUS: none. The guarded branch IS the existing code, so the resulting MySQL
 * schema is byte-identical with or without this edit. MariaDB is included in the guard
 * deliberately: Laravel 11 reports 'mariadb' as a driver name distinct from 'mysql', and an
 * unqualified `=== 'mysql'` test would have silently skipped the real body on a MariaDB host.
 */
return new class extends Migration
{
    /**
     * True when the connection speaks the MySQL dialect this migration was written for.
     */
    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        // Disable foreign key checks
        \DB::statement('SET foreign_key_checks = 0;');

        Schema::table('user_coupons', function (Blueprint $table) {
            // Check if 'user_id' column exists
            if (Schema::hasColumn('user_coupons', 'user_id')) {
                // Drop the foreign key constraint if it exists
                $foreignKeys = \DB::select('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = "user_coupons" AND COLUMN_NAME = "user_id" AND REFERENCED_TABLE_NAME = "users"');
                if (!empty($foreignKeys)) {
                    \DB::statement('ALTER TABLE user_coupons DROP FOREIGN KEY ' . $foreignKeys[0]->CONSTRAINT_NAME);
                }
            }
        });

        Schema::table('user_coupons', function (Blueprint $table) {
            // Drop the column if it exists
            if (Schema::hasColumn('user_coupons', 'user_id')) {
                $table->dropColumn('user_id');
            }
        });

        Schema::table('user_coupons', function (Blueprint $table) {
            // Recreate the column as nullable
            $table->unsignedBigInteger('user_id')->nullable()->after('id');

            // Add the new foreign key constraint
            $table->foreign('user_id')->references('id')->on('customers')->onDelete('cascade');
        });

        // Enable foreign key checks
        \DB::statement('SET foreign_key_checks = 1;');
    }




    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        // Disable foreign key checks
        \DB::statement('SET foreign_key_checks = 0;');

        Schema::table('user_coupons', function (Blueprint $table) {
            // Check if 'user_id' column exists
            if (Schema::hasColumn('user_coupons', 'user_id')) {
                // Drop the foreign key constraint if it exists
                $foreignKeys = \DB::select('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = "user_coupons" AND COLUMN_NAME = "user_id" AND REFERENCED_TABLE_NAME = "customers"');
                if (!empty($foreignKeys)) {
                    \DB::statement('ALTER TABLE user_coupons DROP FOREIGN KEY ' . $foreignKeys[0]->CONSTRAINT_NAME);
                }
            }
        });

        Schema::table('user_coupons', function (Blueprint $table) {
            // Drop the column if it exists
            if (Schema::hasColumn('user_coupons', 'user_id')) {
                $table->dropColumn('user_id');
            }
        });

        Schema::table('user_coupons', function (Blueprint $table) {
            // Recreate the column as nullable
            $table->unsignedBigInteger('user_id')->nullable()->after('id');

            // Add the new foreign key constraint
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Enable foreign key checks
        \DB::statement('SET foreign_key_checks = 1;');
    }
};
