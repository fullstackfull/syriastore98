<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 `CTRL-003` — THE CHANGE LOG. What the operator switched, when, and from what to what.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — CLAUDE.md reserves a persistent migration to the
 * owner.
 *
 * `CTRL-003` — *"سياسات عامة وإعلانات صيانة وسجل تغييرات"* — public policies, maintenance
 * announcements and **a change log**. `DECISION-ID-REGISTER.md:251`, Phase 06 §06-H.
 *
 * THIS TABLE IS THE THIRD LIMB ONLY, and the split is stated so nobody reads it as the whole
 * decision:
 *
 *  - **public policies** already have a mechanism. `App\Support\Platform\PlatformContent`'s
 *    `LEGAL_DOCUMENTS` carries five documents — terms, privacy notice, acceptable use, billing and
 *    refunds, customer-data handling — each a `markdown` field marked `owed => true`, i.e. awaiting
 *    the owner's own text. The TEXT is the owner's and is not invented here.
 *  - **maintenance announcements** are a separate additive surface.
 *  - **a change log** is this table.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY NOT `audit_logs`, WHICH ALREADY EXISTS AND ALREADY HAS A `record()` HELPER.
 *
 * Because they answer different questions and belong to different decisions. `audit_logs` is
 * `IAM-004`'s — *"سجل تدقيق مركزي واستجابة للحوادث"*, a central audit log for incident response:
 * per-actor, per-subject, carrying `store_id`, `ip_address` and `user_agent`, written by 18 call
 * sites across tenant-facing controllers. `CTRL-003`'s change log is in the §06-H *platform
 * control* section, beside feature flags and gradual rollout, and its subject is the platform's own
 * configuration rather than a tenant's record. Collapsing the two would mean either flooding an
 * incident-response log with routine configuration edits, or scoping a platform-wide record with a
 * tenant's `store_id`.
 *
 * It is also narrower in a way that matters: this table carries `before` and `after`, so "the
 * rollout went from 10% to 50% on Tuesday" is readable off one row. `audit_logs` does carry
 * `old_values`/`new_values`, so that is a convenience rather than a capability the other lacks —
 * stated honestly rather than claimed as a reason.
 *
 * -------------------------------------------------------------------------------------------------
 * APPEND-ONLY, AND EXACTLY HOW FAR THAT GOES.
 *
 * `created_at` and no `updated_at`, the same shape as `audit_logs` and
 * `theme_publication_events`. `App\Models\PlatformControlChange` goes one step further than the
 * house precedent and THROWS on an Eloquent update or delete, because `CTRL-003` asks for a change
 * log and a log whose rows can be rewritten is not one.
 *
 * WHAT THAT DOES NOT DO, said plainly rather than left to be discovered: nothing in the database
 * refuses an `UPDATE` or a `DELETE`. `DB::table('platform_control_changes')->delete()` works, and
 * so does a database client. This is a convention enforced at the model boundary — the boundary
 * every first-party writer passes through — not an immutability guarantee.
 *
 * NO FOREIGN KEY ON `actor_id`. An audit row whose actor can delete it is not an audit row, and
 * `audit_logs` is the measured house precedent: `SHOW CREATE TABLE` shows four plain KEYs and not
 * one CONSTRAINT. `actor_name` is a SNAPSHOT of the name at the time, so the record still reads
 * correctly after the account is renamed or removed — the same reasoning
 * `OrderCurrencyStamp` applies to money.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'platform_control_changes',
            [
                'subject_type', 'subject_key', 'action', 'before', 'after',
                'actor_id', 'actor_name', 'note', 'created_at',
            ],
            ['pcc_subject', 'pcc_recent'],
            []
        );

        if (Schema::hasTable('platform_control_changes')) {
            return;
        }

        Schema::create('platform_control_changes', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* WHAT KIND of thing changed -- `feature_flag`, `feature_flag_target`, `pilot_group`.
               A string rather than an enum for the reason `theme_publication_events.action`
               records: an enum needs a migration to extend, and production's non-strict sql_mode
               truncates rather than refuses. `PlatformChangeLog::SUBJECTS` guards membership. */
            $table->string('subject_type', 32);

            /* WHICH ONE -- the flag's key, the group's slug. Not an id: an id is meaningless once
               the row is gone, and the whole point of a change log is to outlive what it records. */
            $table->string('subject_key', 96);

            /* `defined` | `enabled` | `disabled` | `rollout_changed` | `target_added` |
               `target_withdrawn` | `member_added` | `member_removed`. */
            $table->string('action', 32);

            /* The state either side of the change, as JSON. Nullable in both directions: a
               `defined` row has no before, and a withdrawal has no after. */
            $table->text('before')->nullable();
            $table->text('after')->nullable();

            /* Nullable, and not a foreign key -- see the class docblock. Null means the change came
               from a console command, a seeder or a queued job rather than a person. */
            $table->unsignedBigInteger('actor_id')->nullable();

            /* A snapshot, so the row still reads correctly after a rename or a deletion. */
            $table->string('actor_name', 191)->nullable();

            /* Why. The operator's own sentence, when the screen asks for one. */
            $table->string('note', 255)->nullable();

            /* `created_at` only: a row here is never updated, so there is no `updated_at` to
               invite one. */
            $table->timestamp('created_at')->nullable();

            /* "What has happened to THIS flag" -- the per-subject history the screen shows. */
            $table->index(['subject_type', 'subject_key'], 'pcc_subject');

            /* "What has happened lately" -- the platform-wide feed, newest first. */
            $table->index('created_at', 'pcc_recent');
        });
    }

    /**
     * A DOCUMENTED NO-OP. Dropping a change log destroys exactly the record it exists to keep, and
     * `theme_publication_events`' own migration records that its first draft gave the table a
     * cascade and so destroyed that record; a review caught it. Removing this is a deliberate
     * operator action, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
