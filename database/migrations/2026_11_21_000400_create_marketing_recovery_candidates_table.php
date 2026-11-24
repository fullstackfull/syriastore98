<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 05 MKT-007 — THE RECOVERY QUEUE: what was detected, and why nothing was sent.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — the same posture as `analytics_report_schedules`,
 * `customer_segments`, the three D8 consent tables and the GAP-219 nudge ledger.
 *
 * WHAT WAS MEASURED. MKT-007's row: "NO AUTOMATED OR SCHEDULED CART RECOVERY. Recovery is one
 * merchant click per cart row." The duplicate guard at the SEND step already exists (`NudgeLedger`,
 * keyed on the logical action with no clock in it) and the consent gate at the sender already exists
 * (`MarketingSendGate`, enforcement `off` by owner decision). What is absent is DETECTION — nobody
 * looks for a basket or an unpaid order unless a merchant remembers to open a screen.
 *
 * WHY THIS IS A TABLE AND NOT A COMPUTED LIST. Two reasons, and the second is the one that matters.
 *
 *   1. Deduplication at DETECTION is a different question from deduplication at SEND. "Have I
 *      already queued this basket?" is not "have I already sent this message?", and only the first
 *      stops an hourly sweep re-reporting the same basket twenty-four times a day.
 *   2. **THE BACKLOG.** Automatic sending is OFF by owner decision, and the day it is approved the
 *      pipeline must not discover a year of abandoned baskets and message all of them. A queue whose
 *      rows carry `detected_at` and a `send_state` can be released deliberately -- or aged out, or
 *      ignored -- because the decision is visible. A pipeline with no memory would have no choice.
 *
 * `send_state` IS WRITTEN `held` AND THE REASON IS RECORDED. That is the honest shape of "built but
 * switched off": every row says, in the row, that nothing was sent and which gate said so. A feature
 * that silently does nothing is indistinguishable from one that is broken, and this project keeps
 * finding exactly that.
 *
 * `store_id` IS ALWAYS EXPLICIT. Not in `config/tenancy.php`'s `scoped_models`, and the sweep runs
 * from the CONSOLE where no tenant resolves at all (`null_tenant => 'open'` means a global scope
 * would add no predicate), so the explicit column is the only tenant control on that path.
 *
 * NO FOREIGN KEYS, consistent with the rest of this schema: a basket or an order can be deleted
 * under a queued row, and the reader resolves the subject inside the store rather than trusting the
 * id. A dangling row is therefore inert rather than a crash.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'marketing_recovery_candidates',
            [
                'store_id', 'kind', 'subject_type', 'subject_id', 'customer_id',
                'detected_at', 'send_state', 'held_reason', 'created_at', 'updated_at',
            ],
            ['mrc_subject_unique', 'mrc_store_state'],
            []
        );

        if (Schema::hasTable('marketing_recovery_candidates')) {
            return;
        }

        Schema::create('marketing_recovery_candidates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');

            /* `abandoned_basket` | `incomplete_payment`. See `RecoveryCandidates::KINDS`. */
            $table->string('kind', 32);

            /*
             * The thing to recover, named by type and id rather than by two nullable foreign keys.
             * `cart` or `order` today; a third kind would add a value, not a column.
             */
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');

            /*
             * WHO, recorded separately from the subject because that is the question a recovery
             * asks. Nullable: an incomplete payment can belong to a guest checkout, and a guest has
             * no customer row -- so the queue can hold a candidate nobody can be messaged about,
             * and saying so is better than dropping it silently.
             */
            $table->unsignedBigInteger('customer_id')->nullable();

            /* When the sweep first saw it. NOT when the basket was abandoned -- that is the
               subject's own timestamp, and conflating the two would make the queue's age meaningless
               after a gap in scheduling. */
            $table->timestamp('detected_at')->nullable();

            /*
             * `held` | `released`. Today it is always `held`: automatic promotional sending is OFF
             * by owner decision until consent enforcement is approved and active. The column exists
             * so that the state is a fact in the row rather than an inference from the absence of
             * one.
             */
            $table->string('send_state', 16)->default('held');

            /* WHICH gate held it, in words, so a merchant or an operator reading the queue does not
               have to guess whether the feature is off or broken. */
            $table->string('held_reason', 64)->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            /*
             * ONE ROW PER SUBJECT PER STORE. This is the deduplication the requirement's own title
             * asks for ("without duplication"): an hourly sweep that re-queued the same basket would
             * produce twenty-four rows a day, and on the day sending is approved it would send
             * twenty-four messages about one basket.
             */
            $table->unique(['store_id', 'kind', 'subject_type', 'subject_id'], 'mrc_subject_unique');

            /* The only query the queue screen makes. */
            $table->index(['store_id', 'send_state'], 'mrc_store_state');
        });
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as every GATE F migration and the sibling tables.
     *
     * Dropping this destroys the record of what was detected and what was held -- and with it the
     * ability to release a backlog deliberately rather than all at once. Two GATE F drafts dropped a
     * column *because* it existed and a rollback destroyed 45 `cost_price` values.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
