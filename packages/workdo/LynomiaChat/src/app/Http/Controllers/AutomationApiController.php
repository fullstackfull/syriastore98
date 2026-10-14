<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Controllers\Concerns\ProxiesToLynomia;
use Workdo\LynomiaChat\app\Services\AgentApiClient;
use Workdo\LynomiaChat\app\Services\MerchantInboxGateway;

/**
 * The Automation Studio's only door to Lynomia.
 *
 * SAME DOOR, SAME RULES AS THE INBOX. Every route authenticates the Store Syria user, resolves
 * the store EnsureStoreOwnsLink already authorized, resolves that person's own Lynomia identity
 * and forwards only what they may do. No account_id, store_id or link id appears in any
 * signature. A flow id IS accepted and is deliberately not trusted: flow ids are global, so
 * another store's flow 7 is a perfectly real 7 -- what stops it resolving is that the
 * credential is scoped to one workspace server side, which no caller can influence.
 *
 * ONLY THE STORE OWNER GETS PAST LYNOMIA, and that is the design rather than a gap. Upstream
 * authorizes every flows action -- the listing included -- with AgentBotPolicy#update?, which
 * is `administrator?`; IdentityProvisioner gives the owner an administrator identity and their
 * staff an agent identity. A flow decides what every customer of the store is told,
 * automatically, with nobody reading it first, so that is the right boundary. The refusal is
 * surfaced as its own sentence rather than a generic 403, because "ask the store owner" is
 * something a staff member can act on and "forbidden" is not.
 *
 * WHAT COMES BACK IS NARROWED, as the inbox's is. Lynomia's flow payloads carry the bot's
 * `secret` and access token in other contexts and will carry more fields over time; each
 * response here is projected to what the Studio renders, so an upstream addition does not
 * silently become something this product exposes.
 *
 * THE TWO STRUCTURED REFUSALS PASS THROUGH, PROJECTED. A publish that fails validation answers
 * 422 with `errors: [{ code, node_id, edge_id, detail }]`, and those codes are the only thing
 * that lets a merchant fix the flow -- they are rendered as Arabic or English sentences by the
 * browser from the code, never from any text Lynomia sent. Everything else follows the rule
 * the inbox set: the provider's prose never crosses this boundary.
 */
class AutomationApiController extends Controller
{
    use ProxiesToLynomia;

    /** Lynomia's own ceiling on a flow name; longer is refused there, so it is refused here first. */
    private const NAME_MAX = 255;
    private const DESCRIPTION_MAX = 1000;

    public function __construct(private readonly MerchantInboxGateway $gateway)
    {
    }

    /**
     * The flow list, and the allowance it is counted against.
     *
     * USAGE COMES FROM LYNOMIA, NOT FROM COUNTING THE LIST. `billing/entitlements` reports
     * { used, limit } per resource through Billing::Entitlements -- the same resolver that
     * REFUSES the create. Counting the rows in this response would be a second answer to the
     * same question, and the one on the screen is the one that would drift from the one the
     * server enforces.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) {
            $payload = $client->listFlows()['payload'] ?? [];

            return [
                'flows' => array_values(array_map([$this, 'projectFlow'], is_array($payload) ? $payload : [])),
                'usage' => $this->projectUsage($client->entitlements()),
            ];
        });
    }

    public function show(Request $request, int $flow): JsonResponse
    {
        return $this->proxy($request, fn (AgentApiClient $client) => [
            'flow' => $this->projectFlow($client->getFlow($flow), detailed: true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:' . self::NAME_MAX],
            'description' => ['sometimes', 'nullable', 'string', 'max:' . self::DESCRIPTION_MAX],
        ]);

        return $this->proxy($request, function (AgentApiClient $client) use ($validated) {
            [$status, $body] = $client->createFlow((string) $validated['name'], $validated['description'] ?? null);

            return $status === 422
                ? $this->refused($client)
                : ['flow' => $this->projectFlow($body, detailed: true)];
        });
    }

    public function update(Request $request, int $flow): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:' . self::NAME_MAX],
            'description' => ['sometimes', 'nullable', 'string', 'max:' . self::DESCRIPTION_MAX],
        ]);

        if ($validated === []) {
            return response()->json(['error' => __('Nothing to change.')], 422);
        }

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'flow' => $this->projectFlow($client->updateFlow($flow, $validated)),
        ]);
    }

    public function duplicate(Request $request, int $flow): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:' . self::NAME_MAX],
        ]);

        return $this->proxy($request, function (AgentApiClient $client) use ($flow, $validated) {
            [$status, $body] = $client->duplicateFlow($flow, $validated['name'] ?? null);

            return $status === 422
                ? $this->refused($client)
                : ['flow' => $this->projectFlow($body, detailed: true)];
        });
    }

    /**
     * Deleting is refused upstream while the flow is published or has a live session, with
     * `errors: [{ code: 'flow_active' }]`. That is an instruction -- turn it off first -- not a
     * fault, so it is reported as its own code rather than as a failure.
     */
    public function destroy(Request $request, int $flow): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($flow) {
            [$status, $body] = $client->deleteFlow($flow);

            return $status === 422
                ? ['ok' => false, 'errors' => $this->projectGraphErrors($body)]
                : ['ok' => true];
        });
    }

    /**
     * Publishing is what makes a flow answer real customers, so the validation errors matter
     * more here than anywhere else in the Studio: they are per node, and the builder puts each
     * one next to the node it belongs to.
     */
    public function publish(Request $request, int $flow): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($flow) {
            [$status, $body] = $client->publishFlow($flow);

            return $status === 422
                ? ['ok' => false, 'errors' => $this->projectGraphErrors($body)]
                : ['ok' => true, 'flow' => $this->projectFlow($body)];
        });
    }

    public function disable(Request $request, int $flow): JsonResponse
    {
        return $this->proxy($request, fn (AgentApiClient $client) => [
            'ok' => true,
            'flow' => $this->projectFlow($client->disableFlow($flow)),
        ]);
    }

    // ------------------------------------------------------------------ projections

    /**
     * @param  bool  $detailed  the single-flow view also needs the per-inbox eligibility, which
     *   the list does not render and which costs an inbox read per flow upstream.
     */
    private function projectFlow(array $flow, bool $detailed = false): array
    {
        $projected = [
            'id' => $flow['id'] ?? null,
            'name' => $flow['name'] ?? '',
            'description' => $flow['description'] ?? null,
            // published | disabled | draft, decided by Lynomia from its archived versions.
            'state' => $flow['state'] ?? 'draft',
            'live_sessions' => (int) ($flow['live_sessions'] ?? 0),
            'published' => $this->projectVersion($flow['published'] ?? null),
            'draft' => $this->projectVersion($flow['draft'] ?? null),
            'inboxes' => array_values(array_map(static fn (array $inbox): array => [
                'id' => $inbox['id'] ?? null,
                'name' => $inbox['name'] ?? '',
                'channel_type' => $inbox['channel_type'] ?? null,
            ], array_filter(is_array($flow['inboxes'] ?? null) ? $flow['inboxes'] : [], 'is_array'))),
        ];

        if (! $detailed) {
            return $projected;
        }

        // WHAT THIS FLOW'S OWN INBOXES CAN ACTUALLY RUN. Carried through unchanged in meaning
        // because it is the honest-capability answer: a node type an inbox cannot execute is
        // shown as unavailable rather than offered and refused at publish.
        $projected['channel_eligibility'] = array_values(array_map(static fn (array $entry): array => [
            'id' => $entry['id'] ?? null,
            'name' => $entry['name'] ?? '',
            'channel_type' => $entry['channel_type'] ?? null,
            'runs_flows' => (bool) ($entry['runs_flows'] ?? false),
            'unsupported_nodes' => array_values(array_filter(
                is_array($entry['unsupported_nodes'] ?? null) ? $entry['unsupported_nodes'] : [],
                'is_string'
            )),
        ], array_filter(is_array($flow['channel_eligibility'] ?? null) ? $flow['channel_eligibility'] : [], 'is_array')));

        $projected['errors'] = $this->projectGraphErrors($flow);

        return $projected;
    }

    private function projectVersion(mixed $version): ?array
    {
        if (! is_array($version)) {
            return null;
        }

        return [
            'version' => $version['version'] ?? null,
            'status' => $version['status'] ?? null,
            'published_at' => $version['published_at'] ?? null,
            'updated_at' => $version['updated_at'] ?? null,
            // The optimistic-concurrency token. It travels back on the next draft save, which is
            // what stops two editors silently overwriting each other.
            'lock_version' => $version['lock_version'] ?? null,
        ];
    }

    /**
     * CODES AND IDS ONLY. `detail` is a bounded machine value in the upstream validator -- a
     * handle name, a node type, a numeric limit -- never a sentence, and it is cast to a short
     * string here so that it stays one. The browser renders each code in the merchant's own
     * language; nothing Lynomia wrote is ever displayed.
     */
    private function projectGraphErrors(array $body): array
    {
        $errors = $body['errors'] ?? [];

        if (! is_array($errors) || ! array_is_list($errors)) {
            return [];
        }

        return array_values(array_map(static fn (array $error): array => [
            'code' => (string) ($error['code'] ?? 'invalid'),
            'node_id' => isset($error['node_id']) ? mb_substr((string) $error['node_id'], 0, 64) : null,
            'edge_id' => isset($error['edge_id']) ? mb_substr((string) $error['edge_id'], 0, 64) : null,
            'detail' => isset($error['detail']) ? mb_substr((string) $error['detail'], 0, 64) : null,
        ], array_filter($errors, 'is_array')));
    }

    /**
     * A create or a duplicate that Lynomia refused with a 422.
     *
     * The only refusal a merchant can reach here is the flow ceiling, and the sentence Lynomia
     * sends with it is English -- so it is not forwarded. The allowance is re-read instead and
     * the browser is told the numbers, which it renders in the merchant's language. One extra
     * read, on the failure path only.
     */
    private function refused(AgentApiClient $client): array
    {
        return ['flow' => null, 'usage' => $this->projectUsage($client->entitlements())];
    }

    /**
     * Flows used against flows allowed. `limit: null` is unlimited and is rendered as such --
     * never as a zero, and never as -1.
     */
    private function projectUsage(array $entitlements): array
    {
        $flows = $entitlements['limits']['flows'] ?? [];

        return [
            'used' => (int) ($flows['used'] ?? 0),
            'limit' => isset($flows['limit']) && $flows['limit'] !== null ? (int) $flows['limit'] : null,
        ];
    }

    private function merchantMessage(LynomiaApiException $e): string
    {
        return match ($e->kind) {
            LynomiaApiException::KIND_DISABLED,
            LynomiaApiException::KIND_NOT_CONFIGURED => __('Lynomia Chat is not available yet. Please contact support.'),
            LynomiaApiException::KIND_CONFLICT => $e->getMessage(),
            // The one that is not generic. Lynomia allows only workspace administrators to manage
            // flows, and only the store owner holds that identity, so this is what a staff member
            // sees -- and it tells them who to ask instead of leaving them at "forbidden".
            LynomiaApiException::KIND_UNAUTHORIZED,
            LynomiaApiException::KIND_NOT_PERMISSIBLE => __('Only the store owner can manage automations.'),
            LynomiaApiException::KIND_NOT_FOUND => __('That automation no longer exists.'),
            LynomiaApiException::KIND_RATE_LIMITED,
            LynomiaApiException::KIND_UNREACHABLE => __('Lynomia Chat is temporarily unreachable. Please try again shortly.'),
            default => __('Lynomia Chat could not complete that request. Please contact support.'),
        };
    }
}
