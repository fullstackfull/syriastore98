<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;

/**
 * The merchant browser's door to Lynomia, and the walls around it.
 *
 * WHAT THESE ARE FOR. The inbox is the first surface where a merchant's browser causes reads and
 * WRITES inside a Lynomia workspace, so it is the first place where getting authorization wrong
 * leaks one store's customer conversations to another. These tests are written against the
 * boundary rather than the implementation: they ask what a REQUEST can do, not what a method
 * returns, because the question that matters is what an attacker with a session can reach.
 *
 * NOTHING HERE REACHES THE NETWORK -- see InteractsWithLynomia.
 */
class LynomiaInboxBffTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    /** A tenant with a live workspace, ready to open the inbox. */
    private function connectedTenant(): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $link = app(AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store, $link->fresh()];
    }

    private function conversationsUrl(): string
    {
        return '/lynomia/inbox/api/conversations';
    }

    // ================================================================ the happy path

    public function test_the_merchant_owner_reads_their_own_conversations(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->getJson($this->conversationsUrl());

        $response->assertOk();
        $response->assertJsonStructure(['conversations', 'meta']);
    }

    public function test_the_inbox_page_renders_for_a_connected_store(): void
    {
        [$owner] = $this->connectedTenant();

        $response = $this->actingAs($owner)->get('/lynomia/inbox');

        $response->assertOk();
        $response->assertSee('lyn-inbox', false);
    }

    /**
     * THE CREDENTIAL IS PER PERSON AND SCOPED. The BFF asks Lynomia for a token bound to this
     * workspace rather than using the platform token, so every call it makes is attributable and
     * confined. If this ever regressed to the platform credential the integration would be
     * acting as itself in a merchant's workspace, and nothing in Lynomia's audit trail would say
     * which person did what.
     */
    public function test_the_bff_acts_with_a_per_person_workspace_scoped_credential(): void
    {
        [$owner, , $link] = $this->connectedTenant();

        $this->actingAs($owner)->getJson($this->conversationsUrl())->assertOk();

        $minted = collect(Http::recorded())->first(fn ($pair) => str_contains(
            (string) parse_url($pair[0]->url(), PHP_URL_PATH), '/scoped_access_token'
        ));
        $this->assertNotNull($minted, 'the BFF did not mint a scoped credential');
        $this->assertSame((int) $link->lynomia_account_id, (int) $minted[0]->data()['account_id']);

        $identity = LynomiaStoreIdentity::forWorkspacePerson((int) $link->lynomia_account_id, (int) $owner->id)->first();
        $this->assertStringContainsString(
            '/users/' . $identity->lynomia_user_id . '/scoped_access_token',
            (string) parse_url($minted[0]->url(), PHP_URL_PATH)
        );
    }

    /**
     * NO LYNOMIA CREDENTIAL MAY REACH THE BROWSER. Not the platform token, not the per-person
     * token, not an SSO url. The merchant's browser talks to Store Syria and nothing else.
     */
    public function test_no_lynomia_credential_ever_reaches_the_browser(): void
    {
        [$owner] = $this->connectedTenant();

        $page = $this->actingAs($owner)->get('/lynomia/inbox');
        $api = $this->actingAs($owner)->getJson($this->conversationsUrl());

        foreach ([$page->getContent(), $api->getContent()] as $body) {
            $this->assertStringNotContainsString(config('lynomia.platform_token'), $body);
            $this->assertStringNotContainsString('SCOPED-TOKEN', $body);
            $this->assertStringNotContainsString('sso_auth_token', $body);
            $this->assertStringNotContainsString((string) config('lynomia.base_url'), $body);
        }
    }

    // ================================================================ isolation

    /**
     * STORE A CANNOT READ STORE B. The two merchants are unrelated and each has their own
     * workspace; the only thing that decides which one a request reaches is the session.
     */
    public function test_one_merchant_cannot_reach_another_merchants_conversations(): void
    {
        [$ownerA] = $this->connectedTenant();

        [$ownerB, $storeB] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7002]);
        app(AccountProvisioner::class)->provision($storeB, $ownerB);

        // B's session reaches B's workspace, and the account id on the wire proves which one.
        $this->actingAs($ownerB->fresh())->getJson($this->conversationsUrl())->assertOk();
        $this->assertTrue(
            collect(Http::recorded())->contains(fn ($pair) => str_contains((string) $pair[0]->url(), '/accounts/7002/')),
            'B did not reach B'
        );
        $this->assertFalse(
            collect(Http::recorded())->contains(fn ($pair) => str_contains((string) $pair[0]->url(), '/accounts/7001/')),
            'B reached A'
        );

        $this->assertNotNull($ownerA);
    }

    /**
     * A MERCHANT WITH TWO STORES IS SCOPED TO THE ONE THEY ARE IN. Same person, same session,
     * different current_store -- and the workspace the BFF reaches must follow the store.
     */
    public function test_a_merchant_with_two_stores_is_scoped_to_the_selected_one(): void
    {
        [$owner, $storeA] = $this->connectedTenant();

        $storeB = $this->storeFor($owner->fresh());
        $this->fakeLynomia(['account_id' => 7002]);
        app(AccountProvisioner::class)->provision($storeB, $owner->fresh());

        // current_store is storeB after storeFor(); the inbox must read B's workspace.
        Http::fake();
        $this->actingAs($owner->fresh())->getJson($this->conversationsUrl())->assertOk();
        $this->assertTrue(
            collect(Http::recorded())->contains(fn ($pair) => str_contains((string) $pair[0]->url(), '/accounts/7002/')),
            'the inbox did not follow the selected store'
        );

        // Switch back to A and it must follow again.
        $owner->fresh()->forceFill(['current_store' => $storeA->id])->save();
        Http::fake();
        $this->actingAs($owner->fresh())->getJson($this->conversationsUrl())->assertOk();
        $this->assertTrue(
            collect(Http::recorded())->contains(fn ($pair) => str_contains((string) $pair[0]->url(), '/accounts/7001/')),
            'the inbox did not follow back to the first store'
        );
    }

    /**
     * A FORGED CONVERSATION ID IS NOT AN AUTHORIZATION FAILURE HERE -- it is simply sent to a
     * workspace that does not contain it. display_ids are per-account and sequential, so another
     * store's conversation 7 is a perfectly valid 7; what stops it resolving is that the
     * credential cannot see that account at all. This asserts the request goes to the caller's
     * OWN account, which is the property that makes the forgery pointless.
     */
    public function test_a_forged_conversation_id_is_confined_to_the_callers_own_workspace(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->getJson('/lynomia/inbox/api/conversations/999999/messages');

        $asked = collect(Http::recorded())->first(fn ($pair) => str_contains((string) $pair[0]->url(), '/messages'));
        $this->assertNotNull($asked);
        $this->assertStringContainsString('/accounts/7001/conversations/999999/messages', (string) $asked[0]->url());
        $this->assertStringNotContainsString('/accounts/7002/', (string) $asked[0]->url());
    }

    public function test_an_unconnected_store_gets_no_inbox(): void
    {
        [$owner] = $this->entitledTenant();

        $this->actingAs($owner)->getJson($this->conversationsUrl())->assertForbidden();
        $this->actingAs($owner)->get('/lynomia/inbox')->assertRedirect();
    }

    public function test_an_anonymous_caller_is_refused_on_every_bff_route(): void
    {
        $routes = [
            ['get', $this->conversationsUrl()],
            ['get', '/lynomia/inbox/api/conversations/1/messages'],
            ['post', '/lynomia/inbox/api/conversations/1/reply'],
            ['post', '/lynomia/inbox/api/conversations/1/assign'],
            ['post', '/lynomia/inbox/api/conversations/1/status'],
            ['get', '/lynomia/inbox/api/agents'],
        ];

        foreach ($routes as [$verb, $uri]) {
            $response = $this->json($verb, $uri);
            $this->assertContains($response->getStatusCode(), [401, 403, 302], "{$uri} admitted an anonymous caller");
        }
    }

    // ================================================================ per-action permissions

    /**
     * READING IS NOT REPLYING. A staff member granted the module may look at what customers are
     * asking without being able to answer them in the store's name.
     */
    public function test_staff_without_the_reply_permission_may_read_but_not_send(): void
    {
        [$owner, , $link] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff);

        $this->actingAs($staff->fresh())->getJson($this->conversationsUrl())->assertOk();

        $sentBefore = count(Http::recorded());
        $this->actingAs($staff->fresh())
            ->postJson('/lynomia/inbox/api/conversations/1/reply', ['content' => 'hello'])
            ->assertForbidden();

        $this->assertSame($sentBefore, count(Http::recorded()), 'a refused reply still reached Lynomia');
        $this->assertNotNull($link);
    }

    public function test_staff_without_the_assign_permission_cannot_assign_or_change_status(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff);

        $sentBefore = count(Http::recorded());

        $this->actingAs($staff->fresh())
            ->postJson('/lynomia/inbox/api/conversations/1/assign', ['assignee_id' => null])
            ->assertForbidden();
        $this->actingAs($staff->fresh())
            ->postJson('/lynomia/inbox/api/conversations/1/status', ['status' => 'resolved'])
            ->assertForbidden();

        $this->assertSame($sentBefore, count(Http::recorded()), 'a refused action still reached Lynomia');
    }

    /** A staff member with no module permission at all reaches nothing, including the page. */
    public function test_staff_without_the_module_permission_reach_nothing(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);

        $this->actingAs($staff->fresh())->getJson($this->conversationsUrl())->assertForbidden();
        $this->actingAs($staff->fresh())->get('/lynomia/inbox')->assertForbidden();
    }

    // ================================================================ input handling

    public function test_a_reply_must_carry_content(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)
            ->postJson('/lynomia/inbox/api/conversations/1/reply', ['content' => '   '])
            ->assertStatus(422);
    }

    public function test_an_unknown_status_is_refused_at_the_boundary(): void
    {
        [$owner] = $this->connectedTenant();
        $sentBefore = count(Http::recorded());

        $this->actingAs($owner)
            ->postJson('/lynomia/inbox/api/conversations/1/status', ['status' => 'deleted'])
            ->assertStatus(422);

        $this->assertSame($sentBefore, count(Http::recorded()), 'an invalid status still reached Lynomia');
    }

    /**
     * An unknown filter is DROPPED, not forwarded. Upstream's conversation finder accepts a
     * number of parameters this product does not expose, and forwarding whatever arrives would
     * make the BFF a pass-through for an API surface nobody reviewed.
     */
    public function test_an_unrecognised_filter_is_not_forwarded(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->getJson($this->conversationsUrl() . '?status=nonsense&inbox_id=99');

        $asked = collect(Http::recorded())->first(fn ($pair) => str_contains((string) $pair[0]->url(), '/conversations'));
        $this->assertNotNull($asked);
        $this->assertStringNotContainsString('nonsense', (string) $asked[0]->url());
        $this->assertStringNotContainsString('inbox_id', (string) $asked[0]->url());
    }

    /**
     * A SEND IS NEVER TRANSPORT-RETRIED. A read timeout is exactly the case where Lynomia did the
     * work and the answer was lost coming back; retrying posts a second reply the merchant never
     * wrote, to a real customer, on a real channel.
     */
    public function test_a_failed_send_is_not_retried(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['fail' => [
            'POST /api/v1/accounts/7001/conversations/1/messages' => Http::failedConnection(),
        ]]);

        $this->actingAs($owner)
            ->postJson('/lynomia/inbox/api/conversations/1/reply', ['content' => 'hello'])
            ->assertStatus(503);

        $sends = collect(Http::recorded())->filter(fn ($pair) => str_ends_with(
            (string) parse_url($pair[0]->url(), PHP_URL_PATH), '/conversations/1/messages'
        ));
        $this->assertCount(1, $sends, 'a dropped send was retried');
    }

    /** Lynomia's own error text is never shown to a merchant. */
    public function test_an_upstream_error_body_is_never_forwarded(): void
    {
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia(['fail' => [
            'GET /api/v1/accounts/7001/conversations' => Http::response(['error' => 'PG::UndefinedColumn kaboom'], 500),
        ]]);

        $response = $this->actingAs($owner)->getJson($this->conversationsUrl());

        $response->assertStatus(503);
        $this->assertStringNotContainsString('PG::UndefinedColumn', $response->getContent());
    }
}
