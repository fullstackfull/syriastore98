<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\ChatwootIntegration;
use Exception;

class ChatwootAbandonedCartService
{
    public function send(
        int $storeId,
        int $customerId,
        string $templateName,
        string $templateLanguage,
        string $templateCategory,
        array $parameters = []
    ): array {

        /*
        |--------------------------------------------------------------------------
        | Integration
        |--------------------------------------------------------------------------
        */

        $integration = ChatwootIntegration::where('store_id', $storeId)
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
        | Customer
        |--------------------------------------------------------------------------
        */

        $customer = Customer::where('id', $customerId)
            ->where('store_id', $storeId)
            ->first();

        if (!$customer) {
            throw new Exception(
                'Customer not found.'
            );
        }

        $phone = trim((string) $customer->mobile);

        if (!$phone) {
            throw new Exception(
                'Customer phone is empty.'
            );
        }

        $name = trim(
            $customer->first_name . ' ' . $customer->last_name
        );

        if (!$name) {
            $name = 'Customer';
        }


        /*
        |--------------------------------------------------------------------------
        | Customer cart
        |--------------------------------------------------------------------------
        */

        $cartItems = Cart::where('store_id', $storeId)
            ->where('customer_id', $customerId)
            ->get();

        if ($cartItems->isEmpty()) {
            throw new Exception(
                'Customer cart is empty.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Chatwoot
        |--------------------------------------------------------------------------
        */

        $chatwoot = new ChatwootService(
            $integration->api_access_token,
            (int) $integration->account_id,
            $integration->base_url
        );


        /*
        |--------------------------------------------------------------------------
        | Find contact
        |--------------------------------------------------------------------------
        */

        $searchResult = $chatwoot->searchContacts($phone);

        $contacts = $searchResult['payload'] ?? [];

        $contact = collect($contacts)->first(
            function ($item) use ($phone) {

                return trim(
                    (string) ($item['phone_number'] ?? '')
                ) === $phone;
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Create contact
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
                $contact = $created['payload'][0];

            } elseif (
                isset($created['payload']['id'])
            ) {
                $contact = $created['payload'];

            } elseif (
                isset($created['id'])
            ) {
                $contact = $created;
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

        $contactId = (int) $contact['id'];


        /*
        |--------------------------------------------------------------------------
        | Full contact
        |--------------------------------------------------------------------------
        */

        $contactResponse =
            $chatwoot->getContact($contactId);

        $contactData =
            $contactResponse['payload'] ?? [];


        /*
        |--------------------------------------------------------------------------
        | Find source_id
        |--------------------------------------------------------------------------
        */

        $contactInbox = collect(
            $contactData['contact_inboxes'] ?? []
        )->first(
            function ($item) use ($integration) {

                return (int) (
                    $item['inbox']['id'] ?? 0
                ) === (int) $integration->inbox_id;
            }
        );

        if (!$contactInbox) {
            throw new Exception(
                'Contact is not linked to selected WhatsApp inbox.'
            );
        }

        $sourceId =
            $contactInbox['source_id'] ?? null;

        if (!$sourceId) {
            throw new Exception(
                'Chatwoot source_id not found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Conversation
        |--------------------------------------------------------------------------
        */

        $conversationsResponse =
            $chatwoot->getContactConversations(
                $contactId
            );

        $conversations =
            $conversationsResponse['payload'] ?? [];

        $existingConversation =
            collect($conversations)
                ->filter(
                    function ($item) use ($integration) {

                        return (int) (
                            $item['inbox_id'] ?? 0
                        ) ===
                        (int) $integration->inbox_id;
                    }
                )
                ->sortByDesc(
                    function ($item) {
                        return $item['id'] ?? 0;
                    }
                )
                ->first();


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
                    $conversation['id'] ?? 0
                );
        }

        if (!$conversationId) {
            throw new Exception(
                'Conversation ID not found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Find WhatsApp template
        |--------------------------------------------------------------------------
        */

        $inboxesData = $chatwoot->getInboxes();

        $inboxes =
            $inboxesData['payload'] ?? [];

        $selectedInbox =
            collect($inboxes)->first(
                function ($item) use ($integration) {

                    return (int) (
                        $item['id'] ?? 0
                    ) ===
                    (int) $integration->inbox_id;
                }
            );

        if (!$selectedInbox) {
            throw new Exception(
                'Selected WhatsApp inbox not found.'
            );
        }

        $template =
            collect(
                $selectedInbox['message_templates'] ?? []
            )->first(
                function ($item) use (
                    $templateName,
                    $templateLanguage
                ) {

                    return
                        ($item['name'] ?? null)
                            === $templateName
                        &&
                        ($item['language'] ?? null)
                            === $templateLanguage;
                }
            );

        if (!$template) {
            throw new Exception(
                'WhatsApp template not found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Resolve variables
        |--------------------------------------------------------------------------
        */

        $resolvedParameters =
            $this->resolveParameters(
                $customer,
                $cartItems,
                $parameters
            );


        /*
        |--------------------------------------------------------------------------
        | Template BODY
        |--------------------------------------------------------------------------
        */

        $bodyComponent =
            collect(
                $template['components'] ?? []
            )->first(
                function ($component) {

                    return (
                        $component['type'] ?? null
                    ) === 'BODY';
                }
            );

        $templateContent =
            $bodyComponent['text'] ?? '';


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
        | Send
        |--------------------------------------------------------------------------
        */

        $message =
            $chatwoot->sendTemplateMessage(
                $conversationId,
                $templateName,
                $templateLanguage,
                $templateCategory ?: 'MARKETING',
                $templateContent,
                $resolvedParameters
            );


        return [
            'success' => true,

            'customer_id' =>
                $customerId,

            'customer_name' =>
                $name,

            'phone' =>
                $phone,

            'conversation_id' =>
                $conversationId,

            'template' =>
                $templateName,

            'content' =>
                $templateContent,

            'parameters' =>
                $resolvedParameters,

            'message' =>
                $message,
        ];
    }


    protected function resolveParameters(
        Customer $customer,
        $cartItems,
        array $parameters
    ): array {

        $values = [];

        foreach (
            $parameters
            as $position => $variable
        ) {

            $values[(string) $position] =
                $this->resolveVariable(
                    $variable,
                    $customer,
                    $cartItems
                );
        }

        return $values;
    }


    protected function resolveVariable(
        string $variable,
        Customer $customer,
        $cartItems
    ): string {

        switch ($variable) {

            case 'customer_name':

                return trim(
                    $customer->first_name
                    . ' '
                    . $customer->last_name
                );


            case 'customer_phone':

                return (string)
                    $customer->mobile;


            case 'customer_email':

                return (string)
                    $customer->email;


            case 'items_count':

                return (string)
                    $cartItems->sum('qty');


            case 'cart_total':

                $total = $cartItems->sum(
                    function ($item) {

                        return
                            ((float) $item->price)
                            *
                            ((int) $item->qty);
                    }
                );

                return (string)
                    number_format(
                        $total,
                        2,
                        '.',
                        ''
                    );


            case 'cart_last_activity':

                $date =
                    $cartItems
                        ->max('updated_at');

                return $date
                    ? (string) $date
                    : '';


            default:

                return '';
        }
    }



    
}