<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();
Schedule::command('zid:process-events')->everyMinute()->withoutOverlapping();

/*
 * ANA-003 — the merchant analytics exports whose schedule is due.
 *
 * HOURLY, NOT EVERY MINUTE. The runner computes each row's next due time from NOW when it claims
 * it, so the tick rate only bounds how late a report can be, not how often one is sent. Hourly
 * keeps a daily schedule accurate to within an hour and costs 24 near-empty queries a day instead
 * of 1,440.
 *
 * `withoutOverlapping()` as well as the runner's own conditional claim. The claim is what makes
 * double-sending impossible; this is what stops two ticks competing for the same rows and doing the
 * work twice over for nothing.
 *
 * NOTE WHAT THIS DOES NOT GUARANTEE: `php artisan schedule:run` must be in the server's crontab,
 * and the project's Phase 7 readiness notes record that as an unverified item for production. The
 * merchant-facing list shows each schedule's last run for exactly that reason -- see
 * `ScheduledReportRunner`.
 */
Schedule::command('analytics:send-scheduled-reports')->hourly()->withoutOverlapping();

/*
 * ANA-009 — the metric floors each merchant set, checked hourly.
 *
 * HOURLY FOR THE SAME REASON ANA-003 IS, and it is safe for a different one: the alert is
 * deduplicated on the PERIOD (metric plus the window's end date), not on the run, so twenty-four
 * ticks over one breached day record ONE notice. The tick rate bounds how late an alert can be, not
 * how many there are.
 *
 * IT DOES NOTHING ON A PLATFORM WHERE NOBODY HAS SET A FLOOR, which is the shipped state: the
 * command's candidate set comes from `settings`, so with no configuration it answers in one query
 * and returns. There is no default threshold anywhere in this feature, by design — choosing one
 * would be inventing a business policy.
 *
 * THE SAME CAVEAT AS ANA-003: `php artisan schedule:run` must be in the server's crontab, which the
 * Phase 7 readiness notes record as unverified for production.
 */
Schedule::command('analytics:check-metric-thresholds')->hourly()->withoutOverlapping();