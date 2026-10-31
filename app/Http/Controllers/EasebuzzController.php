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
    use \App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit;
    /* P1-ORD-02: a pending balance is flipped to paid only for the customer who owes it. */
    use \App\Http\Controllers\Concerns\ResolvesPendingPartialPayment;

    /*
     * D-13 / S32. The three raw read-modify-write stock decrements in `getProductStatus()` now go
     * through this trait's `decrementStockOrFail()`, which RECORDS each decrement so
     * `restoreStockDecrements()` can give it back when a later cart line makes the handler return
     * early. It does NOT close the unverified decrement -- see the note on the same trait in
     * CinetPayController and `docs/development/phase-2/E13-STOCK-EVIDENCE-ANALYSIS.md`.
     *
     * WATCH THE EXIT SPELLING HERE. This handler refuses with `Utility::error(...)`, not
     * `$this->error(...)` like its three siblings, so a sweep that greps for the latter finds
     * nothing in this file and reports it as having no in-loop exits at all.
     */
    use \App\Http\Controllers\Concerns\AppliesCartStockChanges;

    public function planPayWithEasebuzz(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();

        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);

        $plan = Plan::find($planID);
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $user = \Auth::user();

        if ($plan) {
            /* Declared with the try so the catch can always read it: the compensation is gated
           on whether the ORDER was written, not on whether anything threw. */
        $ssOrderWritten = false;

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
         * D-13. THE STOCK LOOP RUNS INSIDE THIS METHOD, and until this wrapper nothing gave the
         * inventory back if the ORDER WRITE threw. Between the last decrement and the end of the order write
         * the handler creates the order, its billing detail, its tax rows, its notes and its coupon usage,
         * with NO transaction and NO catch -- measured across all seven first-party gateway writers; the only
         * `catch` blocks in that region are the narrow SMTP ones around mail. MEASURED by injecting a failure
         * at `Order::creating`: `product_stock` stayed down and no order was written.
         *
         * `OrderWriteTransactionTest` pins exactly this property -- "a rolled-back order write must give back
         * the stock it consumed" -- for PaymentController::getProductStatus, PaymentService::getProductStatus
         * and PaymentController::whatsapp. The four gateway RETURN handlers were not in its data provider.
         *
         * THE EXCEPTION IS RETHROWN, so the response the caller sees is unchanged. This adds compensation and
         * changes nothing about whether a payment is accepted, which is what keeps it inside D-13's condition
         * of not altering unapproved collection rules.
         *
         * `?? []` because `$appliedStockChanges` is initialised deep inside the body: a failure before the
         * loop has nothing to give back, and an undefined variable must not become a second error inside the
         * handler for the first one.
         *
         * THE WHOLE BODY IS WRAPPED rather than the order-write region, and the reason is NOT that a
         * narrower wrap is impossible -- the independent review corrected that claim. Wrapping from
         * `$order = new Order()` to the method's closing brace is brace-balanced but not
         * STATEMENT-balanced (the order write sits inside an `if` whose `else` comes later, so the
         * inserted `}` closed the `if` and all four files stopped parsing), but a narrower
         * statement-balanced run of statements DOES exist. It would not have helped: the order is
         * COMMITTED inside any such region, so narrowing moves the problem rather than solving it.
         * The gate on `$ssOrderWritten` is what solves it, and with the gate in place the width of
         * the try no longer matters. A method body is balanced by definition, so that is what it
         * wraps.
         */
        try {
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
        if (! $this->claimGatewayReturn($request, 'easebuzz', $requests_data)) {
            return $this->gatewayReturnReplayRefusal();
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
                     * checkout's state -- which carries no `partial_payment_type` -- let a posted one survive
                     * the merge and divert the return. By then the replay claim is consumed and the state
                     * forgotten, so the shopper's payment stands with no order and no retry.
                     *
                     * AND THE ROW IS CHECKED BEFORE IT IS FLIPPED. ManagePartialPayment() is three statements
                     * of vendored code with no store_id and no customer_id predicate, on a model that is in no
                     * `scoped_models` entry -- so without this a signed-in shopper could mark ANY tenant's
                     * pending balance paid by naming its id. See
                     * App\Http\Controllers\Concerns\ResolvesPendingPartialPayment.
                     *
                     * WHAT THIS GUARD DOES NOT ANSWER, stated so the next reader is not misled: it proves the
                     * balance is THIS customer's and still owed. It does NOT prove it was PAID. The settle
                     * branch returns before any provider verification on this handler, so a customer can still
                     * mark their OWN balance settled without a charge. Pre-existing, not closed here, and
                     * recorded in docs/security/P01-ORD-02-PARTIAL-PAYMENT-TOTAL-SOURCE.md section 5.
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

            /*
             * D-22 / F121. A BASKET LINE WHOSE PRODUCT OR VARIANT ROW IS GONE.
             *
             * MEASURED on this route's guest arm before this guard existed: a deleted product made
             * the return answer `ErrorException: Attempt to read property "id" on null`, and a
             * dangling variant reference `... property "variant" on null` -- the two unguarded
             * dereferences in `Cart::cart_list_cookie()` that the register names. Zero orders, and
             * no decrement, because the cart read precedes the loop
             * (tests/Feature/Security/GatewaySiblingMissingProductTest.php).
             *
             * THIS GATEWAY VERIFIES NOTHING, so `null` is passed where Khalti passes its
             * verification outcome, and that is the honest value rather than a placeholder:
             * `PaymentVerificationService` holds three provider verifiers and none of them is this
             * one, so every return here is "before proof of collection" by construction. The log
             * therefore records that the capture state is UNKNOWN rather than claiming it was not
             * collected -- this gateway may well have taken the money before redirecting.
             *
             * WHAT THIS FIXES IS THE 500, NOT THE LOST ORDER. Before: an unhandled exception, no
             * order, no decrement. After: a structured refusal, no order, no decrement. A customer
             * who has paid still has no order -- that half of D-22 is the owner's open decision --
             * but the failure is now a message and a `critical` log instead of a stack trace.
             */
            if ($this->basketHasMissingProductRow($store)) {
                return $this->gatewayReturnMissingProductRefusal($request, 'easebuzz', null, $this->serverPayableAmount($requests_data), $store);
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
             * The decrement below subtracts $qtyy. D-13 routed it through
             * `decrementStockOrFail()`; it was `$remain_stock = $Product->product_stock - $qtyy`,
             * and THAT is the form a negative quantity could ADD stock through. It no longer can:
             * `ProductStockService::normaliseQuantity()` returns null for a non-positive quantity,
             * so the decrement is SKIPPED rather than inverted. The guard is still needed, because
             * the pricing half is untouched -- a negative line still reduces every total it is
             * summed into. See
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
            /*
             * P1-ORD-02 criterion 2 -- THE DEPOSIT IS PRICED HERE, WHILE THE CART STILL EXISTS.
             *
             * The order's own write site is where the total is SET, and it is the wrong place to ASK for it.
             * The module's `ManageDeposit()` re-reads the basket through `ApiController::cart_list()`, and the
             * loop below DELETES the cart rows before `new Order()` is reached -- so called down there it finds
             * an empty basket, falls off the end of its own `if (! empty($products))` and returns NULL.
             *
             * MEASURED, and this is the part worth keeping: the first cut of this fix called the resolver at the
             * write site, exactly as the already-repaired `PaymentService` does, and it SILENTLY fell back to the
             * subtotal -- no exception, nothing logged, the order simply charged 100.00 where the module prices
             * 10.00. The same call made here returns `deposite_amount = "10.00"`. `PaymentService` gets away with
             * it only because a previous package DEFERRED its cart delete to after the order commits
             * (PaymentService.php:2592-2615); this controller still deletes first.
             *
             * So the figure is resolved before the loop and carried down. See
             * App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit for what was wrong with reading it
             * out of the posted checkout state.
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

            $prodduct_id_array = [];
            if (!empty($products)) {
                /*
                 * D-13. Decrements applied by the loop below, so they can be GIVEN BACK when a
                 * later line makes this handler return early. Before this, each of the three
                 * in-loop `return Utility::error(...)` exits left every line already processed
                 * decremented, with Easebuzz's money captured and no order row to show for it.
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
                            $variationOptions = explode(',', (string) ($ProductStock->variation_option ?? ''));   // P1-PAY-01: null-safe; a bare deref here throws ErrorException
                            $option = in_array('manage_stock', $variationOptions);
                            if (!empty($ProductStock)) {
                                if($option == true)
                                {
                                    /*
                                     * `false` is $rejectOnShortfall: on a shortfall the trait
                                     * re-runs the decrement with overselling allowed, which is
                                     * exactly what the raw `stock - qty` did, and logs it at
                                     * critical. Refusing is not available here -- Easebuzz has
                                     * already captured the money by the time this runs.
                                     */
                                    $this->decrementStockOrFail($appliedStockChanges, 'variant', $ProductStock, $qtyy, false);

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
                                    // The variant row exists but is not stock-managed, so the
                                    // PARENT product is decremented through `$datas`.
                                    $this->decrementStockOrFail($appliedStockChanges, 'product', $datas, $qtyy, false);
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
                                $this->restoreStockDecrements($appliedStockChanges);

                                return Utility::error(['message' => 'Product not found .']);
                            }
                        } elseif (!empty($product_id) && $product_id != 0) {

                            if (!empty($Product)) {
                                // The no-variant leg.
                                $this->decrementStockOrFail($appliedStockChanges, 'product', $Product, $qtyy, false);
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
                                $this->restoreStockDecrements($appliedStockChanges);

                                return Utility::error(['message' => __('Product not found .')]);
                            }
                        } else {
                            $this->restoreStockDecrements($appliedStockChanges);

                            return Utility::error(['message' => 'Please fill proper product json field .']);
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

            // add in  Order table  start
            $order = new Order();
            $order->order_source = Order::SOURCE_STOREFRONT;
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
                /*
                 * THE SECOND POSTED MONEY FIELD IN THIS SAME BRANCH, and the one 569494d2's own message
                 * named alongside the total ("delivery_price from $requests_data['shipping_price']").
                 * It was repaired on the storefront path and left here. `$delivery_price` a few lines
                 * above is the server's own shipping figure, resolved from the chosen method; the
                 * posted one is whatever the checkout body carried.
                 */
                $order->delivery_price = $delivery_price;
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
             * THIS PARAGRAPH USED TO SAY THE OPPOSITE OF WHAT THE CODE DOES, and the correction
             * matters because it is the paragraph a reviewer reads to decide whether this path is
             * safe. It said: "NO BEHAVIOUR CHANGES TODAY: this payment type is not in
             * ENFORCED_PAYMENT_TYPES, so the call returns exactly the 'Paid' the literal did."
             *
             * That was true when it was written and is now false. The owner's Phase 01 final
             * execution directive -- "any unverified gateway payment must remain Pending, never
             * Paid" -- INVERTED the default: PaymentVerificationService::resolveOrderPaymentStatus()
             * returns STATUS_PENDING when no verification record exists
             * (PaymentVerificationService.php, the `$verification === null` branch), and that
             * service's own docblock records that it superseded the very sentence quoted above.
             *
             * SO WHAT ACTUALLY HAPPENS HERE: nothing records a verification for this gateway, so
             * every order this handler writes is PENDING. `OrderObserver::created()` credits a
             * merchant wallet only for an order already Paid at creation, so no wallet is credited
             * -- which is the bound that keeps this handler's unverified-stock exposure (F90) from
             * being free paid goods. A merchant who confirms the capture with the provider marks it
             * Paid through the existing status workflow, and `OrderObserver::updated()` syncs the
             * wallet then.
             *
             * The behaviour is pinned by tests/Feature/Commerce/PaymentStatusDerivationTest.php and
             * tests/Feature/Commerce/UnverifiedGatewayStaysPendingTest.php, so the claim above is
             * checkable rather than another comment to trust.
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
            /* F237: phase two of the two-phase attribution -- point this request's
               movements at the order that now exists. Inside the transaction on purpose: a
               rollback must undo the attribution, not the movement. */
            $this->attributeStockEvidenceTo($order);
            /* THE ORDER NOW EXISTS. From here a failure must NOT give the stock back: an order
               that exists claims its goods, and returning them puts the shopper's items on the
               shelf for someone else while their order stands. */
            $ssOrderWritten = true;
            // add in  Order table end
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
            event(new GetProductStatus($OrderBillingDetail,$order,$owner));
            if(auth('customers')->user() &&  module_is_active('ProductAffiliate'))
            {
                Utility::affiliateTransaction($order);
            }
            if (!empty($order) && !empty($OrderBillingDetail)) {
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
                return Utility::error(['message' => 'Somthing went wrong.']);
            }
        }else{
            return redirect()->back()->with('error',__('Transaction has been failed'));
        }
        } catch (\Throwable $ssStockGuardError) {
            /*
             * D-13 — GIVE THE STOCK BACK ONLY IF NO ORDER ROW CLAIMS IT.
             *
             * TWO QUESTIONS, NOT ONE, AND THE SECOND WAS MISSING. The flag is set by the
             * statement AFTER `$order->save()`, so it is still false while the `created` model
             * event runs -- and `OrderObserver::created()` catches `\Exception` but NOT
             * `\Throwable`, so a `TypeError` inside `OrderFinancialService::createForOrder()`
             * escapes it, propagates out of `save()`, and reaches here with the row ALREADY
             * COMMITTED. On the flag alone that restored the stock behind an order that exists,
             * which oversells the same units to the next shopper while this order stands -- the
             * mirror image of the defect D-13 was opened for, introduced by its own fix.
             *
             * `$order->exists` is the authoritative answer and closes that window:
             * `Model::performInsert()` sets it at line 1341 and fires `created` at 1345, so by
             * the time any `created` listener can throw, `exists` is already true. Measured in
             * `ApiOrderWriterStockCompensationTest`, which fails on the flag alone.
             */
            $ssOrderClaimsIt = ($ssOrderWritten ?? false)
                || (isset($order) && $order instanceof \App\Models\Order && $order->exists);

            if (! $ssOrderClaimsIt) {
                $this->restoreStockDecrements($appliedStockChanges ?? []);
            }

            throw $ssStockGuardError;
        }
    }

}
