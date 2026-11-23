<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-TOP — THE CATEGORY AND BRAND REPORTS ADD CURRENCIES TOGETHER, AND SAY ONE SYMBOL.
 * =================================================================================================
 *
 * Part 1 stopped the dashboard's Top Category / Top Brand CARDS doing this. These four surfaces
 * -- `order_category_reports`, `category_order_export`, `order_brand_reports`,
 * `brand_order_export` -- are the same figure on another screen, and they still do it:
 *
 *     $categoryNetSales[$category->name][$dayIndex] += (float) ($product['final_price']);
 *
 * with no reference to `orders.currency_code` anywhere in either handler, and the result printed
 * as `$currency . number_format($saleAmount, 2)` where `$currency` is one symbol from
 * `Utility::GetValueByName('CURRENCY')`. A store that has taken 25 EUR and 90 USD is shown
 * `115.00` behind whichever symbol that setting holds -- a number that is not a quantity of any
 * currency, presented as if it were.
 *
 * The owner's instruction is explicit: never sum monetary amounts denominated in different
 * currencies without an approved conversion basis; separate the buckets or withhold the
 * aggregate. There is no conversion basis in this product -- `CurrencyRate` exists but no owner
 * decision authorises using it for reporting -- so the rule these surfaces must follow is the one
 * part 1 established on the card: the store's own currency is totalled, amounts in other
 * currencies are LISTED and never added, and orders that carry no recorded currency are counted in
 * the store's currency and disclosed.
 *
 * WHY THESE SURFACES AND NOT THE CARD'S CODE. The card is `TopSellersByRevenue`, which already
 * computes exactly this, tenant-safely and in two queries. It cannot simply be substituted here:
 * it windows on `created_at` while these handlers window on `order_date` (see
 * `TopSellersByRevenue::revenueByProduct()` for why that divergence is deliberate), so swapping
 * would make the report's tiles count a different set of orders than the report's own chart --
 * worse than the defect. The rule is shared; the window is not.
 *
 * THESE TESTS DRIVE THE REAL ROUTES, because the defect is in what a merchant is shown. Both
 * handlers hold zero `MONTH(`/`YEAR(` constructs (measured 2026-11-23), so unlike most of
 * `ReportController` they ARE reachable under this suite.
 */
class CategoryBrandReportCurrencyTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /** @return array<string, array{0: string}> */
    public static function surfaces(): array
    {
        return [
            'category report' => ['category'],
            'brand report' => ['brand'],
        ];
    }

    /**
     * THE FIVE PERIOD BRANCHES, because each is its own copy of the arithmetic.
     *
     * Both handlers are an if/elseif chain over `chart_data`, and every arm accumulates money in
     * its own loop with its own bucket index. A fix applied to one arm leaves the other four
     * adding currencies together, and the merchant reaches them with a button press. So the
     * currency case is driven through all five rather than through the one that was convenient.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function surfacesAndPeriods(): array
    {
        $cases = [];
        $range = date('Y-m-01') . ' to ' . date('Y-m-d');

        $periods = [
            'last-month' => 'chart_data=last-month',
            'this-month' => 'chart_data=this-month',
            'seven-day' => 'chart_data=seven-day',
            'year' => 'chart_data=year',
            'date-range (else)' => 'chart_data=range&Date=' . urlencode($range),
        ];

        foreach (self::surfaces() as $surfaceName => [$surface]) {
            foreach ($periods as $periodName => $query) {
                $cases["{$surfaceName}, {$periodName}"] = [$surface, $query];
            }
        }

        return $cases;
    }

    /**
     * A date that genuinely falls inside the given branch's window.
     *
     * ONE FIXTURE DATE CANNOT SERVE ALL FIVE, and a case whose order falls outside the window it
     * is testing passes for the wrong reason: the figure is absent because there is no money, not
     * because the money was bucketed correctly. `seven-day` is the one that catches this -- it
     * covers only the last week, so a start-of-month order is invisible to it for most of a month.
     */
    private function dateInside(string $query): \Carbon\Carbon
    {
        if (str_contains($query, 'last-month')) {
            return now()->subMonthNoOverflow()->startOfMonth()->addHours(9);
        }

        if (str_contains($query, 'seven-day')) {
            return now()->startOfDay()->addHours(9);
        }

        /* this-month, year and the custom range all contain a day in the current month that is
           not in the future. The range provider asks for the 1st to today. */
        return now()->startOfMonth()->addHours(9);
    }

    // ---------------------------------------------------------------------------------- the defect

    #[\PHPUnit\Framework\Attributes\DataProvider('surfacesAndPeriods')]
    public function test_two_currencies_are_not_added_into_one_figure(string $surface, string $query): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $product = $this->productIn($store, $surface, 'Lamps');

        $when = $this->dateInside($query);
        $this->orderFor($store, $product, 25.0, 'EUR', $when);
        $this->orderFor($store, $product, 90.0, 'USD', $when);

        $html = $this->chart($merchant, $surface, $query)['html'];

        /* 115.00 is the only number that can appear if the two were added, and it is not a
           quantity of euros or of dollars. */
        $this->assertStringNotContainsString('115.00', $html,
            'the report added 25 EUR and 90 USD into one figure');

        $this->assertStringContainsString('25.00', $html,
            "the store's own currency total is missing, so the figure was withheld rather than "
            . 'bucketed');

        $this->assertStringContainsString('USD', $html,
            'the 90 USD is neither added nor disclosed, so it has silently vanished from the report');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_the_export_replay_carries_the_currency_disclosure(string $surface): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $product = $this->productIn($store, $surface, 'Lamps');
        $this->orderFor($store, $product, 25.0, 'EUR');
        $this->orderFor($store, $product, 90.0, 'USD');

        /* The screen first: the export replays what the screen put in the session. */
        $this->chart($merchant, $surface);

        $uri = $surface === 'category' ? '/category-order-export' : '/brand-order-export';
        $response = $this->actingAs($merchant->fresh())->get($uri);

        $this->assertSame(200, $response->status(), $uri . ': the export handler did not run');
        $payload = $response->json();
        $this->assertIsArray($payload, $uri . ': the export did not return JSON');

        $note = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('USD', (string) $note,
            'Phase 05\'s rule is that an export carries the same caveats as the screen. This one '
            . 'hands the merchant a money column with no word about the second currency, so the '
            . 'spreadsheet is less honest than the page it came from.');
    }

    /**
     * A product id in THIS store's order that belongs to ANOTHER store is attributed by the
     * foreign product's own category or brand.
     *
     * `Product` registers no `StoreScope` and is absent from `config/tenancy.php`'s
     * `scoped_models`, so `Product::find($product['product_id'])` -- which both handlers call,
     * once per order line, inside a triple-nested loop -- reaches every tenant's catalogue. The
     * comparison that follows is `$product_data->category_id == $categoryId` against THIS store's
     * own category ids, so a foreign product whose `category_id` collides with one of them is
     * counted into this store's report.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_a_foreign_product_in_our_order_is_not_attributed_by_its_own_catalogue(string $surface): void
    {
        [$merchant, $store] = $this->makeTenant();
        [, $other] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $ours = $this->productIn($store, $surface, 'Lamps');
        $theirs = $this->productIn($other, $surface, 'Lamps');

        /* Force the collision this defect needs: the foreign product is filed under OUR row. */
        $column = $surface === 'category' ? 'category_id' : 'brand_id';
        DB::table('products')->where('id', $theirs->id)
            ->update([$column => DB::table('products')->where('id', $ours->id)->value($column)]);
        (new Product)->flushCache();

        /* One order, OURS, but naming the other tenant's product. */
        $this->orderFor($store, $theirs, 777.0, 'EUR');

        $html = $this->chart($merchant, $surface)['html'];

        $this->assertStringNotContainsString('777.00', $html,
            'a line naming another tenant\'s product was counted into this store\'s report, '
            . 'attributed by that foreign product\'s own ' . $column);
    }

    // ---------------------------------------------------- what the independent review found

    public function test_the_brand_seven_day_table_pairs_each_day_with_its_own_money(): void
    {
        /*
         * REVIEW FINDING 1, AND IT WAS LIVE BEFORE THIS SLICE. `$dayList` is built
         * `for ($i = 6; $i >= 0; $i--)`, so its index 0 is SIX DAYS AGO; the bucket index counts
         * days backwards from today, so bucket 0 is TODAY. The arm reversed for the ApexCharts
         * series but `$netSeries` fell through to the UN-reversed aggregate, so the chart's
         * accessible table -- which `x-ss.bar-chart` calls the accessible content, not a fallback
         * -- paired today's money with the label for six days ago, right across the week.
         *
         * Nothing caught it: the existing `ChartMoneyPrecisionTest` asserts sums and a total, and
         * both are order-independent. This drives the real route with money on ONE day only, so
         * the pairing is the whole assertion.
         */
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $product = $this->productIn($store, 'brand', 'Lamps');
        $this->orderFor($store, $product, 61.0, 'EUR', now()->startOfDay()->addHours(9));

        $html = $this->chart($merchant, 'brand', 'chart_data=seven-day')['html'];

        $today = date('d-M');
        $sixDaysAgo = date('d-M', strtotime('-6 days'));

        $this->assertNotSame($today, $sixDaysAgo, 'premise: the two labels differ');

        /* The accessible table is `<th>label</th><td>money</td>`; the money must sit in the cell
           that follows TODAY's label, not the one that follows the oldest label. */
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($today, '/') . '<\/th>\s*<td[^>]*>[^<]*61/',
            $html,
            "the day's money is not in the cell beside its own label -- the seven-day series and "
            . 'its labels run in opposite directions'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/' . preg_quote($sixDaysAgo, '/') . '<\/th>\s*<td[^>]*>[^<]*61/',
            $html,
            "today's money was printed against the label for six days ago"
        );
    }

    public function test_the_category_last_month_window_derives_its_year_from_the_month_it_reports(): void
    {
        /*
         * REVIEW FINDING 6, PINNED AT THE SOURCE -- AND THE FIRST DRAFT OF THIS TEST COULD NOT
         * WORK, WHICH IS THE MORE USEFUL FINDING.
         *
         * The defect: the category last-month arm filtered on `whereYear(date('Y'))` -- the
         * CURRENT year -- together with the PREVIOUS month's bounds. Every January those
         * contradict each other and the arm reports zero for every category. The brand twin was
         * already fixed for this (GAP-223(a)) by deriving both from `$firstDayOfPreviousMonth`.
         *
         * WHY THIS IS NOT A BEHAVIOURAL TEST. I wrote one first: `Carbon::setTestNow(January)`,
         * an order in the preceding December, assert the figure appears. It failed against the
         * CORRECTED handler, and the reason is the point -- the arm computes its bounds with
         * `date()` and `strtotime('first day of last month')`, which read the REAL process clock.
         * `Carbon::setTestNow()` moves Carbon's clock and nothing else, so the fixture was dated
         * December 2026 while the handler was still reporting on the real previous month. The
         * January path is unreachable under this suite without making the handler clock-injectable,
         * which is a change to five arms and not this slice's.
         *
         * So the assertion is on the source, with comments stripped -- this file's own docblocks
         * quote `date('Y')`, which an unstripped scan would match.
         */
        $source = $this->sourceWithoutComments('app/Http/Controllers/ReportController.php');

        $start = strpos($source, 'public function order_category_reports(');
        $end = strpos($source, 'public function category_order_export(');
        $this->assertNotFalse($start, 'the category report handler is gone');
        $this->assertNotFalse($end, 'the category export handler is gone');

        $handler = substr($source, $start, $end - $start);

        /* The premise: the arm really does window on the previous month. */
        $this->assertStringContainsString('$firstDayOfPreviousMonth', $handler,
            'premise failed: the last-month arm no longer computes a previous-month bound');

        $this->assertStringContainsString(
            "->whereYear('order_date', date('Y', strtotime(\$firstDayOfPreviousMonth)))",
            $handler,
            'the last-month arm takes its YEAR from the current date while taking its MONTH from '
            . 'the previous one, so every January the two predicates contradict each other and '
            . 'the report is empty. The brand twin already derives both from '
            . '$firstDayOfPreviousMonth (GAP-223(a)); this must too.'
        );

        $this->assertStringNotContainsString(
            "->whereYear('order_date', \$year)\n                ->whereBetween('order_date', [\$firstDayOfPreviousMonth",
            $handler,
            'the contradictory year-plus-bounds pair is back'
        );
    }

    // ------------------------------------------------------------------------------------ fixtures

    private function storeCurrency(Store $store, string $code): void
    {
        DB::table('settings')->insert([
            'store_id' => $store->id, 'name' => 'defult_currancy', 'value' => $code,
            'created_by' => $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function productIn(Store $store, string $surface, string $name): Product
    {
        $categoryId = Category::create([
            'name' => $name . '-' . $store->id,
            'slug' => Str::slug($name) . '-' . $store->id,
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ])->id;

        $brandId = ProductBrand::create([
            'name' => $name . 'Brand-' . $store->id,
            'slug' => Str::slug($name) . '-brand-' . $store->id,
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ])->id;

        /* `forceCreate`, and every NOT NULL column named: `products` requires `track_stock`,
           and `brand_id` is not on `$fillable`, so `create()` would drop the brand silently and
           the brand surface of this file would test nothing.

           NO `sub_category_id`. The first draft set it, on the reasonable assumption that a
           product has one -- `products` HAS NO SUCH COLUMN. CLAUDE.md's rule that the migrations
           are not the schema cuts the other way here too: a column one expects to exist may not. */
        return Product::forceCreate([
            'name' => $name . ' product ' . $store->id,
            'slug' => Str::slug($name) . '-product-' . $store->id,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function orderFor(
        Store $store,
        Product $product,
        float $amount,
        string $currency,
        ?\Carbon\Carbon $when = null
    ): Order {
        $order = Order::create([
            'product_order_id' => 'ORD-' . strtoupper($this->uniqueFixtureRef()),
            /* THESE HANDLERS WINDOW ON `order_date`, not `created_at` -- the divergence
               `TopSellersByRevenue` records. Mid-month so every branch of both handlers
               (this-month, seven-day, year, range) contains it. */
            'order_date' => $when ?? now()->startOfMonth()->addHours(9),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'name' => $product->name,
                'qty' => 1,
                'quantity' => 1,
                'price' => $amount,
                'final_price' => $amount,
            ]]),
            'product_id' => (string) $product->id,
            'product_price' => $amount,
            'coupon_price' => 0,
            'delivery_price' => 0,
            'tax_price' => 0,
            'final_price' => $amount,
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            'delivered_status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);

        /* RAW, because the model's `creating` hook stamps `currency_code` from the store's
           setting and would overwrite a value passed to `create()`. Flushed because `Order` is
           `Cachable`: under `CACHE_STORE=array` a stale read would serve the pre-write row and
           this fixture would silently not be the one the test describes. */
        DB::table('orders')->where('id', $order->id)->update(['currency_code' => $currency]);
        (new Order)->flushCache();

        $this->assertSame($currency, DB::table('orders')->where('id', $order->id)->value('currency_code'),
            'premise failed: the order does not carry the currency this case is about');

        return $order->fresh();
    }

    /** @return array{html: string, series: array<int, mixed>} */
    private function chart(User $actor, string $surface, string $query = 'chart_data=this-month'): array
    {
        $uri = $surface === 'category' ? '/category-order-reports' : '/brand-order-reports';

        $response = $this->actingAs($actor->fresh())->get($uri . '?' . $query);

        $this->assertNotSame(404, $response->status(), $uri . ': the route does not exist');
        $this->assertSame(200, $response->status(),
            $uri . ": the handler did not run (HTTP {$response->status()})");

        $payload = $response->json();
        $this->assertIsArray($payload, $uri . ': the handler did not return the chart JSON');

        $html = (string) ($payload['html'] ?? '');
        $this->assertNotSame('', $html,
            $uri . ': the chart partial rendered empty, so an "is absent" assertion proves nothing');

        return ['html' => $html, 'series' => $payload['NetSaleTotal'] ?? []];
    }
}
