<?php

use App\Support\Database\GateFSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GATE F -- bring `delivery_earnings` under version control. ONE OF THE SIX MONEY-BEARING TABLES.
 *
 * DRIVER COMMISSION PER DELIVERED ORDER, written by `OrderController::order_assign()`. Its money
 * scales are `decimal(10,2)` and `decimal(5,2)` -- NARROWER than the other money tables' `(15,2)`,
 * measured and not harmonised, because widening a live money column is a separate deliberate change.
 *
 * SEQUENCING: it is numbered after the `delivery_boys` commission columns, which supply the
 * percentage this table records.
 *
 * ONE DOCUMENTED DIFFERENCE FROM PRODUCTION, stated rather than papered over: production carries
 * Arabic `COMMENT` text on five of these columns, which reached
 * `docs/GATE-F-VERIFIED-SCHEMA.md` as `?????` through the dump's encoding. Inventing Arabic text to
 * match would be worse than omitting it, and a column comment enforces nothing, so the comments are
 * absent here and `GateFSchemaGuard` does not compare them.
 *
 * WHERE THIS DEFINITION COMES FROM. Not from `$fillable`, and not typed by hand. It is derived
 * from `App\Support\Database\GateFProductionSchema`, whose DDL is a verbatim copy of
 * `docs/GATE-F-VERIFIED-SCHEMA.md` section 2 -- generated from `information_schema` on a RESTORED
 * COPY of the production dump (commit `3935dee8`, read 2026-10-24, MariaDB 11.4.9).
 * `tests/Unit/Database/GateFMigrationParityTest` re-derives the expected Blueprint from that same
 * source and compares it with this file, so the two cannot drift apart in silence.
 *
 * WHAT RUNNING THIS AGAINST PRODUCTION DOES: NOTHING, BY DESIGN. The table already exists there
 * with live data. The point of this migration is to put the schema under version control and to
 * give a FRESH environment -- CI, staging, a new tenant database -- the same structure production
 * already has. It does NOT reconcile production to this repository and must never be reported as
 * having done so.
 *
 * WHY `Schema::hasTable()` ALONE IS NOT THE GUARD. A name check proves a `CREATE` would fail; it
 * does not prove that skipping the `CREATE` is safe. If a table with the WRONG types were already
 * present, a bare `hasTable()` guard would return early, `migrate:status` would then report this
 * migration as "Ran", and the wrong schema would become permanent AND invisible. So when the table
 * exists, `GateFSchemaGuard::assertCompatible()` compares the LIVE STRUCTURE against the measured
 * one and THROWS on any disagreement, naming it field by field. Throwing changes nothing, records
 * nothing, and stops the run -- which is the safe outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_earnings')) {
            // Existing table: prove it matches production instead of assuming it does.
            GateFSchemaGuard::assertCompatible('delivery_earnings');

            return;
        }

        Schema::create('delivery_earnings', function (Blueprint $table) {
            // The measured production charset and collation, set per table because they are NOT
            // uniform across the fifteen and the application default ('utf8mb4_unicode_ci' is not
            // always it) would silently substitute its own.
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedBigInteger('delivery_boy_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->decimal('shipping_cost', 10, 2)->default(0.00);
            $table->decimal('commission_percentage', 5, 2)->default(0.00);
            $table->decimal('commission_amount', 10, 2)->default(0.00);
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('delivery_boy_id', 'delivery_earnings_delivery_boy_id_foreign');
            $table->index('order_id', 'delivery_earnings_order_id_foreign');
            $table->index('shipping_method_id', 'delivery_earnings_shipping_method_id_foreign');
            $table->index(['delivery_boy_id', 'status'], 'idx_boy_status');
            $table->index('order_id', 'idx_order_id');

            $table->foreign('delivery_boy_id', 'delivery_earnings_delivery_boy_id_foreign')->references('id')->on('delivery_boys')->cascadeOnDelete();
            $table->foreign('order_id', 'delivery_earnings_order_id_foreign')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('shipping_method_id', 'delivery_earnings_shipping_method_id_foreign')->references('id')->on('shipping_methods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // DELIBERATELY NOT `dropIfExists`, and not a no-op by oversight: these are driver commissions owed or already paid.
        //
        // Every one of the fifteen GATE F tables exists in production WITH LIVE DATA, so a
        // rollback that dropped one would be a destructive production operation dressed as a
        // routine `migrate:rollback`. Two of the original drafts did exactly that -- measured, not
        // reasoned: their `down()` dropped a column BECAUSE it existed, and a rollback against a
        // copy of production destroyed all 45 `products.cost_price` values and both
        // `delivery_boys` commission columns with their data. Drop this table by hand, on purpose,
        // with a backup, or not at all.
    }
};
