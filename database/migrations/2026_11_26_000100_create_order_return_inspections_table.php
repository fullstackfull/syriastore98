<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RET-002 — THE RECORDED VERDICT OF A RETURNED ITEM'S CONDITION.
 *
 * The master plan's 04-F clause, verbatim: *"فحص حالة المنتج قبل إعادته للبيع"* — inspect the
 * product's condition before returning it to sale. `DECISION-ID-REGISTER.md` carries it as
 * `RET-002`; it appears in neither `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` nor
 * `MASTER-PLAN-GAP-REGISTER.md`, so it has never been tracked.
 *
 * **DEVELOPMENT AND TEST ONLY, exactly as `module_payment_initiations`, `plan_purchase_intents`
 * and `orders.replaced_order_id` are, and for the reason their headers give:** the restriction is
 * on who may run a migration against production, which is the owner's, and it is not a property of
 * this file. Nothing here has been run against production and nothing may be without that
 * decision.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT WAS MISSING, MEASURED RATHER THAN INFERRED
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * A census over `app/` and `database/migrations/` for `inspect`, `condition`, `resellable`,
 * `quarantine` and `damaged` returns, outside unrelated prose, exactly two live things: the
 * `FlashSale` rule vocabulary and `Customer::$fields_status`. Neither is about a returned item.
 * The one string in the whole platform that names an item's condition is a shopper-stated REASON
 * on the refund request — `OrderRefund::RefundReason()`'s *"The Product Was Damaged Upon
 * Arrival."* — and a grep for `refund_reason` shows it is written by
 * `AccountProfileController::order_refund_request()` and read by three Blade files to DISPLAY it.
 * **No code anywhere reads it to decide anything.**
 *
 * Meanwhile THREE doors put returned goods back into sellable stock:
 *
 *   1. `OrderRefundController::RefundStock()`       the refund screen's Manage Stock action
 *   2. `Order::performProductReturn()`              the merchant marking ONE line returned
 *   3. `OrderController::restoreReturnedStock()`    the whole-order return approval (`status == 2`)
 *
 * All three credit stock with no condition record of any kind, so a damaged return became
 * sellable inventory on exactly the same evidence as a pristine one. That is the gap this table
 * closes: it gives the verdict somewhere to live, and `App\Services\ReturnInspection` is the only
 * thing that reads it.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * ADDITIVE, AND IT CHANGES NOTHING ON ITS OWN
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * A new table, no column altered, no row rewritten, `down()` drops only what `up()` created. With
 * the table empty every one of the three doors behaves byte-for-byte as it did before — the gate
 * blocks a line **only** when a row exists for it AND that row says the goods are not resellable.
 * So this cannot withhold stock from a merchant who has recorded nothing, which is the whole
 * platform today.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * `variant_id` IS NOT NULL WITH DEFAULT 0, AND THAT IS THE CODEBASE'S OWN SPELLING
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Every return door tells a simple product from a variant with `$variant_id == 0 || empty(...)`
 * (`Order::performProductReturn()`, `OrderController::restoreReturnedStock()`), and `product_json`
 * lines carry `variant_id: 0` for a simple product. A NULLable column would make the unique index
 * below behave differently on MySQL and SQLite — the SQL standard treats NULLs as distinct, which
 * is the property `2026_11_01_000100_add_unique_index_to_orders_replaced_order_id` relies on in
 * the OPPOSITE direction — so two verdicts for one simple product would both be insertable and the
 * gate would have to pick. `0` makes the key total and the index real.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * UNIQUE (store_id, order_id, product_id, variant_id) — ONE CURRENT VERDICT PER RETURNED LINE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * A re-inspection replaces the verdict rather than appending a second one, so the gate never has
 * to resolve a disagreement between two rows. `RefundPolicy::allows()` documents what the
 * alternative costs: `order_refund_settings` permits duplicate rows, three reads of it carry no
 * `ORDER BY`, and the screen and the gate **can** therefore disagree about the same store. A gate
 * on stock must not inherit that. The trail of who changed a verdict and when is
 * `AuditLog::record()`'s, which is where this codebase already keeps actor history.
 *
 * **NO FOREIGN KEYS, for the reason `orders.replaced_order_id` gives:** `orders` and `products`
 * are read by 70 vendored modules, a real FK changes what a `DELETE` does for all of them, and the
 * integrity this needs is *"the row belongs to this store"* — which a FK cannot express and
 * `ReturnInspection` writes in PHP, where the store predicate can be written. `store_id` is here
 * because `OrderRefund` is not tenant-scoped either and `RefundIntegrityService::
 * awaitingSettlement()` takes the same position: the predicate is an argument, not an inheritance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_return_inspections')) {
            return;
        }

        Schema::create('order_return_inspections', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * The tenancy predicate. Not inherited from a scope: `OrderReturnInspection` is not in
             * `config/tenancy.php`'s `scoped_models`, and `null_tenant => 'open'` means a scope
             * that does not resolve a tenant adds NO predicate at all (CLAUDE.md). Every read in
             * `ReturnInspection` writes `where('store_id', ...)` itself.
             */
            $table->unsignedBigInteger('store_id')->index();

            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');

            /* 0 for a simple product — see the header. */
            $table->unsignedBigInteger('variant_id')->default(0);

            /*
             * One of `App\Services\ReturnInspection::VERDICTS`. A plain string, like
             * `order_refunds.refund_status`, and for the same reason: a database enum would have
             * to be migrated to add a verdict, and the vocabulary is the service's.
             */
            $table->string('verdict', 32);

            /*
             * THE DERIVED ANSWER, STORED, so the gate is one column read and cannot drift from a
             * verdict list a later reader forgets to extend. `ReturnInspection::record()` is the
             * only writer and derives it from `VERDICTS`; `ReturnInspectionVocabularyTest` asserts
             * the two cannot disagree.
             *
             * Default 0 — an unrecognised or half-written row reads NOT resellable, which is the
             * direction that withholds goods from sale rather than putting a damaged item on it.
             */
            $table->boolean('resellable')->default(0);

            /* How many units this verdict covers, when the merchant recorded a number. */
            $table->unsignedInteger('units')->nullable();

            $table->text('notes')->nullable();

            /* `users.id` of whoever recorded it. Nullable: a row written by a future automated
               path would have no user, and inventing one would be a false attribution. */
            $table->unsignedBigInteger('inspected_by')->nullable();

            $table->timestamps();

            $table->unique(
                ['store_id', 'order_id', 'product_id', 'variant_id'],
                'order_return_inspections_line_unique'
            );
        });
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as `create_marketing_nudge_sends_table` and every GATE F
     * migration — and the opposite of the first draft of this file, which called
     * `Schema::dropIfExists()`.
     *
     * The rows here are a HUMAN'S VERDICT about physical goods, and they are the only thing keeping
     * a damaged return off the shelf: `ReturnInspection::blocksRestock()` fails OPEN, so a table
     * that disappears does not refuse the next restock — it silently permits every one of them.
     * A `down()` that dropped this table would therefore undo a stock control without a word, and
     * the verdicts a merchant recorded by hand would be unrecoverable.
     *
     * Two GATE F drafts dropped a column *because* it existed and a rollback destroyed 45
     * `cost_price` values; the lesson this repository took from that is that a reversible-looking
     * `down()` on a data table is a loaded gun. Removing this table is a deliberate operator
     * action, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
