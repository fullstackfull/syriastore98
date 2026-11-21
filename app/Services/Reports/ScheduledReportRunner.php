<?php

namespace App\Services\Reports;

use App\Mail\ScheduledAnalyticsReport;
use App\Models\AnalyticsReportSchedule;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\ReportAccess;
use App\Support\Analytics\AnalyticsExportRows;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Metrics\FigureCsv;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * ANA-003 — SENDING THE SCHEDULED ANALYTICS EXPORTS THAT ARE DUE.
 * =================================================================================================
 *
 * ANA-003 is "custom reports, export and scheduling, permission-gated". Scheduling was measured
 * ABSENT ENTIRELY -- the only scheduled commands in the tree were `zid:process-events` and six
 * `lynomia:*` ones, none of them a report.
 *
 * THE CLAIM IS THE WRITE, NOT THE READ, and that is the whole concurrency design. A due row is
 * taken by UPDATING `next_due_at` conditionally on its current value; only the writer whose update
 * affected a row proceeds to send. A read-then-send would double-send under two overlapping
 * scheduler ticks, and `schedule:run` every minute with a slow mail server is exactly how those
 * overlap. This is the same shape `NudgeLedger::reserve()` uses and for the same reason: the
 * database decides who won, not the application.
 *
 * AUTHORIZATION IS RE-CHECKED AT SEND TIME, not trusted from creation time. A schedule is a
 * standing request that outlives the session that made it: the employee may have lost
 * `Manage Reports`, been deactivated, or been moved to another store since. So every run re-runs
 * `ReportAccess::permits()` against the OWNING user and skips the row if it now refuses -- recorded
 * as a skip, not silently. A permission check that happens only at creation is a permission check
 * that expires.
 *
 * THE RECIPIENT IS THE OWNER'S OWN ADDRESS, re-read from the user row rather than taken from the
 * schedule. The stored `recipient_email` is not fillable, but a row can be edited by hand or by a
 * future bug, and a schedule that mails store figures to an attacker-chosen address on a timer is
 * the worst thing this feature could become. If the two disagree, the USER's current address wins
 * and the divergence is logged.
 *
 * THE STORE COMES FROM THE SCHEDULE'S OWN `store_id`, and every query carries it.
 * `analytics_report_schedules` is not in `config/tenancy.php`'s `scoped_models`, and there is no
 * authenticated principal during a console run at all -- `getCurrentStore()` would be null, and
 * with `null_tenant => 'open'` a scope would add NO predicate. So the explicit id is not belt and
 * braces here; it is the only tenant control that exists on this path.
 *
 * IT RECORDS EVERY OUTCOME. `last_run_at` and `last_outcome` are written whether the send
 * succeeded, failed or was skipped, because the merchant-facing list shows them: the project's own
 * Phase 7 readiness notes record that production may have no `schedule:run` cron entry, and that "a
 * deployment without a scheduler passes SS4-SS10 and fails silently". A schedule that has never run
 * must look different from one that is working.
 */
final class ScheduledReportRunner
{
    /** One tick's bound. A slow mail server must not let one tick run for an hour. */
    public const MAX_PER_RUN = 25;

    /**
     * Send every schedule that is due, and report what happened to each.
     *
     * @return array<string, int> outcome => count, for the command's own output
     */
    public function runDue(?CarbonImmutable $now = null, int $limit = self::MAX_PER_RUN): array
    {
        $now = $now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now);
        $tally = [];

        $due = AnalyticsReportSchedule::query()
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('next_due_at')->orWhere('next_due_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $schedule) {
            if (! $this->claim($schedule, $now)) {
                // Another tick took it. Not an error, and not counted as work.
                continue;
            }

            $outcome = $this->send($schedule, $now);

            $schedule->last_run_at = $now;
            $schedule->last_outcome = $outcome;
            $schedule->save();

            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }

        return $tally;
    }

    /**
     * Take the row by moving `next_due_at` forward, conditional on its current value.
     *
     * TRUE ONLY IF THIS WRITER WON. `update()` returns the number of rows affected, so a second
     * tick whose `where` no longer matches gets 0 and stands down. The next due time is computed
     * from NOW rather than from the old due time, so a schedule that was missed for a week does not
     * then fire seven times catching up -- a merchant wants their weekly report, not seven of them.
     */
    /*
     * PUBLIC SO THE RACE CAN BE DRIVEN, which is the only way this method's guard is testable.
     *
     * A sequential test cannot exercise it: after the first tick `next_due_at` has moved, so the
     * second tick's "what is due" query finds nothing and the conditional `where` is never reached.
     * Measured -- a mutation making this claim UNCONDITIONAL passed a two-tick test, because the
     * due filter was doing the work the claim is credited with.
     *
     * Driving the real race needs two claimants holding the SAME pre-claim row state, which is what
     * `ScheduledAnalyticsReportTest::test_two_claimants_on_one_row_and_only_one_wins` does with two
     * model instances. Keeping this private would have left the concurrency control permanently
     * unproven, and an unproven concurrency control is one somebody simplifies away.
     */
    public function claim(AnalyticsReportSchedule $schedule, CarbonImmutable $now): bool
    {
        $next = $this->nextDue($schedule->frequency, $now);

        $affected = AnalyticsReportSchedule::query()
            ->where('id', $schedule->id)
            ->where('store_id', $schedule->store_id)
            ->where(function ($q) use ($schedule) {
                $schedule->next_due_at === null
                    ? $q->whereNull('next_due_at')
                    : $q->where('next_due_at', $schedule->next_due_at);
            })
            ->update(['next_due_at' => $next, 'updated_at' => $now]);

        if ($affected === 1) {
            $schedule->next_due_at = $next;

            return true;
        }

        return false;
    }

    /** The next occurrence, from now. Never a catch-up backlog; see `claim()`. */
    public function nextDue(string $frequency, CarbonImmutable $from): CarbonImmutable
    {
        return match ($frequency) {
            AnalyticsReportSchedule::FREQ_WEEKLY => $from->addWeek(),
            AnalyticsReportSchedule::FREQ_MONTHLY => $from->addMonthNoOverflow(),
            default => $from->addDay(),
        };
    }

    /**
     * Build and send one schedule's file. Returns the outcome to record.
     *
     * Every refusal is a NAMED outcome rather than a silent return, so the merchant's list can say
     * which of the four things went wrong.
     */
    private function send(AnalyticsReportSchedule $schedule, CarbonImmutable $now): string
    {
        $storeId = (int) $schedule->store_id;

        $store = Store::find($storeId);

        if ($store === null) {
            return AnalyticsReportSchedule::OUTCOME_SKIPPED_NO_STORE;
        }

        $owner = User::find($schedule->created_by);

        /*
         * THE PERMISSION IS RE-CHECKED AGAINST THE OWNER, NOW. A schedule outlives the session that
         * created it; the employee may have lost the permission, been deactivated or moved. The
         * operator limb inside `ReportAccess::permits()` is harmless here because the schedule's
         * own `store_id` is what scopes the figures -- an operator's schedule reads the store they
         * made it for, not store 1.
         */
        if ($owner === null || ! ReportAccess::permits($owner)) {
            return AnalyticsReportSchedule::OUTCOME_SKIPPED_NO_OWNER;
        }

        $recipient = trim((string) $owner->email);

        if ($recipient === '') {
            return AnalyticsReportSchedule::OUTCOME_SKIPPED_NO_OWNER;
        }

        if ($recipient !== trim((string) $schedule->recipient_email)) {
            /*
             * The stored address and the owner's current one disagree. The OWNER'S wins -- the
             * stored column is a convenience for display, never the authority for where store
             * figures go. Logged because the only innocent cause is the user changing their e-mail,
             * and the guilty one is a hand-edited row.
             */
            Log::warning('scheduled analytics report: stored recipient differs from the owner address; using the owner', [
                'schedule_id' => $schedule->id,
                'store_id' => $storeId,
            ]);
        }

        try {
            $currency = (string) (Setting::where('store_id', $storeId)
                ->where('name', 'defult_currancy')
                ->value('value') ?? 'USD');

            $period = AnalyticsPeriod::fromRequest([
                AnalyticsPeriod::KEY_PERIOD => (string) $schedule->period_key,
                AnalyticsPeriod::KEY_FROM => $schedule->period_from?->toDateString() ?? '',
                AnalyticsPeriod::KEY_TO => $schedule->period_to?->toDateString() ?? '',
            ], $now);

            // THE SAME FIGURES AND THE SAME ROW SET AS THE SCREEN'S OWN DOWNLOAD. No second query,
            // no second definition -- that is why `AnalyticsExportRows` exists.
            $figures = MerchantAnalyticsFigures::forStore($storeId, $currency, $now, $period);
            $csv = FigureCsv::render(AnalyticsExportRows::build($figures));

            Mail::to($recipient)->send(new ScheduledAnalyticsReport(
                storeName: (string) ($store->name ?? ''),
                scheduleName: (string) $schedule->name,
                periodLabel: (string) ($figures['period']['label'] ?? ''),
                runAtLabel: $now->toDayDateTimeString(),
                csv: $csv,
                filename: 'analytics-' . $now->toDateString() . '.csv',
            ));

            return AnalyticsReportSchedule::OUTCOME_SENT;
        } catch (\Throwable $e) {
            /*
             * RECORDED, NOT RETHROWN. One misconfigured store's mail settings must not stop the
             * other schedules in this tick, and the merchant needs to SEE that theirs failed -- an
             * exception that aborts the command leaves every later schedule silently unrun.
             */
            Log::warning('scheduled analytics report failed', [
                'schedule_id' => $schedule->id,
                'store_id' => $storeId,
                'exception' => $e->getMessage(),
            ]);

            return AnalyticsReportSchedule::OUTCOME_FAILED;
        }
    }
}
