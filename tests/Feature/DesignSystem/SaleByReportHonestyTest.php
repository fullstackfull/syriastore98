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
     * THE CHART IS STILL AN APEXCHARTS CONTAINER WITH NO TABLE, and that is recorded rather than
     * hidden: converting these three series needs the controller to pass them through, and the
     * MySQL-only queries around them make that untestable here. What it must NOT do meanwhile is
     * be announced as content — an empty div with no accessible name is worse than a hidden one.
     */
    public function test_the_unconverted_chart_is_hidden_rather_than_announced_empty(): void
    {
        $html = $this->partial('reports.order_product_chart', [
            'currency' => '$', 'Totalsale' => '1.00', 'PurchasedItem' => 1,
            'periodLabel' => 'Last 7 days',
        ]);

        $this->assertStringContainsString('<div class="traffic-chart" aria-hidden="true">', $html,
            'the chart container has no accessible representation yet, so it must not be '
            . 'announced as if it had one');
    }
}
