<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\ChatwootIntegration;
use App\Models\ChatwootNotificationTemplate;
use Exception;

class ChatwootAbandonedCartNotificationService
{
    public function send(
        int $storeId,
        int $customerId
    ): array {

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

        if (!$setting) {
            throw new Exception(
                'Abandoned cart template is not configured.'
            );
        }

        $integration = ChatwootIntegration::where(
            'store_id',
            $storeId
        )
        ->where('is_active', 1)
        ->first();

        if (!$integration) {
            throw new Exception(
                'Chatwoot integration is not active.'
            );
        }

        if (!$integration->inbox_id) {
            throw new Exception(
                'No WhatsApp inbox selected.'
            );
        }

        $customer = Customer::where('id', $customerId)
            ->where('store_id', $storeId)
            ->first();

        if (!$customer) {
            throw new Exception(
                'Customer not found.'
            );
        }

      $phone = $this->normalizePhone(
    (string) $customer->mobile
);

if (!$phone) {
    throw new Exception(
        'Customer phone is invalid.'
    );
}

        if (!$phone) {
            throw new Exception(
                'Customer phone is empty.'
            );
        }

        $name = trim(
            $customer->first_name . ' ' .
            $customer->last_name
        );

        if (!$name) {
            $name = 'Customer';
        }

        $cartItems = Cart::where(
            'store_id',
            $storeId
        )
        ->where(
            'customer_id',
            $customerId
        )
        ->get();

        if ($cartItems->isEmpty()) {
            throw new Exception(
                'Customer cart is empty.'
            );
        }

        $chatwoot = new ChatwootService(
            $integration->api_access_token,
            (int) $integration->account_id,
            $integration->base_url
        );

        /*
        |--------------------------------------------------------------------------
        | Find or create contact
        |--------------------------------------------------------------------------
        */

        $searchResult =
            $chatwoot->searchContacts(
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

if (!$contact) {

    $created = $chatwoot->createContact(
        (int) $integration->inbox_id,
        $name,
        $phone
    );

    /*
    |--------------------------------------------------------------------------
    | Chatwoot may return the created contact in different shapes
    |--------------------------------------------------------------------------
    */

    if (isset($created['payload']['contact']['id'])) {

        $contact = $created['payload']['contact'];

    } elseif (isset($created['payload']['id'])) {

        $contact = $created['payload'];

    } elseif (
        isset($created['payload'][0]) &&
        is_array($created['payload'][0])
    ) {

        $contact = $created['payload'][0];

    } elseif (isset($created['contact']['id'])) {

        $contact = $created['contact'];

    } elseif (isset($created['id'])) {

        $contact = $created;
    }

    /*
     * Fallback:
     * sometimes Chatwoot creates the contact successfully,
     * but the create response doesn't contain the shape we expect.
     * Search for it again by phone.
     */
    if (!$contact || empty($contact['id'])) {

        $searchAgain = $chatwoot->searchContacts($phone);

        $contactsAgain =
            $searchAgain['payload'] ?? [];

        $contact = collect($contactsAgain)
            ->first(function ($item) use ($phone) {

                return trim(
                    (string) (
                        $item['phone_number'] ?? ''
                    )
                ) === $phone;
            });
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
        | Get source_id
        |--------------------------------------------------------------------------
        */

        $contactResponse =
            $chatwoot->getContact(
                $contactId
            );

        $contactData =
            $contactResponse['payload'] ?? [];

        $contactInbox =
            collect(
                $contactData['contact_inboxes'] ?? []
            )
            ->first(function ($item) use ($integration) {

                return (int) (
                    $item['inbox']['id'] ?? 0
                ) ===
                (int) $integration->inbox_id;
            });

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
        | Find conversation
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
            ->filter(function ($item) use ($integration) {

                return (int) (
                    $item['inbox_id'] ?? 0
                ) ===
                (int) $integration->inbox_id;

            })
            ->sortByDesc(function ($item) {

                return $item['id'] ?? 0;

            })
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
        | Resolve abandoned cart variables
        |--------------------------------------------------------------------------
        */

        $resolvedParameters =
            $this->resolveParameters(
                $customer,
                $cartItems,
                $setting->parameters ?? []
            );

        /*
        |--------------------------------------------------------------------------
        | Find WhatsApp template
        |--------------------------------------------------------------------------
        */

        $inboxesData =
            $chatwoot->getInboxes();

        $inboxes =
            $inboxesData['payload'] ?? [];

        $selectedInbox =
            collect($inboxes)
            ->first(function ($item) use ($integration) {

                return (int) (
                    $item['id'] ?? 0
                ) ===
                (int) $integration->inbox_id;

            });

        if (!$selectedInbox) {
            throw new Exception(
                'Selected WhatsApp inbox not found.'
            );
        }

        $template =
            collect(
                $selectedInbox['message_templates'] ?? []
            )
            ->first(function ($item) use ($setting) {

                return
                    ($item['name'] ?? null)
                        === $setting->template_name
                    &&
                    ($item['language'] ?? null)
                        === $setting->template_language;

            });

        if (!$template) {
            throw new Exception(
                'WhatsApp template not found.'
            );
        }

        $bodyComponent =
            collect(
                $template['components'] ?? []
            )
            ->first(function ($component) {

                return (
                    $component['type']
                    ?? null
                ) === 'BODY';

            });

        $templateContent =
            $bodyComponent['text']
            ?? '';

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

        $message =
            $chatwoot->sendTemplateMessage(
                $conversationId,
                $setting->template_name,
                $setting->template_language,
                $setting->template_category
                    ?? 'MARKETING',
                $templateContent,
                $resolvedParameters
            );

        return [
            'success' => true,
            'customer_id' => $customerId,
            'conversation_id' => $conversationId,
            'template' => $setting->template_name,
            'content' => $templateContent,
            'parameters' => $resolvedParameters,
            'message' => $message,
        ];
    }


    protected function resolveParameters(
        Customer $customer,
        $cartItems,
        array $parameters
    ): array {

        $values = [];

        foreach ($parameters as $position => $variable) {

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
                    $customer->first_name . ' ' .
                    $customer->last_name
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

                $total =
                    $cartItems->sum(function ($item) {

                        return
                            ((float) $item->price)
                            *
                            ((int) $item->qty);
                    });

                return number_format(
                    $total,
                    2,
                    '.',
                    ''
                );


            case 'cart_last_activity':

                $last =
                    $cartItems
                    ->sortByDesc('updated_at')
                    ->first();

                return $last && $last->updated_at
                    ? $last->updated_at->format('Y-m-d H:i')
                    : '';


            case 'store_name':

                $store =
                    \App\Models\Store::find(
                        $customer->store_id
                    );

                return (string) (
                    $store->name ?? ''
                );


            default:

                return '';
        }
    }




    protected function normalizePhone(string $phone): ?string
{
    $phone = trim($phone);

    if ($phone === '') {
        return null;
    }

    // Remove spaces, dashes, parentheses, etc.
    $phone = preg_replace('/[^\d+]/', '', $phone);

    // Already E.164
    if (
        str_starts_with($phone, '+') &&
        preg_match('/^\+[1-9]\d{7,14}$/', $phone)
    ) {
        return $phone;
    }

    /*
     * IMPORTANT:
     * If your customers are mainly from Syria,
     * convert local 09xxxxxxxx to +9639xxxxxxxx
     */
    if (preg_match('/^09\d{8}$/', $phone)) {

        return '+963' . substr($phone, 1);
    }

    /*
     * Syria number without leading zero:
     * 9xxxxxxxx
     */
    if (preg_match('/^9\d{8}$/', $phone)) {

        return '+963' . $phone;
    }

    /*
     * If already starts with 963
     */
    if (preg_match('/^9639\d{8}$/', $phone)) {

        return '+' . $phone;
    }

    return null;
}
}