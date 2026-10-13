<?php

namespace App\Policies;

use App\Models\PlanOrder;
use App\Models\User;

/**
 * NEW-PLAN-ORDER-SHOW-IDOR-01. `plan.order.show` (routes/web.php:631) resolved its subject as
 * `PlanOrder::find($id)` with NO ownership test and rendered resources/views/order/show.blade.php
 * for it, so ANY authenticated user could read ANY merchant's subscription order by incrementing
 * one path segment.
 *
 * WHAT WAS DISCLOSED, read off the template rather than guessed:
 *   order/show.blade.php:4   plan_orders.order_id
 *   order/show.blade.php:8   plan_name          -- which plan that merchant is on
 *   order/show.blade.php:12  price              -- and what they actually paid for it
 *   order/show.blade.php:20  payment_status
 *   order/show.blade.php:31  receipt            -- a download link to the UPLOADED BANK SLIP
 * The receipt is the worst of the five: it is a third party's bank document, uploaded under
 * public/uploads/receipt by BankTransferController::planPayWithbank:41.
 *
 * WHY "THE PAYER OR THE PLATFORM OPERATOR", taken from the application's own UI and not from
 * taste. The only link to this route is resources/views/plans/action.blade.php:4, rendered from
 * PlanOrderDataTable, and that table's own query (app/DataTables/PlanOrderDataTable.php:87-89) is
 *
 *     if (auth()->user() && auth()->user()->type != 'super admin') {
 *         $query->where('users.id', '=', auth()->user()->id);
 *     }
 *
 * -- a merchant is shown only the rows whose plan_orders.user_id is their OWN user id, and a
 * super admin is shown every row. So this policy states the predicate the listing has always
 * used; the detail route simply never enforced it.
 *
 * `user_id` AND NOT `creatorId()`, DELIBERATELY. plan_orders.user_id is the id of the user who
 * bought the subscription, written from `Auth::user()` at BankTransferController:101. Widening to
 * the tenant (creatorId()) would hand a merchant's STAFF their employer's bank receipt and plan
 * price, which the listing never showed them either. The narrow predicate matches the listing, is
 * fail-closed, and nothing in the UI links staff here.
 *
 * `store_id` IS NOT USED AS THE DISCRIMINATOR even though plan_orders has the column.
 * BulkDeleteTenantGuard puts `plan-order` in PLATFORM_GLOBAL for the same reason
 * (app/Http/Middleware/BulkDeleteTenantGuard.php:213, and its docblock says so explicitly): a
 * plan order is the record of a merchant's own subscription payment to the platform, so the
 * principal is the payer, not whoever currently holds a session on some store.
 *
 * ATTACHED AS ROUTE MIDDLEWARE, NOT AS AN authorize() CALL. BankTransferController is a
 * plan-purchase gateway controller and belongs to the payments workstream; routes/** does not.
 * This is the same division PlatformOperatorOnly already documents for order.approve and
 * order.reject on this very controller. App\Http\Middleware\PlanOrderViewGate is the attachment
 * point; the predicate lives here.
 */
class PlanOrderPolicy
{
    public const PLATFORM_ADMIN = 'super admin';

    /**
     * `strtolower(trim())`, matching PlatformOperatorOnly::handle() and
     * FinancePermission -- not the bare `=== 'super admin'` that OrderPolicy and the
     * two resolveOwnedStore() helpers use. A stored 'Super admin' or a trailing
     * space must not silently demote the platform operator.
     */
    protected function isPlatformAdmin(?User $actor): bool
    {
        return $actor !== null
            && strtolower(trim((string) $actor->type)) === self::PLATFORM_ADMIN;
    }

    /**
     * Read one plan order's detail, including its uploaded bank receipt.
     */
    public function view(?User $actor, PlanOrder $order): bool
    {
        if ($actor === null) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        // A NULL user_id MUST NOT MATCH. Several gateway paths in this application save a
        // PlanOrder without one, and `null == 0` plus a loose comparison against an absent
        // actor id is exactly how an "ownership" test becomes a no-op. Both sides are
        // required to be present before they are compared.
        if (empty($order->user_id) || empty($actor->id)) {
            return false;
        }

        return (int) $order->user_id === (int) $actor->id;
    }
}
