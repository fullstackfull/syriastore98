<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use App\Models\PlanCoupon;
use Stripe;

use Illuminate\Http\Request;

class StripePaymentController extends Controller
{
    public function stripe($code)
    {
        try {
            $plan_id = \Illuminate\Support\Facades\Crypt::decrypt($code);
            $plan    = Plan::find($plan_id);
            if ($plan) {
                $admin_payments_details = getSuperAdminAllSetting();

                if((isset($admin_payments_details['is_stripe_enabled']) && $admin_payments_details['is_stripe_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_paystack_enabled']) && $admin_payments_details['is_paystack_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_razorpay_enabled']) && $admin_payments_details['is_razorpay_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_mercado_enabled']) && $admin_payments_details['is_mercado_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_skrill_enabled']) && $admin_payments_details['is_skrill_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_paymentwall_enabled']) && $admin_payments_details['is_paymentwall_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_paypal_enabled']) && $admin_payments_details['is_paypal_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_flutterwave_enabled']) && $admin_payments_details['is_flutterwave_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_paytm_enabled']) && $admin_payments_details['is_paytm_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_mollie_enabled']) && $admin_payments_details['is_mollie_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_coingate_enabled']) && $admin_payments_details['is_coingate_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_sspay_enabled']) && $admin_payments_details['is_sspay_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_toyyibpay_enabled']) && $admin_payments_details['is_toyyibpay_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_bank_transfer_enabled']) && $admin_payments_details['is_bank_transfer_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_paytabs_enabled']) && $admin_payments_details['is_midtrans_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_nepalste_enabled']) && $admin_payments_details['is_nepalste_enabled'] == 'on') || 
                    (isset($admin_payments_details['is_khalti_enabled']) && $admin_payments_details['is_khalti_enabled'] == 'on') || 
                    (isset($admin_payments_details['is_payhere_enabled']) && $admin_payments_details['is_payhere_enabled'] == 'on') || 
                    (isset($admin_payments_details['is_authorizenet_enabled']) && $admin_payments_details['is_authorizenet_enabled'] == 'on') || 
                    (isset($admin_payments_details['is_tap_enabled']) && $admin_payments_details['is_tap_enabled'] == 'on') || 
                    (isset($admin_payments_details['is_phonepe_enabled']) && $admin_payments_details['is_phonepe_enabled'] == 'on') || 
                    (isset($admin_payments_details['is_paddle_enabled']) && $admin_payments_details['is_paddle_enabled'] == 'on') || 
                    (isset($admin_payments_details['is_paiementpro_enabled']) && $admin_payments_details['is_paiementpro_enabled'] == 'on') || 
                    (isset($admin_payments_details['is_fedpay_enabled']) && $admin_payments_details['is_fedpay_enabled'] == 'on') || 
                    (isset($admin_payments_details['is_cinetpay_enabled']) && $admin_payments_details['is_cinetpay_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_easebuzz_enabled']) && $admin_payments_details['is_easebuzz_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_cybersource_enabled']) && $admin_payments_details['is_cybersource_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_Senangpay_enabled']) && $admin_payments_details['is_Senangpay_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_ozow_enabled']) && $admin_payments_details['is_ozow_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_nmi_enabled']) && $admin_payments_details['is_nmi_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_payu_enabled']) && $admin_payments_details['is_payu_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_myfatoorah_enabled']) && $admin_payments_details['is_myfatoorah_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_sofort_enabled']) && $admin_payments_details['is_sofort_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_esewa_enabled']) && $admin_payments_details['is_esewa_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_paynow_enabled']) && $admin_payments_details['is_paynow_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_sslcommerz_enabled']) && $admin_payments_details['is_sslcommerz_enabled'] == 'on') ||
                    (isset($admin_payments_details['is_dpopay_enabled']) && $admin_payments_details['is_dpopay_enabled'] == 'on')
                ){
                    $store_id = 1;
                    $theme_id = 'stylique';
                    return view('plans/stripe', compact('plan', 'admin_payments_details','store_id'));
                }else{
                    return redirect()->route('plan.index')->with('error', __('The admin has not set the payment method. '));
                }
            } else {
                return redirect()->back()->with('error', __('Plan is deleted.'));
            }
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Plan is not found.'));
        }
    }

    public function addpayment(Request $request)
    {
        $objUser               = \Auth::user();
        $planID                = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan                  = Plan::find($planID);
        $admin_payment_setting = getSuperAdminAllSetting($objUser->id, getCurrentStore());
        $currency = $admin_payment_setting['CURRENCY_NAME'];
        if ($plan) {
            try {
                $price = $plan->price;
                if (!empty($request->coupon)) {
                    $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                    if (!empty($coupons)) {
                        $usedCoupun     = $coupons->used_coupon();
                        $discount_value = ($plan->price / 100) * $coupons->discount;
                        $price          = $plan->price - $discount_value;

                        if ($coupons->limit == $usedCoupun) {
                            return redirect()->back()->with('error', __('This coupon code has expired.'));
                        }
                    } else {
                        return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                    }
                }

                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
                if ($price > 0.0) {
                    // Prepare the shipping detail
                    $shippingDetails = [
                        'name' => $objUser->name ?? 'eCommerceGo',
                        'address' => [
                            'line1' => $objUser->address_line_one ?? '510 Townsend St',
                            'line2' => $objUser->address_line_two ?? '510 Townsend St',
                            'city' => $objUser->city ?? 'San Francisco',
                            'state' => $objUser->state ?? 'CA',
                            'postal_code' => $objUser->postal_code ?? '98140',
                            'country' => $objUser->country ?? 'US',
                        ],
                    ];

                    Stripe\Stripe::setApiKey($admin_payment_setting['stripe_secret_key']);
                    $data = Stripe\Charge::create(
                        [
                        "amount" => (int)($price * 100),
                            "currency" => $currency,
                            "source" => $request->stripeToken,
                            "description" => " Plan - " . $plan->name,
                            "metadata" => ["order_id" => $orderID],
                            'shipping' => $shippingDetails,
                        ]
                    );
                } else {
                    $data['amount_refunded'] = 0;
                    $data['failure_code']    = '';
                    $data['paid']            = 1;
                    $data['captured']        = 1;
                    $data['status']          = 'succeeded';
                }

                if ($data['amount_refunded'] == 0 && empty($data['failure_code']) && $data['paid'] == 1 && $data['captured'] == 1) {

                    PlanOrder::create(
                        [
                            'order_id' => $orderID,
                            'name' => $request->name,
                            'card_number' => isset($data['payment_method_details']['card']['last4']) ? $data['payment_method_details']['card']['last4'] : '',
                            'card_exp_month' => isset($data['payment_method_details']['card']['exp_month']) ? $data['payment_method_details']['card']['exp_month'] : '',
                            'card_exp_year' => isset($data['payment_method_details']['card']['exp_year']) ? $data['payment_method_details']['card']['exp_year'] : '',
                            'plan_name' => $plan->name,
                            'plan_id' => $plan->id,
                            'price' => $price,
                            'price_currency' => $currency,
                            'txn_id' => isset($data['balance_transaction']) ? $data['balance_transaction'] : '',
                            'payment_type' => __('STRIPE'),
                            'payment_status' => isset($data['status']) ? $data['status'] : 'succeeded',
                            'receipt' => isset($data['receipt_url']) ? $data['receipt_url'] : 'free coupon',
                            'user_id' => $objUser->id,
                            'store_id' => getCurrentStore(),
                        ]
                    );

                    if (!empty($request->coupon)) {
                        $userCoupon         = new PlanUserCoupon();
                        $userCoupon->user_id   = $objUser->id;
                        $userCoupon->coupon_id = $coupons->id;
                        $userCoupon->order  = $orderID;
                        $userCoupon->save();

                        $usedCoupun = $coupons->used_coupon();
                        if ($coupons->limit <= $usedCoupun) {
                            $coupons->is_active = 0;
                            $coupons->save();
                        }
                    }
                    if ($data['status'] == 'succeeded') {
                        $assignPlan = $objUser->assignPlan($plan->id);
                        if ($assignPlan['is_success']) {
                            return redirect()->route('plan.index')->with('success', __('Plan successfully activated.'));
                        } else {
                            return redirect()->back()->with('error', __($assignPlan['error']));
                        }
                    } else {
                        return redirect()->back()->with('error', __('Your payment has failed.'));
                    }
                } else {
                    return redirect()->back()->with('error', __('Transaction has been failed.'));
                }
            } catch (\Exception $e) {
                return redirect()->back()->with('error', __($e->getMessage()));
            }
        } else {
            return redirect()->back()->with('error', __('Plan is deleted.'));
        }
    }
}