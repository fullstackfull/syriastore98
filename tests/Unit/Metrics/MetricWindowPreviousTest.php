<?php

namespace Tests\Unit\Metrics;

use App\Support\Metrics\MetricWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * ANA-002 — the baseline window a comparison is measured against.
 * =================================================================================================
 *
 * `MetricWindow::previous()` is one small method and it is the half of a comparison that can be
 * wrong without looking wrong. A delta computed against a window one day longer than its subject
 * does not error, does not look odd on screen, and reads to a merchant as a trend. So every
 * property that makes it a FAIR baseline is asserted here rather than assumed from the arithmetic:
 * equal length, no overlap, no gap, and — for a window that has no length at all — nothing.
 *
 * THE WHOLE-DAY BASIS IS NOT A SIMPLIFICATION. `applyTo()` widens `from` with `startOfDay()` and
 * `to` with `endOfDay()`, because `orders.cancel_date` is a DATE while the other three date columns
 * are DATETIME. A window is therefore an inclusive span of whole days no matter what times its
 * bounds carry, and these tests pass mid-afternoon bounds in on purpose to prove the times do not
 * change the answer.
 */
final class MetricWindowPreviousTest extends TestCase
{
    public function test_the_previous_window_is_the_same_length_and_ends_the_day_before(): void
    {
        $window = MetricWindow::between(
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-11-30'),
        );

        $previous = $window->previous();

        $this->assertNotNull($previous);
        $this->assertSame('2026-10-02', $previous->from()->toDateString(),
            'A 30-day window must be compared against 30 days, so the baseline starts on 2 October '
            . '— not 1 October, which would be a 31-day baseline for a 30-day figure.');
        $this->assertSame('2026-10-31', $previous->to()->toDateString(),
            'The baseline must end the day BEFORE the window begins: any later overlaps, any '
            . 'earlier leaves a gap neither period covers.');
    }

    public function test_the_two_windows_are_equal_in_length_for_every_span_tested(): void
    {
        $spans = [
            'one day' => ['2026-11-15', '2026-11-15'],
            'a week' => ['2026-11-09', '2026-11-15'],
            'thirty days' => ['2026-10-17', '2026-11-15'],
            'a calendar month' => ['2026-11-01', '2026-11-30'],
            'across a year boundary' => ['2026-12-20', '2027-01-10'],
            'across a leap day' => ['2028-02-20', '2028-03-05'],
        ];

        foreach ($spans as $name => [$from, $to]) {
            $window = MetricWindow::between(
                CarbonImmutable::parse($from),
                CarbonImmutable::parse($to),
            );

            $previous = $window->previous();
            $this->assertNotNull($previous, "{$name}: no baseline was produced for a bounded window.");

            $length = $window->from()->startOfDay()->diffInDays($window->to()->startOfDay()) + 1;
            $baseline = $previous->from()->startOfDay()->diffInDays($previous->to()->startOfDay()) + 1;

            $this->assertSame($length, $baseline, sprintf(
                '%s: the window covers %d day(s) and its baseline covers %d. An unequal baseline '
                . 'makes every delta computed from it wrong by the ratio of the two lengths.',
                $name,
                $length,
                $baseline
            ));

            $this->assertTrue(
                $previous->to()->startOfDay()->addDay()->equalTo($window->from()->startOfDay()),
                "{$name}: the baseline does not end exactly the day before the window begins."
            );
        }
    }

    /**
     * A single day is the smallest window and the easiest one to get wrong by an off-by-one, so it
     * is asserted on its own as well as in the span table.
     */
    public function test_a_one_day_window_compares_against_the_day_before(): void
    {
        $previous = MetricWindow::between(
            CarbonImmutable::parse('2026-11-15'),
            CarbonImmutable::parse('2026-11-15'),
        )->previous();

        $this->assertSame('2026-11-14', $previous->from()->toDateString());
        $this->assertSame('2026-11-14', $previous->to()->toDateString());
    }

    /** The bounds' clock times are irrelevant: `applyTo()` queries whole days. */
    public function test_the_time_of_day_on_the_bounds_does_not_change_the_baseline(): void
    {
        $previous = MetricWindow::between(
            CarbonImmutable::parse('2026-11-01 15:42:11'),
            CarbonImmutable::parse('2026-11-30 03:04:05'),
        )->previous();

        $this->assertSame('2026-10-02', $previous->from()->toDateString());
        $this->assertSame('2026-10-31', $previous->to()->toDateString());
    }

    /**
     * THE BOUNDS COME BACK DAY-ALIGNED, which is the property that stops a 24-hour hole.
     *
     * This is a REGRESSION TEST for a defect this method really had: `to` was returned at 00:00,
     * and `MerchantAnalyticsFigures` counts with `whereBetween` directly rather than through
     * `applyTo()`, so every order placed DURING the baseline's last day fell between the two
     * periods and was counted in neither. `AnalyticsComparisonTest`'s boundary case found it.
     *
     * `applyTo()` re-applies the same widening, which is idempotent, so aligning here is safe for
     * both paths -- and it means a consumer cannot get this wrong by forgetting.
     */
    public function test_the_baseline_bounds_are_day_aligned_so_a_naive_consumer_cannot_lose_a_day(): void
    {
        $previous = MetricWindow::between(
            CarbonImmutable::parse('2026-10-22 00:00:00'),
            CarbonImmutable::parse('2026-11-20 23:59:59'),
        )->previous();

        $this->assertSame('00:00:00', $previous->from()->format('H:i:s'),
            'The baseline must start at the very beginning of its first day.');

        $this->assertSame('23:59:59', $previous->to()->format('H:i:s'), sprintf(
            'The baseline ends at %s. Any consumer that passes these bounds straight into a '
            . 'whereBetween -- which MerchantAnalyticsFigures does -- then drops every row dated '
            . 'DURING the final day, and since the window itself begins the next midnight those '
            . 'rows are counted in NEITHER period and vanish from the comparison.',
            $previous->to()->format('H:i:s')
        ));

        $window = MetricWindow::between(
            CarbonImmutable::parse('2026-10-22 00:00:00'),
            CarbonImmutable::parse('2026-11-20 23:59:59'),
        );

        /*
         * CONTIGUITY IS ASSERTED ON DAY BOUNDARIES, NOT BY ADDING A SECOND, and the first draft of
         * this assertion got that wrong: Carbon's `endOfDay()` is 23:59:59.999999, so
         * `->addSecond()` lands on 00:00:00.999999 and is NOT equal to the window's 00:00:00. The
         * assertion failed while the code was right, which is the cheaper of the two mistakes but
         * still a wrong test. The micros are harmless against the schema -- a DATETIME column
         * stores at most 23:59:59, so `<= 23:59:59.999999` includes it -- and what actually
         * matters is that the baseline's last DAY is the one before the window's first.
         */
        $this->assertTrue(
            $previous->to()->startOfDay()->addDay()->equalTo($window->from()->startOfDay()),
            'The baseline\'s last day is not the day before the window\'s first, so there is a '
            . 'gap or an overlap between the two periods.'
        );
    }

    /**
     * THE REFUSALS, which are the point of the method rather than its edge cases.
     *
     * A window with an open end has no length — `since($from)` runs to whenever the query executes
     * — so there is no equally long period before it. Returning null makes the caller say "no
     * comparison" on the screen; returning a guess would make it print a trend it invented.
     */
    public function test_a_window_with_no_length_has_no_baseline(): void
    {
        $this->assertNull(MetricWindow::allTime()->previous(),
            'All time has no baseline: there is nothing before the beginning.');

        $this->assertNull(MetricWindow::since(CarbonImmutable::parse('2026-11-01'))->previous(),
            'An open-ended window runs to whenever the query executes, so its length is not known '
            . 'at construction and no equally long baseline can be computed.');

        $this->assertNull(MetricWindow::between(null, CarbonImmutable::parse('2026-11-30'))->previous(),
            'A window with no start has no length either.');
    }

    /** The baseline is itself a usable window: bounded, so it can be compared in turn. */
    public function test_the_baseline_is_a_window_that_can_itself_be_stepped_back(): void
    {
        $window = MetricWindow::between(
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-11-30'),
        );

        $twoAgo = $window->previous()->previous();

        $this->assertNotNull($twoAgo);
        $this->assertSame('2026-09-02', $twoAgo->from()->toDateString());
        $this->assertSame('2026-10-01', $twoAgo->to()->toDateString());
        $this->assertFalse($twoAgo->isAllTime());
    }
}
