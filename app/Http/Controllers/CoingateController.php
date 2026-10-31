<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use App\Coingate\Coingate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


use App\Http\Controllers\Concerns\GuardsPlanActivation;

class CoingateController extends Controller
{
    use GuardsPlanActivation;

    public function coingatePaymentPrepare(Request $request)
    {
        $validator = \Validator::make(
            $request->all(), [
                               'plan_id' => 'required',
                               'total_price' => 'required',
                           ]
        );
        if($validator->fails())
        {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', $messages->first());
        }
        $user    = getCurrentStore() ?? Auth::user()->current_store;
        $authuser =Auth::user();
        $store   = getStoreById(getCurrentStore() ?? Auth::user()->current_store);
        
        $plan_id = decrypt($request->plan_id);
        $plan    = Plan::find($plan_id);

        if (empty($plan)) {
            abort(404, __('Plan not found.'));
        }

        // P0 (free activation), AND IT NEEDS NO GATEWAY AT ALL.
        //
        // This was `$price = $request->total_price`, and `total_price` is POSTed by the merchant
        // to route('coingate.prepare.plan'). Sixty lines below, `if ($price <= 0)` sets
        // $authuser->plan, saves, and calls assignPlan() -- a shortcut that exists so a 100%
        // coupon does not have to round-trip through CoinGate. With the price taken from the
        // request, a merchant POSTing total_price=0 and no coupon activated ANY paid plan
        // instantly, without CoinGate being involved in any way.
        //
        // THIS IS A SHAPE NO EARLIER AUDIT HERE HAD LOOKED AT. Every pass examined the RETURN
        // handlers; the free-plan shortcut inside the CHECKOUT INITIATOR was never examined, and
        // it is a shorter path than any of them -- no callback, no forged provider field, one
        // authenticated POST.
        //
        // Recomputing from $plan->price makes `$price <= 0` true only for a plan that really is
        // free or a coupon that really is 100%. The coupon block below still runs: it keeps the
        // limit check, the invalid-code rejection and the usage recording, and recomputes the
        // same number.
        $price   = $this->serverOwnedPlanPrice($plan, $request->coupon);
        $admin_payment_setting = getSuperAdminAllSetting();

        /*
         * F234 -- REFUSE HERE, ABOVE THE `try` AND ABOVE BOTH BRANCHES.
         *
         * The coupon block below persists a `PlanUserCoupon` and may deactivate the coupon
         * platform-wide, fifty lines below, `:200` reads `$admin_payment_setting['coingate_mode']`
         * and raises on an installation that never saved its CoinGate settings. F196 Option A
         * (D-28b): the refusal must precede persistence and every side effect.
         *
         * ABOVE THE `try` because `HttpException` extends `\Exception`: inside it, the `catch`
         * below would swallow the 403 and downgrade it into a flash redirect, and the refusal
         * would stop refusing.
         *
         * BUT ONLY WHEN THE GATEWAY IS ACTUALLY NEEDED, and an existing test is why.
         *
         * The first version of this guard refused unconditionally, on the reasoning that D-28b
         * requires the refusal above BOTH persisting branches. That broke
         * `PlanPaymentFailClosedTest::test_the_coingate_initiator_still_reaches_the_shortcut_for_a_genuinely_free_plan`
         * -- and the test was right. **A genuinely free plan never calls CoinGate**, so refusing it
         * because CoinGate has no credentials is refusing a path that does not need them. That is
         * the positive-control half of F196's own lesson: a guard that refuses everything satisfies
         * every negative assertion and is still wrong.
         *
         * `$price` here is `serverOwnedPlanPrice()` from the line above, which already applies the
         * coupon, so this is the server's own post-discount figure and not the caller's.
         *
         * **WHAT THIS DELIBERATELY DOES NOT FIX.** The free branch also persists the coupon before
         * it can fail -- `$authuser->plan = $plan->id` writes a column no migration creates. That
         * is a different defect, in the F58 family, already pinned by
         * `test_the_free_plan_shortcut_writes_a_users_column_that_no_migration_creates`, and
         * refusing the free path here to cover it would break the legitimate free activation the
         * suite declares. F234 is the GATEWAY path's burn; the free path's is recorded, not
         * silently absorbed into this slice.
         */
        if ($price > 0) {
            $this->assertProviderCredentialsPresent('coingate', $admin_payment_setting, [
                'coingate_mode',
                'coingate_auth_token',
                /*
                 * CURRENCY_NAME IS HERE BECAUSE INDEPENDENT REVIEW FOUND THE FIRST VERSION OF THIS
                 * LIST INCOMPLETE, AND THE OMISSION REPRODUCED F234 EXACTLY, ONE SETTING OVER.
                 *
                 * `:211-212` read `$admin_payment_setting['CURRENCY_NAME']` as a BARE subscript --
                 * unlike `:180`, which guards the same key with `!empty()` -- inside the `try` and
                 * AFTER the coupon row is saved and the coupon possibly deactivated. So an
                 * installation holding both CoinGate rows but no CURRENCY_NAME row still burned a
                 * coupon use on a request that could not succeed.
                 *
                 * THE SUITE COULD NOT HAVE CAUGHT IT: `PlanPaymentTestCase::seedSuperAdminCurrency()`
                 * inserts CURRENCY_NAME in every fixture, including the "unconfigured" cases, so no
                 * existing test can reach that state. `test_a_missing_currency_setting_is_also_refused_before_any_write`
                 * seeds the CoinGate rows and deliberately omits the currency.
                 */
                'CURRENCY_NAME',
            ]);
        }

        try {
            if(!empty($request->coupon))
            {
                $coupons = $this->planCouponForRecording($request->coupon);
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
                $order_id = strtoupper(str_replace('.', '', uniqid('', true)));

                $userCoupon         = new PlanUserCoupon();
                $userCoupon->user_id   = Auth::user()->id;
                $userCoupon->coupon_id = $coupons->id;
                $userCoupon->order  = $order_id;
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
                            'price_currency' => !empty($admin_payment_setting['CURRENCY_NAME']) ? $admin_payment_setting['CURRENCY_NAME'] : 'USD',
                            'txn_id' => '',
                            'payment_type' => 'coingate',
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
            if($plan)
            {
                $order                 = $request->all();
                CoinGate::config(
                    array(
                        'environment' => $admin_payment_setting['coingate_mode'],
                        // sandbox OR live
                        'auth_token' => $admin_payment_setting['coingate_auth_token'],
                        'curlopt_ssl_verifypeer' => FALSE
                        // default is false
                    )
                );

                $post_params = array(
                    'order_id' => time(),
                    'price_amount' => $price,
                    'price_currency' =>$admin_payment_setting['CURRENCY_NAME'],
                    'receive_currency' =>$admin_payment_setting['CURRENCY_NAME'],
                    'callback_url' => route('coingate.coingate.callback') . '?plan_id=' . $plan->id . '&user_id=' . Auth::user()->id . '&coupon=' .$request->coupon ,
                    'cancel_url' => route('plan.index'),
                    'success_url' => route('coingate.coingate.callback') . '?plan_id=' . $plan->id . '&user_id=' . Auth::user()->id . '&coupon=' .$request->coupon ,
                    'title' => 'Order #' . time(),
                );
                $order = Coingate::coingatePayment($post_params, 'POST');
                if($order['status_code'] === 200) { 
                    $response = $order['response'];
                    return redirect($response['payment_url']); 
                }
                else
                {
                    return redirect()->back()->with('error', __('opps something wren wrong.'));
                }
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * P0 (free activation). FOUND BY CLOSING A HOLE IN THE AUDIT'S OWN CENSUS.
     *
     * This handler was never on the plan-purchase inventory at all, and the reason is worth
     * recording: the census looked for `$order->price = ...`, and this method writes
     * `$planorder->price`. A different variable name was enough to make a free-activation path
     * invisible to the audit that was supposed to find it.
     *
     * THERE IS NO GATE OF ANY KIND. route('coingate.coingate.callback') is a plain GET
     * (routes/web.php:607). This method reads plan_id off the request, writes
     * `$planorder->payment_status = 'success'` and calls assignPlan(). Nothing asks CoinGate
     * whether the invoice was paid, and no signature is checked.
     *
     * THE PRICE WAS ALREADY SERVER-OWNED here ($price = $plan->price with a coupon recompute),
     * so this is a free activation without a forged amount -- the plan_orders row records the
     * correct price for a payment that never happened.
     *
     * Activation is refused until CoinGate's own order-status API is consulted; see
     * config/payments.php for the re-enable conditions.
     */
    public function coingatePlanGetPayment(Request $request)
    {

        $this->assertPlanActivationEnabled('coingate', $request->plan_id);

        $user                  = Auth::user();
        $plan_id               = $request->plan_id;
        $store_id              = getCurrentStore() ?? Auth::user()->current_store;
        $admin_payment_setting = getSuperAdminAllSetting();
        $plan                  = Plan::find($plan_id);
        if($plan)
        {
            try
            {
                $order_id = strtoupper(str_replace('.', '', uniqid('', true)));
                $price     = $plan->price;
                // Portable and case-exact; see GuardsPlanActivation::findPlanCouponExact(). The
                // upstream `whereRaw('BINARY `code` = ?')` is MySQL-only -- a hard syntax error
                // on sqlite -- and it ran UNCONDITIONALLY here, before the order was written,
                // so on any non-MySQL driver this handler threw before doing anything. That
                // also made the legitimate-activation path impossible to test.
                $coupons = $this->planCouponForRecording($request->coupon);
                if(!empty($coupons))
                {

                    $usedCoupun     = $coupons->used_coupon();
                    $discount_value = ($plan->price / 100) * $coupons->discount;
                    $price          = $plan->price - $discount_value;
                    if($coupons->limit == $usedCoupun)
                    {

                        return redirect()->back()->with('error', __('This coupon code has expired.'));
                    }
                        $coupon_id = $coupons->id;


                    if(!empty($request->coupon))
                    {

                            $statuses = 'success';
                            if ($coupon_id != '') {

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
                    }
                }
                $planorder                 = new PlanOrder();
                $planorder->order_id       = $order_id;
                $planorder->name           = $user->name;
                $planorder->card_number    = '';
                $planorder->card_exp_month = '';
                $planorder->card_exp_year  = '';
                $planorder->plan_name      = $plan->name;
                $planorder->plan_id        = $plan->id;
                $planorder->price          = $price;
                $planorder->price_currency = $admin_payment_setting['CURRENCY_NAME'];
                $planorder->txn_id         = '-';
                $planorder->payment_type   = __('CoinGAte');
                $planorder->payment_status = 'success';
                $planorder->receipt        = '';
                $planorder->user_id        = $user->id;
                $planorder->store_id       = $store_id;
                $planorder->save();

                $assignPlan = $user->assignPlan($plan->id);

                if($assignPlan['is_success'])
                {
                    return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
                }
                else
                {


                    return redirect()->route('plan.index')->with('error', $assignPlan['error']);
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
