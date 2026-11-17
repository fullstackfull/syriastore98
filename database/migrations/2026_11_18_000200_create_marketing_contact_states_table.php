<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 05 D8 / MKT-008 — WHERE EACH CONTACT STANDS NOW, rebuilt from the evidence on demand.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying it
 * needs the owner's separate authorisation.** See `2026_11_18_000100_create_marketing_consent_events_table`.
 *
 * A PROJECTION, NOT A SOURCE OF TRUTH. `marketing_consent_events` is the evidence; this table is the
 * answer the send gate reads, kept so one indexed lookup replaces folding a log on every message. It is
 * written only by `ConsentLedger`, in the same transaction as the event it reflects, and
 * `ConsentLedger::rebuildState()` recomputes it from the events so the two can be compared.
 *
 * THE UNIQUE KEY IS THE WHOLE POINT. One row per (store, channel, purpose, address hash), so the same
 * person is a separate decision at every store and on every channel, and two racing writers cannot
 * create two answers for one contact -- the loser meets the unique key and re-reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'marketing_contact_states',
            ['store_id', 'channel', 'purpose', 'address_hash', 'status', 'last_event_id', 'created_at', 'updated_at'],
            ['mcs_contact_unique'],
            []
        );

        if (Schema::hasTable('marketing_contact_states')) {
            return;
        }

        Schema::create('marketing_contact_states', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');
            $table->string('channel', 16);
            $table->string('purpose', 32)->default('marketing');
            $table->char('address_hash', 64);

            /* `granted` | `withdrawn` | `suppressed`. Absence of a row is `none`, which is NOT consent. */
            $table->string('status', 16);

            /* The event this row reflects. Not a foreign key, for the reason on the events table. */
            $table->unsignedBigInteger('last_event_id');

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['store_id', 'channel', 'purpose', 'address_hash'], 'mcs_contact_unique');
        });
    }

    /** Refuses to drop a populated projection: it is rebuildable, but a rollback must be a decision, not an accident. */
    public function down(): void
    {
        if (! Schema::hasTable('marketing_contact_states')) {
            return;
        }

        if (Schema::getConnection()->table('marketing_contact_states')->exists()) {
            throw new RuntimeException('Refusing to drop marketing_contact_states: it holds the current consent decisions. Rebuild or retain it deliberately first.');
        }

        Schema::drop('marketing_contact_states');
    }
};
