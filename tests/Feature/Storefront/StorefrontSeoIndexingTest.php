<?php

namespace Tests\Feature\Storefront;

use App\Models\Store;
use App\Models\User;
use App\Support\Storefront\StorefrontSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-117 / P03-MP-01 — THE STOREFRONT'S CANONICAL URL, ROBOTS DIRECTIVE AND STRUCTURED DATA.
 * =================================================================================================
 *
 * THE REQUIREMENT, AND WHERE IT IS. The traceability row for `P03-MP-01` cites CLAUDE.md §4 and
 * says "the plan itself is not in the repo". That was true when the row was written and is no
 * longer: the owner-approved plan was recovered on 2026-11-16 and the obligation is one Arabic
 * bullet, the LAST bullet of Phase 03 section **03-B — تجربة العميل** (customer experience),
 * decision family `CUST-001`…`CUST-008`:
 *
 *     docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md:222
 *     docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:236
 *     docs/development/master-plan/DECISION-ID-REGISTER.md:365
 *
 *         - تحسين SEO والفهرسة والأداء والصور والبيانات المنظمة.
 *           "Improve SEO and INDEXING and PERFORMANCE and IMAGES and STRUCTURED DATA."
 *
 * Five named obligations, not one. Performance is tracked separately as `P03-E-02` / `GAP-112`
 * and images as part of the same milestone's work; what is untracked and unbuilt, and what this
 * file is about, is **SEO + indexing + structured data**.
 *
 * -------------------------------------------------------------------------------------------------
 * MEASURED BASELINE, before this change, on a real rendered storefront page of a real store:
 *
 *     rel=canonical              0 occurrences in any of the four storefront layouts
 *     <meta name="robots">       0
 *     application/ld+json        0
 *     schema.org                 0
 *     a sitemap route            0 (grep of routes/ and app/Http/Controllers/)
 *
 * So every storefront page of every store was served with no canonical URL, no indexing
 * directive and no structured data at all. `GAP-118` wired the merchant's own title/description
 * meta in the same `<head>` on 2026-11-25; this is the half that row's own "Next action" named
 * and left: "canonical, hreflang for ar/en, sitemap, robots policy, product/organization
 * structured data".
 *
 * -------------------------------------------------------------------------------------------------
 * WHERE THE MARKUP IS EMITTED, AND WHY IT IS NOT A BLADE EDIT.
 *
 * `metaKeywordSetting()` in `app/Helper/helper.php`, which is the ONLY seam called by all FOUR
 * copies of `front_end/layouts/app.blade.php` — measured: it is at line 12 of each of
 * `themes/{stylique,greentic,techzonix}/views/front_end/layouts/app.blade.php` and of
 * `resources/views/front_end/layouts/app.blade.php`. `front_end.partials.appearance`, the other
 * candidate, is included by only THREE of the four (the `resources/views` fallback omits it,
 * recorded in its own header). CLAUDE.md records that a layout-level storefront change made in
 * fewer than four places silently does nothing for the other themes, so the four-of-four seam is
 * the one to use — and it keeps the change out of `themes/`, which this work package does not own.
 *
 * `test_every_theme_emits_the_seo_block` drives each installed theme so that claim is measured
 * rather than reasoned.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TENANCY CONTROL. The store comes from `request()->route('storeSlug')` and from nowhere else.
 * CLAUDE.md: `getCurrentStore()` and `auth()->user()` read the DEFAULT (`web`) guard, and storefront
 * routes sit in the `web` group with a session cookie path of `/`, so a visitor who also holds a
 * merchant panel login is a populated web-guard principal on EVERY storefront request — including
 * other merchants' storefronts. Had the canonical URL or the structured data been built from
 * `getCurrentStore()`, that visitor would have been served store A's canonical URL and store A's
 * organization name inside store B's `<head>`, which is a cross-tenant disclosure a crawler then
 * publishes. `test_a_visitor_holding_another_merchants_panel_session_still_gets_this_store` drives
 * exactly that principal and is the only case in this file that reddens when the route is swapped
 * for the guard.
 */
class StorefrontSeoIndexingTest extends TestCase
{
    use RefreshDatabase;

    private User $merchantA;

    private Store $storeA;

    private User $merchantB;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchantA, $this->storeA] = $this->makeTenant('Aleppo Soap House', 'seo-store-a', 'stylique', 'en');
        [$this->merchantB, $this->storeB] = $this->makeTenant('Damascene Brocade', 'seo-store-b', 'stylique', 'ar');
    }

    /** @return array{0: User, 1: Store} */
    private function makeTenant(string $name, string $slug, string $theme, string $language, int $active = 1): array
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
            'theme_id' => $theme,
            'created_by' => $merchant->id,
            'is_active' => $active,
            'default_language' => $language,
        ]);

        $merchant->current_store = $store->id;
        $merchant->save();

        return [$merchant, $store];
    }

    // ---------------------------------------------------------------------------------------------
    // the oracles. Parsed, never substring-matched: the question is what a crawler READS.
    // ---------------------------------------------------------------------------------------------

    private function document(string $html): \DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function canonicalOf(string $html): ?string
    {
        foreach ($this->document($html)->query('//link[@rel="canonical"]') as $element) {
            return $element->getAttribute('href');
        }

        return null;
    }

    private function robotsOf(string $html): ?string
    {
        foreach ($this->document($html)->query('//meta[@name="robots"]') as $element) {
            return trim($element->getAttribute('content'));
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> every ld+json block on the page, decoded */
    private function jsonLdOf(string $html): array
    {
        $blocks = [];
        foreach ($this->document($html)->query('//script[@type="application/ld+json"]') as $element) {
            $decoded = json_decode($element->textContent, true);
            $this->assertIsArray(
                $decoded,
                'A ld+json block did not decode as JSON: ' . $element->textContent
            );
            $blocks[] = $decoded;
        }

        return $blocks;
    }

    private function html(Store $store, string $page): string
    {
        $response = $this->get('/' . $store->slug . '/' . $page);
        $this->assertSame(200, $response->getStatusCode(), "/{$store->slug}/{$page} did not answer 200");

        return $response->getContent();
    }

    // ---------------------------------------------------------------------------------------------
    // 1. canonical
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_an_indexable_page_carries_a_self_referencing_canonical(): void
    {
        $canonical = $this->canonicalOf($this->html($this->storeA, 'home'));

        $this->assertSame(route('landing_page', $this->storeA->slug), $canonical);
    }

    #[Test]
    #[DataProvider('indexablePages')]
    public function test_each_indexable_page_canonicalises_to_its_own_url(string $page, string $routeName): void
    {
        $canonical = $this->canonicalOf($this->html($this->storeA, $page));

        $this->assertSame(route($routeName, $this->storeA->slug), $canonical);
    }

    public static function indexablePages(): array
    {
        return [
            'home' => ['home', 'landing_page'],
            'about' => ['about', 'page.about'],
            'faq' => ['faq', 'page.faq'],
            'contact-us' => ['contact-us', 'page.contact_us'],
            'privacy-policy' => ['privacy-policy', 'privacy_page'],
            'product-list' => ['product-list', 'page.product-list'],
            'collections' => ['collections', 'collections'],
        ];
    }

    /**
     * THE CANONICAL IS BUILT FROM THE ROUTE, NOT ECHOED FROM THE REQUEST — proved on an Arabic
     * slug, where the two spellings genuinely differ.
     *
     * `User::slugs()` keeps `\p{L}`, so an Arabic store name yields an Arabic slug and that is the
     * ordinary case for this product (it is what made `metaKeywordSetting()`'s `sprintf` defect a
     * 500 on every page of such a store, GAP-118). A request carries the path as raw UTF-8 bytes;
     * `route()` percent-encodes. So a canonical that matches `route()` rather than the request's
     * own path is a canonical that was generated, and two visitors arriving on the encoded and the
     * unencoded spelling are told about one URL instead of two.
     *
     * THE CASE-FOLDING HALF OF THIS IS A REAL PRODUCTION DEFECT THIS SUITE CANNOT SEE.
     * `Store::where('slug', $raw)` runs under the COLUMN's collation. On MySQL that is
     * `utf8mb4_*_ci`, so `/SEO-STORE-A/home` resolves the same store and serves the same page as
     * `/seo-store-a/home` — one page, two URLs, the textbook duplicate-content split a canonical
     * exists to collapse. MEASURED on this suite's SQLite: `Store::where('slug','PROBE-A')
     * ->exists()` is **false**, because SQLite's default collation is BINARY. So the upper-cased
     * URL 404s here and answers 200 in production, which is CLAUDE.md's "SQLite's type affinity
     * and BINARY collation hide real MySQL defects" in its collation form. The normalisation
     * below is what fixes it; the proof that it fixes it needs a real MySQL and is recorded in
     * the evidence document rather than as a test that would pass for the wrong reason here.
     */
    #[Test]
    public function test_the_canonical_is_generated_from_the_route_and_not_echoed_from_the_request(): void
    {
        $arabic = 'متجر-الصابون';
        $this->storeA->slug = $arabic;
        $this->storeA->save();

        $response = $this->get('/' . $arabic . '/home');

        $this->assertSame(200, $response->getStatusCode(), 'premise: an Arabic slug resolves and renders');

        $canonical = $this->canonicalOf($response->getContent());

        $this->assertSame(route('landing_page', $arabic), $canonical);
        $this->assertStringContainsString('%D9%85', (string) $canonical, 'premise: route() really does percent-encode');
        $this->assertStringNotContainsString($arabic, (string) $canonical, 'so the raw request path was NOT echoed');
    }

    /** Tracking parameters are not content, so they are not part of the canonical identity. */
    #[Test]
    public function test_tracking_parameters_are_dropped_from_the_canonical(): void
    {
        $response = $this->get('/' . $this->storeA->slug . '/home?utm_source=newsletter&fbclid=abc&gclid=xyz');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            route('landing_page', $this->storeA->slug),
            $this->canonicalOf($response->getContent())
        );
    }

    /**
     * PAGINATION IS CONTENT IDENTITY AND MUST SURVIVE. `HomeController::product_page` ends in
     * `->paginate(12)`, so `?page=2` is a different set of products. Google's own guidance is
     * that a paginated page self-canonicalises; collapsing page 2 onto page 1 would ask the
     * crawler to discard every product after the twelfth.
     */
    #[Test]
    public function test_a_paginated_page_keeps_its_page_number_in_the_canonical(): void
    {
        $response = $this->get('/' . $this->storeA->slug . '/product-list?page=2&utm_source=x');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            route('page.product-list', $this->storeA->slug) . '?page=2',
            $this->canonicalOf($response->getContent())
        );
    }

    #[Test]
    public function test_page_one_is_not_spelled_out_in_the_canonical(): void
    {
        $response = $this->get('/' . $this->storeA->slug . '/product-list?page=1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            route('page.product-list', $this->storeA->slug),
            $this->canonicalOf($response->getContent())
        );
    }

    /** A `page` that is not a page number is input, not identity. */
    #[Test]
    #[DataProvider('pageNoiseProvider')]
    public function test_a_non_numeric_page_parameter_is_dropped(string $page): void
    {
        $response = $this->get('/' . $this->storeA->slug . '/product-list?page=' . urlencode($page));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            route('page.product-list', $this->storeA->slug),
            $this->canonicalOf($response->getContent())
        );
    }

    public static function pageNoiseProvider(): array
    {
        return [
            'words' => ['abc'],
            'injection' => ['2" onload="x'],
            'negative' => ['-3'],
            'zero' => ['0'],
            'float' => ['2.5'],
            // CLAUDE.md / the tenancy skill: is_numeric accepts '5.9' and rejects '5abc', while
            // MySQL coerces '5abc' to 5. FILTER_VALIDATE_INT is the parse that agrees with neither.
            'numeric prefix' => ['5abc'],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // 2. the robots directive — the "indexing" half of the plan's bullet
    // ---------------------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('indexablePages')]
    public function test_a_content_page_is_offered_for_indexing(string $page): void
    {
        $this->assertSame('index, follow', $this->robotsOf($this->html($this->storeA, $page)));
    }

    /**
     * THE PAGES THAT MUST NOT BE INDEXED. A basket, a sign-in form and an account page are
     * per-visitor, carry no content a searcher wants, and in the basket's case can be crawled
     * into. Nothing stopped any of them being indexed before this change.
     */
    #[Test]
    #[DataProvider('nonIndexablePages')]
    public function test_a_private_or_transactional_page_is_withheld_from_the_index(string $page): void
    {
        $this->assertSame('noindex, follow', $this->robotsOf($this->html($this->storeA, $page)));
    }

    public static function nonIndexablePages(): array
    {
        return [
            'cart' => ['cart'],
            'wishlist' => ['wishlist'],
            'login' => ['login'],
            'track-order' => ['track-order'],
        ];
    }

    /**
     * AN INACTIVE STORE IS NOT INDEXABLE, AND IT IS STILL REACHABLE — WHICH TOOK MEASURING TWICE.
     *
     * `App\Http\Middleware\ActiveTheme` looks the store up with `->where('is_active', 1)` and its
     * `abort('403', 'The store is not active.')` is COMMENTED OUT; `ViewServiceProvider`'s
     * composer resolves the store with no `is_active` predicate at all. So nothing refuses a
     * deactivated store. The first version of this case asserted 200 and got **503**, which is
     * the interesting part:
     *
     *   - `main_file` exists ONLY in `themes/<theme>/views/`, never in `resources/views/`.
     *   - For an INACTIVE store `ActiveTheme` finds no row, so it never calls `Theme::set()` and
     *     no theme view root is prepended. `HomeController::landing_page:1163` then takes
     *     `abort(503, 'The store theme is not available.')`.
     *   - But `Theme::set()` prepends a location that survives for the rest of the PROCESS, and
     *     the storefront view composer calls it for every store it resolves. So once a worker has
     *     served ANY active store on the same theme, the inactive store's pages render at HTTP
     *     200 — measured both ways below.
     *
     * In production that is a persistent php-fpm worker serving many stores, so the 200 path is
     * the ordinary one, not the exotic one: a deactivated shop really does keep serving its
     * storefront. The warm-up request below is therefore the realistic state and not a
     * convenience, and the `noindex` is the only thing keeping a switched-off shop out of the
     * index.
     */
    #[Test]
    public function test_a_deactivated_stores_pages_are_withheld_from_the_index(): void
    {
        [, $closed] = $this->makeTenant('Closed Shop', 'seo-store-closed', 'stylique', 'en', 0);

        $this->assertSame(
            0,
            (int) $closed->is_active,
            'premise: the store really is deactivated'
        );

        // The warm-up: an active store on the same theme prepends the theme's view root for the
        // rest of this process, exactly as any earlier request in a live worker would have.
        $this->assertSame(
            200,
            $this->get('/' . $this->storeA->slug . '/home')->getStatusCode(),
            'premise: an active store on this theme renders, which is what warms the view root'
        );

        $response = $this->get('/' . $closed->slug . '/home');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'premise: the deactivated store still serves its storefront, so the noindex is the only control'
        );
        $this->assertSame('noindex, follow', $this->robotsOf($response->getContent()));
        $this->assertSame([], $this->jsonLdOf($response->getContent()));
    }

    /**
     * The `is_active` limb, pinned where it is deterministic. The HTTP case above depends on a
     * warmed view root; this one cannot.
     */
    #[Test]
    public function test_the_is_active_limb_withholds_an_otherwise_indexable_page(): void
    {
        $this->assertSame('index, follow', StorefrontSeo::robotsDirectiveFor('landing_page', true));
        $this->assertSame('noindex, follow', StorefrontSeo::robotsDirectiveFor('landing_page', false));
    }

    // ---------------------------------------------------------------------------------------------
    // 3. structured data — "البيانات المنظمة"
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_an_indexable_page_carries_organization_structured_data_for_its_own_store(): void
    {
        $blocks = $this->jsonLdOf($this->html($this->storeA, 'home'));

        $this->assertCount(1, $blocks, 'exactly one ld+json block is expected');
        $this->assertSame('https://schema.org', $blocks[0]['@context']);
        $this->assertSame('Organization', $blocks[0]['@type']);
        $this->assertSame($this->storeA->name, $blocks[0]['name']);
        $this->assertSame(route('landing_page', $this->storeA->slug), $blocks[0]['url']);
    }

    /** A page withheld from the index does not need to describe itself to a crawler. */
    #[Test]
    public function test_a_non_indexable_page_carries_no_structured_data(): void
    {
        $this->assertSame([], $this->jsonLdOf($this->html($this->storeA, 'cart')));
    }

    /**
     * THE JSON-LD IS A SCRIPT BODY, WHICH IS A DIFFERENT SINK FROM AN ATTRIBUTE.
     *
     * `e()` — the escaping GAP-118 put on the neighbouring `<meta>` values — is the WRONG tool
     * here: inside `<script>` the browser's HTML parser does not decode entities, so an
     * HTML-escaped `</script>` would be served to the JSON parser as the literal text
     * `&lt;/script&gt;` (invalid data) while a RAW `</script>` in a store's name would close the
     * element and spill the rest into the document as markup. `stores.name` is merchant-supplied
     * through three writers, so this is reachable. The defence is `JSON_HEX_TAG`, which encodes
     * `<` and `>` as `<` / `>` — valid JSON that no HTML parser can read as a tag.
     */
    #[Test]
    #[DataProvider('hostileStoreNames')]
    public function test_a_hostile_store_name_cannot_escape_the_json_ld_block(string $name): void
    {
        $this->storeA->name = $name;
        $this->storeA->save();

        $html = $this->html($this->storeA, 'home');

        // The decode inside jsonLdOf() asserts the block is still valid JSON.
        $blocks = $this->jsonLdOf($html);
        $this->assertCount(1, $blocks);
        $this->assertSame($name, $blocks[0]['name'], 'the value must survive intact, not be mangled');

        // And no tag may have been opened or closed by the data.
        $block = $this->document($html)->query('//script[@type="application/ld+json"]')->item(0);
        $this->assertSame(
            0,
            $block->getElementsByTagName('*')->length,
            'the ld+json block must contain no elements'
        );
    }

    public static function hostileStoreNames(): array
    {
        return [
            'closing script tag' => ['Soap </script><img src=x onerror=alert(1)>'],
            'double quote' => ['The "Best" Soap'],
            'backslash' => ['Soap \\ House'],
            'html comment' => ['Soap <!--> House'],
            'arabic' => ['صابون حلب الأصلي'],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // 4. tenancy
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_each_store_gets_its_own_canonical_and_its_own_organization(): void
    {
        $a = $this->html($this->storeA, 'home');
        $b = $this->html($this->storeB, 'home');

        $this->assertSame(route('landing_page', $this->storeA->slug), $this->canonicalOf($a));
        $this->assertSame(route('landing_page', $this->storeB->slug), $this->canonicalOf($b));

        $this->assertSame($this->storeA->name, $this->jsonLdOf($a)[0]['name']);
        $this->assertSame($this->storeB->name, $this->jsonLdOf($b)[0]['name']);

        $this->assertStringNotContainsString($this->storeB->name, $a);
        $this->assertStringNotContainsString($this->storeA->name, $b);
    }

    /**
     * THE CASE THE WHOLE DESIGN IS FOR. A merchant signed into their own panel browses another
     * merchant's storefront. `getCurrentStore()` answers for THEIR store on that request, because
     * the session cookie path is '/' and storefront routes are in the `web` group. The page must
     * still describe the store whose slug is in the URL.
     */
    #[Test]
    public function test_a_visitor_holding_another_merchants_panel_session_still_gets_this_store(): void
    {
        $this->actingAs($this->merchantA, 'web');
        $this->assertSame(
            $this->storeA->id,
            getCurrentStore(),
            'premise: the web guard answers for store A on every request this visitor makes'
        );

        $html = $this->html($this->storeB, 'home');

        $this->assertSame(route('landing_page', $this->storeB->slug), $this->canonicalOf($html));
        $this->assertSame($this->storeB->name, $this->jsonLdOf($html)[0]['name']);
    }

    // ---------------------------------------------------------------------------------------------
    // 5. all four layout copies, and both writing directions
    // ---------------------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('installedThemes')]
    public function test_every_theme_emits_the_seo_block(string $theme): void
    {
        $this->storeA->theme_id = $theme;
        $this->storeA->save();

        $html = $this->html($this->storeA, 'home');

        $this->assertSame(route('landing_page', $this->storeA->slug), $this->canonicalOf($html));
        $this->assertSame('index, follow', $this->robotsOf($html));
        $this->assertCount(1, $this->jsonLdOf($html));
    }

    public static function installedThemes(): array
    {
        return ['stylique' => ['stylique'], 'greentic' => ['greentic'], 'techzonix' => ['techzonix']];
    }

    /**
     * The block is machine-facing, so it must be IDENTICAL in both languages: a canonical that
     * differed by locale would split one page into two, and the storefront has no per-locale URL
     * for the alternate to point at (see the hreflang note in StorefrontSeo's docblock).
     */
    #[Test]
    public function test_the_block_is_identical_in_arabic_and_in_english(): void
    {
        $english = $this->withCookie('LANGUAGE', 'en')->get('/' . $this->storeA->slug . '/home');
        $arabic = $this->withCookie('LANGUAGE', 'ar')->get('/' . $this->storeA->slug . '/home');

        $this->assertSame(200, $english->getStatusCode());
        $this->assertSame(200, $arabic->getStatusCode());

        // premise: the two really are rendered in different directions
        $this->assertStringContainsString('dir="ltr"', $english->getContent());
        $this->assertStringContainsString('dir="rtl"', $arabic->getContent());

        $this->assertSame(
            $this->canonicalOf($english->getContent()),
            $this->canonicalOf($arabic->getContent())
        );
        $this->assertSame(
            $this->robotsOf($english->getContent()),
            $this->robotsOf($arabic->getContent())
        );
        $this->assertSame(
            $this->jsonLdOf($english->getContent()),
            $this->jsonLdOf($arabic->getContent())
        );
    }

    /**
     * NO hreflang IS EMITTED, AND THAT IS A MEASURED REFUSAL RATHER THAN AN OMISSION.
     *
     * `GAP-117`'s next action names "hreflang for ar/en". It is not implementable on this
     * storefront, and this case pins the absence so that nobody adds a decorative one.
     * `LanguageController::changeLanquageStore()` is the storefront's language switch and its
     * whole body is `Cookie::queue('LANGUAGE', $lang, 1440)` plus a session write and
     * `redirect()->back()` — the language is a COOKIE and there is no per-language URL. hreflang
     * annotations must point at distinct URLs per language version; with one URL serving both,
     * every alternate would point at the page itself, which tells a crawler nothing and is
     * self-contradictory where it is read at all.
     *
     * Giving the storefront a URL-level language dimension is a routing change across ~75
     * storefront routes plus a redirect policy for the existing cookie URLs. That is an owner
     * decision about public URL shape, not an engineering detail — reported, not guessed.
     */
    #[Test]
    public function test_no_hreflang_is_emitted_because_the_storefront_has_no_per_language_url(): void
    {
        $html = $this->html($this->storeA, 'home');

        $this->assertCount(
            0,
            iterator_to_array($this->document($html)->query('//link[@hreflang]')),
            'an hreflang pointing at a single cookie-switched URL would be decorative; see the docblock'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // 6. premises — so that none of the above can pass vacuously
    // ---------------------------------------------------------------------------------------------

    /** If the two route lists were empty or overlapping, every verdict above would be an accident. */
    #[Test]
    public function test_the_route_classification_is_a_real_non_empty_partition(): void
    {
        $indexable = StorefrontSeo::INDEXABLE_ROUTES;
        $withheld = StorefrontSeo::WITHHELD_ROUTES;

        $this->assertGreaterThan(8, count($indexable));
        $this->assertGreaterThan(20, count($withheld));
        $this->assertSame([], array_intersect($indexable, $withheld), 'a route cannot be both');
        $this->assertSame(array_values(array_unique($indexable)), array_values($indexable));
        $this->assertSame(array_values(array_unique($withheld)), array_values($withheld));
    }

    /**
     * FAIL CLOSED. A storefront route nobody has classified must not be indexed: a new private
     * page silently entering the search index is the expensive direction of this mistake, and the
     * census in StorefrontSeoRouteCensusTest turns the gate red until somebody classifies it.
     */
    #[Test]
    public function test_an_unclassified_route_is_withheld_rather_than_indexed(): void
    {
        $this->assertSame(
            'noindex, follow',
            StorefrontSeo::robotsDirectiveFor('a.route.name.nobody.classified', true)
        );
        $this->assertSame(
            'noindex, follow',
            StorefrontSeo::robotsDirectiveFor(null, true),
            'an unnamed route is unclassifiable, so it is withheld'
        );
    }
}
