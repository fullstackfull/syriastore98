<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOMEWHERE TO RECORD THAT A REVIEWER ACTUALLY BOUGHT THE THING.
 * =================================================================================================
 * `CUST-005` — «التقييمات الموثقة والأسئلة والمفضلة» / *verified reviews, questions and wishlists*.
 *
 * `GAP-312` records the measurement this closes half of: *"the verified-purchase gate does not
 * exist, AND THERE IS NOWHERE TO RECORD ONE"* — no `verified`, `is_verified`, `verified_purchase`
 * or `order_id` column on `testimonials`, asserted against the live schema rather than against the
 * migration, which is `CLAUDE.md` §1's standing rule. So the requirement's "verified" clause was
 * not merely unimplemented; it was unrecordable, and a badge had nothing to read from.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS IS NOT: IT IS NOT THE GATE, AND IT DOES NOT PRE-EMPT `J19`
 * -------------------------------------------------------------------------------------------------
 *
 * Whether a review *requires* a completed purchase, and what becomes of the reviews already
 * published without one, is owner decision `J19` on an engineering-complete phase. These two
 * columns do not answer it and cannot: they are a RECORD of a fact about `orders`, written beside a
 * review that is accepted exactly as it is accepted today. No journey is denied by this migration
 * or by the writers that fill it.
 *
 * -------------------------------------------------------------------------------------------------
 * NULLABLE, WITH NO BACKFILL, BECAUSE NULL IS THE ABSENCE OF A RECORD AND NOT A THIRD VALUE
 * -------------------------------------------------------------------------------------------------
 *
 * `verified_purchase` is `nullable()` with NO default, so:
 *
 *     NULL   nobody assessed this review  (every row written before this change; every row written
 *            by a MERCHANT-PANEL writer, where the "reviewer" is a `users` row and no customer
 *            purchase is even expressible)
 *     0      assessed, and this customer had bought nothing matching
 *     1      assessed, and `verified_order_id` names the order
 *
 * This is `App\Models\Order::SOURCES`' convention verbatim, and its docblock is the argument:
 * *"THERE IS DELIBERATELY NO `SOURCE_UNKNOWN`. An order with no source has NULL, and NULL is the
 * ABSENCE of a record, not a fourth category. Giving that absence a name would let it be stored,
 * compared and filtered as though somebody had established it."* A backfill would do exactly that —
 * it would assert, for every historical review, a verification that nobody performed at the time
 * and that `J19` has not yet defined. The badge therefore shows for `1` and for nothing else.
 *
 * -------------------------------------------------------------------------------------------------
 * `verified_order_id` CARRIES NO FOREIGN KEY, AND THAT IS DELIBERATE
 * -------------------------------------------------------------------------------------------------
 *
 * An `onDelete('cascade')` here would let deleting an order delete a review, and an
 * `onDelete('restrict')` would let a published review block an order deletion. Neither is a
 * behaviour anybody asked for. It is an evidence pointer, in the same shape as
 * `plan_purchase_intents.acknowledged_by` beside it ("the row must outlive the person, exactly as
 * an audit row does"). `testimonials.category_id` is the counter-example in the same table: its
 * cascade is why `2026_10_23_000100_product_reviews_category_id_on_delete_set_null.php` exists.
 *
 * -------------------------------------------------------------------------------------------------
 * `down()` IS A DOCUMENTED NO-OP — THE GATE F CONVENTION, AND HERE IT IS THE RIGHT ONE
 * -------------------------------------------------------------------------------------------------
 *
 * `2026_11_25_000200_add_acknowledgement_to_plan_purchase_intents_table.php` departs from that
 * convention and its docblock states the condition under which departing is safe: *"These do not
 * exist in production at all"*. **`testimonials` DOES exist in production** — it is one of the
 * tables `docs/GATE-F-VERIFIED-SCHEMA.md` measured — so this file is squarely in the case
 * `CLAUDE.md` §1 warns about: *"two drafts dropped a column BECAUSE it existed and a rollback
 * destroyed 45 `cost_price` values."*
 *
 * `up()` is idempotent per column via `hasColumn()`, so a no-op `down()` leaves a rollback able to
 * re-run it. And it avoids the SQLite index trap that same sibling's `down()` executed into
 * (`"1 error in index … after drop column"`): no index is added here, because no reader filters on
 * either column — the badge reads them off a review row that has already been fetched.
 *
 * @see \App\Support\Commerce\VerifiedPurchase  the one definition of the fact these columns hold
 */
return new class extends Migration
{
    private const TABLE = 'testimonials';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            /*
             * Not an error, and not an inference either: a sorted run reaches
             * `2023_12_26_095726_create_testimonials_table.php` long before this file, so an absent
             * table here means a database this migration has nothing to say about.
             */
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(self::TABLE, 'verified_purchase')) {
                /*
                 * `boolean()` emits TINYINT(1) on MySQL and INTEGER on SQLite. Nullable with no
                 * default: see the docblock on why NULL must stay distinguishable from 0.
                 */
                $table->boolean('verified_purchase')->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'verified_order_id')) {
                /*
                 * `unsignedBigInteger` matches `orders.id` (`$table->id()`), NOT the `integer`
                 * width of `orders.customer_id` beside it. No FK — see the docblock.
                 */
                $table->unsignedBigInteger('verified_order_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        /*
         * A DOCUMENTED NO-OP, for the reason in the docblock: `testimonials` is a production table,
         * and dropping a column on rollback is the exact mechanism that destroyed 45 real
         * `cost_price` values. `up()` is per-column idempotent, so nothing is stuck.
         */
    }
};
