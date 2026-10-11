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

        $validator = Validator::make($request->all(), [
            'notification_id' => 'required|integer|exists:delivery_boy_notifications,id',
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