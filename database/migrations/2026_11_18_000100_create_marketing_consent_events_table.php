<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 05 D8 / GAP-200 / MKT-008 — THE APPEND-ONLY EVIDENCE OF MARKETING CONSENT.
 * =================================================================================================
 *
 * APPROVED FOR DESIGN, DEVELOPMENT AND ISOLATED DATABASE TESTING (owner decision D8). **No
 * production migration has been run and none may be: applying this to production, and activating
 * enforcement, each need the owner's separate authorisation.**
 *
 * WHY THE TABLE EXISTS. `customers` has no consent or eligibility column at all (all 25 columns of
 * the create migration were read). The only consent mechanism in the platform, `lynomia_consent_
 * states`, is enforced upstream, outside this repository, and nothing in `app/` or `packages/workdo/`
 * reads it to gate a send. So today no promotional message anywhere in the codebase is conditioned on
 * any permission. This is where permission is recorded.
 *
 * ONE ROW PER FACT, NEVER UPDATED, NEVER DELETED. A grant, a withdrawal, a suppression, a lifted
 * suppression. `marketing_contact_states` is the current answer and can be rebuilt from this table;
 * if they disagree, this table is right. `created_at` only, with no `updated_at`, because the column
 * would invite the edit the table exists to prevent -- the same shape as `theme_publication_events`.
 *
 * NO FOREIGN KEYS, AND THE REASONING IS THE PRECEDENT'S. A foreign key to `stores` with CASCADE would
 * let deleting a store erase the evidence that its customers did, or did not, agree to be messaged;
 * RESTRICT would make a store undeletable for as long as it had ever recorded a consent. An evidence
 * log that the thing it concerns can delete is not an evidence log (`audit_logs` and
 * `theme_publication_events` are built the same way). What a deleted store's consent history should
 * become -- erased with it, retained for a statutory period, or anonymised -- is a retention decision
 * for the owner and for counsel, and is recorded as open rather than guessed at here.
 *
 * WHAT IS AND IS NOT STORED ABOUT THE PERSON. The address is held as `address_hash`, never raw: the
 * send gate needs equality only, and a second copy of every contact in an evidence table is personal
 * data with no enforcement benefit. `address_hint` is a masked rendering (`j***@example.com`) so a
 * human audit can recognise a row, and `customer_id` links it to the customer where there is one.
 * `evidence` is the proof -- which form, which consent wording version, the locale, the IP and user
 * agent -- and the IP is personal data, so it is retention-bound like everything else here.
 *
 * `string` COLUMNS, NOT `enum`. Under `strict => false` MySQL coerces an unknown enum value to '' and
 * keeps the row, which would silently turn a typo'd event name into an event nothing recognises.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'marketing_consent_events',
            ['store_id', 'channel', 'purpose', 'address_hash', 'address_hint', 'customer_id', 'event', 'source', 'evidence', 'actor_type', 'actor_id', 'occurred_at', 'created_at'],
            ['mce_store_contact_index', 'mce_customer_index'],
            []
        );

        if (Schema::hasTable('marketing_consent_events')) {
            return;
        }

        Schema::create('marketing_consent_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');

            /* `email` | `sms` | `whatsapp` | `push`. See App\Support\Marketing\Channel. */
            $table->string('channel', 16);

            /* `marketing` today. A column rather than a constant so a second purpose never needs a migration. */
            $table->string('purpose', 32)->default('marketing');

            /* SHA-256 of `store|channel|normalised address`. See App\Support\Marketing\ContactAddress. */
            $table->char('address_hash', 64);

            /* Masked, for a human reading the log. Never usable as an address. */
            $table->string('address_hint', 191)->nullable();

            $table->unsignedBigInteger('customer_id')->nullable();

            /* `granted` | `withdrawn` | `suppressed` | `suppression_lifted`. See App\Support\Marketing\ConsentEvent. */
            $table->string('event', 24);

            /* Where the decision was made: `checkout_checkbox`, `account_settings`, `unsubscribe_link`, `admin`, ... */
            $table->string('source', 48);

            /* The proof: consent wording version, form, locale, ip, user agent. Personal data; retention-bound. */
            $table->json('evidence')->nullable();

            /* Who acted: `customer` | `merchant` | `system`. */
            $table->string('actor_type', 16)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();

            /* When the person acted. The fold that decides a contact's state is ALWAYS by `id` (the order the events were written under the
               contact's lock), never by this column: an imported old `granted` would otherwise fold BEFORE a newer withdrawal, and an
               importer must be written knowing it folds AFTER. ConsentLedger cannot set this column today (it is `now()`). */
            $table->timestamp('occurred_at')->nullable();

            /* `created_at` only. See the docblock. */
            $table->timestamp('created_at')->nullable();

            /* The gate's read: this contact's history at this store on this channel, newest last. */
            $table->index(['store_id', 'channel', 'address_hash', 'id'], 'mce_store_contact_index');
            $table->index('customer_id', 'mce_customer_index');
        });
    }

    /**
     * REFUSES TO DROP A TABLE THAT HOLDS EVIDENCE. A rollback of this migration in an environment that
     * has recorded consent would destroy the only proof that those people agreed, which is the kind of
     * `down()` this project has already been burned by (two drafts dropped a column because it existed
     * and a rollback destroyed 45 values). An empty table may be dropped; a populated one may not.
     */
    public function down(): void
    {
        if (! Schema::hasTable('marketing_consent_events')) {
            return;
        }

        if (Schema::getConnection()->table('marketing_consent_events')->exists()) {
            throw new RuntimeException('Refusing to drop marketing_consent_events: it holds consent evidence. Export or retain it deliberately first.');
        }

        Schema::drop('marketing_consent_events');
    }
};
