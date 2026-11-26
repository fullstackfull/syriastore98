<?php

namespace Tests\Feature\Analytics;

use App\Models\Permission;
use App\Models\User;
use App\Support\Metrics\CaveatedFigure;
use App\Support\Metrics\CouponRedemption;
use App\Support\Metrics\MerchantAnalyticsFigures;
use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\PlatformAnalyticsFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-152 / P05-04 — THE ANALYTICS TILES, AND THE CAVEATS THEIR OWN METRICS ATTACH.
 * =================================================================================================
 *
 * `CaveatCarrierReachesEverySurfaceTest` is this gate for the two `/finance` ledger figures. This is
 * the same gate for the ANALYTICS producers, which the GAP-152 row names first because they feed
 * both a screen and an export: `MerchantAnalyticsFigures` (15 hand-composed hints) and
 * `PlatformAnalyticsFigures` (16).
 *
 * THE OMISSION THIS WAS WRITTEN AGAINST, MEASURED FROM SOURCE BEFORE ANYTHING CHANGED.
 * `CouponRedemption::for()` attaches `MetricCaveat::FLOAT_PRECISION` **unconditionally** --
 * `CouponRedemption.php:152`, with its own reason on the line above: "coupon_discount_amount is
 * FLOAT on order_coupon_details". `MerchantAnalyticsFigures::redemptions()` composed its hint by
 * hand from two to four sentences and stated the recording coverage, the payment-status basis, the
 * assumed currency and the bucket/setting mismatch -- and said nothing at all about precision. That
 * is the same family of defect as the payment-fee caveat GAP-152 closed on `/finance`: a caveat
 * attached by the metric, translated, ratcheted, and rendered on none of the three surfaces that
 * print the figure (`/analytics`, `/analytics/export`, the scheduled-report mail).
 *
 * WHY THE CAVEATS ARE ENUMERATED AT RUN TIME rather than listed here. A list goes stale the next
 * time a metric attaches something; a loop over `CaveatedFigure::caveats()` tightens by itself. The
 * premise tests below exist because that loop is vacuous over an empty list -- the exact failure the
 * GAP-152 note records as a surviving mutant ("the test read its expectations from the same code it
 * was testing").
 */
final class AnalyticsTilesCarryTheirCaveatsTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A fixed instant, so "the last 30 days" is the same window in every run. */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-20 12:00:00');
    }

    /** An order inside the window with a coupon discount AND its redemption row, so the figure shows. */
    private function discountedOrder($store, CarbonImmutable $when): void
    {
        $order = $this->makeOrder($store, ['coupon_price' => 10.0, 'order_date' => $when]);

        DB::table('order_coupon_details')->insert([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'coupon_id' => 1,
            'coupon_name' => 'TEN', 'coupon_code' => 'TEN',
            'coupon_discount_type' => 'flat', 'coupon_discount_number' => 10.0,
            'coupon_discount_amount' => 10.0, 'coupon_final_amount' => 90.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Whitespace collapsed, bidi isolates dropped: the comparison a reader would make. */
    private function plain(string $text): string
    {
        $text = (string) preg_replace('/[\x{2066}-\x{2069}]/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * THE PREMISE. Without it every caveat loop below iterates an empty list and passes vacuously,
     * which is precisely how the first version of GAP-152's gate let a mutant survive.
     */
    public function test_the_premise_that_the_coupon_discount_metric_attaches_the_precision_caveat(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $this->discountedOrder($store, $now->subDays(2));

        $result = (new CouponRedemption())->for(
            MetricScope::store((int) $store->id),
            MetricWindow::between($now->subDays(30), $now),
        );

        $this->assertTrue($result->hasCaveat(MetricCaveat::FLOAT_PRECISION),
            'CouponRedemption.php:152 attaches FLOAT_PRECISION unconditionally because '
            . 'order_coupon_details.coupon_discount_amount is FLOAT. If that stopped being true, '
            . 'this whole test must be re-read rather than relaxed.');
        $this->assertTrue($result->isAuthoritative(),
            'premise: the window lies inside the complete period, so the tile shows a number at all');
    }

    /**
     * THE DEFECT. The money tile prints an amount off a FLOAT column and its own metric says so.
     */
    public function test_the_coupon_discount_tile_states_the_precision_limit_its_metric_attached(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $this->discountedOrder($store, $now->subDays(2));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);
        $tile = $figures['redemptions'];

        $this->assertNotNull($tile['value'],
            'premise: the records are complete for this window, so the tile shows the money');

        $this->assertStringContainsString(
            $this->plain(MetricCaveat::message(MetricCaveat::FLOAT_PRECISION)),
            $this->plain((string) $tile['hint']),
            'the coupon-discount tile prints a money figure accumulated off a FLOAT column and '
            . 'states no precision limit, while its own CouponRedemption result carries '
            . 'FLOAT_PRECISION. That is the payment-fee omission of GAP-152 in a second place.'
        );
    }

    /**
     * THE MECHANISM. Every single-figure analytics tile is a carrier, so its words are DERIVED from
     * its caveat list and no surface can compose a hint that omits one.
     *
     * The breakdowns are deliberately NOT in this list and that is the producer's own distinction,
     * stated in `MerchantAnalyticsFigures`' docblock: "a figure is one `value`, a breakdown is a
     * `rows` list". A breakdown carries no single value, so `CaveatedFigure` cannot hold one.
     */
    public function test_every_single_figure_merchant_analytics_tile_is_a_carrier(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $this->discountedOrder($store, $now->subDays(2));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        foreach (['orders', 'visits', 'conversion', 'customers', 'redemptions', 'retention'] as $key) {
            $this->assertInstanceOf(CaveatedFigure::class, $figures[$key],
                $key . ' is still a hand-composed array, so its hint can omit a caveat its metric attached');
        }
    }

    public function test_every_single_figure_platform_analytics_tile_is_a_carrier(): void
    {
        $this->makeTenant();

        $figures = PlatformAnalyticsFigures::forPlatform($this->now());

        foreach (['stores', 'orders', 'buyers', 'retention', 'visits'] as $key) {
            $this->assertInstanceOf(CaveatedFigure::class, $figures[$key],
                $key . ' is still a hand-composed array, so its hint can omit a caveat its metric attached');
        }
    }

    /**
     * AND THE WORDS FOLLOW THE KEYS. For every caveat every converted tile carries, the sentence
     * that answers it is in the hint a merchant reads.
     */
    public function test_every_caveat_a_merchant_analytics_tile_carries_is_stated_in_its_hint(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $this->discountedOrder($store, $now->subDays(2));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);
        $seen = 0;

        foreach (['orders', 'visits', 'conversion', 'customers', 'redemptions', 'retention'] as $key) {
            $tile = $figures[$key];

            $this->assertInstanceOf(CaveatedFigure::class, $tile, $key . ' is not a carrier');

            foreach ($tile->caveats() as $index => $caveat) {
                $seen++;

                $this->assertStringContainsString(
                    $this->plain($tile->caveatSentences()[$index]),
                    $this->plain($tile->hint()),
                    $key . ' carries ' . $caveat . ' and its hint does not state it'
                );
            }
        }

        $this->assertGreaterThan(0, $seen,
            'no tile carried any caveat, so the loop above asserted nothing. Either the fixture '
            . 'stopped producing a coupon redemption or the tiles stopped reading their results.');
    }

    /**
     * AND IT READS AS ARABIC ON AN ARABIC SCREEN. CLAUDE.md's standing rule is that any UI change
     * is checked in both directions; this change moved twelve tiles from `<x-ss.stat>` to
     * `<x-ss.figure>`, which renders THROUGH `<x-ss.stat>` rather than beside it, so the markup is
     * the audited one -- but the SENTENCES are now derived in the producer, and a figure built
     * outside the request's locale would carry English words onto an Arabic page.
     */
    public function test_the_coupon_tiles_precision_sentence_is_arabic_on_an_arabic_screen(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $now = $this->now();

        Permission::firstOrCreate(['name' => 'Manage Reports'], ['display_name' => 'Manage Reports']);
        $this->grantPermission(User::find($merchant->id), 'Manage Reports');

        DB::table('settings')->insert([
            'name' => 'defult_currancy', 'value' => 'USD',
            'store_id' => $store->id, 'created_by' => $merchant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->discountedOrder($store, $now->subDays(2));

        $merchant = User::find($merchant->id);
        $merchant->language = 'ar';
        $merchant->save();

        Cache::forget('admin_details');

        $english = MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);

        $html = (string) $this->actingAs(User::find($merchant->id))->get('/analytics')->getContent();
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $page = $this->plain(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        /* THE ORDER MATTERS, as the sibling gate records: the controller built its figures in the
           REQUEST's locale, so the expectation has to be rebuilt in that locale too. */
        $this->app->setLocale('ar');

        $arabic = MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);

        $this->assertNotSame($english, $arabic,
            'FLOAT_PRECISION has no Arabic, so this test proves nothing');
        $this->assertStringContainsString($this->plain($arabic), $page,
            'the Arabic /analytics shows no precision sentence on the coupon-discount tile');
        $this->assertStringNotContainsString($this->plain($english), $page,
            'an Arabic merchant was shown the English sentence');
    }

    /**
     * AND THE EXPORT, which is half the reason GAP-152 names these two producers first: "they feed
     * both a screen and an export". The Phase 05 rule is that the disclosure is a COLUMN, because a
     * caveat in a header preamble survives neither sorting nor copying a range.
     */
    public function test_every_caveat_the_coupon_tile_carries_reaches_the_analytics_export(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $now = $this->now();

        Permission::firstOrCreate(['name' => 'Manage Reports'], ['display_name' => 'Manage Reports']);
        $this->grantPermission(User::find($merchant->id), 'Manage Reports');

        DB::table('settings')->insert([
            'name' => 'defult_currancy', 'value' => 'USD',
            'store_id' => $store->id, 'created_by' => $merchant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /* Several handlers read the platform admin through Cache::remember('admin_details'). */
        Cache::forget('admin_details');

        $this->discountedOrder($store, $now->subDays(2));

        $tile = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now)['redemptions'];

        $this->assertInstanceOf(CaveatedFigure::class, $tile);
        $this->assertNotSame([], $tile->caveats(), 'premise: the tile carries a caveat to look for');

        $response = $this->actingAs(User::find($merchant->id))->get('/analytics/export');

        $this->assertSame(200, $response->status(), 'the export did not run: ' . $response->status());

        $body = $this->plain((string) $response->getContent());

        foreach ($tile->caveats() as $index => $caveat) {
            $this->assertStringContainsString(
                $this->plain($tile->caveatSentences()[$index]),
                $body,
                "the analytics export drops {$caveat} from the Coupon discounts given row, so the "
                . 'spreadsheet is less honest than the page it came from'
            );
        }
    }
}
