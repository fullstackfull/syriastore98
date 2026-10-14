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

    // ------------------------------------------------------------------ commerce context

    /**
     * The commerce stores this conversation can be looked up in, and whether its contact is
     * already linked to a customer in each.
     *
     * PROXIED RATHER THAN ANSWERED LOCALLY, even though the orders live in this database. The
     * question "which customer is this conversation" is decided once, by Lynomia's matcher,
     * which matches only on verified identifiers and treats everything else as a candidate an
     * agent has to confirm. Answering it a second time here would be a second engine with its
     * own rules, and the two would disagree the first time one of them changed.
     *
     * Authorization comes free for the same reason: this call carries the merchant's own
     * Lynomia credential, so someone who cannot see the conversation cannot see its orders.
     */
    public function listCommerceStores(int $displayId): array
    {
        return $this->http->send('get', $this->path("conversations/{$displayId}/commerce/stores"));
    }

    /** The panel for one store: the link or the candidates, and the linked customer's orders. */
    public function commercePanel(int $displayId, int $storeId): array
    {
        return $this->http->send('get', $this->path("conversations/{$displayId}/commerce/stores/{$storeId}"));
    }

    // ------------------------------------------------------------------ automation (flows)

    /**
     * ALL OF THESE ARE ADMINISTRATOR-ONLY UPSTREAM, AND THAT IS NOT A BUG TO ROUTE AROUND.
     * Api::V1::Accounts::FlowsController authorizes every action -- index included -- with
     * AgentBotPolicy#update?, which is `administrator?`. IdentityProvisioner gives the store
     * OWNER an administrator identity and the owner's STAFF an agent identity, so a staff
     * member's own credential is refused by Lynomia on every call below.
     *
     * That is the correct answer and the Studio shows it as one: a flow decides what every
     * customer of the store is told, automatically, with nobody reading it first. The
     * alternative -- minting an administrator token for staff so the screens "work" -- would
     * hand them full account-administrator rights over the whole workspace, which is the
     * shortcut the Phase 4A capability review rejected by name.
     */
    public function listFlows(): array
    {
        return $this->http->send('get', $this->path('flows'));
    }

    public function getFlow(int $flowId): array
    {
        return $this->http->send('get', $this->path("flows/{$flowId}"));
    }

    /**
     * 422 is returned rather than thrown because the flow CEILING lands here: Billing::FlowLimit
     * refuses the create once the account is at the number its plan includes, and a merchant
     * needs to be told which of the two it was.
     *
     * Not transport-retried: a retried create is a second flow the merchant did not ask for.
     *
     * @return array{0:int,1:array}
     */
    public function createFlow(string $name, ?string $description): array
    {
        return $this->http->sendAllowing([422], 'post', $this->path('flows'), array_filter([
            'name' => $name,
            'description' => $description,
        ], static fn ($value) => $value !== null), retryTransport: false);
    }

    /** @return array{0:int,1:array} */
    public function duplicateFlow(int $flowId, ?string $name): array
    {
        return $this->http->sendAllowing([422], 'post', $this->path("flows/{$flowId}/duplicate"),
            $name === null ? [] : ['name' => $name], retryTransport: false);
    }

    public function updateFlow(int $flowId, array $attributes): array
    {
        return $this->http->send('patch', $this->path("flows/{$flowId}"), $attributes);
    }

    /**
     * 422 carries `errors: [{ code: 'flow_active' }]` -- a published flow, or one with a live
     * session, must be disabled first. Which is a thing the merchant does, not an error.
     *
     * @return array{0:int,1:array}
     */
    public function deleteFlow(int $flowId): array
    {
        return $this->http->sendAllowing([422], 'delete', $this->path("flows/{$flowId}"), retryTransport: false);
    }

    /**
     * 422 carries the graph's validation errors, keyed by node id: the one thing that lets a
     * merchant fix a flow that will not publish.
     *
     * @return array{0:int,1:array}
     */
    public function publishFlow(int $flowId): array
    {
        return $this->http->sendAllowing([422], 'post', $this->path("flows/{$flowId}/publish"), retryTransport: false);
    }

    /** Idempotent upstream -- archiving an already-archived version is a no-op -- so it may retry. */
    public function disableFlow(int $flowId): array
    {
        return $this->http->send('post', $this->path("flows/{$flowId}/disable"));
    }

    public function flowSessions(int $flowId, ?string $status = null): array
    {
        return $this->http->send('get', $this->path("flows/{$flowId}/sessions"), $status === null ? [] : ['status' => $status]);
    }

    /**
     * The account references a configuration form binds its pickers to.
     *
     * FOUR CALLS RATHER THAN ONE, because Lynomia has no composite endpoint for them and inventing one here
     * would be a new surface to maintain for a saving of three round trips on a page that is opened, not
     * polled. They are the endpoints the dashboard itself uses.
     */
    public function listLabels(): array
    {
        return $this->http->send('get', $this->path('labels'));
    }

    public function listTeams(): array
    {
        return $this->http->send('get', $this->path('teams'));
    }

    /** @param string $model contact_attribute | conversation_attribute */
    public function listCustomAttributes(string $model): array
    {
        return $this->http->send('get', $this->path('custom_attribute_definitions'), ['attribute_model' => $model]);
    }

    /**
     * What a condition may say: the attribute keys, their operators and their value sources.
     *
     * SERVED, NEVER COPIED. Lynomia's own dashboard keeps this vocabulary as JavaScript constants beside the
     * server's; a second hand-maintained copy here would offer a merchant an attribute the server rejects, and
     * they would find out when the flow refused to publish. Administrator-only upstream, like the flows
     * endpoints themselves.
     */
    public function automationVocabulary(): array
    {
        return $this->http->send('get', $this->path('automation_rules/vocabulary'));
    }

    /**
     * What this workspace may do and how much of each allowance it has used.
     *
     * READ FROM BILLING RATHER THAN COUNTED HERE. `limits` carries { used, limit } per resource
     * -- agents, inboxes, stores, flows -- resolved through Billing::Entitlements, which is the
     * same resolver that REFUSES the create. A number Store Syria computed itself would be a
     * second answer to the same question, and the one on the screen is the one that would drift.
     *
     * Open to every member upstream, unlike the flows endpoints: it is what a client needs in
     * order to render correctly, not a commercial detail.
     */
    public function entitlements(): array
    {
        return $this->http->send('get', $this->path('billing/entitlements'));
    }

    // ------------------------------------------------------------------ the builder

    /**
     * Save the draft graph, subject to the optimistic lock.
     *
     * 409 carries the OTHER editor's graph and the retry token; 422 carries the shape errors. Both are
     * returned rather than thrown -- see AgentApiClient::saveFlowDraft's sibling on LynomiaHttp.
     *
     * NOT TRANSPORT-RETRIED, for a reason specific to the lock: if the save landed and the answer was lost,
     * the retry's lock_version is stale and Lynomia answers 409 -- the editor would be told somebody else had
     * saved when nobody had. The browser re-reads instead, which is right in both cases.
     *
     * @return array{0:int,1:array}
     */
    public function saveFlowDraft(int $flowId, array $graph, int $lockVersion): array
    {
        return $this->http->sendAllowing([409, 422], 'put', $this->path("flows/{$flowId}/draft"), [
            'graph' => $graph,
            'lock_version' => $lockVersion,
        ], retryTransport: false);
    }

    /**
     * Test Mode on the draft. A pure function upstream: the spec covering it asserts that no message,
     * conversation, contact, session or job survives, so a retry costs nothing and changes nothing.
     *
     * @param list<array{text?:string,reply_id?:string,timer?:bool}> $inputs
     */
    public function simulateFlow(int $flowId, array $inputs): array
    {
        return $this->http->send('post', $this->path("flows/{$flowId}/simulate"), ['inputs' => $inputs]);
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

    /**
     * @param string|null $secret The key deliveries will be signed with. Chosen by the RECEIVER
     *   rather than accepted from Lynomia, so Store Syria can derive it per request from an
     *   environment master key instead of storing a copy of a signing key in a database whose
     *   APP_KEY is published. Omitting it lets Lynomia generate one, which is its default.
     */
    public function createWebhook(string $url, array $subscriptions, ?string $secret = null): array
    {
        return $this->http->send('post', $this->path('webhooks'), [
            // Upstream requires the nested `webhook` key (webhook_params uses params.require).
            'webhook' => array_filter([
                'url' => $url,
                'subscriptions' => $subscriptions,
                'secret' => $secret,
            ], static fn ($value) => $value !== null),
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

    /** The workspace every path above is built against. */
    public function accountId(): int
    {
        return $this->accountId;
    }
}
