<?php

namespace App\Http\Middleware;

use App\Services\MerchantPageDirectory;
use App\Services\RecentPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P2-06-7 — notes which merchant page was just visited, as a navigation `name`.
 *
 * WHAT IT RECORDS IS NOT THE URL, and that is the whole point. The owner's condition is "احفظ
 * معرّفات صفحات أو Routes معروفة، لا روابط عشوائية أو Query Strings قد تحتوي بيانات عميل", so this
 * takes the request's ROUTE NAME, maps it through the gated directory to a known navigation name,
 * and stores that. `/order?customer=jane@example.com` and `/order` both record `order`; a path
 * with no navigation entry records nothing at all.
 *
 * NARROW ON PURPOSE -- it runs on every web request, so each condition below is there to keep it
 * off the paths where it would be wrong or wasteful:
 *
 *   - GET only: a POST is an action, not a place, and a redirect-after-post would otherwise
 *     record the action's own route rather than the page the merchant lands on.
 *   - successful only: a 403 or a 404 is not a visit, and recording one would offer a shortcut
 *     straight back to a failure.
 *   - not AJAX/JSON: the table reloads, the search endpoint and the notification poll are all
 *     GETs against named routes, and none of them is a page the merchant navigated to.
 *   - authenticated only, implicitly: `MerchantPageDirectory::available()` returns `[]` with no
 *     principal, so a guest records nothing.
 *
 * COST, stated honestly: resolving the directory fires the navigation menu event, which the
 * sidebar on these same pages already fires once per render. This adds a second one on HTML page
 * loads and none at all on the AJAX traffic, which is the bulk of the requests.
 */
class RecordRecentMerchantPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || ! $response->isSuccessful()) {
            return $response;
        }

        if ($request->ajax() || $request->wantsJson() || $request->isJson()) {
            return $response;
        }

        $name = MerchantPageDirectory::nameForRoute($request->route()?->getName());

        if ($name !== null) {
            RecentPages::remember($name);
        }

        return $response;
    }
}
