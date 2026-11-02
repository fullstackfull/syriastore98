<?php

use App\Support\Database\GateFSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GATE F -- bring `store_order_financials` under version control. ONE OF THE SIX MONEY-BEARING TABLES.
 *
 * THE PER-ORDER PROFIT AND LOSS ROW, and the table `OrderFinancialService::createForOrder()` writes
 * when an order CREDITS A MERCHANT WALLET. `uq_store_order_financial_order (order_id)` is the
 * constraint that makes "one financial row per order" -- i.e. NO DUPLICATE FINANCIAL ENTRIES -- an
 * enforced guarantee instead of a hopeful property of the current rows.
 *
 * SEQUENCING, and why this file is numbered after the cost_price one. `product_cost` is computed
 * from `products.cost_price`; with no such column `OrderFinancialService` reads null, `?? 0` makes
 * the unit cost zero, and every P&L row is all-margin. `products.cost_price` is created by
 * `2026_10_26_000100_add_cost_price_to_products_table.php`, which sorts BEFORE this file.
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
        if (Schema::hasTable('store_order_financials')) {
            // Existing table: prove it matches production instead of assuming it does.
            GateFSchemaGuard::assertCompatible('store_order_financials');

            return;
        }

        Schema::create('store_order_financials', function (Blueprint $table) {
            // The measured production charset and collation, set per table because they are NOT
            // uniform across the fifteen and the application default ('utf8mb4_general_ci' is not
            // always it) would silently substitute its own.
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';

            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('wallet_id');
            $table->unsignedBigInteger('order_id');
            $table->string('currency_code', 10)->nullable();
            $table->string('currency_symbol', 20)->nullable();
            $table->decimal('product_amount', 15, 2)->default(0.00);
            $table->decimal('discount_amount', 15, 2)->default(0.00);
            $table->decimal('tax_amount', 15, 2)->default(0.00);
            $table->decimal('shipping_amount', 15, 2)->default(0.00);
            $table->decimal('customer_total', 15, 2)->default(0.00);
            $table->decimal('product_cost', 15, 2)->default(0.00);
            $table->decimal('gross_profit', 15, 2)->default(0.00);
            $table->enum('payment_status', ['unpaid', 'pending', 'paid', 'partially_paid', 'refunded', 'cancelled'])->default('unpaid');
            $table->decimal('paid_amount', 15, 2)->default(0.00);
            $table->decimal('remaining_amount', 15, 2)->default(0.00);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique('order_id', 'uq_store_order_financial_order');
            $table->index('store_id', 'idx_order_financial_store');
            $table->index('wallet_id', 'idx_order_financial_wallet');
            $table->index('payment_status', 'idx_order_financial_payment_status');

            $table->foreign('order_id', 'fk_order_financial_order')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('store_id', 'fk_order_financial_store')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('wallet_id', 'fk_order_financial_wallet')->references('id')->on('store_wallets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // DELIBERATELY NOT `dropIfExists`, and not a no-op by oversight: these are the per-order profit and loss rows.
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
