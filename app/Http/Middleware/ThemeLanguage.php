<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Cookie;
use Illuminate\Support\Facades\App;

class ThemeLanguage
{
    /**
     * Resolve the locale for a STOREFRONT request.
     *
     * PHASE 04 P4-1 -- THIS USED TO ANSWER WITH ANOTHER TENANT'S LANGUAGE.
     * =============================================================================================
     *
     * The final fallback was:
     *
     *     $user = Cache::remember('admin_details', 3600, fn () => User::where('type','admin')->first());
     *     $currantLang = $user->language ?? 'en';
     *
     * THE FIRST type-`admin` USER IN THE ENTIRE SHARED DATABASE, cached globally. This is a
     * multi-tenant application: that row belongs to some other merchant, selected by nothing but
     * having the lowest id. The store actually being visited was never consulted, although
     * `stores.default_language` exists and holds exactly the answer.
     *
     * WHY NOBODY SAW IT, AND WHY A PAGE TEST CANNOT FIND IT. There are TWO locale resolvers on the
     * storefront and they disagreed. `ViewServiceProvider.php:91` composes every storefront view
     * and already does the right thing:
     *
     *     $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
     *
     * A PAGE request composes a view, so the composer overwrote this middleware's wrong answer and
     * the page came out correct. A JSON request returns without composing those views, so the
     * composer never ran and this middleware's answer stood.
     *
     * MEASURED on a running server, English storefront `damascus-textiles` whose
     * `stores.default_language` is 'en', anonymous session, no LANGUAGE cookie:
     *
     *     the page               <html lang="en" dir="ltr">, English chrome and labels
     *     POST product_cart      "من فضلك اختر نوعاً من المنتج"   (no variant chosen)
     *     POST product_cart      "تم إضافة المنتج بنجاح"          (success)
     *     POST wish-list-count   "قائمة أمنيات العد"
     *     POST states-list       "حدد الدولة"
     *
     * because fixture user 2 is `type=admin, language=ar`. An English-speaking shopper got Arabic
     * error and success messages on an English shop, from a merchant they have never heard of.
     *
     * THE FIX IS TO STATE THE COMPOSER'S RULE HERE TOO, not to delete the composer. Two guards
     * expressing the SAME rule is defence in depth; two guards expressing DIFFERENT rules is the
     * defect above. The order is:
     *
     *   1. the visitor's own LANGUAGE cookie -- an explicit choice outranks every default;
     *   2. a signed-in principal's own `language` -- a merchant previewing their own shop;
     *   3. THIS STORE's `default_language`, taken from the route's `{storeSlug}` via `getStore()`,
     *      or -- for a route that names the store as its FIRST path segment as `{slug}` -- via
     *      `getRouteStoreId()`. A route that uses `slug` for anything else resolves no store;
     *   4. the platform default, `config('platform.default_language')`, which itself defaults
     *      to 'en'.
     *
     * Step 4 catches a store whose `default_language` is the empty string -- which is a real state
     * in the fixture data -- and a request that carries no store at all. Neither may fall through
     * to a stranger's preference, which is what made step 4 of the old code a tenancy fault rather
     * than a cosmetic one.
     *
     * `customers` HAS NO `language` COLUMN -- measured -- so a signed-in shopper has no stored
     * preference and step 2 cannot serve them. The cookie is their only signal, which is why it
     * stays first.
     *
     * Pinned by `tests/Feature/Commerce/StorefrontLocaleFollowsTheStoreTest`, which drives a JSON
     * route on purpose: on a page GET the composer satisfies every assertion whatever this
     * middleware does, and three of that file's five cases passed against the OLD code until they
     * were retargeted.
     */
    public function handle(Request $request, Closure $next)
    {
        $currantLang = Cookie::get('LANGUAGE');

        if ($currantLang == null) {
            $user = \Auth::user();
            if ($user) {
                $currantLang = $user->language;
            }
        }

        if ($currantLang == null || $currantLang === '') {
            /* The route's own `{storeSlug}`, via `getStore()`. An earlier version of this comment
               said the same source `getStore()`, `getRouteStoreId()` and the view composer all
               use, "so the four agree on one answer". They did not: `getRouteStoreId()` also
               reads `{slug}` as a first path segment and `getStore()` did not. The fallback below
               closes that for the one route that needs it. Null for a request that carries no
               store, which then falls to the platform default. */
            $store = getStore();

            // A store named as the FIRST path segment (`{slug}/...`, `api/{slug}/...`) is not
            // `{storeSlug}`, so getStore() does not see it -- `{slug}/product-wishlist` is the
            // live case. getRouteStoreId() resolves exactly that spelling and no other: it refuses
            // `custom-msg/{slug?}` and every route that reuses `slug` for a page or an article, on
            // purpose, because a page slug that happens to equal a store's slug must not select
            // that store. Those routes fall to the platform default below, as before.
            if ($store === null && ($routeStoreId = getRouteStoreId())) {
                $store = getStoreById($routeStoreId);
            }

            $currantLang = $store->default_language ?? null;
        }

        if ($currantLang == null || $currantLang === '') {
            $currantLang = config('platform.default_language', 'en');
        }

        App::setLocale($currantLang);

        return $next($request);
    }
}
