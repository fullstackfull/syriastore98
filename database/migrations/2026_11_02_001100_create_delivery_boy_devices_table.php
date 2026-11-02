<?php

use App\Support\Database\GateFSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GATE F -- bring `delivery_boy_devices` under version control. Not money-bearing.
 *
 * DRIVER PUSH-NOTIFICATION DEVICE REGISTRATIONS. `fcm_token` is `varchar(500)`, which is what an
 * FCM registration token actually needs; the composite `idx_boy_active_token` is the index the
 * "send to this driver's live devices" read uses.
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
        if (Schema::hasTable('delivery_boy_devices')) {
            // Existing table: prove it matches production instead of assuming it does.
            GateFSchemaGuard::assertCompatible('delivery_boy_devices');

            return;
        }

        Schema::create('delivery_boy_devices', function (Blueprint $table) {
            // The measured production charset and collation, set per table because they are NOT
            // uniform across the fifteen and the application default ('utf8mb4_unicode_ci' is not
            // always it) would silently substitute its own.
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedBigInteger('delivery_boy_id');
            $table->string('fcm_token', 500);
            $table->string('device_id', 255)->nullable();
            $table->string('platform', 50)->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('delivery_boy_id', 'delivery_boy_devices_delivery_boy_id_foreign');
            $table->index(['delivery_boy_id', 'is_active'], 'idx_boy_active_token');

            $table->foreign('delivery_boy_id', 'delivery_boy_devices_delivery_boy_id_foreign')->references('id')->on('delivery_boys')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // DELIBERATELY NOT `dropIfExists`, and not a no-op by oversight: these are live push registrations; dropping them silently stops driver notifications.
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
