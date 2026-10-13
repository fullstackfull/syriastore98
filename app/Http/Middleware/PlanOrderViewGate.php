<?php

namespace App\Http\Middleware;

use App\Models\PlanOrder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * NEW-PLAN-ORDER-SHOW-IDOR-01 -- the attachment point for App\Policies\PlanOrderPolicy::view()
 * on `plan.order.show` (routes/web.php:631).
 *
 * WHY MIDDLEWARE AND NOT A CHECK IN THE HANDLER. The handler is
 * BankTransferController::show():111, and BankTransferController is a plan-purchase gateway
 * controller (`plan-pay-with-bank`, :17) owned by the payments workstream. routes/** is this
 * workstream's file. That is the identical division PlatformOperatorOnly already documents and
 * applies to order.approve and order.reject on this same controller, so the gate goes in the
 * same place for the same reason. A second check inside show() would be defence in depth and is
 * recorded as a cross-agent note rather than smuggled in here.
 *
 * 403 FOR BOTH "NO SUCH ORDER" AND "NOT YOURS", deliberately. The two cases are made
 * indistinguishable so this route is not an oracle for which plan_orders ids exist -- the same
 * posture Api\DashboardController::resolveOwnedStore() documents at :3765-3767. It also replaces
 * what the unfixed route did with an unknown id: `PlanOrder::find()` returned null and
 * order/show.blade.php dereferenced it, so a bogus id was a 500.
 *
 * 403 AND NEVER 401: repo B's lib/network/network.dart maps any 401 to a forced logout that
 * clears stored preferences, and 403 to a plain "not permitted".
 */
class PlanOrderViewGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // NOT a denial. This route already carries `auth`, which runs first and owns the
        // anonymous case; answering 403 here would replace the sign-in redirect with a dead
        // end. Same early return, for the same reason, as PlatformOperatorOnly::handle().
        if (! $user) {
            return $next($request);
        }

        // The route parameter is `{id}` (routes/web.php:631), so there is no implicit model
        // binding to lean on -- the id is read from the matched route and resolved here.
        $order = PlanOrder::find($request->route('id'));

        if ($order !== null && Gate::forUser($user)->allows('view', $order)) {
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
