<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class ChatwootService
{
    protected string $baseUrl;
    protected string $token;
    protected int $accountId;

    public function __construct(
        string $token,
        int $accountId,
        string $baseUrl = 'https://chat.lynomia.com'
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->accountId = $accountId;
    }

    protected function client()
    {
        return Http::acceptJson()
            ->withHeaders([
                'api_access_token' => $this->token,
            ])
            ->timeout(15);
    }

    public function getInboxes(): array
    {
        $response = $this->client()->get(
            "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/inboxes"
        );

        if (!$response->successful()) {
            throw new Exception(
                'Chatwoot error: '
                . $response->status()
                . ' - '
                . $response->body()
            );
        }

        return $response->json();
    }





public function searchContacts(string $query): array
{
    $response = $this->client()->get(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/search",
        [
            'q' => $query,
        ]
    );

    if (!$response->successful()) {
        throw new \Exception(
            'Unable to search Chatwoot contacts: ' .
            $response->body()
        );
    }

    return $response->json();
}

    public function createContact(
    int $inboxId,
    string $name,
    string $phone
): array {

    $response = $this->client()->post(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts",
        [
            'inbox_id' => $inboxId,
            'name' => $name,
            'phone_number' => $phone,
        ]
    );

    if (!$response->successful()) {
        throw new \Exception(
            'Unable to create Chatwoot contact: ' .
            $response->body()
        );
    }

    return $response->json();
}


public function getContact(int $contactId): array
{
    $response = $this->client()->get(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/{$contactId}"
    );

    if (!$response->successful()) {
        throw new \Exception(
            'Unable to get Chatwoot contact: ' .
            $response->body()
        );
    }

    return $response->json();
}



public function createConversation(
    string $sourceId,
    int $inboxId,
    int $contactId
): array {

    $response = $this->client()->post(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations",
        [
            'source_id' => $sourceId,
            'inbox_id' => $inboxId,
            'contact_id' => $contactId,
            'status' => 'open',
        ]
    );

    if (!$response->successful()) {
        throw new \Exception(
            'Unable to create Chatwoot conversation: ' .
            $response->body()
        );
    }

    return $response->json();
}


public function sendTemplateMessage(
    int $conversationId,
    string $templateName,
    string $language,
    string $category,
    string $content,
    array $processedParams = []
): array {

    $payload = [
        'content' => $content,
        'message_type' => 'outgoing',
        'private' => false,
        'content_type' => 'text',

        'template_params' => [
            'name' => $templateName,
            'category' => $category,
            'language' => $language,

            'processed_params' => [
                'body' => $processedParams,
            ],
        ],
    ];

    $response = $this->client()->post(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages",
        $payload
    );

    if (!$response->successful()) {
        throw new \Exception(
            'Unable to send Chatwoot template message: ' .
            $response->body()
        );
    }

    return $response->json();
}
public function getConversationMessages(
    int $conversationId
): array {

    $response = $this->client()->get(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages"
    );

    if (!$response->successful()) {
        throw new \Exception(
            'Unable to get Chatwoot messages: ' .
            $response->body()
        );
    }

    return $response->json();
}


public function createConversationWithTemplate(
    string $sourceId,
    int $inboxId,
    int $contactId,
    string $templateName,
    string $language,
    string $category,
    array $processedParams = []
): array {

    $response = $this->client()->post(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations",
        [
            'source_id' => $sourceId,
            'inbox_id' => $inboxId,
            'contact_id' => $contactId,
            'status' => 'open',

            'message' => [
                'content' => '',
                'template_params' => [
                    'name' => $templateName,
                    'category' => $category,
                    'language' => $language,
                    'processed_params' => [
                        'body' => $processedParams,
                    ],
                ],
            ],
        ]
    );

    if (!$response->successful()) {
        throw new \Exception(
            'Unable to create Chatwoot conversation with template: ' .
            $response->body()
        );
    }

    return $response->json();
}



public function getContactConversations(int $contactId): array
{
    $response = $this->client()->get(
        "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/{$contactId}/conversations"
    );

    if (!$response->successful()) {
        throw new \Exception(
            'Unable to get contact conversations: ' .
            $response->body()
        );
    }

    return $response->json();
}
}