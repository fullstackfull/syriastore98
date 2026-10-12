<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW-sqlite-migration-blocker (companion) — bring `user_coupons.user_id` to the same end
 * state on non-MySQL drivers that `2024_05_29_102438_add_customers_tbale_forienkey_in_user_coupons_table.php`
 * reaches on MySQL: NULLABLE, with the foreign key pointing at `customers` rather than `users`.
 *
 * WHY A SEPARATE, NEW MIGRATION. That historical migration has already executed in every real
 * environment, so it must not grow new logic — an edit would only change what NEW environments
 * build and would widen the production-versus-source gap this whole exercise exists to close.
 * It has therefore been reduced to a pure MySQL/MariaDB driver guard, and the portable route
 * to the same end state lives here, in an additive forward-only migration, per the doctrine in
 * 03-DATABASE-RECOVERY-PLAN.md section 1.7 step 3.
 *
 * WHY A TABLE REBUILD IS THE ONLY ROUTE ON SQLITE. All three verified empirically against
 * SQLite 3.45.1 in this container:
 *   1. `ALTER TABLE user_coupons DROP COLUMN user_id` is REFUSED, because the column is named
 *      in a foreign-key definition created inline at
 *      `2023_12_29_100550_create_user_coupons_table.php:23`:
 *        "error in table user_coupons after drop column: unknown column user_id in foreign key definition"
 *      The refusal is unconditional — it occurs with PRAGMA foreign_keys both ON and OFF.
 *   2. SQLite has no `ALTER TABLE ... ADD CONSTRAINT`, so a foreign key cannot be attached to
 *      an existing table at all.
 *   3. The rename / create / drop sequence below DOES succeed inside a transaction, which is
 *      how Laravel runs SQLite migrations (the SQLite grammar supports schema transactions).
 *
 * DATA SAFETY — THIS MIGRATION REFUSES TO TOUCH A NON-EMPTY TABLE.
 * A rebuild cannot carry rows across honestly here: the existing `user_id` values are `users`
 * ids, and the target foreign key points at `customers`, so copying them would either violate
 * the new constraint or require inventing a mapping. Rather than guess, the migration no-ops
 * with a logged warning when `user_coupons` holds any row, and leaves reconciliation to the
 * owner. The target case — a from-zero CI/test build — always has an empty table, which is
 * exactly the case this increment needs to unblock.
 *
 * SAFETY SUMMARY.
 *   - NO-OP on MySQL and MariaDB: the historical migration owns that path.
 *   - NO-OP when `user_coupons` does not exist.
 *   - NO-OP when the column is already nullable and already points at `customers`.
 *   - NO-OP when the table holds any row.
 *   So this is only ever creative on a fresh non-MySQL build, which is its entire purpose.
 */
return new class extends Migration
{
    private const STAGING_TABLE = 'user_coupons_pre_reconcile';

    public function up(): void
    {
        // The historical MySQL migration already produced this end state on MySQL/MariaDB.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! Schema::hasTable('user_coupons') || ! Schema::hasColumn('user_coupons', 'user_id')) {
            return;
        }

        if (! $this->needsReconciliation()) {
            return;
        }

        if (DB::table('user_coupons')->exists()) {
            report(new RuntimeException(
                'Migration reconcile_user_coupons_user_id_on_non_mysql: SKIPPED. '
                . 'user_coupons holds rows, and user_id cannot be safely repointed from users to '
                . 'customers without an owner-supplied mapping. Reconcile manually.'
            ));

            return;
        }

        // Left behind only by an interrupted earlier attempt.
        Schema::dropIfExists(self::STAGING_TABLE);

        Schema::rename('user_coupons', self::STAGING_TABLE);

        // Target shape == what the MySQL path produces, column for column.
        Schema::create('user_coupons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->default('0');
            $table->float('amount')->default('0');
            $table->unsignedBigInteger('order_id')->default('0');
            $table->dateTime('date_used')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('coupon_id')->references('id')->on('coupons')->onDelete('cascade');
        });

        Schema::dropIfExists(self::STAGING_TABLE);
    }

    /**
     * True when `user_id` is still NOT NULL, or still has a foreign key into `users`.
     *
     * Written defensively: if the driver's introspection cannot answer, assume reconciliation
     * IS needed, because the only environment reaching this code is a fresh non-MySQL build
     * where rebuilding an empty table is harmless.
     */
    private function needsReconciliation(): bool
    {
        try {
            foreach (Schema::getColumns('user_coupons') as $column) {
                if (($column['name'] ?? null) === 'user_id' && empty($column['nullable'])) {
                    return true;
                }
            }

            foreach (Schema::getForeignKeys('user_coupons') as $foreignKey) {
                $columns = array_map('strtolower', (array) ($foreignKey['columns'] ?? []));
                $foreignTable = strtolower((string) ($foreignKey['foreign_table'] ?? ''));

                if ($columns === ['user_id'] && $foreignTable === 'users') {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return true;
        }

        return false;
    }

    /**
     * Deliberately a NO-OP.
     *
     * Reverting would mean rebuilding the table back to a NOT NULL `user_id` with a foreign key
     * into `users` — which fails outright if any row has a NULL `user_id`, and which repoints a
     * constraint at the wrong table. A rollback that corrupts referential intent is worse than
     * no rollback, so the inverse is left to the owner, documented rather than guessed.
     * Nothing here blocks `migrate:fresh`, which drops the whole schema regardless.
     */
    public function down(): void
    {
        // Intentionally empty. See the docblock above.
    }
};
