<?php

namespace Tests\Feature\Marketplace;

use App\Models\Addon;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * MARKET-001 — THE ADD-ON CATALOGUE MUST RENDER WITHOUT A ROUND TRIP TO THE VENDOR'S SERVER.
 *
 * `GAP-196` records MARKET-001 as "Not located. No app/theme marketplace." The browse half DOES
 * exist, which `PHASE-06-INVENTORY-2026-11-24.md:3266` already corrected: five views under
 * `resources/views/marketplace/`, a catalogue in `HomeController`, and the operator's own Add-on
 * Manager and theme screens. What nothing had measured is that three of those screens take a
 * dependency on a THIRD-PARTY HTTP ENDPOINT to render at all.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE DEFECT, MEASURED
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `Utility::BuyMoreTheme()` is `json_decode(file_get_contents('https://ecom-demo.workdo.io/cronjob/
 * addon.json'), true)` inside a `try`, and it has **no return statement on the failure path**:
 *
 *   - the fetch throws (Laravel's `HandleExceptions::handleError` turns the warning into an
 *     `ErrorException`, which IS an `Exception`) -> `return []`;
 *   - the fetch succeeds but the payload is empty -> falls off the end -> returns **null**.
 *
 * Its three callers pass the result straight into a view. `resources/views/module/index.blade.php`
 * guards every use (`isset($theme['add_ons'])`, `?? []`), so the Add-on Manager survives. The other
 * two do not: `addon/index.blade.php` does `@foreach ($theme['theme'] ...)` and
 * `addon/apps.blade.php` does `@foreach ($apps['apps'] ...)`, both unguarded. So `GET /addon` and
 * `GET /addon-apps` are **HTTP 500** whenever that host is unreachable, slow, or answers a payload
 * without those keys — which is the normal state of any installation with restricted egress.
 *
 * IT IS NOT TESTED BY BREAKING THE NETWORK, and that matters. The endpoint is reachable from this
 * environment, so a test that relied on a failed fetch would pass for the wrong reason here and be
 * flaky everywhere. These cases drive the function's OWN DOCUMENTED FAILURE RETURNS — `[]` and
 * `null` — straight into the views, which is deterministic and is exactly the state the callers see.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS NOT CHANGED, DELIBERATELY
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The outbound call itself stays. Removing the vendor's catalogue is a product decision about what
 * the operator is shown, not a defect; `MARKET-001`'s own text ("سوق للتطبيقات والثيمات
 * والمطورين") and `MARKET-003`'s unresolved commercial model are what decide whether a first-party
 * catalogue replaces it, and neither is engineering's to settle. What changes is that a failure to
 * reach it degrades to an empty catalogue instead of a 500.
 */
class AddonCatalogueSurvivesTheVendorFeedTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The shape every caller may rely on — WITH THE REMOTE REACHABLE.
     *
     * This passes through the SUCCESS path in this environment, and that is why it is not enough on
     * its own: a mutation emptying the fallback shape left it green. The failure path is driven
     * separately below, which is where the guarantee actually bites.
     */
    #[Test]
    public function test_the_catalogue_reader_returns_the_three_keys_it_promises(): void
    {
        $this->assertShape(Utility::BuyMoreTheme());
    }

    /**
     * THE FAILURE PATH, DRIVEN DETERMINISTICALLY, by replacing PHP's `https` stream wrapper for the
     * duration of one call.
     *
     * This exists because a MUTATION PROVED THE SUITE COULD NOT TELL. Emptying the fallback shape
     * (`$empty = []`) left every other case in this file green: the vendor host is reachable from
     * this environment, so the success path answered and the fallback was never executed. That is
     * CLAUDE.md's most expensive recurring defect -- a test that passes for the wrong reason -- and
     * it was this file's own test.
     *
     * The wrapper is unregistered and restored around the single call, so nothing else in the
     * process sees it, and NO SEAM IS ADDED TO PRODUCTION CODE: `Utility::BuyMoreTheme()` is called
     * exactly as the three controllers call it, and the thing that changes is the network
     * underneath it, which is the condition being tested.
     */
    #[Test]
    public function test_the_catalogue_reader_returns_the_three_keys_when_the_vendor_host_cannot_be_reached(): void
    {
        $result = $this->withHttpsUnreachable(fn () => Utility::BuyMoreTheme());

        $this->assertShape($result);

        foreach (['theme', 'apps', 'add_ons'] as $key) {
            $this->assertSame([], $result[$key], 'With no catalogue, every list must be empty rather than absent.');
        }
    }

    /**
     * A PAYLOAD WITH ONLY SOME OF THE KEYS, which is the other way this used to 500: the host was
     * reachable and the response simply did not carry `apps`. Added because a mutation removing the
     * key normalisation left every other case green -- the live endpoint happens to return all
     * three keys today, so normalisation was a no-op in every test that used the real network.
     *
     * The value under a key the remote DID send must survive; only the missing ones are filled.
     */
    #[Test]
    public function test_a_partial_catalogue_payload_is_filled_out_rather_than_left_with_holes(): void
    {
        $result = $this->withHttpsReturning('{"theme":[["dark","https://example.test/a.png"]]}', fn () => Utility::BuyMoreTheme());

        $this->assertShape($result);
        $this->assertSame([['dark', 'https://example.test/a.png']], $result['theme'], 'what the remote sent must survive');
        $this->assertSame([], $result['apps']);
        $this->assertSame([], $result['add_ons']);
    }

    /** A payload that is not an object at all -- a plain string, or `null` from an empty body. */
    #[Test]
    public function test_a_catalogue_payload_that_is_not_an_object_falls_back_to_the_empty_shape(): void
    {
        $this->assertShape($this->withHttpsReturning('"not an object"', fn () => Utility::BuyMoreTheme()));
        $this->assertShape($this->withHttpsReturning('', fn () => Utility::BuyMoreTheme()));
    }

    private function assertShape(mixed $result): void
    {
        $this->assertIsArray($result, 'BuyMoreTheme() must never return null; it used to, by falling off the end.');

        foreach (['theme', 'apps', 'add_ons'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $result,
                "Every view that reads the catalogue reads \$catalogue['{$key}']. The key must exist even when the remote gave nothing."
            );
            $this->assertIsArray($result[$key]);
        }
    }

    private function withHttpsUnreachable(callable $callback): mixed
    {
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', UnreachableHttpsStream::class);

        try {
            return $callback();
        } finally {
            stream_wrapper_restore('https');
        }
    }

    private function withHttpsReturning(string $body, callable $callback): mixed
    {
        CannedHttpsStream::$body = $body;

        stream_wrapper_unregister('https');
        stream_wrapper_register('https', CannedHttpsStream::class);

        try {
            return $callback();
        } finally {
            stream_wrapper_restore('https');
        }
    }

    /**
     * EACH SCREEN IS RENDERED THROUGH A REAL REQUEST, WITH THE CATALOGUE VALUE SUPPLIED.
     *
     * Not `view(...)->render()`, which these cases started as and which cannot work here:
     * `layouts/app.blade.php:411` calls `\Request::route()->getName()` and `partision/sidebar`
     * reads `$user->id`, so a bare render fails in the LAYOUT -- "Call to a member function
     * getName() on null" -- and a case failing that way proves nothing about the catalogue.
     *
     * Not a real `GET /addon` either, and this is the more interesting constraint:
     * `ecom-demo.workdo.io` IS reachable from this environment, so a test that hit the controller
     * would exercise the SUCCESS path and pass whatever the failure path does. Making the network
     * fail is not available and would be flaky where it is.
     *
     * So a throwaway route hands the view the function's own documented failure value, inside a real
     * request. The Blade executed is the product's; only the data is chosen.
     */
    private function renderThroughARequest(string $view, array $data): \Illuminate\Testing\TestResponse
    {
        Route::middleware('web')->get('/_p6_market_catalogue_probe', fn () => view($view, $data));

        return $this->actingAs($this->makePlatformAdmin())->get('/_p6_market_catalogue_probe');
    }

    #[Test]
    public function test_the_installed_theme_screen_renders_with_an_empty_catalogue(): void
    {
        Addon::create(['theme_id' => 'stylique', 'status' => '1']);

        $response = $this->renderThroughARequest('addon.index', [
            'theme' => [],
            'addon_themes' => Addon::get(),
        ]);

        $response->assertOk();
        $response->assertSee('stylique', false);
    }

    #[Test]
    public function test_the_installed_theme_screen_renders_when_the_catalogue_is_null(): void
    {
        Addon::create(['theme_id' => 'greentic', 'status' => '1']);

        $response = $this->renderThroughARequest('addon.index', [
            'theme' => null,
            'addon_themes' => Addon::get(),
        ]);

        $response->assertOk();
        $response->assertSee('greentic', false);
    }

    #[Test]
    public function test_the_addon_apps_screen_renders_with_an_empty_catalogue(): void
    {
        $response = $this->renderThroughARequest('addon.apps', ['apps' => []]);

        $response->assertOk();
        $response->assertSee('Buy More Themes', false);
    }

    #[Test]
    public function test_the_addon_apps_screen_renders_when_the_catalogue_is_null(): void
    {
        $response = $this->renderThroughARequest('addon.apps', ['apps' => null]);

        $response->assertOk();
        $response->assertSee('Buy More Themes', false);
    }

    /**
     * The Add-on Manager was ALREADY guarded; this pins that, so a later tidy-up of the guards in
     * `module/index.blade.php` cannot quietly take it the other way.
     */
    #[Test]
    public function test_the_add_on_manager_screen_renders_with_an_empty_catalogue(): void
    {
        $response = $this->renderThroughARequest('module.index', [
            'modules' => [],
            'module_path' => null,
            'theme' => [],
            'addon_themes' => Addon::get(),
            'addon_tab' => 'pills-premium-tab',
        ]);

        $response->assertOk();
        $response->assertSee('Premium Add-On', false);
    }
}

/**
 * An `https` stream wrapper that refuses to open anything — the state of a production installation
 * whose egress does not reach `ecom-demo.workdo.io`. Registered only for the duration of one call.
 */
class UnreachableHttpsStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return false;
    }
}

/** An `https` wrapper that answers one canned body, for the payload-shape cases. */
class CannedHttpsStream
{
    public static string $body = '';

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return false;
    }
}
