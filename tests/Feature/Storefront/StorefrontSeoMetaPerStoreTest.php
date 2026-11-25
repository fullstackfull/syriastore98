<?php

namespace Tests\Feature\Storefront;

use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GAP-118 / P03-MP-02 — THE MERCHANT'S SEO META NOW REACHES ITS OWN STORE'S PAGES, AND ONLY ITS OWN.
 * =================================================================================================
 *
 * GAP-118's words were "a merchant-configurable feature is wired to nothing", and that was exact.
 * `POST /seo-setting` (`AppSettingController::seoSettings`, route `seo.settings`) has always
 * validated and stored `metakeyword`, `metadesc` and `metaimage` into `settings` under the
 * merchant's own `store_id`. Nothing read them back.
 *
 * MEASURED BASELINE, before this change, on four stores each with all three values stored and on
 * every storefront page that answers 200 (`home`, `about`, `faq`, `contact-us`, `product-list`,
 * `collections`, `privacy-policy`, `blog`, `track-order`, `cart`, `wishlist`, `login`):
 *
 *     <meta name='title'               content='Store Syria (testing)'>   <- config('app.name')
 *     <meta name='description'         content='Store Syria (testing)'>   <- config('app.name')
 *     <meta property='og:title'        content=' Store Syria (testing)'>  <- config('app.name')
 *     <meta property='og:description'  content='Store Syria (testing)'>   <- config('app.name')
 *     <meta property='og:image'        content=' Store Syria (testing)'>  <- a NAME where a URL goes
 *     <meta property='twitter:title'       … twitter:description … twitter:image …  same
 *
 * Ten of the twelve tags carried `config('app.name')` on every page of every store. Only `og:url`
 * and `twitter:url` were already per-store, because the layout passes `$slug` and the helper runs
 * it through `route('landing_page', …)`. So every tenant's storefront shared one set of search and
 * social metadata, naming the PLATFORM rather than the shop.
 *
 * -------------------------------------------------------------------------------------------------
 * WHERE THE WIRING WENT, AND WHY NOT IN A CONTROLLER OR IN THE LAYOUTS.
 *
 * `App\Providers\ViewServiceProvider`'s composer, registered for
 * `['front_end.layouts.app', 'main_file', 'front_end.pages.*', 'front_end.Auth.*']`.
 *
 * There are FOUR copies of `front_end/layouts/app.blade.php` — one per installed theme plus the
 * `resources/views` fallback — and each already calls
 *
 *     {!! metaKeywordSetting($metakeyword ?? null, $metadesc ?? null, $metaimage ?? null, $slug) !!}
 *
 * A composer is keyed on the view NAME, and all four files resolve under the one name
 * `front_end.layouts.app` (the theme's copy wins through `Theme::set()` → `prependLocation()`).
 * So ONE registration feeds all four copies and NO Blade file needed editing — which is the
 * outcome to want, because CLAUDE.md records that a layout-level storefront change made in fewer
 * than four places silently does nothing for the other themes. `test_every_theme_gets_its_own_meta`
 * below drives each theme so that claim is measured rather than reasoned.
 *
 * MEASURED, which copy serves which store (`View::creator` reporting `$view->getPath()`):
 *
 *     theme_id = 'stylique'           -> themes/stylique/views/front_end/layouts/app.blade.php
 *     theme_id = 'greentic'           -> themes/greentic/views/.../app.blade.php
 *     theme_id = 'techzonix'          -> themes/techzonix/views/.../app.blade.php
 *     theme_id = ''                   -> themes/stylique/... (config('theme.active'))
 *     theme_id = 'uninstalled-theme'  -> 503, the composer never fires at all
 *
 * The `resources/views` fallback copy is therefore reached by NO store through this path; it is fed
 * by the same composer registration regardless, so it cannot drift.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TENANCY DEFECT THIS WIRING HAD TO AVOID, AND IT IS NOT HYPOTHETICAL.
 *
 * `Utility::GetValueByName($name)` called with no store id defaults to
 * `getCurrentStore() ?: getRouteStoreId()`, and `getCurrentStore()` reads `current_store` off the
 * DEFAULT (`web`) guard. Storefront routes sit inside the `web` middleware group and the session
 * cookie path is `/`, so a merchant with a live panel session is a populated web-guard principal on
 * every storefront request — including other merchants' storefronts. Taking the default would have
 * printed STORE A's SEO metadata into STORE B's `<head>` for that visitor.
 *
 * So the composer passes `$store->id` explicitly, and `$store` is the one it already resolved from
 * `request()->route('storeSlug')`. `test_a_merchant_browsing_another_store_sees_that_stores_meta`
 * drives exactly that principal, and it is the assertion that would fail if the store id were ever
 * dropped from those three calls.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ESCAPING IS A PRECONDITION OF THIS CHANGE, NOT A SEPARATE CONCERN.
 *
 * Until now the three arguments were always null, so `metaKeywordSetting()`'s raw interpolation into
 * single-quoted attributes was unreachable by any request. Wiring the feature is what MAKES it
 * reachable: the values are merchant-supplied, stored verbatim (`Utility::GetValueByName` runs only
 * the currency-symbol cleaner, which ignores these names), and printed with `{!! !!}`.
 * `tests/Unit/Security/MetaKeywordSettingEscapingTest` pins the helper; the two tests at the foot of
 * this file pin the RENDERED PAGE, which is the claim that matters now that a real request can carry
 * a payload end to end.
 */
class StorefrontSeoMetaPerStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $merchantA;

    private User $merchantB;

    private Store $storeA;

    private Store $storeB;

    /** Store A's three stored values. Distinct strings so a cross-tenant leak is unmistakable. */
    private const A_KEYWORD = 'aleppo soap, olive oil, laurel';

    private const A_DESC = 'Traditional Aleppo soap shipped from Syria';

    private const A_IMAGE = 'uploads/seo-a/aleppo-soap.png';

    private const B_KEYWORD = 'damascus brocade, silk, damask';

    private const B_DESC = 'Hand-woven Damascene brocade';

    private const B_IMAGE = 'uploads/seo-b/brocade.png';

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchantA, $this->storeA] = $this->makeTenant('a', 'stylique', 'en');
        [$this->merchantB, $this->storeB] = $this->makeTenant('b', 'stylique', 'ar');

        Setting::putForStore('metakeyword', self::A_KEYWORD, $this->storeA->id, $this->merchantA->id);
        Setting::putForStore('metadesc', self::A_DESC, $this->storeA->id, $this->merchantA->id);
        Setting::putForStore('metaimage', self::A_IMAGE, $this->storeA->id, $this->merchantA->id);

        Setting::putForStore('metakeyword', self::B_KEYWORD, $this->storeB->id, $this->merchantB->id);
        Setting::putForStore('metadesc', self::B_DESC, $this->storeB->id, $this->merchantB->id);
        Setting::putForStore('metaimage', self::B_IMAGE, $this->storeB->id, $this->merchantB->id);
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function makeTenant(string $key, string $theme, string $language): array
    {
        $merchant = User::forceCreate([
            'name' => 'SEO Merchant ' . $key,
            'email' => 'seo-merchant-' . $key . '@example.test',
            'password' => bcrypt('x'),
            'type' => 'admin',
            'created_by' => 1,
            'email_verified_at' => now(),
        ]);

        $store = Store::forceCreate([
            'name' => 'SEO Store ' . $key,
            'email' => 'seo-store-' . $key . '@example.test',
            'slug' => 'seo-store-' . $key,
            'theme_id' => $theme,
            'created_by' => $merchant->id,
            'is_active' => 1,
            'default_language' => $language,
        ]);

        $merchant->current_store = $store->id;
        $merchant->save();

        return [$merchant, $store];
    }

    // ---------------------------------------------------------------------------------------------
    // the oracle
    // ---------------------------------------------------------------------------------------------

    /**
     * The `content` of every `<meta>` on the rendered page, keyed by `name=` or `property=`.
     *
     * Parsed, not substring-matched: the question is what a crawler would READ as the value of the
     * attribute, which only a parser answers. Values are trimmed because `og:title`, `og:image` and
     * the three `twitter:` tags carry a pre-existing leading space in the helper's source, and this
     * file pins the wiring rather than that cosmetic defect.
     *
     * @return array<string,string>
     */
    private function metaOf(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $meta = [];
        foreach ((new \DOMXPath($document))->query('//meta') as $element) {
            $key = $element->getAttribute('name') !== ''
                ? $element->getAttribute('name')
                : $element->getAttribute('property');
            if ($key !== '') {
                $meta[$key] = trim($element->getAttribute('content'));
            }
        }

        return $meta;
    }

    /** @return array<string,string> */
    private function metaFor(Store $store, string $page = 'home'): array
    {
        $response = $this->get('/' . $store->slug . '/' . $page);

        // LIVENESS, both directions. A 404 satisfies "store A's keyword is absent" and a 500
        // satisfies it too, so neither may stand in for the claim.
        $this->assertNotSame(404, $response->status(),
            "GET /{$store->slug}/{$page} answered 404 -- the route does not exist, so no assertion "
            . 'about its markup means anything.');
        $this->assertNotSame(500, $response->status(),
            "GET /{$store->slug}/{$page} answered 500, so the page never rendered.");
        $this->assertSame(200, $response->status(),
            "GET /{$store->slug}/{$page} answered {$response->status()} rather than 200.");

        return $this->metaOf($response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // the premise. Without these, everything below is about something else.
    // ---------------------------------------------------------------------------------------------

    public function test_the_merchant_half_of_the_feature_really_stores_these_three_names(): void
    {
        $writer = file_get_contents(base_path('app/Http/Controllers/AppSettingController.php'));

        foreach (["\$post['metakeyword']", "\$post['metadesc']", "'metaimage'"] as $assignment) {
            $this->assertStringContainsString($assignment, $writer,
                'AppSettingController no longer writes ' . $assignment . ', so the merchant half of '
                . 'this feature has moved and this file is measuring a value nothing can set.');
        }

        $this->assertNotNull(
            app('router')->getRoutes()->getByName('seo.settings'),
            'the route seo.settings is gone, so there is no merchant writer to wire up.'
        );

        // And the values really are readable per store, which is the half that existed already.
        $this->assertSame(self::A_KEYWORD, Utility::GetValueByName('metakeyword', $this->storeA->id));
        $this->assertSame(self::B_KEYWORD, Utility::GetValueByName('metakeyword', $this->storeB->id));
        $this->assertSame(self::A_DESC, Utility::GetValueByName('metadesc', $this->storeA->id));
        $this->assertSame(self::A_IMAGE, Utility::GetValueByName('metaimage', $this->storeA->id));
    }

    public function test_the_stored_rows_are_the_stores_own_rows(): void
    {
        // Through withoutGlobalScopes, because an "it is there" assertion through a scoped read
        // answers against whichever tenant happens to be resolved rather than against the table.
        $rows = Setting::withoutGlobalScopes()
            ->whereIn('name', ['metakeyword', 'metadesc', 'metaimage'])
            ->get()
            ->groupBy('store_id');

        $this->assertCount(3, $rows[$this->storeA->id] ?? []);
        $this->assertCount(3, $rows[$this->storeB->id] ?? []);
    }

    // ---------------------------------------------------------------------------------------------
    // the wiring
    // ---------------------------------------------------------------------------------------------

    public function test_a_stores_own_meta_reaches_its_own_head(): void
    {
        $meta = $this->metaFor($this->storeA);

        $this->assertSame(self::A_KEYWORD, $meta['title'] ?? null,
            'the store\'s configured Meta Keywords value did not reach <meta name="title">');
        $this->assertSame(self::A_DESC, $meta['description'] ?? null,
            'the store\'s configured Meta Description did not reach <meta name="description">');

        // The social pair the vendor helper builds from the same two values.
        $this->assertSame(self::A_KEYWORD, $meta['og:title'] ?? null);
        $this->assertSame(self::A_DESC, $meta['og:description'] ?? null);
        $this->assertSame(self::A_KEYWORD, $meta['twitter:title'] ?? null);
        $this->assertSame(self::A_DESC, $meta['twitter:description'] ?? null);

        // THE IMAGE IS AN ABSOLUTE URL, not the stored relative path: og:image and twitter:image
        // are consumed by crawlers off-site, where a relative path resolves against nothing. The
        // merchant settings screen already renders this value through get_file() for the same
        // reason (AppSettingController:139-140).
        $this->assertStringEndsWith(self::A_IMAGE, $meta['og:image'] ?? '');
        $this->assertStringStartsWith('http', (string) ($meta['og:image'] ?? ''),
            'og:image must be an absolute URL, not the stored relative path');
        $this->assertSame($meta['og:image'] ?? null, $meta['twitter:image'] ?? null);

        // The platform name must no longer be standing in for any of them.
        $this->assertNotSame(config('app.name'), $meta['title'] ?? null);
        $this->assertNotSame(config('app.name'), $meta['og:image'] ?? null);
    }

    /** @return array<string,array{0:string}> every storefront page that answers 200. */
    public static function storefrontPages(): array
    {
        return [
            'landing' => ['home'],
            'about' => ['about'],
            'faq' => ['faq'],
            'contact' => ['contact-us'],
            'product list' => ['product-list'],
            'collections' => ['collections'],
            'privacy policy' => ['privacy-policy'],
            'blog' => ['blog'],
            'track order' => ['track-order'],
            'cart' => ['cart'],
            'wishlist' => ['wishlist'],
            'customer login' => ['login'],
        ];
    }

    /**
     * EVERY page, not just the landing page. The helper is called from the shared layout, so the
     * wiring either reaches all of them or the composer is not the thing feeding it.
     */
    #[DataProvider('storefrontPages')]
    public function test_every_storefront_page_carries_the_stores_own_meta(string $page): void
    {
        $meta = $this->metaFor($this->storeA, $page);

        $this->assertSame(self::A_KEYWORD, $meta['title'] ?? null,
            "GET /{$this->storeA->slug}/{$page} did not carry the store's own Meta Keywords");
        $this->assertSame(self::A_DESC, $meta['description'] ?? null);
    }

    // ---------------------------------------------------------------------------------------------
    // per-store isolation — the point of the whole change
    // ---------------------------------------------------------------------------------------------

    public function test_one_stores_meta_never_appears_on_another_stores_page(): void
    {
        $a = $this->metaFor($this->storeA);
        $b = $this->metaFor($this->storeB);

        $this->assertSame(self::A_KEYWORD, $a['title'] ?? null);
        $this->assertSame(self::B_KEYWORD, $b['title'] ?? null);
        $this->assertSame(self::A_DESC, $a['description'] ?? null);
        $this->assertSame(self::B_DESC, $b['description'] ?? null);

        // Stated as an absence too, over the WHOLE page rather than the parsed tags: a leak into
        // any other sink on the page is the same disclosure.
        $pageA = $this->get('/' . $this->storeA->slug . '/home')->getContent();
        $pageB = $this->get('/' . $this->storeB->slug . '/home')->getContent();

        $this->assertStringNotContainsString(self::B_KEYWORD, $pageA,
            "store B's SEO keywords appear on store A's storefront");
        $this->assertStringNotContainsString(self::B_DESC, $pageA);
        $this->assertStringNotContainsString(self::A_KEYWORD, $pageB,
            "store A's SEO keywords appear on store B's storefront");
        $this->assertStringNotContainsString(self::A_DESC, $pageB);
        $this->assertStringNotContainsString(self::A_IMAGE, $pageB);
    }

    /**
     * THE TRAP. `getCurrentStore()` answers for the merchant's OWN store on the `web` guard, and a
     * merchant with a panel session is a populated web-guard principal on every storefront request
     * — storefront routes are inside the `web` group and the session cookie path is `/`. If the
     * composer ever drops the explicit `$store->id` and lets `Utility::GetValueByName` fall back to
     * its default, THIS is the test that reddens, and nothing else here would.
     */
    public function test_a_merchant_browsing_another_store_sees_that_stores_meta(): void
    {
        $this->actingAs($this->merchantA);          // the `web` guard, current_store = store A

        // PREMISE: this principal really does resolve store A as "the current store", so the
        // defect is available to be made. Without this the test could pass on a null tenant.
        $this->assertSame($this->storeA->id, getCurrentStore(),
            'the fixture merchant does not resolve store A as the current store, so this test is '
            . 'not exercising the getCurrentStore() trap at all.');

        $meta = $this->metaFor($this->storeB);      // ... browsing store B's storefront

        $this->assertSame(self::B_KEYWORD, $meta['title'] ?? null,
            'a merchant signed into store A saw store A\'s SEO metadata in store B\'s <head>. The '
            . 'storefront must take its store from the route slug, never from getCurrentStore().');
        $this->assertSame(self::B_DESC, $meta['description'] ?? null);
        $this->assertStringEndsWith(self::B_IMAGE, $meta['og:image'] ?? '');

        $this->assertStringNotContainsString(
            self::A_KEYWORD,
            $this->get('/' . $this->storeB->slug . '/home')->getContent(),
            'store A\'s keywords leaked into store B\'s page for a signed-in merchant of store A'
        );
    }

    /** The same trap from the other side: a SHOPPER signed in to store A, browsing store B. */
    public function test_a_signed_in_shopper_of_one_store_sees_the_other_stores_meta(): void
    {
        $customer = \App\Models\Customer::withoutGlobalScopes()->create([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'shopper-a@example.test', 'password' => bcrypt('x'),
            'store_id' => $this->storeA->id, 'created_by' => $this->merchantA->id,
        ]);

        $this->actingAs($customer, 'customers');

        $meta = $this->metaFor($this->storeB);

        $this->assertSame(self::B_KEYWORD, $meta['title'] ?? null);
        $this->assertSame(self::B_DESC, $meta['description'] ?? null);
    }

    // ---------------------------------------------------------------------------------------------
    // all four layout copies
    // ---------------------------------------------------------------------------------------------

    /** @return array<string,array{0:string}> */
    public static function themes(): array
    {
        return [
            'stylique' => ['stylique'],
            'greentic' => ['greentic'],
            'techzonix' => ['techzonix'],
            'no theme chosen, falls back to config(theme.active)' => [''],
        ];
    }

    /**
     * Each theme has its OWN copy of `front_end/layouts/app.blade.php` and the theme's copy wins
     * through `Theme::set()`. A wiring that reached only one of them would pass every assertion
     * above and still be broken for two thirds of the installed themes.
     */
    #[DataProvider('themes')]
    public function test_every_theme_gets_its_own_meta(string $theme): void
    {
        [$merchant, $store] = $this->makeTenant('t-' . ($theme === '' ? 'none' : $theme), $theme, 'en');

        $keyword = 'THEME-KEYWORD-' . ($theme === '' ? 'NONE' : strtoupper($theme));
        Setting::putForStore('metakeyword', $keyword, $store->id, $merchant->id);
        Setting::putForStore('metadesc', 'THEME-DESC', $store->id, $merchant->id);

        $meta = $this->metaFor($store);

        $this->assertSame($keyword, $meta['title'] ?? null,
            "the layout copy serving theme '{$theme}' did not receive the store's own meta. There "
            . 'are four copies of front_end/layouts/app.blade.php and the theme\'s own wins.');
        $this->assertSame('THEME-DESC', $meta['description'] ?? null);
    }

    // ---------------------------------------------------------------------------------------------
    // the unconfigured store must not regress
    // ---------------------------------------------------------------------------------------------

    public function test_a_store_that_configured_nothing_keeps_the_platform_fallback(): void
    {
        [, $bare] = $this->makeTenant('bare', 'stylique', 'en');

        $meta = $this->metaFor($bare);

        $this->assertSame(config('app.name'), $meta['title'] ?? null,
            'a store with no SEO settings must still get the existing config(app.name) fallback');
        $this->assertSame(config('app.name'), $meta['description'] ?? null);
        $this->assertSame(route('landing_page', $bare->slug), $meta['og:url'] ?? null);
    }

    public function test_an_empty_stored_value_falls_back_rather_than_emitting_an_empty_tag(): void
    {
        // `settings` has no NOT NULL or length constraint on `value` and several handlers write
        // `$request->all()`, so an empty string is a state the table really reaches -- rows exist
        // from before the current validation. The helper's `??` fallback only fires on null, so an
        // empty string would otherwise render an empty title on every page of that store.
        [$merchant, $store] = $this->makeTenant('empty', 'stylique', 'en');
        Setting::putForStore('metakeyword', '', $store->id, $merchant->id);
        Setting::putForStore('metadesc', '', $store->id, $merchant->id);

        $meta = $this->metaFor($store);

        $this->assertSame(config('app.name'), $meta['title'] ?? null);
        $this->assertSame(config('app.name'), $meta['description'] ?? null);
    }

    public function test_an_unresolvable_image_falls_back_rather_than_emitting_an_empty_tag(): void
    {
        // `get_file()` ends in `catch (\Throwable) { return ''; }`. Drive the real state that
        // produces it: the platform operator's own store has `storage_setting = 'wasabi'` and none
        // of the wasabi keys, so `$settings['wasabi_key']` is an undefined array key, Laravel
        // promotes the warning, and get_file() swallows it and answers ''. None of those keys is
        // NOT NULL anywhere, so this is a configuration a real deployment reaches.
        $operator = User::forceCreate([
            'name' => 'Operator', 'email' => 'operator-seo@example.test',
            'password' => bcrypt('x'), 'type' => 'super admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);
        [$merchant, $store] = $this->makeTenant('wasabi', 'stylique', 'en');
        $operator->current_store = $store->id;
        $operator->save();

        Setting::putForStore('storage_setting', 'wasabi', $store->id, $operator->id);
        Setting::putForStore('metakeyword', 'WASABI-KEYWORD', $store->id, $merchant->id);
        Setting::putForStore('metaimage', 'uploads/seo/photo.png', $store->id, $merchant->id);

        // PREMISE: get_file() really does answer '' in this state, so the guard has something to do.
        $this->assertSame('', get_file('uploads/seo/photo.png'),
            'get_file() no longer collapses to an empty string for a half-configured remote disk, '
            . 'so this test is not exercising the fallback it was written for.');

        $meta = $this->metaFor($store);

        $this->assertSame('WASABI-KEYWORD', $meta['title'] ?? null, 'the rest of the wiring must still work');
        $this->assertNotSame('', $meta['og:image'] ?? null,
            'an unresolvable image must fall back, not emit an empty og:image');
        $this->assertSame(config('app.name'), $meta['og:image'] ?? null,
            'the fallback for an unresolvable image is the pre-existing one, unchanged');
    }

    // ---------------------------------------------------------------------------------------------
    // bilingual: the page is AR (RTL) and EN (LTR)
    // ---------------------------------------------------------------------------------------------

    public function test_arabic_meta_renders_in_both_directions(): void
    {
        $arabic = 'صابون حلب, زيت الزيتون, غار';
        $description = 'صابون حلب التقليدي يُشحن من سوريا';

        [$merchant, $store] = $this->makeTenant('ar', 'stylique', 'ar');
        Setting::putForStore('metakeyword', $arabic, $store->id, $merchant->id);
        Setting::putForStore('metadesc', $description, $store->id, $merchant->id);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $language => $direction) {
            $response = $this->withCookie('LANGUAGE', $language)->get('/' . $store->slug . '/home');
            $this->assertSame(200, $response->status(), "the {$language} rendering did not answer 200");

            $html = $response->getContent();
            $meta = $this->metaOf($html);

            $this->assertSame($arabic, $meta['title'] ?? null,
                "the Arabic Meta Keywords value did not survive the {$language} rendering");
            $this->assertSame($description, $meta['description'] ?? null);

            // The direction really did change, so both branches of the layout were exercised.
            $this->assertStringContainsString('dir="' . $direction . '"', $html,
                "the {$language} rendering is not {$direction}, so this is one direction twice");

            // Literal Arabic, not numeric entities, and still valid UTF-8 end to end.
            $this->assertStringContainsString('صابون حلب', $html, 'Arabic must not be entity-encoded');
            $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // the injection, now that a real request can carry one end to end
    // ---------------------------------------------------------------------------------------------

    /** @return array<string,array{0:string}> */
    public static function payloads(): array
    {
        return [
            'single quote, attribute injection' => ["XSS-1' onload='XSS-1"],
            'single quote, element injection' => ["XSS-2'><img src=x onerror=XSS-2>"],
            'double quote' => ['XSS-3"><img src=x onerror=XSS-3>'],
            'script tag' => ['XSS-4<script>alert("XSS-4")</script>'],
        ];
    }

    /**
     * THE RENDERED PAGE, not the helper's return value. Before this change the three arguments were
     * always null, so no request could carry a payload into that interpolation; the wiring is what
     * makes this reachable, and `Utility::GetValueByName` applies no filter of its own (its only
     * transform is the currency-symbol cleaner, which ignores these names). So the stored value
     * arrives verbatim at a `{!! !!}` print, and the helper's `e()` is the whole of the defence.
     */
    #[DataProvider('payloads')]
    public function test_a_stored_payload_cannot_break_out_of_the_attribute_on_a_real_page(string $payload): void
    {
        [$merchant, $store] = $this->makeTenant('xss-' . md5($payload), 'stylique', 'en');
        Setting::putForStore('metakeyword', $payload, $store->id, $merchant->id);
        Setting::putForStore('metadesc', $payload, $store->id, $merchant->id);

        // PREMISE: the payload really is stored verbatim. If a writer or reader sanitised it, this
        // test would be measuring that instead of the escaping.
        $this->assertSame($payload, Utility::GetValueByName('metakeyword', $store->id),
            'the payload was altered before it reached the page, so this test no longer measures '
            . 'the escaping at the sink.');

        $response = $this->get('/' . $store->slug . '/home');
        $this->assertSame(200, $response->status());
        $html = $response->getContent();

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        // THE ROUND TRIP. The payload must still be the VALUE of the attribute, byte for byte --
        // bytes that escaped the attribute are by definition no longer in it.
        $meta = $this->metaOf($html);
        $this->assertSame($payload, $meta['title'] ?? null,
            'the payload did not survive as the value of content=, which means it broke out');
        $this->assertSame($payload, $meta['description'] ?? null);

        // Nothing anywhere on the page carries an event handler built from the payload.
        $handlers = [];
        foreach ($xpath->query('//*[@*[starts-with(name(), "on") and contains(., "XSS-")]]') as $element) {
            foreach ($element->attributes as $attribute) {
                if (str_starts_with($attribute->name, 'on')) {
                    $handlers[] = $element->nodeName . '@' . $attribute->name;
                }
            }
        }
        $this->assertSame([], $handlers,
            'an event handler built from the stored payload reached the page: ' . implode(', ', $handlers));

        // And no injected element of our own making.
        $injected = [];
        foreach ($xpath->query('//img[contains(@src, "x")][@onerror] | //script[contains(text(), "XSS-")]') as $element) {
            $injected[] = $element->nodeName;
        }
        $this->assertSame([], $injected,
            'the payload closed the tag and injected an element: ' . implode(', ', $injected));
    }

    public function test_an_apostrophe_in_a_legitimate_value_is_encoded_not_dropped(): void
    {
        // The ordinary case that an over-eager "strip the quotes" fix would silently mangle.
        [$merchant, $store] = $this->makeTenant('apos', 'stylique', 'en');
        Setting::putForStore('metadesc', "Syria's finest olive oil", $store->id, $merchant->id);

        $meta = $this->metaFor($store);

        $this->assertSame("Syria's finest olive oil", $meta['description'] ?? null,
            'a legitimate apostrophe must come back intact after parsing -- encoded in the bytes, '
            . 'not removed from the value');
    }
}
