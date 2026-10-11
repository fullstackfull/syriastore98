<?php

namespace App\Http\Controllers\newsyria;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\ChatwootNotificationTemplate;
use App\Services\ChatwootAbandonedCartNotificationService;
use Illuminate\Http\Request;

class AbandonedCartController extends Controller
{
    public function index()
    {
        $storeId = getCurrentStore();

        $carts = Cart::with('UserData')
            ->where('store_id', $storeId)
            ->whereNotNull('customer_id')
            ->where('customer_id', '>', 0)
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('customer_id');

        $setting = ChatwootNotificationTemplate::where(
            'store_id',
            $storeId
        )
        ->where(
            'event_type',
            'abandoned_cart'
        )
        ->where(
            'is_enabled',
            1
        )
        ->first();

        return view(
            'newsyria.chatwoot.abandoned-carts',
            compact(
                'carts',
                'setting'
            )
        );
    }


 public function send(
    Request $request,
    ChatwootAbandonedCartNotificationService $service
) {
    $request->validate([
        'customer_ids' => 'required|array|min:1',
        'customer_ids.*' => 'integer',
    ]);

    $storeId = getCurrentStore();

    $sent = 0;
    $failed = 0;

    $errors = [];

    foreach (array_unique($request->customer_ids) as $customerId) {

        try {

            $result = $service->send(
                $storeId,
                (int) $customerId
            );

            $sent++;

        } catch (\Throwable $e) {

            $failed++;

            $errors[] = [
                'customer_id' => $customerId,
                'message' => $e->getMessage(),
            ];

            \Log::error('Abandoned cart reminder failed', [
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    if ($failed > 0) {

        return redirect()
            ->back()
            ->with('send_result', [
                'sent' => $sent,
                'failed' => $failed,
                'errors' => $errors,
            ]);
    }

    return redirect()
        ->back()
        ->with('success', "Successfully sent {$sent} WhatsApp reminder(s).");
}





}