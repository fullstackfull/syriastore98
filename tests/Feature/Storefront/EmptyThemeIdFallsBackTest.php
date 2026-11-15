<?php

namespace Tests\Feature\Storefront;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M0 — a store with no theme chosen must still render, in the platform's configured theme.
 *
 * `stores.theme_id` is `$table->string('theme_id')` — NOT nullable (`2023_12_14_090259`), so the
 * empty case is the empty STRING, and `''` is falsy in PHP. That one fact broke the whole
 * storefront for such a store:
 *
 *   * `ActiveTheme::handle()` tested `if ($store && $store->theme_id)`, so for `''` it fell to
 *     `getThemeNameFromUrl()`, which returns null for an ordinary storefront URL. `Theme::set()`
 *     was therefore never called, no theme view root was prepended, and `view()->exists('main_file')`
 *     was false.
 *   * `getThemeSetting($id, '')` built `base_path('themes//default_data/settings.php')`, which does
 *     not exist, so `$defaultSettings` was `[]` and the merged array came back with NO KEYS. Every
 *     storefront view indexes that array directly.
 *
 * MEASURED on a third dev-fixture store (`homs-notheme`, `theme_id = ''`): `/home` refused and the
 * other ELEVEN page routes answered 500 — `Undefined array key "abouts_us_banner_status"` and its
 * siblings. The M0 inventory had this recorded as "open, unreproduced" and predicted a degraded
 * "base layout with no dir". It is not degradation; it is a crash.
 *
 * THE FALLBACK IS NOT A NEW BUSINESS RULE. `config/theme.php` already ships
 * `'active' => 'stylique'`, which is what Qirolab's theme resolver uses when nothing is set — this
 * change makes the two helpers read that existing configuration instead of building paths from an
 * empty string. No merchant's stored theme choice is altered, and a store that HAS a theme is
 * unaffected.
 *
 * Stored customisations are deliberately NOT part of the fallback: only the DEFAULTS resolve
 * through the configured theme. `ThemeCustomize` is still looked up on the store's own `theme_id`,
 * so a themeless store shows defaults rather than settings a merchant saved against some other
 * theme.
 */
class EmptyThemeIdFallsBackTest extends TestCase
{
    use RefreshDatabase;

    private Store $themeless;
    private Store $themed;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = User::forceCreate([
            'name' => 'Theme Merchant', 'email' => 'theme-merchant@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        // `theme_id` is NOT NULL, so `forceCreate` with '' is the only way to build this row.
        $this->themeless = Store::forceCreate([
            'name' => 'Themeless Store', 'email' => 'themeless@example.test',
            'slug' => 'themeless-store', 'theme_id' => '',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->themed = Store::create([
            'name' => 'Themed Store', 'email' => 'themed@example.test',
            'slug' => 'themed-store', 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $this->themed->id;
        $merchant->save();
    }

    /** The premise. If this fails the fixture is not exercising the case at all. */
    #[Test]
    public function test_the_fixture_store_really_has_an_empty_theme_id(): void
    {
        $row = Store::withoutGlobalScopes()->find($this->themeless->id);

        $this->assertSame('', (string) $row->theme_id,
            'The themeless fixture does not actually carry an empty theme_id, so every assertion '
            . 'below is about something else.');
        $this->assertNotSame('', (string) config('theme.active'),
            'config(theme.active) is empty, so there is nothing to fall back TO.');
    }

    /** @return array<string, array{0: string}> */
    public static function pageRoutes(): array
    {
        return [
            'home' => ['home'],
            'product-list' => ['product-list'],
            'collections' => ['collections'],
            'cart' => ['cart'],
            'wishlist' => ['wishlist'],
            'about' => ['about'],
            'faq' => ['faq'],
            'contact-us' => ['contact-us'],
            'blog' => ['blog'],
            'track-order' => ['track-order'],
            'privacy-policy' => ['privacy-policy'],
            'login' => ['login'],
        ];
    }

    #[Test]
    #[DataProvider('pageRoutes')]
    public function test_a_themeless_store_does_not_crash(string $route): void
    {
        $response = $this->get("/{$this->themeless->slug}/{$route}");

        $this->assertNotSame(500, $response->getStatusCode(),
            "/{$route} answered 500 for a store whose theme_id is ''. The merged theme-settings "
            . 'array came back with no keys and the view indexed it.');
        $this->assertNotSame(503, $response->getStatusCode(),
            "/{$route} answered 503 for a store whose theme_id is '': no theme view root was "
            . 'prepended, so main_file could not resolve.');
    }

    /**
     * THE POSITIVE CONTROL. The same routes on a store that HAS a theme must behave the same way,
     * or the case above is satisfied by a route that refuses for an unrelated reason.
     */
    #[Test]
    #[DataProvider('pageRoutes')]
    public function test_the_same_route_is_healthy_for_a_themed_store(string $route): void
    {
        $response = $this->get("/{$this->themed->slug}/{$route}");

        $this->assertNotSame(500, $response->getStatusCode(),
            "/{$route} is 500 for a store WITH a theme, so the themeless case proves nothing.");
        $this->assertNotSame(404, $response->getStatusCode(),
            "/{$route} is 404 for a store WITH a theme.");
    }

    /* =================================================================================
     * It must render the real page, not an empty one
     * ================================================================================= */

    #[Test]
    public function test_the_themeless_home_renders_a_real_document(): void
    {
        $response = $this->get("/{$this->themeless->slug}/home");

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('<html', strtolower($body),
            'A themeless store answered 200 with no document — the empty-body failure mode that '
            . 'SlotLayoutViewsRenderTest exists to catch.');
        $this->assertGreaterThan(2000, strlen($body),
            'The themeless home is ' . strlen($body) . ' bytes. Correct chrome around an empty '
            . 'content area answers 200 too.');
    }

    /**
     * `dir` is the one attribute that proves the theme layout rendered: it is set by the theme's
     * own `main_file`/layout, not by the framework.
     */
    #[Test]
    public function test_the_themeless_store_still_gets_a_direction_attribute(): void
    {
        $body = $this->get("/{$this->themeless->slug}/home")->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*\sdir=["\'](ltr|rtl)["\']/i', $body,
            'No dir attribute, so the base layout rendered instead of a theme layout — exactly '
            . 'what the M0 inventory predicted for an empty theme_id.');
    }

    /**
     * ARABIC / RTL, which the directive asks for by name. The storefront locale is driven by the
     * application's own switch, so the store default is used here: a store whose
     * `default_language` is `ar` must come back `rtl`.
     */
    #[Test]
    public function test_a_themeless_arabic_store_renders_rtl(): void
    {
        $arabic = Store::forceCreate([
            'name' => 'Themeless Arabic', 'email' => 'themeless-ar@example.test',
            'slug' => 'themeless-arabic', 'theme_id' => '',
            'created_by' => $this->themeless->created_by, 'is_active' => 1,
            'default_language' => 'ar',
        ]);

        $body = $this->get("/{$arabic->slug}/home")->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*\sdir=["\']rtl["\']/i', $body,
            'A themeless Arabic store did not render RTL.');
    }

    /**
     * THEME ASSET PATHS. With no theme set the asset URLs came out as `themes//...` — a double
     * slash that resolves to the wrong place. A rendered page must not contain one.
     */
    #[Test]
    public function test_no_asset_url_is_built_from_an_empty_theme_name(): void
    {
        $body = $this->get("/{$this->themeless->slug}/home")->getContent();

        $this->assertStringNotContainsString('themes//', $body,
            'An asset URL was built from an empty theme name (`themes//...`). Measured before the '
            . 'fix: six of them, every stylesheet and script in the layout, all 404.');

        // And it must name the CONFIGURED theme, not merely avoid the empty one.
        $this->assertStringContainsString(
            'themes/' . config('theme.active') . '/assets/',
            $body,
            'The themeless page references no theme assets at all, so "no themes//" is satisfied '
            . 'by a page that simply has no stylesheets.'
        );
    }

    /* =================================================================================
     * The helper itself
     * ================================================================================= */

    #[Test]
    public function test_get_theme_setting_never_returns_a_keyless_array(): void
    {
        $settings = getThemeSetting($this->themeless->id, '');

        $this->assertNotEmpty($settings,
            "getThemeSetting(id, '') returned an empty array. Every storefront view indexes this "
            . 'directly, so an absent key is an ErrorException and a 500.');
        $this->assertArrayHasKey('abouts_us_banner_status', $settings,
            'The key whose absence was the measured cause of the /about 500.');
    }

    #[Test]
    public function test_the_fallback_matches_the_configured_theme_and_not_a_hardcoded_one(): void
    {
        $viaEmpty = getThemeSetting($this->themeless->id, '');
        $viaConfigured = getThemeSetting($this->themeless->id, (string) config('theme.active'));

        $this->assertSame(array_keys($viaConfigured), array_keys($viaEmpty),
            'The empty-theme fallback does not resolve to config(theme.active).');
    }

    /** A store that HAS a theme must be untouched by any of this. */
    #[Test]
    public function test_a_store_with_its_own_theme_is_unaffected(): void
    {
        $own = getThemeSetting($this->themed->id, 'greentic');
        $fallback = getThemeSetting($this->themed->id, '');

        $this->assertNotSame(count($fallback), count($own),
            'greentic (538 default keys) and stylique (525) resolved to the same key count, so '
            . 'the named theme is being ignored in favour of the fallback.');
    }
}
