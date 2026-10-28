<?php

namespace App\Http\Controllers;

use App\Events\AddAdditionalFields;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Order;
use App\Models\PlanUserCoupon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use App\Models\PlanCoupon;
use Illuminate\Http\Request;
use App\Models\PlanOrder;
use App\Models\Utility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use App\Traits\ApiResponser;
use Illuminate\Support\Facades\Cookie;
use App\Http\Controllers\Concerns\GuardsPlanActivation;
use App\Http\Controllers\Concerns\GuardsGatewayReturn;

class PayUPaymentController extends Controller
{
    use GuardsPlanActivation;
    use GuardsGatewayReturn;

    /*
     * D-21 / S32. The three raw read-modify-write stock decrements in this handler now go through
     * the shared trait, which performs the same subtraction as one atomic conditional UPDATE and
     * RECORDS it so `restoreStockDecrements()` can give it back on an in-loop exit.
     *
     * WHAT IT DOES NOT DO. It does not close the unverified decrement. The inventory in
     * `docs/development/phase-2/D-21-STOCK-WRITER-INVENTORY.md` measured the ordering on this
     * path: the stock is taken hundreds of lines BEFORE the payment status is resolved, and the
     * decrement is conditioned on nothing. Closing that needs E13's durable stock evidence, which
     * is BLOCKED. This bounds the damage; it does not remove it.
     */
    use \App\Http\Controllers\Concerns\AppliesCartStockChanges;
    use \App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit;
    /* P1-ORD-02: a pending balance is flipped to paid only for the customer who owes it. */
    use \App\Http\Controllers\Concerns\ResolvesPendingPartialPayment;
    use ApiResponser;

    public function planPayWithPayU(Request $request)
    {
        $user = Auth::user();
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan = Plan::find($planID);

        // Always generate a new unique order ID
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

        if ($plan) {
            try {
                $price = $plan->price;
                if (!empty($request->coupon)) {
                    $coupons = PlanCoupon::where('code', $request->coupon)->where('is_active', '1')->first();
                    if ($coupons) {
                        $coupon_code = $coupons->code;
                        $usedCoupun = $coupons->used_coupon();
                        if ($coupons->limit == $usedCoupun) {
                            return redirect()->back()->with('error', __('This coupon code has expired.'));
                        } else {
                            $discount_value = ($plan->price / 100) * $coupons->discount;
                            $price = $price - $discount_value;
                            if ($price < 0) {
                                $price = $plan->price;
                            }
                            $coupon_id = $coupons->id;
                        }
                    } else {
                        return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                    }
                }

                $merchant_key = isset($payment_setting['payu_merchant_key']) ? $payment_setting['payu_merchant_key'] : '';
                $salt_key = isset($payment_setting['payu_salt_key']) ? $payment_setting['payu_salt_key'] : '';
                $payu_mode = isset($payment_setting['payu_mode']) ? $payment_setting['payu_mode'] : '';
                $plan_id = $plan->id;
                $pay_id = $orderID . '777';


                $pay = [
                    'key' => $merchant_key,
                    'mode' => $payu_mode,
                    'salt' => $salt_key,
                    'txnid' => $orderID,
                    'amount' => $price,
                    'firstname' => $user->name,
                    'email' => $user->email,
                    'surl' => route('plan.payu.status', ['plan_id' => $plan_id, 'data' => Crypt::encrypt(['status' => 'true', 'order_id' => $pay_id])]),
                    'furl' => route('plan.payu.status', ['plan_id' => $plan_id, 'data' => Crypt::encrypt(['status' => 'false', 'order_id' => $pay_id])]),
                    'productinfo' => 'WorkDo',
                    'service_provider' => 'payu_paisa',
                    'coupon_code' => isset($request->coupon)? $request->coupon :  '',
                ];

                // Session::put('order_id', $orderID);
                Session::put('\'' . $pay_id . '\'', $pay);
                return redirect()->to(route('payu.pay', ['pay' => Crypt::encrypt($pay_id)]));
            } catch (\Exception $e) {
                return redirect()->route('plan.index')->with('error', __($e->getMessage()));
            }
        } else {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }
    }

    public function planGetPayUStatus(Request $request, $plan_id)
    {
        // FAIL CLOSED FIRST. The gate below is `$data['status'] == 'true'`, read from a blob
        // this server sealed at checkout start -- and plans/payu_request.blade.php:14 renders
        // that surl into a hidden input in the caller's own browser, so they hold it before
        // paying. PayU's reverse hash is never recomputed here (generateHash is outbound-only).
        // See config/payments.php.
        $this->assertPlanActivationEnabled('payu', $plan_id);

        $data = Crypt::decrypt($request->data);
        $orderID = $data['order_id'];
        Session::forget($data['order_id']);
        $session = (object) Session::get('\'' . $orderID . '\'');
        $user = Auth::user();
        $plan = Plan::find($plan_id);
        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'INR';

        if ($plan) {
            try {
                if (isset($data['status']) && $data['status'] == 'true') {
                    $order = new PlanOrder();
                    $order->order_id = $orderID;
                    $order->name = $user->name;
                    $order->card_number = '';
                    $order->card_exp_month = '';
                    $order->card_exp_year = '';
                    $order->plan_name = $plan->name;
                    $order->plan_id = $plan->id;
                    $order->price = !empty($session->amount) ? $session->amount : 0;
                    $order->price_currency = $currency;
                    $order->txn_id = time();
                    $order->payment_type = __('PayU');
                    $order->payment_status = 'Succeeded';
                    $order->txn_id = '';
                    $order->receipt = '';
                    $order->user_id = $user->id;
                    $order->save();


                    if ($session->coupon_code) {
                        $coupons = PlanCoupon::where('code', $session->coupon_code)->where('is_active', '1')->first();
                        if (!empty($coupons)) {
                            $userCoupon = new PlanUserCoupon();
                            $userCoupon->user_id = $user->id;
                            $userCoupon->coupon_id = $coupons->id;
                            $userCoupon->order = $order->order_id;
                            $userCoupon->save();
                            $usedCoupun = $coupons->used_coupon();
                            if ($coupons->limit <= $usedCoupun) {
                                $coupons->is_active = 0;
                                $coupons->save();
                            }
                        }
                    }

                    $assignPlan = $user->assignPlan($plan->id);

                    if ($assignPlan['is_success']) {
                        return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
                    } else {
                        return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                    }
                } else {
                    return redirect()->route('plan.index')->with('error', __('Transaction has been failed.'));
                }
            } catch (\Exception $exception) {
                return redirect()->route('plan.index')->with('error', $exception->getMessage());
            }
        } else {
            return redirect()->route('plan.index')->with('error', __('Oops something went wrong.'));
        }
    }


    function generateHash($key, $txnid, $amount, $productinfo, $firstname, $email, $salt, $udf1 = '', $udf2 = '', $udf3 = '', $udf4 = '', $udf5 = '')
    {
        $data = $key . '|' . $txnid . '|' . $amount . '|' . $productinfo . '|' . $firstname . '|' . $email . '|' . $udf1 . '|' . $udf2 . '|' . $udf3 . '|' . $udf4 . '|' . $udf5 . '||||||' . $salt;
        return hash('sha512', $data);
    }

    public function pay($pay)
    {
        $pay = (object) Session::get('\'' . Crypt::decrypt($pay) . '\'');
        $pay->action =  ($pay->mode == 'production') ? "https://secure.payu.in/_payment" : "https://test.payu.in/_payment";
        $pay->hash   = $this->generateHash($pay->key, $pay->txnid, $pay->amount, $pay->productinfo, $pay->firstname, $pay->email, $pay->salt);
        return view('plans.payu_request', compact('pay'));
    }

    public function storeGetPayUStatus(Request $request)
    {
        $data = Crypt::decrypt($request->data);
        $orderID = $data['order_id'];
        Session::forget($data['order_id']);
        $session = (object) Session::get('\'' . $orderID . '\'');
        $slug = $session->slug;
        if ($slug) {
            try {
                if (isset($data['status']) && $data['status'] == 'true') {
                    return redirect()->route('store.payment.status',$slug);
                } else {
                    return redirect()->route('checkout', $slug)->with('error', __('Transaction has been failed.'));
                }
            } catch (\Exception $exception) {
                return redirect()->back()->with('error', $exception->getMessage());
            }
        } else {
            return redirect()->back()->with('error', __('Oops something went wrong.'));
        }
    }

    public function getProductStatus(Request $request, $slug)
    {
        $requests_data = (Session::get('request_data'));
        if($requests_data == null)
        {
            $requests_data = $request->all();
        }
        $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
        $store = getStore($slug);

        Session::forget('request_data');
        $customer_id = $requests_data['customer_id'] ?? '';

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
                /*
                 * `&&`, WHERE IT USED TO SAY `||`.  P1-ORD-02 criterion 2.
                 *
                 * `isset()` alone is true for ANY value of a caller-supplied key, so posting
                 * `partial_payment_type=x` on an ORDINARY checkout diverted this return into the
                 * settle-a-balance branch. That branch answers redirect()->back() without writing an
                 * order, and its first statement is
                 * `OrderPartialPayments::find($requests_data['partial_payment_id'])`, which is find(NULL)
                 * when the stored state holds no such key -- a fatal on the next line. Either way the
                 * shopper has been charged and no order row exists.
                 *
                 * AND THE PREDICATE READS THE STORED STATE, not the live request. `Request::merge()`
                 * only overwrites keys the state HOLDS (GuardsGatewayReturn:174-176), so an ordinary
                 * checkout's state -- which carries no `partial_payment_type` -- let a posted one
                 * survive the merge and divert the return. By then the replay claim is consumed and
                 * the state forgotten, so the shopper's payment stands with no order and no retry.
                 *
                 * AND THE ROW IS CHECKED BEFORE IT IS FLIPPED. ManagePartialPayment() is three statements
                 * of vendored code with no store_id and no customer_id predicate, on a model that is in no
                 * `scoped_models` entry -- so without this a signed-in shopper could mark ANY tenant's
                 * pending balance paid by naming its id. See
                 * App\Http\Controllers\Concerns\ResolvesPendingPartialPayment.
                 *
                 * WHAT THIS GUARD DOES NOT ANSWER, stated so the next reader is not misled: it proves the
                 * balance is THIS customer's and still owed. It does NOT prove it was PAID. On this
                 * handler the settle branch returns before any provider verification, so a customer can
                 * still mark their OWN balance settled without a charge. That is pre-existing and is
                 * recorded as its own item -- see docs/security/P01-ORD-02-PARTIAL-PAYMENT-TOTAL-SOURCE.md
                 * section 5.
                 */
                if (($requests_data['partial_payment_type'] ?? null) === 'pending_payment') {
                    if ($this->pendingPartialPaymentRow(
                        $requests_data['partial_payment_id'] ?? null,
                        $store->id ?? null,
                        \Auth::guard('customers')->id()
                    ) === null) {
                        \Illuminate\Support\Facades\Log::warning('Pending partial payment refused: the balance named does not belong to this customer.', [
                            'store_id' => $store->id ?? null,
                            'customer_id' => \Auth::guard('customers')->id(),
                            'partial_payment_id' => $requests_data['partial_payment_id'] ?? null,
                        ]);

                        return redirect()->back()->with('error', __('This payment could not be verified.'));
                    }

                    $return = \Workdo\PartialPayments\app\Models\PartialPayments::ManagePartialPayment($requests_data, $slug);
                    if ($return['status'] == 'success') {
                        return redirect()->back()->with('success', __($return['message']));
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
        // cart list api
        if (!auth('customers')->user()) {
            $response = Cart::cart_list_cookie($requests_data,$store->id ?? '');

            $response = json_decode(json_encode($response));
            $cartlist = (array)$response->data;
            if (empty($cartlist['product_list'])) {
                return $this->error(['message' => 'Cart is empty.']);
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
                return $this->error(['message' => 'Cart is empty.']);
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
        $prodduct_id_array = [];
        /*
         * P1-ORD-02 criterion 2 -- THE DEPOSIT IS PRICED HERE, WHILE THE CART STILL EXISTS.
         *
         * The order's write site is where the total is SET and the wrong place to ASK for it. The module's
         * `ManageDeposit()` re-reads the basket through `ApiController::cart_list()`, and the loop below
         * DELETES the cart rows before `new Order()` is reached -- so called down there it finds an empty
         * basket, falls off the end of its own `if (! empty($products))` and returns NULL.
         *
         * MEASURED on EasebuzzController, and this is the part worth keeping: the first cut called the
         * resolver at the write site, exactly as the already-repaired `PaymentService` does, and it SILENTLY
         * fell back to the subtotal -- no exception, nothing logged, the order charged 100.00 where the
         * module prices 10.00. The same call made here returns `deposite_amount = "10.00"`. `PaymentService`
         * gets away with it only because a previous package DEFERRED its cart delete to after the order
         * commits (PaymentService.php:2592-2615); these controllers still delete first.
         *
         * See App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit for what was wrong with reading
         * the total out of the posted checkout state.
         */
        $partialPaymentDeposit = null;

        if (module_is_active('PartialPayments') && \Auth::guard('customers')->user() && (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on')) {
            $partialPaymentDeposit = $this->partialPaymentDeposit(
                $request,
                $slug,
                [
                    'tax_price' => $tax_price,
                    'shipping_price' => $delivery_price,
                    'coupon_price' => $coupon_price,
                    'total_sub_price' => $final_sub_total_price,
                ],
                $final_sub_total_price
            );
        }

        if (!empty($products)) {
            /*
             * D-21. Decrements applied by the loop below, so they can be GIVEN BACK when a later
             * line makes this handler return early. Before this, each in-loop
             * `return $this->error(...)` left every line already processed decremented, with
             * PayU's money captured and no order row to show for it.
             */
            $appliedStockChanges = [];
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
                                /*
                                 * `false` is $rejectOnShortfall, and it is what keeps this bit-identical: on a
                                 * shortfall the trait re-runs the decrement with overselling allowed, exactly as the
                                 * raw `stock - qty` did. Refusing is not available here -- PayU has already
                                 * captured the money by the time this runs, so abandoning the basket would lose a
                                 * paid order.
                                 */
                                $this->decrementStockOrFail($appliedStockChanges, 'variant', $ProductStock, $qtyy, false);

                                if($ProductStock->stock <= $ProductStock->low_stock_threshold)
                                {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock",json_decode($settings['notification'])))
                                    {
                                        if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                        {
                                            Utility::variant_low_stock_threshold($product,$ProductStock,$settings);
                                        }

                                    }
                                }
                                if($ProductStock->stock <= $settings['out_of_stock_threshold'])
                                {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock",json_decode($settings['notification'])))
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
                                // The variant row exists but is not stock-managed, so the PARENT product is
                                // decremented through `$datas`.
                                $this->decrementStockOrFail($appliedStockChanges, 'product', $datas, $qtyy, false);
                                if($datas->product_stock <= $datas->low_stock_threshold)
                                {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock",json_decode($settings['notification'])))
                                    {
                                        if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                        {
                                            Utility::variant_low_stock_threshold($product,$datas,$settings);
                                        }

                                    }
                                }
                                if($datas->product_stock <= $settings['out_of_stock_threshold'])
                                {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock",json_decode($settings['notification'])))
                                    {
                                        if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                        {
                                            Utility::variant_out_of_stock($product,$datas,$settings);
                                        }
                                    }
                                }
                                if($datas->product_stock <= $settings['out_of_stock_threshold'] && $datas->stock_order_status == 'notify_customer')
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
                            $this->restoreStockDecrements($appliedStockChanges);

                            return $this->error(['message' => 'Product not found .']);
                        }
                    } elseif (!empty($product_id) && $product_id != 0) {

                        if (!empty($Product)) {
                            // The no-variant leg.
                            $this->decrementStockOrFail($appliedStockChanges, 'product', $Product, $qtyy, false);
                            if($Product->product_stock <= $Product->low_stock_threshold)
                            {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock",json_decode($settings['notification'])))
                                {
                                    if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                    {
                                        Utility::low_stock_threshold($Product,$settings);
                                    }
                                }
                            }

                            if($Product->product_stock <= $settings['out_of_stock_threshold'])
                            {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock",json_decode($settings['notification'])))
                                {
                                    if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                    {
                                        Utility::out_of_stock($Product,$settings);
                                    }
                                }
                            }

                            if($Product->product_stock <= $settings['out_of_stock_threshold'] && $Product->stock_order_status == 'notify_customer')
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
                            $this->restoreStockDecrements($appliedStockChanges);

                            return $this->error(['message' => 'Product not found .']);
                        }
                    } else {
                        $this->restoreStockDecrements($appliedStockChanges);

                        return $this->error(['message' => 'Please fill proper product json field .']);
                    }
                }
                // remove from cart
                // P1-SEC-24: the buyer's basket is the AUTHENTICATED customer's. `$request->customer_id` here is the stored checkout
                // state -- '' for a guest (MySQL reads '' = 0) or the id of whoever's e-mail was typed -- and is not an owner.
                Cart::forCustomer(auth('customers')->id())->where('product_id', $product_id)->where('variant_id', $variant_id)->where('store_id',$store->id)->delete();
            }
        }
        if (isset($request->customer_id) && !empty($request->customer_id)) {
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

        // add in  Order table  start
        $order = new Order();
        $order->order_source = Order::SOURCE_STOREFRONT;
        $order->product_order_id = $product_order_id;
        $order->order_date = date('Y-m-d H:i:s');
        $order->customer_id = $orderCustomerId;   // F46
        $order->is_guest = $is_guest;
        $order->product_id = $prodduct_id_array;
        $order->product_json = json_encode($products);
        $order->product_price = $final_price;
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
            /*
             * P1-ORD-02 criterion 2. THIS WAS
             *
             *     $order->final_price = $requests_data['cartlist_final_price'] ?? $total_sub_price;
             *
             * and `$requests_data` is the stored checkout state, which `PaymentService` stores as
             * `$request->except('attachment','payment_receipt')` -- the whole POST BODY of the
             * checkout request. SERVER-STORED IS NOT SERVER-DERIVED: a key the shopper posts at
             * initiation is in the array this return reads back.
             *
             * WHICH INITIATION PATHS LET A CALLER CHOOSE IT -- measured on the REAL route, because the
             * first write-up of this package overclaimed it and a read-only review called that. `POST
             * {slug}/process-order`, signed in as a customer, posting `cartlist_final_price = 1.00`,
             * stores:
             *     partial payments off, plain              100.00  (the SERVER's figure)
             *     partial payments on,  plain               10.00  (the MODULE's deposit)
             *     partial payments on,  pending_payment      1.00  (THE CALLER'S OWN FIGURE)
             * The first two are prepareFinalRequestData() overwriting the key before the session store
             * (PaymentController:1802, :1826), so on those paths this repair is DEFENCE IN DEPTH rather
             * than a live fix. The third IS live: that method's own `else` limb (:1831) writes
             * `$request->payment_amount` with nothing checking it against the amount outstanding.
             * Pinned by tests/Feature/Commerce/PartialPaymentDepositSourceTest.php.
             *
             * `$total_sub_price` was ALSO never assigned in this file, so the `??` limb was an
             * undefined variable -- an ErrorException, not a safe fallback.
             *
             * Recomputed through the module's own server-side ManageDeposit(), with the
             * server-derived components merged in first so neither the deposit nor its inputs can
             * come from the caller. The same repair 569494d2 made at PaymentService and
             * PaymentController, which this file was missed out of. See
             * App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit.
             */
            $order->final_price = $partialPaymentDeposit ?? $final_sub_total_price;
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
         * WHAT ACTUALLY HAPPENS HERE: nothing, because this handler is UNROUTED dead code (F94) -- no
         * route declares PayUPaymentController::getProductStatus, and it would fatal on six unimported
         * classes if it were wired. Were it live it would record no verification, so its orders would be
         * PENDING. The recorded disposition for this method is deletion, not maintenance; the paragraph
         * is corrected rather than left asserting the opposite of the truth in case anyone reads it
         * before that happens.
         * Pinned by tests/Feature/Commerce/PaymentStatusDerivationTest.php and
         * tests/Feature/Commerce/UnverifiedGatewayStaysPendingTest.php, so the claim above is checkable
         * rather than another comment to trust.
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
            \Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::CheckoutAttachment($order, $slug , $request);
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

            // $order_array['order_id'] = $order->id;
            // $cart_array = [];
            // $cart_json = json_encode($cart_array);
            // Cookie::queue('cart', $cart_json, 1440);
            $cart = Cookie::get('cart');
            $cart = Cart::forGuestCookie($cart)->delete();
            if (auth('customers')->user()) {
                event(new AddRewardClubPoint($requests_data,$order,$slug));
            }
            // P1-ORD-13: the thank-you page resolves the PRIMARY KEY this session placed, because
            // `product_order_id` is a bare timestamp two same-second orders in one store share --
            // the number alone showed one buyer the other's order, and after P1-ORD-08 refused both.
            \App\Services\OrderCompleteBinding::remember($order->id);
            return redirect()->route('order.complete', $slug)->with('data', $order->product_order_id);
        } else {
            return $this->error(['message' => 'Somthing went wrong.']);
        }
    }
}
