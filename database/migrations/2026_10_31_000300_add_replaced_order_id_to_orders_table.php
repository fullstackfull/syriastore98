<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E13 REQUIREMENT D — THE LINKED ORIGINAL ORDER. One additive, nullable column.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * DEVELOPMENT AND TEST ONLY, and as with `plan_purchase_intents` this file does not enforce that
 * — the deploy does.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * D-15 and D-07 authorise this in isolated dev/test databases only. `up()` tests `Schema` and
 * nothing else, so a `php artisan migrate` creates the column wherever it is run; the restriction
 * is on **who may run a migration against production**, which is the owner's, and it is not a
 * property of this file. Saying otherwise in a docblock while the code does not enforce it is the
 * gap independent review found in the sibling migration, so it is not repeated here.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A COLUMN AND NOT A PIVOT
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * A replacement has exactly one original, and an original has at most one replacement. A nullable
 * self-reference says that; a pivot would permit a many-to-many the design forbids, and the
 * forbidding would then live only in prose.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * NO FOREIGN KEY, DELIBERATELY — a departure from the design document, with its reason
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `E13-CANCEL-AND-REPLACEMENT-DESIGN.md` §7 says "FK to `orders.id`". It is indexed here and NOT
 * constrained, for two measured reasons:
 *
 *   1. `orders` is **not** tenant-scoped and is read by 70 vendored modules. A real FK changes
 *      what `DELETE FROM orders` does for every one of them — on MySQL the default
 *      `ON DELETE RESTRICT` would start refusing deletions that succeed today, which is a
 *      behaviour change to shared code well outside this slice.
 *   2. The integrity this needs is "the original exists and belongs to this store", and a FK
 *      cannot express the second half. `cancelAndReplace()` checks both in PHP, where the store
 *      predicate can be written.
 *
 * The index is what the column is actually for: `replacementOrder()` looks an original up by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'replaced_order_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('replaced_order_id')->nullable()->after('id');
            $table->index('replaced_order_id', 'orders_replaced_order_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'replaced_order_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_replaced_order_id_index');
            $table->dropColumn('replaced_order_id');
        });
    }
};
