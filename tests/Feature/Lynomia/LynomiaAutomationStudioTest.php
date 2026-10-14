<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;

/**
 * The Automation Studio: what a merchant can reach, what their staff cannot, and what never
 * crosses the boundary.
 *
 * WHY THIS SURFACE NEEDS ITS OWN TESTS RATHER THAN THE INBOX'S. The inbox writes ONE message,
 * composed by a person who read what was asked. A published flow answers every future customer
 * of the store automatically with nobody reading anything, so the blast radius of a wrong
 * authorization answer is different in kind. It is also the first surface where two separate
 * authorities have to agree: Store Syria's own permission decides who reaches the Studio, and
 * Lynomia's AgentBotPolicy decides who may change a flow -- and only the store OWNER satisfies
 * the second. Both halves are asserted here, including that the first cannot widen the second.
 *
 * NOTHING HERE REACHES THE NETWORK -- see InteractsWithLynomia.
 */
class LynomiaAutomationStudioTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const FLOWS = '/lynomia/automation/api/flows';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    /** A tenant with a live workspace, ready to open the Studio. */
    private function connectedTenant(): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store];
    }

    // ================================================================ the walls

    public function test_every_studio_route_carries_session_auth_entitlement_both_permissions_and_tenancy(): void
    {
        $expected = [
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\EmailIsVerified::class,
        ];

        $names = ['lynomia.automation', 'lynomia.automation.api.flows', 'lynomia.automation.api.flows.store',
            'lynomia.automation.api.flows.show', 'lynomia.automation.api.flows.update',
            'lynomia.automation.api.flows.destroy', 'lynomia.automation.api.flows.duplicate',
            'lynomia.automation.api.flows.publish', 'lynomia.automation.api.flows.disable'];

        foreach ($names as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            foreach ($expected as $middleware) {
                $this->assertContains($middleware, $stack, "{$name} lost " . class_basename($middleware));
            }

            $this->assertTrue((bool) preg_grep('/PlanModuleCheck:LynomiaChat/', $stack),
                "{$name} is not gated by the module entitlement.");
            // `:existing` -- a store with no workspace is refused before any controller runs,
            // so the Studio can never reach the gateway with a null link.
            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack),
                "{$name} does not require an existing link.");
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Chat/', $stack),
                "{$name} does not require the module permission.");
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Automation/', $stack),
                "{$name} does not require the automation permission.");
        }
    }

    public function test_anonymous_callers_reach_no_studio_route(): void
    {
        Http::fake();

        foreach ([
            ['get', '/lynomia/automation'],
            ['get', self::FLOWS],
            ['post', self::FLOWS],
            ['get', self::FLOWS . '/42'],
            ['patch', self::FLOWS . '/42'],
            ['delete', self::FLOWS . '/42'],
            ['post', self::FLOWS . '/42/duplicate'],
            ['post', self::FLOWS . '/42/publish'],
            ['post', self::FLOWS . '/42/disable'],
        ] as [$method, $path]) {
            $response = $this->{$method . 'Json'}($path);

            $this->assertContains($response->status(), [401, 403, 302], "{$method} {$path} admitted an anonymous caller");
        }

        Http::assertNothingSent();
    }

    public function test_an_unentitled_tenant_is_refused_before_any_lynomia_call(): void
    {
        $owner = $this->merchant();
        $this->storeFor($owner);
        $this->grantLynomiaPermission($owner, ['Manage Lynomia Chat', 'Manage Lynomia Automation']);
        Http::fake();

        $this->actingAs($owner->fresh())->getJson(self::FLOWS)->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * The permission NARROWS. A staff member with the module permission but not the automation
     * one is refused by Store Syria, before a credential is minted for them -- which is what
     * makes it a usable control for a merchant who wants staff on the inbox and not in here.
     */
    public function test_staff_without_the_automation_permission_are_refused_by_store_syria(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat']);
        Http::fake();

        $this->actingAs($staff->fresh())->getJson(self::FLOWS)->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * And it never WIDENS. A staff member granted the automation permission still holds an
     * AGENT identity in the workspace, and Lynomia authorizes every flows action as an
     * administrator -- so the call is made, refused there, and reported as the one sentence
     * that tells them what to do about it.
     */
    public function test_staff_with_the_permission_are_still_refused_by_lynomia_and_told_who_to_ask(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat', 'Manage Lynomia Automation']);

        $this->fakeLynomia(['fail' => [
            'GET /api/v1/accounts/7001/flows' => Http::response(['error' => 'You are not authorized to do this action'], 403),
        ]]);

        $response = $this->actingAs($staff->fresh())->getJson(self::FLOWS);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Only the store owner can manage automations.');
        // Lynomia's own sentence is never what the merchant reads.
        $this->assertStringNotContainsString('not authorized to do this action', $response->getContent());
    }

    public function test_a_merchant_pointing_at_another_tenants_store_reaches_no_workspace(): void
    {
        [, $victimStore] = $this->connectedTenant();

        $attacker = $this->merchant();
        $this->storeFor($attacker);
        $this->entitle($attacker);
        $attacker->current_store = $victimStore->id;
        $attacker->save();

        Http::fake();

        $response = $this->actingAs($attacker->fresh())->getJson(self::FLOWS);

        $this->assertContains($response->status(), [403, 409], 'a foreign store was admitted');
        Http::assertNothingSent();
    }

    // ================================================================ what it does

    public function test_the_owner_reads_their_flows_and_the_allowance_lynomia_enforces(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson(self::FLOWS);

        $response->assertOk();
        $response->assertJsonPath('flows.0.name', 'Welcome menu');
        $response->assertJsonPath('flows.0.state', 'published');
        $response->assertJsonPath('flows.0.live_sessions', 3);
        $response->assertJsonPath('flows.0.inboxes.0.name', 'WhatsApp');
        // The number comes from billing/entitlements, not from counting the list above -- one
        // flow is returned and the allowance says two are used.
        $response->assertJsonPath('usage.used', 2);
        $response->assertJsonPath('usage.limit', 10);
    }

    public function test_an_unlimited_allowance_is_reported_as_unlimited_and_never_as_a_number(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['limits' => ['flows' => ['used' => 4, 'limit' => null]]]);

        $response = $this->actingAs($owner)->getJson(self::FLOWS);

        $response->assertOk();
        $response->assertJsonPath('usage.used', 4);
        $this->assertNull($response->json('usage.limit'));
    }

    public function test_a_flow_bots_secret_and_token_never_reach_the_browser(): void
    {
        [$owner] = $this->connectedTenant();

        foreach ([self::FLOWS, self::FLOWS . '/42'] as $path) {
            $body = $this->actingAs($owner)->getJson($path)->getContent();

            $this->assertStringNotContainsString('BOT-SECRET-MUST-NOT-LEAK', $body, $path);
            $this->assertStringNotContainsString('BOT-TOKEN-MUST-NOT-LEAK', $body, $path);
            $this->assertStringNotContainsString('SCOPED-TOKEN', $body, $path);
        }
    }

    public function test_the_single_flow_view_carries_what_each_channel_can_actually_run(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['flow' => array_merge(self::sampleFlow(), [
            'channel_eligibility' => [
                ['id' => 9, 'name' => 'WhatsApp', 'channel_type' => 'Channel::Whatsapp',
                    'runs_flows' => true, 'unsupported_nodes' => ['send_template']],
                ['id' => 12, 'name' => 'Live chat', 'channel_type' => 'Channel::WebWidget',
                    'runs_flows' => false, 'unsupported_nodes' => []],
            ],
        ])]);

        $response = $this->actingAs($owner)->getJson(self::FLOWS . '/42');

        $response->assertOk();
        $response->assertJsonPath('flow.channel_eligibility.0.unsupported_nodes.0', 'send_template');
        $response->assertJsonPath('flow.channel_eligibility.1.runs_flows', false);
    }

    public function test_creating_a_flow_forwards_the_name_and_returns_the_new_flow(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->postJson(self::FLOWS, [
            'name' => 'ترحيب',
            'description' => 'أول رد',
        ]);

        $response->assertOk();
        $response->assertJsonPath('flow.name', 'ترحيب');
        $response->assertJsonPath('flow.state', 'draft');

        Http::assertSent(fn ($request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/flows')
            && $request->method() === 'POST'
            && ($request->data()['name'] ?? null) === 'ترحيب');
    }

    public function test_a_nameless_or_oversized_flow_is_refused_at_the_boundary(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->postJson(self::FLOWS, ['name' => ''])->assertStatus(422);
        $this->actingAs($owner)->postJson(self::FLOWS, ['name' => str_repeat('a', 256)])->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * THE CEILING REFUSAL IS REPORTED AS NUMBERS, NOT AS LYNOMIA'S SENTENCE. Lynomia answers
     * 422 with an English message built from its own i18n; forwarding it would put English in
     * front of an Arabic merchant. The fresh allowance is returned instead and the browser
     * writes the sentence in the merchant's language.
     */
    public function test_a_create_past_the_flow_ceiling_reports_the_allowance_and_no_english(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia([
            'limits' => ['flows' => ['used' => 3, 'limit' => 3]],
            'fail' => [
                'POST /api/v1/accounts/7001/flows' => Http::response([
                    'message' => 'Your plan allows up to 3 flows. Upgrade your plan to build more.',
                    'attributes' => ['base'],
                ], 422),
            ],
        ]);

        $response = $this->actingAs($owner)->postJson(self::FLOWS, ['name' => 'Fourth']);

        $response->assertOk();
        $this->assertNull($response->json('flow'));
        $response->assertJsonPath('usage.used', 3);
        $response->assertJsonPath('usage.limit', 3);
        $this->assertStringNotContainsString('Upgrade your plan', $response->getContent());
    }

    public function test_duplicating_sends_the_name_the_merchant_sees_and_returns_an_unpublished_copy(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->postJson(self::FLOWS . '/42/duplicate', ['name' => 'ترحيب (نسخة)']);

        $response->assertOk();
        $response->assertJsonPath('flow.name', 'ترحيب (نسخة)');
        $response->assertJsonPath('flow.state', 'draft');
        $this->assertNull($response->json('flow.published'));
        $this->assertSame([], $response->json('flow.inboxes'));
    }

    public function test_renaming_forwards_only_name_and_description(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->patchJson(self::FLOWS . '/42', [
            'name' => 'Renamed',
            'bot_type' => 'webhook',
            'account_id' => 999,
        ])->assertOk();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PATCH') {
                return true;
            }

            return array_keys($request->data()) === ['name'];
        });
    }

    public function test_publishing_asks_lynomia_and_reports_the_new_state(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->postJson(self::FLOWS . '/42/publish');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('flow.state', 'published');
    }

    /**
     * The one refusal whose BODY is the answer. A merchant cannot fix a flow that will not
     * publish from "invalid request": the codes and node ids are what the builder puts next to
     * the step at fault. They are passed through projected -- codes and ids only -- and the
     * browser renders each code as its own Arabic or English sentence.
     */
    public function test_a_publish_that_fails_validation_returns_the_codes_and_node_ids(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['fail' => [
            'POST /api/v1/accounts/7001/flows/42/publish' => Http::response(['errors' => [
                ['code' => 'unconnected_output', 'node_id' => 'menu', 'detail' => 'track'],
                ['code' => 'no_inbox'],
            ]], 422),
        ]]);

        $response = $this->actingAs($owner)->postJson(self::FLOWS . '/42/publish');

        $response->assertOk();
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('errors.0.code', 'unconnected_output');
        $response->assertJsonPath('errors.0.node_id', 'menu');
        $response->assertJsonPath('errors.0.detail', 'track');
        $response->assertJsonPath('errors.1.code', 'no_inbox');
    }

    public function test_disabling_reports_the_flow_as_off(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->postJson(self::FLOWS . '/42/disable');

        $response->assertOk();
        $response->assertJsonPath('flow.state', 'disabled');
        $response->assertJsonPath('flow.live_sessions', 0);
    }

    public function test_deleting_a_live_flow_reports_that_it_must_be_turned_off_first(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['fail' => [
            'DELETE /api/v1/accounts/7001/flows/42' => Http::response(['errors' => [['code' => 'flow_active']]], 422),
        ]]);

        $response = $this->actingAs($owner)->deleteJson(self::FLOWS . '/42');

        $response->assertOk();
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('errors.0.code', 'flow_active');
    }

    public function test_deleting_an_off_flow_succeeds(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->deleteJson(self::FLOWS . '/42')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    // ================================================================ the page

    public function test_the_studio_page_ships_a_shell_with_no_credential_and_no_flow_data(): void
    {
        [$owner] = $this->connectedTenant();

        // Resets the recorder so what follows counts only the page request, not the
        // provisioning calls that built the fixture.
        $this->fakeLynomia();

        $response = $this->actingAs($owner)->get('/lynomia/automation');

        $response->assertOk();
        $response->assertSee('lyn-studio', false);
        $response->assertSee('/lynomia/automation/api/flows', false);
        // The page makes no Lynomia call of its own: a slow Lynomia must not stop the
        // dashboard rendering.
        Http::assertNothingSent();
        $response->assertDontSee('SCOPED-TOKEN', false);
        $response->assertDontSee('Welcome menu', false);
    }

    /**
     * The menu entry is COSMETIC and is tested as such: it must disappear for a staff member
     * who may not use it, so they are not shown a page that will refuse them. It is not the
     * control -- the route tests above are -- and this asserts the same permission so the two
     * cannot drift into a visible link that 403s.
     */
    public function test_the_menu_entry_appears_only_for_a_user_holding_the_automation_permission(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat']);

        $entryFor = function ($user) {
            // actingAs because Menu reads ActivatedModule(), which resolves the signed-in
            // user's tenant rather than taking one as an argument.
            $this->actingAs($user->fresh());
            $menu = new \App\Classes\Menu($user->fresh());
            (new \Workdo\LynomiaChat\app\Listeners\CompanyMenuListener())
                ->handle(new \App\Events\CompanyMenuEvent($menu));

            return collect($menu->menu)->firstWhere('name', 'lynomia-automation');
        };

        $entry = $entryFor($owner);
        $this->assertNotNull($entry, 'the Automation Studio has no menu entry for its owner');
        $this->assertSame('Manage Lynomia Automation', $entry['permission']);
        $this->assertSame('lynomia.automation', $entry['route']);

        $this->assertNull($entryFor($staff), 'the Automation Studio is offered to staff who may not open it');
    }
}
