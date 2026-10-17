<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * NATIVE AUDIENCE MANAGEMENT (P5B).
 *
 * AN AUDIENCE IS A QUERY, EVALUATED THERE. Nothing about one is stored on this side, so the
 * tests that matter are about the boundary: that the walls hold, that the store scope reaches
 * the count, and that this surface never enumerates the people in an audience.
 */
class LynomiaAudienceTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const AUDIENCES = '/lynomia/audiences/api';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function connectedTenant(int $accountId = 7001): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store];
    }

    // ================================================================ the walls

    public function test_every_audience_route_carries_the_full_stack(): void
    {
        foreach (['lynomia.audiences', 'lynomia.audiences.api.index', 'lynomia.audiences.api.references',
            'lynomia.audiences.api.store', 'lynomia.audiences.api.update',
            'lynomia.audiences.api.destroy', 'lynomia.audiences.api.preview'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, $name);
            $this->assertTrue((bool) preg_grep('/PlanModuleCheck:LynomiaChat/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Audiences/', $stack), $name);
        }
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->getJson(self::AUDIENCES)->assertUnauthorized();
    }

    /**
     * ITS OWN PERMISSION. A merchant can reasonably let support read conversations without
     * letting them build the list a campaign sends to, so holding the chat and automation
     * permissions is not enough.
     */
    public function test_a_staff_member_without_the_audience_permission_is_refused_before_any_call(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat', 'Manage Lynomia Automation', 'Manage Lynomia Consent']);
        Http::fake();

        $this->actingAs($staff->fresh())->getJson(self::AUDIENCES)->assertForbidden();
        $this->actingAs($staff->fresh())->postJson(self::AUDIENCES, [
            'name' => 'Theirs', 'payload' => [['attribute_key' => 'commerce_orders_count',
                'filter_operator' => 'is_greater_than', 'values' => ['1']]],
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_merchant_whose_store_is_not_connected_is_refused(): void
    {
        [$owner] = $this->entitledTenant();

        $this->actingAs($owner)->getJson(self::AUDIENCES)->assertForbidden();
    }

    // ================================================================ reading

    public function test_it_lists_the_workspace_audiences_without_naming_anybody_in_them(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['audiences'] = [[
            'id' => 12, 'name' => 'Repeat buyers', 'shared' => true, 'filter_type' => 'contact',
            'query' => ['payload' => [['attribute_key' => 'commerce_orders_count',
                'filter_operator' => 'is_greater_than', 'values' => ['3']]]],
        ]];

        $body = $this->actingAs($owner)->getJson(self::AUDIENCES)->assertOk()->json();

        $this->assertSame('Repeat buyers', $body['audiences'][0]['name']);
        $this->assertSame('commerce_orders_count', $body['audiences'][0]['payload'][0]['attribute_key']);
        // A COUNT, NEVER A LIST. The people in an audience are Lynomia's contacts and this
        // surface has no business enumerating them.
        $this->assertArrayNotHasKey('contacts', $body);
        $this->assertStringNotContainsString('email', json_encode($body));
    }

    public function test_the_references_say_which_stores_and_currencies_conditions_can_use(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->lynomiaScript['audience_fields'] = [
            'stores' => [['id' => 55, 'name' => 'Damascus', 'provider' => 'storesyria',
                'external_store_id' => StoreIdentity::of($store->id)]],
            'currencies' => ['SYP', 'USD'],
            'unread_contacts' => 4,
        ];

        $body = $this->actingAs($owner)->getJson(self::AUDIENCES . '/references')->assertOk()->json();

        $this->assertSame(['SYP', 'USD'], $body['currencies']);
        $this->assertSame(4, $body['unread_contacts'], 'a merchant must be told which contacts cannot be answered for');
        $this->assertSame(55, $body['this_store'], 'the page scopes its counts to this installation');
    }

    // ================================================================ the store scope

    /**
     * THE POINT OF THE WHOLE INCREMENT. The scoped count is what a campaign from this store
     * will reach; the unscoped one is what the same conditions select account-wide. Showing
     * only the second promises a merchant people their campaign cannot message.
     */
    public function test_the_preview_counts_this_store_and_says_what_the_account_wide_number_is(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->lynomiaScript['audience_fields'] = [
            'stores' => [['id' => 55, 'name' => 'Damascus', 'provider' => 'storesyria',
                'external_store_id' => StoreIdentity::of($store->id)]],
            'currencies' => [], 'unread_contacts' => 0,
        ];
        $this->lynomiaScript['audience_preview'] = ['scoped' => 40, 'all' => 180];

        $body = $this->actingAs($owner)->postJson(self::AUDIENCES . '/preview', ['audience_id' => 12])
            ->assertOk()->json();

        $this->assertSame(40, $body['count']);
        $this->assertSame(180, $body['count_all_stores']);
    }

    /** The scope names the LYNOMIA store row, not the Store Syria store id. */
    public function test_the_preview_scopes_on_the_lynomia_store_that_matches_this_installation(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->lynomiaScript['audience_fields'] = [
            'stores' => [
                ['id' => 99, 'name' => 'Their Shopify', 'provider' => 'shopify', 'external_store_id' => 'sh-1'],
                ['id' => 55, 'name' => 'Damascus', 'provider' => 'storesyria', 'external_store_id' => StoreIdentity::of($store->id)],
            ],
            'currencies' => [], 'unread_contacts' => 0,
        ];

        $this->actingAs($owner)->postJson(self::AUDIENCES . '/preview', ['audience_id' => 12])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'audience_preview')
                && ($request['commerce_store_id'] ?? null) === 55;
        });
    }

    // ================================================================ writing

    public function test_a_merchant_can_create_an_audience(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->postJson(self::AUDIENCES, [
            'name' => 'Repeat buyers',
            'payload' => [['attribute_key' => 'commerce_orders_count', 'filter_operator' => 'is_greater_than',
                'values' => ['3'], 'query_operator' => 'and']],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), 'custom_filters')
                && strtoupper($request->method()) === 'POST'
                && ($body['custom_filter']['shared'] ?? null) === true
                && ($body['custom_filter']['filter_type'] ?? null) === 'contact';
        });
    }

    /**
     * A presence condition legitimately carries an empty list, and `required` refuses an
     * empty array in Laravel -- the same mistake this project already made once on a flow's
     * edges. `present` is what accepts it.
     */
    public function test_a_presence_condition_with_no_values_is_accepted(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->postJson(self::AUDIENCES, [
            'name' => 'Has bought somewhere',
            'payload' => [['attribute_key' => 'commerce_store', 'filter_operator' => 'is_present', 'values' => []]],
        ])->assertOk();
    }

    public function test_a_malformed_audience_is_refused_at_the_boundary_without_calling_lynomia(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->postJson(self::AUDIENCES, ['name' => '', 'payload' => []])->assertStatus(422);
        $this->actingAs($owner)->postJson(self::AUDIENCES, [
            'name' => 'x', 'payload' => array_fill(0, 11, ['attribute_key' => 'a', 'filter_operator' => 'equal_to', 'values' => ['1']]),
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * Upstream owns which keys exist and what a value may be, and answers 422 naming the
     * attribute it refused. Restating that rulebook here would be a second copy to keep in
     * step, and the copy that fell behind would refuse a condition the engine supports.
     */
    public function test_a_condition_lynomia_refuses_is_reported_rather_than_pre_judged(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['fail'] = [
            'POST /api/v1/accounts/7001/custom_filters' => Http::response(
                ['attribute' => 'commerce_orders_count', 'message' => 'Invalid value'], 422
            ),
        ];

        $this->actingAs($owner)->postJson(self::AUDIENCES, [
            'name' => 'Nonsense',
            'payload' => [['attribute_key' => 'commerce_orders_count', 'filter_operator' => 'is_greater_than',
                'values' => ['-1']]],
        // 409, not 422: ProxiesToLynomia maps upstream's 422 to KIND_CONFLICT and KIND_CONFLICT
        // to 409 for every surface in this module. The status is shared; what matters here is
        // that it is a client error carrying upstream's own explanation rather than a 500 or a
        // pre-judgement made on this side.
        ])->assertStatus(409)->assertJsonStructure(['error']);
    }

    /**
     * Sharing an audience is a workspace-administrator act upstream and only the store owner
     * holds that identity, so a staff member who HAS the Store Syria permission is still
     * refused there -- and the sentence says who to ask rather than leaving them at 403.
     */
    public function test_a_refusal_from_lynomia_names_who_can_do_it_instead(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['fail'] = [
            'POST /api/v1/accounts/7001/custom_filters' => Http::response(['error' => 'forbidden'], 403),
        ];

        $response = $this->actingAs($owner)->postJson(self::AUDIENCES, [
            'name' => 'Repeat buyers',
            'payload' => [['attribute_key' => 'commerce_orders_count', 'filter_operator' => 'is_greater_than', 'values' => ['3']]],
        ])->assertStatus(403);

        $this->assertStringContainsString('store owner', $response->json('error'));
    }

    /** Upstream refuses to delete an audience a campaign still uses; that refusal is surfaced. */
    public function test_a_delete_lynomia_refuses_is_reported_to_the_merchant(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['fail'] = [
            'DELETE /api/v1/accounts/7001/custom_filters/12' => Http::response(
                ['message' => 'Used by 2 campaigns'], 422
            ),
        ];

        $this->actingAs($owner)->deleteJson(self::AUDIENCES . '/12')->assertStatus(409)->assertJsonStructure(['error']);
    }

    public function test_a_merchant_can_delete_an_audience(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->deleteJson(self::AUDIENCES . '/12')->assertOk()->assertJson(['deleted' => true]);

        Http::assertSent(fn ($request) => strtoupper($request->method()) === 'DELETE'
            && str_contains($request->url(), 'custom_filters/12'));
    }
}
