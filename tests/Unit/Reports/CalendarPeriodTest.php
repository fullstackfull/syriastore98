<?php

namespace Tests\Unit\Reports;

use App\Support\Reports\CalendarPeriod;
use PHPUnit\Framework\TestCase;

/**
 * GAP-223(a) -- THE LAST-MONTH WINDOW, ASSERTED ON EVERY DAY OF FIVE YEARS.
 *
 * The defect was only visible on 33 days in every five years, which is why no test had it: a suite
 * that runs on whatever day CI happens to run cannot see a bug that needs 31 March. `Carbon::
 * setTestNow()` does not help -- it moves `Carbon::now()` and NOT `date()` or `strtotime()`,
 * measured in this project -- so the earlier evidence for this defect had to be a standalone
 * script. `CalendarPeriod::lastMonth()` takes a reference timestamp, and that one change is what
 * turns 33 unreachable days into 1,827 ordinary assertions.
 *
 * THE EXPECTATIONS ARE SPELLED DIFFERENTLY FROM THE IMPLEMENTATION, deliberately. A test that
 * computes `date('Y-m-01', strtotime('first day of last month', $t))` to check a method that
 * computes `date('Y-m-01', strtotime('first day of last month', $t))` asserts that PHP is
 * deterministic. These expectations are built from the YEAR AND MONTH ARITHMETIC instead -- subtract
 * one from the month, borrow from the year at January -- so the two can disagree, which is the only
 * way a test can fail for the right reason. That discipline is in this project's rules because a
 * preset-bounds test written the other way was true on exactly one day.
 */
class CalendarPeriodTest extends TestCase
{
    /** The window every day of these five years must produce. Pure arithmetic, no date functions. */
    private function expected(int $year, int $month, int $day): array
    {
        $m = $month - 1;
        $y = $year;

        if ($m === 0) {
            $m = 12;
            $y--;
        }

        // Days in month, from the rule rather than from date('t').
        $lengths = [1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30,
            7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];
        $days = $lengths[$m];

        if ($m === 2 && (($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0)) {
            $days = 29;
        }

        return [
            'from' => sprintf('%04d-%02d-01', $y, $m),
            'to' => sprintf('%04d-%02d-%02d', $y, $m, $days),
            'days' => $days,
        ];
    }

    public function test_every_day_of_five_years_gets_the_previous_calendar_month(): void
    {
        $wrong = [];
        $examined = 0;

        for ($t = mktime(12, 0, 0, 1, 1, 2024); $t <= mktime(12, 0, 0, 12, 31, 2028); $t = strtotime('+1 day', $t)) {
            $examined++;
            $actual = CalendarPeriod::lastMonth($t);
            $want = $this->expected((int) date('Y', $t), (int) date('n', $t), (int) date('j', $t));

            if ($actual !== $want) {
                $wrong[] = date('Y-m-d', $t).': got '.json_encode($actual).' want '.json_encode($want);
            }
        }

        $this->assertSame(1827, $examined, 'premise: five years including two leap years were walked');
        $this->assertSame([], array_slice($wrong, 0, 10),
            count($wrong).' of '.$examined.' days derive the wrong previous month');
    }

    /**
     * THE 33 DAYS, NAMED. This is the regression: before the fix, each of these produced the WHOLE
     * CURRENT MONTH. The list is derived from the overflow RULE -- today's day-of-month exceeds the
     * length of the previous month -- not copied from a run, so it is a statement about the calendar
     * and not a snapshot of the code's behaviour.
     */
    public function test_the_overflow_days_now_name_the_previous_month_and_not_the_current_one(): void
    {
        $overflowDays = [];

        for ($t = mktime(12, 0, 0, 1, 1, 2024); $t <= mktime(12, 0, 0, 12, 31, 2028); $t = strtotime('+1 day', $t)) {
            $want = $this->expected((int) date('Y', $t), (int) date('n', $t), (int) date('j', $t));

            // The rule: the day cannot exist in the previous month.
            if ((int) date('j', $t) > $want['days']) {
                $overflowDays[] = $t;
            }
        }

        $this->assertCount(33, $overflowDays,
            'premise: the calendar really does supply 33 such days in these five years');

        foreach ($overflowDays as $t) {
            $window = CalendarPeriod::lastMonth($t);
            $want = $this->expected((int) date('Y', $t), (int) date('n', $t), (int) date('j', $t));

            $this->assertSame($want, $window, 'overflow day '.date('Y-m-d', $t).' derives the wrong month');

            // And the specific wrong answer the old expression gave: the current month.
            $this->assertNotSame(date('Y-m-01', $t), $window['from'],
                'on '.date('Y-m-d', $t).' the window starts in the CURRENT month, which is the whole defect');
        }
    }

    public function test_the_label_and_the_bounds_can_never_name_different_months(): void
    {
        $mismatched = [];

        for ($t = mktime(12, 0, 0, 1, 1, 2024); $t <= mktime(12, 0, 0, 12, 31, 2028); $t = strtotime('+1 day', $t)) {
            $window = CalendarPeriod::lastMonth($t);
            $label = CalendarPeriod::lastMonthLabel($t);

            // `from` is YYYY-MM-01; the label is "Month YYYY". Compare them as the reader does.
            $expectedLabel = date('F Y', mktime(12, 0, 0, (int) substr($window['from'], 5, 2), 1, (int) substr($window['from'], 0, 4)));

            if ($label !== $expectedLabel) {
                $mismatched[] = date('Y-m-d', $t).": label {$label} over figures from {$window['from']}";
            }
        }

        $this->assertSame([], array_slice($mismatched, 0, 10),
            count($mismatched).' days name a month the figures do not cover -- which is GAP-223(a) itself');
    }

    public function test_the_day_count_is_the_length_of_the_window_it_came_with(): void
    {
        $wrong = [];

        for ($t = mktime(12, 0, 0, 1, 1, 2024); $t <= mktime(12, 0, 0, 12, 31, 2028); $t = strtotime('+1 day', $t)) {
            $window = CalendarPeriod::lastMonth($t);

            /*
             * The day count is what the handlers use as a LOOP BOUND for the chart series, so it has
             * to be the length of THIS window and not of some other month. Derived here by counting
             * from `from` to `to` -- a different spelling from `date('t')`.
             */
            $counted = 1 + (int) round((strtotime($window['to'].' 12:00:00') - strtotime($window['from'].' 12:00:00')) / 86400);

            if ($counted !== $window['days']) {
                $wrong[] = date('Y-m-d', $t).": {$window['from']}..{$window['to']} spans {$counted} days but says {$window['days']}";
            }
        }

        $this->assertSame([], array_slice($wrong, 0, 10), count($wrong).' windows disagree with their own day count');
    }

    public function test_february_in_a_leap_year_is_twenty_nine_days(): void
    {
        $window = CalendarPeriod::lastMonth(mktime(12, 0, 0, 3, 15, 2024));

        $this->assertSame(['from' => '2024-02-01', 'to' => '2024-02-29', 'days' => 29], $window);
    }

    public function test_january_borrows_from_the_previous_year(): void
    {
        $window = CalendarPeriod::lastMonth(mktime(12, 0, 0, 1, 31, 2027));

        // 31 January is itself an overflow day: December has 31 days, so it is NOT -- a good check
        // that the fix does not over-correct a day that was always fine.
        $this->assertSame(['from' => '2026-12-01', 'to' => '2026-12-31', 'days' => 31], $window);
        $this->assertSame('December 2026', CalendarPeriod::lastMonthLabel(mktime(12, 0, 0, 1, 31, 2027)));
    }

    public function test_the_time_of_day_does_not_move_the_window(): void
    {
        $midnight = CalendarPeriod::lastMonth(mktime(0, 0, 0, 3, 31, 2026));
        $noon = CalendarPeriod::lastMonth(mktime(12, 0, 0, 3, 31, 2026));
        $lastSecond = CalendarPeriod::lastMonth(mktime(23, 59, 59, 3, 31, 2026));

        $this->assertSame($midnight, $noon);
        $this->assertSame($noon, $lastSecond);
        $this->assertSame('2026-02-01', $noon['from'], 'and 31 March 2026 is an overflow day, so this is the repaired answer');
    }

    /**
     * WHY THE `false` GUARD EXISTS, since no test can reach it.
     *
     * `strtotime('first day of last month', $int)` cannot fail -- the format is a constant and the
     * base is an int -- so the guard is defensive against a state PHP will not produce, and a
     * mutation replacing its condition with `false` SURVIVES. Recorded rather than papered over: an
     * unreachable branch is not coverage, and a test that pretended otherwise would be the fourth
     * kind of false reading this project tracks.
     *
     * MEASURED, rather than assumed, because the first version of this test tried to reach the
     * guard with `PHP_INT_MAX` and did not. `strtotime('first day of last month', $base)` returns an
     * int for EVERY integer base tried -- PHP_INT_MAX gives the year 292277026596, PHP_INT_MIN
     * wraps to a date in the future, 0 gives 1969-12-01 -- so there is no input that reaches the
     * throw, and the mutation stays unaimable. The test says so instead of asserting an exception it
     * cannot provoke.
     *
     * What IS assertable is the hazard, and it is the reason the guard throws rather than falling
     * back: PHP coerces `false` to 0 in `date()`, so an unguarded failure would silently produce a
     * window in JANUARY 1970 and the report would read ZERO ORDERS. Zero is the one wrong answer
     * that looks like a right one -- a merchant reads it as a month with no business, not as a
     * broken date.
     */
    public function test_an_unguarded_failure_would_read_as_the_epoch_which_is_why_it_throws(): void
    {
        $this->assertSame('1970-01-01', date('Y-m-01', (int) false),
            'premise: a false timestamp is the epoch to date(), not an error');
        $this->assertSame('1970-01-31', date('Y-m-t', (int) false));
        $this->assertSame(0, (int) false, 'which is the coercion that makes the silence possible');

        /*
         * And the measurement that makes the guard unreachable: every one of these answers with an
         * int. If a future PHP ever returns false for one of them, THIS assertion fails and the
         * guard stops being dead code -- which is the tripwire worth having.
         */
        foreach ([PHP_INT_MAX, PHP_INT_MIN, 0, -1, 1, time()] as $base) {
            $this->assertIsInt(strtotime('first day of last month', $base),
                'strtotime now fails for base '.$base.', so CalendarPeriod\'s false guard is reachable: aim a mutation at it');
        }
    }

    public function test_omitting_the_timestamp_answers_for_today(): void
    {
        $this->assertSame(CalendarPeriod::lastMonth(time()), CalendarPeriod::lastMonth(),
            'the production call passes no timestamp, so the default must be the real clock');
        $this->assertSame(CalendarPeriod::lastMonthLabel(time()), CalendarPeriod::lastMonthLabel());
    }
}
