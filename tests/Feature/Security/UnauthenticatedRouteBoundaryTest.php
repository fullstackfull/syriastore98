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

        // --- PENDING GATE C: customer-reachable order reads and returns. If
        // --- the owner says guest order tracking is real, these must be
        // --- re-homed into routes/customer.php behind Laravel's `signed`
        // --- middleware -- NOT left outside `auth` -- and removed from here.
        'order.return',
        'order.view',
        'order.order_view',
        'order.receipt',
        'order.return.request',

        // --- STOREFRONT CHECKOUT. OrderController serves both the admin
        // --- screens and the public storefront, so a controller-level sweep
        // --- necessarily catches these. They are the customer checkout flow on
        // --- the {storeSlug?} prefix and MUST stay public, or no one can buy
        // --- anything. Listed explicitly so that the exemption is reviewed
        // --- rather than inferred.
        'place.order',
        'applycoupon',
        'paymentlist',
        'additionalnote',
        'status.cancel',
        'order.details',
        'order.complete',

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
     */
    public function test_order_view_is_registered_exactly_once(): void
    {
        $count = 0;

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'order-view/{id}') {
                $count++;
            }
        }

        $this->assertSame(1, $count, 'order-view/{id} must be registered exactly once.');
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

        foreach (['newsletter.store', 'contacts.store'] as $name) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, "Route {$name} does not exist at all.");

            $middleware = $route->gatherMiddleware();

            $this->assertNotContains('auth', $middleware,
                "Route {$name} carries the `auth` middleware. It is a storefront form that "
                . 'anonymous shoppers post to, so authenticating it breaks the storefront. '
                . 'If it genuinely must move inside `auth`, the theme views that post to it '
                . 'have to change in the same commit.');
        }
    }
}
