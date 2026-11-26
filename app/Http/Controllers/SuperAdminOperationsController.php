<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\SaasPlatformOperations;
use Illuminate\Http\Request;

/**
 * OPS-001 — THE PLATFORM OPERATOR'S DATABASE, QUEUE AND INTEGRATION MEASUREMENTS.
 * =================================================================================================
 *
 * The sibling of `SuperAdminResourcesController`, which measures the MACHINE (`OPS-002`). This one
 * measures the other three subjects the recovered requirement names: "مراقبة الخوادم وقواعد
 * البيانات والطوابير والتكاملات" — servers, databases, queues and integrations.
 *
 * ONE READ, NO WRITES, NO REQUEST INPUT. There is no path parameter, no store parameter and no
 * filter, so there is nothing a caller could supply and therefore nothing to validate. That is
 * deliberate, and it is the same reasoning the resources screen records: `CLAUDE.md` notes that a
 * predicate built from caller-supplied input is not a check and has produced four separate defects
 * here, and this handler reads figures spanning every tenant — a `store_id` in the query string
 * would be a cross-tenant selector that looked like a filter.
 * `PlatformOperationsScreenTest::test_a_query_string_changes_nothing` asserts the absence rather
 * than leaving it to be read off today's code.
 *
 * THE GATE IS IDENTITY, NOT A PERMISSION, and the reasoning is `SuperAdminResourcesController`'s
 * verbatim because the situation is identical: a merchant creates their own roles
 * (`RoleController::store`, reachable with the `Create Role` permission that the shared `admin`
 * role carries for every self-registered merchant), so "holds permission X" is substantially
 * self-assignable, while `users.type` is not. For a screen whose subject is the SHARED
 * INFRASTRUCTURE, operator-first collapses to operator-only. So the route carries
 * `PlatformOperatorOnly`, which compares `strtolower(rtrim(users.type, ' '))` through
 * `UserPolicy::isOperator()` — there is no super-admin bypass in Laratrust here, so `isAbleTo()`
 * alone would refuse the operator — defers the anonymous case to `auth`, and answers 403 and never
 * 401, because repo B's `lib/network/network.dart` maps any 401 to a forced logout that clears
 * stored preferences.
 *
 * AND THE SUBJECT MAKES THE GATE STRICTER THAN THE MACHINE SCREEN'S, NOT LOOSER. The resources
 * screen discloses load, memory and disk. This one discloses, per payment gateway, HOW MANY STORES
 * HAVE IT SWITCHED ON AND HOW MANY OF THOSE ARE MISCONFIGURED — commercial information about third
 * parties, and an attacker's shopping list. No credential value is read or printed anywhere
 * (`SaasOperationsProbe` asks the engine for presence and never selects a `settings` value, which
 * its test holds against the SQL actually issued), but the ENABLEMENT itself is not a merchant's
 * business to know about other merchants.
 *
 * NO STORE NEEDS TO BE SELECTED. `getCurrentStore()` answers the hardcoded literal 1 for a
 * `super admin`, so a handler that asked would silently attribute one tenant's situation to the
 * platform. This one never calls it: the subject is the infrastructure, which has no tenant.
 *
 * WHY THE CLASS IS NAMED `SuperAdmin…` AND ITS NEIGHBOUR `PlatformAnalyticsController` IS NOT.
 * Phase 06 is being built by several agents at once and this workstream's file ownership is
 * `app/Http/Controllers/SuperAdmin*`. The name is the ownership boundary, not a second convention;
 * the screen, its route name and its view all read `platform`, like the analytics pair beside them.
 */
class SuperAdminOperationsController extends Controller
{
    /**
     * HOW FAR BACK THE FAILURE FIGURE REACHES, fixed in the code and not taken from the request.
     *
     * A window the caller chooses would be a nicety and would also be the one input on a screen
     * that has none; a window nobody states is the commonest way a failure count misleads. 24
     * hours is the span the screen says it covers, in words, next to the figure.
     *
     * THIS IS NOT A THRESHOLD AND IT JUDGES NOTHING. It selects which failures are counted, not
     * whether the count is acceptable. What is acceptable is `OPS-003`'s alerting policy and is an
     * owner decision that has not been taken.
     */
    public const FAILURE_WINDOW_HOURS = 24;

    public function index(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        return view('superadmin.platform_operations', [
            'measured' => SaasPlatformOperations::measure(null, self::FAILURE_WINDOW_HOURS),
        ]);
    }

    /**
     * DEFENCE IN DEPTH, AND NOT A SECOND SPELLING OF THE ROUTE GATE.
     *
     * The route carries `PlatformOperatorOnly`. This is the same test again, inside the handler,
     * for the reason `SEC-F2` established in this codebase: a control that exists only at the
     * route is one declaration away from not existing. And the way it disappears is MEASURED here,
     * not hypothetical — `RouteCollection::addToCollections()` keys on method+domain+uri, so a
     * later declaration of the same verb and URI REPLACES the earlier one and silently drops its
     * middleware. `GAP-268` found a real duplicated declaration in `routes/web.php`.
     *
     * THE PREDICATE IS COPIED FROM `SuperAdminResourcesController::refuseAnyoneButTheOperator()`
     * character-for-character rather than rewritten: a second spelling of an operator test is how
     * `PlatformOperatorOnly` came to admit `' super admin'` while every other check refused it
     * (`trim` strips a leading space; `UserPolicy::isOperator()` does `rtrim($type, ' ')`, matching
     * MySQL's PAD SPACE).
     *
     * `instanceof User` FIRST, AND THE ORDER IS THE CONTROL. `UserPolicy::isOperator()` takes an
     * untyped `$actor` and reads `$actor->type`, and `customers` HAS a `type` column with `'type'`
     * in `Customer::$fillable` — so a `Customer` row typed `super admin` satisfies `isOperator()`.
     * `instanceof` short-circuits, so the helper never reads `->type` off a non-`User` at all.
     * Without the class clause such a Customer reaches the handler and raises a 500, and a 500 is
     * not a refusal.
     *
     * NO MESSAGE, like its sibling. `PlatformResourceMessagesAreDeclaredTest` declares the
     * `SuperAdmin*` controllers `SILENT_SURFACES` — "the controller hands the producer's result to
     * a view and composes nothing" — and a `__()` key here reddens it. No control is lost: the 403
     * IS the control, the route gate supplies the translated message on the ordinary path, and on
     * this path — which only fires if the route gate has been dropped — a generic 403 discloses
     * less.
     */
    private function refuseAnyoneButTheOperator(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403);
        }
    }
}
