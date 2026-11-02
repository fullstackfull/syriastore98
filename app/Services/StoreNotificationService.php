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
    /**
     * D-22 — A CAPTURE THAT PRODUCED NO ORDER, recorded where the merchant can see it.
     *
     * THE HALF OF D-22 THAT WAS OPEN. The pre-collection half closed on 2026-10-28: a basket
     * naming a deleted `products` or `product_variants` row now gets a structured refusal at the
     * gateway return instead of `ErrorException: Attempt to read property "id" on null`. What
     * stayed open was *"after proof, do not lose the paid order"* — a customer whose product was
     * deleted mid-checkout still gets no order, and all the merchant had was a `critical` line in
     * a log file nobody reads.
     *
     * The register costed three options. **(A)** write the order from the surviving `carts` facts
     * — the only one that satisfies the instruction literally, and the one that changes what an
     * order line contains, a shape the merchant UI, the invoice modules and the **Flutter** app
     * all read. **(B)** make the capture a first-class record the merchant can settle from.
     * **(C)** prevent the cause by refusing to delete a product that sits in a live basket.
     *
     * **(B) IS WHAT THIS IS**, and (A) stays BLOCKED: the owner's standing rule is that a
     * financial path whose safety cannot be proved stays blocked, and (A)'s safety is a claim
     * about a second repository's rendering of a line with no product.
     *
     * (B) COSTS NO SCHEMA AND NO CONTRACT. `store_notifications` already carries every column
     * this needs, it is already read by the merchant web bell
     * (`MerchantNotificationController`) and by the shipped app (`GET api/admin/notification`),
     * and the app tolerates an unknown `type` — READ FROM ITS SOURCE, not assumed:
     * `notification_screen.dart`'s `_getTypeColor` and `_getNotificationIcon` both end in a
     * `default:` branch, and its order footer and tap-to-navigate are both gated on
     * `referenceType == 'order'`. So `reference_type` here is `'payment'`, and the app renders
     * the title and message with its default bell and does nothing on a tap.
     *
     * WHAT IT DOES NOT DO, so nobody reads more into it than is there: it creates no order, moves
     * no stock, and changes no money. It records that a capture happened and produced nothing, so
     * a merchant can settle or refund it deliberately rather than discovering it in a support
     * ticket.
     *
     * @param string|null $captureState 'confirmed' when a verifier said so, 'unknown' when the
     *                                  gateway has no verifier at all (Easebuzz, PaiementPro and
     *                                  CinetPay have none, so every return there is unproven by
     *                                  construction). A REJECTED capture must not reach this: no
     *                                  money was taken and there is nothing to settle.
     */
    public function captureWithoutOrder(
        int $storeId,
        string $gateway,
        ?string $amount = null,
        ?string $captureState = null,
        ?string $cause = null
    ): ?StoreNotification {
        try {
            /*
             * A SHOPPER WHO REFRESHES THE RETURN PAGE MUST NOT SPAM THE BELL. The gateway return
             * is a GET on three of the four handlers, so a reload re-runs the refusal.
             *
             * THE AMOUNT IS PART OF THE KEY ONLY WHEN THE CAPTURE IS CONFIRMED, and that
             * asymmetry is the whole point of this block. The independent review traced a real
             * path by which a shopper can reach the `unknown` state at will: `getValidPaymentTypes`
             * is a static list of ~50 gateway names rather than the store's enabled set, so
             * `payment_type=CinetPay` passes on any store; `PaymentService::processCinetPay` puts
             * the checkout state in the session BEFORE its cURL call and regardless of whether the
             * store has credentials; the return route is `Route::any`, so it is reachable by GET
             * with no gateway round trip; and a dangling `variant_id` can be planted afterwards
             * through `FrequentlyProductController::product_cartlist`, which writes
             * `$request->variant_id` verbatim. Varying the basket total would then defeat an
             * amount-keyed dedupe and multiply both rows and FCM pushes.
             *
             * For `unknown` the amount is not what the merchant acts on — the message says "check
             * the gateway" and that is one action however many totals were tried — so the key is
             * the store and the gateway, and a flood collapses to one row. For `confirmed` the
             * amount IS the action (settle or refund THIS capture), and `confirmed` cannot be
             * reached without a verifier positively answering VERIFIED, so it cannot be flooded.
             *
             * Two genuinely separate CONFIRMED captures of the same amount minutes apart still
             * collapse into one row. That is a real loss of information, accepted deliberately,
             * because the alternative is a bell the merchant stops reading — and the `critical`
             * log keeps every occurrence either way.
             */
            $existing = StoreNotification::where('store_id', $storeId)
                ->where('type', 'payment_needs_attention')
                ->where('reference_type', 'payment')
                ->where('created_at', '>=', now()->subMinutes(10))
                ->get()
                ->first(function (StoreNotification $row) use ($gateway, $amount, $captureState): bool {
                    $data = $row->data;
                    $data = is_array($data) ? $data : (array) json_decode((string) $data, true);

                    if (($data['gateway'] ?? null) !== $gateway) {
                        return false;
                    }

                    if ($captureState !== 'confirmed') {
                        return ($data['capture_state'] ?? null) !== 'confirmed';
                    }

                    return ($data['capture_state'] ?? null) === 'confirmed'
                        && (string) ($data['amount'] ?? '') === (string) $amount;
                });

            if ($existing) {
                return $existing;
            }

            $notification = StoreNotification::create([
                'store_id' => $storeId,
                'user_id' => $this->getStoreOwnerId($storeId),

                'type' => 'payment_needs_attention',
                'title' => __('A payment needs your attention'),
                'message' => $captureState === 'confirmed'
                    ? __(
                        'A :gateway payment of :amount was confirmed but no order could be created, because an item in the basket no longer exists. Settle or refund it directly with the customer.',
                        ['gateway' => $gateway, 'amount' => (string) ($amount ?? '—')]
                    )
                    : __(
                        'A :gateway checkout for :amount may have been charged but no order could be created, because an item in the basket no longer exists. Check the gateway before asking the customer to pay again.',
                        ['gateway' => $gateway, 'amount' => (string) ($amount ?? '—')]
                    ),

                /*
                 * NOT `'order'`. There is no order — that is the whole point — and the shipped
                 * app gates its order footer and its tap-to-open on exactly this value.
                 */
                'reference_type' => 'payment',
                'reference_id' => null,

                'data' => [
                    'gateway' => $gateway,
                    'amount' => $amount,
                    'capture_state' => $captureState,
                    'cause' => $cause,
                    'store_id' => $storeId,
                ],

                'is_read' => false,
                'push_sent' => false,
            ]);

            $this->sendPushNotification($notification);

            return $notification->fresh();
        } catch (Throwable $exception) {
            /*
             * THIS IS ALREADY THE ERROR PATH. The caller is refusing a gateway return; a failure
             * to record the refusal must not turn that refusal into a 500, which is the defect
             * D-22 exists to remove.
             */
            Log::error('Failed to record a capture that produced no order.', [
                'store_id' => $storeId,
                'gateway' => $gateway,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

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