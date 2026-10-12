<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The storefront's customer-session gate.
 *
 * WHY THIS EXISTS RATHER THAN `auth:customers`.
 *
 * routes/customer.php serves the public storefront, so a denial here has to do
 * two things Laravel's own Authenticate middleware does not do together:
 *
 *  1. ANSWER AJAX WITH 403, NEVER 401. Most of the newly gated endpoints are
 *     jQuery calls that expect JSON (remove-address, delete-wishlist,
 *     reward-list, order-refund, ...). Illuminate\Auth\Middleware\Authenticate
 *     answers an expectsJson() request with 401. A 401 is the one status this
 *     codebase must not use for an authorization denial: repo-B
 *     lib/network/network.dart maps ANY 401 into _handleUnauthorized(), which
 *     clears preferences and forces a re-login. Keeping the rule uniform across
 *     web and API means a future shared client cannot be logged out by a
 *     storefront denial.
 *
 *  2. REDIRECT TO THE RIGHT LOGIN. Laravel's Authenticate redirects to
 *     route('login'), which in this application is the MERCHANT login
 *     (routes/auth.php). A shopper who clicks "my orders" while logged out must
 *     land on their store's own customer login -- `{storeSlug?}/login`,
 *     name customer.login (routes/customer.php:79). The slug is taken from the
 *     route itself so the shopper stays inside the store they were browsing.
 *
 * The guard is read, never written: this middleware NEVER logs anybody in or
 * out and never touches the session beyond the intended-URL hint, so applying
 * it cannot change the behaviour of an already-authenticated shopper.
 *
 * SCOPE DISCIPLINE. This gates ONLY routes that have a concrete defect behind
 * them -- a destructive write with no ownership predicate, an IDOR read, or a
 * guaranteed null dereference for an anonymous caller. The genuinely public
 * storefront endpoints (add.newsletter, states.list, city.list, the whole
 * checkout flow) are deliberately NOT gated, and are asserted POSITIVELY in
 * tests/Feature/Security/CustomerAccountBoundaryTest.php, because this project
 * has already shipped two "fixes" that broke the storefront while the test
 * board stayed green.
 */
class EnsureCustomerAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth('customers')->check()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => false,
                'message' => __('You must be logged in to do that.'),
                'data' => [],
            ], 403);
        }

        $storeSlug = $request->route('storeSlug') ?: $request->route('slug');

        $target = $storeSlug
            ? route('customer.login', ['storeSlug' => $storeSlug])
            : route('customer.login');

        return redirect()
            ->guest($target)
            ->with('error', __('Please log in to continue.'));
    }
}
