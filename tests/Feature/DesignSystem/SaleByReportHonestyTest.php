<?php

namespace Tests\Feature\DesignSystem;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P2-05-6 / PRD-001 — "Total Net sale" was neither net nor a sale.
 * =============================================================================================
 *
 * The three sale-by partials printed money with no period, under labels the data does not
 * support. `$Totalsale`, `$NetSalesofcategory` and `$NetSalesofbrand` all sum `final_price` out
 * of each order's product JSON — the same quantity the dashboard labels ORDER VALUE and
 * qualifies "gross" (P2-02-5). It is not revenue, not cash collected, not net of anything and
 * not profit. "Total Net sale" and "Sales in X" each claimed otherwise, and each sat over a
 * figure that changes with the period button at the top of the page without saying which period
 * it was showing.
 *
 * WHY THESE RENDER THE PARTIAL RATHER THAN DRIVING THE ROUTE. The three handlers around them
 * build their series with MySQL-only date and string functions, so `/product-order-reports` and
 * its siblings cannot run on the suite's SQLite at all. The partials are plain Blade over data
 * the handler hands them, so they are rendered directly, through a route because the admin
 * layout needs `Request::route()`. What this cannot see is whether the handler passes the right
 * period — that is covered for all five windows in `tests/Unit/Ui/ReportPeriodLabelTest.php`,
 * which tests the one function all of them now call.
 */
class SaleByReportHonestyTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'name' => 'Sale Store', 'email' => 'sale@example.test',
            'slug' => 'sale-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();
    }

    private function partial(string $view, array $data): string
    {
        Route::get('/__sale_partial', fn () => view($view, $data))->middleware(['web', 'auth']);

        return $this->actingAs($this->merchant)->get('/__sale_partial')->assertOk()->getContent();
    }

    /** Every `ss-stat__period`, in order. */
    private function periods(string $html): array
    {
        preg_match_all('/<span class="ss-stat__period">\s*([^<]*?)\s*(?:<|$)/s', $html, $m);

        return array_values(array_filter(array_map('trim', $m[1] ?? [])));
    }

    public function test_the_product_partial_states_its_period_and_claims_nothing_it_cannot(): void
    {
        $html = $this->partial('reports.order_product_chart', [
            'currency' => '$', 'Totalsale' => '1234.00', 'PurchasedItem' => 7,
            'periodLabel' => 'Last 7 days',
        ]);

        $this->assertSame(2, substr_count($html, 'ss-stat__value'), 'Premise: both figures render.');
        $this->assertSame(['Last 7 days', 'Last 7 days'], $this->periods($html),
            'a money figure with no period is a figure the reader cannot interpret');

        $this->assertStringNotContainsString('Total Net sale', $html,
            'this sums final_price out of the order JSON; it is neither net nor a sale');
        $this->assertStringContainsString('Order value', $html,
            'the dashboard settled this wording in P2-02-5 and this screen must not invent a '
            . 'second vocabulary for the same number');
        $this->assertStringContainsString('This is not payments received, and not profit.', $html);
    }

    /** @dataProvider groupedPartials */
    public function test_each_grouped_partial_states_a_period_on_every_figure(
        string $view, string $key, string $expectLabel
    ): void {
        $html = $this->partial($view, [
            'currency' => '$',
            $key => ['Shoes' => 100.5, 'Hats' => 20.0],
            'periodLabel' => 'This year (2026)',
        ]);

        $this->assertSame(2, substr_count($html, 'ss-stat__value'), 'Premise: both rows render.');
        $this->assertSame(['This year (2026)', 'This year (2026)'], $this->periods($html));

        $this->assertStringNotContainsString('Sales in', $html,
            '"Sales in X" claims cash this figure is not');
        $this->assertStringContainsString($expectLabel, $html);
        $this->assertStringContainsString('Shoes', $html, 'Premise: the group names render.');
        $this->assertStringContainsString('100.50', $html, 'Premise: the amounts render.');
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function groupedPartials(): array
    {
        return [
            'category' => ['reports.order_category_chart', 'NetSalesofcategory', 'Order value by category'],
            'brand' => ['reports.order_brand_chart', 'NetSalesofbrand', 'Order value by brand'],
        ];
    }

    /**
     * THE CHART SHIPS ITS NUMBERS. The series the handler already returned to the browser now
     * reach the view, so each of these three charts is a real table rather than an ApexCharts
     * container with nothing behind it — which is what they were when this file was first
     * written, and what its earlier case asserted was at least HIDDEN rather than announced
     * empty. That case is replaced rather than kept beside this one: an assertion that a
     * container is politely hidden is worthless once the container holds the data.
     */
    public function test_the_chart_ships_its_numbers(): void
    {
        $html = $this->partial('reports.order_product_chart', [
            'currency' => '$', 'Totalsale' => '1.00', 'PurchasedItem' => 1,
            'periodLabel' => 'Last 7 days',
            'labels' => ['21-Oct', '22-Oct'],
            'netSeries' => [10, 20],
            'itemSeries' => [1, 2],
        ]);

        $this->assertStringContainsString('<div class="ss-chart" aria-hidden="true">', $html,
            'the bars are a picture of the table and must not be announced twice');
        $this->assertStringContainsString('<caption', $html);
        $this->assertStringContainsString('>Order value</th>', $html);
        $this->assertStringContainsString('>Items ordered</th>', $html);
        $this->assertStringContainsString('>20</td>', $html, 'the series values are in the table');
        $this->assertStringContainsString('>21-Oct</th>', $html, 'the labels are in the table');
    }

    /**
     * THE SALES REPORT PRINTED A COUNT AS MONEY. `order_chart_data` rendered
     * `{{ $currency }}{{ $TotalOrder }}` — "$7" for seven orders — and the same for the item
     * count, beside four figures that really are money. Two of its six cards were labelled
     * "in this period" with no period named anywhere on the card.
     */
    public function test_the_sales_report_separates_counts_from_money(): void
    {
        $html = $this->partial('reports.order_chart_data', [
            'currency' => '$', 'TotalgrossSale' => '120.00', 'TotalNetSale' => '100.00',
            'TotalOrder' => 7, 'PurchasedProductItemTotal' => 9,
            'TotalShippingCharge' => '5.00', 'TotalCouponAmount' => '20.00',
            'periodLabel' => 'Last 7 days',
            'labels' => ['21-Oct'], 'grossSeries' => [120], 'netSeries' => [100], 'orderSeries' => [7],
        ]);

        $this->assertSame(6, substr_count($html, 'ss-stat__value'), 'Premise: all six figures render.');
        $this->assertSame(array_fill(0, 6, 'Last 7 days'), $this->periods($html),
            'every figure must carry the period, including the two whose labels used to SAY '
            . '"in this period" without naming one');

        $this->assertStringNotContainsString('$7', $html, 'the order COUNT is being printed as money');
        $this->assertStringNotContainsString('$9', $html, 'the item COUNT is being printed as money');
        /* Whitespace-normalised: the component renders the value on its own line inside the
           span, so a tight `>7</span>` never matches. */
        $this->assertStringContainsString('ss-stat__value"> 7 </span>',
            preg_replace('/\s+/', ' ', $html), 'Premise: the order count renders.');
        $this->assertStringContainsString('$120.00', $html, 'Premise: the money figures still render.');

        $this->assertStringNotContainsString('Net sale in this period', $html,
            'this sums final_price; the dashboard calls it order value and this must not invent '
            . 'a second vocabulary');
    }

    /**
     * THE TWO ORDER-REPORT BODIES RENDER THE SAME SIX FIGURES, and each had its own copy of the
     * markup, so each carried the same defects independently. They share one partial now, and
     * this drives the OTHER body through it — a shared partial is only shared while both
     * callers still use it.
     */
    public function test_the_bar_chart_body_shares_the_same_honest_figures(): void
    {
        $html = $this->partial('reports.order_bar_chart_data', [
            'currency' => '$', 'TotalgrossSale' => '120.00', 'TotalNetSale' => '100.00',
            'TotalOrder' => 7, 'PurchasedProductItemTotal' => 9,
            'TotalShippingCharge' => '5.00', 'TotalCouponAmount' => '20.00',
            'periodLabel' => 'This year (2026)',
            'labels' => ['21-Oct'], 'grossSeries' => [120], 'netSeries' => [100],
            'orderSeries' => [7], 'itemSeries' => [9],
        ]);

        $this->assertSame(6, substr_count($html, 'ss-stat__value'));
        $this->assertSame(array_fill(0, 6, 'This year (2026)'), $this->periods($html));
        $this->assertStringNotContainsString('$7', $html, 'the order COUNT is being printed as money');

        /* Both charts ship their numbers. */
        $this->assertSame(2, substr_count($html, '<table'));
        $this->assertStringNotContainsString('radialbar-charts', $html,
            'a radial bar of two values is a bar chart that is harder to read, and it had no '
            . 'accessible representation at all');
    }

    /** An empty series renders the empty state rather than a chart of nothing. */
    public function test_an_absent_series_renders_the_empty_state(): void
    {
        $html = $this->partial('reports.order_brand_chart', [
            'currency' => '$', 'NetSalesofbrand' => ['Shoes' => 1.0],
            'periodLabel' => 'Last 7 days',
        ]);

        $this->assertStringContainsString('No data for this period yet', $html,
            'a partial rendered without its series must say so, not draw an empty frame');
    }
}
