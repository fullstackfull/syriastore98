<?php

namespace Workdo\LynomiaChat\app\Services;

use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;

/**
 * REACHING STORE SYRIA'S OWN LYNOMIA WORKSPACE.
 *
 * WHY A SIBLING OF MerchantInboxGateway RATHER THAN A REUSE OF IT. That one resolves a
 * merchant's identity inside a merchant's workspace: both its entry points take a
 * LynomiaStoreLink and a Store Syria User, and it gates on `allowsSso()`. The platform
 * workspace has no link, no merchant and no SSO -- it is addressed by a configured account
 * id and acted on by a service identity. Bending that class to cover both would mean
 * nullable parameters on the only code path that decides which workspace a credential opens.
 *
 * WHY THE ACCOUNT API AND NOT THE PLATFORM API. There is no platform messaging endpoint:
 * that namespace has users, accounts, grants and consents, and nothing for contacts,
 * conversations or messages. So a scoped user token is minted through the platform API and
 * the account API is driven with it -- the same shape MerchantInboxGateway uses, for the
 * same reason.
 *
 * THE TOKEN IS NOT WRITTEN DOWN. Cached in the `array` store by default, which lives for the
 * request and is then gone. This installation's default cache driver is `file`, and caching
 * a bearer there would write a live Lynomia credential to disk in plaintext -- the mistake
 * the retired Chatwoot integration made and the reason credentials are kept out of the
 * database entirely.
 *
 * UNCONFIGURED IS A REFUSAL, NOT A GUESS. Until an operator provisions the workspace and
 * records its ids, platform communications are simply unavailable. That is better than a
 * default that silently addresses a merchant's workspace.
 */
class PlatformWorkspaceGateway
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    public function configured(): bool
    {
        return $this->accountId() !== null && $this->senderUserId() !== null && $this->inboxId() !== null;
    }

    /**
     * Deliver one approved template to one number, in the platform workspace.
     *
     * Three calls, because the account API has no "message this number" shortcut: resolve the
     * contact, open the conversation, send the template. The contact lookup is keyed on the
     * WhatsApp identity, so a second notice to the same merchant reuses it.
     *
     * @param  array<string,string>  $params
     * @return array{message_id:int, conversation_id:int} what Lynomia now holds this as
     *
     * THE CONVERSATION TRAVELS BACK TOO, and it is not decoration. Lynomia's delivery feed
     * reports an outcome per message, but its READ endpoints are addressed by conversation --
     * so a notice that keeps only the message id can never be reconciled when an event is
     * lost, which is the one case reconciliation exists for.
     *
     * @throws LynomiaApiException
     */
    public function sendTemplate(string $phone, string $name, string $template, string $language, array $params): array
    {
        $client = $this->client();
        $inboxId = (int) $this->inboxId();

        $contactId = $client->resolveContact($phone, $name, $inboxId);

        if ($contactId === 0) {
            throw new LynomiaApiException(LynomiaApiException::KIND_CONFLICT, __('The recipient could not be resolved.'));
        }

        $displayId = $client->openConversation($contactId, $inboxId, $phone);

        if ($displayId === 0) {
            throw new LynomiaApiException(LynomiaApiException::KIND_CONFLICT, __('The conversation could not be opened.'));
        }

        return [
            'message_id' => $client->sendTemplateMessage($displayId, $template, $language, $params),
            'conversation_id' => $displayId,
        ];
    }

    /**
     * Read a conversation's messages back from the platform workspace.
     *
     * Reconciliation's only question: what does Lynomia now hold for the message we handed it?
     * Here rather than in the reconciler because this class is the one that knows how to reach
     * the workspace at all -- the token, the account id and the refusal when it is unconfigured.
     *
     * @return array<string,mixed>
     *
     * @throws LynomiaApiException
     */
    public function listMessages(int $conversationId): array
    {
        return $this->client()->listMessages($conversationId);
    }

    private function client(): AgentApiClient
    {
        if (! $this->configured()) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                __('Platform communications are not set up on this installation.')
            );
        }

        $accountId = (int) $this->accountId();

        return new AgentApiClient(
            new LynomiaHttp(
                (string) config('lynomia.base_url'),
                $this->token($accountId),
                (array) config('lynomia.http', [])
            ),
            $accountId
        );
    }

    private function token(int $accountId): string
    {
        $key = sprintf('lynomia.platform-token.%d', $accountId);
        $store = cache()->store(config('lynomia.credential_cache_store', 'array'));
        $cached = $store->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $lifetime = (int) config('lynomia.credential_lifetime_seconds', 3600);
        $issued = $this->api->issueScopedAccessToken((int) $this->senderUserId(), $accountId, $lifetime);

        // Expire the copy well before the credential does, so a request never picks up a
        // token that lapses mid-flight.
        $store->put($key, $issued['token'], now()->addSeconds(max(60, (int) ($lifetime * 0.5))));

        return $issued['token'];
    }

    private function accountId(): ?int
    {
        $value = config('lynomia.platform_workspace_account_id');

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function senderUserId(): ?int
    {
        $value = config('lynomia.platform_workspace_sender_user_id');

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function inboxId(): ?int
    {
        $value = config('lynomia.platform_workspace_inbox_id');

        return is_int($value) && $value > 0 ? $value : null;
    }
}
