<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;

/**
 * The native visual Flow Builder: its contract, its concurrency protection and its walls.
 *
 * WHAT THESE ARE FOR. The builder is where a merchant decides what every future customer of the
 * store is told, with nobody reading it first. Three things therefore have to hold and are
 * asserted here rather than assumed:
 *
 *   the catalogue is CARRIED, not invented   the palette, every configuration form and every
 *                                            limit come from Lynomia. A field this product made
 *                                            up is a field the server rejects at publish
 *   the optimistic lock survives the proxy   a 409 must reach the browser WITH the other
 *                                            editor's graph and a fresh token, or the protection
 *                                            added in Phase 4A is silently undone by the BFF
 *   nothing leaks                            a flow bot's secret, an inbox's provider config and
 *                                            the scoped credential never cross this boundary
 *
 * NOTHING HERE REACHES THE NETWORK -- see InteractsWithLynomia.
 */
class LynomiaFlowBuilderTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const BUILDER = '/lynomia/automation/api/flows/42/builder';
    private const DRAFT = '/lynomia/automation/api/flows/42/draft';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    private function connectedTenant(): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store];
    }

    /** A graph the boundary will accept: the shape check here is structural, not semantic. */
    private function graph(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'hello', 'type' => 'send_message', 'position' => ['x' => 0, 'y' => 160],
                    'data' => ['text' => 'Hello']],
            ],
            'edges' => [['id' => 'e1', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'hello']],
        ];
    }

    // ================================================================ the walls

    public function test_every_builder_route_carries_the_same_stack_as_the_studio(): void
    {
        foreach (['lynomia.automation.builder', 'lynomia.automation.api.flows.builder',
            'lynomia.automation.api.flows.draft', 'lynomia.automation.api.flows.simulate',
            'lynomia.automation.api.references'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, $name);
            $this->assertContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $stack, $name);
            $this->assertTrue((bool) preg_grep('/PlanModuleCheck:LynomiaChat/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Automation/', $stack), $name);
        }
    }

    public function test_anonymous_and_unpermitted_callers_reach_no_builder_route(): void
    {
        // The tenant is built first so the scriptable fake is registered: a bare Http::fake()
        // before it answers every call with an empty body, which is a test artefact rather than
        // anything this surface does.
        [$owner] = $this->connectedTenant();

        $this->getJson(self::BUILDER)->assertStatus(401);
        $this->putJson(self::DRAFT, ['graph' => $this->graph(), 'lock_version' => 0])->assertStatus(401);

        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat']);
        Http::fake();

        $this->actingAs($staff->fresh())->getJson(self::BUILDER)->assertForbidden();
        $this->actingAs($staff->fresh())->get('/lynomia/automation/42/builder')->assertForbidden();

        Http::assertNothingSent();
    }

    // ================================================================ the contract

    public function test_the_builder_payload_carries_the_catalogue_the_server_serves(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson(self::BUILDER);

        $response->assertOk();
        // The node types, their field shapes and the limits are Lynomia's, passed through.
        $response->assertJsonPath('catalogue.node_types.send_message.outputs.0', 'next');
        $response->assertJsonPath('catalogue.node_fields.send_message.0.key', 'text');
        $response->assertJsonPath('catalogue.node_fields.send_message.0.max', 4096);
        $response->assertJsonPath('catalogue.capabilities.buttons.max', 3);
        $response->assertJsonPath('catalogue.variables.0', 'contact.name');
        $response->assertJsonPath('catalogue.store_scopes.2', 'context');
        // The templates a Send template node may name, with the values each takes.
        $response->assertJsonPath('catalogue.templates.0.name', 'spring_offer');
        $response->assertJsonPath('catalogue.templates.0.slots.0.key', '1');
    }

    public function test_the_graph_arrives_shaped_with_its_node_data_intact(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson(self::BUILDER);

        $response->assertJsonPath('graph.nodes.1.type', 'send_message');
        // `data` is passed through whole on purpose: its keys are the node type's own contract,
        // and a list of permitted keys written here would silently drop a setting the day a node
        // type gained one.
        $response->assertJsonPath('graph.nodes.1.data.text', 'Hi {{contact.name}}');
        $response->assertJsonPath('graph.edges.0.sourceHandle', 'next');
        // Positions are numbers after projection, whatever the wire said. JSON has one number
        // type, so what comes back is 160 rather than 160.0 -- what matters is that a string
        // position would have been cast, not which PHP type the decode chose.
        $this->assertIsNumeric($response->json('graph.nodes.1.position.y'));
        $this->assertEquals(160, $response->json('graph.nodes.1.position.y'));
    }

    public function test_the_references_a_form_binds_to_carry_no_channel_credential_and_no_agent_email(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson('/lynomia/automation/api/references');

        $response->assertOk();
        $response->assertJsonPath('labels.0.name', 'vip');
        $response->assertJsonPath('teams.0.name', 'Support');
        $response->assertJsonPath('agents.0.name', 'Agent');
        $response->assertJsonPath('contact_attributes.0.key', 'loyalty_tier');
        $response->assertJsonPath('contact_attributes.0.values.0', 'gold');
        $response->assertJsonPath('vocabulary.conditions.0.key', 'status');
        $response->assertJsonPath('vocabulary.conditions.1.options_from', 'agents');
        // Present but refused, so a merchant sees why rather than wondering where it went.
        $response->assertJsonPath('vocabulary.conditions.2.available', false);

        $body = $response->getContent();
        $this->assertStringNotContainsString('INBOX-SECRET-MUST-NOT-LEAK', $body);
        $this->assertStringNotContainsString('agent@example.test', $body);
        $this->assertStringNotContainsString('+963900000000', $body);
        $this->assertStringNotContainsString('SCOPED-TOKEN', $body);
    }

    // ================================================================ saving

    public function test_saving_a_draft_forwards_the_graph_and_the_lock_and_returns_the_next_token(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->putJson(self::DRAFT, ['graph' => $this->graph(), 'lock_version' => 7]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('flow.draft.lock_version', 8);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return true;
            }

            return ($request->data()['lock_version'] ?? null) === 7
                && ($request->data()['graph']['nodes'][1]['data']['text'] ?? null) === 'Hello';
        });
    }

    /**
     * THE PROTECTION ADDED IN PHASE 4A, SURVIVING THE PROXY. A 409 that reached the browser as a
     * bare "conflict" would be the same data loss the lock was added to prevent, one layer out:
     * the merchant would have to reload and lose what they were drawing. The other editor's graph
     * and a fresh token have to come through.
     */
    public function test_a_draft_that_lost_the_race_returns_the_other_editors_graph_and_a_fresh_token(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['fail' => [
            'PUT /api/v1/accounts/7001/flows/42/draft' => Http::response([
                'error' => 'flow_draft_stale',
                'graph' => ['nodes' => [['id' => 'start', 'type' => 'start', 'position' => ['x' => 5, 'y' => 5],
                    'data' => []]], 'edges' => []],
                'lock_version' => 12,
                'updated_at' => '2026-02-03T08:00:00Z',
            ], 409),
        ]]);

        $response = $this->actingAs($owner)->putJson(self::DRAFT, ['graph' => $this->graph(), 'lock_version' => 3]);

        $response->assertOk();
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('conflict.lock_version', 12);
        $response->assertJsonPath('conflict.graph.nodes.0.id', 'start');
        $response->assertJsonPath('conflict.updated_at', '2026-02-03T08:00:00Z');
    }

    public function test_a_draft_refused_for_its_shape_returns_the_codes_and_node_ids(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['fail' => [
            'PUT /api/v1/accounts/7001/flows/42/draft' => Http::response([
                'errors' => [['code' => 'unknown_node_type', 'node_id' => 'odd', 'detail' => 'eval']],
            ], 422),
        ]]);

        $response = $this->actingAs($owner)->putJson(self::DRAFT, ['graph' => $this->graph(), 'lock_version' => 1]);

        $response->assertOk();
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('errors.0.code', 'unknown_node_type');
        $response->assertJsonPath('errors.0.node_id', 'odd');
    }

    /**
     * `lock_version` IS REQUIRED. An optional precondition is not a precondition: any caller that
     * omits it is back to silently overwriting whoever saved first, which is the defect the lock
     * was added for. Refused at this boundary rather than discovered upstream.
     */
    public function test_a_save_without_a_lock_version_or_a_graph_never_reaches_lynomia(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->putJson(self::DRAFT, ['graph' => $this->graph()])->assertStatus(422);
        $this->actingAs($owner)->putJson(self::DRAFT, ['lock_version' => 0])->assertStatus(422);
        $this->actingAs($owner)->putJson(self::DRAFT, [
            'graph' => ['nodes' => 'not-a-list', 'edges' => []], 'lock_version' => 0,
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_graph_past_the_engines_own_ceiling_is_refused_before_it_is_forwarded(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $nodes = array_fill(0, 301, ['id' => 'n', 'type' => 'end', 'position' => ['x' => 0, 'y' => 0], 'data' => []]);

        $this->actingAs($owner)->putJson(self::DRAFT, ['graph' => ['nodes' => $nodes, 'edges' => []], 'lock_version' => 0])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * A FLOW WITH NO CONNECTIONS IS THE ORDINARY FIRST SAVE, NOT A MALFORMED REQUEST.
     *
     * A brand new flow is one start step and nothing else; a merchant who drags a step before
     * wiring it has the same graph. Laravel's `required` refuses an empty array, so the rule
     * that looked like "send me the edges" actually meant "send me at least one edge" -- and
     * every one of those saves 422'd at this boundary without reaching Lynomia, which is where
     * a graph is supposed to be judged. The builder funnels Save, Test and Publish through this
     * one call, so it took all three down together.
     */
    public function test_a_graph_with_no_connections_yet_is_forwarded_rather_than_refused(): void
    {
        [$owner] = $this->connectedTenant();

        $graph = ['nodes' => [['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []]],
                  'edges' => []];

        $this->actingAs($owner)->putJson(self::DRAFT, ['graph' => $graph, 'lock_version' => 0])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/flows/42/draft')
                && $request['graph']['edges'] === []
                && count($request['graph']['nodes']) === 1;
        });
    }

    /** The key still has to BE there, and still has to be an array -- `present` is not `nullable`. */
    public function test_a_graph_missing_its_edges_key_entirely_is_still_refused(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $nodes = [['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []]];

        $this->actingAs($owner)->putJson(self::DRAFT, ['graph' => ['nodes' => $nodes], 'lock_version' => 0])
            ->assertStatus(422);
        $this->actingAs($owner)->putJson(self::DRAFT, [
            'graph' => ['nodes' => $nodes, 'edges' => 'not-a-list'], 'lock_version' => 0,
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    // ================================================================ test mode

    public function test_test_mode_returns_the_transcript_and_the_clickable_choices(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->postJson('/lynomia/automation/api/flows/42/simulate', [
            'inputs' => [['text' => 'hi'], ['text' => 'Track order', 'reply_id' => 'lfb:menu:track']],
        ]);

        $response->assertOk();
        $response->assertJsonPath('transcript.0.text', 'hi');
        $response->assertJsonPath('transcript.1.items.0.value', 'lfb:menu:track');
        $response->assertJsonPath('session.status', 'active');
        $response->assertJsonPath('trace.0.node_id', 'menu');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/simulate')) {
                return true;
            }

            return count($request->data()['inputs']) === 2;
        });
    }

    public function test_an_empty_or_oversized_test_run_never_reaches_lynomia(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->postJson('/lynomia/automation/api/flows/42/simulate', ['inputs' => []])
            ->assertStatus(422);
        $this->actingAs($owner)->postJson('/lynomia/automation/api/flows/42/simulate', [
            'inputs' => array_fill(0, 21, ['text' => 'x']),
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    // ================================================================ channels

    public function test_the_channel_list_says_what_each_channel_can_do_and_what_it_runs(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson('/lynomia/automation/api/channels');

        $response->assertOk();
        $response->assertJsonPath('channels.0.runs_flows', true);
        $response->assertJsonPath('channels.0.flow.name', 'Welcome menu');
        // Listed and SAID to be unable, rather than hidden: a merchant who cannot find their
        // channel here learns nothing.
        $response->assertJsonPath('channels.1.runs_flows', false);
        $this->assertNull($response->json('channels.1.flow'));
        // Taken by something with no account of its own -- warned about, never named.
        $response->assertJsonPath('channels.2.occupied_by_other', true);
        $this->assertNull($response->json('channels.2.flow'));
    }

    /**
     * THE FLOW IS RESOLVED BEFORE IT IS ATTACHED, and that is the control rather than a
     * formality: Chatwoot's set_agent_bot accepts any bot `accessible_to` the account, which
     * includes installation-wide bots with no account at all -- attach one and its token reaches
     * this merchant's conversations. Reading the flow first, through an endpoint scoped to this
     * account's own flow bots, is what stops an id from outside being forwarded.
     */
    public function test_connecting_resolves_the_flow_first_and_then_sets_it_on_the_inbox(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->postJson('/lynomia/automation/api/flows/42/channels', ['inbox_id' => 9])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $calls = [];
        Http::assertSent(function ($request) use (&$calls) {
            $calls[] = $request->method() . ' ' . parse_url($request->url(), PHP_URL_PATH);

            return true;
        });

        $flowRead = array_search('GET /api/v1/accounts/7001/flows/42', $calls, true);
        $attach = array_search('POST /api/v1/accounts/7001/inboxes/9/set_agent_bot', $calls, true);
        $this->assertNotFalse($flowRead, 'the flow was never resolved');
        $this->assertNotFalse($attach, 'the inbox was never set');
        $this->assertLessThan($attach, $flowRead, 'the inbox was set before the flow was resolved');
    }

    public function test_a_flow_this_account_does_not_own_is_never_attached_to_an_inbox(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['fail' => [
            'GET /api/v1/accounts/7001/flows/999' => Http::response(['error' => 'not found'], 404),
        ]]);

        $this->actingAs($owner)->postJson('/lynomia/automation/api/flows/999/channels', ['inbox_id' => 9])
            ->assertStatus(404);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'set_agent_bot'));
    }

    public function test_disconnecting_clears_the_channel(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->deleteJson('/lynomia/automation/api/channels/9')
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'set_agent_bot')) {
                return true;
            }

            // An explicit null, not an omitted key: upstream reads an absent agent_bot as
            // "disconnect" and both have to mean the same thing here.
            return array_key_exists('agent_bot', $request->data()) && $request->data()['agent_bot'] === null;
        });
    }

    public function test_connecting_without_an_inbox_never_reaches_lynomia(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->postJson('/lynomia/automation/api/flows/42/channels', [])->assertStatus(422);
        $this->actingAs($owner)->postJson('/lynomia/automation/api/flows/42/channels', ['inbox_id' => 'nine'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_the_channel_routes_carry_the_same_stack_as_the_rest_of_the_studio(): void
    {
        foreach (['lynomia.automation.api.channels', 'lynomia.automation.api.flows.channels.connect',
            'lynomia.automation.api.channels.disconnect'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Automation/', $stack), $name);
        }
    }

    // ================================================================ templates and history

    public function test_the_starter_list_carries_its_graph_and_says_what_each_one_needs(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson('/lynomia/automation/api/starters');

        $response->assertOk();
        $response->assertJsonPath('starters.0.id', 'welcome_menu');
        $response->assertJsonPath('starters.0.available', true);
        // The graph travels so creating from a template is create-then-save, not a third call,
        // and it arrives in the same shape the builder's own graph does.
        $response->assertJsonPath('starters.0.graph.nodes.0.type', 'start');
        // Listed and explained rather than hidden.
        $response->assertJsonPath('starters.1.available', false);
        $response->assertJsonPath('starters.1.missing.0', 'lynomia_commerce');
    }

    /**
     * "Is it working" and "what happened" are different questions a merchant asks together, so
     * one call answers both. Neither half may carry what a customer wrote.
     */
    public function test_the_history_answers_both_how_it_is_running_and_what_happened(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson('/lynomia/automation/api/flows/42/history');

        $response->assertOk();
        $response->assertJsonPath('insights.total', 7);
        $response->assertJsonPath('insights.live', 1);
        $response->assertJsonPath('insights.by_status.failed', 3);
        $response->assertJsonPath('insights.by_failure.step_limit', 2);
        // Why a run stopped is the whole value of the screen.
        $response->assertJsonPath('sessions.0.failure_code', 'step_limit');
        $response->assertJsonPath('sessions.0.current_node_id', 'menu');
    }

    /**
     * NO CUSTOMER AND NO CONVERSATION. An operational screen that carries a conversation id is a
     * link from "a run failed" into somebody's messages, and that link belongs to the inbox,
     * with the inbox's own authorization.
     */
    public function test_the_history_carries_no_conversation_and_no_customer(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['sessions' => [
            ['id' => 1, 'status' => 'handed_off', 'current_node_id' => 'menu', 'steps_count' => 3,
                'failure_code' => null, 'end_reason' => 'no_choice', 'version' => 1,
                'created_at' => '2026-02-01T10:00:00Z', 'conversation_id' => 41,
                'context' => ['reply' => 'my secret address']],
        ]]);

        $body = $this->actingAs($owner)->getJson('/lynomia/automation/api/flows/42/history')->getContent();

        $this->assertStringNotContainsString('my secret address', $body);
        $this->assertStringNotContainsString('conversation_id', $body);
        $this->assertStringContainsString('no_choice', $body);
    }

    public function test_the_template_and_history_routes_carry_the_studio_stack(): void
    {
        foreach (['lynomia.automation.api.starters', 'lynomia.automation.api.flows.history'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Automation/', $stack), $name);
        }
    }

    // ================================================================ the page

    public function test_the_builder_page_is_a_shell_that_makes_no_lynomia_call_of_its_own(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia();

        $response = $this->actingAs($owner)->get('/lynomia/automation/42/builder');

        $response->assertOk();
        $response->assertSee('lynb-root', false);
        $response->assertSee('lynomia/automation/api', false);
        Http::assertNothingSent();
        $response->assertDontSee('SCOPED-TOKEN', false);
        // No flow content is rendered server-side: a slow Lynomia must not stop the page loading.
        $response->assertDontSee('Welcome menu', false);
    }

    /**
     * THE CANVAS IS BUILT HERE AND ITS DEPENDENCIES MUST STAY NONE. The repository has no working
     * JS build -- resources/js/app.js imports laravel-echo and pusher-js, which are in neither
     * package.json nor node_modules -- so a builder that pulled in a graph library would be a
     * builder that does not load. This fails the moment somebody adds one.
     */
    public function test_the_builder_pulls_in_no_javascript_library(): void
    {
        $raw = file_get_contents(base_path(
            'packages/workdo/LynomiaChat/src/resources/views/merchant/builder.blade.php'
        ));

        // COMMENT-STRIPPED, because the file EXPLAINS at length which libraries it deliberately
        // does not use, and a scanner that counted those sentences would be a scanner that can
        // never pass. What is asserted is what the browser runs.
        $code = preg_replace(['/\{\{--.*?--\}\}/s', '~^\s*//.*$~m'], '', $raw);

        $this->assertStringNotContainsString('@vite', $code);
        foreach (['cdn.jsdelivr', 'unpkg.com', 'cdnjs.', 'vue-flow', 'reactflow', 'jsplumb', 'drawflow',
            'cytoscape', 'litegraph', 'dagre', 'd3.min', 'mermaid', '<script src'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($code), "the builder now depends on {$forbidden}");
        }

        // And it draws with the platform's own SVG rather than injecting markup for merchant text.
        $this->assertStringContainsString('createElementNS', $code);

        // A LITERAL BLADE ECHO TAG IN THE JAVASCRIPT. The variable picker builds a {{ name }}
        // token to insert into a merchant's message; written as a literal, Blade compiled it away
        // and the picker inserted "+ name +" instead, and written with Blade's @ escape the @
        // survived into the message. Both were found by opening the page in a browser. The braces
        // are concatenated now, and this is what stops the literal coming back.
        $this->assertStringNotContainsString("'{{", $code);
        $this->assertStringNotContainsString('@{{', $code);
    }

    /**
     * FOUR WORDS THE HOST'S DICTIONARY MUST NOT ANSWER FOR.
     *
     * This module's strings are JSON translations keyed by their English text, in the same flat
     * namespace as the host's own resources/lang/<locale>.json of ~2,800 keys -- and the host's
     * wins. Four of the Studio's words collide with an entry there that says something else in
     * Arabic: "Live" reads as "alive", "Off" as the imperative "turn it off!", and Yes and No
     * carry trailing dots from another screen's sentence. Found by reading the Arabic page in a
     * browser. A namespaced PHP group cannot be overridden by flat JSON; this keeps it that way.
     */
    public function test_the_four_words_the_host_dictionary_would_hijack_are_namespaced(): void
    {
        $host = file_exists(base_path('resources/lang/ar.json'))
            ? json_decode(file_get_contents(base_path('resources/lang/ar.json')), true)
            : [];

        app()->setLocale('ar');

        foreach (['state_published' => 'Live', 'state_disabled' => 'Off', 'yes' => 'Yes', 'no' => 'No'] as $key => $english) {
            $namespaced = __("lynomia-chat::studio.{$key}");

            $this->assertNotSame("lynomia-chat::studio.{$key}", $namespaced, "studio.{$key} has no Arabic");
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $namespaced, "studio.{$key} is not Arabic");

            // And it is genuinely different from what the host would have answered -- which is
            // the whole reason these four are not JSON.
            if (isset($host[$english])) {
                $this->assertNotSame($host[$english], $namespaced,
                    "studio.{$key} now matches the host's own '{$english}', so namespacing it bought nothing");
            }
        }

        foreach (['merchant/builder', 'merchant/automation'] as $view) {
            $source = file_get_contents(base_path("packages/workdo/LynomiaChat/src/resources/views/{$view}.blade.php"));
            foreach (["__('Live')", "__('Off')", "__('Yes')", "__('No')"] as $hijackable) {
                $this->assertStringNotContainsString($hijackable, $source,
                    "{$view} went back to a key the host's dictionary answers for");
            }
        }
    }
}
