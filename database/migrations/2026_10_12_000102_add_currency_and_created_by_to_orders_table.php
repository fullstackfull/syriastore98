<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA-02 / SEC-R5-02 — `orders.currency_code`, `orders.currency_symbol`
 * NEW-UNDECLARED-SCHEMA (the `orders.created_by` half only) / MONEY-08
 *
 * NOT PROVISIONAL for the currency pair; see the note on `created_by` below. These shapes are
 * derivable from code without a production dump, which is why they land here rather than
 * behind GATE F with the 15 undeclared finance tables.
 *
 * EVIDENCE — currency pair
 *   - `database/migrations/2024_01_23_061635_create_orders_table.php` creates `orders` with
 *     neither column, and grep for "currency_code" / "currency_symbol" over both migration
 *     trees (207 files) returns 0 hits.
 *   - Both are in `$fillable` at app/Models/Order.php:26-27.
 *   - A MODEL HOOK assigns both on EVERY insert: app/Models/Order.php:63 `booted()`,
 *     :65 `static::creating(...)`, :68-72 sets `currency_code`
 *     (`Utility::GetValueByName('defult_currancy', $storeId)` -> `'currency'` -> `'USD'`),
 *     :74-78 the same for `currency_symbol` falling back to `'$'`. Both attributes are empty
 *     on a fresh insert, so BOTH branches fire unconditionally on every order-creation path.
 *
 * WHY THIS IS CRITICAL. Because the writer is a model hook and not a controller, NO middleware
 * and no route change can gate it: storefront checkout, merchant/POS creation and the order
 * APIs the Flutter app calls are all affected. On a from-zero build every order insert dies
 * with "unknown column currency_code", so the app's order-create endpoints return 500.
 *
 * COLUMN SHAPES, and why these and not others.
 *   - `currency_code`  string(3), nullable. ISO 4217 alpha-3; the hook's own fallback is 'USD'.
 *   - `currency_symbol` string(8), nullable. Wider than 3 on purpose: symbols are multi-byte
 *     and multi-character ('ل.س' for the Syrian pound is 3 characters / 5 bytes; 'ر.س' and
 *     'د.ك' likewise). 8 is a deliberate margin, not a guess at a production width.
 *   Both nullable so the migration is additive against existing rows, which have no value to
 *   backfill from. The hook populates them going forward. NOTE: historical rows keep NULL —
 *   this migration does NOT backfill, because the correct per-order historical rate is not
 *   recoverable from code, and inventing one would be exactly the silent divergence GATE F
 *   exists to prevent. Reading code must tolerate NULL on pre-migration orders.
 *
 * DO NOT TOUCH THE HOOK. A per-order currency snapshot is CORRECT behaviour (it is what stops
 * MONEY-10, where changing a store's currency rewrites historical order amounts); it simply
 * has no column to land in.
 *
 * `created_by` — PARTIALLY PROVISIONAL, and it does not work on its own.
 *   `app/Http/Controllers/OrderController.php:159` passes `'created_by' => auth()->id()` to
 *   `Order::create()`. The column is created here as `unsignedBigInteger` nullable + index,
 *   matching the `created_by` shape used by 67 other migrations in this tree.
 *   HOWEVER: `created_by` is NOT present in `Order::$fillable` (app/Models/Order.php:15-46),
 *   so Laravel SILENTLY DISCARDS it on mass assignment and the column stays NULL even once it
 *   exists. Adding it to `$fillable` is required to complete the fix and is NOT done here,
 *   because app/Models/Order.php is owned by another workstream in this increment. Tracked as
 *   a cross-agent dependency; this migration is a safe no-op prerequisite either way.
 *   No foreign key to `users` is declared: `users` is the highest-fan-in table in the schema
 *   (14 FKs reference it) and adding a 15th with cascade semantics is a behaviour change, not
 *   a schema catch-up.
 *
 * SAFETY. Every statement `hasColumn`-guarded and applied one column at a time, so re-running
 * against a database that already has some or all of them is a NO-OP. Purely additive and
 * nullable: no existing row changes, no existing query result changes.
 *
 * FLUTTER IMPACT: none. Additive nullable columns cannot change any JSON key's presence or
 * type for a client that reads named keys, and repo-B's models ignore unknown keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'currency_code')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('currency_code', 3)->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'currency_symbol')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('currency_symbol', 8)->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'created_by')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        // The index on `created_by` must go FIRST. Verified against SQLite 3.45.1: dropping a
        // column that is still named in an index fails outright --
        //   "error in index orders_created_by_index after drop column: no such column: created_by"
        // whereas dropping the index and then the column succeeds. MySQL tolerates either order,
        // so doing it in this order is correct on both drivers.
        if (Schema::hasColumn('orders', 'created_by')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_created_by_index');
            });
        }

        // Dropped one at a time and only when present, so rollback cannot fail part-way.
        foreach (['currency_code', 'currency_symbol', 'created_by'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
