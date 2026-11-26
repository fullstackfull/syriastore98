<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-117 / P03-MP-01 — THE PER-STORE XML SITEMAP. The "الفهرسة" (indexing) half of
 * `MASTER-PRODUCT-PLAN-v1.0-BASE.md:222`.
 *
 * MEASURED BEFORE THIS CHANGE: `grep -rni sitemap routes/ app/Http/Controllers/` returned
 * nothing. There was no sitemap of any kind, for any store or for the platform site.
 *
 * -------------------------------------------------------------------------------------------------
 * THE SITEMAP IS PER STORE, because the storefront is multi-tenant by PATH prefix
 * (`/{storeSlug}/...`) on one host. A single `/sitemap.xml` at the host root would have to
 * enumerate every tenant's catalogue in one document, which is both a cross-tenant disclosure
 * (one merchant reading the full product list of every other) and unbounded in size. So each
 * store gets `/{storeSlug}/sitemap.xml` holding only its own URLs.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TENANCY CONTROL IS EXPLICIT, AND CLAUDE.md IS THE REASON.
 *
 * `Product` and `Category` carry `StoreScope` but are NOT in `config/tenancy.php`'s
 * `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and the scope adds NO predicate
 * for any principal — `getGlobalScopes()` answers `[StoreScope]` for both, which is why checking
 * that asks the wrong question. A sitemap built with `Product::where('status', 1)->get()` would
 * therefore have published EVERY tenant's catalogue under every tenant's slug. Both queries name
 * `store_id` explicitly, and `test_the_sitemap_never_lists_another_stores_products` is the case
 * that reddens if either predicate is removed.
 *
 * The assertions below read the rows through `withoutGlobalScopes()` where they read rows at all,
 * per CLAUDE.md's note that a plain `find()` on a scoped model answers against whichever tenant
 * happened to resolve.
 */
class StorefrontSitemapTest extends TestCase
{
    use RefreshDatabase;

    private User $merchantA;

    private Store $storeA;

    private User $merchantB;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * THE ROUTE DECLARATION IS FIRST-PARTY CODE, AND THIS IS NOT A TEST-ONLY ROUTE.
         *
         * `routes/web.php` was shared with another work package in this round, so the one line
         * that calls the declaration is handed over in the report rather than applied here. The
         * URI, the name and the action all live in
         * `App\Support\Storefront\StorefrontSitemapRoute`, so what the cases below drive is the
         * production declaration itself and not an imitation of it. `declare()` is guarded by
         * `Route::has()`, so once that line is in `routes/web.php` this call becomes a no-op and
         * these tests go on measuring the real route.
         *
         * A test that wrote its own `Route::get(...)` here would have proved the controller works
         * at a URI of the test's choosing and nothing about the one production serves.
         */
        \App\Support\Storefront\StorefrontSitemapRoute::declare();

        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has(\App\Support\Storefront\StorefrontSitemapRoute::NAME),
            'premise: the sitemap route is declared'
        );

        [$this->merchantA, $this->storeA] = $this->makeTenant('Aleppo Soap House', 'map-store-a');
        [$this->merchantB, $this->storeB] = $this->makeTenant('Damascene Brocade', 'map-store-b');
    }

    /** @return array{0: User, 1: Store} */
    private function makeTenant(string $name, string $slug, int $active = 1): array
    {
        $merchant = User::forceCreate([
            'name' => 'Merchant ' . $slug,
            'email' => $slug . '@example.test',
            'password' => bcrypt('x'),
            'type' => 'admin',
            'created_by' => 1,
            'email_verified_at' => now(),
        ]);

        $store = Store::forceCreate([
            'name' => $name,
            'email' => 'store-' . $slug . '@example.test',
            'slug' => $slug,
            'theme_id' => 'stylique',
            'created_by' => $merchant->id,
            'is_active' => $active,
            'default_language' => 'en',
        ]);

        $merchant->current_store = $store->id;
        $merchant->save();

        return [$merchant, $store];
    }

    private function makeProduct(Store $store, User $merchant, string $name, string $slug, int $status = 1): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => $slug,
            'status' => $status,
            'track_stock' => 0,
            'price' => 10,
            'sale_price' => 10,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);
    }

    /** `categories` has no `created_by` column — measured, not assumed from the sibling tables. */
    private function makeCategory(Store $store, string $name, string $slug, int $status = 1): Category
    {
        return Category::forceCreate([
            'name' => $name,
            'slug' => $slug,
            'status' => $status,
            'store_id' => $store->id,
        ]);
    }

    /** @return array<int,string> every <loc> in the document, parsed as XML rather than grepped */
    private function locationsOf(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'the sitemap must be well-formed XML');

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $locations = [];
        foreach ($xpath->query('//s:urlset/s:url/s:loc') as $element) {
            $locations[] = $element->textContent;
        }

        return $locations;
    }

    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_a_store_has_a_sitemap(): void
    {
        $response = $this->get('/' . $this->storeA->slug . '/sitemap.xml');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('xml', strtolower((string) $response->headers->get('Content-Type')));
        $this->assertNotEmpty($this->locationsOf($response->getContent()));
    }

    #[Test]
    public function test_the_sitemap_lists_the_stores_own_content_pages(): void
    {
        $locations = $this->locationsOf($this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent());

        foreach (['landing_page', 'page.product-list', 'collections', 'page.about', 'page.faq', 'page.contact_us', 'privacy_page'] as $name) {
            $this->assertContains(route($name, $this->storeA->slug), $locations, "missing {$name}");
        }
    }

    /** A basket or a sign-in form in a sitemap is an instruction to crawl a private page. */
    #[Test]
    public function test_the_sitemap_lists_no_private_or_transactional_page(): void
    {
        $locations = $this->locationsOf($this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent());

        foreach (['page.cart', 'wishlist', 'customer.login', 'customer.register', 'track.order'] as $name) {
            $this->assertNotContains(route($name, $this->storeA->slug), $locations, "{$name} must not be listed");
        }
    }

    #[Test]
    public function test_the_sitemap_lists_the_stores_active_products(): void
    {
        $this->makeProduct($this->storeA, $this->merchantA, 'Laurel Soap', 'laurel-soap');

        $locations = $this->locationsOf($this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent());

        $this->assertContains(
            route('page.product', [$this->storeA->slug, 'laurel-soap']),
            $locations
        );
    }

    #[Test]
    public function test_the_sitemap_omits_an_inactive_product(): void
    {
        $this->makeProduct($this->storeA, $this->merchantA, 'Retired Soap', 'retired-soap', 0);

        $locations = $this->locationsOf($this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent());

        $this->assertNotContains(
            route('page.product', [$this->storeA->slug, 'retired-soap']),
            $locations
        );
    }

    /** A product with no slug has no URL; listing one would publish a 404. */
    #[Test]
    public function test_the_sitemap_omits_a_product_with_no_slug(): void
    {
        $product = $this->makeProduct($this->storeA, $this->merchantA, 'Slugless', 'placeholder');
        $product->slug = null;
        $product->save();

        $locations = $this->locationsOf($this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent());

        foreach ($locations as $location) {
            $this->assertStringNotContainsString('/product/', $location);
        }
    }

    /**
     * THE CASE THAT MATTERS. `Product` is not in `scoped_models`, so the only thing keeping store
     * B's catalogue out of store A's sitemap is the handler's own `where('store_id', …)`.
     */
    #[Test]
    public function test_the_sitemap_never_lists_another_stores_products(): void
    {
        $this->makeProduct($this->storeA, $this->merchantA, 'A Soap', 'a-soap');
        $this->makeProduct($this->storeB, $this->merchantB, 'B Brocade', 'b-brocade');

        $this->assertSame(
            2,
            Product::withoutGlobalScopes()->count(),
            'premise: both products really are in the table'
        );

        $a = $this->locationsOf($this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent());
        $b = $this->locationsOf($this->get('/' . $this->storeB->slug . '/sitemap.xml')->getContent());

        $this->assertContains(route('page.product', [$this->storeA->slug, 'a-soap']), $a);
        $this->assertNotContains(route('page.product', [$this->storeB->slug, 'b-brocade']), $a);
        $this->assertNotContains(route('page.product', [$this->storeA->slug, 'b-brocade']), $a);

        $this->assertContains(route('page.product', [$this->storeB->slug, 'b-brocade']), $b);
        $this->assertNotContains(route('page.product', [$this->storeA->slug, 'a-soap']), $b);

        // And no URL in either document names the other store's slug at all.
        foreach ($a as $location) {
            $this->assertStringNotContainsString($this->storeB->slug, $location);
        }
        foreach ($b as $location) {
            $this->assertStringNotContainsString($this->storeA->slug, $location);
        }
    }

    #[Test]
    public function test_the_sitemap_lists_the_stores_active_categories_and_not_another_stores(): void
    {
        $this->makeCategory($this->storeA, 'Soaps', 'soaps');
        $this->makeCategory($this->storeA, 'Hidden', 'hidden', 0);
        $this->makeCategory($this->storeB, 'Textiles', 'textiles');

        $locations = $this->locationsOf($this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent());

        $this->assertContains(route('collections.all', [$this->storeA->slug, 'soaps']), $locations);
        $this->assertNotContains(route('collections.all', [$this->storeA->slug, 'hidden']), $locations);
        $this->assertNotContains(route('collections.all', [$this->storeA->slug, 'textiles']), $locations);
    }

    /**
     * THE PANEL-SESSION TRAP, AT THE SITEMAP DOOR. Added because a mutation survived without it.
     *
     * The mutation was `Store::find(getCurrentStore()) ?? Store::where('slug', $storeSlug)…` — the
     * exact substitution CLAUDE.md warns about — and every case in this file passed, because no
     * case held a `web`-guard principal, so `getCurrentStore()` answered null and the `??` fell
     * through to the correct lookup. The containment was untested rather than absent, which is the
     * failure mode this project's revert-and-rerun rule exists for.
     *
     * In production the session cookie path is `/` and storefront routes are in the `web` group,
     * so a merchant signed into their own panel is a populated web-guard principal on EVERY
     * storefront request they make. Under the mutation, merchant A fetching store B's sitemap was
     * served STORE A's catalogue at store B's URL: a cross-tenant product-list disclosure in a
     * document whose whole purpose is to be published.
     */
    #[Test]
    public function test_a_merchant_holding_a_panel_session_gets_the_sitemap_of_the_store_in_the_url(): void
    {
        $this->makeProduct($this->storeA, $this->merchantA, 'A Soap', 'a-soap');
        $this->makeProduct($this->storeB, $this->merchantB, 'B Brocade', 'b-brocade');

        $this->actingAs($this->merchantA, 'web');
        $this->assertSame(
            $this->storeA->id,
            getCurrentStore(),
            'premise: the web guard answers for store A on every request this visitor makes'
        );

        $locations = $this->locationsOf($this->get('/' . $this->storeB->slug . '/sitemap.xml')->getContent());

        $this->assertContains(route('page.product', [$this->storeB->slug, 'b-brocade']), $locations);
        $this->assertNotContains(route('page.product', [$this->storeB->slug, 'a-soap']), $locations);

        foreach ($locations as $location) {
            $this->assertStringNotContainsString('a-soap', $location);
            $this->assertStringStartsWith(url('/' . $this->storeB->slug) . '/', $location);
        }
    }

    /**
     * AN AMPERSAND IN A PRODUCT SLUG WOULD OTHERWISE DESTROY THE WHOLE DOCUMENT, AND THE ESCAPING
     * IS THEREFORE LOAD-BEARING RATHER THAN DEFENSIVE. Measured, because the first write-up of
     * this controller called it defensive on the assumption that `route()` encodes everything:
     *
     *     route('page.product', ['amp-store', 'a&b<c>d'])
     *       => http://localhost/amp-store/product/a&b%3Cc%3Ed
     *
     * `<` and `>` are percent-encoded. **`&` IS NOT.** So a raw `&` reaches `<loc>`, and a bare
     * `&` in XML character data is not an entity reference — the document is then not well-formed
     * and a crawler rejects the WHOLE sitemap, not the one URL. `products.slug` is a plain
     * `string` column with no constraint, written by more than one path, so this is a reachable
     * state rather than a hypothetical.
     *
     * The assertion is the PROPERTY ("the document still parses, and the URL still means what it
     * meant"), not one build's exact text — CLAUDE.md records two exact-text pins of a parser edge
     * failing on CI's newer libxml while their outputs were inert.
     */
    #[Test]
    public function test_an_ampersand_in_a_slug_does_not_break_the_document(): void
    {
        $product = $this->makeProduct($this->storeA, $this->merchantA, 'Soap and Oil', 'placeholder');
        $product->slug = 'a&b<c>d';
        $product->save();

        $body = $this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent();

        // locationsOf() asserts the document is well-formed XML, which is the whole point here.
        $locations = $this->locationsOf($body);

        $this->assertContains(route('page.product', [$this->storeA->slug, 'a&b<c>d']), $locations);
        $this->assertStringNotContainsString('<loc>http://localhost/' . $this->storeA->slug . '/product/a&b', $body);
    }

    /**
     * THE ROUTE IS THROTTLED. Added after working this change through
     * `.claude/agents/tenant-authz-reviewer.md`'s shape 7 against its own new route: an anonymous
     * GET that reads up to `StorefrontSeo::SITEMAP_MAX_URLS` product rows and as many category rows
     * is an amplifier if it is free to call, and every store's slug is public.
     *
     * Asserted on the ROUTE's declared middleware rather than by driving 31 requests: the
     * behavioural version would be slow, and `ThrottleRequests` keys on the IP, which is constant
     * across a test run, so a 31st request in one test would also throttle every later test's
     * requests in the same process. What must not be silently lost is the DECLARATION.
     */
    /**
     * A SITEMAP MUST CLAIM EXACTLY WHAT THE SHOP CLAIMS, and the first draft of the handler did
     * not — found by working this change through `.claude/agents/tenant-authz-reviewer.md`.
     *
     * Every host storefront product listing carries `->where('product_type', null)`
     * (`HomeController` 1172, 1251, 1294, 1332, 1459), so an `'auction'` or `'digital'` product
     * never appears on the shop's own list. `product_detail` has NO such predicate, so those
     * products are reachable by direct URL — which is precisely why advertising them in a sitemap
     * would change their discoverability rather than merely describe it.
     *
     * The control leg is the point: a plain product in the same store IS listed, so this case
     * cannot pass by the handler returning nothing.
     */
    #[Test]
    public function test_the_sitemap_lists_only_the_product_kinds_the_shop_itself_lists(): void
    {
        $this->makeProduct($this->storeA, $this->merchantA, 'Plain Soap', 'plain-soap');

        foreach (['auction', 'digital'] as $kind) {
            $typed = $this->makeProduct($this->storeA, $this->merchantA, ucfirst($kind), $kind . '-thing');
            $typed->product_type = $kind;
            $typed->save();
        }

        $this->assertSame(
            3,
            Product::withoutGlobalScopes()->where('store_id', $this->storeA->id)->count(),
            'premise: all three products are in the table'
        );

        $locations = $this->locationsOf($this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent());

        // the control: an ordinary product of the same store IS listed
        $this->assertContains(route('page.product', [$this->storeA->slug, 'plain-soap']), $locations);

        foreach (['auction-thing', 'digital-thing'] as $slug) {
            $this->assertNotContains(route('page.product', [$this->storeA->slug, $slug]), $locations);
        }
    }

    #[Test]
    public function test_the_sitemap_route_is_throttled(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName(\App\Support\Storefront\StorefrontSitemapRoute::NAME);

        $this->assertNotNull($route, 'premise: the route exists');

        $throttles = array_values(array_filter(
            $route->gatherMiddleware(),
            static fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')
        ));

        $this->assertNotEmpty(
            $throttles,
            'the sitemap route must carry a throttle; its middleware is: '
            . implode(', ', array_map(static fn ($m) => is_string($m) ? $m : gettype($m), $route->gatherMiddleware()))
        );
    }

    #[Test]
    public function test_an_unknown_slug_has_no_sitemap(): void
    {
        $this->assertSame(404, $this->get('/no-such-store/sitemap.xml')->getStatusCode());
    }

    /**
     * A deactivated store's pages are already withheld from the index by the robots directive
     * (StorefrontSeoIndexingTest), so publishing a sitemap for it would contradict the page.
     */
    #[Test]
    public function test_a_deactivated_store_has_no_sitemap(): void
    {
        [, $closed] = $this->makeTenant('Closed Shop', 'map-store-closed', 0);

        /*
         * The premise is the ROW, not a rendered page: a deactivated store's storefront renders
         * or answers 503 depending on whether this process has already served another store on
         * the same theme (measured in StorefrontSeoIndexingTest's own docblock), so asserting a
         * status for `/home` here would be asserting an ordering. What matters is that the store
         * EXISTS and is only refused for being inactive — otherwise the 404 below would pass just
         * as well against a missing row and prove nothing.
         */
        $this->assertNotNull(Store::where('slug', $closed->slug)->first(), 'premise: the row exists');
        $this->assertSame(
            200,
            $this->get('/' . $this->storeA->slug . '/sitemap.xml')->getStatusCode(),
            'premise: an ACTIVE store at the same URI shape answers 200, so the 404 is about is_active'
        );

        $this->assertSame(404, $this->get('/' . $closed->slug . '/sitemap.xml')->getStatusCode());
    }

    /** Every URL in the document must be inside this store's own path prefix. */
    #[Test]
    public function test_every_url_is_inside_this_stores_prefix(): void
    {
        $this->makeProduct($this->storeA, $this->merchantA, 'A Soap', 'a-soap');
        $this->makeCategory($this->storeA, 'Soaps', 'soaps');

        $prefix = rtrim(route('landing_page', $this->storeA->slug), '/home');

        foreach ($this->locationsOf($this->get('/' . $this->storeA->slug . '/sitemap.xml')->getContent()) as $location) {
            $this->assertStringStartsWith(
                url('/' . $this->storeA->slug) . '/',
                $location,
                $location . ' is outside ' . $prefix
            );
        }
    }
}
