<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\ReportController;
use App\Support\Reports\CalendarPeriod;
use Illuminate\Http\Request;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-223(a) -- THE LAST-MONTH WINDOW, PINNED AT EVERY LEVEL THE SUITE CAN REACH.
 * =================================================================================================
 *
 * `strtotime('-1 month')` and `Carbon::now()->subMonth()` both OVERFLOW: PHP keeps the day-of-month,
 * so on 31 March they yield 3 March and `date('Y-m-01', ...)` of that is 1 MARCH. Nine expressions
 * in `ReportController` derived "last month" that way, while `chartPeriodLabels()` and three
 * `subMonthNoOverflow()` calls were already correct. On 33 days in every five years they disagreed,
 * with THREE measured consequences:
 *
 *   1. the card named a month its figures did not cover ("Last month (February 2026)" over March);
 *   2. the chart SILENTLY DROPPED ORDERS. The window returned 31 days of March while the loop bound
 *      -- already correct -- ran 1..28, so rows on 29, 30 and 31 were never read into the series;
 *   3. `order_reports_chart_data` paired the overflowing `$lastMonth` with the correct
 *      `$lastDayofMonth` in a `whereBetween`, giving `['2026-03-03', '2026-02-28']` -- a REVERSED
 *      range matching no row, so that figure read ZERO.
 *
 * WHAT THIS FILE CAN AND CANNOT TEST, stated because the difference is the whole reason the evidence
 * is split three ways:
 *
 *   * `CalendarPeriod` itself is covered by `tests/Unit/Reports/CalendarPeriodTest` on ALL 1,827 days
 *     of 2024-2028, with expectations spelled from year-and-month arithmetic.
 *   * The two seams that take an injectable clock -- `chartPeriodWindow()` and
 *     `getLastMonthDatesFormatted()` -- are driven HERE on a real overflow day. That parameter exists
 *     for exactly this: `Carbon::setTestNow()` does not move `date()`/`strtotime()`, and this
 *     container has no `faketime`, so without it no test could reach 31 March at all.
 *   * The four window derivations INSIDE the chart handlers cannot be executed: those methods use
 *     `MONTH(` and `YEAR(` in `selectRaw`, which SQLite does not implement, so a Feature test against
 *     them answers HTTP 500. They are pinned by the SOURCE census below and driven end-to-end on a
 *     real MariaDB by `tests/Support/Security/bin/verify-gap223-lastmonth-window-on-mysql.php`.
 *
 * THE CENSUS READS COMMENT-STRIPPED SOURCE, because this file's own explanation of the defect quotes
 * the very expressions it forbids -- and a census that reads comments can be broken, or satisfied, by
 * prose about the thing it measures. That has happened four times in this project in one day.
 */
class LastMonthWindowOverflowTest extends TestCase
{
    use StripsSourceComments;

    /** 31 March 2026: February has 28 days, so day 31 does not exist in it. */
    private const OVERFLOW_DAY = [2026, 3, 31];

    /** 15 March 2026: an ordinary day, where the old and new expressions agree. */
    private const ORDINARY_DAY = [2026, 3, 15];

    private function at(array $ymd): int
    {
        return mktime(12, 0, 0, $ymd[1], $ymd[2], $ymd[0]);
    }

    private function window(int $now): array
    {
        $controller = new ReportController;
        $method = new \ReflectionMethod($controller, 'chartPeriodWindow');
        $method->setAccessible(true);

        $w = $method->invoke($controller, Request::create('/', 'GET', ['chart_data' => 'last-month']), $now);

        $this->assertNotNull($w, 'premise: the last-month preset yields a window');

        return [$w->from()?->toDateString(), $w->to()?->toDateString()];
    }

    public function test_the_metric_window_names_the_previous_month_on_an_overflow_day(): void
    {
        $now = $this->at(self::OVERFLOW_DAY);

        // The premise, so a calendar change cannot make this test vacuous.
        $this->assertSame('2026-03-03', date('Y-m-d', strtotime('-1 month', $now)),
            'premise: 31 March 2026 really is an overflow day for the old expression');

        $this->assertSame(['2026-02-01', '2026-02-28'], $this->window($now),
            'the window must be February, which is what the card says');
    }

    public function test_the_window_agrees_with_the_label_on_the_overflow_day(): void
    {
        $now = $this->at(self::OVERFLOW_DAY);
        [$from] = $this->window($now);

        $this->assertSame('February 2026', CalendarPeriod::lastMonthLabel($now));
        $this->assertSame('February 2026', date('F Y', strtotime((string) $from)),
            'the figures and the heading must name the same month');
    }

    public function test_an_ordinary_day_is_unchanged_by_the_fix(): void
    {
        $now = $this->at(self::ORDINARY_DAY);
        $old = strtotime('-1 month', $now);

        $this->assertSame(
            [date('Y-m-01', $old), date('Y-m-t', $old)],
            $this->window($now),
            'on the 1,794 non-overflow days the corrected window must be byte-identical to the old one',
        );
    }

    public function test_the_x_axis_labels_name_the_previous_months_days_on_an_overflow_day(): void
    {
        $labels = (new ReportController)->getLastMonthDatesFormatted($this->at(self::OVERFLOW_DAY));

        $this->assertCount(28, $labels, 'February 2026 has 28 days, so the axis has 28 cells');
        $this->assertSame('01-Feb', $labels[0], 'the axis used to begin 03-Mar, which is the overflow itself');
        $this->assertSame('28-Feb', $labels[27]);
        /*
         * Distinctness, which is worth asserting on its own account -- but NOT, as an earlier version
         * of this comment claimed, because it catches the in-place `setDay()`. MEASURED: `setDay()`
         * sets an absolute day, so the copied and in-place forms produce identical labels in every
         * month tried, and the mutation removing `copy()` is EQUIVALENT and survives. Recorded rather
         * than dressed up: a test that claims to cover something it cannot is the failure this
         * project's mutation discipline exists to find.
         */
        $this->assertSame($labels, array_values(array_unique($labels)), 'every label is distinct');
    }

    public function test_the_axis_length_equals_the_window_it_belongs_to(): void
    {
        foreach ([self::OVERFLOW_DAY, self::ORDINARY_DAY] as $day) {
            $now = $this->at($day);
            $labels = (new ReportController)->getLastMonthDatesFormatted($now);

            /*
             * CONSEQUENCE 2, PINNED. The series is built by looping 1..$period['days'] and the axis
             * by this helper; if they came from different expressions the chart would have more cells
             * than labels or vice versa, and the surplus rows would be dropped in silence.
             */
            $this->assertCount(CalendarPeriod::lastMonth($now)['days'], $labels,
                'the axis and the loop bound must be the same number on '.date('Y-m-d', $now));
        }
    }

    public function test_no_overflowing_last_month_expression_survives_in_the_controller(): void
    {
        $source = $this->sourceWithoutComments(base_path('app/Http/Controllers/ReportController.php'));

        /*
         * THE PIN FOR THE FOUR SITES THE SUITE CANNOT EXECUTE. Both spellings overflow and both were
         * present: `strtotime('-1 month')` five times and `Carbon::now()->subMonth()` four.
         */
        foreach (["strtotime('-1 month')", 'strtotime("-1 month")', 'subMonth()'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source,
                "`{$forbidden}` overflows on 33 days in five years; derive the period from CalendarPeriod instead");
        }

        // And the positive half: the replacements are really there, not merely the old ones removed.
        $this->assertGreaterThanOrEqual(6, substr_count($source, 'CalendarPeriod::lastMonth('),
            'the six last-month derivations should all read the shared period');
        $this->assertStringContainsString('CalendarPeriod::lastMonthLabel(', $source,
            'the label must come from the same derivation as the bounds');
    }

    public function test_the_already_correct_no_overflow_calls_were_removed_rather_than_left_beside_the_fix(): void
    {
        $source = $this->sourceWithoutComments(base_path('app/Http/Controllers/ReportController.php'));

        /*
         * `subMonthNoOverflow()` was CORRECT and that was the problem: it disagreed with the
         * overflowing window beside it. Three of them are gone because the day count now arrives with
         * the bounds. A reappearance means a second expression is back, which is how they drifted.
         */
        $this->assertStringNotContainsString('subMonthNoOverflow()', $source,
            'a second day-count expression is back; the count must come from CalendarPeriod with the bounds');
    }
}
