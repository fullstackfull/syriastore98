<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\User;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use App\Models\PlanUserCoupon;
use App\Models\Utility;


class BankTransferController extends Controller
{
    public function planPayWithbank(Request $request)
    {
        if ($request->payment_receipt)
        {
            $request->validate(
                [
                    'payment_receipt' => 'image',
                ]
            );
        }
        $admin_payment_setting = getSuperAdminAllSetting();
        $currency       = $admin_payment_setting['CURRENCY_NAME'];
        $planID         = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan           = Plan::find($planID);
        $authuser       = Auth::user();
        $coupon_id = '';
        $user = Auth::user();
        $orderID = time();
        $order = PlanOrder::where('plan_id', $planID)->where('payment_status', 'Pending')->where('user_id', $authuser->id)->first();
        if ($order)
        {
            return redirect()->route('plan.index')->with('error', __('You already send Payment request to this plan.'));
        }
        if ($request->payment_receipt)
        {

            $dir = 'uploads/receipt';
            if($request->payment_receipt) {
                $fileName = $request->payment_receipt->getClientOriginalName();
                $path = Utility::upload_file($request, 'payment_receipt', $fileName, $dir,[]);
            }
            if ($path['flag'] == 1) {
                $payment_receipt = $path['url'];
            } else {
                return redirect()->back()->with('error', __($path['msg']));
            }
            if ($plan) {
                $plan->discounted_price = false;
                $price                  = $plan->price;
                if (isset($request->coupon) && !empty($request->coupon)) {
                    $request->coupon = trim($request->coupon);
                    $coupons         = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                    if (!empty($coupons)) {
                        $usedCoupun             = $coupons->used_coupon();
                        $discount_value         = ($price / 100) * $coupons->discount;
                        $plan->discounted_price = $price - $discount_value;
                        if ($usedCoupun >= $coupons->limit) {
                            return Utility::error(['message' => __('This coupon code has expired.')]);
                        }
                        $price = $price - $discount_value;
                        $coupon_id = $coupons->id;
                    } else {
                        return Utility::error(['message' => __('This coupon code is invalid or has expired.')]);
                    }
                }

                if ($request->has('coupon') && $request->coupon != '') {
                    $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->first();
                    $discount_value         = ($plan->price / 100) * $coupons->discount;
                    $discounted_price = $plan->price - $discount_value;
                    if (!empty($coupons)) {
                        $userCoupon         = new PlanUserCoupon();
                        $userCoupon->user_id   = $user->id;
                        $userCoupon->coupon_id = $coupons->id;
                        $userCoupon->order  = $orderID;
                        $userCoupon->save();
                        $usedCoupun = $coupons->used_coupon();
                        if ($coupons->limit <= $usedCoupun) {
                            $coupons->is_active = 0;
                            $coupons->save();
                        }
                    }
                }
                $order                 = new PlanOrder();
                $order->order_id       = $orderID;
                $order->name           = $user->name;
                $order->card_number    = '';
                $order->card_exp_month = '';
                $order->card_exp_year  = '';
                $order->plan_name      = $plan->name;
                $order->plan_id        = $plan->id;
                $order->price          = isset($coupons) ? $plan->discounted_price : $plan->price;
                $order->price_currency = $currency;
                $order->txn_id         = isset($request->TXNID) ? $request->TXNID : '';
                $order->payment_type   = __('Bank Transfer');
                $order->payment_status = 'Pending';
                $order->receipt        = $payment_receipt;
                $order->user_id        = $user->id;
                $order->save();
                return redirect()->route('plan.index')->with('success', __('Plan payment request send successfully!'));
            }
        }
    }

    public function show(PlanOrder $order, $id)
    {
        $order = PlanOrder::find($id);
        $admin_payment_setting = getSuperAdminAllSetting();

        return view('order.show', compact('order', 'admin_payment_setting'));
    }

    /**
     * Approve a bank-transfer plan order and grant the plan it paid for.
     *
     * Authorization is on the route (PlatformOperatorOnly, routes/web.php), which is why
     * there is no check here. What was missing was everything downstream of it:
     *
     *   - NOT IDEMPOTENT. The handler re-granted the plan on every call regardless of the
     *     order's current status, and assignPlan() resets plan_expire_date to now()+period
     *     (User.php). A double-submit, a browser retry or a refresh therefore extended a
     *     subscriber's term for free, as many times as the button was pressed. The status
     *     guard below is the fix; it is the one behaviour change in this method.
     *   - A deleted user or a deleted plan fataled on `$user->assignPlan(...)` /
     *     `$plann->duration` instead of reporting the broken reference.
     *   - The status write and the plan grant were two uncommitted statements, so a failure
     *     between them left an order marked Approved with no plan granted.
     *   - Nothing was audited, on the one surface that converts money into entitlement.
     */
    public function orderapprove($id)
    {
        $order = PlanOrder::find($id);

        if (! $order) {
            return redirect()->back()->with('error', __('Something is wrong'));
        }

        if ($order->payment_status === 'Approved') {
            return redirect()->back()->with('error', __('This order has already been approved.'));
        }

        $user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);

        if (! $user || ! $plan) {
            return redirect()->back()->with('error', __('Something is wrong'));
        }

        $previousStatus = $order->payment_status;

        DB::transaction(function () use ($order, $user, $plan) {
            $order->payment_status = 'Approved';
            $order->save();

            $user->assignPlan($order->plan_id, $plan->duration);
        });

        AuditLog::record('plan_order_approved', $order, [
            'payment_status' => $previousStatus,
        ], [
            'payment_status' => 'Approved',
            'plan_id'        => $order->plan_id,
            'beneficiary_id' => $user->id,
        ]);

        return redirect()->back()->with('success', __('Order Successfully Approved'));
    }

    /**
     * Reject a bank-transfer plan order.
     *
     * Rejecting an ALREADY-APPROVED order is refused. The old handler allowed it, and it
     * only rewrote `payment_status`: the plan granted at approval stayed on the user, so the
     * result was a rejected order and a live subscription. Reversing a granted plan is the
     * refund path (PlanController::refund), which revokes entitlement as well.
     */
    public function orderreject($id)
    {
        $order = PlanOrder::find($id);

        if (! $order) {
            return redirect()->back()->with('error', __('Something is wrong'));
        }

        if ($order->payment_status === 'Approved') {
            return redirect()->back()->with('error', __('An approved order cannot be rejected. Use the refund flow instead.'));
        }

        if ($order->payment_status === 'Rejected') {
            return redirect()->back()->with('error', __('This order has already been rejected.'));
        }

        $previousStatus = $order->payment_status;

        $order->payment_status = 'Rejected';
        $order->save();

        AuditLog::record('plan_order_rejected', $order, [
            'payment_status' => $previousStatus,
        ], [
            'payment_status' => 'Rejected',
        ]);

        return redirect()->back()->with('success', __('Order Successfully Rejected'));
    }

    /*
     * P2.5-SEC-DESTRUCTIVE F-1. THIS DOOR HAD NO LOCK, AND IT IS THE IRREVERSIBLE ONE.
     *
     * `destroy(PlanOrder $order)` is route-model bound, so `if ($order)` below is decoration --
     * it is never null -- and the route carries `web, auth, xss, setlocate, verified` and NOTHING
     * else. MEASURED end to end: merchant A sent DELETE /bank_transfer/{B's plan order id} and the
     * row was gone; so did a STAFF member holding no permission at all. What goes is a
     * SUBSCRIPTION PAYMENT RECORD -- order id, price paid, payment status and the pointer to the
     * bank slip the merchant uploaded -- hard deleted, with no soft delete on PlanOrder and no
     * audit row to restore it from.
     *
     * THE REPOSITORY ALREADY STATED THIS PRINCIPLE AND THIS DOOR DID NOT READ IT.
     * `BulkDeleteTenantGuard` puts `plan-order` in PLATFORM_GLOBAL precisely because "letting the
     * payer erase it is an audit problem". The BULK door refuses it; the SINGLE-ROW door did not
     * look. Four of the five routes on this screen were gated by earlier reviews -- show, approve,
     * refund -- and the one that destroys was the one left open.
     *
     * THE GATE MIRRORS THE LIST'S OWN SCOPE, which is what makes it a fix and not a new policy:
     * `PlanOrderDataTable::query()` shows a non-operator only `users.id = auth()->user()->id`, so
     * a merchant can only ever SEE their own. This refuses what they could never have listed.
     * Operator-first, because `isAbleTo()` alone refuses the platform operator in this codebase.
     *
     * WHAT THIS DELIBERATELY DOES NOT DECIDE: whether the legitimate owner should be able to
     * erase their own payment record at all. That is an audit question with a financial
     * dimension, it is recorded for the owner in the acceptance report, and this change leaves
     * that behaviour exactly as it was.
     */
    /**
     * DELETION OF A SUBSCRIPTION PAYMENT RECORD IS PROHIBITED. This method refuses everyone.
     *
     * Owner instruction, 2026-11-11: *"Treat financial-record deletion as prohibited pending an
     * approved retention and correction policy."* A `PlanOrder` is a financial record -- order id,
     * price paid, payment status, and the pointer to the bank slip the merchant uploaded as proof
     * of payment. There is no retention or correction policy in `docs/security/` yet, so there is
     * nothing to delete *under*, and a hard delete leaves no audit row behind: `PlanOrder` is not
     * `Cachable`, has no `SoftDeletes`, and nothing writes an `AuditLog` on this path.
     *
     * THIS IS A DELIBERATE TIGHTENING OF AN EARLIER FIX, NOT A REGRESSION. The shipped code was
     * `if ($order) { $order->delete(); }` -- decoration, because route-model binding guarantees
     * `$order`, so any authenticated caller erased any merchant's payment record (F-1). That was
     * first repaired to admit the platform operator and the record's own owner, matching
     * `PlanOrderDataTable::query()`, which shows a non-operator only `users.id = auth()->id()`.
     * The instruction above then superseded that: the owner and the operator are now refused too.
     * Two tests in `DestructiveRoutesTenantGuardTest` were inverted to match and say so by name,
     * so this does not read as a broken feature.
     *
     * DO NOT re-admit a caller here to make a UI affordance work. The affordance was removed in
     * `resources/views/plans/action.blade.php` in the same change, precisely so that nobody
     * restores deletion in order to fix a button. When a retention and correction policy is
     * approved, implement correction (a reversing entry, or a soft delete with an audit row)
     * rather than reinstating a hard delete.
     */
    public function destroy(PlanOrder $order)
    {
        return redirect()->back()->with(
            'error',
            __('Deleting a payment record is not permitted.')
        );
    }

}
