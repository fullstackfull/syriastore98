<?php

namespace App\Services;

use App\Models\Order;
use App\Models\DeliveryBoy;
use App\Models\DeliveryBoyNotification;
use App\Models\DeliveryBoyDevice;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliveryBoyNotificationService
{
    public function __construct(
        private readonly DeliveryFirebaseNotificationService $deliveryFirebase
    ) {
    }

    public function orderAssigned(Order $order, DeliveryBoy $deliveryBoy): ?DeliveryBoyNotification
    {
        try {
            $notification = DeliveryBoyNotification::create([
                'delivery_boy_id' => $deliveryBoy->id,
                'order_id'        => $order->id,
                'type'            => 'order_assigned',
                'title'           => __('طلب جديد'),
                'message'         => __('تم تعيين طلبية جديدة لك برقم #:order_id', [
                    'order_id' => $order->product_order_id,
                ]),
                'data'            => [
                    'order_id'         => $order->id,
                    'product_order_id' => $order->product_order_id,
                    'final_price'      => (string) $order->final_price,
                    'payment_type'     => $order->payment_type,
                ],
                'is_read'   => false,
                'push_sent' => false,
            ]);

            $this->sendPushNotification($notification);

            return $notification->fresh();

        } catch (Throwable $exception) {
            Log::error('Failed to save/send delivery boy notification.', [
                'order_id'        => $order->id,
                'delivery_boy_id' => $deliveryBoy->id,
                'message'         => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function sendPushNotification(DeliveryBoyNotification $notification): void
    {
        $devices = DeliveryBoyDevice::where('delivery_boy_id', $notification->delivery_boy_id)
            ->where('is_active', 1)
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->where('fcm_token', '!=', 'YOUR_FIREBASE_TOKEN')
            ->get();

        if ($devices->isEmpty()) {
            Log::warning('No active Delivery Firebase devices found for delivery boy.', [
                'notification_id'   => $notification->id,
                'delivery_boy_id'   => $notification->delivery_boy_id,
            ]);
            return;
        }

        $sentCount = 0;

        foreach ($devices as $device) {
            try {
                $this->deliveryFirebase->sendToToken(
                    $device->fcm_token,
                    $notification->title,
                    $notification->message,
                    $this->buildFirebaseData($notification)
                );

                $sentCount++;

                Log::info('Delivery Firebase notification sent successfully.', [
                    'notification_id' => $notification->id,
                    'delivery_boy_id' => $notification->delivery_boy_id,
                    'device_id'       => $device->id,
                ]);

            } catch (Throwable $exception) {
                Log::error('Delivery Firebase notification sending failed.', [
                    'notification_id' => $notification->id,
                    'delivery_boy_id' => $notification->delivery_boy_id,
                    'device_id'       => $device->id,
                    'message'         => $exception->getMessage(),
                ]);

                if ($this->isInvalidTokenError($exception->getMessage())) {
                    $device->update([
                        'is_active' => 0,
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if ($sentCount > 0) {
            $notification->update([
                'push_sent' => true,
            ]);
        }
    }
    private function buildFirebaseData(DeliveryBoyNotification $notification): array
    {
        $notificationData = is_array($notification->data) ? $notification->data : [];

        return [
            'notification_id'  => (string) $notification->id,
            'type'             => (string) $notification->type,
            'delivery_boy_id'  => (string) $notification->delivery_boy_id,
            'reference_type'   => 'order',
            'reference_id'     => (string) $notification->order_id,
            'screen'           => 'order_details',
            'data'             => json_encode($notificationData, JSON_UNESCAPED_UNICODE),
        ];
    }

    private function isInvalidTokenError(string $message): bool
    {
        return str_contains($message, 'UNREGISTERED')
            || str_contains($message, 'registration-token-not-registered')
            || str_contains($message, 'Requested entity was not found')
            || str_contains($message, 'Invalid registration token');
    }
}