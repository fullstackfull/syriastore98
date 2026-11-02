<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GATE F -- bring the two `delivery_boys` commission columns under version control.
 *
 * WHAT THEY ARE. `commission_percentage` is the driver's cut of a delivery's shipping cost and
 * `is_commission_enabled` is the per-driver switch for it. `OrderController::order_assign()` reads
 * both and writes the result into `delivery_earnings`, which is why this file is numbered BEFORE
 * `2026_11_02_000600_create_delivery_earnings_table.php`: that table records a percentage this
 * migration supplies.
 *
 * THIS REPOSITORY WAS WRONG ABOUT THESE COLUMNS, AND THE CORRECTION IS THE REASON TO READ ON.
 * `CLAUDE.md` and the Phase 01 notes stated that `delivery_boys` LACKS both columns and that "those
 * handlers 500 for everyone". The restored production dump refutes it: both columns exist, across 2
 * rows, both with a non-zero percentage and one with the flag set. Measured, 2026-10-24:
 *
 *     delivery_boys.commission_percentage  decimal(5,2)  NULL      DEFAULT 0.00
 *     delivery_boys.is_commission_enabled  tinyint(1)    NOT NULL  DEFAULT 0
 *
 * `commission_percentage` being NULLABLE WITH A NON-NULL DEFAULT is unusual and is reproduced
 * exactly, because production is the authority and a handler may already distinguish "never set"
 * from "set to zero". `docs/GATE-F-VERIFIED-SCHEMA.md` section 2 holds the measurement; the former
 * claim is corrected there and in `CLAUDE.md`.
 *
 * WHAT RUNNING THIS AGAINST PRODUCTION DOES: NOTHING. Both columns are already there, so the
 * `hasColumn()` checks skip them. The value of the migration is that a FRESH environment -- CI,
 * staging, a new tenant database -- now gets the same two columns production already has, instead
 * of 500ing on first use.
 *
 * WHY THE GUARD HERE IS `hasColumn()` AND NOT `GateFSchemaGuard`. The guard compares a whole
 * `CREATE TABLE` against a measured one, and `delivery_boys` as a whole is NOT a GATE F table --
 * it has had a migration since 2023 and most of its columns are none of this file's business.
 * Comparing the two columns is what is wanted, and that is done by
 * `tests/Unit/Database/GateFColumnParityTest`, which reads the measured types out of
 * `GateFProductionSchema::COLUMN_DDL` and asserts this file declares exactly them. A wrong type
 * here fails in the pull request rather than in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_boys')) {
            // Nothing to add the columns to. Returning is right, but it is also the exact trap the
            // original draft fell into: see down() below.
            return;
        }

        Schema::table('delivery_boys', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_boys', 'commission_percentage')) {
                // Production: decimal(5,2) NULL DEFAULT 0.00 -- nullable AND defaulted, as measured.
                $table->decimal('commission_percentage', 5, 2)->nullable()->default(0.00);
            }

            if (! Schema::hasColumn('delivery_boys', 'is_commission_enabled')) {
                // Production: tinyint(1) NOT NULL DEFAULT 0.
                $table->boolean('is_commission_enabled')->default(false);
            }
        });
    }

    public function down(): void
    {
        // A DELIBERATE NO-OP, and this is the one `down()` in the GATE F set that was MEASURED
        // destroying production data rather than merely suspected of it.
        //
        // The original draft guarded its up() on `hasColumn()` -- returning early when the column
        // already existed -- and then dropped the column in down() BECAUSE it existed. Run against
        // a copy of production, `migrate:rollback` executed it in 46.82ms and destroyed BOTH
        // commission columns with their data, having created nothing. An up() that creates nothing
        // paired with a down() that drops regardless is not a rollback; it is data loss with a
        // routine-looking command in front of it.
        //
        // So nothing is dropped here. If a fresh environment really needs these columns gone,
        // drop them by hand, on purpose, with a backup.
    }
};
