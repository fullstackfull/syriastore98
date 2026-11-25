<?php

namespace App\Http\Controllers;

use App\Services\SaasPlatformResources;
use Illuminate\Http\Request;

/**
 * GAP-195 / OPS-002 — THE PLATFORM OPERATOR'S RESOURCE MEASUREMENTS.
 *
 * ONE READ, NO WRITES, NO REQUEST INPUT. There is no path parameter, no store parameter and no
 * filter, so there is nothing a caller could supply and therefore nothing to validate. That is
 * deliberate twice over: CLAUDE.md records that a predicate built from caller-supplied input is
 * not a check and has produced four separate defects here, and a `path` parameter on a screen that
 * calls `disk_free_space()` would be an arbitrary-filesystem probe handed to the caller.
 * `PlatformResourcesScreenTest::test_a_path_in_the_query_string_changes_nothing` asserts the
 * absence rather than leaving it to be read off today's code.
 *
 * THE GATE IS IDENTITY, NOT A PERMISSION, and the reasoning is `PlatformAnalyticsController`'s
 * verbatim because the situation is identical: a merchant creates their own roles
 * (`RoleController::store`, reachable with the `Create Role` permission that the shared `admin`
 * role carries for every self-registered merchant), so "holds permission X" is substantially
 * self-assignable, while `users.type` is not. For a screen whose subject is the SHARED MACHINE,
 * operator-first collapses to operator-only. So the route carries `PlatformOperatorOnly`, which
 * already compares `strtolower(rtrim(users.type, ' '))` through `UserPolicy::isOperator()` — there
 * is no super-admin bypass in Laratrust here, so `isAbleTo()` alone would refuse the operator —
 * defers the anonymous case to `auth`, and answers 403 and never 401, because repo B's
 * `lib/network/network.dart` maps any 401 to a forced logout that clears stored preferences.
 *
 * NO STORE NEEDS TO BE SELECTED. `getCurrentStore()` answers the hardcoded literal 1 for a
 * `super admin`, so a handler that asked would silently attribute one tenant's situation to the
 * platform. This one never calls it: the subject is the machine, which has no tenant.
 *
 * WHY THE CLASS IS NAMED `SuperAdmin…` AND ITS NEIGHBOUR `PlatformAnalyticsController` IS NOT.
 * Phase 06 is being built by several agents at once and this workstream's file ownership is
 * `app/Http/Controllers/SuperAdmin*`. The name is the ownership boundary, not a second convention;
 * the screen, its route name and its view all read `platform`, like the analytics pair beside them.
 */
class SuperAdminResourcesController extends Controller
{
    public function index(Request $request)
    {
        return view('superadmin.platform_resources', [
            'measured' => SaasPlatformResources::measure(),
        ]);
    }
}
