<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Controllers\Concerns\ProxiesToLynomia;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AgentApiClient;
use Workdo\LynomiaChat\app\Services\MerchantInboxGateway;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * THE MERCHANT'S AUDIENCES, BUILT HERE AND EVALUATED THERE.
 *
 * AN AUDIENCE IS A QUERY, NOT A LIST, and that is why none of it is stored on this side. It is
 * evaluated by Lynomia against Lynomia's contacts and Lynomia's own commerce summaries at the
 * moment a campaign sends. A copy of the conditions in this database would be a second
 * definition the sender never reads, and the first time the two drifted the merchant would be
 * shown one audience and send to another. The same reason the inbox proxies a conversation's
 * orders instead of reading them locally.
 *
 * STORE SCOPE IS APPLIED TO THE COUNT, NOT TO THE AUDIENCE. The audience itself is
 * account-wide and may be reused by any campaign; what narrows it to this installation is WHO
 * IS ASKING. So the preview passes this store's id and Lynomia's aggregates answer "orders
 * with us" -- the same question a campaign for this store answers when it sends. Previewing
 * and sending have to be the same query or the preview is decoration. See
 * Audience::CommerceCondition upstream.
 *
 * THE CONDITIONS ARE VALIDATED UPSTREAM, DELIBERATELY. Lynomia's filter service owns which
 * keys exist, which operators each takes and what a value may be, and it answers 422 with the
 * offending attribute. Restating that here would be a second rulebook to keep in step, and the
 * copy that fell behind would be the one refusing a condition the engine supports. What IS
 * checked here is shape and size -- the things a proxy owes its upstream.
 *
 * TENANCY AND AUTHORIZATION COME FROM THE ROUTE. EnsureStoreOwnsLink resolves the store from
 * the session before any method here runs, the credential is minted for this workspace only,
 * and no path carries an id that names a workspace. Sharing an audience is an administrator
 * act upstream, so a staff member who reaches this is refused there -- which is why the
 * refusal says who to ask rather than rendering a bare 403.
 */
class AudienceApiController extends Controller
{
    use ProxiesToLynomia;

    /** One audience's conditions. Bounded because each is a subquery upstream. */
    private const MAX_CONDITIONS = 10;

    private const MAX_VALUES = 50;

    public function __construct(private readonly MerchantInboxGateway $gateway)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) {
            $payload = $client->listAudiences()['payload'] ?? $client->listAudiences();

            return ['audiences' => $this->project(is_array($payload) ? $payload : [])];
        });
    }

    /** What the commerce conditions can be built from, and this store's own id for scoping. */
    public function references(Request $request): JsonResponse
    {
        $storeId = (int) $request->attributes->get(EnsureStoreOwnsLink::STORE_ATTRIBUTE);

        return $this->proxy($request, function (AgentApiClient $client) use ($storeId) {
            $fields = $client->audienceFields();

            return [
                'stores' => is_array($fields['stores'] ?? null) ? $fields['stores'] : [],
                'currencies' => is_array($fields['currencies'] ?? null) ? $fields['currencies'] : [],
                // Contacts linked to a store whose orders Lynomia has not read yet. Their
                // order conditions are UNKNOWN, never zero, so an audience built on "fewer
                // than two orders" cannot answer for them -- which is a fact the merchant
                // needs before they wonder why a number looks small.
                'unread_contacts' => (int) ($fields['unread_contacts'] ?? 0),
                // Which of those stores is this one. The page scopes its counts to it.
                'this_store' => $this->ownStoreId($client, $storeId, $fields),
            ];
        });
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'audience' => $this->projectOne($client->createAudience($validated['name'], ['payload' => $validated['payload']])),
        ]);
    }

    public function update(Request $request, int $audience): JsonResponse
    {
        $validated = $this->validatePayload($request);

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'audience' => $this->projectOne($client->updateAudience($audience, [
                'name' => $validated['name'],
                'query' => ['payload' => $validated['payload']],
            ])),
        ]);
    }

    /**
     * Upstream refuses to delete an audience a campaign or an automation rule still uses and
     * says which, so that refusal is surfaced rather than pre-checked here -- a pre-check
     * would be a second answer that can be stale by the time the delete lands.
     */
    public function destroy(Request $request, int $audience): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($audience) {
            $client->deleteAudience($audience);

            return ['deleted' => true];
        });
    }

    /**
     * How many contacts this audience reaches from THIS store, now.
     *
     * Scoped to this installation, because that is the question a merchant is asking and the
     * one their campaign will answer. An unscoped count would be larger and unreachable.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audience_id' => ['required', 'integer', 'min:1'],
        ]);
        $storeId = (int) $request->attributes->get(EnsureStoreOwnsLink::STORE_ATTRIBUTE);

        return $this->proxy($request, function (AgentApiClient $client) use ($validated, $storeId) {
            $scoped = $client->previewAudience(
                [['type' => 'Audience', 'id' => $validated['audience_id']]],
                $this->ownStoreId($client, $storeId)
            );
            $everywhere = $client->previewAudience([['type' => 'Audience', 'id' => $validated['audience_id']]]);

            return [
                'count' => (int) ($scoped['count'] ?? 0),
                // BOTH NUMBERS, because the difference is the point. "40 of your 180 customers"
                // tells a merchant their audience is mostly other stores' customers; the
                // scoped number alone just looks disappointing.
                'count_all_stores' => (int) ($everywhere['count'] ?? 0),
            ];
        });
    }

    // ============================================================ helpers

    /**
     * @return array{name: string, payload: array}
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'payload' => ['required', 'array', 'min:1', 'max:' . self::MAX_CONDITIONS],
            'payload.*.attribute_key' => ['required', 'string', 'max:100'],
            'payload.*.filter_operator' => ['required', 'string', 'max:40'],
            // `present` rather than `required`: an is_present condition legitimately carries
            // an empty list, and `required` refuses an empty array. The same mistake this
            // project has already made once on a graph's edges.
            'payload.*.values' => ['present', 'array', 'max:' . self::MAX_VALUES],
            'payload.*.query_operator' => ['nullable', 'string', 'in:and,or,AND,OR'],
        ]);
    }

    /**
     * This store's Lynomia-side commerce store id.
     *
     * NOT THE STORE SYRIA STORE ID. Lynomia knows this installation as a `commerce_stores` row
     * whose `external_store_id` is the Store Syria id; the audience scope names the Lynomia
     * row. Matched on provider AND external id, so a merchant who also connected a Shopify
     * store does not have their Store Syria audience counted against it.
     */
    private function ownStoreId(AgentApiClient $client, int $storeId, ?array $fields = null): ?int
    {
        $fields ??= $client->audienceFields();
        $stores = is_array($fields['stores'] ?? null) ? $fields['stores'] : [];

        foreach ($stores as $store) {
            if (($store['provider'] ?? null) === 'storesyria' && (string) ($store['external_store_id'] ?? '') === StoreIdentity::of($storeId)) {
                return (int) $store['id'];
            }
        }

        return null;
    }

    /** @param array<int, mixed> $rows */
    private function project(array $rows): array
    {
        return array_values(array_map(fn (array $row): array => $this->projectOne($row), array_filter($rows, 'is_array')));
    }

    private function projectOne(array $row): array
    {
        $query = is_array($row['query'] ?? null) ? $row['query'] : [];

        return [
            'id' => $row['id'] ?? null,
            'name' => (string) ($row['name'] ?? ''),
            'shared' => (bool) ($row['shared'] ?? false),
            'payload' => is_array($query['payload'] ?? null) ? $query['payload'] : [],
        ];
    }

    /**
     * The sentences this surface owns. Deliberately not shared with the inbox or the Studio:
     * the same `kind` means different advice here.
     */
    private function merchantMessage(LynomiaApiException $e): string
    {
        return match ($e->kind) {
            LynomiaApiException::KIND_DISABLED,
            LynomiaApiException::KIND_NOT_CONFIGURED => __('Lynomia Chat is not available yet. Please contact support.'),
            // Upstream answers 422 with the attribute it refused -- "which field and why" is
            // exactly what a merchant editing conditions needs, and it is safe to pass on
            // because it names a filter key, never anything about another tenant.
            LynomiaApiException::KIND_CONFLICT => $e->getMessage(),
            // Sharing an audience is an administrator act upstream and only the store owner
            // holds that identity, so this is what a staff member sees. It says who to ask
            // instead of leaving them at "forbidden".
            LynomiaApiException::KIND_UNAUTHORIZED,
            LynomiaApiException::KIND_NOT_PERMISSIBLE => __('Only the store owner can manage audiences.'),
            LynomiaApiException::KIND_NOT_FOUND => __('That audience no longer exists.'),
            LynomiaApiException::KIND_RATE_LIMITED,
            LynomiaApiException::KIND_UNREACHABLE => __('Lynomia Chat is temporarily unreachable. Please try again shortly.'),
            default => __('Lynomia Chat could not complete that request. Please contact support.'),
        };
    }
}
