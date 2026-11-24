<?php

namespace App\Http\Middleware;

use App\Support\Modules\ModuleAvailability;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * A SWITCHED-OFF APPLICATION'S AUTHENTICATED ROUTES ARE REFUSED.
 *
 * Phase 06 item 2(B). `ModuleAvailability` has the measurement: disabling an application removed
 * nothing, because `PackageServiceProvider:47` loads every module regardless of `is_enable`. Census
 * through the production loader (`tests/Support/Security/bin/route-census.php`, 70 modules, 0 load
 * failures): **1,464 of the 3,058 routes in the table are owned by a module**, across 68 modules, and
 * every one of them stayed mounted for an application the operator had switched off.
 *
 * HOST-SIDE, BECAUSE `packages/workdo/` IS VENDORED and must not be edited. That is the same reason
 * `ModuleAdministrativeRouteGuard` exists, and this guard deliberately copies its shape: one
 * first-party middleware, the module's own code untouched.
 *
 * WHY A REQUEST GUARD AND NOT `where('is_enable', 1)` AT THE LOADER
 * =================================================================
 *
 * Filtering the loader looks like the obvious one-line fix and is the wrong one. `loadAddons()`
 * registers more than routes: the module's migrations, console commands and event listeners come
 * with it. Filtering there would mean a switched-off application could not be migrated, could not be
 * re-enabled cleanly, and would silently stop whatever scheduled work it owns. That is a far larger
 * behaviour change than the requirement asks for, and it fails by BREAKING rather than by refusing.
 *
 * WHAT THIS COVERS, AND WHAT IT DELIBERATELY DOES NOT
 * ===================================================
 *
 * Only routes that carry their own authentication: **1,012 of the 1,464**, measured. A request with
 * no authenticated principal behind it passes through untouched, and that is not laziness -- it is
 * the only part of this boundary engineering may decide alone:
 *
 *   - **87 of the anonymous module routes are payment-gateway callbacks** (the classification is
 *     `ModuleRouteManifest::ANONYMOUS_BY_DESIGN`'s own, counted). Refusing one can lose a CAPTURED
 *     PAYMENT: if an application is switched off while a transaction is in flight, the gateway's
 *     return or notify URL is how the capture gets recorded. Whether a switched-off application still
 *     records money already taken is a settlement rule and is the owner's, not engineering's.
 *   - 100 more are storefront endpoints, 6 carry their own credential, 2 are webhooks. Whether a
 *     switched-off application should still serve shoppers is a product question with the same shape.
 *
 * So this closes the capability surface a signed-in principal reaches -- every screen and every
 * writer -- and the rest is named as an open decision rather than quietly left out. The owner has the
 * counts and the question.
 *
 * REFUSAL SHAPE, COPIED FROM `ModuleAdministrativeRouteGuard::deny()` (`:365-375`) RATHER THAN
 * INVENTED: JSON 403 when the caller expects JSON, otherwise a redirect carrying an error. **NEVER a
 * 401** -- CLAUDE.md records that the shipped Flutter admin app forces a logout on any 401, so a new
 * 401 on a path a client calls is a user-visible regression.
 *
 * REGISTERED IN BOTH THE `web` AND `api` GROUPS, because 18 of the 1,464 module routes sit outside
 * `web` (LynomiaChat's 8 commerce endpoints, CartQuantityControl's 8, and one each from LandingPage
 * and ProductsPDF). A web-only registration would have missed them, and that was measured rather
 * than assumed.
 */
class DisabledModuleRouteGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return $next($request);
        }

        $module = self::moduleOf($route);

        /*
         * A host route. 1,594 of the 3,058 routes are first-party and this is the common case, so it
         * costs nothing before any database read -- including, importantly, the module enable and
         * remove screens themselves, which live in the first-party `ModuleController` and must stay
         * reachable for an application that is switched off.
         */
        if ($module === null) {
            return $next($request);
        }

        if (! self::requiresAuthentication($route)) {
            return $next($request);
        }

        if (! ModuleAvailability::isDisabled($module)) {
            return $next($request);
        }

        return $this->deny($request);
    }

    /**
     * The module that owns a route, from its action's namespace, or null for a host route.
     *
     * THE PATTERN IS THE PROJECT'S OWN, not a new convention: `route-census.php` derives the module
     * exactly this way, and the census MEASURED the mapping as exact in both directions -- every
     * module route's action matches `Workdo\<Module>\`, every such `<Module>` is a real directory
     * under `packages/workdo`, and there are no mismatches either way across all 1,464 of them. That
     * is what makes a host-side guard possible at all.
     */
    public static function moduleOf(Route $route): ?string
    {
        return preg_match('/^Workdo\\\\([A-Za-z0-9_]+)\\\\/', (string) $route->getActionName(), $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * Does the route carry its own authentication middleware?
     *
     * The predicate is `route-census.php`'s, so the control and the measurement agree on what
     * "authenticated" means. `gatherMiddleware()` is the route's OWN list plus its groups' and needs
     * no router call, so this is array work rather than a resolution pass.
     */
    public static function requiresAuthentication(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth'
                || $middleware === 'custom.auth'
                || str_starts_with($middleware, 'auth:')
                || $middleware === \Illuminate\Auth\Middleware\Authenticate::class
                || str_starts_with($middleware, \Illuminate\Auth\Middleware\Authenticate::class . ':')
                || str_ends_with($middleware, '\\CustomAuth')
                || str_ends_with($middleware, '\\EnsureCustomerAuthenticated')) {
                return true;
            }
        }

        return false;
    }

    private function deny(Request $request): Response
    {
        $message = __('This add-on is not available.');

        if ($request->expectsJson()) {
            return response()->json(['is_success' => false, 'msg' => $message, 'message' => $message], 403);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}
