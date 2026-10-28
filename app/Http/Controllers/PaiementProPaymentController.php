<?php

namespace App\Http\Controllers;

use App\Events\AddRewardClubPoint;
use App\Events\PaiementProPayment;
use App\Models\User;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use App\Models\Coupon;
use App\Events\AddAdditionalFields;
use App\Http\Controllers\Api\ApiController;
use App\Models\ActivityLog;
use App\Models\Cart;
use App\Models\City;
use App\Models\Customer;
use App\Models\OrderBillingDetail;
use App\Models\OrderCouponDetail;
use App\Models\OrderNote;
use App\Models\OrderTaxDetail;
use App\Models\PlanUserCoupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Store;
use App\Models\TaxMethod;
use App\Models\UserCoupon;
use App\Models\Utility;
use Session;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Http\Request;
use App\Facades\ModuleFacade as Module;
use Illuminate\Support\Facades\Cache;
use App\Traits\ApiResponser;
use App\Http\Controllers\Concerns\GuardsPlanActivation;
use App\Http\Controllers\Concerns\GuardsGatewayReturn;

class PaiementProPaymentController extends Controller
{
    use GuardsPlanActivation;
    use GuardsGatewayReturn;

    /*
     * D-13 / S32. The three raw read-modify-write stock decrements in `getProductStatus()` now go
     * through this trait's `decrementStockOrFail()`, which RECORDS each decrement so
     * `restoreStockDecrements()` can give it back when a later cart line makes the handler return
     * early. It does NOT close the unverified decrement -- see
     * `docs/development/phase-2/E13-STOCK-EVIDENCE-ANALYSIS.md` for why a decrement deferred to
     * payment confirmation would simply never be taken.
     */
    use \App\Http\Controllers\Concerns\AppliesCartStockChanges;
    use \App\Http\Controllers\Concerns\ResolvesPartialPaymentDeposit;
    /* P1-ORD-02: a pending balance is flipped to paid only for the customer who owes it. */
    use \App\Http\Controllers\Concerns\ResolvesPendingPartialPayment;

    use ApiResponser;//

    public function planPayWithPaiementpro(Request $request)
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
                $merchant_id = isset($payment_setting['paiementpro_merchant_id']) ? $payment_setting['paiementpro_merchant_id'] : '';
                $call_back = route('plan.get.paiementpro.status', ['plan_id' => $plan->id,'coupon_code'=>$request->coupon_code,'amount' => $price,]);
                $returnURL = route('plan.index', \Illuminate\Support\Facades\Crypt::encrypt($plan->id));

                $data = array(
                    'merchantId' =>$merchant_id,
                    'amount' =>  $price,
                    'description' => "Api PHP",
                    'channel' => $request->channel,
                    'countryCurrencyCode' => !empty($currency)?$currency:'',
                    'referenceNumber' => "REF-" . time(),
                    'customerEmail' => $user->email,
                    'customerFirstName' => $user->name,
                    'customerLastname' =>  $user->name,
                    'customerPhoneNumber' => $request->mobile_number,
                    'notificationURL' => $call_back,
                    'returnURL' => $call_back,
                    'returnContext' => json_encode(['coupon_code' => $request->coupon_code,
                    ])
                    ,
                );

                $data = json_encode($data);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://www.paiementpro.net/webservice/onlinepayment/init/curl-init.php");
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($ch, CURLOPT_HEADER, FALSE);
                curl_setopt($ch, CURLOPT_POST, TRUE);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
                $response = curl_exec($ch);

                curl_close($ch);
                $response = json_decode($response);

                if (isset($response->success) && $response->success == true) {
                    // redirect to approve href
                    return redirect($response->url);

                    return redirect()
                        ->route('plan.index', \Illuminate\Support\Facades\Crypt::encrypt($plan->id))
                        ->with('error', 'Something went wrong. OR Unknown error occurred');
                } else {
                    return redirect()
                        ->route('plan.index', \Illuminate\Support\Facades\Crypt::encrypt($plan->id))
                        ->with('error', $response->message ?? 'Something went wrong.');
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
     * P0 (free activation). CLASSIFIED BY HAND THIS CYCLE -- previously "not hand-verified".
     *
     * The gate is `$request->responsecode == 0`, read straight off the return. planPayWithPaiementpro
     * builds the callback URL itself at :77 with the plan and the amount on it, so a merchant's
     * first checkout hands them a URL that needs only `&responsecode=0` appended.
     *
     * Note the comparison is loose against integer 0, so in PHP 8 an ABSENT responsecode (null)
     * also satisfies it -- `null == 0` is true. The gate was therefore satisfied by a bare GET
     * to the callback with no responsecode at all.
     *
     * Activation is refused until the transaction is confirmed against PaiementPro; see
     * config/payments.php.
     *
     * THIS IS THE PLAN PATH ONLY. getProductStatus() in this controller is the storefront order
     * path and is untouched.
     */
    public function planGetPaiementproStatus(Request $request)
    {
        $plan = Plan::find($request->plan_id);

        $this->assertPlanActivationEnabled('paiementpro', $plan);

        if (empty($plan)) {
            abort(404, __('Plan not found.'));
        }

        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

        $getAmount = $this->serverOwnedPlanPrice($plan, $request->coupon_code);
        $coupanCode = $request->coupon_code;
        $user = \Auth::user();

        if ($request->responsecode == 0) {

            $order = new PlanOrder();
            $order->order_id = $orderID;
            $order->name = $user->name;
            $order->card_number = '';
            $order->card_exp_month = '';
            $order->card_exp_year = '';
            $order->plan_name = $plan->name;
            $order->plan_id = $plan->id;
            $order->price = $getAmount;
            $order->price_currency = $currency;
            $order->txn_id = time();
            $order->payment_type = __('Paiement Pro');
            $order->payment_status = 'success';
            $order->txn_id = '';
            $order->receipt = '';
            $order->user_id = $user->id;
            $order->save();

            // Portable and case-exact; see GuardsPlanActivation::findPlanCouponExact().
            $coupons = $this->planCouponForRecording($coupanCode);
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
        } else {
            return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
        }
    }

    public function getProductStatus(Request $request, $slug)
    {
        /*
         * =========================================================================================
         *  THIS HANDLER'S ONLY GATEWAY CHECK WAS COMMENTED OUT, SO IT WROTE AN ORDER FOR ANY RETURN.
         * =========================================================================================
         *
         * The line below this block reads `// if ($request->responsecode == 0) {` -- commented out,
         * and `git blame` puts it at the boundary commit, so it arrived that way rather than being
         * disabled by this project. The handler therefore wrote a Paid order whatever PaiementPro
         * said, including for a declined payment and for a bare GET to the callback.
         *
         * OWNER DECISION, Phase 01 final execution directive: "Any unverified gateway payment must
         * remain Pending, never Paid. Do not create a falsely paid order, and preserve a safe
         * reconciliation path for customers who may have been charged."
         *
         * WHY THE COMMENT IS NOT SIMPLY RESTORED, and this is the part that matters. Two reasons,
         * both measured rather than assumed:
         *
         *   1. THAT GATE NEVER WORKED. `$request->responsecode == 0` is a LOOSE comparison against
         *      integer 0, and in PHP 8 `null == 0` is TRUE -- so an ABSENT responsecode satisfied
         *      it. A prior cycle recorded exactly this on the sibling plan path in this same file
         *      (:149-151): "The gate was therefore satisfied by a bare GET to the callback with no
         *      responsecode at all." Restoring it would install a check that passes the attack it
         *      is supposed to stop.
         *
         *   2. RESTORING IT WOULD REFUSE RATHER THAN RECONCILE. If PaiementPro's real return does
         *      not carry `responsecode` under that name -- which this repository cannot establish,
         *      and which docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md lists as an item to
         *      obtain from the provider -- then an `if` around the whole handler stops every
         *      legitimate order being written while the customer has already been charged. That is
         *      the exact failure the directive's second sentence forbids.
         *
         * ============================================================================================
         *  WHAT WAS DONE FIRST, AND WHY IT WAS WRONG. CORRECTED, NOT DELETED.
         * ============================================================================================
         *
         * The first version of this block RECORDED the gateway's success code as a verification, on
         * a strict `=== '0'` comparison, reasoning that a strict test an absent value cannot satisfy
         * was enough. It is not, and an independent verification pass over that very commit found
         * two ways to get a Paid order without paying. BOTH WERE REPRODUCED on the real route
         * before this correction:
         *
         *   SHAPE 1, the plant. recordVerification() ran BEFORE the stored-checkout-state read
         *       below, and the `=== null` refusal returns without clearing it. So a POST carrying
         *       `responsecode=0` and no stored state wrote no order and left
         *       ['payment_type' => 'paiementpro', ...] in the SESSION; a later, genuine return in
         *       the same session carrying no responsecode at all then redeemed it and the order was
         *       written PAID.
         *
         *   SHAPE 2, the forgery, and this is the deeper one. `responsecode` is the CALLER'S OWN
         *       FIELD. This handler makes no provider call -- no Http:: request, no transaction
         *       lookup, no signature check -- and the route is a `Route::any`
         *       (routes/customer.php:195) that accepts GET as readily as POST. So any caller who
         *       could reach the return URL could mint a Paid order for a basket they never paid
         *       for, and recording it told PaymentVerificationService that something had been
         *       verified when nothing had.
         *
         * OWNER DECISION D2 SETTLES IT, and the first version did not weigh it:
         *
         *     "Gateways: a return URL, redirect or client flag proves nothing; require
         *      provider-side verification of ownership, reference, amount, currency and status;
         *      ... an unverifiable gateway stays Pending and writes no paid ledger entry"
         *
         * A client flag proves nothing. PaiementPro is therefore an UNVERIFIABLE gateway in D2's
         * sense until its own status endpoint and a credential exist, so NOTHING IS RECORDED HERE.
         *
         * WHAT HAPPENS NOW. The order is still always written -- the reconciliation half of the
         * directive, and a refusal would be the worse failure it names -- and
         * PaymentVerificationService::resolveOrderPaymentStatus() finds no verification record and
         * returns PENDING. Every PaiementPro order is Pending until a human confirms it, no wallet
         * is credited for one, and the warning below is the reconciliation queue rather than just a
         * trace.
         *
         * WHAT THIS COSTS, stated rather than discovered in production: PaiementPro no longer
         * settles automatically at all. That is the price D2 sets for a gateway that cannot be
         * asked whether money moved. Restoring automatic settlement needs PaiementPro's own status
         * endpoint, its response field names and an authenticated credential -- per-gateway work
         * recorded on #25 and in docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md.
         *
         * AND THE SHAPE-1 HAZARD IS CLOSED BY CONSTRUCTION rather than by moving the call below the
         * refusal: with nothing recorded there is nothing to plant. A gateway that genuinely
         * verifies must still record AFTER the stored-state refusal, which is where the NMI and
         * AuthorizeNet arms already do it.
         */
        \Illuminate\Support\Facades\Log::warning('PaiementPro return recorded PENDING: this gateway is not provider-verified', [
            'slug' => $slug,
            'responsecode' => $request->input('responsecode'),
            'reason' => 'D2: a client flag proves nothing; no provider call exists on this path',
        ]);

            /*
             * P01-COMMERCE-SEC-01. THE CHECKOUT STATE IS THE SERVER'S, OR THERE IS NO ORDER.
             *
             * Three things were wrong here and all three are closed by reading the stored state
             * and refusing when it is absent:
             *
             *   1. the `$request->all()` fallback handed the caller the prices, the shipping, the
             *      coupon, the cart, the STORE and the CUSTOMER -- by not sending a session cookie;
             *   2. `$request->merge($requests_data)` ran BEFORE that null check, so on the
             *      fallback path the merge had already pushed a null through the request bag;
             *   3. the `coupon_info` fix-up below read `$request->cartlist`, i.e. the caller's own
             *      cart, into the state the order is written from. It now reads the state's own
             *      cartlist, which PaymentController::processOrder computed.
             *
             * See App\Http\Controllers\Concerns\GuardsGatewayReturn for why the stored state is
             * sufficient: it is the request as it stood after processOrder priced the cart,
             * computed the shipping server-side and refused a non-positive quantity.
             */
            $requests_data = $this->storedCheckoutState($request, 'paiementpro');

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
            if (! $this->claimGatewayReturn($request, 'paiementpro', $requests_data)) {
                return $this->gatewayReturnReplayRefusal();
            }

            if (! isset($requests_data['coupon_info']) && isset($requests_data['cartlist']['coupon_info'])) {
                $requests_data['coupon_info'] = is_object($requests_data['cartlist']['coupon_info'])
                    ? (array) $requests_data['cartlist']['coupon_info']
                    : $requests_data['cartlist']['coupon_info'];
            }

            $request->merge($requests_data);
            $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
            $store = getStore($slug);

            Session::forget('request_data');
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
                return $this->gatewayReturnQuantityRefusal($request, 'paiementpro');
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
                return $this->gatewayReturnForeignProductRefusal($request, 'paiementpro');
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
                 * D-13. Decrements applied by the loop below, so they can be GIVEN BACK when a
                 * later line makes this handler return early. Before this, each of the three
                 * in-loop `return $this->error(...)` exits left every line already processed
                 * decremented, with PaiementPro's money captured and no order row to show for it.
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
                                     * `false` is $rejectOnShortfall: on a shortfall the trait re-runs the decrement
                                     * with overselling allowed, which is what the raw `stock - qty` did, and logs it at
                                     * critical. Refusing is not available -- PaiementPro has already captured the money by the
                                     * time this runs.
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

                                return $this->error(['message' => __('Product not found .')]);
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
             * WHAT ACTUALLY HAPPENS HERE: this handler records NO verification -- measured by stripping
             * comments and counting real calls, because the only textual hit in this file is the comment
             * above documenting the recording that was REMOVED after two ways to get a Paid order without
             * paying were reproduced on the real route. So every order it writes is PENDING, and since
             * OrderObserver::created() credits a merchant wallet only for an order already Paid at creation,
             * no wallet is credited. That is the single bound making this handler's unverified stock
             * decrement (F90) inventory damage rather than free paid goods.
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
                if($Coupon){
                    $UserCoupon = new UserCoupon();
                    $UserCoupon->user_id = !empty($requests_data['customer_id']) ? $requests_data['customer_id'] : null;
                    $UserCoupon->coupon_id = $Coupon->id;
                    $UserCoupon->amount = $coupon_data['coupon_final_amount'];
                    $UserCoupon->order_id = $order->id;
                    $UserCoupon->date_used = now();
                    
                    $UserCoupon->save();
                }

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
            $order_id    = \Crypt::encrypt($order->id);

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
            event(new PaiementProPayment($OrderBillingDetail,$order,$owner));

            if(auth('customers')->user() &&  module_is_active('ProductAffiliate'))
            {
                Utility::affiliateTransaction($order);
            }
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
                return $this->error(['message' => 'Somthing went wrong.']);
            }
        // } else {
        //     return redirect()->back()->with('error', __('Transaction Unsuccesfull'));
        // }
    }
}
