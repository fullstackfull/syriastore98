<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ChatwootIntegration;
use App\Models\ChatwootNotificationTemplate;
use Exception;

class ChatwootOrderNotificationService
{
    protected ChatwootOrderVariableService $variableService;

    public function __construct(
        ChatwootOrderVariableService $variableService
    ) {
        $this->variableService = $variableService;
    }


    /**
     * Send order notification.
     *
     * Example events:
     *
     * order_created
     * order_confirmed
     * order_picked_up
     * order_shipped
     * order_delivered
     * order_cancelled
     * return_requested
     * order_status_changed
     */
    public function send(
        Order $order,
        string $eventType
    ): array {

        /*
        |--------------------------------------------------------------------------
        | Get store integration
        |--------------------------------------------------------------------------
        */

        $integration = ChatwootIntegration::where(
            'store_id',
            $order->store_id
        )
        ->where('is_active', 1)
        ->first();

        if (!$integration) {
            throw new Exception(
                'Chatwoot integration is not active for this store.'
            );
        }

        if (!$integration->inbox_id) {
            throw new Exception(
                'No WhatsApp inbox selected.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Find notification template
        |--------------------------------------------------------------------------
        |
        | First:
        | Try the specific event.
        |
        | Example:
        | order_shipped
        |
        | If there is no enabled template:
        | fallback to order_status_changed
        |
        */

        $setting = ChatwootNotificationTemplate::where(
            'store_id',
            $order->store_id
        )
        ->where(
            'event_type',
            $eventType
        )
        ->where(
            'is_enabled',
            1
        )
        ->first();


        if (
            !$setting &&
            $eventType !== 'order_status_changed'
        ) {

            $setting = ChatwootNotificationTemplate::where(
                'store_id',
                $order->store_id
            )
            ->where(
                'event_type',
                'order_status_changed'
            )
            ->where(
                'is_enabled',
                1
            )
            ->first();
        }


        /*
        |--------------------------------------------------------------------------
        | No template = do nothing
        |--------------------------------------------------------------------------
        */

        if (!$setting) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'No enabled notification template.',
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Order customer information
        |--------------------------------------------------------------------------
        */

        $orderDetails = Order::order_detail(
            $order->id
        );

        $name = trim(
            $orderDetails['billing_informations']['name']
            ?? $orderDetails['delivery_informations']['name']
            ?? ''
        );

$rawPhone = \DB::table('order_billing_details')
    ->where('order_id', $order->id)
    ->value('telephone');

$phone = $this->normalizePhone(
    trim((string) $rawPhone)
);

if (!$phone) {
    throw new \Exception(
        'Customer phone is invalid. Raw phone: ' . $rawPhone
    );
}

        if (!$phone) {
            throw new Exception(
                'Customer phone is empty.'
            );
        }

        if (!$name) {
            $name = 'Customer';
        }


        /*
        |--------------------------------------------------------------------------
        | Chatwoot API
        |--------------------------------------------------------------------------
        */

        $chatwoot = new ChatwootService(
            $integration->api_access_token,
            (int) $integration->account_id,
            $integration->base_url
        );


        /*
        |--------------------------------------------------------------------------
        | Find Chatwoot contact
        |--------------------------------------------------------------------------
        */

        $searchResult = $chatwoot->searchContacts(
            $phone
        );

        $contacts =
            $searchResult['payload'] ?? [];

        $contact = collect(
            $contacts
        )->first(function ($item) use ($phone) {

            return trim(
                (string) (
                    $item['phone_number'] ?? ''
                )
            ) === $phone;

        });


        /*
        |--------------------------------------------------------------------------
        | Create contact if it doesn't exist
        |--------------------------------------------------------------------------
        */

        if (!$contact) {

            $created = $chatwoot->createContact(
                (int) $integration->inbox_id,
                $name,
                $phone
            );


            if (
                isset($created['payload'][0]) &&
                is_array($created['payload'][0])
            ) {

                $contact =
                    $created['payload'][0];

            } elseif (
                isset($created['payload']['id'])
            ) {

                $contact =
                    $created['payload'];

            } elseif (
                isset($created['id'])
            ) {

                $contact =
                    $created;
            }
        }


        if (
            !$contact ||
            empty($contact['id'])
        ) {

            throw new Exception(
                'Unable to find or create Chatwoot contact.'
            );
        }


        $contactId =
            (int) $contact['id'];


        /*
        |--------------------------------------------------------------------------
        | Get full contact information
        |--------------------------------------------------------------------------
        */

        $contactResponse =
            $chatwoot->getContact(
                $contactId
            );

        $contactData =
            $contactResponse['payload']
            ?? [];


        /*
        |--------------------------------------------------------------------------
        | Find source_id for WhatsApp inbox
        |--------------------------------------------------------------------------
        */

        $contactInbox = collect(
            $contactData['contact_inboxes']
            ?? []
        )->first(
            function ($item) use ($integration) {

                return (int) (
                    $item['inbox']['id']
                    ?? 0
                ) ===
                (int) $integration->inbox_id;

            }
        );


        if (!$contactInbox) {

            throw new Exception(
                'Contact is not linked to selected WhatsApp inbox.'
            );
        }


        $sourceId =
            $contactInbox['source_id']
            ?? null;


        if (!$sourceId) {

            throw new Exception(
                'Chatwoot source_id not found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Find existing conversation
        |--------------------------------------------------------------------------
        */

        $conversationsResponse =
            $chatwoot->getContactConversations(
                $contactId
            );

        $conversations =
            $conversationsResponse['payload']
            ?? [];


        $existingConversation =
            collect($conversations)
                ->filter(
                    function ($item) use ($integration) {

                        return (int) (
                            $item['inbox_id']
                            ?? 0
                        ) ===
                        (int) $integration->inbox_id;

                    }
                )
                ->sortByDesc(
                    function ($item) {

                        return $item['id']
                            ?? 0;

                    }
                )
                ->first();


        /*
        |--------------------------------------------------------------------------
        | Use conversation or create one
        |--------------------------------------------------------------------------
        */

        if ($existingConversation) {

            $conversationId =
                (int) $existingConversation['id'];

        } else {

            $conversation =
                $chatwoot->createConversation(
                    $sourceId,
                    (int) $integration->inbox_id,
                    $contactId
                );

            $conversationId =
                (int) (
                    $conversation['id']
                    ?? 0
                );
        }


        if (!$conversationId) {

            throw new Exception(
                'Conversation ID not found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Resolve ecommerce variables
        |--------------------------------------------------------------------------
        */

        $resolvedParameters =
            $this->variableService->resolve(
                $order,
                $setting->parameters ?? []
            );


        /*
        |--------------------------------------------------------------------------
        | Get inbox templates
        |--------------------------------------------------------------------------
        */

        $inboxesData =
            $chatwoot->getInboxes();

        $inboxes =
            $inboxesData['payload']
            ?? [];


        $selectedInbox =
            collect($inboxes)->first(
                function ($item) use ($integration) {

                    return (int) (
                        $item['id']
                        ?? 0
                    ) ===
                    (int) $integration->inbox_id;

                }
            );


        if (!$selectedInbox) {

            throw new Exception(
                'Selected WhatsApp inbox not found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Find selected WhatsApp template
        |--------------------------------------------------------------------------
        */

        $template =
            collect(
                $selectedInbox['message_templates']
                ?? []
            )
            ->first(
                function ($item) use ($setting) {

                    return (
                        ($item['name'] ?? null)
                        ===
                        $setting->template_name
                    )
                    &&
                    (
                        ($item['language'] ?? null)
                        ===
                        $setting->template_language
                    );

                }
            );


        if (!$template) {

            throw new Exception(
                'WhatsApp template not found in Chatwoot.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Find BODY
        |--------------------------------------------------------------------------
        */

        $bodyComponent =
            collect(
                $template['components']
                ?? []
            )
            ->first(
                function ($component) {

                    return (
                        $component['type']
                        ?? null
                    ) === 'BODY';

                }
            );


        $templateContent =
            $bodyComponent['text']
            ?? '';


        /*
        |--------------------------------------------------------------------------
        | Replace {{1}}, {{2}}, ...
        |--------------------------------------------------------------------------
        */

        foreach (
            $resolvedParameters
            as $position => $value
        ) {

            $templateContent =
                str_replace(
                    '{{' . $position . '}}',
                    (string) $value,
                    $templateContent
                );
        }


        /*
        |--------------------------------------------------------------------------
        | Send WhatsApp template
        |--------------------------------------------------------------------------
        */

        $message =
            $chatwoot->sendTemplateMessage(
                $conversationId,
                $setting->template_name,
                $setting->template_language,
                $setting->template_category
                    ?? 'UTILITY',
                $templateContent,
                $resolvedParameters
            );


        /*
        |--------------------------------------------------------------------------
        | Result
        |--------------------------------------------------------------------------
        */

        return [
            'success' => true,

            'event_type' =>
                $setting->event_type,

            'conversation_id' =>
                $conversationId,

            'contact_id' =>
                $contactId,

            'template' =>
                $setting->template_name,

            'content' =>
                $templateContent,

            'parameters' =>
                $resolvedParameters,

            'message' =>
                $message,
        ];
    }








    protected function normalizePhone(string $phone): ?string
{
    $phone = trim($phone);

    if ($phone === '') {
        return null;
    }

    $phone = preg_replace('/[^\d+]/', '', $phone);

    if (
        str_starts_with($phone, '+') &&
        preg_match('/^\+[1-9]\d{7,14}$/', $phone)
    ) {
        return $phone;
    }

    // 09xxxxxxxx
    if (preg_match('/^09\d{8}$/', $phone)) {
        return '+963' . substr($phone, 1);
    }

    // 9xxxxxxxx
    if (preg_match('/^9\d{8}$/', $phone)) {
        return '+963' . $phone;
    }

    // 9639xxxxxxxx
    if (preg_match('/^9639\d{8}$/', $phone)) {
        return '+' . $phone;
    }

    return null;
}
}