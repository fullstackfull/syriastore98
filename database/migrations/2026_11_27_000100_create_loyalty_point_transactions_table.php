<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 05 `MKT-005` / `P05-11` — «سجل حركات», THE LOYALTY TRANSACTION LOG, FIRST-PARTY.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — the same posture as `customer_segments`,
 * `analytics_report_schedules`, the three D8 consent tables and the GAP-219 nudge ledger, because
 * CLAUDE.md reserves a persistent migration to the owner.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY A FIRST-PARTY TABLE WHEN A VENDORED LOG ALREADY EXISTS
 * -------------------------------------------------------------------------------------------------
 *
 * `MKT-005` is «نقاط ولاء وVIP وإحالات مع سجل حركات» — *loyalty points, VIP and referrals, **with a
 * transaction log***. Vendored `RewardClubPoint` does ship a `reward_club_points` table, so the
 * first question was whether that already satisfies the clause. Measured against the vendored
 * migration and its single writer (`AddRewardClubPointLis`), it does not, for reasons of fact:
 *
 * 1. **IT IS NOT IN THIS SCHEMA.** `reward_club_points` and `customers.total_club_point` are both
 *    declared by `packages/workdo/RewardClubPoint/src/database/migrations/`, which only runs when
 *    the module is installed. `GAP-191`: host migrations **NONE** for the whole substrate. A
 *    requirement cannot be met by a table the application does not have.
 * 2. **IT RECORDS NO SIGNED MOVEMENT.** Its columns are `points` (this order's award),
 *    `used_points` (the prior balance, written only on the redemption branch and `?? 0` otherwise)
 *    and `reward_price` (the money discount). There is no delta and no sign anywhere, so no sum
 *    over it is a balance and no single row says which way the balance went.
 * 3. **IT HAS NO VOCABULARY.** `MKT-005`'s log needs earn, redeem, expire and adjust. Two of those
 *    are inferable from that row shape and two are inexpressible.
 * 4. **IT CANNOT RECORD WHAT ITS OWN WRITER DOES NOT SEE.** The one movement family already known
 *    to bypass that listener is the Khalti redemption, which takes the discount and fires no event
 *    (`PHASE-05-OWNER-DECISION-PACKAGE.md` §2.5): the money moved and the log has no row.
 *
 * Nothing here is a claim that the vendored table is *defective* — in particular NO precision claim
 * is made from its `float` wording, because CLAUDE.md records a `FLOAT_PRECISION` caveat being
 * attached and **withdrawn** on exactly that reasoning (`$table->float()` emits a MySQL `double`).
 * The claim is narrower and is about this application: that table is absent here, and what it
 * stores is not a movement.
 *
 * -------------------------------------------------------------------------------------------------
 * THIS TABLE IS AN OBSERVER OF RECORD AND NOT AN AUTHORITY
 * -------------------------------------------------------------------------------------------------
 *
 * The authoritative balance stays exactly where it is, in `customers.total_club_point`. Nothing in
 * this slice writes that column, and nothing reads this table to price anything — so a store with
 * the vendored module has no balance changed by any of it, and a store without the module behaves
 * exactly as it does today. Making the ledger authoritative would change the input of all eleven
 * first-party spend paths and therefore change customer-visible balances; points are money-adjacent,
 * so that is the owner's decision and it is reported as one rather than taken here.
 *
 * -------------------------------------------------------------------------------------------------
 * `store_id` IS ALWAYS EXPLICIT
 * -------------------------------------------------------------------------------------------------
 *
 * This table is NOT in `config/tenancy.php`'s `scoped_models`, and `null_tenant => 'open'` means a
 * global scope adds **no predicate at all** when no tenant resolves — so every reader states the
 * store itself. `customers.id` values are reused across tenants in a shared database, so
 * `(store_id, customer_id)` and not `customer_id` is what identifies whose history this is.
 *
 * -------------------------------------------------------------------------------------------------
 * `decimal` AND NOT `float`, FOR A REASON THAT IS ABOUT THIS TABLE ONLY
 * -------------------------------------------------------------------------------------------------
 *
 * A reconciliation SUMS this column over every row of a customer's history and compares the result
 * with a balance. A sum is the one operation where a binary floating-point column's rounding
 * accumulates and becomes engine-dependent, and this table's whole purpose is to be summed. The
 * authority it is compared against is a `double`, which is why
 * {@see \App\Support\Loyalty\LoyaltyReconciliation} compares with a stated tolerance rather than
 * for equality.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'loyalty_point_transactions',
            [
                'store_id', 'customer_id', 'kind', 'points_delta', 'balance_before',
                'balance_after', 'order_id', 'redemption_claimed', 'source', 'reason', 'created_at',
            ],
            ['lpt_store_customer', 'lpt_store_created'],
            []
        );

        if (Schema::hasTable('loyalty_point_transactions')) {
            return;
        }

        Schema::create('loyalty_point_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* See the class docblock's tenancy paragraph. Never nullable: a storeless movement is
             * invisible to every reader and present in the table, which is the worst of both. */
            $table->unsignedBigInteger('store_id');

            $table->unsignedBigInteger('customer_id');

            /*
             * `earn` | `redeem` | `expire` | `adjust` — the vocabulary `MKT-005`'s log names.
             *
             * NO FOREIGN KEY AND NO ENUM. The vocabulary is enforced in `LoyaltyLedger`, which is
             * the only writer, for the same reason `customer_segments.kind` is a plain string: a
             * database-level enum makes MySQL reject a row this application should refuse with a
             * message a human can read, and migrating an enum to add `expire`'s eventual sibling is
             * a table rebuild.
             *
             * `expire` HAS NO PRODUCER IN THIS CODEBASE and that is deliberate, not an oversight:
             * nothing expires points here, and inventing an expiry policy would be inventing a
             * business rule that costs customers their balance. The kind exists so that a future
             * owner-approved expiry has somewhere honest to write, and so that the log's vocabulary
             * matches the requirement's words rather than the subset that happens to be wired.
             */
            $table->string('kind', 16);

            /*
             * THE SIGNED MOVEMENT, and the column the vendored log has no equivalent of. Negative
             * for a redemption. See the class docblock for why `decimal`.
             */
            $table->decimal('points_delta', 15, 2);

            /*
             * THE BALANCE THIS WRITER ACTUALLY SAW, before and after. NULLABLE, and null means
             * *unobservable* — never zero. CLAUDE.md records a case that passed because
             * `(int) null` is `0`; here the two readings are opposite facts, and collapsing them
             * would make a reconciliation report drift against a figure nobody ever observed.
             *
             * The FIRST row's `balance_before` is what anchors a customer's chain to a balance that
             * existed before this table did, which is the only reason a store that installed the
             * vendored module years ago can be reconciled at all.
             */
            $table->decimal('balance_before', 15, 2)->nullable();
            $table->decimal('balance_after', 15, 2)->nullable();

            /*
             * What caused the movement, where that is known. Nullable because an `adjust` observed
             * with no attribution is a real and important row — it is how a write from outside the
             * known paths becomes visible instead of silent.
             */
            $table->unsignedBigInteger('order_id')->nullable();

            /*
             * WHAT THE REQUEST CLAIMED, recorded as a claim and never used as the `kind`.
             * `club_point_is_active` is caller-supplied; the host overwrites it server-side at the
             * quote (`PaymentController::processRewardPoints`) but `GAP-214` records the
             * gateway-return handlers as not covered. So this answers "the request said a
             * redemption was being applied", which is worth auditing and is not the same statement
             * as "points were spent". The `kind` comes from the observed delta instead.
             */
            $table->boolean('redemption_claimed')->default(false);

            /* Which writer observed it, so a row can be traced back to the code that made it. */
            $table->string('source', 32);

            $table->string('reason', 190)->nullable();

            /*
             * `created_at` ONLY, and the absence of `updated_at` is part of the contract rather
             * than a saving. D4b: *"A reversal writes a compensating row; it never edits history."*
             * An `updated_at` column would invite a future reader to believe a movement can change
             * in place. The model refuses `updating` and `deleting` outright; this is the same
             * statement in the schema, and it is the same call `customer_segment_members` made.
             */
            $table->timestamp('created_at')->nullable();

            /*
             * "This customer's history, newest first", with the tenant predicate leading. The `id`
             * is in the key because two movements in one request share a `created_at` to the
             * second and the screen's order must still be total.
             */
            $table->index(['store_id', 'customer_id', 'id'], 'lpt_store_customer');

            /* "What moved in this store lately" — the reconciliation and any later report. */
            $table->index(['store_id', 'created_at'], 'lpt_store_created');
        });
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as every GATE F migration and as `customer_segments`.
     *
     * This table is an audit log. Dropping it destroys the only record of why a customer's points
     * are what they are, and nothing can rebuild it: the movements it describes were observed as
     * they happened and are not derivable from any other row. CLAUDE.md records two GATE F drafts
     * dropping a column *because* it existed and a rollback destroying 45 `cost_price` values.
     * Removing this table is a deliberate operator action, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
