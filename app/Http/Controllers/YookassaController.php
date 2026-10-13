<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\ApiController;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderBillingDetail;
use App\Models\OrderCouponDetail;
use App\Models\OrderTaxDetail;
use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\UserCoupon;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Session;
use YooKassa\Client;

use App\Models\OrderNote;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use App\Http\Controllers\Concerns\GuardsPlanActivation;

class YookassaController extends Controller
{
    use GuardsPlanActivation;

    //
    public function paywithyookassa(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan = Plan::find($planID);
        $user = \Auth::user();
        if ($plan) {

            $get_amount = $plan->price;
            try {
                if (!empty($request->coupon)) {
                    $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                    if (!empty($coupons)) {
                        $usedCoupun = $coupons->used_coupon();
                        $discount_value = ($plan->price / 100) * $coupons->discount;
                        $get_amount = $plan->price - $discount_value;

                        if ($coupons->limit == $usedCoupun) {
                            return redirect()->back()->with('error', __('This coupon code has expired.'));
                        }
                        if ($get_amount <= 0) {
                            $authuser = \Auth::user();
                            $authuser->plan = $plan->id;
                            $authuser->save();
                            $assignPlan = $authuser->assignPlan($plan->id);
                            if ($assignPlan['is_success'] == true && !empty($plan)) {
                                if (!empty($authuser->payment_subscription_id) && $authuser->payment_subscription_id != '') {
                                    try {
                                        $authuser->cancel_subscription($authuser->id);
                                    } catch (\Exception $exception) {
                                        \Log::debug($exception->getMessage());
                                    }
                                }
                                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
                                $userCoupon = new PlanUserCoupon();
                                $userCoupon->user_id = $authuser->id;
                                $userCoupon->coupon_id = $coupons->id;
                                $userCoupon->order = $orderID;
                                $userCoupon->save();
                                PlanOrder::create(
                                    [
                                        'order_id' => $orderID,
                                        'name' => null,
                                        'email' => null,
                                        'card_number' => null,
                                        'card_exp_month' => null,
                                        'card_exp_year' => null,
                                        'plan_name' => $plan->name,
                                        'plan_id' => $plan->id,
                                        'price' => $get_amount == null ? 0 : $get_amount,
                                        'price_currency' => !empty($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD',
                                        'txn_id' => '',
                                        'payment_type' => 'Cashfree',
                                        'payment_status' => 'success',
                                        'receipt' => null,
                                        'user_id' => $authuser->id,
                                    ]
                                );
                                $assignPlan = $authuser->assignPlan($plan->id);
                                return redirect()->route('plan.index')->with('success', __('Plan Successfully Activated'));
                            }
                        }
                    } else {
                        return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                    }
                }
                $coupon = (empty($request->coupon)) ? "0" : $request->coupon;
                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

                try {
                    if(is_int((int)$payment_setting['yookassa_shop_id_key']))
                    {
                        $client = new Client();
                        $client->setAuth((int)$payment_setting['yookassa_shop_id_key'], $payment_setting['yookassa_secret_key']);
                        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
                        $product = 'Basic Package';
                        $payment = $client->createPayment(
                            array(
                                'amount' => array(
                                    'value' => $get_amount,
                                    'currency' => !empty($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD',
                                ),
                                'confirmation' => array(
                                    'type' => 'redirect',
                                    'return_url' => route('plan.get.yookassa.status', ['order_id'=>$orderID,'plan_id'=>$plan->id, 'amount'=>$get_amount,'coupon'=>$coupon]),
                                ),

                                'capture' => true,
                                'description' => 'Заказ №1',
                            ),
                            uniqid('', true)
                        );

                        $type = 'Subscription';

                        Session::put('payment_id',$payment['id']);
                        if($payment['confirmation']['confirmation_url'] != null)
                        {
                            return redirect($payment['confirmation']['confirmation_url']);
                        }
                        else
                        {
                            return redirect()->route('plan.index')->with('error', 'Something went wrong, Please try again');
                        }
                    }
                    else
                    {
                        return redirect()->back()->with('error', 'Please Enter  Valid Shop Id Key');
                    }

                } catch (\Exception $e)
                {
                    return redirect()->route('plan.index')->with('error', __('Incorrect currency of payment.'));
                }
            } catch (\Exception $e) {

                return redirect()->back()->with('error', $e);
            }

        } else {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }


    }

    /**
     * P0 (free activation). THE GATE PROVED A CHECKOUT WAS STARTED, NOT THAT IT WAS PAID.
     *
     * The only check was `\Session::get('payment_id') != null`. paywithyookassa() puts that in
     * the session the moment it CREATES the payment, before the merchant has paid anything -- so
     * a merchant who starts a YooKassa checkout, abandons it, and then hits the return URL got
     * the plan for free. The price came from `$request->amount` on that return URL.
     *
     * A YooKassa\Client IS constructed at :180 and then never asked anything, which is the
     * whole defect in one line: the verification call is missing, not the client.
     *
     * WHY THE VERIFICATION IS NOT IMPLEMENTED HERE, contrary to the expectation that the SDK is
     * vendored and readable. It is NOT installed in this environment:
     *   - `class_exists('YooKassa\Client')` is false at runtime;
     *   - no file under vendor/ declares `namespace YooKassa`;
     *   - vendor/composer/installed.json contains no 'yoomoney' entry,
     * even though composer.lock pins yoomoney/yookassa-sdk-php 3.7.1. So `new Client()` below
     * raises an Error -- which the `catch (\Exception $e)` does NOT catch -- and the SDK's
     * payment-info contract cannot be read, let alone tested, from here. Guessing it is exactly
     * what must not be done with a money API.
     *
     * Activation is therefore refused. The guard runs FIRST, before the unresolvable `new
     * Client()`, so the refusal is a clean 403 rather than a fatal.
     */
    public function planGetYooKassaStatus(Request $request)
    {
        $plan = Plan::find($request->plan_id);

        $this->assertPlanActivationEnabled('yookassa', $plan);

        if (empty($plan)) {
            abort(404, __('Plan not found.'));
        }

        $payment_setting = getSuperAdminAllSetting();
        $user = \Auth::user();
        $couponCode = $request->coupon;
        $getAmount = $this->serverOwnedPlanPrice($plan, $couponCode);
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        // Portable and case-exact; see GuardsPlanActivation::findPlanCouponExact(). Also no
        // longer dereferences ->id on a miss: upstream read $coupons->id unconditionally, so an
        // unknown coupon code 500ed here.
        $coupons = $this->planCouponForRecording($couponCode);
        if ($coupons !== null) {
            $request['coupon_id'] = $coupons->id;
        }

        try {
            if(is_int((int)$payment_setting['yookassa_shop_id_key']))
            {
                $client = new Client();
                $client->setAuth((int)$payment_setting['yookassa_shop_id_key'], $payment_setting['yookassa_secret_key']);
                $paymentId = \Session::get('payment_id');
                \Session::forget('payment_id');

                if($paymentId == null)
                {
                    return redirect()->back()->with('error', __('Transaction Unsuccesfull'));
                }

                $order = new PlanOrder();
                $order->order_id = $orderID;
                $order->name = $user->name;
                $order->card_number = '';
                $order->card_exp_month = '';
                $order->card_exp_year = '';
                $order->plan_name = $plan->name;
                $order->plan_id = $plan->id;
                $order->price = $getAmount;
                $order->price_currency = !empty($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
                $order->payment_type = __('yookassa');
                $order->payment_status = 'success';
                $order->txn_id = '';
                $order->receipt = '';
                $order->user_id = $user->id;
                $order->save();
                $assignPlan = $user->assignPlan($plan->id);
                $coupons = PlanCoupon::find($request->coupon_id);
                if (!empty($request->coupon_id)) {
                    if (!empty($coupons)) {
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
                    }
                }

                if ($assignPlan['is_success']) {
                    return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
                } else {
                    return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                }
            } else {
                return redirect()->route('plan.index')->with('error', 'Payment Failed.');
            }
            return redirect()->route('plan.index')->with('success', 'Plan activated Successfully.');
        } catch (\Exception $e) {
            return redirect()->route('plan.index')->with('error', __($e->getMessage()));
        }

    }
}
