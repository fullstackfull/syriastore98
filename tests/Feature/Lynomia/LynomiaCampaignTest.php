<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * NATIVE CAMPAIGN MANAGEMENT (P5D).
 *
 * A CAMPAIGN'S STATUS IS THE RECORD OF WHAT WAS SENT, and Lynomia owns it. The tests that
 * matter are therefore about not keeping a second account of it here, about the preview being
 * the same query as the send, and about cancelling KEEPING everything -- because the results
 * are what an operator who stopped a half-sent campaign came for.
 */
class LynomiaCampaignTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const CAMPAIGNS = '/lynomia/campaigns/api';

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

    private function campaign(array $overrides = []): array
    {
        return array_merge([
            'id' => 51, 'display_id' => 3, 'title' => 'Spring sale', 'message' => 'Hello',
            'campaign_status' => 'active', 'campaign_type' => 'one_off',
            'scheduled_at' => '2026-04-01T09:00:00Z',
            'audience' => [['type' => 'Audience', 'id' => 12]],
            'inbox' => ['id' => 7, 'name' => 'WhatsApp'],
        ], $overrides);
    }

    // ================================================================ the walls

    public function test_every_campaign_route_carries_the_full_stack(): void
    {
        foreach (['lynomia.campaigns', 'lynomia.campaigns.api.index', 'lynomia.campaigns.api.store',
            'lynomia.campaigns.api.preview', 'lynomia.campaigns.api.update',
            'lynomia.campaigns.api.cancel', 'lynomia.campaigns.api.destroy',
            'lynomia.campaigns.api.results'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, $name);
            $this->assertTrue((bool) preg_grep('/PlanModuleCheck:LynomiaChat/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Campaigns/', $stack), $name);
        }
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->getJson(self::CAMPAIGNS)->assertUnauthorized();
    }

    /**
     * THE HEAVIEST OF THE MARKETING PERMISSIONS, and the reason it is separate: an audience
     * says who could be reached and a template says what the words are, but a campaign is
     * the act of sending, and it cannot be taken back.
     */
    public function test_a_staff_member_who_may_draft_but_not_send_is_refused(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat', 'Manage Lynomia Audiences', 'Manage Lynomia Templates']);
        Http::fake();

        $this->actingAs($staff->fresh())->getJson(self::CAMPAIGNS)->assertForbidden();
        $this->actingAs($staff->fresh())->postJson(self::CAMPAIGNS, [
            'title' => 'Theirs', 'message' => 'Hi', 'audience' => [], 'inbox_id' => 7,
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    // ================================================================ reading

    public function test_it_lists_campaigns_with_their_status_and_why_they_stopped(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['campaigns'] = [
            $this->campaign(),
            $this->campaign(['id' => 52, 'display_id' => 4, 'campaign_status' => 'cancelled',
                'blocked_reason' => 'wrong audience']),
        ];

        $body = $this->actingAs($owner)->getJson(self::CAMPAIGNS)->assertOk()->json();

        $this->assertSame('active', $body['campaigns'][0]['campaign_status']);
        $this->assertSame('cancelled', $body['campaigns'][1]['campaign_status']);
        $this->assertSame('wrong audience', $body['campaigns'][1]['blocked_reason'],
            'a campaign that did nothing must say why');
    }

    /** No second account of what was sent: Lynomia owns the send and the snapshot. */
    public function test_nothing_about_a_campaign_is_stored_locally(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['campaigns'] = [$this->campaign()];
        $this->actingAs($owner)->getJson(self::CAMPAIGNS)->assertOk();

        $mine = array_filter(
            \Illuminate\Support\Facades\DB::connection()->getSchemaBuilder()->getTableListing(),
            static fn ($table) => str_starts_with(strtolower(basename((string) $table)), 'lynomia_')
        );
        $this->assertNotEmpty($mine);

        foreach ($mine as $table) {
            $this->assertStringNotContainsString('campaign', strtolower((string) $table));
        }
    }

    // ================================================================ the preview

    /**
     * THE PREVIEW IS THE SAME QUERY AS THE SEND. Upstream's audience_preview resolves the
     * named audiences exactly as the sender does and applies the consent filter, so the
     * number already excludes everyone who cannot be messaged. The store id narrows the
     * commerce aggregates as a campaign from this store will.
     */
    public function test_the_preview_is_scoped_to_this_store_so_the_number_is_the_number(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->lynomiaScript['audience_fields'] = [
            'stores' => [['id' => 55, 'name' => 'Damascus', 'provider' => 'storesyria',
                'external_store_id' => (string) $store->id]],
            'currencies' => [], 'unread_contacts' => 0,
        ];
        $this->lynomiaScript['audience_preview'] = ['scoped' => 40, 'all' => 180];

        $body = $this->actingAs($owner)->postJson(self::CAMPAIGNS . '/preview', [
            'audience' => [['type' => 'Audience', 'id' => 12]],
        ])->assertOk()->json();

        $this->assertSame(40, $body['count']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'audience_preview')
            && ($request['commerce_store_id'] ?? null) === 55);
    }

    public function test_a_malformed_audience_entry_is_refused_at_the_boundary(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->postJson(self::CAMPAIGNS . '/preview', [
            'audience' => [['type' => 'Nonsense', 'id' => 12]],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    // ================================================================ writing

    public function test_a_merchant_can_create_a_scheduled_campaign(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->postJson(self::CAMPAIGNS, [
            'title' => 'Spring sale', 'message' => 'Hello', 'inbox_id' => 7,
            'audience' => [['type' => 'Audience', 'id' => 12]],
            'scheduled_at' => '2026-04-01T09:00:00Z',
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/campaigns') || strtoupper($request->method()) !== 'POST') { return false; }
            $body = json_decode($request->body(), true)['campaign'] ?? [];

            return ($body['title'] ?? null) === 'Spring sale'
                && ($body['scheduled_at'] ?? null) === '2026-04-01T09:00:00Z';
        });
    }

    public function test_a_campaign_with_no_name_or_no_message_is_refused_without_calling_lynomia(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->postJson(self::CAMPAIGNS, ['title' => '', 'message' => 'Hi', 'audience' => [], 'inbox_id' => 7])
            ->assertStatus(422);
        $this->actingAs($owner)->postJson(self::CAMPAIGNS, ['title' => 'x', 'message' => '', 'audience' => [], 'inbox_id' => 7])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /** The channel a campaign sends on cannot change after creation; an edit does not try. */
    public function test_an_edit_does_not_try_to_move_the_campaign_to_another_channel(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->patchJson(self::CAMPAIGNS . '/3', [
            'title' => 'Renamed', 'message' => 'Hello', 'inbox_id' => 99,
            'audience' => [['type' => 'Audience', 'id' => 12]],
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (strtoupper($request->method()) !== 'PATCH') { return false; }

            return ! array_key_exists('inbox_id', json_decode($request->body(), true)['campaign'] ?? []);
        });
    }

    // ================================================================ stopping

    /**
     * CANCELLING KEEPS EVERYTHING. A POST, never a DELETE: deleting destroys the recipient
     * snapshot, which is the single thing an operator who stopped a half-sent campaign needs
     * to read afterwards.
     */
    public function test_cancelling_stops_the_campaign_and_keeps_it(): void
    {
        [$owner] = $this->connectedTenant();

        $body = $this->actingAs($owner)->postJson(self::CAMPAIGNS . '/3/cancel', ['reason' => 'wrong audience'])
            ->assertOk()->json();

        $this->assertSame('cancelled', $body['campaign']['campaign_status']);
        $this->assertSame('wrong audience', $body['campaign']['blocked_reason']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/campaigns/3/cancel')
            && strtoupper($request->method()) === 'POST');
        // Scoped to this campaign: provisioning the tenant above sends its own requests,
        // and what is asserted here is that CANCELLING did not reach for a delete.
        Http::assertNotSent(fn ($request) => strtoupper($request->method()) === 'DELETE'
            && str_contains($request->url(), '/campaigns/'));
    }

    public function test_a_cancellation_reason_longer_than_the_column_is_refused_rather_than_truncated_silently(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->postJson(self::CAMPAIGNS . '/3/cancel', ['reason' => str_repeat('x', 300)])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    // ================================================================ results

    /**
     * "40 of 180 sent" reads as a failure; "140 skipped: no marketing consent on record" is
     * an answer a merchant can act on. The reason is the half a delivery count cannot tell.
     */
    public function test_the_results_say_why_somebody_was_not_reached(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['campaign_recipients'] = [
            ['contact_name' => 'Lina', 'status' => 'sent', 'sent_at' => 1760000000],
            ['contact_name' => 'Omar', 'status' => 'skipped', 'error_message' => 'No marketing consent on record'],
        ];

        $body = $this->actingAs($owner)->getJson(self::CAMPAIGNS . '/51/results')->assertOk()->json();

        $this->assertSame(180, $body['metrics']['audience']);
        $this->assertSame('sent', $body['recipients'][0]['status']);
        $this->assertSame('No marketing consent on record', $body['recipients'][1]['reason']);
    }

    /**
     * THE P5F QUESTION: not how many were not sent, but WHY. Ordered largest first, because
     * the reason that explains most of the gap is the one to read, and reshaped into a list
     * so that order survives JSON.
     */
    public function test_the_results_break_the_unsent_down_by_reason(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['campaign_metrics'] = [
            'audience' => 180, 'sent' => 40, 'skipped' => 140,
            'skip_reasons' => [
                'No marketing consent on record' => 120,
                'Customer opted out' => 15,
                'Contact is blocked' => 5,
            ],
        ];

        $body = $this->actingAs($owner)->getJson(self::CAMPAIGNS . '/51/results')->assertOk()->json();

        $this->assertSame(
            [['reason' => 'No marketing consent on record', 'count' => 120],
                ['reason' => 'Customer opted out', 'count' => 15],
                ['reason' => 'Contact is blocked', 'count' => 5]],
            $body['skip_reasons']
        );
    }

    public function test_a_campaign_that_reached_everyone_reports_no_reasons_rather_than_a_gap(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['campaign_metrics'] = ['audience' => 40, 'sent' => 40, 'skipped' => 0, 'skip_reasons' => []];

        $this->assertSame([], $this->actingAs($owner)->getJson(self::CAMPAIGNS . '/51/results')->assertOk()->json('skip_reasons'));
    }

    public function test_a_bad_status_filter_is_refused_at_the_boundary(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->getJson(self::CAMPAIGNS . '/51/results?page=0')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_refusal_from_lynomia_names_who_can_do_it_instead(): void
    {
        [$owner] = $this->connectedTenant();
        $this->lynomiaScript['fail'] = [
            'POST /api/v1/accounts/7001/campaigns/3/cancel' => Http::response(['error' => 'forbidden'], 403),
        ];

        $response = $this->actingAs($owner)->postJson(self::CAMPAIGNS . '/3/cancel')->assertStatus(403);

        $this->assertStringContainsString('store owner', $response->json('error'));
    }
}
