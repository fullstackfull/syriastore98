<?php

namespace Tests\Feature\Analytics;

use App\Models\SavedView;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Metrics\FinanceLedgerFigures;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * P5-R15 — THE /analytics MONEY TILES FOLLOW THE PERIOD CONTROL, WHEN ONE WAS ASKED FOR.
 * =============================================================================================
 *
 * `MerchantAnalyticsFigures::forStore()` computed its money half on its FIRST statement, before
 * the period was resolved, and passed no window. So ten figures obeyed the control and two did
 * not: a merchant who chose "Last 7 days" saw seven days of orders beside the ledger's whole
 * coverage of money.
 *
 * NOTHING ON SCREEN WAS FALSE, and that measurement decided the shape of the fix. `<x-ss.stat>`
 * makes `period` a required prop and each money tile prints its own, so the tile read
 * "from 2 November 2026" while the selector read seven days. The defect was an unresponsive
 * control, not a lying tile — which is why this is a correctness-of-interface change and not a
 * repair of a wrong number.
 *
 * THE RULE IS THE ONE THE SIBLING SCREEN ALREADY SHIPS, and it was found by looking rather than
 * by choosing. `ReportController`'s finance report resolves `null` unless the REQUEST named a
 * period, because `AnalyticsPeriod::fromRequest([])` falls through to `last_30_days` and
 * "resolving one unconditionally would narrow every existing reader's figure without being
 * asked". `FinanceReportPeriodTest::test_with_no_period_requested_the_figures_are_unchanged`
 * pins that direction and `::test_choosing_all_time_in_the_control_is_not_a_period` pins the
 * other. /analytics was the screen that was missed, so the two directions are pinned here too.
 *
 * WHY NOT NARROW THE DEFAULT VIEW AS WELL: it would move a money figure for every merchant who
 * has never touched the filter, with no correctness argument behind it. That is the owner's call,
 * not an engineer's, and it is deliberately NOT done here.
 *
 * ASSERTED ON THE TILE'S OWN FIGURE, NOT ON PAGE TEXT CONTAINING AN AMOUNT. The sibling file
 * records why in terms: its first version searched the body for the inside amount and against the
 * outside one, and "a mutation that never passed the window at all survived it", because the tile
 * prints a SUM. The two amounts here are chosen so that neither rendered string is a substring of
 * the other, and the figures-level cases compare exact values.
 */
final class AnalyticsMoneyFollowsTheChosenPeriodTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const URI = '/analytics';

    /** Inside a 7-day window. */
    private const RECENT = '123.45';

    /** Forty days old: outside every window these cases choose, inside the ledger's own coverage. */
    private const OLD = '9753.09';

    /** RECENT + OLD. "123.45" is not a substring of "9,876.54" and vice versa — checked, not assumed. */
    private const BOTH_RENDERED = '9,876.54';

    private const RECENT_RENDERED = '123.45';

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function merchant(): array
    {
        $this->makeTenant();                              // a decoy tenant, never the one under test
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission(\App\Models\User::find($merchant->id), 'Manage Reports');
        Cache::forget('admin_details');

        return [$merchant, $store];
    }

    /** A collected payment, which is the day `CollectedCustomerPayments` counts. */
    private function payment($store, string $paidAt, string $amount): void
    {
        $order = $this->makeOrder($store, ['order_date' => $paidAt]);

        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'paid',
            'paid_amount' => $amount,
            'paid_at' => $paidAt,
            'currency_code' => 'USD',
            'updated_at' => now(),
        ]);
    }

    /** Both payments, so coverage is complete and the unwindowed figure is their sum. */
    private function twoPayments($store): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        $this->payment($store, $now->subDays(2)->format('Y-m-d H:i:s'), self::RECENT);
        $this->payment($store, $now->subDays(40)->format('Y-m-d H:i:s'), self::OLD);

        return $now;
    }

    private function plain(?string $text): string
    {
        return str_replace(["\u{2066}", "\u{2069}"], '', (string) $text);
    }

    private function open($merchant, array $query = [], string $uri = self::URI)
    {
        return $this->actingAs($merchant->fresh())
            ->get($uri . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The figures themselves
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_chosen_period_narrows_the_money_tiles(): void
    {
        [, $store] = $this->merchant();
        $now = $this->twoPayments($store);

        $chosen = AnalyticsPeriod::fromRequest([AnalyticsPeriod::KEY_PERIOD => 'last_7_days'], $now);

        $narrowed = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now, $chosen, true);

        $this->assertSame(self::RECENT_RENDERED . ' USD', $this->plain($narrowed['payments']['value']),
            'The money tile still ignores the chosen period: it is showing a figure other than the '
            . 'one payment inside the last seven days.');
    }

    /**
     * THE PRINCIPLE-1 PIN, compared against the assembler rather than a literal — a literal would
     * pass while the two drifted, which is the correction the sibling file already made.
     */
    public function test_with_no_period_asked_for_the_money_tiles_are_unchanged(): void
    {
        [, $store] = $this->merchant();
        $now = $this->twoPayments($store);

        $expected = FinanceLedgerFigures::forStore((int) $store->id, 'USD');

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now, null, false);

        foreach (['payments', 'profit'] as $tile) {
            $this->assertSame($expected[$tile], $figures[$tile],
                "Adding the period control changed the unfiltered {$tile} figure, so every existing "
                . 'reader now sees a different number without having asked for one.');
        }
    }

    /**
     * A STORED SCHEDULE PERIOD IS NOT A CHOICE, because the column cannot say whether it was one.
     * `analytics_report_schedules.period_key` is `string(32)` NOT NULL, so every row carries a
     * concrete preset whether or not the merchant picked it. Narrowing the money in a recurring
     * e-mail set up months ago would change a figure nobody asked to change.
     */
    public function test_a_stored_schedule_period_does_not_narrow_the_money(): void
    {
        [, $store] = $this->merchant();
        $now = $this->twoPayments($store);

        $stored = AnalyticsPeriod::fromRequest([AnalyticsPeriod::KEY_PERIOD => 'last_7_days'], $now);

        $asTheRunnerCallsIt = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now, $stored, false);
        $unfiltered = FinanceLedgerFigures::forStore((int) $store->id, 'USD');

        $this->assertSame($unfiltered['payments'], $asTheRunnerCallsIt['payments'],
            'A scheduled report narrowed its money tile on a stored period, which the row cannot '
            . 'show the merchant ever chose.');

        // And the COUNT tiles do still follow it, or the stored period would mean nothing at all.
        $this->assertStringContainsString('7', $this->plain($asTheRunnerCallsIt['orders']['period']),
            'The stored period stopped governing the count tiles, which is not what changed here.');
    }

    /**
     * AND THE REAL RUNNER IS DRIVEN, because the case above does not prove what it looks like.
     *
     * It passes `false` ITSELF, so it pins the MEANING of the flag and not the runner's use of it:
     * a mutation flipping the runner's argument to `true` SURVIVED it. Only the source pin caught
     * that, and a source pin is a statement about text. This sends the real e-mail through
     * `ScheduledReportRunner::runDue()` and reads the attachment, so the behaviour a merchant
     * actually receives is what is asserted.
     *
     * `Mail::fake()` rather than the array transport, for the reason the sibling file gives: the
     * fake records the MAILABLE, whose `$csv` is the exact string `FigureCsv::render()` produced,
     * so this is a statement about the file rather than about MIME encoding.
     */
    public function test_the_scheduled_email_carries_the_coverage_wide_money(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        [$merchant, $store] = $this->merchant();
        $now = $this->twoPayments($store);

        DB::table('settings')->insert([
            'name' => 'defult_currancy', 'value' => 'USD', 'created_by' => $merchant->id,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\Setting)->flushCache();

        $row = new \App\Models\AnalyticsReportSchedule();
        $row->fill([
            'name' => 'Weekly numbers',
            'period_key' => 'last_7_days',
            'frequency' => \App\Models\AnalyticsReportSchedule::FREQ_WEEKLY,
            'is_active' => true,
        ]);
        $row->store_id = $store->id;
        $row->created_by = $merchant->id;
        $row->recipient_email = $merchant->email;
        $row->next_due_at = null;
        $row->save();

        app(\App\Services\Reports\ScheduledReportRunner::class)->runDue($now);

        $sent = \Illuminate\Support\Facades\Mail::sent(\App\Mail\ScheduledAnalyticsReport::class);

        $this->assertCount(1, $sent, 'PREMISE: the due schedule sent no e-mail, so this case proves nothing.');

        $csv = $this->plain((string) $sent->first()->csv);

        $this->assertStringContainsString(self::BOTH_RENDERED, $csv,
            'A recurring e-mail somebody set up months ago now reports a NARROWED money figure. '
            . '`analytics_report_schedules.period_key` is NOT NULL, so the stored row cannot show '
            . 'the merchant ever chose that period for their money.');
        $this->assertStringNotContainsString(self::RECENT_RENDERED . ' USD', $csv,
            'The scheduled money figure was narrowed to the stored period.');
    }

    /**
     * THE RUNNER PASSES THE FLAG EXPLICITLY, so flipping the parameter's DEFAULT cannot silently
     * start narrowing every merchant's recurring e-mail. Read with comments stripped, because a
     * census that reads prose can be satisfied by prose about the thing it measures.
     */
    public function test_the_scheduled_runner_passes_the_flag_explicitly(): void
    {
        $source = $this->sourceWithoutComments(base_path('app/Services/Reports/ScheduledReportRunner.php'));

        $this->assertStringContainsString(
            'MerchantAnalyticsFigures::forStore($storeId, $currency, $now, $period, false)',
            $source,
            'The scheduled runner no longer states its own answer to "does the money follow the '
            . 'stored period". It must, so that a change to the parameter default cannot narrow a '
            . 'figure in an e-mail somebody set up months ago.'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The screen, the export and the saved view
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_screen_narrows_its_money_when_a_period_is_chosen(): void
    {
        [$merchant, $store] = $this->merchant();
        $this->twoPayments($store);

        $body = $this->plain((string) $this->open($merchant, [AnalyticsPeriod::KEY_PERIOD => 'last_7_days'])
            ->assertStatus(200)->getContent());

        $this->assertStringContainsString(self::RECENT_RENDERED, $body,
            'The seven-day money figure is not on the page.');
        $this->assertStringNotContainsString(self::BOTH_RENDERED, $body,
            'The page still shows the coverage-wide money total while the control says seven days.');
    }

    public function test_the_unfiltered_screen_still_shows_the_coverage_wide_money(): void
    {
        [$merchant, $store] = $this->merchant();
        $this->twoPayments($store);

        $body = $this->plain((string) $this->open($merchant)->assertStatus(200)->getContent());

        $this->assertStringContainsString(self::BOTH_RENDERED, $body,
            'A merchant who filtered nothing now sees a narrowed money figure they did not ask for.');
    }

    /** The control ships an empty option; choosing it is not a period, as on the finance report. */
    public function test_choosing_the_controls_all_time_option_is_not_a_period(): void
    {
        [$merchant, $store] = $this->merchant();
        $this->twoPayments($store);

        $body = $this->plain((string) $this->open($merchant, [AnalyticsPeriod::KEY_PERIOD => ''])
            ->assertStatus(200)->getContent());

        $this->assertStringContainsString(self::BOTH_RENDERED, $body,
            'An empty period was treated as a choice and narrowed the money to the 30-day default.');
    }

    /** A saved view IS a choice: the merchant saved it and selected it. */
    public function test_a_saved_period_counts_as_asked_for(): void
    {
        [$merchant, $store] = $this->merchant();
        $this->twoPayments($store);

        $this->actingAs($merchant->fresh())->post('/saved-views', [
            'screen' => 'analytics', 'name' => 'Last week', 'filters' => [AnalyticsPeriod::KEY_PERIOD => 'last_7_days'],
        ])->assertRedirect();

        $saved = SavedView::withoutGlobalScopes()->where('screen', 'analytics')->firstOrFail();

        $body = $this->plain((string) $this->open($merchant, ['ss_view' => $saved->id])
            ->assertStatus(200)->getContent());

        $this->assertStringContainsString(self::RECENT_RENDERED, $body,
            'A saved seven-day period did not reach the money tiles.');
        $this->assertStringNotContainsString(self::BOTH_RENDERED, $body,
            'A saved period governs the count tiles but not the money tiles.');
    }

    public function test_the_export_follows_the_same_rule_as_the_screen(): void
    {
        [$merchant, $store] = $this->merchant();
        $this->twoPayments($store);

        $filtered = $this->plain((string) $this->open($merchant, [AnalyticsPeriod::KEY_PERIOD => 'last_7_days'], '/analytics/export')
            ->assertStatus(200)->getContent());

        $this->assertStringContainsString(self::RECENT_RENDERED, $filtered,
            'The download of a filtered screen does not carry the filtered money figure.');
        $this->assertStringNotContainsString(self::BOTH_RENDERED, $filtered,
            'The download shows coverage-wide money for a screen the merchant had filtered.');

        $unfiltered = $this->plain((string) $this->open($merchant, [], '/analytics/export')
            ->assertStatus(200)->getContent());

        $this->assertStringContainsString(self::BOTH_RENDERED, $unfiltered,
            'The download of an unfiltered screen narrowed its money anyway.');
    }
}
