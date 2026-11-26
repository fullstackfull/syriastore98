<?php

namespace Tests\Feature\Analytics;

use App\Models\Order;
use Shetabit\Visitor\Models\Visit;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-004 / GAP-227(a) — THE CONVERSION TILE'S BASELINE, AND WHY IT IS NOT A PERCENTAGE.
 * =============================================================================================
 *
 * Measured 2026-11-22: both of the conversion tile's inputs carried a period-over-period baseline
 * and the ratio between them carried none. A merchant could see orders up and page views up and
 * could not see that the RATE had fallen — which is the one thing the tile exists to say.
 *
 * THE UNIT IS PERCENTAGE POINTS, and that is the substance of this file rather than a detail.
 * `comparison()`, which the count tiles use, reports a change as a percentage OF THE BASELINE.
 * For a rate that is a lie with a true number in it: 2.00% rising to 3.00% is +1.00 PERCENTAGE
 * POINT, and reporting it as "+50%" is the standard way an analytics screen turns a small
 * movement into a dramatic one. `rateComparison()` exists so the conversion tile cannot do that,
 * and `percent` is deliberately null on it.
 */
class ConversionBaselineTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function visit($store, string $when): void
    {
        $visit = new Visit();
        $visit->timestamps = false;
        $visit->store_id = $store->id;
        $visit->created_at = now()->parse($when);
        $visit->updated_at = now()->parse($when);
        $visit->save();
    }

    private function order($store, string $when): void
    {
        DB::table('orders')->insert([
            'product_order_id' => 'CB-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id, 'customer_id' => 0, 'is_guest' => 1,
            'order_source' => Order::SOURCE_STOREFRONT, 'final_price' => 10.00,
            'order_date' => now()->parse($when), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A STORE WHOSE PAGE-VIEW HISTORY PREDATES EVERY WINDOW THESE TESTS LOOK AT.
     *
     * The comparison tiles withhold their delta when the EARLIER window straddles the day page
     * views began being attributed to the store, because a count taken over part of a window and
     * compared with a full one reports a trend that did not happen (found by review, 2026-11-22).
     *
     * A fixture whose FIRST EVER visit sits inside the baseline window is exactly that case, so
     * it legitimately gets no comparison -- which is right for the product and useless for a test
     * about comparison ARITHMETIC. This seeds one visit far enough back that coverage is complete
     * for every window used here, which is what a real store of any age looks like. It is outside
     * both the chosen and the baseline window, so it changes no count either test asserts.
     */
    private function establishedVisitHistory($store): void
    {
        $this->visit($store, now()->subDays(400)->toDateTimeString());
    }

    /**
     * THE BASELINE MUST REACH THE SCREEN, not merely the array.
     *
     * Found by an independent review of the NEXT slice, after this one was committed and its CI
     * run was green: `analytics/index.blade.php` passed `:value`, `:period` and `:hint` to the
     * Conversion tile and NOT `:comparison`, unlike the Orders and Page views tiles above it. So
     * the rate baseline was computed, tested, exported-adjacent and shown to nobody -- the exact
     * "shipped into a slot nobody could read" failure `ss.stat`'s own docblock records for hints.
     *
     * Every other test in this file asserts the ARRAY, which is why none of them could see it. A
     * figure that no merchant can read is not delivered, so the assertion is made against the
     * rendered page.
     */
    public function test_the_baseline_is_rendered_on_the_analytics_screen(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->establishedVisitHistory($store);

        $this->grantPermission($merchant, 'Manage Reports');

        for ($i = 0; $i < 10; $i++) {
            $this->visit($store, now()->subDays(5)->toDateTimeString());
            $this->visit($store, now()->subDays(40)->toDateTimeString());
        }

        $this->order($store, now()->subDays(5)->toDateTimeString());
        $this->order($store, now()->subDays(40)->toDateTimeString());
        $this->order($store, now()->subDays(41)->toDateTimeString());

        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $response = $this->actingAs($merchant->fresh())->get('/analytics');

        $this->assertSame(200, $response->status(),
            'Premise: the analytics screen did not open, so the assertion below measures nothing.');

        $response->assertSee(__('percentage points'), false);
    }

    private function comparison($store): array
    {
        /* GAP-152: the trend comes back in the `comparisons` map rather than inside the tile,
           because a `CaveatedFigure`'s wire form is value/period/hint/caveats/authoritative and
           nothing else. An array read for `comparison` on a carrier answers null, so this helper
           asserts the trend is really there instead of handing back a silent null. */
        $figures = MerchantAnalyticsFigures::forStore($store->id, $store->currency ?? 'USD');

        $this->assertNotNull($figures['comparisons']['conversion'] ?? null,
            'The conversion tile carries no baseline at all, although both of its inputs do.');

        return $figures['comparisons']['conversion'];
    }

    private static function plain(?string $value): string
    {
        return trim(preg_replace('/[\x{2066}\x{2069}]/u', '', (string) $value));
    }

    /**
     * The default window is the last 30 days and its baseline is the 30 before that.
     * THIS period: 1 order / 50 visits = 2.00%.  EARLIER: 1 order / 100 visits = 1.00%.
     * So the rate rose by exactly ONE PERCENTAGE POINT -- and by 100% of the baseline, which is
     * the figure that must NOT appear.
     */
    public function test_a_rising_rate_is_reported_in_points_and_not_as_a_percentage_of_itself(): void
    {
        [, $store] = $this->makeTenant();

        $this->establishedVisitHistory($store);

        $this->order($store, '-2 days');
        for ($i = 0; $i < 50; $i++) {
            $this->visit($store, '-2 days');
        }

        $this->order($store, '-45 days');
        for ($i = 0; $i < 100; $i++) {
            $this->visit($store, '-45 days');
        }

        $comparison = $this->comparison($store);

        $this->assertSame('up', $comparison['direction']);
        $this->assertSame('1.00 ' . __('percentage points'), self::plain($comparison['change']),
            'The change is not stated in percentage points.');
        $this->assertSame('1.00%', self::plain($comparison['baseline']),
            'The earlier RATE is not shown, so the points figure cannot be checked against anything.');
        $this->assertNull($comparison['percent'],
            'A percentage of a percentage is shown. 1.00% to 2.00% would read as "+100%", which is the overstatement this comparison exists to avoid.');
    }

    public function test_a_falling_rate_is_reported_as_down(): void
    {
        [, $store] = $this->makeTenant();

        $this->establishedVisitHistory($store);

        $this->order($store, '-2 days');
        for ($i = 0; $i < 100; $i++) {
            $this->visit($store, '-2 days');
        }

        $this->order($store, '-45 days');
        for ($i = 0; $i < 50; $i++) {
            $this->visit($store, '-45 days');
        }

        $comparison = $this->comparison($store);

        $this->assertSame('down', $comparison['direction'],
            'The rate halved and the tile does not say it fell. This is the case the baseline exists for: BOTH counts can rise while the rate drops.');
        $this->assertSame('1.00 ' . __('percentage points'), self::plain($comparison['change']));
    }

    /**
     * NO PAGE VIEWS EARLIER IS NO RATE EARLIER, not a rate of zero -- the same refusal the tile
     * itself makes for the current period, and for the same reason: dividing by nothing produces
     * no answer, and a "rise from 0%" would be an invented one.
     */
    public function test_a_baseline_with_no_page_views_reports_no_rate_rather_than_a_rise_from_zero(): void
    {
        [, $store] = $this->makeTenant();

        $this->order($store, '-2 days');
        for ($i = 0; $i < 10; $i++) {
            $this->visit($store, '-2 days');
        }

        $comparison = $this->comparison($store);

        $this->assertSame('same', $comparison['direction'],
            'A rate was reported as risen against an earlier period that has no rate.');
        $this->assertSame(__('no rate'), self::plain($comparison['baseline']));
        $this->assertNotNull($comparison['note']);
        $this->assertStringContainsString('no rate to compare against', (string) $comparison['note']);
    }

    /** The two tiles above it and the rate must agree about which earlier orders exist. */
    public function test_the_rates_baseline_reads_the_same_counts_as_the_tiles_above_it(): void
    {
        [, $store] = $this->makeTenant();

        $this->establishedVisitHistory($store);

        $this->order($store, '-45 days');
        for ($i = 0; $i < 20; $i++) {
            $this->visit($store, '-45 days');
        }
        for ($i = 0; $i < 20; $i++) {
            $this->visit($store, '-2 days');
        }

        $figures = MerchantAnalyticsFigures::forStore($store->id, $store->currency ?? 'USD');

        $this->assertSame('1', self::plain($figures['comparisons']['orders']['baseline']),
            'Premise: the orders tile does not see the earlier order, so the rate baseline below proves nothing.');
        $this->assertSame('20', self::plain($figures['comparisons']['visits']['baseline']));

        // 1 order / 20 visits = 5.00% earlier; 0 / 20 = 0.00% now.
        $this->assertSame('5.00%', self::plain($figures['comparisons']['conversion']['baseline']),
            'The rate baseline was computed from a different pair of counts than the two tiles above it display.');
    }
}
