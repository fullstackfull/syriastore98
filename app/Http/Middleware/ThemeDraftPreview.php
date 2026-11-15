<?php

namespace App\Http\Middleware;

use App\Models\ThemeVersion;
use App\Services\Theme\ThemeConfigurationService;
use App\Services\Theme\ThemePublishingService;
use App\Support\Theme\ThemeEditorGate;
use App\Support\Theme\ThemePreviewContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Qirolab\Theme\Theme;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHASE 03 M4-5 — RENDER AN UNPUBLISHED DRAFT AS THE STOREFRONT, FOR ITS OWNER ONLY.
 * =================================================================================================
 *
 * THE WORK HAPPENS IN MIDDLEWARE, NOT THE CONTROLLER, AND THAT IS A MEASURED REQUIREMENT.
 * `FileViewFinder::find()` memoises by view name and neither `prependLocation()` nor `setPaths()`
 * clears that cache, while `HomeController::storeSlug()` calls `view()->exists('main_file')` BEFORE
 * `view('main_file')`. Setting the theme inside the controller would therefore resolve the old
 * theme's files against the new theme's settings -- a half-rendered page that reads as a CSS bug
 * rather than a routing one. `ActiveTheme` sets the theme in middleware for the same reason, and
 * this mirrors it.
 *
 * THREE SEAMS THAT LOOK RIGHT AND ARE NOT, each closed by construction here rather than by care:
 *
 *   NO `{theme}` ROUTE PARAMETER. `ViewServiceProvider:50-55` has a latent branch keyed on exactly
 *   that parameter which assigns the UNVALIDATED route string to `$store->theme_id` and resolves
 *   the store through `getCurrentStore()` -- the literal-store-1 fallback M4-1 and M4-2 removed. It
 *   is unreachable today only because the `{theme}` admin routes render `theme_customize.*`, which
 *   the composer does not bind. This route renders a composer-bound storefront view, so a `{theme}`
 *   parameter would switch it on. The version id carries the theme instead.
 *
 *   NO `themes` PATH SEGMENT. `ActiveTheme:71` does `array_search('themes', $request->segments())`
 *   and takes the NEXT segment as the theme name, in the `else` of `$store && $store->theme_id` --
 *   so it fires whenever the store's `theme_id` is the empty string, which is precisely the
 *   pre-launch state a merchant previews from.
 *
 *   NO `theme-preview/` PREFIX. `theme-preview/make-active` is first-party and the vendored WorkDo
 *   `ThemeCustomizer` owns `theme-preview/{theme?}/change-style` and `theme-preview-content/
 *   {theme?}`. A later declaration REPLACES an earlier one for the same method+URI and silently
 *   drops its middleware -- and no WorkDo module loads in a test run without an `add_on_managers`
 *   row, so such a collision is invisible in CI and live in production.
 *
 * AUTHORIZATION IS TENANT MEMBERSHIP (`allows()`), not the management rule. Reading your own
 * tenant's draft is draft work: the owner's decision of 2026-11-15 requires that tenant-authorised
 * staff be able to prepare AND preview drafts without being able to publish them. Publishing stays
 * on `allowsManagement()`. A draft is still unpublished merchant content, so another tenant reading
 * it would be a disclosure even though nothing is written.
 */
class ThemeDraftPreview
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * THE `web` GUARD EXPLICITLY, NEVER `Auth::user()`. MEASURED: under
         * `actingAs($customer, 'customers')` the DEFAULT guard becomes `customers`, so
         * `Auth::user()` returns a **Customer** while `Auth::guard('web')->user()` is null. This
         * codebase records that confusing the two is its most productive defect shape -- a handler
         * that checks the wrong guard is reachable by the wrong principal. A merchant draft is
         * merchant content; a shopper signed into the very store it belongs to must not reach it.
         */
        $user = Auth::guard('web')->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $versionId = $request->route('version');

        if (! is_numeric($versionId) || (int) $versionId <= 0) {
            abort(404);
        }

        $gate = new ThemeEditorGate();
        $storeId = $gate->storeIdForSelfService($user);

        if ($storeId === null || ! $gate->allows($storeId, $user)) {
            abort(403);
        }

        /*
         * SCOPED BY THE TRIPLE, never by bare id. `ThemeVersion` is in neither `scoped_models` nor
         * `Cachable`, and its own docblock records that `ThemeVersion::find($id)` returns another
         * tenant's payload. `ownedBy()` is the predicate that takes store, theme and id together.
         */
        $version = ThemeVersion::query()->where('id', (int) $versionId)->first();

        if (! $version || (int) $version->store_id !== (int) $storeId) {
            abort(404);
        }

        /*
         * THE URL'S SLUG MUST BE THE AUTHORISED STORE'S. The route carries `{storeSlug}` only so
         * that `ViewServiceProvider`'s composer can resolve `$store` -- without it the page
         * `abort(404)`s during rendering. That makes the slug CALLER-SUPPLIED, and a tenant
         * predicate built from caller input is not a tenant check: this codebase records four
         * separate defects of exactly that shape. So the gate resolves the store, and the URL is
         * then required to agree with it rather than to determine it.
         */
        $store = \App\Models\Store::find($storeId);

        if (! $store || (string) $store->slug !== (string) $request->route('storeSlug')) {
            abort(404);
        }

        $themeId = (string) $version->theme_id;

        $settings = (new ThemePublishingService())
            ->preview($storeId, $themeId, (int) $version->id, $user);

        if ($settings === null) {
            abort(403);
        }

        /* The store may own no version yet; the baseline makes history and rollback meaningful. */
        (new ThemeConfigurationService())->ensureBaseline($storeId, $themeId, $user);

        app(ThemePreviewContext::class)->arm($storeId, $themeId, $settings, (int) $version->id);

        /* BEFORE any view is resolved -- see the class docblock. */
        Theme::set($themeId);

        $response = $next($request);

        /*
         * DISARMED EXPLICITLY, rather than trusting the request boundary to do it.
         *
         * `scoped` bindings are cleared by `forgetScopedInstances()`, which Octane calls between
         * requests and which FPM gets for free by using a fresh process each time. Neither applies
         * inside a test, where one application instance serves many requests -- MEASURED: without
         * this line, an armed preview leaked into the NEXT request in the same test, and the public
         * storefront rendered the draft.
         *
         * That is a test artefact today and it is still the wrong thing to depend on: the property
         * this slice promises is "a draft never reaches a shopper", and it should hold because the
         * code says so, not because of how the application happens to be served. The view has
         * already been rendered to a string by the time `$next()` returns, so nothing below needs
         * the context.
         */
        app(ThemePreviewContext::class)->disarm();

        /*
         * NEVER CACHED, ANYWHERE. This is unpublished merchant content rendered on a storefront URL
         * shape; a shared cache storing it would serve one tenant's draft to the next requester.
         */
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
