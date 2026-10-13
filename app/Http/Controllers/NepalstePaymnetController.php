<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use App\Models\PlanUserCoupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Concerns\GuardsPlanActivation;

class NepalstePaymnetController extends Controller
{
    use GuardsPlanActivation;

    //
    public function planPayWithnepalste(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $api_key = isset($payment_setting['nepalste_public_key']) ? $payment_setting['nepalste_public_key'] : '';
        $nepalste_mode = isset($payment_setting['nepalste_mode']) ? $payment_setting['nepalste_mode'] : '';
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);

        $plan = Plan::find($planID);
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $user = Auth::user();

        if ($plan) {
            $get_amount = $plan->price;
            if (!empty($request->coupon))
            {
                $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                if ($coupons) {
                    $coupon_code = $coupons->code;
                    $usedCoupun     = $coupons->used_coupon();
                    if ($coupons->limit == $usedCoupun) {
                        $res_data['error'] = __('This coupon code has expired.');
                    } else {
                        $discount_value = ($plan->price / 100) * $coupons->discount;
                        $price  = $plan->price - $discount_value;
                        if ($price < 0) {
                            $price = $plan->price;
                        }
                        $coupon_id = $coupons->id;
                    }
                }else {
                    return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                }
            }
        }
        if (!empty($request->coupon))
        {
            $response = ['get_amount' => $get_amount, 'plan' => $plan , 'coupon_code'=>$coupon_code];
        }
        else{
            $response = ['get_amount' => $get_amount, 'plan' => $plan];
        }
        $parameters = [
            'identifier' => 'DFU80XZIKS',
            'currency' => $currency,
            'amount' => $get_amount,
            'details' => $plan->name,
            'ipn_url' => route('nepalste.status',$response),
            'cancel_url' => route('nepalste.cancel'),
            'success_url' => route('nepalste.status',$response),
            'public_key' => $api_key,
            'site_logo' => 'https://nepalste.com.np/assets/images/logoIcon/logo.png',
            'checkout_theme' => 'dark',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@mail.com',
        ];
        if($nepalste_mode == 'live')
        {
            //live end point
            $url = "https://nepalste.com.np/payment/initiate";
        }else{
            //test end point
            $url = "https://nepalste.com.np/sandbox/payment/initiate";
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS,  $parameters);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($result, true);

        if(isset($result['success'])){
            return redirect($result['url']);
        }else{
            return redirect()->back()->with('error',isset($result['message']) ? $result['message'] : 'Something went wrong');
        }
    }

    /**
     * P0 (free activation). THIS HANDLER HAD NO GATE AT ALL.
     *
     * planPayWithnepalste() publishes route('nepalste.status') as BOTH the success_url and the
     * ipn_url (:62, :64), with the plan and the amount on the query string. A GET to it created
     * the PlanOrder and called assignPlan() unconditionally, pricing the order from
     * `$request->get_amount` -- so `?plan=<any>&get_amount=0` activated any paid plan for zero,
     * and the URL needed to do it is handed to the merchant by their own first checkout.
     *
     * TWO THINGS CHANGED. Activation is refused outright until Nepalste's return can actually be
     * verified (config/payments.php names the re-enable conditions), and the recorded price is
     * recomputed from the plan so that a forged amount cannot be recorded even once the owner
     * re-enables the provider.
     *
     * ORDER MATTERS: the guard runs before the PlanOrder is built, so a refusal leaves no row.
     */
    public function planGetNepalsteStatus(Request $request)
    {
        $plan = Plan::find($request->plan);

        $this->assertPlanActivationEnabled('nepalste', $plan);

        // Upstream reached $plan->name on a missing plan and 500ed with a half-built order in
        // scope. A 404 is the honest answer and it happens before any write.
        if (empty($plan)) {
            abort(404, __('Plan not found.'));
        }

        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

        $getAmount = $this->serverOwnedPlanPrice($plan, $request->coupon_code);
        $coupanCode = $request->coupon_code;
        $user = \Auth::user();

        $order = new PlanOrder();
        $order->order_id = $orderID;
        $order->name = $user->name;
        $order->card_number = '';
        $order->card_exp_month = '';
        $order->card_exp_year = '';
        $order->plan_name = $plan->name;
        $order->plan_id = $plan->id;
        $order->price = $getAmount;
        $order->price_currency = $currency;
        $order->txn_id = time();
        $order->payment_type = __('Nepalste');
        $order->payment_status = 'success';
        $order->txn_id = '';
        $order->receipt = '';
        $order->user_id = $user->id;
        $order->save();

        // Portable, case-exact, and skipped entirely for an empty code. The upstream
        // `whereRaw('BINARY `code` = ?')` is MySQL-only: on sqlite it is a syntax error, and
        // this query ran UNCONDITIONALLY right after the order was saved -- so on any
        // non-MySQL driver the order was written and the handler then threw, before
        // assignPlan(). See GuardsPlanActivation::findPlanCouponExact().
        $coupons = $this->planCouponForRecording($coupanCode);
        if (!empty($coupons)) {
            $userCoupon         = new PlanUserCoupon();
            $userCoupon->user_id   = $user->id;
            $userCoupon->coupon_id = $coupons->id;
            $userCoupon->order  = $order->order_id;
            $userCoupon->save();
            $usedCoupun = $coupons->used_coupon();
            if ($coupons->limit <= $usedCoupun) {
                $coupons->is_active = 0;
                $coupons->save();
            }
        }

        $assignPlan = $user->assignPlan($plan->id);
        if ($assignPlan['is_success'])
        {
            return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
        } else
        {
            return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
        }
    }

    public function planGetNepalsteCancel(Request $request)
    {
        return redirect()->back()->with('error',__('Transaction has failed'));
    }

}
