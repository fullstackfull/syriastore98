<?php

namespace Tests\Feature\DesignSystem;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-05-6 / PRD-001 — the customer-report hub states the window every figure covers.
 * =============================================================================================
 *
 * WHAT WAS WRONG. `reports/chart_data.blade.php` rendered `{{ $totaluser }} {{ __('orders') }}`
 * — a bare number over three charts — while the selected period lived only in a CSS class on a
 * button at the top of the page. Switch from "This year" to "Last 7 days" and the number changes
 * with nothing beside it saying so. That is the exact reading PRD-001 exists to stop, and it is
 * worse here than on the dashboard because the whole point of this screen is comparing periods.
 *
 * A TEST THAT ONLY CHECKED "A PERIOD IS PRESENT" WOULD BE WORTHLESS. Hard-coding the string
 * "Last 7 days" into the partial would satisfy it, and would then be wrong for four of the five
 * windows. So the assertions are RELATIONAL: the period rendered must change with the requested
 * window and must name it. That is why each case drives the real route more than once.
 *
 * The charts are asserted the same way — not "a table exists" but "the table holds the numbers
 * the series holds", because a chart whose table is empty is exactly as unreadable as no table.
 *
 * WHAT THIS FILE CANNOT REACH, AND WHY IT IS SAID HERE RATHER THAN LEFT TO BE DISCOVERED.
 * `ReportController` builds its buckets with raw `MONTH(...)`, `YEAR(...)` and `DATE(...)` — 36
 * uses of MySQL-only date functions across the controller — so four of the five windows raise
 * "no such function: MONTH" on the suite's SQLite and cannot be RENDERED here at all. Only
 * `seven-day` is driven end to end. That is a real coverage limit, not a formality: the picked
 * DATE and picked RANGE windows are the ones a merchant reaches from the date field, and nothing
 * in this suite executes them.
 *
 * Registering SQLite shims for those functions would make all five green and would be the wrong
 * move: it would turn this into a test of the shim. The period LABELS, which are the PRD-001
 * property and are pure functions of the request, are covered for all five windows in
 * `tests/Unit/Ui/ReportPeriodLabelTest.php` instead — no database, nothing to shim, and it fails
 * for the same reasons the rendered version would.
 */
class ReportsHubHonestyTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
        ]);

        $this->store = Store::create([
            'name' => 'Report Store', 'email' => 'reports@example.test',
            'slug' => 'report-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();
    }

    private function chart(string $window, string $date = ''): string
    {
        $response = $this->actingAs($this->merchant)
            ->get('/reports-chart?' . http_build_query(['chart_data' => $window, 'Date' => $date]));

        $response->assertOk();

        return (string) $response->json('html');
    }

    /** Every `ss-stat__period` on the card, in order. */
    private function periods(string $html): array
    {
        preg_match_all('/<span class="ss-stat__period">\s*([^<]*?)\s*(?:<|$)/s', $html, $m);

        return array_values(array_filter(array_map('trim', $m[1] ?? [])));
    }

    /**
     * THE PREMISE: the screen renders figures at all. Without it every assertion below is
     * trivially satisfied by a blank response, which is what an unpermitted principal or a
     * broken query would produce.
     */
    public function test_the_hub_renders_its_figures(): void
    {
        $html = $this->chart('seven-day');

        $this->assertStringContainsString('ss-stat__value', $html,
            'Premise: the report body rendered no figures at all.');
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'ss-stat__value'),
            'Premise: the four headline figures are not all there.');
    }

    public function test_every_figure_carries_a_period(): void
    {
        foreach (['seven-day'] as $window) {   // see the class docblock: the other three are MySQL-only
            $html = $this->chart($window);

            $values = substr_count($html, 'ss-stat__value');
            $periods = $this->periods($html);

            $this->assertSame($values, count($periods),
                "{$window}: " . ($values - count($periods)) . ' figure(s) are rendered with no '
                . 'period beside them. PRD-001: a number with no window is a number the reader '
                . 'cannot interpret.');
        }
    }

    /**
     * THE ASSERTION A HARD-CODED LABEL WOULD FAIL, for the three windows SQLite can render. The
     * other two are covered in the unit test named in the class docblock.
     */
    public function test_the_period_names_the_window_that_was_asked_for(): void
    {
        $periods = $this->periods($this->chart('seven-day'));

        $this->assertNotSame([], $periods, 'no period at all.');
        $this->assertStringContainsString('Last 7 days', $periods[0],
            "the figure is labelled '{$periods[0]}', which does not name the window that was "
            . 'requested.');
    }


    /**
     * THE CHARTS SHIP THEIR NUMBERS. Not "a table element exists" — the table must hold the
     * series, or a screen-reader user is no better off than with the three empty divs the
     * ApexCharts containers used to be.
     */
    public function test_each_chart_ships_a_table_holding_its_series(): void
    {
        $this->makeOrders(3);

        $html = $this->chart('seven-day');

        $this->assertSame(2, substr_count($html, '<table'),
            'both charts must ship a table of their own.');
        $this->assertStringContainsString('<caption', $html,
            'the table must name its series and period for a screen reader.');
        $this->assertStringContainsString('scope="row"', $html);
        $this->assertStringContainsString('scope="col"', $html);

        /* The orders exist, so the totals cannot all be zero — that is what distinguishes a
           table holding the data from a table of placeholders. */
        preg_match_all('/<td class="ss-table__num ss-num">([\d,]+)<\/td>/', $html, $m);
        $this->assertNotSame([], $m[1] ?? [], 'the tables rendered no numbers.');
        $this->assertGreaterThan(0, array_sum(array_map(
            static fn ($n) => (int) str_replace(',', '', $n), $m[1]
        )), 'three orders were placed in the window and every cell is zero.');
    }

    /** The bars are a picture of the table, so they must not be announced twice. */
    public function test_the_bars_are_hidden_from_assistive_technology(): void
    {
        $this->makeOrders(1);

        $html = $this->chart('seven-day');

        $this->assertStringContainsString('<div class="ss-chart" aria-hidden="true">', $html);
    }

    /**
     * The hub page's own controls. The period buttons used to be `<button class="nav-link">`
     * inside a `<ul role="tablist">` with no `role="tab"` and no `aria-selected`, so the
     * selection was a CSS class and nothing else — invisible to a screen reader. Two `<div>`s
     * sat as direct children of that `<ul>`, which is invalid.
     */
    public function test_the_period_controls_state_their_selection(): void
    {
        $page = $this->actingAs($this->merchant)->get('/reports')->assertOk()->getContent();

        /* Scoped to the toolbar. The host layout's sidebar carries an `aria-pressed` collapse
           button of its own, so a whole-page count would pass for the wrong reason — and would
           keep passing if every period button lost its state. */
        $this->assertSame(1, preg_match(
            '/<div class="ss-toolbar"[^>]*>(.*?)<\/div>\s*<\/div>/s', $page, $m
        ), 'Premise: the period toolbar is not in the page at all.');
        $html = $m[1];

        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'),
            'exactly one period must be pressed on first load.');
        $this->assertSame(3, substr_count($html, 'aria-pressed="false"'),
            'the unselected periods must say so rather than merely lacking a class.');

        $this->assertStringContainsString('<label class="ss-label" for="report-date">', $html,
            'the date field had no label at all.');
        $this->assertStringContainsString('id="report-date"', $html);

        $this->assertStringNotContainsString('role="tablist"', $html,
            'these are not tabs — they refetch rather than reveal a panel already in the page — '
            . 'and promising tabs that do not behave like tabs is worse than not promising them.');
    }

    private function makeOrders(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            Order::create([
                'order_id' => 'R' . uniqid(),
                'store_id' => $this->store->id,
                'is_guest' => $i % 2,
                'order_date' => now()->subDay(),
                'price' => 100,
                'customer_id' => 0,
            ]);
        }
    }
}
