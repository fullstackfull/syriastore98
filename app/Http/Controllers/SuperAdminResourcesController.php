<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Policies\UserPolicy;
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
        $this->refuseAnyoneButTheOperator();

        return view('superadmin.platform_resources', [
            'measured' => SaasPlatformResources::measure(),
        ]);
    }

    /**
     * DEFENCE IN DEPTH, AND NOT A SECOND SPELLING OF THE ROUTE GATE. `GAP-178` / `P06-05`.
     *
     * The route carries `PlatformOperatorOnly`. This is the same test again, inside the handler,
     * for the reason `SEC-F2` established in this codebase: a control that exists only at the
     * route is one declaration away from not existing. And the way it disappears is MEASURED here,
     * not hypothetical -- `RouteCollection::addToCollections()` keys on method+domain+uri, so a
     * later declaration of the same verb and URI REPLACES the earlier one and silently drops its
     * middleware. `GAP-268` found a real duplicated declaration in `routes/web.php`;
     * `NoAccidentalDuplicateRouteDeclarationsTest` now ratchets the set. Without this method, that
     * day is a cross-tenant disclosure rather than a caught regression.
     *
     * THE PREDICATE IS COPIED FROM `PlatformContentController::refuseAnyoneButTheOperator()`
     * character-for-character rather than rewritten: a second spelling of an operator test is how
     * `PlatformOperatorOnly` came to admit `' super admin'` while every other check refused it
     * (`trim` strips a leading space; `UserPolicy::isOperator()` does `rtrim($type, ' ')`, matching
     * MySQL's PAD SPACE).
     *
     * ONE THING DIFFERS, AND IT IS NOT AN OVERSIGHT: the sibling aborts with
     * `__('Permission denied.')` and this aborts with no message. `PlatformResourceMessagesAre
     * DeclaredTest` declares BOTH `SuperAdmin*` controllers `SILENT_SURFACES` -- "the controller
     * hands the producer's result to a view and composes nothing" -- and a `__()` key here reddens
     * it. That is another workstream's design rule about this exact file, so the status carries the
     * refusal instead of a sentence. No control is lost: the 403 IS the control, the route gate
     * still supplies the translated message on the ordinary path, and on this path -- which only
     * fires if the route gate has been dropped -- a generic 403 discloses less. The census was
     * found by running it, not predicted: it failed on `+ 0 => 'Permission denied.'`.
     *
     * `instanceof User` FIRST, and the ORDER is the control. `UserPolicy::isOperator()` takes an
     * untyped `$actor` and reads `$actor->type`, and `customers` HAS a `type` column with `'type'`
     * in `Customer::$fillable` -- so a `Customer` row typed `super admin` satisfies `isOperator()`.
     * `instanceof` short-circuits, so the helper never reads `->type` off a non-`User` at all.
     * Driven by `PlatformAdminSurfaceDefenceInDepthTest`, which removes the route gate and asserts
     * EXACTLY 403 for both a merchant and such a Customer -- exactly 403, because without the class
     * clause the Customer reaches the handler and raises a 500, and a 500 is not a refusal.
     */
    private function refuseAnyoneButTheOperator(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403);
        }
    }
}
