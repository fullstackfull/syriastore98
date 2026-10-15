<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * NATIVE MESSAGE TEMPLATE MANAGEMENT (P5C).
 *
 * META OWNS A TEMPLATE'S STATUS AND MOVES IT WITHOUT WARNING, so the tests that matter are
 * about not pretending otherwise: that nothing is stored on this side, that what may be done
 * comes from the server rather than from a copy of Meta's rules kept here, and that a
 * submission -- which spends a review cycle that cannot be taken back -- is never retried.
 */
class LynomiaTemplateTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const TEMPLATES = '/lynomia/templates/api';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    private function connectedOwner(int $accountId = 7001): \App\Models\User
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)->provision($store, $owner);

        return $owner->fresh();
    }

    private function approved(array $overrides = []): array
    {
        return array_merge([
            'id' => 31, 'name' => 'order_shipped', 'language' => 'ar', 'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Your order is on its way.']],
            'state' => 'remote', 'meta_status' => 'APPROVED', 'meta_template_id' => '99',
            'rejected_reason' => null, 'rejection_info' => null, 'quality_score' => 'GREEN',
            'last_seen_at' => 1760000000, 'missing_at_meta' => false,
            'inboxes' => [['id' => 7, 'name' => 'WhatsApp']],
            'allowed_actions' => ['duplicate'], 'validation_problems' => [],
        ], $overrides);
    }

    // ================================================================ the walls

    public function test_every_template_route_carries_the_full_stack(): void
    {
        foreach (['lynomia.templates', 'lynomia.templates.api.index', 'lynomia.templates.api.store',
            'lynomia.templates.api.update', 'lynomia.templates.api.destroy',
            'lynomia.templates.api.submit', 'lynomia.templates.api.duplicate'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, $name);
            $this->assertTrue((bool) preg_grep('/PlanModuleCheck:LynomiaChat/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Templates/', $stack), $name);
        }
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->getJson(self::TEMPLATES)->assertUnauthorized();
    }

    public function test_a_staff_member_without_the_template_permission_is_refused_before_any_call(): void
    {
        $owner = $this->connectedOwner();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat', 'Manage Lynomia Audiences', 'Manage Lynomia Automation']);
        Http::fake();

        $this->actingAs($staff->fresh())->getJson(self::TEMPLATES)->assertForbidden();
        $this->actingAs($staff->fresh())->postJson(self::TEMPLATES . '/31/submit')->assertForbidden();

        Http::assertNothingSent();
    }

    // ================================================================ what it shows

    public function test_it_shows_metas_verdict_and_when_it_was_last_checked(): void
    {
        $owner = $this->connectedOwner();
        $this->lynomiaScript['templates'] = [$this->approved([
            'meta_status' => 'REJECTED',
            'rejected_reason' => 'INVALID_FORMAT',
            'rejection_info' => 'The body has a trailing variable.',
        ])];

        $row = $this->actingAs($owner)->getJson(self::TEMPLATES)->assertOk()->json('templates.0');

        $this->assertSame('REJECTED', $row['meta_status']);
        $this->assertSame('INVALID_FORMAT', $row['rejected_reason'], 'a refusal with no reason sends a merchant guessing');
        $this->assertSame('The body has a trailing variable.', $row['rejection_info']);
        $this->assertSame(1760000000, $row['last_seen_at'], 'a status with no age is a status nobody can weigh');
    }

    /**
     * WHAT MAY BE DONE IS THE SERVER'S ANSWER. Meta's transition rules are intricate and they
     * change; a copy kept here would fall behind, and the action it wrongly offered would
     * cost the merchant a review cycle.
     */
    public function test_it_passes_through_the_allowed_actions_rather_than_deriving_them(): void
    {
        $owner = $this->connectedOwner();
        $this->lynomiaScript['templates'] = [
            $this->approved(['id' => 31, 'allowed_actions' => ['duplicate']]),
            $this->approved(['id' => 32, 'state' => 'draft', 'meta_status' => null,
                'allowed_actions' => ['edit', 'submit', 'destroy']]),
        ];

        $body = $this->actingAs($owner)->getJson(self::TEMPLATES)->assertOk()->json();

        $this->assertSame(['duplicate'], $body['templates'][0]['allowed_actions']);
        $this->assertSame(['edit', 'submit', 'destroy'], $body['templates'][1]['allowed_actions']);
    }

    public function test_it_passes_through_the_validation_problems_so_a_submission_is_not_wasted(): void
    {
        $owner = $this->connectedOwner();
        $this->lynomiaScript['templates'] = [$this->approved([
            'state' => 'draft', 'meta_status' => null,
            'validation_problems' => ['The body must not end with a variable.'],
        ])];

        $row = $this->actingAs($owner)->getJson(self::TEMPLATES)->assertOk()->json('templates.0');

        $this->assertSame(['The body must not end with a variable.'], $row['validation_problems']);
    }

    /** A template deleted at Meta must not linger here reading "approved". */
    public function test_it_reports_a_template_that_is_no_longer_at_meta(): void
    {
        $owner = $this->connectedOwner();
        $this->lynomiaScript['templates'] = [$this->approved(['missing_at_meta' => true])];

        $this->assertTrue($this->actingAs($owner)->getJson(self::TEMPLATES)->assertOk()->json('templates.0.missing_at_meta'));
    }

    /** Nothing about a template is kept on this side; a third copy would be the stalest. */
    public function test_nothing_about_a_template_is_stored_locally(): void
    {
        $owner = $this->connectedOwner();
        $this->lynomiaScript['templates'] = [$this->approved()];
        $this->actingAs($owner)->getJson(self::TEMPLATES)->assertOk();

        // Scoped to THIS MODULE's tables: the platform has email_template_langs and others
        // that have nothing to do with WhatsApp templates, and asserting over every table
        // would be asserting about somebody else's schema.
        $mine = array_filter(
            \Illuminate\Support\Facades\DB::connection()->getSchemaBuilder()->getTableListing(),
            static fn ($table) => str_starts_with(strtolower(basename((string) $table)), 'lynomia_')
        );
        $this->assertNotEmpty($mine, 'the module has tables, so this filter is not vacuous');

        foreach ($mine as $table) {
            $this->assertStringNotContainsString('template', strtolower((string) $table),
                'a local template table would be a third copy of a status Meta owns');
        }
    }

    // ================================================================ writing

    public function test_a_merchant_can_create_a_draft(): void
    {
        $owner = $this->connectedOwner();

        $this->actingAs($owner)->postJson(self::TEMPLATES, [
            'inbox_id' => 7, 'name' => 'spring_offer', 'language' => 'ar', 'category' => 'MARKETING',
            'components' => [['type' => 'BODY', 'text' => 'Hello']],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'whatsapp/message_templates')
            && strtoupper($request->method()) === 'POST');
    }

    /**
     * Name, language and category are Meta's idea of a template's IDENTITY and cannot change
     * after creation. Sending them on an edit would ask for a refusal the merchant cannot act
     * on, so they are not sent.
     */
    public function test_an_edit_does_not_try_to_change_the_identity_fields(): void
    {
        $owner = $this->connectedOwner();

        $this->actingAs($owner)->patchJson(self::TEMPLATES . '/31', [
            'name' => 'renamed', 'language' => 'en', 'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Changed']],
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (strtoupper($request->method()) !== 'PATCH') { return false; }
            $body = json_decode($request->body(), true) ?? [];

            return ! array_key_exists('name', $body)
                && ! array_key_exists('language', $body)
                && ! array_key_exists('category', $body)
                && isset($body['components']);
        });
    }

    public function test_a_malformed_draft_is_refused_at_the_boundary_without_calling_lynomia(): void
    {
        $owner = $this->connectedOwner();
        Http::fake();

        $this->actingAs($owner)->postJson(self::TEMPLATES, ['name' => '', 'components' => []])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_merchant_can_submit_a_draft_for_review(): void
    {
        $owner = $this->connectedOwner();

        $this->actingAs($owner)->postJson(self::TEMPLATES . '/31/submit')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'message_templates/31/submit'));
    }

    /**
     * A SUBMISSION IS NEVER RETRIED ON THE WIRE. A retry Meta saw the first time starts a
     * SECOND review of the same template, and a merchant waiting on a verdict now has two.
     * Asserted by counting what actually went out when the first attempt fails.
     */
    public function test_a_failed_submission_is_not_retried(): void
    {
        $owner = $this->connectedOwner();
        $this->lynomiaScript['fail'] = [
            'POST /api/v1/accounts/7001/whatsapp/message_templates/31/submit' => Http::failedConnection(),
        ];

        $this->actingAs($owner)->postJson(self::TEMPLATES . '/31/submit')->assertStatus(503);

        $attempts = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'message_templates/31/submit'))
            ->count();
        $this->assertSame(1, $attempts, 'a retried submission would start a second review at Meta');
    }

    public function test_a_merchant_can_duplicate_a_template_into_a_new_draft(): void
    {
        $owner = $this->connectedOwner();

        $this->actingAs($owner)->postJson(self::TEMPLATES . '/31/duplicate')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'message_templates/31/duplicate'));
    }

    public function test_a_merchant_can_delete_a_template(): void
    {
        $owner = $this->connectedOwner();

        $this->actingAs($owner)->deleteJson(self::TEMPLATES . '/31')->assertOk()->assertJson(['deleted' => true]);
    }

    /**
     * Upstream requires an account administrator for every template action, and only the
     * store owner holds that identity -- so the sentence says who to ask rather than leaving
     * a staff member at "forbidden".
     */
    public function test_a_refusal_from_lynomia_names_who_can_do_it_instead(): void
    {
        $owner = $this->connectedOwner();
        $this->lynomiaScript['fail'] = [
            'POST /api/v1/accounts/7001/whatsapp/message_templates/31/submit' => Http::response(['error' => 'forbidden'], 403),
        ];

        $response = $this->actingAs($owner)->postJson(self::TEMPLATES . '/31/submit')->assertStatus(403);

        $this->assertStringContainsString('store owner', $response->json('error'));
    }

    /** Meta's own refusal reaches the merchant, because which input is wrong is the useful part. */
    public function test_a_rules_refusal_from_meta_is_reported_rather_than_pre_judged(): void
    {
        $owner = $this->connectedOwner();
        $this->lynomiaScript['fail'] = [
            'POST /api/v1/accounts/7001/whatsapp/message_templates' => Http::response(
                ['error' => ['message' => 'Body must not end with a variable']], 422
            ),
        ];

        $this->actingAs($owner)->postJson(self::TEMPLATES, [
            'inbox_id' => 7, 'name' => 'bad', 'language' => 'ar', 'category' => 'MARKETING',
            'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']],
        ])->assertStatus(409)->assertJsonStructure(['error']);
    }
}
