<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `CUST-003` — A STOREFRONT SEARCH MUST NOT OFFER A PRODUCT THE MERCHANT HAS UNPUBLISHED.
 * =================================================================================================
 *
 * `CUST-003` is «بحث ذكي ومرادفات وفلاتر ديناميكية» — *smart search, synonyms and dynamic filters*.
 * `DECISION-ID-REGISTER.md` §4.3 lists it among the recovered requirements the 350-row traceability
 * matrix **never tracked at all**, on a phase declared engineering-complete.
 *
 * -------------------------------------------------------------------------------------------------
 * THE MEASUREMENT, AND WHY THIS IS A CONSISTENCY FIX RATHER THAN A NEW POLICY
 * -------------------------------------------------------------------------------------------------
 *
 * `products.status` is `integer default 1 comment '0 => Inactive, 1 => Active'`. The storefront has
 * **three** doors in `CUST-003`'s own surface, and they disagreed:
 *
 *   `HomeController::product_page_filter`  the web filter panel  — `where('status', 1)` ✔
 *   `HomeController::search_products`      the web typeahead     — **no status predicate**
 *   `Api\ApiController::search`            both its branches     — **no status predicate**
 *
 * So the one door that FILTERS is the sibling of the two that search, on the same controller, for the
 * same store, in the same requirement. That is what makes this engineering rather than a product
 * decision: no new rule is invented, the existing rule is applied to the two doors that skipped it.
 *
 * **IT IS NOT A PLATFORM-WIDE FIX AND MUST NOT BE READ AS ONE.** A comment-stripped,
 * brace-matched-per-method census of both controllers found **26 methods** that read `products`
 * without a `status` predicate, among them `place_order`, `apply_coupon`, `confirm_order`,
 * `ordersave`, `product_detail` and `wishlist`. Several of those MUST NOT filter it — whether a
 * product deactivated mid-checkout can still be paid for, whether it vanishes from a cart, and
 * whether a direct link to it 404s are each a product rule with a different answer, and inventing
 * them here is what `CLAUDE.md` §4.1 forbids. Those are reported as an owner question; only the
 * search doors are changed. {@see self::test_the_census_of_unfiltered_storefront_readers_is_recorded}
 * pins the number so the question cannot be lost.
 *
 * -------------------------------------------------------------------------------------------------
 * HOW A TEST OF THIS PASSES FOR THE WRONG REASON
 * -------------------------------------------------------------------------------------------------
 *
 *  - **`ApiResponser::error()` ANSWERS HTTP 200** with `{status: 0}`, and the search handler's own
 *    "Product not found" branch is one of those. So "the inactive product is absent" is satisfied by
 *    a 500, a refusal, or an empty answer for any other reason. Every case asserts the ACTIVE
 *    product is present in the same response.
 *  - **`Product` IS `Cachable` AND HAS NO EFFECTIVE GLOBAL SCOPE.** It is in `StoreScope`'s registry
 *    but absent from `config/tenancy.php`'s `scoped_models`, so the scope short-circuits for every
 *    principal — the store predicate in each handler is the whole control. Fixtures are written
 *    through Eloquent (which flushes) rather than `DB::table()`, because
 *    `SearchPriceBandFailsOpenTest` records that file being green for the wrong reason until a
 *    `flushCache()` was added after a raw insert.
 */
final class SearchDoesNotOfferUnpublishedProductsTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Store, 1: Product, 2: Product} active then inactive */
    private function storeWithAPublishedAndAnUnpublishedProduct(string $tag): array
    {
        [$merchant, $store] = $this->makeTenant();

        $category = Category::forceCreate([
            'name' => $tag . '-cat', 'slug' => $tag . '-cat-' . uniqid(),
            'store_id' => $store->id, 'status' => 1,
        ]);

        /* ONE SHARED NEEDLE in both names, so a single search term reaches both rows. */
        $needle = $tag . 'needle';

        $make = function (string $suffix, int $status) use ($needle, $store, $category, $merchant): Product {
            return Product::forceCreate([
                'name' => $needle . '-' . $suffix,
                'slug' => $needle . '-' . $suffix . '-' . uniqid(),
                'price' => 100.00, 'sale_price' => 100.00,
                'product_stock' => 10, 'track_stock' => 0, 'variant_product' => 0,
                'status' => $status, 'stock_status' => 'in_stock',
                'category_id' => $category->id, 'average_rating' => 4,
                'store_id' => $store->id, 'created_by' => $merchant->id,
            ]);
        };

        return [$store, $make('published', 1), $make('unpublished', 0)];
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Search', 'last_name' => 'Shopper',
            'email' => 'search-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 1 — the web typeahead
    |--------------------------------------------------------------------------
    */

    public function test_the_web_typeahead_offers_the_published_product_and_not_the_unpublished_one(): void
    {
        [$store, $published, $unpublished] = $this->storeWithAPublishedAndAnUnpublishedProduct('web');

        $response = $this->get('/' . $store->slug . '/search-product?product=' . urlencode('webneedle'));

        $this->assertSame(200, $response->status(),
            'premise: the typeahead answered. Body: ' . $response->getContent());

        $names = array_column((array) $response->json(), 'name');

        $this->assertContains($published->name, $names,
            'PREMISE, and it is load-bearing: the PUBLISHED product must be in the answer. Without '
            . 'this, "the unpublished one is absent" is satisfied by an empty response, a 500, or '
            . 'the handler\'s own no-match branch.');

        $this->assertNotContains($unpublished->name, $names,
            'the typeahead offered a product the merchant has unpublished, with a working link to '
            . 'it. `product_page_filter`, the sibling door in the same requirement, already '
            . 'filters `status`.');
    }

    /** And when EVERYTHING matching is unpublished, the answer is the handler's no-match branch. */
    public function test_a_search_matching_only_unpublished_products_finds_nothing(): void
    {
        [$store, $published, $unpublished] = $this->storeWithAPublishedAndAnUnpublishedProduct('only');

        $response = $this->get('/' . $store->slug . '/search-product?product='
            . urlencode('onlyneedle-unpub'));

        $this->assertSame(200, $response->status());

        $names = array_column((array) $response->json(), 'name');

        /*
         * THE SEARCH TERM IS A PROPER PREFIX OF THE PRODUCT'S NAME, NOT EQUAL TO IT, AND THE FIRST
         * VERSION OF THIS CASE WAS WRONG ABOUT THAT. The handler's no-match branch echoes the
         * SEARCH TERM back as `name` with an `/error` url, so a term byte-identical to the
         * unpublished product's name satisfied `assertNotContains($unpublished->name, …)` through
         * the echo rather than through the product. `onlyneedle-unpub` matches the product's
         * `LIKE %…%` and is not its name, so the two cannot be confused.
         */
        $this->assertNotContains($unpublished->name, $names,
            'the typeahead returned a product the merchant has unpublished.');

        $this->assertNotContains($published->name, $names,
            'premise: this term really does not match the published product, so the case above is '
            . 'measuring the status predicate and not a term mismatch.');

        $this->assertContains('onlyneedle-unpub', $names,
            'premise: the handler took its NO-MATCH branch, which echoes the term back. Without '
            . 'this the case is also satisfied by a 500 or an empty body.');
    }

    /**
     * **THE SEARCH DROPDOWN ON EVERY STOREFRONT PAGE — the widest of the three doors.**
     *
     * `ViewServiceProvider` builds `$search_products` as `Product::where('store_id', …)
     * ->pluck('name', 'id')` — the WHOLE catalogue, no status predicate — and all three themes'
     * `front_end/partison/header.blade.php` iterate it into the search dropdown. So every page of
     * every storefront embedded the names of the merchant's unpublished products, in the page
     * source, whether or not anybody searched.
     *
     * **TWO LINES ABOVE IT, `$CategoryList` IS `Category::where('status', 1)`.** The inconsistency
     * is inside one block of one method, which is the clearest evidence this is an omission rather
     * than a decision.
     *
     * Driven by rendering a real storefront page through the real view composer, because the
     * composer is the thing under test and a unit call on the query would not prove the page
     * carries the names.
     */
    public function test_the_storefront_header_dropdown_does_not_carry_unpublished_product_names(): void
    {
        config(['app.debug' => false]);

        [$store, $published, $unpublished] = $this->storeWithAPublishedAndAnUnpublishedProduct('hdr');

        /*
         * `theme_id` IS SET EXPLICITLY. `config('theme.active')` is `stylique`, so a store with an
         * empty `theme_id` renders stylique's views anyway (CLAUDE.md §6) -- but the storefront home
         * route is `/{slug}/home`, not `/{slug}`, and the header partial that carries the dropdown
         * is the THEME's. Naming the theme makes the rendered partial a premise rather than a
         * coincidence of the config default.
         */
        $store->theme_id = 'stylique';
        $store->save();

        $response = $this->get('/' . $store->slug . '/home');

        $this->assertSame(200, $response->status(),
            'premise: the storefront home page rendered, so the view composer ran.');

        /*
         * ASSERTED ON THE COMPOSER'S OWN DATASET, NOT ON THE WHOLE PAGE BODY, AND THE FIRST VERSION
         * OF THIS CASE DID THE LATTER AND STAYED RED AFTER A CORRECT FIX.
         *
         * Measured: with the dropdown fixed, `/{slug}/home` STILL contained the unpublished
         * product's name — twice, from a `<x-product-card>` in stylique's category block, fed by
         * `$category->product_details`. That relation is
         * `hasMany(Product::class, 'category_id', 'id')` with NO status predicate, so it is a
         * SEPARATE unfiltered reader on a different surface (a home-page listing, not search), with
         * four eager-load call sites and a `count()` a merchant reads. It is reported, not changed
         * here. A body-wide assertion therefore measures every product reader on the page at once
         * and cannot say which one this change is about — the same "scope the search to the block"
         * rule `CLAUDE.md` §3 records for a stylesheet census.
         *
         * `search_products` is the composer's key and the three themes' header partials iterate
         * exactly it, so this is the dropdown's contents and nothing else.
         */
        $data = $response->original->getData();

        $this->assertArrayHasKey('search_products', $data,
            'premise: the view composer ran and handed the header its dataset. Without this the '
            . 'assertions below pass vacuously.');

        /*
         * `collect(...)->values()->all()`, NOT `(array) $data['search_products']`. The composer
         * hands over a `pluck('name', 'id')` COLLECTION, and casting a Collection with `(array)`
         * yields its OBJECT PROPERTIES — `['items' => [...]]` — so the names were one level down
         * and the premise assertion below failed against a correct fix. Caught by that premise,
         * which is why it is there.
         */
        $names = collect($data['search_products'])->values()->all();

        $this->assertContains($published->name, $names,
            'PREMISE: the published product really is offered in the dropdown. Without this, the '
            . 'absence below is satisfied by an empty dataset — a broken fixture or an early return.');

        $this->assertNotContains($unpublished->name, $names,
            'the search dropdown on every storefront page carried the name of a product the '
            . 'merchant has unpublished.');
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — the API, both branches
    |--------------------------------------------------------------------------
    */

    /** @return array<int, int> the product ids the API answered with */
    private function apiSearchIds($store, Customer $shopper, array $payload): array
    {
        $token = $shopper->createToken('search')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $store->slug . '/search', $payload);

        $this->assertNotSame(404, $response->status(),
            'premise: api/{slug}/search exists. An absence assertion is satisfied by a 404.');
        $this->assertSame(1, (int) $response->json('status'), sprintf(
            'premise: the search SUCCEEDED. `ApiResponser::error()` answers HTTP 200 with status 0 '
            . 'and this handler\'s own "Product not found" branch is one of those, so the status '
            . 'code cannot tell an empty result from a refusal. Body: %s', $response->getContent()
        ));

        return array_map('intval', array_column((array) $response->json('data.data'), 'id'));
    }

    public function test_the_api_product_search_branch_excludes_an_unpublished_product(): void
    {
        [$store, $published, $unpublished] = $this->storeWithAPublishedAndAnUnpublishedProduct('aps');
        $shopper = $this->shopper($store);

        $ids = $this->apiSearchIds($store, $shopper, [
            'type' => 'product_search', 'name' => 'apsneedle',
        ]);

        $this->assertContains((int) $published->id, $ids,
            'premise: the published product is in the answer.');
        $this->assertNotContains((int) $unpublished->id, $ids,
            'the `product_search` branch returned an unpublished product.');
    }

    public function test_the_api_product_filter_branch_excludes_an_unpublished_product(): void
    {
        [$store, $published, $unpublished] = $this->storeWithAPublishedAndAnUnpublishedProduct('apf');
        $shopper = $this->shopper($store);

        $ids = $this->apiSearchIds($store, $shopper, [
            'type' => 'product_filter', 'name' => 'apfneedle',
        ]);

        $this->assertContains((int) $published->id, $ids,
            'premise: the published product is in the answer.');
        $this->assertNotContains((int) $unpublished->id, $ids,
            'the `product_filter` branch returned an unpublished product. It is the API twin of '
            . '`product_page_filter`, which already filters `status`.');
    }

    /**
     * AND WITH NO `name` AT ALL — the filter branch's own "everything in the store" shape, which is
     * where an unfiltered reader does the most damage.
     */
    public function test_the_api_filter_branch_excludes_it_even_with_no_search_term(): void
    {
        [$store, $published, $unpublished] = $this->storeWithAPublishedAndAnUnpublishedProduct('bare');
        $shopper = $this->shopper($store);

        $ids = $this->apiSearchIds($store, $shopper, ['type' => 'product_filter']);

        $this->assertContains((int) $published->id, $ids,
            'premise: a bare filter really does list the store\'s products.');
        $this->assertNotContains((int) $unpublished->id, $ids);
    }

    /**
     * TENANCY, unchanged by this fix and asserted because the predicate being added sits beside the
     * store one. `Product` has NO effective global scope, so `where('store_id', …)` is the whole
     * control and a fix that touched the query must not have disturbed it.
     */
    public function test_the_search_still_does_not_reach_another_stores_catalogue(): void
    {
        [$storeA, $publishedA] = $this->storeWithAPublishedAndAnUnpublishedProduct('tenA');
        [$storeB, $publishedB] = $this->storeWithAPublishedAndAnUnpublishedProduct('tenB');

        $shopper = $this->shopper($storeA);

        /* Store B's product is PUBLISHED, so only the store predicate can exclude it. */
        $ids = $this->apiSearchIds($storeA, $shopper, [
            'type' => 'product_filter', 'name' => 'needle',
        ]);

        $this->assertContains((int) $publishedA->id, $ids, 'premise: store A\'s product is found.');
        $this->assertNotContains((int) $publishedB->id, $ids,
            'store B\'s published product reached a search of store A.');
    }

    /*
    |--------------------------------------------------------------------------
    | 3 — the census this fix deliberately did NOT act on
    |--------------------------------------------------------------------------
    */

    /**
     * **THE OWNER QUESTION, PINNED SO IT CANNOT BE LOST.**
     *
     * Most storefront readers of `products` apply no `status` predicate, and that is NOT fixed here
     * because the right answer differs per door and every variant is a product rule: whether a
     * product deactivated mid-checkout can still be paid for (`place_order`, `confirm_order`,
     * `ordersave`), whether it vanishes from a cart already holding it (`cart_check_guest`,
     * `apply_coupon`), and whether a direct link to it 404s (`product_detail`).
     *
     * The census is **comment-stripped and brace-matched per method body**, because both files
     * discuss `status` at length and `CLAUDE.md` §3 records eight occasions where a census was
     * satisfied or broken by prose about the thing it measures. It asserts a BOUND rather than an
     * exact list, so closing one door does not redden it — only widening the problem does.
     */
    public function test_the_census_of_unfiltered_storefront_readers_is_recorded(): void
    {
        $unfiltered = [];

        foreach ([
            'app/Http/Controllers/Api/ApiController.php',
            'app/Http/Controllers/HomeController.php',
        ] as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            /* Comments first, both forms. A docblock here names several of these methods. */
            $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
            $source = (string) preg_replace('#^\s*//.*$#m', '', $source);

            $this->assertStringContainsString('Product::', $source, sprintf(
                'premise: %s still reads the products table, so the census is looking at the right '
                . 'file and the stripper did not empty it.', basename($relative)
            ));

            foreach ($this->methodBodies($source) as $name => $body) {
                if (! preg_match('/Product::\s*(where|select|query)/', $body)) {
                    continue;
                }

                if (! preg_match('/where\(\s*[\'"]status[\'"]\s*,\s*1/', $body)) {
                    $unfiltered[] = basename($relative, '.php') . '::' . $name;
                }
            }
        }

        $this->assertNotSame([], $unfiltered,
            'premise: the census found something. An empty list would make the bound below vacuous.');

        $this->assertLessThanOrEqual(24, count($unfiltered), sprintf(
            'MORE storefront readers of `products` now apply no `status` predicate than when this '
            . 'was measured (24 after the two search doors were fixed). Either a new unfiltered '
            . 'reader was added, or the census broke. The list: %s',
            implode(', ', $unfiltered)
        ));

        foreach (['ApiController::search', 'HomeController::search_products'] as $fixed) {
            $this->assertNotContains($fixed, $unfiltered, sprintf(
                '%s is one of the two doors this change fixed; it must not be back in the census.',
                $fixed
            ));
        }
    }

    /**
     * Method bodies, brace-matched from each declaration.
     *
     * BRACE-MATCHED AND NOT "DECLARATION TO END OF FILE", which `GAP-277`'s seeder census got wrong
     * and had corrected in review: reading to the end of a 10,000-line file makes the census answer
     * a question about the whole file.
     *
     * @return array<string, string>
     */
    private function methodBodies(string $source): array
    {
        $bodies = [];

        if (! preg_match_all('/public function (\w+)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return $bodies;
        }

        foreach ($matches[1] as $index => [$name, ]) {
            $open = strpos($source, '{', $matches[0][$index][1]);

            if ($open === false) {
                continue;
            }

            $depth = 0;

            for ($i = $open, $len = strlen($source); $i < $len; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $bodies[$name] = substr($source, $open, $i - $open + 1);
                        break;
                    }
                }
            }
        }

        return $bodies;
    }
}
