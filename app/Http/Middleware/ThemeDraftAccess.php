<?php

namespace App\Http\Middleware;

use App\Support\Theme\ThemeEditorGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHASE 03 M4-7 — THE DRAFT BOUNDARY, which is deliberately WIDER than the publish boundary.
 * =================================================================================================
 *
 * The owner's decision of 2026-11-15 restricts publishing and rollback to the verified tenant
 * owner, a same-tenant holder of `Manage Themes`, or the operator naming a store -- and separately
 * requires that tenant-authorised staff be able to PREPARE and PREVIEW drafts without being able to
 * publish them, "through deliberately separated draft/preview authorization, not by reopening
 * unrestricted live-write endpoints".
 *
 * This is that separation, and it exists because putting the draft route inside `theme.editor`
 * made it unreachable for exactly the principal it was for: MEASURED -- staff holding no permission
 * were refused by the middleware before `saveDraft()`'s own `allows()` check ever ran.
 *
 * SO THE TWO BOUNDARIES ARE:
 *
 *     theme.editor         allowsManagement()   live writes, publish, rollback   (narrow)
 *     theme.draft          allows()             save/update/preview a draft      (wider)
 *
 * NO NEW PERMISSION IS INTRODUCED, which is the owner's standing constraint and also the safe
 * choice: a name that is gated on but never seeded is a permanent 403 for everyone including the
 * operator, and this codebase has been bitten by that once already. Tenant membership is the
 * capability; the gate's `allows()` is what decides it.
 *
 * A DRAFT CANNOT REACH A SHOPPER whatever this admits: drafts live in `theme_versions`, which no
 * storefront path reads, and the published projection is written only by the management-gated
 * paths.
 */
class ThemeDraftAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /* The `web` guard explicitly: under a customer session the DEFAULT guard is `customers`,
         * so `Auth::user()` would return a Customer. */
        $user = Auth::guard('web')->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $gate = new ThemeEditorGate();
        $storeId = $gate->storeIdForSelfService($user);

        if ($storeId === null || ! $gate->allows($storeId, $user)) {
            Log::warning('Theme draft access refused.', [
                'route'   => optional($request->route())->getName(),
                'user_id' => $user->id ?? null,
                'reason'  => $gate->reason(),
            ]);

            if ($request->expectsJson()) {
                return response()->json(['status' => 0, 'message' => __('Permission denied.')], 403);
            }

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return $next($request);
    }
}
