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

    private function comparison($store): array
    {
        $conversion = MerchantAnalyticsFigures::forStore($store->id, $store->currency ?? 'USD')['conversion'];

        $this->assertArrayHasKey('comparison', $conversion,
            'The conversion tile carries no baseline at all, although both of its inputs do.');

        return $conversion['comparison'];
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

        $this->order($store, '-45 days');
        for ($i = 0; $i < 20; $i++) {
            $this->visit($store, '-45 days');
        }
        for ($i = 0; $i < 20; $i++) {
            $this->visit($store, '-2 days');
        }

        $figures = MerchantAnalyticsFigures::forStore($store->id, $store->currency ?? 'USD');

        $this->assertSame('1', self::plain($figures['orders']['comparison']['baseline']),
            'Premise: the orders tile does not see the earlier order, so the rate baseline below proves nothing.');
        $this->assertSame('20', self::plain($figures['visits']['comparison']['baseline']));

        // 1 order / 20 visits = 5.00% earlier; 0 / 20 = 0.00% now.
        $this->assertSame('5.00%', self::plain($figures['conversion']['comparison']['baseline']),
            'The rate baseline was computed from a different pair of counts than the two tiles above it display.');
    }
}
