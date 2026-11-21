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