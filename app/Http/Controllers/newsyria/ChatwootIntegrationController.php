<?php

namespace App\Http\Controllers\newsyria;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ChatwootService;
use App\Models\ChatwootIntegration;
use App\Models\ChatwootNotificationTemplate;

use App\Models\Order;
use App\Services\ChatwootOrderVariableService;
use App\Services\ChatwootOrderNotificationService;

class ChatwootIntegrationController extends Controller
{



public function testOrderNotification(
    Order $order,
    ChatwootOrderNotificationService $notificationService
) {
    try {

        if ((int) $order->store_id !== (int) getCurrentStore()) {
            return response()->json([
                'success' => false,
                'message' => 'Order does not belong to current store.',
            ], 403);
        }

        $result = $notificationService->send(
            $order,
            'order_status_changed'
        );

        return response()->json($result);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}

public function connect(Request $request)
{
    $request->validate([
        'account_id' => 'required|integer',
        'api_access_token' => 'nullable|string',
    ]);

    $existingIntegration = ChatwootIntegration::where(
        'store_id',
        getCurrentStore()
    )->first();

    $token = $request->api_access_token;

    if (empty($token) && $existingIntegration) {
        $token = $existingIntegration->api_access_token;
    }

    if (empty($token)) {
        return response()->json([
            'success' => false,
            'message' => 'API Access Token is required.',
        ], 422);
    }

    try {

        $chatwoot = new ChatwootService(
            $token,
            (int) $request->account_id
        );

        $data = $chatwoot->getInboxes();

        $inboxes = $data['payload'] ?? [];

        $whatsappInboxes = array_values(array_filter(
            $inboxes,
            function ($inbox) {
                return ($inbox['channel_type'] ?? null) === 'Channel::Whatsapp';
            }
        ));

        $dataToSave = [
            'base_url' => 'https://chat.lynomia.com',
            'account_id' => $request->account_id,
            'is_active' => 1,
            'last_connected_at' => now(),
        ];

        if (!empty($request->api_access_token)) {
            $dataToSave['api_access_token'] = $request->api_access_token;
        }

        ChatwootIntegration::updateOrCreate(
            [
                'store_id' => getCurrentStore(),
            ],
            $dataToSave
        );

        return response()->json([
            'success' => true,
            'message' => 'Chatwoot connected and saved successfully.',
            'inboxes' => $whatsappInboxes,
        ]);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}


public function saveInbox(Request $request)
{
    $request->validate([
        'inbox_id' => 'required|integer',
    ]);

    try {

        $integration = ChatwootIntegration::where(
            'store_id',
            getCurrentStore()
        )->firstOrFail();

        $chatwoot = new ChatwootService(
            $integration->api_access_token,
            (int) $integration->account_id
        );

        $data = $chatwoot->getInboxes();

        $inboxes = $data['payload'] ?? [];

        $inbox = collect($inboxes)->first(function ($item) use ($request) {
            return (int) ($item['id'] ?? 0) === (int) $request->inbox_id
                && ($item['channel_type'] ?? null) === 'Channel::Whatsapp';
        });

        if (!$inbox) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid WhatsApp inbox.',
            ], 422);
        }

        $integration->update([
            'inbox_id' => $inbox['id'],
            'inbox_name' => $inbox['name'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp inbox saved successfully.',
        ]);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}




public function getInboxes()
{
    try {

        $integration = ChatwootIntegration::where(
            'store_id',
            getCurrentStore()
        )->firstOrFail();

        $chatwoot = new ChatwootService(
            $integration->api_access_token,
            (int) $integration->account_id
        );

        $data = $chatwoot->getInboxes();

        $inboxes = $data['payload'] ?? [];

        $whatsappInboxes = array_values(array_filter(
            $inboxes,
            function ($inbox) {
                return ($inbox['channel_type'] ?? null) === 'Channel::Whatsapp';
            }
        ));

        return response()->json([
            'success' => true,
            'inboxes' => $whatsappInboxes,
            'selected_inbox_id' => $integration->inbox_id,
        ]);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}

public function getTemplates()
{
    try {

        $integration = ChatwootIntegration::where(
            'store_id',
            getCurrentStore()
        )->firstOrFail();

        if (!$integration->inbox_id) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a WhatsApp inbox first.',
            ], 422);
        }

        $chatwoot = new ChatwootService(
            $integration->api_access_token,
            (int) $integration->account_id
        );

        $data = $chatwoot->getInboxes();

        $inboxes = $data['payload'] ?? [];

        $inbox = collect($inboxes)->first(function ($item) use ($integration) {
            return (int) ($item['id'] ?? 0) === (int) $integration->inbox_id
                && ($item['channel_type'] ?? null) === 'Channel::Whatsapp';
        });

        if (!$inbox) {
            return response()->json([
                'success' => false,
                'message' => 'Saved WhatsApp inbox not found.',
            ], 422);
        }

        $templates = collect(
            $inbox['message_templates'] ?? []
        )
        ->filter(function ($template) {
            return ($template['status'] ?? null) === 'APPROVED';
        })
        ->values();

        return response()->json([
            'success' => true,
            'templates' => $templates,
        ]);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}


public function saveNotificationTemplates(Request $request)
{
    $request->validate([
        'settings' => 'required|array',
    ]);

    try {

        $storeId = getCurrentStore();

        foreach ($request->settings as $item) {

            $eventType = $item['event_type'] ?? null;
            $templateName = $item['template_name'] ?? null;
            $templateLanguage = $item['template_language'] ?? null;
            $templateCategory = $item['template_category'] ?? null;
            $parameters = $item['parameters'] ?? [];

            $isEnabled = !empty($item['is_enabled'])
                ? 1
                : 0;


            if (!$eventType) {
                continue;
            }


            /*
             * إذا ما اختار Template
             * نحذف إعداد هذه الحالة.
             */
            if (empty($templateName)) {

                ChatwootNotificationTemplate::where(
                    'store_id',
                    $storeId
                )
                ->where(
                    'event_type',
                    $eventType
                )
                ->delete();

                continue;
            }


            ChatwootNotificationTemplate::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'event_type' => $eventType,
                ],
                [
                    'template_name' => $templateName,
                    'template_language' => $templateLanguage,
                    'template_category' => $templateCategory,
                    'parameters' => $parameters,
                    'is_enabled' => $isEnabled,
                ]
            );

        }


        return response()->json([
            'success' => true,
            'message' => 'Notification templates saved successfully.',
        ]);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422);

    }
}







public function getNotificationTemplates()
{
    try {

        $settings = ChatwootNotificationTemplate::where(
            'store_id',
            getCurrentStore()
        )->get();

        return response()->json([
            'success' => true,
            'settings' => $settings,
        ]);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422);

    }
}






}