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
    use \App\Http\Controllers\Concerns\RestoresCancelledOrderStock;

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
                $oldStatus,
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

                        /*
                         * THE GOODS.  P4-F056. MEASURED before this line: a courier-reported
                         * cancellation of a pending order left a 2-unit line's stock at 4 where 6
                         * was due -- HTTP 200, {success:true}, `cancel_date` stamped, nothing
                         * credited.
                         *
                         * `$oldStatus === 0` IS THE REPLAY GUARD AND IT IS LOAD-BEARING HERE, more
                         * than at the other doors: this handler's only idempotency is
                         * `$oldStatus === $newStatus` (:164), so a 2 -> 1 -> 2 sequence re-enters
                         * this case, and Delivery Syria retries deliveries. Restricting the credit
                         * to a cancel FROM PENDING also matches what the web permits at all
                         * (`Order::order_status_change()` refuses a cancel from any non-pending
                         * state, app/Models/Order.php:702), so the webhook and the merchant screen
                         * now agree about which cancels are legitimate. The trait's per-line
                         * markers bound it a second time.
                         */
                        if ($oldStatus === 0) {
                            $this->restoreCancelledOrderStock(
                                $order,
                                is_array($cancelLines = json_decode((string) $order->product_json, true)) ? $cancelLines : []
                            );

                            /*
                             * P4-F124 -- THE MONEY, under the SAME `$oldStatus === 0` guard as the
                             * goods, deliberately.
                             *
                             * Delivery Syria RETRIES deliveries, and this handler's only other
                             * idempotency is `$oldStatus === $newStatus`, so a 2 -> 1 -> 2 sequence
                             * re-enters this case. Keeping the reversal inside the same guard means
                             * the money and the stock answer the same question about what a
                             * legitimate cancellation is. The reversal is idempotent on the
                             * financial row's own status as well, so a retry that did get through
                             * still moves nothing twice -- two independent bounds, which is what a
                             * retried webhook deserves.
                             */
                            app(\App\Services\OrderEntitlementReversal::class)->reverseOrLog(
                                $order,
                                \App\Services\OrderEntitlementReversal::REASON_CANCELLED,
                                'delivery-syria webhook'
                            );
                        }
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
                         * `1`, NOT `2`.  P4-F056.
                         *
                         * This wrote `2` -- APPROVED -- and restocked nothing, and `2` is the exact
                         * durable idempotency marker `OrderController::order_return_request` reads
                         * before crediting (OrderController.php:1421-1422). So a courier-reported
                         * return DISARMED the merchant's own approval: MEASURED, the merchant then
                         * POSTed `order-return-request` with status 2, got HTTP 200, and the stock
                         * stayed at 4 where 6 was due.
                         *
                         * It was not even visible as a problem in the UI: both web controls are
                         * gated on `return_status == 1` (order/action.blade.php:35,
                         * order/grid.blade.php:230), so the Approve button was never offered and the
                         * order simply rendered as "Return" with the goods quietly missing.
                         *
                         * A COURIER REPORTING A RETURN IS A REQUEST, NOT AN APPROVAL -- the goods
                         * are on their way back, not yet checked in. `1` says exactly that, puts the
                         * Approve control back in front of the merchant, and routes the credit
                         * through the ONE existing restore door rather than adding a fourth.
                         */
                        $order->return_status = 1;
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