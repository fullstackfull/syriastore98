<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Plan;
use GuzzleHttp\Client;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SSLCommerzPaymentController extends Controller
{
    public function planPayWithSSLCommerz(Request $request)
{
    $user                   = Auth::user();
    $planID                 = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
    $plan                   = Plan::find($planID);
    $payment_setting        = getSuperAdminAllSetting();
    $currency               = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'BDT';
    $duration               = !empty($request->time_period) ? $request->time_period : 'Month';
    $order_id               = strtoupper(str_replace('.', '', uniqid('', true)));

    if (!in_array($currency, ['BDT', 'USD', 'EUR', 'GBP', 'AUD', 'INR'])) {
        return redirect()->route('plan.index')->with('error', __('Available currencies: BDT, USD, EUR, GBP, AUD, INR'));
    }

    if ($plan) {
        try {
            $price =  $plan->price;
            if (!empty($request->coupon)) {
                $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                if ($coupons) {
                    $coupon_code = $coupons->code;
                    $usedCoupun = $coupons->used_coupon();
                    if ($coupons->limit == $usedCoupun) {
                        return redirect()->route('plan.index')->with('error', __('This coupon code has expired.'));
                    } else {
                        $discount_value = ($plan->price / 100) * $coupons->discount;
                        $price = max(0, $price - $discount_value);
                        $coupon_id = $coupons->id;
                    }
                } else {
                    return redirect()->route('plan.index')->with('error', __('This coupon code is invalid or has expired.'));
                }
            }
        } catch (\Exception $e) {
            return redirect()->route('plan.index')->with('error', __($e->getMessage()));
        }
    }

    $sslcommerz_pay_store_id = $payment_setting['sslcommerz_pay_store_id'] ?? '';
    $sslcommerz_pay_secret_key = $payment_setting['sslcommerz_pay_secret_key'] ?? '';

    $sslcommerz_data = [
            "store_id"          => $sslcommerz_pay_store_id,
            "store_passwd"      => $sslcommerz_pay_secret_key,
            "total_amount"      => $price,
            "currency"          => $currency,
            "tran_id"           => $order_id,
            "success_url"       => route('plan.get.sslcommerz.status', [
                $plan->id,
                'amount'       => $price,
                'duration'     => $duration,
                'coupon_code'  => $coupon_id ?? '',
                'success'      => 1
            ]),
            "fail_url"          => route('plan.get.sslcommerz.status', [
                $plan->id
            ]),
            "cancel_url"        => route('plan.get.sslcommerz.status', [
                $plan->id
            ]),
            "cus_name"          => $user->name,
            "cus_email"         => $user->email,
            "cus_add1"          => '',
            "cus_add2"          => '',
            "cus_city"          => '',
            "cus_country"       => '',
            "cus_phone"         => '1234567890',
            "cus_postcode"      => '',
            "product_profile"   => 'plan_purchase',
            "product_name"      => $plan->name ? $plan->name : ' ',
            "product_category"  => 'Subscription',
            "ship_name"         => $user->name,
            'ship_add1'         => 'Dhaka',
            'ship_add2'         => '',
            'ship_city'         => 'Dhaka',
            'ship_state'        => '',
            'ship_postcode'     => '1000',
            'ship_country'      => 'Bangladesh',
            'shipping_method'   => 'yes'
        ];

         try {
        $url = $payment_setting['sslcommerz_mode'] == 'sandbox' ? 
            'https://sandbox.sslcommerz.com/gwprocess/v4/api.php' : 
            'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php';

        $client = new Client();
        $response = $client->post($url, [
            'form_params' => $sslcommerz_data,
            'timeout' => 30,
            'connect_timeout' => 30,
            'verify' => false,
        ]);

        $body = $response->getBody();
        $sslcz = json_decode($body, true);

        if (isset($sslcz['status']) && $sslcz['status'] === "SUCCESS") {
            PlanOrder::create([
                'order_id'          => $order_id,
                'user_id'           => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'card_number'       => null,
                'card_exp_month'    => null,
                'card_exp_year'     => null,
                'plan_name'         => $plan->name ?? ' ',
                'plan_id'           => $plan->id,
                'price'             => $price,
                'price_currency'    => $currency,
                'txn_id'            => '',
                'payment_type'      => __('SSLCommerz'),
                'payment_status'    => 'Failed', 
                'receipt'           => null,
            ]);

            return redirect()->to($sslcz['GatewayPageURL']);
        } else {
            return redirect()->route('plan.index')->with('error', __('Transaction has been failed.'));
        }
    } catch (\Exception $e) {
        Log::error($e->getMessage());
        return redirect()->route('plan.index')->with('error', __('Something went wrong, Please try again.'));
    }
}
    public function planGetSSLCommerzStatus(Request $request, $plan_id)
    {
        if (!$request->success == 0) {
            $plan = Plan::find($plan_id);
            $user = Auth::user();
            $order_id = $request['tran_id'];
            $coupanCode = $request->coupon_code;

            if ($plan) {
                try {
                    $Order = PlanOrder::where('order_id', $order_id)->first();
                    $Order->payment_status = 'succeeded';
                    $Order->save();
                    $user       = User::find($user->id);
                    $assignPlan = $user->assignPlan($plan->id, $request->duration, $request->user_module, $request->counter);

                    $coupons = PlanCoupon::where('id' ,  $coupanCode)->where('is_active', '1')->first();

                    if (!empty($coupons)) {
                        $userCoupon         = new PlanUserCoupon();
                        $userCoupon->user_id   = $user->id;
                        $userCoupon->coupon_id = $coupons->id;
                        $userCoupon->order  = $order_id;
                        $userCoupon->save();

                        $usedCoupun = $coupons->used_coupon();
                        if ($coupons->limit <= $usedCoupun) {
                            $coupons->is_active = 0;
                            $coupons->save();
                        }
                    }

                    $assignPlan = $user->assignPlan($plan->id);
                    if ($assignPlan['is_success']) {
                        return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
                    } else {
                        return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                    }
                } catch (\Exception $e) {
                    return redirect()->route('plan.index')->with('error', __('Transaction has been failed.'));
                }
            } else {
                return redirect()->route('plan.index')->with('error', __('Plan is not found.'));
            }
        } else {
            return redirect()->route('plan.index')->with('error', __('Transaction has been failed.'));
        }
    }
}
