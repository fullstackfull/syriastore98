<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\NMIService;
use GuzzleHttp\Client;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use Session;
use App\Models\{Store,Utility,Coupon,User,Setting,Product,ProductVariant,Cart,Order,Customer,City,OrderBillingDetail,OrderCouponDetail,UserCoupon,TaxMethod,OrderTaxDetail,ActivityLog,OrderNote, PlanUserCoupon};
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\CartController;
use App\Facades\ModuleFacade as Module;
use App\Events\AddAdditionalFields;
use App\Events\AddRewardClubPoint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Cache;
use App\Traits\ApiResponser;

class NMIPayController extends Controller
{
    protected $nmiService;
    use ApiResponser;

    /*
     * P01-COMMERCE-SEC-02. The same concern the seven storefront return handlers already use.
     * Nothing new is invented here: this handler was simply never wired to any of it. See
     * App\Http\Controllers\Concerns\GuardsGatewayReturn.
     */
    use \App\Http\Controllers\Concerns\GuardsGatewayReturn;
    use \App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit;

    /* Atomic stock decrement and give-back, shared with PaymentController's three writers. */
    use \App\Http\Controllers\Concerns\AppliesCartStockChanges;

    public function __construct(NMIService $nmiService)
    {
        $this->nmiService = $nmiService;
    }


    public function planPayWithNMIView(Request $request,$plan_id)
    {
        $couponCode = $request->input('coupon');
        $plan = Plan::find($plan_id);
        if(\Auth::user())
        {
            $currentTheme = '';
            $store = Store::find(getCurrentStore());
            $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
        }else{
            $currentTheme = '';
            $currantLang = \Cookie::get('LANGUAGE') ;
        }
        return view('payment.nmi-payment' ,compact('plan','couponCode','currentTheme','currantLang'));

    }

    public function planPayWithNMI(Request $request)
    {
        $payment_setting = getSuperAdminAllSetting();
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
                $nmi_api_private_key = isset($payment_setting['nmi_api_private_key']) ? $payment_setting['nmi_api_private_key'] : '';

                $price = number_format((float)$price, 2, '.', '');

                list($year, $month) = explode('-', $request->nmi_expiration_date);
                $expiration_date = $month . substr($year, 2);

                $client = new Client();
                $response = $client->post('https://secure.networkmerchants.com/api/transact.php', [
                    'form_params' => [
                        'security_key' => $nmi_api_private_key,
                        'amount' => $price,
                        'ccnumber' => $request->nmi_card_number,
                        'ccexp' => $expiration_date,
                        'cvv' => $request->nmi_cvv_code,
                        'firstname' => $request->nmi_cardholder_name,
                        'lastname' => $request->nmi_cardholder_name,
                        'type' => 'sale',
                        'orderid' => $orderID,
                    ],
                ]);
                $responseBody = $response->getBody()->getContents();
                parse_str($responseBody, $parsedResponse);
                if ($parsedResponse['response'] == 1) {
                    if ($request->has('coupon') && $request->coupon != '') {
                        $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();

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
                    $order->price_currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : '';
                    $order->txn_id         = $request->has('preference_id') ? $request->preference_id : '';
                    $order->payment_type   = 'NMI';
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
                    // Payment failed
                    return redirect()->back()->with('error',__($parsedResponse['responsetext']));
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
     * THE NINTH ORDER WRITER, AND THE SECOND CARD CHARGE. P01-COMMERCE-SEC-02.
     * =========================================================================================
     *
     * WHAT THIS HANDLER IS. `Route::any {storeSlug?}/get-payment-NMI` (routes/customer.php:355),
     * in the group whose only middleware is [web, ActiveTheme, themelanguage] -- no
     * authentication, no authorization, no throttle, and `any`, so it answers GET without a CSRF
     * token. It is not a gateway RETURN: the card number, expiry and CVV arrive in THIS request
     * and this method posts the `sale` to NMI itself. Like AuthorizeNet, what the caller could
     * choose here was not only what the ledger recorded but what the card was CHARGED.
     *
     * WHAT IT TRUSTED, BEFORE. Every item the P01-COMMERCE-SEC-01 package removed from the other
     * seven was still present here, plus one shape none of them had:
     *
     *   1. `Session::get('request_data')` with a caller fallback. See storedCheckoutState().
     *      The fallback was `json_encode($request->response_data)`, i.e. a STRING, so the
     *      `['slug']` read below it did not obey the caller so much as break -- the handler then
     *      ran with `$slug = ''`, `getStore('')` and a null store, and died on `$store->id`. That
     *      made it inert rather than safe, and it is removed rather than relied upon.
     *   2. THE BASKET AND EVERY TOTAL CAME BACK THROUGH THE BROWSER. Further down the method:
     *      `$response = json_decode($request->input('response_data'), true)` -- the hidden field
     *      the payment view prints at resources/views/front_end/payment/nmi-payment.blade.php:98
     *      -- supplied `product_list` (the product ids and the line quantities that drive every
     *      stock decrement), `total_final_price` and `total_sub_price` (the order's recorded
     *      money). That path needs no expired session and no missing cookie: it is the SUCCESS
     *      path, with a perfectly valid state sitting unread in the session. See
     *      GuardsGatewayReturn::storedCheckoutCartList().
     *   3. The amount charged to the card was `$requests_data['cartlist_final_price']` taken raw.
     *      It is now serverPayableAmount(), the same control AuthorizeNet got in 81db1700.
     *   4. No quantity guard anywhere, so a cart line of -5 ADDED stock and one of 2147483647
     *      took it as far negative as the column goes. Both are refused before the card is
     *      charged, not merely before the stock loop: refusing after a capture would leave a
     *      shopper paid-for and orderless.
     *   5. The state was never consumed, so the same return could be replayed -- each replay a
     *      FRESH CHARGE on the card plus another order. `Session::forget()` makes it single-use,
     *      which is what the other six do and the only replay control this platform has (there is
     *      still no idempotency key; see S32).
     *
     * WHAT IS STILL NOT CLAIMED. NMI itself is not reachable from this environment and no test
     * here sends a card number anywhere. The charge is routed through the injected
     * App\Services\NMIService so a test can substitute the gateway's ANSWER and drive the order
     * writer; that is a seam for the writer's guards, NOT evidence that any payment was verified.
     * What IS new is that the answer is recorded through PaymentVerificationService, so this
     * becomes the first of the nine whose Paid is backed by a recorded gateway verification
     * rather than asserted -- the status it produces today is identical.
     */
    public function getNMIProductStatus(Request $request)
    {
        $requests_data = $this->storedCheckoutState($request, 'nmi');

        if ($requests_data === null) {
            return $this->gatewayReturnRefusal();
        }

        $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
        $store = getStore($slug);

        if (empty($store)) {
            return $this->gatewayReturnRefusal();
        }

        $this->adoptStoredCheckoutState($request, $requests_data);

        $storedCartList = $this->storedCheckoutCartList($requests_data);
        $storedProducts = $storedCartList['product_list'] ?? [];

        if (empty($storedProducts)) {
            return $this->gatewayReturnRefusal();
        }

        if ($this->basketHasUnusableQuantity($storedProducts)) {
            return $this->gatewayReturnQuantityRefusal($request, 'nmi');
        }

        /*
         * AND THE BASKET MUST BE THIS STORE'S, decided BEFORE the capture rather than inside
         * the stock loop. See GuardsGatewayReturn::basketHasForeignProduct() for why the
         * per-line check this replaced was in the wrong place on both counts: it could only
         * refuse after the card had been charged, and it covered one of the loop's two branches
         * -- the variant branch resolves its row through a predicate built from the caller's own
         * `variant_id` and `product_id`, and `ProductVariant` is scoped by nothing at all.
         */
        if ($this->basketHasForeignProduct($storedProducts, $store)) {
            return $this->gatewayReturnForeignProductRefusal($request, 'nmi');
        }

        $cartlist_final_price = $this->serverPayableAmount($requests_data);

        if ($cartlist_final_price === null) {
            return $this->gatewayReturnRefusal();
        }

        /*
         * The charged figure, kept under its own name because `$cartlist_final_price` is
         * REASSIGNED further down (to the basket subtotal, and again by the coupon branch) and
         * the order row needs the amount the card was actually charged.
         */
        $serverPayableAmount = $cartlist_final_price;

        $nmi_api_private_key = \App\Models\Utility::GetValueByName('nmi_api_private_key', $store->id);

        /*
         * The expiry arrives as `YYYY-MM` from a month input. `list()` on a short explode()
         * raised "Undefined array key 1" and 500d, which is a crash on a card form rather than a
         * message -- and it ran BEFORE any of the guards above when it was the first statement.
         */
        $expirationParts = explode('-', (string) $request->nmi_expiration_date);

        if (count($expirationParts) < 2) {
            return redirect()->back()->with('error', __('Please enter a valid card expiration date.'));
        }

        [$year, $month] = $expirationParts;
        $expiration_date = $month . substr($year, 2);
        $orderID = ($requests_data['customer_id'] ?? '') . date('YmdHis');

        /*
         * SINGLE USE, AND NOT ONE STEP EARLIER. Consuming the state is what stops a replayed
         * return reaching the gateway twice, so it has to happen before the charge -- but it
         * used to happen before the GUARDS, which made every refusal above, and every decline
         * below, destroy the checkout. A shopper who mistyped a CVV got
         * "We could not confirm your checkout details" on their next attempt instead of
         * another try. Declines are a routine few percent of card traffic, not an edge case.
         *
         * So: consumed here, and PUT BACK on every path that did not take money. A failed
         * charge re-opens nothing, because nothing was captured.
         */
        /*
         * P1-PAY-02. ONE CHECKOUT, AND ONE PAYMENT, PRODUCE AT MOST ONE ORDER -- and the control
         * is NOT the session. The forget below makes the stored state single-use, which stops a
         * SEQUENTIAL replay; it cannot stop two returns that OVERLAP, because Laravel holds no
         * session lock for the life of these requests and both read the state before either
         * writes the forget. That is the read-check-use straddle already fixed on the Paymera
         * path, where it produced a second order, a second stock decrement and a second wallet
         * credit.
         *
         * TAKEN HERE AND NOT WITH THE OTHER GUARDS, for the same reason the forget is here and
         * not with them: everything above this line can refuse WITHOUT taking money, and this
         * handler is one of the two that let the shopper come back to the same stored state
         * afterwards. The first version of this change claimed above the card-expiry check, so a
         * bare GET to this route -- `Route::any`, so GET is routed, and the expiry check returns
         * before the forget -- burned a live checkout's claim while leaving its state intact, and
         * the shopper's real attempt was then refused as a replay. An independent review caught
         * it. The claim belongs exactly where the money does.
         */
        if (! $this->claimGatewayReturn($request, 'nmi', $requests_data)) {
            return $this->gatewayReturnReplayRefusal();
        }

        \Session::forget(self::CHECKOUT_STATE_KEY);

        $parsedResponse = $this->nmiService->chargeSale([
            'security_key' => $nmi_api_private_key,
            'amount' => $cartlist_final_price,
            'ccnumber' => $request->nmi_card_number,
            'ccexp' => $expiration_date,
            'cvv' => $request->nmi_cvv_code,
            'firstname' => $request->nmi_cardholder_name,
            'lastname' => $request->nmi_cardholder_name,
            'type' => 'sale',
            'orderid' => $orderID,
        ]);

        if (($parsedResponse['response'] ?? null) == 1) {
            /*
             * THE GATEWAY'S OWN ANSWER, RECORDED. `response == 1` is NMI's success code and this
             * handler made the capture itself, so unlike the five pure returns there really is a
             * server-side verification to record. Recording it through the platform's existing
             * service rather than writing the literal 'Paid' is what makes the posture
             * measurable; resolveOrderPaymentStatus() returns the same 'Paid' today.
             */
            $this->gatewayReturnVerifier()->recordVerification(
                'nmi',
                \App\Services\PaymentVerificationResult::verified(
                    'NMI sale response=1 (transactionid ' . ($parsedResponse['transactionid'] ?? 'unknown') . ')',
                    isset($parsedResponse['amount']) ? (string) $parsedResponse['amount'] : null,
                    (string) $cartlist_final_price
                ),
                $cartlist_final_price
            );

            $customer_id = $requests_data['customer_id'] ?? '';

            if(!empty($requests_data['method_id'])){

                $request['method_id'] = $requests_data['method_id'];
            }
            $user = Cache::remember('admin_details', 3600, function () {
                return User::where('type','admin')->first();
            });
            $plan = null;
            if ($user && $user->type == 'admin') {
                $plan = Plan::find($user->plan_id);
            }

            /*
             * `$plan->shipping_method` is read four times below and `$plan` was only ever
             * assigned inside the `type == 'admin'` test above -- so on a platform whose admin
             * row is missing, is `super admin`, or whose `plan_id` no longer resolves, this
             * method died on a null property read. On THIS handler that 500 lands after the
             * card has been charged, which is why it is fixed here and not only in the sibling
             * `whatsapp()` that the same package guarded.
             */
            $planShippingMethod = $plan->shipping_method ?? 'off';

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
                            return $this->error(['message' => __('This coupon has expired.')]);
                        }
                    }
                }
            }
            if (!auth('customers')->user()) {
                $rules = [
                    'billing_info' => 'required',
                    'payment_type' => 'required',
                // 'delivery_id' => 'required',
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
            /*
             * THE BASKET AND ITS TOTALS ARE THE SERVER'S, NOT THE HIDDEN FIELD'S.
             *
             * This was `json_decode($request->input('response_data'), true)` -- the cart the
             * server printed into the payment form and took back from the browser. Every figure
             * below came from it: the line quantities that drive the stock decrements, the
             * `product_id`s they are applied to, and `total_final_price` / `total_sub_price`,
             * which become `orders.product_price` and the order's own total.
             *
             * `$storedCartList` is the SAME array at initiation -- PaymentService's NMI arm hands
             * one `$cartList` to both the session and the view (PaymentService.php:4228-4236) --
             * so a genuine checkout is priced identically and nothing in the contract changes.
             * What changes is that a caller editing the hidden field now edits nothing.
             *
             * The guards above have already refused an empty basket and an unusable line
             * quantity, before the card was charged.
             */
            $response = $storedCartList;
            $products = $storedProducts;
            $cartlist_final_price = !empty($response['final_price']) ? $response['final_price'] : 0;
            $product_price = !empty($response['total_final_price']) ? $response['total_final_price'] : 0;
            $final_price = $response['total_final_price'] ?? 0;
            $total_sub_price = $response['total_sub_price'] ?? 0;
            $tax_price = !empty($requests_data['tax_price']) ? $requests_data['tax_price'] : ($response['tax_price'] ?? 0);
            $billing = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

            /*
             * THE PAYABLE THE CARD WAS ACTUALLY CHARGED, kept for the order row.
             *
             * `$order->final_price` read `$final_sub_total_price` on the shipping-enabled branch
             * -- A VARIABLE THIS METHOD NEVER ASSIGNS. PHP 8 raises a warning and evaluates it as
             * null, so every such order recorded `final_price` 0.00 while the card was charged
             * the real amount. It is the state's `cartlist_final_price`: products + server
             * shipping + server tax, which prepareFinalRequestData() composes
             * (PaymentController.php:1721) and which serverPayableAmount() has already validated
             * as the figure handed to the gateway.
             */
            $final_sub_total_price = $serverPayableAmount;

            $coupon_price = 0;
            // coupon api call
            if (!empty($requests_data['coupon_code'])) {
                if (isset($requests_data['coupon_info']) && $requests_data['coupon_info']) {
                    $coupon_price = $requests_data['coupon_info']['coupon_discount_amount'] ?? 0;
                } else {
                    $coupon_data = $requests_data['coupon_code'];
                    $apply_coupon = [
                        'coupon_code' => $coupon_data,
                        'sub_total' => $product_price,
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
            } elseif (!empty($requests_data['cartlist']['coupon_code'])) {
                $coupon_info = is_object($requests_data['cartlist']['coupon_info']) ? (array) $requests_data['cartlist']['coupon_info'] : $requests_data['cartlist']['coupon_info'];
                if (isset($coupon_info) && $coupon_info) {
                    $coupon_price =  ($coupon_info['coupon_final_amount'] ?? 0);
                } else {
                    $coupon_data = $requests_data['cartlist']['coupon_code'];
                    $apply_coupon = [
                        'coupon_code' => $coupon_data,
                        'sub_total' => $product_price,
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
            }
            $delivery_price = 0;
            if ($planShippingMethod == 'on') {
                if (isset($requests_data['shipping_final_price'])) {
                    $delivery_price = $requests_data['shipping_final_price'];
                } else {
                    if (!empty($request->method_id)) {
                        $del_charge = new CartController();
                        $delivery_charge = $del_charge->get_shipping_method($request, $slug);
                        $content = $delivery_charge->getContent();

                        $data = json_decode($content, true);
                        $delivery_price = $data['shipping_final_price'];
                        $tax_price = $requests_data['tax_price'] ?? ( $content['final_tax_price'] ?? 0);
                    } 
                    // else {
                    //     return $this->error(['message' => __('Shipping Method not found')]);
                    // }
                }
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
             * Decrements applied by this loop, so they can be GIVEN BACK when a later line
             * cannot be fulfilled and no order is therefore written. Before this, the three
             * `return $this->error(...)` exits below left every line already processed
             * decremented, with the card charged and no order row -- and the store-scoped product
             * lookup added in this package makes that exit reachable for the first time, on a
             * cart line naming another merchant's product.
             */
            $appliedStockChanges = [];
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

            if (module_is_active('PartialPayments')) {
                $partialPaymentDeposit = $this->partialPaymentDeposit(
                    $request,
                    $slug,
                    [
                        'tax_price' => $tax_price,
                        'shipping_price' => $delivery_price,
                        'coupon_price' => $coupon_price,
                        'total_sub_price' => $total_sub_price,
                    ],
                    $total_sub_price
                );
            }

            if (!empty($products)) {
                foreach ($products as $key => $product) {
                    $prodduct_id_array[] = $product['product_id'];

                    $product_id = $product['product_id'];
                    $variant_id = $product['variant_id'];
                    $qtyy = !empty($product['qty']) ? $product['qty'] : 0;

                    /*
                     * STORE-SCOPED, because `Product` is NOT in config/tenancy.php's
                     * `scoped_models` -- StoreScope::isActiveFor() short-circuits for it, so
                     * `Product::find($id)` reaches EVERY tenant's catalogue for every principal.
                     * That is the S19/S32 cross-tenant stock drain: a cart line naming another
                     * merchant's product id decrements THAT merchant's stock from this store's
                     * checkout.
                     *
                     * A NULL `store_id` is still accepted. The column is nullable and legacy rows
                     * may not carry one; refusing those would hide a merchant's own product and
                     * break a live catalogue. What is refused is a PROVABLE mismatch -- the same
                     * rule and the same reasoning as 0ad3f7e0 applied to ShippingMethod.
                     *
                     * SUBSUMED, AND RECORDED AS UNPROVEN RATHER THAN CLAIMED AS A GUARD.
                     * basketHasForeignProduct() now refuses the same basket BEFORE the card is
                     * charged, which is strictly better placed, and a removal check confirms
                     * this predicate now fails no test: 34 of 34 still pass with it reverted to
                     * a bare `Product::where('id', $product_id)`. It is kept as defence in depth
                     * at zero cost -- the only protection left if the pre-flight is ever bypassed
                     * or a new caller reaches this loop directly. Same honesty S32 applied to the
                     * `method_id` pin once 0ad3f7e0 subsumed it: a later fix can stop an earlier
                     * guard from biting, and nothing re-checks it unless someone looks.
                     */
                    $Product = Product::where('id', $product_id)
                        ->where(function ($q) use ($store) {
                            $q->where('store_id', $store->id)->orWhereNull('store_id');
                        })
                        ->first();
                    $datas = $Product;
                    if(isset($settings['stock_management']) && $settings['stock_management'] == 'on')
                    {
                        if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {
                            /*
                             * DEFENCE IN DEPTH BEHIND basketHasForeignProduct(). The pre-flight
                             * refuses a foreign product before the card is charged, so `$Product`
                             * should never be null here. If it ever is, this branch must not
                             * proceed: `ProductVariant`'s only predicate is the caller's own
                             * `variant_id` and `product_id` -- the model carries no StoreScope and
                             * is not in config/tenancy.php's `scoped_models` -- so it would
                             * decrement whatever that pair resolves to, and `$datas` (= `$Product`)
                             * is dereferenced below on the no-manage-stock leg. The sibling
                             * `elseif` branch has always had this guard; this one never did.
                             *
                             * SUBSUMED LIKE THE PREDICATE ABOVE, and recorded the same way: with
                             * basketHasForeignProduct() running before the charge, a removal
                             * check shows this `empty($Product)` test fails nothing (34 of 34
                             * pass without it). The `empty($ProductStock)` test below is a
                             * DIFFERENT guard and does bite -- see the 'missing variant row'
                             * case of test_a_charged_card_does_not_end_in_a_crash.
                             */
                            if (empty($Product)) {
                                $this->restoreStockDecrements($appliedStockChanges);

                                return $this->error(['message' => __('Product not found .')]);
                            }

                            $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();

                            /*
                             * And `$ProductStock` is tested BEFORE it is dereferenced. `explode()`
                             * on `$ProductStock->variation_option` ran first and
                             * `if (!empty($ProductStock))` came after it, so a line naming a
                             * variant id that does not exist for its product raised a null
                             * property read instead of reaching the handler's own "Product not
                             * found" path. Pre-existing and shared with the five sibling gateway
                             * handlers; fixed here because this one has already taken the money by
                             * the time it throws.
                             */
                            if (empty($ProductStock)) {
                                $this->restoreStockDecrements($appliedStockChanges);

                                return $this->error(['message' => __('Product not found .')]);
                            }

                            $variationOptions = explode(',', $ProductStock->variation_option);
                            $option = in_array('manage_stock', $variationOptions);
                            if (!empty($ProductStock)) {
                                if($option == true)
                                {
                                    /*
                                     * ATOMIC, and recorded so it can be given back. This was a
                                     * read-modify-write -- two concurrent checkouts both wrote
                                     * `stock - qty` and the second overwrote the first -- and
                                     * nothing anywhere in this handler could undo a decrement
                                     * once a later line failed, which on a CARD CHARGE means the
                                     * money is already taken. $rejectOnShortfall is false for the
                                     * same reason it is false in PaymentController's return
                                     * paths: past the capture, overselling and reporting it beats
                                     * discarding a paid basket.
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
                                    /*
                                     * ATOMIC, and recorded so it can be given back. This was a
                                     * read-modify-write -- two concurrent checkouts both wrote
                                     * `stock - qty` and the second overwrote the first -- and
                                     * nothing anywhere in this handler could undo a decrement
                                     * once a later line failed, which on a CARD CHARGE means the
                                     * money is already taken. $rejectOnShortfall is false for the
                                     * same reason it is false in PaymentController's return
                                     * paths: past the capture, overselling and reporting it beats
                                     * discarding a paid basket.
                                     */
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

                                return $this->error(['message' => __('Product not found .')]);
                            }
                        } elseif (!empty($product_id) && $product_id != 0) {

                            if (!empty($Product)) {
                                /* ATOMIC and recorded -- see the note on the variant branch above. */
                                $this->decrementStockOrFail($appliedStockChanges, 'product', $Product, $qtyy, false);
                                if($Product['product_stock'] <= $Product['low_stock_threshold'])
                                {
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_low_stock",json_decode($settings['notification'] ?? '[]')))
                                    {
                                        if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                        {
                                            Utility::low_stock_threshold($Product,$settings);
                                        }
                                    }
                                }

                                if($Product['product_stock'] <= ($settings['out_of_stock_threshold'] ?? '0'))
                                {
                                    if (!empty(json_decode($settings['notification'] ?? '[]')) && in_array("enable_out_of_stock",json_decode($settings['notification'] ?? '[]')))
                                    {
                                        if(isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] =="on")
                                        {
                                            Utility::out_of_stock($Product,$settings);
                                        }
                                    }
                                }

                                if($Product['product_stock'] <= ($settings['out_of_stock_threshold'] ?? '0') && $Product['stock_order_status'] == 'notify_customer')
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
                /*
                 * `store_id`, which the per-line delete four lines above already carries and this
                 * one did not. `customer_id` is pinned from the stored state by
                 * adoptStoredCheckoutState(), so it is no longer the caller's -- but a bare
                 * `customer_id` predicate still empties that customer's basket in EVERY store,
                 * and `Cart`'s own StoreScope cannot be relied on to supply the missing half:
                 * config/tenancy.php sets `null_tenant => 'open'`, so a principal that resolves
                 * no tenant gets no predicate from the scope either.
                 */
                Cart::forCustomer(auth('customers')->id())->where('store_id', $store->id)->delete();
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
            $order->product_price = $product_price;
            $order->coupon_price = $coupon_price;
            $order->delivery_price = $delivery_price;
            $order->tax_price = $tax_price;
            if (!auth('customers')->user()) {
                if ($planShippingMethod == "on") {
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
                 * initiation is in the array this return reads back. MEASURED with the module active and
                 * a 100.00 basket: a posted `cartlist_final_price=1.00` wrote an order with
                 * `product_price = 100.00` and `final_price = 1.00`.
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
                $order->final_price = $partialPaymentDeposit ?? $total_sub_price;
                \Workdo\PartialPayments\app\Models\OrderPartialPayments::OrderPartialPayments($order, $slug , $request);
            }else{
                if (module_is_active('RewardClubPoint') && isset($requests_data['club_point_is_active']) && $requests_data['club_point_is_active'] == 'on') {
                    $customerDetail = Customer::find($customer_id);
                    $rewardSetting = getAdminAllSetting($store->created_by,$store->id) ?? null;
                    $saveRewardPrice = ($customerDetail->total_club_point * $rewardSetting['reward_point_price'] ?? 0) / ($rewardSetting['reward_points'] ?? 0);
                    if ($planShippingMethod == "on") {
                        /*
                         * NOT `- $saveRewardPrice`. The reward discount is ALREADY inside this
                         * figure: PaymentController::processRewardPoints() (:1520-1530)
                         * subtracts it from the cart's `final_price` at initiation, and
                         * prepareFinalRequestData() then composes `cartlist_final_price` from
                         * that -- which is what serverPayableAmount() returns and what the card
                         * was charged. Subtracting it again recorded an order total BELOW the
                         * amount captured.
                         *
                         * This was introduced by assigning `$final_sub_total_price` at all:
                         * before that the variable was unassigned, so the line evaluated to
                         * `0 - $saveRewardPrice` -- a negative total, broken differently. The
                         * order now records the amount the card was charged, as the
                         * non-reward branch below it does.
                         *
                         * The shipping-OFF branches are deliberately untouched: they build from
                         * `total_final_price`, which processRewardPoints() does not adjust, and
                         * their dropping of `$coupon_price` is the pre-existing item S32
                         * records for all six gateway handlers, gated on S24.
                         */
                        $order->final_price = $final_sub_total_price;
                    } else {
                        $order->final_price = $final_price + $tax_price - $saveRewardPrice;
                    }
                } else {
                    if ($planShippingMethod == "on") {
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
             * DERIVED, not asserted. This was the literal `'Paid'`. Unlike the five pure returns
             * there IS a gateway answer behind it here -- this handler made the capture -- and
             * that answer was recorded through PaymentVerificationService immediately after the
             * sale, so this call consumes a real verification rather than inventing one. The
             * status it yields today is the same 'Paid'; what changes is that it is now
             * attributable, and that enforcing NMI later is a one-line change in that service.
             */
            $order->payment_status = $this->gatewayReturnPaymentStatus(
                $requests_data['payment_type'] ?? 'nmi',
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
            /*
             * `!empty()` rather than a bare read. Same family as the `out_of_stock_threshold` /
             * `notification` sweep: a raw array index with no default, where a state that does
             * not carry the key raises "Undefined array key" -- which Laravel promotes to an
             * ErrorException -- AFTER `$order->save()` has committed. Behaviour is identical for
             * every value the cart reader actually produces (it writes `$tax_id ?? null`).
             */
            if(!empty($response['tax_id'])){
                $taxes = TaxMethod::where('tax_id',$response['tax_id'])->where('store_id', $store->id)->orderBy('priority', 'asc')->get();
                $other_info = is_string($requests_data['billing_info']) ? (array) json_decode($requests_data['billing_info']) : $requests_data['billing_info'];
                $country = !empty($other_info->delivery_country) ? $other_info->delivery_country :$other_info['delivery_country'];
                $state_id = !empty($other_info->delivery_state) ? $other_info->delivery_state : $other_info['delivery_state'];
                $city_id = !empty($other_info->delivery_city) ? $other_info->delivery_city : $other_info['delivery_city'];
                foreach ($taxes as $tax) {
                    $countryMatch = (!$tax->country_id || $country == $tax->country_id);
                    $stateMatch = (!$tax->state_id || $state_id == $tax->state_id);
                    $cityMatch = (!$tax->city_id || $city_id == $tax->city_id);

                    if ($countryMatch && $stateMatch && $cityMatch) {
                        $OrderTaxDetail = new OrderTaxDetail();
                        $OrderTaxDetail->order_id = $order->id;
                        $OrderTaxDetail->product_order_id = $order->product_order_id;
                        $OrderTaxDetail->tax_id = $response['tax_id'];
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
            $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;

            //Order Mail
            //$order_email = !empty($other_info->email) ? $other_info->email : '';
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
                $product_data = Product::find($product['product_id']);

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
            if(auth('customers')->user() && module_is_active('ProductAffiliate'))
            {
                Utility::affiliateTransaction($order);
            }
            // add in Order Tax Detail table end
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
                return $this->error(['message' => __('Somthing went wrong.')]);
            }
        } else {
            /*
             * THE CARD WAS DECLINED, OR THE REQUEST NEVER GOT AN ANSWER -- so no money moved and
             * the checkout must survive. The state was consumed before the charge to stop a
             * replay reaching the gateway twice; it goes back here, because a shopper who
             * mistyped a CVV or hit an NMI outage has to be able to try again. Without this they
             * met "We could not confirm your checkout details. ... please contact the store" on
             * their second attempt, which is a dead checkout for an entirely ordinary event.
             *
             * `chargeSale()` returns [] when the request itself failed, so that case lands here
             * too and is restored the same way.
             */
            \Session::put(self::CHECKOUT_STATE_KEY, $requests_data);
            /*
             * P1-PAY-02. The claim goes back with the state, and it HAS to: the state carries the
             * same checkout_nonce, so without this the restored checkout meets "This payment has
             * already been recorded against an order" on the retry -- for an order that was never
             * written and money that never moved. Safe on this branch and only on this branch:
             * chargeSale returned a decline or nothing at all, so there is no capture to replay.
             */
            $this->releaseGatewayReturnClaim($request, 'nmi', $requests_data);

            return redirect()->back()->with(
                'error',
                __($parsedResponse['responsetext'] ?? 'The payment could not be completed. Please try again.')
            );
        }
    }
}
