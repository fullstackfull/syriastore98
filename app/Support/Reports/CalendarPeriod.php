<?php

namespace App\Support\Reports;

/**
 * THE CALENDAR PERIODS THE REPORT SCREENS NAME, derived ONCE -- GAP-223(a).
 *
 * `ReportController` derived "last month" in five places with `strtotime('-1 month')`, and that
 * expression OVERFLOWS: PHP's relative month arithmetic keeps the day-of-month, so on 31 March it
 * yields 3 March, and `date('Y-m-01', ...)` on that is 1 MARCH. The window is then the WHOLE
 * CURRENT MONTH while `chartPeriodLabels()` -- which already used the correct
 * `'first day of last month'` -- names the previous one. MEASURED over 2024-01-01..2028-12-31
 * (1,827 days): **33 overflow days**, and on every one of them the card's label names a different
 * month from its figures. On 31 December the card reads "Last month (November 2025)" over
 * December's orders.
 *
 * THE LABEL AND THE WINDOW NOW COME FROM THE SAME EXPRESSION, which is the part that makes this a
 * fix rather than a second copy of the same hazard. `lastMonth()` and `lastMonthLabel()` both call
 * `firstDayOfLastMonth()`; there is no way for them to drift because there is nothing to keep in
 * step. The old arrangement was not a typo -- it was two writers reaching for two idioms for the
 * same idea, and one of them was wrong.
 *
 * `days` IS RETURNED WITH THE BOUNDS, AND NOT COMPUTED BY THE CALLER. The handlers build their
 * chart series by looping over the days of the window, and two of them derived the loop bound
 * separately (`(int) date('j', strtotime($end))`, `date('j', strtotime($lastDayOfPreviousMonth))`).
 * A day count derived from a second expression is the same defect one level down: correct dates
 * with a stale bound give a chart whose series is shorter or longer than its axis. One call, three
 * values, no way to disagree.
 *
 * `$now` IS INJECTABLE BECAUSE THE BUG IS ONLY VISIBLE ON 33 DAYS A YEAR AND A TEST CANNOT WAIT
 * FOR THEM. `Carbon::setTestNow()` moves `Carbon::now()` but NOT `date()` or `strtotime()` --
 * measured in this project, and it is why the earlier evidence for this defect had to be a
 * standalone script. Passing a timestamp makes every one of the 1,827 days assertable in the
 * ordinary Unit suite, which is what `CalendarPeriodTest` does.
 */
final class CalendarPeriod
{
    /**
     * The previous calendar month: its first day, its last day, and how many days it has.
     *
     * @param  ?int  $now  a reference timestamp; null means now. Tests pass one; callers do not.
     * @return array{from: string, to: string, days: int}
     */
    public static function lastMonth(?int $now = null): array
    {
        $first = self::firstDayOfLastMonth($now);

        return [
            'from' => date('Y-m-01', $first),
            'to' => date('Y-m-t', $first),
            'days' => (int) date('t', $first),
        ];
    }

    /** How that period is NAMED on screen, from the same expression as its bounds. */
    public static function lastMonthLabel(?int $now = null): string
    {
        return date('F Y', self::firstDayOfLastMonth($now));
    }

    /**
     * The one expression. `'first day of last month'` sets the day to 1 BEFORE subtracting the
     * month, so there is no day-of-month to overflow -- which is exactly what `'-1 month'` gets
     * wrong.
     */
    private static function firstDayOfLastMonth(?int $now): int
    {
        $base = $now ?? time();
        $first = strtotime('first day of last month', $base);

        if ($first === false) {
            // Unreachable for any real timestamp, and a silent `false` would be read by `date()`
            // as the epoch -- a window in January 1970, which would quietly report zero orders
            // rather than failing. Zero is the one wrong answer that looks like a right one.
            throw new \RuntimeException(
                'Could not derive the previous calendar month from timestamp '.var_export($base, true).'.'
            );
        }

        return $first;
    }
}
