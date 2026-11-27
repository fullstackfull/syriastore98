<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 `CTRL-003` — "إعلانات صيانة" : WHAT THE PLATFORM TELLS ITS MERCHANTS IS HAPPENING.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — CLAUDE.md reserves a persistent migration to the
 * owner.
 *
 * `CTRL-003` is *"سياسات عامة وإعلانات صيانة وسجل تغييرات"* — public policies, **maintenance
 * announcements**, and a change log (`DECISION-ID-REGISTER.md:251`). This is the second limb. The
 * other two are measured and dispositioned in
 * `docs/development/phase-6/PHASE-06-FEATURE-CONTROL.md` §1.3: the policy MECHANISM already exists
 * and its text is the owner's, and the change log is
 * `2026_11_26_000200_create_platform_control_changes_table`.
 *
 * MEASURED ABSENT BEFORE THIS. Laravel's own `PreventRequestsDuringMaintenance` is `php artisan
 * down` — an outage, not an announcement, not reachable from any operator screen, and it refuses
 * the request rather than telling anybody anything. There was no announcement table, model, screen
 * or banner.
 *
 * -------------------------------------------------------------------------------------------------
 * PLATFORM-WIDE, WITH NO AUDIENCE TARGETING, AND THAT IS A SCOPE DECISION RATHER THAN A SHORTCUT.
 *
 * The sibling tables in this change carry three targeting axes because `CTRL-001` names three.
 * `CTRL-003` names none: *"إعلانات صيانة"* is the platform announcing maintenance, and maintenance
 * of a shared platform reaches every tenant on it. Adding a plan/store/pilot audience here would be
 * inventing a requirement — and the obvious one to invent, "announce to the pilot group first", is
 * a thing an operator might well want and is not what the decision asks for. It is recorded as an
 * open question in the findings document instead of built.
 *
 * -------------------------------------------------------------------------------------------------
 * BILINGUAL IN THE SCHEMA, NOT IN A TRANSLATION FILE.
 *
 * `title_en`/`title_ar`/`body_en`/`body_ar` rather than one column plus a locale. These are words
 * the OPERATOR writes at 2am about tonight's maintenance window; they are not application strings
 * and they cannot go through `resources/lang/*.json`, which needs a deploy. Two columns per field
 * is the same shape `PlatformContentStore` reaches for the official site's copy, for the same
 * stated reason: the operator must be able to change what the platform says without a release.
 *
 * ONE ROW PER ANNOUNCEMENT, BOTH LANGUAGES IN IT, so a save is atomic and the two halves cannot
 * drift into two rows with different windows. `App\Support\Control\MaintenanceAnnouncements` falls
 * back to the other language when the reader's is empty, because an Arabic merchant seeing nothing
 * at all about tonight's outage is worse than seeing it in English — and that fallback is asserted
 * in both directions rather than left as a comment.
 *
 * -------------------------------------------------------------------------------------------------
 * `is_published` AND A WINDOW ARE DIFFERENT QUESTIONS, AND BOTH ARE NEEDED.
 *
 * `is_published` is the operator's intent — a draft they are still writing must not appear. The
 * window is WHEN it is relevant. Keeping them apart is what lets an announcement be written on
 * Monday, published on Monday, and appear only during Thursday's window; collapsing them into one
 * flag would mean somebody has to be awake to turn it on.
 *
 * BOTH ENDS ARE NULLABLE, and null means "no bound at that end": a null `starts_at` is live now, a
 * null `ends_at` never expires. A notice that never expires is a real need (a policy change) and a
 * real hazard (a forgotten banner), so the operator screen prints the window it computed rather
 * than letting the operator infer it from two empty boxes.
 *
 * `severity` IS THE OPERATOR'S JUDGEMENT AND THEREFORE ALLOWED TO BE ONE. `platform_resources`'
 * view is explicitly forbidden to colour a figure, because that would be the platform stating an
 * operational policy nobody set. This is the opposite case: the operator is the one saying "this
 * one is urgent", so the column records what they said. It is still never colour ALONE — the badge
 * carries its text, which is `x-ss.status-badge`'s own contract.
 *
 * NO FOREIGN KEY ON `created_by`, the same posture as `audit_logs` and
 * `theme_publication_events`: a record of what the platform told its merchants must not disappear
 * with the account that wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'platform_maintenance_announcements',
            [
                'title_en', 'title_ar', 'body_en', 'body_ar', 'severity',
                'starts_at', 'ends_at', 'is_published', 'created_by',
                'created_at', 'updated_at',
            ],
            ['pma_live'],
            []
        );

        if (Schema::hasTable('platform_maintenance_announcements')) {
            return;
        }

        Schema::create('platform_maintenance_announcements', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* 160 and not 255: a heading a merchant reads in a banner. Production runs non-strict
               `sql_mode`, where an over-long value is TRUNCATED rather than refused, so the screen
               carries the same `maxlength` -- a title that loses its last three words at 2am is the
               failure this length exists to make visible in the form instead. */
            $table->string('title_en', 160)->nullable();
            $table->string('title_ar', 160)->nullable();

            $table->text('body_en')->nullable();
            $table->text('body_ar')->nullable();

            /* `notice` | `urgent`. A plain string for the reason `theme_publication_events.action`
               records: an enum needs a migration to extend. Anything unrecognised reads as
               `notice`, which is the quieter of the two -- a corrupted value must not shout. */
            $table->string('severity', 16)->default('notice');

            /* Null at either end means "no bound there". See the class docblock. */
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            /* The operator's intent, separate from the window. A new announcement is a DRAFT. */
            $table->boolean('is_published')->default(false);

            /* Not a foreign key -- see the class docblock. */
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            /* THE ONLY QUERY EVERY MERCHANT REQUEST MAKES: "what is live right now". Published
               first, because it is the most selective column by far -- most rows in a mature table
               are past announcements that are still published, so a window-first index would scan
               them all. */
            $table->index(['is_published', 'starts_at', 'ends_at'], 'pma_live');
        });
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as its two siblings and every GATE F migration.
     *
     * Dropping this destroys the record of what the platform told its merchants and when — which is
     * exactly what somebody asks for after an incident. Removing it is a deliberate operator
     * action, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
