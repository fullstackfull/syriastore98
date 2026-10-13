<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use Illuminate\Http\Request;
use App\Models\Senangpay;
use App\Http\Controllers\Concerns\GuardsPlanActivation;

class SenangPayController extends Controller
{
    use GuardsPlanActivation;

    public function planPayWithSenangpay(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();

        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);

        $plan = Plan::find($planID);
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $user = \Auth::user();

        if ($plan) {
            try {
                $price = $plan->price;
                if (!empty($request->coupon)) {
                    $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                    if ($coupons) {
                        $coupon_code = $coupons->code;
                        $usedCoupun = $coupons->used_coupon();
                        if ($coupons->limit == $usedCoupun) {
                            $res_data['error'] = __('This coupon code has expired.');
                        } else {
                            $discount_value = ($plan->price / 100) * $coupons->discount;
                            $price = $price - $discount_value;
                            if ($price < 0) {
                                $price = $plan->price;
                            }
                            $coupon_id = $coupons->id;
                        }
                    } else {
                        return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                    }
                }

                try {
                    $transactionId = uniqid();

                    $senangPaySession = [
                        'order_id' => $orderID,
                        'plan_id' => $plan->id,
                        'price' => $price,
                        'coupon' => !empty($request->coupon) ? $request->coupon : '',
                    ];
                    $request->session()->put('senangPaySession', $senangPaySession);

                    $request = [
                        'full_name' => $user->name,
                        'email' => $user->email,
                        'contact_number' => $user->mobile_no
                    ];
                    $senangPay = new Senangpay();
                    $senangPay->setSendPaymentDetails($request, $user->name, $orderID, $price, $user->id);
                    return redirect($senangPay->processPayment());

                } catch (\Exception $e) {
                    \Log::debug($e->getMessage());
                    return redirect()->route('plan.index')->with('error', $e->getMessage());
                }

            } catch (\Exception $e) {
                return redirect()->route('plan.index')->with('error', __($e->getMessage()));
            }
        } else {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }
    }

    /**
     * A MILDER SUB-CLASS OF THE SAME DEFECT, AND THE ONE PROVIDER WHOSE VERIFICATION IS
     * IMPLEMENTABLE FROM IN-REPO EVIDENCE ALONE.
     *
     * WHAT WAS NOT WRONG. The plan AND the price come from server-side session state written by
     * planPayWithSenangpay (:52-58), so neither can be forged and the recorded amount was always
     * right. That session price is KEPT below rather than recomputed: it is the amount the
     * server actually charged at checkout time, which is strictly more accurate than a
     * recompute if the plan's price changes while a payment is in flight.
     *
     * WHAT WAS WRONG. The only gate was `$request->status_id == 1`, which the caller supplies.
     * A merchant could start a SenangPay checkout (populating the session), abandon the payment,
     * and then hit the callback with status_id=1 to get the plan at the correct price without
     * paying for it.
     *
     * WHAT IS NOW ALSO REQUIRED. App\Models\Senangpay::checkIfReturnHashCorrect() -- a
     * return-hash verifier that has been sitting in this repo (app/Models/Senangpay.php:120)
     * with ZERO call sites, confirmed by grepping app/ and packages/. It recomputes an md5 over
     * status_id, order_id, transaction_id and message keyed on the MERCHANT SECRET, so a caller
     * who does not hold that secret cannot forge a passing return. That is a real cryptographic
     * gate, and it is the reason this provider needs no guesswork.
     *
     * WHY THE PROVIDER IS STILL DISABLED BY DEFAULT ANYWAY. That verifier has never been
     * exercised by any code path here, so there is no evidence its formula matches SenangPay's:
     * generateReturnHash() (:115) appends a literal '&hash=[HASH]' to the signed string, which
     * is unusual enough that real payments should not depend on it until one sandbox payment has
     * confirmed it. Enabling it is an env flip, not a code change. The hash check itself IS
     * covered by a test (a correct hash passes, a wrong one is refused), so what ships is a
     * tested mechanism with an unconfirmed constant -- not an untested one.
     *
     * ANY ERROR WHILE VERIFYING IS A REFUSAL. If the merchant secret is not configured,
     * payment_setting() throws on an undefined settings key; failing closed there is correct,
     * because an unverifiable return must not activate a plan.
     *
     * THE `slug` BRANCH BELOW IS THE STOREFRONT ORDER PATH and is deliberately untouched by all
     * of this -- the guard, the hash check and the replay check all sit inside the plan branch.
     */
    public function paymentCallback(Request $request)
    {
        $senangPaySession = $request->session()->get('senangPaySession');
        $request->session()->forget('senangPaySession');

        if ($request->status_id == 1) {
            if(isset($senangPaySession['plan_id']) && !empty($senangPaySession['plan_id'])){
                $this->assertPlanActivationEnabled('senangpay', $senangPaySession['plan_id']);

                $user = \Auth::user();

                $hashVerified = false;

                try {
                    $senangPay = new Senangpay();
                    $senangPay->payment_setting(optional($user)->id);
                    $hashVerified = (bool) $senangPay->checkIfReturnHashCorrect($request);
                } catch (\Throwable $e) {
                    \Log::warning('SenangPay plan return refused: the return hash could not be verified.', [
                        'plan_id' => $senangPaySession['plan_id'],
                        'user_id' => optional($user)->id,
                        'reason'  => $e->getMessage(),
                    ]);

                    $hashVerified = false;
                }

                if (! $hashVerified) {
                    \Log::warning('SenangPay plan return refused: return hash mismatch.', [
                        'plan_id' => $senangPaySession['plan_id'],
                        'user_id' => optional($user)->id,
                    ]);

                    abort(403, __('The payment confirmation could not be verified.'));
                }

                $payment_setting = getSuperAdminAllSetting();
                $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
                $plan = Plan::find($senangPaySession['plan_id']);

                if (empty($plan)) {
                    abort(404, __('Plan not found.'));
                }

                $paymentType = __('SenangPay');

                // REPLAY / RETRY IS A NO-OP, NOT A SECOND PURCHASE.
                //
                // Consuming the session (forget(), above) already stops the common browser
                // replay, but it does not stop two arrivals that both read the session before
                // either response is written back, and it is not a claim about the provider.
                // SenangPay's own transaction_id is the key.
                if ($this->existingPlanOrderForProviderTxn($paymentType, $request->transaction_id) !== null) {
                    return redirect()->route('plan.index')->with('success', __('Plan activated Successfully!'));
                }

                $order = new PlanOrder();
                $order->order_id = $senangPaySession['order_id'];
                $order->name = $user->name;
                $order->card_number = '';
                $order->card_exp_month = '';
                $order->card_exp_year = '';
                $order->plan_name = $plan->name;
                $order->plan_id = $plan->id;
                $order->price = !empty($senangPaySession['price']) ? $senangPaySession['price'] : 0;
                $order->price_currency = $currency;
                // The provider's transaction id, so the idempotency lookup above has something
                // to find. Upstream recorded time(), which identifies nothing.
                $order->txn_id = (string) $request->transaction_id;
                $order->payment_type = $paymentType;
                $order->payment_status = 'Succeeded';
                $order->receipt = '';
                $order->user_id = $user->id;
                $order->save();

                if ($senangPaySession['coupon']) {
                    // Portable and case-exact; see GuardsPlanActivation::findPlanCouponExact().
                    $coupons = $this->planCouponForRecording($senangPaySession['coupon']);
                    if (!empty($coupons)) {
                        $userCoupon = new PlanUserCoupon();
                        $userCoupon->user_id = $user->id;
                        $userCoupon->coupon_id = $coupons->id;
                        $userCoupon->order = $order->order_id;
                        $userCoupon->save();
                        $usedCoupun = $coupons->used_coupon();
                        if ($coupons->limit <= $usedCoupun) {
                            $coupons->is_active = 0;
                            $coupons->save();
                        }
                    }
                }

                $assignPlan = $user->assignPlan($plan->id);
                if ($assignPlan['is_success']) {
                    return redirect()->route('plan.index')->with('success', __('Plan activated Successfully!'));
                } else {
                    return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                }
            }elseif(isset($senangPaySession['slug']) && !empty($senangPaySession['slug'])){
                $slug=$senangPaySession['slug'];
                return redirect()->route('store.payment.status',$slug);
            }else{
                return redirect()->back()->with('error', 'Oops something went wrong.');
            }
        } else {
            return redirect()->back()->with('error', 'Transaction has been failed.');
        }
    }
}
