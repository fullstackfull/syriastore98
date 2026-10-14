<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Models\LynomiaInboxEvent;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\EventSignature;

/**
 * Where Lynomia tells Store Syria that something changed.
 *
 * THIS ROUTE HAS NO SESSION AND MUST NOT WANT ONE. It is called server to server by Lynomia, so
 * `auth` would refuse every legitimate delivery. What authenticates it instead is the signature:
 * HMAC-SHA256 over "{timestamp}.{body}" with a per-store key, which Lynomia already sends on
 * every webhook (lib/webhooks/trigger.rb) and which this derives rather than stores.
 *
 * THE STORE IS IN THE URL, AND THAT IS NOT A TRUST DECISION. Each store's deliveries are signed
 * with that store's own key, so naming store 7 in the path and signing with store 9's key fails;
 * and the account id in the body is checked against the link that store actually holds, so a
 * correctly-signed delivery still cannot write an event about a workspace its store does not own.
 * Both checks are needed: the first stops forgery, the second stops a confused or compromised
 * sender attributing one store's traffic to another.
 *
 * WHAT IT WRITES IS A NOTIFICATION, NEVER CONTENT -- see the migration. A row says "conversation
 * 41 changed"; the interface re-fetches that conversation through the BFF, which authorizes the
 * request as the person making it. So even a delivery that somehow got past both checks could
 * leak nothing: there is nothing in the row to leak.
 *
 * IT ANSWERS 200 TO ALMOST EVERYTHING IT ACCEPTS, including duplicates. Lynomia's webhook
 * delivery is fire-and-forget with no retry (Webhooks::Trigger rescues and logs), so a non-2xx
 * buys nothing -- nobody will try again -- while a duplicate is ordinary at-least-once traffic.
 */
class EventIngressController extends Controller
{
    public function store(Request $request, int $store): JsonResponse
    {
        $body = $request->getContent();

        if (! EventSignature::verify($store, $body, $request->header(EventSignature::SIGNATURE_HEADER),
            $request->header(EventSignature::TIMESTAMP_HEADER))) {
            // Deliberately uninformative. Telling a caller whether the signature, the timestamp
            // or the store was wrong is three oracles in one response.
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return $this->refuse($store, 'malformed_json', 400);
        }

        $link = LynomiaStoreLink::forStore($store)->first();

        // A signature proves WHO sent it; this proves WHAT it may talk about. A delivery about
        // an account this store does not hold is dropped even when correctly signed.
        if ($link === null || $link->lynomia_account_id === null
            || (int) $link->lynomia_account_id !== $this->accountIdOf($payload)) {
            return $this->refuse($store, 'unknown_workspace', 404);
        }

        $deliveryId = (string) $request->header(EventSignature::DELIVERY_HEADER, '');

        if ($deliveryId === '') {
            return $this->refuse($store, 'missing_delivery_id', 400);
        }

        $recorded = $this->record($link, $payload, $deliveryId,
            $request->header(EventSignature::TIMESTAMP_HEADER));

        if (! $recorded) {
            // A duplicate is a success, not a problem: the unique index on delivery_id did its
            // job. Logged at debug rather than warning so a retrying proxy does not look like
            // an incident.
            Log::debug('[lynomia] duplicate event delivery ignored', [
                'store_id' => (int) $link->store_id,
                'delivery_id' => mb_substr($deliveryId, 0, 64),
            ]);
        }

        return response()->json(['recorded' => $recorded]);
    }

    /**
     * IDEMPOTENT BY THE UNIQUE INDEX, not by a check. Two deliveries of one event can arrive at
     * once; both would read "not seen" and both would insert. The index settles it and the
     * loser reports success, because from Lynomia's side the event WAS delivered.
     */
    private function record(LynomiaStoreLink $link, array $payload, string $deliveryId, ?string $signedTimestamp = null): bool
    {
        $occurredAt = ctype_digit((string) $signedTimestamp)
            ? \Illuminate\Support\Carbon::createFromTimestampUTC((int) $signedTimestamp)
            : now();

        try {
            LynomiaInboxEvent::create([
                'store_id' => (int) $link->store_id,
                'lynomia_account_id' => (int) $link->lynomia_account_id,
                'event' => mb_substr((string) ($payload['event'] ?? 'unknown'), 0, 64),
                'conversation_display_id' => $this->conversationIdOf($payload),
                // The moment LYNOMIA says it happened, not the moment this server parsed it.
                // The timestamp is inside the signature, so it is as trustworthy as the body.
                'occurred_at' => $occurredAt,
                'delivery_id' => mb_substr($deliveryId, 0, 64),
            ]);

            return true;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Lynomia's payloads put the account in different places by event: a conversation payload
     * nests it under `account`, a message payload carries the conversation which carries it.
     */
    private function accountIdOf(array $payload): int
    {
        $candidates = [
            $payload['account']['id'] ?? null,
            $payload['account_id'] ?? null,
            $payload['conversation']['account_id'] ?? null,
            $payload['conversation']['meta']['account_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        return 0;
    }

    /** The per-account display id, which is what the agent API addresses a conversation by. */
    private function conversationIdOf(array $payload): ?int
    {
        $candidates = [
            $payload['conversation']['display_id'] ?? null,
            $payload['display_id'] ?? null,
            $payload['conversation']['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * One shape for every refusal, so a rejected delivery is visible.
     *
     * The controller logged NOTHING before this: a bad signature, a stale timestamp, a workspace
     * mismatch and a malformed body were all indistinguishable silence, which is the worst
     * possible property for the one unauthenticated endpoint in the module. Identifiers and a
     * reason only -- the body is unauthenticated and never reaches the log.
     */
    private function refuse(int $store, string $reason, int $status): JsonResponse
    {
        Log::warning('[lynomia] refused an inbound event', [
            'store_id' => $store,
            'reason' => $reason,
            'status' => $status,
        ]);

        return response()->json(['error' => 'Rejected'], $status);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
