<?php

namespace App\Support\Storefront;

use App\Models\Store;
use Illuminate\Http\Request;

/**
 * THE STOREFRONT'S CANONICAL URL, INDEXING DIRECTIVE AND STRUCTURED DATA.  GAP-117 / P03-MP-01.
 * =================================================================================================
 *
 * THE REQUIREMENT. The owner-approved Master Product Plan, Phase 03 section **03-B — تجربة العميل**
 * (customer experience), decision family `CUST-001`…`CUST-008`, last bullet — verbatim:
 *
 *     docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md:222
 *
 *         - تحسين SEO والفهرسة والأداء والصور والبيانات المنظمة.
 *
 *     "Improve SEO and INDEXING and PERFORMANCE and IMAGES and STRUCTURED DATA."
 *
 * Five obligations. Performance is `P03-E-02`/`GAP-112` and images belong with the theme work;
 * this class is SEO + indexing + structured data, which were never built, dispositioned or
 * reported. Measured before it existed: zero `rel=canonical`, zero `<meta name="robots">`, zero
 * `application/ld+json` and zero `schema.org` anywhere under `resources/views/` or `themes/`, and
 * no sitemap route anywhere.
 *
 * -------------------------------------------------------------------------------------------------
 * THE STORE COMES FROM THE ROUTE, AND THAT IS THE WHOLE TENANCY CONTROL.
 *
 * CLAUDE.md: `getCurrentStore()` and `auth()->user()` read the DEFAULT (`web`) guard. Storefront
 * routes sit in the `web` group and the session cookie path is `/`, so a visitor who also holds a
 * merchant panel login is a populated web-guard principal on EVERY storefront request — other
 * merchants' storefronts included. Built from the guard, this class would have printed store A's
 * canonical URL and store A's organization name into store B's `<head>` for that visitor, and a
 * crawler would then have published it. `forRequest()` reads `storeSlug` off the matched route and
 * nothing else. The same trap cost GAP-118 a dedicated pair of tests one row earlier.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS DELIBERATELY DOES NOT DO, each a decision rather than an oversight:
 *
 *  - **NO hreflang.** `GAP-117`'s next action names "hreflang for ar/en" and it is not
 *    implementable on this storefront. `LanguageController::changeLanquageStore()` is the
 *    storefront's language switch and its entire body is `Cookie::queue('LANGUAGE', $lang, 1440)`,
 *    a session write and `redirect()->back()`: the language is a COOKIE and there is no
 *    per-language URL. An hreflang annotation must name a distinct URL per language version, so
 *    with one URL serving both every alternate would point at the page itself. Giving the
 *    storefront a URL-level language dimension is a public-URL-shape decision for the owner
 *    across ~75 routes plus a redirect policy for every existing link, not an engineering detail.
 *
 *  - **NO `Product` structured data.** A `Product` block is only useful with `offers`, and a
 *    marked-up price that disagrees with the displayed one is a commercial misrepresentation
 *    rather than a markup bug. The displayed price is assembled in
 *    `HomeController::product_detail` from `products.price`/`sale_price`, the `product_variants`
 *    min/max and any live `FlashSale`, and reproducing that arithmetic here would create a second
 *    source of truth that silently drifts. It needs the price to come from ONE place first.
 *
 *  - **NO `robots.txt` change.** `public/robots.txt` is the vendored allow-all and sits at the
 *    HOST root, shared by every tenant's storefront and by the platform's own website, so its
 *    contents (and the `Sitemap:` line that would make the per-store sitemaps discoverable
 *    without Search Console) are a platform-operator decision, not a per-store one.
 *
 * The census-trap sentinel `census-trap-name-in-a-comment-only` is in this comment on purpose:
 * `StorefrontSeoRouteCensusTest` proves the route census reads the ROUTER and not this file's
 * source text, because CLAUDE.md records six measured cases of a census satisfied — or broken —
 * by prose about the thing it measures.
 */
final class StorefrontSeo
{
    /**
     * Storefront pages that ARE a store's public content, so a crawler is invited in.
     *
     * Every name here is asserted to be a real storefront GET route by
     * `StorefrontSeoRouteCensusTest::test_every_indexable_route_is_a_storefront_get_page`.
     *
     * @var array<int,string>
     */
    public const INDEXABLE_ROUTES = [
        'landing_page',
        'page.product-list',
        'page.product',
        'collections',
        'collections.all',
        'brands.all',
        'page.about',
        'page.faq',
        'page.blog',
        'page.article',
        'page.contact_us',
        'privacy_page',
        'page.custom',
    ];

    /**
     * Storefront pages deliberately kept out of the index: per-visitor state (a basket, a
     * wishlist, an account), credential forms, checkout and gateway legs, JSON/partial endpoints
     * that are not pages at all, the error page, and search/filter result URLs — an unbounded
     * query-shaped URL space whose indexing is pure crawl waste.
     *
     * This list exists so that a route has to be RULED ON rather than merely absent: the default
     * for an unlisted route is `noindex` (see `robotsDirectiveFor`), and the census turns the
     * blocking Unit gate red until a new storefront route appears in one list or the other.
     *
     * @var array<int,string>
     */
    public const WITHHELD_ROUTES = [
        // per-visitor state
        'page.cart',
        'cart.list.sidebar',
        'wishlist',
        'delete.wishlist',
        'reward.list',
        // credentials
        'customer.login',
        'customer.register',
        'customer.password.request',
        'customer.password.reset',
        'customer.password.otp.form',
        'customer.password.otp.reset.form',
        // account
        'my-account.index',
        'my-account.create',
        // GAP-268. `my-account.show`, `my-account.edit`, `my-account.update` and
        // `my-account.destroy` WERE HERE and are gone, because the routes are gone:
        // `AccountProfileController` never defined any of those four methods, so each was an
        // HTTP 500 for a signed-in shopper and the resource is now `->only(['index', 'create',
        // 'store'])` (routes/customer.php). `show` and `edit` were the two that had been
        // classified; `update` and `destroy` are not GET routes and were never in this census.
        // Removing them is not a relaxation -- `test_no_classified_route_name_is_stale` REQUIRES
        // it, and failed on exactly these two names until they were deleted.
        'address',
        'address-form',
        'edit.address.form',
        'delete.addressbook',
        'order',
        'order.details',
        'order.page.filter',
        'order.return.list',
        'order.refund',
        'customer.order',
        'support.ticket',
        'add.support.ticket',
        'get.support.ticket',
        'reply.support.ticket',
        'destroy.ticket',
        'paid.download',
        'marketing.preferences.show',
        'marketing.unsubscribe.show',
        // checkout and gateway legs
        'checkout',
        'payment.process',
        'paymentlist',
        'additionalnote',
        'order.complete',
        'order.get.authorizenet.status',
        'product-order.pay.with.nmi',
        'store.payment.cinetpay',
        'store.payment.cinetpay.return',
        'store.payment.easebuzz',
        'store.payment.paiementpro',
        'store.payment.status',
        'store.get.Powertranz.status',
        /*
         * AN UNPUBLISHED THEME DRAFT. `theme-draft/{storeSlug}/{version}/preview` renders the
         * storefront layout for a version the merchant has NOT published, so this block runs on it
         * — and indexing it would publish a store's unreleased design and split its canonical.
         * Found by the route census, not by reading: its URI does not BEGIN with the slug, so the
         * census's first derivation (first segment is a parameter) missed it. That is the whole
         * argument for keying the census on the `storeSlug` parameter instead.
         */
        'theme.draft.preview',
        // search, filters and other non-pages
        'search.product',
        'product.page.filter',
        'blogs.filter.view',
        'question',
        'more_question',
        'track.order',
        'theme.404',
        // machine-readable, so the robots meta never renders for it anyway
        'storefront.sitemap',
    ];

    /**
     * Routes whose content really is split across pages, so `?page=N` is part of the URL's
     * identity and must survive into the canonical.
     *
     * Measured, not guessed: `HomeController::product_page` ends in `->paginate(12)` (line 1377)
     * and `HomeController::blog_page` in `->paginate(6)` (line 1221). `collections.all` and
     * `brands.all` are the same `product_page` action under different URIs. Google's guidance is
     * that a paginated page self-canonicalises; collapsing page 2 onto page 1 would ask a crawler
     * to discard every product after the twelfth.
     *
     * @var array<int,string>
     */
    public const PAGINATED_ROUTES = [
        'page.product-list',
        'collections.all',
        'brands.all',
        'page.blog',
    ];

    /** The sitemaps spec's per-document ceiling: 50,000 URLs. A sitemap INDEX is not built. */
    public const SITEMAP_MAX_URLS = 50000;

    private function __construct(
        private readonly Store $store,
        private readonly ?string $routeName,
        /** @var array<string,mixed> */
        private readonly array $routeParameters,
        private readonly ?int $page,
    ) {
    }

    /**
     * The SEO facts for a storefront request, or null when this is not one.
     *
     * Null rather than a default is deliberate: a console render, a panel page or any view that
     * happens to match the storefront layout's composer patterns must emit NOTHING, because a
     * canonical URL or an organization block naming the wrong store is worse than none at all.
     */
    public static function forRequest(?Request $request = null): ?self
    {
        $request ??= request();
        $route = $request?->route();

        if ($route === null) {
            return null;
        }

        // THE ROUTE, NOT THE GUARD. See the class docblock.
        $slug = $route->parameter('storeSlug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $store = Store::where('slug', $slug)->first();

        if (! $store) {
            return null;
        }

        return new self(
            $store,
            $route->getName(),
            $route->parameters(),
            self::pageNumberOf($request)
        );
    }

    /** The markup for the current request, or '' when there is nothing to say. */
    public static function markupForCurrentRequest(): string
    {
        return self::forRequest()?->render() ?? '';
    }

    /**
     * FAIL CLOSED. An unclassified or unnamed route is withheld, because a new private page
     * silently entering the search index is the expensive direction of this mistake. The other
     * direction — a new content page silently never indexed — is caught by
     * `StorefrontSeoRouteCensusTest`, which reddens the blocking Unit gate instead.
     *
     * `follow` is kept in both verdicts: a withheld page's LINKS are still worth following, and
     * `nofollow` on a basket page would cut the crawler off from whatever the basket links to.
     */
    public static function robotsDirectiveFor(?string $routeName, bool $storeIsActive): string
    {
        if ($routeName !== null && $storeIsActive && in_array($routeName, self::INDEXABLE_ROUTES, true)) {
            return 'index, follow';
        }

        return 'noindex, follow';
    }

    /**
     * `?page=N` when, and only when, N is a page number.
     *
     * `FILTER_VALIDATE_INT` and not `is_numeric`: the tenancy skill records that `is_numeric`
     * accepts `'5.9'` and rejects `'5abc'` while MySQL coerces `'5abc'` to 5, so neither agrees
     * with the other. Here the consequence is only a wrong canonical, but the parse is the same
     * one and there is no reason to use the loose test.
     */
    private static function pageNumberOf(Request $request): ?int
    {
        $raw = $request->query('page');

        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $page = filter_var($raw, FILTER_VALIDATE_INT);

        return ($page !== false && $page > 1) ? $page : null;
    }

    /**
     * The one URL that represents this page.
     *
     * Built from the route NAME and its parameters rather than echoed from `url()->current()`,
     * which matters in two measured ways:
     *
     *  1. THE SLUG IS NORMALISED TO `stores.slug`. `Store::where('slug', $raw)` runs under the
     *     column's collation, which is case-insensitive on MySQL (`utf8mb4_*_ci`) and for ASCII
     *     on SQLite — so `/SEO-STORE-A/home` resolves the same store and renders the same page as
     *     `/seo-store-a/home`. Two URLs, one page: exactly the duplicate-content split a
     *     canonical exists to collapse. Echoing the request would have preserved it.
     *  2. EVERY QUERY PARAMETER IS DROPPED except a validated `page`. `utm_*`, `fbclid`, `gclid`
     *     and the storefront's own filter parameters do not change which page this is.
     *
     * It also keeps the canonical in agreement with the `og:url` that `metaKeywordSetting()`
     * already emits through `route('landing_page', $slug)`; a canonical that disagreed with the
     * page's own og:url is its own defect.
     *
     * Null when the route cannot be rebuilt (an unnamed route, or one whose parameters no longer
     * satisfy `route()`), because a wrong canonical is worse than none.
     */
    public function canonicalUrl(): ?string
    {
        if ($this->routeName === null) {
            return null;
        }

        $parameters = $this->routeParameters;
        $parameters['storeSlug'] = $this->store->slug;

        try {
            $url = route($this->routeName, $parameters);
        } catch (\Throwable) {
            return null;
        }

        if ($this->page !== null && in_array($this->routeName, self::PAGINATED_ROUTES, true)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'page=' . $this->page;
        }

        return $url;
    }

    public function robotsDirective(): string
    {
        return self::robotsDirectiveFor($this->routeName, (int) $this->store->is_active === 1);
    }

    public function isIndexable(): bool
    {
        return $this->robotsDirective() === 'index, follow';
    }

    /**
     * The "البيانات المنظمة" half: who this shop is, in schema.org terms.
     *
     * Only on an indexable page — a page withheld from the index has no reason to describe itself
     * to a crawler, and emitting it there would be the only place the block appeared on a page a
     * crawler is told to ignore.
     *
     * `logo` is deliberately absent: the only per-store image the product stores for this purpose
     * is the merchant's SEO `metaimage`, which is a social share card and not an organization
     * logo, and misusing it would publish a wrong claim about the brand.
     *
     * @return array<string,mixed>|null
     */
    public function structuredData(): ?array
    {
        if (! $this->isIndexable()) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => (string) $this->store->name,
            'url' => route('landing_page', $this->store->slug),
        ];
    }

    /**
     * THE JSON-LD BLOCK IS A SCRIPT BODY, WHICH IS A DIFFERENT SINK FROM AN ATTRIBUTE.
     *
     * `e()` — the escaping GAP-118 put on the neighbouring `<meta>` values one row earlier — is
     * the WRONG tool inside `<script>`: the HTML parser does not decode entities there, so an
     * HTML-escaped `</script>` would reach the JSON parser as the literal text `&lt;/script&gt;`
     * (invalid data), while a RAW `</script>` in a store's name would close the element and spill
     * the remainder into the document as markup. `stores.name` is merchant-supplied through three
     * writers (`User::slugs()`, `AppSettingController::store()` and the Flutter store-settings
     * update), so this is reachable input and not a hypothetical.
     *
     * `JSON_HEX_TAG` is the load-bearing flag: it encodes `<` and `>` as `<` / `>`,
     * which is valid JSON that decodes back to the original string and that no HTML parser can
     * read as a tag, a comment opener or a script-data escape. `JSON_HEX_AMP|APOS|QUOT` are
     * defence in depth. `JSON_UNESCAPED_UNICODE` keeps Arabic store names legible rather than
     * turning every letter into an escape; it is safe precisely because `<` is already hexed.
     */
    private function structuredDataJson(): ?string
    {
        $data = $this->structuredData();

        if ($data === null) {
            return null;
        }

        $json = json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $json === false ? null : $json;
    }

    /**
     * The `<head>` block, printed by `metaKeywordSetting()` — the only seam called by all FOUR
     * copies of `front_end/layouts/app.blade.php`.
     *
     * `e()` on the two attribute values. `route()` percent-encodes, so neither can currently
     * carry a quote, but an unescaped interpolation into a single- or double-quoted attribute is
     * the exact shape of the defect GAP-118 found in the function this output is appended to, and
     * relying on a caller's encoding is how that one survived.
     */
    public function render(): string
    {
        $lines = [];

        $canonical = $this->canonicalUrl();

        if ($canonical !== null) {
            $lines[] = '<link rel="canonical" href="' . e($canonical) . '">';
        }

        $lines[] = '<meta name="robots" content="' . e($this->robotsDirective()) . '">';

        $json = $this->structuredDataJson();

        if ($json !== null) {
            $lines[] = '<script type="application/ld+json">' . $json . '</script>';
        }

        return "\n" . implode("\n", $lines) . "\n";
    }
}
