<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPlanActivation;
use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mollie\Laravel\Facades\Mollie;


class MolliePaymentController extends Controller
{
    use GuardsPlanActivation;


    public $api_key;
    public $profile_id;
    public $partner_id;
    public $is_enabled;
    public $invoiceData;


    public function paymentConfig()
    {

        if (\Auth::check()) {

            $payment_setting = getSuperAdminAllSetting();
        }

        $this->api_key    = isset($payment_setting['mollie_api_key']) ? $payment_setting['mollie_api_key'] : '';
        $this->profile_id = isset($payment_setting['mollie_profile_id']) ? $payment_setting['mollie_profile_id'] : '';
        $this->partner_id = isset($payment_setting['mollie_partner_id']) ? $payment_setting['mollie_partner_id'] : '';
        $this->is_enabled = isset($payment_setting['is_mollie_enabled']) ? $payment_setting['is_mollie_enabled'] : 'off';

        return $this;
    }
    public function planPayWithMollie(Request $request)
    {

        $payment    = $this->paymentConfig();
        $planID     = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan       = Plan::find($planID);

        $authuser   = Auth::user();
        $coupon = '';
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $admin_payment_setting = getSuperAdminAllSetting();

        if ($plan) {
            $price = $plan->price;
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
            if(!empty($request->coupon))
            {

                $userCoupon         = new PlanUserCoupon();
                $userCoupon->user_id   = $authuser->id;
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
            if ($price <= 0) {
                $authuser->plan = $plan->id;
                $authuser->save();

                $assignPlan = $authuser->assignPlan($plan->id);

                if ($assignPlan['is_success'] == true && !empty($plan)) {

                    $orderID = time();
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
                            'price_currency' =>!empty($admin_payment_setting['CURRENCY_NAME'])?$admin_payment_setting['CURRENCY_NAME']:'USD',
                            'txn_id' => '',
                            'payment_type' => __('Mollie'),
                            'payment_status' => 'succeeded',
                            'receipt' => null,
                            'user_id' => $authuser->id,
                        ]
                    );
                    $assignPlan = $authuser->assignPlan($plan->id);

                    return redirect()->route('plan.index')->with('success', __('Plan activated Successfully!'));
                } else {
                    return redirect()->back()->with('error', __('Plan fail to upgrade.'));
                }
            }
            $errormsg = "";
            try {
                $mollie = new \Mollie\Api\MollieApiClient();
                $mollie->setApiKey($this->api_key);


                $payment = $mollie->payments->create(
                    [
                        "amount" => [
                            "currency" =>!empty($admin_payment_setting['CURRENCY_NAME']) ? $admin_payment_setting['CURRENCY_NAME']:'USD',
                            "value" => str_replace(",","",number_format($price, 2)),
                        ],

                        "description" => "payment for product",
                        "redirectUrl" => route(
                            'plan.mollie',
                            [
                                $request->plan_id,
                                'coupon_id=' . $request->coupon,
                            ]
                        ),
                    ]
                );


                session()->put('mollie_payment_id', $payment->id);

                /*
                 * ═══════════════════════════════════════════════════════════════════════════════
                 * F229 / D-31 REQUIREMENT 1 -- THE MOLLIE PAYMENT ID IS THE INTENT'S REFERENCE.
                 * ═══════════════════════════════════════════════════════════════════════════════
                 *
                 * The return reads the plan from `$plan_id`, a separate route parameter, and
                 * `Crypt` is an encoding rather than a control (`APP_KEY` is in git history). The
                 * gate is `$payment->isPaid()` -- a property of the MOLLIE PAYMENT, which says
                 * nothing about which plan the money was for. So one genuinely paid Mollie payment
                 * could be presented against any plan.
                 *
                 * Mollie mints the payment id here, before the buyer leaves, and the server holds
                 * it in the session -- the strongest sourcing of the four providers, because the
                 * caller cannot name it. It is also the id the return asks Mollie about. So it is
                 * the natural reference: binding it to a plan here means the return can read the
                 * plan from the server's own record instead of from the route.
                 */
                $ssIntentReference = \App\Services\PlanPurchaseIntent::create([
                    'provider' => 'Mollie',
                    'reference' => $payment->id,
                    'user_id' => $authuser->id ?? null,
                    'plan_id' => $plan->id,
                    'coupon_id' => isset($coupons) && $coupons ? $coupons->id : null,
                    'coupon_code' => $request->coupon,
                    'expected_amount' => (string) $price,
                    'currency' => !empty($admin_payment_setting['CURRENCY_NAME']) ? $admin_payment_setting['CURRENCY_NAME'] : 'USD',
                ]);

                if ($ssIntentReference === null) {
                    /* FAIL CLOSED: without the intent the return cannot tell which plan the money
                       was for, which is the defect itself. Mollie has already created the payment
                       at this point but the buyer has NOT been sent to the checkout page, so
                       nothing is captured -- an unpaid Mollie payment simply expires. */
                    \Illuminate\Support\Facades\Log::warning('Mollie plan purchase refused: the purchase intent could not be recorded.', [
                        'plan_id' => $plan->id ?? null,
                        'user_id' => $authuser->id ?? null,
                    ]);

                    return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                }
            } catch (\Exception $e) {
                $errormsg = $e->getMessage();
                return redirect()->back()->with('error', __('The amount contains an invalid value.'));
            }
            return redirect($payment->getCheckoutUrl())->with('payment_id', $payment->id);
        } else {
            return redirect()->back()->with('error', __('Plan is deleted.'));
        }
    }

    public function getPaymentStatus(Request $request, $plan_id)
    {
        $user                  = Auth::user();
        $store_id              = getCurrentStore() ?? Auth::user()->current_store;
        $admin_payment_setting = getSuperAdminAllSetting();
        $plan_id               = \Illuminate\Support\Facades\Crypt::decrypt($plan_id);
        $plan                  = Plan::find($plan_id);

        if($plan)
        {
            try
            {
                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

                /*
                 * ONE MOLLIE PAYMENT, ONE PLAN ACTIVATION -- decided before the SDK is touched.
                 *
                 * The payment id comes from the SERVER SESSION (written at :149), which is the
                 * strongest sourcing of the three providers guarded this way: the caller cannot
                 * name the payment. What was missing is single use. `$plan_id` is a separate
                 * route parameter (:165) and `Crypt` is an encoding rather than a control, so one
                 * genuinely paid Mollie payment could be presented against ANY plan -- and
                 * indefinitely, because the clean-up at the end of the paid branch was
                 * UNREACHABLE: `session()->forget('mollie_payment_id')` sat after an if/else in
                 * which every branch returns, so it never ran. Both halves are fixed: the durable
                 * guard here, and the forget() moved to where it executes.
                 */
                $mollieReference = session()->get('mollie_payment_id');

                $mollieReplay = $this->refusePlanActivationReplay(
                    'Mollie',
                    $mollieReference,
                    $plan,
                    $user
                );

                if ($mollieReplay !== null) {
                    return $mollieReplay;
                }

                /*
                 * ═══════════════════════════════════════════════════════════════════════════════
                 * F229 -- THE PLAN COMES FROM THE INTENT, NOT FROM THE ROUTE PARAMETER.
                 * ═══════════════════════════════════════════════════════════════════════════════
                 *
                 * `consumeForPlanBinding()` and not `verifyAndConsume()`: it enforces the intent's
                 * existence, its purchaser and one-consumption-per-reference, and does NOT compare
                 * the captured amount. The reasoning is on that method -- the substitution vector
                 * is the plan, and binding the plan needs nothing about how Mollie reports money,
                 * while the amount comparison needs a verified contract this project does not have
                 * for Mollie. `$payment->amount->value` is read for the RECORDED price below, which
                 * is bookkeeping; it is not used as a control, and requirement 8's BLOCKED status
                 * for Mollie's amount check stands.
                 *
                 * The amount is server-owned at initiation in any case: `amount.value` is
                 * `number_format($price, 2)` where `$price` is `$plan->price` minus a
                 * server-resolved discount, sent in a server-to-server payment creation.
                 *
                 * ORDER OF OPERATIONS: AFTER the replay guard, as Paytm and Cashfree both do. The
                 * first version of this change put it before, which broke eight existing guard
                 * tests and was right to: `refusePlanActivationReplay()` answers a reference that
                 * has already activated a plan with its own refusal, and its same-buyer branch is
                 * what gives a buyer who paid the success they earned. Checking the intent first
                 * replaced both with "no intent exists".
                 */
                [$ssIntentState, $ssIntent, $ssIntentReason] = \App\Services\PlanPurchaseIntent::consumeForPlanBinding(
                    'Mollie',
                    (string) $mollieReference,
                    $user->id ?? null,
                    $mollieReference
                );

                if ($ssIntentState !== \App\Services\PlanPurchaseIntent::VERIFIED) {
                    \App\Services\PlanPurchaseIntent::recordRefusal(
                        'Mollie',
                        (string) $mollieReference,
                        $ssIntentState . ': ' . $ssIntentReason,
                        $mollieReference,
                        $user->id ?? null
                    );

                    \Illuminate\Support\Facades\Log::warning('Mollie plan activation refused: the payment does not match a recorded purchase intent.', [
                        'state' => $ssIntentState,
                        'reason' => $ssIntentReason,
                        'user_id' => $user->id ?? null,
                    ]);

                    return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                }

                /* THE PLAN THE MONEY WAS ACTUALLY FOR. Everything below reads `$plan`. */
                $plan = Plan::find($ssIntent->plan_id);

                if (empty($plan)) {
                    \Illuminate\Support\Facades\Log::warning('Mollie plan activation refused: the intent names a plan that no longer exists.', [
                        'intent_plan_id' => $ssIntent->plan_id,
                        'user_id' => $user->id ?? null,
                    ]);

                    return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                }


                $mollie = new \Mollie\Api\MollieApiClient();
                $mollie->setApiKey($admin_payment_setting['mollie_api_key']);

                if(session()->has('mollie_payment_id'))
                {
                    $payment = $mollie->payments->get(session()->get('mollie_payment_id'));

                    if($payment->isPaid())
                    {
                        if(!empty($request->coupon_id))
                        {
                            $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon_id])->where('is_active', '1')->first();

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
                        $planorder                 = new PlanOrder();
                        $planorder->order_id       = $orderID;
                        $planorder->name           = $user->name;
                        $planorder->card_number    = '';
                        $planorder->card_exp_month = '';
                        $planorder->card_exp_year  = '';
                        $planorder->plan_name      = $plan->name;
                        $planorder->plan_id        = $plan->id;
                        $planorder->price          = $payment->amount->value;
                        $planorder->price_currency = !empty($admin_payment_setting['CURRENCY_NAME'])?$admin_payment_setting['CURRENCY_NAME']:'USD';
                        /*
                         * THE SAME VARIABLE THE GUARD WAS GIVEN, not `$payment->id`.
                         *
                         * They are the same value -- `$payment` was fetched BY the session id --
                         * but writing the one the guard looked up makes a mismatch structurally
                         * impossible rather than merely true today. The Cashfree arm of this
                         * change was keyed on `order_id` while recording `cf_payment_id`, so it
                         * protected nothing while three tests passed; independent review found it.
                         */
                        $planorder->txn_id         = (string) $mollieReference;
                        $planorder->payment_type   = __('Mollie');
                        $planorder->payment_status = $payment->status == 'paid' ? 'success' : 'failed';
                        $planorder->receipt        = '';
                        $planorder->user_id        = $user->id;
                        $planorder->store_id       = $store_id;
                        $planorder->save();


                        if(!empty($request->coupon_id))
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
                        $assignPlan = $user->assignPlan($plan->id);

                        if($assignPlan['is_success'])
                        {
                            /*
                             * THE PAYMENT ID IS SPENT, so clear it -- and ONLY ONCE THE
                             * ENTITLEMENT EXISTS.
                             *
                             * It used to be cleared after this if/else, where every branch
                             * returns, so the line never executed and the id stayed spendable for
                             * the life of the session. The first version of this fix moved it
                             * ABOVE `assignPlan()`, and INDEPENDENT REVIEW showed that traded one
                             * defect for a worse one: Mollie's payment id lives in the session and
                             * nowhere else, so if `assignPlan()` then threw or failed, the buyer's
                             * retry would find no key, fall through to 'Transaction Error', and
                             * have no path left to claim a plan they had paid for. Pre-change the
                             * key survived and the retry worked (writing a duplicate row, which is
                             * what the durable guard now prevents).
                             *
                             * Here it is unreachable until the plan is actually assigned, so a
                             * failure leaves the key in place and the buyer can retry -- and the
                             * retry is caught by the guard at the top of this method, which
                             * re-runs the activation instead of writing a second row.
                             */
                            session()->forget('mollie_payment_id');

                            return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
                        }
                        else
                        {

                            return redirect()->route('plan.index')->with('error', $assignPlan['error']);
                        }

                    }
                    else
                    {
                        return redirect()->back()->with('error', __('Transaction Unsuccesfull'));
                    }


                }
                else
                {
                    session()->flash('error', __('Transaction Error'));

                    return redirect('/');
                }
            }
            catch(\Exception $e)
            {
                return redirect()->route('plan.index')->with('error', __('Transaction has been failed.'));
            }
        }
        else
        {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }
    }

}


