<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use App\Models\PlanUserCoupon;
use Illuminate\Http\Request;
use App\Http\Controllers\Concerns\GuardsPlanActivation;

class PaddlePaymentController extends Controller
{
    use GuardsPlanActivation;

    //

    public function paymentConfig()
    {
        $admin_settings = getSuperAdminAllSetting();
        $companyPaddleMode = !empty($admin_settings['paddle_mode']) ? $admin_settings['paddle_mode'] : true;
        $isSandboxMode = ($companyPaddleMode === 'sandbox');
        config(
            [
                'cashier.vendor_id' => !empty($admin_settings['paddle_vendor_id']) ? $admin_settings['paddle_vendor_id'] : '',
                'cashier.vendor_auth_code' => !empty($admin_settings['paddle_vendor_auth_code']) ? $admin_settings['paddle_vendor_auth_code'] : '',
                'cashier.public_key' => !empty($admin_settings['paddle_public_key']) ? $admin_settings['paddle_public_key'] : '',
                'cashier.sandbox' => $isSandboxMode,
            ]
        );
    }

    public function planPayWithPaddle(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $this->paymentConfig();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);

        $plan = Plan::find($planID);
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $user = \Auth::user();

        if ($plan) {
            try {

                $price = $plan->price;
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
                            $price  = $price - $discount_value;
                            if ($price < 0) {
                                $price = $plan->price;
                            }
                            $coupon_id = $coupons->id;
                        }
                    }else {
                        return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                    }
                }
                
                $priceInCents = (int) ($price);
                $planName = !empty($plan->name) ? $plan->name : 'Basic Package';

                $paylink = $user->charge($priceInCents, $planName, [
                    'quantity_variable' => 0,
                    'discountable' => 0,
                    'customer_email' => $user->email,
                    'webhook_url' => route('plan.get.paddle.status', ['plan_id' => $plan->id,'coupon_code'=>$request->coupon,'amount' => $price,]),
                    'return_url' => route('plan.get.paddle.status', ['plan_id' => $plan->id,'coupon_code'=>$request->coupon,'amount' => $price,]),
                ]);
                return redirect($paylink);


            } catch (\Exception $e) {
                return redirect()->route('plan.index')->with('error', __($e->getMessage()));
            }
        }
        else{
            return redirect()->back()->with('error',__('Something went wrong.'));
        }
    }

    public function planGetStatus()
    {
        return redirect()->route('plan.index')->with('success', __('Please wait for some while.'));
    }

    /**
     * P0 (free activation). NO GATE, AND IT WRITES A PLAN_ORDERS ROW WITH A NULL PLAN.
     *
     * This is the weakest of the set. There is no verification of any kind, and because the
     * order fields are written as `$plan->name ?? null` and `$plan->id ?? null`, a request
     * naming a non-existent plan persisted a PlanOrder with plan_id NULL and plan_name NULL
     * before dying on `$plan->id` in the assignPlan() call below -- a corrupt revenue row from
     * an unauthenticated-in-effect parameter.
     *
     * Activation is refused until a signature-verified Paddle webhook drives it rather than a
     * browser return; see config/payments.php. The plan is now resolved before anything is
     * written and a missing one is a 404, so the NULL-plan row can no longer be created.
     */
    public function planGetPaddleStatus(Request $request)
    {
        $plan = Plan::find($request->plan_id);

        $this->assertPlanActivationEnabled('paddle', $plan);

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
        $order->plan_name = $plan->name ?? null;
        $order->plan_id = $plan->id ?? null;
        $order->price = $getAmount;
        $order->price_currency = $currency;
        $order->txn_id = time();
        $order->payment_type = __('Paddle');
        $order->payment_status = 'success';
        $order->txn_id = '';
        $order->receipt = '';
        $order->user_id = $user->id;
        $order->save();

        // Portable and case-exact; see GuardsPlanActivation::findPlanCouponExact().
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
}
