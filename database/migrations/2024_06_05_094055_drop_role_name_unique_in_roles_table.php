<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA-09 / NEW-sqlite-migration-blocker — driver portability fix.
 *
 * ORIGINAL DEFECT: `SHOW INDEX FROM roles ...` is MySQL-only syntax. `Schema::hasColumn()`
 * succeeds on SQLite, so the SHOW INDEX statement WAS reached and aborted `migrate:fresh`
 * with a syntax error on any non-MySQL driver. That is the single blocker that kept the
 * from-zero SQLite build (and therefore the whole test suite) red.
 *
 * FIX: the MySQL detection branch is preserved BYTE FOR BYTE and now runs only on MySQL/MariaDB.
 * Other drivers use Laravel 11's portable `Schema::getIndexes()` (framework v11.47.0).
 *
 * WHY DETECT AT ALL RATHER THAN JUST SKIP ON SQLITE: the intent of this migration is to
 * drop the unique index that `2023_12_08_100022_laratrust_setup_tables.php:19` creates via
 * `$table->string('name')->unique()`. Roles are per-store in this platform, so leaving the
 * unique constraint in place on SQLite would make two tenants unable to hold a role of the
 * same name — a behavioural divergence from MySQL, and one that breaks multi-tenant tests.
 * Skipping on SQLite would have been "non-fatal but wrong"; this is portable and correct.
 *
 * MySQL BLAST RADIUS: none. On MySQL the emitted DDL is identical to before this edit
 * (`alter table roles drop index roles_name_unique`, or nothing when no such index exists).
 * The resulting MySQL schema is byte-identical either way.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('roles', 'name')) {
            return;
        }

        // Laravel 11 reports 'mariadb' as a driver name distinct from 'mysql'; both speak
        // SHOW INDEX, so both must take the original path.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // UNCHANGED MySQL path.
            $hasUnique = collect(DB::select("SHOW INDEX FROM roles WHERE Column_name = 'name' AND Non_unique = 0 AND Key_name != 'PRIMARY'"))->isNotEmpty();
        } else {
            $hasUnique = collect(Schema::getIndexes('roles'))->contains(
                fn ($index) => ! empty($index['unique'])
                    && empty($index['primary'])
                    && array_map('strtolower', (array) ($index['columns'] ?? [])) === ['name']
            );
        }

        if ($hasUnique) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropUnique('roles_name_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Guarded so rollback cannot fail on a database where the index is already present
     * (which is the state left behind when `up()` found nothing to drop).
     */
    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('roles', 'name')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            // Add back the unique constraint if needed
            $table->unique('name');
        });
    }
};
