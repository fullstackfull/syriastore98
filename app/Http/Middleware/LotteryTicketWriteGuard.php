<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-INV-20 / #67 item 1 -- `StoreLotteryPayment` writes a `purchase_tickets` row ANONYMOUSLY,
 * into whichever store the caller's own URL slug names, with the caller's own `amount`.
 *
 * WHAT THIS GUARD DOES AND DOES NOT DO. READ THIS BEFORE TREATING #67 ITEM 1 AS CLOSED -- an
 * earlier version of this docblock said it "refuses the attacker", and that was WRONG. It costs
 * the attacker one extra request. It does not close the defect.
 *
 *   IT CLOSES   the single-request, validator-free variant: the `$request->all()` fallback at
 *               :568-570, through which a caller supplies every field by hand, including fields
 *               the lottery form never collects, on any of the eight routes -- five of which are
 *               gateway callbacks with no form step of their own.
 *
 *   IT DOES NOT the same write in TWO anonymous requests. MEASURED, not argued:
 *   CLOSE
 *                 POST /{victim}/lottery-form-data   (no auth on that route either)
 *                      product_id=<the victim's lottery product>, payment_methods=telegram,
 *                      ticket_price=1, and the nine lottery_info[*] fields the validator wants
 *                 -> 200. processTelegram (LotteryPaymentService:1135-1140) is literally
 *                    `Session::put('lottery_request_data', $request); return view(...)`, so the
 *                    caller now holds the session key -- containing THEIR OWN ticket_price.
 *
 *                 GET /{victim}/store-lottery-payment     (no query string needed)
 *                 -> 200, purchase_tickets 0 -> 1, store_id = the victim's, customer_id = 0,
 *                    amount = 1.
 *
 *               Identical to the one-request outcome this guard was built to stop.
 *
 * WHY NO MIDDLEWARE CAN CLOSE IT. The attacker's two requests are byte-for-byte the shape of a
 * legitimate guest purchase, minus the payment -- and for `telegram` and `whatsapp` there IS no
 * payment step at all: the module creates the row and settles out of band by design. So the only
 * discriminator is whether money actually moved, which means the gateway's own signature or a
 * transaction lookup, inside the handler. Middleware cannot ask Paystack whether a payment
 * settled. Requiring `auth('customers')` would not help either: `lottery-form-data` has no auth,
 * guest purchase is a real flow (`:614` writes `customer_id = 0` for it), and refusing guests
 * would close the defect by breaking the feature.
 *
 * So #67 item 1 stays OPEN as a payment-verification finding, tracked with the forgeable amount in
 * #68. This guard is kept because the variant it does close is real -- the fallback bypasses the
 * controller's own validator entirely and is the only path to the five gateway-callback routes --
 * and because it replaces a 500 with a message for the customer it most often meets.
 *
 * THE ORIGINAL ONE-REQUEST DEFECT, for the record:
 *
 *   GET /victim-store/store-lottery-payment?ticket_price=1&payment_methods=telegram&product_id=N
 *   -> HTTP 200, purchase_tickets 0 -> 1, store_id = the victim's, customer_id = 0, amount = 1
 *
 * No bearer token, no session, no guard, no payment. The routes sit in a `['web']`-only group
 * (packages/workdo/LotteryPrizes/src/routes/web.php:41-52) -- the same shape as #57's six routes.
 *
 * WHAT IS REFUSED, precisely. LotteryPrizesController:568-570:
 *
 *     $requests_data = Session::get('lottery_request_data');
 *     if ($requests_data == null) {
 *         $requests_data = $request->all();     // <- the attacker's whole payload
 *     }
 *
 * With the session present, the fields come from a `lottery-form-data` submission that passed the
 * controller's validator. With it absent, they come from the query string.
 *
 * REQUIRING THE SESSION KEY BREAKS NO LEGITIMATE PAYMENT, and this is the half that had to be
 * checked by counting rather than assuming: `LotteryPaymentService` puts `lottery_request_data`
 * into the session on EVERY path before handing off to a gateway -- **34** call sites, counted with
 * `grep -c`. An earlier version of this docblock said 15, which was a partial count of the file and
 * is corrected here because a load-bearing number has to be right.
 *
 * AND THE SESSION-LESS PATH CANNOT SERVE A REAL GATEWAY CALLBACK ANYWAY. These routes are wired as
 * `return_url`, `cancel_url` and `callback_url` (LotteryPaymentService:335, :438-439, :528, :655,
 * :713, :779, :899). A cross-site POST callback loses the session cookie -- `config/session.php:202`
 * leaves `same_site` at `env(..., null)`, which browsers now treat as Lax -- and when it does, the
 * handler reads `$requests_data['ticket_price']` at :571 from the GATEWAY's POST body, which has no
 * such key: an undefined-key warning, promoted to `ErrorException`, so HTTP 500. The session-less
 * branch is already broken for the legitimate caller. Refusing it replaces a 500 with a message.
 *
 * ALSO NOT CLOSED, and recorded rather than implied by silence: `ticket_price` is a client-posted
 * hidden input (lottery-form.blade.php:277) that lands in `purchase_tickets.amount`, so the
 * RECORDED amount is forgeable even on the paid path -- the charge itself is server-side, taken
 * from `$lottery_setting->ticket_price`. #68.
 *
 * KEYED ON THE CONTROLLER ACTION, NOT THE URI. Eight routes reach this one method
 * (`store.lottery.payment`, five gateway callbacks, `ticket.customer.telegram`,
 * `ticket.customer.whatsapp`), two of them with an OPTIONAL `{storeSlug?}` segment. A uri-keyed
 * target list is how P1-INV-17's census missed live routes twice: once keyed on uri words, once on
 * a uri prefix. The action name is the thing that actually identifies the handler.
 */
class LotteryTicketWriteGuard
{
    /**
     * The one handler this guard covers, as `Controller@method`.
     */
    private const TARGET_ACTION =
        'Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController@StoreLotteryPayment';

    /**
     * The session key whose absence selects the attacker-controlled branch.
     */
    private const FLOW_KEY = 'lottery_request_data';

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route || ltrim((string) $route->getActionName(), '\\') !== ltrim(self::TARGET_ACTION, '\\')) {
            return $next($request);
        }

        // `has()` is not enough: the handler's test is `== null`, which is also true of '' and of
        // an empty array. The guard must ask the handler's question, or a value that the guard
        // accepts and the handler rejects sends the request down the fallback anyway.
        $flow = $request->hasSession() ? $request->session()->get(self::FLOW_KEY) : null;

        if ($flow == null) {
            return $this->sendBack($request);
        }

        return $next($request);
    }
    /**
     * A REFUSAL THE CUSTOMER CAN RECOVER FROM, not a 403.
     *
     * This is a storefront web route reached by a browser, and the people who will meet this
     * refusal most often are not attackers: they are customers whose session expired between the
     * gateway and the return URL. A 403 error page there loses the store they were shopping in.
     * The module's own failure path does the same thing -- `redirect()->route('landing_page', $slug)`
     * at LotteryPaymentService:206 -- so this follows the convention already in the flow.
     *
     * `#64`'s guard learned this the hard way in the other direction: it 403'd an empty selection
     * and cost a merchant their screen for a mis-click, and the test that should have caught it
     * drove `postJson` for every payload so the web path was never exercised.
     */
    private function sendBack(Request $request): Response
    {
        $message = __('Your ticket purchase could not be completed. Please start again from the lottery page.');

        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => $message], 403);
        }

        $slug = $request->route('storeSlug');

        if (is_string($slug) && $slug !== '') {
            return redirect()->route('landing_page', $slug)->with('error', $message);
        }

        return redirect()->to('/')->with('error', $message);
    }
}
