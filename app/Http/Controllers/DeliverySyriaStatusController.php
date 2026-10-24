<?php

namespace App\Http\Controllers;

use App\Services\DeliverySyriaCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverySyriaStatusController extends Controller
{
    
    
    
    // zidStatuses() was removed here. Its route was deleted earlier in this
    // branch, leaving the method unreachable, and it was not worth keeping: it
    // had no authentication of any kind, hard-coded a single tenant's store id
    // (3191416), and echoed a third-party API response straight back to the
    // caller. Deleted rather than guarded, because nothing calls it.

    /**
     * DeliverySyria delivery-status webhook.
     *
     * NEW-delivery-syria-status. This endpoint was ANONYMOUS: routes/api.php
     * applied only `throttle:60,1`, so anyone who could reach the URL could
     * drive any Zid order to 'indelivery' or 'delivered' and have this server
     * push that status to Zid under the merchant's own stored credentials. The
     * order id was not even a secret -- it is derived from the invoice number.
     *
     * The two checks below are copied from the shape that is already correct in
     * Api\DeliverySyriaOrderController (:25-32 and :157-179): an X-Platform
     * header check, then a constant-time comparison of the bearer token against
     * the per-store users.deliverysyria_secret, resolved through
     * stores.created_by. Per-store, not a single global secret, so one
     * merchant's token cannot move another merchant's orders.
     *
     * ============================== GATE ==============================
     * THIS CHANGE STOPS THE LIVE WEBHOOK UNTIL THE PROVIDER IS CONFIGURED.
     *
     * DeliverySyria must be told to send BOTH:
     *     X-Platform: deliverysyria
     *     Authorization: Bearer <that store's users.deliverysyria_secret>
     *
     * Until they do, every delivery-status callback returns 403 and order
     * statuses stop syncing to Zid. That is deliberate -- the alternative is
     * leaving an anonymous write endpoint open -- but it is a coordinated
     * cutover, not a silent deploy. It needs an owner to schedule it with the
     * provider.
     *
     * Note also that users.deliverysyria_secret is declared by NO migration
     * (verified: no match in database/migrations). On the live database the
     * column exists; on a database built from source it does not, and the
     * secret therefore reads as empty and every request is refused. Declaring
     * the column is a cross-agent dependency (schema agent). This is the same
     * undeclared-column pattern as the finance tables.
     * ==================================================================
     */
    public function updateStatus(Request $request)
    {
        /*
         * 1. Platform header. Cheap, no database work, and it is what
         *    distinguishes a configured provider callback from a stray request.
         */
        $platform = trim((string) $request->header('X-Platform'));

        if ($platform !== 'deliverysyria') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid platform.',
            ], 403);
        }

        $secret = trim((string) $request->bearerToken());

        if ($secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 403);
        }

        /*
         * P1-SEC-10. The credential is resolved BEFORE the order is looked up. This endpoint used to find the zid_orders row first and
         * compare the owner's secret second, so a wrong token got 404 (which also echoed the caller's id back) for an id that exists nowhere
         * and 403 for one that exists in some tenant: an existence oracle over every store's Zid order ids. Now an unauthenticated caller
         * gets one answer whatever it asks about, and an authenticated one is confined to the stores its own secret owns -- so a VALID
         * secret of store B cannot probe store A's ids either.
         */
        $storeIds = DeliverySyriaCredential::storeIdsFor($secret);

        if ($storeIds === []) {
            // Never log the secret or the supplied token -- length only, which is enough to tell "sent nothing" from "sent the wrong thing".
            Log::warning('Delivery Syria status webhook: invalid credentials.', [
                'platform' => $platform,
                'provided_token_length' => strlen($secret),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 403);
        }

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
            ->whereIn('store_id', $storeIds)
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
                ->where('store_id', $order->store_id)
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