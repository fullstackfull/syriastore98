<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 OPS-003 — THE PLATFORM'S OWN INCIDENT RECORD, AND ITS AUDIT TRAIL.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — the same posture as the three D8 consent tables,
 * the GAP-219 nudge ledger and `analytics_report_schedules`, because `CLAUDE.md` section 2 reserves
 * a persistent migration to the owner.
 *
 * PURELY ADDITIVE. Two new tables, no column altered, no column dropped, no data rewritten
 * anywhere. Nothing else in the schema is touched, so there is nothing for this to destroy.
 *
 * OPS-003 is "إدارة الحوادث والتنبيهات والتشخيص" — incident, alert and diagnostics management —
 * and it was measured ABSENT ENTIRELY: no `incident`, `alert` or `diagnostic` table, model,
 * controller or route existed anywhere in `app/`, `routes/` or `database/migrations/`. An operator
 * who noticed the platform misbehaving had nowhere in the product to write it down, which means
 * the platform has no operational history at all and cannot answer "has this happened before".
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A ROW IS, AND WHAT IT IS NOT
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * One `platform_incidents` row is one thing that went wrong with the PLATFORM — the shared
 * database, the queue, an integration, a host. It is not a support ticket (`support_tickets`
 * exists, is store-scoped and is a merchant talking to their own staff) and it is not an error
 * (`ScrubbedLaraBug` collects those). An incident is the human record of an operational event, with
 * the states it passed through and who moved it.
 *
 * NO `store_id`, AND THE ABSENCE IS THE DESIGN. The subject is the shared infrastructure, which has
 * no tenant. A `store_id` here would invite a merchant-visible incident list, and whether a merchant
 * ever sees a platform incident is an owner decision — `PlatformIncidentPolicy::openDecisions()`
 * records it as `merchant_visibility`. So these tables are not in `config/tenancy.php`'s
 * `scoped_models`, deliberately, and every route that reads them is `PlatformOperatorOnly`.
 *
 * `detected_at` IS NOT `created_at`, and keeping them apart is most of the value of the table. An
 * incident is nearly always recorded after it began — somebody notices at 09:40 that checkout has
 * been failing since 07:15 — and an incident log that cannot express that answers "how long was it
 * broken" with the time it took somebody to write it down.
 *
 * `diagnostics` IS THE SNAPSHOT TAKEN WHEN THE INCIDENT WAS OPENED, and it is the half of OPS-003's
 * "التشخيص" that the measurement screens cannot supply on their own: those screens show NOW, and an
 * incident is about then. The figures are already produced with their own source and period by
 * `SaasPlatformResources` and `SaasPlatformOperations`, so the snapshot is their output, stored as
 * JSON, unjudged. It is nullable because a host that refuses every measurement must still be able
 * to have an incident recorded against it.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * `platform_incident_events` IS APPEND-ONLY, AND THAT IS WHY IT IS A SECOND TABLE
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The incident row holds the CURRENT state; the event rows hold how it got there. Flattening the two
 * — a `status` column and a `notes` text field — gives a record that can be edited into a different
 * history, and a log that can be rewritten is not a log. `PlatformIncidentLog` is the only writer
 * and has no update or delete path for an event at all.
 *
 * `from_status` IS NULLABLE because the first event of an incident comes from nowhere, and a note
 * comes from no state and goes to none — so BOTH columns are nullable and a row with neither is a
 * note. That is cheaper and more honest than a `type` column whose values would have to be kept in
 * step with the two status columns anyway.
 *
 * NO FOREIGN KEY TO `users`, which is this codebase's existing convention rather than an omission:
 * `audit_logs.user_id`, `analytics_report_schedules.created_by` and `settings.created_by` all carry
 * a bare id. A cascade from `users` would also be wrong here — deleting an operator must not delete
 * the record of what they did, which is the whole point of an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'platform_incidents',
            [
                'reference', 'title', 'summary', 'severity', 'status',
                'detected_at', 'acknowledged_at', 'mitigated_at', 'resolved_at',
                'opened_by', 'diagnostics', 'created_at', 'updated_at',
            ],
            ['platform_incidents_reference_unique', 'pinc_live', 'pinc_detected'],
            []
        );

        if (! Schema::hasTable('platform_incidents')) {
            Schema::create('platform_incidents', function (Blueprint $table) {
                $table->bigIncrements('id');

                /* THE HUMAN HANDLE, so an incident can be quoted in a message, a commit or a
                   conversation without anybody reading a database id. Unique, because two
                   incidents sharing a reference makes every such quotation ambiguous. */
                $table->string('reference', 32)->unique('platform_incidents_reference_unique');

                $table->string('title', 160);
                $table->text('summary')->nullable();

                /* `IncidentSeverity`'s own keys. A string and not an enum column: this codebase
                   stores every such vocabulary as a string (`orders.status`,
                   `analytics_report_schedules.frequency`), and a MySQL enum cannot gain a value
                   without an ALTER, which is an owner escalation here. */
                $table->string('severity', 20);

                /* `IncidentState`'s own keys. Moved only through
                   `PlatformIncidentLog::transition()`, which authorises the move against the
                   declared machine and writes an event row for it. */
                $table->string('status', 20);

                /* WHEN IT BEGAN, as the operator states it — NOT when the row was written. See the
                   class docblock: these are different facts and `created_at` already holds the
                   second one. */
                $table->timestamp('detected_at')->nullable();

                /* Stamped by the move that reaches each state, once. A repeated move is refused by
                   the state machine, so none of these can be dragged forward by a second click. */
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('mitigated_at')->nullable();
                $table->timestamp('resolved_at')->nullable();

                /* The `users.id` of the operator who recorded it. No foreign key: deleting an
                   operator must not delete the record of what they did. */
                $table->unsignedBigInteger('opened_by')->nullable();

                /* The measurement snapshot taken when the incident was opened, as JSON.
                   `longText` and not `json`: SQLite has no json type and this suite runs on it, and
                   the column is read back whole rather than queried into. */
                $table->longText('diagnostics')->nullable();

                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                /* The list screen's only two queries: live incidents worst-first, and the history
                   newest-first. */
                $table->index(['status', 'severity'], 'pinc_live');
                $table->index('detected_at', 'pinc_detected');
            });
        }

        ThemeSchemaGuard::assertCompatible(
            'platform_incident_events',
            ['incident_id', 'from_status', 'to_status', 'note', 'actor_id', 'recorded_at', 'created_at', 'updated_at'],
            ['pince_incident'],
            []
        );

        if (! Schema::hasTable('platform_incident_events')) {
            Schema::create('platform_incident_events', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('incident_id');

                /* BOTH NULLABLE. The first event of an incident comes from nowhere; a note comes
                   from no state and goes to none, and a row with neither IS a note. */
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20)->nullable();

                $table->text('note')->nullable();

                /* The `users.id` of whoever made the move. Nullable so a move made by something
                   other than a signed-in operator — the automatic opening OPS-003's threshold
                   decision would enable — can be recorded as not having an actor rather than
                   attributed to somebody. */
                $table->unsignedBigInteger('actor_id')->nullable();

                $table->timestamp('recorded_at')->nullable();

                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index(['incident_id', 'id'], 'pince_incident');
            });
        }
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as every GATE F migration, the nudge ledger and the
     * analytics schedules.
     *
     * Dropping these would destroy the platform's entire operational history and the audit trail of
     * who did what about it — which is the one thing an audit trail must not be able to lose. Two
     * GATE F drafts dropped a column *because* it existed and a rollback destroyed 45 `cost_price`
     * values; removing these tables is a deliberate operator action, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
