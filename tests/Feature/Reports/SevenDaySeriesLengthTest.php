<?php

namespace Tests\Feature\Reports;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * THE SEVEN-DAY ORDER SERIES WAS TWICE AS LONG AS ITS OWN AXIS.
 * =================================================================================================
 *
 * `ReportController::order_reports_chart`'s seven-day limb loops `for ($i = 0; $i <= 7 - 1; $i++)`
 * and appends one value per iteration to each series it builds: `$monthList[]` (the axis labels),
 * `$PurchasedItemTotal[]`, `$GrossSaleTotal[]`, `$NetSaleTotal[]`, `$CouponTotal[]`,
 * `$ShippingTotal[]`, `$averageGrossSales[]`, `$averageNetSales[]` -- seven values each.
 *
 * `$TotalOrderCount[]` WAS APPENDED TWICE IN THE SAME ITERATION: once among the sibling series, and
 * again forty lines later among the `$Total* +=` accumulators where it does not belong. Fourteen
 * values against a seven-label axis, every count duplicated. Nothing between the two reads it, and
 * the second is the one that does not fit its surroundings, so the second is the one removed.
 *
 * FOUND BY THE SITE-MAPPING PASS FOR GAP-223(a), not by any test, and recorded then as a separate
 * defect rather than folded into that slice -- it is a different bug in the same method and deserved
 * its own failing test.
 *
 * WHY THIS IS A SOURCE TEST. `order_reports_chart` cannot run under the suite: it uses `MONTH(` in
 * `selectRaw` and SQLite has no such function, so the route answers HTTP 500 (measured: `no such
 * function: MONTH`). The same constraint that hid this bug for the life of the handler also stops a
 * Feature test from driving it, so the property is asserted where it IS visible -- by counting the
 * appends in the limb's own source, comment-stripped, because this file's own explanation names the
 * variable it counts.
 */
class SevenDaySeriesLengthTest extends TestCase
{
    use StripsSourceComments;

    /** The seven-day limb of `order_reports_chart`, comment-stripped. */
    private function sevenDayLimb(): string
    {
        $source = $this->sourceWithoutComments('app/Http/Controllers/ReportController.php');

        $method = strpos($source, 'public function order_reports_chart(');
        $this->assertNotFalse($method, 'premise: order_reports_chart is still in the controller');

        $limb = strpos($source, "chart_data == 'seven-day'", $method);
        $this->assertNotFalse($limb, 'premise: the seven-day limb is still a branch of that method');

        // To the next branch, which bounds the limb.
        $next = strpos($source, "chart_data == 'year'", $limb);
        $this->assertNotFalse($next, 'premise: the year limb follows it');

        return substr($source, $limb, $next - $limb);
    }

    public function test_the_order_count_series_gets_one_value_per_day_like_every_other_series(): void
    {
        $limb = $this->sevenDayLimb();

        $this->assertSame(1, substr_count($limb, '$TotalOrderCount[] ='),
            'the order-count series is appended more than once per iteration, so it comes out longer '
            . 'than the axis it is plotted against and every count is duplicated');
    }

    public function test_every_series_in_the_limb_is_appended_exactly_once_per_iteration(): void
    {
        $limb = $this->sevenDayLimb();

        /*
         * THE WHOLE FAMILY, not just the one that was wrong. A series appended twice is invisible
         * until someone counts, and the next one to drift should fail here rather than ship a chart
         * whose values and labels disagree.
         */
        $series = [
            '$monthList[] =',
            '$PurchasedItemTotal[] =',
            '$GrossSaleTotal[] =',
            '$NetSaleTotal[] =',
            '$CouponTotal[] =',
            '$ShippingTotal[] =',
            '$TotalOrderCount[] =',
            '$averageGrossSales[] =',
            '$averageNetSales[] =',
        ];

        $counts = [];
        foreach ($series as $append) {
            $counts[$append] = substr_count($limb, $append);
        }

        $this->assertSame(array_fill_keys($series, 1), $counts,
            'every series in a one-value-per-day loop must be appended exactly once per iteration');
    }

    public function test_the_loop_really_is_seven_iterations_so_the_counts_above_mean_seven_values(): void
    {
        $limb = $this->sevenDayLimb();

        /*
         * The premise the two assertions above rest on. If this loop ever stops being seven
         * iterations, "appended once" stops meaning "seven values" and these tests would be
         * measuring the wrong thing while still passing.
         */
        $this->assertStringContainsString('for ($i = 0; $i <= 7 - 1; $i++)', $limb,
            'the seven-day limb is no longer a seven-iteration loop, so these append counts no longer '
            . 'establish the series length -- re-derive the property before trusting them');
        $this->assertSame(1, substr_count($limb, 'for ($i = 0; $i <= 7 - 1; $i++)'),
            'more than one such loop in the limb would make the append counts ambiguous');
    }
}
