<?php

namespace Tests\Feature\Analytics;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-002 / GAP-221 — THE "TOP SALES" SCREEN COULD NOT BE EXECUTED BY THE SUITE AT ALL.
 * =================================================================================================
 *
 * GAP-221 names five genuinely unfiltered report screens and four are done. The fifth,
 * `top_product()`, was dispositioned as *not buildable with suite evidence today*, because its
 * category and brand charts joined `orders.product_id` with
 *
 *     products.id = SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)
 *
 * across a generated-numbers cross join. SQLite has no `SUBSTRING_INDEX`, so driving the route
 * answered `SQLSTATE[HY000]: General error: 1 no such function: SUBSTRING_INDEX` — measured, and
 * recorded on the row. The whole screen was therefore invisible to the suite: not merely the two
 * charts, but the ranking, the tenant predicates and the period that could not be added to it.
 *
 * THIS IS THE SECOND BRANCH OF THAT ROW'S OWN DISPOSITION — *"replace their `selectRaw` ... so the
 * suite can reach them — the second is the larger change and the one that removes the blindness
 * permanently"* — and it is not a new pattern: `App\Support\TopSellersByRevenue` already replaced
 * the identical cross join on the dashboard's Top Category / Top Brand cards, for the same reason,
 * and its docblock measures why that join can never be summed correctly.
 *
 * THE METRIC IS UNCHANGED AND IS THE ONE THE VIEW ALREADY DOCUMENTS: *"Categories by the number of
 * distinct orders containing one of their products"* — `COUNT(DISTINCT orders.id)`, which is what
 * the SQL computed. These tests assert that metric, its ranking, its tie-break and its three store
 * predicates, so a rewrite that quietly answers a different question fails them.
 *
 * `tests/Support/Security/bin/verify-top-five-reports-on-mysql.php` keeps the SAME figures on the
 * production engine (15 checks). Neither replaces the other: that script is the only evidence that
 * MySQL's case-insensitive collation and this PHP agree; this file is the evidence every other
 * agent gets for free on every run.
 */
class TopSalesReportSuiteReachabilityTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /* The screen must answer a MERCHANT, not a stack trace. With debug on, a rendered
           exception page is still an HTTP 500 and the status assertions below would read the
           same -- but the failure message a future reader gets is the one this config produces. */
        config(['app.debug' => false]);
    }

    /**
     * A principal this screen admits.
     *
     * `ReportPermission::ACTIONS['top_product']` maps to `Manage Reports`. The grant is not what
     * admits them -- `makeTenant()` builds a type-`admin` user, so `ReportAccess::permits()`
     * answers true at the TENANT OWNER limb before any permission is read -- so it is granted for
     * honesty and the authorization question itself stays with `ReportAuthorizationTest`.
     *
     * @return array{0: \App\Models\User, 1: Store}
     */
    private function merchantWhoMayRead(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Reports');
        Cache::forget('admin_details');

        return [$merchant, $store];
    }

    /**
     * One category, one brand and one product, all of this store, named for the reader.
     *
     * @return array{category: int, brand: int, product: int}
     */
    private function catalogue(Store $store, string $label): array
    {
        $unique = $label . '-' . $store->id;

        $categoryId = Category::create([
            'name' => 'Cat ' . $unique,
            'slug' => Str::slug('cat ' . $unique),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ])->id;

        $brandId = ProductBrand::create([
            'name' => 'Brand ' . $unique,
            'slug' => Str::slug('brand ' . $unique),
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ])->id;

        /* `forceCreate` and every NOT NULL column named: `brand_id` is not on `$fillable`, so
           `create()` drops it silently and the brand chart would be tested against nothing. */
        $productId = Product::forceCreate([
            'name' => 'Prod ' . $unique,
            'slug' => Str::slug('prod ' . $unique),
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ])->id;

        return ['category' => $categoryId, 'brand' => $brandId, 'product' => $productId];
    }

    /**
     * An order of this store whose `product_id` is the comma-joined list the three writers build.
     *
     * @param  list<int>  $productIds
     */
    private function orderListing(Store $store, array $productIds, ?string $when = null): Order
    {
        return Order::create([
            'product_order_id' => 'TOP-' . strtoupper($this->uniqueFixtureRef()),
            /* `order_date`, not `created_at`: every other screen in this centre windows
               `order_date` and the merchant's own order list shows it under "Date". */
            'order_date' => $when === null ? now() : now()->parse($when),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => '[]',
            'product_id' => implode(',', $productIds),
            'product_price' => 10,
            'coupon_price' => 0,
            'delivery_price' => 0,
            'tax_price' => 0,
            'final_price' => 10,
            'payment_type' => 'cod',
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

    /** @return array<string, int> name => count */
    private function ranking($response, string $key): array
    {
        $rows = collect($response->viewData($key));

        return $rows->mapWithKeys(fn ($row) => [
            (string) (is_array($row) ? $row['sale_name'] : $row->sale_name) => (int) (is_array($row) ? $row['total_sale'] : $row->total_sale),
        ])->all();
    }

    // -------------------------------------------------------------- the blindness itself

    public function test_the_top_sales_screen_can_be_rendered_at_all(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $one = $this->catalogue($store, 'one');
        $this->orderListing($store, [$one['product']]);

        $response = $this->openReport($merchant);

        $this->assertSame(200, $response->status(),
            'GAP-221: driving `top-all-reports` answered HTTP ' . $response->status() . '. The '
            . 'category and brand charts joined on `SUBSTRING_INDEX`, which SQLite does not have, '
            . 'so the whole screen -- its ranking, its three store predicates and the period it '
            . 'still lacks -- could not be executed by this suite at all.');
    }

    // -------------------------------------------------------------- the metric

    public function test_a_category_is_counted_once_per_order_however_many_of_its_products_the_order_lists(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $first = $this->catalogue($store, 'first');

        /* A SECOND product filed under the SAME category, and one order listing both. The card
           says "the number of distinct orders containing one of their products", so this order
           contributes ONE -- the `COUNT(DISTINCT orders.id)` the SQL computed. Counting rows
           would say two. */
        $second = Product::forceCreate([
            'name' => 'Prod sibling', 'slug' => 'prod-sibling',
            'category_id' => $first['category'], 'brand_id' => $first['brand'],
            'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ])->id;

        $this->orderListing($store, [$first['product'], $second]);

        $response = $this->openReport($merchant);
        $response->assertOk();

        $this->assertSame(1, $this->ranking($response, 'top_sales')['Cat first-' . $store->id] ?? null,
            'One order listing two products of one category must count ONCE: the metric is '
            . 'distinct ORDERS, which is what the card promises and what COUNT(DISTINCT orders.id) '
            . 'computed.');

        $this->assertSame(1, $this->ranking($response, 'top_brand_sales')['Brand first-' . $store->id] ?? null,
            'The brand chart counts the same way and must give the same answer.');
    }

    public function test_the_same_product_listed_twice_in_one_order_still_counts_once(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $one = $this->catalogue($store, 'one');

        /* The shape `OrderController::store()` and the Flutter admin path both produce: neither
           de-duplicates `implode(',', array_column($request->products, 'product_id'))`. */
        $this->orderListing($store, [$one['product'], $one['product'], $one['product']]);

        $response = $this->openReport($merchant);
        $response->assertOk();

        $this->assertSame(1, $this->ranking($response, 'top_sales')['Cat one-' . $store->id] ?? null,
            'A product listed three times in ONE order inflated a figure labelled "orders".');
    }

    public function test_only_the_top_five_are_returned_ranked_descending(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        /* Seven categories with 7,6,5,4,3,2,1 orders, so a correct top five EXCLUDES two and
           engine order (insertion) differs from ranked order. */
        $catalogue = [];
        foreach (range(1, 7) as $i) {
            $catalogue[$i] = $this->catalogue($store, 'rank' . $i);
            for ($o = 0; $o < 8 - $i; $o++) {
                $this->orderListing($store, [$catalogue[$i]['product']]);
            }
        }

        $response = $this->openReport($merchant);
        $response->assertOk();

        $categories = $this->ranking($response, 'top_sales');

        $this->assertCount(5, $categories,
            'A screen headed "Top Sales Report" returned ' . count($categories) . ' bars: the '
            . 'ranking and the limit are part of the figure.');

        $this->assertSame([7, 6, 5, 4, 3], array_values($categories),
            'The five returned are not the five with the most orders in descending order: '
            . json_encode($categories));

        /* The same ladder: each of the seven brands holds exactly one product, so the brand counts
           are the category counts. The first draft of this assertion said 5,4,3,2,1 -- my
           arithmetic, not the code's -- and the test caught it. */
        $this->assertSame([7, 6, 5, 4, 3], array_values($this->ranking($response, 'top_brand_sales')),
            'The brand chart is not ranked and limited the same way as the category chart.');
    }

    public function test_a_tie_is_broken_by_name_ascending_and_not_by_the_order_the_rows_arrived_in(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        /* Seven items at ONE order each, inserted so first-appearance order is the REVERSE of
           name order: an implementation that keeps arrival order returns exactly the wrong five.
           `orderBy('categories.name')` is what the SQL did, so the correct answer is the lowest
           five by name. */
        foreach (range(7, 1) as $i) {
            $made = $this->catalogue($store, 'tie' . $i);
            $this->orderListing($store, [$made['product']]);
        }

        $response = $this->openReport($merchant);
        $response->assertOk();

        $this->assertSame(
            ['Cat tie1-' . $store->id, 'Cat tie2-' . $store->id, 'Cat tie3-' . $store->id,
                'Cat tie4-' . $store->id, 'Cat tie5-' . $store->id],
            array_keys($this->ranking($response, 'top_sales')),
            'Seven categories tied at one order each must break the tie by name ascending. '
            . 'Arrival order would return tie7..tie3.');
    }

    /**
     * TWO CATEGORIES WHOSE NAMES DIFFER ONLY IN CASE ARE ONE BAR, because that is what the server
     * did and this screen must not change its answer between the suite and production.
     *
     * The old query said `GROUP BY categories.name`, and the MySQL collation in use here
     * (`utf8mb4_unicode_ci`) is case-INSENSITIVE and PAD SPACE: 'Shoes', 'shoes' and 'Shoes  '
     * were ONE bucket on the server. A PHP implementation that compared bytes would show three
     * bars in the suite and one in production, so the fold PRESERVES the shipped behaviour rather
     * than inventing one. The payment-method chart on the same screen reached the same conclusion
     * and records it.
     */
    public function test_two_names_differing_only_in_case_or_trailing_space_are_one_bar(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $first = Category::create([
            'name' => 'Shoes', 'slug' => 'shoes-a',
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ])->id;

        $second = Category::create([
            'name' => 'shoes  ', 'slug' => 'shoes-b',
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ])->id;

        foreach ([$first, $second] as $index => $categoryId) {
            $productId = Product::forceCreate([
                'name' => 'Shoe ' . $index, 'slug' => 'shoe-' . $index,
                'category_id' => $categoryId, 'brand_id' => null,
                'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
                'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock', 'status' => 1,
                'store_id' => $store->id, 'created_by' => $store->created_by,
            ])->id;

            $this->orderListing($store, [$productId]);
        }

        $response = $this->openReport($merchant);
        $response->assertOk();

        $categories = $this->ranking($response, 'top_sales');

        $this->assertCount(1, $categories,
            'Two spellings of one category name produced ' . count($categories) . ' bars: '
            . json_encode($categories) . '. The server folded them into one and this must agree '
            . 'with the server, or the figure differs between the suite and production.');

        $this->assertSame(2, array_values($categories)[0],
            'The folded bucket must count the orders of BOTH rows: two distinct orders.');

        $this->assertSame('Shoes', array_keys($categories)[0],
            'The bucket is labelled with the OLDEST row\'s spelling, which is deterministic. The '
            . 'server returned whichever row it reached first.');
    }

    /**
     * A PRODUCT BEYOND THE HUNDREDTH POSITION OF ONE ORDER IS COUNTED — the one behaviour
     * difference the rewrite introduces, pinned so it is a decision and not a surprise.
     *
     * The generated series ran `n = 1 … 100`, so only the first hundred elements of
     * `orders.product_id` were ever matched. An order listing more products than that silently
     * omitted the rest from a figure the card defines as "the number of distinct orders containing
     * one of their products". Reading the whole list moves the code towards its own documented
     * definition; the direction is stated on `TopSellersByOrderCount` as well.
     */
    public function test_a_product_past_the_hundredth_position_of_one_order_is_counted(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $filler = $this->catalogue($store, 'filler');
        $late = $this->catalogue($store, 'late');

        /* 100 positions of one product, then the one the old series could not reach. The filler
           repeats, so the order names exactly two DISTINCT products -- the length of the list is
           what matters here, not its variety. */
        $list = array_fill(0, 100, $filler['product']);
        $list[] = $late['product'];

        $this->orderListing($store, $list);

        $response = $this->openReport($merchant);
        $response->assertOk();

        $this->assertSame(1, $this->ranking($response, 'top_sales')['Cat late-' . $store->id] ?? null,
            'The 101st product of an order was dropped, so a category the order really contained '
            . 'is missing from a figure defined as "orders containing one of their products".');
    }

    // -------------------------------------------------------------- the three store predicates

    public function test_another_tenants_order_does_not_enter_this_stores_ranking(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        [, $other] = $this->makeTenant();

        $theirs = $this->catalogue($other, 'theirs');
        $this->orderListing($other, [$theirs['product']]);

        $ours = $this->catalogue($store, 'ours');
        $this->orderListing($store, [$ours['product']]);

        $response = $this->openReport($merchant);
        $response->assertOk();

        $categories = $this->ranking($response, 'top_sales');

        $this->assertSame(['Cat ours-' . $store->id], array_keys($categories),
            'Another tenant\'s category appeared in this store\'s Top Sales report: '
            . json_encode($categories) . '. `Order`, `Product` and `Category` are all absent from '
            . '`config/tenancy.php`\'s `scoped_models`, so nothing but a written predicate '
            . 'confines any of these three reads.');
    }

    public function test_a_foreign_product_named_in_our_own_order_is_not_attributed_by_its_own_catalogue(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        [, $other] = $this->makeTenant();

        $theirs = $this->catalogue($other, 'theirs');

        /* OUR order naming THEIR product -- reachable, because `orders.product_id` is free text
           and the product lookup is not confined by any global scope. The foreign product's
           category must not be credited: the old query required `products.store_id` AND
           `categories.store_id` to be ours, and so must this one. */
        $this->orderListing($store, [$theirs['product']]);

        $response = $this->openReport($merchant);
        $response->assertOk();

        $this->assertSame([], $this->ranking($response, 'top_sales'),
            'A product id belonging to another store, named in our own order, was attributed by '
            . 'that store\'s category: ' . json_encode($this->ranking($response, 'top_sales')));

        $this->assertSame([], $this->ranking($response, 'top_brand_sales'),
            'The same leak through the brand chart.');
    }

    /**
     * A FOREIGN PRODUCT WHOSE `category_id` COLLIDES WITH ONE OF OURS IS STILL NOT COUNTED.
     *
     * This is what makes the `products.store_id` predicate load-bearing rather than belt-and-
     * braces, and it was found by a mutation: dropping that predicate left the plain foreign-
     * product test above GREEN, because the foreign product resolved to the foreign CATEGORY and
     * the label lookup -- scoped separately -- dropped it. The leak needs the two stores' ids to
     * collide, which `CategoryBrandReportCurrencyTest` forces for the money surfaces for the same
     * reason: nothing in this schema stops `products.category_id` naming another store's row, and
     * sequential ids across tenants make a collision ordinary rather than exotic.
     */
    public function test_a_foreign_product_whose_category_collides_with_ours_is_not_counted(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        [, $other] = $this->makeTenant();

        $ours = $this->catalogue($store, 'ours');
        $theirs = $this->catalogue($other, 'theirs');

        /* THEIR product, filed under OUR category and brand -- the collision. */
        DB::table('products')->where('id', $theirs['product'])->update([
            'category_id' => $ours['category'],
            'brand_id' => $ours['brand'],
        ]);
        (new Product)->flushCache();

        /* Our own order, naming their product. `orders.product_id` is free text. */
        $this->orderListing($store, [$theirs['product']]);

        $response = $this->openReport($merchant);
        $response->assertOk();

        $this->assertSame([], $this->ranking($response, 'top_sales'),
            'An order naming ANOTHER store\'s product was counted into our category because that '
            . 'product happens to carry one of our category ids: '
            . json_encode($this->ranking($response, 'top_sales')));

        $this->assertSame([], $this->ranking($response, 'top_brand_sales'),
            'The same leak through the brand chart.');
    }

    /**
     * OUR OWN PRODUCT FILED UNDER ANOTHER STORE'S CATEGORY IS DROPPED, NOT NAMED.
     *
     * This is the third of the three store predicates, and the only one the two tests above cannot
     * reach: the dimension ids come from our own (store-scoped) products, so a leak here needs a
     * product of OURS whose `category_id` points at a row of theirs. `CategoryBrandReportCurrency
     * Test` forces exactly this collision for the money surfaces, because `products.category_id`
     * carries no foreign key to a store and nothing stops it. The old query required
     * `categories.store_id` to be ours and dropped the row; so must this one.
     */
    public function test_our_product_filed_under_another_stores_category_is_dropped_rather_than_named(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();
        [, $other] = $this->makeTenant();

        $theirs = $this->catalogue($other, 'theirs');
        $ours = $this->catalogue($store, 'ours');

        /* OUR product, THEIR category and brand. */
        DB::table('products')->where('id', $ours['product'])->update([
            'category_id' => $theirs['category'],
            'brand_id' => $theirs['brand'],
        ]);
        (new Product)->flushCache();

        $this->orderListing($store, [$ours['product']]);

        $response = $this->openReport($merchant);
        $response->assertOk();

        $this->assertSame([], $this->ranking($response, 'top_sales'),
            'Another store\'s category NAME was printed in this store\'s report: '
            . json_encode($this->ranking($response, 'top_sales')));

        $this->assertSame([], $this->ranking($response, 'top_brand_sales'),
            'The same leak through the brand chart.');
    }

    public function test_a_deleted_or_unknown_product_id_in_the_list_is_skipped_rather_than_counted(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $one = $this->catalogue($store, 'one');

        /* A real product, a dead id, an empty element and a non-numeric one -- all four shapes
           `orders.product_id` really carries. */
        $order = $this->orderListing($store, [$one['product']]);
        DB::table('orders')->where('id', $order->id)
            ->update(['product_id' => $one['product'] . ',999999,, abc ']);
        (new Order)->flushCache();

        $response = $this->openReport($merchant);
        $response->assertOk();

        $this->assertSame(['Cat one-' . $store->id => 1], $this->ranking($response, 'top_sales'),
            'A dead, empty or non-numeric element of the comma-joined list must be skipped, not '
            . 'counted and not fatal.');
    }
}
