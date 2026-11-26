<?php

namespace App\Support\Storefront;

use App\Http\Controllers\Storefront\SitemapController;
use Illuminate\Support\Facades\Route;

/**
 * THE PER-STORE SITEMAP'S ROUTE DECLARATION, OWNED BY FIRST-PARTY CODE.  GAP-117 / P03-MP-01.
 * =================================================================================================
 *
 * WHY THE DECLARATION IS HERE AND NOT WRITTEN OUT IN `routes/web.php`.
 *
 * `routes/web.php` was shared with another work package in the same round, so this package may not
 * edit it. Putting the URI, the name and the middleware in a class instead means:
 *
 *   - `routes/web.php` needs exactly ONE line, applied by whoever owns that file:
 *
 *         \App\Support\Storefront\StorefrontSitemapRoute::declare();
 *
 *     It must go AFTER `require __DIR__ . '/customer.php'` (line 1994), outside any group, at the
 *     tail of the file. CLAUDE.md: a later declaration REPLACES an earlier one for the same
 *     method+URI and silently drops its middleware — measured here as safe, because no existing
 *     route matches `{storeSlug}/sitemap.xml` (`page/{page_slug}`, `product/{product_slug}` and
 *     the rest all have a literal second segment), so this adds a slot rather than taking one.
 *     Outside a group it takes the `web` group that `bootstrap/app.php` maps `routes/web.php` to,
 *     which is what the storefront's other routes carry.
 *
 *   - the tests drive the REAL declaration rather than a hand-made imitation of it. A Feature test
 *     that declared its own `Route::get(...)` would prove the controller works at a URI of the
 *     test's choosing and nothing about the one production serves.
 *
 * The guard makes the call idempotent, so once the line above is in `routes/web.php` the tests'
 * own call becomes a no-op and they measure the production declaration from then on.
 */
final class StorefrontSitemapRoute
{
    public const NAME = 'storefront.sitemap';

    public const URI = '{storeSlug}/sitemap.xml';

    /**
     * THE THROTTLE IS NOT DECORATION. Found by working this change through
     * `.claude/agents/tenant-authz-reviewer.md`'s shape 7, against the change's own new route.
     *
     * This is an anonymous, unauthenticated GET that runs two queries bounded only by
     * `StorefrontSeo::SITEMAP_MAX_URLS` — up to 50,000 product rows and 50,000 category rows per
     * request — where the storefront's own product list paginates at 12. Unthrottled that is a
     * cheap amplifier: one request costs the caller nothing and the database a full indexed scan of
     * a tenant's catalogue, and the slug of every store is public.
     *
     * `throttle:30,1` matches the precedent two routes above it in `routes/customer.php`: the
     * public unsubscribe GET carries the same per-IP limiter for the same reason ("a cheap read,
     * per-IP, which is what bounds enumeration"). 30 a minute is far above any crawler's real
     * sitemap rate — Googlebot fetches one infrequently — so it bounds abuse without touching the
     * legitimate caller. Pinned by `StorefrontSitemapTest::test_the_sitemap_route_is_throttled`.
     *
     * IT IS DELIBERATELY OUTSIDE `routes/customer.php`'s
     * `Route::middleware([ActiveTheme::class, 'themelanguage'])` GROUP. `ActiveTheme` calls
     * `visitor()->visit($store)`, so serving the sitemap from inside that group would record every
     * crawler fetch of a machine-readable file as a shop VISIT and inflate the merchant's own
     * analytics. Neither middleware is a security control — `ActiveTheme` sets the theme and logs,
     * `themelanguage` sets the locale — and this route renders no view and emits no translated
     * string, so nothing is lost by sitting outside.
     */
    public static function declare(): void
    {
        if (Route::has(self::NAME)) {
            return;
        }

        Route::get(self::URI, [SitemapController::class, 'show'])
            ->middleware('throttle:30,1')
            ->name(self::NAME);

        /*
         * THE NAME LOOKUP IS NOT REFRESHED BY `->name()`, AND THAT IS NOT A TEST ARTEFACT.
         *
         * `RouteCollection::add()` runs inside `Route::get(...)`, i.e. BEFORE `->name()` has put
         * the name on the action, so `addLookups()` registers no name. The framework normally
         * papers over this by calling `refreshNameLookups()` once from a `booted` callback after
         * the whole routes file has loaded — so a route declared from `routes/web.php` works, and
         * the SAME declaration made after boot (a test, a package, a lazily-registered provider)
         * leaves `Route::has()` false and `route('storefront.sitemap')` throwing.
         *
         * Measured: without this line every case in `StorefrontSitemapTest` failed on its own
         * `Route::has()` premise. Refreshing here makes `declare()` correct wherever it is called
         * from, which is the whole point of putting the declaration in a class. It is guarded by
         * the `Route::has()` return above, so it runs at most once per process, and the framework's
         * own post-boot refresh makes it a no-op when the call does come from the routes file.
         */
        Route::getRoutes()->refreshNameLookups();
    }
}
