<?php

namespace App\Http\Controllers;

use App\Models\PlanUserCoupon;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Utility;
use Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Concerns\GuardsPlanActivation;

class MercadoPaymentController extends Controller
{
    use GuardsPlanActivation;
    //
    public $token;
    public $is_enabled;
    public $currancy;
    public $mode;
    public $secret_key;
    public $public_key;


    public function paymentConfig()
    {
        if (Auth::check()) {
            $user = Auth::user();
        }

        if ($user->type == 'admin') {
            $payment_setting = getSuperAdminAllSetting();
        } else {
            $payment_setting = Utility::getCompanyPaymentSetting($user);
        }

        $this->token = isset($payment_setting['mercado_access_token']) ? $payment_setting['mercado_access_token'] : '';
        $this->mode = isset($payment_setting['mercado_mode']) ? $payment_setting['mercado_mode'] : '';
        $this->is_enabled = isset($payment_setting['is_mercado_enabled']) ? $payment_setting['is_mercado_enabled'] : 'off';
        return $this;
    }


    public function planPayWithMercado(Request $request)
    {
        $this->paymentConfig();

        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan = Plan::find($planID);
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

        $authuser = Auth::user();
        if ($plan) {
            /* Check for code usage */
            $plan->discounted_price = false;
            $price = $plan->price;
            if (isset($request->coupon) && !empty($request->coupon)) {
                $request->coupon = trim($request->coupon);
                $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                if (!empty($coupons)) {
                    $usedCoupun = $coupons->used_coupon();
                    $discount_value = ($price / 100) * $coupons->discount;
                    $plan->discounted_price = $price - $discount_value;
                    if ($usedCoupun >= $coupons->limit) {
                        return redirect()->back()->with('error', __('This coupon code has expired.'));
                    }
                    $price = $price - $discount_value;
                } else {
                    return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                }
            }
            if ($price <= 0) {
                $authuser->plan = $plan->id;
                $authuser->save();

                $assignPlan = $authuser->assignPlan($plan->id);
                if ($assignPlan['is_success'] == true && !empty($plan)) {

                    $orderID = time();

                    Order::create(
                        [
                            'order_id' => $orderID,
                            'name' => null,
                            'email' => null,
                            'card_number' => null,
                            'card_exp_month' => null,
                            'card_exp_year' => null,
                            'plan_name' => $plan->name,
                            'plan_id' => $plan->id,
                            'price' => $price == null ? 0 : $price,
                            'price_currency' => !empty($this->currancy) ? $this->currancy : 'USD',
                            'txn_id' => '',
                            'payment_type' => 'Mercado',
                            'payment_status' => 'succeeded',
                            'receipt' => null,
                            'user_id' => $authuser->id,
                        ]
                    );
                    $res['msg'] = __("Plan successfully upgraded.");
                    $res['flag'] = 2;

                    return $res;
                } else {
                    return Utility::error(['message' => __('Plan fail to upgrade.')]);
                }
            }

            $payment_setting = getSuperAdminAllSetting();
            $this->token = isset($payment_setting['mercado_access_token']) ? $payment_setting['mercado_access_token'] : '';
            $this->mode = isset($payment_setting['mercado_mode']) ? $payment_setting['mercado_mode'] : '';
            $this->is_enabled = isset($payment_setting['is_mercado_enabled']) ? $payment_setting['is_mercado_enabled'] : 'off';
            try {
                $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';

                $successUrl = 'https://yourdomain.com/plan/mercado/' . $plan->id . '?flag=success';
                $failureUrl = 'https://yourdomain.com/plan/mercado/' . $plan->id . '?flag=failure';
                $pendingUrl = 'https://yourdomain.com/plan/mercado/' . $plan->id . '?flag=pending';

                $paymentData = [
                    "items" => [
                        [
                            "title" => "Plan: " . $plan->name,
                            "quantity" => 1,
                            "unit_price" => (float) $price,
                            "currency_id" => $currency,
                        ]
                    ],
                    "payer" => ["email" => $authuser->email],
                    "back_urls" => [
                        "success" => $successUrl,
                        "failure" => $failureUrl,
                        "pending" => $pendingUrl,
                    ],
                    "auto_return" => "approved"
                ];

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json',
                ])->send('POST', 'https://api.mercadopago.com/checkout/preferences', [
                            'body' => json_encode($paymentData, JSON_UNESCAPED_SLASHES),
                        ]);

                $data = $response->json();
                $initPoint = $this->mode == 'live' ? $data['init_point'] : $data['sandbox_init_point'];
                return redirect($initPoint);

            } catch (\Exception $e) {
                return redirect()->back()->with('error', $e->getMessage());
            }

        } else {
            return redirect()->back()->with('error', 'Plan is deleted.');
        }
    }

    public function getPaymentStatus(Request $request, $plan)
    {
        // FAIL CLOSED FIRST. The gate below is `$request->status == 'approved' &&
        // $request->flag == 'success'` -- two query parameters -- and this route takes the plan
        // id UNENCRYPTED. See config/payments.php.
        $this->assertPlanActivationEnabled('mercado', $plan);

        $this->paymentConfig();
        $planID = $plan;
        $plan = Plan::find($planID);
        $user = Auth::user();
        $orderID = time();
        if ($plan) {
            $price = $plan->price;

            if ($plan && $request->has('status')) {

                if ($request->status == 'approved' && $request->flag == 'success') {
                    if (!empty($user->payment_subscription_id) && $user->payment_subscription_id != '') {
                        try {
                            $user->cancel_subscription($user->id);
                        } catch (\Exception $e) {
                            return redirect()->back()->with('error', $e->getMessage());
                        }
                    }
                    if ($request->has('coupon_id') && $request->coupon_id != '') {
                        $coupons = PlanCoupon::find($request->coupon_id);

                        if (!empty($coupons)) {

                            $discount_value = ($price / 100) * $coupons->discount;

                            $userCoupon = new PlanUserCoupon();
                            $userCoupon->user_id = $user->id;
                            $userCoupon->coupon_id = $coupons->id;
                            $userCoupon->order = $orderID;
                            $userCoupon->save();

                            $usedCoupun = $coupons->used_coupon();
                            if ($coupons->limit <= $usedCoupun) {
                                $coupons->is_active = 0;
                                $coupons->save();
                            }
                            $price = $price - $discount_value;
                        }
                    }
                    $order = new PlanOrder();
                    $order->order_id = $orderID;
                    $order->name = $user->name;
                    $order->card_number = '';
                    $order->card_exp_month = '';
                    $order->card_exp_year = '';
                    $order->plan_name = $plan->name;
                    $order->plan_id = $plan->id;
                    $order->price = $price == null ? 0 : $price;
                    $order->price_currency = isset($this->currancy) ? $this->currancy : '';
                    $order->txn_id = $request->has('preference_id') ? $request->preference_id : '';
                    $order->payment_type = 'Mercado Pago';
                    $order->payment_status = 'succeeded';
                    $order->receipt = '';
                    $order->user_id = $user->id;
                    $order->save();
                    $assignPlan = $user->assignPlan($plan->id, $request->payment_frequency);
                    if ($assignPlan['is_success']) {
                        return redirect()->route('plan.index')->with('success', __('Plan activated Successfully!'));
                    } else {
                        return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                    }
                } else {
                    return redirect()->route('plan.index')->with('error', __('Transaction has been failed! '));
                }
            } else {
                return redirect()->route('plan.index')->with('error', __('Transaction has been failed! '));
            }
        }
    }

}
