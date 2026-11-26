<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Support\Storefront\StorefrontSeo;
use Illuminate\Http\Response;

/**
 * ONE STORE'S XML SITEMAP.  GAP-117 / P03-MP-01 — the "الفهرسة" (indexing) half of the
 * owner-approved Phase 03 bullet at `master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md:222`.
 *
 * MEASURED BEFORE THIS FILE: `grep -rni sitemap routes/ app/Http/Controllers/` returned nothing.
 * There was no sitemap of any kind, for any store or for the platform site.
 *
 * -------------------------------------------------------------------------------------------------
 * IT IS PER STORE, AND THAT IS NOT A STYLE CHOICE. The storefront is multi-tenant by PATH PREFIX
 * (`/{storeSlug}/...`) on one host. A single host-root `/sitemap.xml` would have to enumerate every
 * tenant's catalogue in one document — a cross-tenant disclosure (any merchant reading the full
 * product list of every other) and unbounded in size. So each store publishes only its own URLs.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TENANCY CONTROL IS THE TWO EXPLICIT `store_id` PREDICATES, AND NOTHING ELSE.
 *
 * CLAUDE.md: `Product` and `Category` carry `StoreScope` but are NOT in `config/tenancy.php`'s
 * `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and the scope adds NO predicate
 * for ANY principal — while `getGlobalScopes()` still answers `[StoreScope]` for both, which is why
 * checking that asks the wrong question. `Product::where('status', 1)->get()` here would have
 * published every tenant's catalogue under every tenant's slug. `StorefrontSitemapTest::
 * test_the_sitemap_never_lists_another_stores_products` is the case that reddens if either
 * predicate is removed.
 *
 * The store itself comes from the ROUTE parameter, never from `getCurrentStore()` or `auth()`,
 * which read the DEFAULT (`web`) guard and answer for a merchant's OWN store on any storefront
 * request that merchant makes — including requests to other merchants' storefronts.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IS NOT HERE, each named rather than left to be discovered:
 *
 *  - **No sitemap INDEX.** The sitemaps protocol caps one document at 50,000 URLs; a catalogue
 *    past that needs an index document and paged children. The cap is enforced
 *    (`StorefrontSeo::SITEMAP_MAX_URLS`) and the overflow is simply not listed, which is the safe
 *    truncation — a document over the limit is rejected whole.
 *  - **No `Sitemap:` line in `public/robots.txt`,** so these are discoverable by submission to
 *    Search Console rather than automatically. That file is at the HOST root and shared with the
 *    platform's own website, so its contents are a platform-operator decision.
 *  - **No `<changefreq>` or `<priority>`.** Both are ignored by Google and inventing values for
 *    them would be noise presented as information.
 *  - **No custom pages (`page.custom`) or articles (`page.article`).** Both are indexable and both
 *    live in vendored module tables that do not load in any test run (CLAUDE.md), so they cannot
 *    be enumerated here with evidence. Named in the evidence document as not done.
 */
final class SitemapController extends Controller
{
    /**
     * The store's own content pages. A subset of `StorefrontSeo::INDEXABLE_ROUTES`: exactly the
     * indexable routes that need no parameter beyond the slug, so they can be listed without
     * enumerating anything. The parameterised ones (`page.product`, `collections.all`,
     * `brands.all`) are expanded from the store's own rows below; `page.custom` and `page.article`
     * are module-backed and are the documented omission above.
     *
     * @var array<int,string>
     */
    private const PAGE_ROUTES = [
        'landing_page',
        'page.product-list',
        'collections',
        'page.about',
        'page.faq',
        'page.blog',
        'page.contact_us',
        'privacy_page',
    ];

    public function show(string $storeSlug): Response
    {
        /*
         * `is_active` IS PART OF THE LOOKUP, not a later check. A deactivated store keeps serving
         * its whole storefront at HTTP 200 — measured: `App\Http\Middleware\ActiveTheme` filters
         * on `is_active` but its `abort('403', 'The store is not active.')` is commented out, and
         * `ViewServiceProvider`'s composer resolves the store with no `is_active` predicate at all.
         * `StorefrontSeo` answers `noindex` for such a store, so publishing a sitemap for it would
         * contradict every page it lists.
         */
        $store = Store::where('slug', $storeSlug)->where('is_active', 1)->first();

        if (! $store) {
            abort(404);
        }

        $entries = [];

        foreach (self::PAGE_ROUTES as $name) {
            $entries[] = ['loc' => route($name, $store->slug), 'lastmod' => null];
        }

        $remaining = StorefrontSeo::SITEMAP_MAX_URLS - count($entries);

        foreach ($this->categoriesOf($store, $remaining) as $category) {
            $entries[] = [
                'loc' => route('collections.all', [$store->slug, $category->slug]),
                'lastmod' => $category->updated_at?->toAtomString(),
            ];
        }

        $remaining = StorefrontSeo::SITEMAP_MAX_URLS - count($entries);

        foreach ($this->productsOf($store, $remaining) as $product) {
            $entries[] = [
                'loc' => route('page.product', [$store->slug, $product->slug]),
                'lastmod' => $product->updated_at?->toAtomString(),
            ];
        }

        return response($this->toXml($entries), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * This store's listable categories.
     *
     * `where('store_id', …)` is the tenant predicate — see the class docblock. `status = 1` matches
     * what the storefront itself lists (`HomeController::product_page` builds `$MainCategoryList`
     * with `Category::where('status', 1)->where('store_id', $store_id)`), so the sitemap cannot
     * advertise a collection the store does not show. A row with no slug has no URL, and listing
     * one would publish a link to nothing.
     *
     * @return \Illuminate\Support\Collection<int,Category>
     */
    private function categoriesOf(Store $store, int $limit)
    {
        if ($limit <= 0) {
            return collect();
        }

        return Category::query()
            ->where('store_id', $store->id)
            ->where('status', 1)
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'slug', 'updated_at']);
    }

    /**
     * This store's listable products.
     *
     * `status = 1` is the column the product editor writes and the storefront filters on
     * (`0 => Inactive, 1 => Active`, per the table's own comment), so a merchant who deactivates a
     * product removes it from the next crawl.
     *
     * `whereNull('product_type')` MATCHES THE STOREFRONT EXACTLY, and leaving it out was a real
     * gap in the first draft of this handler — found by working the change through
     * `.claude/agents/tenant-authz-reviewer.md`. EVERY host storefront product listing carries
     * `->where('product_type', null)`: `HomeController` lines 1172, 1251, 1294, 1332 and 1459. The
     * non-null values are module-owned product kinds — `'auction'` and `'digital'` are the two the
     * host names (`ModuleResourceOwnershipGuard:128,253`) — which have their own surfaces and are
     * absent from the shop's own list. `product_detail` has no such predicate, so they ARE
     * reachable by direct URL; a sitemap that listed them would therefore advertise products the
     * shop itself does not show. A sitemap must claim exactly what the shop claims.
     *
     * A KNOWN PRE-EXISTING ODDITY INHERITED ON PURPOSE, not fixed here: the ImportExport module
     * writes `'product_type' => $row['product_type'] ?? ''` (`ProductsImportData:148`) — the empty
     * STRING, not null — and `where('product_type', null)` compiles to `is null`, which `''` does
     * not satisfy. So an ordinary product imported without a declared type is invisible on the
     * storefront's own product list today, and is therefore absent from this sitemap too.
     * Consistent with the shop, and both are wrong; recorded rather than silently diverged from.
     *
     * @return \Illuminate\Support\Collection<int,Product>
     */
    private function productsOf(Store $store, int $limit)
    {
        if ($limit <= 0) {
            return collect();
        }

        return Product::query()
            ->where('store_id', $store->id)
            ->whereNull('product_type')
            ->where('status', 1)
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'slug', 'updated_at']);
    }

    /**
     * XML, built with an escaper rather than string concatenation.
     *
     * A `<loc>` is character data, so `&`, `<` and `>` must be entity-encoded or the document is
     * not well-formed. THIS IS LOAD-BEARING, NOT DEFENSIVE, and the difference was measured rather
     * than reasoned — the first draft of this comment claimed `route()` encodes everything:
     *
     *     route('page.product', ['amp-store', 'a&b<c>d'])
     *       => http://localhost/amp-store/product/a&b%3Cc%3Ed
     *
     * `<` and `>` come back percent-encoded. **`&` DOES NOT.** A bare `&` in XML character data is
     * not an entity reference, so one product slug containing an ampersand made the WHOLE document
     * malformed and a crawler rejects the whole file, not the one URL. `products.slug` is a plain
     * `string` column with no constraint and more than one writer, so that is a reachable state.
     *
     * `ENT_XML1` is the flag that matters: it is XML's entity set, not HTML's — `&apos;` exists in
     * XML and `&nbsp;` does not. Pinned by
     * `StorefrontSitemapTest::test_an_ampersand_in_a_slug_does_not_break_the_document`, and every
     * case in that file parses the response with `loadXML()` and fails on a malformed document.
     *
     * @param array<int,array{loc:string,lastmod:?string}> $entries
     */
    private function toXml(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($entries as $entry) {
            $xml .= '  <url>' . "\n"
                . '    <loc>' . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>' . "\n";

            if ($entry['lastmod'] !== null) {
                $xml .= '    <lastmod>' . htmlspecialchars($entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>' . "\n";
            }

            $xml .= '  </url>' . "\n";
        }

        return $xml . '</urlset>' . "\n";
    }
}
