<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Support\Search\ProductNameIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * `CUST-003` — A SHOPPER MUST FIND AN ARABIC PRODUCT WITHOUT TYPING ITS ORTHOGRAPHY.
 * =================================================================================================
 *
 * «بحث ذكي ومرادفات وفلاتر ديناميكية». `GAP-190` / owner decision `J38` sized this exactly: the
 * storefront's product search is one `LIKE %term%` on `products.name`, and **normalising the query
 * alone is insufficient because `LIKE` cannot normalise the haystack**. So the fold is stored
 * (`products.name_normalised`, written by `App\Observers\ProductNameIndexObserver`) and
 * `App\Support\Search\ProductNameIndex::applyNameFilter()` asks both columns.
 *
 * -------------------------------------------------------------------------------------------------
 * THE THREE DOORS THAT SEARCH BY NAME, MEASURED
 * -------------------------------------------------------------------------------------------------
 *
 * `GAP-351` names four storefront search doors, and only **three** of them match on a name:
 *
 *   `HomeController::search_products`            the web typeahead      — `name LIKE %term%`
 *   `Api\ApiController::search` `product_search` the API typeahead      — `name LIKE %term%`
 *   `Api\ApiController::search` `product_filter` the API filter panel   — `name LIKE %term%`
 *   `HomeController::product_page_filter`        the web filter panel   — **no name predicate at
 *                                                                        all**; category, brand,
 *                                                                        price band and sort only
 *   `ViewServiceProvider` `$search_products`     the header dropdown    — `pluck('name','id')` of
 *                                                                        the whole catalogue, no
 *                                                                        term, matched in the
 *                                                                        browser
 *
 * So the fourth and fifth doors are untouched by this slice and that is a measurement, not an
 * omission: there is nothing to normalise where there is no term.
 * {@see self::test_the_two_doors_that_do_not_match_on_a_name_are_recorded} pins it, so a name
 * predicate added to either one later cannot quietly skip the fold.
 *
 * -------------------------------------------------------------------------------------------------
 * HOW A TEST OF THIS PASSES FOR THE WRONG REASON — each of these is handled below
 * -------------------------------------------------------------------------------------------------
 *
 *  - **ONE DIRECTION PROVES HALF OF IT.** The first draft of `ProductNameIndex` skipped the folded
 *    limb when `fold(term) === term`, which looks like a free optimisation and silently loses the
 *    COMMONEST case: the shopper types the bare `شاشه` (which folds to itself) and the merchant
 *    stored `شاشة`. Every door is driven in BOTH directions.
 *  - **`ApiResponser::error()` ANSWERS HTTP 200** with `{status: 0}`, so "the product is in the
 *    answer" must be read off the payload and the success flag asserted as a premise.
 *  - **`Product` HAS NO EFFECTIVE GLOBAL SCOPE** — it is in `StoreScope`'s registry but absent from
 *    `config/tenancy.php`'s `scoped_models` — so each handler's own `store_id` is the entire
 *    isolation, and an ungrouped `orWhere` would annihilate it. A second store's matching product
 *    is present in the database for every case, and asserted absent from every answer.
 *  - **A GREEN RUN WITH NO COLUMN PROVES NOTHING.** `isStored()` is memoised per process, and the
 *    whole feature is a no-op when it is false. `test_the_premise_the_column_exists_and_is_filled`
 *    asserts the column, the observer's write, and that the fold really differs from the stored
 *    name — so a case that passes through the raw limb alone cannot masquerade as a fold match.
 */
final class ArabicProductSearchTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** `شاشة ذكية` — screen, written correctly: teh marbuta twice. */
    private const WRITTEN = 'شاشة ذكية';

    /** What a shopper types: both teh marbutas as heh. Folds to itself. */
    private const TYPED = 'شاشه ذكيه';

    protected function setUp(): void
    {
        parent::setUp();

        /* The memo survives between tests in one process and the migration ran in THIS one. */
        ProductNameIndex::forgetStored();
    }

    /* ======================================================================== fixtures */

    private function makeStoreWithCategory(string $tag): array
    {
        [$merchant, $store] = $this->makeTenant();

        $category = Category::forceCreate([
            'name' => $tag . '-cat', 'slug' => $tag . '-cat-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id, 'status' => 1,
        ]);

        return [$merchant, $store, $category];
    }

    private function makeProduct($store, $merchant, Category $category, string $name, int $status = 1): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'p-' . $this->uniqueFixtureRef(),
            'price' => 100.00, 'sale_price' => 100.00,
            'product_stock' => 10, 'track_stock' => 0, 'variant_product' => 0,
            'status' => $status, 'stock_status' => 'in_stock',
            'category_id' => $category->id, 'average_rating' => 4,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Arabic', 'last_name' => 'Shopper',
            'email' => 'ar-' . $this->uniqueFixtureRef() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * One store holding the product under test, plus a SECOND store holding a product with the
     * SAME name, plus an unpublished one in the first store with the same name.
     *
     * The two decoys are what make the grouping of the `OR` testable at all: an ungrouped
     * `orWhere` annihilates both the `store_id` and the `status` predicate, and the only way to see
     * that is to have a row each predicate is the sole reason to exclude.
     *
     * @return array{0: Store, 1: Product, 2: Product, 3: Product} store, needle, foreign, unpublished
     */
    private function sceneWithDecoys(string $tag, string $storedName): array
    {
        [$merchant, $store, $category] = $this->makeStoreWithCategory($tag);

        $needle = $this->makeProduct($store, $merchant, $category, $storedName);
        $unpublished = $this->makeProduct($store, $merchant, $category, $storedName, 0);

        [$otherMerchant, $otherStore, $otherCategory] = $this->makeStoreWithCategory($tag . 'o');
        $foreign = $this->makeProduct($otherStore, $otherMerchant, $otherCategory, $storedName);

        return [$store, $needle, $foreign, $unpublished];
    }

    /* ======================================================================== the doors */

    /** @return list<string> the names the web typeahead answered with */
    private function typeahead(Store $store, string $term): array
    {
        $response = $this->get('/' . $store->slug . '/search-product?product=' . urlencode($term));

        $this->assertSame(200, $response->status(),
            'premise: the typeahead answered. Body: ' . $response->getContent());

        return array_column((array) $response->json(), 'name');
    }

    /** @return list<int> the product ids the API answered with */
    private function apiSearchIds(Store $store, Customer $shopper, array $payload): array
    {
        $token = $shopper->createToken('arabic-search')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $store->slug . '/search', $payload);

        $this->assertNotSame(404, $response->status(),
            'premise: api/{slug}/search exists. An absence assertion is satisfied by a 404.');

        $this->assertSame(1, (int) $response->json('status'), sprintf(
            'premise: the search SUCCEEDED. `ApiResponser::error()` answers HTTP 200 with status 0 '
            . 'and this handler\'s "Product not found" branch is one of those. Body: %s',
            $response->getContent()
        ));

        return array_map('intval', array_column((array) $response->json('data.data'), 'id'));
    }

    /* ======================================================================== 0 — premises */

    /**
     * THE PREMISE EVERY CASE BELOW RESTS ON, ASSERTED BEFORE ANY OF THEM.
     *
     * Without this, a run against a database with no `name_normalised` column passes every case
     * through the raw limb for a term that happens to match, and reports a working feature.
     */
    public function test_the_premise_the_column_exists_and_is_filled(): void
    {
        $this->assertTrue(ProductNameIndex::isStored(),
            'premise: products.' . ProductNameIndex::COLUMN . ' exists. The whole feature is a '
            . 'deliberate no-op without it, so every case below would pass vacuously.');

        [$merchant, $store, $category] = $this->makeStoreWithCategory('prem');
        $product = $this->makeProduct($store, $merchant, $category, self::WRITTEN);

        $stored = DB::table('products')->where('id', $product->id)->value(ProductNameIndex::COLUMN);

        $this->assertSame(ProductNameIndex::normalise(self::WRITTEN), $stored,
            'premise: the observer wrote the fold on create.');

        $this->assertNotSame(self::WRITTEN, $stored,
            'premise, AND IT IS THE POINT: the fold must actually DIFFER from the stored name. If '
            . 'the two were equal, a match through the folded limb would be indistinguishable from '
            . 'a match through the raw one and no case below would measure anything.');

        $this->assertSame(self::TYPED, ProductNameIndex::normalise(self::TYPED),
            'premise: the TYPED spelling folds to itself. This is what makes the "both directions" '
            . 'cases below different questions rather than the same one twice.');
    }

    /* ======================================================================== 1 — the web typeahead */

    public function test_the_web_typeahead_finds_a_correctly_spelled_product_from_a_plainly_typed_term(): void
    {
        [$store, $needle, $foreign, $unpublished] = $this->sceneWithDecoys('wt1', self::WRITTEN);

        $names = $this->typeahead($store, self::TYPED);

        $this->assertContains($needle->name, $names, sprintf(
            'a shopper typing %s did not find the product named %s. The stored fold is %s and the '
            . 'term folds to %s; they are equal, so the search is not asking the folded column.',
            self::TYPED, self::WRITTEN,
            ProductNameIndex::normalise(self::WRITTEN), ProductNameIndex::normalise(self::TYPED)
        ));

        /*
         * THIS DOOR ANSWERS NAMES, NOT IDS, AND ALL THREE FIXTURE ROWS CARRY THE SAME NAME — so
         * "the foreign row is absent" cannot be asked by name and is asked by COUNT instead. Three
         * rows in the database spell `شاشة ذكية`: the publishable one in this store, an unpublished
         * sibling, and one in a second store. Exactly one may come back, and which predicate each
         * exclusion rests on is measured individually by the API cases and by
         * `test_an_unpublished_product_is_still_excluded`.
         */
        $this->assertCount(1, array_filter($names, fn (string $n): bool => $n === self::WRITTEN),
            sprintf('the typeahead answered %d rows spelled %s. Only the published row in this '
                . 'store may come back; the unpublished sibling (id %d) and the other store\'s '
                . 'product (id %d) must not. Got: %s',
                count(array_filter($names, fn (string $n): bool => $n === self::WRITTEN)),
                self::WRITTEN, $unpublished->id, $foreign->id,
                json_encode($names, JSON_UNESCAPED_UNICODE)));
    }

    public function test_the_web_typeahead_finds_a_plainly_stored_product_from_a_correctly_spelled_term(): void
    {
        [$store, $needle, , ] = $this->sceneWithDecoys('wt2', self::TYPED);

        $names = $this->typeahead($store, self::WRITTEN);

        $this->assertContains($needle->name, $names, sprintf(
            'THE OTHER DIRECTION, and the one a naive implementation gets wrong the other way: the '
            . 'merchant stored the plain %s and the shopper typed the decorated %s. The term folds '
            . 'to %s, which is the stored fold.',
            self::TYPED, self::WRITTEN, ProductNameIndex::normalise(self::WRITTEN)
        ));
    }

    /** Diacritics and tatweel, which a merchant writes and nobody types. */
    public function test_the_web_typeahead_finds_a_product_written_with_diacritics_and_tatweel(): void
    {
        [$merchant, $store, $category] = $this->makeStoreWithCategory('wt3');
        $needle = $this->makeProduct($store, $merchant, $category, 'مِفْتَاح حاســوب');

        $this->assertContains($needle->name, $this->typeahead($store, 'مفتاح حاسوب'),
            'tashkeel and tatweel in the stored name kept the product unreachable.');
    }

    /* ======================================================================== 2 — API product_search */

    public function test_the_api_product_search_branch_finds_it_in_both_directions(): void
    {
        [$store, $needle, $foreign, $unpublished] = $this->sceneWithDecoys('as1', self::WRITTEN);
        $shopper = $this->shopper($store);

        $ids = $this->apiSearchIds($store, $shopper, ['type' => 'product_search', 'name' => self::TYPED]);

        $this->assertContains((int) $needle->id, $ids, 'typed term did not reach the written name');
        $this->assertNotContains((int) $foreign->id, $ids,
            'ANOTHER STORE\'S product came back. `Product` has no effective global scope, so the '
            . 'handler\'s `store_id` is the whole isolation and an ungrouped `orWhere` destroys it '
            . '— the shape of the live cross-tenant disclosure `GAP-341` records in this handler.');
        $this->assertNotContains((int) $unpublished->id, $ids,
            'an UNPUBLISHED product came back. `GAP-351` closed that at this door and the new '
            . '`OR` must not reopen it.');

        [$store2, $needle2, $foreign2, ] = $this->sceneWithDecoys('as2', self::TYPED);
        $ids2 = $this->apiSearchIds($store2, $this->shopper($store2),
            ['type' => 'product_search', 'name' => self::WRITTEN]);

        $this->assertContains((int) $needle2->id, $ids2, 'the other direction did not match');
        $this->assertNotContains((int) $foreign2->id, $ids2, 'cross-store leak in the other direction');
    }

    /* ======================================================================== 3 — API product_filter */

    public function test_the_api_product_filter_branch_finds_it_in_both_directions(): void
    {
        [$store, $needle, $foreign, $unpublished] = $this->sceneWithDecoys('af1', self::WRITTEN);
        $shopper = $this->shopper($store);

        $ids = $this->apiSearchIds($store, $shopper, ['type' => 'product_filter', 'name' => self::TYPED]);

        $this->assertContains((int) $needle->id, $ids, 'typed term did not reach the written name');
        $this->assertNotContains((int) $foreign->id, $ids, 'another store\'s product came back');
        $this->assertNotContains((int) $unpublished->id, $ids, 'an unpublished product came back');

        [$store2, $needle2, $foreign2, ] = $this->sceneWithDecoys('af2', self::TYPED);
        $ids2 = $this->apiSearchIds($store2, $this->shopper($store2),
            ['type' => 'product_filter', 'name' => self::WRITTEN]);

        $this->assertContains((int) $needle2->id, $ids2, 'the other direction did not match');
        $this->assertNotContains((int) $foreign2->id, $ids2, 'cross-store leak in the other direction');
    }

    /**
     * THE FILTER BRANCH COMBINES THE NAME WITH OTHER PREDICATES, AND THE `OR` MUST NOT ESCAPE THEM.
     *
     * A category filter and a folded name in one request. If the group were missing, the name limb
     * would satisfy the whole `WHERE` on its own and the category would be ignored.
     */
    public function test_the_filter_branchs_other_predicates_survive_the_folded_name_limb(): void
    {
        [$merchant, $store, $wanted] = $this->makeStoreWithCategory('afc');

        $other = Category::forceCreate([
            'name' => 'afc-other', 'slug' => 'afc-other-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id, 'status' => 1,
        ]);

        $inWanted = $this->makeProduct($store, $merchant, $wanted, self::WRITTEN);
        $inOther = $this->makeProduct($store, $merchant, $other, self::WRITTEN);

        $ids = $this->apiSearchIds($store, $this->shopper($store), [
            'type' => 'product_filter', 'name' => self::TYPED, 'tag' => (string) $wanted->id,
        ]);

        $this->assertContains((int) $inWanted->id, $ids,
            'premise: the wanted category\'s product is in the answer, so the case measures the '
            . 'category predicate and not an empty result.');

        $this->assertNotContains((int) $inOther->id, $ids,
            'the category filter was ignored. That is what an ungrouped `orWhere` does: the folded '
            . 'name limb satisfies the whole WHERE by itself.');
    }

    /* ======================================================================== 4 — the degradation path */

    /**
     * **THE ASSERTION THIS SLICE RESTS ON.** A product whose fold was never written must still be
     * findable exactly as it is today.
     *
     * Every row in production has a NULL fold until the owner runs the backfill, and the backfill
     * is explicitly not this workstream's to run. So this is not an edge case — it is the state of
     * the entire platform at the moment the migration lands, and a regression here would make
     * products disappear from search rather than merely fail to gain a new way of being found.
     *
     * The NULL is written with a raw `DB::table()` update AND the model cache is flushed after it:
     * `Product` is `Cachable`, and `CLAUDE.md` records three measured false readings from exactly
     * this shape. The raw write is the only way to produce the state, because the observer's whole
     * job is to prevent it.
     */
    public function test_a_product_with_no_stored_fold_is_still_found_at_every_door(): void
    {
        [$merchant, $store, $category] = $this->makeStoreWithCategory('null');
        $needle = $this->makeProduct($store, $merchant, $category, self::WRITTEN);

        DB::table('products')->where('id', $needle->id)->update([ProductNameIndex::COLUMN => null]);
        (new Product)->flushCache();

        $this->assertNull(
            DB::table('products')->where('id', $needle->id)->value(ProductNameIndex::COLUMN),
            'premise: the fold really is NULL, so this case measures the degradation path.'
        );

        $this->assertContains($needle->name, $this->typeahead($store, self::WRITTEN),
            'the web typeahead lost a product whose fold is NULL. Every row on the platform is in '
            . 'that state until the backfill runs.');

        $shopper = $this->shopper($store);

        $this->assertContains((int) $needle->id,
            $this->apiSearchIds($store, $shopper, ['type' => 'product_search', 'name' => self::WRITTEN]),
            'the API typeahead lost a product whose fold is NULL.');

        $this->assertContains((int) $needle->id,
            $this->apiSearchIds($store, $shopper, ['type' => 'product_filter', 'name' => self::WRITTEN]),
            'the API filter lost a product whose fold is NULL.');
    }

    /**
     * AND A **STALE** FOLD IS NOT WORSE THAN NO FOLD.
     *
     * A raw writer that bypasses Eloquent (none exists today — `ProductNameIndexObserver`'s
     * docblock records the census) would leave the column holding a previous name. The raw limb
     * still answers for the current one.
     */
    public function test_a_product_with_a_stale_stored_fold_is_still_found_by_its_real_name(): void
    {
        [$merchant, $store, $category] = $this->makeStoreWithCategory('stale');
        $needle = $this->makeProduct($store, $merchant, $category, self::WRITTEN);

        DB::table('products')->where('id', $needle->id)
            ->update([ProductNameIndex::COLUMN => ProductNameIndex::normalise('شيء اخر تماما')]);
        (new Product)->flushCache();

        $this->assertContains($needle->name, $this->typeahead($store, self::WRITTEN),
            'a stale fold hid a product from a search for its real name.');
    }

    /* ======================================================================== 5 — the Latin control */

    /**
     * **A MERCHANT'S ENGLISH CATALOGUE MUST BEHAVE IDENTICALLY, AND THIS IS ASSERTED POSITIVELY.**
     *
     * Not "nothing broke" — the exact row set, compared against the query the doors ran BEFORE this
     * change: `Product::where('store_id', …)->where('status', 1)->where('name','LIKE','%term%')`.
     * That expectation is computed here from the same table, so it cannot drift from the fixture.
     *
     * The catalogue carries the shapes that could differ if the fold did more than lower-case:
     * mixed case, an em dash against an ASCII hyphen, an accent, and a literal `%` (which stays a
     * `LIKE` wildcard at these doors today and must keep doing so).
     */
    public function test_a_latin_catalogue_answers_identically_at_every_door(): void
    {
        [$merchant, $store, $category] = $this->makeStoreWithCategory('latin');

        foreach ([
            'Blue Cotton Shirt', 'blue cotton SHIRT', 'Sony — WH-1000', 'Sony - WH-1000',
            'Café Noir', 'Cafe Noir', '50% Linen Mix', 'ACME-500/XL',
        ] as $name) {
            $this->makeProduct($store, $merchant, $category, $name);
        }

        $shopper = $this->shopper($store);

        foreach (['Blue', 'blue cotton', 'SHIRT', 'Sony -', 'Sony —', 'Caf', '50%', '%Linen', 'acme-500'] as $term) {
            /* Today's predicate, run against the same rows. The control, not an expectation list. */
            $expected = Product::query()->withoutGlobalScopes()
                ->where('store_id', $store->id)->where('status', 1)
                ->where('name', 'LIKE', '%' . $term . '%')
                ->orderBy('id')->pluck('id')->map('intval')->all();

            $this->assertNotSame([], $expected,
                'premise: the term "' . $term . '" matches something under the OLD predicate, so '
                . 'the comparisons below are not two empty sets.');

            $search = $this->apiSearchIds($store, $shopper, ['type' => 'product_search', 'name' => $term]);
            sort($search);
            $this->assertSame($expected, $search,
                'the API typeahead changed what a Latin-script query returns for "' . $term . '"');

            $filter = $this->apiSearchIds($store, $shopper, ['type' => 'product_filter', 'name' => $term]);
            sort($filter);
            $this->assertSame($expected, $filter,
                'the API filter changed what a Latin-script query returns for "' . $term . '"');

            $expectedNames = Product::query()->withoutGlobalScopes()->whereIn('id', $expected)
                ->orderBy('id')->pluck('name')->all();
            $got = $this->typeahead($store, $term);
            sort($expectedNames);
            sort($got);
            $this->assertSame($expectedNames, $got,
                'the web typeahead changed what a Latin-script query returns for "' . $term . '"');
        }
    }

    /* ======================================================================== 5b — odd terms */

    /**
     * AN EMPTY TERM AND AN ARRAY TERM BOTH ANSWER WHAT THEY ANSWERED BEFORE.
     *
     * **The array case is a regression this slice nearly shipped.** The first draft of
     * `applyNameFilter()` typed its term `?string`, and every caller hands it `$request->name` or
     * `$request->product` — which a query string or JSON body can make an array (`?product[]=x`).
     * Measured: `'%' . ['x'] . '%'` is `"%Array%"` plus a warning, while a `?string` parameter
     * raises a **TypeError**, i.e. HTTP 500 on a public storefront door. The term is untyped and
     * stringified instead.
     *
     * The empty case pins the other half of the same question: `search_products` calls the filter
     * unconditionally, so `/{slug}/search-product` with no `product` at all is a reachable request.
     * Today it compiles `name LIKE '%%'` and answers with the whole published catalogue; the
     * short-circuit answers with the whole published catalogue by applying no name predicate. Those
     * are the same rows, which is why a mutant removing the short-circuit survives — it is a
     * query-shape choice, not a behaviour, and this case is the control that says so.
     */
    public function test_an_empty_term_and_an_array_term_answer_exactly_as_they_did_before(): void
    {
        [$merchant, $store, $category] = $this->makeStoreWithCategory('odd');

        $published = $this->makeProduct($store, $merchant, $category, self::WRITTEN);
        $unpublished = $this->makeProduct($store, $merchant, $category, 'قميص مخفي', 0);

        /* --- no term at all: the whole published catalogue, as today. */
        $names = $this->typeahead($store, '');

        $this->assertContains($published->name, $names,
            'an empty term stopped returning the published catalogue. Today it compiles '
            . '`name LIKE \'%%\'`, which matches every row.');
        $this->assertNotContains($unpublished->name, $names,
            'and the status predicate still applies with no term.');

        /* --- an ARRAY term: must not 500. */
        $response = $this->get('/' . $store->slug . '/search-product?product[]=' . urlencode('قميص'));

        $this->assertSame(200, $response->status(),
            'an array-valued `product` made the typeahead throw. A `?string` type hint on the '
            . 'filter does exactly that: TypeError, HTTP 500, on a public door. Body: '
            . $response->getContent());

        $arrayNames = array_column((array) $response->json(), 'name');

        $this->assertNotContains($published->name, $arrayNames,
            'an array term matched a real product. It must reproduce today\'s "%Array%", which '
            . 'matches effectively nothing — and must NOT be read as "no filter", which would '
            . 'answer with the whole catalogue (the GAP-339 shape).');

        /* And through the API filter branch, where `! empty(['x'])` is TRUE and the guard passes. */
        $ids = $this->apiSearchIds($store, $this->shopper($store), [
            'type' => 'product_filter', 'name' => ['قميص'],
        ]);

        $this->assertNotContains((int) $published->id, $ids,
            'the API filter branch treated an array term as "no name filter" and answered with the '
            . 'store\'s whole catalogue.');
    }

    /* ======================================================================== 6 — GAP-351 holds */

    /** The status predicate `GAP-351` closed must survive the new `OR`. Driven at the web door too. */
    public function test_an_unpublished_product_is_still_excluded(): void
    {
        [$merchant, $store, $category] = $this->makeStoreWithCategory('unpub');

        $published = $this->makeProduct($store, $merchant, $category, 'قميص منشور');
        $unpublished = $this->makeProduct($store, $merchant, $category, 'قميص مخفي', 0);

        $names = $this->typeahead($store, 'قميص');

        $this->assertContains($published->name, $names,
            'premise: the published product is offered, so the absence below is not an empty answer.');
        $this->assertNotContains($unpublished->name, $names,
            'the folded limb let an unpublished product back into the typeahead. `GAP-351` closed '
            . 'this door; an ungrouped `orWhere` reopens it.');
    }

    /* ======================================================================== 7 — the recorded census */

    /**
     * THE TWO DOORS THIS SLICE DOES NOT TOUCH, RECORDED SO A LATER NAME PREDICATE CANNOT SKIP THE
     * FOLD SILENTLY.
     *
     * Keyed on the handler's own source with comments stripped, because `CLAUDE.md` §3 records
     * eight occasions on which a census was satisfied — or broken — by prose about the thing it
     * measures, and this very docblock names the predicate it searches for.
     */
    public function test_the_two_doors_that_do_not_match_on_a_name_are_recorded(): void
    {
        $home = $this->sourceWithoutComments('app/Http/Controllers/HomeController.php');
        $provider = $this->sourceWithoutComments('app/Providers/ViewServiceProvider.php');

        $filterBody = $this->methodBody($home, 'product_page_filter');

        $this->assertNotSame('', $filterBody, 'premise: product_page_filter was located.');
        $this->assertStringNotContainsString("'name', 'LIKE'", $filterBody,
            'HomeController::product_page_filter has gained a name predicate. It must go through '
            . 'App\Support\Search\ProductNameIndex::applyNameFilter() or Arabic shoppers lose this '
            . 'door.');
        $this->assertStringNotContainsString("'name', 'like'", $filterBody,
            'as above, lower-case spelling.');

        $this->assertStringContainsString("pluck('name', 'id')", $provider,
            'premise: the header dropdown is still a whole-catalogue pluck with no term. If it has '
            . 'become a term search it needs the fold.');
    }

    /** Brace-matched body of one method, so a census cannot be satisfied by a sibling method. */
    private function methodBody(string $source, string $method): string
    {
        $at = strpos($source, 'function ' . $method . '(');

        if ($at === false) {
            return '';
        }

        $open = strpos($source, '{', $at);
        $depth = 0;

        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }
}
