<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOMEWHERE TO RECORD THAT A PERSON HAS DEALT WITH AN UNRECONCILED PAYMENT.
 *
 * `plan_purchase_intents` records what a purchase was, and `PlanPurchaseIntent::unreconciled()`
 * surfaces the ones where money was taken and no entitlement exists. What it had nowhere to put
 * was the other half of that conversation: the answer. So the question could only ever be open.
 *
 * The independent financial review of #204 named the consequence (its finding 9).
 * `payments:reconcile` adds `unreconciled()`'s whole count to its exit status, and no state ends a
 * row's membership of that query — `--acknowledge` reaches the SEPARATE module ledger, which has
 * these three columns already. So one definite provider NO made the command exit 1 on every later
 * run, for ever. Since it is the only reader of "captured, nothing delivered" in the application,
 * the next genuinely new loss would have arrived behind a red status weeks old.
 *
 * Three nullable columns, mirroring `module_payment_initiations` exactly so one command can speak
 * about both ledgers in the same words.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * DEVELOPMENT AND TEST ONLY, like the table it extends, AND THIS FILE DOES NOT ENFORCE THAT
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `plan_purchase_intents` does not exist in production (D-31, D-15 condition 9), so neither does
 * this. Promotion of the three dev-only payment migrations is the owner's decision and is recorded
 * as outstanding; nothing here assumes it has been taken.
 *
 * WHAT THAT MEANS FOR A DATABASE MIGRATED BEFORE THIS CHANGE, which is the case that actually has
 * to work: `PlanPurchaseIntent::available()` tests the TABLE, not the columns, so a reader that
 * referenced them unconditionally would throw against such a database — and the read-only queries
 * swallow their failures, so `unreconciled()` would have returned EMPTY and hidden the very losses
 * it exists to show. Nothing in that path references these columns. `unreconciled()` and
 * `awaitingReconciliation()` are untouched; every reader of the acknowledgement reads it as
 * `$row->acknowledged_at ?? null`, so an absent column reads as "not acknowledged" and the
 * behaviour degrades to exactly what it was before this migration. Only `acknowledge()` and
 * `acknowledgementAvailable()` name them, and the first asks the second.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * `down()` DROPS THEM, which is the opposite of the GATE F convention, deliberately
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * CLAUDE.md records why every GATE F `down()` is a no-op: two drafts dropped a column *because* it
 * existed and a rollback destroyed 45 real `cost_price` values. That reasoning is about columns
 * that exist in production holding data nobody migrated. These do not exist in production at all,
 * and `2026_10_31_000200`'s own `down()` already drops the whole table — so a no-op here would
 * leave a rollback unable to re-run `up()`, while protecting data that cannot exist. The guard
 * is `hasColumn()` per column rather than a bare `dropColumn()`, so a partial state rolls back.
 */
return new class extends Migration
{
    private const TABLE = 'plan_purchase_intents';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            /*
             * Not an error. The table is created by its own migration and does not exist in
             * production; a sorted run always reaches that one first, and if it has not been run
             * there is nothing to add a column to.
             */
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(self::TABLE, 'acknowledged_at')) {
                /* Indexed: every reader that filters on it filters on IS NULL. */
                $table->timestamp('acknowledged_at')->nullable()->index();
            }

            if (! Schema::hasColumn(self::TABLE, 'acknowledged_by')) {
                /*
                 * The `users.id` of whoever recorded it, or NULL for a console run with no
                 * session — which is every run today, since `payments:reconcile` has no
                 * authenticated actor. No foreign key, matching `module_payment_initiations`:
                 * the row must outlive the person, exactly as an audit row does.
                 */
                $table->unsignedBigInteger('acknowledged_by')->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'acknowledged_reason')) {
                /* 255, like `failure_reason` beside it, and truncated by the writer rather than the engine. */
                $table->string('acknowledged_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        /*
         * THE INDEX GOES FIRST, AND THIS ROLLBACK THREW WITHOUT IT. Dropping an indexed column on
         * SQLite -- the engine the whole suite runs on -- fails with
         * `General error: 1 error in index plan_purchase_intents_acknowledged_at_index after drop
         * column`, because SQLite leaves the index behind and then finds it invalid. MySQL
         * auto-drops a single-column index, so MySQL never showed it.
         *
         * Found by independent review, which executed this `down()` rather than reading it. Nothing
         * in CI would have: `MigrationPortabilityTest` drives `migrate:fresh` only, and no step runs
         * `migrate:rollback`. It matters because this file deliberately departs from the GATE F
         * no-op convention on the argument that a no-op would leave a rollback unable to re-run
         * `up()` -- an argument that needs the rollback to actually work.
         */
        if (Schema::hasColumn(self::TABLE, 'acknowledged_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(['acknowledged_at']);
            });
        }

        foreach (['acknowledged_at', 'acknowledged_by', 'acknowledged_reason'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
