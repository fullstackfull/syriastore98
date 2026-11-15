<?php

namespace Tests\Feature\Platform;

use App\Http\Controllers\PlatformSiteController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M5 — THE OFFICIAL SITE'S SEVEN PAGES, ASSERTED ON WHAT THEY RENDER.
 * =================================================================================================
 *
 * THREE WAYS THIS SUITE COULD HAVE PASSED WITHOUT PROVING ANYTHING, each guarded against by name.
 *
 *   1. **HTTP 200 FROM THE WRONG HANDLER.** `/platform/faq` and `/platform/contact` are two
 *      segments, and `routes/customer.php` declares `{storeSlug?}/faq` and `{storeSlug?}/contact-us`.
 *      A storefront handler answering 200 for the platform's own URL would look identical to
 *      success. So every route is resolved through the REAL router and the controller class is
 *      asserted, not just the status.
 *
 *   2. **PERFECT CHROME, EMPTY CONTENT.** CLAUDE.md records the measured failure: a layout that
 *      `@yield`s renders header, footer, HTTP 200 and NOTHING in the content area when the view
 *      was written in the `<x-*-layout>` shape. Every page here is asserted on a string that
 *      exists only inside its own `@section('content')`, so chrome alone cannot satisfy it.
 *
 *   3. **A NAVIGATION THAT 500s LATER.** The layout links to the documentation centre only if
 *      `platform.docs.index` is registered. The negative case is asserted here; M6 registers that
 *      route and the positive case is asserted there.
 */
class PlatformSiteRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** url => a string that appears ONLY inside that page's own content section. */
    private const PAGES = [
        '/platform'                  => 'What you get',
        '/platform/features'         => 'What the platform does',
        '/platform/pricing'          => 'Plans',
        '/platform/getting-started'  => 'From signing up to a shop that is open',
        '/platform/faq'              => 'Questions people ask',
        '/platform/contact'          => 'Talk to us',
        '/platform/legal'            => 'Legal information',
    ];

    #[Test]
    public function every_platform_page_answers_200_and_renders_its_own_content(): void
    {
        foreach (self::PAGES as $url => $marker) {
            $response = $this->get($url);

            $response->assertOk();
            /* The marker lives in the page's own @section('content'). The chrome cannot supply it,
               so this fails against the empty-content-area defect that CLAUDE.md records. */
            $response->assertSee($marker, escape: false);
        }
    }

    #[Test]
    public function every_platform_page_also_renders_the_shared_chrome(): void
    {
        foreach (array_keys(self::PAGES) as $url) {
            $html = $this->get($url)->getContent();

            $this->assertStringContainsString('ss-ps-header', $html, "header missing on {$url}");
            $this->assertStringContainsString('ss-ps-footer', $html, "footer missing on {$url}");
            $this->assertStringContainsString('css/store-syria-ds.css', $html, "design system missing on {$url}");
            $this->assertStringContainsString('css/store-syria-platform.css', $html, "site stylesheet missing on {$url}");
            $this->assertStringContainsString('Skip to main content', $html, "skip link missing on {$url}");
        }
    }

    /**
     * THE PRECEDENCE TEST. Resolving through the router is the only way to show that the storefront
     * patterns did not win: an assertOk() cannot tell two 200s apart.
     */
    #[Test]
    public function the_router_resolves_every_platform_url_to_the_platform_controller(): void
    {
        foreach (array_keys(self::PAGES) as $url) {
            $route = Route::getRoutes()->match(
                \Illuminate\Http\Request::create($url, 'GET')
            );

            $this->assertSame(
                PlatformSiteController::class,
                $route->getController()::class,
                "{$url} resolved to " . $route->getActionName() . ' instead of the platform site'
            );
        }
    }

    /**
     * FALSIFICATION OF THE PRECEDENCE CLAIM. The storefront really does declare the colliding
     * patterns; if it did not, the test above would be proving nothing. This asserts the collision
     * exists, so the precedence result is meaningful.
     */
    #[Test]
    public function the_storefront_really_does_declare_colliding_patterns(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($r) => $r->uri())
            ->all();

        $this->assertContains('{storeSlug?}/faq', $uris, 'the collision this suite guards against no longer exists');
        $this->assertContains('{storeSlug?}/home', $uris);
    }

    #[Test]
    public function the_platform_slug_cannot_be_minted_for_a_store(): void
    {
        /* THE MINT PATH ONLY. An earlier version of this comment called `User::slugs()` "the
           single place store slugs are created"; an independent review of M5 measured two more
           writers that never call it, and `ReservedStoreSlugTest` drives all three. Here the
           guard must SUFFIX rather than refuse, because failing a registration form over a
           derived slug would block the sign-up. */
        $this->assertNotSame('platform', User::slugs('Platform'));
        $this->assertSame('platform-1', User::slugs('Platform'));

        /* And the guard must not have broken ordinary slugging. */
        $this->assertSame('abu-samra-sweets', User::slugs('Abu Samra Sweets'));
    }

    #[Test]
    public function an_existing_slug_still_gets_a_suffix(): void
    {
        /* The reserved-word check is woven into the collision loop, so this pins that the loop it
           was woven into still does its original job. */
        $owner = User::forceCreate([
            'name' => 'Slug Owner', 'email' => 'slug-o@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        \App\Models\Store::create([
            'name' => 'Taken Name', 'email' => 'taken@example.test', 'slug' => 'taken-name',
            'theme_id' => 'stylique', 'created_by' => $owner->id, 'is_active' => 1,
        ]);

        $this->assertSame('taken-name-1', User::slugs('Taken Name'));
    }

    /**
     * THE DOCUMENTATION LINK, NOW THAT M6 HAS REGISTERED THE ROUTE.
     *
     * This test was written in M5 as its own opposite: it asserted `Route::has()` was FALSE and
     * that the word "Documentation" was absent, with a message telling whoever made it fail to
     * come back and invert it. M6 registered `platform.docs.index` and it failed exactly as
     * designed. Inverted here rather than deleted, because the guard that matters is unchanged in
     * either direction: the layout calls `route()` only behind `Route::has()`, and calling it
     * without that guard is a RouteNotFoundException, not a broken link.
     */
    #[Test]
    public function the_navigation_links_to_the_documentation_centre(): void
    {
        $this->assertTrue(
            Route::has('platform.docs.index'),
            'PREMISE: the documentation route is gone, so this proves nothing.'
        );

        foreach (array_keys(self::PAGES) as $url) {
            $html = $this->get($url)->getContent();

            $this->assertStringContainsString(
                route('platform.docs.index'),
                $html,
                "no documentation link on {$url}"
            );
        }
    }

    /**
     * THE PAGE AND THE REGISTRY ARE THE SAME LIST.
     *
     * `PlatformCapabilitiesTest` proves the registry cannot name a module that does not exist and
     * that `available()` filters an absent one out. Neither of those matters if the VIEW ignores
     * the filter and prints the declared list instead, so the two are tied together here: every
     * available claim must be on the page, and every claim that was filtered out must not be.
     */
    #[Test]
    public function the_features_page_renders_the_filtered_registry_and_nothing_else(): void
    {
        $html = $this->get('/platform/features')->getContent();

        $available = $filtered = 0;

        foreach (\App\Support\Platform\PlatformCapabilities::groupNames() as $group) {
            $live = array_column(\App\Support\Platform\PlatformCapabilities::available($group), 'title');

            foreach ($live as $title) {
                $available++;
                $this->assertStringContainsString($title, $html, "'{$title}' is available but not rendered");
            }

            foreach (\App\Support\Platform\PlatformCapabilities::declared($group) as $entry) {
                if (in_array($entry['title'], $live, true)) {
                    continue;
                }
                $filtered++;
                $this->assertStringNotContainsString($entry['title'], $html,
                    "'{$entry['title']}' was filtered out but is still on the page");
            }
        }

        $this->assertGreaterThanOrEqual(30, $available,
            'PREMISE: too few claims rendered for this to mean anything.');
        /* Recorded rather than asserted: today every named module ships, so nothing is filtered.
           The assertion loop above is what matters when that stops being true. */
        $this->assertSame(0, $filtered, 'a module named by the registry has been removed from the repository');
    }

    #[Test]
    public function the_pages_render_for_an_installation_with_no_super_admin_and_no_settings(): void
    {
        /* `RefreshDatabase` leaves `users` and `settings` empty, which is exactly the state of a
           fresh installation. The layout wraps `getSuperAdminAllSetting()` for this reason: an
           exception there turns the company's home page into a 500. */
        $this->assertSame(0, User::query()->count());

        $this->get('/platform')->assertOk();
        $this->get('/platform/pricing')->assertOk();
        $this->get('/platform/contact')->assertOk();
    }
}
