<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F229 / D-31 REQUIREMENT 1 — the durable, server-owned plan purchase intent.
 *
 * **DEVELOPMENT AND TEST ONLY.** D-31 grants development/test-only authorisation and restates the
 * prohibition on production migrations; D-15 condition 9 says the same for E13's record. This table
 * does not exist in production and nothing here may be run there. Every reader must consult
 * `PlanPurchaseIntent::available()` first and **refuse** when it is false — never treat a missing
 * table as "no intent", which would be the inference D-15 forbids, on every purchase.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT EXISTS, in one paragraph
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The plan-return family shares one shape: the provider attests that *a* payment succeeded, and
 * the handler then activates whatever plan the CALLER named. F228 closed the repeatable half (one
 * reference cannot activate twice). F229 is the substitution half — pay once for the cheapest plan
 * and name a dearer one on the FIRST return — and it cannot be closed by comparing numbers alone,
 * because nothing on the return side says which plan the money was *for*.
 *
 * Paytm is the sharpest instance and the reason this design is shaped the way it is. Its
 * verification is genuinely cryptographic: `PaytmWalletProvider::response()` AES-verifies
 * `CHECKSUMHASH` against the server-held merchant key, and `TXNAMOUNT` is INSIDE the checksummed
 * body — so the amount is provider-attested without a network call. **But the plan is not**, because
 * `ReceivePaymentProvider::beginTransaction()` drops the `plan` key when building its params. So no
 * checksum can ever bind the plan, and the plan arrives instead as a `Crypt` route parameter —
 * forgeable, because `APP_KEY` is in git history and unrotated (CLAUDE.md records this: `Crypt` is
 * an encoding, not a control).
 *
 * **The intent is the missing binding.** The server decides, before the caller leaves for the
 * provider, exactly what is being bought and for how much, and records it against the one
 * identifier that *is* inside the attested body: the order reference it generated itself. The
 * return then reads the plan from the INTENT rather than from the request, and the request's job
 * shrinks to naming which intent it is answering.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT EACH COLUMN IS FOR, since each answers one of D-31's requirements
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `reference` — the server-generated provider order id. **UNIQUE**, and that constraint is load
 *     bearing: it is what makes "one intent per reference" a database fact rather than a
 *     convention. It is the only field that appears in both the intent and the provider's attested
 *     response, which is why the binding keys on it.
 *
 * `provider` — canonical provider name, for the per-provider amount normaliser. Requirement 3
 *     forbids a single global unit convention, and the blade this server renders proves the
 *     conventions genuinely differ (`plans/stripe.blade.php:3228` and `:3275` multiply by 100,
 *     `:3364` does not), so the provider has to travel with the amount.
 *
 * `user_id` — requirement 5's cross-user isolation. The return must refuse an intent that belongs
 *     to somebody else, and it cannot ask the request who that is.
 *
 * `plan_id` — requirement 4's binding. The return reads the plan from HERE, never from the route.
 *
 * `coupon_id` / `coupon_code` — requirement 1 names the discount explicitly. Recording both the id
 *     and the code is deliberate: the code is what the caller presented and what a reviewer will
 *     search for, and the id is what survives a code being edited. F228's own comment records that
 *     resolving a coupon by id matched nothing and silently charged the undiscounted price, which
 *     is why the code is kept rather than inferred.
 *
 * `expected_amount` / `currency` — requirement 4's amount and currency. **`DECIMAL(16, 4)`, NOT a
 *     float**, because requirement 3 forbids "imprecise floating-point comparisons" and a float
 *     equality test on money is exactly that. Four decimal places rather than two so a provider
 *     that reports more precision than it was sent can be compared without truncation deciding the
 *     answer. The comparison itself is `bccomp` at an explicit scale, never `==`.
 *
 * `status` + `consumed_at` + `plan_order_id` — requirement 5. Consumption is a CONDITIONAL UPDATE
 *     from `pending`, so two concurrent callbacks cannot both win: the second one's update matches
 *     zero rows and it refuses. Same construction as `GatewayReference::bind()` and
 *     `StockMovement::attributeToOrder()`, and for the same reason.
 *
 * `provider_txn_id` — the provider's own reference, recorded once known. Not the key (the server's
 *     own reference is), but it is what an operator needs to reconcile with a provider statement.
 *
 * `failure_reason` — requirement 8's "document the impact of fail-closed handling" needs the
 *     refusals to be legible afterwards, and requirement 5's recovery record needs to say WHY an
 *     entitlement did not land. A captured payment whose intent could not be consumed is the
 *     plan-side twin of F186's captured-payment-without-order, and this column is how a reviewer
 *     finds it.
 *
 * **NO REFUND OR SETTLEMENT BEHAVIOUR IS IMPLIED BY ANY OF THIS.** Requirement 9 forbids it and
 * D-15 condition 3 separates reversing a merchant's balance from a real refund to a customer. A
 * `refused` intent beside a captured payment is a record for a human to act on, and nothing here
 * acts on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_purchase_intents')) {
            return;
        }

        Schema::create('plan_purchase_intents', function (Blueprint $table) {
            $table->id();

            /* The server-generated provider order id. UNIQUE is the whole replay story. */
            $table->string('reference', 64)->unique();

            $table->string('provider', 40)->index();

            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('plan_id')->index();

            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('coupon_code', 191)->nullable();

            /* DECIMAL, not float -- requirement 3 forbids imprecise comparison of money. */
            $table->decimal('expected_amount', 16, 4);
            $table->string('currency', 8);

            /* pending -> consumed | refused. Consumption is a conditional update from pending. */
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('plan_order_id')->nullable();

            $table->string('provider_txn_id', 191)->nullable()->index();
            $table->string('failure_reason', 255)->nullable();

            $table->timestamps();

            /* The lookup the return actually performs: this provider, this reference. */
            $table->index(['provider', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_purchase_intents');
    }
};
