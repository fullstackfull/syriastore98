<?php

namespace Tests\Unit\Ui;

use App\Http\Controllers\ReportController;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PRD-001 for the five report windows, where the feature suite cannot reach four of them.
 * =============================================================================================
 *
 * `ReportsHubHonestyTest` drives the real `/reports-chart` route, which is the right way to test
 * this — but `ReportController` builds its buckets with raw `MONTH(...)`, `YEAR(...)` and
 * `DATE(...)`, and SQLite has none of them, so four of the five windows raise "no such function"
 * before they render. Registering shims would turn that test into a test of the shims.
 *
 * The PRD-001 property does not need the database. The period a figure claims is a pure function
 * of the request that chose the window, so it is tested as one here: no store, no orders, no
 * connection. What this file CANNOT see is whether the label ends up beside the figure — that is
 * the feature test's job, for the one window it can run.
 *
 * TOGETHER they cover the two ways this goes wrong: a window with no label (feature), and a
 * label that does not match its window (here). Neither alone is enough, and the second is the
 * one a careless fix produces — a hard-coded "Last 7 days" satisfies "a period is present" for
 * all five windows and is wrong for four of them.
 */
class ReportPeriodLabelTest extends TestCase
{
    /** @return array{0:string,1:string} [period, column] */
    private function labels(array $query): array
    {
        $method = new \ReflectionMethod(ReportController::class, 'chartPeriodLabels');
        $method->setAccessible(true);

        return $method->invoke(
            (new \ReflectionClass(ReportController::class))->newInstanceWithoutConstructor(),
            Request::create('/reports-chart', 'GET', $query)
        );
    }

    public function test_each_window_names_itself(): void
    {
        $cases = [
            'year' => (string) date('Y'),
            'last-month' => date('F Y', strtotime('first day of last month')),
            'this-month' => date('F Y'),
            'seven-day' => 'Last 7 days',
        ];

        foreach ($cases as $window => $needle) {
            [$period] = $this->labels(['chart_data' => $window]);

            $this->assertStringContainsString($needle, $period,
                "the '{$window}' window is labelled '{$period}', which does not name it.");
        }
    }

    /**
     * THE ASSERTION A HARD-CODED LABEL FAILS. Five windows, five distinct labels — a constant
     * passes every "contains" check above for at most one of them, but this one catches a
     * constant that happens to be a substring of several.
     */
    public function test_the_five_windows_do_not_share_a_label(): void
    {
        $seen = [];

        foreach (['year', 'last-month', 'this-month', 'seven-day'] as $window) {
            $seen[$window] = $this->labels(['chart_data' => $window])[0];
        }
        $seen['picked'] = $this->labels(['chart_data' => '', 'Date' => '2026-03-12'])[0];

        $this->assertSame(count($seen), count(array_unique($seen)),
            'two windows render the SAME period, so at least one is a constant rather than a '
            . 'statement about the data: ' . json_encode($seen));
    }

    /** A picked date names that date — not "today", not the month it falls in. */
    public function test_a_picked_date_names_the_date(): void
    {
        [$period, $column] = $this->labels(['chart_data' => '', 'Date' => '2026-03-12']);

        $this->assertSame('12 Mar 2026', $period);
        $this->assertSame('Day', $column);
    }

    public function test_a_picked_range_names_both_ends(): void
    {
        [$period] = $this->labels(['chart_data' => '', 'Date' => '2026-03-01 to 2026-03-31']);

        $this->assertStringContainsString('01 Mar 2026', $period);
        $this->assertStringContainsString('31 Mar 2026', $period);
    }

    /** A range whose ends are the same day reads as one date, not "12 Mar – 12 Mar". */
    public function test_a_one_day_range_is_not_written_twice(): void
    {
        [$period] = $this->labels(['chart_data' => '', 'Date' => '2026-03-12 to 2026-03-12']);

        $this->assertSame('12 Mar 2026', $period);
    }

    /** No date at all is today, said as a date rather than as the word. */
    public function test_an_absent_date_names_today(): void
    {
        [$period] = $this->labels(['chart_data' => '']);

        $this->assertStringContainsString(date('d M Y'), $period);
    }

    /**
     * THE COLUMN HEADER IS PART OF THE SAME CLAIM. The year view's buckets are months; every
     * other view's are days. A table headed "Day" over twelve month names is wrong in a way
     * nobody notices until they read it out loud — and `<x-ss.bar-chart>` used to hard-code it.
     */
    public function test_the_column_header_matches_the_buckets(): void
    {
        $this->assertSame('Month', $this->labels(['chart_data' => 'year'])[1]);

        foreach (['last-month', 'this-month', 'seven-day'] as $window) {
            $this->assertSame('Day', $this->labels(['chart_data' => $window])[1],
                "the '{$window}' window buckets by day, so its table column is a day.");
        }
    }
}
