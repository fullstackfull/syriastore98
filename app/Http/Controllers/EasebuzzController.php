<?php

namespace App\Http\Controllers;

use App\Events\AddRewardClubPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Events\AddAdditionalFields;
use App\Models\User;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\PlanUserCoupon;
use App\Facades\ModuleFacade as Module;
use Easebuzz\Easebuzz;
use App\Events\CustomerOrder;
use App\Events\GetProductStatus;
use App\Services\PaymentService;
use App\Models\Store;
use App\Models\Utility;
use App\Models\Setting;
use App\Models\Product;
use App\Models\Cart;
use App\Models\City;
use App\Models\ActivityLog;
use App\Models\OrderTaxDetail;
use App\Models\OrderBillingDetail;
use App\Models\AppSetting;
use Stripe;
use App\Http\Controllers\CartController;
use Illuminate\Http\RedirectResponse;
use App\Models\OrderNote;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use App\Traits\ApiResponser;
use App\Http\Controllers\Api\ApiController;
use App\Models\OrderCouponDetail;
use App\Models\ProductVariant;
use App\Models\TaxMethod;
use App\Models\UserCoupon;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Concerns\GuardsPlanActivation;
use App\Http\Controllers\Concerns\GuardsGatewayReturn;

class EasebuzzController extends Controller
{
    use GuardsPlanActivation;
    use GuardsGatewayReturn;

    public function planPayWithEasebuzz(Request $request)
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
                $easebuzz_merchant_key = isset($payment_setting['easebuzz_merchant_key']) ? $payment_setting['easebuzz_merchant_key'] : '';
                $easebuzz_salt_key = isset($payment_setting['easebuzz_salt_key']) ? $payment_setting['easebuzz_salt_key'] : '';
                $easebuzz_enviroment_name = isset($payment_setting['easebuzz_enviroment_name']) ? $payment_setting['easebuzz_enviroment_name'] : '';
                $call_back = route('plan.get.easebuzz.notify', ['plan_id' => $plan->id,'coupon_code'=>$request->coupon,'amount' => $price]) . '?_token=' . csrf_token();
                $returnURL = route('plan.easebuzz.return') . '?_token=' . csrf_token();

                $transaction_id = strtoupper(str_replace('.', '', uniqid('', true)));
                $easebuzzObj = new Easebuzz($easebuzz_merchant_key, $easebuzz_salt_key, $easebuzz_enviroment_name);

                $price = number_format((float)$price, 2, '.', '');
                $postData = array (
                    "txnid" => $transaction_id,
                    "amount" => $price,
                    "firstname" => $user->name,
                    "email" => $user->email,
                    "phone" =>$user->mobile,
                    "productinfo" => $plan->name,
                    "surl" => $call_back,
                    "furl" => $returnURL,
                    "udf1" => "aaaa",
                    "udf2" => "aaaa",
                    "udf3" => "aaaa",
                    "udf4" => "aaaa",
                    "udf5" => "aaaa",
                    "address1" => "aaaa",
                    "address2" => "aaaa",
                    "city" => "aaaa",
                    "state" => "aaaa",
                    "country" => "aaaa",
                    "zipcode" => "123123"
                  );
                $easebuzzObj->initiatePaymentAPI($postData);
                if($easebuzzObj)
                {
                    return redirect()->back()->with('error',__('Something went wrong.'));
                }
            } catch (\Exception $e) {
                return redirect()->route('plan.index')->with('error', __($e->getMessage()));
            }
        }
        else{
            return redirect()->back()->with('error',__('Something went wrong.'));
        }
    }

    /**
     * P0 (free activation). THE GATE WAS AN ENGLISH SENTENCE, COMPARED BY STRING EQUALITY.
     *
     * `$request->error == 'Transaction is successful.'` -- supplied by the caller, trailing full
     * stop and all. The price came from `$request->amount` on the same request. One POST to
     * route('plan.get.easebuzz.notify') with those two fields activated any paid plan for zero.
     *
     * Activation is refused until the Easebuzz reverse hash is verified with the salt, or the
     * transaction is re-fetched through the Easebuzz SDK this controller already uses to
     * initiate; see config/payments.php.
     *
     * THIS IS THE PLAN PATH ONLY. getProductStatus() is the storefront order path in the same
     * controller and is deliberately untouched -- a merchant's customers keep checking out.
     */
    public function notify_url (Request $request)
    {
        $plan           = Plan::find($request->plan_id);

        $this->assertPlanActivationEnabled('easebuzz', $plan);

        $user = \Auth::user();
        $orderID = time();
        if ($plan) {
            // Server-owned: was `$request->amount`.
            $price = $this->serverOwnedPlanPrice($plan, $request->coupon_code);

            if ($plan && $request->error == 'Transaction is successful.') {
                if (!empty($user->payment_subscription_id) && $user->payment_subscription_id != '') {
                    try {
                        $user->cancel_subscription($user->id);
                    } catch (\Exception $exception) {
                        \Log::debug($exception->getMessage());
                    }
                }
                if ($request->has('coupon_code') && $request->coupon_code != '') {
                    // Portable and case-exact; see GuardsPlanActivation::findPlanCouponExact().
                    $coupons = $this->planCouponForRecording($request->coupon_code);

                    if (!empty($coupons)) {

                        // $discount_value = ($price / 100) * $coupons->discount;

                        $userCoupon            = new PlanUserCoupon();
                        $userCoupon->user_id  = $user->id;
                        $userCoupon->coupon_id = $coupons->id;
                        $userCoupon->order  = $orderID;
                        $userCoupon->save();

                        $usedCoupun = $coupons->used_coupon();
                        if ($coupons->limit <= $usedCoupun) {
                            $coupons->is_active = 0;
                            $coupons->save();
                        }
                        // $price = $price - $discount_value;
                    }
                }
                $order                 = new PlanOrder();
                $order->order_id       = $orderID;
                $order->name           = $user->name;
                $order->card_number    = '';
                $order->card_exp_month = '';
                $order->card_exp_year  = '';
                $order->plan_name      = $plan->name;
                $order->plan_id        = $plan->id;
                $order->price          = $price == null ? 0 : $price;
                $order->price_currency = isset($this->currancy) ? $this->currancy : '';
                $order->txn_id         = $request->has('preference_id') ? $request->preference_id : '';
                $order->payment_type   = 'Easebuzz';
                $order->payment_status = 'succeeded';
                $order->receipt        = '';
                $order->user_id        = $user->id;
                $order->save();
                $assignPlan = $user->assignPlan($plan->id, $request->payment_frequency);
                if ($assignPlan['is_success']) {
                    return redirect()->route('plan.index')->with('success', __('Plan activated Successfully!'));
                } else {
                    return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                }
            } else {
                return redirect()->route('plan.index')->with('error', __('Transaction has been failed! '));
            }
        }
    }

    public function return_url (Request $request)
    {
        return redirect()->back()->with('error',__('Transaction has been failed'));
    }


    public function getProductStatus(Request $request, $storeSlug)
    {
        /*
         * P01-COMMERCE-SEC-01. THE CHECKOUT STATE IS THE SERVER'S, OR THERE IS NO ORDER.
         *
         * THIS ONE WAS THE INVERSE OF ITS FIVE SIBLINGS, AND WORSE. It read the state from a
         * REQUEST parameter FIRST and used the session only as a fallback:
         *
         *     $requests_data = json_decode(urldecode($request->input('request_data')), true);
         *     if (empty($requests_data)) { $requests_data = Session::get('easebuzz_request_data'); }
         *
         * so no expired session was even needed -- a JSON blob in the query string was
         * authoritative by design, carrying the prices, the shipping, the coupon, the cart, the
         * STORE the order is written to and the CUSTOMER it is written for. The request source is
         * removed rather than reordered: a value the caller supplies is not a weaker source of
         * truth here, it is not a source of truth at all.
         *
         * See App\Http\Controllers\Concerns\GuardsGatewayReturn.
         */
        $requests_data = $this->storedCheckoutState($request, 'easebuzz', 'easebuzz_request_data');

        if ($requests_data === null) {
            return $this->gatewayReturnRefusal();
        }

        // Always true now that the refusal above returns early. Left in place rather than
        // unwrapped because its closing brace pairs with an `else` ~600 lines below, and
        // restructuring that is a bigger change than this package should make; it is NOT a
        // control, so nobody should read it as one.
        if($requests_data)
        {

            $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
            $store = getStore($slug);

            \Session::forget('easebuzz_request_data');
            $customer_id = $requests_data['customer_id'] ?? '';
            // P01-COMMERCE-SEC-01. The stored state is now the request for the REST of this handler,
            // not only on the authenticated arm below. See
            // GuardsGatewayReturn::adoptStoredCheckoutState(): on the GUEST arm the pre-existing
            // `$request->merge($requests_data)` never ran, so every later `$request->customer_id` was
            // the posted value -- the order's owner, and the subject of the cart delete.
            $this->adoptStoredCheckoutState($request, $requests_data);

            if(!empty($requests_data['method_id'])){

                $request['method_id'] = $requests_data['method_id'];
            }
            $user = Cache::remember('admin_details', 3600, function () {
                return User::where('type','admin')->first();
            });
            if ($user->type == 'admin') {
                $plan = Plan::find($user->plan_id);
            }
            if (module_is_active('PartialPayments')) {
                if($plan && strpos($plan->modules, 'PartialPayments') !== false && \Auth::guard('customers')->user())
                {
                    if((isset($request->partial_payment_type) || $request->partial_payment_type == 'pending_payment'))
                    {
                        $return = \Workdo\PartialPayments\app\Models\PartialPayments::ManagePartialPayment($requests_data, $slug);
                        if($return['status'] == 'success')
                        {
                            return redirect()->back()->with('success',__($return['message']));
                        }
                    }
                }
            }
            if (!auth('customers')->user()) {
                if ($request->coupon_code != null) {
                    $coupon = Coupon::where('id', $request->coupon_info['coupon_id'])->where('store_id', $store->id)->first();
                    $coupon_email  = $coupon->PerUsesCouponCount();
                    $i = 0;
                    foreach ($coupon_email as $email) {
                        if ($email == $request->billing_info['email']) {
                            $i++;
                        }
                    }

                    if (!empty($coupon->coupon_limit_user)) {
                        if ($i  >= $coupon->coupon_limit_user) {
                            return Utility::error(['message' => __('This coupon has expired.')]);
                        }
                    }
                }
            }
            if (!auth('customers')->user()) {
                $rules = [
                    'billing_info' => 'required',
                    'payment_type' => 'required',
                ];
            } else {
                $rules = [
                    'customer_id' => 'required',
                    'billing_info' => 'required',
                    'payment_type' => 'required',
                ];
            }

            $validator = \Validator::make($requests_data, $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                Utility::error([
                    'message' => $messages->first()
                ]);
            }

            $cartlist_final_price = 0;
            $final_price = 0;
            $tax_price = 0;
            // cart list api
            if (!auth('customers')->user()) {

                $response = Cart::cart_list_cookie($requests_data,$store->id);
                $response = json_decode(json_encode($response));
                $cartlist = (array)$response->data;
                if (empty($cartlist['product_list'])) {
                    return Utility::error(['message' => 'Cart is empty.']);
                }
                $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
                $final_sub_total_price = !empty($cartlist['total_sub_price']) ? $cartlist['total_sub_price'] : 0;
                $final_price = $response->data->total_final_price;
                $tax_price = !empty($requests_data['tax_price']) ? $requests_data['tax_price'] : '';
                $billing = $requests_data['billing_info'];

                $products = $cartlist['product_list'];
            } elseif (!empty($customer_id)) {
                $cart_list['customer_id']   = $customer_id;
                $request->merge($requests_data);
                $cartt = new ApiController();
                $cartlist_response = $cartt->cart_list($request, $slug);
                $cartlist = (array)$cartlist_response->getData()->data;
                if (empty($cartlist['product_list'])) {
                    return Utility::error(['message' => 'Cart is empty.']);
                }

                $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
                $final_sub_total_price = !empty($cartlist['total_sub_price']) ? $cartlist['total_sub_price'] : 0;
                $final_price = $cartlist['total_final_price'];
                $tax_price = !empty($requests_data['tax_price']) ? $requests_data['tax_price'] : '';
                $billing = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
                $products = $cartlist['product_list'];
            } else {
                return Utility::error(['message' => 'User not found.']);
            }

            $coupon_price = 0;
            // coupon api call
            if (!empty($requests_data['coupon_info'])) {
                $coupon_data = $requests_data['coupon_info'];
                $apply_coupon = [
                    'coupon_code' => $coupon_data['coupon_code'],
                    'sub_total' => $cartlist_final_price,
                    'slug' => $requests_data['slug']

                ];
                $request->merge($apply_coupon);
                $couponss = new ApiController();
                $apply_coupon_response = $couponss->apply_coupon($request, $slug);
                $apply_coupon = (array)$apply_coupon_response->getData()->data;

                $order_array['coupon']['message'] = $apply_coupon['message'];
                $order_array['coupon']['status'] = false;
                if (!empty($apply_coupon['final_price'])) {
                    $cartlist_final_price = $apply_coupon['final_price'];
                    $coupon_price = $apply_coupon['amount'];
                    $order_array['coupon']['status'] = true;
                }
            } elseif (!empty($requests_data['cartlist']['coupon_info'])) {
                $coupon_data = is_object( $requests_data['cartlist']['coupon_info']) ? (array) $requests_data['cartlist']['coupon_info'] :  $requests_data['cartlist']['coupon_info'];

                $apply_coupon = [
                    'coupon_code' => $coupon_data['coupon_code'],
                    'sub_total' => $cartlist_final_price,
                    'slug' => $requests_data['slug']

                ];
                $request->merge($apply_coupon);
                $couponss = new ApiController();
                $apply_coupon_response = $couponss->apply_coupon($request, $slug);
                $apply_coupon = (array)$apply_coupon_response->getData()->data;
                $order_array['coupon']['message'] = $apply_coupon['message'];
                $order_array['coupon']['status'] = false;
                if (!empty($apply_coupon['final_price'])) {
                    $cartlist_final_price = $apply_coupon['final_price'];
                    $coupon_price = $apply_coupon['amount'];
                    $order_array['coupon']['status'] = true;
                }
            }

            $delivery_price = 0;
            if ($plan->shipping_method == 'on') {
                if (!empty($request->method_id)) {
                    $del_charge = new CartController();
                    $delivery_charge = $del_charge->get_shipping_method($request, $slug);
                    $content = $delivery_charge->getContent();
                    $data = json_decode($content, true);
                    $delivery_price = $data['shipping_final_price'];
                    $tax_price = $requests_data['tax_price'] ?? 0;
                } 
                // else {
                //     return Utility::error(['message' => __('Shipping Method not found')]);
                // }
            } else {
                if (!empty($tax_price)) {
                    $tax_price = $tax_price;
                }else{
                    $tax_price = 0;
                }
            }

            $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();

            // Order stock decrease start
            
            /*
             * P01-COMMERCE-SEC-01. A BASKET WITH A NON-POSITIVE LINE IS NOT PRICED AND NOT SHIPPED.
             *
             * Placed here because here it is free: the loop below is the first thing that touches stock,
             * and the order row is not written until ~200 lines further on, so a refusal leaves nothing
             * behind. It is the same position assertCartAvailable() occupies in
             * PaymentController::processOrder, and the same reasoning.
             *
             * The decrement below is `$remain_stock = $Product->product_stock - $qtyy`, so a negative
             * quantity ADDS stock while the same line reduces every total it is summed into. See
             * App\Http\Controllers\Concerns\GuardsGatewayReturn::basketHasUnusableQuantity() for why
             * the initiation-time guard is not enough on its own: the cart stays mutable across the
             * gateway round trip, and two vendored module routes can still write such a row.
             */
            if ($this->basketHasUnusableQuantity($products)) {
                return $this->gatewayReturnQuantityRefusal($request, 'easebuzz');
            }

            /*
             * AND THE BASKET MUST BE THIS STORE'S -- product AND variant. P01-COMMERCE-SEC-03.
             *
             * Wired here, immediately after the quantity guard, because both questions belong in
             * the same place: before any stock row moves and before any order is priced from the
             * basket. See GuardsGatewayReturn::basketHasForeignProduct(); the variant half exists
             * because this handler's own stock loop resolves its variant as
             * `ProductVariant::where('id', $variant_id)->where('product_id', $product_id)` -- the
             * caller's own two values, with `ProductVariant` scoped by nothing -- so scoping the
             * product would not have scoped the variant.
             *
             * MEASURED on the sibling API route before this guard existed: a store-A cart row
             * naming store B's variant product took store B's `product_variants.stock` from 50 to
             * 43 on an ordinary checkout. The row reaches `carts` from any writer that is not
             * `add-cart` -- two vendored module routes write `product_id` straight from the
             * request (S30/S32) -- so closing the cart entry does not retire this guard.
             */
            if ($this->basketHasForeignProduct($products, $store)) {
                return $this->gatewayReturnForeignProductRefusal($request, 'easebuzz');
            }
            $prodduct_id_array = [];
            if (!empty($products)) {
                foreach ($products as $key => $product) {
                    $prodduct_id_array[] = $product->product_id;

                    $product_id = $product->product_id;
                    $variant_id = $product->variant_id;
                    $qtyy = !empty($product->qty) ? $product->qty : 0;

                    $Product = Product::where('id', $product_id)->first();
                    $datas = Product::find($product_id);
                    if (($settings['stock_management'] ?? null) === 'on')
                    {
                        if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {
                            $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();
                            $variationOptions = explode(',', $ProductStock->variation_option);
                            $option = in_array('manage_stock', $variationOptions);
                            if (!empty($ProductStock)) {
                                if($option == true)
                                {
                                    $remain_stock = $ProductStock->stock - $qtyy;
                                    $ProductStock->stock = $remain_stock;
                                    $ProductStock->save();

                                    if($ProductStock->stock <= $ProductStock->low_stock_threshold)
                                    {
                                        if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock",json_decode($settings['notification'] ?? '[]')))
                                        {
                                            if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                            {
                                                Utility::variant_low_stock_threshold($product,$ProductStock,$settings);
                                            }

                                        }
                                    }
                                    if($ProductStock->stock <= ($settings['out_of_stock_threshold'] ?? '0'))
                                    {
                                        if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock",json_decode($settings['notification'] ?? '[]')))
                                        {
                                            if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                            {
                                                Utility::variant_out_of_stock($product,$ProductStock,$settings);
                                            }
                                        }
                                    }
                                }
                                else
                                {
                                    $remain_stock = $datas->product_stock - $qtyy;
                                    $datas->product_stock = $remain_stock;
                                    $datas->save();
                                    if($datas->product_stock <= $datas->low_stock_threshold)
                                    {
                                        if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock",json_decode($settings['notification'] ?? '[]')))
                                        {
                                            if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                            {
                                                Utility::variant_low_stock_threshold($product,$datas,$settings);
                                            }

                                        }
                                    }
                                    if($datas->product_stock <= ($settings['out_of_stock_threshold'] ?? '0'))
                                    {
                                        if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock",json_decode($settings['notification'] ?? '[]')))
                                        {
                                            if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                            {
                                                Utility::variant_out_of_stock($product,$datas,$settings);
                                            }
                                        }
                                    }
                                    if($datas->product_stock <= ($settings['out_of_stock_threshold'] ?? '0') && $datas->stock_order_status == 'notify_customer')
                                    {
                                        //Stock Mail
                                        $order_email = $billing['email'];
                                        $owner=User::find($store->created_by);
                                        $ProductId    = '';

                                        try
                                        {
                                            $dArr = [
                                                'item_variable' => $Product->id,
                                                'product_name' => $Product->name,
                                                'customer_name' => $billing['firstname'],
                                            ];

                                            // Send Email
                                            $resp = Utility::sendEmailTemplate('Stock Status', $order_email, $dArr, $owner,$store, $ProductId);
                                        }
                                        catch(\Exception $e)
                                        {
                                            $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                                        }
                                        try
                                        {
                                            $mobile_no =$request['billing_info']['billing_user_telephone'];
                                            $customer_name =$request['billing_info']['firstname'];
                                            $msg =   __("Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name. ");
                                            $resp  = Utility::SendMsgs('Stock Status', $mobile_no, $msg);
                                        }
                                        catch(\Exception $e)
                                        {
                                            $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
                                        }
                                    }
                                }
                            } else {
                                return Utility::error(['message' => 'Product not found .']);
                            }
                        } elseif (!empty($product_id) && $product_id != 0) {

                            if (!empty($Product)) {
                                $remain_stock = $Product->product_stock - $qtyy;
                                $Product->product_stock = $remain_stock;
                                $Product->save();
                                if($Product->product_stock <= $Product->low_stock_threshold)
                                {
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock",json_decode($settings['notification'] ?? '[]')))
                                    {
                                        if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                        {
                                            Utility::low_stock_threshold($Product,$settings);
                                        }
                                    }
                                }

                                if($Product->product_stock <= ($settings['out_of_stock_threshold'] ?? '0'))
                                {
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock",json_decode($settings['notification'] ?? '[]')))
                                    {
                                        if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                        {
                                            Utility::out_of_stock($Product,$settings);
                                        }
                                    }
                                }

                                if($Product->product_stock <= ($settings['out_of_stock_threshold'] ?? '0') && $Product->stock_order_status == 'notify_customer')
                                {
                                    //Stock Mail
                                    $order_email = $billing['email'];
                                    $owner=User::find($store->created_by);
                                    $ProductId    = '';

                                    try
                                    {
                                    $dArr = [
                                    'item_variable' => $Product->id,
                                    'product_name' => $Product->name,
                                    'customer_name' => $billing['firstname'],
                                    ];

                                    // Send Email
                                    $resp = Utility::sendEmailTemplate('Stock Status', $order_email, $dArr, $owner,$store, $ProductId);
                                    }
                                    catch(\Exception $e)
                                    {
                                    $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                                    }
                                    try
                                    {
                                        $mobile_no =$request['billing_info']['billing_user_telephone'];
                                        $customer_name =$request['billing_info']['firstname'];
                                        $msg =   __("Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name. ");
                                        $resp  = Utility::SendMsgs('Stock Status', $mobile_no, $msg);
                                    }
                                    catch(\Exception $e)
                                    {
                                        $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
                                    }
                                }

                            } else {
                                return Utility::error(['message' => __('Product not found .')]);
                            }
                        } else {
                            return Utility::error(['message' => 'Please fill proper product json field .']);
                        }
                    }
                    // remove from cart
                    Cart::where('customer_id', $request->customer_id)->where('product_id', $product_id)->where('variant_id', $variant_id)->where('store_id',$store->id)->delete();
                }
            }
            if (isset($request->customer_id) && !empty($request->customer_id)) {
                // P01-COMMERCE-SEC-01, EXAMINED AND DELIBERATELY LEFT AS IS. This delete carries no
                // store predicate, unlike the per-line delete above it, so on its face an order in this
                // store empties the same shopper's basket in every other store. Adding
                // ->where('store_id', $store->id) was tried and REVERTED because it could not be shown
                // to change any outcome: Cart is in config/tenancy.php's scoped_models, and on both
                // principals that reach this line -- an authenticated customer and a cookie guest -- a
                // tenant resolves, so StoreScope already adds the predicate. Measured both ways: the
                // other store's rows survive with and without it. It is recorded as a residual rather
                // than shipped unproven, because null_tenant is 'open': a principal that resolved NO
                // tenant would get no predicate from either source. See the package report.
                Cart::where('customer_id', $request->customer_id)->delete();
            }
            // Order stock decrease end
            if (!empty($prodduct_id_array)) {
                $prodduct_id_array = $prodduct_id_array = array_unique($prodduct_id_array);
                $prodduct_id_array = implode(',', $prodduct_id_array);
            } else {
                $prodduct_id_array = '';
            }

            $product_reward_point = 1;

            $product_order_id  = '0' . date('YmdHis');
            $is_guest = 1;
            if (auth('customers')->check()) {
                $product_order_id  = $request->customer_id . date('YmdHis');
                $is_guest = 0;
            }

            // add in  Order table  start
            $order = new Order();
            $order->product_order_id = $product_order_id;
            $order->order_date = date('Y-m-d H:i:s');
            $order->customer_id = !empty($request->customer_id) ? $request->customer_id : 0;
            $order->is_guest = $is_guest;
            $order->product_id = $prodduct_id_array;
            $order->product_json = json_encode($products);
            $order->product_price = $final_sub_total_price;
            $order->coupon_price = $coupon_price;
            $order->delivery_price = $delivery_price;
            $order->tax_price = $tax_price;
            if (!auth('customers')->user()) {
                if ($plan->shipping_method == "on") {
                    $order->final_price = $final_sub_total_price;
                } else {
                    $order->final_price = $final_price + $tax_price - $coupon_price;
                }
            }elseif (module_is_active('PartialPayments') && \Auth::guard('customers')->user() && (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on')) {
                $order->final_price = $requests_data['cartlist_final_price'] ?? $total_sub_price;
                $order->delivery_price = $requests_data['shipping_price'] ?? $delivery_price;
                \Workdo\PartialPayments\app\Models\OrderPartialPayments::OrderPartialPayments($order, $slug , $request);
            }
            else{
                if (module_is_active('RewardClubPoint') && isset($requests_data['club_point_is_active']) && $requests_data['club_point_is_active'] == 'on') {
                    $customerDetail = Customer::find($customer_id);
                    $rewardSetting = getAdminAllSetting($store->created_by,$store->id) ?? null;
                    $saveRewardPrice = ($customerDetail->total_club_point * $rewardSetting['reward_point_price'] ?? 0) / ($rewardSetting['reward_points'] ?? 0);
                    if ($plan->shipping_method == "on") {
                        $order->final_price = $final_sub_total_price - $saveRewardPrice;
                    } else {
                        $order->final_price = $final_price + $tax_price - $saveRewardPrice;
                    }
                } else {
                    if ($plan->shipping_method == "on") {
                        $order->final_price = $final_sub_total_price;
                    } else {
                        $order->final_price = $final_price + $tax_price - $coupon_price;
                    }
                }
            }
            event(new AddAdditionalFields($order,$request->all(),$store));
            $order->payment_comment = !empty($requests_data['payment_comment']) ? $requests_data['payment_comment'] : '';
            $order->payment_type = $requests_data['payment_type'];
            /*
             * P01-COMMERCE-SEC-01. DERIVED, NOT ASSERTED -- the same wiring PaymentController (:2436)
             * and PaymentService (:2594) already use. This was the literal `'Paid'`, with nothing
             * between the shopper's browser and this line having asked a gateway whether money moved.
             *
             * NO BEHAVIOUR CHANGES TODAY: this payment type is not in
             * PaymentVerificationService::ENFORCED_PAYMENT_TYPES, so the call returns exactly the 'Paid'
             * the literal did. What it buys is that the unverified write is LOGGED -- the exposure
             * becomes measurable -- and that enforcing this gateway later is a one-line change in that
             * service instead of another edit here. Flipping the posture is the owner's call, because
             * recording every gateway order as Pending stops merchant wallets being credited; see the
             * blocker in docs/security/P01-SECURITY-WORK-QUEUE.md.
             */
            $order->payment_status = $this->gatewayReturnPaymentStatus(
                $requests_data['payment_type'] ?? null,
                $order->final_price,
                __METHOD__
            );
            $order->delivery_id = $requests_data['method_id'] ?? 0;
            $order->delivery_comment = !empty($requests_data['delivery_comment']) ? $requests_data['delivery_comment'] : '';
            if (module_is_active('PreOrder') && \Auth::guard('customers')->user() && isset($requests_data['order_type']) && $requests_data['order_type'] == 'pre_order') {
                $order->delivered_status = 8;
            }else{
                $order->delivered_status = 0;
            }
            $order->reward_points = SetNumber($product_reward_point);
            $order->additional_note = $request->additional_note;
            $order->store_id = $store->id;
            $order->save();
            // add in  Order table end
            // Utility::paymentWebhook($order);

            $billing_city_id = 0;
            if (!empty($billing['billing_city'])) {
                $cityy = City::where('name', $billing['billing_city'])->first();
                if (!empty($cityy)) {
                    $billing_city_id = $cityy->id;
                } else {
                    $new_billing_city = new City();
                    $new_billing_city->name = $billing['billing_city'];
                    $new_billing_city->state_id = $billing['billing_state'];
                    $new_billing_city->country_id = $billing['billing_country'];
                    $new_billing_city->save();
                    $billing_city_id = $new_billing_city->id;
                }
            }

            $delivery_city_id = 0;
            if (!empty($billing['delivery_city'])) {
                $d_cityy = City::where('name', $billing['delivery_city'])->first();
                if (!empty($d_cityy)) {
                    $delivery_city_id = $d_cityy->id;
                } else {
                    $new_delivery_city = new City();
                    $new_delivery_city->name = $billing['delivery_city'];
                    $new_delivery_city->state_id = $billing['delivery_state'];
                    $new_delivery_city->country_id = $billing['delivery_country'];
                    $new_delivery_city->save();
                    $delivery_city_id = $new_delivery_city->id;
                }
            }

            $OrderBillingDetail = new OrderBillingDetail();
            $OrderBillingDetail->order_id = $order->id;
            $OrderBillingDetail->product_order_id = $order->product_order_id;
            $OrderBillingDetail->first_name = !empty($billing['firstname']) ? $billing['firstname'] : '';
            $OrderBillingDetail->last_name = !empty($billing['lastname']) ? $billing['lastname'] : '';
            $OrderBillingDetail->email = !empty($billing['email']) ? $billing['email'] : '';
            $OrderBillingDetail->telephone = !empty($billing['billing_user_telephone']) ? $billing['billing_user_telephone'] : '';
            $OrderBillingDetail->address = !empty($billing['billing_address']) ? $billing['billing_address'] : '';
            $OrderBillingDetail->postcode = !empty($billing['billing_postecode']) ? $billing['billing_postecode'] : '';
            $OrderBillingDetail->country = !empty($billing['billing_country']) ? $billing['billing_country'] : '';
            $OrderBillingDetail->state = !empty($billing['billing_state']) ? $billing['billing_state'] : '';
            $OrderBillingDetail->city = $billing_city_id;
            
            $OrderBillingDetail->delivery_address = !empty($billing['delivery_address']) ? $billing['delivery_address'] : '';
            $OrderBillingDetail->delivery_city = $delivery_city_id;
            $OrderBillingDetail->delivery_postcode = !empty($billing['delivery_postcode']) ? $billing['delivery_postcode'] : '';
            $OrderBillingDetail->delivery_country = !empty($billing['delivery_country']) ? $billing['delivery_country'] : '';
            $OrderBillingDetail->delivery_state = !empty($billing['delivery_state']) ? $billing['delivery_state'] : '';
            $OrderBillingDetail->save();

            // add in Order Coupon Detail table start
            if (!empty($requests_data['coupon_info'])) {
                $coupon_data = $requests_data['coupon_info'];
                $Coupon = Coupon::find($coupon_data['coupon_id']);
                // coupon stock decrease end

                // Order Coupon history
                $OrderCouponDetail = new OrderCouponDetail();
                $OrderCouponDetail->order_id = $order->id;
                $OrderCouponDetail->product_order_id = $order->product_order_id;
                $OrderCouponDetail->coupon_id = $coupon_data['coupon_id'];
                $OrderCouponDetail->coupon_name = $coupon_data['coupon_name'];
                $OrderCouponDetail->coupon_code = $coupon_data['coupon_code'];
                $OrderCouponDetail->coupon_discount_type = $coupon_data['coupon_discount_type'];
                $OrderCouponDetail->coupon_discount_number = $coupon_data['coupon_discount_number'];
                $OrderCouponDetail->coupon_discount_amount = $coupon_data['coupon_discount_amount'];
                $OrderCouponDetail->coupon_final_amount = $coupon_data['coupon_final_amount'];
                
                $OrderCouponDetail->save();

                // Coupon history
                $UserCoupon = new UserCoupon();
                $UserCoupon->user_id = !empty($request->user_id) ? $request->user_id : '0';
                $UserCoupon->coupon_id = $Coupon->id;
                $UserCoupon->amount = $coupon_data['coupon_discount_amount'];
                $UserCoupon->order_id = $order->id;
                $UserCoupon->date_used = now();
                
                $UserCoupon->save();

                $discount_string = '-' . $coupon_data['coupon_discount_amount'];
                $CURRENCY = Utility::GetValueByName('CURRENCY');
                $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME');
                if ($coupon_data['coupon_discount_type'] == 'flat') {
                    $discount_string .= $CURRENCY;
                } else {
                    $discount_string .= '%';
                }

                $discount_string .= ' ' . __('for all products');
                $order_array['coupon']['code'] = $coupon_data['coupon_code'];
                $order_array['coupon']['discount_string'] = $discount_string;
                $order_array['coupon']['price'] = SetNumber($coupon_data['coupon_final_amount']);
            } elseif (!empty($requests_data['cartlist']['coupon_code'])) {
                $coupon_data = is_object($requests_data['cartlist']['coupon_info']) ? (array) $requests_data['cartlist']['coupon_info'] : $requests_data['cartlist']['coupon_info'];
                $Coupon = Coupon::find($coupon_data['coupon_id']);
                if($Coupon) {
                    // coupon stock decrease end

                    // Order Coupon history
                    $OrderCouponDetail = new OrderCouponDetail();
                    $OrderCouponDetail->order_id = $order->id;
                    $OrderCouponDetail->product_order_id = $order->product_order_id;
                    $OrderCouponDetail->coupon_id = $coupon_data['coupon_id'];
                    $OrderCouponDetail->coupon_name = $coupon_data['coupon_name'];
                    $OrderCouponDetail->coupon_code = $coupon_data['coupon_code'];
                    $OrderCouponDetail->coupon_discount_type = $coupon_data['coupon_discount_type'];
                    $OrderCouponDetail->coupon_discount_number = $coupon_data['coupon_discount_number'];
                    $OrderCouponDetail->coupon_discount_amount = $coupon_data['coupon_discount_amount'];
                    $OrderCouponDetail->coupon_final_amount = $coupon_data['coupon_final_amount'];
                    
                    $OrderCouponDetail->save();

                    // Coupon history
                    $UserCoupon = new UserCoupon();
                    $UserCoupon->user_id = !empty($request->customer_id) ? $request->customer_id : null;
                    $UserCoupon->coupon_id = $Coupon->id;
                    $UserCoupon->amount = $coupon_data['coupon_discount_amount'];
                    $UserCoupon->order_id = $order->id;
                    $UserCoupon->date_used = now();
                    
                    $UserCoupon->save();
                }


                $discount_string = '-' . $coupon_data['coupon_discount_amount'];
                $CURRENCY = Utility::GetValueByName('CURRENCY');
                $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME');
                if ($coupon_data['coupon_discount_type'] == 'flat') {
                    $discount_string .= $CURRENCY;
                } else {
                    $discount_string .= '%';
                }

                $discount_string .= ' ' . __('for all products');
                $order_array['coupon']['code'] = $coupon_data['coupon_code'] ?? null;
                $order_array['coupon']['discount_string'] = $discount_string ?? null;
                $order_array['coupon']['price'] = SetNumber($coupon_data['coupon_final_amount'] ?? 0.00);
            }
            // add in Order Coupon Detail table end
            if(isset($requests_data['tax_id_value'])){
                $taxes = TaxMethod::where('tax_id',$request['tax_id_value'])->where('store_id', $store->id)->orderBy('priority', 'asc')->get();
                $other_info = !is_array($requests_data['billing_info']) ? json_decode($requests_data['billing_info']) : $requests_data['billing_info'];
                $country = !empty($other_info->delivery_country) ? $other_info->delivery_country :'';
                $state_id = !empty($other_info->delivery_state) ? $other_info->delivery_state : '';
                $city_id = !empty($other_info->delivery_city) ? $other_info->delivery_city : '';
                foreach ($taxes as $tax) {
                    $countryMatch = (!$tax->country_id || $country == $tax->country_id);
                    $stateMatch = (!$tax->state_id || $state_id == $tax->state_id);
                    $cityMatch = (!$tax->city_id || $city_id == $tax->city_id);

                    if ($countryMatch && $stateMatch && $cityMatch) {
                        $OrderTaxDetail = new OrderTaxDetail();
                        $OrderTaxDetail->order_id = $order->id;
                        $OrderTaxDetail->product_order_id = $order->product_order_id;
                        $OrderTaxDetail->tax_id = $tax->id;
                        $OrderTaxDetail->tax_name = $tax->name;
                        $OrderTaxDetail->tax_discount_amount = $tax->tax_rate;
                        $OrderTaxDetail->tax_final_amount = $requests_data['tax_price'];
                        
                        $OrderTaxDetail->save();
                    }
                }
            }

            //activity log
            ActivityLog::order_entry(['customer_id'=>$order->customer_id ,
            'order_id'=> $order->product_order_id ,
            'order_date' => $order->order_date ,
            'products' =>$order->product_id,
            'final_price' =>$order->final_price,
            'payment_type' =>$order->payment_type,
            'store_id'=>$order->store_id]);
            $other_info = $request->billing_info;

            //Order Mail
            $order_email = $OrderBillingDetail->email ?? (!empty($other_info->email) ? $other_info->email : '');
            $owner=User::find($store->created_by);
            $owner_email=$owner->email;
            $order_id    = Crypt::encrypt($order->id);

            if (module_is_active('PreOrder') && \Auth::guard('customers')->user() && isset($requests_data['order_type']) && $requests_data['order_type'] == 'pre_order') {
                \Workdo\PreOrder\app\Models\PreOrderHistory::PreOrderHistory($order, $store, $request, $order_email);
            } else {
                try
                {
                    $dArr = [
                    'order_id' => $order->product_order_id,
                    ];

                    // Send Email
                    $resp  = Utility::sendEmailTemplate('Order Created', $order_email, $dArr, $owner,$store, $order_id);
                    $resp1 = Utility::sendEmailTemplate('Order Created For Owner', $owner_email, $dArr,$owner, $store, $order_id);
                }
                catch(\Exception $e)
                {
                    $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                }
            }

            foreach ($products as $product) {
                $product_data = Product::find($product->product_id);

                if ($product_data) {
                    if ($product_data->variant_product == 0) {
                        if ($product_data->track_stock == 1) {
                            OrderNote::order_note_data([
                                'customer_id' => !empty($request->customer_id) ? $request->customer_id : '0',
                                'order_id' => $order->id,
                                'product_name' => !empty($product_data->name)?$product_data->name: '',
                                'variant_product' => $product_data->variant_product,
                                'product_stock' => !empty($product_data->product_stock) ? $product_data->product_stock : '',
                                'status' => 'Stock Manage',
                                'store_id' => $order->store_id,
                            ]);
                        }
                    } else {
                        $variant_data = ProductVariant::find($product->variant_id);
                        $variationOptions = explode(',', $variant_data->variation_option);
                        $option = in_array('manage_stock', $variationOptions);
                        if ($option == true) {
                            OrderNote::order_note_data([
                                'customer_id' => !empty($request->customer_id) ? $request->customer_id : '0',
                                'order_id' => !empty($order->id) ? $order->id : '',
                                'product_name' => !empty($product_data->name)?$product_data->name: '',
                                'variant_product' => $product_data->variant_product,
                                'product_variant_name' => !empty($variant_data->variant) ? $variant_data->variant : '',
                                'product_stock' => !empty($variant_data->stock) ? $variant_data->stock : '',
                                'status' => 'Stock Manage',
                                'store_id' => $order->store_id,
                            ]);
                        }
                    }
                }
            }

            OrderNote::order_note_data([
                'customer_id' => !empty($request->customer_id) ? $request->customer_id : '0',
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'delivery_status' => 'Pending',
                'status' => 'Order Created',
                'store_id' => $order->store_id
            ]);
            try{
                $msg = __("Hello, Welcome to $store->name .Hi,your order id is $order->product_order_id, Thank you for Shopping We received your purchase request, we'll be in touch shortly!. ") ;
                $mess = Utility::SendMsgs('Order Created',$OrderBillingDetail->telephone, $msg);
            } catch(\Exception $e)
            {
                $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
            }
            // add in Order Tax Detail table end
            event(new GetProductStatus($OrderBillingDetail,$order,$owner));
            if(auth('customers')->user() &&  module_is_active('ProductAffiliate'))
            {
                Utility::affiliateTransaction($order);
            }
            if (!empty($order) && !empty($OrderBillingDetail)) {
                $cart = Cookie::get('cart');
                $cart = Cart::where('cookie_session_id', $cart)->delete();
                
                if (auth('customers')->user()) {
                    event(new AddRewardClubPoint($requests_data,$order,$slug));
                }
                return redirect()->route('order.complete', $slug)->with('data', $order->product_order_id);
            } else {
                return Utility::error(['message' => 'Somthing went wrong.']);
            }
        }else{
            return redirect()->back()->with('error',__('Transaction has been failed'));
        }
    }

}
