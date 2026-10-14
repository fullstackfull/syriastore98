<?php

namespace Workdo\LynomiaChat\app\Services;

use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;

/**
 * Lynomia's agent API, spoken as ONE merchant person inside ONE workspace.
 *
 * THIS IS NOT PlatformApiClient AND MUST NEVER BECOME IT. PlatformApiClient holds the
 * integration's own credential: it can create workspaces and users, and it reaches every
 * account this app was ever granted. This client holds a credential confined to a single
 * workspace and carrying a single person's rights, so everything it does is attributable to
 * that person and nothing it does can reach another store. The separation is the point -- a
 * merchant request must never be served by the platform credential, because that credential
 * cannot be made to mean "this person, here".
 *
 * WHAT IT MAY NOT DO. It never creates accounts or users and holds no method to. Lynomia
 * enforces the same thing independently: the token is account-scoped server side, and the
 * person's own role decides what they may do inside the workspace.
 *
 * CONVERSATIONS ARE ADDRESSED BY display_id, NOT BY PRIMARY KEY. Upstream resolves
 * `Current.account.conversations.find_by!(display_id: params[:id])`, so the id a caller passes
 * is per-account and sequential. That is convenient for a URL and it is also why the caller is
 * never trusted with it blindly: a display_id from another store is a VALID display_id here,
 * and only the account scope on the token stops it resolving.
 */
class AgentApiClient
{
    public function __construct(
        private readonly LynomiaHttp $http,
        private readonly int $accountId
    ) {
    }

    // ------------------------------------------------------------------ conversations

    /**
     * Page-based, 25 per page upstream (ConversationFinder), and there is NO total count --
     * only the four bucket counts in `meta`. A caller that wants "how many pages" cannot have
     * it; it asks for the next one and stops when a page comes back short.
     *
     * @param array<string,mixed> $filters status, assignee_type, inbox_id, labels, q, page
     */
    public function listConversations(array $filters = []): array
    {
        return $this->http->send('get', $this->path('conversations'), $filters);
    }

    public function getConversation(int $displayId): array
    {
        return $this->http->send('get', $this->path("conversations/{$displayId}"));
    }

    /**
     * CURSOR-based, not page-based: `before` and `after` are MESSAGE IDS, and upstream caps a
     * page at 20 going back, 100 going forward. Mixing this up with page numbers silently
     * returns the newest 20 every time, which looks like a working inbox that never scrolls.
     */
    public function listMessages(int $displayId, array $cursor = []): array
    {
        return $this->http->send('get', $this->path("conversations/{$displayId}/messages"), $cursor);
    }

    /**
     * NOT TRANSPORT-RETRIED, and that is a correctness decision rather than a timid one. A read
     * timeout is exactly the case where Lynomia did the work and the answer was lost coming
     * back; retrying a send posts a SECOND reply the merchant never wrote, to a real customer,
     * on a real channel. The caller reports the uncertainty instead and the merchant can see
     * for themselves whether it landed.
     *
     * @param bool $private an internal note rather than a reply to the customer
     */
    public function sendMessage(int $displayId, string $content, bool $private = false): array
    {
        return $this->http->send(
            'post',
            $this->path("conversations/{$displayId}/messages"),
            // ONLY THESE TWO KEYS. Upstream's create has no strong parameters at all -- it hands
            // the raw params to Messages::MessageBuilder -- so whatever is forwarded is what the
            // builder gets. The whitelist is here because there is none there.
            ['content' => $content, 'private' => $private],
            retryTransport: false
        );
    }

    public function toggleStatus(int $displayId, string $status): array
    {
        return $this->http->send('post', $this->path("conversations/{$displayId}/toggle_status"), ['status' => $status]);
    }

    public function assign(int $displayId, ?int $assigneeId): array
    {
        // A null assignee is how upstream unassigns; it is sent explicitly rather than omitted,
        // because an absent assignee_id key means "do nothing" there.
        return $this->http->send('post', $this->path("conversations/{$displayId}/assignments"), ['assignee_id' => $assigneeId]);
    }

    public function markRead(int $displayId): array
    {
        return $this->http->send('post', $this->path("conversations/{$displayId}/update_last_seen"));
    }

    public function unreadCounts(): array
    {
        return $this->http->send('get', $this->path('conversations/unread_counts'));
    }

    // ------------------------------------------------------------------ people and channels

    public function getContact(int $contactId): array
    {
        return $this->http->send('get', $this->path("contacts/{$contactId}"));
    }

    public function listInboxes(): array
    {
        return $this->http->send('get', $this->path('inboxes'));
    }

    /** The agents this person may assign a conversation to, which is the workspace's members. */
    public function listAgents(): array
    {
        return $this->http->send('get', $this->path('agents'));
    }

    // ------------------------------------------------------------------ webhooks (admin only)

    /**
     * The event subscription this integration keeps on the workspace. Upstream requires the
     * caller to be an ACCOUNT ADMINISTRATOR, so only the owner's identity can do this -- which
     * is correct: subscribing an external system to a workspace's conversations is an
     * owner-level act, not something a staff agent should be able to arrange.
     */
    public function listWebhooks(): array
    {
        return $this->http->send('get', $this->path('webhooks'));
    }

    public function createWebhook(string $url, array $subscriptions): array
    {
        return $this->http->send('post', $this->path('webhooks'), [
            'url' => $url,
            'subscriptions' => $subscriptions,
        ], retryTransport: false);
    }

    public function deleteWebhook(int $webhookId): array
    {
        return $this->http->send('delete', $this->path("webhooks/{$webhookId}"));
    }

    private function path(string $suffix): string
    {
        return "/api/v1/accounts/{$this->accountId}/{$suffix}";
    }

    /** @throws LynomiaApiException never thrown here; declared so callers see the contract. */
    public function accountId(): int
    {
        return $this->accountId;
    }
}
