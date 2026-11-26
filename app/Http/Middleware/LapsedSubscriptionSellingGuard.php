<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\Saas\SubscriptionLifecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAAS-004 — NO NEW ORDER ON A STORE WHOSE SUBSCRIPTION IS PAST THE GRACE PERIOD.
 *
 * "تقييد البيع الجديد بعد انتهاء فترة السماح، مع الحفاظ على الطلبات القائمة والتتبع والدعم" —
 * restrict NEW selling after the grace period, while PRESERVING existing orders, tracking and
 * support. Both halves are load-bearing and this middleware is only the first one: it is attached
 * to the three doors that open a new order and to nothing else.
 *
 * WHAT THE PLATFORM DID BEFORE, MEASURED. Nothing. The entire response to a lapsed subscription was
 * `assignPlan(1)` at the next login, which is a DOWNGRADE: it switches off stores past plan 1's
 * `max_stores` and leaves the rest selling. And `ActiveTheme::handle()`'s own
 * `abort('403', 'The store is not active.')` is commented out, so even a store with
 * `is_active = 0` still serves its storefront and still takes orders.
 *
 * THE DOORS, ENUMERATED FROM THE ROUTE TABLE:
 *
 *   POST {storeSlug?}/process-order    `PaymentController::processOrder`  (`payment.process`)
 *   POST api/{slug}/place-order        `Api\ApiController::place_order`
 *   POST api/{slug}/place-order-guest  `Api\ApiController::place_order`
 *
 * The first is what the shipped checkout posts to; `GAP-267` records the old
 * `{storeSlug?}/place-order` web route pointing at a method that does not exist and being
 * withdrawn. The other two are the storefront API, which no client in either repository calls.
 *
 * THE TWENTY-ODD GATEWAY CALLBACK CONTROLLERS ARE NOT GATED, DELIBERATELY. `Order::create` /
 * `new Order()` appears in twenty-odd payment controllers and each is the CONTINUATION of a
 * checkout that already passed the door above. Refusing there would reject a sale after the
 * shopper's money had moved — the worst failure mode available, and the opposite of what SAAS-004
 * asks. If the owner later wants belt-and-braces there, it is a separate decision with a refund
 * story attached.
 *
 * INERT UNTIL THE OWNER SETS THE GRACE PERIOD. `config('saas.grace_period_days')` ships NULL, so
 * `SubscriptionLifecycle::restrictsNewSelling…()` is false for every subscription however long ago
 * it lapsed, and attaching this changes nothing for any live merchant. `config/saas.php` states
 * the question in one line. `LapsedSubscriptionSellingGuardTest::test_with_no_grace_period_
 * configured_a_lapsed_store_still_sells` holds that, and is the case not to "fix" with a default.
 *
 * NEVER 401. The shipped Flutter client force-clears its session on any 401
 * (`lib/network/network.dart`'s `_handleUnauthorized`), so a subscription refusal must not be one —
 * the rule `EnsureCustomerAuthenticated` records at length. A JSON caller gets 403; a browser gets
 * a redirect back with a flash.
 *
 * THE STORE COMES FROM THE ROUTE, NEVER FROM REQUEST INPUT. CLAUDE.md records four separate
 * defects from a predicate built out of a caller-supplied `store_id`, and a caller who could name
 * the store this guard reads could name a paid-up one and sell on a lapsed storefront. The slug is
 * taken from the route parameter — the same segment that chose the handler — which is why this is a
 * middleware and not a check inside `processOrder()`.
 */
class LapsedSubscriptionSellingGuard
{
    /**
     * @param  string|null  $storeSlug  passed only by the direct-call tests; production reads the route
     */
    public function handle(Request $request, Closure $next, ?string $storeSlug = null): Response
    {
        $store = $this->resolveStore($request, $storeSlug);

        if (! SubscriptionLifecycle::restrictsNewSellingForStore($store)) {
            return $next($request);
        }

        /*
         * THE SHOPPER IS NOT THE PARTY AT FAULT and must not be told about the merchant's billing.
         * A subscription state is commercial information about a third party, so the message says
         * only that the store is not accepting orders.
         */
        $message = __('This store is not accepting new orders at the moment.');

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => false,
                'message' => $message,
                'data' => [],
            ], 403);
        }

        return redirect()->back()->with('error', $message);
    }

    /**
     * The store this request is addressed to, from its ROUTE.
     *
     * Both route shapes are handled because the three gated doors do not spell the parameter the
     * same way: `routes/customer.php` uses `{storeSlug?}` and `routes/api.php` uses `{slug}`.
     * `getStore()` would read only `storeSlug`, so the API doors would silently resolve null and
     * the guard would pass everything — a gate that is attached and does nothing.
     */
    private function resolveStore(Request $request, ?string $storeSlug): ?Store
    {
        $slug = $storeSlug
            ?? $request->route('storeSlug')
            ?? $request->route('slug');

        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        return Store::where('slug', $slug)->first();
    }
}
