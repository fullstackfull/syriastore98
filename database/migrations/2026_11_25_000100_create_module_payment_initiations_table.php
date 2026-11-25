<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #204 / PHASE 06 ITEM 2(B) — THE DURABLE RECORD THAT A PAYMENT WAS INITIATED THROUGH A MODULE.
 *
 * **DEVELOPMENT AND TEST ONLY, exactly as `plan_purchase_intents` and `order_gateway_references`
 * are, and for the reason their headers give:** the restriction is on who may run a migration
 * against production, which is the owner's, and it is not a property of this file. Nothing here
 * has been run against production and nothing may be without that decision.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE HOST HAS TO KEEP THIS, MEASURED
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The owner's rule for a switched-off application: *"Keep a safe, authenticated and idempotent
 * reconciliation mechanism for previously initiated payments; do not use it to permit new
 * transactions through disabled applications."*
 *
 * Three vendored modules take payments — Donation, LotteryPrizes and Campaigns, which between them
 * own all 87 `payment-callback` keys of `ModuleRouteManifest`. Read end to end, **none of them
 * writes a database row when a payment is initiated.** The only trace is the payer's session, and
 * the handlers that complete a payment are, in order: unverified against the provider (one gateway
 * of thirty-five excepted), not idempotent, and — for Donation — willing to build the record from
 * the request when the session is empty. So "previously initiated" was not a question the
 * application could answer, and a return to a disabled module was indistinguishable from a brand
 * new transaction made by typing the return URL.
 *
 * `packages/workdo/` is vendored and is not edited. So the host records the initiation itself, from
 * `DisabledModuleRouteGuard`, and this is where.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A ROW IS, BY `status`
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 *   `initiated`   a payer submitted the module's payment form while the module was ENABLED. The
 *                 `reference` is 128 bits minted by the server and held in that payer's session.
 *   `abandoned`   the module's own handler rejected that form, so nothing reached a gateway.
 *   `superseded`  the same session started another payment through the same module.
 *   `completed`   the payer came back and the module's completion handler was allowed to run for
 *                 this initiation. ONE conditional UPDATE from `initiated`, so it happens once.
 *   `refused`     somebody arrived at a DISABLED module's completion route with no initiation to
 *                 show for it. Not a payment record — a record that a request was turned away, so
 *                 a payer whose money was taken is not turned away in silence.
 *
 * **NO COLUMN SAYS `paid`, AND NONE MAY.** D-29.3: *"no automatic Paid state without proof of
 * capture."* Nothing in these modules or in this table establishes that a provider took money; the
 * row proves that a payment was STARTED here, by this session, before the application was switched
 * off — which is the question the owner's rule turns on, and the only one it can answer.
 *
 * `reference` UNIQUE is what makes the claim replay-proof at the engine. `payload_hash` is a
 * SHA-256 of a refused request's input: it lets a repeat be recognised and counted (`attempts`)
 * without keeping a shopper's form — or a card number, which one of these gateways puts in a URL —
 * in a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('module_payment_initiations')) {
            return;
        }

        Schema::create('module_payment_initiations', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * Server-minted, 128 bits, 32 lower-case hex characters. UNIQUE is the replay story for
             * the completion claim.
             *
             * THE COLLATION IS NARROWED ON MySQL BECAUSE A MATCH ON THIS COLUMN MUST MEAN THE VALUE
             * AND NOT A SET THE DATABASE CHOOSES. Measured by
             * `verify-module-payment-boundary-on-mysql.php`: under `utf8mb4_general_ci` -- what the
             * server actually reports -- the completion claim matched a reference differing only in
             * letter case. `ModulePaymentLedger::isWellFormedReference()` closes that on every
             * engine, and this makes the column agree rather than leaving the property to one layer.
             *
             * APPLIED PER DRIVER, AND THE FIRST DRAFT'S REASON FOR NOT DOING SO WAS WRONG. It said
             * *"SQLite ignores the collation name and compares binary already"*. SQLite does not
             * ignore it: `collate 'utf8mb4_bin'` is **`SQLSTATE[HY000]: no such collation
             * sequence`**, and every test that touches this table died on it immediately. SQLite's
             * default comparison IS binary, so naming nothing there is the same property.
             */
            $reference = $table->string('reference', 64);

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $reference->collation('utf8mb4_bin');
            }

            $reference->unique();

            $table->string('module', 64);
            $table->string('status', 16)->default('initiated');

            /* The store the route's slug resolved to. NULL for Campaigns, which a merchant pays
               the PLATFORM for, and for a slug that resolves to nothing. */
            $table->unsignedBigInteger('store_id')->nullable();

            /* `METHOD uri` as the router reports it — the manifest's own key shape. */
            $table->string('route', 191);

            $table->string('payer_guard', 16)->nullable();
            $table->unsignedBigInteger('payer_id')->nullable();

            /* The method the payer chose, AS POSTED. A label for a person reading the row; it is
               caller-supplied and decides nothing. */
            $table->string('gateway', 64)->nullable();

            /* Refusals only. */
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->string('reason', 255)->nullable();

            $table->timestamp('completed_at')->nullable();

            /* A person has looked at a refusal and said what it was. */
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->string('acknowledged_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['module', 'status'], 'mpi_module_status_index');
            $table->index(['status', 'created_at'], 'mpi_status_created_index');
            $table->index('store_id', 'mpi_store_index');
            $table->index(['module', 'route', 'payload_hash'], 'mpi_refusal_dedupe_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_payment_initiations');
    }
};
