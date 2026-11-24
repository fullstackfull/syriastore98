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
         */
        if (\App\Policies\UserPolicy::isOperator($user)) {
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
