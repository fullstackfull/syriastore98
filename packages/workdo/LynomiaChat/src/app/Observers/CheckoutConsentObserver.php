<?php

namespace Workdo\LynomiaChat\app\Observers;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Services\CheckoutConsentCapture;

/**
 * Where a checkout opt-in is captured: when the order it was ticked on is created.
 *
 * ON THE MODEL, FOR THE SAME REASON THE COMMERCE OUTBOX IS. An order is written by
 * PaymentService, by PaymentController, by the admin form, by the two API controllers and by
 * roughly fifty gateway controllers. `App\Events\AddAdditionalFields` carries both the order
 * and the request and looks like the natural hook, but it is dispatched from about twenty of
 * those sites and -- in PaymentService -- fires BEFORE the transaction that saves the order
 * opens, so a consent row written from it would survive an order that rolled back. The
 * model's `created` event fires once, inside whatever transaction the writer opened, for
 * every writer including ones added later.
 *
 * READING THE REQUEST FROM AN OBSERVER IS UNUSUAL AND IS THE POINT. The tick is a property of
 * the HTTP submission, not of the order row, and there is nowhere on the order to put it that
 * would not mean adding a column to the platform's own orders table for one module's benefit.
 * In a console run or a queued job there is no checkout form, `boolean()` on the empty
 * request is false, and nothing is captured -- which is the right answer, not a gap.
 *
 * IT CANNOT FAIL A CHECKOUT. A consent row is worth less than the order it rode in on: if
 * writing it raises, the shopper has still bought something and must still get their
 * confirmation. The failure is logged with the order id and nothing else.
 */
class CheckoutConsentObserver
{
    public function __construct(private readonly CheckoutConsentCapture $capture)
    {
    }

    public function created(Order $order): void
    {
        try {
            $this->capture->capture($order, request());
        } catch (\Throwable $e) {
            Log::warning('[LynomiaChat] checkout opt-in not recorded for order ' . $order->id . ': ' . $e::class);
        }
    }
}
