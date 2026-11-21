<?php

namespace Tests\Feature\Ui;

use App\Http\Controllers\HomeController;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE SHARED ADMIN CHROME MUST RENDER FOR A PRINCIPAL WHO RESOLVES NO STORE.
 *
 * `partision/header.blade.php:12` hands `getStoreById(getCurrentStore())` to
 * `HomeController::getThemeUrl()`. That is NULL for any principal who resolves no store -- a
 * merchant between stores, and the platform operator, for whom `getCurrentStore()` returns the
 * hardcoded literal 1 and `getStoreById(1)` finds nothing when store 1 does not exist. The method
 * then reached `route('landing_page', $store->slug)` and died.
 *
 * MEASURED BEFORE THE FIX, by driving the operator screen over HTTP in
 * `tests/Feature/Platform/PlatformAnalyticsScreenTest`:
 *
 *     Failed asserting that 500 is identical to 200.
 *     ErrorException: Attempt to read property "slug" on null
 *         in app/Http/Controllers/HomeController.php:610
 *
 * THIS FILE MAKES NO HTTP REQUEST, and says so because the distinction matters to a reader: it
 * pins the HELPER's contract directly, and the end-to-end coverage lives in that screen test and
 * in `tests/Feature/ProfileTest::test_profile_page_is_displayed`. Both were run on revert.
 *
 * So the chrome was a 500 on EVERY merchant screen such a principal opened. It was known:
 * `StoreFinanceController` measured it twice (:806, :1639), routed its own refusals to
 * `/dashboard`, and recorded in a comment that the dashboard was a 500 there too and that fixing
 * the chrome was not finance's to do. P5-7 is the first screen designed to work WITHOUT a store,
 * so it is the first that cannot route around it.
 *
 * THE FIX IS TO RETURN NULL, NOT TO ADD A FALLBACK. Line 250 of that partial already renders
 * `{{ $theme_url ?? '#' }}`: the author expected the value to be absent, and the guard had simply
 * never been reachable because the function threw before it could return.
 *
 * AND IT RETURNS BEFORE THE SETTINGS READS, which is the half worth pinning. Each of them falls
 * back to `getCurrentStore()` — the literal 1 for the operator — so continuing would read another
 * merchant's `domains` / `subdomain` rows and could hand the operator a link to an arbitrary
 * tenant's storefront labelled as their own. A missing link is correct; a confident wrong one is a
 * cross-tenant disclosure in the chrome.
 */
final class StorelessChromeRendersTest extends SecurityTestCase
{
    use RefreshDatabase;

    public function test_the_storefront_link_is_absent_rather_than_fatal_when_no_store_resolves(): void
    {
        $this->assertNull(HomeController::getThemeUrl(null),
            'getThemeUrl(null) must answer null -- the header renders `$theme_url ?? "#"`.');
    }

    /**
     * A store with no slug has no slug-addressed link -- but only because the slug is what the
     * remaining branches need. `stores.slug` is nullable, so this is reachable without a deleted
     * row.
     */
    public function test_a_store_row_with_no_slug_has_no_slug_addressed_link(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $store->slug = null;

        $this->assertNull(HomeController::getThemeUrl($store));
    }

    /**
     * AND IT KEEPS A WORKING CUSTOM-DOMAIN LINK, which is the correction an independent review of
     * the first version of this fix produced.
     *
     * That version guarded `! $store || ! isset($store->slug)` together, at the top. A store with
     * no slug but `enable_domain = 'on'` had always returned `https://<domains>` WITHOUT touching
     * the slug -- it never fatalled and the link worked -- so guarding them together silently took
     * a working link away from a store that exists and is reachable. That is a change well beyond
     * the null-store defect, and this test is why it cannot come back.
     */
    public function test_a_slugless_store_with_a_custom_domain_keeps_its_link(): void
    {
        [$merchant, $store] = $this->makeTenant();

        \App\Models\Setting::create([
            'name' => 'enable_domain', 'value' => 'on',
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
        \App\Models\Setting::create([
            'name' => 'domains', 'value' => 'this-shop.example',
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        $store->slug = null;

        $this->assertSame('https://this-shop.example', HomeController::getThemeUrl($store),
            'a slug-less store with its own domain must keep the link it has always had');
    }

    /**
     * THE PREMISE, without which both assertions above would pass for an empty reason: a real store
     * still gets a real link. A fix that returned null for everybody would satisfy the two cases
     * above and silently remove the storefront link from every merchant's header.
     */
    public function test_a_real_store_still_gets_its_storefront_link(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $url = HomeController::getThemeUrl($store->fresh());

        $this->assertNotNull($url, 'a store with a slug must still produce a storefront link');
        $this->assertStringContainsString((string) $store->slug, $url,
            'the link must address this store, not another');
    }

    /**
     * No settings row of ANOTHER store may be read on the way to deciding there is no link. This is
     * what the early return buys, and it is asserted by effect: store 1's `enable_domain` is set to
     * a value that would otherwise win, and the answer must still be null rather than that domain.
     */
    public function test_no_other_stores_domain_is_borrowed_when_no_store_resolves(): void
    {
        [$merchant, $store] = $this->makeTenant();

        \App\Models\Setting::create([
            'name' => 'enable_domain',
            'value' => 'on',
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);
        \App\Models\Setting::create([
            'name' => 'domains',
            'value' => 'someone-elses-shop.example',
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $answer = HomeController::getThemeUrl(null);

        $this->assertNull($answer);
        $this->assertNotSame('https://someone-elses-shop.example', $answer);
    }
}
