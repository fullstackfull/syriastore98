<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use AWS\CRT\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PaytmWallet;


use App\Http\Controllers\Concerns\GuardsPlanActivation;

class PaytmPaymentController extends Controller
{
    use GuardsPlanActivation;

    public $currancy;

    public function paymentConfig()
    {
        if (\Auth::check()) {
            $payment_setting = getSuperAdminAllSetting();
            config(
                [
                    'services.paytm-wallet.env' => isset($payment_setting['paytm_mode']) ? $payment_setting['paytm_mode'] : '',
                    'services.paytm-wallet.merchant_id' => isset($payment_setting['paytm_merchant_id']) ? $payment_setting['paytm_merchant_id'] : '',
                    'services.paytm-wallet.merchant_key' => isset($payment_setting['paytm_merchant_key']) ? $payment_setting['paytm_merchant_key'] : '',
                    'services.paytm-wallet.merchant_website' => 'WEBSTAGING',
                    'services.paytm-wallet.channel' => 'WEB',
                    'services.paytm-wallet.industry_type' => isset($payment_setting['paytm_industry_type']) ? $payment_setting['paytm_industry_type'] : '',
                ]
            );
        }

    }
    public function planPayWithPaytm(Request $request)
    {

        $this->paymentConfig();

        $planID         = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan           = Plan::find($planID);
        $authuser       = Auth::user();
        $coupons_id = '';
        if ($plan) {
         try{
            /* Check for code usage */
            $plan->discounted_price = false;
            $price                  = $plan->price;

            $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
            $admin_payments_details = getSuperAdminAllSetting();
            $CURRENCY = !empty($admin_payments_details['CURRENCY_NAME']) ? $admin_payments_details['CURRENCY_NAME'] : 'USD';
            if (!empty($coupons))
            {
                $usedCoupun     = $coupons->used_coupon();
                $discount_value = ($plan->price / 100) * $coupons->discount;
                $price          = $plan->price - $discount_value;
                if ($coupons->limit == $usedCoupun) {
                    return redirect()->back()->with('error', __('This coupon code has expired.'));
                }
                $coupon_id = $coupons->id;


                if ($price < 1) {
                    $order_id = strtoupper(str_replace('.', '', uniqid('', true)));
                    $statuses = 'success';
                    if ($coupon_id != '') {


                        $userCoupon         = new PlanUserCoupon();
                        $userCoupon->user_id   = $authuser->id;
                        $userCoupon->coupon_id = $coupons->id;
                        $userCoupon->order  = $order_id;
                        $userCoupon->save();
                        $usedCoupun = $coupons->used_coupon();
                        if ($coupons->limit <= $usedCoupun) {
                            $coupons->is_active = 0;
                            $coupons->save();
                        }
                    }
                }
                if($price <= 0)
                {

                    $authuser->plan = $plan->id;
                    $authuser->save();

                    $assignPlan = $authuser->assignPlan($plan->id);

                    if ($assignPlan['is_success'] == true && !empty($plan)) {
                        if (!empty($authuser->payment_subscription_id) && $authuser->payment_subscription_id != '') {
                            try {
                                $authuser->cancel_subscription($authuser->id);
                            } catch (\Exception $exception) {
                                Log::debug($exception->getMessage());
                            }
                        }

                        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
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
                                'price' => $price == null ? 0 : $price,
                                'price_currency' => $CURRENCY,
                                'txn_id' => '',
                                'payment_type' => 'Paytm',
                                'payment_status' => 'succeeded',
                                'receipt' => null,
                                'user_id' => $authuser->id,
                            ]
                        );
                        $res['msg'] = __("Plan successfully upgraded.");
                        $res['flag'] = 2;
                        return $res;
                    } else {
                        return redirect()->back()->with(__('Plan fail to upgrade.'));
                    }
                }
            }

            try {
                $call_back = route('plan.paytm', [$request->plan_id]);
                $payment = PaytmWallet::with('receive');
                $payment->prepare(
                    [
                        'order' => date('Y-m-d') . '-' . strtotime(date('Y-m-d H:i:s')),
                        'user' => Auth::user()->id,
                        'mobile_number' => $request->mobile_number,
                        'email' => Auth::user()->email,
                        'amount' => $price,
                        'plan' => $plan->id,
                        'callback_url' => $call_back
                        ]
                    );
                return $payment->receive();

            } catch (\Exception $e) {
                return redirect()->route('plan.index')->with('error', __($e->getMessage()));
            }
        }
        catch (\Exception $e) {
            return redirect()->route('plan.index')->with('error', __($e->getMessage()));
        }

        } else {
            return redirect()->back()->with(__('Plan is deleted.'));
        }
    }
    public function getPaymentStatus(Request $request, $plan, $coupon = null)
    {

        $transaction = $this->paymentConfig();
        $planID  = \Illuminate\Support\Facades\Crypt::decrypt($plan);
        $plan    = Plan::find($planID);
        $user   = Auth::user();
        $orderID = time();

        /*
         * AN UNCONFIGURED MERCHANT KEY MUST REFUSE, NOT VERIFY AGAINST THE EMPTY STRING.
         *
         * `paymentConfig()` at :31 passes `isset(...) ? ... : ''`, so an installation with no
         * `paytm_merchant_key` row verifies `CHECKSUMHASH` against `''` -- a key the caller knows
         * as well as the server does. MEASURED by driving this route: a self-minted `TXN_SUCCESS`
         * body signed with the empty key wrote one plan order with `payment_status = 'success'`
         * and assigned a 499 plan, with no Paytm account involved anywhere. That is a free paid
         * plan on every installation that has not configured Paytm, which is every installation
         * that does not use it.
         *
         * An installation that HAS configured Paytm is unaffected, and one that has not cannot
         * take a Paytm payment anyway -- so this fails closed in the only direction that exists.
         */
        $paytmMerchantKey = trim((string) config('services.paytm-wallet.merchant_key'));

        if ($paytmMerchantKey === '') {
            \Illuminate\Support\Facades\Log::warning('Paytm plan return refused: no merchant key is configured, so the checksum proves nothing.', [
                'plan_id' => $plan->id ?? null,
                'user_id' => $user->id ?? null,
            ]);

            return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
        }

        if ($plan) {
            try {
                $transaction = PaytmWallet::with('receive');
                $response    = $transaction->response();

                if ($transaction->isSuccessful()) {

                    /*
                     * ONE PAYTM TRANSACTION, ONE PLAN ACTIVATION -- and here the guard runs AFTER
                     * the checksum, unlike Benefit, Cashfree and Mollie.
                     *
                     * Those three guard first because the reference is what they are about to
                     * spend an outbound call on. Paytm's verification is local and free (an AES
                     * check against the server-held key, no network), so verifying first costs
                     * nothing and denies a caller the chance to probe which transaction ids exist.
                     *
                     * MEASURED: a valid checksum stays valid on a re-POST, so the cryptography is
                     * no defence against replay. Driving this route twice with the identical
                     * signed body wrote TWO rows carrying one `txn_id`. `plan_orders.order_id` is
                     * UNIQUE and blocks a same-second repeat, but `$orderID` is `time()` -- so
                     * that constraint keys on the clock, not on anything the provider said, and
                     * two posts a second apart both landed.
                     */
                    $paytmReference = $request->TXNID;
                    $paytmReplay = $this->refusePlanActivationReplay('Paytm', $paytmReference, $plan, $user);

                    if ($paytmReplay !== null) {
                        return $paytmReplay;
                    }

                    if(!empty($request->coupon))
                    {
                        $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                        if(!empty($coupons))
                        {
                            $usedCoupun     = $coupons->used_coupon();
                            $discount_value = ($plan->price / 100) * $coupons->discount;
                            $price          = $plan->price - $discount_value;

                            if($coupons->limit == $usedCoupun)
                            {
                                return redirect()->back()->with('error', __('This coupon code has expired.'));
                            }
                        }
                        else
                        {
                            return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                        }
                    }

                    $order                 = new PlanOrder();


                    $order->order_id       = $orderID;
                    $order->name           = isset($user->name) ? $user->name : '';
                    $order->card_number    = '';
                    $order->card_exp_month = '';
                    $order->card_exp_year  = '';
                    $order->plan_name      = $plan->name;
                    $order->plan_id        = $plan->id;
                    // Server-owned: was `isset($request->TXNAMOUNT) ? $request->TXNAMOUNT : 0`.
                    // This handler ALREADY computed the correct price server-side a few lines
                    // above ($price = $plan->price - $discount_value) and then discarded it in
                    // favour of the posted TXNAMOUNT. Paytm IS verified on this path
                    // ($transaction->isSuccessful()), so only the RECORDED price changes.
                    $order->price          = $this->serverOwnedPlanPrice($plan, $request->coupon);
                    $order->price_currency = isset($request->CURRENCY_CODE) ? $request->CURRENCY_CODE : 'NFD';
                    // The same variable the guard was keyed on, so the recorded value cannot drift
                    // away from the looked-up one. See the structural assertion in
                    // PlanActivationReplayGuardTest.
                    $order->txn_id         = (string) $paytmReference;
                    $order->payment_type   = __('paytm');
                    $order->payment_status = 'success';
                    $order->receipt        = '';
                    $order->user_id        = $user->id;
                    $order->save();
                    $assignPlan = $user->assignPlan($plan->id, $request->payment_frequency);

                    if(!empty($request->coupon))
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
                    if ($assignPlan['is_success']) {
                        return redirect()->route('plan.index')->with('success', __('Plan activated Successfully!'));
                    } else {
                        return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                    }
                } else {
                    return redirect()->route('plan.index')->with('error', __('Transaction has been failed! '));
                }
            } catch (\Exception $e) {

                return redirect()->route('plan.index')->with('error', __('Plan not found!'));
            }
        }
    }

}
