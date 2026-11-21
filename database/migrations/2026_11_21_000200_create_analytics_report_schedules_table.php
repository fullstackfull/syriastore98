<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 05 ANA-003 — A MERCHANT'S STANDING REQUEST FOR AN ANALYTICS EXPORT ON A SCHEDULE.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** -- the same posture as the three D8 consent tables
 * and the GAP-219 nudge ledger, because CLAUDE.md reserves a persistent migration to the owner.
 *
 * ANA-003 is "custom reports, export and scheduling, permission-gated". Scheduling was measured
 * ABSENT ENTIRELY: the only scheduled commands in the whole tree were `zid:process-events` and six
 * `lynomia:*` ones, none of them a report.
 *
 * WHAT A ROW IS. One standing request: this store, this employee, this period, this often, to this
 * address. It is NOT a report definition language -- the report is the existing `/analytics` export,
 * which ANA-002 made filterable, so a schedule is a saved PERIOD plus a cadence. That keeps one
 * definition of every figure: the file a schedule sends is produced by the same
 * `MerchantAnalyticsFigures` and `FigureCsv` the screen uses, with the same disclosures in the same
 * columns.
 *
 * THE RECIPIENT IS NOT ARBITRARY, and that is the security decision in this table. An email column
 * a merchant can type anything into is an authenticated mail relay: it would send store data to any
 * address on the internet on a timer, which is an exfiltration path dressed as a feature. So
 * `recipient_email` is written from the AUTHENTICATED USER'S OWN account address at creation time
 * and is not fillable; `ScheduledReportRunner` re-checks it against the owning user on every run,
 * so changing the row by hand does not redirect the file. Sending to a colleague is a real feature
 * and a real decision -- it needs an owner ruling about who may receive store figures, not a text
 * input.
 *
 * `next_due_at` IS THE CLAIM, not a computed guess. The runner takes a due row by writing the next
 * due time FIRST and only sends if that write won, so two overlapping scheduler ticks cannot send
 * one report twice. `last_run_at` and `last_outcome` are kept because a schedule that silently
 * never fires is the defect this whole workstream exists to prevent: the project's own Phase 7
 * readiness notes record that production may have no `php artisan schedule:run` cron entry at all,
 * and that "a deployment without a scheduler passes SS4-SS10 and fails silently". A merchant must be
 * able to SEE that their report has never been sent.
 *
 * `store_id` IS ALWAYS EXPLICIT. This table is not in `config/tenancy.php`'s `scoped_models`, and
 * `null_tenant => 'open'` means a global scope adds no predicate when no tenant resolves -- so a
 * runner that leaned on the scope would read every store's schedules. Every query carries the id.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'analytics_report_schedules',
            [
                'store_id', 'created_by', 'name', 'period_key', 'period_from', 'period_to',
                'frequency', 'recipient_email', 'is_active',
                'next_due_at', 'last_run_at', 'last_outcome', 'created_at', 'updated_at',
            ],
            ['ars_owner_name_unique', 'ars_due'],
            []
        );

        if (Schema::hasTable('analytics_report_schedules')) {
            return;
        }

        Schema::create('analytics_report_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('store_id');

            /* THE EMPLOYEE'S OWN id, deliberately not `creatorId()` -- that helper returns the
               OWNER's id for staff, which would collapse every employee of a merchant onto one set
               of schedules. The same reasoning `SavedView` records. */
            $table->unsignedBigInteger('created_by');

            $table->string('name', 60);

            /* The period, in `AnalyticsPeriod`'s own vocabulary. `period_from`/`period_to` matter
               only for the `custom` key and are nullable for every preset. */
            $table->string('period_key', 32);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            /* `daily` | `weekly` | `monthly`. A merchant choosing how often to receive their OWN
               figures is a preference, not a marketing policy -- unlike GAP-219's shopper cadence,
               which is the owner's and is deliberately unset. */
            $table->string('frequency', 16);

            /* Written from the authenticated user's own address; see the class docblock. */
            $table->string('recipient_email', 191);

            $table->boolean('is_active')->default(true);

            /* THE CLAIM. Indexed with `is_active` because the runner's only query is "what is due". */
            $table->timestamp('next_due_at')->nullable();

            /* So a merchant can see that it has -- or has not -- actually run. */
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_outcome', 32)->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            /* One name per employee per store, so re-saving a name updates rather than duplicating
               -- the same contract `SavedView` has, and the same reason: the merchant's intent is
               "this is what that schedule means now". */
            $table->unique(['store_id', 'created_by', 'name'], 'ars_owner_name_unique');

            $table->index(['is_active', 'next_due_at'], 'ars_due');
        });
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as every GATE F migration and the nudge ledger.
     *
     * Dropping this would destroy a merchant's standing requests and the record of what was sent.
     * Two GATE F drafts dropped a column *because* it existed and a rollback destroyed 45
     * `cost_price` values; removing this table is a deliberate operator action, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
