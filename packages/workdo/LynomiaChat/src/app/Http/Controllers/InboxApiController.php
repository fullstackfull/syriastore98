<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaInboxEvent;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AgentApiClient;
use Workdo\LynomiaChat\app\Services\MerchantInboxGateway;

/**
 * The merchant browser's only door to Lynomia.
 *
 * THE BROWSER NEVER TALKS TO LYNOMIA. Not because its CORS policy would refuse -- it would --
 * but because talking to Lynomia requires a credential, and there is no credential a merchant
 * browser could hold that would not also be a credential a merchant browser could leak. Every
 * route here authenticates the Store Syria user, resolves the store the middleware already
 * authorized, resolves that person's own Lynomia identity, and forwards only the operation they
 * are permitted to perform.
 *
 * NO IDENTIFIER FROM THE CALLER NAMES A TENANT. There is no account_id, store_id or link id in
 * any of these signatures. The store comes from EnsureStoreOwnsLink, which resolved it from the
 * session and checked ownership; the Lynomia account comes from that store's link; the
 * credential is scoped to that account server side by Lynomia. A conversation display_id IS
 * accepted -- it has to be -- and it is deliberately not trusted: display_ids are per-account
 * and sequential, so another store's conversation 7 is a perfectly VALID 7 here. What stops it
 * resolving is that the token cannot see that account at all, which is a server-side fact the
 * caller cannot influence. That is the whole reason the credential is scoped rather than merely
 * membership-checked.
 *
 * WHAT COMES BACK IS NARROWED. Lynomia's payloads carry more than a merchant inbox needs --
 * internal ids, channel credentials on inbox objects, agent emails. Each response is projected
 * to the fields the interface uses, so a field added upstream does not silently become a field
 * this product exposes.
 */
class InboxApiController extends Controller
{
    public function __construct(private readonly MerchantInboxGateway $gateway)
    {
    }

    public function conversations(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($request) {
            $body = $client->listConversations(array_filter([
                'status' => $this->oneOf($request->query('status'), ['open', 'resolved', 'pending', 'snoozed', 'all']),
                'assignee_type' => $this->oneOf($request->query('assignee_type'), ['me', 'unassigned', 'all', 'assigned']),
                'page' => max(1, (int) $request->query('page', 1)),
            ], static fn ($value) => $value !== null));

            $payload = $body['data']['payload'] ?? [];

            return [
                'conversations' => array_map([$this, 'projectConversation'], is_array($payload) ? $payload : []),
                // Upstream reports bucket counts, never a total; a caller asks for the next page
                // and stops when one comes back short rather than computing a page count.
                'meta' => $body['data']['meta'] ?? [],
            ];
        });
    }

    public function messages(Request $request, int $conversation): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($request, $conversation) {
            // Cursor, not page: `before` is a MESSAGE id and upstream caps the window at 20.
            $before = $request->query('before');
            $body = $client->listMessages($conversation, $before !== null ? ['before' => (int) $before] : []);
            $payload = $body['payload'] ?? [];

            return ['messages' => array_map([$this, 'projectMessage'], is_array($payload) ? $payload : [])];
        });
    }

    public function reply(Request $request, int $conversation): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:20000'],
            'private' => ['sometimes', 'boolean'],
        ]);

        return $this->proxy($request, function (AgentApiClient $client) use ($validated, $conversation) {
            $body = $client->sendMessage(
                $conversation,
                (string) $validated['content'],
                (bool) ($validated['private'] ?? false)
            );

            return ['message' => $this->projectMessage($body)];
        });
    }

    public function updateStatus(Request $request, int $conversation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:open,resolved,pending,snoozed'],
        ]);

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'status' => $client->toggleStatus($conversation, (string) $validated['status']),
        ]);
    }

    public function assign(Request $request, int $conversation): JsonResponse
    {
        $validated = $request->validate([
            // Nullable is how a conversation is UNassigned; absent would mean "do nothing".
            'assignee_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'assignee' => $client->assign($conversation, $validated['assignee_id'] === null ? null : (int) $validated['assignee_id']),
        ]);
    }

    public function markRead(Request $request, int $conversation): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($conversation) {
            $client->markRead($conversation);

            return ['ok' => true];
        });
    }

    public function agents(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) {
            $agents = $client->listAgents();

            return ['agents' => array_values(array_map(static fn (array $agent): array => [
                'id' => $agent['id'] ?? null,
                'name' => $agent['name'] ?? '',
                // Deliberately no email: the assignment picker needs a name and an id, and an
                // agent roster is not a reason to hand every staff member everyone's address.
            ], array_filter($agents, 'is_array')))];
        });
    }

    public function contact(Request $request, int $contact): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($contact) {
            $body = $client->getContact($contact);
            $payload = $body['payload'] ?? $body;

            return ['contact' => [
                'id' => $payload['id'] ?? null,
                'name' => $payload['name'] ?? '',
                'email' => $payload['email'] ?? null,
                'phone_number' => $payload['phone_number'] ?? null,
                'thumbnail' => $payload['thumbnail'] ?? null,
            ]];
        });
    }

    /**
     * What has changed in this store's workspace since the caller last looked.
     *
     * THE CURSOR IS THE WHOLE DESIGN. A client sends the last event id it saw and gets everything
     * after it, which makes reconnection, catch-up after a dropped connection and ordinary
     * polling the SAME operation -- there is no separate recovery path that only runs when
     * something has already gone wrong, and therefore no recovery path that is never exercised.
     * Out-of-order and duplicate deliveries are harmless for the same reason: the client re-reads
     * current state rather than applying a diff.
     *
     * EVENTS CARRY NO CONTENT. A row says "conversation 41 changed"; the browser then asks for
     * that conversation through the ordinary authorized routes above. So this endpoint cannot
     * leak a message even if an event were somehow misrouted -- there is nothing in it to leak.
     *
     * The store is taken from the middleware, never from the caller, so a cursor can only ever
     * move forward within this store's own rows.
     */
    public function events(Request $request): JsonResponse
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        if (! $link) {
            return response()->json(['error' => __('This store is not connected to Lynomia Chat.')], 409);
        }

        $after = max(0, (int) $request->query('after', 0));
        $events = LynomiaInboxEvent::forStoreAfter((int) $link->store_id, $after)
            ->limit(100)
            ->get(['id', 'event', 'conversation_display_id', 'occurred_at']);

        return response()->json([
            'events' => $events->map(fn (LynomiaInboxEvent $event) => [
                'id' => $event->id,
                'event' => $event->event,
                'conversation_id' => $event->conversation_display_id,
                'at' => $event->occurred_at?->toIso8601String(),
            ])->all(),
            // Where to resume from. Sent explicitly rather than inferred from the last row, so a
            // poll that returns nothing still tells the client where it is.
            'cursor' => $events->last()->id ?? $after,
        ]);
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Resolve the caller's workspace, run the operation, and turn any failure into something a
     * merchant can read.
     *
     * THE UPSTREAM BODY IS NEVER FORWARDED. The retired Chatwoot integration echoed the
     * provider's raw response to the browser; that leaked internal error text to a merchant and
     * gave the caller nothing to branch on. LynomiaApiException carries a machine-readable kind
     * and a safe sentence, and only those cross this boundary.
     */
    private function proxy(Request $request, callable $operation): JsonResponse
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        if (! $link) {
            return response()->json(['error' => __('This store is not connected to Lynomia Chat.')], 409);
        }

        try {
            $client = $this->gateway->clientFor($link, $request->user());

            return response()->json($operation($client));
        } catch (LynomiaApiException $e) {
            return response()->json(['error' => $this->merchantMessage($e), 'kind' => $e->kind], $this->statusFor($e));
        }
    }

    private function statusFor(LynomiaApiException $e): int
    {
        return match ($e->kind) {
            LynomiaApiException::KIND_CONFLICT => 409,
            LynomiaApiException::KIND_UNAUTHORIZED, LynomiaApiException::KIND_NOT_PERMISSIBLE => 403,
            LynomiaApiException::KIND_NOT_FOUND => 404,
            LynomiaApiException::KIND_RATE_LIMITED => 429,
            // Unreachable, disabled and not-configured are all "try again or call support", and
            // 503 is the one a browser and a load balancer both read the same way.
            default => 503,
        };
    }

    private function merchantMessage(LynomiaApiException $e): string
    {
        return match ($e->kind) {
            LynomiaApiException::KIND_DISABLED,
            LynomiaApiException::KIND_NOT_CONFIGURED => __('Lynomia Chat is not available yet. Please contact support.'),
            LynomiaApiException::KIND_CONFLICT => $e->getMessage(),
            LynomiaApiException::KIND_UNAUTHORIZED,
            LynomiaApiException::KIND_NOT_PERMISSIBLE => __('You do not have access to that conversation.'),
            LynomiaApiException::KIND_NOT_FOUND => __('That conversation no longer exists.'),
            LynomiaApiException::KIND_RATE_LIMITED,
            LynomiaApiException::KIND_UNREACHABLE => __('Lynomia Chat is temporarily unreachable. Please try again shortly.'),
            default => __('Lynomia Chat could not complete that request. Please contact support.'),
        };
    }

    /** @param array<string,mixed> $conversation */
    private function projectConversation(array $conversation): array
    {
        $meta = is_array($conversation['meta'] ?? null) ? $conversation['meta'] : [];
        $sender = is_array($meta['sender'] ?? null) ? $meta['sender'] : [];
        $assignee = is_array($meta['assignee'] ?? null) ? $meta['assignee'] : [];
        $last = is_array($conversation['last_non_activity_message'] ?? null) ? $conversation['last_non_activity_message'] : [];

        return [
            'id' => $conversation['id'] ?? null,
            'status' => $conversation['status'] ?? null,
            'unread_count' => $conversation['unread_count'] ?? 0,
            'last_activity_at' => $conversation['last_activity_at'] ?? null,
            'channel' => $meta['channel'] ?? null,
            'inbox_id' => $conversation['inbox_id'] ?? null,
            'contact' => [
                'id' => $sender['id'] ?? null,
                'name' => $sender['name'] ?? '',
                'thumbnail' => $sender['thumbnail'] ?? null,
            ],
            'assignee' => $assignee === [] ? null : [
                'id' => $assignee['id'] ?? null,
                'name' => $assignee['name'] ?? '',
            ],
            'preview' => $this->previewOf($last),
        ];
    }

    /** @param array<string,mixed> $message */
    private function projectMessage(array $message): array
    {
        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];

        return [
            'id' => $message['id'] ?? null,
            // 0 incoming, 1 outgoing, 2 activity, 3 template -- upstream's enum, passed through
            // as a number because the interface branches on it and a translated word would not.
            'message_type' => $message['message_type'] ?? null,
            'content' => $message['content'] ?? '',
            'private' => (bool) ($message['private'] ?? false),
            'created_at' => $message['created_at'] ?? null,
            'status' => $message['status'] ?? null,
            'sender' => [
                'name' => $message['sender']['name'] ?? null,
                'type' => $message['sender']['type'] ?? null,
            ],
            'attachments' => array_values(array_map(static fn (array $attachment): array => [
                'id' => $attachment['id'] ?? null,
                'file_type' => $attachment['file_type'] ?? null,
                'data_url' => $attachment['data_url'] ?? null,
            ], array_filter($attachments, 'is_array'))),
        ];
    }

    private function previewOf(array $message): string
    {
        $content = (string) ($message['content'] ?? '');

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $content) ?? ''), 0, 120);
    }

    /** Accept only a value from the list, or nothing. An unknown filter is dropped, not forwarded. */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
