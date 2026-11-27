<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NEW-PLAN-APPROVE-UNGATED-01 / NEW-PLAN-REFUND-UNGATED-01 -- a route-level platform-operator
 * gate, for SaaS billing actions whose controllers belong to another workstream.
 *
 * WHY THIS EXISTS AS MIDDLEWARE AND NOT AS A CHECK IN THE CONTROLLER. Three routes perform
 * platform-side SaaS billing decisions with no authorization at all:
 *
 *   routes/web.php:624  order.approve      BankTransferController::orderapprove:119
 *   routes/web.php:625  order.reject       BankTransferController::orderreject
 *   routes/web.php:517  plan.order.refund  PlanController::refund
 *
 * `orderapprove` does `PlanOrder::find($id)`, sets payment_status = 'Approved' and calls
 * `$user->assignPlan($order->plan_id, $plann->duration)` -- so ANY authenticated user could
 * approve their OWN pending bank-transfer order and receive a paid plan without paying, or
 * approve anyone else's. `PlanController::refund` marks any plan order `is_refund = 1` and then
 * `$user->assignPlan(1)` on a caller-supplied user_id -- so it also downgrades an ARBITRARY
 * merchant to the free plan, which is sabotage of a third party rather than a self-serve action.
 *
 * BankTransferController is a plan-purchase gateway controller and belongs to the payments
 * workstream; PlanController is not this workstream's file either. routes/** IS. So the control
 * is attached where this workstream owns the code, and the need for a second check INSIDE those
 * controllers (defence in depth, the pattern SEC-F2 established after relying on grouping alone
 * failed) is recorded as a cross-agent dependency rather than smuggled in here.
 *
 * WHY PLATFORM-OPERATOR AND NOT AN OWNERSHIP CHECK -- decided from the application's own UI, not
 * from taste:
 *   - the Refund affordance is rendered only inside `@foreach ($userOrders ...)` in
 *     resources/views/plans/action.blade.php:20-23, and `$userOrders` is populated ONLY for
 *     `type == 'super admin'` (app/DataTables/PlanOrderDataTable.php:22-32). It is already a
 *     super-admin-only button; the route simply never enforced it.
 *   - Approval/Reject (resources/views/order/show.blade.php:39-40) is the platform reviewing an
 *     uploaded bank-transfer receipt. A merchant approving their own payment is the defect, and
 *     the fact that the modal currently renders those buttons for the payer IS the finding, not
 *     a workflow to preserve.
 *
 * 403, NEVER 401: repo B's lib/network/network.dart maps any 401 to a forced logout that clears
 * stored preferences, and 403 to a plain "not allowed".
 */
class PlatformOperatorOnly
{
    public const PLATFORM_ADMIN = 'super admin';

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // NOT a denial: these routes already carry `auth`, which runs first and owns the
        // anonymous case. Answering 403 here would replace the sign-in redirect.
        if (! $user) {
            return $next($request);
        }

        /*
         * `UserPolicy::isOperator()` AND NOT A SECOND SPELLING OF THE SAME TEST. What stood here
         * was `strtolower(trim((string) $user->type))`, and `trim` strips a LEADING space while
         * the canonical helper deliberately does not: `strtolower(rtrim($type, ' '))`, because
         * MySQL's PAD SPACE ignores a trailing space and does NOT ignore a leading one. So a row
         * typed `' super admin'` was a NON-operator to the engine, to `User::isPlatformAdmin()`
         * and to `OperatorCheckCensusTest`'s own data provider -- which asserts that string must
         * be refused -- and an OPERATOR to this middleware.
         *
         * LATENT, NOT LIVE, and that was established rather than assumed: the only writers of
         * `users.type` are the seeder's exact `'super admin'` and `UserController`'s role-name
         * path, which normalises before refusing the reserved names, so no tenant can mint a
         * variant. It needs a hand-edited row. It is fixed anyway, because this middleware is the
         * ONLY check on routes whose handlers document that they rely on it
         * (`BankTransferController::orderapprove`, `PlanController::refund`).
         *
         * Found by an independent review of the `plan.disable` gate, which this middleware now
         * also carries -- there the handler's own strict `!==` refused the lookalike, so the pair
         * composed fail-closed and the middleware's looseness was invisible.
         *
         * THAT COMPOSITION DOES NOT HOLD FOR THE OTHER DEFECT, and reading it as though it did
         * would overstate `plan.disable`'s protection. `planDisable`'s own check is
         * `$actor->type !== UserPolicy::PLATFORM_ADMIN` -- a plain string comparison that never
         * asks what CLASS the principal is -- so a `Customer` typed exactly `super admin`
         * satisfies it. MEASURED 2026-11-26 by removing the `instanceof` clause below: that
         * request answered **200** carrying the handler's own business-logic JSON, in a loop and
         * in isolation. So for `plan.disable` the clause below is the ONLY thing standing there,
         * not the second of two. Pinned by the five-group case in
         * `PlatformOperatorOnlyPrincipalTest`.
         */
        /*
         * THE PRINCIPAL MUST BE A `User` BEFORE ITS `type` MEANS ANYTHING, and that clause is the
         * second latent defect found in this one line.
         *
         * `UserPolicy::isOperator()` takes an UNTYPED `$actor` and reads `$actor->type`
         * (`app/Policies/UserPolicy.php:239`) -- and `customers` HAS a `type` column, with
         * `'type'` in `Customer::$fillable` (`app/Models/Customer.php:35`). So a `Customer` row
         * whose `type` read `super admin` passed this middleware, which is the ONLY check on
         * 57 routes. THIS SAID "14 ... AND THE THREE `platform/*` SCREENS" UNTIL 2026-11-26, when
         * it was measured at 37. FIVE WORKSTREAMS THEN ADDED TO THIS GATE INDEPENDENTLY, FROM
         * THAT SAME BASE, AND EACH CORRECTLY UPDATED THIS TABLE TO ITS OWN TOTAL: `ADM-002` added
         * Store 360's `platform/stores` and `platform/stores/{store}` and wrote 39; `IAM-003`
         * added three `platform/approvals` routes (the two-person approval queue) and wrote 40;
         * `KYC-002` added three `platform/verification` routes and did not touch this table;
         * `OPS-001`/`OPS-003` added `platform/operations` and five `platform/incidents` and wrote
         * 43; and `CTRL-001`/`002`/`003` added four `platform/feature-control` routes and two
         * `platform/maintenance` ones and also wrote 43.
         * None was wrong and none was the answer -- the merged total is the UNION, 57, and taking
         * any one side would have dropped the others' routes out of a partition whose purpose is
         * to account for every one. THE INTEGRATOR'S OWN ARITHMETIC SAID 42, because it reconciled
         * the two conflicting sides and never asked whether a THIRD branch had added routes without
         * conflicting. The ratchet measured 45 and refused it. The number is written out as a partition
         * because a count living only in prose is exactly what went stale here -- measured from
         * the booted route table, and asserted in both directions by
         * `PlatformOperatorOnlyPrincipalTest::test_premise_the_measured_blast_radius_is_still_57`,
         * so the next route that joins the
         * gate reddens a test instead of quietly making this comment wrong again:
         *
         *     LynomiaChat super-admin group                                      15
         *     platform/* operator screens (NOT three: analytics x2, documentation
         *       x2, resources, revenue, site-content x3, stores x2,
         *       approvals x3, verification x3, operations, incidents x5,
         *       feature-control x4, maintenance x2)                              29
         *     plan-coupon                                                         7
         *     plan.order.refund, plan.disable, order.approve, order.reject        4
         *     zid/{storeId}/*                                                     2
         *                                                                       ---
         *                                                                        57
         *
         * AND THE SIX `OPS-001`/`OPS-003` ROUTES CHANGED WHAT A BYPASS WOULD COST, which is worth
         * saying exactly where the blast radius is counted. Restored on 2026-11-27 from
         * `ws/p6-ops`, whose docblock carried it at a total of 43; the integration merge took the
         * reconciled 51 and dropped this paragraph with the number, and nothing replaced it.
         * `platform/operations` (1) and the incident log (5 -- index, store, show, transition,
         * note) ARE THE FIRST WRITING ROUTES BEHIND THIS GATE: every route in the table above this
         * line DISCLOSES, and three of those six let a caller WRITE the platform's own operational
         * history. A `Customer` row typed `super admin` getting through would not have read a
         * number -- it would have signed one. `SuperAdminIncidentsController` repeats the test in
         * all five actions, and
         * `PlatformIncidentsScreenTest::test_every_action_refuses_a_non_operator_with_the_route_gate_removed`
         * drives each of them directly, with no middleware in the way, asserting EXACTLY 403 and
         * that the tables are unchanged.
         *
         * AND `CTRL-001`/`002`/`003`'S FOUR WRITE ROUTES ARE A NARROWER CATEGORY STILL, which is
         * why both paragraphs stand rather than one replacing the other. OPS-003's writes RECORD
         * what already happened to the platform; these four CHANGE ITS CONFIGURATION -- three
         * `platform/feature-control` writes decide which features reach which stores, and
         * `platform/maintenance` publishes a notice to every merchant on the platform. So the
         * clause below now stands between a non-`User` principal and a feature switch, not only
         * between it and a disclosure or a log entry. CTRL's own docblock claimed these were "the
         * first on this gate that change platform configuration rather than read it", which was
         * true from its base of 37 and is not after OPS-003 landed first; the distinction that
         * survives is CONFIGURATION against HISTORY, not first against later.
         *
         * `PlatformFeatureControlController` and `PlatformMaintenanceController` each repeat the
         * test in every action (`refuseAnyoneButTheOperator()`, copied character-for-character
         * from `SuperAdminResourcesController`), and
         * `PlatformFeatureControlScreenTest` and `MaintenanceAnnouncementTest` drive them with the
         * route gate removed -- mandatory here, because CLAUDE.md records that a handler-level
         * check behind an `abort()`ing middleware is invisible to an HTTP test (`GAP-307`).
         *
         * It grew with the Lynomia group and with the screens `GAP-192`, `GAP-195`, `GAP-107` and
         * `GAP-232` (Store 360: `platform/stores` and `platform/stores/{store}`)
         * added. Nothing behaved wrongly at 14; what was understated by 23 routes is how much
         * rests on the one clause below -- handlers whose own docblocks say they rely on it
         * (`BankTransferController:128`, `PlanController:625`).
         *
         * LATENT, NOT LIVE, AND BOTH LINKS WERE MEASURED rather than assumed:
         *
         *   1. Nothing in production makes `customers` the default guard. `shouldUse(` appears
         *      ZERO times outside `tests/` and prose, so `auth()->user()` on a `web` route cannot
         *      be a `Customer`.
         *   2. No path writes `customers.type` from input. Every `Customer` creator in `app/`
         *      hard-codes `'customer'` -- `PaymentController:2378`, `CustomerController:196`,
         *      `Api\AuthController:260`, `Api\DashboardController:11356`,
         *      `Customer\Auth\CustomerLoginController:1165`, `ShopifyCustomerController:149,265`.
         *      (Not swept across the 70 vendored modules; that claim is scoped to `app/`.)
         *
         * Two independent missing links, so this is hardening. Fixed anyway, because the blast
         * radius if either link ever closes is every route above, and the cost is one clause.
         * Found by the independent review of the `/platform/documentation` surface, whose
         * controller carries the identical guard for the identical reason.
         *
         * The ORDER matters: `instanceof` short-circuits, so `isOperator()` never reads `->type`
         * off a non-`User` at all.
         */
        if ($user instanceof \App\Models\User && \App\Policies\UserPolicy::isOperator($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => false,
                'message' => __('Permission denied.'),
            ], 403);
        }

        abort(403, __('Permission denied.'));
    }
}
