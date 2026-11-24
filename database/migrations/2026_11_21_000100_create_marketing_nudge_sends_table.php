<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 05 GAP-219 / MKT-007, MKT-009 — WHAT WAS ALREADY SENT TO WHOM, so it is not sent twice.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — the same posture as the three D8 consent tables
 * (`2026_11_18_000100_create_marketing_consent_events_table` and its two siblings), and for the
 * same reason: CLAUDE.md reserves a persistent migration to the owner.
 *
 * THE DEFECT THIS EXISTS FOR IS LIVE AND COSTS MONEY. Measured 2026-11-21 from
 * `php artisan route:list`: `POST abandon-carts-messagesend`, `POST abandon-carts-emailsend`,
 * `POST abandon-wishlist-message-send` and `POST abandon-wish-emailsend` all carry
 * `web, Authenticate, XSS, SetLocate, EmailIsVerified` and **no `throttle`**, and neither
 * `AbandonedMessageDispatch` nor either controller writes any sent marker. `blockedReason()` asks
 * five questions -- mobile present, WhatsApp configured, template exists, template active,
 * marketing consent -- and NONE of them is "have we already sent this". So a merchant who
 * double-clicks sends twice, and every WhatsApp message is billable.
 *
 * THIS TABLE RECORDS SENDS. IT DOES NOT DECIDE A CADENCE.
 *
 * GAP-219 splits in two and only one half is engineering's. A technical duplicate-suppression guard
 * is ordinary correctness, in the same family as P4-3b's double-submit work. **How often a shopper
 * may be nudged is a MARKETING POLICY and therefore the owner's**, and the standing instruction is
 * not to invent one. So the schema carries what is needed to answer either question, and
 * `NudgeThrottle` leaves the cadence limb switched off until a number is chosen:
 *
 *   - `idempotency_key` + `store_id` are UNIQUE, which is the duplicate guard. The key is derived
 *     from the logical action (store, channel, template, contact, subject) and NOT from a clock, so
 *     suppressing a double-click needs no window and therefore invents no frequency.
 *   - `sent_at` is here so a cadence COULD be enforced once one is approved, and so a merchant can
 *     be told when the last nudge went out. Nothing reads it as a rule today.
 *
 * THE CONTACT IS A HASH, NOT AN ADDRESS, exactly as `marketing_contact_states.address_hash` is. A
 * nudge ledger keyed on plaintext phone numbers would be a second copy of the customer contact
 * list in a table whose whole purpose is to be read often and cheaply; the hash answers "is this
 * the same person" without storing who they are. `subject_type`/`subject_id` name the cart or
 * wishlist the nudge was about, so "already nudged about THIS basket" is answerable.
 *
 * `store_id` IS ALWAYS EXPLICIT AND IS NEVER NULL. This table is not in `config/tenancy.php`'s
 * `scoped_models` and must not rely on one: `null_tenant => 'open'` means the global scope adds NO
 * predicate when no tenant resolves, so a handler that leaned on it would read every store's sends.
 * Every query in `NudgeLedger` carries the store id from the authenticated principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'marketing_nudge_sends',
            [
                'store_id', 'channel', 'message_class', 'template', 'contact_hash',
                'subject_type', 'subject_id', 'idempotency_key', 'outcome',
                'sent_at', 'created_by', 'created_at', 'updated_at',
            ],
            ['mns_idempotency_unique', 'mns_contact_recent', 'mns_subject'],
            []
        );

        if (Schema::hasTable('marketing_nudge_sends')) {
            return;
        }

        Schema::create('marketing_nudge_sends', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');

            /* `whatsapp` | `email` | `telegram` — the same vocabulary as `App\Support\Marketing\Channel`. */
            $table->string('channel', 16);

            /* `marketing` | `transactional`, the `MessageClass` vocabulary. A nudge is marketing;
               the column exists so a transactional send recorded here can never be mistaken for one. */
            $table->string('message_class', 32)->default('marketing');

            /* The template name the merchant's own WhatsApp/e-mail configuration names. */
            $table->string('template', 191);

            /* SHA-256 of the normalised address. See the class docblock: never the address itself. */
            $table->char('contact_hash', 64);

            /* What the nudge was about — `cart` / `wishlist` and the row id. Nullable because a
               future nudge may be about nothing in particular, and a NOT NULL here would force a
               caller to invent a subject just to record a send. */
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            /* THE DUPLICATE GUARD. Derived from the logical action, not from a clock. */
            $table->char('idempotency_key', 64);

            /* `sent` | `duplicate_suppressed` | `throttled` | `blocked`. A refusal is RECORDED
               rather than dropped: "we declined to send this" is the fact a merchant and an audit
               both need, and a ledger that holds only successes cannot answer why nothing arrived. */
            $table->string('outcome', 32);

            /* When the send was attempted. Nullable only for a row written before a send was tried. */
            $table->timestamp('sent_at')->nullable();

            /* The merchant employee who pressed the button. Not a foreign key, for the same reason
               the consent tables give: a ledger row must outlive the user row it refers to. */
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            /* ONE ROW PER LOGICAL ACTION PER STORE. Two racing writers cannot both record a send:
               the loser meets this key, and `NudgeLedger` reads the winner's row and reports a
               duplicate rather than sending. */
            $table->unique(['store_id', 'idempotency_key'], 'mns_idempotency_unique');

            /* "When did we last nudge this person on this channel" — the shape a cadence rule would
               read, and the shape the merchant-facing "last sent" answer reads today. */
            $table->index(['store_id', 'channel', 'contact_hash', 'sent_at'], 'mns_contact_recent');

            /* "Have we nudged about this basket" without scanning the store's whole history. */
            $table->index(['store_id', 'subject_type', 'subject_id'], 'mns_subject');
        });
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as every GATE F migration.
     *
     * `down()` dropping this table would destroy the only record of what was sent to whom. Two
     * GATE F drafts dropped a column *because* it existed and a rollback destroyed 45 `cost_price`
     * values; the lesson taken from that was that a reversible-looking `down()` on a data table is
     * a loaded gun. Removing this table is a deliberate operator action, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
