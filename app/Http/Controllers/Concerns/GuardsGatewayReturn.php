<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
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
    protected function gatewayReturnPaymentStatus(?string $paymentType, $expectedTotal, string $site): string
    {
        return $this->gatewayReturnVerifier()->resolveOrderPaymentStatus(
            $paymentType,
            $this->gatewayReturnVerifier()->consumeVerification(),
            $expectedTotal,
            $site
        );
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
     * What a handler returns when a basket cannot be priced.
     *
     * Separate text from the state refusal so a log or a support conversation can tell the two
     * apart, and so the regression tests can assert which guard fired.
     */
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
