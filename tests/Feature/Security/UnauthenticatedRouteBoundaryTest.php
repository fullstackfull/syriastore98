<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SEC-F2 / NEW-public-mutating-routes -- the route-table test.
 *
 * This test is worth more than any individual authorization test, because it
 * makes the whole class of regression IMPOSSIBLE rather than merely caught:
 * it iterates the real route table and asserts that every route handled by an
 * administrative controller carries `auth`, measured against an EXPLICIT
 * allowlist. A future route added outside the group therefore fails by
 * default.
 *
 * SEC-F2 exists because a `});` moved. This is the regression check for the
 * brace itself.
 */
class UnauthenticatedRouteBoundaryTest extends TestCase
{
    /**
     * Controllers whose every route must sit behind `auth`.
     */
    private const ADMINISTRATIVE_CONTROLLERS = [
        \App\Http\Controllers\OrderController::class,
        \App\Http\Controllers\OrderRefundController::class,
        \App\Http\Controllers\AppSettingController::class,
        \App\Http\Controllers\NewsletterController::class,
        \App\Http\Controllers\OrderNoteController::class,
        \App\Http\Controllers\StoreFinanceController::class,
        \App\Http\Controllers\CurrencyRateController::class,
        \App\Http\Controllers\AddonController::class,
        \App\Http\Controllers\ModuleController::class,
        \App\Http\Controllers\RoleController::class,
        \App\Http\Controllers\UserController::class,
    ];

    /**
     * THE EXPLICIT ALLOWLIST. Route NAMES that are intentionally reachable
     * without authentication. Anything else handled by the controllers above
     * fails this test.
     *
     * The five `order.*` reads are here under GATE C, pending the owner's
     * answer on whether guest order tracking is a real customer flow. If it is,
     * they must be re-homed into routes/customer.php behind Laravel's `signed`
     * middleware -- NOT left outside `auth` -- and removed from this list. They
     * are listed explicitly, with this reason, so the exception is visible in
     * review rather than implicit in a brace position.
     */
    private const PUBLIC_ROUTE_ALLOWLIST = [
        // --- Genuinely public (admin side).
        'contacts.store',
        // The storefront footer subscribe form, anonymous by design. This entry
        // CORRECTS this allowlist rather than widening it: the first version of
        // this test omitted newsletter.store while routes/web.php had swept that
        // POST inside the `auth` group, so the suite passed while newsletter
        // signup was broken on every storefront. Evidence it must be public:
        // all three shipped themes post to it with no session --
        // stylique/.../footer.blade.php:52, greentic/.../subscribe.blade.php:10,
        // techzonix/.../subscribe.blade.php:2.
        'newsletter.store',
        'change.languagestore',
        'payu.pay',
        'format.price',

        // --- GATE C IS CLOSED. order.return, order.view, order.order_view,
        // --- order.receipt and order.return.request were here, and all five
        // --- have been REMOVED from this allowlist rather than re-justified:
        // --- they now sit inside the administrative `auth` group in
        // --- routes/web.php, so the sweep below checks them instead of
        // --- skipping them. The evidence is recorded at the routes and pinned
        // --- by test_the_gate_c_order_routes_are_behind_auth() below.
        // ---
        // --- The decisive facts were (a) not one of the three shipped themes
        // --- references any of the five, (b) every caller is an admin view or
        // --- DataTable, and (c) the customer order flow is a separate set of
        // --- routes in routes/customer.php served by AccountProfileController.
        // --- Two of the five were anonymous arbitrary WRITES that incremented
        // --- any store's product stock, not reads.

        // --- STOREFRONT CHECKOUT. OrderController serves both the admin
        // --- screens and the public storefront, so a controller-level sweep
        // --- necessarily catches these. They are the customer checkout flow on
        // --- the {storeSlug?} prefix in routes/customer.php and MUST stay
        // --- public, or no one can buy anything. Listed explicitly so that the
        // --- exemption is reviewed rather than inferred.
        // ---
        // --- EACH ONE RE-EXAMINED, with the storefront caller that proves it
        // --- must stay anonymous:
        // ---   place.order     routes/customer.php:122, guest checkout submit.
        // ---   applycoupon     resources/views/front_end/jQueryRoute.blade.php
        // ---   paymentlist     resources/views/front_end/jQueryRoute.blade.php
        // ---   additionalnote  resources/views/front_end/jQueryRoute.blade.php
        // ---                   -- renders a partial only, no writes
        // ---                   (OrderController::additionalnote:696-706).
        // ---   order.details   front_end/pages/account.blade.php,
        // ---                   front_end/pages/order_list_filter.blade.php
        // ---   order.complete  the post-payment landing page.
        // --- Asserted positively by
        // --- test_the_storefront_public_endpoints_are_reachable_without_a_session.
        'place.order',
        'applycoupon',
        'paymentlist',
        'additionalnote',
        'order.details',
        'order.complete',

        // --- status.cancel STAYS PUBLIC, AND IS A REPORTED OPEN FINDING.
        // ---
        // --- It is a genuine storefront route: three shipped customer views
        // --- post to it -- front_end/sections/order_detail.blade.php,
        // --- front_end/pages/order-detail.blade.php and
        // --- front_end/pages/auction_order_detail.blade.php -- and guest
        // --- orders exist (orders.is_guest), so a guest must be able to cancel
        // --- from the link they were given. Sweeping it behind `auth:customers`
        // --- would break guest cancellation on every store.
        // ---
        // --- BUT IT HAS NO OWNERSHIP CHECK AT ALL.
        // --- OrderController::status_cancel:604-612 takes order_id straight off
        // --- the request, calls Order::order_status_change() on it, and then
        // --- walks Order::order_detail($request->order_id) adding every line
        // --- item's qty back onto Product::product_stock / ProductVariant::stock
        // --- (:613-626). No auth, no customer binding, no tenant predicate, no
        // --- signature. An anonymous caller who guesses a numeric order id can
        // --- cancel any order on the platform and inflate any merchant's stock.
        // ---
        // --- NOT FIXED HERE, and deliberately so: the repair is an ownership
        // --- predicate inside OrderController::status_cancel, and
        // --- app/Http/Controllers/OrderController.php belongs to the commerce
        // --- workstream. Recorded as a cross-agent dependency with this
        // --- evidence rather than papered over at the route, because the two
        // --- route-level options -- `auth:customers` (breaks guest
        // --- cancellation) and `signed` (requires re-issuing every order link)
        // --- are both product decisions.
        'status.cancel',

        // --- NEW-addon-session-routes-dead: these two sit outside `auth` in
        // --- the "module page before login" block of routes/web.php, and they
        // --- reference ModuleController methods that DO NOT EXIST
        // --- (grep -rn AddOnSession app/ returns nothing), so no handler can
        // --- run and they can only produce a 500. Not exploitable, but dead
        // --- unauthenticated scaffolding. Reported as a NEW finding for the
        // --- owner to decide between deleting and implementing; deliberately
        // --- NOT deleted here, because deciding that is not this agent's call.
        'add-on.session.create',
        'add-on.session.save',
    ];

    public function test_no_administrative_route_is_reachable_without_auth(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('controller');

            if (!is_string($action) || !str_contains($action, '@')) {
                continue;
            }

            [$controller] = explode('@', $action, 2);

            if (!in_array($controller, self::ADMINISTRATIVE_CONTROLLERS, true)) {
                continue;
            }

            $name = $route->getName();

            if ($name !== null && in_array($name, self::PUBLIC_ROUTE_ALLOWLIST, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            // `custom.auth` is the API's authentication gate (routes/api.php)
            // and, since the CustomAuth repair, a real one: it verifies the
            // token secret with hash_equals and rejects header-less requests.
            // So either gate satisfies this assertion.
            $authenticated = in_array('auth', $middleware, true)
                || in_array('custom.auth', $middleware, true);

            if (!$authenticated) {
                $offenders[] = sprintf(
                    '%s %s (name=%s, controller=%s, middleware=[%s])',
                    implode('|', $route->methods()),
                    $route->uri(),
                    $name ?? '(unnamed)',
                    $action,
                    implode(', ', array_map('strval', $middleware))
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These administrative routes are reachable without authentication:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * The allowlist must not rot into a dumping ground: every name in it has to
     * still exist as a real route.
     */
    public function test_every_allowlisted_route_name_still_exists(): void
    {
        $missing = [];

        foreach (self::PUBLIC_ROUTE_ALLOWLIST as $name) {
            if (!Route::has($name)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'Stale entries in PUBLIC_ROUTE_ALLOWLIST: ' . implode(', ', $missing));
    }

    /**
     * The duplicate `order-view/{id}` registration was deleted. Two identical
     * declarations of one URI is how the boundary defect hid in review.
     *
     * THIS TEST WAS ASSERTING THE SYMPTOM AWAY, and the count was never the
     * point. `order-view/{id}` was declared TWICE -- once inside the
     * administrative `auth` group (routes/web.php:941) and once in the public
     * block -- but Laravel's RouteCollection::addToCollections keys allRoutes
     * by method+domain+uri, so the second declaration REPLACED the first and
     * only one route was ever in the table. The count has always been 1. The
     * copy that survived was the UNAUTHENTICATED one.
     *
     * Measured on bd6900bc by dumping the real route table: exactly one
     * `GET|HEAD order-view/{id}`, middleware [web, themelanguage] -- no auth.
     *
     * So the count assertion is KEPT, because two declarations of one URI is
     * still the defect worth forbidding, and the middleware assertion is ADDED,
     * because that is the property the old test was believed to be proving.
     */
    public function test_order_view_is_registered_exactly_once_and_is_the_authenticated_copy(): void
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'order-view/{id}') {
                $found[] = $route;
            }
        }

        $this->assertCount(1, $found, 'order-view/{id} must be registered exactly once.');

        $middleware = $found[0]->gatherMiddleware();

        $this->assertContains(
            'auth',
            $middleware,
            'The surviving order-view/{id} route must be the authenticated copy. '
            . 'A duplicate declaration in a public block REPLACES the one inside the '
            . '`auth` group, so a second declaration silently removes the gate: '
            . 'middleware was [' . implode(', ', array_map('strval', $middleware)) . '].'
        );
    }

    /**
     * GATE C, pinned. The five routes that were allowlisted "PENDING GATE C"
     * must each carry `auth`.
     *
     * Two of them were anonymous arbitrary WRITES, not reads:
     * OrderController::order_return_request:451 did `Order::find($request->id)`
     * with no auth, permission or tenant predicate and then incremented
     * Product::product_stock on any store's products; order.return reached
     * Order::product_return (app/Models/Order.php:637) and did the same.
     *
     * Kept as its own test, separate from the controller sweep above, so that
     * re-adding any of these names to PUBLIC_ROUTE_ALLOWLIST cannot make the
     * check pass again -- the sweep skips allowlisted names, this does not.
     */
    public function test_the_gate_c_order_routes_are_behind_auth(): void
    {
        foreach ([
            'order.view',
            'order.order_view',
            'order.receipt',
            'order.return',
            'order.return.request',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains(
                'auth',
                $middleware,
                "Route {$name} must require auth: it is an administrative order "
                . 'screen or a merchant return-approval action, referenced by no '
                . 'storefront theme. Middleware was ['
                . implode(', ', array_map('strval', $middleware)) . '].'
            );
        }
    }

    /**
     * And the converse, so closing Gate C cannot be mistaken for closing the
     * customer order flow: the customer-facing order routes in
     * routes/customer.php are a DIFFERENT set and are untouched.
     */
    public function test_the_customer_order_flow_routes_still_exist(): void
    {
        foreach ([
            'order.track',
            'track.order',
            'customer.order',
            'order.return.list',
            'order.refund',
            'order.refund.request',
        ] as $name) {
            $this->assertTrue(
                Route::has($name),
                "Route {$name} is missing. The customer-facing order flow lives in "
                . 'routes/customer.php and must survive the Gate C closure.'
            );
        }
    }

    /**
     * NEW-zid-debug-endpoint: the unauthenticated debug route that decrypted a
     * hardcoded merchant's third-party credentials must be gone from the route
     * table entirely, not merely guarded.
     */
    public function test_zid_statuses_test_route_no_longer_exists(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString(
                'zid-statuses-test',
                $route->uri(),
                'The zid-statuses-test debug route must be deleted.'
            );
        }
    }

    /**
     * SEC-F5: installation routes must carry the platform-operator Gate.
     */
    public function test_addon_and_module_install_routes_require_the_platform_gate(): void
    {
        foreach (['addon.theme', 'theme.enable', 'module.install'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('auth', $middleware, "{$name} must require auth.");
            $this->assertContains(
                'can:manage-platform-addons',
                $middleware,
                "{$name} must carry the manage-platform-addons Gate."
            );
        }
    }

    /**
     * NEW-finance-authorization: every finance mutator must carry auth AND a
     * permission check. The READ was the only thing gated before.
     */
    public function test_every_finance_route_requires_auth_and_a_permission(): void
    {
        $financeRoutes = [
            'finance.index',
            'finance.add.balance',
            'finance.deduct.balance',
            'finance.withdrawal.request',
            'finance.withdrawals',
            'finance.withdrawals.approve',
            'finance.withdrawals.reject',
            'finance.withdrawals.pay',
            'finance.order-financial.status',
        ];

        foreach ($financeRoutes as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('auth', $middleware, "{$name} must require auth.");

            // These used to assert Laratrust's `permission:` prefix. They now
            // assert `finance.permission:`, and the change is the fix, not an
            // accommodation of it: `permission:` resolves purely through the
            // role/permission pivot tables with no super-admin bypass, and none
            // of the six finance permission names is created by
            // Utility::addNewData(), so every one of these routes was 403 for
            // every user including the platform operator. See
            // FinancePermissionTest and App\Http\Middleware\FinancePermission.
            $hasPermission = false;
            foreach ($middleware as $m) {
                if (is_string($m) && str_starts_with($m, 'finance.permission:')) {
                    $hasPermission = true;
                    break;
                }
            }

            $this->assertTrue(
                $hasPermission,
                "{$name} must carry a finance.permission: middleware."
            );

            // And must NOT have regressed to the Laratrust alias.
            foreach ($middleware as $m) {
                if (is_string($m)) {
                    $this->assertFalse(
                        str_starts_with($m, 'permission:'),
                        "{$name} uses Laratrust's permission:, which 403s a super "
                        . 'admin while the row is unseeded.'
                    );
                }
            }
        }
    }

    /**
     * THE CONVERSE OF THIS SUITE'S MAIN ASSERTION, and the reason it is needed.
     *
     * The sweep above SKIPS every allowlisted name, so adding a name to the
     * allowlist proves nothing about whether that route is actually reachable
     * without a session. That gap is exactly how the storefront newsletter form
     * broke: routes/web.php had swept the `newsletter.store` POST inside the
     * ['auth','xss','setlocate','verified'] group, the allowlist did not mention
     * it, and the suite stayed green while footer signup 404'd into a login
     * redirect on every store.
     *
     * So the storefront endpoints that MUST be anonymous are asserted positively,
     * against the real router.
     */
    public function test_the_storefront_public_endpoints_are_reachable_without_a_session(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([
            'newsletter.store',
            'contacts.store',
            // The checkout flow. Closing Gate C moved five ADMIN order routes
            // behind `auth`; these are the customer-facing ones and must not
            // follow them, or nobody can buy anything.
            'place.order',
            'applycoupon',
            'paymentlist',
            'additionalnote',
            'order.details',
            'order.complete',
            'status.cancel',
            // The storefront language switcher, linked from all three themes --
            // techzonix/.../announcement.blade.php,
            // greentic/.../announcement.blade.php,
            // stylique/.../header.blade.php.
            'change.languagestore',
            'format.price',
        ] as $name) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, "Route {$name} does not exist at all.");

            $middleware = $route->gatherMiddleware();

            $this->assertNotContains('auth', $middleware,
                "Route {$name} carries the `auth` middleware. It is a storefront endpoint "
                . 'anonymous shoppers reach, so authenticating it breaks the storefront. '
                . 'If it genuinely must move inside `auth`, the theme views that reach it '
                . 'have to change in the same commit.');
        }
    }

    /**
     * NEW-addon-session-routes-dead, kept as a TRIPWIRE rather than a claim.
     *
     * `add-on.session.create` and `add-on.session.save` sit outside `auth` in
     * the "module page before login" block of routes/web.php:408-409 and point
     * at ModuleController::AddOnSessioncreate / AddOnSessionSave, which DO NOT
     * EXIST -- `grep -rn "AddOnSession" app/` returns nothing, so no handler can
     * run and the routes can only produce a 500. Not exploitable, but dead
     * unauthenticated scaffolding, and reported for the owner to decide between
     * deleting and implementing.
     *
     * This test fails the moment somebody implements either method, which forces
     * the authentication question to be answered in the same change instead of
     * shipping a live unauthenticated module endpoint. It is not asserting that
     * dead code is desirable.
     */
    public function test_the_dead_addon_session_handlers_have_not_been_implemented_while_unauthenticated(): void
    {
        foreach ([
            'add-on.session.create' => 'AddOnSessioncreate',
            'add-on.session.save'   => 'AddOnSessionSave',
        ] as $name => $method) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            if (in_array('auth', $middleware, true)) {
                // Somebody gated them. Then there is nothing left to warn about.
                continue;
            }

            $this->assertFalse(
                method_exists(\App\Http\Controllers\ModuleController::class, $method),
                "ModuleController::{$method} now exists, so route {$name} is a LIVE "
                . 'unauthenticated module endpoint. Either put it behind `auth` or '
                . 'delete the route; it must not become reachable by implementation '
                . 'alone.'
            );
        }
    }
}
