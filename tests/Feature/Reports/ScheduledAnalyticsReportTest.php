<?php

namespace Tests\Feature\Reports;

use App\Models\AnalyticsReportSchedule;
use App\Models\Order;
use App\Services\Reports\ScheduledReportRunner;
use App\Support\Analytics\AnalyticsExportRows;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Metrics\FigureCsv;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Mail\ScheduledAnalyticsReport;
use Illuminate\Support\Facades\Mail;
use Shetabit\Visitor\Models\Visit;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-003 — THE SCHEDULED ANALYTICS EXPORT.
 * =================================================================================================
 *
 * Scheduling was measured ABSENT ENTIRELY: the only scheduled commands in the tree were
 * `zid:process-events` and six `lynomia:*` ones, none of them a report.
 *
 * WHAT THIS FILE PROVES, organised around what a scheduled send must not do:
 *
 *  1. IT MUST NOT PRODUCE A DIFFERENT FILE FROM THE SCREEN'S OWN DOWNLOAD. A merchant comparing the
 *     two must find the same figures with the same disclosures, so the CSV is asserted BYTE FOR BYTE
 *     against the one `AnalyticsExportRows` builds for the same period. That is what makes
 *     extracting the row set a correctness measure rather than tidying.
 *  2. IT MUST NOT SEND TWICE. The claim is a conditional write, because two `schedule:run` ticks
 *     really do overlap when a mail server is slow. Driven by running the tick twice.
 *  3. IT MUST NOT KEEP SENDING TO SOMEONE WHO HAS LOST THE PERMISSION. A schedule outlives the
 *     session that created it, so authorization is re-checked at send time against the owning user.
 *  4. IT MUST NOT SEND STORE FIGURES TO AN ADDRESS NOBODY AUTHORISED. The recipient is the owner's
 *     own account address, re-read at send time, so a hand-edited row cannot redirect the file --
 *     which is the difference between a feature and an authenticated mail relay.
 *  5. IT MUST NOT CROSS A TENANT. There is NO authenticated principal during a console run, so
 *     `getCurrentStore()` is null and `null_tenant => 'open'` means a global scope adds no
 *     predicate at all. The schedule's own `store_id` is the only control on that path.
 *  6. IT MUST NOT FAIL SILENTLY. Every outcome is recorded, because the project's own Phase 7
 *     readiness notes say production may have no `schedule:run` cron entry and that such a
 *     deployment "fails silently".
 *
 * MAIL IS THE `array` TRANSPORT in this suite (`phpunit.xml`), so nothing leaves the machine and
 * `Mail::fake()` is not needed to assert on what was sent.
 */
final class ScheduledAnalyticsReportTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-20 09:00:00');
    }

    private function visit(int $storeId, int $daysAgo): void
    {
        $visit = new Visit();
        $visit->timestamps = false;
        $visit->store_id = $storeId;
        $visit->device = 'iPhone';
        $visit->created_at = $this->now()->subDays($daysAgo);
        $visit->updated_at = $this->now()->subDays($daysAgo);
        $visit->save();
    }

    /** A schedule owned by this user in this store. Ownership is set as properties, never filled. */
    private function schedule($owner, $store, array $attributes = []): AnalyticsReportSchedule
    {
        $row = new AnalyticsReportSchedule();
        $row->fill(array_merge([
            'name' => 'Weekly numbers',
            'period_key' => 'last_7_days',
            'frequency' => AnalyticsReportSchedule::FREQ_WEEKLY,
            'is_active' => true,
        ], $attributes));

        $row->store_id = $store->id;
        $row->created_by = $owner->id;
        $row->recipient_email = $owner->email;
        $row->next_due_at = $attributes['next_due_at'] ?? null;
        $row->save();

        return $row->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `Mail::fake()` RATHER THAN THE `array` TRANSPORT, although `phpunit.xml` already sets
         * that. The array transport records Symfony messages, so reading an attachment back means
         * walking MIME parts; the fake records the MAILABLE, whose `$csv` property is the exact
         * string `FigureCsv::render()` produced. That makes the byte-identical assertion below a
         * statement about the file rather than about MIME encoding, and it also means `hasTo()`
         * answers the recipient question directly.
         */
        Mail::fake();
    }

    private function runner(): ScheduledReportRunner
    {
        return app(ScheduledReportRunner::class);
    }

    /** @return \Illuminate\Support\Collection<int, ScheduledAnalyticsReport> */
    private function sent()
    {
        return Mail::sent(ScheduledAnalyticsReport::class);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 1 — the premise, and the file
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_command_is_registered_and_scheduled(): void
    {
        $commands = \Artisan::all();

        $this->assertArrayHasKey('analytics:send-scheduled-reports', $commands,
            'The scheduled-export command is not registered, so nothing can ever run it.');

        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $found = false;

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'analytics:send-scheduled-reports')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found,
            'The command exists but is not in the schedule, so a merchant\'s report would never '
            . 'be sent. ANA-003 asks for scheduling, not for a command somebody could run by hand.');
    }

    public function test_a_due_schedule_sends_the_file_to_its_owner(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Reports');
        $this->visit($store->id, 2);

        $schedule = $this->schedule($owner->fresh(), $store);

        $tally = $this->runner()->runDue($this->now());

        $this->assertSame([AnalyticsReportSchedule::OUTCOME_SENT => 1], $tally);

        $messages = $this->sent();

        $this->assertCount(1, $messages, 'Exactly one e-mail should have been sent.');

        $report = $messages->first();

        $this->assertTrue($report->hasTo($owner->email),
            'The report went somewhere other than the owning employee\'s own address.');

        $this->assertNotSame('', $report->csv,
            'The report carries an empty file, so it carries no figures.');
        $this->assertStringContainsString('.csv', $report->filename);
        $this->assertStringContainsString('Page views', $report->csv,
            'The attached file does not contain the screen\'s own tile labels.');
    }

    /**
     * THE SCHEDULED FILE IS THE SCREEN'S FILE, byte for byte.
     *
     * This is the assertion that makes extracting `AnalyticsExportRows` a correctness measure. If
     * the runner ever grew its own row set, a merchant comparing their scheduled file with the
     * download would find two different answers to the same question, each internally consistent.
     */
    public function test_the_scheduled_file_is_byte_identical_to_the_screens_own_export(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Reports');

        $this->visit($store->id, 1);
        $this->visit($store->id, 3);

        $schedule = $this->schedule($owner->fresh(), $store, ['period_key' => 'last_7_days']);

        $this->runner()->runDue($this->now());

        $scheduledCsv = $this->sent()->first()->csv;

        // The same period, the same figures class, the same row builder -- the screen's path.
        $period = AnalyticsPeriod::fromRequest(
            [AnalyticsPeriod::KEY_PERIOD => 'last_7_days'],
            $this->now()
        );
        $expected = FigureCsv::render(AnalyticsExportRows::build(
            MerchantAnalyticsFigures::forStore($store->id, 'USD', $this->now(), $period)
        ));

        $this->assertSame($expected, $scheduledCsv,
            'The scheduled file differs from the file the screen would produce for the same period. '
            . 'Two definitions of one export is how a merchant ends up with two different answers '
            . 'to the same question.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 2 — it must not send twice
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_second_tick_does_not_send_the_same_schedule_again(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Reports');

        $this->schedule($owner->fresh(), $store);

        $this->runner()->runDue($this->now());
        $second = $this->runner()->runDue($this->now());

        $this->assertSame([], $second,
            'A second tick at the same moment sent the report again. The due claim must be a '
            . 'conditional write: two schedule:run ticks overlap whenever a mail server is slow.');
        $this->assertCount(1, $this->sent());
    }

    /**
     * THE NEXT DUE TIME IS COMPUTED FROM NOW, so a schedule missed for a month does not then fire
     * thirty times catching up. A merchant wants their daily report, not a month of them.
     */
    public function test_a_missed_schedule_does_not_produce_a_backlog(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Reports');

        // Due a month ago and never run -- the shape a stopped scheduler leaves behind.
        $this->schedule($owner->fresh(), $store, [
            'frequency' => AnalyticsReportSchedule::FREQ_DAILY,
            'next_due_at' => $this->now()->subMonth(),
        ]);

        $this->runner()->runDue($this->now());

        $this->assertCount(1, $this->sent(),
            'A schedule that was due a month ago sent more than one report.');

        $row = AnalyticsReportSchedule::withoutGlobalScopes()->first();

        $this->assertSame(
            $this->now()->addDay()->toDateTimeString(),
            $row->next_due_at->toDateTimeString(),
            'The next due time was computed from the OLD due time, so this schedule will keep '
            . 'firing until it has caught up a month of reports.'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 3 and 4 — authorization and the recipient, re-checked at send time
     * ---------------------------------------------------------------------------------------------
     */

    public function test_an_owner_who_has_lost_the_permission_is_skipped_and_recorded(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Manage Reports');

        $schedule = $this->schedule($staff->fresh(), $store);

        // Premise: with the permission it sends.
        $this->assertSame([AnalyticsReportSchedule::OUTCOME_SENT => 1], $this->runner()->runDue($this->now()));

        // The permission is taken away, and the schedule becomes due again.
        $staff->fresh()->roles()->detach();
        (new \App\Models\User)->flushCache();
        AnalyticsReportSchedule::withoutGlobalScopes()->where('id', $schedule->id)
            ->update(['next_due_at' => $this->now()->subDay()]);

        $tally = $this->runner()->runDue($this->now()->addDay());

        $this->assertSame([AnalyticsReportSchedule::OUTCOME_SKIPPED_NO_OWNER => 1], $tally,
            'A schedule kept sending after its owner lost Manage Reports. A permission checked only '
            . 'at creation is a permission that expires.');

        $this->assertCount(1, $this->sent(), 'Only the first, authorised send should exist.');

        $row = AnalyticsReportSchedule::withoutGlobalScopes()->find($schedule->id);

        $this->assertSame(AnalyticsReportSchedule::OUTCOME_SKIPPED_NO_OWNER, $row->last_outcome,
            'The skip was not recorded, so the merchant cannot see why their report stopped.');
    }

    /**
     * A HAND-EDITED RECIPIENT CANNOT REDIRECT THE FILE.
     *
     * `recipient_email` is not fillable, but a row can be changed by a direct write or a future bug,
     * and a schedule that mails store figures to an attacker-chosen address on a timer is the worst
     * thing this feature could become. The owner's CURRENT address is the authority.
     */
    public function test_a_tampered_recipient_is_ignored_in_favour_of_the_owners_own_address(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Reports');

        $schedule = $this->schedule($owner->fresh(), $store);

        AnalyticsReportSchedule::withoutGlobalScopes()->where('id', $schedule->id)
            ->update(['recipient_email' => 'attacker@elsewhere.test']);

        $this->runner()->runDue($this->now());

        $recipients = $this->sent()
            ->map(fn ($m) => collect($m->to)->pluck('address')->implode(','))
            ->values()
            ->all();

        $this->assertSame([$owner->email], $recipients, sprintf(
            'The report was sent to %s. The stored column is for display; the owning user\'s own '
            . 'address is the only authority for where store figures go, or this is an '
            . 'authenticated mail relay on a timer.',
            implode(', ', $recipients)
        ));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 5 — tenancy, with no principal at all
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE FIGURES ARE THE SCHEDULE'S STORE, and nothing resolves a tenant during a console run.
     *
     * Store B is given far more traffic than store A, so a leak would be unmistakable in the file.
     * This is the assertion that matters most on this path: `getCurrentStore()` is null here, and
     * `null_tenant => 'open'` means a global scope would add NO predicate, so the schedule's own
     * `store_id` is the only thing keeping the two apart.
     */
    public function test_the_file_holds_only_the_schedules_own_stores_figures(): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($ownerA, 'Manage Reports');

        $this->visit($storeA->id, 1);

        foreach (range(1, 6) as $d) {
            $this->visit($storeB->id, $d);
        }

        $this->schedule($ownerA->fresh(), $storeA);

        $this->runner()->runDue($this->now());

        $csv = $this->sent()->first()->csv;
        $plain = (string) preg_replace('/[\x{2066}-\x{2069}]/u', '', $csv);

        /*
         * THE LABEL IS QUOTED, because PHP's `fputcsv` quotes any field containing a SPACE -- so
         * the row really reads `"Page views",1,...`. The first version of this assertion matched
         * `/Page views,1,/` and failed against a correct file; measured rather than guessed:
         *
         *     fputcsv(h, ['Page views','1','last 7 days','hint'], escape: '')
         *         -> "Page views",1,"last 7 days",hint
         */
        $this->assertMatchesRegularExpression('/"Page views",1,/', $plain, sprintf(
            "The file does not report store A's single page view. Store B has six, so a count of 7 "
            . "would mean the console run read every store. File was:\n%s",
            $plain
        ));
        $this->assertDoesNotMatchRegularExpression('/"Page views",7,/', $plain,
            'The scheduled file counted another store\'s visits.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * 6 — nothing fails silently
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_schedule_that_has_never_run_is_distinguishable_from_one_that_works(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Reports');

        $fresh = $this->schedule($owner->fresh(), $store);

        $this->assertFalse($fresh->hasEverRun(),
            'A newly created schedule must report that it has never run -- the project\'s own '
            . 'readiness notes record that production may have no schedule:run cron entry, and a '
            . 'schedule that never fires must not look like one that works.');
        $this->assertNull($fresh->last_outcome);

        $this->runner()->runDue($this->now());

        $after = AnalyticsReportSchedule::withoutGlobalScopes()->find($fresh->id);

        $this->assertTrue($after->hasEverRun());
        $this->assertSame(AnalyticsReportSchedule::OUTCOME_SENT, $after->last_outcome);
        $this->assertNotNull($after->last_run_at);
    }

    /**
     * ONE *SKIPPED* SCHEDULE MUST NOT STOP THE OTHERS.
     *
     * RENAMED after a mutation exposed the original claim as too broad. This drives the NO-STORE
     * path, which returns a named outcome BEFORE the try/catch -- so it says nothing about what
     * happens when a send genuinely throws, and a mutation replacing the catch with `throw $e`
     * passed it. The throwing case is the next test; this one is about the early returns.
     */
    public function test_one_skipped_schedule_does_not_stop_the_rest(): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();

        $this->grantPermission($ownerA, 'Manage Reports');
        $this->grantPermission($ownerB, 'Manage Reports');

        // A's store row is removed, so its send is skipped with a named outcome.
        $scheduleA = $this->schedule($ownerA->fresh(), $storeA);
        \App\Models\Store::where('id', $storeA->id)->delete();

        $scheduleB = $this->schedule($ownerB->fresh(), $storeB);

        $tally = $this->runner()->runDue($this->now());

        $this->assertSame(1, $tally[AnalyticsReportSchedule::OUTCOME_SENT] ?? 0, sprintf(
            "The healthy schedule did not send. Tally was: %s",
            json_encode($tally)
        ));
        $this->assertSame(1, $tally[AnalyticsReportSchedule::OUTCOME_SKIPPED_NO_STORE] ?? 0,
            'The broken schedule was not recorded with a named outcome.');

        $this->assertCount(1, $this->sent());
    }

    /**
     * A SEND THAT THROWS IS RECORDED AS `failed` AND THE TICK CARRIES ON.
     *
     * The gap a mutation found: the test above drives an EARLY RETURN that never reaches the
     * try/catch, so replacing the catch with `throw $e` passed it. An escaping exception would
     * abort `runDue()` and leave every later schedule in that tick silently unrun -- the precise
     * failure this slice exists to prevent, and invisible, because those schedules would still read
     * "never run".
     *
     * `Mail::shouldReceive('to')->andThrow()` replaces the fake for this case, so the throw comes
     * from the real send path rather than from a stub inside the runner.
     */
    public function test_a_send_that_throws_is_recorded_and_does_not_stop_the_tick(): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();

        $this->grantPermission($ownerA, 'Manage Reports');
        $this->grantPermission($ownerB, 'Manage Reports');

        $a = $this->schedule($ownerA->fresh(), $storeA, ['name' => 'A']);
        $b = $this->schedule($ownerB->fresh(), $storeB, ['name' => 'B']);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp is down'));

        $tally = $this->runner()->runDue($this->now());

        $this->assertSame(
            [AnalyticsReportSchedule::OUTCOME_FAILED => 2],
            $tally,
            'A throwing send did not produce two recorded failures. If the exception escaped '
            . '`send()`, `runDue()` aborted and the second schedule was never attempted -- exactly '
            . 'the silent failure this slice guards against.'
        );

        foreach ([$a->id, $b->id] as $id) {
            $row = AnalyticsReportSchedule::withoutGlobalScopes()->find($id);

            $this->assertSame(AnalyticsReportSchedule::OUTCOME_FAILED, $row->last_outcome, sprintf(
                'Schedule %d has outcome "%s". A merchant must be able to see that their report '
                . 'FAILED rather than that it never ran.',
                $id,
                (string) $row->last_outcome
            ));
            $this->assertNotNull($row->last_run_at);
        }
    }

    /**
     * TWO CLAIMANTS ON ONE ROW, AND ONLY ONE WINS — the concurrency control, driven for real.
     *
     * This exists because a mutation making the claim UNCONDITIONAL passed the two-tick test: after
     * the first tick `next_due_at` has moved, so the second tick's due filter finds nothing and the
     * claim is never reached. The filter was taking the credit.
     *
     * Two `schedule:run` ticks really do overlap -- `withoutOverlapping()` is advisory and a slow
     * mail server makes a tick outlast its minute -- and both would read the same pre-claim row.
     * Two model instances holding that state is that race, without threads.
     */
    public function test_two_claimants_on_one_row_and_only_one_wins(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Reports');

        $this->schedule($owner->fresh(), $store, ['next_due_at' => $this->now()->subHour()]);

        // Two readers, both holding the row as it was BEFORE either claimed it.
        $first = AnalyticsReportSchedule::withoutGlobalScopes()->first();
        $second = AnalyticsReportSchedule::withoutGlobalScopes()->first();

        $this->assertSame(
            $first->next_due_at->toDateTimeString(),
            $second->next_due_at->toDateTimeString(),
            'Premise: both claimants must start from the same row state, or there is no race.'
        );

        $runner = $this->runner();

        $wonFirst = $runner->claim($first, $this->now());
        $wonSecond = $runner->claim($second, $this->now());

        $this->assertTrue($wonFirst, 'The first claimant did not win an uncontested row.');
        $this->assertFalse($wonSecond,
            'BOTH claimants won the same row, so two overlapping scheduler ticks would each send '
            . 'the report. The claim must be a write conditional on the value that was read.');
    }

    /** An inactive schedule is not due, however long ago its next_due_at was. */
    public function test_an_inactive_schedule_is_never_sent(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Reports');

        $this->schedule($owner->fresh(), $store, [
            'is_active' => false,
            'next_due_at' => $this->now()->subYear(),
        ]);

        $this->assertSame([], $this->runner()->runDue($this->now()));
        $this->assertCount(0, $this->sent());
    }
}
