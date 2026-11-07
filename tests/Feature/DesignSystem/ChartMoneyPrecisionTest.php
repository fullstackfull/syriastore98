<?php

namespace Tests\Feature\DesignSystem;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;
use Tests\TestCase;

/**
 * D-42.3 — THE CHART TABLES WERE NOT SHOWING MONEY.
 * =============================================================================================
 *
 * `<x-ss.bar-chart>` is the accessible representation of five money charts: the table IS the
 * content and the bars are a picture of it. The table was printing a number that was not the
 * merchant's money, in TWO different ways, and the five caller files and the component were each
 * half of it.
 *
 * ONE: TRUNCATION. `resources/views/components/ss/bar-chart.blade.php` cast every value with
 * `array_map('intval', …)` before rendering, and the table then printed `number_format($v)` with
 * no decimal places. Five caller files cast AGAIN with the same `array_map('intval', …)` before
 * passing — eleven sites, not nine; the brief for this work listed nine and missed
 * `order_product_chart.blade.php:43` and `order_bar_chart_data.blade.php:43`. MEASURED on the real
 * route `/product-order-reports`: a single order worth 1234.56 rendered as `1,234`. The component's
 * own cast is the defect that matters — removing the callers' wrapper alone left :67-68 truncating.
 *
 * TWO, AND WORSE: FABRICATION. `order_category_chart` and `order_brand_chart` are not handed a
 * flat numeric series at all. `ReportController::order_category_reports()` and
 * `order_brand_reports()` build `$NetSaleTotal` as an APEXCHARTS SERIES LIST —
 * `[['name' => 'Shirts', 'data' => [...]], ['name' => 'All Categories', 'data' => [...]]]` — one
 * entry per CATEGORY, while `$labels` is one entry per DAY. `intval()` of a non-empty array is
 * `1`, so every row of those two tables read `1` whatever the money was, paired with a day label
 * that belongs to a different axis. MEASURED on `/category-order-reports` with one order worth
 * 1234.56: the table read `01-Nov | 1`, `02-Nov | 1`, `Total | 2`. The brief for this work
 * diagnosed that `1` as "a fractional amount truncated"; it is not, and the difference matters —
 * truncation loses the cents, this loses the entire figure.
 *
 * WHAT THIS CHANGE DOES AND DOES NOT DO. The component now carries faithful numbers and formats
 * money with the project's established rule (`number_format($x, 2)`, as on the finance screen and
 * in these very files' own stat cards). It REFUSES to turn a non-numeric value into a number, so
 * the two nested callers get the honest empty state instead of a fabricated `1`. It does NOT
 * invent an aggregation rule to rescue the nested shape: deciding what those two charts should
 * plot is a controller fix (`$allCategoriesTotal` and `$totalNetSalesAllBrands` are already
 * computed there and simply never passed), and that is outside this display-only authorisation.
 *
 * WHICH ROUTES ARE REAL HERE. Four of the five callers are driven END TO END through their real
 * routes, against real rows. The fifth, `order_bar_chart_data`, cannot be: its handler
 * `order_reports_chart_data()` builds its series with `MONTH(order_date)`, which SQLite does not
 * have, so `/order-reports-chart-data` returns 500 in this suite on every window — MEASURED on
 * `this-month` and `seven-day`. It is rendered as a partial through a route instead, which is the
 * pattern `SaleByReportHonestyTest` established for the same reason.
 */
class ChartMoneyPrecisionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The pre-change rendering of a COUNT series, captured by running the component before the
     * repair. Six consumers of this component pass counts, not money —
     * `dashboard/overview.blade.php`, `reports/orderStatusReport`, `reports/top_5_reports` (four
     * charts), `reports/chart_data` (two) and `reports/order_country_Report` — and none of them is
     * mine to change. A money fix that shifted a count by one character would regress all six, and
     * only a browser run would notice. So the no-change requirement is asserted as a hash.
     */
    private const COUNT_BASELINE_SINGLE = 'e2c91b55113e8e178ec1ea2a210b33b8';
    private const COUNT_BASELINE_DOUBLE = '5ed8605e3c38a33689103f4f39156e90';
    private const COUNT_BASELINE_STRINGS = '51289ba66576d456da3d6df772404d34';

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Precision Store', 'email' => 'precision@example.test',
            'slug' => 'precision-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  Fixtures
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * One order, one product, one category AND ONE BRAND, for a stated money amount.
     *
     * THE BRAND IS NOT DECORATION. `order_brand_reports()` matches on
     * `$product_data->brand_id == $brandId`, so a product with no brand contributes to no brand
     * series and the brand chart's honest answer is 0.00 in every row. That is exactly what the
     * repaired chart rendered when this fixture had no brand -- and it is the measurement that
     * shows how empty the old reading was: the pre-repair table printed `1` in every row for a
     * product that belonged to no brand at all. A fixture that cannot produce a non-zero brand
     * figure cannot tell a working brand chart from a broken one.
     */
    private function orderWorth(float $amount): Product
    {
        $category = Category::forceCreate([
            'name' => 'Shirts', 'slug' => 'shirts-' . uniqid(), 'status' => 1,
            'store_id' => $this->store->id, 'parent_id' => 0,
        ]);
        $brand = ProductBrand::forceCreate([
            'name' => 'Northwind', 'slug' => 'northwind-' . uniqid(), 'status' => 1,
            'store_id' => $this->store->id, 'created_by' => $this->merchant->id,
        ]);
        $product = Product::forceCreate([
            'name' => 'Blue Shirt', 'slug' => Str::slug('blue shirt') . '-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'price' => $amount, 'sale_price' => $amount, 'category_id' => $category->id,
            'brand_id' => $brand->id,
            'store_id' => $this->store->id, 'created_by' => $this->merchant->id,
        ]);

        /* `orders` has no `order_id` column — it is `product_order_id`. forceCreate, because the
           money columns are not all in $fillable and Laravel discards an unfillable key in
           silence, which is the trap this model's own $fillable comments record twice. */
        Order::forceCreate([
            'product_order_id' => 'D423-' . uniqid(),
            'store_id' => $this->store->id,
            'order_date' => now()->toDateString(),
            'customer_id' => 0, 'is_guest' => 0,
            'final_price' => $amount, 'delivery_price' => 0, 'tax_price' => 0, 'coupon_price' => 0,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'qty' => 1, 'final_price' => $amount,
            ]]),
        ]);

        return $product;
    }

    /** @return array{0:int,1:array<mixed>,2:string} status, decoded json, html */
    private function driveRoute(string $uri): array
    {
        $response = $this->actingAs($this->merchant)->get($uri);
        $status = $response->getStatusCode();
        $body = $response->getContent();
        $json = json_decode($body, true);

        return [$status, is_array($json) ? $json : [], is_array($json) ? ($json['html'] ?? '') : $body];
    }

    /** Every numeric cell of every chart table, in document order. */
    private function cells(string $html): array
    {
        preg_match_all('#<td class="ss-table__num ss-num">\s*([^<]*?)\s*</td>#', $html, $m);

        return $m[1];
    }

    private function renderChart(array $props): string
    {
        return view('components.ss.bar-chart', $props + [
            'attributes' => new ComponentAttributeBag([]),
        ])->render();
    }

    /** The admin layout needs `Request::route()`, so a partial is rendered through a route. */
    private function partial(string $view, array $data): string
    {
        Route::get('/__d423_partial', fn () => view($view, $data))->middleware(['web', 'auth']);

        return $this->actingAs($this->merchant)->get('/__d423_partial')->assertOk()->getContent();
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  THE REAL ROUTES — truncation
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `/product-order-reports` → `reports.order_product_chart`. Series A is money, series B is a
     * unit count, in ONE table — so this also proves the two are formatted differently.
     */
    public function test_the_product_report_route_shows_the_cents_it_was_given(): void
    {
        $this->orderWorth(1234.56);

        [$status, $json, $html] = $this->driveRoute('/product-order-reports?chart_data=this-month');

        /* PREMISES. A 404 or a 500 satisfies "the wrong number is absent" on its own, and a
           handler that returned an empty series would too. */
        $this->assertSame(200, $status, 'Premise: the route must actually run.');
        $this->assertContains(1234.56, $json['NetSaleTotal'] ?? [],
            'Premise: the handler must have put the real amount in the series.');
        $this->assertContains(1, $json['PurchasedItemTotal'] ?? [],
            'Premise: the unit COUNT series must be there too, or the count assertion is vacuous.');
        $this->assertStringContainsString('<table', $html, 'Premise: the chart table must render.');

        $cells = $this->cells($html);
        $this->assertContains('1,234.56', $cells,
            'the money cell must carry the cents the handler computed');
        $this->assertNotContains('1,234', $cells,
            'intval truncation: 1234.56 was being printed as 1,234');

        /* The COUNT in the same table keeps whole units. "1.00 items ordered" would be a new
           defect introduced by the fix for this one. */
        $this->assertContains('1', $cells, 'the unit count must stay a whole number');
        $this->assertNotContains('1.00', $cells, 'a count must not grow decimal places');
    }

    /**
     * `/order-reports-chart?chart_data=seven-day` → `reports.order_chart_data`. The only window of
     * that handler that runs on SQLite: `last-month` and `this-month` both use
     * `selectRaw('… MONTH(order_date) …')` and return 500 here.
     */
    public function test_the_sales_report_route_shows_the_cents_it_was_given(): void
    {
        $this->orderWorth(1234.56);

        [$status, $json, $html] = $this->driveRoute('/order-reports-chart?chart_data=seven-day');

        $this->assertSame(200, $status, 'Premise: the route must actually run.');
        $this->assertContains(1234.56, $json['GrossSaleTotal'] ?? [],
            'Premise: the handler must have put the real amount in the gross series.');
        $this->assertContains(1234.56, $json['NetSaleTotal'] ?? [],
            'Premise: and in the net series.');
        $this->assertSame(2, substr_count($html, '<table'),
            'Premise: both charts on this body must render their tables.');

        $cells = $this->cells($html);
        $this->assertContains('1,234.56', $cells,
            'the money cell must carry the cents the handler computed');
        $this->assertNotContains('1,234', $cells,
            'intval truncation: 1234.56 was being printed as 1,234');
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  THE REAL ROUTES — fabrication
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `/category-order-reports` and `/brand-order-reports` USED TO READ `1` IN EVERY ROW. Each
     * handler builds `$NetSaleTotal` as an ApexCharts series LIST -- one `{name, data}` entry per
     * CATEGORY or BRAND -- while the chart's table is one row per PERIOD, and `intval()` of a
     * non-empty array is 1. MEASURED before the repair on an order worth 1234.56:
     * `01-Nov | 1`, `02-Nov | 1`, `Total | 2`, the last of those being the NUMBER OF SERIES
     * printed as the merchant's total.
     *
     * RE-POINTED, exactly as the first draft of this test said it must be. It asserted the honest
     * empty state, under a premise that read: "this test exists because the series entries are
     * ARRAYS. If the controller now returns a flat numeric series, assert the real amount
     * instead." The controller now passes the per-period total it was ALREADY computing and
     * throwing away (`$allCategoriesTotal` / `$totalNetSalesAllBrands`), so the real amount is
     * what this asserts. An empty state was honest but it was not the merchant's money, and a
     * chart screen with nothing on it is not the deliverable.
     *
     * TWO HALVES, AND BOTH ARE LOAD-BEARING. The table must show the money, and the JSON
     * `NetSaleTotal` must still be the untouched nested shape the browser's ApexCharts reads --
     * the repair is display-only, so the response contract may not move. The component-level
     * guard that a nested series is never coerced into a number is NOT retired with this case;
     * it lives in `test_a_non_numeric_series_is_not_turned_into_a_number`, which drives this very
     * shape straight into the component.
     *
     * @dataProvider nestedSeriesRoutes
     */
    public function test_a_nested_series_route_shows_the_real_money(string $uri): void
    {
        $this->orderWorth(1234.56);

        [$status, $json, $html] = $this->driveRoute($uri);

        $this->assertSame(200, $status, 'Premise: the route must actually run.');

        /* PREMISE: the shape really is nested. If a later controller fix flattens it, this test
           must be re-pointed rather than quietly passing for a new reason. */
        $series = $json['NetSaleTotal'] ?? null;
        $this->assertIsArray($series);
        $this->assertNotEmpty($series, 'Premise: the handler must have returned a series.');
        $this->assertIsArray($series[0] ?? null,
            'Premise: this test exists because the series entries are ARRAYS. If the controller '
            . 'now returns a flat numeric series, assert the real amount instead.');
        $this->assertArrayHasKey('data', $series[0],
            'Premise: the entries must be the {name, data} ApexCharts shape.');

        /* THE TABLE CARRIES THE MONEY. `1,234.56` must appear as a cell, and `1` must not: the
           defect's signature is a figure derived from an array's non-emptiness, and it would
           render as `1.00` once the component formats money — which an `assertNotContains('1')`
           on the raw string would be perfectly happy with. So both are asserted by exact cell
           value, and the bite is what proves it: restoring the cast turns these cells into
           `1.00`, which `assertNotContains('1.00', …)` catches. */
        $cells = $this->cells($html);

        $this->assertNotSame([], $cells,
            'the chart must render its table: an empty state here means the per-period total is '
            . 'not reaching the view, which is the whole repair');
        $this->assertContains('1,234.56', $cells,
            'the period holding the order must show the order\'s real value. cells: '
            . json_encode($cells));
        $this->assertNotContains('1.00', $cells,
            'a cell of `1.00` is `intval(array)` formatted as money — the fabrication, not the '
            . 'money. cells: ' . json_encode($cells));
        $this->assertNotContains('1', $cells,
            'nor the unformatted fabrication. cells: ' . json_encode($cells));
        $this->assertSame('1,234.56', $cells[count($cells) - 1],
            'and the Total row must be the money total, not the number of series. cells: '
            . json_encode($cells));
    }

    /**
     * THE PERIODS THAT CONTAIN THE FIXTURE'S ORDER, on both routes. The order is dated today, so
     * `last-month` and the custom range legitimately hold nothing; they are driven by
     * `test_a_period_with_no_orders_shows_zero_not_one` instead, because asserting 1,234.56 there
     * would be asserting a figure that should not exist.
     *
     * THREE PERIODS AND NOT ONE, because the per-period total the controller now passes is held
     * under TWO DIFFERENT NAMES and no single branch proves the others. Measured by reading every
     * branch rather than assuming the author was consistent:
     *
     *   category  last-month / this-month / seven-day / range -> $allCategoriesTotal
     *   category  year                                        -> $totalNetSalesAllCategories
     *   brand     last-month / this-month                     -> $allBrandsTotal
     *   brand     year / seven-day / range                    -> $totalNetSalesAllBrands
     *
     * So the resolution is `$allXTotal ?? $totalNetSalesAllX ?? []`, and a period driven under the
     * wrong name renders the empty state, which these cases fail on. `brand year` is the case that
     * found the double-count below. A `chart_data` with no recognised value falls to the handler's
     * `else`, the custom-range branch, which is how that fifth path is reached without inventing a
     * date format.
     */
    public static function nestedSeriesRoutes(): array
    {
        $cases = [];
        foreach (['category', 'brand'] as $kind) {
            foreach (['this-month', 'seven-day', 'year'] as $period) {
                $cases["{$kind} {$period}"] = ["/{$kind}-order-reports?chart_data={$period}"];
            }
        }

        return $cases;
    }

    /**
     * THE AGGREGATE IS THE SUM, NOT TWICE THE SUM. `order_brand_reports()`'s `year` branch
     * accumulated `$totalNetSalesAllBrands` inside the month-by-brand loop AND array_mapped every
     * brand's series onto it again afterwards, so the figure was exactly double. MEASURED with one
     * order worth 1234.56: the aggregate read `2,469.12`. The `year` branch was the only one of the
     * five with both adds; the other four have the post-loop array_map alone and read correctly,
     * which is why the in-loop line is the one that was removed.
     *
     * IT WAS WRONG ON THE LIVE SCREEN BEFORE ANY OF THIS. The doubled array is what the handler
     * sends to the browser's ApexCharts as `All Brands`, and what `$NetSalesofbrand['All Brands']`
     * sums for the card above the chart. The accessible table printed `1` in every row, which is
     * what hid it for as long as it was hidden.
     *
     * ALL FIVE PERIODS OF BOTH ROUTES are driven, so a later "consistency" edit that puts the
     * second add back on any sibling branch reddens here.
     *
     * @dataProvider everyChartPeriod
     */
    public function test_the_aggregate_is_the_sum_and_never_double(string $kind, string $period): void
    {
        $this->orderWorth(1234.56);

        [$status, $json, $html] = $this->driveRoute("/{$kind}-order-reports?chart_data={$period}");
        $this->assertSame(200, $status, 'Premise: the route must actually run.');

        $cells = $this->cells($html);
        $numeric = array_map(static fn ($c) => (float) str_replace(',', '', $c), $cells);

        $this->assertNotContains(2469.12, $numeric,
            "{$kind}/{$period} reports twice the money: the aggregate is accumulated in the loop "
            . 'AND array_mapped afterwards. cells: ' . json_encode($cells));

        /* The last cell is the tfoot Total and the ones before it are the periods, so a
           double-count shows in either. Both are pinned, and the fixture holds exactly one order,
           so the rows cannot legitimately sum past it. */
        if ($cells !== []) {
            $rows = array_slice($numeric, 0, -1);
            $this->assertEqualsWithDelta(
                array_sum($rows),
                $numeric[count($numeric) - 1],
                0.005,
                "{$kind}/{$period}: the Total row must be the sum of the rows above it. cells: "
                . json_encode($cells)
            );
            $this->assertLessThanOrEqual(
                1234.565,
                array_sum($rows),
                "{$kind}/{$period} totals more than the only order in the fixture. cells: "
                . json_encode($cells)
            );
        }
    }

    /**
     * A PERIOD WITH NO ORDERS MUST READ ZERO, NOT `1` -- the route-level half of the fabrication
     * guard. `last-month` and the custom range hold none of the fixture's money, and before the
     * repair they still printed `1` in every row, because `intval()` of a non-empty array is 1.
     * Zero and one are the two readings a merchant cannot tell apart by eye, so this is the case
     * that says which one it is.
     *
     * @dataProvider emptyChartPeriods
     */
    public function test_a_period_with_no_orders_shows_zero_not_one(string $kind, string $period): void
    {
        $this->orderWorth(1234.56);

        [$status, $json, $html] = $this->driveRoute("/{$kind}-order-reports?chart_data={$period}");
        $this->assertSame(200, $status, 'Premise: the route must actually run.');

        $cells = $this->cells($html);

        /* PREMISE, AND IT IS THE WHOLE TEST. Without it this case passes VACUOUSLY: a `foreach`
           over no cells asserts nothing, and the bite proved it -- with the nested list restored
           the component renders no table at all and these four cases stayed green while the three
           money cases reddened. So the periods the handler gave labels for MUST render a cell per
           label plus the Total row. The custom range legitimately produces no labels at all on
           `category` (no dates were posted), and a chart with nothing to plot is allowed to have
           no table; it is `last-month`, with its full month of labels, that carries the weight. */
        $labels = count($json['monthList'] ?? []);
        if ($labels > 0) {
            $this->assertCount($labels + 1, $cells,
                "{$kind}/{$period}: the handler produced {$labels} periods, so the table must "
                . 'carry one cell each plus the Total row. An empty table cannot show a zero.');
        } else {
            $this->assertSame([], $cells,
                "{$kind}/{$period}: no periods, so no numeric cells either.");
        }

        foreach ($cells as $cell) {
            $this->assertSame('0.00', $cell,
                "{$kind}/{$period} holds none of the fixture's money, so every cell must read "
                . '0.00. A `1` or `1.00` is the array-non-emptiness fabrication.');
        }
    }

    public static function everyChartPeriod(): array
    {
        $cases = [];
        foreach (['category', 'brand'] as $kind) {
            foreach (['last-month', 'this-month', 'seven-day', 'year', 'range'] as $period) {
                $cases["{$kind} {$period}"] = [$kind, $period];
            }
        }

        return $cases;
    }

    public static function emptyChartPeriods(): array
    {
        $cases = [];
        foreach (['category', 'brand'] as $kind) {
            foreach (['last-month', 'range'] as $period) {
                $cases["{$kind} {$period}"] = [$kind, $period];
            }
        }

        return $cases;
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  The fifth caller, which its own route cannot reach on SQLite
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `order_bar_chart_data` carries two charts: COUNTS (orders, units) and MONEY (gross, net).
     * Both in one body, so this is where a fix that formatted everything the same way shows up.
     */
    public function test_the_bar_chart_body_formats_money_and_counts_differently(): void
    {
        $html = $this->partial('reports.order_bar_chart_data', [
            'currency' => '$', 'TotalgrossSale' => '120.00', 'TotalNetSale' => '100.00',
            'TotalOrder' => 7, 'PurchasedProductItemTotal' => 9,
            'TotalShippingCharge' => '5.00', 'TotalCouponAmount' => '20.00',
            'periodLabel' => 'This year (2026)',
            'labels' => ['21-Oct'],
            'orderSeries' => [7], 'itemSeries' => [9],
            'grossSeries' => [120.5], 'netSeries' => [100.25],
        ]);

        $this->assertSame(2, substr_count($html, '<table'), 'Premise: both charts must render.');

        $cells = $this->cells($html);
        $this->assertContains('7', $cells, 'the order COUNT must stay whole');
        $this->assertContains('9', $cells, 'the unit COUNT must stay whole');
        $this->assertNotContains('7.00', $cells, 'a count must not grow decimal places');
        $this->assertContains('120.50', $cells, 'the gross money figure must keep its cents');
        $this->assertContains('100.25', $cells, 'the net money figure must keep its cents');
        $this->assertNotContains('120', $cells, 'intval truncation on the gross series');
        $this->assertNotContains('100', $cells, 'intval truncation on the net series');
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  The component's own contract — the four things the repair had to not break
    // ────────────────────────────────────────────────────────────────────────────────────────

    /** A COUNT series must render byte for byte as it did before the repair. */
    public function test_a_count_series_renders_byte_for_byte_as_before(): void
    {
        $single = $this->renderChart([
            'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'values' => [3, 0, 7, 1, 0, 12, 4],
            'title' => 'Orders per day', 'valueLabel' => 'Orders', 'period' => 'last 7 days',
        ]);
        $this->assertSame(self::COUNT_BASELINE_SINGLE, md5($single),
            'the dashboard chart and five count-only report charts changed');

        $double = $this->renderChart([
            'labels' => ['Jan', 'Feb'], 'values' => [3, 0], 'values2' => [1, 4],
            'title' => 'Orders', 'valueLabel' => 'Customers', 'valueLabel2' => 'Guests',
            'columnLabel' => 'Month', 'period' => 'this year',
        ]);
        $this->assertSame(self::COUNT_BASELINE_DOUBLE, md5($double),
            'the customer report\'s two comparison charts changed');

        /* `pluck()` off a database can return numeric STRINGS, and four consumers pass pluck()
           results straight in. `intval` used to flatten those; a faithful normaliser must still
           render them as whole numbers. */
        $strings = $this->renderChart([
            'labels' => ['A', 'B'], 'values' => ['7', '0'],
            'title' => 'Orders', 'valueLabel' => 'Orders', 'period' => 'all time',
        ]);
        $this->assertSame(self::COUNT_BASELINE_STRINGS, md5($strings),
            'a numeric-string count series changed; top_5_reports and the country report pass '
            . 'pluck() results directly');
    }

    /** THE min() PAIRING. Three disagreeing arrays must narrow, not mis-pair, with decimals too. */
    public function test_the_pairing_still_narrows_with_fractional_values(): void
    {
        $html = $this->renderChart([
            'labels' => ['Jan', 'Feb', 'Mar'],
            'values' => [1.5, 2.5, 3.5], 'values2' => [4.25],
            'valueDecimals' => 2, 'valueDecimals2' => 2,
            'title' => 'Order value', 'valueLabel' => 'A', 'valueLabel2' => 'B',
            'period' => 'this year',
        ]);

        $this->assertSame(1, substr_count($html, 'ss-chart__col'),
            'only the columns ALL series have may be drawn');
        $this->assertStringNotContainsString('>Feb<', $html);
        $this->assertStringNotContainsString('>Mar<', $html);
        $cells = $this->cells($html);
        $this->assertContains('1.50', $cells, 'Premise: the surviving column renders.');
        $this->assertNotContains('2.50', $cells, 'a narrowed column must not leak into the table');
    }

    /** ONE SCALE FOR BOTH SERIES. The stack maximum, not each series' own, with decimals. */
    public function test_both_series_still_share_one_scale_with_fractional_values(): void
    {
        /* Jan 3.0 + 1.0 = 4.0, Feb 0.0 + 4.0 = 4.0. Stack max 4.0, so Jan's first bar is 75%. */
        $html = $this->renderChart([
            'labels' => ['Jan', 'Feb'],
            'values' => [3.0, 0.0], 'values2' => [1.0, 4.0],
            'valueDecimals' => 2, 'valueDecimals2' => 2,
            'title' => 'Order value', 'valueLabel' => 'A', 'valueLabel2' => 'B',
            'period' => 'this year',
        ]);

        $this->assertStringContainsString('block-size: 75%', $html,
            'the first series is not scaled against the STACKED maximum');
        $this->assertStringContainsString('block-size: 100%', $html,
            'the tallest stack must reach the top');
    }

    /** EMPTY IS NOT ZERO. Both halves of it. */
    public function test_empty_is_still_not_zero(): void
    {
        $empty = $this->renderChart([
            'labels' => [], 'values' => [], 'title' => 'Order value', 'period' => 'this year',
        ]);
        $this->assertStringNotContainsString('<table', $empty);
        $this->assertStringContainsString('No data for this period yet', $empty);

        $zeroes = $this->renderChart([
            'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'values' => [0, 0, 0, 0, 0, 0, 0], 'valueDecimals' => 2,
            'title' => 'Order value', 'valueLabel' => 'Order value', 'period' => 'last 7 days',
        ]);
        $this->assertStringNotContainsString('No data for this period yet', $zeroes,
            'seven zeroes is a real answer and must not be reported as no data');
        $this->assertSame(7, substr_count($zeroes, 'ss-chart__col'),
            'seven zeroes must draw seven zero bars');
        /* SEVEN, not eight. The first draft of this line said eight and went red against a
           CORRECT component — the same trap `BarChartComponentTest` records twice in its own
           comments. The <tfoot> total is a cell but not a bar, so only the seven drawn bars
           carry the marker. MEASURED: substr_count is 7 before and after the repair. */
        $this->assertSame(7, substr_count($zeroes, 'ss-chart__bar--zero'),
            'a float 0.0 is not === int 0, so a faithful normaliser must still mark a zero bar');
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  What the owner asked to be tested
    // ────────────────────────────────────────────────────────────────────────────────────────

    /** Fractional, negative and zero, in one money series, with the total over all three. */
    public function test_fractional_negative_and_zero_money_are_all_shown_faithfully(): void
    {
        $html = $this->renderChart([
            'labels' => ['Mon', 'Tue', 'Wed'],
            'values' => [1234.56, -45.5, 0.0],
            'valueDecimals' => 2,
            'title' => 'Order value', 'valueLabel' => 'Order value', 'period' => 'last 7 days',
        ]);

        $this->assertStringContainsString('<table', $html, 'Premise: the table must render.');

        $cells = $this->cells($html);
        $this->assertContains('1,234.56', $cells, 'the cents must survive');
        $this->assertContains('-45.50', $cells,
            'a negative amount must be shown as the negative it is, not clamped or dropped');
        $this->assertContains('0.00', $cells,
            'a zero money figure reads 0.00 in a money column, not a bare 0');
        /* 1234.56 - 45.5 + 0 = 1189.06. */
        $this->assertContains('1,189.06', $cells, 'the total must be the real sum');

        /* THE BARS. A negative height is not expressible in this markup, so the bar is clamped
           to the baseline and the table carries the truth. What must NOT happen is a negative
           `block-size`, which browsers discard, leaving the bar at its auto height — a LOSS
           drawn as tall as a gain. */
        $this->assertStringNotContainsString('block-size: -', $html,
            'a negative bar height is invalid CSS and renders as a full-height bar');
        $this->assertStringContainsString('block-size: 100%', $html,
            'the largest positive value must still reach the top');
    }

    /** A value the component cannot read must not become a number. */
    public function test_a_non_numeric_series_is_not_turned_into_a_number(): void
    {
        /* The exact shape `ReportController::order_category_reports()` passes. */
        $html = $this->renderChart([
            'labels' => ['01-Nov', '02-Nov'],
            'values' => [
                ['name' => 'Shirts', 'data' => [1234.56, 0]],
                ['name' => 'All Categories', 'data' => [1234.56, 0]],
            ],
            'valueDecimals' => 2,
            'title' => 'Category Sales Summary', 'valueLabel' => 'Order value',
            'period' => 'last 7 days',
        ]);

        $this->assertStringNotContainsString('<table', $html,
            'a series the component cannot read must not be rendered as a table of numbers');
        $this->assertStringNotContainsString('>1</td>', $html,
            'intval(array) === 1 was being printed as the merchant\'s money');
        $this->assertStringContainsString('ss-empty', $html,
            'and the card must say something rather than render an empty frame');
    }

    /**
     * MORE THAN ONE SUPPORTED CURRENCY. The component is handed numbers, never a symbol — the
     * symbol lives on the card and the stat beside it — so what must hold across currencies is
     * that the PRECISION does not change with the symbol. A currency whose amounts are large
     * (Syrian pounds) and one whose amounts are small (dollars) must both keep two places, and
     * the thousands grouping must not eat a digit.
     */
    public function test_precision_is_the_same_for_every_supported_currency(): void
    {
        foreach ([['$', 1234.56, '1,234.56'], ['ل.س', 1284500.75, '1,284,500.75']] as [$symbol, $amount, $expected]) {
            $html = $this->renderChart([
                'labels' => ['01-Nov'], 'values' => [$amount], 'valueDecimals' => 2,
                'title' => 'Order value ' . $symbol, 'valueLabel' => 'Order value',
                'period' => 'last 7 days',
            ]);

            $cells = $this->cells($html);
            $this->assertContains($expected, $cells,
                "$symbol amounts lost precision or grouping");
        }
    }

    /**
     * ARABIC. The figure must be the same figure, and it must stay a single left-to-right run.
     * `.ss-num` carries `unicode-bidi: isolate` for exactly this, and the repair must keep the
     * class on the cell — a decimal point and a thousands comma are both bidi-neutral, so a
     * money figure in an Arabic line reorders without it.
     */
    public function test_arabic_renders_the_same_figure_and_keeps_the_bidi_isolation(): void
    {
        $previous = app()->getLocale();

        try {
            app()->setLocale('ar');

            $html = $this->renderChart([
                'labels' => ['01-Nov'], 'values' => [1234.56], 'valueDecimals' => 2,
                'title' => __('Order value'), 'valueLabel' => __('Order value'),
                'period' => 'last 7 days',
            ]);

            /* PREMISE: this really is the Arabic rendering, not English with the locale set. */
            $this->assertStringContainsString('قيمة الطلبات', $html,
                'Premise: the Arabic translation must actually be in use.');
            $this->assertStringContainsString('عرض الأرقام', $html,
                'Premise: the component\'s own strings must be translated too.');

            $this->assertStringContainsString('<td class="ss-table__num ss-num">', $html,
                'the money cell must keep ss-num, which is what isolates the figure in RTL');
            $this->assertContains('1,234.56', $this->cells($html),
                'the Arabic rendering must show the same amount to the same precision');
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * ENGLISH, the same figure. Paired with the Arabic case deliberately: a formatter that read
     * the locale would pass one of the two and fail the other, and this project's rule is one
     * money format (`number_format($x, 2)`) in both directions.
     */
    public function test_english_renders_the_same_figure(): void
    {
        $previous = app()->getLocale();

        try {
            app()->setLocale('en');

            $html = $this->renderChart([
                'labels' => ['01-Nov'], 'values' => [1234.56], 'valueDecimals' => 2,
                'title' => __('Order value'), 'valueLabel' => __('Order value'),
                'period' => 'last 7 days',
            ]);

            $this->assertStringContainsString('Show the numbers', $html,
                'Premise: this must be the English rendering.');
            $this->assertContains('1,234.56', $this->cells($html),
                'the English rendering must show the same amount to the same precision');
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * THE STAT CARD BESIDE THE CHART PRINTED A RAW FLOAT.
     * =========================================================================================
     *
     * `order_product_chart.blade.php` rendered `$currency . (!empty($Totalsale) ? $Totalsale :
     * '0.00')` -- the float itself, with no `number_format`. `$Totalsale` is accumulated in
     * `ReportController::order_product_reports()` as `+= (float) $product['final_price']`, and
     * PHP echoes a float with its trailing zeroes GONE: MEASURED, `0.1 + 0.2` echoes as `0.3`,
     * so thirty cents read `$0.3` and 59.90 read `$59.9`, beside a chart whose money column is
     * formatted properly.
     *
     * The same rule fixes it as fixes the chart: `number_format($x, 2)`, which is what the
     * finance screen uses throughout and what `order_category_chart` and `order_brand_chart`
     * already use for this very quantity. Those two were correct all along, which is why this
     * one stood out.
     *
     * NOT FIXED, AND REPORTED: `reports/_sales_stats.blade.php` carries the identical pattern at
     * FOUR money sites (`$TotalgrossSale`, `$TotalNetSale`, `$TotalShippingCharge`,
     * `$TotalCouponAmount`). It is a shared partial outside this change's file ownership, and
     * both order-report bodies include it, so the same `$0.3` is on those screens too.
     *
     * RE-ADDED after a concurrent agent's rewrite of this file dropped it; the source fix in
     * `order_product_chart.blade.php` had survived, so the line was live but untested.
     */
    public function test_the_product_stat_card_formats_its_money_figure(): void
    {
        /* The value a float sum really arrives as -- not a pre-formatted string, which is what
           the pre-existing tests for this partial pass and why they never saw this. */
        $html = $this->partial('reports.order_product_chart', [
            'currency' => '$', 'Totalsale' => 0.1 + 0.2, 'PurchasedItem' => 3,
            'periodLabel' => 'Last 7 days',
        ]);

        $this->assertSame(2, substr_count($html, 'ss-stat__value'),
            'Premise: both figures on this card must render.');

        $tight = preg_replace('/\s+/', ' ', $html);
        $this->assertStringContainsString('$0.30', $tight,
            'a money figure must carry both decimal places; PHP echoes the float as "0.3"');
        $this->assertStringNotContainsString('$0.3 ', $tight,
            'the raw float was being printed, so thirty cents read "$0.3"');

        /* And the grouping, which is the other half of the established rule. */
        $grouped = preg_replace('/\s+/', ' ', $this->partial('reports.order_product_chart', [
            'currency' => '$', 'Totalsale' => 1284500.5, 'PurchasedItem' => 3,
            'periodLabel' => 'Last 7 days',
        ]));
        $this->assertStringContainsString('$1,284,500.50', $grouped,
            'the money rule is number_format($x, 2), grouping included -- as on the finance '
            . 'screen and in the two sibling sale-by partials');
    }
}
