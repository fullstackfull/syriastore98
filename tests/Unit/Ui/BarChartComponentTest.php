<?php

namespace Tests\Unit\Ui;

use Illuminate\View\ComponentAttributeBag;
use Tests\TestCase;

/**
 * `<x-ss.bar-chart>` after it grew a second series — and the single-series path it must not
 * have disturbed.
 * =============================================================================================
 *
 * The component was built for the dashboard's weekly orders chart (P2-02-8), which has eleven
 * browser checks against it. P2-05-6 needed it to draw a COMPARISON — customers against guests —
 * and to head its table with something other than "Day", because the year view's buckets are
 * months. Both are additive: a caller that passes neither `values2` nor `columnLabel` must get
 * exactly what it got before, or the dashboard regresses and only a browser run notices.
 *
 * So half of this file is a no-change assertion. That is the point of it.
 */
class BarChartComponentTest extends TestCase
{
    private function render(array $props): string
    {
        return view('components.ss.bar-chart', $props + [
            'attributes' => new ComponentAttributeBag([]),
        ])->render();
    }

    public function test_the_single_series_path_is_untouched(): void
    {
        $html = $this->render([
            'labels' => ['Mon', 'Tue'],
            'values' => [3, 0],
            'title' => 'Orders per day',
            'valueLabel' => 'Orders',
            'period' => 'last 7 days',
        ]);

        $this->assertStringNotContainsString('ss-chart__bar--b', $html,
            'a single-series chart grew a second bar');
        $this->assertStringNotContainsString('ss-chart__legend', $html,
            'a single-series chart grew a legend it has nothing to explain');
        $this->assertStringContainsString('<th scope="col">Day</th>', $html,
            'the default column header changed, which would silently relabel the dashboard');

        /* Two rows and one total. Not "2": the <tfoot> total is a cell too, and the first draft
           of this assertion said 2 and failed against a correct component. */
        $this->assertSame(3, substr_count($html, '<td class="ss-table__num ss-num">'),
            'the table grew or lost a column');

        $this->assertStringContainsString('ss-chart__bar--zero', $html,
            'a zero value must still draw a marked baseline rather than nothing');
    }

    public function test_a_second_series_adds_a_bar_a_column_and_a_legend(): void
    {
        $html = $this->render([
            'labels' => ['Jan', 'Feb'],
            'values' => [3, 0],
            'values2' => [1, 4],
            'title' => 'Orders',
            'valueLabel' => 'Customers',
            'valueLabel2' => 'Guests',
            'columnLabel' => 'Month',
            'period' => 'this year',
        ]);

        $this->assertStringContainsString('ss-chart__bar--b', $html);
        $this->assertStringContainsString('ss-chart__legend', $html);
        $this->assertStringContainsString('<th scope="col">Month</th>', $html);
        $this->assertStringContainsString('>Guests</th>', $html);

        /* 2 rows x 2 series, plus 2 totals. */
        $this->assertSame(6, substr_count($html, '<td class="ss-table__num ss-num">'));
        $this->assertStringContainsString('>4</td>', $html, "the second series' values are in the table");
    }

    /**
     * ONE SCALE FOR BOTH SERIES. The bars are STACKED, so the tallest column is the largest SUM.
     * Scaling each series against its own maximum would draw a 3 and a 4 at the same height and
     * make the picture lie about the comparison it exists to show.
     *
     * Jan is 3 + 1 = 4, Feb is 0 + 4 = 4, so the stack maximum is 4 and Jan's first bar is 75%.
     */
    public function test_both_series_share_one_scale(): void
    {
        $html = $this->render([
            'labels' => ['Jan', 'Feb'],
            'values' => [3, 0],
            'values2' => [1, 4],
            'title' => 'Orders', 'valueLabel' => 'A', 'valueLabel2' => 'B',
            'period' => 'this year',
        ]);

        $this->assertStringContainsString('block-size: 75%', $html,
            'the first series is not scaled against the stacked maximum');
        $this->assertStringContainsString('block-size: 100%', $html,
            'the tallest stack must reach the top');
    }

    /**
     * MISMATCHED LENGTHS NARROW, they do not pad. A chart that silently invents a zero for a
     * month the second series does not have is worse than one that renders fewer columns.
     */
    public function test_a_short_second_series_narrows_the_chart(): void
    {
        $html = $this->render([
            'labels' => ['Jan', 'Feb', 'Mar'],
            'values' => [1, 2, 3],
            'values2' => [4],
            'title' => 'Orders', 'valueLabel' => 'A', 'valueLabel2' => 'B',
            'period' => 'this year',
        ]);

        /* One drawn column, and one data row. `<th scope="row">` would count TWO — the
           <tfoot> total is a row header as well — which is the same tfoot the row-count
           assertion above already tripped over once. */
        $this->assertSame(1, substr_count($html, 'ss-chart__col'),
            'only the columns ALL series have may be drawn');
        $this->assertStringNotContainsString('>Mar<', $html);
        $this->assertStringNotContainsString('>Feb<', $html);
    }

    /** An empty series is an empty state, not a chart of nothing. */
    public function test_no_data_renders_the_empty_state(): void
    {
        $html = $this->render([
            'labels' => [], 'values' => [], 'title' => 'Orders', 'period' => 'this year',
        ]);

        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('No data for this period yet', $html);
    }
}
