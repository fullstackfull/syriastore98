<?php

namespace App\Http\Controllers;

use App\Events\AddRewardClubPoint;
use App\Events\AuthorizeNetPayment;
use App\Events\AddAdditionalFields;
use Illuminate\Http\Request;
use App\Models\Coupon;
use App\Models\User;
use App\Models\Plan;
use App\Models\Utility;
use App\Models\Setting;
use App\Models\Product;
use App\Models\Cart;
use App\Models\City;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderTaxDetail;
use App\Models\OrderBillingDetail;
use App\Http\Controllers\CartController;
use Session;
use App\Models\OrderNote;
use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;
use App\Traits\ApiResponser;
use App\Http\Controllers\Api\ApiController;
use App\Models\OrderCouponDetail;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use Illuminate\Support\Facades\Auth;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use App\Models\PlanUserCoupon;
use App\Models\ProductVariant;
use App\Models\TaxMethod;
use App\Models\UserCoupon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use App\Http\Controllers\Concerns\GuardsPlanActivation;
use App\Http\Controllers\Concerns\GuardsGatewayReturn;
use Illuminate\Support\Facades\Log;

class AuthorizeNetPaymnetController extends Controller
{
    use GuardsPlanActivation;
    use GuardsGatewayReturn;
    use ApiResponser;
    //
    public function planPayWithAuthorizeNet(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();

        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);

        $plan = Plan::find($planID);
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $user = Auth::user();
        $price = $plan->price;
        if ($plan) {
            $get_amount = $plan->price;
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
        }
        $data = [
            'id' =>  $plan->id,
            'amount' =>  $price,
            'coupon_code' =>  $request->coupon,
        ];
        $data  =    json_encode($data);
        try {
            return view('plans.request', compact('plan', 'price','data','payment_setting'));
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }

    public function planPayWithAuthorizeNetData(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $input          = $request->all();

        $data           = json_decode($input['data'] , true);
        $coupanCode =  $data['coupon_code'];
        $user = \Auth::user();
        $plan = Plan::find($data['id']);

        if (empty($plan)) {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }

        // THE AMOUNT IS THE PLAN'S, NOT THE BROWSER'S.
        //
        // This was `$amount = $data['amount']`, and $data is json_decode($input['data']) --
        // i.e. a POSTED FORM FIELD (plans/request.blade.php:34 renders it as a hidden input).
        // The caller therefore chose the figure that is CHARGED TO THE CARD at :120 and then
        // RECORDED at :154: `{"id":<expensive plan>,"amount":0.01,"coupon_code":null}` bought
        // any plan for a cent, and the charge succeeded, so the provider verification below
        // confirmed it honestly.
        //
        // AuthorizeNet IS genuinely verified on this path -- the SDK executes a real
        // authCaptureTransaction against Authorize.Net and the gate is its resultCode plus
        // $tresponse->getMessages() -- so this provider is NOT on the config/payments.php map
        // and its gate is untouched. Only the amount changes, and it is computed once here so
        // that the figure charged and the figure recorded cannot disagree.
        //
        // No amount-mismatch refusal is added; see GuardsPlanActivation for why that is
        // deliberate.
        $amount = $this->serverOwnedPlanPrice($plan, $coupanCode);

        try {
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($payment_setting['authorizenet_login_id']);
            $merchantAuthentication->setTransactionKey($payment_setting['authorizenet_transaction_key']);
            $refId                  = 'ref' . time();
            // Create the payment data for a credit card
            $creditCard = new AnetAPI\CreditCardType();
            $creditCard->setCardNumber($input['cardNumber']);
            $creditCard->setExpirationDate($input['year'] .'-'. $input['month']);
            $creditCard->setCardCode($input['cvv']);

            $paymentOne             = new AnetAPI\PaymentType();
            $paymentOne->setCreditCard($creditCard);
            // Create a TransactionRequestType object and add the previous objects to it
            $transactionRequestType = new AnetAPI\TransactionRequestType();
            $transactionRequestType->setTransactionType("authCaptureTransaction");
            $transactionRequestType->setAmount($amount);
            $transactionRequestType->setPayment($paymentOne);
            // Assemble the complete transaction request
            $requestNet             = new AnetAPI\CreateTransactionRequest();
            $requestNet->setMerchantAuthentication($merchantAuthentication);
            $requestNet->setRefId($refId);
            $requestNet->setTransactionRequest($transactionRequestType);
        } catch (\Exception $e) {
            return redirect()->route('plan.index')->with('error', __('something Went wrong!'));
        }

        $controller = new AnetController\CreateTransactionController($requestNet);
        if(!empty($payment_setting['authorizenet_mode']) && $payment_setting['authorizenet_mode'] == 'live'){

            $response   = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION); // change SANDBOX to PRODUCTION in live mode

        }else{

            $response   = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX); // change SANDBOX to PRODUCTION in live mode
        }
        if ($response != null) {
            if ($response->getMessages()->getResultCode() == "Ok") {
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {

                    $order = new PlanOrder();
                    $order->order_id = $orderID;
                    $order->name = $user->name;
                    $order->card_number = '';
                    $order->card_exp_month = '';
                    $order->card_exp_year = '';
                    $order->plan_name = $plan->name;
                    $order->plan_id = $plan->id;
                    $order->price = $amount;
                    $order->price_currency = $currency;
                    $order->txn_id = time();
                    $order->payment_type = __('AuthorizeNet');
                    $order->payment_status = 'success';
                    $order->txn_id = '';
                    $order->receipt = '';
                    $order->user_id = $user->id;
                    $order->save();

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
                        return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
                    } else
                    {
                        return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                    }
                }
            } else {
                $tresponse      = $response->getTransactionResponse();
                if ($tresponse != null && $tresponse->getErrors() != null) {
                    return $this->error(['message' => __('Somthing went wrong.')]);
                } else {
                    return $this->error(['message' => __('Somthing went wrong.')]);
                }
            }
        }
    }

    public function getOrderPaymentStatus(Request $request, $slug)
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
        $requests_data = $this->storedCheckoutState($request, 'authorizenet');

        if ($requests_data === null) {
            return $this->gatewayReturnRefusal();
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
        $input          = $request->all();

        /*
         * P01-COMMERCE-SEC-01. THE CAPTURED AMOUNT IS THE SERVER'S.
         *
         * This handler is not a gateway return -- it is the CHARGE. It reads the card number,
         * expiry and CVV from the request below and builds an Authorize.Net
         * `authCaptureTransaction`. The amount used to be
         *
         *     $data   = json_decode($input['data'], true);
         *     $amount = (int) $data['get_amount'];
         *
         * so the shopper chose how much their own card was charged, from a JSON blob in their own
         * request body. Of everything the brief lists as off-limits to the browser -- prices,
         * shipping, discounts, taxes, the final payable, stock, payment success -- this is the
         * capture itself.
         *
         * `cartlist_final_price` in the stored state is the full payable: products plus the
         * SERVER's delivery price plus the SERVER's tax, assembled by
         * PaymentController::prepareFinalRequestData(). See
         * App\Http\Controllers\Concerns\GuardsGatewayReturn::serverPayableAmount(), including
         * why it rounds where the old line truncated -- `(int)` charged 10 for a 10.99 basket.
         */
        $amount = $this->serverPayableAmount($requests_data);

        if ($amount === null) {
            Log::warning('AuthorizeNet capture refused: no server-computed payable in the stored state.', [
                'store_id' => $store->id ?? null,
                'customer_id' => $customer_id ?: null,
            ]);

            return $this->gatewayReturnRefusal();
        }

        /*
         * OWNERSHIP IS DECIDED BEFORE THE CARD IS CHARGED, P01-COMMERCE-SEC-03 FOLLOW-UP.
         *
         * This handler had the quantity pre-flight and NOT the ownership one, and it is the
         * storefront's second card charge: it builds the `authCaptureTransaction` itself
         * (:325-341 below), so a refusal from any later point leaves a shopper charged with no
         * order row. See GuardsGatewayReturn::basketHasForeignProduct() -- its own docblock
         * argues exactly this case, and this file was the one site it was not wired into.
         *
         * THE BASKET HAS TO COME FROM THE STORED STATE, not from `$cartlist`. The handler does
         * not build `$products` until :413/:430, which is AFTER the capture, so a guard reading
         * that variable could not run before the money moved. storedCheckoutCartList() derives
         * the same basket from the state this handler already trusts for its payable amount --
         * the shape NMIPayController uses for the same reason.
         *
         * The post-charge quantity guard at :517 is left exactly where it is: it is now
         * defence in depth behind the pre-charge copy here, and removing it would be a second
         * behaviour change in a file this change set is otherwise only adding to.
         */
        $storedCartList = $this->storedCheckoutCartList($requests_data);
        /*
         * `['product_list']`, NOT the cart list itself. storedCheckoutCartList() returns
         * `$state['cartlist']`, whose LINES live under `product_list` -- iterating the cart list
         * directly walks its top-level keys (`product_list`, `final_price`, `tax_id`, ...), none
         * of which carries a `product_id`, so the guard would read nothing and refuse nothing.
         * A silent no-op guard is worse than none, because the test still passes. Same
         * dereference as NMIPayController:237.
         */
        $storedProducts = $storedCartList['product_list'] ?? [];

        if ($this->basketHasForeignProduct($storedProducts, $store)) {
            return $this->gatewayReturnForeignProductRefusal($request, 'authorizenet');
        }

        if ($this->basketHasUnusableQuantity($storedProducts)) {
            return $this->gatewayReturnQuantityRefusal($request, 'authorizenet');
        }

        $admin_payment_setting = getAdminAllSetting(null,$store->id);

        try {
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($admin_payment_setting['authorizenet_login_id']);
            $merchantAuthentication->setTransactionKey($admin_payment_setting['authorizenet_transaction_key']);
            $refId                  = 'ref' . time();
            // Create the payment data for a credit card
            $creditCard = new AnetAPI\CreditCardType();
            $creditCard->setCardNumber($input['cardNumber']);
            $creditCard->setExpirationDate($input['year'] .'-'. $input['month']);
            $creditCard->setCardCode($input['cvv']);

            $paymentOne             = new AnetAPI\PaymentType();
            $paymentOne->setCreditCard($creditCard);
            // Create a TransactionRequestType object and add the previous objects to it
            $transactionRequestType = new AnetAPI\TransactionRequestType();
            $transactionRequestType->setTransactionType("authCaptureTransaction");
            $transactionRequestType->setAmount($amount);
            $transactionRequestType->setPayment($paymentOne);
            // Assemble the complete transaction request
            $requestNet             = new AnetAPI\CreateTransactionRequest();
            $requestNet->setMerchantAuthentication($merchantAuthentication);
            $requestNet->setRefId($refId);
            $requestNet->setTransactionRequest($transactionRequestType);
        } catch (\Exception $e) {
        }

        $controller = new AnetController\CreateTransactionController($requestNet);
        if(!empty($admin_payment_setting['authorizenet_mode']) && $admin_payment_setting['authorizenet_mode'] == 'live'){

            $response   = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION); // change SANDBOX to PRODUCTION in live mode

        }else{

            $response   = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX); // change SANDBOX to PRODUCTION in live mode
        }
        if ($response != null) {
            if ($response->getMessages()->getResultCode() == "Ok") {
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {

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
                                    return $this->error(['message' => 'This coupon has expired.']);
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
                        // else {
                        //     return $this->error(['message' => 'Shipping Method not found']);
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
                        return $this->gatewayReturnQuantityRefusal($request, 'authorizenet');
                    }
                    $prodduct_id_array = [];
                    if (!empty($products)) {
                        foreach ($products as $key => $product) {
                            $prodduct_id_array[] = $product->product_id;

                            $product_id = $product->product_id;
                            $variant_id = $product->variant_id;
                            $qtyy = !empty($product->qty) ? $product->qty : 0;

                            /*
                             * THE BASKET THIS LOOP DECREMENTS IS NOT THE BASKET THE PRE-FLIGHT
                             * CHECKED, so this loop needs its own predicate. P01-COMMERCE-SEC-03
                             * FOLLOW-UP, found by an adversarial authz review of the commit that
                             * added that pre-flight -- i.e. of my own work, for the second time in
                             * this package.
                             *
                             * The pre-charge guard at :330-347 reads the STORED CHECKOUT STATE,
                             * which is the right basket to decide on before the card is charged
                             * and the only one available there. But :439/:456 then RE-READ THE
                             * LIVE CART (`Cart::cart_list_cookie()` on the guest arm,
                             * `ApiController::cart_list()` on the authenticated one) and this loop
                             * iterates THAT. A cart row added during the gateway round trip --
                             * which the two vendored module writers can still do, and which legacy
                             * rows already are -- was therefore never examined, and
                             * `Product::where('id', $product_id)` carried no store predicate at
                             * all, so the decrement landed on another merchant's row.
                             *
                             * This is the shape NMIPayController already has (:574-605) and this
                             * file did not: the pre-flight closes the common case cheaply, and the
                             * loop's own predicate makes a mid-flight basket change refusable
                             * rather than chargeable-and-drainable. The refusal is post-capture and
                             * says so to the shopper, which is the lesser of the two outcomes:
                             * better a charge to reconcile than another merchant's inventory gone.
                             *
                             * NULLABLE-TOLERANT, like NMI's and like the shared helper: a legacy
                             * product with a NULL `store_id` is not a provable mismatch, and
                             * refusing it here would hide a merchant's own product. `$datas` is the
                             * same row, so the plain-product arm below cannot reach past it either.
                             */
                            $Product = Product::where('id', $product_id)
                                ->where(function ($q) use ($store) {
                                    $q->where('store_id', $store->id)->orWhereNull('store_id');
                                })
                                ->first();
                            $datas = $Product;

                            if (!empty($product_id) && $product_id != 0 && empty($Product)) {
                                return redirect()->back()->with(
                                    'error',
                                    __('Your cart contains an item this store does not sell. Your payment has not been applied to an order — please contact the store.')
                                );
                            }

                            if (($settings['stock_management'] ?? null) === 'on')
                            {
                                if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {
                                    $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();

                                    /*
                                     * TESTED BEFORE IT IS DEREFERENCED, AND THIS ONE IS
                                     * POST-CAPTURE. P01-COMMERCE-SEC-03 FOLLOW-UP.
                                     *
                                     * `explode(',', $ProductStock->variation_option)` ran first
                                     * and `if (!empty($ProductStock))` came after, so a line
                                     * naming a variant that resolves to NO ROW raised a null
                                     * property read -- an ErrorException, HTTP 500 -- and the
                                     * `else` refusal below was unreachable. The capture at
                                     * :380-384 has ALREADY HAPPENED by this point, so that crash
                                     * left the shopper CHARGED WITH NO ORDER ROW.
                                     *
                                     * The ownership pre-flight added above does NOT cover it:
                                     * basketHasForeignProduct() `continue`s when the variant
                                     * resolves to no row, because a missing row is not a provable
                                     * mismatch. Found by a completeness critic reading the two
                                     * controls against each other.
                                     *
                                     * NOT PROVEN BY A TEST, and that is stated rather than
                                     * glossed: reaching this loop requires a successful
                                     * Authorize.Net capture, so every request that gets here in
                                     * this environment dies at the SDK with no credentials and no
                                     * network. The change is the same reorder `ordersave` and
                                     * `place_order` carry, where it IS removal-checked. The seven
                                     * remaining copies of this shape are listed in S36.
                                     */
                                    if (empty($ProductStock)) {
                                        return redirect()->back()->with(
                                            'error',
                                            __('Your cart contains an item this store does not sell. Your payment has not been applied to an order — please contact the store.')
                                        );
                                    }

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
                                        return $this->error(['message' => 'Product not found .']);
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
                    }elseif (module_is_active('PartialPayments')) {
                        $order->final_price = $requests_data['cartlist_final_price'] ?? $total_sub_price;
                        \Workdo\PartialPayments\app\Models\OrderPartialPayments::OrderPartialPayments($order, $slug , $request);
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
                    if (module_is_active('CheckoutAttachment')) {
                        \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachmentOrderStore($order, $slug , $request);
                    }
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
                        $UserCoupon->user_id = !empty($requests_data['customer_id']) ? $requests_data['customer_id'] : null;
                        $UserCoupon->coupon_id = $Coupon->id;
                        $UserCoupon->amount = $coupon_data['coupon_discount_amount'];
                        $UserCoupon->order_id = $order->id;
                        $UserCoupon->date_used = now();
                        $UserCoupon->save();

                        $discount_string = '-' . $coupon_data['coupon_discount_amount'];
                        $CURRENCY = Utility::GetValueByName('CURRENCY', $store->id);
                        $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME', $store->id);
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
                event(new AuthorizeNetPayment($OrderBillingDetail,$order,$owner));

                    if (!empty($order) && !empty($OrderBillingDetail)) {

                        // $order_array['order_id'] = $order->id;
                        // $cart_array = [];
                        // $cart_json = json_encode($cart_array);
                        // \Cookie::queue('cart', $cart_json, 1440);
                        $cart = Cookie::get('cart');
                        $cart = Cart::forGuestCookie($cart)->delete();
                        if (auth('customers')->user()) {
                            event(new AddRewardClubPoint($requests_data,$order,$slug));
                        }
                        return redirect()->route('order.complete', $slug)->with('data', $order->product_order_id);
                    } else {
                        return $this->error(['message' => __('Somthing went wrong.')]);
                    }
                }
            } else {
                $tresponse      = $response->getTransactionResponse();
                if ($tresponse != null && $tresponse->getErrors() != null) {
                    return $this->error(['message' => __('Somthing went wrong.')]);
                } else {
                    return $this->error(['message' => __('Somthing went wrong.')]);
                }
            }
        }
    }

}
