<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\DeliverySyriaCredential;
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
            // NEW-DS-ROUTE-SHADOW-01 (second half). This answered 401, and it is now
            // 403 with the same message its sibling endpoint uses.
            //
            // Two reasons, in order of weight:
            //  1. 401 is forbidden across this project because repo B's
            //     lib/network/network.dart turns ANY 401 from this API into a forced
            //     logout that clears stored preferences, so a 401 anywhere under /api
            //     is a logout bomb for the Flutter admin app.
            //  2. This is not a behaviour change for any caller that existed, because
            //     NO CALLER COULD REACH THIS METHOD. Until the duplicate closure was
            //     removed from routes/web.php, POST api/delivery-syria/orders/update-status
            //     was served by that closure -- measured, by dispatching the request:
            //     an empty POST returned 422 {"message":"product_order_id and status are
            //     required."} (the closure) and never 403 {"message":"Invalid platform."}
            //     (this controller, :27-32). A response contract that was unreachable
            //     cannot have been depended on.
            //
            // The message is aligned with DeliverySyriaStatusController's
            // 'Invalid credentials.' so that a caller without a token and a caller with a
            // wrong token are indistinguishable, which is the property
            // DeliverySyriaWebhookTest::test_the_credential_check_precedes_input_validation
            // already pins on the sibling endpoint.
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | 2b. P1-SEC-10: the credential is resolved BEFORE anything else is learned
        |--------------------------------------------------------------------------
        |
        | This used to look the order up first and compare the secret of that order's store owner second, so a wrong token got 404 for an id
        | that exists nowhere and 403 for one that exists in some tenant -- an existence oracle over every store's product_order_id, with
        | no throttle. The token is now matched against the stored secrets first (constant time, see DeliverySyriaCredential); a caller
        | that matches none gets one answer whatever it asked about, and one that matches is confined to the stores its secret owns.
        */
        $storeIds = DeliverySyriaCredential::storeIdsFor($secret);

        if ($storeIds === []) {
            Log::warning('Delivery Syria invalid credentials', [
                'platform' => $platform,
                'provided_token_length' => strlen($secret),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 403);
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
        | 4. البحث عن الطلب -- only among the stores the authenticated secret owns
        |--------------------------------------------------------------------------
        */
        $order = Order::query()
            ->where('product_order_id', $productOrderId)
            ->whereIn('store_id', $storeIds)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
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