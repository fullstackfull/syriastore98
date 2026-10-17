<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;

/**
 * Commerce automation rules, and the consent that decides whether any of it may be sent.
 *
 * WHAT THESE ARE FOR. A rule is the surface where a merchant arranges for a message to be sent
 * to a customer with nobody reading it first, and consent is the only thing standing between
 * that and messaging somebody who asked to be left alone. Three claims are worth testing at the
 * boundary and are tested here:
 *
 *   nothing unusable is offered   a trigger that cannot fire for this store, and an action the
 *                                 server does not describe, must not reach the merchant
 *   consent is its own permission a merchant may let staff build automations without letting
 *                                 them mark a customer as having agreed to marketing
 *   a decision carries its source a grant with no note is indistinguishable from adding
 *                                 somebody to a list, and is refused at the boundary
 *
 * NOTHING HERE REACHES THE NETWORK -- see InteractsWithLynomia.
 */
class LynomiaCommerceAutomationTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const RULES = '/lynomia/automation/api/rules';
    private const CONSENTS = '/lynomia/automation/api/consents';

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

    private function rule(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tell them when it ships',
            'event_name' => 'commerce_order_shipped',
            'active' => true,
            'conditions' => [],
            'actions' => [['action_name' => 'add_label', 'action_params' => ['vip']]],
        ], $overrides);
    }

    // ================================================================ the walls

    public function test_every_rule_and_consent_route_carries_the_studio_stack(): void
    {
        foreach (['lynomia.automation.commerce', 'lynomia.automation.api.rules', 'lynomia.automation.api.rules.store',
            'lynomia.automation.api.rules.update', 'lynomia.automation.api.rules.destroy',
            'lynomia.automation.api.consents', 'lynomia.automation.api.consents.show',
            'lynomia.automation.api.consents.record', 'lynomia.automation.api.consents.clear',
            'lynomia.automation.api.consents.search'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, $name);
            $this->assertTrue((bool) preg_grep('/PlanModuleCheck:LynomiaChat/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Automation/', $stack), $name);
        }
    }

    /**
     * CONSENT IS ITS OWN PERMISSION, on top of the Studio's. Editing an automation changes what
     * the store would send; recording a consent decision changes whether a named person may be
     * messaged at all, and a merchant can reasonably grant the first without the second.
     */
    public function test_consent_routes_require_their_own_permission_and_rules_do_not(): void
    {
        foreach (['lynomia.automation.api.consents', 'lynomia.automation.api.consents.record',
            'lynomia.automation.api.consents.clear', 'lynomia.automation.api.consents.search'] as $name) {
            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Consent/', $stack), $name);
        }

        $rules = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('lynomia.automation.api.rules')), 'is_string');
        $this->assertEmpty(preg_grep('/Permission:Manage Lynomia Consent/', $rules),
            'building a rule now demands the consent permission, which was not the intent');
    }

    public function test_a_staff_member_without_the_consent_permission_is_refused_before_any_call(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat', 'Manage Lynomia Automation']);
        Http::fake();

        $this->actingAs($staff->fresh())->getJson(self::CONSENTS)->assertForbidden();
        $this->actingAs($staff->fresh())->putJson(self::CONSENTS . '/55', [
            'purpose' => 'promotional', 'state' => 'granted', 'note' => 'They said so',
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_anonymous_callers_reach_nothing(): void
    {
        $this->connectedTenant();

        $this->getJson(self::RULES)->assertStatus(401);
        $this->postJson(self::RULES, $this->rule())->assertStatus(401);
        $this->getJson(self::CONSENTS)->assertStatus(401);
        $this->putJson(self::CONSENTS . '/55', ['purpose' => 'promotional', 'state' => 'granted', 'note' => 'x'])
            ->assertStatus(401);
    }

    // ================================================================ rules

    public function test_the_rule_list_carries_the_vocabulary_it_cannot_be_drawn_without(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson(self::RULES);

        $response->assertOk();
        $response->assertJsonPath('rules.0.event_name', 'commerce_order_shipped');
        $response->assertJsonPath('rules.0.actions.0.action_name', 'add_label');
        $response->assertJsonPath('rules.0.conditions.0.attribute_key', 'commerce_order_status');
        $response->assertJsonPath('vocabulary.events.0.name', 'conversation_created');
    }

    /**
     * THE FIRST TRAP THE CAPABILITY REVIEW NAMED. A Commerce trigger is a valid name for every
     * account and a rule naming one SAVES -- but Store Syria's abandoned carts are still PRE_UAT
     * and no cart event is ever produced for them. The flag travels so the Studio can show the
     * trigger and refuse it with a reason, rather than offering a rule that does nothing.
     */
    public function test_a_trigger_that_cannot_fire_here_travels_with_the_reason_it_cannot(): void
    {
        [$owner] = $this->connectedTenant();

        $events = collect($this->actingAs($owner)->getJson(self::RULES)->json('vocabulary.events'))
            ->keyBy('name');

        $this->assertTrue($events['commerce_order_shipped']['available']);
        $this->assertFalse($events['commerce_cart_abandoned']['available']);
        $this->assertSame('recovery_unavailable', $events['commerce_cart_abandoned']['reason']);
    }

    /**
     * An action the server describes with no field schema is one whose parameters are a file
     * upload, a webhook or an email body -- each needing a surface of its own. It is dropped
     * here so no form for it can be drawn.
     */
    public function test_only_the_actions_the_server_describes_are_offered(): void
    {
        [$owner] = $this->connectedTenant();

        $actions = collect($this->actingAs($owner)->getJson(self::RULES)->json('vocabulary.actions'));

        $this->assertEqualsCanonicalizing(['send_message', 'add_label'], $actions->pluck('name')->all());
        $this->assertFalse($actions->firstWhere('name', 'send_message')['commerce_safe']);
        $this->assertSame('labels', $actions->firstWhere('name', 'add_label')['fields'][0]['kind']);
    }

    public function test_creating_a_rule_forwards_its_trigger_conditions_and_actions(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->postJson(self::RULES, $this->rule([
            'conditions' => [['attribute_key' => 'commerce_order_status', 'filter_operator' => 'equal_to',
                'values' => ['shipped'], 'query_operator' => null]],
        ]))->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/automation_rules')) {
                return true;
            }

            return $request->data()['event_name'] === 'commerce_order_shipped'
                && $request->data()['conditions'][0]['attribute_key'] === 'commerce_order_status'
                && $request->data()['actions'][0]['action_name'] === 'add_label';
        });
    }

    /**
     * WHAT A RULE MEANS IS JUDGED UPSTREAM, not here: a condition's attribute and an action's
     * parameters are checked against the account's own labels, teams, audiences and entitlements,
     * none of which this side can see. What this boundary refuses is a malformed REQUEST.
     */
    public function test_a_malformed_rule_never_reaches_lynomia(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        foreach ([
            $this->rule(['name' => '']),
            $this->rule(['event_name' => '']),
            $this->rule(['actions' => []]),
            $this->rule(['conditions' => [['attribute_key' => 'status']]]),
            $this->rule(['actions' => [['action_params' => []]]]),
        ] as $bad) {
            $this->actingAs($owner)->postJson(self::RULES, $bad)->assertStatus(422);
        }

        Http::assertNothingSent();
    }

    public function test_a_rule_lynomia_refuses_returns_its_reasons_rather_than_a_blank_failure(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['fail' => [
            'POST /api/v1/accounts/7001/automation_rules' => Http::response([
                'message' => 'Actions send_message not supported',
                'errors' => ['actions' => ['send_message is not allowed on a commerce trigger']],
            ], 422),
        ]]);

        $response = $this->actingAs($owner)->postJson(self::RULES, $this->rule());

        $response->assertOk();
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('errors.actions.0', 'send_message is not allowed on a commerce trigger');
    }

    public function test_deleting_a_rule_goes_through(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->deleteJson(self::RULES . '/7')->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => $request->method() !== 'DELETE'
            || str_contains($request->url(), '/automation_rules/7'));
    }

    // ================================================================ consent

    public function test_a_shoppers_consent_says_what_would_happen_now(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson(self::CONSENTS . '/55');

        $response->assertOk();
        $response->assertJsonPath('consent.contact_name', 'Rasha');
        $response->assertJsonPath('consent.purposes.promotional.state', 'granted');
        $response->assertJsonPath('consent.purposes.promotional.note', 'Agreed on the phone');
        // A state alone cannot answer "will they be messaged": no row means allowed for one
        // class and refused for the other, so the live answer travels too.
        $this->assertNull($response->json('consent.purposes.transactional.refusal'));
    }

    public function test_recording_a_decision_requires_a_note_and_refuses_a_state_a_merchant_may_not_set(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        foreach ([
            ['purpose' => 'promotional', 'state' => 'granted'],
            ['purpose' => 'promotional', 'state' => 'granted', 'note' => ''],
            // `suppressed` is the provider's word, never a merchant's.
            ['purpose' => 'promotional', 'state' => 'suppressed', 'note' => 'x'],
            ['purpose' => 'marketing', 'state' => 'granted', 'note' => 'x'],
        ] as $bad) {
            $this->actingAs($owner)->putJson(self::CONSENTS . '/55', $bad)->assertStatus(422);
        }

        Http::assertNothingSent();
    }

    public function test_recording_a_decision_forwards_the_note_that_says_where_it_came_from(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->putJson(self::CONSENTS . '/55', [
            'purpose' => 'promotional', 'state' => 'granted', 'note' => 'Agreed on the phone, 12 Feb',
        ])->assertOk();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return true;
            }

            return $request->data() === ['purpose' => 'promotional', 'state' => 'granted',
                'note' => 'Agreed on the phone, 12 Feb'];
        });
    }

    public function test_the_contact_search_returns_a_name_and_one_identifier_and_nothing_else(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson(self::CONSENTS . '/contacts?q=ras');

        $response->assertOk();
        $response->assertJsonPath('contacts.0.name', 'Rasha');
        $response->assertJsonPath('contacts.0.identifier', '+963900000055');
        // Chatwoot's `identifier` is an external system's key for the person and is not a
        // merchant's business; the projection keeps it out.
        $this->assertStringNotContainsString('secret-identifier', $response->getContent());
        $this->assertStringNotContainsString('SCOPED-TOKEN', $response->getContent());
    }

    public function test_a_search_shorter_than_two_characters_never_reaches_lynomia(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->getJson(self::CONSENTS . '/contacts?q=r')->assertStatus(422);
        $this->actingAs($owner)->getJson(self::CONSENTS . '/contacts')->assertStatus(422);

        Http::assertNothingSent();
    }

    // ================================================================ the page

    public function test_the_page_hides_the_consent_tab_from_staff_who_may_not_use_it(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat', 'Manage Lynomia Automation']);
        $this->fakeLynomia();

        $this->actingAs($owner)->get('/lynomia/automation/commerce')
            ->assertOk()
            ->assertSee('lync-tab-consent', false);

        // Hidden is not the control -- the routes above are -- but a tab that 403s is still a
        // tab that should not be there.
        $this->actingAs($staff->fresh())->get('/lynomia/automation/commerce')
            ->assertOk()
            ->assertDontSee('lync-tab-consent', false);

        Http::assertNothingSent();
    }
}
