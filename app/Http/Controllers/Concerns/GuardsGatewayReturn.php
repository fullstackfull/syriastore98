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
