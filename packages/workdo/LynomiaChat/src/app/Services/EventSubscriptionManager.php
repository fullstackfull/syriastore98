<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * Subscribes Store Syria to a workspace's events, and keeps that subscription honest.
 *
 * WHY THE OWNER'S IDENTITY AND NOT THE PLATFORM TOKEN. Lynomia has no platform-API route for
 * webhooks -- they live on the agent API behind WebhookPolicy, which requires an account
 * ADMINISTRATOR. That is the right gate rather than an obstacle: subscribing an external system
 * to every conversation in a workspace is an owner-level act, not something a staff agent should
 * be able to arrange quietly. The tenant owner's identity is an administrator of the workspace by
 * construction (AccountProvisioner gives them that seat), so the subscription is made as the
 * person who actually owns the workspace and Lynomia's audit trail says so.
 *
 * WHAT IT SUBSCRIBES TO, AND WHAT LYNOMIA CANNOT OFFER. Webhook::ALLOWED_WEBHOOK_EVENTS permits
 * twelve events and the list is frozen with no overlay seam. Of the events the approved
 * requirements name, these map directly:
 *
 *   new incoming / outgoing message   message_created  (message_type distinguishes them)
 *   conversation created              conversation_created
 *   conversation updated              conversation_updated
 *   status changed                    conversation_status_changed
 *   delivery / read update            message_updated
 *
 * ASSIGNMENT CHANGE and UNREAD CHANGE have no webhook of their own. They are not missed: both
 * change the conversation, so conversation_updated fires, and because an event carries only ids
 * and the client re-reads state, "something about this conversation changed" is sufficient --
 * the interface never needed to know WHICH field. CHANNEL DISCONNECTED has no webhook either and
 * genuinely is not covered; it would need an additive adapter on the Lynomia side, and this
 * integration does not pretend otherwise.
 */
class EventSubscriptionManager
{
    /** The subset of Webhook::ALLOWED_WEBHOOK_EVENTS this inbox reacts to. */
    public const SUBSCRIPTIONS = [
        'conversation_created',
        'conversation_updated',
        'conversation_status_changed',
        'message_created',
        'message_updated',
        // A shopper replying STOP on WhatsApp is a decision made on Lynomia's channel about
        // this store's customer. Without this subscription the merchant's consent screen
        // shows a cheerful "subscribed" for somebody who opted out days ago.
        'consent_changed',
    ];

    public function __construct(private readonly MerchantInboxGateway $gateway)
    {
    }

    /**
     * Make sure this store's workspace is sending us events, as the tenant owner.
     *
     * IDEMPOTENT BY URL. Lynomia has a unique index on (account_id, url), so re-subscribing the
     * same endpoint is refused upstream rather than duplicated; this reads first and only creates
     * what is missing, so a repeated activation is a no-op.
     */
    public function ensureSubscribed(LynomiaStoreLink $link, User $owner): bool
    {
        $url = $this->ingressUrl($link);

        try {
            $client = $this->gateway->clientFor($link, $owner);
            $existing = (array) ($client->listWebhooks()['payload'] ?? []);

            foreach ($existing as $webhook) {
                if (is_array($webhook) && ($webhook['url'] ?? null) === $url) {
                    return false;
                }
            }

            $client->createWebhook($url, self::SUBSCRIPTIONS, EventSignature::secretFor((int) $link->store_id));

            return true;
        } catch (LynomiaApiException $e) {
            // Best effort at activation, for the same reason the seat sync is: the workspace
            // exists and works without this -- the inbox refreshes on demand instead of on its
            // own -- and failing the whole activation would send the merchant back to retry
            // something already done.
            Log::warning('[lynomia] could not subscribe to workspace events', [
                'store_id' => $link->store_id,
                'lynomia_account_id' => $link->lynomia_account_id,
                'kind' => $e->kind,
            ]);

            return false;
        }
    }

    /**
     * Where Lynomia should post. Absolute, because another system consumes it, and built from the
     * route rather than concatenated so a prefix change cannot silently desynchronise the
     * registered URL from the one that is actually served.
     */
    public function ingressUrl(LynomiaStoreLink $link): string
    {
        return route('lynomia.events.ingress', ['store' => (int) $link->store_id]);
    }
}
