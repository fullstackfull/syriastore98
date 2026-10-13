<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use App\Models\PlanUserCoupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lahirulhr\PayHere\PayHere;
use App\Http\Controllers\Concerns\GuardsPlanActivation;

class PayHerePaymnetController extends Controller
{
    use GuardsPlanActivation;

    //

    public function planPayWithPayHere(Request $request)
    {
        try {
        $user = User::find(\Auth::user()->id);
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);

        $plan = Plan::find($planID);
        $admin_settings = getSuperAdminAllSetting();
        $admin_currancy = !empty($admin_settings['CURRENCY_NAME']) ? $admin_settings['CURRENCY_NAME'] : 'LKR';
        $authuser = Auth::user();
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        if ($plan) {
            /* Check for code usage */
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


                $config = [
                    'payhere.api_endpoint' => $admin_settings['payhere_mode'] === 'sandbox'
                        ? 'https://sandbox.payhere.lk/'
                        : 'https://www.payhere.lk/',
                ];
                $config['payhere.merchant_id'] = $admin_settings['payhere_merchant_id'] ?? '';
                $config['payhere.merchant_secret'] = $admin_settings['payhere_merchant_secret'] ?? '';
                $config['payhere.app_secret'] = $admin_settings['payhere_app_secret'] ?? '';
                $config['payhere.app_id'] = $admin_settings['payhere_app_id'] ?? '';
                config($config);

                $hash = strtoupper(
                    md5(
                        $admin_settings['payhere_merchant_id'] .
                            $orderID .
                            number_format($price, 2, '.', '') .
                            'LKR' .
                            strtoupper(md5($admin_settings['payhere_merchant_secret']))
                    )
                );
                $data = [
                    'first_name' => $user->name,
                    'last_name' => '',
                    'email' => $user->email,
                    'phone' => $user->mobile_no ?? '',
                    'address' => 'Main Rd',
                    'city' => 'Anuradhapura',
                    'country' => 'Sri lanka',
                    'order_id' => $orderID,
                    'items' => $plan->name,
                    'currency' => $admin_currancy,
                    'amount' => $price,
                    'hash' => $hash,
                ];

                return PayHere::checkOut()
                    ->data($data)
                    ->successUrl(route('plan.get.payhere.status', [
                        $plan->id,
                        'amount' => $price,
                        'coupon_code' => $request->coupon_code,
                    ]))
                    ->failUrl(route('plan.get.payhere.status', [
                        $plan->id,
                        'amount' => $price,
                        'coupon_code' => $request->coupon_code,
                    ]))
                    ->renderView();

        } else {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }
    } catch (\Exception $e) {
        \Log::debug($e->getMessage());
        return redirect()->route('plan.index')->with('error', $e->getMessage());
    }
    }

    /**
     * P0 (free activation). NO GATE -- AND PAYHERE'S OWN SIGNATURE IS COMPUTED, THEN IGNORED.
     *
     * planPayWithPayHere() builds a real PayHere md5 hash at :65-73, folding in the merchant
     * secret, and sends it OUTBOUND. Nothing is checked on the way back. Worse, the SAME route
     * is registered as both successUrl (:91) and failUrl (:96), so a payment PayHere itself
     * declined still landed here and activated the plan.
     *
     * The price came from `$request->get_amount` -- a parameter this controller never even puts
     * on the URL (it sends `amount`), so the recorded price was reliably NULL->0 on a genuine
     * return and whatever the caller chose on a forged one.
     *
     * Activation is refused until the return's md5sig is verified; see config/payments.php for
     * the formula and the re-enable conditions. The recorded price now comes from the plan.
     */
    public function planGetPayHereStatus(Request $request)
    {
        $plan = Plan::find($request->plan);

        $this->assertPlanActivationEnabled('payhere', $plan);

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
        $order->payment_type = __('PayHere');
        $order->payment_status = 'success';
        $order->txn_id = '';
        $order->receipt = '';
        $order->user_id = $user->id;
        $order->save();

        // Portable and case-exact; see GuardsPlanActivation::findPlanCouponExact(). The
        // upstream BINARY form is MySQL-only and ran unconditionally after the order was saved.
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
