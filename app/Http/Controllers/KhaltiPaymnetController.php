<?php

namespace App\Http\Controllers;

use App\Khalti\Khalti;
use App\Models\User;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use App\Models\Coupon;
use App\Events\AddAdditionalFields;
use App\Models\PlanUserCoupon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
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
use Session;
use App\Models\OrderNote;
use App\Models\Customer;
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
use App\Facades\ModuleFacade as Module;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Concerns\GuardsGatewayReturn;

class KhaltiPaymnetController extends Controller
{
    use ApiResponser;
    use GuardsGatewayReturn;
    //
    public function planPayWithKhalti(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $api_key = isset($payment_setting['nepalste_public_key']) ? $payment_setting['nepalste_public_key'] : '';
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);

        $plan = Plan::find($planID);
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

        config(
            [
                'khalti.public_key' => isset($payment_setting['khalti_public_key']) ? $payment_setting['khalti_public_key'] : '',
                'khalti.sck' => isset($payment_setting['khalti_secret_key']) ? $payment_setting['khalti_secret_key'] : '',
            ]
        );

        if ($plan) {
            $price     = $plan->price;
            try {
                if (!empty($request->coupon_code))
                {

                    $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon_code])->where('is_active', '1')->first();
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
                $authuser       = \Auth::user();
                if($price <= 0){
                    $assignPlan = $authuser->assignPlan($plan->id);
                    if($assignPlan['is_success']){
                        $amount = $price;
                        return $amount;
                    }else{
                        return response()->json([
                            'success' => true,
                            'inputs' => __('Something into warning.'),
                        ]);
                    }
                }
                $secret = !empty($payment_setting['khalti_secret_key'])?$payment_setting['khalti_secret_key']:'';


                $amount = $price;
                return $amount;

            } catch (\Exception $e) {
                return redirect()->route('plan.index')->with('error', __($e->getMessage()));
            }
        } else {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }
    }

    public function planGetKhaltiStatus(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);

        $plan = Plan::find($planID);

        $payload = $request->payload;
        $price = $plan->price;
        $token = is_array($payload) ? ($payload['token'] ?? null) : null;
        $secret = !empty($payment_setting['khalti_secret_key'])?$payment_setting['khalti_secret_key']:'';
        /*
         * P1-PAY-03, THE SAME DEFECT ON THE PLATFORM'S OWN REVENUE PATH. This read
         * `$amount = $payload['amount']` and verified the caller's figure, exactly as the
         * storefront handler did -- so a buyer could activate a plan of any price by presenting
         * the token for a small Khalti payment. It also verified HERE, ABOVE the coupon block,
         * where `$price` is still the list price and not the price this request will actually
         * charge. Both are fixed by moving the call below that block and asking about the
         * server's own figure; nothing between here and there reads `$response`.
         */

        if (!empty($request->coupon_code))
        {

            $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon_code])->where('is_active', '1')->first();
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
                // return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
            }
        }
        $coupanCode = $request->coupon_code;
        $user = \Auth::user();

        // P1-PAY-03. `$price` is final from here: list price, less a coupon this server re-read
        // and re-applied from its own rows. That is the only figure Khalti may be asked about.
        $expectedPaisa = (int) round(((float) $price) * 100);

        /*
         * RESOLVED FROM THE CONTAINER, NOT `new Khalti()`, and that is the only reason this path
         * is testable at all. App\Khalti\Khalti::verifyPayment() opens a curl handle to
         * khalti.com; with the class constructed directly, no test could reach a single line past
         * it without making a real outbound request, which is why GatewayReturnTrustBoundaryTest
         * records that "khalti stops at verifyPayment()". `app()` lets a test bind a double and
         * drive the amount binding, the rejection and the unreachable branch for real. It
         * constructs the same object in production -- Khalti has no constructor arguments.
         */
        $khalti = app(Khalti::class);
        $response = $khalti->verifyPayment($secret, $token, $expectedPaisa);
        $response = is_array($response) ? $response : [];

        $planVerification = (new \App\Services\PaymentVerificationService())
            ->verifyKhaltiCapture($response, $expectedPaisa);

        /*
         * NO PLAN IS ACTIVATED WITHOUT A CONFIRMED CAPTURE, and UNVERIFIABLE is not confirmed.
         * This differs from the storefront handler above, which writes a Pending order on an
         * unreachable gateway: there the reconciliation record protects a charged customer whose
         * goods are owed. Here the "goods" are a subscription the operator can grant at any time,
         * so the safe failure is to grant nothing and let the operator settle it by hand. A free
         * plan never reaches this path -- planPayWithKhalti() assigns it directly when the price
         * is not positive -- so a non-positive price here is refused rather than verified.
         */
        if($planVerification->isVerified())
        {
            $order = new PlanOrder();
            $order->order_id = $orderID;
            $order->name = $user->name;
            $order->card_number = '';
            $order->card_exp_month = '';
            $order->card_exp_year = '';
            $order->plan_name = $plan->name;
            $order->plan_id = $plan->id;
            $order->price = $price;
            $order->price_currency = $currency;
            $order->txn_id = time();
            $order->payment_type = __('Khalti');
            $order->payment_status = 'success';
            $order->txn_id = '';
            $order->receipt = '';
            $order->user_id = $user->id;
            $order->save();
            $type = 'Subscription';

            $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$coupanCode])->where('is_active', '1')->first();
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
                return $response;
                return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
            } else
            {
                return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
            }
        }
        else {
            \Illuminate\Support\Facades\Log::warning('Khalti plan purchase NOT activated: the capture was not confirmed for the server price', [
                'site' => __METHOD__,
                'user_id' => $user->id ?? null,
                'plan_id' => $plan->id ?? null,
                'expected_total' => (string) $price,
                'outcome' => $planVerification->outcome,
                'reason' => $planVerification->reason,
            ]);

            return redirect()->route('plan.index')->with('error', __('Transaction has been failed.'));
        }
    }

    public function getOrderPaymentStatus(Request $request,$slug)
    {
        /*
         * P01-COMMERCE-SEC-01. THE CHECKOUT STATE IS THE SERVER'S, OR THERE IS NO ORDER.
         *
         * This block used to fall back to `$request->all()` when the session held nothing, which
         * handed the caller the prices, the shipping, the coupon, the cart, the STORE the order is
         * written to and the CUSTOMER it is written for -- by simply not sending a session cookie.
         * See App\Http\Controllers\Concerns\GuardsGatewayReturn for why reading only the stored
         * state is sufficient rather than merely narrower: that state is the request as it stood
         * AFTER PaymentController::processOrder priced the cart, computed the shipping server-side
         * and refused a non-positive quantity, so this handler inherits all of it.
         */
        $requests_data = $this->storedCheckoutState($request, 'khalti');

        if ($requests_data === null) {
            return $this->gatewayReturnRefusal();
        }

        /*
         * P1-PAY-02. ONE CHECKOUT, AND ONE PAYMENT, PRODUCE AT MOST ONE ORDER -- and the control is
         * NOT the session. `Session::forget()` below makes the stored state single-use, which stops a
         * SEQUENTIAL replay; it cannot stop two returns that OVERLAP, because Laravel holds no session
         * lock for the life of these requests and both read the state before either writes the forget.
         * That is the same read-check-use straddle already fixed on the Paymera path, where it was
         * measured to produce a second order, a second stock decrement and a second wallet credit.
         *
         * Taken HERE, above every write and above the forget, so a refusal costs nothing. See
         * GuardsGatewayReturn::claimGatewayReturn() for the two keys and for which gateways supply a
         * transaction reference and which do not.
         */
        if (! $this->claimGatewayReturn($request, 'khalti', $requests_data)) {
            return $this->gatewayReturnReplayRefusal();
        }
        $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
        $store = getStore($slug);
        \Session::forget('request_data');
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
                        return $this->error(['message' => __('This coupon has expired.')]);
                    }
                }
            }
        }
        if (!auth('customers')->user()) {
            $rules = [
                'billing_info' => 'required',
                'payment_type' => 'required',
                //'delivery_id' => 'required',
            ];
        } else {
            $rules = [
                'customer_id' => 'required',
                'billing_info' => 'required',
                'payment_type' => 'required',
                //'delivery_id' => 'required',
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
        // cart list api call
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
            //  else {
            //     return $this->error(['message' => __('Shipping Method not found')]);
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
            return $this->gatewayReturnQuantityRefusal($request, 'khalti');
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
            return $this->gatewayReturnForeignProductRefusal($request, 'khalti');
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
                        $variationOptions = explode(',', (string) ($ProductStock->variation_option ?? ''));   // P1-PAY-01: null-safe; a bare deref here throws ErrorException
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
                            return $this->error(['message' => __('Product not found .')]);
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
                            return $this->error(['message' => __('Product not found .')]);
                        }
                    } else {
                        return $this->error(['message' => __('Please fill proper product json field .')]);
                    }
                }
                // remove from cart
                // P1-SEC-24: the buyer's basket is the AUTHENTICATED customer's. `$request->customer_id` here is the stored checkout
                // state -- '' for a guest (MySQL reads '' = 0) or the id of whoever's e-mail was typed -- and is not an owner.
                Cart::forCustomer(auth('customers')->id())->where('product_id', $product_id)->where('variant_id', $variant_id)->where('store_id',$store->id)->delete();
            }
        }
        if (isset($request->customer_id) && !empty($request->customer_id)) {
            // P1-SEC-24. The id is the AUTHENTICATED buyer's (it was the stored checkout state's), and a Customer belongs to one
            // store (customers.store_id), so this reaches that buyer's own basket. The earlier note here said StoreScope adds a
            // store predicate for every principal that reaches this line; that was measured with actingAs($customer, 'customers'),
            // which also makes `customers` the DEFAULT guard and so resolves a tenant. In production the default guard is `web`, a
            // storefront request resolves none, and with null_tenant 'open' the scope adds nothing -- do not rely on it.
            Cart::forCustomer(auth('customers')->id())->delete();
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
        // F46. The AUTHENTICATED customer's id, never $request->customer_id. This is one of NINE byte-for-
        // byte copies of the same checkout block; see app/Services/PaymentService.php for the full
        // reasoning. The guest branch is untouched, and PosController is deliberately NOT changed -- it
        // uses the MERCHANT guard and the merchant legitimately picks the customer.
        $orderCustomerId = ! empty($request->customer_id) ? $request->customer_id : 0;
        if (auth('customers')->check()) {
            $orderCustomerId = auth('customers')->id();
            $product_order_id  = $orderCustomerId . date('YmdHis');
            $is_guest = 0;
        }

        $payload = $request->payload;
        $theme_name = !empty(APP_THEME()) ? APP_THEME() : env('DATA_INSERT_APP_THEME');
        $secret = Utility::GetValueByName('khalti_secret_key', $store->id);
        $token = is_array($payload) ? ($payload['token'] ?? null) : null;

        /*
         * P1-PAY-03. THE AMOUNT KHALTI IS ASKED ABOUT IS THE SERVER'S, NOT THE SHOPPER'S.
         *
         * This was `$amount = $payload['amount'];` -- the caller's own field -- handed straight to
         * `payment/verify/`. Khalti's verify endpoint confirms the token AND amount as a PAIR, so
         * posting the caller's figure asks the gateway the caller's question: a buyer who paid one
         * unit could present that token with a matching one-unit amount, collect a 200, and have
         * this handler write the order at the SERVER's full basket price. The verification was
         * real; it was just a verification of the wrong number.
         *
         * `cartlist_final_price` in the stored checkout state is the full payable -- products plus
         * the SERVER's delivery price plus the SERVER's tax, assembled by
         * PaymentController::prepareFinalRequestData(). It is also exactly what the Khalti widget
         * was initiated with: resources/views/payment/khalti.blade.php:16 reads
         * `var amount = {{$data['cartlist_final_price']}} * 100`, so this asks about the same
         * figure the shopper was charged, in the same unit (paisa).
         *
         * Owner decision D2: "a return URL, redirect or client flag proves nothing; require
         * provider-side verification of ownership, reference, amount, currency and status".
         */
        $serverPayable = $this->serverPayableAmount($requests_data);

        if ($serverPayable === null) {
            \Illuminate\Support\Facades\Log::warning('Khalti verification refused: no server-computed payable in the stored state.', [
                'store_id' => $store->id ?? null,
                'customer_id' => $customer_id ?: null,
            ]);

            return $this->gatewayReturnRefusal();
        }

        $expectedPaisa = (int) round($serverPayable * 100);

        // Container-resolved for the reason recorded on planGetKhaltiStatus(): it is what makes
        // this path drivable in a test without an outbound request.
        $khalti = app(Khalti::class);
        $response = $khalti->verifyPayment($secret, $token, $expectedPaisa);
        $response = is_array($response) ? $response : [];

        $khaltiVerification = $this->gatewayReturnVerifier()->verifyKhaltiCapture($response, $expectedPaisa);

        /*
         * UNVERIFIABLE STILL WRITES THE ORDER. A failed curl leaves this server unable to say
         * whether money moved, and the old `else` returned "Transaction has been failed" with no
         * order row at all -- so a Khalti payment that completed while the verify call did not
         * left a charged customer with nothing to reconcile against. The order is written
         * (Pending, because no verification was recorded) and the SHOPPER IS STILL TOLD IT
         * FAILED -- see the refusal below, just before the thank-you payload. A rejection, where
         * Khalti was reached and said no, is refused exactly as before.
         */
        $khaltiUnverifiable = $khaltiVerification->outcome === \App\Services\PaymentVerificationResult::UNVERIFIABLE;

        if($khaltiVerification->isVerified() || $khaltiUnverifiable)
        {
        // add in  Order table  start
        $order = new Order();
        $order->product_order_id = $product_order_id;
        $order->order_date = date('Y-m-d H:i:s');
        $order->customer_id = $orderCustomerId;   // F46
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
        }else{
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
         * THIS PARAGRAPH USED TO SAY THE OPPOSITE OF WHAT THE CODE DOES, and the correction matters
         * because it is the paragraph a reviewer reads to decide whether this path is safe. It said:
         * "NO BEHAVIOUR CHANGES TODAY: this payment type is not in ENFORCED_PAYMENT_TYPES, so the call
         * returns exactly the 'Paid' the literal did", and that flipping the posture was still the
         * owner's to choose.
         *
         * Both were true when written and are now false. The owner CHOSE it -- the Phase 01 final
         * execution directive, "any unverified gateway payment must remain Pending, never Paid" -- and
         * resolveOrderPaymentStatus() now returns STATUS_PENDING on its no-record branch. That service's
         * own docblock quotes the superseded sentence and says so.
         *
         * WHAT ACTUALLY HAPPENS HERE, and it is the opposite of the sentence above in BOTH of its
         * clauses: khalti IS in ENFORCED_PAYMENT_TYPES, and this handler DOES record a verification (see
         * the paragraph below). So an order here is Paid only when a verification exists for this
         * checkout AND the captured amount reconciles against the order total -- the enforced-type
         * comparison in PaymentVerificationService. Without a record it is PENDING. This gateway is the
         * best-guarded of its family on amount, which is why the stale claim that nothing changes here
         * was the most misleading of the seven copies.
         * Pinned by tests/Feature/Commerce/PaymentStatusDerivationTest.php and
         * tests/Feature/Commerce/UnverifiedGatewayStaysPendingTest.php, so the claim above is checkable
         * rather than another comment to trust.
         */
        /*
         * RECORDING IS WHAT MAKES A VERIFIED KHALTI ORDER PAID AGAIN. Until this line Khalti
         * verified against the provider and recorded NOTHING, so after the no-record rule landed
         * in resolveOrderPaymentStatus() every Khalti order -- including genuinely captured ones --
         * was written Pending. That was a commercial regression introduced by the P1-PAY-03
         * default inversion, not a decision; this closes it on the only ground D2 accepts, a
         * provider-side confirmation of the server's own amount.
         *
         * RECORDED ONE LINE ABOVE ITS CONSUMER, AND THE POSITION IS THE CONTROL. The first cut
         * recorded it beside the verify call, ~70 lines up. An independent review showed two
         * things wrong with that, and the second is the one that mattered:
         *
         *   1. recordVerification() writes to the SESSION and nothing else clears it, so any
         *      THROW between there and here leaves a usable record behind for the next request.
         *      One is caller-influenced: the RewardClubPoint branch below divides by
         *      $rewardSetting['reward_points'] ?? 0, and `club_point_is_active` comes from the
         *      stored state. Laravel renders the 500 inside the pipeline, so the session is saved.
         *
         *   2. THE UNVERIFIABLE BRANCH CONSUMES WHATEVER IS IN THE SESSION. gatewayReturnPaymentStatus()
         *      calls consumeVerification() unconditionally, so a stray khalti record from an
         *      earlier aborted request would have made the reconciliation order PAID -- the exact
         *      opposite of what that branch's own comment asserts. A comment is not an invariant;
         *      recording here makes it one, because on the unverifiable path nothing has been put
         *      in the session on this request at all.
         */
        if ($khaltiVerification->isVerified()) {
            $this->gatewayReturnVerifier()->recordVerification(
                'khalti',
                $khaltiVerification,
                $serverPayable,
                $this->checkoutNonceOf($requests_data)
            );
        }

        $order->payment_status = $this->gatewayReturnPaymentStatus(
            $requests_data['payment_type'] ?? null,
            $order->final_price,
            __METHOD__,
            $this->checkoutNonceOf($requests_data)
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
        if (module_is_active('CheckoutAttachment')) {
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentOrderStore($order, $slug , $request);
        }
        // Utility::paymentWebhook($order);

        // P1-ORD-15: the posted values are IDS and this looked the city up by NAME. One resolver,
        // which also keeps a posted NAME out of cities.state_id / country_id (foreign keys).
        $billing_city_id = \App\Services\BillingGeography::resolveCityId(
            $billing['billing_city'] ?? null,
            $billing['billing_state'] ?? null,
            $billing['billing_country'] ?? null
        );

        // P1-ORD-15: the posted values are IDS and this looked the city up by NAME. One resolver,
        // which also keeps a posted NAME out of cities.state_id / country_id (foreign keys).
        $delivery_city_id = \App\Services\BillingGeography::resolveCityId(
            $billing['delivery_city'] ?? null,
            $billing['delivery_state'] ?? null,
            $billing['delivery_country'] ?? null
        );

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
            $UserCoupon->user_id = !empty($requests_data['customer_id']) ? $requests_data['customer_id'] : null;
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
            // coupon stock decrease end
            if($Coupon) {
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
        if($requests_data['tax_id_value'] ?? ''){
            $taxes = TaxMethod::where('tax_id',$request['tax_id_value'])->where('store_id', $store->id)->orderBy('priority', 'asc')->get();
            $other_info = is_string($requests_data['billing_info']) ? json_decode($requests_data['billing_info']) : $requests_data['billing_info'];
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
                $resp = Utility::sendEmailTemplate('Order Created', $order_email, $dArr, $owner,$store, $order_id);
                $resp1=Utility::sendEmailTemplate('Order Created For Owner', $owner_email, $dArr,$owner, $store, $order_id);
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
                    $variationOptions = explode(',', (string) ($variant_data->variation_option ?? ''));   // P1-PAY-01: null-safe; a bare deref here throws ErrorException
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

        if (!empty($order) && !empty($OrderBillingDetail)) {

            $order_array['order_id'] = $order->id;

            /*
             * P1-SEC-22 (f). KHALTI WAS THE ONE GATEWAY THAT LEFT THE GUEST'S CART ROWS BEHIND, and the
             * task row had the mechanism backwards: it said the other gateways "end with the cookie clear"
             * and Khalti does not. MEASURED, the opposite is true. Eight host sites run
             *
             *     $cart = Cookie::get('cart'); Cart::forGuestCookie($cart)->delete();
             *
             * (PaymentService:3053, PaymentController:2982, AuthorizeNet:1179, PayU:881, NMI:1221,
             * Easebuzz:1026, PaiementPro:991, CinetPay:1043) -- they DELETE the rows, and the cookie poke
             * those four still carry is COMMENTED OUT as the older, weaker idiom. Khalti did only the poke.
             *
             * The cookie holds a guest's `cookie_session_id` (Cart.php:218-228), so poking it to `'[]'`
             * makes the basket unreachable from the browser but leaves the rows in `carts` -- where they
             * sit as ABANDONED-CART rows for an order that was in fact paid. A merchant chasing abandoned
             * baskets would chase a customer who had already bought. The delete is added here so all nine
             * agree; the poke is kept because Khalti's client navigates from a JSON response rather than a
             * redirect and nothing establishes that it is redundant there.
             */
            $guestCartCookie = \Cookie::get('cart');
            Cart::forGuestCookie($guestCartCookie)->delete();

            $cart_array = [];
            $cart_json = json_encode($cart_array);
            \Cookie::queue('cart', $cart_json, 1440);
            /*
             * P1-PAY-03. THE ORDER EXISTS SO IT CAN BE RECONCILED; THE SHOPPER IS NOT TOLD IT
             * SUCCEEDED. Reached only when the verify call could not be completed, so this server
             * does not know whether money moved. The order was written above (Pending), the
             * basket was cleared, and the buyer gets the same failure response the old `else`
             * returned -- the difference is that there is now a row for a merchant to settle
             * against instead of a charge with no record. No thank-you page and no
             * OrderCompleteBinding: this buyer is not being sent to one.
             */
            if (! $khaltiVerification->isVerified()) {
                \Illuminate\Support\Facades\Log::critical('Khalti order written for RECONCILIATION: the gateway could not be asked whether money moved', [
                    'site' => __METHOD__,
                    'order_id' => $order->id,
                    'store_id' => $order->store_id,
                    'expected_total' => (string) $serverPayable,
                    'reason' => $khaltiVerification->reason,
                ]);

                return redirect()->back()->with('error', __('Transaction has been failed'));
            }

            $arr = [
                'data' => $order->product_order_id,
                'status_code' => $response['status_code'] ?? 0,
                'store_complete'=>route('order.complete',$slug),
            ];
            \Session::put('khalti_order_id', $order->product_order_id);
            /*
             * P1-ORD-13 FOLLOW-UP. Khalti was the ONE thank-you-page producer with no binding, so
             * its buyers were left on the P1-ORD-08 double refusal: `khalti_order_id` holds the
             * NUMBER, and two orders placed in one store in one second share it, so
             * soleStoreOrderByNumber() refuses and both buyers are sent back to the landing page
             * having just paid. The order is created in the buyer's own session by this handler, so
             * the primary key is recordable here exactly as it is at the other nine sites.
             */
            \App\Services\OrderCompleteBinding::remember($order->id);
            return $arr;
        } else {
            return $this->error(['message' => __('Somthing went wrong.')]);
        }
    } else {
        return redirect()->back()->with('error', __('Transaction has been failed'));
    }
    }
}
