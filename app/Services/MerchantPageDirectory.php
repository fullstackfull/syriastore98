<?php

namespace App\Services;

use App\Classes\Menu;

/**
 * P2-06-7 — the merchant pages the CURRENT principal may open, keyed by navigation `name`.
 *
 * ONE DECISION POINT, ASKED AGAIN -- NOT COPIED. `App\Classes\Menu::permits()` is the only module
 * entitlement and Laratrust permission gate the navigation has, and `Menu::add()` is the only way
 * an item reaches `$menu->menu`. This class fires the SAME event the sidebar fires and reads what
 * survived, so a favourite is offered exactly when the navigation would offer it. Writing a second
 * permission map here -- `'order' => 'Manage Order'` and so on, 69 rows of it -- is the obvious
 * shortcut and is the defect: it would drift from the listeners the first time a module changed a
 * permission name, and it would silently miss the module-entitlement half of the gate entirely.
 *
 * WHICH ALSO MEANS THE OPERATOR IS HANDLED BY NOT HANDLING IT. CLAUDE.md's rule is that
 * `isAbleTo()` alone refuses the platform operator, so an authorization check must test the
 * operator first. `Menu::permits()` does NOT do that -- but a `super admin` never reaches it,
 * because `getMenu()` branches on `$user->type` and fires `SuperAdminMenuEvent` with an entirely
 * different set of items. Reproducing that branch here, rather than adding an operator bypass to
 * a permission check, is what keeps this class honest: whatever the operator's navigation is, this
 * returns it, and if that branch is ever wrong it is wrong in one place.
 *
 * MEMOISED ON THE REQUEST, NOT IN A STATIC, and both halves of that matter.
 *
 * Why memoised at all: review measured 12 to 21 full navigation builds per admin page, because
 * every helper here rebuilt it and `FavouritePages::all()` / `RecentPages::all()` called
 * `resolve()` once PER ROW. Each build fires the whole menu event -- `getAdminAllsetting()`,
 * `Plan::find()`, `ActivatedModule()`, `module_is_active()` per module and ~69 `isAbleTo()` --
 * which the sidebar pays once. Under `CACHE_STORE=database` each repeat is real `cache` table
 * traffic. The loops now index into one array instead.
 *
 * Why on the request and not in a static: a static answers from BEFORE a permission change for
 * the rest of the process, and a long-lived worker (Octane) has no process boundary at all. The
 * REQUEST is the correct lifetime -- a revoked permission must take effect on the very next one.
 * An earlier version of this file had no cache precisely to avoid a stale permission answer; the
 * request scope keeps that property while removing the multiplier.
 *
 * `forget()` exists for tests that change a permission and then simulate a new request without
 * making one. The authoritative proof that revocation works is still the HTTP-level test, which
 * gets genuinely fresh requests and so cannot be fooled by this cache.
 */
final class MerchantPageDirectory
{
    /**
     * Every page this principal may open, keyed by navigation `name`.
     *
     * Only entries that HAVE a route are returned: the navigation also carries pure containers
     * (`products` is a parent with no route of its own, rendered as a submenu heading), and a
     * favourite pointing at one would be a link to `#!`.
     *
     * @return array<string, array{name:string,title:string,icon:?string,route:string,href:string}>
     */
    public static function available(): array
    {
        $user = auth()->user();

        /* IT MUST BE A MERCHANT-SIDE PRINCIPAL, AND THAT CHECK IS NOT PARANOIA -- it is a
           measured 500. `Menu::permits()` calls `$user->isAbleTo()`, which `App\Models\Customer`
           does not have, so a `Customer` reaching here is a fatal
           `BadMethodCallException`. Because this class is now evaluated by middleware on EVERY
           web request, that fatal landed on storefront pages: eight security tests went from 200
           to 500 with `Call to undefined method App\Models\Customer::isAbleTo()`.

           A `Customer` gets onto the DEFAULT guard easily. `config/auth.php` defaults to `web`
           and `CustomAuth` logs a customer into `customers`, so the reasoning "a customer leaves
           the web guard empty" sounds right -- but `actingAs($customer)` with no guard argument
           puts the Customer on `web`, and Laravel does not type-check the model against the
           guard's provider. Whatever the route in, an `instanceof` test is the honest control:
           this class answers for merchant-side principals and for nobody else. */
        if (! $user instanceof \App\Models\User) {
            return [];
        }

        $request = request();
        $key = 'ss_page_directory_' . (int) $user->id;

        if ($request && $request->attributes->has($key)) {
            return (array) $request->attributes->get($key);
        }

        $menu = new Menu($user);

        /* THE SHARED HELPER, NOT A LOCAL NORMALISATION, and the difference is a real defect
           rather than a style point. My first version was
           `strtolower(trim((string) $user->type)) === 'super admin'`, and `UserPolicy` records
           why `trim()` is the wrong function here: MySQL's PAD SPACE folds a TRAILING space but
           not a leading one, so `trim()` grants operator status to a row every SQL comparison
           refuses -- "the opposite failure from the lockout and a worse one"
           (app/Policies/UserPolicy.php:85-91). `isOperator()` uses `rtrim($type, ' ')` to match
           the engine.

           `OperatorCheckCensusTest` forbids exactly that construct, but only inside three named
           files, so CI would NOT have caught it here. It is also why the fix is the helper and
           not a literal comparison: `$user->type == 'super admin'` matches the census's second
           pattern and would push the un-normalised count to 102 against a CEILING of 101,
           turning the suite red for writing the same bug a different way. */
        if (\App\Policies\UserPolicy::isOperator($user)) {
            event(new \App\Events\SuperAdminMenuEvent($menu));
        } else {
            event(new \App\Events\CompanyMenuEvent($menu));
        }

        $pages = [];

        foreach ($menu->menu as $item) {
            $name = (string) ($item['name'] ?? '');
            $route = (string) ($item['route'] ?? '');

            if ($name === '' || $route === '') {
                continue;
            }

            /* THE SAME EXCLUSION `menuTree()` APPLIES, for the same reason. `Menu::add()` permits
               `mobilescreensetting` but the renderer drops it unless `platform.is_mobile` is
               'yes' (app/Helper/helper.php:98), so without this the page is favouritable and
               offerable while being absent from the navigation it is supposed to mirror. Measured:
               it was one of exactly two entries a merchant with no permissions received. */
            if ($name === 'mobilescreensetting' && config('platform.is_mobile') !== 'yes') {
                continue;
            }

            /* A route the navigation names but the router does not know would throw inside the
               view, exactly as `menuTree()` notes about its own `route()` call. A favourites list
               is a worse place to discover that than a sidebar, because the row is stored, so the
               page is skipped rather than allowed to break the render. */
            if (! \Illuminate\Support\Facades\Route::has($route)) {
                continue;
            }

            /* AND IT MUST NOT NEED PARAMETERS. `Route::has()` says the name exists, not that
               `route($name)` can be called with no arguments -- a route with a required segment
               throws `UrlGenerationException`. No navigation route is parameterised today (every
               host entry is `*.index`-shaped, and `menuTree()` would already throw on every admin
               page if one were), so this is defence rather than a live fix. It is worth the two
               lines because this class is now evaluated by middleware on pages that render no
               sidebar: without it, a future module declaring a parameterised menu route would
               500 the storefront for a signed-in merchant rather than just its own screen. */
            if (\Illuminate\Support\Facades\Route::getRoutes()->getByName($route)?->parameterNames()) {
                continue;
            }

            $pages[$name] = [
                'name'  => $name,
                'title' => __($item['title'] ?? $name),
                'icon'  => $item['icon'] ?? null,
                'route' => $route,
                'href'  => route($route),
            ];
        }

        if ($request) {
            $request->attributes->set($key, $pages);
        }

        return $pages;
    }

    /**
     * Drop the per-request cache.
     *
     * For a test that revokes a permission and then asks again WITHOUT making a new request --
     * in production that boundary is the request itself. Not needed by any request path.
     */
    public static function forget(): void
    {
        $request = request();

        if (! $request) {
            return;
        }

        foreach (array_keys($request->attributes->all()) as $key) {
            if (str_starts_with((string) $key, 'ss_page_directory_')) {
                $request->attributes->remove($key);
            }
        }
    }

    /** Is this page currently open to this principal? The only question callers should ask. */
    public static function permits(string $page): bool
    {
        return $page !== '' && array_key_exists($page, self::available());
    }

    /** @return array{name:string,title:string,icon:?string,route:string,href:string}|null */
    public static function resolve(string $page): ?array
    {
        return self::available()[$page] ?? null;
    }

    /**
     * The navigation `name` of the route currently being served, or null.
     *
     * This is how a visit becomes a "recent page" without ever storing a URL: the request's own
     * route name is matched against the gated directory, so what gets remembered is a known
     * identifier and a request to a page the principal may not open resolves to nothing.
     */
    public static function nameForRoute(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        foreach (self::available() as $name => $page) {
            if ($page['route'] === $routeName) {
                return $name;
            }
        }

        return null;
    }
}
