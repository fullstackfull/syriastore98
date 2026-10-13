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
        // THE INITIATOR CANNOT RUN AT ALL, SO SAY SO INSTEAD OF RAISING A 500.
        //
        // :92 calls PayHere::checkOut(), and Lahirulhr\PayHere\PayHere is absent from vendor/ --
        // measured, not assumed:
        //     php -r '... class_exists("Lahirulhr\PayHere\PayHere")'  =>  false
        //     ls vendor/lahirulhr                                     =>  No such file or directory
        // A missing class raises \Error, which is a Throwable but NOT an \Exception, so the
        // catch below (widened to \Throwable in the same change) never saw it and a merchant who
        // clicked "pay with PayHere" got an uncaught 500.
        //
        // lahirulhr/laravel-payhere is externally blocked rather than merely uninstalled: its
        // pinned commit no longer exists upstream and all eight published versions resolve to
        // two GitHub repositories that answer neither a zipball fetch nor an unauthenticated
        // git ls-remote. So this is the permanent state of this deployment, not a transient
        // vendor gap.
        //
        // This is the same redirect-with-error the method already produces when the plan has
        // been deleted, and it runs BEFORE the coupon lookup, the config() write and the hash
        // computation, so a purchase that cannot proceed leaves no side effect behind.
        //
        // NOT A SECURITY CHANGE, and not a substitute for one: the free-activation gate for
        // this provider is assertPlanActivationEnabled('payhere') on the RETURN handler at
        // :134, where it belongs, and payhere remains shipped-disabled in config/payments.php.
        // An initiator that 500s is a broken checkout, not an open door.
        // PAYHERE IS RETIRED BY OWNER DECISION. REFUSE DELIBERATELY, NOT INCIDENTALLY.
        //
        // The closure decision is that PayHere is not needed, so the integration is withdrawn:
        // lahirulhr/laravel-payhere is gone from composer.json and composer.lock and this
        // subscription path is closed on purpose. The refusal is FIRST and UNCONDITIONAL --
        // it does not depend on the SDK being unloadable, so a tree that vendored the SDK back
        // in would still refuse rather than resume selling plans through a retired gateway.
        //
        // It runs BEFORE the Crypt::decrypt of plan_id, before the coupon lookup, before the
        // config() write and before the hash computation, so a refused purchase performs no
        // lookup and leaves no side effect. It returns the SAME redirect-with-error this method
        // already produces when the plan has been deleted.
        //
        // This is NOT the activation gate and does not replace one: activation is refused by
        // assertPlanActivationEnabled('payhere') on the RETURN handler (planGetPayHereStatus,
        // below), and payhere stays shipped-disabled in config/payments.php. An initiator that
        // refuses is a closed checkout, not an authorisation control.
        \Log::warning('PayHere plan checkout refused: the integration is retired (owner decision).', [
            'user_id' => optional(\Auth::user())->id,
        ]);

        return redirect()->route('plan.index')
            ->with('error', __('This payment method is not available for subscription purchases.'));

        // Second line of defence. Unreachable while the refusal above stands; retained so that
        // deleting the refusal cannot silently re-expose a static call to a class the committed
        // lock does not install (a missing class raises \Error, not \Exception, which is how
        // this path used to reach the merchant as an uncaught 500).
        if (! class_exists(PayHere::class)) {
            \Log::warning('PayHere plan checkout refused: the PayHere SDK is not installed.', [
                'class'   => PayHere::class,
                'user_id' => optional(\Auth::user())->id,
            ]);

            return redirect()->route('plan.index')
                ->with('error', __('This payment method is not available for subscription purchases.'));
        }

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
    } catch (\Throwable $e) {
        // \Throwable, not \Exception: a missing class or a bad method call raises \Error, which
        // does not extend \Exception, so the narrower catch let those out as a 500. The
        // class_exists() guard above handles the one case known to occur here; this widening is
        // what stops the NEXT one from reaching the merchant as a stack trace.
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
