<?php

namespace App\Http\Controllers;

use App\Models\Utility;
use App\Models\Plan;
use App\Models\Coupon;
use App\Models\UserCoupon;
use Illuminate\Support\Facades\Auth;
use App\Models\Order;
use App\Models\PlanOrder;
use Illuminate\Http\Request;
use App\Http\Controllers\Concerns\GuardsPlanActivation;

class PaymentWallPaymentController extends Controller
{
    use GuardsPlanActivation;
    public $secret_key;
    public $public_key;
    public $is_enabled;

    public function paymentwall(Request $request)
    {
        $data = $request->all();
        $admin_payment_setting = getSuperAdminAllSetting();

        return view('plans.paymentwall', compact('data', 'admin_payment_setting'));
    }
    public function paymentConfig($user)
    {
        if (Auth::check()) {
            $user = Auth::user();
        }
        if ($user->type == 'admin') {
            $payment_setting = getSuperAdminAllSetting();
        } else {
            $payment_setting = Utility::getCompanyPaymentSetting();
        }

        $this->secret_key = isset($payment_setting['paymentwall_private_key ']) ? $payment_setting['paymentwall_private_key  '] : '';
        $this->public_key = isset($payment_setting['paymentwall_public_key']) ? $payment_setting['paymentwall_public_key'] : '';
        $this->is_enabled = isset($payment_setting['is_paymentwall_enabled']) ? $payment_setting['is_paymentwall_enabled'] : 'off';

        return $this;
    }

    public function planPayWithPaymentWall(Request $request, $plan_id)
    {
        // FAIL CLOSED FIRST. The gate below WOULD be a real provider charge
        // ($charge->isSuccessful() / isCaptured()), but \Paymentwall_Charge does not exist in
        // this environment -- no paymentwall package is required in composer.json and
        // class_exists('Paymentwall_Charge') is false -- so the SDK's contract cannot be read
        // or tested here, and the private key below is a hardcoded literal rather than a
        // setting. The Yookassa precedent. See config/payments.php.
        $this->assertPlanActivationEnabled('paymentwall');

        $planID    = \Illuminate\Support\Facades\Crypt::decrypt($plan_id);

        $plan      = Plan::find($planID);
        $user   = Auth::user();
        $coupon_id = '';
        if ($plan) {
            /* Check for code usage */
            $plan->discounted_price = false;
            $price                  = $plan->price;
            if (isset($request->coupon) && !empty($request->coupon)) {
                $request->coupon = trim($request->coupon);
                $coupons         = Coupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();

                if (!empty($coupons)) {

                    $usedCoupun             = $coupons->used_coupon();
                    $discount_value         = ($price / 100) * $coupons->discount;
                    $plan->discounted_price = $price - $discount_value;

                    if ($coupons->limit == $usedCoupun) {
                        return redirect()->back()->with('error', __('This coupon code has expired.'));
                    }

                    $price     = $price - $discount_value;
                    $coupon_id = $coupons->id;
                } else {
                    return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                }
            }
            if ($price <= 0) {

                $user->plan = $plan->id;
                $user->save();

                $assignPlan = $user->assignPlan($plan->id, $request->paymentwall_payment_frequency);

                if ($assignPlan['is_success'] == true && !empty($plan)) {
                    if (!empty($user->payment_subscription_id) && $user->payment_subscription_id != '') {
                        try {
                            $user->cancel_subscription($user->id);
                        } catch (\Exception $exception) {
                            \Log::debug($exception->getMessage());
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
                            'price' => $price,
                            'price_currency' => !empty($this->currancy) ? $this->currancy : 'usd',
                            'txn_id' => '',
                            'payment_type' => __('Manually'),
                            'payment_status' => 'succeeded',
                            'receipt' => null,
                            'user_id' => $user->id,
                        ]
                    );   // P1-PLT-02: a plan sale is a plan_orders row; Order::create wrote a blank host order with store_id NULL

                    $res['msg']  = __("Plan successfully upgraded.");
                    $res['flag'] = 2;

                    return $res;
                }
            } else {
                $orderID = time();
                \Paymentwall_Config::getInstance()->set(array(
                    'private_key' => 'sdrsefrszdef'
                ));
                $parameters = $request->all();
                $chargeInfo = array(
                    'email' => $parameters['email'],
                    'history[registration_date]' => '1489655092',
                    'amount' => $price,
                    'currency' => !empty($this->currancy) ? $this->currancy : 'USD',
                    'token' => $parameters['brick_token'],
                    'fingerprint' => $parameters['brick_fingerprint'],
                    'description' => 'Order #123'
                );
                $charge = new \Paymentwall_Charge();
                $charge->create($chargeInfo);
                $responseData = json_decode($charge->getRawResponseData(), true);
                $response = $charge->getPublicData();
                if ($charge->isSuccessful() and empty($responseData['secure'])) {
                    if ($charge->isCaptured()) {
                        if ($request->has('coupon_id') && $request->coupon_id != '') {
                            $coupons = Coupon::find($request->coupon_id);
                            if (!empty($coupons)) {
                                $userCoupon         = new UserCoupon();
                                $userCoupon->user_id   = $user->id;
                                $userCoupon->coupon_id = $coupons->id;
                                $userCoupon->order  = $orderID;
                                $userCoupon->save();

                                $usedCoupun = $coupons->used_coupon();
                                if ($coupons->limit <= $usedCoupun) {
                                    $coupons->is_active = 0;
                                    $coupons->save();
                                }
                            }
                        }

                        $user->is_plan_purchased = 1;
                        if ($user->is_trial_done == 1) {
                            $user->is_trial_done = 2;
                            $user->save();
                        }

                        /*
                         * F147. `PlanOrder`, NOT `Order` -- THE UNCONVERTED TWIN OF P1-PLT-02.
                         *
                         * This is a SaaS PLAN purchase, not a shopper's order. Of the fifteen
                         * attributes assigned below, TWELVE are not columns on `orders` at all
                         * (`order_id`, `name`, `card_number`, `card_exp_month`, `card_exp_year`,
                         * `plan_name`, `plan_id`, `price`, `price_currency`, `txn_id`, `receipt`,
                         * `user_id`) and every one of them IS a column on `plan_orders`.
                         *
                         * P1-PLT-02 converted this method's OTHER branch 51 lines above -- see
                         * its comment, "a plan sale is a plan_orders row; Order::create wrote a
                         * blank host order with store_id NULL" -- and converted the same shape in
                         * four sibling gateways. It missed this one because that sweep searched
                         * for the `Order::create([...])` ARRAY form, and this branch uses direct
                         * property assignment. The two fail differently, which is why it hid:
                         * mass assignment SILENTLY DROPS unfillable keys (so the array form wrote
                         * a blank row), while property assignment bypasses `$fillable` entirely
                         * and would raise "Unknown column 'order_id'" at the INSERT.
                         *
                         * Found by independent review of the P2-04-11 stamp, which had labelled
                         * a merchant's subscription payment `order_source = storefront`. The
                         * ratchet asked "does this writer record a source?" and nobody asked the
                         * prior question, "is this an order at all?". The stamp is DELETED rather
                         * than moved: `plan_orders` has no such column, and provenance of a plan
                         * sale is not in that vocabulary.
                         *
                         * LATENT, double-gated, so no row carrying the false stamp can exist:
                         * `assertPlanActivationEnabled('paymentwall')` at :55 fails closed
                         * (`config/payments.php` defaults it to false), and no Paymentwall SDK is
                         * in `composer.json`/`composer.lock`, so `Paymentwall_Config` at :129
                         * raises before this line is reached. Converting it is still correct:
                         * repairing either gate later must not resurrect the defect.
                         */
                        $order                 = new PlanOrder();
                        $order->order_id       = $orderID;
                        $order->name           = $user->name;
                        $order->card_number    = '';
                        $order->card_exp_month = '';
                        $order->card_exp_year  = '';
                        $order->plan_name      = $plan->name;
                        $order->plan_id        = $plan->id;
                        $order->price          = $plan->price ?  : 0;
                        $order->price_currency = $this->currancy;
                        $order->txn_id         = "";
                        $order->payment_type   = 'PaymentWall';
                        $order->payment_status = 'success';
                        $order->receipt        = '';
                        $order->user_id        = $user->id;
                        $order->save();
                        $assignPlan = $user->assignPlan($plan->id);
                        if ($assignPlan['is_success']) {
                            $res['msg'] = __("Plan successfully upgraded.");
                            $res['flag'] = 1;
                            return $res;
                        }
                    } elseif ($charge->isUnderReview()) {
                    }
                } elseif (!empty($responseData['secure'])) {
                    $response = json_encode(array('secure' => $responseData['secure']));
                } else {
                    $errors = json_decode($response, true);
                    $res['flag'] = 2;
                    return $res;
                }
                echo $response;
            }
        }
    }
    public function planeerror(Request $request, $flag)
    {
        if ($flag == 1) {
            return redirect()->route("plan.index")->with('error', __('Transaction has been Successfull! '));
        } else {

            return redirect()->route("plan.index")->with('error', __('Transaction has been failed! '));
        }
    }

}
