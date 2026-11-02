<?php

use App\Support\Database\GateFSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GATE F -- bring `store_wallet_transactions` under version control. ONE OF THE SIX MONEY-BEARING TABLES.
 *
 * THE WALLET LEDGER. `direction` is an `enum('credit','debit')` and NOT NULL: an earlier
 * provisional draft turned this column into a nullable `varchar(255)`, which would have made the
 * ledger's credit/debit discriminator free text. `balance_before`/`balance_after` make each row
 * self-checking against its neighbours.
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
        if (Schema::hasTable('store_wallet_transactions')) {
            // Existing table: prove it matches production instead of assuming it does.
            GateFSchemaGuard::assertCompatible('store_wallet_transactions');

            return;
        }

        Schema::create('store_wallet_transactions', function (Blueprint $table) {
            // The measured production charset and collation, set per table because they are NOT
            // uniform across the fifteen and the application default ('utf8mb4_general_ci' is not
            // always it) would silently substitute its own.
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';

            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('wallet_id');
            $table->string('currency_code', 10)->nullable();
            $table->string('type', 50);
            $table->enum('direction', ['credit', 'debit']);
            $table->decimal('amount', 15, 2)->default(0.00);
            $table->decimal('balance_before', 15, 2)->default(0.00);
            $table->decimal('balance_after', 15, 2)->default(0.00);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('completed');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('store_id', 'idx_wallet_transactions_store_id');
            $table->index('wallet_id', 'idx_wallet_transactions_wallet_id');
            $table->index('type', 'idx_wallet_transactions_type');
            $table->index('created_at', 'idx_wallet_transactions_created_at');
            $table->index(['reference_type', 'reference_id'], 'idx_wallet_transactions_reference');

            $table->foreign('store_id', 'fk_wallet_transactions_store')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('wallet_id', 'fk_wallet_transactions_wallet')->references('id')->on('store_wallets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // DELIBERATELY NOT `dropIfExists`, and not a no-op by oversight: this is the wallet ledger itself -- the audit trail of every balance change.
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
