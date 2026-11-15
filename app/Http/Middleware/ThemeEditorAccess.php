<?php

namespace App\Http\Middleware;

use App\Support\Theme\ThemeEditorGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHASE 03 M4-2 — ONE AUTHORIZATION BOUNDARY FOR EVERY THEME-EDITING ROUTE, BOTH VERBS.
 * =================================================================================================
 *
 * THE INVERSION THIS CLOSES, measured before it was fixed. The four GET theme routes each opened
 * with `isAbleTo('Manage Themes')`, while the three POST routes
 * (`theme.customize.update`, `theme.customize.update.order`, `theme-preview.make-active`) sat in
 * `['auth','xss','setlocate','verified']` with NO permission middleware at all and were entitled by
 * store OWNERSHIP alone. So a `type`-`staff` user holding no role and no permission could not open
 * the editor and COULD drive every write in it by direct POST -- pinned at
 * `ThemeCustomizeWebRoutesTest.php:177-186`, where exactly that principal's value lands.
 *
 * The owner's decision of 2026-11-15 closes it in the safe direction: the same boundary on all
 * seven routes, so the two verbs can no longer disagree. Zero-permission staff may no longer modify
 * a live storefront.
 *
 * WHO PASSES -- `ThemeEditorGate::allowsManagement()` owns the rule, and this class owns only the
 * HTTP shape of the refusal:
 *
 *     the platform operator, naming a store explicitly (never store 1 by fallback)
 *     the verified tenant owner    (stores.created_by === this user's own id)
 *     a SAME-TENANT user holding `Manage Themes`
 *
 * WHY THE PERMISSION IS NOT TESTED HERE. `Manage Themes` is granted to the shared GLOBAL `admin`
 * role, so it is not tenant-scoped and means nothing on its own. The gate reaches it only after
 * `resolveStore()` has applied the tenant predicate, which is what keeps a global permission from
 * authorising another tenant. Re-testing it in the middleware would invite exactly that mistake.
 *
 * WHY THE STORE IS NOT TAKEN FROM THE REQUEST. There is no `{store}` route parameter on any theme
 * route, and a tenant predicate built from caller-supplied input is not a tenant check -- it only
 * compares the caller's own two values, which this codebase records as the source of four separate
 * defects. The store comes from `storeIdForSelfService()`, which refuses rather than guesses.
 */
class ThemeEditorAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        $gate = new ThemeEditorGate();
        $storeId = $gate->storeIdForSelfService($user);

        if ($storeId === null || ! $gate->allowsManagement($storeId, $user)) {
            Log::warning('Theme editor access refused.', [
                'route'   => optional($request->route())->getName(),
                'method'  => $request->method(),
                'user_id' => $user->id ?? null,
                'reason'  => $gate->reason(),
            ]);

            /*
             * A REFUSAL THE SHIPPED CLIENTS ALREADY UNDERSTAND. These are session routes in the
             * merchant admin, not API routes: every existing refusal on this surface is
             * `redirect()->back()->with('error', ...)`, and `ApiResponser::error()` is not in play.
             * A 403 here would be a new status on a path the admin UI already handles as a toast.
             */
            if ($request->expectsJson()) {
                return response()->json(['status' => 0, 'message' => __('Permission denied.')], 403);
            }

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return $next($request);
    }
}
