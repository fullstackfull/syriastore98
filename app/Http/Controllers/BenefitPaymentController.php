<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use GuzzleHttp\Client;
use Exception;
use App\Models\PlanCoupon;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Concerns\RefusesDeferredGateway;

use App\Http\Controllers\Concerns\GuardsPlanActivation;

class BenefitPaymentController extends Controller
{
    use GuardsPlanActivation;
    use RefusesDeferredGateway;

    //

    /** D-33: the gateway name in one place, so a call site cannot name the wrong one. */
    private function refuseDeferredGatewayPaidCheckoutOrNull($plan, $couponCode)
    {
        return $this->refuseDeferredGatewayCheckout('Benefit', $plan, $couponCode);
    }

    public function initiatePayment(Request $request)
    {
        
        $admin_payment_setting = getSuperAdminAllSetting();
        $secret_key = $admin_payment_setting['benefit_secret_key'];
        $objUser = Auth::user();
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan = Plan::find($planID);

        /*
         * D-33 — NO CHARGE IS MADE AT ALL. Owner correction, 2026-10-31.
         *
         * This refusal used to live only on the return, which meant the merchant reached tap, was
         * charged, came back and was told the method was unavailable -- money taken, nothing
         * given. Refusing here, before `$plan` is even priced for the outbound call, means the
         * provider is never contacted.
         */
        $benefitDeferredCheckout = $this->refuseDeferredGatewayPaidCheckoutOrNull($plan, $request->coupon);

        if ($benefitDeferredCheckout !== null) {
            return $benefitDeferredCheckout;
        }

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
                                        'price_currency' => !empty($admin_payment_setting['CURRENCY_NAME']) ? $admin_payment_setting['CURRENCY_NAME'] : 'USD',
                                        'txn_id' => '',
                                        'payment_type' => 'Benefit',
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

                $userData =
                    [
                        "amount" => $get_amount,
                        "currency" => $admin_payment_setting['CURRENCY_NAME'],
                        "customer_initiated" => true,
                        "threeDSecure" => true,
                        "save_card" => false,
                        "description" => " Plan - " . $plan->name,
                        "metadata" => ["udf1" => "Metadata 1"],
                        "reference" => ["transaction" => "txn_01", "order" => "ord_01"],
                        "receipt" => ["email" => true, "sms" => true],
                        "customer" => ["first_name" => $objUser->name, "middle_name" => "", "last_name" => "", "email" => $objUser->email, "phone" => ["country_code" => 965, "number" => 51234567]],
                        "source" => ["id" => "src_bh.benefit"],
                        /*
                         * P4-F046 -- THE PUBLIC BIN IS GONE. Owner decision: "remove webhook.site".
                         *
                         * The same committed bin as the storefront's, on the SaaS-plan rail, and
                         * worse here in one respect: the sibling `redirect.url` below carries
                         * plan_id, amount and coupon, so the bin would have received the complete
                         * replay tuple in the SAME payload as the Tap charge id.
                         *
                         * `''` IS THE SHAPE processTap ALREADY SHIPS ON THE WIRE, and an earlier
                         * version of this comment claimed `null` was. It is not. processTap (and
                         * TapPaymnetController) pass PHP `null` into App\Package\Payment::charge(),
                         * which builds its JSON by string concatenation and so sends
                         * `"post":{"url":""}`. This method json_encode()s an array, so `null` here
                         * reached Tap as `"url":null` -- a shape no other Tap flow in this
                         * repository sends and one nobody could verify, since no credential exists
                         * in the container. `''` is byte-identical to what the card flows already
                         * send. It removes the bin WITHOUT choosing a new destination -- that
                         * remains the open owner decision P1-SEC-55, which this does not pre-empt.
                         */
                        "post" => ["url" => ''],
                        "redirect" => ["url" => route('benefit.call_back', ['plan_id' => $plan->id, 'amount' => $get_amount, 'coupon' => $coupon])],


                    ];
                    $responseData = json_encode($userData);
                    
                    $client = new Client();
                try {
                    $response = $client->request('POST', 'https://api.tap.company/v2/charges', [
                        'body' => $responseData,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $secret_key,
                            'accept' => 'application/json',
                            'content-type' => 'application/json',
                        ],
                    ]);
                  

                } catch (\Throwable $th) {
                    /*
                     * THE REASON IS LOGGED NOW, and the currency sentence is shown for FEWER
                     * failures than before -- not for exactly the right ones.
                     *
                     * This catch answered EVERY failure with "Currency Not Supported" and recorded
                     * none: a wrong platform key, an unreachable provider and a malformed body all
                     * read the same, and the log could not say which. The storefront twin,
                     * PaymentService::processBenefit, was narrowed in the same commit that removed
                     * the webhook bin; this rail was not.
                     *
                     * WHAT THE CONDITION SEPARATES, AND WHAT IT DOES NOT. `ClientException` is
                     * EVERY 4xx the provider returns. That includes the 400/422 a genuine currency
                     * rejection would use and ALSO a 401 from a wrong key, a 403 and a 429 -- for
                     * those the sentence is still misleading, and this does not pretend otherwise.
                     * It was not narrowed to particular status codes because Tap's codes for a
                     * rejected currency cannot be verified from here (no credential exists in this
                     * container), and a wrong guess would silence the sentence where it IS true.
                     * What changed is the other side: a connection failure, a 5xx and a malformed
                     * body -- the cases that were certainly NOT a currency problem -- no longer say
                     * it was.
                     *
                     * WHO CAN ACT ON IT. The key is the PLATFORM's (`benefit_secret_key`, read from
                     * the super-admin settings at the top of this method) and the person paying is a
                     * merchant buying a plan, who cannot fix a wrong key. "Contact To Your Site
                     * Admin" addresses the right person, and the log now gives that person the real
                     * reason: PaymentFailure::redirectBack() records the gateway, the exception
                     * class and the first 300 characters of its message -- never the request headers.
                     */
                    $failure = \App\Services\PaymentFailure::redirectBack($th, 'benefit');

                    return $th instanceof \GuzzleHttp\Exception\ClientException
                        ? $failure->with('error', __('Currency Not Supported.Contact To Your Site Admin'))
                        : $failure;
                }

                $data = $response->getBody();
                $res = json_decode($data);

                return redirect($res->transaction->url);
            } catch (Exception $e) {
        
                return \App\Services\PaymentFailure::redirectBack($e, 'benefit');
            }
        } else {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }
    }

    public function call_back(Request $request)
    {
        $admin_payment_setting = getSuperAdminAllSetting();
        $secret_key = $admin_payment_setting['benefit_secret_key'];
        $user = \Auth::user();
        $plan = Plan::find($request->plan_id);
        $couponCode = $request->coupon;
        // Server-owned: was `$request->amount`. Benefit IS verified on this path -- an outbound
        // GET to api.tap.company/v2/charges/{tap_id} gated on
        // $data->gateway->response->code == '00' -- so only the RECORDED price changes.
        $getAmount = $plan ? $this->serverOwnedPlanPrice($plan, $couponCode) : 0;
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

        // Portable and case-exact; see GuardsPlanActivation::findPlanCouponExact(). Also no
        // longer dereferences ->id on a miss.
        $coupons = $this->planCouponForRecording($couponCode);
        if ($coupons !== null) {
            $request['coupon_id'] = $coupons->id;
        }

        /*
         * ONE TAP CHARGE, ONE PLAN ACTIVATION -- and it has to be decided HERE, above the try.
         *
         * `plan_id` on this route is a RAW query-string integer (:148), published in the return
         * URL by the initiator at :108, and the gate below is `$data->gateway->response->code ==
         * '00'` -- a property of the tap CHARGE, which says nothing about which plan the charge
         * paid for. So before this guard, one real tap payment re-opened as
         * `?tap_id=<mine>&plan_id=<any>` activated any plan, any number of times, and
         * `txn_id` was recorded as '' so the replay left nothing to find afterwards.
         *
         * The charge id is the value this method is ABOUT to ask tap.company about, so it is in
         * hand before any network traffic -- which is also what makes the refusal reachable from
         * a test; see GuardsPlanActivation::refusePlanActivationReplay().
         */
        $benefitReference = $request->tap_id;
        /*
         * D-33 — DEFERRED GATEWAY. A paid plan activation refuses here, BEFORE the outbound call,
         * because tap's charge says nothing about which plan the money was for and the contract
         * that would let the intent bind to it cannot be established from this environment.
         * Zero-price plans are unaffected. Compatibility and business impact are set out in the
         * trait's header, as D-33 requires.
         */
        $benefitDeferred = $this->refuseDeferredGatewayPaidActivation('Benefit', $plan, $request->coupon, $benefitReference);

        if ($benefitDeferred !== null) {
            return $benefitDeferred;
        }

        $benefitReplay = $this->refusePlanActivationReplay('Benefit', $benefitReference, $plan, $user);

        if ($benefitReplay !== null) {
            return $benefitReplay;
        }

        try {
            $post = $request->all();
            $client = new Client();
            $response = $client->request('GET', 'https://api.tap.company/v2/charges/' . $post['tap_id'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret_key,
                    'accept' => 'application/json',
                ],
            ]);

            $json = $response->getBody();
            $data = json_decode($json);
            $status_code = $data->gateway->response->code;

            if ($status_code == '00') {
                $order = new PlanOrder();
                $order->order_id = $orderID;
                $order->name = $user->name;
                $order->card_number = '';
                $order->card_exp_month = '';
                $order->card_exp_year = '';
                $order->plan_name = $plan->name;
                $order->plan_id = $plan->id;
                $order->price = $getAmount;
                $order->price_currency = $admin_payment_setting['CURRENCY_NAME'];
                $order->payment_type = __('Benefit');
                $order->payment_status = 'success';
                // THE SAME VARIABLE THE GUARD WAS GIVEN. Upstream recorded '' here, with the tap
                // charge id in hand and discarded, so a replay left nothing in plan_orders to
                // find. Hoisting it into one local is deliberate: see the structural assertion in
                // PlanActivationReplayGuardTest -- a guard keyed on one expression and a txn_id
                // written from another is how the Cashfree arm of this change protected nothing.
                $order->txn_id = (string) $benefitReference;
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
                return redirect()->route('plan.index')->with('error', __('Your Transaction is fail please try again'));
            }
        } catch (Exception $e) {
            return redirect()->route('plan.index')->with('error', __($e->getMessage()));
        }
    }

}
