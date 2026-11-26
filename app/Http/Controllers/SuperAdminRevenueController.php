<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Policies\UserPolicy;
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
        $this->refuseAnyoneButTheOperator();

        return view('superadmin.platform_revenue', [
            'figures' => SaasRevenueFigures::forPlatform(),
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
