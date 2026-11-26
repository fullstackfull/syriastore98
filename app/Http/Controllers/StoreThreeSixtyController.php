<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Operator\StoreThreeSixty;
use Illuminate\Http\Request;

/**
 * ADM-002 -- THE OPERATOR'S STORE 360° SPACE.
 *
 * Recovered decision text, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 * `مساحة Store 360° لمراجعة حالة كل متجر وإدارته من أنظمته الأصلية` -- a Store 360° space to
 * review the state of EACH store and manage it FROM ITS OWN SYSTEMS.
 *
 * TWO READS, NO WRITES. `index()` lists every store; `show()` reads one. Neither writes anything
 * and neither reimplements a merchant screen -- management happens through
 * `StoreThreeSixty::doors()`, which links the EXISTING route into each subsystem with its existing
 * gate. That split is the requirement, not an economy: an operator-side editor for a merchant's
 * catalogue would be a second system, and the decision asks for the store's own.
 *
 * ============================================================================================
 *  THE GATE IS IDENTITY, IT IS CHECKED TWICE, AND `isAbleTo()` IS NOT USED AT ALL.
 * ============================================================================================
 *
 * There is NO super-admin bypass in this application's Laratrust setup
 * (`permissions_as_gates => false`), so `isAbleTo('...')` REFUSES the platform operator outright.
 * A permission name would also be the wrong KIND of gate here even if it worked: a merchant
 * creates their own roles through the product (`RoleController::store`, reachable with the
 * `Create Role` permission the shared `admin` role carries for every self-registered merchant),
 * so "holds permission X" is substantially self-assignable, and this screen reads every tenant.
 *
 * So the gate is identity: `PlatformOperatorOnly` on the route, and `refuseNonOperator()` in the
 * handler. Both, not either.
 *
 * WHY BOTH, STATED PRECISELY, because CLAUDE.md records the trap in the other direction too:
 * *A HANDLER-LEVEL CHECK BEHIND AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP TEST* -- the
 * middleware answers 403 and the handler is never reached, so an HTTP test is green whether or not
 * the handler checks anything. `PlanController::planDisable` admitted a `Customer` typed
 * `super admin` behind exactly that arrangement (GAP-307). The second check is therefore worth
 * having AND is worth nothing unless something calls the handler DIRECTLY, which
 * `StoreThreeSixtyOperatorGateTest` does.
 *
 * `UserPolicy::isOperator()` AND NOT A LITERAL COMPARISON. `$user->type === 'super admin'`
 * disagrees with the engine for `'Super Admin'` and for `'super admin '` -- values MySQL's
 * `utf8mb4_unicode_ci` with PAD SPACE treats as EQUAL to the literal -- so a row spelled either
 * way is the operator to the database and a stranger to that line. The helper uses
 * `strtolower(rtrim($type, ' '))`, which matches the engine, and deliberately does NOT `trim()`:
 * a LEADING space is not folded by PAD SPACE, so `' super admin'` must stay a non-operator.
 *
 * AND THE PRINCIPAL MUST BE A `User` FIRST. `customers` HAS a `type` column with `'type'` in
 * `Customer::$fillable`, and `isOperator()` takes an untyped `$actor`, so a `Customer` row whose
 * `type` read `super admin` would satisfy a bare `isOperator()` call. The `instanceof`
 * short-circuits ahead of it, exactly as `PlatformOperatorOnly` does.
 *
 * 403, NEVER 401: the shipped Flutter app's `lib/network/network.dart` maps any 401 to a forced
 * logout that clears stored preferences.
 */
class StoreThreeSixtyController extends Controller
{
    /**
     * THE ROSTER. No request input of any kind -- no store parameter, no filter, no page size --
     * so there is nothing a caller could supply and therefore nothing to validate. CLAUDE.md
     * records that a tenant predicate built from caller-supplied input is not a tenant check and
     * has produced four separate defects here; a screen that reads EVERY tenant is the worst place
     * to accept an id from a request.
     */
    public function index(Request $request)
    {
        if ($refusal = $this->refuseNonOperator()) {
            return $refusal;
        }

        return view('superadmin.store_360_index', [
            'stores' => StoreThreeSixty::roster(),
        ]);
    }

    /**
     * ONE STORE.
     *
     * ROUTE-MODEL BINDING IS SAFE HERE AND THAT IS NOT AN ASSUMPTION. `Store` is absent from
     * `config/tenancy.php`'s `scoped_models` and carries no `store_id`, so the binding adds no
     * tenant predicate -- which for this caller is the point: the operator administers every
     * store. The cross-tenant reach is supplied by the gate above, not by the absence of a scope.
     *
     * A MISSING STORE IS A 404 FROM THE BINDING, which is a different answer from the 403 above,
     * and keeping them distinguishable matters: CLAUDE.md records that *a gateless handler's own
     * "not found" branch is also a 302, so a 302 from a missing row and a 302 from a gate are the
     * same status and opposite facts*. Here they are 404 and 403, and the gate test keys on the
     * reason rather than on the status alone.
     */
    public function show(Request $request, Store $store)
    {
        if ($refusal = $this->refuseNonOperator()) {
            return $refusal;
        }

        return view('superadmin.store_360', [
            'store' => $store,
            'facts' => StoreThreeSixty::forStore($store),
        ]);
    }

    /**
     * The handler's own copy of the gate. Returns a response to refuse with, or null to proceed.
     *
     * `abort(403)` rather than a redirect, for the reason the whole family of `platform/*` screens
     * uses: a redirect to the dashboard answers HTTP 200, which a status-code assertion
     * photographs as the screen -- CLAUDE.md's own warning about `ActivatedModule()`'s
     * `redirect()->route('dashboard')` refusals.
     */
    private function refuseNonOperator(): mixed
    {
        $user = auth()->user();

        if ($user instanceof User && UserPolicy::isOperator($user)) {
            return null;
        }

        if (request()->expectsJson()) {
            return response()->json([
                'status' => false,
                'message' => __('Permission denied.'),
            ], 403);
        }

        abort(403, __('Permission denied.'));
    }
}
