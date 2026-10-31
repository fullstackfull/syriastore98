<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E13 / D-30 — THE DURABLE STOCK-EVIDENCE RECORD.
 *
 * **DEVELOPMENT AND TEST ONLY.** D-30 permits "local isolated MariaDB, development migrations and
 * safe test environments" and forbids production migrations outright. This table does not exist in
 * production and nothing here may be run there. The reading code treats its absence as "no evidence
 * available" rather than "no movement happened" — see `StockMovement::available()`.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT EXISTS, AND WHY ONLY NOW.
 *
 * D-15 forbids inferring stock movement from order status, payment status, a flag or the existence
 * of an order. The 2026-10-26 analysis then found the evidence undesignable, for a specific reason
 * recorded in D-21: *"A movement record written from the shared service would be SILENT on all
 * five [raw writers], so an absent record would mean 'no decrement, OR a decrement on an
 * uninstrumented writer' — the exact inference D-15 forbids."*
 *
 * **D-21 REMOVED THAT OBJECTION**, and `E13-ARCHITECTURE-REEVALUATION-2026-10-31.md` re-measures it:
 * `ProductStockService` is now the ONLY mutator of `products.product_stock` and
 * `product_variants.stock` in `app/` — the single remaining hit outside it is a commented-out line
 * at `PosController:208`. So a record written inside its four methods observes every movement on
 * every order path, and **absent now means absent**.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THE COLUMNS ARE FOR, since each answers a question D-15 asks.
 *
 * `order_id` — WHICH ORDER consumed or returned the stock. Nullable, because not every movement
 *   belongs to an order (a merchant editing a product's stock by hand is not an order event), and a
 *   NULL here is honest: it says "this movement was not attributed", never "there was no order".
 * `direction` — `out` or `in`. Not a signed quantity: a signed column invites a `SUM()` that reads
 *   as a balance, and a balance is the inference D-15 forbids. Each row is one EVENT.
 * `applied` — whether the write actually changed a row. A REFUSED decrement (insufficient stock)
 *   and a SKIPPED one (no quantity, no row) must be distinguishable from an applied one, because
 *   "the order tried and failed" is a different fact from "the order took stock".
 * `outcome` — the service's own result code, stored verbatim, so a reader is not re-deriving it.
 * `store_id` — the tenant predicate, explicit. `Product` is NOT in `config/tenancy.php`'s
 *   `scoped_models`, so no global scope supplies it and every read of this table must name it.
 * `reason` — the event class (`order_place`, `order_cancel`, `order_return`, `refund`, `manual`),
 *   so the five cancellation doors become distinguishable in the record even though they write
 *   identical order rows.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IT IS NOT. It is not a ledger and must not be summed into a stock balance: the authoritative
 * stock is still `products.product_stock`. This table answers "did this order move stock, when, in
 * which direction, and did it apply" — nothing more. E13 needs exactly that and no more.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* The tenant. Explicit, never inferred -- Product carries no StoreScope. */
            $table->unsignedBigInteger('store_id')->index();

            /* WHICH ORDER. Nullable and honest: NULL means "not attributed", not "no order". */
            $table->unsignedBigInteger('order_id')->nullable()->index();

            /* The row that moved. One of the two is set; both are indexed because E13 asks
               "did THIS line move?" and a line is a product or a variant. */
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('variant_id')->nullable()->index();

            /* `out` = consumed by an order, `in` = given back. NOT a signed quantity: see above. */
            $table->string('direction', 3);
            $table->unsignedInteger('quantity');

            /* Did it actually change a row, and what did the service call the outcome. */
            $table->boolean('applied')->default(false);
            $table->string('outcome', 24)->nullable();

            /* The event class, so the five identical-looking cancellation doors stay distinguishable. */
            $table->string('reason', 32)->nullable();

            $table->timestamps();

            /* The query E13 actually runs: "what did order N do to this line, in this store?" */
            $table->index(['store_id', 'order_id'], 'sm_store_order');
            $table->index(['store_id', 'order_id', 'product_id'], 'sm_store_order_product');
            $table->index(['store_id', 'order_id', 'variant_id'], 'sm_store_order_variant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
