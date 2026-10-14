<?php

namespace Workdo\LynomiaChat\app\Observers;

use App\Models\Order;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Models\LynomiaCommerceEvent;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * Where commerce events are recorded: the one place every order change passes through.
 *
 * WHY THE MODEL AND NOT App\Events\OrderPaymentStatus. That event is dispatched from six sites
 * in Order::order_status_change, the admin API, the courier API, PartialPayments, PreOrder and
 * AuctionProduct -- and it is NOT dispatched when an order is CREATED, or when any of the ~52
 * gateway controllers writes payment_status directly. The retired Chatwoot integration listened
 * to it and therefore never saw a new order; its settings UI offered an `order_created`
 * notification that no code path could ever fire. Attaching here instead means every writer is
 * covered, including ones written after this, without editing fifty call sites.
 *
 * THE ROW IS WRITTEN IN THE CALLER'S TRANSACTION, which is the whole point of an outbox. A
 * queued job dispatched from here would fire for an order whose transaction then rolled back;
 * one dispatched after commit would be lost by a crash in between. A row commits exactly when
 * the order change does, and nothing else can be true of it.
 *
 * ONE ROW PER SAVE, CARRYING THE CURRENT STATUS PAIR. A save that moves both the delivery and
 * the payment status records the delivery change -- it is the more significant of the two --
 * and both current values travel on the row, so a single row is not a lossy account of what
 * happened. Lynomia re-reads the order from the store anyway; the event says that something
 * changed, never what the order now is.
 */
class CommerceEventObserver
{
    /** Stores whose link state has already been resolved in this process. */
    private array $eligible = [];

    public function created(Order $order): void
    {
        $this->record($order, 'order.created');
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged('delivered_status')) {
            $this->record($order, 'order.status_changed');

            return;
        }

        if ($order->wasChanged('payment_status')) {
            $this->record($order, 'order.payment_changed');
        }
    }

    private function record(Order $order, string $event): void
    {
        // The engineering kill switch. While the integration is off, nothing is recorded and
        // nothing accumulates -- an outbox that fills up while switched off is a queue of
        // deliveries nobody asked for, aimed at a workspace that may not exist any more.
        if (! config('lynomia.enabled') || ! config('lynomia.commerce_events_enabled')) {
            return;
        }

        $storeId = (int) $order->store_id;

        if ($storeId <= 0 || ! $this->isConnected($storeId)) {
            return;
        }

        LynomiaCommerceEvent::create([
            'store_id' => $storeId,
            'event' => $event,
            'order_id' => (int) $order->id,
            // The same reference the commerce API issues, so Lynomia can match it against the
            // customer link it already holds without a second vocabulary.
            'customer_reference' => $order->is_guest ? 'guest:' . $order->id : (string) $order->customer_id,
            'delivered_status' => (int) $order->delivered_status,
            'payment_status' => $order->payment_status === null ? null : Str::limit((string) $order->payment_status, 32, ''),
            'occurred_at' => now(),
            'delivery_id' => (string) Str::uuid(),
        ]);
    }

    /**
     * Only a store with a live Lynomia workspace records anything. Memoised per process because
     * this runs on every order write, and a store's link state does not change inside one
     * request; a worker process picks up a change on its next job.
     */
    private function isConnected(int $storeId): bool
    {
        return $this->eligible[$storeId] ??= LynomiaStoreLink::forStore($storeId)
            ->where('state', LinkState::ACTIVE)
            ->whereNotNull('lynomia_account_id')
            ->exists();
    }
}
