<?php

namespace Tests\Feature\Reports;

use App\Models\AnalyticsReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-003 — THE MERCHANT-FACING HALF: creating and stopping a scheduled export.
 * =================================================================================================
 *
 * The engine is driven by `ScheduledAnalyticsReportTest`. This file is about the door, and it is
 * organised around the two things that would turn a convenience into a liability:
 *
 *  1. THE RECIPIENT MUST NOT COME FROM REQUEST INPUT. An address a merchant can type is an
 *     authenticated mail relay: store figures to any address on the internet, on a timer. The
 *     handler writes the authenticated user's OWN account address, and this file posts an `email`
 *     field to prove it is ignored.
 *  2. A SCHEDULE ID MUST NOT BE A BARE ROW ID. `analytics_report_schedules` is not in
 *     `config/tenancy.php`'s `scoped_models`, and `null_tenant => 'open'` means no global scope
 *     adds a predicate for anybody -- so a `find()` in the delete handler would let one merchant
 *     stop another's report by guessing an id. Driven with a real foreign row.
 *
 * Plus the ordinary contract: the gate is the screen's own gate, re-saving a name updates rather
 * than duplicating, and a NEW schedule is not due the instant it is created.
 */
final class AnalyticsScheduleSurfaceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/analytics';

    private const CREATE = '/analytics/schedules';

    /** @return array<int, AnalyticsReportSchedule> */
    private function rows(): array
    {
        return AnalyticsReportSchedule::withoutGlobalScopes()->orderBy('id')->get()->all();
    }

    public function test_a_merchant_can_schedule_the_export_they_are_looking_at(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->post(self::CREATE, [
            'name' => 'Monday numbers',
            'frequency' => AnalyticsReportSchedule::FREQ_WEEKLY,
            'ss_period' => 'last_7_days',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertCount(1, $rows, 'Scheduling wrote no row.');

        $row = $rows[0];

        $this->assertSame('Monday numbers', $row->name);
        $this->assertSame('last_7_days', $row->period_key,
            'The schedule did not save the period that was on screen.');
        $this->assertSame(AnalyticsReportSchedule::FREQ_WEEKLY, $row->frequency);
        $this->assertSame((int) $store->id, (int) $row->store_id);
        $this->assertSame($merchant->id, (int) $row->created_by);
        $this->assertSame($merchant->email, $row->recipient_email);
        $this->assertTrue($row->is_active);
    }

    /**
     * THE RECIPIENT IS NOT A PARAMETER, and posting one proves it.
     *
     * `recipient_email` is absent from `$fillable`, so `fill()` would drop it -- but the handler
     * also never reads the request for it, and this asserts the OUTCOME rather than the mechanism:
     * whatever a caller posts, the file goes to their own account address.
     */
    public function test_a_posted_recipient_store_or_owner_is_ignored(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$otherMerchant, $otherStore] = $this->makeTenant();

        /*
         * EVERY FIELD THAT DECIDES OWNERSHIP OR DESTINATION IS POSTED, and none may be honoured.
         *
         * The `store_id` case was added after a mutation exposed that nothing drove it: a handler
         * reading `$request->input('store_id')` would have written one merchant's schedule into
         * another's partition, and CLAUDE.md names a tenant predicate built from caller-supplied
         * input as the pattern that has produced four separate defects here. The recipient case is
         * the exfiltration one; the `created_by` case is the `order_notes` lesson.
         */
        $this->actingAs($merchant->fresh())->post(self::CREATE, [
            'name' => 'Exfiltration attempt',
            'frequency' => AnalyticsReportSchedule::FREQ_DAILY,
            'email' => 'attacker@elsewhere.test',
            'recipient_email' => 'attacker@elsewhere.test',
            'store_id' => $otherStore->id,
            'created_by' => $otherMerchant->id,
        ]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);

        $this->assertSame($merchant->email, $rows[0]->recipient_email, sprintf(
            'The schedule will send this store\'s figures to %s. A recipient taken from request '
            . 'input makes this an authenticated mail relay on a timer.',
            $rows[0]->recipient_email
        ));

        $this->assertSame((int) $store->id, (int) $rows[0]->store_id, sprintf(
            'The schedule was filed under store %d instead of the caller\'s own store %d, so a '
            . 'recurring export of another tenant\'s figures was just created from request input.',
            (int) $rows[0]->store_id,
            (int) $store->id
        ));

        $this->assertSame($merchant->id, (int) $rows[0]->created_by,
            'The schedule was filed under another employee.');
    }

    /**
     * A NEW SCHEDULE IS NOT DUE IMMEDIATELY.
     *
     * Leaving `next_due_at` null would make it due at once, so pressing Save would send a report
     * the merchant has not asked for yet -- and on a daily schedule they would receive two on the
     * first day.
     */
    public function test_a_new_schedule_is_due_at_its_first_interval_not_at_once(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->post(self::CREATE, [
            'name' => 'Daily',
            'frequency' => AnalyticsReportSchedule::FREQ_DAILY,
        ]);

        $row = $this->rows()[0];

        $this->assertNotNull($row->next_due_at,
            'A null next_due_at makes the schedule due at once, so saving it sends a report the '
            . 'merchant did not ask for yet.');
        $this->assertTrue(
            $row->next_due_at->greaterThan(CarbonImmutable::now()),
            'The first run is already due.'
        );
    }

    public function test_re_saving_a_name_updates_rather_than_duplicating(): void
    {
        [$merchant] = $this->makeTenant();

        foreach ([AnalyticsReportSchedule::FREQ_DAILY, AnalyticsReportSchedule::FREQ_MONTHLY] as $freq) {
            $this->actingAs($merchant->fresh())->post(self::CREATE, [
                'name' => 'Same name',
                'frequency' => $freq,
            ]);
        }

        $rows = $this->rows();

        $this->assertCount(1, $rows,
            'Re-saving a name created a second schedule, so the merchant now receives two reports.');
        $this->assertSame(AnalyticsReportSchedule::FREQ_MONTHLY, $rows[0]->frequency,
            'The update did not take effect.');
    }

    public function test_an_unknown_frequency_is_refused(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->post(self::CREATE, [
            'name' => 'Every second',
            'frequency' => 'everySecond',
        ]);

        $this->assertCount(0, $this->rows(),
            'An unvalidated frequency reached the row, and `ScheduledReportRunner::nextDue()` '
            . 'falls back to daily for anything it does not recognise -- so this would silently '
            . 'become a daily report.');
    }

    public function test_a_staff_member_without_the_report_permission_cannot_schedule(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $this->actingAs($staff->fresh())->post(self::CREATE, [
            'name' => 'Sneaky',
            'frequency' => AnalyticsReportSchedule::FREQ_DAILY,
        ]);

        $this->assertCount(0, $this->rows(),
            'A staff member with no report permission created a recurring export of figures they '
            . 'cannot read.');
    }

    /**
     * ANOTHER STORE'S SCHEDULE CANNOT BE STOPPED, and the row is created directly so the test does
     * not depend on the other merchant being able to create one.
     */
    public function test_another_stores_schedule_cannot_be_deleted(): void
    {
        [$merchant] = $this->makeTenant();
        [$otherMerchant, $otherStore] = $this->makeTenant();

        $foreign = new AnalyticsReportSchedule();
        $foreign->fill([
            'name' => 'Theirs',
            'period_key' => 'last_7_days',
            'frequency' => AnalyticsReportSchedule::FREQ_DAILY,
            'is_active' => true,
        ]);
        $foreign->store_id = $otherStore->id;
        $foreign->created_by = $otherMerchant->id;
        $foreign->recipient_email = $otherMerchant->email;
        $foreign->save();

        $this->actingAs($merchant->fresh())->delete('/analytics/schedules/' . $foreign->id);

        $this->assertNotNull(
            AnalyticsReportSchedule::withoutGlobalScopes()->find($foreign->id),
            'One merchant stopped another merchant\'s scheduled report. The id came from the URL '
            . 'and was resolved without the ownership predicate -- the pattern CLAUDE.md names as '
            . 'the most productive defect class in this codebase.'
        );
    }

    public function test_a_merchant_can_stop_their_own_schedule(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->post(self::CREATE, [
            'name' => 'Mine',
            'frequency' => AnalyticsReportSchedule::FREQ_DAILY,
        ]);

        $id = $this->rows()[0]->id;

        $this->actingAs($merchant->fresh())->delete('/analytics/schedules/' . $id)->assertRedirect();

        $this->assertCount(0, $this->rows(), 'The merchant could not stop their own schedule.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The disclosure that keeps a dead scheduler from looking like success
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * A SCHEDULE THAT HAS NEVER RUN SAYS SO, IN WORDS, ON THE SCREEN.
     *
     * Delivery depends on the server running `php artisan schedule:run`, and the project's own
     * Phase 7 readiness notes record that as unverified for production -- "a deployment without a
     * scheduler passes SS4-SS10 and fails silently". Without this, a merchant whose server has no
     * cron entry sees a tidy list of schedules and no indication that none of them has ever sent
     * anything. That is the same defect as a figure with a label that does not describe it.
     */
    public function test_the_screen_says_when_a_schedule_has_never_run_and_why_that_may_be(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->post(self::CREATE, [
            'name' => 'Never run yet',
            'frequency' => AnalyticsReportSchedule::FREQ_DAILY,
        ]);

        $html = $this->actingAs($merchant->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('Never run yet', $html, 'The schedule is not listed.');

        $this->assertStringContainsString('Never sent yet', $html,
            'The screen does not say that this schedule has never sent anything, so a server with '
            . 'no scheduler cron entry looks exactly like one that works.');

        $this->assertStringContainsString('scheduler may not be running', $html,
            'The screen does not tell the merchant that delivery depends on the server\'s '
            . 'scheduler, so "never sent" reads as a product fault with no next step.');
    }

    /** And the list is one employee's own: another employee's schedules are not shown. */
    public function test_one_employees_schedules_are_not_shown_to_another(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $colleague = $this->makeStaff($merchant);
        $this->grantPermission($colleague, 'Manage Reports');

        $this->actingAs($merchant->fresh())->post(self::CREATE, [
            'name' => 'Owner only',
            'frequency' => AnalyticsReportSchedule::FREQ_DAILY,
        ]);

        $html = $this->actingAs($colleague->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('Owner only', $html,
            'One employee\'s private schedule is listed on another employee\'s screen. A schedule '
            . 'is `(store_id, created_by)`, like a saved view, for the same reason.');
    }
}
