<?php

namespace Tests\Feature\Commerce;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 04 P4-1 — A STOREFRONT'S LANGUAGE IS ITS OWN, NOT THE FIRST MERCHANT'S IN THE DATABASE.
 * =================================================================================================
 *
 * THE DEFECT, measured on a running server before this test existed. On an English storefront --
 * `<html lang="en" dir="ltr">`, English chrome, English product and cart labels -- every commerce
 * AJAX message came back in ARABIC:
 *
 *     POST /{slug}/product_cart   (no variant)  ->  "من فضلك اختر نوعاً من المنتج"
 *     POST /{slug}/product_cart   (success)     ->  "تم إضافة المنتج بنجاح"
 *     POST /{slug}/wish-list-count              ->  "قائمة أمنيات العد"
 *     POST /{slug}/states-list                  ->  "حدد الدولة"
 *
 * and the rendered checkout page showed `State حدد ا…` beside English labels.
 *
 * THE MECHANISM -- TWO RESOLVERS THAT DISAGREE, AND ONLY THE WRONG ONE RUNS ON JSON.
 *
 * `ViewServiceProvider.php:91` composes every storefront view and resolves the locale CORRECTLY:
 *
 *     $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
 *
 * `ThemeLanguage::handle()` -- the middleware on the same routes -- resolves it from the cookie,
 * then the authenticated user, and then, for an anonymous shopper, from
 *
 *     User::where('type','admin')->first()->language
 *
 * cached globally as `admin_details`. That is THE FIRST type-`admin` USER IN THE ENTIRE SHARED
 * DATABASE: in a multi-tenant system, some other merchant, chosen by nothing but having the lowest
 * id. The store being visited is never consulted.
 *
 * A PAGE request composes a view, so the composer OVERWRITES the middleware's wrong answer and the
 * page is correct. A JSON request returns without composing those views, so the composer never
 * runs and the middleware's answer stands. Every storefront AJAX message therefore spoke the
 * first merchant's language while the page around it spoke the store's.
 *
 * THIS IS WHY THE FIRST VERSION OF THIS FILE WAS WRONG, and the correction is the useful part. It
 * drove page GETs and three of its five cases PASSED BEFORE THE FIX -- the composer was quietly
 * satisfying them. A test that only visits pages cannot see this defect at all. Every case below
 * now drives a JSON endpoint, where the middleware is the only resolver.
 *
 * WHAT WAS AND WAS NOT ALREADY KNOWN, stated precisely because the first draft of this file
 * claimed the behaviour was unrecorded and that was too strong.
 * `tests/Feature/Security/WhatsappOrderConcurrencyTest.php:258-260` already describes the
 * mechanism -- "`ThemeLanguage` ... reads `Cookie::get('LANGUAGE')`, falling back to the platform
 * admin's own language" -- but describes it as an OBSTACLE TO WRITING A TEST, not as a defect. It
 * is noted there so a later author knows the cookie is the only lever. What was never recorded is
 * that the fallback crosses tenants, that `stores.default_language` holds the right answer and is
 * ignored, or that the page and the JSON paths therefore disagree. Nothing pinned any of it.
 *
 * WHAT THIS FILE ASSERTS. The fallback order, in both directions:
 *
 *   1. the visitor's own LANGUAGE cookie wins over everything;
 *   2. with no cookie, an anonymous shopper gets THIS STORE's `default_language`;
 *   3. two stores with different `default_language` get different locales in the same test run,
 *      which is the cross-tenant half and the one the old code could not satisfy;
 *   4. a store with no `default_language` falls back to the platform default, not to a stranger's;
 *   5. the first-admin's language does NOT leak in -- the fixture deliberately gives the
 *      lowest-id `type=admin` user a DIFFERENT language from the store under test, so a
 *      regression to the old behaviour fails here by name.
 */
class StorefrontLocaleFollowsTheStoreTest extends TestCase
{
    use RefreshDatabase;

    private Store $english;
    private Store $arabic;

    protected function setUp(): void
    {
        parent::setUp();

        /* THE TRAP, BUILT ON PURPOSE. This merchant has the LOWEST id of any type-`admin` row, so
           `User::where('type','admin')->first()` returns them -- and their language is Arabic. Every
           assertion below that expects English therefore fails if the old fallback comes back. */
        $firstAdmin = User::forceCreate([
            'name' => 'First Admin', 'email' => 'first-admin@p4.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
            'language' => 'ar',
        ]);

        $otherMerchant = User::forceCreate([
            'name' => 'Other Merchant', 'email' => 'other@p4.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
            'language' => 'ar',
        ]);

        $this->english = Store::create([
            'name' => 'P4 English Shop', 'email' => 'p4-en@p4.test', 'slug' => 'p4-english-shop',
            'theme_id' => 'stylique', 'created_by' => $otherMerchant->id, 'is_active' => 1,
            'default_language' => 'en',
        ]);

        $this->arabic = Store::create([
            'name' => 'P4 Arabic Shop', 'email' => 'p4-ar@p4.test', 'slug' => 'p4-arabic-shop',
            'theme_id' => 'stylique', 'created_by' => $firstAdmin->id, 'is_active' => 1,
            'default_language' => 'ar',
        ]);

        /* `admin_details` is a one-hour cache and several handlers read it; CLAUDE.md records it as
           a cause of a test seeing the pre-fixture row. */
        Cache::forget('admin_details');
        Cache::forget('super_admin_details');
    }

    /**
     * The locale the MIDDLEWARE resolved, measured on a JSON route.
     *
     * A JSON endpoint is used deliberately: it is the path on which `ViewServiceProvider`'s
     * composer does not run, so the middleware is the only resolver and the answer is its own. A
     * page GET would measure the composer instead and pass whatever the middleware did.
     */
    private function localeOnJsonRequest(string $slug, array $cookies = []): string
    {
        $request = $this;
        foreach ($cookies as $name => $value) {
            $request = $request->withCookie($name, $value);
        }
        $request->post("/{$slug}/wish-list-count", []);

        return app()->getLocale();
    }

    /**
     * The locale on a route that names the store as its FIRST path segment as `{slug}`, not as
     * `{storeSlug}` -- `routes/customer.php`'s `{slug}/product-wishlist`. `getStore()` reads only
     * `storeSlug`, so before AUD-4 the middleware could not see the store here at all.
     */
    private function localeOnFirstSegmentSlugRoute(string $slug): string
    {
        $this->post("/{$slug}/product-wishlist", []);

        return app()->getLocale();
    }

    /** The same question on a page GET, where the composer DOES run. Used to show the divergence. */
    private function localeOnPageRequest(string $slug, array $cookies = []): string
    {
        $request = $this;
        foreach ($cookies as $name => $value) {
            $request = $request->withCookie($name, $value);
        }
        $request->get("/{$slug}/home");

        return app()->getLocale();
    }

    #[Test]
    public function an_anonymous_shopper_gets_the_language_of_the_store_they_are_visiting(): void
    {
        $this->assertSame('en', $this->localeOnJsonRequest($this->english->slug),
            'the English store served the first admin\'s language instead of its own');
    }

    /**
     * THE CROSS-TENANT HALF. Two stores, two languages, one test run. The old fallback returned the
     * same answer for both because it never looked at the store, so this is the assertion it cannot
     * satisfy however the fixture is ordered.
     */
    #[Test]
    public function two_stores_with_different_languages_get_different_locales(): void
    {
        $en = $this->localeOnJsonRequest($this->english->slug);
        $ar = $this->localeOnJsonRequest($this->arabic->slug);

        $this->assertSame('en', $en);
        $this->assertSame('ar', $ar);
        $this->assertNotSame($en, $ar, 'both storefronts resolved the same locale');
    }

    #[Test]
    public function the_visitors_own_cookie_still_wins_over_the_store_default(): void
    {
        /* A shopper who has chosen a language keeps it, on either store. That choice is the whole
           reason the cookie is checked first and it must survive this change. */
        $this->assertSame('ar', $this->localeOnJsonRequest($this->english->slug, ['LANGUAGE' => 'ar']));
        $this->assertSame('en', $this->localeOnJsonRequest($this->arabic->slug, ['LANGUAGE' => 'en']));
    }

    #[Test]
    public function a_store_with_no_language_falls_back_to_the_platform_default_not_to_a_stranger(): void
    {
        $unset = Store::create([
            'name' => 'P4 Unset Shop', 'email' => 'p4-unset@p4.test', 'slug' => 'p4-unset-shop',
            'theme_id' => 'stylique', 'created_by' => $this->english->created_by, 'is_active' => 1,
            'default_language' => '',
        ]);

        /* NOT 'ar'. The first admin's language is Arabic, so this is the case where the old code
           and the new one differ most visibly. */
        $this->assertSame('en', $this->localeOnJsonRequest($unset->slug));
    }

    /**
     * AUD-4. THE ROUTE THAT SPELLS THE PARAMETER `slug`. The PR #140 deletion audit found that the
     * docblock claiming "`getStore()`, `getRouteStoreId()` and the view composer all use the same
     * source, so the four agree" was false: `getRouteStoreId()` also reads `{slug}` as a first path
     * segment and `getStore()` did not. On `{slug}/product-wishlist` -- an unauthenticated
     * storefront JSON POST -- the locale was therefore the platform default whatever the store's
     * own language, which is the incident this whole file exists for, surviving in one route.
     */
    #[Test]
    public function a_route_that_names_the_store_as_its_first_segment_gets_that_stores_language(): void
    {
        $this->assertSame('ar', $this->localeOnFirstSegmentSlugRoute($this->arabic->slug),
            'the Arabic store was served the platform default on a {slug}/... route');
        $this->assertSame('en', $this->localeOnFirstSegmentSlugRoute($this->english->slug));
    }

    /**
     * THE NEGATIVE CONTROL, and the half that stops the fix being made by reading any `slug`.
     * `getRouteStoreId()` refuses `custom-msg/{slug?}` on purpose: a page or article slug that
     * happens to equal a store's slug must not select that store. If the fallback were widened to
     * `route('slug')` this would answer `ar` for a path that merely CONTAINS the Arabic store's
     * slug, which is a store chosen by what a visitor typed into a URL.
     */
    #[Test]
    public function a_route_that_reuses_slug_for_something_else_is_not_read_as_a_store(): void
    {
        $this->post('/custom-msg/'.$this->arabic->slug, []);

        $this->assertSame('en', app()->getLocale(),
            'a path segment that happens to equal a store slug selected that store');
    }

    #[Test]
    public function the_first_admins_language_is_not_consulted_at_all(): void
    {
        /* Belt and braces, and the one that names the defect. Change the first admin's language to
           something neither store uses; nothing may move. */
        User::query()->where('email', 'first-admin@p4.test')->update(['language' => 'fr']);
        Cache::forget('admin_details');

        $this->assertSame('en', $this->localeOnJsonRequest($this->english->slug));
        $this->assertSame('ar', $this->localeOnJsonRequest($this->arabic->slug));
    }
}
