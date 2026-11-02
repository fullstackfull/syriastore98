<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponser;
use App\Models\DeliveryBoy;
use App\Models\DeliveryBoyDevice;
use App\Models\DeliveryBoyNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeliveryBoyNotificationController extends Controller
{
    use ApiResponser;

public function registerDevice(Request $request)
{
    $user = auth('deliveryboy')->user();
    
    if (!$user) {
        return $this->error(['message' => __('Unauthorized')], 401);
    }

    $validator = Validator::make($request->all(), [
        'fcm_token' => 'required|string',
        'device_id' => 'nullable|string',
        'platform'  => 'nullable|string|in:android,ios',
    ]);

    if ($validator->fails()) {
        return $this->error(['message' => $validator->errors()->first()]);
    }

    DeliveryBoyDevice::updateOrCreate(
        [
            'delivery_boy_id' => $user->id,
            'device_id'       => $request->device_id,
        ],
        [
            'fcm_token'  => $request->fcm_token,
            'platform'   => $request->platform,
            'is_active'  => true,
            'updated_at' => now(),
        ]
    );

    return $this->success(['message' => __('Device registered successfully for notifications.')]);
}
    public function getNotifications(Request $request)
    {
    $user = auth('deliveryboy')->user();
    
    if (!$user) {
        return $this->error(['message' => __('Unauthorized')], 401);
    }

        $unreadCount = DeliveryBoyNotification::where('delivery_boy_id', $user->id)
            ->where('is_read', false)
            ->count();

        $notifications = DeliveryBoyNotification::where('delivery_boy_id', $user->id)
            ->with('order:id,product_order_id,final_price,delivered_status')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return $this->success([
            'unread_count'  => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    public function markAsRead(Request $request)
    {
        $user = auth('deliveryboy')->user();

        if (!$user) {
            return $this->error(['message' => __('Unauthorized')], 401);
        }

        /*
         * P1-SEC-47 -- THE `exists:` RULE IS SCOPED TO THE AUTHENTICATED DRIVER.
         *
         * It was `exists:delivery_boy_notifications,id`, which looks at the WHOLE table. The
         * lookup below is correctly derived from the authenticated principal
         * (`->where('delivery_boy_id', $user->id)`), but the validator runs FIRST and
         * `delivery_boy_notifications` has no `store_id` column and is not in
         * `config/tenancy.php`'s `scoped_models`, so it saw every tenant's rows. The result was
         * two distinguishable refusals:
         *
         *     a foreign but EXISTING id -> "Notification not found."
         *     a NONEXISTENT id          -> "The selected notification id is invalid."
         *
         * which is a platform-wide existence oracle over sequential ids -- the same defect class
         * as P1-SEC-10's 404-vs-403 on the DeliverySyria webhook, on a different table. It leaks
         * row existence, and so notification volume and activity, not content.
         *
         * Unreachable until GATE F: before
         * `2026_11_02_001200_create_delivery_boy_notifications_table.php` the `exists:` rule
         * raised a QueryException and this endpoint answered 500 to every caller.
         *
         * Scoping the rule collapses both cases to one message. The `->first()` below already
         * answered correctly on its own, so this changes no successful call.
         */
        $validator = Validator::make($request->all(), [
            'notification_id' => [
                'required',
                'integer',
                \Illuminate\Validation\Rule::exists('delivery_boy_notifications', 'id')
                    ->where('delivery_boy_id', $user->id),
            ],
        ]);

        if ($validator->fails()) {
            return $this->error(['message' => $validator->errors()->first()]);
        }

        $notification = DeliveryBoyNotification::where('id', $request->notification_id)
            ->where('delivery_boy_id', $user->id)
            ->first();

        if ($notification) {
            $notification->update(['is_read' => true]);
            return $this->success(['message' => __('Notification marked as read successfully.')]);
        }

        return $this->error(['message' => __('Notification not found.')]);
    }

    public function markAllAsRead(Request $request)
    {
        $user = auth('deliveryboy')->user();

        if (!$user) {
            return $this->error(['message' => __('Unauthorized')], 401);
        }

        DeliveryBoyNotification::where('delivery_boy_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return $this->success(['message' => __('All notifications marked as read successfully.')]);
    }
}