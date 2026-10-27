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

    /*
     * D-21 / S32. The three raw read-modify-write stock decrements in this handler now go through
     * the shared trait, which performs the same subtraction as one atomic conditional UPDATE and
     * RECORDS it so `restoreStockDecrements()` can give it back. Every in-loop error exit in
 * this handler restores -- the three `$this->error(...)` returns and the two
 * `redirect()->back()` ones. The second pair was MISSED by the first pass, whose exit
 * detector keyed on `return $this->error(` and so could not see a redirect; a security
 * review of the change found them. Count the exits, do not pattern-match one spelling.
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
        if (! $this->claimGatewayReturn($request, 'authorizenet', $requests_data)) {
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
        } catch (\Throwable $e) {
            /*
             * THIS CATCH WAS EMPTY, and the next line then read the variable the block had just
             * failed to assign.
             *
             * THE ORDINARY CAUSE IS A STORE WITH NO AUTHORIZENET CREDENTIALS. `getAdminAllSetting()`
             * returns whatever rows the store has, so `$admin_payment_setting['authorizenet_login_id']`
             * is an UNDEFINED ARRAY KEY -- which PHP 8 raises and Laravel's handler promotes to an
             * ErrorException. The empty catch discarded it, `$requestNet` stayed unassigned, and
             * `new CreateTransactionController($requestNet)` raised `Undefined variable $requestNet`.
             * MEASURED: ErrorException @ this file:394, on the whole gateway return, WITH NOTHING IN
             * THE LOG -- so a misconfigured store is indistinguishable from a broken one.
             *
             * THE SHAPE IS NOT INVENTED HERE. `planPayWithauthorizenet()` in this same file (:132-157)
             * has the IDENTICAL try block and its catch returns an error redirect; the order path was
             * the one left empty. The refusal is `gatewayReturnConfigurationRefusal()`, which is its
             * OWN refusal rather than the no-stored-state one: reusing that broke
             * GatewayReturnTrustBoundaryTest, whose job is to prove the state guard does not
             * over-refuse, because two different failures shared one flash string.
             *
             * `\Throwable` and not `\Exception`: an ErrorException is an Exception, but a TypeError
             * from the SDK is not, and either one leaves `$requestNet` just as unassigned.
             */
            return $this->gatewayReturnConfigurationRefusal($request, 'authorizenet', $e->getMessage());
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
                     * The decrement below subtracts $qtyy. D-21 routed it through
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
                        return $this->gatewayReturnQuantityRefusal($request, 'authorizenet');
                    }
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

                    /*
                     * THE CONDITION IS THE WRITE SITE'S INTENT, NOT ITS LITERAL TEXT, and the difference was a money
                     * regression this package introduced and a read-only review caught.
                     *
                     * The write site below gates on a BARE `module_is_active('PartialPayments')`. On a route that
                     * carries a slug that helper means "the STORE OWNER'S PLAN BUNDLES THE MODULE"
                     * (app/Helper/helper.php:1359-1381) -- NOT "this store turned partial payments on". For a store in
                     * that state the module does not refuse: its per-product body is skipped
                     * (PartialPaymentsController.php:562), control reaches the `else` at :660, and it returns
                     * `deposite_amount = "0.00"`. MEASURED exactly that. `is_numeric("0.00")` is true, so the resolver
                     * accepts it, `$partialPaymentDeposit` is non-null, the `??` below never fires and the order
                     * persists a total of ZERO while the card is charged the real amount.
                     *
                     * So the deposit is computed only when partial payments are actually ON for this store and a
                     * customer is signed in -- the two limbs the other four controllers already carry at both their
                     * early block and their write site. For every other store `$partialPaymentDeposit` stays null and
                     * the `??` restores the server figure, which is what the line did before this package.
                     *
                     * The guest limb matters for a second reason: `ManageDeposit()` re-reads the basket through
                     * `ApiController::cart_list()`, which refuses an unauthenticated caller, so without it every guest
                     * checkout logged "deposit recomputation failed" -- poisoning the one signal that is supposed to
                     * mean a money path could not be priced.
                     */
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
                         * Authorize.Net's money captured and no order row to show for it.
                         */
                        $appliedStockChanges = [];
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
                                $this->restoreStockDecrements($appliedStockChanges);

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
                                        $this->restoreStockDecrements($appliedStockChanges);

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
                                            /*
                                             * `false` is $rejectOnShortfall, and it is what keeps this bit-identical: on a
                                             * shortfall the trait re-runs the decrement with overselling allowed, exactly as the
                                             * raw `stock - qty` did. Refusing is not available here -- Authorize.Net has already
                                             * captured the money by the time this runs, so abandoning the basket would lose a
                                             * paid order.
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
                                            // The variant row exists but is not stock-managed, so the PARENT product is
                                            // decremented through `$datas`.
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

                                        return $this->error(['message' => 'Product not found .']);
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

                                        return $this->error(['message' => __('Product not found .')]);
                                    }
                                } else {
                                    $this->restoreStockDecrements($appliedStockChanges);

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

                    /* F46, the same defect as PaymentService.php:2706 and fixed the same way --
                     * this is a byte-for-byte copy of it, so fixing only the other one would have
                     * left the whole control bypassable by paying through Authorize.Net instead.
                     * See that file for the full reasoning; the guest branch is untouched. */
                    $orderCustomerId = ! empty($request->customer_id) ? $request->customer_id : 0;
                    $product_order_id  = '0' . date('YmdHis');
                    $is_guest = 1;
                    if (auth('customers')->check()) {
                        $orderCustomerId = auth('customers')->id();
                        $product_order_id  = $orderCustomerId . date('YmdHis');
                        $is_guest = 0;
                    }
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
                    }elseif (module_is_active('PartialPayments')) {
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
                     * WHAT ACTUALLY HAPPENS HERE, and it is half right in a way that hides a real defect: this
                     * handler DOES record a verification, so its orders get past the no-record branch and are written
                     * Paid. But 'authorizenet' is NOT in ENFORCED_PAYMENT_TYPES, so the amount reconciliation is
                     * never reached -- and this handler captures serverPayableAmount() from the state frozen at
                     * checkout while writing final_price from a FRESH cart read. The two are never compared. That is
                     * F92, and it is an open decision rather than something this comment should imply is fine.
                     * Pinned by tests/Feature/Commerce/PaymentStatusDerivationTest.php and
                     * tests/Feature/Commerce/UnverifiedGatewayStaysPendingTest.php, so the claim above is checkable
                     * rather than another comment to trust.
                     */
                    /*
                     * P1-PAY-03. THIS HANDLER VERIFIED AND RECORDED NOTHING, so after the
                     * no-record rule landed in PaymentVerificationService every Authorize.Net
                     * order was written Pending -- including the ones whose cards had just been
                     * charged. That was a commercial regression introduced by the default
                     * inversion, not a decision.
                     *
                     * IT IS THE STRONGEST EVIDENCE ANY GATEWAY ON THIS PLATFORM PRODUCES, and the
                     * reason is that this handler is not a RETURN, it BUILDS the
                     * authCaptureTransaction and sets its amount from $amount, which is
                     * serverPayableAmount($requests_data): the stored state's
                     * `cartlist_final_price`, products plus the SERVER's shipping plus the
                     * SERVER's tax. So the captured amount is not something a caller could
                     * influence and is not something this code has to parse back out of a
                     * response -- it is the figure this code SENT. The SDK's
                     * TransactionResponseType exposes no amount accessor (getResponseCode and
                     * getTransId; there is no getAuthAmount), so reading one back is not
                     * available; and the gate above -- getResultCode() == "Ok" AND a non-null
                     * $tresponse->getMessages() -- is Authorize.Net's own approved/declined
                     * distinction, not merely "the API call worked": a declined transaction
                     * populates getErrors() and leaves getMessages() null.
                     *
                     * ===================================================================
                     * RECORDED HERE, ONE LINE ABOVE THE CONSUMER, AND THAT POSITION IS THE
                     * CONTROL -- NOT A STYLE CHOICE.
                     * ===================================================================
                     *
                     * The first cut of this change recorded at the top of this branch, ~600 lines
                     * up, right after the capture. An independent review found what that costs and
                     * it is the PaiementPro defect of d082214a rebuilt on another gateway:
                     * recordVerification() writes to the SESSION, nothing else clears it, and SIX
                     * reachable returns sit between there and here -- 'This coupon has expired.',
                     * two 'Cart is empty.', 'User not found.', the quantity refusal and the
                     * post-capture foreign-product refusal. Every one of them returns a plain
                     * array through Utility::error(), so the response is an ordinary 200 and the
                     * session IS saved.
                     *
                     * A record so planted is bound to nothing but the lowercase type string --
                     * resolveOrderPaymentStatus() does not read verified_at and no checkout nonce
                     * is stored -- and PaymentController::getProductStatus on the shared
                     * Route::any('{storeSlug?}/get-payment-status') consumes whatever is in the
                     * session for a matching type. So: empty your own basket, drive a REAL small
                     * capture that refuses at 'Cart is empty.', then start an expensive checkout
                     * and hit get-payment-status. The expensive order is written Paid against a
                     * capture of the small one. 'authorizenet' is not amount-enforced, so nothing
                     * compares the two figures.
                     *
                     * Recording here closes it BY CONSTRUCTION rather than by clearing the record
                     * on each refusal path: there is no longer a window to plant in. $tresponse
                     * and $amount are both still in scope and neither is reassigned between the
                     * capture and this line -- the earlier comment's worry was $response, which IS
                     * reassigned by the cart read, and that is why the record is not built from it.
                     */
                    $this->gatewayReturnVerifier()->recordVerification(
                        'authorizenet',
                        \App\Services\PaymentVerificationResult::verified(
                            'Authorize.Net approved an authCaptureTransaction this server built '
                            . 'for its own computed payable (transaction '
                            . (string) $tresponse->getTransId() . ', response code '
                            . (string) $tresponse->getResponseCode() . ').',
                            number_format((float) $amount, 2, '.', ''),
                            number_format((float) $amount, 2, '.', '')
                        ),
                        $amount,
                        $this->checkoutNonceOf($requests_data)
                    );

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
                        // P1-ORD-13: the thank-you page resolves the PRIMARY KEY this session placed, because
                        // `product_order_id` is a bare timestamp two same-second orders in one store share --
                        // the number alone showed one buyer the other's order, and after P1-ORD-08 refused both.
                        \App\Services\OrderCompleteBinding::remember($order->id);
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
