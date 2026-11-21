<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\ReportController;
use App\Support\Metrics\MetricWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * THE SEVENTH TILE REACHES BOTH ORDER-REPORT BODIES, AND COVERS THE PERIOD THEY DO. ANA-005.
 * =================================================================================================
 *
 * `reports/_sales_stats.blade.php` is ONE copy of six figures rendered by TWO handlers, and the
 * labelling proposal's own warning about adding a seventh was that "two converted together or the
 * partial takes resolved figures" -- i.e. a handler that forgets to pass it drops the tile in
 * silence, on a screen nobody has a test for. This file is the three things that can go wrong:
 *
 *   1. ONE HANDLER PASSES IT AND THE OTHER DOES NOT. A source census over both `view(...)` calls,
 *      because neither handler is reachable from the suite at all -- `order_reports_chart` holds
 *      five `MONTH(` and one `YEAR(` and `order_reports_chart_data` twelve and four, none of which
 *      SQLite has, so a Feature request to either answers HTTP 500. The census is read off the
 *      source WITH COMMENTS STRIPPED: a docblock that merely MENTIONS `'collection'` would satisfy
 *      a naive grep, which has happened four times in this repository in one day.
 *   2. THE PARTIAL RENDERS THE TILE WHEN THERE IS NOTHING TO RENDER, or drops the other six when
 *      there is. Both directions are rendered here.
 *   3. THE WINDOW DOES NOT MATCH THE ONE THE HANDLER QUERIES. `chartPeriodWindow` is private and is
 *      exercised through reflection, against bounds read off the handler's own `whereDate` calls --
 *      not off `chartPeriodLabels`'s prose, which is a different claim and has already been measured
 *      to disagree with the SQL for an unparseable date.
 *
 * WHAT THIS FILE DOES NOT PROVE: that either handler returns HTTP 200 with the tile on it. That
 * needs MySQL, and the evidence is `tests/Support/Security/bin/verify-ana005-sales-collection-on-mysql.php`.
 */
class SalesStatsPassesItsCollectionFigureTest extends TestCase
{
    use StripsSourceComments;

    private const CONTROLLER = 'app/Http/Controllers/ReportController.php';

    private const PARTIAL = 'resources/views/reports/_sales_stats.blade.php';

    /** The six figures the partial has always rendered, as the handlers spell them. */
    private function sixFigures(): array
    {
        return [
            'currency' => '$',
            'TotalgrossSale' => '1000.00',
            'TotalNetSale' => '900.00',
            'TotalOrder' => 4,
            'PurchasedProductItemTotal' => 9,
            'TotalShippingCharge' => '50.00',
            'TotalCouponAmount' => '25.00',
            'periodLabel' => 'This month (November 2026)',
        ];
    }

    private function window(Request $request): ?MetricWindow
    {
        $method = new \ReflectionMethod(ReportController::class, 'chartPeriodWindow');
        $method->setAccessible(true);

        return $method->invoke(new ReportController(), $request);
    }

    private function bounds(?MetricWindow $window): ?array
    {
        return $window === null ? null : [
            $window->from()?->toDateString(),
            $window->to()?->toDateString(),
        ];
    }

    public function test_both_order_report_bodies_are_handed_a_collection_figure(): void
    {
        $source = $this->sourceWithoutComments(self::CONTROLLER);

        foreach (['reports.order_chart_data', 'reports.order_bar_chart_data'] as $view) {
            $at = strpos($source, "view('" . $view . "'");

            $this->assertNotFalse($at, "no handler renders {$view}");

            // The view data of that one call: from the `view(` to the `])->render()` that closes it.
            $end = strpos($source, "])->render()", $at);
            $this->assertNotFalse($end, "could not find the end of the {$view} call");

            $call = substr($source, $at, $end - $at);

            $this->assertStringContainsString(
                "'collection' =>",
                $call,
                "{$view} is rendered without a collection figure, so its Payments received tile is silently absent"
            );
        }
    }

    public function test_the_partial_renders_the_collection_tile_it_is_given(): void
    {
        $html = view('reports._sales_stats', $this->sixFigures() + [
            'collection' => [
                'value' => '123.45 USD',
                'period' => 'from 2 November 2026',
                'hint' => 'Money customers actually paid.',
            ],
        ])->render();

        $this->assertStringContainsString('Payments received', $html);
        $this->assertStringContainsString('123.45 USD', $html);
        $this->assertStringContainsString('from 2 November 2026', $html, "the tile must carry its OWN period, not the card's");
        $this->assertStringContainsString('Money customers actually paid.', $html);

        // And the six it has always rendered are still there.
        foreach (['Order value', 'Orders', 'Items ordered', 'Shipping charged', 'Coupon value applied'] as $label) {
            $this->assertStringContainsString($label, $html, "adding the seventh tile dropped {$label}");
        }
    }

    public function test_the_partial_renders_six_tiles_when_no_collection_figure_was_resolved(): void
    {
        $html = view('reports._sales_stats', $this->sixFigures() + ['collection' => null])->render();

        $this->assertStringNotContainsString('Payments received', $html, 'a tile with nothing behind it must not be rendered');
        $this->assertStringContainsString('Shipping charged', $html);
    }

    public function test_the_partial_does_not_fail_when_the_variable_is_absent_altogether(): void
    {
        // A third includer, or a stale compiled view from before this slice, must not 500 the report.
        $html = view('reports._sales_stats', $this->sixFigures())->render();

        $this->assertStringNotContainsString('Payments received', $html);
        $this->assertStringContainsString('Shipping charged', $html);
    }

    /**
     * THE BOUNDS, DERIVED INDEPENDENTLY OF THE HANDLER AND OF ANY MOCK CLOCK.
     *
     * THE FIRST VERSION OF THIS TEST WAS A TIME BOMB AND LOOKED PINNED, which is worse than looking
     * wrong. It called `Carbon::setTestNow('2026-11-21 13:00:00')` and then asserted literal dates --
     * but `chartPeriodWindow()` reads the NATIVE clock (`date()` / `strtotime()`, deliberately, so it
     * mirrors the handler's own expressions), and `Carbon::setTestNow()` governs `Carbon::now()` ONLY.
     * Measured: after `setTestNow('2030-06-15')`, `Carbon::now()` moves and `date('Y-m-d')` does not.
     * So all four assertions were real-clock dependent and the seven-day one was true on exactly ONE
     * day. It was green when written because that day was the day it was written; the next day it
     * would have been an undeclared Feature failure, which fails the known-outcome gate in both
     * directions. Found by the authorization review of this slice, not by running it.
     *
     * The repair is NOT to make `chartPeriodWindow()` use Carbon -- that would stop it mirroring the
     * handler on exactly the edge cases the mirror exists for. It is to spell each expected bound a
     * DIFFERENT way from the handler, so the assertion still discriminates:
     *
     *   handler                                  this test
     *   strtotime('-1 week +1 day') + 6 days     strtotime('-6 days') .. today
     *   date('Y-m-01') / date('Y-m-t')           Carbon::now()->startOfMonth() / ->endOfMonth()
     *   date('Y-m-01'|'Y-m-t', strtotime(Y-01|Y-12))   Carbon::now()->startOfYear() / ->endOfYear()
     *
     * `last-month` IS DELIBERATELY ASSERTED STRUCTURALLY, because the handler has a pre-existing
     * overflow bug this mirror faithfully reproduces: `strtotime('-1 month')` on the 29th-31st of a
     * month following a shorter one lands in the CURRENT month (31 March - 1 month = 3 March), while
     * `chartPeriodLabels()` uses the correct `'first day of last month'` for the LABEL. On those days
     * the card reads "Last month (February)" over March figures, for all seven tiles. That is
     * `GAP-223` and is not this slice's to change. Asserting the calendar month unconditionally would
     * make this test red for a real reason on three days a month, so the overflow day is DETECTED and
     * the expectation follows it -- and the detection is itself the regression pin for the bug.
     */
    public function test_the_window_matches_the_bounds_each_preset_actually_queries(): void
    {
        $now = Carbon::now();

        $this->assertSame(
            [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()],
            $this->bounds($this->window(Request::create('/', 'GET', ['chart_data' => 'this-month']))),
            'this-month is the whole current calendar month',
        );

        $this->assertSame(
            [Carbon::now()->subDays(6)->toDateString(), Carbon::now()->toDateString()],
            $this->bounds($this->window(Request::create('/', 'GET', ['chart_data' => 'seven-day']))),
            'seven-day is the seven days ENDING today',
        );

        $this->assertSame(
            [$now->copy()->startOfYear()->toDateString(), $now->copy()->endOfYear()->toDateString()],
            $this->bounds($this->window(Request::create('/', 'GET', ['chart_data' => 'year']))),
            'year is 1 January to 31 December of the current year',
        );

        // ---- last-month, and the overflow the handler's own expression has ----

        [$from, $to] = $this->bounds($this->window(Request::create('/', 'GET', ['chart_data' => 'last-month'])));

        $this->assertSame('01', substr((string) $from, 8, 2), 'the window must start on the first of a month');
        $this->assertSame(
            date('Y-m-t', strtotime((string) $from)),
            $to,
            'the window must end on the last day of the SAME month it starts in',
        );

        /* True exactly when `strtotime('-1 month')` overflows: today's day-of-month does not exist in
           the previous month. On those days the handler lands in the current month and this test says
           so, rather than failing for something it is not measuring. */
        $overflows = (int) date('j') > (int) date('t', strtotime('first day of last month'));

        $this->assertSame(
            $overflows
                ? $now->copy()->format('Y-m')
                : date('Y-m', strtotime('first day of last month')),
            substr((string) $from, 0, 7),
            $overflows
                ? 'GAP-223: on this day of the month the handler\'s -1 month overflows into the current month'
                : 'last-month is the previous calendar month',
        );

        $this->assertFalse(
            $overflows && $from === $now->copy()->startOfMonth()->toDateString()
                && $to !== $now->copy()->endOfMonth()->toDateString(),
            'premise: an overflow still yields a whole calendar month',
        );
    }

    public function test_the_date_picker_window_is_the_single_day_or_the_range_it_sends(): void
    {
        $this->assertSame(
            ['2026-03-12', '2026-03-12'],
            $this->bounds($this->window(Request::create('/', 'GET', ['Date' => '2026-03-12']))),
        );

        $this->assertSame(
            ['2026-03-01', '2026-03-31'],
            $this->bounds($this->window(Request::create('/', 'GET', ['Date' => '2026-03-01 to 2026-03-31']))),
        );
    }

    public function test_a_range_the_picker_sends_backwards_yields_no_window_rather_than_an_exception(): void
    {
        // MetricWindow throws when `from` is after `to`. Swapping the bounds would invent a window the
        // six tiles beside it are not answering, so the tile is omitted instead.
        $this->assertNull(
            $this->window(Request::create('/', 'GET', ['Date' => '2026-03-31 to 2026-03-01'])),
        );
    }

    public function test_an_unparseable_date_mirrors_the_handlers_own_degenerate_window(): void
    {
        /* `strtotime('')` is false and `date('Y-m-d', false)` is 1970-01-01, so the handler queries a
           single day in 1970 while its label says "Today". Mirrored on purpose: the ledger figure then
           refuses that window with a stated reason, where the six demand tiles print silent zeros. */
        $this->assertSame(
            ['1970-01-01', '1970-01-01'],
            $this->bounds($this->window(Request::create('/', 'GET', ['Date' => '']))),
        );
    }

    public function test_the_partial_guards_the_tile_rather_than_assuming_the_variable(): void
    {
        $blade = $this->sourceWithoutComments(self::PARTIAL);

        $this->assertStringContainsString('@if (!empty($collection))', $blade);
        $this->assertSame(
            1,
            substr_count($blade, "__('Payments received')"),
            'the label must appear exactly once in the one shared copy'
        );
    }
}
