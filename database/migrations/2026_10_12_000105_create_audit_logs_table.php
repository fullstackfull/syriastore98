<?php

use App\Support\Database\GateFSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `audit_logs` -- row 7 of SCHEMA-01's "tables live code reads and writes that no migration
 * creates".
 *
 * ================================================================================================
 *  CORRECTED 2026-11-02 AGAINST THE MEASURED PRODUCTION STRUCTURE. The previous version of this
 *  file was DERIVED FROM THE MODEL, and the derivation was reasonable and wrong in five places.
 * ================================================================================================
 *
 * The earlier docblock argued, at length, that this table was safe to create ahead of GATE F
 * because "its column list and types ARE fully derivable from the model, with no guessing". The
 * column LIST was. The TYPES were not, and `$fillable` cannot tell you a length. What production
 * actually has, measured on 2026-10-24 from a restored copy of the dump and recorded in
 * `docs/GATE-F-VERIFIED-SCHEMA.md` section 2, differed from what this file created in five ways:
 *
 *   | column / key           | this file used to create | production has                        |
 *   |------------------------|--------------------------|---------------------------------------|
 *   | `action`               | `varchar(255)`           | `varchar(100)`                        |
 *   | `subject_type`         | `varchar(255)`           | `varchar(150)`                        |
 *   | `created_at`           | `DEFAULT NULL`           | `DEFAULT CURRENT_TIMESTAMP`           |
 *   | `updated_at`           | `DEFAULT NULL`           | `CURRENT_TIMESTAMP ON UPDATE ...`     |
 *   | indexes                | one per column, Laravel-named | four, production-named, two of them COMPOSITE |
 *
 * The index set is the one that mattered. Production indexes `(store_id, created_at)` together --
 * the shape every read of this table actually uses, "what happened in this store, most recent
 * first" -- where this file created a single-column `store_id` index that cannot serve the sort.
 * It is corrected to the measured structure, names included, because a later migration or a raw
 * query that names `idx_audit_store_created` must find it.
 *
 * NONE OF THIS TOUCHED PRODUCTION. `audit_logs` was applied there out of band and this migration
 * has never run against it; the `hasTable()` check below has always made it a no-op there. The
 * damage was confined to environments built from this repository -- CI, development, staging -- and
 * it was silent: a shorter string truncating on MySQL, or a slow audit read, with nothing to say
 * why.
 *
 * WHY IT IS STILL NOT HELD BEHIND GATE F. GATE F exists because a GUESSED money-column type becomes
 * permanent and invisible once a guarded migration has created the table. Two things make this
 * table a different case: it is not money-bearing -- SCHEMA-01 classifies it "no (compliance)" --
 * and since 2026-10-24 its types are MEASURED rather than guessed. The guess is what GATE F
 * guards against, and there is no longer one here.
 *
 * NO FOREIGN KEYS, deliberately, and production agrees -- it declares none either. An audit record
 * must outlive the user or store it refers to; a cascade would destroy the compliance trail and a
 * restrict would block the very deletion the trail exists to record. `user_id` and `store_id` are
 * plain indexed integers.
 *
 * NULLABILITY is taken from the call site, and the measurement confirms it.
 * `AuditLog::record()` passes `auth()->id()` for `user_id` and `getCurrentStore()` for `store_id`,
 * and both are null in the contexts R4-02 names (queued job, console command, storefront,
 * customer/deliveryboy guard). `subject` is an optional parameter defaulting to null.
 * `action` is a non-nullable string parameter, and production has it NOT NULL.
 *
 * `old_values` / `new_values` are declared `json()`, which the model's `$casts` requires. On
 * MariaDB that is `longtext COLLATE utf8mb4_bin` with a `json_valid` CHECK -- exactly what
 * production shows, both constraints included -- and on SQLite it is `text`, so the same migration
 * runs under test.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            // An existing table is compared against the measured production structure rather than
            // accepted on its name. This matters most for an environment created by the PREVIOUS
            // version of this file: that table is still there, still the wrong shape, and nothing
            // would otherwise say so. The guard names every difference and stops.
            GateFSchemaGuard::assertCompatible('audit_logs');

            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            // Production is utf8mb4_general_ci here, while the application default is
            // utf8mb4_unicode_ci -- so leaving this out would substitute a different collation.
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';

            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 150)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

            // The production index set, names and composition included. Two are composite; there
            // is no single-column store_id or subject_type index, deliberately.
            $table->index(['store_id', 'created_at'], 'idx_audit_store_created');
            $table->index(['subject_type', 'subject_id'], 'idx_audit_subject');
            $table->index('user_id', 'idx_audit_user');
            $table->index('action', 'idx_audit_action');
        });
    }

    public function down(): void
    {
        // DELIBERATELY NOT `dropIfExists`, changed 2026-11-02 along with the structure above.
        //
        // `audit_logs` exists in production with live rows -- 29 of them at the time of
        // measurement -- and it is the compliance trail. A `migrate:rollback` that dropped it would
        // destroy the record of who did what, which is the one table whose whole purpose is to
        // survive the events it records. Drop it by hand, on purpose, with a backup, or not at all.
    }
};
