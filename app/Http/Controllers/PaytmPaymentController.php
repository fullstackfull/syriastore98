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
                /*
                 * F229 / D-31 REQUIREMENT 1 -- RECORD WHAT IS BEING BOUGHT BEFORE LEAVING.
                 *
                 * The return side cannot establish WHICH PLAN the money was for. Paytm's checksum
                 * covers `TXNAMOUNT` -- the amount is genuinely provider-attested -- but
                 * `ReceivePaymentProvider::beginTransaction()` DROPS the `plan` key when building
                 * its params, so no checksum can ever bind the plan. The plan instead arrives on
                 * the return as a `Crypt` route parameter, and `APP_KEY` is in git history and
                 * unrotated, so that is an encoding and not a control. Hence the substitution:
                 * pay once for the cheapest plan, name a dearer one on the first return.
                 *
                 * The intent is the binding. The server decides here, before the caller leaves,
                 * exactly which plan, which coupon, how much and in what currency -- and records
                 * it against the one identifier that IS inside the attested body: the order
                 * reference, which is now generated BY THE SERVER.
                 *
                 * THE OLD REFERENCE WAS THE CLOCK. `date('Y-m-d') . '-' . strtotime(...)` is
                 * second-resolution, so two purchases in the same second collided on it -- the
                 * same defect F228 measured on `plan_orders.order_id`, whose UNIQUE constraint was
                 * keying on `time()` rather than on anything the provider said. A random reference
                 * cannot collide, and `plan_purchase_intents.reference` is UNIQUE so that is a
                 * database fact rather than a convention.
                 *
                 * IT FAILS CLOSED. A null reference means the intent could not be recorded, and
                 * taking a payment against an intent that does not exist is exactly the
                 * unverifiable capture requirement 5 asks us not to create. So the purchase does
                 * not start. In production the table is absent by design (D-31 is
                 * development/test-only), so this refuses there -- which is requirement 8's
                 * fail-closed behaviour and its documented cost.
                 */
                $ssIntentReference = \App\Services\PlanPurchaseIntent::create([
                    'provider' => 'Paytm',
                    'user_id' => $authuser->id,
                    'plan_id' => $plan->id,
                    'coupon_id' => $coupon_id ?? null,
                    'coupon_code' => $request->coupon,
                    'expected_amount' => $price,
                    'currency' => $CURRENCY,
                ]);

                if ($ssIntentReference === null) {
                    Log::warning('Paytm plan purchase refused: the purchase intent could not be recorded.', [
                        'plan_id' => $plan->id,
                        'user_id' => $authuser->id,
                    ]);

                    return redirect()->route('plan.index')
                        ->with('error', __('This payment method is not available for subscription purchases.'));
                }

                $call_back = route('plan.paytm', [$request->plan_id]);
                $payment = PaytmWallet::with('receive');
                $payment->prepare(
                    [
                        'order' => $ssIntentReference,
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

                    /*
                     * ═══════════════════════════════════════════════════════════════════════════
                     * F229 / D-31 -- THE INTENT IS THE AUTHORITY FROM HERE DOWN.
                     * ═══════════════════════════════════════════════════════════════════════════
                     *
                     * Everything above establishes that an AUTHENTIC body says a payment
                     * succeeded, and that this provider reference has not already activated a
                     * plan. Neither of those says WHAT WAS BOUGHT, and that is the whole of F229.
                     *
                     * REQUIREMENT 2 -- the provider is asked directly. The checksum proves the
                     * body was minted by someone holding the merchant key; it does not prove the
                     * transaction is still successful, was not reversed, and was ever settled.
                     * The owner's instruction is explicit that a valid checksum is not by itself
                     * sufficient proof of a successful payment, so the authoritative answer is
                     * fetched server-to-server from /merchant-status/getTxnStatus.
                     *
                     * REQUIREMENT 4 -- the plan comes from the INTENT, never from the route. The
                     * route's `$plan` was decrypted from a `Crypt` parameter and `APP_KEY` is in
                     * git history, so it is forgeable. It is used below only to ANSWER the caller;
                     * the entitlement follows `$ssIntent->plan_id`.
                     *
                     * REQUIREMENTS 3 and 4 -- the amount and currency are matched against the
                     * intent by exact scaled-integer comparison, never float equality and never
                     * `bccomp` (bcmath is not installed and not declared, so it would fatal).
                     *
                     * REQUIREMENT 5 -- the intent is consumed by one conditional UPDATE from
                     * `pending`, so two concurrent callbacks cannot both win, a replay finds it
                     * already consumed, and another account's reference is refused on the
                     * purchaser check before the amount is ever disclosed.
                     *
                     * REQUIREMENT 8 -- UNVERIFIABLE IS A REFUSAL. An unreachable status endpoint,
                     * an absent intent record, a malformed response: none of them activates
                     * anything. The cost is stated in PaytmTransactionVerifier's header and the
                     * leftover is surfaced by PlanPurchaseIntent::unreconciled() for a human.
                     *
                     * REQUIREMENT 9 -- nothing here refunds, cancels or settles anything. A
                     * refused intent beside a captured payment is a RECORD, and that is all.
                     */
                    $ssOrderReference = (string) $request->ORDERID;

                    $ssProviderSays = app(\App\Services\PaytmTransactionVerifier::class)
                        ->verify($ssOrderReference);

                    if ($ssProviderSays['state'] !== \App\Services\PaytmTransactionVerifier::VERIFIED) {
                        \App\Services\PlanPurchaseIntent::recordRefusal(
                            'Paytm',
                            $ssOrderReference,
                            'provider verification ' . $ssProviderSays['state'] . ': ' . $ssProviderSays['reason'],
                            $paytmReference
                        );

                        Log::warning('Paytm plan activation refused: the provider did not confirm the payment.', [
                            'state' => $ssProviderSays['state'],
                            'reason' => $ssProviderSays['reason'],
                            'reference' => $ssOrderReference,
                            'user_id' => $user->id ?? null,
                        ]);

                        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                    }

                    [$ssIntentState, $ssIntent, $ssIntentReason] = \App\Services\PlanPurchaseIntent::verifyAndConsume(
                        'Paytm',
                        $ssOrderReference,
                        $user->id ?? null,
                        (string) $ssProviderSays['amount'],
                        (string) ($ssProviderSays['currency'] ?? ''),
                        $ssProviderSays['txn_id'] ?? $paytmReference
                    );

                    if ($ssIntentState !== \App\Services\PlanPurchaseIntent::VERIFIED) {
                        \App\Services\PlanPurchaseIntent::recordRefusal(
                            'Paytm',
                            $ssOrderReference,
                            $ssIntentState . ': ' . $ssIntentReason,
                            $ssProviderSays['txn_id'] ?? $paytmReference
                        );

                        Log::warning('Paytm plan activation refused: the payment does not match the recorded purchase intent.', [
                            'state' => $ssIntentState,
                            'reason' => $ssIntentReason,
                            'reference' => $ssOrderReference,
                            'user_id' => $user->id ?? null,
                        ]);

                        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                    }

                    /*
                     * THE PLAN THE MONEY WAS ACTUALLY FOR. Replacing the route's forgeable
                     * parameter with the server's own record is the substitution fix itself.
                     */
                    $ssIntendedPlan = Plan::find($ssIntent->plan_id);

                    if (empty($ssIntendedPlan)) {
                        \App\Services\PlanPurchaseIntent::recordRefusal(
                            'Paytm',
                            $ssOrderReference,
                            'the intent names a plan that no longer exists',
                            $ssProviderSays['txn_id'] ?? $paytmReference
                        );

                        Log::warning('Paytm plan activation refused: the intent names a plan that no longer exists.', [
                            'reference' => $ssOrderReference,
                            'plan_id' => $ssIntent->plan_id,
                        ]);

                        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                    }

                    $plan = $ssIntendedPlan;

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

                    /*
                     * F229 -- tie the consumed intent to the row it produced, so an operator can
                     * reconcile a Paytm statement line against an entitlement in one hop rather
                     * than by matching timestamps. Idempotent (`whereNull('plan_order_id')`), so a
                     * retried return cannot re-point it at a second order.
                     */
                    \App\Services\PlanPurchaseIntent::attachOrder('Paytm', $ssOrderReference, $order->id);

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
                    /*
                     * REQUIREMENT 5's RECOVERY RECORD. The payment is verified and the intent is
                     * consumed, so if `assignPlan()` fails from here the money is taken and the
                     * entitlement did not land -- the plan-side twin of F186's
                     * captured-payment-without-order. `User::assignPlan()` has a reachable throw
                     * inside it, so this is not hypothetical.
                     *
                     * It RECORDS and does nothing else. Requirement 9 forbids new refund or
                     * settlement behaviour and D-15 condition 3 separates reversing a merchant's
                     * balance from a real refund to a customer, so the intent is marked with its
                     * reason and `PlanPurchaseIntent::unreconciled()` surfaces it for a human. The
                     * intent stays CONSUMED -- re-opening it would let the same payment be spent
                     * again, which is the opposite error.
                     */
                    if (empty($assignPlan['is_success'])) {
                        Log::error('Paytm payment verified and captured, but the entitlement did not activate.', [
                            'reference' => $ssOrderReference,
                            'plan_order_id' => $order->id,
                            'plan_id' => $plan->id,
                            'user_id' => $user->id,
                            'reason' => $assignPlan['error'] ?? 'assignPlan reported no success',
                        ]);
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
