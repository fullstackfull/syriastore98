<?php

namespace App\Http\Controllers;

use App\Services\SaasRevenueFigures;
use Illuminate\Http\Request;

/**
 * GAP-192 / SAAS-007 — THE PLATFORM'S OWN REVENUE SCREEN.
 *
 * "فصل إيرادات المنصة MRR/ARR عن GMV ومبيعات التجار" — separate platform revenue from merchant
 * sales. The separation is the requirement, so it is a SEPARATE SCREEN from `/platform/analytics`
 * rather than two more tiles on it, and each screen says in words what the other one measures.
 * Putting an MRR beside a cross-tenant GMV is how somebody eventually adds them.
 *
 * ONE READ, NO WRITES, NO REQUEST INPUT. No date range, no store, no plan filter: there is nothing
 * a caller could supply, so there is nothing to validate, and a cross-tenant money screen is the
 * worst place in the product to start accepting an identifier from a request — CLAUDE.md records
 * four separate defects from predicates built out of caller-supplied input.
 *
 * THE GATE IS IDENTITY, NOT A PERMISSION. `PlatformOperatorOnly` on the route, for the reason
 * `PlatformAnalyticsController` records at length: a merchant creates their own roles
 * (`RoleController::store`, reachable with the `Create Role` permission the shared `admin` role
 * carries for every self-registered merchant), so a permission name is substantially
 * self-assignable, while `users.type` is not. This screen reports what every merchant on the
 * platform pays, which is commercial information about third parties — the strongest case in the
 * product for operator-only. 403 and never 401: the shipped Flutter client force-clears its
 * session on any 401.
 *
 * WHY THE CLASS IS NAMED `SuperAdmin…`: Phase 06 is being built by several agents at once and this
 * workstream's file ownership is `app/Http/Controllers/SuperAdmin*`. The route, its name and the
 * view read `platform`, like the analytics pair beside them.
 */
class SuperAdminRevenueController extends Controller
{
    public function index(Request $request)
    {
        return view('superadmin.platform_revenue', [
            'figures' => SaasRevenueFigures::forPlatform(),
        ]);
    }
}
