<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * THE CHECKOUT STATE A GATEWAY-RETURN HANDLER MAY TRUST.
 * =============================================================================================
 *
 * WHAT THIS GOVERNS. One question, asked by the six storefront gateway-return handlers before
 * they write an order: *where may the money come from?* The answer is the state this server
 * stored at payment initiation, and nothing else.
 *
 * THE DEFECT IT CLOSES, measured rather than argued. Five of the six opened with
 *
 *     $requests_data = Session::get('request_data');
 *     if ($requests_data == null) {
 *         $requests_data = $request->all();          // <-- the browser becomes authoritative
 *     }
 *     $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
 *     $store = getStore($slug);                      // <-- ... for the STORE
 *     $customer_id = $requests_data['customer_id'] ?? '';   // <-- ... and the CUSTOMER
 *
 * An attacker simply does not send the session cookie. The fallback then hands them every field
 * the handler goes on to trust: the prices, the shipping, the coupon, the cart contents, the
 * store the order is written to, and the customer it is written for. It is not only an amount
 * defect -- it is a cross-store and cross-customer order writer with no ownership predicate
 * anywhere on the path.
 *
 * EASEBUZZ WAS THE INVERSE, and worse: it read the state from a REQUEST parameter FIRST and used
 * the session only as a fallback --
 *
 *     $requests_data = json_decode(urldecode($request->input('request_data')), true);
 *     if (empty($requests_data)) { $requests_data = Session::get('easebuzz_request_data'); }
 *
 * so no expired session was even required; a JSON blob in the query string was authoritative by
 * design.
 *
 * WHY READING THE SESSION IS ENOUGH, AND WHY THAT IS THE WHOLE POINT. The stored state is not
 * "the request at initiation". It is the request as it stood AFTER
 * PaymentController::processOrder had finished with it:
 *
 *     getCartData()              (:1180)  -- the cart priced server-side
 *     processShipping()          (:1245)  -- the SERVER's delivery price
 *     prepareFinalRequestData()  (:1250)  -- merges shipping_final_price, tax_price,
 *                                            cartlist_final_price and cartlist back in
 *     processPayment()           (:1253)  -- PaymentService stores $request->except(...)
 *
 * and processOrder now also zeroes a posted `shipping_final_price` before the cart read
 * (P01-SEC-14 H) and refuses a basket holding a non-positive quantity. So a handler that reads
 * ONLY the stored state inherits every one of those protections for free, instead of
 * re-implementing them six times and drifting. That is why this trait does not re-validate
 * amounts: the figures it returns were computed by the guarded path.
 *
 * WHY REFUSE RATHER THAN FALL BACK. The two candidates for an absent state are "trust the
 * browser" and "refuse". Trusting the browser is the defect. Refusing can, in principle, strand
 * a genuine shopper whose session died between initiation and return -- so the refusal logs
 * everything needed to reconstruct the order by hand, and `config/session.php` sets a 120-minute
 * lifetime against a gateway round trip measured in minutes.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not decide whether a payment SUCCEEDED. No method
 * here looks at a status code, a signature or a gateway response. That question belongs to
 * App\Services\PaymentVerificationService, which already owns it, and for these six gateways it
 * is blocked on credentials this environment does not have -- see
 * docs/security/P01-SECURITY-WORK-QUEUE.md. Conflating "the state is trustworthy" with "the money
 * arrived" is exactly the mistake these handlers already make, and this trait must not repeat it.
 *
 * The shape is deliberately the same as the sibling GuardsPlanActivation: a Concern trait, a
 * refusal that logs with enough context to act on, and a posture asserted by tests.
 */
trait GuardsGatewayReturn
{
    /**
     * The session key the storefront checkout stores its state under.
     *
     * Written by App\Services\PaymentService at eight sites, all as
     * `Session::put('request_data', $requestData)`.
     */
    public const CHECKOUT_STATE_KEY = 'request_data';

    /**
     * The key inside the stored state holding this checkout's one-time identifier.
     *
     * Stamped ONCE, by App\Services\PaymentService::process(), which is the web checkout's single
     * entry point to the gateways (PaymentController:1778 is its only caller). Stamping there
     * rather than at the 46 `Session::put('request_data', ...)` sites means every branch inherits
     * it from `$request->except('attachment','payment_receipt')` without 46 edits to keep in step.
     */
    public const CHECKOUT_NONCE_KEY = 'checkout_nonce';

    /**
     * How long a consumed return stays claimed.
     *
     * Longer than any gateway's retry window, so a claim cannot lapse while the gateway is still
     * resending. It is not a security parameter in the other direction: a claim that expires
     * after two days leaves a replay facing a session that was forgotten two days earlier.
     */
    private const GATEWAY_RETURN_CLAIM_TTL = 172800;

    /**
     * The largest single cart-line quantity that can be a real order.
     *
     * See basketHasUnusableQuantity() for the measurement that produced it, and for why this is a
     * sanity bound rather than a business rule.
     */
    public const MAX_SANE_LINE_QUANTITY = 1000000;

    /**
     * The checkout state this server stored at payment initiation, or NULL.
     *
     * NULL means "do not write an order from this request" -- never "fall back to the request".
     * The caller must return a refusal; see gatewayReturnRefusal().
     *
     * @param  string  $gateway  for the log line only, so a refusal names the arm it came from
     * @param  string|null  $sessionKey  Easebuzz stores under its own key
     * @return array<string, mixed>|null
     */
    protected function storedCheckoutState(Request $request, string $gateway, ?string $sessionKey = null): ?array
    {
        $state = Session::get($sessionKey ?? self::CHECKOUT_STATE_KEY);

        if (! is_array($state) || $state === []) {
            /*
             * The log carries what a human needs to reconcile a real payment that landed here
             * without its state, and what a reviewer needs to tell that apart from an attempt.
             * `money_fields_posted` is the tell: a genuine shopper's return carries the gateway's
             * own parameters, not a cart.
             */
            Log::warning('Gateway return refused: no server-stored checkout state.', [
                'gateway' => $gateway,
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'authenticated_customer' => optional(auth('customers')->user())->id,
                'money_fields_posted' => array_values(array_intersect(
                    ['shipping_final_price', 'tax_price', 'cartlist_final_price', 'cartlist',
                     'coupon_info', 'coupon_code', 'final_price', 'price', 'amount',
                     'customer_id', 'store_id', 'slug', 'request_data'],
                    array_keys($request->all())
                )),
            ]);

            return null;
        }

        return $state;
    }

    /**
     * Make the stored checkout state the request, for the whole handler.
     *
     * WHY THIS IS A SECOND GUARD AND NOT A TIDY-UP. storedCheckoutState() decides WHICH array the
     * handler reads. It does not stop the handler reading `$request->x` instead, and five of these
     * handlers do -- on one branch only. Each splits on
     *
     *     if (! auth('customers')->user()) { ...guest... }
     *     elseif (! empty($customer_id))  { ...$request->merge($requests_data); ... }
     *
     * so the merge that reconciles the two runs for an AUTHENTICATED shopper and never for a
     * guest. On the guest arm every later `$request->customer_id` is the POSTED value: it becomes
     * `$order->customer_id`, the `product_order_id`, the UserCoupon owner, and the subject of
     * `Cart::where('customer_id', $request->customer_id)->delete()`.
     *
     * The stored-state guard cannot close that, because the attacker is a real guest with real
     * stored state -- they add one field to the return they were already making. Measured, before
     * this method existed: a guest return posting `customer_id=<victim>` wrote an order OWNED BY
     * THE VICTIM on cinetpay and easebuzz, and emptied the victim's basket on cinetpay,
     * easebuzz and khalti.
     *
     * WHY IT PINS TWO FIELDS SEPARATELY. `Request::merge()` can only overwrite a key the stored
     * state actually carries, so a field the state OMITS keeps whatever the caller posted -- and
     * both of the fields that matter are routinely omitted.
     *
     *   customer_id: a guest state carries none, because there was no customer to record. Merging
     *   alone would leave the posted id standing in exactly the case that matters. Pinned to
     *   `?? ''`, which every one of these handlers already reads as "a guest"
     *   (`!empty($request->customer_id) ? ... : 0`).
     *
     *   method_id: a checkout where the shopper chose no shipping method carries none, and the
     *   handlers then take the POSTED one -- `if (!empty($request->method_id))` ->
     *   `CartController::get_shipping_method()` -> `ShippingMethod::find($request->method_id)`
     *   (CartController.php:433), which has no store predicate and whose model is NOT in
     *   config/tenancy.php's scoped_models. So the browser chose the shipping price, from any
     *   tenant's methods. MEASURED: a store-B 'Local pickup' method with cost '-500' posted on a
     *   store-A return put `-500.00SYP` into `orders.delivery_price` -- another tenant's figure,
     *   negative, and not even numeric, in a money column. The payable itself was unaffected
     *   (`$order->final_price` is built from the stored state's `cartlist_final_price` and the
     *   server's cart figures, never from `$delivery_price`), which is why this is recorded as a
     *   shipping-price and data-integrity defect and not as a payable manipulation. The brief
     *   names shipping price as the browser's to lose either way. Pinned to `?? ''`, which the
     *   handlers' own `if (!empty($requests_data['method_id']))` already treats as "no method".
     *
     * WHAT IT DOES NOT TOUCH. merge() only replaces keys the state holds. The gateway's own return
     * fields -- token, status, responsecode, transaction ids -- are not in the state, because the
     * state was captured at initiation, before the gateway answered; nor are the card fields
     * AuthorizeNet's form collects. All of those survive untouched, which is why this is safe to
     * apply unconditionally on a live checkout.
     */
    protected function adoptStoredCheckoutState(Request $request, array $state): void
    {
        $request->merge($state);
        $request->merge([
            'customer_id' => $state['customer_id'] ?? '',
            'method_id' => $state['method_id'] ?? '',
        ]);
    }

    /**
     * Claim this return, so one checkout and one payment can produce at most one order.
     *
     * P1-PAY-02. WHAT THE SESSION ALREADY DOES, AND WHERE IT STOPS. Each of these handlers calls
     * `Session::forget()` immediately after reading the state, so a SEQUENTIAL replay from the
     * same browser finds nothing and is refused. GatewayReturnTrustBoundaryTest::
     * test_replaying_a_return_cannot_multiply_orders pins exactly that, and its own docblock says
     * what it does not pin: "It does NOT claim the handlers have an idempotency key. They do not:
     * the only thing stopping a replay is that the session entry is gone."
     *
     * THE SESSION CANNOT STOP TWO RETURNS THAT OVERLAP, and that is the gap. Laravel does not
     * hold a session lock for the life of a request unless a route asks for one, and none of
     * these does. Two returns for the same payment -- a shopper's double submit, a browser
     * retry, a gateway that pokes the return URL while the browser is also on it -- both READ the
     * state before either WRITES the forget, so both proceed. That is the same read-check-use
     * straddle already fixed on the Paymera path (see PaymentController::freshPaymeraPendingOrNull),
     * where it was measured to produce a second order, a second stock decrement and a second
     * wallet credit. The six handlers here were never given the equivalent.
     *
     * SO THE CLAIM LIVES OUTSIDE THE SESSION. `Cache::add()` is insert-if-absent: on the database
     * store this project runs in production (CACHE_STORE=database) it is an INSERT that the
     * primary key rejects on the second caller, decided by the engine rather than by PHP. Exactly
     * one of two overlapping returns can take a given key.
     *
     * TWO KEYS, BECAUSE THEY ANSWER DIFFERENT QUESTIONS.
     *
     *   checkout -- the nonce PaymentService::process() stamped at initiation. "One checkout
     *   produces at most one order." Present on every gateway.
     *
     *   payment -- the gateway's own transaction reference. "One payment produces at most one
     *   order." This is the one the nonce cannot answer: a shopper who completes checkout A, then
     *   starts checkout B and replays A's return, arrives with a FRESH nonce, and only the
     *   payment reference ties the two returns together.
     *
     * THE PAYMENT KEY COVERS TWO OF THE SIX, AND THE OTHER FOUR ARE A FINDING, NOT AN OMISSION.
     * See gatewayTransactionReference(): only khalti and cinetpay read a transaction reference
     * from their own return at all. The remaining four decide on a request field or on nothing,
     * which is the unverified-payment exposure already recorded as an owner blocker -- for those,
     * a replayed return is not the first problem.
     *
     * FAIL-CLOSED ON A PARTIAL CLAIM, deliberately. If the checkout key is taken and the payment
     * key is already held, the checkout key is NOT released: this checkout is spent either way,
     * and un-refusing it to keep two cache entries tidy would be the wrong direction for a money
     * control.
     *
     * WHERE THIS MAY BE CALLED FROM, and it is a rule the first version of this change broke on
     * two handlers. A claim is CONSUMED, so it may only be taken on a path that is about to take
     * money or write an order. Two handlers deliberately let a shopper come back to the same
     * stored state -- NMIPayController puts the state back when a card is DECLINED, and
     * PaymentController::getProductStatus returns early, state intact, when PhonePe or toyyibpay
     * report a payment that is still PENDING. Claiming above those exits burned the claim on a
     * visit that took no money, and the shopper's genuine retry was then refused as a replay. An
     * independent review of this change caught both. So: take the claim as late as possible while
     * still above every write, and where a handler restores the state for a retry, release the
     * claim with releaseGatewayReturnClaim() in the same breath.
     *
     * FAIL-OPEN WHEN THERE IS NOTHING TO CLAIM, also deliberately, and bounded. A checkout
     * initiated before this code shipped carries no nonce, and on a gateway with no reference
     * there is then no key at all. Refusing those would turn a deploy into a wave of failed
     * returns on real payments; they fall back to the session control that has always been there,
     * and the fallback is logged so the window is measurable rather than invisible.
     *
     * @param  array<string, mixed>  $state  the server-stored checkout state
     * @return bool  true if this return may proceed; false if it is a replay
     */
    protected function claimGatewayReturn(Request $request, string $gateway, array $state): bool
    {
        $keys = $this->gatewayReturnClaimKeys($request, $gateway, $state);

        if ($keys === []) {
            Log::warning('Gateway return carries no claimable identity; falling back to the session control alone.', [
                'gateway' => $gateway,
                'path' => $request->path(),
                'reason' => 'the stored state carries no checkout nonce and this gateway supplies no transaction reference',
            ]);

            return true;
        }

        foreach ($keys as $kind => $key) {
            if (Cache::add($key, $gateway, self::GATEWAY_RETURN_CLAIM_TTL)) {
                continue;
            }

            Log::warning('Gateway return refused: already claimed.', [
                'gateway' => $gateway,
                'claim' => $kind,
                'path' => $request->path(),
                'ip' => $request->ip(),
                'authenticated_customer' => optional(auth('customers')->user())->id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Give the claim back, because this return did not take money after all.
     *
     * THE ONE LEGITIMATE REASON TO CALL THIS: the handler is putting the stored checkout state
     * BACK so the shopper can try again. A declined card is the case that forced it to exist --
     * NMIPayController consumes the state before the charge so a replay cannot reach the gateway
     * twice, and restores it when `chargeSale` returns a decline, because nothing was captured
     * and a mistyped CVV must not kill the checkout. Without a matching release the claim stayed
     * taken and the retry met "This payment has already been recorded against an order" for an
     * order that was never written.
     *
     * IT IS NOT A GENERAL UNDO. Calling it after an order row, a stock movement or a capture
     * would re-open exactly the replay this control closes. Call it only where the state itself
     * is being restored, and only on a branch where no money moved.
     *
     * @param  array<string, mixed>  $state  the same state the claim was taken from
     */
    protected function releaseGatewayReturnClaim(Request $request, string $gateway, array $state): void
    {
        foreach ($this->gatewayReturnClaimKeys($request, $gateway, $state) as $key) {
            Cache::forget($key);
        }
    }

    /**
     * The keys this return must hold before it may write anything.
     *
     * Hashed rather than stored raw: a gateway transaction reference is payment metadata, and a
     * cache row is read by anyone who can read the cache table.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, string>  kind => key, empty when nothing identifies this return
     */
    private function gatewayReturnClaimKeys(Request $request, string $gateway, array $state): array
    {
        $keys = [];

        $nonce = $state[self::CHECKOUT_NONCE_KEY] ?? null;
        if (is_string($nonce) && trim($nonce) !== '') {
            $keys['checkout'] = 'gateway-return-claim:checkout:' . sha1(trim($nonce));
        }

        $reference = $this->gatewayTransactionReference($request, $gateway);
        if ($reference !== null) {
            /*
             * SCOPED BY STORE, although no live collision was found. A review traced both
             * references and neither can collide across tenants today -- cpm_trans_id is minted
             * by this platform per payment (PaymentService:4092) and khalti's token is the
             * gateway's own per-payment value -- so this closes a CLASS rather than a hole: a
             * gateway added later whose reference is a per-merchant sequence ("order 1001") would
             * otherwise let one tenant's payment deny another's. The store comes from the stored
             * state, which the server wrote; it is never the caller's.
             */
            $storeId = $state['store_id'] ?? '';
            $storeId = is_scalar($storeId) ? trim((string) $storeId) : '';

            $keys['payment'] = 'gateway-return-claim:payment:' . $gateway . ':'
                . ($storeId === '' ? 'no-store' : $storeId) . ':' . sha1($reference);
        }

        return $keys;
    }

    /**
     * This gateway's own identifier for the payment, read from its return, or NULL.
     *
     * EVERY ENTRY IS TAKEN FROM THIS REPOSITORY'S OWN CODE, not from a gateway's documentation and
     * not from a guess. The project rule is to claim only what was executed, and nothing here can
     * call a live gateway.
     *
     *   khalti    `payload.token` -- KhaltiPaymnetController::getOrderPaymentStatus reads exactly
     *             this and hands it to Khalti's own verifyPayment() (:652-655), so it is the
     *             reference that gateway settles against.
     *
     *   cinetpay  `cpm_trans_id` -- CinetPayController::notify_url, the server-to-server half of
     *             the same payment, reads `$request->cpm_trans_id` and sends it back as
     *             `transaction_id` (:214, :228). The value is minted BY THIS PLATFORM, not by
     *             CinetPay, at PaymentService:4092 -- `strtoupper(str_replace('.', '',
     *             uniqid('', true)))`, sent as `transaction_id` at :4099 on the storefront
     *             initiation -- so it is unique to one payment and not a constant the merchant
     *             reuses, which is what would make keying on it refuse legitimate second
     *             purchases. (An earlier draft of this docblock cited CinetPayController:93 and
     *             :153 for that generation. Both are the wrong flow -- :93 is the SaaS PLAN
     *             purchase and :153 is an unrouted demo using env('APIKEY') -- and a review
     *             caught it. The conclusion was right and the citation was not, which on a
     *             docblock whose whole claim is "taken from this repository's own code" is worth
     *             correcting in place rather than quietly.)
     *
     * THE OTHER FOUR SUPPLY NOTHING, and that is a finding rather than a gap in this census.
     * authorizenet, easebuzz, paiementpro and the storefront return read no transaction identifier
     * from their return at all -- paiementpro branches on `responsecode`, which says only "the
     * browser claims this succeeded". That is the unverified-payment exposure recorded as an owner
     * blocker, and the honest statement is that for those four this control is the checkout nonce
     * only.
     *
     * A missing field yields NULL and therefore no key, so a gateway that stops sending one loses
     * the payment key and keeps the checkout key. It never produces a WRONG key.
     */
    private function gatewayTransactionReference(Request $request, string $gateway): ?string
    {
        $raw = null;

        if ($gateway === 'khalti') {
            $payload = $request->input('payload');
            $raw = is_array($payload) ? ($payload['token'] ?? null) : null;
        } elseif ($gateway === 'cinetpay') {
            $raw = $request->input('cpm_trans_id');
        }

        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $raw = trim((string) $raw);

        return $raw === '' ? null : $raw;
    }

    /**
     * What a handler returns when this return has already been claimed.
     *
     * Its own text, separate from the state, quantity and foreign-product refusals, so a log line
     * or a support conversation can tell which guard fired -- and so a regression test can assert
     * it rather than inferring it from an order that was not written.
     *
     * IT DOES NOT SAY "your payment failed", because it did not: the shopper's money moved and an
     * order exists. A replay is the one refusal here that is usually the shopper's own browser
     * rather than an attacker, so the text points them at the order they already have.
     */
    protected function gatewayReturnReplayRefusal()
    {
        return redirect()->back()->with(
            'error',
            __('This payment has already been recorded against an order. No second order was created — please check your order history before paying again.')
        );
    }

    /** @var \App\Services\PaymentVerificationService|null */
    private $gatewayReturnVerifier;

    /**
     * The platform's existing answer to "may this order be recorded as Paid?".
     *
     * NOT A NEW SEAM. App\Services\PaymentVerificationService already owns this question and is
     * already called by PaymentController (:2436, :3546) and PaymentService (:2594). These six
     * handlers were simply never wired to it -- they wrote the literal `'Paid'`, with nothing
     * between the shopper's browser and that assignment having asked a gateway whether money
     * moved.
     *
     * WIRING IT CHANGES NO BEHAVIOUR TODAY, and that is deliberate. None of these payment types is
     * in PaymentVerificationService::ENFORCED_PAYMENT_TYPES, so resolveOrderPaymentStatus()
     * returns exactly the 'Paid' the literal did. What it buys is the two things the service was
     * built for: every unverified Paid write is now LOGGED, so the residual exposure is
     * measurable instead of invisible, and enforcing a gateway later becomes a one-line change in
     * that service rather than another edit to six controllers.
     *
     * The service's own docblock states the reason the posture is preserved rather than flipped:
     * recording every gateway order as Pending would stop merchant wallets being credited for 51
     * live payment methods -- a business change, not a security fix. Choosing that is the owner's,
     * and it is the blocker recorded for these six.
     */
    protected function gatewayReturnVerifier(): \App\Services\PaymentVerificationService
    {
        return $this->gatewayReturnVerifier ??= new \App\Services\PaymentVerificationService();
    }

    /**
     * The payment status to record for an order written by a gateway-return handler.
     *
     * @param  mixed  $expectedTotal  the order's own final_price, for the log line
     */
    protected function gatewayReturnPaymentStatus(
        ?string $paymentType,
        $expectedTotal,
        string $site,
        ?string $checkoutNonce = null
    ): string {
        return $this->gatewayReturnVerifier()->resolveOrderPaymentStatus(
            $paymentType,
            $this->gatewayReturnVerifier()->consumeVerification(),
            $expectedTotal,
            $site,
            $checkoutNonce
        );
    }

    /**
     * The checkout nonce the stored state carries, for binding a verification to its checkout.
     *
     * Same value claimGatewayReturn() keys its replay claim on. Null when the state has none,
     * and PaymentVerificationService then applies no binding -- see recordVerification().
     *
     * @param  array<string, mixed>  $state
     */
    protected function checkoutNonceOf(array $state): ?string
    {
        $nonce = $state[self::CHECKOUT_NONCE_KEY] ?? null;

        return (is_string($nonce) && $nonce !== '') ? $nonce : null;
    }

    /**
     * The amount this server says is payable, from the state it stored at initiation.
     *
     * WHY THIS EXISTS. AuthorizeNetPaymnetController::getOrderPaymentStatus is not a gateway
     * RETURN at all -- it is the charge. It takes the card number, expiry and CVV from the request
     * and builds an Authorize.Net `authCaptureTransaction`, and it set the captured amount from
     *
     *     $input  = $request->all();
     *     $data   = json_decode($input['data'], true);
     *     $amount = (int) $data['get_amount'];
     *
     * so the shopper chose how much their own card was charged. That is the one item the brief
     * names that is worse than an order-total defect: not what the ledger records, but what the
     * gateway captures.
     *
     * WHERE THE RIGHT NUMBER COMES FROM. `cartlist_final_price` in the stored state is the FULL
     * payable, not the basket subtotal: PaymentController::prepareFinalRequestData() writes it as
     * `$cartlist_final_price + $delivery_price + $tax_price`, with the delivery price from
     * processShipping() and the tax from OrderTaxResolver. So it is products plus server shipping
     * plus server tax, computed by the guarded path, which is exactly the figure a capture should
     * use.
     *
     * ROUNDED, NOT TRUNCATED. The old line was `(int) $data['get_amount']`, so a 10.99 basket
     * captured 10 -- the merchant lost the pence on every non-integer total. This returns a
     * 2-decimal value. That is a change in what is charged, in the merchant's favour and towards
     * the order's own recorded figure, and it is called out in the commit rather than slipped in.
     *
     * Returns null when the state carries no total, so the caller can refuse rather than charge a
     * guess.
     *
     * @param  array<string, mixed>  $state
     */
    protected function serverPayableAmount(array $state): ?float
    {
        $total = $state['cartlist_final_price'] ?? null;

        if ($total === null || $total === '' || ! is_numeric($total)) {
            return null;
        }

        $amount = round((float) $total, 2);

        // A capture of zero or less is not a capture. The initiation path already refuses an empty
        // or non-positive basket, so reaching here with one means the state is not usable.
        return $amount > 0 ? $amount : null;
    }

    /**
     * The SERVER-PRICED BASKET held inside the stored checkout state.
     *
     * WHY A HANDLER NEEDS THIS AND NOT THE ONE IT WAS POSTED. PaymentService::getNMIProductview()
     * does two things with the same array: it stores the priced cart in the session as part of
     * `request_data`, AND it prints it into the payment form as
     *
     *     <input type="hidden" name="response_data" value="{{ json_encode($response) }}">
     *
     * (resources/views/front_end/payment/nmi-payment.blade.php:98). The handler on the way back
     * then read the HIDDEN FIELD -- `json_decode($request->input('response_data'), true)` -- for
     * the product list, the line quantities, `total_final_price` and `total_sub_price`. So the
     * server computed the basket, handed it to the browser, and believed it back: the whole cart
     * and every total in it were the caller's to rewrite, on the SUCCESS path, with a valid
     * session. That is not the "no session cookie" defect storedCheckoutState() closes; the
     * stored state was present and correct the entire time and simply was not the thing being
     * read.
     *
     * The two arrays are the same array at initiation, so reading this one changes nothing for a
     * genuine shopper and removes the round trip for everyone else.
     *
     * NORMALISED TO NESTED ARRAYS. The session may hold stdClass (it is serialised through
     * json_decode(json_encode(...)) at several initiation sites), while the handlers index their
     * cart lines as arrays -- `$product['product_id']`. One json round trip makes the shape
     * predictable instead of leaving each caller to guess, and it is what getCartData() already
     * does at PaymentController.php:1528.
     *
     * Returns an empty array when the state carries no cartlist, so the caller can refuse rather
     * than price a guess.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function storedCheckoutCartList(array $state): array
    {
        $cartlist = $state['cartlist'] ?? null;

        if (empty($cartlist)) {
            return [];
        }

        $normalised = json_decode(json_encode($cartlist), true);

        return is_array($normalised) ? $normalised : [];
    }

    /**
     * Does this basket hold a line whose quantity is not a positive integer?
     *
     * WHY A GATEWAY RETURN NEEDS ITS OWN CHECK, when PaymentController::processOrder already
     * refuses such a basket at initiation: the cart is still mutable between the two. The shopper
     * is redirected to the gateway, and the cart rows are only deleted once the order is written
     * on the way back -- so a row added in between is read here for the first time. And two
     * writers that can add one are in vendored module code this project must not edit:
     *
     *     packages/workdo/BoostSales/.../BoostSalesController.php:59 -> :183
     *     packages/workdo/FrequentlyProduct/.../FrequentlyProductController.php:88 -> :209
     *
     * both `$qty = $request->qty` written straight to `carts.qty` with no validation, on routes
     * under plain `web`. See S30 in docs/security/P01-SECURITY-WORK-QUEUE.md.
     *
     * WHAT A NEGATIVE LINE DOES IF IT REACHES THE LOOP. Every one of these handlers decrements
     * with `$remain_stock = $Product->product_stock - $qtyy`, and subtracting a negative ADDS
     * stock -- measured on the API and web order writers at 20 -> 25 for a qty of -5 -- while the
     * same line reduces every total it is summed into. So it is an inventory defect and an amount
     * defect in one row.
     *
     * The caller must refuse the whole basket rather than skip the line: skipping prices an order
     * the shopper did not ask for, and this is the last point before any row is written.
     *
     * @param  iterable<mixed>|null  $products  the cart list's product_list, objects or arrays
     */
    protected function basketHasUnusableQuantity($products): bool
    {
        if (empty($products)) {
            return false;
        }

        foreach ($products as $line) {
            $qty = is_array($line)
                ? ($line['qty'] ?? null)
                : (is_object($line) ? ($line->qty ?? null) : null);

            // A null or absent qty is NOT treated as an offence: the handlers themselves read it
            // as `!empty($product->qty) ? $product->qty : 0` and a 0 there decrements nothing.
            // What must be refused is a value that is present and not a positive integer.
            if ($qty === null || $qty === '') {
                continue;
            }

            if (! is_numeric($qty) || (int) $qty < 1 || (float) $qty != (int) $qty) {
                return true;
            }

            /*
             * AND A CEILING. The guard originally had none, and that was a hole rather than a
             * simplification. MEASURED: a cart line of qty 2147483647 on an otherwise legitimate
             * PaiementPro return took a product seeded at 50 units to product_stock
             * -2147483597, and wrote the order. The quantity is not negative, so the test above
             * waves it through; the RESULT is as negative as stock gets.
             *
             * It needs no payment and no account. `carts.qty` is written straight from the request
             * with no validation by two vendored module routes under plain `web`
             * (packages/workdo/BoostSales/.../BoostSalesController.php:59 and
             * packages/workdo/FrequentlyProduct/.../FrequentlyProductController.php:88), and three
             * of these gateways verify nothing, so the stock moves whether or not money did. One
             * anonymous request corrupts any merchant's inventory.
             *
             * THIS IS A SANITY BOUND, NOT A BUSINESS RULE, and the distinction matters because the
             * guard REFUSES the return. The business rule is the merchant's own
             * `products.maximum_quantity`, which CartQuantityControl enforces where that module is
             * active (PaymentController::checkCartQuantityControl) -- and which is checked at
             * INITIATION only, never here. This bound exists solely so that a quantity which
             * cannot be a real order line, and is the signature of an unvalidated write rather
             * than of a shopper, cannot move stock. It sits deliberately far above any genuine
             * single line: a merchant whose one cart line legitimately exceeds a million units has
             * a wholesale problem this platform is not solving. If one ever does, RAISE the
             * constant -- do not remove the check.
             */
            if ((int) $qty > self::MAX_SANE_LINE_QUANTITY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this basket name a product, or a variant, that provably belongs somewhere else?
     *
     * Two questions, because closing one without the other closes nothing: the product must
     * belong to THIS store, and the variant must belong to THAT product. See the second half of
     * the loop for why the variant half is not a `store_id` predicate.
     *
     * WHY THIS IS A PRE-FLIGHT AND NOT A CHECK INSIDE THE STOCK LOOP. The loop is the wrong
     * place for two reasons that only became visible once the loop could refuse at all:
     *
     *   1. ON A CARD CHARGE THE MONEY IS ALREADY GONE BY THEN.
     *      NMIPayController::getNMIProductStatus posts the sale itself, so a refusal from inside
     *      its stock loop leaves a shopper charged, with no order row, reading "Product not
     *      found". The guard that was added one leg deeper created exactly the outcome this
     *      trait's own quantity guard is positioned to avoid -- and an adversarial review of
     *      that change said so. Refusing before the capture is the only placement that is
     *      honest about which failures cost money.
     *
     *   2. THE LOOP HAS TWO BRANCHES AND A PER-BRANCH CHECK COVERED ONE.
     *      A store-scoped `Product` lookup returns NULL for a foreign product, and the handler's
     *      variant branch resolves its row through
     *      `ProductVariant::where('id', $variant_id)->where('product_id', $product_id)` instead
     *      -- a predicate built entirely from the caller's own two values. `ProductVariant`
     *      carries no StoreScope and is NOT in config/tenancy.php's `scoped_models`, so for a
     *      line naming another merchant's product AND their matching variant, that merchant's
     *      `product_variants.stock` moved anyway. The product-level check here covers both
     *      branches at once, because the variant predicate requires the variant to belong to
     *      the product this method has already judged.
     *
     * A NULL `store_id` IS ACCEPTED, and only a PROVABLE mismatch is refused -- the same rule
     * and the same reasoning as 0ad3f7e0 applied to ShippingMethod. The column is nullable,
     * legacy rows may not carry it, and refusing those would hide a merchant's own product,
     * which is a broken storefront rather than a fix. A product id that resolves to NO row is
     * also left to the loop, which already has a "Product not found" path for it.
     *
     * `withoutGlobalScopes()` because `Product` adds no predicate for anybody anyway (that is
     * the defect) and because an assertion about another tenant's row must not be answered by
     * whichever tenant happens to be resolved -- the false-reading trap CLAUDE.md records.
     *
     * @param  iterable<mixed>|null  $products  the cart list's product_list, objects or arrays
     * @param  mixed  $store  the store this checkout belongs to
     */
    protected function basketHasForeignProduct($products, $store): bool
    {
        if (empty($products) || empty($store) || empty($store->id)) {
            return false;
        }

        foreach ($products as $line) {
            $productId = is_array($line)
                ? ($line['product_id'] ?? null)
                : (is_object($line) ? ($line->product_id ?? null) : null);

            if (empty($productId) || ! is_numeric($productId)) {
                continue;
            }

            $owner = \App\Models\Product::withoutGlobalScopes()
                ->whereKey($productId)
                ->value('store_id');

            // No row, or a legacy row with no store: not a provable mismatch.
            if ($owner === null) {
                continue;
            }

            if ((int) $owner !== (int) $store->id) {
                return true;
            }

            /*
             * AND THE VARIANT MUST BELONG TO THAT SAME PRODUCT. P01-COMMERCE-SEC-03.
             *
             * SCOPING THE PRODUCT DOES NOT SCOPE THE VARIANT, which is the defect this half
             * closes. Every stock loop on the platform resolves its variant as
             *
             *     ProductVariant::where('id', $variant_id)->where('product_id', $product_id)
             *
             * -- a predicate built entirely from the caller's own two values, consulting neither
             * the store nor the product the handler just resolved. `ProductVariant` carries no
             * StoreScope and is not in config/tenancy.php's `scoped_models`, so it adds no
             * predicate for anybody. A line pairing THIS store's product id with ANOTHER
             * product's variant id therefore reached a variant row this store does not own, and
             * the decrement landed on it.
             *
             * The check is `product_id`, not `store_id`: `product_variants.store_id` is NULLABLE
             * (create_product_variants_table.php:30), so a hard store predicate would drop
             * legitimate legacy variant rows. Requiring the variant to belong to a product this
             * store owns reaches the same conclusion without that cost -- the product half above
             * has already established ownership.
             *
             * A variant id that resolves to NO row is left alone, for the same reason a missing
             * product is: it is not a provable mismatch, and every one of these loops already has
             * a "Product not found" path for it.
             */
            $variantId = is_array($line)
                ? ($line['variant_id'] ?? null)
                : (is_object($line) ? ($line->variant_id ?? null) : null);

            if (empty($variantId) || ! is_numeric($variantId) || (int) $variantId === 0) {
                continue;
            }

            $variantProductId = \App\Models\ProductVariant::withoutGlobalScopes()
                ->whereKey($variantId)
                ->value('product_id');

            if ($variantProductId === null) {
                continue;
            }

            if ((int) $variantProductId !== (int) $productId) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a handler returns when the basket names another merchant's product.
     *
     * Its own text, separate from the quantity and state refusals, so a log line or a support
     * conversation can tell which of the three fired -- and so the regression tests can assert
     * it rather than inferring it from an absent order.
     */
    protected function gatewayReturnForeignProductRefusal(Request $request, string $gateway)
    {
        Log::warning('Gateway return refused: basket names a product from another store.', [
            'gateway' => $gateway,
            'path' => $request->path(),
            'ip' => $request->ip(),
            'authenticated_customer' => optional(auth('customers')->user())->id,
        ]);

        return redirect()->back()->with(
            'error',
            __('Your cart contains an item this store does not sell. Your payment has not been applied to an order — please contact the store.')
        );
    }

    /**
     * What a handler returns when a basket cannot be priced.
     *
     * Separate text from the state refusal so a log or a support conversation can tell the two
     * apart, and so the regression tests can assert which guard fired.
     */
    /**
     * THE STORE'S GATEWAY COULD NOT BE CONFIGURED -- a DIFFERENT refusal from every other one here,
     * and it needs its own message for a reason that a test caught rather than a reviewer.
     *
     * The first cut of the AuthorizeNet setup fix reused `gatewayReturnRefusal()`, which is the
     * NO-STORED-STATE refusal. `GatewayReturnTrustBoundaryTest::
     * test_the_guard_refuses_only_when_the_stored_state_is_absent` then failed on the authorizenet
     * data set: with state present the handler still produced that exact message, so a test whose
     * whole job is to prove the state guard does not over-refuse could no longer tell the two causes
     * apart. Two different failures must not share one flash string.
     *
     * It is also the honest message. "We could not confirm your checkout details" tells a shopper to
     * check their own basket; the truth is that the STORE has not finished configuring its payment
     * gateway, and only the merchant can act on that.
     */
    protected function gatewayReturnConfigurationRefusal(Request $request, string $gateway, ?string $reason = null)
    {
        Log::warning('Gateway return refused: the gateway request could not be built.', [
            'gateway' => $gateway,
            'path' => $request->path(),
            'authenticated_customer' => optional(auth('customers')->user())->id,
            // The MESSAGE only, never the trace: the block this comes from holds the card number,
            // the expiry and the CVV, and a trace would carry its arguments.
            'reason' => $reason,
        ]);

        return redirect()->back()->with(
            'error',
            __('This store\'s payment gateway is not configured. Your payment has not been applied to an order — please contact the store.')
        );
    }

    protected function gatewayReturnQuantityRefusal(Request $request, string $gateway)
    {
        Log::warning('Gateway return refused: basket holds a non-positive quantity.', [
            'gateway' => $gateway,
            'path' => $request->path(),
            'ip' => $request->ip(),
            'authenticated_customer' => optional(auth('customers')->user())->id,
        ]);

        return redirect()->back()->with(
            'error',
            __('Your cart contains an invalid quantity. Your payment has not been applied to an order — please contact the store.')
        );
    }

    /**
     * What a handler returns when there is no stored state.
     *
     * A redirect rather than `abort(403)`: the caller may be a shopper whose session expired on
     * a real payment, and a bare 403 page tells them nothing. The sibling GuardsPlanActivation
     * uses 403 because its caller is an authenticated merchant on an admin screen. Neither uses
     * 401 -- repo B's lib/network/network.dart turns any 401 into a forced logout.
     */
    protected function gatewayReturnRefusal()
    {
        return redirect()->back()->with(
            'error',
            __('We could not confirm your checkout details. Your payment has not been applied to an order — please contact the store before trying again.')
        );
    }
}
