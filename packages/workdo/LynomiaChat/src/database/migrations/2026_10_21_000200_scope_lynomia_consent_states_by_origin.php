<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ORIGIN IS PART OF THE SCOPE, BECAUSE THE TWO ORIGINS COUNT IN DIFFERENT SEQUENCES.
 *
 * THE DEFECT. `lynomia_consent_states` was unique on
 * (store_id, external_subject_id, purpose, channel) and ordered by `revision`. Lynomia
 * publishes consent changes from TWO tables: a native decision -- a shopper replying STOP on
 * WhatsApp -- carries `origin: lynomia_native` and `revision` is that row's id in
 * `commerce_messaging_consents`; a partner decision carries `origin: partner` and `revision`
 * is a `partner_consent_events` id. Each is monotonic within its own table and the two are
 * unrelated, so comparing them decides nothing.
 *
 * What that produced: a native withdrawal at revision 5 and a partner grant at revision
 * 10001 landed on ONE row, the grant compared as "newer", and this side's record of a
 * withdrawal was overwritten by a grant -- the precise thing the ordering rule exists to
 * prevent. It could not cause a wrong send, because Lynomia's own send guard unions the
 * negatives and is the authority on that; it made the MERCHANT'S SCREEN wrong, which is what
 * this table is for.
 *
 * THE FIX IS A ROW PER ORIGIN, not a cleverer comparison. Two sequences cannot be merged into
 * one order, so they are not merged: each origin keeps its own row, each is ordered against
 * itself only, and the effective answer a merchant is shown is the union of the negatives
 * across them (LynomiaConsentState::effectiveFor). That is the same rule Lynomia applies at
 * the send boundary, which is what keeps the two sides saying the same thing.
 *
 * AN ORIGIN-LESS EVENT GETS ITS OWN KEY ('unknown') rather than being guessed into one of the
 * two. Guessing would reopen the cross-sequence comparison; refusing would drop an opt-out,
 * which is worse than keeping an extra row. An unknown-origin negative still counts in the
 * union, so nothing is lost by not knowing where it came from.
 *
 * DROPPED AND RECREATED RATHER THAN ALTERED. This table is a PROJECTION of what Lynomia
 * holds, not a record of anything this side decided: the evidence lives in
 * `lynomia_consent_decisions`, which is append-only and is not touched here, and
 * `lynomia:reconcile-consents` rebuilds this table from Lynomia on demand. Recreating it is
 * therefore cheaper and clearer than a backfill that would have to invent an origin for rows
 * whose origin is exactly what was lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lynomia_consent_states');

        Schema::create('lynomia_consent_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('external_subject_id', 128);
            $table->string('purpose', 20);
            $table->string('channel', 20);
            // Which of Lynomia's two consent stores this came from, and therefore which
            // sequence `revision` belongs to. Part of the key for that reason.
            $table->string('origin', 20)->default('unknown');
            $table->string('state', 20);
            $table->string('source', 40)->nullable();
            // Lynomia's server-assigned ordering key, WITHIN THIS ORIGIN. An inbound event
            // carrying a revision not greater than the one stored for the same origin has
            // already been superseded and is dropped.
            $table->unsignedBigInteger('revision')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(
                ['store_id', 'external_subject_id', 'purpose', 'channel', 'origin'],
                'lynomia_consent_state_scope_uniq'
            );
            $table->index(['store_id', 'state'], 'lynomia_consent_state_store_idx');
            // The merchant screen's own query: everything this store knows about one person.
            $table->index(['store_id', 'external_subject_id'], 'lynomia_consent_state_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lynomia_consent_states');
    }
};
