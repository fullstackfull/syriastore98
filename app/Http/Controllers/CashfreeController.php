<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\ApiController;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Cart;
use App\Models\City;
use Illuminate\Http\Request;
use App\Models\Coupon;
use App\Models\PlanOrder;
use App\Models\Plan;
use App\Models\Utility;
use App\Models\UserCoupon;
use App\Models\Shipping;
use App\Models\Product;
use App\Models\ProductVariantOption;
use App\Models\PurchasedProducts;
use App\Models\ProductCoupon;
use App\Models\Store;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderBillingDetail;
use App\Models\OrderCouponDetail;
use App\Models\OrderTaxDetail;
use App\Models\PlanCoupon;
use App\Models\PlanUserCoupon;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use PhpParser\Node\Stmt\TryCatch;
use Illuminate\Support\Facades\Cookie;
use Session;

use App\Models\OrderNote;
use App\Models\Setting;

use App\Http\Controllers\Concerns\GuardsPlanActivation;

class CashfreeController extends Controller
{
    use GuardsPlanActivation;

    //
    public function paymentConfig()
    {
        if(\Auth::check()){
            $payment_setting = getSuperAdminAllSetting();
            config(
                [
                    'services.cashfree.key' => isset($payment_setting['cashfree_key']) ? $payment_setting['cashfree_key'] : '',
                    'services.cashfree.secret' => isset($payment_setting['cashfree_secret_key']) ? $payment_setting['cashfree_secret_key'] : '',
                ]
            );
        }
    }

    public function cashfreePayment(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan = Plan::find($planID);
        $user = \Auth::user();
        $this->paymentConfig();
        $url = config('services.cashfree.url');
     
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

                    /*
                     * ═══════════════════════════════════════════════════════════════════════════
                     * F229 / D-31 REQUIREMENT 1 -- THE ORDER ID IS THE INTENT'S REFERENCE.
                     * ═══════════════════════════════════════════════════════════════════════════
                     *
                     * The return side cannot establish WHICH PLAN the money was for: it reads
                     * `$request->plan_id`, a raw query-string integer the caller supplies, and the
                     * gate is `$info->payment_status == "SUCCESS"` -- a property of the cashfree
                     * ORDER, which says nothing about a plan. So paying once for the cheapest plan
                     * and naming a dearer one on the first return succeeded.
                     *
                     * Cashfree makes this straightforward in a way Paytm did not: the order id is
                     * **minted here, by this server**, sent in the server-to-server order
                     * creation, echoed back by `return_url`'s `{order_id}` template, AND it is the
                     * id the return then asks Cashfree's own settlements and payments APIs about.
                     * So it is provider-attested by construction -- no new round trip, no new
                     * contract. Using it as the intent reference binds the plan to the order
                     * Cashfree actually processed.
                     *
                     * `newReference()` is NOT used here, deliberately: the reference has to be the
                     * value Cashfree knows, and inventing a second identifier would leave the two
                     * to drift.
                     */
                    $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

                    $ssIntentReference = \App\Services\PlanPurchaseIntent::create([
                        'provider' => 'Cashfree',
                        'reference' => $orderID,
                        'user_id' => $user->id ?? null,
                        'plan_id' => $plan->id,
                        'coupon_id' => isset($coupons) && $coupons ? $coupons->id : null,
                        'coupon_code' => $request->coupon,
                        'expected_amount' => (string) $get_amount,
                        'currency' => !empty($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD',
                    ]);

                    if ($ssIntentReference === null) {
                        /* FAIL CLOSED. Without the intent the return cannot tell which plan the
                           money was for, which is the whole defect -- so the purchase does not
                           start rather than completing unprotected. */
                        \Illuminate\Support\Facades\Log::warning('Cashfree plan purchase refused: the purchase intent could not be recorded.', [
                            'plan_id' => $plan->id ?? null,
                            'user_id' => $user->id ?? null,
                        ]);

                        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                    }

                    $headers = array(
                        "Content-Type: application/json",
                        "x-api-version: 2022-01-01",
                        "x-client-id: " . config('services.cashfree.key'),
                        "x-client-secret: " . config('services.cashfree.secret')
                    );

                    $data = json_encode([
                        'order_id' => $orderID,
                        'order_amount' => $get_amount,
                        "order_currency" => !empty($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD',
                        "order_name" => $plan->name,
                        "customer_details" => [
                            "customer_id" => 'customer_' . $user->id,
                            "customer_name" => $user->name,
                            "customer_email" => $user->email,
                            "customer_phone" => '1234567890',
                        ],
                        "order_meta" => [
                            "return_url" => route('cashfreePayment.success') . '?order_id={order_id}&order_token={order_token}&plan_id=' . $plan->id . '&amount=' . $get_amount . '&coupon=' . $coupon . ''

                        ]
                    ]);
                    try {

                        $curl = curl_init($url);
                        curl_setopt($curl, CURLOPT_URL, $url);
                        curl_setopt($curl, CURLOPT_POST, true);
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

                        $resp = curl_exec($curl);
                      
                        curl_close($curl);
                        return redirect()->to(json_decode($resp)->payment_link);
                    } catch (\Throwable $th) {
                        return redirect()->back()->with('error', __('Currency Not Supported.Contact To Your Site Admin'));
                    }



                } catch (\Exception $e) {

                    return \App\Services\PaymentFailure::redirectBack($e, 'cashfree');
                }

        } else {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }


    }

    public function cashfreePaymentSuccess(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $this->paymentConfig();
        $user = \Auth::user();
        $plan = Plan::find($request->plan_id);
        $couponCode = $request->coupon;
        // Server-owned: was `$request->amount`. Cashfree IS verified on this path
        // ($info->payment_status == "SUCCESS"), so only the RECORDED price changes.
        $getAmount = $plan ? $this->serverOwnedPlanPrice($plan, $couponCode) : 0;
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        // Portable and case-exact; see GuardsPlanActivation::findPlanCouponExact(). Also no
        // longer dereferences ->id on a miss: upstream read $coupons->id unconditionally, so an
        // unknown coupon code 500ed here.
        $coupons = $this->planCouponForRecording($couponCode);
        if ($coupons !== null) {
            $request['coupon_id'] = $coupons->id;
        }

        /*
         * ONE CASHFREE ORDER, ONE PLAN ACTIVATION. Same shape as Benefit: `plan_id` is a raw
         * query-string integer (:190), the gate is `$info->payment_status == "SUCCESS"` -- a
         * property of the cashfree ORDER, not of the plan -- and `txn_id` was recorded as ''
         * (:244) with `$respons->cf_payment_id` in hand and discarded. Decided above the try,
         * because the order id is what this method is about to ask cashfree about.
         */
        $cashfreeReference = $request->get('order_id');

        $cashfreeReplay = $this->refusePlanActivationReplay('Cashfree', $cashfreeReference, $plan, $user);

        if ($cashfreeReplay !== null) {
            return $cashfreeReplay;
        }

        /*
         * ═══════════════════════════════════════════════════════════════════════════════════════
         * F229 -- THE PLAN COMES FROM THE INTENT, NOT FROM `$request->plan_id`.
         * ═══════════════════════════════════════════════════════════════════════════════════════
         *
         * `consumeForPlanBinding()` rather than `verifyAndConsume()`, and the difference is
         * documented at length on that method: it enforces the intent's existence, its purchaser,
         * and one-consumption-per-reference, and it does **not** compare the captured amount.
         *
         * That is requirement 8 applied honestly rather than as a blanket refusal. The
         * substitution vector is the PLAN, and binding the plan needs nothing about how Cashfree
         * reports money. The amount comparison is a second, independent control that does need a
         * verified response contract, and this project has none for Cashfree -- so it stays
         * BLOCKED and is NOT guessed. The amount is in any case server-owned at initiation
         * (`order_amount => $get_amount`, computed from `$plan->price` minus a server-resolved
         * coupon and sent server-to-server), so a buyer cannot lower what Cashfree asks for.
         *
         * ORDER OF OPERATIONS: this runs AFTER the replay guard, exactly as Paytm does -- and the
         * first version of this change put it before, which BROKE eight existing guard tests and
         * was right to. `refusePlanActivationReplay()` answers a reference that has already
         * activated a plan with its own refusal, and those semantics are tested by name. Putting
         * the intent check first replaced that refusal with "no intent exists" for every spent
         * reference -- a different answer to the same question, and a worse one, because the
         * guard's same-buyer branch is what gives a buyer who paid the success they earned.
         */
        [$ssIntentState, $ssIntent, $ssIntentReason] = \App\Services\PlanPurchaseIntent::consumeForPlanBinding(
            'Cashfree',
            (string) $cashfreeReference,
            $user->id ?? null,
            $cashfreeReference
        );

        if ($ssIntentState !== \App\Services\PlanPurchaseIntent::VERIFIED) {
            \App\Services\PlanPurchaseIntent::recordRefusal(
                'Cashfree',
                (string) $cashfreeReference,
                $ssIntentState . ': ' . $ssIntentReason,
                $cashfreeReference,
                $user->id ?? null
            );

            \Illuminate\Support\Facades\Log::warning('Cashfree plan activation refused: the payment does not match a recorded purchase intent.', [
                'state' => $ssIntentState,
                'reason' => $ssIntentReason,
                'user_id' => $user->id ?? null,
            ]);

            return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
        }

        /* THE PLAN THE MONEY WAS ACTUALLY FOR. Everything below reads `$plan`, so reassigning it
           here is the fix -- the forgeable `$request->plan_id` no longer reaches the entitlement.
           `$getAmount` is recomputed from it for the same reason. */
        $plan = Plan::find($ssIntent->plan_id);

        if (empty($plan)) {
            \Illuminate\Support\Facades\Log::warning('Cashfree plan activation refused: the intent names a plan that no longer exists.', [
                'intent_plan_id' => $ssIntent->plan_id,
                'user_id' => $user->id ?? null,
            ]);

            return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
        }

        $couponCode = $ssIntent->coupon_code;
        $getAmount = $this->serverOwnedPlanPrice($plan, $couponCode);
        $coupons = $this->planCouponForRecording($couponCode);


        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->request('GET', config('services.cashfree.url') . '/' . $request->get('order_id') . '/settlements', [
                'headers' => [
                    'accept' => 'application/json',
                    'x-api-version' => '2022-09-01',
                    "x-client-id" => config('services.cashfree.key'),
                    "x-client-secret" => config('services.cashfree.secret')
                ],
            ]);


            $respons = json_decode($response->getBody());
            if ($respons->order_id && $respons->cf_payment_id != NULL) {

                $response = $client->request('GET', config('services.cashfree.url') . '/' . $respons->order_id . '/payments/' . $respons->cf_payment_id . '', [
                    'headers' => [
                        'accept' => 'application/json',
                        'x-api-version' => '2022-09-01',
                        'x-client-id' => config('services.cashfree.key'),
                        'x-client-secret' => config('services.cashfree.secret'),
                    ],
                ]);
                $info = json_decode($response->getBody());


                if ($info->payment_status == "SUCCESS") {

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
                    $order->payment_type = __('Cashfree');
                    $order->payment_status = 'success';
                    /*
                     * THE RECORDED REFERENCE MUST BE THE ONE THE GUARD LOOKS UP. Upstream recorded
                     * '' here; the first version of this fix recorded `$respons->cf_payment_id`,
                     * which is a DIFFERENT value from the `order_id` the guard above keys on --
                     * cashfree's own payment id versus the id this server minted at :139 and
                     * published in the return URL at :150 as `?order_id={order_id}`. The guard
                     * would then have looked for a row that is never written, and the Cashfree arm
                     * of this change would have protected nothing while three tests passed on a
                     * hand-seeded shape production never produces. Found by independent review.
                     *
                     * `order_id` is also the right choice on the merits: it is the value a
                     * replayer presents, because it is the one in the URL they re-open.
                     */
                    $order->txn_id = (string) $cashfreeReference;
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
            } else {
                return redirect()->route('plan.index')->with('error', 'Payment Failed.');
            }
            return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
        } catch (\Exception $e) {
            return redirect()->route('plan.index')->with('error', __($e->getMessage()));
        }

    }
}
