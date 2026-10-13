<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use App\Models\PlanCoupon;
use Dipesh79\LaravelEsewa\LaravelEsewa as eSewa;

class ESewaPaymentController extends Controller
{
    //
    public function addpayment(Request $request){
        $objUser               = \Auth::user();
        $planID                = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan                  = Plan::find($planID);
        $admin_payment_setting = getSuperAdminAllSetting($objUser->id, getCurrentStore());
        $currency = $admin_payment_setting['CURRENCY_NAME'];
        if ($plan) {
            try {
                $price = $plan->price;
                if (!empty($request->coupon)) {
                    $coupons = PlanCoupon::where('code', strtoupper($request->coupon))->where('is_active', '1')->first();
                    if (!empty($coupons)) {
                        $usedCoupun     = $coupons->used_coupon();
                        $discount_value = ($plan->price / 100) * $coupons->discount;
                        $price          = $plan->price - $discount_value;

                        if ($coupons->limit == $usedCoupun) {
                            return redirect()->back()->with('error', __('This coupon code has expired.'));
                        }
                    } else {
                        return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                    }
                }

                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

                config([
                    'esewa.scd' => isset($admin_payment_setting['esewa_merchant_key']) ? $admin_payment_setting['esewa_merchant_key'] : '',
                    'esewa.env' => ucfirst(isset($admin_payment_setting['esewa_mode']) ? $admin_payment_setting['esewa_mode'] : 'Sandbox'),
                ]);
                $payment    = new eSewa();
                $successURL = route('plan.esewa.payment.success', ['plan_id' => $planID,'coupon_code'=>$request->coupon_code,'amount' => $price,'plan' => $plan->id, 'status' => 'success']);
                $faildURL   = route('plan.esewa.payment.failure', ['status' => 'faild']);
                $pay        =  $payment->esewaCheckout($price, 0, 0, 0, $planID, $successURL, $faildURL);

                return redirect()->away($pay);
            } catch (\Exception $e) {
                return redirect()->back()->with('error', __($e->getMessage()));
            }
        } else {
            return redirect()->back()->with('error', __('Plan is deleted.'));
        }
    }

    public function success(Request $request){
        $admin_payment_setting = getSuperAdminAllSetting();
        $plan           = Plan::find($request->plan_id);

        // The plan must exist before anything is priced or activated. Previously a bad
        // plan_id reached `$plan->name` and 500ed with a half-built order in scope.
        if (empty($plan)) {
            abort(404, __('Plan not found.'));
        }

        // P0: price comes from the PLAN, never from the query string. See
        // priceFromPlanOrFail() for the bypass this closes.
        $serverPrice = $this->priceFromPlanOrFail($plan, $request);
        $user = \Auth::user();
        $couponCode = $request->coupon;
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        if ($request->status == 'success') {
            if($request->has('coupon_code') && $request->coupon_code != '')
            {
                    $coupons = PlanCoupon::where('code', strtoupper($couponCode))->where('is_active', '1')->first();
                    if(!empty($coupons))
                    {
                        $userCoupon         = new PlanUserCoupon();
                        $userCoupon->user_id   = $user->id;
                        $userCoupon->coupon_id = $coupons->id;
                        $userCoupon->order  = $orderID;
                        $userCoupon->save();
                        $usedCoupun = $coupons->used_coupon();
                        if($coupons->limit <= $usedCoupun)
                        {
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
            $order->price          = $serverPrice;
            $order->price_currency = $admin_payment_setting['CURRENCY_NAME'];
            $order->txn_id         = '';
            $order->payment_type   = __('ESewa');
            $order->payment_status = 'success';
            $order->receipt        = '';
            $order->user_id        = $user->id;
            $order->save();

            if(!empty($request->coupon))
            {
                $userCoupon         = new PlanUserCoupon();
                $userCoupon->user   = $user->id;
                $userCoupon->coupon = $coupons->id;
                $userCoupon->order  = $orderID;
                $userCoupon->save();

                $usedCoupun = $coupons->used_coupon();
                if($coupons->limit <= $usedCoupun)
                {
                    $coupons->is_active = 0;
                    $coupons->save();
                }

            }
            $assignPlan = $user->assignPlan($plan->id);
            if($assignPlan['is_success'])
            {
                return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
            }else {
                return redirect()->back()->with('error', __($assignPlan['error']));
            }
        } else {
            return redirect()->route('plan.index')->with('error', __('Your Payment has failed!'));
        }
    }

    public function failure(){
        return redirect()->route('plan.index')->with('error', __('Your payment has failed.'));
    }

    public function Transactionfailure($slug){
        return redirect()->route('checkout', $slug)->with('error', __('Transaction has been failed!'));
    }

    /**
     * RECOMPUTE THE PRICE SERVER-SIDE AND REFUSE A RETURN THAT CLAIMS A DIFFERENT ONE.
     *
     * THE DEFECT THIS CLOSES (P0, revenue bypass). success() took BOTH the plan and the
     * price from the query string and then hardcoded `payment_status = 'success'` before
     * calling assignPlan(), with no verification against the provider and no signature.
     * One authenticated GET to this route therefore activated any paid plan for zero:
     * `?plan_id=<id>&amount=0` (eSewa additionally supplied its own `status=success`,
     * which is attacker-controlled like everything else on the URL). The replayable URL
     * is published by this controller's own addpayment(), so a merchant who starts one
     * legitimate checkout holds every parameter needed to replay it.
     *
     * WHY AN AMOUNT CHECK IS THE RIGHT FIX HERE AND CANNOT BREAK A REAL PAYMENT: the
     * amount on a legitimate return is the one THIS SERVER put into the success_url in
     * addpayment(), computed from $plan->price and a validated coupon. Recomputing it and
     * comparing is therefore a no-op for every genuine return, and it rejects the
     * zero-amount replay outright.
     *
     * THE COUPON IS LOOKED UP WITHOUT `is_active` ON PURPOSE. addpayment() deactivates a
     * coupon once its limit is reached, so a coupon that was valid when checkout started
     * can be inactive by the time the provider returns. Filtering on is_active here would
     * recompute a HIGHER price than the server itself advertised and 403 a paid customer.
     * The coupon still has to exist, so this cannot be used to invent a discount.
     *
     * WHAT THIS DOES NOT DO, AND MUST NOT BE READ AS DOING: it does not verify that any
     * money moved. No provider is reachable from this environment, so the gateway contract
     * is unverified and that finding stays open. This closes the zero-cost replay; it does
     * not make the integration trustworthy.
     */
    private function priceFromPlanOrFail(Plan $plan, Request $request): float
    {
        $price = (float) $plan->price;

        if ($request->filled('coupon_code')) {
            $coupon = PlanCoupon::where('code', strtoupper($request->coupon_code))->first();

            if (! empty($coupon)) {
                $price = $price - (((float) $plan->price / 100) * (float) $coupon->discount);
            }
        }

        $price = round(max($price, 0.0), 2);

        if ($request->has('amount')) {
            $claimed = round((float) $request->amount, 2);

            // 0.009 rather than an equality test: these amounts travel through a query
            // string and back, so a half-cent of float noise must not 403 a real payment.
            if (abs($claimed - $price) > 0.009) {
                \Log::warning('Plan payment return rejected: claimed amount does not match the plan price.', [
                    'controller' => static::class,
                    'plan_id'    => $plan->id,
                    'claimed'    => $claimed,
                    'expected'   => $price,
                    'user_id'    => optional(\Auth::user())->id,
                ]);

                abort(403, __('The payment amount does not match the plan price.'));
            }
        }

        return $price;
    }
}
