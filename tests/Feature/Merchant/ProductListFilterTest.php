<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-03-2 -- the product list can be filtered, and a filter can only NARROW it.
 * =============================================================================================
 *
 * THE GAP. The product list had no filters at all -- only DataTables' free-text box. A merchant
 * with three hundred products could not ask the one question they actually ask, which is "what
 * is out of stock", and the dashboard surfaces that count while the list it links to could not
 * show it.
 *
 * FILTERS ARRIVE AS `ss_`-PREFIXED QUERY PARAMETERS, and the prefix is not decoration: the AJAX
 * leg's url is the page url, so DataTables' own `draw`, `columns`, `search`, `order`, `start`
 * and `length` travel in the same query string. A filter named `status` would be one rename away
 * from colliding with something the library owns.
 *
 * THE SAME PARAMETERS REACH BOTH LEGS, which is why this works without any JavaScript: yajra's
 * `minifiedAjax()` sets `ajax.url` to the CURRENT url, so a page loaded at
 * `/product?ss_stock=out` makes its AJAX calls to `/product?ss_stock=out&draw=1&...`. Measured in
 * a real browser before the feature was designed around it, not assumed.
 *
 * THE RULE THIS FILE ENFORCES: **a filter may only narrow.** `query()` starts from
 * `where('store_id', getCurrentStore())` and every filter is an additional predicate on top. No
 * filter value -- including a malformed, unknown or hostile one -- may remove that predicate,
 * reach another tenant's rows, or return more rows than the unfiltered list. The last three
 * cases below are about exactly that, because a filter that reads request input into a query is
 * the shape this codebase has been bitten by repeatedly.
 */
class ProductListFilterTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private function makeProduct(int $storeId, int $createdBy, string $name, array $overrides = []): Product
    {
        return Product::forceCreate(array_merge([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 50,
            'low_stock_threshold' => 5,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ], $overrides));
    }

    /** The names the list served, for the given filter query. */
    private function names($user, array $filters = []): array
    {
        $query = array_merge($filters, ['draw' => 1, 'start' => 0, 'length' => 100]);

        foreach (['name', 'price'] as $i => $column) {
            $query["columns[{$i}][data]"] = $column;
            $query["columns[{$i}][name]"] = $column;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'asc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        $response = $this->actingAs($user)
            ->withHeaders(self::AJAX)
            ->getJson('/product?' . http_build_query($query));

        $this->assertNotSame(404, $response->getStatusCode(), 'the product route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'a filter must not 500 the list');

        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('draw', $payload, 'premise: yajra served the AJAX leg');

        $names = [];

        foreach ($payload['data'] ?? [] as $row) {
            // `name` is rendered as markup by the table, so the raw name is recovered from it.
            $names[] = strip_tags((string) ($row['name'] ?? ''));
        }

        return $names;
    }

    /** A tenant with one product in each state the filters name. */
    private function makeCatalogue(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        $this->makeProduct($store->id, $merchant->id, 'Plenty Scarf', ['product_stock' => 50]);
        $this->makeProduct($store->id, $merchant->id, 'Low Scarf', ['product_stock' => 3]);
        $this->makeProduct($store->id, $merchant->id, 'Gone Scarf', ['product_stock' => 0]);
        $this->makeProduct($store->id, $merchant->id, 'Hidden Scarf', ['status' => 0]);
        $this->makeProduct($store->id, $merchant->id, 'Varied Scarf', ['variant_product' => 1]);
        $this->makeProduct($store->id, $merchant->id, 'Untracked Scarf', [
            'track_stock' => 0,
            'product_stock' => 0,
        ]);

        return [$merchant, $store];
    }

    // =========================================================================================
    // The premise: no filter means every row
    // =========================================================================================

    public function test_with_no_filter_the_list_serves_every_product(): void
    {
        [$merchant] = $this->makeCatalogue();

        $names = $this->names($merchant);

        $this->assertCount(6, $names, 'premise: the unfiltered list serves all six rows, so '
            . 'every "the filter excluded X" assertion below is measuring the filter');
    }

    // =========================================================================================
    // Each filter narrows to what it names
    // =========================================================================================

    public function test_the_out_of_stock_filter_serves_only_tracked_products_at_zero(): void
    {
        [$merchant] = $this->makeCatalogue();

        $names = $this->names($merchant, ['ss_stock' => 'out']);

        $this->assertContains('Gone Scarf', $names);
        $this->assertNotContains('Plenty Scarf', $names);
        $this->assertNotContains('Low Scarf', $names);

        /*
         * AND THE UNTRACKED PRODUCT IS NOT "OUT OF STOCK". `track_stock = 0` means the merchant
         * told the system not to count, so its `product_stock` of 0 is not a fact about
         * inventory -- it is the absence of one. Listing it under "out of stock" would invent a
         * problem, which is the same honesty the preview panel applies to the same column.
         */
        $this->assertNotContains('Untracked Scarf', $names,
            'an untracked product was reported as out of stock; its zero is "not counted", '
            . 'not "none left"');
    }

    public function test_the_low_stock_filter_sits_between_zero_and_the_threshold(): void
    {
        [$merchant] = $this->makeCatalogue();

        $names = $this->names($merchant, ['ss_stock' => 'low']);

        $this->assertContains('Low Scarf', $names, 'stock 3 against a threshold of 5 is low');
        $this->assertNotContains('Gone Scarf', $names, 'zero is OUT, not low -- the two filters '
            . 'must not overlap or the counts stop adding up');
        $this->assertNotContains('Plenty Scarf', $names);
        $this->assertNotContains('Untracked Scarf', $names);
    }

    public function test_the_in_stock_filter_is_above_the_threshold(): void
    {
        [$merchant] = $this->makeCatalogue();

        $names = $this->names($merchant, ['ss_stock' => 'in']);

        $this->assertContains('Plenty Scarf', $names);
        $this->assertNotContains('Low Scarf', $names);
        $this->assertNotContains('Gone Scarf', $names);
    }

    /**
     * THE THREE STOCK FILTERS PARTITION THE TRACKED PRODUCTS. Nothing is in two of them and
     * nothing tracked is in none -- the property that makes the counts trustworthy, and the one
     * a boundary mistake breaks silently.
     */
    public function test_the_three_stock_filters_partition_the_tracked_products(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        // Values ON the boundaries, which is where an off-by-one lives.
        foreach ([0, 1, 4, 5, 6, 50] as $stock) {
            $this->makeProduct($store->id, $merchant->id, "Stock {$stock} Scarf", [
                'product_stock' => $stock,
                'low_stock_threshold' => 5,
            ]);
        }

        $out = $this->names($merchant, ['ss_stock' => 'out']);
        $low = $this->names($merchant, ['ss_stock' => 'low']);
        $in = $this->names($merchant, ['ss_stock' => 'in']);

        $this->assertSame([], array_intersect($out, $low), 'out and low overlap');
        $this->assertSame([], array_intersect($low, $in), 'low and in overlap');
        $this->assertSame([], array_intersect($out, $in), 'out and in overlap');

        $this->assertCount(6, array_merge($out, $low, $in),
            'the three filters do not cover every tracked product between them');

        // And the boundary itself: a stock EQUAL to the threshold is low, not in.
        $this->assertContains('Stock 5 Scarf', $low, 'stock == threshold must be LOW');
        $this->assertContains('Stock 6 Scarf', $in, 'stock > threshold must be IN');
        $this->assertContains('Stock 0 Scarf', $out);
        $this->assertContains('Stock 1 Scarf', $low);
    }

    public function test_the_status_filter_narrows_to_active_or_inactive(): void
    {
        [$merchant] = $this->makeCatalogue();

        $this->assertContains('Hidden Scarf', $this->names($merchant, ['ss_status' => '0']));
        $this->assertNotContains('Hidden Scarf', $this->names($merchant, ['ss_status' => '1']));
        $this->assertContains('Plenty Scarf', $this->names($merchant, ['ss_status' => '1']));
    }

    public function test_the_variant_filter_narrows_to_products_with_or_without_variants(): void
    {
        [$merchant] = $this->makeCatalogue();

        $this->assertContains('Varied Scarf', $this->names($merchant, ['ss_variant' => '1']));
        $this->assertNotContains('Plenty Scarf', $this->names($merchant, ['ss_variant' => '1']));
        $this->assertContains('Plenty Scarf', $this->names($merchant, ['ss_variant' => '0']));
    }

    public function test_filters_combine_rather_than_replacing_each_other(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        $this->makeProduct($store->id, $merchant->id, 'Active Gone Scarf',
            ['product_stock' => 0, 'status' => 1]);
        $this->makeProduct($store->id, $merchant->id, 'Hidden Gone Scarf',
            ['product_stock' => 0, 'status' => 0]);

        $names = $this->names($merchant, ['ss_stock' => 'out', 'ss_status' => '1']);

        $this->assertContains('Active Gone Scarf', $names);
        $this->assertNotContains('Hidden Gone Scarf', $names,
            'the second filter replaced the first instead of narrowing further');
    }

    // =========================================================================================
    // A filter may ONLY narrow -- the part that matters
    // =========================================================================================

    /**
     * NO FILTER VALUE REACHES ANOTHER TENANT'S ROWS.
     *
     * `query()` starts from `where('store_id', getCurrentStore())` and filters are additional
     * predicates. This drives every filter, and several malformed ones, against a two-tenant
     * fixture and asserts the foreign row never appears -- because "a filter that reads request
     * input into a query" is the shape this codebase has been bitten by repeatedly.
     */
    public function test_no_filter_value_can_reach_another_tenants_products(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Products');

        $this->makeProduct($storeA->id, $merchantA->id, 'Alpha Own Scarf');
        $this->makeProduct($storeB->id, $merchantB->id, 'Beta Secret Scarf', ['product_stock' => 0]);

        // PREMISE: the foreign row exists and Product carries NO effective scope, so an
        // unfiltered query would return it. Raw, because Product is Cachable.
        $this->assertSame(2, \DB::table('products')->whereNull('product_type')->count(),
            'premise: both rows are in the table');

        foreach ([
            [],
            ['ss_stock' => 'out'],
            ['ss_stock' => 'low'],
            ['ss_stock' => 'in'],
            ['ss_status' => '1'],
            ['ss_status' => '0'],
            ['ss_variant' => '0'],
            ['ss_category' => '0'],
            ['ss_category' => '-1'],
            ['ss_stock' => 'nonsense'],
            ['ss_status' => 'nonsense'],
            ['ss_status' => ''],
            ['ss_stock' => "' OR 1=1 --"],
            ['ss_category' => '1 OR 1=1'],
        ] as $filters) {
            $names = $this->names($merchantA, $filters);

            $this->assertNotContains('Beta Secret Scarf', $names,
                'the filter ' . json_encode($filters) . " reached another tenant's product");
        }
    }

    /**
     * AND NO FILTER RETURNS MORE THAN THE UNFILTERED LIST.
     *
     * The direct statement of "a filter may only narrow". An unrecognised value must be ignored
     * rather than becoming an `OR`, a removed predicate, or an exception.
     */
    public function test_no_filter_value_returns_more_rows_than_no_filter_at_all(): void
    {
        [$merchant] = $this->makeCatalogue();

        $unfiltered = count($this->names($merchant));

        $this->assertSame(6, $unfiltered, 'premise: the baseline is the whole catalogue');

        foreach ([
            ['ss_stock' => 'out'], ['ss_stock' => 'low'], ['ss_stock' => 'in'],
            ['ss_status' => '1'], ['ss_status' => '0'],
            ['ss_variant' => '1'], ['ss_variant' => '0'],
            ['ss_category' => '99999'],
            ['ss_stock' => 'nonsense'], ['ss_status' => 'nonsense'], ['ss_variant' => 'nonsense'],
            ['ss_category' => 'nonsense'], ['ss_category' => ''],
        ] as $filters) {
            $this->assertLessThanOrEqual($unfiltered, count($this->names($merchant, $filters)),
                'the filter ' . json_encode($filters) . ' returned MORE rows than no filter');
        }
    }

    /**
     * AN UNRECOGNISED VALUE IS IGNORED, not an error and not a refusal.
     *
     * A merchant who edits the url, or a bookmark from a build where a filter value was spelled
     * differently, must get the list rather than a 500 or an empty screen that looks like data
     * loss.
     */
    public function test_an_unrecognised_filter_value_is_ignored(): void
    {
        [$merchant] = $this->makeCatalogue();

        $this->assertCount(6, $this->names($merchant, ['ss_stock' => 'sideways']),
            'an unknown stock value narrowed the list instead of being ignored');

        $this->assertCount(6, $this->names($merchant, ['ss_status' => '7']),
            'an unknown status value narrowed the list');

        /*
         * An array where a scalar is expected must not 500 -- `?ss_stock[]=out`.
         *
         * STATED HONESTLY: this case passes with or without the handler's `is_string` guards,
         * because strict `in_array` and `filter_var` both decline an array on their own.
         * Measured -- removing all four guards left this green. It is kept because it pins the
         * OUTCOME (no 500 from a query string) against whatever the filters become, not because
         * it measures the guards.
         */
        $response = $this->actingAs($merchant)
            ->withHeaders(self::AJAX)
            ->getJson('/product?' . http_build_query([
                'ss_stock' => ['out'],
                'draw' => 1, 'start' => 0, 'length' => 10,
            ]));

        $this->assertNotSame(500, $response->getStatusCode(),
            'an array where a scalar filter was expected crashed the list');
    }

    /**
     * THE FILTER BAR IS ON THE PAGE, and its controls carry the names the handler reads.
     *
     * Without this the filters would work only for somebody typing query strings by hand, which
     * is the shape of a feature that passes its tests and ships unusable.
     */
    public function test_the_filter_bar_renders_with_the_parameter_names_the_query_reads(): void
    {
        [$merchant] = $this->makeCatalogue();

        $response = $this->actingAs($merchant)->get('/product');

        $response->assertOk();

        $body = (string) $response->getContent();

        foreach (['ss_status', 'ss_stock', 'ss_variant', 'ss_category'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $body,
                "the filter bar does not offer {$name}, which the query reads");
        }

        // A GET form, because filtering is a read. The AJAX leg inherits the page url, which is
        // what makes the whole mechanism work without any JavaScript.
        $this->assertStringContainsString('data-ss-product-filters', $body,
            'the filter bar is missing from the product list');
    }
}
