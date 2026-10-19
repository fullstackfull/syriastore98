<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * THE EVENT CINETPAY'S RETURN HANDLER HAS ALWAYS FIRED, AND WHICH DID NOT EXIST.
 * =============================================================================================
 *
 * THE DEFECT, P01-COMMERCE-SEC-02. `CinetPayController::getProductStatus()` ends with
 *
 *     event(new CinetPayPayment($OrderBillingDetail, $order, $owner));
 *
 * and the class was nowhere in the repository -- one grep hit in the whole tree, the `new`
 * itself. Unqualified inside `App\Http\Controllers`, it resolved to
 * `App\Http\Controllers\CinetPayPayment` and raised an uncaught Error.
 *
 * WHAT THAT COST. Already committed by the time it threw: the order row, the stock decrements and
 * the cart deletes. Never reached: the cookie-cart clear, the reward-point event and the
 * `order.complete` redirect. So EVERY LEGITIMATE CINETPAY RETURN answered 500 to a shopper whose
 * card had just been charged and whose order did in fact exist. Measured in the previous
 * package's own probe: `http=500` with `orders=1`.
 *
 * WHY THE FIX IS THE MISSING CLASS AND NOT A `class_exists()` GUARD. Every sibling gateway fires
 * exactly this shape -- `App\Events\PaiementProPayment`, `App\Events\AuthorizeNetPayment`, both
 * present, both with the same three constructor arguments and the same body. CinetPay's was
 * simply never added. Restoring the member of that family is the narrowest change that matches
 * the platform's own intent, and it leaves a real extension point where the other gateways have
 * one; suppressing the dispatch would remove the extension point and keep the asymmetry.
 *
 * NOTHING LISTENS TO IT TODAY. Searched for a listener across `app/` and all 70 modules under
 * `packages/workdo/`: `PaiementProPayment` and `AuthorizeNetPayment` have none either. So this
 * adds no behaviour beyond ending the crash -- which is the point.
 */
class CinetPayPayment
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $OrderBillingDetail;

    public $order;

    public $owner;

    public function __construct($OrderBillingDetail, $order, $owner)
    {
        $this->OrderBillingDetail = $OrderBillingDetail;
        $this->order = $order;
        $this->owner = $owner;
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel-name'),
        ];
    }
}
