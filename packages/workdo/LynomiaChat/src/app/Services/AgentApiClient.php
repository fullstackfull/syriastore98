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

    /** Every inbox: whether it can run an automation, and which one it is running. */
    public function flowChannels(): array
    {
        return $this->http->send('get', $this->path('flows/channels'));
    }

    /**
     * Connect a flow to an inbox, or disconnect whatever is on it.
     *
     * CHATWOOT'S OWN ENDPOINT, deliberately: one inbox carries one bot, and set_agent_bot is
     * where that rule lives -- attaching a second replaces the first, which is the behaviour a
     * merchant expects and the behaviour the dashboard already has. A separate endpoint here
     * would be a second place that rule could be written differently.
     *
     * @param int|null $flowId null disconnects whatever the inbox is running
     */
    public function setInboxFlow(int $inboxId, ?int $flowId): array
    {
        // Upstream treats an ABSENT agent_bot as "disconnect" -- `if params[:agent_bot]` -- so a
        // null is sent as an explicit null rather than omitted, and both read the same there.
        return $this->http->send('post', $this->path("inboxes/{$inboxId}/set_agent_bot"),
            ['agent_bot' => $flowId], retryTransport: false);
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

    /** Ready-made graphs to start from, each one validated by the engine that will publish it. */
    public function flowStarters(): array
    {
        return $this->http->send('get', $this->path('flows/starters'));
    }

    /** How a flow is actually running: counts by outcome, and why the failed runs stopped. */
    public function flowInsights(int $flowId): array
    {
        return $this->http->send('get', $this->path("flows/{$flowId}/insights"));
    }

    // ------------------------------------------------------------------ commerce automation

    /**
     * Automation RULES, which are not flows and must not be confused with them.
     *
     * A flow answers a conversation a customer started. A rule reacts to something that happened --
     * an order paid, a conversation opened -- and takes an action. The Commerce triggers this
     * integration cares about are rules, and the Studio's Commerce section is a rule editor; the
     * Flow Builder is a different surface on a different model.
     */
    public function listAutomationRules(): array
    {
        return $this->http->send('get', $this->path('automation_rules'), ['page' => 1]);
    }

    /** @return array{0:int,1:array} 422 carries the rule's own validation errors, keyed by attribute. */
    public function createAutomationRule(array $rule): array
    {
        return $this->http->sendAllowing([422], 'post', $this->path('automation_rules'), $rule, retryTransport: false);
    }

    /** @return array{0:int,1:array} */
    public function updateAutomationRule(int $ruleId, array $rule): array
    {
        return $this->http->sendAllowing([422], 'patch', $this->path("automation_rules/{$ruleId}"), $rule,
            retryTransport: false);
    }

    public function deleteAutomationRule(int $ruleId): array
    {
        return $this->http->send('delete', $this->path("automation_rules/{$ruleId}"), retryTransport: false);
    }

    // ------------------------------------------------------------------ consent

    /** Recent consent decisions in this workspace, newest first. */
    public function listConsents(array $filters = []): array
    {
        return $this->http->send('get', $this->path('commerce/consents'), $filters);
    }

    /** One shopper's two classes, and whether a send would be allowed right now. */
    public function contactConsent(int $contactId): array
    {
        return $this->http->send('get', $this->path("commerce/consents/{$contactId}"));
    }

    /**
     * Record what a shopper decided.
     *
     * NOT TRANSPORT-RETRIED. A retry is harmless on the wire -- it is an upsert of one row -- but
     * each attempt writes an audit entry, and a consent trail with a phantom duplicate decision is
     * worse than one extra round trip for the person reading it later.
     */
    public function recordConsent(int $contactId, string $purpose, string $state, string $note): array
    {
        return $this->http->send('put', $this->path("commerce/consents/{$contactId}"), [
            'purpose' => $purpose, 'state' => $state, 'note' => $note,
        ], retryTransport: false);
    }

    /** Clears a SUPPRESSION. A withdrawal is the shopper's and is never cleared from here. */
    public function clearConsentSuppression(int $contactId): array
    {
        return $this->http->send('delete', $this->path("commerce/consents/{$contactId}"), retryTransport: false);
    }

    // ------------------------------------------------------------------ campaigns

    /**
     * The workspace's campaigns.
     *
     * NOT STORED HERE, like the audiences and the templates, and for the sharpest reason of
     * the three: a campaign's status is the record of what was SENT. Lynomia owns the send,
     * the recipient snapshot and the outcome of every message; a copy of "status" in this
     * database would be a second account of whether a merchant's customers were messaged,
     * and the two would disagree the first time a run was interrupted.
     */
    public function listCampaigns(): array
    {
        return $this->http->send('get', $this->path('campaigns'));
    }

    public function createCampaign(array $campaign): array
    {
        return $this->http->send('post', $this->path('campaigns'), ['campaign' => $campaign], retryTransport: false);
    }

    public function updateCampaign(int $displayId, array $campaign): array
    {
        return $this->http->send('patch', $this->path("campaigns/{$displayId}"), ['campaign' => $campaign], retryTransport: false);
    }

    /**
     * Stop a campaign that is running or due to run.
     *
     * A POST, NOT A DELETE, and the difference matters: deleting removes the recipient
     * snapshot -- the record of who WAS reached before it stopped -- which is precisely what
     * an operator cancelling a half-sent campaign needs to read afterwards.
     *
     * Not transport-retried. A retry is harmless upstream (cancelling twice is a no-op) but
     * a cancellation that appears twice in the audit trail is a worse record than one extra
     * round trip.
     */
    public function cancelCampaign(int $displayId, ?string $reason = null): array
    {
        return $this->http->send('post', $this->path("campaigns/{$displayId}/cancel"),
            array_filter(['reason' => $reason], static fn ($value) => $value !== null), retryTransport: false);
    }

    public function deleteCampaign(int $displayId): array
    {
        return $this->http->send('delete', $this->path("campaigns/{$displayId}"), retryTransport: false);
    }

    /** What one campaign actually did, per outcome. */
    public function campaignMetrics(int $campaignId): array
    {
        return $this->http->send('get', $this->path("campaigns/{$campaignId}/analytics/metrics"));
    }

    /** Who it reached, and who it did not, with the reason. A page at a time. */
    public function campaignRecipients(int $campaignId, array $filters = []): array
    {
        return $this->http->send('get', $this->path("campaigns/{$campaignId}/analytics/contacts"), $filters);
    }

    // ------------------------------------------------------------------ message templates

    /**
     * The WhatsApp templates this workspace manages.
     *
     * NOT STORED HERE, FOR A HARDER REASON THAN THE AUDIENCES. A template's real state lives
     * at META: approved, rejected, paused, flagged, with a quality score that moves on its
     * own. Lynomia mirrors it and knows when the mirror was last refreshed; a third copy in
     * this database would be the stalest of the three and would be the one a merchant read
     * before sending a campaign on a template Meta had already paused.
     */
    public function listMessageTemplates(): array
    {
        return $this->http->send('get', $this->path('whatsapp/message_templates'));
    }

    public function createMessageTemplate(array $attributes): array
    {
        return $this->http->send('post', $this->path('whatsapp/message_templates'), $attributes, retryTransport: false);
    }

    public function updateMessageTemplate(int $templateId, array $attributes): array
    {
        return $this->http->send('patch', $this->path("whatsapp/message_templates/{$templateId}"), $attributes, retryTransport: false);
    }

    public function deleteMessageTemplate(int $templateId): array
    {
        return $this->http->send('delete', $this->path("whatsapp/message_templates/{$templateId}"), retryTransport: false);
    }

    /**
     * Hand a draft to Meta for review.
     *
     * NOT TRANSPORT-RETRIED, and this is the one where it matters most: a retry that Meta saw
     * the first time starts a SECOND review of the same template, and a merchant waiting on a
     * verdict now has two. Lynomia's own submission guard catches most of that; not retrying
     * is the half this side owns.
     */
    public function submitMessageTemplate(int $templateId): array
    {
        return $this->http->send('post', $this->path("whatsapp/message_templates/{$templateId}/submit"), retryTransport: false);
    }

    public function duplicateMessageTemplate(int $templateId): array
    {
        return $this->http->send('post', $this->path("whatsapp/message_templates/{$templateId}/duplicate"), retryTransport: false);
    }

    // ------------------------------------------------------------------ audiences

    /**
     * The workspace's saved contact filters -- its audiences.
     *
     * READ AND WRITTEN THROUGH LYNOMIA, never kept here. An audience is a QUERY, evaluated by
     * Lynomia against its own contacts and its own commerce summaries at the moment a campaign
     * sends; a copy of the conditions in this database would be a second definition that the
     * sender does not read, and the first time the two disagreed the merchant would be shown
     * one audience and send to another.
     */
    public function listAudiences(): array
    {
        return $this->http->send('get', $this->path('custom_filters'), ['filter_type' => 'contact']);
    }

    public function createAudience(string $name, array $query): array
    {
        return $this->http->send('post', $this->path('custom_filters'), [
            'custom_filter' => ['name' => $name, 'filter_type' => 'contact', 'shared' => true, 'query' => $query],
        ], retryTransport: false);
    }

    public function updateAudience(int $audienceId, array $attributes): array
    {
        return $this->http->send('patch', $this->path("custom_filters/{$audienceId}"), [
            'custom_filter' => $attributes,
        ], retryTransport: false);
    }

    /**
     * Upstream refuses to delete an audience a campaign or an automation rule still uses, and
     * says which -- so this reports that refusal rather than pre-checking it here, where the
     * answer could be stale by the time the delete lands.
     */
    public function deleteAudience(int $audienceId): array
    {
        return $this->http->send('delete', $this->path("custom_filters/{$audienceId}"), retryTransport: false);
    }

    /**
     * How many contacts an audience selects RIGHT NOW, counted on Lynomia's side.
     *
     * `commerce_store_id` narrows the commerce aggregates to this installation's links, which
     * is what a campaign for this store will do when it sends. Without it the count answers
     * "orders anywhere" while the send answers "orders with us", and the merchant is shown a
     * number their campaign cannot reach.
     */
    /**
     * @param int|null $inboxId The channel the campaign will send on. A store-scoped audience
     *   is scoped by CHANNEL -- a partner grant taken for WhatsApp does not authorise an SMS
     *   blast -- so a preview that omits it answers a different question from the send.
     *   Upstream defaults to SMS when it is not given, which is what this used to do for
     *   every WhatsApp campaign.
     */
    public function previewAudience(
        array $audience,
        ?int $commerceStoreId = null,
        ?int $inboxId = null,
        ?array $templateParams = null
    ): array {
        return $this->http->send('post', $this->path('campaigns/audience_preview'), array_filter([
            'audience' => $audience,
            'commerce_store_id' => $commerceStoreId,
            'inbox_id' => $inboxId,
            // Upstream reads only the name and language out of it, to find the template in
            // the channel's own synced list and take ITS category -- nothing a client says
            // about the class is used.
            'template_params' => $templateParams,
        ], static fn ($value) => $value !== null));
    }

    /** What the commerce conditions can be built from: the stores, the currencies, the gaps. */
    public function audienceFields(): array
    {
        return $this->http->send('get', $this->path('commerce/audience_fields'));
    }

    /** Find a shopper by name, email or phone, to look at or change their consent. */
    public function searchContacts(string $query): array
    {
        return $this->http->send('get', $this->path('contacts/search'), ['q' => $query, 'page' => 1]);
    }

    // --------------------------------------------- platform notices (P6E, platform workspace)

    /**
     * Find or make the contact for one WhatsApp identity.
     *
     * THE IDENTITY IS THE KEY, NOT THE NAME. Upstream holds a UNIQUE index on
     * contact_inboxes (inbox_id, source_id), so the number already is the natural key within
     * an inbox -- searching first and creating only on a miss means a second notice to the
     * same merchant reuses the contact rather than accumulating duplicates of a person.
     *
     * Only ever called in the PLATFORM workspace, where every contact is a merchant Store
     * Syria already has a record of. It is not a way to create people in a merchant's
     * workspace, and nothing routes it there.
     *
     * @return int the contact id
     */
    public function resolveContact(string $phone, string $name, int $inboxId): int
    {
        $existing = $this->searchContacts($phone);

        foreach ((array) ($existing['payload'] ?? []) as $candidate) {
            $identifier = preg_replace('/\D+/', '', (string) ($candidate['phone_number'] ?? ''));

            if ($identifier === preg_replace('/\D+/', '', $phone) && isset($candidate['id'])) {
                return (int) $candidate['id'];
            }
        }

        $created = $this->http->send('post', $this->path('contacts'), [
            'name' => $name,
            'phone_number' => '+' . ltrim($phone, '+'),
            'inbox_id' => $inboxId,
        ]);

        return (int) ($created['payload']['contact']['id'] ?? $created['id'] ?? 0);
    }

    /**
     * The conversation a notice is delivered in.
     *
     * NOT IDEMPOTENT UPSTREAM unless the inbox opts into locking to a single conversation,
     * so a caller that wants one thread per merchant has to arrange it there. The notice
     * record's own unique key is what stops a notice being sent twice; this only decides
     * where it lands.
     *
     * @return int the conversation's DISPLAY id, which is what every other endpoint takes
     */
    public function openConversation(int $contactId, int $inboxId, string $sourceId): int
    {
        $created = $this->http->send('post', $this->path('conversations'), [
            'contact_id' => $contactId,
            'inbox_id' => $inboxId,
            'source_id' => $sourceId,
        ]);

        return (int) ($created['display_id'] ?? 0);
    }

    /**
     * Send an approved template, as the dashboard composer does.
     *
     * WHAT IS ALLOWED IS NOT DECIDED HERE. The platform workspace refuses anything Meta does
     * not classify as UTILITY, reading the category off the channel's own synced list, so
     * naming a marketing template in this call does not make it operational -- it makes the
     * message fail with a reason.
     *
     * @param  array<string,string>  $params  placeholder values, keyed by position
     * @return int the message id
     */
    public function sendTemplateMessage(int $displayId, string $templateName, string $language, array $params): int
    {
        $sent = $this->http->send('post', $this->path("conversations/{$displayId}/messages"), [
            'content' => '',
            'template_params' => [
                'name' => $templateName,
                'language' => $language,
                'processed_params' => ['body' => $params],
            ],
        ]);

        return (int) ($sent['id'] ?? 0);
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

    /**
     * Widen an existing subscription without re-registering the endpoint.
     *
     * NEEDED BECAUSE THE SUBSCRIPTION LIST GROWS. A store connected before `consent_changed`
     * joined the list has a webhook at the right URL carrying the OLD events, and a
     * create-if-missing check sees the URL, calls it done and leaves the new event
     * unsubscribed forever. The secret is not re-sent: it is derived per store and
     * unchanged, and resending it would rotate nothing while putting a signing key in
     * another request body.
     */
    public function updateWebhookSubscriptions(int $webhookId, array $subscriptions): array
    {
        return $this->http->send('patch', $this->path("webhooks/{$webhookId}"), [
            'webhook' => ['subscriptions' => $subscriptions],
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
