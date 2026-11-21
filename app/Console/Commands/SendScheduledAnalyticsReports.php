<?php

namespace App\Console\Commands;

use App\Services\Reports\ScheduledReportRunner;
use Illuminate\Console\Command;

/**
 * ANA-003 — the scheduler's entry point for merchant analytics exports.
 *
 * THIN ON PURPOSE. Everything that can be wrong lives in `ScheduledReportRunner`, where a test can
 * drive it without the console: this class parses no input, makes no decision and holds no
 * business rule. A command with logic in it is logic the Feature suite reaches only through
 * `Artisan::call`.
 *
 * IT PRINTS WHAT IT DID, including the refusals. A scheduled task whose output is silence cannot be
 * diagnosed from a cron log, and the four skip outcomes are precisely the things an operator needs
 * to see: no store, no owner, failed, sent.
 *
 * IT DEPENDS ON `php artisan schedule:run` ACTUALLY RUNNING, and that is NOT guaranteed in this
 * deployment. The project's own Phase 7 readiness notes record it as an open item -- "a deployment
 * without a scheduler passes SS4-SS10 and fails silently" -- so the merchant-facing schedule list
 * shows each row's `last_run_at` and says plainly when a schedule has never run. This command is
 * the mechanism; the disclosure is what keeps its absence from looking like success.
 */
class SendScheduledAnalyticsReports extends Command
{
    protected $signature = 'analytics:send-scheduled-reports
                            {--limit= : how many due schedules to send in this tick}';

    protected $description = 'Send the merchant analytics exports whose schedule is due';

    public function handle(ScheduledReportRunner $runner): int
    {
        $limit = (int) ($this->option('limit') ?: ScheduledReportRunner::MAX_PER_RUN);

        $tally = $runner->runDue(null, max(1, $limit));

        if ($tally === []) {
            $this->info('No analytics report schedule was due.');

            return self::SUCCESS;
        }

        foreach ($tally as $outcome => $count) {
            $this->line(sprintf('  %-20s %d', $outcome, $count));
        }

        /*
         * SUCCESS EVEN WITH FAILED ROWS, and that is deliberate. A nonzero exit would make the
         * scheduler treat the whole tick as broken and would hide the fact that the other rows went
         * out. The per-row outcome is the record that matters, and it is on the merchant's screen.
         */
        return self::SUCCESS;
    }
}
