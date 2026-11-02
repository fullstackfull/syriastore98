<?php

use App\Support\Database\GateFSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GATE F -- bring `zid_orders` under version control. Not money-bearing.
 *
 * ORDERS INGESTED FROM ZID, with the raw payload kept for replay. `store_id` is again ZID's own
 * `varchar(191)` identifier and not a foreign key. `total` is `decimal(14,4)` -- a DIFFERENT scale
 * from this platform's own money columns, measured and preserved because it mirrors what the
 * upstream API sends.
 *
 * `zid_orders` is one of the two SCHEMA-01 skips that are NOT among the six money tables; the other
 * is the `newsletters.user_id` column.
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
        if (Schema::hasTable('zid_orders')) {
            // Existing table: prove it matches production instead of assuming it does.
            GateFSchemaGuard::assertCompatible('zid_orders');

            return;
        }

        Schema::create('zid_orders', function (Blueprint $table) {
            // The measured production charset and collation, set per table because they are NOT
            // uniform across the fifteen and the application default ('utf8mb4_unicode_ci' is not
            // always it) would silently substitute its own.
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedBigInteger('zid_order_id');
            $table->string('store_id', 191);
            $table->string('customer_name', 191)->nullable();
            $table->string('customer_mobile', 191)->nullable();
            $table->string('city', 191)->nullable();
            $table->text('address')->nullable();
            $table->decimal('total', 14, 4)->nullable();
            $table->string('payment_method', 191)->nullable();
            $table->string('status_code', 191)->nullable();
            $table->longText('raw');
            $table->string('parcel_status', 20)->default('pending');
            $table->string('parcel_tracking', 191)->nullable();
            $table->text('parcel_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique('zid_order_id', 'zid_orders_zid_order_id_unique');
            $table->index('store_id', 'zid_orders_store_id_index');
            $table->index('parcel_status', 'zid_orders_parcel_status_index');
        });
    }

    public function down(): void
    {
        // DELIBERATELY NOT `dropIfExists`, and not a no-op by oversight: these are ingested orders, including ones not yet reconciled.
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
