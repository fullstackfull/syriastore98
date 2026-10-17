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
    /**
     * Lynomia's name for the outcome feed (Delivery::StatusFeed::EVENT).
     *
     * ONE EVENT, not the merchant set. This workspace has no inbox a human watches, so
     * conversation and message events would be a stream nothing reads; what the installation
     * needs to know is what became of the notices it sent.
     */
    public const DELIVERY_EVENT = 'message_delivery_status';

    /**
     * Whether the configured account has been confirmed platform-owned in THIS instance.
     *
     * Memoised rather than cached: a cache entry outlives the request that wrote it, and the
     * thing being checked is an environment variable an operator can change between two
     * requests. One extra GET per gateway instance, against the three calls `sendTemplate`
     * already makes, is not a cost worth trading correctness for.
     */
    private ?bool $ownershipConfirmed = null;

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

    /**
     * Make sure the platform workspace is telling us what becomes of its messages.
     *
     * WITHOUT THIS THE DELIVERY FEED HAS NO SUBSCRIBER. Lynomia only posts an account webhook
     * to an endpoint somebody registered with the event in its subscription list, and
     * EventSubscriptionManager registers a MERCHANT'S workspace -- it takes a store link, and
     * the platform workspace is not a store and has no link. The ingress, the projector and
     * the ordering rule were all complete and exercised while nothing had ever told Lynomia
     * where to send a delivery.
     *
     * WIDENED, NEVER NARROWED, and matched on the URL, the same way the merchant side does:
     * whatever else the workspace has pointed at this endpoint stays, because it is not this
     * module's to remove.
     *
     * THE SECRET IS OURS, NOT LYNOMIA'S. Chosen by the receiver and derived per request from
     * the environment master key, so there is no copy of a live signing key in a database
     * whose APP_KEY is published.
     *
     * @return bool whether anything was changed
     *
     * @throws LynomiaApiException
     */
    public function ensureDeliveryFeedSubscribed(): bool
    {
        $client = $this->client();
        $url = route('lynomia.platform.events.ingress');

        foreach ((array) ($client->listWebhooks()['payload'] ?? []) as $webhook) {
            if (! is_array($webhook) || ($webhook['url'] ?? null) !== $url) {
                continue;
            }

            $subscriptions = is_array($webhook['subscriptions'] ?? null) ? $webhook['subscriptions'] : [];

            if (in_array(self::DELIVERY_EVENT, $subscriptions, true)) {
                return false;
            }

            $client->updateWebhookSubscriptions(
                (int) $webhook['id'],
                array_values(array_unique(array_merge($subscriptions, [self::DELIVERY_EVENT])))
            );

            return true;
        }

        $client->createWebhook($url, [self::DELIVERY_EVENT], EventSignature::platformSecret());

        return true;
    }

    /**
     * REFUSE TO ACT AS A WORKSPACE THAT IS NOT OURS (blocker B5).
     *
     * THE HOLE THIS CLOSES. `LYNOMIA_PLATFORM_WORKSPACE_ACCOUNT_ID` is a number an operator
     * types after provisioning, and nothing on this side could tell a platform-owned workspace
     * from a merchant's. Pointed at a merchant's account and inbox, every surface here would
     * send the installation's operational notices from THAT MERCHANT'S OWN WhatsApp channel --
     * and Lynomia's UTILITY-only gate would not apply either, because that gate keys on the
     * account being platform-owned. A wrong digit is enough.
     *
     * BOTH WAYS OF GETTING IT WRONG ARE ANSWERED, and by two different mechanisms:
     *   a merchant's account of ours   `show` succeeds, because this app holds a permissible
     *                                  on every workspace it provisioned, and answers
     *                                  platform_owned: false. The flag catches it.
     *   anything else                  `show` is permissible-checked upstream and answers 401.
     *                                  The status catches it, before the flag is even read.
     *
     * LOUDLY, AND BEFORE ANYTHING IS SENT. A misconfigured workspace is a deployment bug, not
     * a condition to degrade around: the throw leaves the notice `failed` with a reason an
     * operator reads, rather than a message in front of a merchant's customers. It is
     * KIND_NOT_CONFIGURED, which `isTransient()` excludes, so nothing retries it -- retrying
     * cannot fix a wrong number in the environment.
     *
     * AND AN ANSWER WITHOUT THE FIELD IS NOT AN ANSWER. A Lynomia predating the field returns
     * no `platform_owned`, which is "could not say", not "no". Both refuse -- the deploy order
     * is Lynomia first precisely so this does not arise -- but the message distinguishes them,
     * because one is a wrong id and the other is a half-finished deploy.
     *
     * @throws LynomiaApiException
     */
    private function assertPlatformOwned(int $accountId): void
    {
        if ($this->ownershipConfirmed === true) {
            return;
        }

        $account = $this->api->showAccount($accountId);

        if ($account['platform_owned'] === null) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_NOT_CONFIGURED,
                "Lynomia did not report whether account {$accountId} is platform-owned. "
                . 'Deploy Lynomia before Store Syria: this installation cannot confirm it is '
                . 'sending from its own workspace.'
            );
        }

        if ($account['platform_owned'] !== true) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_NOT_CONFIGURED,
                "Lynomia account {$accountId} is not the platform-owned workspace. "
                . 'LYNOMIA_PLATFORM_WORKSPACE_ACCOUNT_ID is pointing at a merchant workspace; '
                . 'nothing was sent.'
            );
        }

        $this->ownershipConfirmed = true;
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

        $this->assertPlatformOwned($accountId);

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
