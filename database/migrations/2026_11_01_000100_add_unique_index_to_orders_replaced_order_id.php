<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E13 / D-15 CONDITION 8 — "ONE REPLACEMENT PER ORIGINAL" BECOMES A DATABASE INVARIANT.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THE PHP CHECK WAS NOT ENOUGH, measured rather than assumed
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `OrderReplacement::cancelAndReplace()` re-tested `Order::where('replaced_order_id', $id)->exists()`
 * INSIDE its transaction, and that reads as a concurrency guard. It is not one. Two transactions
 * can both run that SELECT before either INSERTs: under MySQL's default REPEATABLE READ the
 * statement takes no gap lock on a **non-unique** index, so neither sees the other's uncommitted
 * row and both commit. The owner's directive names the remedy precisely — *"using an actual
 * database-enforced invariant where necessary"* — and this is the necessary one, because the thing
 * being protected is a money-and-stock operation.
 *
 * A UNIQUE index is what makes the second INSERT fail rather than succeed. The service then
 * catches the integrity violation and answers `already_replaced`, so a genuine double-submit gets
 * the same named refusal it got before — but now because the database refused it, not because a
 * SELECT happened to be lucky.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * MULTIPLE NULLS ARE FINE, AND THAT IS THE WHOLE REASON THIS IS SAFE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Every ordinary order has `replaced_order_id = NULL`. The SQL standard treats NULLs as distinct
 * in a UNIQUE index, and MySQL, MariaDB and SQLite all implement that, so a unique index here
 * constrains **only** rows that actually claim an original. Nothing about existing orders changes.
 * This is also why a unique index can be added to a live table without first de-duplicating it —
 * though the guard below still checks, because a pre-existing duplicate would make `up()` fail
 * loudly rather than silently leave the table unprotected.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * DEVELOPMENT AND TEST ONLY — the same statement as the column's own migration
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * D-15 condition 9 authorises the link schema in isolated dev/test databases only. As with
 * `2026_10_31_000300`, this file does not and cannot enforce that: it is a property of who may run
 * a migration against production. Said here so it is not mistaken for enforcement.
 *
 * NO FOREIGN KEY IS ADDED, for the reasons the column's migration records: `orders` is read by 70
 * vendored modules and an FK would change what `DELETE FROM orders` does for all of them.
 */
return new class extends Migration
{
    private const INDEX = 'orders_replaced_order_id_unique';

    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'replaced_order_id')) {
            return;
        }

        if ($this->indexExists(self::INDEX)) {
            return;
        }

        /*
         * A PRE-EXISTING DUPLICATE MUST STOP THIS, not be worked around. If two orders already
         * claim one original then the invariant is already violated and creating the index would
         * fail anyway; this turns that into a message naming the rows.
         */
        $duplicates = DB::table('orders')
            ->select('replaced_order_id', DB::raw('COUNT(*) as n'))
            ->whereNotNull('replaced_order_id')
            ->groupBy('replaced_order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('n', 'replaced_order_id');

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'orders.replaced_order_id already holds duplicates, so the E13 one-replacement-per-original '
                . 'invariant is violated and cannot be enforced without a decision about which row to keep. '
                . 'Originals with more than one replacement: ' . $duplicates->keys()->implode(', ')
            );
        }

        Schema::table('orders', function ($table) {
            $table->unique('replaced_order_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! $this->indexExists(self::INDEX)) {
            return;
        }

        Schema::table('orders', function ($table) {
            $table->dropUnique(self::INDEX);
        });
    }

    /**
     * Driver-agnostic introspection, because this runs on SQLite in the suite and MySQL/MariaDB
     * everywhere else, and each has its own catalogue syntax. `Schema::getIndexes()` covers both,
     * so no raw driver-specific statement is issued here at all.
     *
     * (Worded without naming MySQL's catalogue statement on purpose: `MigrationHygieneTest` scans
     * migration FILES as text for MySQL-only SQL, and it cannot tell a comment from a query. It
     * flagged this file when the previous wording mentioned that statement by name. The ratchet is
     * right to be a dumb text scan — a smarter one is a parser nobody will maintain — so the
     * comment moves rather than the check.)
     */
    private function indexExists(string $name): bool
    {
        try {
            foreach (Schema::getIndexes('orders') as $index) {
                if (($index['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (\Throwable $theDriverCannotIntrospect) {
            return false;
        }

        return false;
    }
};
