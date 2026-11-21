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

    public function test_the_window_matches_the_bounds_each_preset_actually_queries(): void
    {
        Carbon::setTestNow('2026-11-21 13:00:00');

        try {
            $this->assertSame(
                ['2026-10-01', '2026-10-31'],
                $this->bounds($this->window(Request::create('/', 'GET', ['chart_data' => 'last-month']))),
                "last-month queries date('Y-m-01') .. date('Y-m-t') of the previous month"
            );

            $this->assertSame(
                ['2026-11-01', '2026-11-30'],
                $this->bounds($this->window(Request::create('/', 'GET', ['chart_data' => 'this-month']))),
            );

            $this->assertSame(
                ['2026-11-15', '2026-11-21'],
                $this->bounds($this->window(Request::create('/', 'GET', ['chart_data' => 'seven-day']))),
                'seven-day is the seven days ENDING today, which is -1 week +1 day and six more'
            );

            $this->assertSame(
                ['2026-01-01', '2026-12-31'],
                $this->bounds($this->window(Request::create('/', 'GET', ['chart_data' => 'year']))),
                "year is 1 January to 31 December: date('Y-12') parses as 1 December, so Y-m-t is the 31st"
            );
        } finally {
            Carbon::setTestNow();
        }
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
