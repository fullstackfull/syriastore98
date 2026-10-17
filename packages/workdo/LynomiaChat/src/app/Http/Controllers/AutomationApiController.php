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

    /**
     * Flows::GraphValidator's own ceilings, restated at this boundary for ONE reason: to refuse a body that is
     * obviously too large before it is forwarded, rather than paying a round trip to be told so. The authority
     * is still Lynomia's -- a graph that passes here is validated there in full, and nothing is accepted here
     * that would be refused there.
     */
    private const MAX_NODES = 300;
    private const MAX_EDGES = 900;
    private const MAX_INPUTS = 20;

    /** Automation rule shape limits: the engine's own MAX_CONDITIONS, and bounds this surface will carry. */
    private const MAX_CONDITIONS = 10;
    private const MAX_ACTIONS = 10;
    private const MAX_VALUES = 50;

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

    // ------------------------------------------------------------------ the builder

    /**
     * THE BUILDER'S WHOLE CONTRACT, in one response, because it is one upstream call.
     *
     * The graph, the node catalogue, each node type's field shapes, the channel limits, the variable
     * allow-list, the templates this flow may send, and the validation errors on what is there now. The Studio
     * renders its palette and every configuration form FROM these rather than from constants of its own --
     * which is the difference between a form that matches the server and one that drifts from it between
     * deploys and offers a setting that will be refused at publish.
     */
    public function builder(Request $request, int $flow): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($flow) {
            $body = $client->getFlow($flow);

            return [
                'flow' => $this->projectFlow($body, detailed: true),
                'graph' => $this->projectGraph($body['graph'] ?? []),
                'catalogue' => [
                    'node_types' => is_array($body['node_types'] ?? null) ? $body['node_types'] : [],
                    'node_fields' => is_array($body['node_fields'] ?? null) ? $body['node_fields'] : [],
                    'store_scopes' => array_values(array_filter(
                        is_array($body['store_scopes'] ?? null) ? $body['store_scopes'] : [], 'is_string'
                    )),
                    'capabilities' => is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [],
                    'variables' => array_values(array_filter(
                        is_array($body['variables'] ?? null) ? $body['variables'] : [], 'is_string'
                    )),
                    'templates' => is_array($body['templates'] ?? null) ? $body['templates'] : [],
                ],
            ];
        });
    }

    /**
     * The records a configuration form's pickers offer, and the vocabulary a condition is written in.
     *
     * SEPARATE FROM THE BUILDER PAYLOAD because it is four more upstream calls and it does not change while a
     * flow is being edited: the Studio fetches it once alongside the flow and reuses it for every node. The
     * agent roster deliberately carries no email -- a picker needs a name and an id, and configuring a flow is
     * not a reason to hand a merchant every colleague's address.
     */
    public function references(Request $request): JsonResponse
    {
        return $this->proxy($request, fn (AgentApiClient $client) => [
            'agents' => $this->projectNamed($client->listAgents(), 'name'),
            'teams' => $this->projectNamed($client->listTeams(), 'name'),
            'labels' => $this->projectNamed($client->listLabels()['payload'] ?? [], 'title'),
            'inboxes' => $this->projectNamed($client->listInboxes()['payload'] ?? [], 'name'),
            'contact_attributes' => $this->projectAttributes($client->listCustomAttributes('contact_attribute')),
            'conversation_attributes' => $this->projectAttributes($client->listCustomAttributes('conversation_attribute')),
            'vocabulary' => $this->projectVocabulary($client->automationVocabulary()),
        ]);
    }

    /**
     * Save the draft.
     *
     * `lock_version` IS REQUIRED, not optional-when-absent, because an optional precondition is not a
     * precondition: a caller that omits it is back to silently overwriting whoever saved first. A 409 carries
     * the other editor's graph and a fresh token so the Studio can show what changed and let the merchant
     * reapply, rather than making them reload and lose what they were typing.
     *
     * THE GRAPH IS NOT INSPECTED HERE. Validating it would be a second validator, kept in step with
     * Flows::GraphValidator by hand, and the one that drifted would be this one. What is checked is the shape
     * of the REQUEST -- a graph object with nodes and edges, within a size this surface will carry -- so a
     * malformed body is refused at the boundary instead of travelling to Lynomia to be refused there.
     *
     * `present` RATHER THAN `required` ON BOTH ARRAYS, for the reason ruleParams() uses it: Laravel's
     * `required` REFUSES AN EMPTY ARRAY. A flow that has no connections yet is the ordinary case -- a new
     * flow is one start step and nothing else, and a starter graph arrives before anything is wired -- so
     * `required` on `edges` refused the first save of almost every flow. `present` asks the question this
     * boundary is actually asking: did the caller send the key, and is it an array.
     */
    public function draft(Request $request, int $flow): JsonResponse
    {
        $validated = $request->validate([
            'graph' => ['required', 'array'],
            'graph.nodes' => ['present', 'array', 'max:' . self::MAX_NODES],
            'graph.edges' => ['present', 'array', 'max:' . self::MAX_EDGES],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->proxy($request, function (AgentApiClient $client) use ($flow, $validated) {
            [$status, $body] = $client->saveFlowDraft($flow, $validated['graph'], (int) $validated['lock_version']);

            if ($status === 409) {
                return ['ok' => false, 'conflict' => [
                    'graph' => $this->projectGraph($body['graph'] ?? []),
                    'lock_version' => (int) ($body['lock_version'] ?? 0),
                    'updated_at' => $body['updated_at'] ?? null,
                ]];
            }

            return $status === 422
                ? ['ok' => false, 'errors' => $this->projectGraphErrors($body)]
                : ['ok' => true, 'flow' => $this->projectFlow($body, detailed: true)];
        });
    }

    /**
     * Test Mode: run the DRAFT against the real runtime and show what a customer would see.
     *
     * The runtime leaves nothing behind -- no message, conversation, contact, session or job -- which is
     * asserted upstream rather than assumed here. That is what makes it safe to offer a merchant a button
     * that runs their unpublished flow.
     */
    public function simulate(Request $request, int $flow): JsonResponse
    {
        $validated = $request->validate([
            'inputs' => ['required', 'array', 'min:1', 'max:' . self::MAX_INPUTS],
            'inputs.*.text' => ['sometimes', 'string', 'min:1', 'max:1024'],
            'inputs.*.reply_id' => ['sometimes', 'nullable', 'string', 'max:256'],
            'inputs.*.timer' => ['sometimes', 'boolean'],
        ]);

        return $this->proxy($request, function (AgentApiClient $client) use ($flow, $validated) {
            $body = $client->simulateFlow($flow, array_values($validated['inputs']));

            return [
                'transcript' => array_values(array_map([$this, 'projectTranscript'],
                    array_filter(is_array($body['transcript'] ?? null) ? $body['transcript'] : [], 'is_array'))),
                'session' => [
                    'status' => $body['session']['status'] ?? null,
                    'current_node_id' => $body['session']['current_node_id'] ?? null,
                    'end_reason' => $body['session']['end_reason'] ?? null,
                    'failure_code' => $body['session']['failure_code'] ?? null,
                ],
                // Node ids and event names, never message bodies: the transcript above is where what a
                // customer would read belongs, and duplicating it into a trace is a second place to leak it.
                'trace' => array_values(array_map(static fn (array $step): array => [
                    'event' => $step['event'] ?? null,
                    'node_id' => $step['node_id'] ?? null,
                    'node_type' => $step['node_type'] ?? null,
                    'output' => $step['output'] ?? null,
                ], array_filter(is_array($body['trace'] ?? null) ? $body['trace'] : [], 'is_array'))),
            ];
        });
    }

    // ------------------------------------------------------------------ templates and history

    /**
     * Starting points, with whether this workspace may use each one.
     *
     * THE GRAPH TRAVELS WITH THE TEMPLATE so that creating from one is create-then-save with the
     * graph already in hand, rather than a third call to fetch it. It is projected through the
     * same graph shaper the builder uses, so a template and a saved flow are the same shape on
     * the way to the canvas.
     */
    public function starters(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) {
            $payload = $client->flowStarters()['payload'] ?? [];

            return ['starters' => array_values(array_map(fn (array $starter): array => [
                'id' => (string) ($starter['id'] ?? ''),
                'nodes' => (int) ($starter['nodes'] ?? 0),
                'available' => (bool) ($starter['available'] ?? false),
                // Feature names, which the browser turns into a sentence. Codes, not prose.
                'missing' => array_values(array_filter(
                    is_array($starter['missing'] ?? null) ? $starter['missing'] : [], 'is_string'
                )),
                'graph' => $this->projectGraph($starter['graph'] ?? []),
            ], array_filter(is_array($payload) ? $payload : [], 'is_array')))];
        });
    }

    /**
     * How a flow is running, and the last runs behind the numbers.
     *
     * TWO QUESTIONS IN ONE ANSWER because a merchant asks them together: "is it working" is the
     * counts, and "what happened" is the list. Both are already free of message content on the
     * Lynomia side, and the projection keeps it that way rather than relying on that -- an
     * operational screen that starts carrying what a customer wrote is a second place
     * conversation content can be read.
     */
    public function history(Request $request, int $flow): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($flow) {
            $insights = $client->flowInsights($flow);
            $sessions = $client->flowSessions($flow)['payload'] ?? [];

            return [
                'insights' => [
                    'since' => $insights['since'] ?? null,
                    'total' => (int) ($insights['total'] ?? 0),
                    'live' => (int) ($insights['live'] ?? 0),
                    'by_status' => $this->projectCounts($insights['by_status'] ?? null),
                    'by_failure' => $this->projectCounts($insights['by_failure'] ?? null),
                    'versions' => $this->projectCounts($insights['versions'] ?? null),
                ],
                'sessions' => array_values(array_map(static fn (array $session): array => [
                    'id' => $session['id'] ?? null,
                    'status' => $session['status'] ?? null,
                    'current_node_id' => $session['current_node_id'] ?? null,
                    'steps_count' => $session['steps_count'] ?? null,
                    // WHY IT STOPPED, which is the whole value of this screen: a merchant whose
                    // automation hands everything to a person needs the reason, not the count.
                    'failure_code' => $session['failure_code'] ?? null,
                    'end_reason' => $session['end_reason'] ?? null,
                    'version' => $session['version'] ?? null,
                    'created_at' => $session['created_at'] ?? null,
                    'finished_at' => $session['finished_at'] ?? null,
                    // Deliberately no conversation id: this is an operational screen, and a link
                    // from "a run failed" into a customer's conversation is the inbox's job and
                    // the inbox's authorization.
                ], array_filter(is_array($sessions) ? $sessions : [], 'is_array'))),
            ];
        });
    }

    /** A { key => count } map, with both halves made safe for display. */
    private function projectCounts(mixed $counts): array
    {
        if (! is_array($counts)) {
            return [];
        }

        $projected = [];
        foreach ($counts as $key => $count) {
            $projected[mb_substr((string) $key, 0, 64)] = (int) $count;
        }

        return $projected;
    }

    // ------------------------------------------------------------------ commerce automation

    /**
     * The store's automation RULES, and the vocabulary they are written in.
     *
     * A RULE IS NOT A FLOW. A flow answers a conversation the customer started; a rule reacts to
     * something that happened -- an order paid -- and takes an action. The Commerce triggers this
     * integration exists for are rules, which is why they live on their own surface rather than
     * as a node in the builder.
     *
     * THE VOCABULARY TRAVELS WITH THE LIST because the editor cannot be drawn without it, and
     * because what it carries is the honest part: each trigger says whether it can actually fire
     * for THIS store, and each action says whether a Commerce trigger would refuse it. A Store
     * Syria merchant is offered neither the cart trigger, whose provider is still PRE_UAT and
     * produces no abandoned carts, nor a free-form message action a Commerce rule cannot save.
     */
    public function rules(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) {
            $payload = $client->listAutomationRules()['payload'] ?? [];
            $vocabulary = $client->automationVocabulary();

            return [
                'rules' => array_values(array_map([$this, 'projectRule'],
                    array_filter(is_array($payload) ? $payload : [], 'is_array'))),
                'vocabulary' => $this->projectVocabulary($vocabulary) + [
                    'events' => $this->projectEvents($vocabulary),
                    'actions' => $this->projectActions($vocabulary),
                ],
            ];
        });
    }

    public function storeRule(Request $request): JsonResponse
    {
        $rule = $this->ruleParams($request);

        return $this->proxy($request, function (AgentApiClient $client) use ($rule) {
            [$status, $body] = $client->createAutomationRule($rule);

            return $this->ruleOutcome($status, $body);
        });
    }

    public function updateRule(Request $request, int $rule): JsonResponse
    {
        $payload = $this->ruleParams($request);

        return $this->proxy($request, function (AgentApiClient $client) use ($rule, $payload) {
            [$status, $body] = $client->updateAutomationRule($rule, $payload);

            return $this->ruleOutcome($status, $body);
        });
    }

    public function destroyRule(Request $request, int $rule): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($rule) {
            $client->deleteAutomationRule($rule);

            return ['ok' => true];
        });
    }

    /**
     * THE SHAPE OF A RULE IS CHECKED HERE; WHAT IT MEANS IS CHECKED UPSTREAM. A condition's
     * attribute, its operator and an action's parameters are validated by AutomationRule and its
     * Lynomia extensions against the account's own labels, teams, audiences and entitlements --
     * none of which this side can see. Re-deciding any of it here would be a second validator
     * kept in step by hand, and the one that drifted would be this one. So the boundary refuses
     * a malformed REQUEST and forwards a well-formed one to be judged where the rules live.
     */
    private function ruleParams(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:' . self::NAME_MAX],
            'description' => ['sometimes', 'nullable', 'string', 'max:' . self::DESCRIPTION_MAX],
            'event_name' => ['required', 'string', 'max:64'],
            'active' => ['sometimes', 'boolean'],
            'conditions' => ['present', 'array', 'max:' . self::MAX_CONDITIONS],
            'conditions.*.attribute_key' => ['required', 'string', 'max:64'],
            'conditions.*.filter_operator' => ['required', 'string', 'max:32'],
            'conditions.*.values' => ['present', 'array', 'max:' . self::MAX_VALUES],
            'conditions.*.query_operator' => ['sometimes', 'nullable', 'string', 'in:AND,OR'],
            'actions' => ['required', 'array', 'min:1', 'max:' . self::MAX_ACTIONS],
            'actions.*.action_name' => ['required', 'string', 'max:64'],
            'actions.*.action_params' => ['present', 'array', 'max:' . self::MAX_VALUES],
        ]);

        $validated['active'] = (bool) ($validated['active'] ?? false);

        return $validated;
    }

    /** A 422 from a rule carries its own errors keyed by attribute; they are codes enough to show. */
    private function ruleOutcome(int $status, array $body): array
    {
        if ($status !== 422) {
            return ['ok' => true, 'rule' => $this->projectRule($body['payload'] ?? $body)];
        }

        $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];

        return ['ok' => false, 'errors' => array_map(
            static fn ($messages) => array_values(array_map('strval', (array) $messages)),
            array_slice($errors, 0, 10, true)
        )];
    }

    private function projectRule(array $rule): array
    {
        return [
            'id' => $rule['id'] ?? null,
            'name' => (string) ($rule['name'] ?? ''),
            'description' => $rule['description'] ?? null,
            'event_name' => $rule['event_name'] ?? null,
            'active' => (bool) ($rule['active'] ?? false),
            // Conditions and actions are passed through as structure, for the reason node `data`
            // is: their keys are the vocabulary's own contract, and a list written here of which
            // may travel would silently drop a setting the day the vocabulary gains one.
            'conditions' => is_array($rule['conditions'] ?? null) ? array_values($rule['conditions']) : [],
            'actions' => is_array($rule['actions'] ?? null) ? array_values($rule['actions']) : [],
            'created_at' => $rule['created_at'] ?? null,
        ];
    }

    /** Each trigger with whether it can fire here; `reason` is a code the browser renders. */
    private function projectEvents(array $vocabulary): array
    {
        return array_values(array_map(static fn (array $event): array => [
            'name' => (string) ($event['name'] ?? ''),
            'available' => (bool) ($event['available'] ?? false),
            'reason' => $event['reason'] ?? null,
        ], array_filter(is_array($vocabulary['events'] ?? null) ? $vocabulary['events'] : [], 'is_array')));
    }

    /**
     * Only the actions the server DESCRIBES are forwarded. An action with no field schema is one
     * whose parameters are a file upload, a webhook or an email body -- each needing a surface
     * and an authorization story of its own -- and offering a form that happens to post the
     * right-shaped JSON is how a half-built action ships.
     */
    private function projectActions(array $vocabulary): array
    {
        $described = array_filter(is_array($vocabulary['actions'] ?? null) ? $vocabulary['actions'] : [],
            static fn ($action) => is_array($action) && isset($action['fields']));

        return array_values(array_map(static fn (array $action): array => [
            'name' => (string) ($action['name'] ?? ''),
            'commerce_safe' => (bool) ($action['commerce_safe'] ?? false),
            'fields' => array_values($action['fields']),
        ], $described));
    }

    // ------------------------------------------------------------------ consent

    /**
     * What shoppers have agreed to receive, and recording what one of them decided.
     *
     * READ AND WRITTEN THROUGH LYNOMIA, never out of this database, even though Store Syria has
     * its own customers table. The decision governs what LYNOMIA sends; a copy kept here would be
     * a second answer to "may we message this person", and the gate would keep reading the other
     * one. The same reason the inbox proxies a conversation's orders rather than reading them
     * locally.
     */
    public function consents(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($request) {
            $payload = $client->listConsents(array_filter([
                'purpose' => $this->oneOf($request->query('purpose'), ['transactional', 'promotional']),
                'state' => $this->oneOf($request->query('state'), ['granted', 'withdrawn', 'suppressed']),
            ], static fn ($value) => $value !== null))['payload'] ?? [];

            return ['decisions' => array_values(array_map(static fn (array $row): array => [
                'contact_id' => $row['contact_id'] ?? null,
                'contact_name' => (string) ($row['contact_name'] ?? ''),
                'purpose' => $row['purpose'] ?? null,
                'state' => $row['state'] ?? null,
                'source' => $row['source'] ?? null,
                'decided_at' => $row['decided_at'] ?? null,
            ], array_filter(is_array($payload) ? $payload : [], 'is_array')))];
        });
    }

    public function contactConsent(Request $request, int $contact): JsonResponse
    {
        return $this->proxy($request, fn (AgentApiClient $client) => [
            'consent' => $this->projectConsent($client->contactConsent($contact)),
        ]);
    }

    /**
     * A NOTE IS REQUIRED, and that is not red tape. A grant recorded by a merchant is a claim
     * about a conversation that happened somewhere else; without a note saying where, it is
     * indistinguishable from adding a person to a marketing list. Lynomia requires it too -- this
     * refuses it early so the merchant is told by the form rather than by a failed request.
     */
    public function recordConsent(Request $request, int $contact): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', 'string', 'in:transactional,promotional'],
            // `suppressed` is the provider's word, never a merchant's; it is not offered here.
            'state' => ['required', 'string', 'in:granted,withdrawn'],
            'note' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        return $this->proxy($request, fn (AgentApiClient $client) => [
            'consent' => $this->projectConsent($client->recordConsent(
                $contact, $validated['purpose'], $validated['state'], $validated['note']
            )),
        ]);
    }

    public function clearConsentSuppression(Request $request, int $contact): JsonResponse
    {
        return $this->proxy($request, fn (AgentApiClient $client) => [
            'consent' => $this->projectConsent($client->clearConsentSuppression($contact)),
        ]);
    }

    public function searchContacts(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return $this->proxy($request, function (AgentApiClient $client) use ($validated) {
            $payload = $client->searchContacts($validated['q'])['payload'] ?? [];

            return ['contacts' => array_values(array_map(static fn (array $contact): array => [
                'id' => $contact['id'] ?? null,
                'name' => (string) ($contact['name'] ?? ''),
                // Enough to tell two people with the same name apart, and no more.
                'identifier' => $contact['phone_number'] ?? $contact['email'] ?? null,
            ], array_slice(array_filter(is_array($payload) ? $payload : [], 'is_array'), 0, 20)))];
        });
    }

    private function projectConsent(array $body): array
    {
        $purposes = is_array($body['purposes'] ?? null) ? $body['purposes'] : [];

        return [
            'contact_id' => $body['contact_id'] ?? null,
            'contact_name' => (string) ($body['contact_name'] ?? ''),
            'purposes' => array_map(static fn ($entry): array => [
                'state' => is_array($entry) ? ($entry['state'] ?? null) : null,
                'source' => is_array($entry) ? ($entry['source'] ?? null) : null,
                'decided_at' => is_array($entry) ? ($entry['decided_at'] ?? null) : null,
                'note' => is_array($entry) ? ($entry['note'] ?? null) : null,
                // What would happen NOW, which a state alone cannot say: no row means allowed for
                // one class and refused for the other.
                'refusal' => is_array($entry) ? ($entry['refusal'] ?? null) : null,
            ], $purposes),
        ];
    }

    /** Accept only a value from the list, or nothing. An unknown filter is dropped, not forwarded. */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    // ------------------------------------------------------------------ channels

    /**
     * The channels this workspace has, what each can do, and what each is running.
     *
     * HONEST ABOUT WHAT A CHANNEL CANNOT DO. Flows run on WhatsApp Cloud and nowhere else today.
     * An inbox that cannot run one is listed and SAID to be unable, rather than hidden: a
     * merchant who cannot find their email inbox in this list learns nothing, and a merchant
     * told "this channel cannot run automations yet" knows where they stand. It is also why
     * connecting is offered only where it would work -- the server would accept the attachment,
     * and the publish would then refuse, which is a worse way to find out.
     */
    public function channels(Request $request): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) {
            $payload = $client->flowChannels()['payload'] ?? [];

            return ['channels' => array_values(array_map([$this, 'projectChannel'],
                array_filter(is_array($payload) ? $payload : [], 'is_array')))];
        });
    }

    /**
     * Connect this flow to a channel, or disconnect whatever is on one.
     *
     * ONE CHANNEL RUNS ONE AUTOMATION. Connecting to a channel that is already running another
     * replaces it -- Chatwoot's own rule, kept rather than reinvented -- and the Studio asks
     * before it does, because silently turning off a colleague's automation is not something a
     * confirmation dialog is optional for.
     *
     * THE FLOW IS RESOLVED BEFORE IT IS ATTACHED, and that is a real control rather than a
     * formality. Chatwoot's set_agent_bot accepts any bot `accessible_to` the account, which
     * INCLUDES installation-wide bots with no account of their own -- attach one and its token
     * reaches this merchant's conversations. Reading the flow first through /flows/:id, which is
     * scoped to this account's own flow bots, is what makes an id from outside answer 404 here
     * rather than being forwarded.
     */
    public function connectChannel(Request $request, int $flow): JsonResponse
    {
        $validated = $request->validate(['inbox_id' => ['required', 'integer', 'min:1']]);

        return $this->proxy($request, function (AgentApiClient $client) use ($flow, $validated) {
            $client->getFlow($flow);
            $client->setInboxFlow((int) $validated['inbox_id'], $flow);

            return ['ok' => true];
        });
    }

    public function disconnectChannel(Request $request, int $inbox): JsonResponse
    {
        return $this->proxy($request, function (AgentApiClient $client) use ($inbox) {
            $client->setInboxFlow($inbox, null);

            return ['ok' => true];
        });
    }

    private function projectChannel(array $channel): array
    {
        return [
            'id' => $channel['id'] ?? null,
            'name' => (string) ($channel['name'] ?? ''),
            'channel_type' => $channel['channel_type'] ?? null,
            'runs_flows' => (bool) ($channel['runs_flows'] ?? false),
            // Named only when it is one of this account's own automations; an installation-wide
            // bot is reported as "taken" and never identified.
            'flow' => is_array($channel['flow'] ?? null) ? [
                'id' => $channel['flow']['id'] ?? null,
                'name' => (string) ($channel['flow']['name'] ?? ''),
                'state' => $channel['flow']['state'] ?? 'draft',
            ] : null,
            'occupied_by_other' => (bool) ($channel['occupied_by_other'] ?? false),
        ];
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

    /**
     * THE GRAPH, SHAPED, NOT TRUSTED AND NOT INTERPRETED.
     *
     * Node `data` is passed through whole, deliberately: its keys are the node type's own contract and are
     * validated by Flows::NodeValidator, and a list written here of which keys may travel would be a second
     * contract that silently drops a setting the day a node type gains one. What IS enforced is the frame --
     * every node an object with an id, a type, a position and a data object; every edge four strings -- so a
     * malformed graph is a graph the canvas refuses to draw rather than one that throws inside the renderer.
     *
     * IDS AND TYPES ARE BOUNDED because they are drawn into the DOM as attribute values and compared as
     * strings. Lynomia's own ID pattern is at most 64 characters; anything longer is not a node this product
     * made.
     */
    private function projectGraph(mixed $graph): array
    {
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];

        return [
            'nodes' => array_values(array_map(static fn (array $node): array => [
                'id' => mb_substr((string) ($node['id'] ?? ''), 0, 64),
                'type' => mb_substr((string) ($node['type'] ?? ''), 0, 64),
                'position' => [
                    'x' => (float) ($node['position']['x'] ?? 0),
                    'y' => (float) ($node['position']['y'] ?? 0),
                ],
                'data' => is_array($node['data'] ?? null) ? $node['data'] : [],
            ], array_filter($nodes, 'is_array'))),
            'edges' => array_values(array_map(static fn (array $edge): array => [
                'id' => mb_substr((string) ($edge['id'] ?? ''), 0, 64),
                'source' => mb_substr((string) ($edge['source'] ?? ''), 0, 64),
                'sourceHandle' => mb_substr((string) ($edge['sourceHandle'] ?? ''), 0, 64),
                'target' => mb_substr((string) ($edge['target'] ?? ''), 0, 64),
            ], array_filter($edges, 'is_array'))),
        ];
    }

    /** An id and a display name, and nothing else: a picker needs no more and an email is not its business. */
    private function projectNamed(mixed $records, string $nameKey): array
    {
        return array_values(array_map(static fn (array $record): array => [
            'id' => $record['id'] ?? null,
            'name' => (string) ($record[$nameKey] ?? ''),
        ], array_filter(is_array($records) ? $records : [], 'is_array')));
    }

    private function projectAttributes(mixed $definitions): array
    {
        return array_values(array_map(static fn (array $definition): array => [
            'key' => $definition['attribute_key'] ?? null,
            'name' => (string) ($definition['attribute_display_name'] ?? $definition['attribute_key'] ?? ''),
            'type' => $definition['attribute_display_type'] ?? 'text',
            'values' => array_values(array_filter(
                is_array($definition['attribute_values'] ?? null) ? $definition['attribute_values'] : [],
                'is_string'
            )),
        ], array_filter(is_array($definitions) ? $definitions : [], 'is_array')));
    }

    /**
     * The condition vocabulary, carried through as structure. There is no human text in it by design -- the
     * Studio writes every label in the merchant's own language from the key -- so this narrows the keys rather
     * than filtering prose.
     */
    private function projectVocabulary(array $vocabulary): array
    {
        return [
            'conditions' => array_values(array_map(static fn (array $entry): array => [
                'key' => (string) ($entry['key'] ?? ''),
                'scope' => $entry['scope'] ?? null,
                'data_type' => $entry['data_type'] ?? 'text',
                'operators' => array_values(array_filter(
                    is_array($entry['operators'] ?? null) ? $entry['operators'] : [], 'is_string'
                )),
                'options_from' => $entry['options_from'] ?? null,
                'values' => is_array($entry['values'] ?? null) ? array_values($entry['values']) : null,
                'available' => $entry['available'] ?? true,
                'custom_attribute' => (bool) ($entry['custom_attribute'] ?? false),
            ], array_filter(is_array($vocabulary['conditions'] ?? null) ? $vocabulary['conditions'] : [], 'is_array'))),
            'condition_patterns' => array_values(array_filter(
                is_array($vocabulary['condition_patterns'] ?? null) ? $vocabulary['condition_patterns'] : [], 'is_array'
            )),
        ];
    }

    /**
     * One turn of a Test Mode run. `items` are the choices a Buttons or List node offered, which is what makes
     * the transcript clickable rather than something to read and guess at.
     */
    private function projectTranscript(array $entry): array
    {
        return [
            'from' => $entry['from'] ?? null,
            'text' => (string) ($entry['text'] ?? ''),
            'items' => array_values(array_map(static fn (array $item): array => [
                'value' => $item['value'] ?? null,
                'title' => (string) ($item['title'] ?? ''),
            ], array_filter(is_array($entry['items'] ?? null) ? $entry['items'] : [], 'is_array'))),
        ];
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
