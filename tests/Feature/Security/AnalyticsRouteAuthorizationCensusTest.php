<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\MerchantAnalyticsController;
use App\Http\Controllers\PlatformAnalyticsController;
use App\Http\Controllers\PlatformDocsController;
use App\Http\Controllers\PlatformSiteController;
use App\Http\Middleware\PlatformOperatorOnly;
use App\Http\Middleware\ReportPermission;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P05-18 / GAP-166 — THE PHASE 05 ANALYTICS ROUTES WERE OUTSIDE EVERY AUTHORIZATION CENSUS.
 * =============================================================================================
 *
 * The seven routes are gated, and gated correctly — measured 2026-11-22 by dumping
 * `gatherRouteMiddleware()` at runtime. What did not exist is anything that would notice if an
 * EIGHTH were added without a gate.
 *
 * `ReportAuthorizationTest::test_every_routed_report_action_is_mapped_and_gated` is the census
 * for this family, and it filters on `str_starts_with($controller, ReportController::class.'@')`.
 * The analytics screens deliberately live on their own two controllers — `ReportController`
 * cannot run under the Feature suite — so that filter places both of them OUTSIDE the scan. And
 * each of the seven attaches its gate per route, with `->middleware(...)`, rather than inheriting
 * it from a group or a constructor: the next route declared beside them gets `auth` from the
 * enclosing group and NOTHING ELSE unless its author remembers.
 *
 * What an ungated route would mean, concretely, is the reason this is filed under security:
 *
 *   - under `analytics/…`, any signed-in staff member holding ZERO permissions reads the store's
 *     figures, or creates a recurring export of them;
 *   - under `platform/analytics/…`, ANY MERCHANT reads figures across EVERY tenant. `Store` is not
 *     in `config/tenancy.php`'s `scoped_models`, so no global scope narrows that read for anybody.
 *
 * THE `platform/` PREFIX IS SHARED WITH THE PUBLIC WEBSITE, AND THAT IS THE TRAP. `platform`,
 * `platform/pricing`, `platform/docs/…` are Phase 03's official site and are deliberately open to
 * an anonymous visitor. The first remedy proposed for this gap was "assert `PlatformOperatorOnly`
 * on everything under `platform/`" — which fails on eleven public pages, and the cheapest way to
 * make it pass again is to weaken it. So the prefix is asserted as a PARTITION instead: a declared
 * public set, pinned exactly, and operator-only for everything else. A developer adding
 * `platform/tenants` next to `platform/pricing` by copying the route above it is exactly who this
 * is written for.
 *
 * Pure route-table reads: no database, no MySQL-only SQL.
 */
class AnalyticsRouteAuthorizationCensusTest extends TestCase
{
    /**
     * The permission each merchant analytics action must resolve to.
     *
     * `ReportPermission::ACTIONS` is keyed on the BARE action method, so `index` and `export` here
     * are the SAME keys `ReportController@index` and `@export` use: the analytics screen takes
     * `Manage Reports` by name collision with a different controller. That is the intended answer
     * and the route comments say so — but nothing held it. Re-pointing `ACTIONS['index']` for the
     * reports hub would have moved the analytics screen's permission silently. Declared here so
     * the collision is a decision somebody can read rather than a coincidence.
     */
    private const MERCHANT_ACTIONS = [
        'index' => 'Manage Reports',
        'export' => 'Manage Reports',
        'storeSchedule' => 'Manage Reports',
        'destroySchedule' => 'Manage Reports',
        'storeThresholds' => 'Manage Reports',
    ];

    /**
     * The controllers under `platform/` that are PUBLIC BY DESIGN: the official website and the
     * documentation centre (Phase 03, M5 and M6). Adding to this list is a statement that an
     * anonymous visitor may reach everything the controller serves.
     */
    private const PUBLIC_PLATFORM_CONTROLLERS = [
        PlatformSiteController::class,
        PlatformDocsController::class,
    ];

    /** @return list<RoutingRoute> */
    private function routesOf(string $controller): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) ($route->getAction('controller') ?? ''), $controller . '@')) {
                $found[] = $route;
            }
        }

        return $found;
    }

    /** @return list<RoutingRoute> every route at `platform` or beneath `platform/` */
    private function platformRoutes(): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // `lynomia/platform-events` contains the word and is not under the prefix.
            if ($uri === 'platform' || str_starts_with($uri, 'platform/')) {
                $found[] = $route;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function middlewareOf(RoutingRoute $route): array
    {
        return array_values(array_filter(app('router')->gatherRouteMiddleware($route), 'is_string'));
    }

    private function controllerOf(RoutingRoute $route): string
    {
        return explode('@', (string) ($route->getAction('controller') ?? ''))[0];
    }

    /** Guards every assertion below against a silently empty scan. */
    public function test_premise_the_scan_finds_the_routes_it_is_about(): void
    {
        $this->assertGreaterThanOrEqual(5, count($this->routesOf(MerchantAnalyticsController::class)),
            'The scan found fewer merchant analytics routes than exist, so every assertion in this '
            . 'file is measuring nothing.');

        $this->assertGreaterThanOrEqual(2, count($this->routesOf(PlatformAnalyticsController::class)),
            'The scan found fewer platform analytics routes than exist.');

        $this->assertGreaterThanOrEqual(10, count($this->platformRoutes()),
            'The scan found fewer routes under platform/ than exist.');
    }

    public function test_every_merchant_analytics_route_carries_the_report_gate(): void
    {
        foreach ($this->routesOf(MerchantAnalyticsController::class) as $route) {
            $middleware = $this->middlewareOf($route);

            $this->assertContains(ReportPermission::class, $middleware,
                $route->uri() . ' reaches the merchant analytics controller without the report '
                . 'gate, so any signed-in staff member holding no permission at all can use it.');

            $this->assertContains(Authenticate::class, $middleware,
                $route->uri() . ' is outside the authenticated group.');
        }
    }

    public function test_every_merchant_analytics_action_resolves_to_its_declared_permission(): void
    {
        $routed = [];

        foreach ($this->routesOf(MerchantAnalyticsController::class) as $route) {
            $routed[$route->getActionMethod()] = true;
        }

        $routed = array_keys($routed);
        sort($routed);

        $declared = array_keys(self::MERCHANT_ACTIONS);
        sort($declared);

        $this->assertSame($declared, $routed,
            'The routed merchant analytics actions are not the ones this census declares. A new '
            . 'action must be added here WITH the permission it is meant to require -- the report '
            . 'gate falls back to Manage Reports for a name it does not know, which admits by the '
            . 'wrong permission rather than refusing.');

        foreach (self::MERCHANT_ACTIONS as $action => $permission) {
            $this->assertArrayHasKey($action, ReportPermission::ACTIONS,
                "The analytics action '{$action}' has no explicit entry in the report gate's map.");

            $this->assertSame($permission, ReportPermission::ACTIONS[$action],
                "The analytics action '{$action}' no longer resolves to '{$permission}'. The map "
                . 'is keyed on the bare method name, so this moves whenever the same-named '
                . 'ReportController action is re-pointed.');
        }
    }

    /**
     * `PlatformOperatorOnly` AND NOT the report gate. `ReportPermission` admits three principals in
     * order — operator, tenant OWNER, then the holder of `Manage Reports` — and two of those are
     * merchants. On a handler that reads every store, admitting either is a cross-tenant
     * disclosure; and a merchant creates their own roles, so a permission name is substantially
     * self-assignable. The report gate being PRESENT would not by itself be a defect, but it must
     * never be the only thing standing there.
     */
    public function test_every_platform_analytics_route_is_operator_only(): void
    {
        foreach ($this->routesOf(PlatformAnalyticsController::class) as $route) {
            $middleware = $this->middlewareOf($route);

            $this->assertContains(PlatformOperatorOnly::class, $middleware,
                $route->uri() . ' serves figures across every tenant and does not carry the '
                . 'platform-operator gate, so a merchant can read other merchants\' numbers.');

            $this->assertContains(Authenticate::class, $middleware,
                $route->uri() . ' is outside the authenticated group, so an anonymous caller is '
                . 'answered by the operator check instead of being sent to sign in.');
        }
    }

    /**
     * THE PREFIX IS A PARTITION: PUBLIC BY DECLARATION, OPERATOR-ONLY OTHERWISE.
     *
     * Anything under `platform/` that is not served by one of the declared public controllers must
     * carry the operator gate — whatever controller it is on, including one that does not exist
     * yet. That is the half the per-controller assertions above cannot reach.
     */
    public function test_everything_under_the_platform_prefix_is_public_by_declaration_or_operator_only(): void
    {
        foreach ($this->platformRoutes() as $route) {
            if (in_array($this->controllerOf($route), self::PUBLIC_PLATFORM_CONTROLLERS, true)) {
                continue;
            }

            $this->assertContains(PlatformOperatorOnly::class, $this->middlewareOf($route), sprintf(
                '%s is under the platform/ prefix, is not served by a declared public controller '
                . '(%s), and does not carry the platform-operator gate. The prefix is shared with '
                . 'the public website, so a route copied from one of those pages is open to '
                . 'everyone who can sign in -- or to everyone.',
                $route->uri(),
                $this->controllerOf($route) ?: 'a closure'
            ));
        }
    }

    /**
     * THE PUBLIC SET IS PINNED IN BOTH DIRECTIONS. A controller listed above that no longer serves
     * anything under the prefix is a stale exemption waiting to be reused by something else of the
     * same name; and the declared set is what the partition's exemption rests on, so it should be
     * exactly the controllers that are really there.
     */
    public function test_the_declared_public_controllers_are_exactly_the_ungated_ones(): void
    {
        $ungated = [];

        foreach ($this->platformRoutes() as $route) {
            if (! in_array(PlatformOperatorOnly::class, $this->middlewareOf($route), true)) {
                $ungated[$this->controllerOf($route)] = true;
            }
        }

        $ungated = array_keys($ungated);
        sort($ungated);

        $declared = self::PUBLIC_PLATFORM_CONTROLLERS;
        sort($declared);

        $this->assertSame($declared, $ungated,
            'The controllers serving routes under platform/ WITHOUT the operator gate are not the '
            . 'declared public set. Either a public page moved, or something that is not the '
            . 'public website is reachable without the gate.');
    }

    /**
     * THE PUBLIC WEBSITE MUST STAY PUBLIC. The mirror of everything above: the same edit that
     * "fixes" a failing partition by gating the whole prefix would put the official site and its
     * documentation behind a sign-in. Stated as an assertion so that remedy fails too.
     */
    public function test_the_public_website_is_not_behind_a_sign_in(): void
    {
        foreach ($this->platformRoutes() as $route) {
            if (! in_array($this->controllerOf($route), self::PUBLIC_PLATFORM_CONTROLLERS, true)) {
                continue;
            }

            $middleware = $this->middlewareOf($route);

            $this->assertNotContains(Authenticate::class, $middleware,
                $route->uri() . ' is a public page of the official website and now requires a sign-in.');

            $this->assertNotContains(PlatformOperatorOnly::class, $middleware,
                $route->uri() . ' is a public page of the official website and is now operator-only.');
        }
    }
}
