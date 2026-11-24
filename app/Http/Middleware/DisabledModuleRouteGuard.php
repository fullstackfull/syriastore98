<?php

namespace App\Http\Middleware;

use App\Support\ModuleRouteManifest;
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

        if (! self::requiresAuthentication($route, $request->method())) {
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
     * THE PATTERN IS THE PROJECT'S OWN: `route-census.php:75` derives the module with this exact
     * regex, so the control and the measurement agree on what "module-owned" means.
     *
     * THE CLAIM THIS DOCBLOCK USED TO MAKE WAS CIRCULAR, and independent review said so. It read
     * "the census MEASURED the mapping as exact in both directions ... no mismatches either way
     * across all 1,464 of them." But the census DEFINES module-ownership with the same regex, so it
     * cannot discover a route the regex misses -- such a route is simply counted as first-party.
     * What is actually measured, and does hold, is the forward direction: every `Workdo\<Module>\`
     * the regex captures is a real directory under `packages/workdo`, and every module's
     * `composer.json` PSR-4 key is `Workdo\<Directory>\` for all 70, `Signinwithgithub` included.
     *
     * TWO CLASSES ARE THEREFORE UNATTRIBUTABLE, and both fail OPEN -- they read as host routes:
     *
     *  1. A CLOSURE ACTION. `Route::getActionName()` returns the literal `'Closure'` when the action
     *     has no controller. Measured: 11 of the 137 module route files declare one. Most are the
     *     `auth:api` probe each module's `api.php` ships, and there is no `api` guard in this
     *     application (CLAUDE.md), so they throw for everybody -- latent rather than live.
     *  2. A MODULE-DECLARED ROUTE POINTING AT A FIRST-PARTY CONTROLLER. Measured: exactly two files
     *     import one, `AutomaticOrderPrinting/src/routes/web.php` (live) and
     *     `CheckoutAttachment/src/routes/web.php` (its route is commented out). The live one
     *     re-declares `shippinglabel/pdf/{id}` and takes the slot from the host's AUTHENTICATED
     *     declaration -- the case `bootstrap/app.php` already records -- so switching that module
     *     off neither un-mounts the route nor restores the host's version. Its ownership half is
     *     covered by `OrderDocumentAccessGuard`; its availability half is not.
     *
     * A REGEX CHANGE CANNOT FIX EITHER, which is why they are recorded rather than patched: the
     * declaring FILE is not available at request time, only the action. Closing them needs the
     * route-to-module mapping to come from the manifest rather than from the action name, which is
     * the same change the storefront widening needs and belongs with it.
     */
    public static function moduleOf(Route $route): ?string
    {
        return preg_match('/^Workdo\\\\([A-Za-z0-9_]+)\\\\/', (string) $route->getActionName(), $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * Is this route authenticated -- BY ANY OF THE THREE MECHANISMS THIS APPLICATION USES?
     *
     * CORRECTED AFTER INDEPENDENT REVIEW, AND THE CORRECTION WAS THE WHOLE POINT OF THE GUARD.
     * This read `gatherMiddleware()` alone, which is where `route-census.php` looks -- so the
     * control and the measurement agreed with each other and both were wrong, because this codebase
     * authenticates module routes in a way that puts nothing in the route's middleware array.
     *
     * MEASURED, on the real router, replaying PartialPayments' own two declarations in their own
     * order (`packages/workdo/PartialPayments/src/routes/web.php`, the resource at line 18 inside
     * `['auth','web','PlanModuleCheck:PartialPayments']` and THE SAME RESOURCE AGAIN at line 30
     * inside `['web']`):
     *
     *   GET partialpayments               middleware=[web]   gatherMiddleware says auth: NO
     *                                                        manifest rule:            'auth'
     *   POST payment-status-change/{id}   middleware=[web]   gatherMiddleware says auth: NO
     *                                                        manifest rule:            'auth'
     *
     * CLAUDE.md's rule is why: "Later route declarations REPLACE earlier ones for the same
     * method+URI, silently dropping the earlier middleware." So the second declaration wins and
     * `auth` is gone from the array. Those routes are authenticated -- by
     * `ModuleAdministrativeRouteGuard`, which is group middleware that resolves `auth('web')->user()`
     * for any `METHOD uri` carrying a `ModuleRouteManifest::ADMINISTRATIVE` rule (340 keys). That is
     * authentication; it is simply not route middleware, and the manifest exists precisely BECAUSE
     * these routes had none of their own.
     *
     * Without the second limb below, a merchant with PartialPayments switched off still reached the
     * whole `partialpayments` admin resource and `POST payment-status-change/{id}` -- which is
     * P1-SEC-04's own named effect. The guard's docblock claimed it closed "every screen and every
     * writer"; it closed the ones that say so in their own middleware.
     *
     * THE METHOD IS THE REQUEST'S, NOT `$route->methods()[0]`, for the reason
     * `ModuleAdministrativeRouteGuard` uses the request's: `Route::any` carries seven methods and
     * the manifest keys each separately, so the first one would answer for a verb the caller did not
     * send.
     *
     * `gatherMiddleware()` is the route's own list plus its groups', needs no router call, and DOES
     * include a controller's `__construct` middleware -- `Router::gatherRouteMiddleware()` memoises
     * `computedMiddleware` before the pipeline runs, so this is array work either way.
     */
    public static function requiresAuthentication(Route $route, ?string $method = null): bool
    {
        if (ModuleRouteManifest::administrativeRule($method ?? $route->methods()[0], $route->uri()) !== null) {
            return true;
        }

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
