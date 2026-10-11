<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverySyriaStatusController extends Controller
{
    
    
    
    public function zidStatuses()
{
    $storeId = 3191416;

    $zidStore = DB::table('zid_stores')
        ->where('store_id', $storeId)
        ->first();

    if (!$zidStore) {
        return response()->json([
            'success' => false,
            'message' => 'Zid store not found.',
        ], 404);
    }

    try {
        $authorization = Crypt::decryptString($zidStore->authorization);
        $managerToken = Crypt::decryptString($zidStore->access_token);

        $response = Http::withHeaders([
            'Authorization'   => 'Bearer ' . $authorization,
            'X-Manager-Token' => $managerToken,
            'Accept'          => 'application/json',
            'Accept-Language' => 'en',
        ])->get(
            'https://api.zid.sa/v1/managers/store/orders/custom-statuses'
        );

        return response()->json([
            'success' => $response->successful(),
            'http_status' => $response->status(),
            'zid_response' => $response->json(),
        ], $response->successful() ? 200 : 502);

    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}
    public function updateStatus(Request $request)
    {
        $productOrderId = $request->input('product_order_id');
        $status = $request->input('status');

        if (!$productOrderId || $status === null) {
            return response()->json([
                'success' => false,
                'message' => 'product_order_id and status are required.',
            ], 422);
        }

        /*
         * DeliverySyria sends invoice_no in this format:
         *
         * ZID-75604309
         *
         * We only need the actual Zid order ID.
         */
        $zidOrderId = str_replace('ZID-', '', $productOrderId);

        /*
         * Find the Zid order in StoreSyria.
         */
        $order = DB::table('zid_orders')
            ->where('zid_order_id', $zidOrderId)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Zid order not found.',
                'zid_order_id' => $zidOrderId,
            ], 404);
        }

        /*
         * Current StoreSyria API status mapping:
         *
         * DeliverySyria 7 -> StoreSyria status 6 -> Zid indelivery
         * DeliverySyria 9 -> StoreSyria status 1 -> Zid completed
         */
        $zidStatusMap = [
            6 => 'indelivery',
1 => 'delivered',
];

        $zidStatus = $zidStatusMap[(int) $status] ?? null;

        /*
         * For now we only sync the statuses that we explicitly approved.
         * Other statuses are not sent to Zid yet.
         */
        if ($zidStatus === null) {
            return response()->json([
                'success' => true,
                'message' => 'Status received but no Zid mapping configured.',
                'data' => [
                    'product_order_id' => $productOrderId,
                    'zid_order_id' => $zidOrderId,
                    'storesyria_status' => $status,
                    'store_id' => $order->store_id,
                ],
            ]);
        }

        /*
         * Get the Zid store credentials.
         */
        $zidStore = DB::table('zid_stores')
            ->where('store_id', $order->store_id)
            ->first();

        if (!$zidStore) {
            Log::warning('Zid store not found for order status sync.', [
                'zid_order_id' => $zidOrderId,
                'store_id' => $order->store_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Zid store credentials not found.',
                'zid_order_id' => $zidOrderId,
                'store_id' => $order->store_id,
            ], 404);
        }

        try {
            /*
             * These values were encrypted when the Zid store was connected.
             */
            $authorization = Crypt::decryptString($zidStore->authorization);
            $managerToken = Crypt::decryptString($zidStore->access_token);

            /*
             * Zid Change Order Status API.
             */
            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $authorization,
                'X-Manager-Token' => $managerToken,
                'Accept'          => 'application/json',
                'Accept-Language' => 'en',
                'Content-Type'    => 'application/json',
            ])->post(
                'https://api.zid.sa/v1/managers/store/orders/'
                . $zidOrderId
                . '/change-order-status',
                [
                    'order_status' => $zidStatus,
                ]
            );

            Log::info('Zid order status sync response.', [
                'zid_order_id' => $zidOrderId,
                'store_id' => $order->store_id,
                'storesyria_status' => $status,
                'zid_status' => $zidStatus,
                'http_status' => $response->status(),
                'response' => $response->json(),
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Zid order status update failed.',
                    'zid_order_id' => $zidOrderId,
                    'zid_status' => $zidStatus,
                    'http_status' => $response->status(),
                    'zid_response' => $response->json(),
                ], 502);
            }

            /*
             * Keep our local Zid order record in sync as well.
             */
            DB::table('zid_orders')
                ->where('zid_order_id', $zidOrderId)
                ->update([
                    'status_code' => $zidStatus,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Zid order status updated successfully.',
                'data' => [
                    'product_order_id' => $productOrderId,
                    'zid_order_id' => $zidOrderId,
                    'storesyria_status' => $status,
                    'zid_status' => $zidStatus,
                    'store_id' => $order->store_id,
                ],
            ]);

        } catch (\Throwable $e) {

            Log::error('Zid order status sync exception.', [
                'product_order_id' => $productOrderId,
                'zid_order_id' => $zidOrderId,
                'store_id' => $order->store_id,
                'storesyria_status' => $status,
                'zid_status' => $zidStatus,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Zid order status update failed.',
                'zid_order_id' => $zidOrderId,
            ], 502);
        }
    }
}