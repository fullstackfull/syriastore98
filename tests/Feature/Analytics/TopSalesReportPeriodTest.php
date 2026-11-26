<?php

namespace Tests\Feature\Analytics;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\Store;
use App\Support\Analytics\AnalyticsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-002 / GAP-221 — THE FIFTH AND LAST OF THE ROW'S UNFILTERED REPORT SCREENS.
 * =================================================================================================
 *
 * `top_product()` read every order the store has ever taken and passed the view a hardcoded
 * `'periodLabel' => __('All time')` — the same placeholder the four screens before it carried: it
 * names the limitation without removing it, so a merchant cannot ask which products sold last
 * month. GAP-221 recorded this one as NOT BUILDABLE with suite evidence, because the screen could
 * not be executed by the suite at all (`SUBSTRING_INDEX`). `TopSalesReportSuiteReachabilityTest`
 * removed that blockage; this is the filter it was blocking.
 *
 * FOUR RANKINGS, ONE WINDOW, AND THAT IS THE WHOLE RISK ON THIS SCREEN. Products, categories,
 * brands and payment methods sit under one caption. Windowing some and not others would put a
 * February chart beside an all-time one and tell the merchant their catalogue had changed shape —
 * which is the confusion `showCountryOrderReport()` has now been fixed for twice, through two
 * different doors. So every test here asserts all four together, and the handler derives all four
 * from ONE order query rather than four.
 *
 * THE PERIOD IS OPT-IN, as on the other four. `AnalyticsPeriod::fromRequest([])` falls through to
 * `DEFAULT` (`last_30_days`), so resolving unconditionally would silently narrow every existing
 * reader's figure to thirty days without anyone asking — the same number quietly answering a
 * different question. With no `ss_period` the screen answers exactly as before, which the last
 * test pins.
 *
 * IT WINDOWS `orders.order_date`, not `created_at`: every other screen in this centre windows
 * `order_date`, and the merchant's own order list shows that column under the title "Date". A
 * period meaning a different thing on one screen of one centre is the defect this workstream
 * removes.
 */
class TopSalesReportPeriodTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /** @return array{0: \App\Models\User, 1: Store} */
    private function merchantWhoMayRead(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Reports');
        Cache::forget('admin_details');

        return [$merchant, $store];
    }

    /** @return array{category: int, brand: int, product: int} */
    private function catalogue(Store $store, string $label): array
    {
        $unique = $label . '-' . $store->id;

        $categoryId = Category::create([
            'name' => 'Cat ' . $unique, 'slug' => Str::slug('cat ' . $unique),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ])->id;

        $brandId = ProductBrand::create([
            'name' => 'Brand ' . $unique, 'slug' => Str::slug('brand ' . $unique),
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ])->id;

        $productId = Product::forceCreate([
            'name' => 'Prod ' . $unique, 'slug' => Str::slug('prod ' . $unique),
            'category_id' => $categoryId, 'brand_id' => $brandId,
            'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ])->id;

        return ['category' => $categoryId, 'brand' => $brandId, 'product' => $productId];
    }

    private function orderListing(Store $store, array $productIds, string $when, string $paymentType = 'cod'): Order
    {
        return Order::create([
            'product_order_id' => 'TOPP-' . strtoupper($this->uniqueFixtureRef()),
            'order_date' => now()->parse($when),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => '[]',
            'product_id' => implode(',', $productIds),
            'product_price' => 10,
            'coupon_price' => 0,
            'delivery_price' => 0,
            'tax_price' => 0,
            'final_price' => 10,
            'payment_type' => $paymentType,
            'payment_status' => 'Paid',
            'delivered_status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function openReport($merchant, array $query = [])
    {
        return $this->actingAs($merchant->fresh())
            ->get('/top-all-reports' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    /** @return array<string, int> */
    private function ranking($response, string $key): array
    {
        return collect($response->viewData($key))
            ->mapWithKeys(fn ($row) => [(string) $row->sale_name => (int) $row->total_sale])
            ->all();
    }

    /**
     * Three orders: one inside the window, two outside it on BOTH sides.
     *
     * A fixture whose out-of-window rows all sit BEFORE the window lets a mutation that drops the
     * upper bound survive -- measured on `showCountryOrderReport()`'s own slice, where exactly
     * that mutation passed all seven tests. One earlier and one later.
     *
     * @return array{category: int, brand: int, product: int}
     */
    private function threeOrdersAroundFebruary(Store $store): array
    {
        $made = $this->catalogue($store, 'win');

        $this->orderListing($store, [$made['product']], '2026-02-10 10:00:00');
        $this->orderListing($store, [$made['product']], '2025-06-01 10:00:00');
        $this->orderListing($store, [$made['product']], '2026-05-01 10:00:00');

        return $made;
    }

    private const FEBRUARY = [
        AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
        AnalyticsPeriod::KEY_FROM => '2026-02-01',
        AnalyticsPeriod::KEY_TO => '2026-02-28',
    ];

    public function test_a_requested_period_narrows_all_four_rankings_together(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        $made = $this->threeOrdersAroundFebruary($store);

        $response = $this->openReport($merchant, self::FEBRUARY);
        $response->assertOk();

        $this->assertSame(['Cat win-' . $store->id => 1], $this->ranking($response, 'top_sales'),
            'The category ranking counted every order the store has taken rather than the ones '
            . 'inside the requested period.');

        $this->assertSame(['Brand win-' . $store->id => 1], $this->ranking($response, 'top_brand_sales'),
            'The brand ranking does not follow the period, so it sits beside a windowed chart '
            . 'under one caption.');

        $this->assertSame([1], $response->viewData('productCounts'),
            'The product ranking does not follow the period: ' . json_encode($response->viewData('productCounts')));

        $this->assertSame(['cod' => 1], collect($response->viewData('paymentMethods'))->all(),
            'The payment-method ranking does not follow the period, so the four charts on this '
            . 'screen describe different periods.');
    }

    public function test_the_caption_follows_the_window(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        $this->threeOrdersAroundFebruary($store);

        $response = $this->openReport($merchant, self::FEBRUARY);
        $response->assertOk();

        $label = (string) $response->viewData('periodLabel');

        $this->assertNotSame(__('All time'), $label,
            'A windowed figure under a caption reading "All time" is a true number with a false '
            . 'label, which is the defect this workstream keeps fixing.');

        $this->assertStringContainsString('2026', $label,
            'The caption does not name the period it covers: ' . $label);
    }

    public function test_the_screen_offers_the_control_on_the_rendered_page(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        $this->threeOrdersAroundFebruary($store);

        $html = $this->openReport($merchant)->assertOk()->getContent();

        /* ON THE PAGE, NOT IN THE ARRAY. GAP-227(e1) records the sibling failure exactly: a
           comparison figure was computed, passed to a tile that never read it, and shipped with a
           green run having reached no merchant. A period the handler accepts and the screen does
           not offer is the same defect. */
        $this->assertStringContainsString('name="ss_period"', $html,
            'The handler accepts a period and the page offers no way to choose one, so the '
            . 'capability reaches no merchant.');

        $this->assertStringContainsString('<option value="" selected>' . __('All time') . '</option>', $html,
            'The unfiltered view must stay the first option and stay selected: it is the shipped '
            . 'default, and deleting it would make a thirty-day figure the only answer available. '
            . 'Asserted on the <option> element because the words "All time" appear in the chart '
            . 'captions too -- a page-wide search for them passes with the control deleted.');
    }

    public function test_the_control_posts_back_to_this_screen_and_not_to_another(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        $this->threeOrdersAroundFebruary($store);

        $html = $this->openReport($merchant)->assertOk()->getContent();

        $this->assertStringContainsString('action="' . route('reports.top_product') . '"', $html,
            'The period form posts somewhere other than this screen, so applying a period would '
            . 'take the merchant to a different report.');
    }

    public function test_with_no_period_requested_the_screen_answers_exactly_as_before(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        $this->threeOrdersAroundFebruary($store);

        $response = $this->openReport($merchant);
        $response->assertOk();

        $this->assertSame(['Cat win-' . $store->id => 3], $this->ranking($response, 'top_sales'),
            'With no `ss_period` the screen must cover all time, as it did before this filter '
            . 'existed. Resolving a period unconditionally would narrow every existing reader to '
            . 'thirty days without anyone asking.');

        $this->assertSame(__('All time'), (string) $response->viewData('periodLabel'),
            'The unfiltered caption must still say what the figure is.');
    }

    /**
     * AN ARABIC MERCHANT GETS AN ARABIC CONTROL — GAP-164's per-slice acceptance condition, as
     * executable evidence rather than a claim.
     *
     * The locale comes from the merchant's own `language` column and NOT from `app()->setLocale()`:
     * `SetLocate` re-resolves the locale per request, so a locale set in the test does not survive
     * one. That is recorded on two sibling report tests and is the trap this case would otherwise
     * fall into — it would pass against English.
     *
     * The strings are the shared component's, already in `ar.json` before this slice; this slice
     * introduces no new user-facing string at all. What the test proves is that the control this
     * screen now carries renders in Arabic rather than, say, under a hardcoded English label —
     * which is how four raw values reached the Arabic analytics screen (GAP-154).
     */
    public function test_an_arabic_merchant_reads_an_arabic_period_control(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        $this->threeOrdersAroundFebruary($store);

        $merchant->language = 'ar';
        $merchant->save();
        Cache::forget('admin_details');

        $html = $this->openReport($merchant->fresh())->assertOk()->getContent();

        foreach ([
            'الفترة' => 'Period',
            'كل الفترات' => 'All time',
            'تطبيق' => 'Apply',
            'من' => 'From',
            'إلى' => 'To',
        ] as $arabic => $english) {
            $this->assertStringContainsString($arabic, $html,
                'The period control shows the English "' . $english . '" to an Arabic merchant. '
                . 'Either the string is missing from ar.json or the label is hardcoded.');
        }
    }

    public function test_a_period_longer_than_the_cap_is_shortened_and_says_so(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        $this->threeOrdersAroundFebruary($store);

        $response = $this->openReport($merchant, [
            AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
            AnalyticsPeriod::KEY_FROM => '2010-01-01',
            AnalyticsPeriod::KEY_TO => now()->toDateString(),
        ]);

        $response->assertOk();

        $this->assertNotNull($response->viewData('periodClamped'),
            'A window the system quietly shortened is a figure that does not cover the period its '
            . 'own label names. `AnalyticsPeriod` discloses it through `clampNote()` and the screen '
            . 'must carry that note.');

        $this->assertStringContainsString((string) $response->viewData('periodClamped'),
            $response->getContent(),
            'The clamp note is in the view data and not on the page.');
    }
}
