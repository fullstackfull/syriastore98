<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-234 — `sub-category` AND `sub-category-guest` RETURN EVERY STORE'S UNCATEGORISED PRODUCTS.
 * =============================================================================================
 *
 * The statement, in both twins, with no store predicate at all:
 *
 *     Product::where('category_id', $request->category_id)->where('product_type', null)->paginate(10)
 *
 * It is reached through the `else` of `if (Category::find($request->category_id))`. WITH NO
 * `category_id` POSTED, `Category::find(null)` is null, the else runs, and Illuminate turns
 * `where('category_id', null)` into `whereNull('category_id')` -- and `products.category_id` is
 * nullable (2023_12_27 create_products_table:19). So a caller who posts NOTHING is handed a
 * paginated list of every tenant's uncategorised products, whole rows.
 *
 * `Product` is NOT in `config/tenancy.php`'s `scoped_models`, so the `StoreScope` it carries
 * through `BelongsToStore` is INERT -- `isActiveFor()` short-circuits and adds no predicate for
 * ANY principal. The handler's own predicate is the only control, and there was none.
 *
 * ---------------------------------------------------------------------------------------------
 * THE FIX IS TWO THINGS, AND THE SECOND IS A DELIBERATE BEHAVIOUR CHANGE.
 *
 *   1. `->where('store_id', $store->id)` on the product query. `products.store_id` is the only
 *      evidence available here -- there is no store-verified parent to gate on, because the
 *      category id is caller-supplied -- so this is the case where the column itself is the
 *      predicate.
 *
 *   2. A MISSING OR NON-INTEGER `category_id` IS REFUSED rather than silently becoming
 *      `whereNull`. A query the caller did not ask for is not the query the parameter names, and
 *      "no category" is not a category. This is why the control case below posts a REAL category
 *      id: the endpoint still answers for a request that names one, and only the request that
 *      names none is refused. There is no legitimate traffic to preserve in the else branch --
 *      it is reached only by a `category_id` that is not a category, and the two tiles the handler
 *      itself prepends for that purpose (`id => 0` "All Products" and `id => 'trending'`) match no
 *      `products.category_id` value either before or after this change.
 *
 * ---------------------------------------------------------------------------------------------
 * THREE PRINCIPALS: `custom.auth` admits a merchant's and a driver's token while
 * `auth('customers')` stays null, so a customer-only probe cannot speak for this family.
 */
class StorefrontSubCategoryTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const FOREIGN_PRODUCT = 'GAP234-FOREIGN-UNCATEGORISED-PRODUCT';

    private const OWN_PRODUCT = 'GAP234-OWN-UNCATEGORISED-PRODUCT';

    private function uncategorisedProductIn($store, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'gap234-' . uniqid(),
            'description' => 'x',
            'detail' => $name . '-DETAIL',
            'category_id' => null,
            'product_stock' => 3,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'trending' => 0,
            'status' => 1,
            'price' => 10,
            'sale_price' => 10,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function categoryIn($store, string $name): Category
    {
        return Category::forceCreate([
            'name' => $name,
            'slug' => 'gap234-cat-' . uniqid(),
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => $store->id,
        ]);
    }

    /** @return array<string,string> label => bearer token, all three tokenable classes */
    private function threeTokensFor($merchant, $store): array
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'gap234-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'gap234-drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);

        return [
            'customer' => $customer->createToken('t')->plainTextToken,
            'merchant' => $merchant->createToken('t')->plainTextToken,
            'driver' => $driver->createToken('t')->plainTextToken,
        ];
    }

    private function subCategory($store, string $path, array $payload, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/' . $path, $payload);
    }

    /** @return array<string,array<int,string>> both twins, so neither can be fixed alone */
    public static function bothRoutes(): array
    {
        return [
            'authenticated' => ['sub-category'],
            'guest' => ['sub-category-guest'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_a_request_with_no_category_id_does_not_list_other_stores_products(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $foreign = $this->uncategorisedProductIn($storeB, self::FOREIGN_PRODUCT);

        /* PREMISES. Both matter: the row must be store B's, and its `category_id` must really be
           NULL, or the `whereNull` this case is about never selects it and the case is vacuous. */
        $row = DB::table('products')->where('id', $foreign->id)->first();
        $this->assertSame((int) $storeB->id, (int) $row->store_id, 'premise: the product is not store B\'s');
        $this->assertNull($row->category_id, 'premise: the foreign product is categorised, so whereNull misses it');
        $this->assertNull($row->product_type, 'premise: product_type is set, so the handler\'s own filter misses it');
        $this->assertNotSame((int) $storeA->id, (int) $storeB->id, 'premise: one store used for both tenants');

        foreach ($this->threeTokensFor($merchantA, $storeA) as $label => $bearer) {
            /* NO category_id AT ALL -- which is the whole mechanism. */
            $response = $this->subCategory($storeA, $path, [], $bearer);

            $this->assertNotSame(500, $response->status(),
                $path . ' crashed for a ' . $label . ' token, so the assertion below would be vacuous');
            $this->assertSame(200, $response->status());

            $this->assertStringNotContainsString(self::FOREIGN_PRODUCT, (string) $response->getContent(), sprintf(
                '%s handed a %s token of store A a paginated list of store B\'s uncategorised '
                . 'products. With no category_id posted, Category::find(null) is null, the else '
                . 'branch runs, and where(\'category_id\', null) becomes whereNull. Product is NOT '
                . 'in config/tenancy.php scoped_models, so the handler\'s own predicate is the only '
                . 'control.',
                $path, $label
            ));

            $this->assertEmpty(data_get($response->json(), 'data.product.data') ?? [],
                $path . ' still built a product list from a request that named no category, for a '
                . $label . ' token');

            /*
             * AND THE REFUSAL IS EXPLICIT, not merely an empty list. This assertion is what makes
             * the `filter_var` limb of the fix testable on its own: with the store predicate in
             * place but the refusal removed, the query becomes `whereNull('category_id')` scoped to
             * store A, which returns an empty list in THIS fixture and would let the mutation
             * survive the assertion above. "No category" is not a category, and the endpoint says
             * so.
             */
            $this->assertSame(0, (int) $response->json('status'),
                $path . ' answered a request that named no category instead of refusing it, for a '
                . $label . ' token');
        }
    }

    /**
     * A FOREIGN CATEGORY ID MUST NOT STEER THE RESPONSE EITHER -- the claim of the predicate on the
     * CATEGORY lookup, which the product-list cases above cannot speak for.
     *
     * The observable is the handler's own `trending` tile: it prepends "Trending Products" when
     * the resolved category carries `trending = 1`. With the category lookup unscoped, store B's
     * flag on store B's category decides what store A's storefront shows, which is a one-bit read
     * of another tenant's category row -- and the handler then builds a subcategory list under an
     * id store A does not own.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_a_foreign_category_id_does_not_steer_the_response(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $foreignTrending = $this->categoryIn($storeB, 'GAP234-FOREIGN-TRENDING-CATEGORY');
        DB::table('categories')->where('id', $foreignTrending->id)->update(['trending' => 1]);
        (new Category())->flushCache();

        /*
         * AND A PRODUCT INSIDE THAT FOREIGN CATEGORY, which is what claims the predicate on the
         * PRODUCT list. Found by mutation: with the category lookup scoped, a VALID INTEGER naming
         * another tenant's category falls through to the `else`, and the product query there then
         * answers `where('category_id', <foreign id>)` across every store. The earlier cases could
         * not see it -- they post a missing or non-integer id, which the refusal catches first --
         * so the store predicate on that query survived them all.
         */
        $foreignInCategory = $this->uncategorisedProductIn($storeB, self::FOREIGN_PRODUCT);
        DB::table('products')->where('id', $foreignInCategory->id)->update(['category_id' => $foreignTrending->id]);
        (new Product())->flushCache();

        $this->assertSame(1, (int) DB::table('categories')->where('id', $foreignTrending->id)->value('trending'),
            'premise: the foreign category is not trending, so the tile could never appear');
        $this->assertSame((int) $storeB->id, (int) DB::table('categories')->where('id', $foreignTrending->id)->value('store_id'),
            'premise: the category is not store B\'s');
        $this->assertSame((int) $foreignTrending->id, (int) DB::table('products')->where('id', $foreignInCategory->id)->value('category_id'),
            'premise: the foreign product is not in the foreign category, so the product-list '
            . 'assertion below is vacuous');

        foreach ($this->threeTokensFor($merchantA, $storeA) as $label => $bearer) {
            $response = $this->subCategory($storeA, $path, ['category_id' => $foreignTrending->id], $bearer);

            $this->assertNotSame(500, $response->status());
            $this->assertStringNotContainsString(self::FOREIGN_PRODUCT, (string) $response->getContent(), sprintf(
                '%s listed store B\'s product to a %s token of store A, selected by store B\'s own '
                . 'category id. A valid integer naming a foreign category reaches the product '
                . 'query, so the store predicate THERE is load-bearing and not redundant with the '
                . 'missing-id refusal.',
                $path, $label
            ));
            $this->assertStringNotContainsString('Trending Products', (string) $response->getContent(), sprintf(
                '%s resolved store B\'s category for a %s token of store A -- its `trending` flag '
                . 'decided what store A\'s storefront shows. Category carries StoreScope through '
                . 'BelongsToStore but is NOT in config/tenancy.php scoped_models, so the scope is '
                . 'inert and the handler\'s own predicate is the only control.',
                $path, $label
            ));
            $this->assertStringNotContainsString('GAP234-FOREIGN-TRENDING-CATEGORY', (string) $response->getContent(),
                $path . ' echoed a foreign category\'s name to a ' . $label . ' token');
        }
    }

    /** CONTROL: the store's OWN trending category still produces the tile. */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_a_stores_own_trending_category_still_produces_the_tile(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $own = $this->categoryIn($storeA, 'GAP234-OWN-TRENDING-CATEGORY');
        DB::table('categories')->where('id', $own->id)->update(['trending' => 1]);
        (new Category())->flushCache();

        $tokens = $this->threeTokensFor($merchantA, $storeA);
        $response = $this->subCategory($storeA, $path, ['category_id' => $own->id], $tokens['customer']);

        $this->assertSame(1, (int) $response->json('status'));
        $this->assertStringContainsString('Trending Products', (string) $response->getContent(),
            $path . ' stopped resolving the store\'s OWN trending category, so the fix refuses '
            . 'legitimate traffic and the absence asserted in the case above is vacuous');
    }

    /**
     * AND NOT WITH A NON-INTEGER EITHER. `''`, `'null'` and an array all miss `Category::find()`
     * and reach the same else branch; the empty string in particular is what a client sends for an
     * unset field, and it becomes `whereNull` exactly as a missing key does.
     *
     * `category_id[]=1` is included because `Model::find(array)` returns a COLLECTION, which is an
     * object and therefore TRUTHY -- so that one takes the `if` branch and then reads
     * `->category_id` off a Collection. Whatever it does, it must not be a 500 and must not list
     * store B's rows.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_a_non_integer_category_id_does_not_list_other_stores_products(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->uncategorisedProductIn($storeB, self::FOREIGN_PRODUCT);

        $tokens = $this->threeTokensFor($merchantA, $storeA);

        foreach ([
            'an empty string' => '',
            'the word null' => 'null',
            'a zero string' => '0',
            'an array' => [1],
        ] as $what => $value) {
            $response = $this->subCategory($storeA, $path, ['category_id' => $value], $tokens['merchant']);

            $this->assertNotSame(500, $response->status(),
                $path . ' crashed on ' . $what . ' as category_id');

            $this->assertStringNotContainsString(self::FOREIGN_PRODUCT, (string) $response->getContent(),
                $path . ' listed store B\'s uncategorised products for ' . $what . ' as category_id');
        }
    }

    /**
     * CONTROL: a request that NAMES one of the store's own categories is still answered, with the
     * subcategory list the handler builds. Without this the refusal above could be satisfied by an
     * endpoint that refuses everything.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_a_stores_own_category_is_still_answered(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $parent = $this->categoryIn($storeA, 'GAP234-OWN-PARENT-CATEGORY');

        foreach ($this->threeTokensFor($merchantA, $storeA) as $label => $bearer) {
            $response = $this->subCategory($storeA, $path, ['category_id' => $parent->id], $bearer);

            $this->assertSame(200, $response->status());
            $this->assertSame(1, (int) $response->json('status'),
                $path . ' refused a legitimate same-store category for a ' . $label
                . ' token, which is a regression and not a fix');

            /* The handler prepends an "All Products" tile to the subcategory list for a category
               that resolved, so a resolved category is observable in the payload itself. */
            $this->assertStringContainsString('All Products', (string) $response->getContent(),
                $path . ' stopped resolving a same-store category for a ' . $label . ' token');
        }
    }

    /** CONTROL: and the store's OWN uncategorised product is not collateral damage of the fix. */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_the_stores_own_uncategorised_product_is_never_leaked_to_another_store(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $own = $this->uncategorisedProductIn($storeA, self::OWN_PRODUCT);

        $this->assertSame((int) $storeA->id, (int) DB::table('products')->where('id', $own->id)->value('store_id'),
            'premise: the product is not store A\'s');

        /*
         * THE MIRROR DIRECTION, AND IT NOW NAMES A REAL CATEGORY — corrected after the independent
         * review showed the earlier version could not make the claim its comment made.
         *
         * It posted `[]`, and the fix's own first limb (`filter_var(..., FILTER_VALIDATE_INT)`)
         * refuses an absent `category_id` before anything store-related is reached. So the body
         * could never contain the needle whatever the store logic did, and the old comment --
         * "a fix that keyed on the TOKEN rather than on the route's store would pass the first case
         * and fail this one" -- was simply false: neither keying is exercised by an empty payload.
         * (It did still fail if the whole fix was reverted, so it was weak rather than useless.)
         *
         * Now it names STORE A'S OWN CATEGORY while asking at STORE B'S SLUG with store B's token.
         * That reaches the category lookup, which is the predicate under test, and the two keyings
         * really do diverge: keyed on the route's store it resolves nothing and store A's product
         * cannot appear; keyed on the TOKEN it would resolve against store B and -- more to the
         * point -- a predicate keyed on neither would resolve store A's category and serve its
         * products to store B's slug.
         */
        $foreignCategory = $this->categoryIn($storeA, 'GAP234-STORE-A-PARENT');

        $tokens = $this->threeTokensFor($merchantB, $storeB);

        $response = $this->subCategory(
            $storeB,
            $path,
            ['category_id' => $foreignCategory->id],
            $tokens['merchant']
        );

        $this->assertNotSame(500, $response->status(),
            $path . ' crashed, so nothing below is measured');

        $this->assertStringNotContainsString(self::OWN_PRODUCT, (string) $response->getContent(),
            $path . ' leaked store A\'s uncategorised product into store B\'s own request');

        /* AND THE CATEGORY ITSELF MUST NOT RESOLVE. The needle above is about the product list; a
           handler that resolved store A's category for store B's slug and happened to return no
           products would pass it. The "All Products" tile is the observable the same-store control
           above uses for a category that DID resolve, so its absence is the direct claim. */
        $this->assertStringNotContainsString('All Products', (string) $response->getContent(),
            $path . ' RESOLVED store A\'s category for a request at store B\'s slug. Category is '
            . 'not a scoped model, so the handler\'s own predicate is the only thing standing '
            . 'between the two tenants\' category trees.');
    }

    /** The suffix is not a licence: `custom.auth` refuses a missing bearer before the handler. */
    public function test_both_routes_still_refuse_a_caller_with_no_token(): void
    {
        [, $storeA] = $this->makeTenant();

        foreach (['sub-category', 'sub-category-guest'] as $path) {
            $response = $this->postJson('/api/' . $storeA->slug . '/' . $path, []);

            $this->assertSame(401, $response->status(), $path . ' is anonymous');
        }
    }
}
