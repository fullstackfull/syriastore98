<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F216 / OWNER DECISION D-29.3 — THE DURABLE LINK BETWEEN A GATEWAY CAPTURE AND AN ORDER.
 * =================================================================================================
 *
 * APPROVED FOR DESIGN, DEVELOPMENT AND ISOLATED DATABASE TESTING. **No production migration has
 * been run and none may be**; promoting this to a production environment is a separate owner
 * decision, and the standing prohibitions are unchanged.
 *
 * WHY THIS IS A NEW TABLE AND NOT A COLUMN ON `orders`
 * -------------------------------------------------------------------------------------------------
 * Measured first: `orders` carries **no gateway reference of any kind** — not `payment_id`, not
 * `transaction_id`, not `txn_id`. Its 39 columns include `payment_comment` and `payment_receipt`,
 * neither of which is a transaction identifier. The only `txn_id` in the schema is on
 * `plan_orders`, which is for plan purchases rather than storefront orders. So there was nothing to
 * extend and `OrderCompleteBinding` is session-based, which answers *"did this browser place this
 * order"* and cannot reconcile someone else's abandoned checkout.
 *
 * A separate table rather than a column, for four reasons:
 *
 *   1. **Idempotency by construction.** `UNIQUE (store_id, gateway, reference)` means a replayed
 *      gateway return or a re-delivered webhook carrying the same reference **cannot** create a
 *      second row. The database refuses it; the service turns that refusal into "already seen"
 *      rather than into a duplicate order. A nullable column on `orders` could not express that.
 *   2. **It can exist WITHOUT an order**, which is the whole of F216's review case.
 *      `order_id` is nullable: a capture that produced no order gets a row, and that row is the
 *      queue a merchant reviews. One table serves both "bind the order" and "record an
 *      unreconciled capture", so the two can never disagree.
 *   3. **The seven order writers stay untouched.** Adding a column means every writer either sets
 *      it or leaves it null, and F208 is the record of what happens when a property is assumed
 *      present across writers that do not share a base.
 *   4. **Tenant isolation is a column with an index**, not an inference.
 *
 * WHAT THE OWNER'S CONDITIONS MAP TO
 * -------------------------------------------------------------------------------------------------
 *   - *uniqueness* → the composite unique index.
 *   - *tenant isolation* → `store_id`, in the unique key, so two stores may legitimately see the
 *     same gateway reference without colliding.
 *   - *idempotency* → the same unique key, enforced by the engine rather than by a read-then-write
 *     that races.
 *   - *"another order in the same store is not evidence that this operation succeeded"* → the key
 *     includes the **reference**. A different reference is a different capture, and a store with a
 *     hundred paid orders tells you nothing about this one. That sentence is why the unique key is
 *     not `(store_id, order_id)`.
 *   - *gateway return, webhook and retry* → all three arrive as the same (gateway, reference) pair
 *     and are therefore the same row.
 *
 * `capture_state` CARRIES THE THREE HONEST VALUES AND NOT A BOOLEAN: `confirmed` (a verifier
 * positively said so), `unknown` (no verifier exists for this gateway, or it answered
 * UNVERIFIABLE — the money may already be gone), `rejected` (the gateway was reachable and said
 * no). Collapsing `unknown` into either of the others is the error
 * `gatewayReturnMissingProductRefusal()` already records having made once.
 *
 * AND THERE IS NO `paid` COLUMN HERE, deliberately. D-29.3: *"no automatic Paid state without
 * proof of capture."* This table records what a gateway said; it does not decide what an order's
 * payment status becomes.
 *
 * BACKFILL: none is possible, and that is a fact rather than an omission. No existing row anywhere
 * holds a gateway reference for a past order, so there is nothing to copy in. The table starts
 * empty and fills from the next capture onward; historical orders remain unlinkable, which is a
 * limit of what was recorded before this change and must not be presented as solved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_gateway_references')) {
            return;
        }

        Schema::create('order_gateway_references', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');
            $table->string('gateway', 64);
            $table->string('reference', 191);

            /* NULLABLE ON PURPOSE: a capture with no order is the row F216's review queue reads. */
            $table->unsignedBigInteger('order_id')->nullable();

            $table->string('amount', 32)->nullable();
            $table->string('capture_state', 16)->default('unknown');

            $table->timestamp('bound_at')->nullable();
            $table->timestamps();

            /*
             * 191 rather than 255 on `reference`: with utf8mb4 a composite index over
             * (store_id, gateway, reference) must stay inside InnoDB's 3072-byte key limit, and
             * 8 + 64*4 + 191*4 = 1028 bytes leaves ample room. A 255-char column would still fit
             * here, but 191 is this project's established ceiling for an indexed string and
             * matching it avoids a surprise on an older row format.
             */
            $table->unique(['store_id', 'gateway', 'reference'], 'ogr_store_gateway_reference_unique');

            $table->index('order_id', 'ogr_order_id_index');
            $table->index(['store_id', 'order_id'], 'ogr_store_order_index');
            $table->index(['store_id', 'capture_state'], 'ogr_store_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_gateway_references');
    }
};
