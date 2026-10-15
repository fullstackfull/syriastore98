<?php

namespace Tests\Feature\Lynomia;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;

/**
 * Phase 4's security properties, pinned as PROPERTIES rather than as behaviours.
 *
 * Every assertion here is of the form "this cannot be true of the source, or of the booted
 * router, or of any response this surface produces", so it keeps holding as the code moves. The
 * behaviour tests next door prove the Studio works; these prove the ways it could stop being
 * safe are closed, including for code nobody has written yet.
 *
 * WHAT PHASE 4 ADDED THAT IS WORTH GUARDING:
 *   a second BFF            a merchant browser that can now change what the store SENDS, not
 *                           only what one agent replies
 *   two more permissions    which only narrow, and must be proven unable to widen
 *   a consent writer        the one place a merchant can decide whether a person is messaged
 *   a 390-line canvas       merchant-typed text rendered into an SVG document
 */
class LynomiaPhase4SecurityTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const MODULE = 'packages/workdo/LynomiaChat/src';

    /** Everything Phase 4 added or changed on the merchant surface. */
    private const PHASE_4_SOURCES = [
        'app/Http/Controllers/AutomationApiController.php',
        'app/Http/Controllers/AutomationController.php',
        'app/Http/Controllers/Concerns/ProxiesToLynomia.php',
    ];

    private const PHASE_4_VIEWS = [
        'resources/views/merchant/automation.blade.php',
        'resources/views/merchant/builder.blade.php',
        'resources/views/merchant/commerce-automation.blade.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    /** Only what runs: // # and block comments removed, for the reason Phase 3's scanner strips them. */
    private function executableText(string $relative): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents(base_path(self::MODULE . '/' . $relative))) as $token) {
            $out .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token;
        }

        return $out;
    }

    private function connectedTenant(): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        app(AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store];
    }

    /** Every route the Studio, the builder and the commerce page expose. */
    private function studioRoutes(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'lynomia.automation'))
            ->all();
    }

    // ---------------------------------------------------------------- tenancy

    /**
     * THE RULE PHASE 3 ESTABLISHED, HELD BY A SURFACE THAT DID NOT EXIST THEN. The tenant of a
     * Studio request is the store EnsureStoreOwnsLink resolved and checked, carried on the
     * request; a controller that read users.current_store itself would answer for whatever
     * store the person last clicked in another tab.
     */
    public function test_no_phase_4_controller_resolves_a_tenant_for_itself(): void
    {
        foreach (self::PHASE_4_SOURCES as $file) {
            $code = $this->executableText($file);

            foreach (['getCurrentStore', 'current_store', 'creatorId', 'Auth::user'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    "{$file} resolves a tenant itself instead of taking the one the middleware authorized");
            }
        }
    }

    /**
     * NO IDENTIFIER IN ANY SIGNATURE NAMES A TENANT. A flow id, an inbox id, a rule id and a
     * contact id are all accepted -- they have to be -- and none of them is trusted: the
     * credential is scoped to one workspace server side, so another store's id resolves to
     * nothing. What must never appear is an account_id, a store_id or a link id, because those
     * WOULD choose a tenant.
     */
    public function test_no_studio_route_takes_an_account_store_or_link_from_the_caller(): void
    {
        foreach ($this->studioRoutes() as $route) {
            foreach (['account', 'account_id', 'store', 'store_id', 'link'] as $forbidden) {
                $this->assertNotContains($forbidden, $route->parameterNames(),
                    "{$route->getName()} lets the caller name a tenant");
            }
        }
    }

    public function test_every_studio_route_is_gated_on_session_entitlement_tenancy_and_permission(): void
    {
        $routes = $this->studioRoutes();
        $this->assertGreaterThanOrEqual(20, count($routes), 'the Studio surface shrank unexpectedly');

        foreach ($routes as $route) {
            $stack = array_filter(app('router')->gatherRouteMiddleware($route), 'is_string');
            $name = $route->getName();

            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, $name);
            $this->assertContains(\Illuminate\Session\Middleware\StartSession::class, $stack, $name);
            $this->assertContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $stack, $name);
            $this->assertContains(\App\Http\Middleware\EmailIsVerified::class, $stack, $name);
            $this->assertTrue((bool) preg_grep('/PlanModuleCheck:LynomiaChat/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Automation/', $stack), $name);
        }
    }

    /**
     * THE FIRST SURFACE THAT AMPLIFIES. An inbox request is roughly one Lynomia call; opening
     * the builder is the flow AND four reference reads AND the condition vocabulary, so one
     * merchant holding refresh costs Lynomia several times what the same behaviour costs from
     * the inbox. The budget is per PERSON, because the cost is per credential minted -- keying
     * it on the store would let one person's stuck poll exhaust their colleagues'.
     */
    public function test_every_studio_api_route_carries_its_own_budget(): void
    {
        $api = array_filter($this->studioRoutes(),
            fn ($route) => str_contains((string) $route->getName(), '.api.'));

        $this->assertNotEmpty($api);

        foreach ($api as $route) {
            $stack = array_filter(app('router')->gatherRouteMiddleware($route), 'is_string');

            $this->assertTrue((bool) preg_grep('/ThrottleRequests:lynomia-studio/', $stack),
                "{$route->getName()} has no rate limit");
        }
    }

    public function test_the_studio_budget_is_keyed_on_the_person_and_refuses_past_it(): void
    {
        [$owner] = $this->connectedTenant();
        config()->set('lynomia.studio_rate_per_minute', 3);

        foreach (range(1, 3) as $attempt) {
            $this->actingAs($owner)->getJson('/lynomia/automation/api/flows')->assertOk();
        }

        $this->actingAs($owner)->getJson('/lynomia/automation/api/flows')->assertStatus(429);

        // A colleague's budget is their own: one person's loop does not silence the store.
        $colleague = $this->staffOf($owner);
        $this->grantLynomiaPermission($colleague, ['Manage Lynomia Chat', 'Manage Lynomia Automation']);
        $this->actingAs($colleague->fresh())->getJson('/lynomia/automation/api/flows')->assertOk();
    }

    // ---------------------------------------------------------------- credentials

    /**
     * THE PLATFORM CREDENTIAL SERVES NO MERCHANT REQUEST. It can create workspaces and reaches
     * every account this app holds; a Studio controller that reached for it would be a merchant
     * browser indirectly holding it. Every call goes through MerchantInboxGateway, which mints a
     * credential confined to one workspace and carrying one person's rights.
     */
    public function test_no_phase_4_controller_can_reach_the_platform_credential(): void
    {
        foreach (self::PHASE_4_SOURCES as $file) {
            $code = $this->executableText($file);

            foreach (['PlatformApiClient', 'platform_token', 'issueScopedAccessToken'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file} reaches for the platform credential");
            }
        }
    }

    public function test_no_phase_4_page_ships_a_credential_to_the_browser(): void
    {
        foreach (self::PHASE_4_VIEWS as $view) {
            $source = (string) file_get_contents(base_path(self::MODULE . '/' . $view));

            foreach (['platform_token', 'api_access_token', 'scoped_access_token', 'LYNOMIA_PLATFORM_TOKEN',
                'credentials', 'access_token'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$view} mentions a credential");
            }
        }
    }

    public function test_no_studio_response_carries_the_scoped_token_or_a_bot_secret(): void
    {
        [$owner] = $this->connectedTenant();

        foreach (['/lynomia/automation/api/flows', '/lynomia/automation/api/flows/42',
            '/lynomia/automation/api/flows/42/builder', '/lynomia/automation/api/references',
            '/lynomia/automation/api/channels', '/lynomia/automation/api/rules',
            '/lynomia/automation/api/starters', '/lynomia/automation/api/flows/42/history',
            '/lynomia/automation/api/consents'] as $path) {
            $body = $this->actingAs($owner)->getJson($path)->getContent();

            foreach (['SCOPED-TOKEN', 'BOT-SECRET-MUST-NOT-LEAK', 'BOT-TOKEN-MUST-NOT-LEAK',
                'INBOX-SECRET-MUST-NOT-LEAK', 'test-token-not-a-real-credential'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "{$path} leaked {$secret}");
            }
        }
    }

    // ---------------------------------------------------------------- the browser

    /**
     * MERCHANT TEXT IS WRITTEN, NEVER INJECTED. A flow name, a choice title and a customer's
     * name are all attacker-controllable in the sense that matters: anybody who can message the
     * store, or any staff member, can put a string in one. The canvas renders them as SVG and
     * DOM text nodes; `.html()` anywhere in these pages would be the one place that stops being
     * true. The project's own CSP is not relied on, because a page that only survives behind a
     * header is a page that breaks when the header moves.
     */
    public function test_no_phase_4_page_renders_merchant_text_as_html(): void
    {
        foreach (self::PHASE_4_VIEWS as $view) {
            $source = (string) file_get_contents(base_path(self::MODULE . '/' . $view));
            $code = preg_replace(['/\{\{--.*?--\}\}/s', '~^\s*//.*$~m'], '', $source);

            foreach (['.html(', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write',
                'eval(', 'new Function('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$view} uses {$forbidden}");
            }
        }
    }

    /**
     * NO PAGE REACHES LYNOMIA DIRECTLY. The browser has no credential it could use and no CORS
     * allowance it could use it with, so a hardcoded Lynomia URL in a page is either dead code
     * or an attempt to do the one thing the BFF exists to prevent.
     */
    public function test_no_phase_4_page_names_a_lynomia_address(): void
    {
        foreach (self::PHASE_4_VIEWS as $view) {
            $source = (string) file_get_contents(base_path(self::MODULE . '/' . $view));
            $code = preg_replace(['/\{\{--.*?--\}\}/s', '~^\s*//.*$~m'], '', $source);

            foreach (['chat.lynomia', 'lynomia.base_url', '/api/v1/accounts/', '/platform/api/'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$view} addresses Lynomia directly");
            }
        }
    }

    // ---------------------------------------------------------------- permissions

    /**
     * THE PERMISSIONS NARROW AND CANNOT WIDEN, which is the claim the whole staff-access story
     * rests on. A staff member holding every Store Syria permission still carries an AGENT
     * identity in the workspace, and Lynomia authorizes flow management as an administrator --
     * so the call is made and refused THERE. A Store Syria permission that could grant flow
     * management would be this integration inventing authority Lynomia never gave it.
     */
    public function test_a_store_syria_permission_cannot_grant_what_lynomia_refuses(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, [
            'Manage Lynomia Chat', 'Reply Lynomia Chat', 'Assign Lynomia Chat',
            'Manage Lynomia Automation', 'Manage Lynomia Consent',
        ]);

        $this->fakeLynomia(['fail' => [
            'GET /api/v1/accounts/7001/flows' => Http::response(['error' => 'You are not authorized'], 403),
        ]]);

        $response = $this->actingAs($staff->fresh())->getJson('/lynomia/automation/api/flows');

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Only the store owner can manage automations.');
    }

    /**
     * NO IDENTITY IS MINTED FOR A PERSON THE MIDDLEWARE HAS NOT ADMITTED. Reaching the gateway
     * is what creates a Lynomia user for someone, so a refused request must stop before it --
     * otherwise a staff member who may not open the Studio still has a workspace identity
     * created for them by trying.
     */
    public function test_a_refused_request_mints_no_identity_and_sends_nothing(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat']);
        $before = \Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity::count();
        Http::fake();

        $this->actingAs($staff->fresh())->getJson('/lynomia/automation/api/flows')->assertForbidden();

        $this->assertSame($before, \Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity::count());
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- consent

    /**
     * CONSENT IS WRITTEN IN ONE PLACE, AND IT IS NOT THIS DATABASE. The decision governs what
     * LYNOMIA sends; a copy kept here would be a second answer to "may we message this person",
     * and the gate would keep reading the other one. The same rule the inbox follows for orders.
     */
    public function test_consent_is_never_stored_on_this_side(): void
    {
        $code = $this->executableText('app/Http/Controllers/AutomationApiController.php');

        foreach (['MessagingConsent', 'consents_table', 'DB::table', 'Schema::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "the Studio writes consent locally via {$forbidden}");
        }

        $this->assertStringContainsString('recordConsent', $code, 'the Studio no longer proxies consent at all');
    }

    /**
     * A MERCHANT CANNOT RECORD A PROVIDER BLOCK. `suppressed` is this system's word for "the
     * number cannot receive", and a merchant able to set it could silence a customer in a way
     * that looks like a technical fault rather than a decision. Refused at the boundary, before
     * anything is sent.
     */
    public function test_a_merchant_cannot_record_a_provider_suppression(): void
    {
        [$owner] = $this->connectedTenant();
        Http::fake();

        $this->actingAs($owner)->putJson('/lynomia/automation/api/consents/55', [
            'purpose' => 'promotional', 'state' => 'suppressed', 'note' => 'Pretend it bounced',
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- production restrictions

    /**
     * NOTHING IN PHASE 4 TURNS ANYTHING ON. The Studio sends no message, enables no provider and
     * flips no switch: it edits a definition that Lynomia runs, under Lynomia's own kill
     * switches and entitlements. A Store Syria source that wrote one of those would be this side
     * granting itself a capability the architecture says it does not have.
     */
    public function test_nothing_in_the_studio_enables_a_provider_or_sends_a_message(): void
    {
        foreach (self::PHASE_4_SOURCES as $file) {
            $code = $this->executableText($file);

            foreach (['COMMERCE_ALLOW_PRE_UAT', 'RECOVERY_ENABLED', 'ACTIONS_ENABLED',
                'sendMessage', 'send_message(', 'Mail::', 'Notification::'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    "{$file} reaches past the Studio's remit with {$forbidden}");
            }
        }
    }

    /**
     * The Automation tab answers "who on this side can build an automation" WITHOUT reaching
     * Lynomia -- which matters, because the times an operator opens this page are the times
     * Lynomia may be the thing that is wrong. And it counts; it never lists other tenants'
     * employees by name.
     */
    public function test_the_super_admin_automation_tab_counts_without_lynomia_and_names_nobody(): void
    {
        // The tenant fixture already grants the owner every module permission, which is what
        // gives the tab something to count.
        [$owner] = $this->connectedTenant();
        $this->fakeLynomia();
        $this->actingAs($this->operator());

        // THE CONTROLLER, NOT THE ROUTE. Every Super Admin page in this installation sits
        // behind the platform's own update check, which redirects to /update in a test
        // environment -- a pre-existing condition of that route group and nothing to do with
        // this tab. What is under test is the data the page is built from.
        $data = app(\Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController::class)
            ->show()->getData();

        // It answered without reaching Lynomia, which is the point: the times an operator
        // opens this page are the times Lynomia may be the thing that is wrong.
        Http::assertNothingSent();

        $access = collect($data['automationAccess'])->keyBy('name');
        $this->assertSame(
            \Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController::MODULE_PERMISSIONS,
            $access->keys()->all()
        );
        $this->assertGreaterThan(0, $access['Manage Lynomia Automation']['roles']);
        $this->assertGreaterThan(0, $access['Manage Lynomia Consent']['roles']);

        // Counts, never people: a list of everyone holding a permission is a list of other
        // tenants' employees.
        $rendered = json_encode($data['automationAccess']);
        $this->assertStringNotContainsString($owner->email, $rendered);
        $this->assertStringNotContainsString($owner->name, $rendered);

        // And the view really has the tab the data is for.
        $view = (string) file_get_contents(base_path(self::MODULE . '/resources/views/superadmin/integration.blade.php'));
        $this->assertStringContainsString('lynomia-automation', $view);
        $this->assertStringContainsString('automationAccess', $view);
    }

    /**
     * THE PRODUCTION GRANT IS ONE GLOBAL ROLE, AND IT MUST NOT READ AS "NOBODY".
     *
     * PermissionTableSeeder grants all five permissions to a single `admin` role with no
     * store_id -- that is how every merchant owner on the installation gets them. Counted as
     * `COUNT(DISTINCT store_id)` it comes back 0, because SQL skips NULL, so the broadest grant
     * there is rendered as the narrowest. The two numbers are kept apart for that reason, and
     * this pins the production shape rather than the per-store shape the other fixture builds.
     */
    public function test_the_automation_tab_counts_a_platform_wide_grant_as_platform_wide(): void
    {
        $this->fakeLynomia();

        $permission = Permission::firstOrCreate(
            ['name' => 'Manage Lynomia Automation'],
            ['display_name' => 'Manage Lynomia Automation', 'guard_name' => 'web']
        );
        $permission->forceFill(['module' => 'LynomiaChat'])->save();

        // No store_id: the shape the seeder creates.
        $role = Role::forceCreate(['name' => 'admin-global', 'display_name' => 'Admin', 'guard_name' => 'web']);
        $this->assertNull($role->store_id);
        $role->permissions()->sync([$permission->id]);

        $this->actingAs($this->operator());
        $data = app(\Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController::class)
            ->show()->getData();

        $row = collect($data['automationAccess'])->firstWhere('name', 'Manage Lynomia Automation');
        $this->assertSame(1, $row['roles']);
        $this->assertSame(1, $row['platform_wide'], 'A role with no store_id reaches every tenant.');
        $this->assertSame(0, $row['stores'], 'It is not confined to a store, so it covers no named store.');
    }

    /**
     * THE ADMIN PAGE REPORTS, IT DOES NOT DUPLICATE. Store Syria's Super Admin page says who on
     * this side may build an automation; the engine's ceilings and kill switches stay in
     * Lynomia, because a second switch for the same thing is a switch that disagrees.
     */
    public function test_the_super_admin_page_duplicates_no_lynomia_switch(): void
    {
        $code = $this->executableText('app/Http/Controllers/SuperAdmin/IntegrationController.php');

        foreach (['flow_ceiling', 'lynomia_flow_builder', 'RECOVERY_ENABLED', 'MAX_NODES'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "the Super Admin page now carries a second copy of {$forbidden}");
        }

        // And it reads one list of permissions, shared with the seeder that creates them.
        $this->assertSame(
            \Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController::MODULE_PERMISSIONS,
            // Raised for the two permissions Phase 5 genuinely adds. An audience decides WHO
            // gets messaged; a template is the text Meta approves and every campaign then
            // sends, and submitting one spends a review cycle that cannot be taken back.
            // Updated because permissions were really added, which is the change this pin
            // exists to make visible -- not relaxed into a count or a subset check, which
            // would stop it noticing the next one.
            ['Manage Lynomia Chat', 'Reply Lynomia Chat', 'Assign Lynomia Chat',
                'Manage Lynomia Automation', 'Manage Lynomia Consent', 'Manage Lynomia Audiences',
                'Manage Lynomia Templates']
        );
    }
}
