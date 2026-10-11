<?php

namespace App\Listeners;

use App\Events\OrderPaymentStatus;
use App\Services\ChatwootOrderNotificationService;

class SendChatwootOrderStatusNotification
{
    protected ChatwootOrderNotificationService $notificationService;

    public function __construct(
        ChatwootOrderNotificationService $notificationService
    ) {
        $this->notificationService = $notificationService;
    }

    public function handle(OrderPaymentStatus $event): void
    {
        $order = $event->order;

        if (!$order) {
            return;
        }

        $eventType = $this->resolveEventType($order);

        if (!$eventType) {
            return;
        }

        try {

            $this->notificationService->send(
                $order,
                $eventType
            );

        } catch (\Throwable $e) {

            \Log::error('Chatwoot order notification failed', [
                'order_id' => $order->id ?? null,
                'store_id' => $order->store_id ?? null,
                'event_type' => $eventType,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveEventType($order): ?string
    {
        switch ((int) $order->delivered_status) {

            case 1:
                return 'order_delivered';

            case 2:
                return 'order_cancelled';

            case 3:

                if ((int) $order->return_status === 1) {
                    return 'return_requested';
                }

                return 'order_status_changed';

            case 4:
                return 'order_confirmed';

            case 5:
                return 'order_picked_up';

            case 6:
                return 'order_shipped';

            case 0:
                return 'order_status_changed';

            default:
                return null;
        }
    }
}