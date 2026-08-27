<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StoreNotificationService
{
    public function __construct(
        private readonly FirebaseNotificationService $firebase
    ) {
    }

    /**
     * حفظ وإرسال إشعار طلب جديد.
     */
    public function orderCreated(Order $order): ?StoreNotification
    {
        try {
            $notification = StoreNotification::create([
                'store_id' => $order->store_id,
                'user_id' => $this->getStoreOwnerId($order->store_id),

                'type' => 'order_created',
     'title' => __('طلب جديد'),

'message' => __(
    'تم استلام طلب جديد رقم #:order_id.',

                    [
                        'order_id' => $order->product_order_id,
                    ]
                ),

                'reference_type' => 'order',
                'reference_id' => $order->id,

                'data' => [
                    'order_id' => $order->id,
                    'product_order_id' => $order->product_order_id,
                    'store_id' => $order->store_id,
                    'final_price' => $order->final_price,
                    'payment_type' => $order->payment_type,
                    'payment_status' => $order->payment_status,
                    'delivered_status' => $order->delivered_status,
                    'order_date' => $order->order_date,
                ],

                'is_read' => false,
                'push_sent' => false,
            ]);

            $this->sendPushNotification($notification);

            return $notification->fresh();
        } catch (Throwable $exception) {
            Log::error('Failed to save order created notification.', [
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * حفظ وإرسال إشعار دفع الطلب.
     */
    public function orderPaid(Order $order): ?StoreNotification
    {
        try {
            $existingNotification = StoreNotification::where(
                'store_id',
                $order->store_id
            )
                ->where('type', 'order_paid')
                ->where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->first();

            if ($existingNotification) {
                /*
                 * إذا كان الإشعار محفوظًا لكن لم يُرسل Push،
                 * نحاول إرساله مجددًا.
                 */
                if (!$existingNotification->push_sent) {
                    $this->sendPushNotification($existingNotification);
                }

                return $existingNotification->fresh();
            }

            $notification = StoreNotification::create([
                'store_id' => $order->store_id,
                'user_id' => $this->getStoreOwnerId($order->store_id),

                'type' => 'order_paid',
                'title' => __('Order paid'),
                'message' => __(
                    'Order #:order_id has been paid.',
                    [
                        'order_id' => $order->product_order_id,
                    ]
                ),

                'reference_type' => 'order',
                'reference_id' => $order->id,

                'data' => [
                    'order_id' => $order->id,
                    'product_order_id' => $order->product_order_id,
                    'store_id' => $order->store_id,
                    'final_price' => $order->final_price,
                    'payment_type' => $order->payment_type,
                    'payment_status' => $order->payment_status,
                ],

                'is_read' => false,
                'push_sent' => false,
            ]);

            $this->sendPushNotification($notification);

            return $notification->fresh();
        } catch (Throwable $exception) {
            Log::error('Failed to save order paid notification.', [
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * إرسال الإشعار إلى جميع أجهزة المتجر النشطة.
     */
    private function sendPushNotification(
        StoreNotification $notification
    ): void {
        $devices = DB::table('store_devices')
            ->where('store_id', $notification->store_id)
            ->where('is_active', 1)
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->where('fcm_token', '!=', 'YOUR_FIREBASE_TOKEN')
            ->get();

        if ($devices->isEmpty()) {
            Log::warning('No active Firebase devices found for store.', [
                'notification_id' => $notification->id,
                'store_id' => $notification->store_id,
            ]);

            return;
        }

        $sentCount = 0;

        foreach ($devices as $device) {
            try {
                $this->firebase->sendToToken(
                    $device->fcm_token,
                    $notification->title,
                    $notification->message,
                    $this->buildFirebaseData($notification)
                );

                $sentCount++;

                Log::info('Firebase notification sent successfully.', [
                    'notification_id' => $notification->id,
                    'store_id' => $notification->store_id,
                    'device_id' => $device->id,
                ]);
            } catch (Throwable $exception) {
                Log::error('Firebase notification sending failed.', [
                    'notification_id' => $notification->id,
                    'store_id' => $notification->store_id,
                    'device_id' => $device->id,
                    'message' => $exception->getMessage(),
                ]);

                /*
                 * عطّل التوكن إذا أصبح غير صالح.
                 */
                if ($this->isInvalidTokenError($exception->getMessage())) {
                    DB::table('store_devices')
                        ->where('id', $device->id)
                        ->update([
                            'is_active' => 0,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        /*
         * نعتبر push_sent ناجحًا إذا وصل لجهاز واحد على الأقل.
         */
        if ($sentCount > 0) {
            $notification->update([
                'push_sent' => true,
            ]);
        }
    }

    /**
     * تجهيز بيانات Firebase.
     *
     * جميع قيم FCM data يجب أن تكون Strings.
     */
    private function buildFirebaseData(
        StoreNotification $notification
    ): array {
        $notificationData = is_array($notification->data)
            ? $notification->data
            : [];

        return [
            'notification_id' => (string) $notification->id,
            'type' => (string) $notification->type,
            'store_id' => (string) $notification->store_id,
            'reference_type' => (string) $notification->reference_type,
            'reference_id' => (string) $notification->reference_id,
            'screen' => 'order_details',
            'data' => json_encode(
                $notificationData,
                JSON_UNESCAPED_UNICODE
            ),
        ];
    }

    /**
     * التحقق من أخطاء التوكن المنتهي أو غير الصالح.
     */
    private function isInvalidTokenError(string $message): bool
    {
        return str_contains($message, 'UNREGISTERED')
            || str_contains($message, 'registration-token-not-registered')
            || str_contains($message, 'Requested entity was not found')
            || str_contains($message, 'Invalid registration token');
    }

    /**
     * رقم مالك المتجر.
     */
    private function getStoreOwnerId(int $storeId): ?int
    {
        return Store::where('id', $storeId)
            ->value('created_by');
    }
}