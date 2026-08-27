<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DeliverySyriaOrderController extends Controller
{
    public function updateStatus(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | 1. التحقق من اسم المنصة
        |--------------------------------------------------------------------------
        */
        $platform = trim((string) $request->header('X-Platform'));

        if ($platform !== 'deliverysyria') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid platform.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. استخراج Bearer Token
        |--------------------------------------------------------------------------
        */
        $secret = trim((string) $request->bearerToken());

        if ($secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Authorization token is required.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. التحقق من البيانات
        |--------------------------------------------------------------------------
        |
        | الحالات:
        | 0 = Pending
        | 1 = Delivered
        | 2 = Cancel
        | 3 = Return
        | 4 = Confirmed
        | 5 = Picked Up
        | 6 = Shipped
        |
        */
        $validator = Validator::make($request->all(), [
            'product_order_id' => [
                'required',
                'string',
                'max:191',
            ],
            'status' => [
                'required',
                'integer',
                'in:0,1,2,3,4,5,6',
            ],
            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $productOrderId = trim(
            (string) $request->input('product_order_id')
        );

        $newStatus = (int) $request->input('status');

        /*
        |--------------------------------------------------------------------------
        | 4. البحث عن الطلب
        |--------------------------------------------------------------------------
        */
        $order = Order::query()
            ->where('product_order_id', $productOrderId)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. جلب المتجر الخاص بالطلب
        |--------------------------------------------------------------------------
        */
        $store = Store::query()->find($order->store_id);

        if (!$store) {
            Log::error('Delivery Syria status update: store not found', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $order->store_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Store not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | 6. جلب صاحب المتجر
        |--------------------------------------------------------------------------
        */
        $owner = User::query()->find($store->created_by);

        if (!$owner) {
            Log::error('Delivery Syria status update: owner not found', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $store->id,
                'created_by' => $store->created_by,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | 7. مقارنة التوكن مع deliverysyria_secret
        |--------------------------------------------------------------------------
        */
        $storedSecret = trim(
            (string) ($owner->deliverysyria_secret ?? '')
        );

        if (
            $storedSecret === '' ||
            !hash_equals($storedSecret, $secret)
        ) {
            Log::warning('Delivery Syria invalid credentials', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $store->id,
                'owner_id' => $owner->id,
                'platform' => $platform,
                'provided_token_length' => strlen($secret),
                'stored_token_exists' => $storedSecret !== '',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 403);
        }

        $oldStatus = (int) $order->delivered_status;

        /*
        |--------------------------------------------------------------------------
        | 8. إذا كانت الحالة نفسها لا داعي للتعديل
        |--------------------------------------------------------------------------
        */
        if ($oldStatus === $newStatus) {
            return response()->json([
                'success' => true,
                'message' => 'Order already has this status.',
                'data' => [
                    'order_id' => $order->id,
                    'product_order_id' => $order->product_order_id,
                    'status' => $order->delivered_status,
                    'status_name' => $this->statusName($order->delivered_status),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 9. تحديث حالة الطلب
        |--------------------------------------------------------------------------
        */
        try {
            DB::transaction(function () use (
                $order,
                $newStatus,
                $request
            ) {
                $order->delivered_status = $newStatus;

                switch ($newStatus) {
                    case 0:
                        // Pending
                        break;

                    case 1:
                        // Delivered
                        $order->delivery_date = now();
                        break;

                    case 2:
                        // Cancel
                        $order->cancel_date = now();
                        break;

                    case 3:
                        // Return
                        $order->return_date = now();

                        /*
                         * حسب نظامك:
                         * 0 = none
                         * 1 = request
                         * 2 = approve
                         * 3 = cancel
                         *
                         * باعتبار أن Delivery Syria أرسلت الحالة 3،
                         * نعتبر الإرجاع موافقًا عليه.
                         */
                        $order->return_status = 2;
                        break;

                    case 4:
                        // Confirmed
                        $order->confirmed_date = now();
                        break;

                    case 5:
                        // Picked Up
                        $order->picked_date = now();
                        break;

                    case 6:
                        // Shipped
                        $order->shipped_date = now();
                        break;
                }

                if ($request->filled('note')) {
                    $order->delivery_comment = trim(
                        (string) $request->input('note')
                    );
                }

                $order->save();
            });

            /*
             * تحديث النسخة بعد الحفظ.
             */
            $order->refresh();

            /*
             * تشغيل الحدث الموجود عندك لتغيير حالة الطلب.
             */
            event(new OrderPaymentStatus($order));

            Log::info('Delivery Syria order status updated', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $order->store_id,
                'owner_id' => $owner->id,
                'old_status' => $oldStatus,
                'old_status_name' => $this->statusName($oldStatus),
                'new_status' => $newStatus,
                'new_status_name' => $this->statusName($newStatus),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully.',
                'data' => [
                    'order_id' => $order->id,
                    'product_order_id' => $order->product_order_id,
                    'previous_status' => $oldStatus,
                    'previous_status_name' => $this->statusName($oldStatus),
                    'status' => $newStatus,
                    'status_name' => $this->statusName($newStatus),
                    'updated_at' => $order->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Delivery Syria order status update failed', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not update order status.',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | اسم الحالة للعرض في الرد واللوغ
    |--------------------------------------------------------------------------
    */
    private function statusName(int $status): string
    {
        return match ($status) {
            0 => 'Pending',
            1 => 'Delivered',
            2 => 'Cancel',
            3 => 'Return',
            4 => 'Confirmed',
            5 => 'Picked Up',
            6 => 'Shipped',
            default => 'Unknown',
        };
    }
}