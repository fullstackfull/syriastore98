<?php

namespace Tests\Unit\Seo;

use App\Support\Storefront\StorefrontSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-117 / P03-MP-01 — EVERY STOREFRONT PAGE HAS AN EXPLICIT INDEXING VERDICT.
 * =================================================================================================
 *
 * `StorefrontSeo` defaults an unclassified route to `noindex`, which is the safe direction but
 * also the silent one: a merchant's new content page would quietly never be indexed, and nobody
 * would find out from a passing suite. This census is the other half of that default — it turns
 * the blocking Unit gate red the moment a storefront GET route exists that nobody has ruled on,
 * in EITHER direction.
 *
 * -------------------------------------------------------------------------------------------------
 * HOW THE SET IS DERIVED, AND WHY NOT BY SPELLING.
 *
 * CLAUDE.md records four censuses in this repository that missed what they claimed to cover, each
 * because it was keyed on a spelling: uri words, `Route::post` lines, a uri prefix, and an
 * exemption scoped to the methods somebody happened to look at. So this one is keyed on the thing
 * the code under test itself keys on: `StorefrontSeo::forRequest()` takes the store from
 * `$route->parameter('storeSlug')` and returns null for any route without it, so the set that
 * needs a verdict is exactly **the GET routes that declare a `storeSlug` parameter**. Derivation
 * and subject are then the same question, and a route cannot fall between them.
 *
 * THE FIRST DERIVATION WAS WIDER AND WRONG, WHICH IS WHY THIS IS WRITTEN DOWN. It read "a route
 * whose FIRST URI segment is a parameter", on the reasoning that the storefront is multi-tenant by
 * path prefix. That is true of the storefront and also true of four MERCHANT PANEL routes that
 * happen to begin with `{id}` — `{id}/stores-link`, `{id}/free-shipping-edit`,
 * `{id}/local-shipping-edit` and `{id}/{amount}/plan-paypal-payment`. They were demanding an
 * indexing verdict for pages `StorefrontSeo` never runs on.
 * `test_no_get_route_takes_its_store_from_a_differently_named_parameter` keeps that over-capture
 * VISIBLE rather than quietly dropped: the four are declared by name with a reason, and a fifth
 * appearing fails the gate so somebody reads it.
 *
 * IT WAS ALSO NARROWER AND WRONG, WHICH IS THE HALF THAT MATTERED. Two routes carry `storeSlug`
 * WITHOUT it being the first segment, so the first derivation never asked about them:
 * `theme-draft/{storeSlug}/{version}/preview` (`theme.draft.preview`) and
 * `store-get-Powertranz-status{storeSlug?}` (`store.get.Powertranz.status`). The first renders the
 * storefront layout for a theme version the merchant has NOT published — indexing it would publish
 * a store's unreleased design — and it was found by this census rather than by reading the routes
 * file. Both are now withheld.
 *
 * WHAT IS READ: the HOST application's routes, GET or HEAD.
 * WHAT IS NOT: every route of the 70 vendored WorkDo modules. CLAUDE.md records that no module
 * loads in any test run unless the test inserts an `add_on_managers` row, so module routes are
 * structurally invisible here — and a module's own storefront page therefore has no verdict and
 * gets the `noindex` default. That is stated rather than hidden; closing it needs the
 * out-of-process census pattern (`tests/Support/Security/bin/route-census.php`) and is named as
 * not done in the evidence document.
 *
 * A reachability assertion guards against the whole thing passing vacuously on an empty set.
 */
class StorefrontSeoRouteCensusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Storefront GET routes that are deliberately given no indexing verdict, each with the
     * reason it cannot have one. Keep this list empty if at all possible.
     *
     * @var array<string,string>
     */
    private const UNCLASSIFIABLE = [
        // Laravel's own parameterised debug/asset routes would appear here if any existed.
    ];

    /**
     * GET routes whose FIRST URI segment is a parameter that is NOT a store slug — so they are
     * not storefront pages and `StorefrontSeo` never runs on them. Declared by name with the
     * reason, so the over-capture described in this class's docblock stays visible.
     *
     * @var array<string,string>
     */
    private const NOT_A_STORE_SLUG_FIRST_SEGMENT = [
        'stores.link' => '{id} is a STORE id on a merchant panel screen, not a slug',
        'free-shipping.edit' => '{id} is a shipping-method id on a merchant panel screen',
        'local-shipping.edit' => '{id} is a shipping-method id on a merchant panel screen',
        'plan.get.payment.status' => '{id}/{amount} are a plan id and an amount on a PayPal return leg',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The sitemap route's declaration lives in `App\Support\Storefront\StorefrontSitemapRoute`
         * and `routes/web.php` needs one line to call it (see that class's docblock — the file was
         * shared with another work package this round, so the line is handed over in the report
         * rather than applied here). Calling it is idempotent: once that line is in place this
         * becomes a no-op and the census measures the production declaration.
         */
        \App\Support\Storefront\StorefrontSitemapRoute::declare();
    }

    /** @return array<int,\Illuminate\Routing\Route> */
    private function storefrontGetRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // The same question `StorefrontSeo::forRequest()` asks, and the only one that matters.
            if (! in_array('storeSlug', $route->parameterNames(), true)) {
                continue;
            }

            $routes[] = $route;
        }

        return $routes;
    }

    #[Test]
    public function test_the_census_sees_a_realistic_number_of_storefront_routes(): void
    {
        // Reachability: if the derivation breaks, every assertion below passes on an empty set.
        $this->assertGreaterThan(
            50,
            count($this->storefrontGetRoutes()),
            'the storefront route set collapsed — the derivation, not the classification, is wrong'
        );
    }

    #[Test]
    public function test_every_storefront_get_route_has_an_explicit_indexing_verdict(): void
    {
        $classified = array_merge(StorefrontSeo::INDEXABLE_ROUTES, StorefrontSeo::WITHHELD_ROUTES);

        $unruled = [];

        foreach ($this->storefrontGetRoutes() as $route) {
            $name = $route->getName();

            if ($name !== null && in_array($name, $classified, true)) {
                continue;
            }

            if ($name !== null && array_key_exists($name, self::UNCLASSIFIABLE)) {
                continue;
            }

            $unruled[] = ($name ?? '(unnamed)') . '   ' . $route->uri() . '   ' . $route->getActionName();
        }

        $this->assertSame(
            [],
            $unruled,
            "These storefront GET routes have no indexing verdict. Add each route NAME to either\n"
            . "App\\Support\\Storefront\\StorefrontSeo::INDEXABLE_ROUTES or ::WITHHELD_ROUTES.\n"
            . "An unnamed route cannot be classified at all and needs a name first.\n\n"
            . implode("\n", $unruled) . "\n"
        );
    }

    /**
     * THE OTHER DIRECTION. A classified name that no route answers to any more is a classification
     * for a page that no longer exists — and, if the route was renamed rather than deleted, the
     * new name is unclassified and silently `noindex`. CLAUDE.md: assert the set in both
     * directions, because an unexpectedly-passing declaration is as much a failure as a red one.
     */
    #[Test]
    public function test_no_classified_route_name_is_stale(): void
    {
        $live = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->getName() !== null) {
                $live[$route->getName()] = true;
            }
        }

        $stale = [];
        foreach (array_merge(StorefrontSeo::INDEXABLE_ROUTES, StorefrontSeo::WITHHELD_ROUTES) as $name) {
            if (! isset($live[$name])) {
                $stale[] = $name;
            }
        }

        $this->assertSame(
            [],
            $stale,
            "These names are classified in StorefrontSeo but no route answers to them. They were\n"
            . "renamed or removed; a renamed route's NEW name is now unclassified and silently\n"
            . "noindex.\n\n" . implode("\n", $stale) . "\n"
        );
    }

    /**
     * Every route this class calls indexable must actually be a GET page a crawler could fetch.
     * A POST-only name in the indexable list would be a verdict that can never apply.
     */
    #[Test]
    public function test_every_indexable_route_is_a_storefront_get_page(): void
    {
        $storefrontGetNames = [];
        foreach ($this->storefrontGetRoutes() as $route) {
            if ($route->getName() !== null) {
                $storefrontGetNames[$route->getName()] = true;
            }
        }

        $notPages = [];
        foreach (StorefrontSeo::INDEXABLE_ROUTES as $name) {
            if (! isset($storefrontGetNames[$name])) {
                $notPages[] = $name;
            }
        }

        $this->assertSame([], $notPages, implode(', ', $notPages));
    }

    /**
     * THE OVER-CAPTURE THE FIRST DERIVATION MADE, KEPT VISIBLE.
     *
     * A route whose first URI segment is a parameter looks like a storefront route and need not
     * be one. The four that are not are declared above with a reason; a fifth fails this case so
     * that somebody reads it rather than it being silently absent from the verdict set. If one of
     * them ever DOES take a store slug, its name has to move into a verdict list.
     */
    #[Test]
    public function test_no_get_route_takes_its_store_from_a_differently_named_parameter(): void
    {
        $storefrontNames = [];
        foreach ($this->storefrontGetRoutes() as $route) {
            if ($route->getName() !== null) {
                $storefrontNames[$route->getName()] = true;
            }
        }

        $undeclared = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $first = explode('/', trim($route->uri(), '/'))[0] ?? '';

            if (! str_starts_with($first, '{')) {
                continue;
            }

            $name = $route->getName();

            if ($name !== null && isset($storefrontNames[$name])) {
                continue;   // a real storefront route; the verdict census covers it
            }

            if ($name !== null && array_key_exists($name, self::NOT_A_STORE_SLUG_FIRST_SEGMENT)) {
                continue;
            }

            $undeclared[] = ($name ?? '(unnamed)') . '   ' . $route->uri() . '   ' . $route->getActionName();
        }

        $this->assertSame(
            [],
            $undeclared,
            "A GET route begins with a parameter that is neither a `storeSlug` nor declared in\n"
            . "NOT_A_STORE_SLUG_FIRST_SEGMENT. Decide which it is: if it takes a store slug under\n"
            . "another parameter name, StorefrontSeo::forRequest() cannot see it and it needs\n"
            . "fixing; if it is a panel route, declare it with its reason.\n\n"
            . implode("\n", $undeclared) . "\n"
        );
    }

    /** The four declared exemptions must still exist, or the declaration is describing nothing. */
    #[Test]
    public function test_every_declared_non_storefront_exemption_is_a_live_route(): void
    {
        $live = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->getName() !== null) {
                $live[$route->getName()] = true;
            }
        }

        foreach (array_keys(self::NOT_A_STORE_SLUG_FIRST_SEGMENT) as $name) {
            $this->assertArrayHasKey($name, $live, "{$name} is exempted but no longer exists");
        }
    }

    /**
     * THE COMMENT TRAP. CLAUDE.md records six measured instances of a census satisfied — or
     * broken — by prose about the thing it measures. This census reads the ROUTER rather than
     * source text, so it cannot be fooled by a comment; this case pins that property by proving
     * that a name appearing ONLY in a comment in StorefrontSeo's own source is not treated as
     * classified.
     */
    #[Test]
    public function test_the_census_reads_the_router_and_not_the_source_text(): void
    {
        $source = file_get_contents((new \ReflectionClass(StorefrontSeo::class))->getFileName());

        $this->assertStringContainsString(
            'census-trap-name-in-a-comment-only',
            $source,
            'the trap sentinel must be present in StorefrontSeo as a comment, or this case proves nothing'
        );
        $this->assertNotContains('census-trap-name-in-a-comment-only', StorefrontSeo::INDEXABLE_ROUTES);
        $this->assertNotContains('census-trap-name-in-a-comment-only', StorefrontSeo::WITHHELD_ROUTES);
        $this->assertSame(
            'noindex, follow',
            StorefrontSeo::robotsDirectiveFor('census-trap-name-in-a-comment-only', true)
        );
    }
}
