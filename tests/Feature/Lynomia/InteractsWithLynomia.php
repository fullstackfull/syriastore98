<?php

namespace Tests\Feature\Lynomia;

use App\Models\AddOnManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Support\Facades\Http;

/**
 * The shared stand-in for a Lynomia installation, and the tenant fixtures that talk to it.
 *
 * EXTRACTED, NOT DUPLICATED. Two test classes now exercise this module -- the connection
 * lifecycle and the per-person identity model -- and they need the same scriptable fake, the
 * same entitled tenant and the same traffic counters. Copying them would mean two fakes
 * drifting apart, and a drifted fake is a test suite that agrees with itself about an API
 * neither half matches.
 *
 * NOTHING HERE MAY REACH THE NETWORK: bootLynomiaHarness() installs a dummy base URL, a dummy
 * token, and Http::preventStrayRequests(), so a call nobody scripted fails loudly instead of
 * dialling out.
 */
trait InteractsWithLynomia
{
    protected function bootLynomiaHarness(): void
    {
        config()->set('lynomia.enabled', true);
        config()->set('lynomia.base_url', self::FAKE_BASE);
        config()->set('lynomia.platform_token', 'test-token-not-a-real-credential');
        // The master key every store's webhook secret is derived from. A dummy, and the
        // derivation is what the signature tests exercise -- not this value.
        config()->set('lynomia.webhook_master_secret', 'test-master-not-a-real-secret');

        // The client is a singleton built from config at resolve time, so the config above
        // must be set before anything resolves it.
        $this->app->forgetInstance(\Workdo\LynomiaChat\app\Services\PlatformApiClient::class);

        // A DELETE ANSWERS WITH NOTHING, which is what Rails `head :ok` sends and what this
        // double used to get wrong. `Http::response([], 200)` is the two bytes `[]`, so every
        // delete in this suite exercised a body Lynomia never sends -- and LynomiaHttp read a
        // zero-length body as a non-JSON one and turned a successful delete into a 503 with a
        // red banner. The stubs for the five `head :ok` endpoints now send nothing at all.
        // NOTHING IN THESE TESTS MAY REACH THE NETWORK. Http::fake() with an ARRAY does not
        // stop a stray call: an unmatched URL is executed for real, so a test that adds a
        // new remote call and forgets to stub it dials out of the sandbox and fails with a
        // proxy error rather than saying what it did. This turns that into an explicit
        // refusal. It is belt and braces over the closure fake, which always answers --
        // the point is that it keeps answering after someone adds a call it has not met.
        Http::preventStrayRequests();

        // The module must be in the add-on registry for ActivatedModule() to see it at all.
        AddOnManager::updateOrCreate(['module' => 'LynomiaChat'], [
            'name' => 'Lynomia Chat',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'lynomia-chat',
        ]);
    }

    protected const FAKE_BASE = 'https://lynomia.test';


    // ---------------------------------------------------------------- fixtures

    protected function merchant(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'type' => 'admin',
            'created_by' => 1,
            'is_active' => 1,
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function operator(): User
    {
        return User::factory()->create([
            'type' => 'super admin',
            'created_by' => null,
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);
    }

    protected function storeFor(User $owner): Store
    {
        $store = Store::create([
            'name' => 'Store ' . uniqid(),
            'email' => 'store' . uniqid() . '@example.test',
            'theme_id' => '1',
            'slug' => 'store-' . $owner->id . '-' . uniqid(),
            'created_by' => $owner->id,
            'is_active' => 1,
        ]);

        $owner->current_store = $store->id;
        $owner->save();

        return $store;
    }

    /** Grant the merchant's tenant the module, which is what PlanModuleCheck resolves. */
    protected function entitle(User $owner): void
    {
        userActiveModule::updateOrCreate(
            ['user_id' => $owner->id, 'module' => 'LynomiaChat'],
            []
        );

        // Entitlement is the PLAN's answer; the permission is the ROLE's. A merchant owner
        // holds both in production -- PermissionTableSeeder grants all three module
        // permissions to the global `admin` role that every owner carries -- so the fixture
        // gives both. They are granted together here precisely because they are NOT the same
        // control: the staff tests entitle the tenant and withhold individual permissions.
        $this->grantLynomiaPermission($owner, [
            'Manage Lynomia Chat', 'Reply Lynomia Chat', 'Assign Lynomia Chat',
            'Manage Lynomia Automation', 'Manage Lynomia Consent', 'Manage Lynomia Audiences', 'Manage Lynomia Templates', 'Manage Lynomia Campaigns',
        ]);
    }

    /**
     * Give a user a real Laratrust role carrying the module's permission.
     *
     * forceCreate, not create: App\Models\Role sets `public $guarded = []` but extends
     * Laratrust's Role, whose non-empty `protected $fillable` wins in isFillable(), so
     * store_id and created_by are silently dropped by create().
     */
    protected function grantLynomiaPermission(User $user, array $names = ['Manage Lynomia Chat']): void
    {
        // forceFill for `module`, for the same reason the role below uses forceCreate:
        // App\Models\Permission sets `public $guarded = []` but extends Laratrust's Permission,
        // whose non-empty `protected $fillable` wins in isFillable() -- so `module` is silently
        // dropped by create() and firstOrCreate(). PermissionTableSeeder gets it right by
        // wrapping the whole run in Model::unguard(); a fixture that did not would produce
        // permissions with a NULL module, which is not what production has and not what the
        // Super Admin page counts.
        $permissions = array_map(static function (string $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['display_name' => $name, 'guard_name' => 'web']);

            if ($permission->module !== 'LynomiaChat') {
                $permission->forceFill(['module' => 'LynomiaChat'])->save();
            }

            return $permission;
        }, $names);

        $role = Role::firstWhere('name', 'lynomia-role-' . $user->id)
            ?? Role::forceCreate([
                'name' => 'lynomia-role-' . $user->id,
                'display_name' => 'Lynomia Role',
                'guard_name' => 'web',
                'created_by' => $user->creatorId(),
                'store_id' => $user->current_store,
            ]);

        foreach ($permissions as $permission) {
            $role->givePermission($permission);
        }
        $role->flushCache();

        // addRole unconditionally, and NEVER probe with hasRole() first: Laratrust memoises
        // the user's role set on the first read, so a probe taken before the attach caches an
        // empty set and every later isAbleTo() in the same request answers from it.
        $user->addRole($role);
        $user->flushCache();
    }

    /**
     * A staff member of the owner's tenant with a role that carries NO permissions --
     * the shape UserController::store creates: type = the role's name, created_by = the
     * owner, current_store copied from the owner, email already verified.
     */
    protected function staffOf(User $owner): User
    {
        $staff = User::factory()->create([
            'type' => 'Packing',
            'created_by' => $owner->id,
            'current_store' => $owner->current_store,
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);

        $role = Role::forceCreate([
            'name' => 'packing-' . $staff->id,
            'display_name' => 'Packing',
            'guard_name' => 'web',
            'created_by' => $owner->id,
            'store_id' => $owner->current_store,
        ]);

        $staff->addRole($role);
        $staff->flushCache();

        return $staff->fresh();
    }

    /** @return array{0: User, 1: Store} an entitled merchant with a store selected */
    protected function entitledTenant(): array
    {
        $owner = $this->merchant();
        $store = $this->storeFor($owner);
        $this->entitle($owner);

        return [$owner->fresh(), $store];
    }

    /** The currently scripted behaviour. Read on every faked call, so a test can rescript mid-example. */
    protected array $lynomiaScript = [];

    /** email -> the id the fake Lynomia hands back for it, stable within one test. */
    protected array $lynomiaUserIds = [];

    protected bool $lynomiaFakeRegistered = false;

    /**
     * A scriptable stand-in for the Lynomia Platform API.
     *
     * A CLOSURE, NOT A URL MAP, because two of the calls that matter here share a URL and
     * differ only by verb: POST /accounts creates a workspace and GET /accounts is the
     * recovery lookup that must find one a lost POST already made. A map keyed by URL answers
     * both with the same body and would quietly make every reconciliation test vacuous.
     *
     * REGISTERED ONCE AND RESCRIPTED THROUGH A PROPERTY, because Http::fake() MERGES stub
     * callbacks rather than replacing them (Factory::fake, vendor/laravel/framework), and the
     * earliest registered callback answers first. Calling it a second time with new behaviour
     * therefore changes nothing -- the original closure still wins -- which silently turns a
     * two-phase failure-recovery test into one that re-runs the same failure. The later bare
     * fake() call is kept for its other effect: it resets Http::recorded(), so each phase
     * counts its own traffic.
     *
     * @param array{
     *     account_id?:int, user_id?:int,
     *     inventory?:list<array>,   what GET /accounts already holds -- the orphan a lost create left behind
     *     fail?:array<string,mixed> 'POST /platform/api/v1/accounts' => Http::failedConnection() | Http::response(...)
     * } $options
     */
    protected function fakeLynomia(array $options = []): void
    {
        $this->lynomiaScript = $options;

        if ($this->lynomiaFakeRegistered) {
            // Resets recorded(); the default stub it appends never answers, because the
            // closure below is consulted first and always returns something.
            Http::fake();

            return;
        }

        $this->lynomiaFakeRegistered = true;

        Http::fake(function ($request) {
            $accountId = $this->lynomiaScript['account_id'] ?? 7001;
            $userId = $this->lynomiaScript['user_id'] ?? 9001;
            $inventory = $this->lynomiaScript['inventory'] ?? [];
            $fail = $this->lynomiaScript['fail'] ?? [];

            $method = strtoupper($request->method());
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $key = "{$method} {$path}";

            // Scripted failures take precedence, and are matched on verb+path so a test can
            // break the create without also breaking the lookup that recovers from it.
            // Returned as-is: the factory unwraps a Closure itself, passing the request --
            // which is what Http::failedConnection() needs to build its message.
            if (array_key_exists($key, $fail)) {
                return $fail[$key];
            }

            return match (true) {
                $key === 'GET /platform/api/v1/accounts' => Http::response($inventory, 200),
                $key === 'POST /platform/api/v1/accounts' => Http::response(['id' => $accountId, 'name' => 'WS'], 200),
                // ONE ACCOUNT, READ BACK BY ID -- how this side confirms the configured
                // platform workspace really is platform-owned (B5). Upstream excludes
                // platform-owned accounts from the INDEX, so `show` is the only endpoint that
                // answers for it, and it is permissible-checked.
                //
                // Scriptable in three states, because all three are real:
                //   (default)                     platform_owned: true
                //   'workspace_owned' => false    a merchant's account -- a mistyped id
                //   'workspace_owned' => null     a Lynomia predating the field, which is
                //                                 "could not say", not "no"
                (bool) preg_match('#^/platform/api/v1/accounts/\d+$#', $path) && $method === 'GET'
                    => Http::response(array_filter([
                        'id' => (int) basename($path),
                        'name' => 'WS',
                        'status' => 'active',
                        'platform_owned' => array_key_exists('workspace_owned', $this->lynomiaScript)
                            ? $this->lynomiaScript['workspace_owned']
                            : true,
                    ], static fn ($value) => $value !== null), 200),
                // Upstream echoes the submitted custom_attributes only when it CREATES the
                // user; for an address that already exists it returns that user untouched.
                // `squatted_user` scripts the second case, which is what the provisioner has
                // to be able to tell apart.
                // ONE REMOTE USER PER ADDRESS, which is what makes per-person identities
                // testable at all: a fake that answered with the same id for every address
                // would make "two people, two identities" pass without the code doing it.
                // The same address asked twice returns the same id, exactly as upstream's
                // `User.from_email(...) || User.new(...)` does.
                $key === 'POST /platform/api/v1/users' => Http::response(array_filter([
                    'id' => $this->remoteUserIdFor((string) (json_decode($request->body(), true)['email'] ?? ''), $userId),
                    'email' => 'x@y.z',
                    'custom_attributes' => ($this->lynomiaScript['squatted_user'] ?? false)
                        ? null
                        : (json_decode($request->body(), true)['custom_attributes'] ?? []),
                ], static fn ($value) => $value !== null), 200),
                $key === "POST /platform/api/v1/accounts/{$accountId}/account_users" => Http::response(['id' => 1], 200),
                // Any user's login, because there is no longer one user per workspace.
                (bool) preg_match('#^/platform/api/v1/users/\d+/login$#', $path) && $method === 'GET' => Http::response(
                    ['url' => self::FAKE_BASE . '/app/login?email=x&sso_auth_token=SECRET'],
                    200
                ),
                // The proof names the Lynomia administrator who authorized it and grants
                // nothing on them -- see Platform::Api::V1::AccountLinksController. Who signs
                // in afterwards is a separate, per-person decision.
                $key === 'POST /platform/api/v1/account_links' => Http::response([
                    'account_id' => $accountId,
                    'account_name' => 'Claimed WS',
                    'authorized_by_user_id' => $this->lynomiaScript['authorized_by'] ?? 4242,
                    'authorized_by_name' => 'Merchant Admin',
                ], 201),
                str_starts_with($key, 'DELETE /platform/api/v1/account_links/') => Http::response('', 200),

                // --------------------------------------------------- Phase 2: the BFF's own calls
                //
                // A per-person, workspace-scoped bearer. The literal below is asserted on by the
                // "no credential reaches the browser" test, so it is deliberately recognisable.
                (bool) preg_match('#^/platform/api/v1/users/\d+/scoped_access_token$#', $path) && $method === 'POST'
                    => Http::response([
                        'token' => 'SCOPED-TOKEN-' . (json_decode($request->body(), true)['account_id'] ?? 0),
                        'account_id' => json_decode($request->body(), true)['account_id'] ?? 0,
                        'expires_at' => now()->addHour()->toIso8601String(),
                    ], 200),

                // The agent API, as the merchant person. Shapes mirror the real jbuilder views:
                // conversations nest under data.payload with a meta of bucket counts, messages
                // come back under payload, and a conversation is addressed by display_id.
                (bool) preg_match('#^/api/v1/accounts/\d+/conversations$#', $path) && $method === 'GET'
                    => Http::response(['data' => [
                        'meta' => ['mine_count' => 0, 'assigned_count' => 0, 'unassigned_count' => 0, 'all_count' => 1],
                        'payload' => $this->lynomiaScript['conversations'] ?? [self::sampleConversation()],
                    ]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/conversations/\d+/messages$#', $path) && $method === 'GET'
                    => Http::response(['payload' => $this->lynomiaScript['messages'] ?? [self::sampleMessage()]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/conversations/\d+/messages$#', $path) && $method === 'POST'
                    => Http::response(self::sampleMessage(), 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/conversations/\d+/toggle_status$#', $path)
                    => Http::response(['payload' => ['success' => true]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/conversations/\d+/assignments$#', $path)
                    => Http::response(['id' => 1, 'name' => 'Agent'], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/conversations/\d+/update_last_seen$#', $path)
                    => Http::response([], 200),
                // ------------------------------- Phase 6E: platform notices (account API)
                (bool) preg_match('#^/api/v1/accounts/\\d+/contacts$#', $path) && $method === 'POST'
                    => Http::response(['payload' => ['contact' => ['id' => 4242]]], 200),
                (bool) preg_match('#^/api/v1/accounts/\\d+/conversations$#', $path) && $method === 'POST'
                    => Http::response(['id' => 900, 'display_id' => 77], 200),
                // ------------------------------------ Phase 6B: installation lifecycle
                (bool) preg_match('#^/platform/api/v1/accounts/\\d+/installation_lifecycle$#', $path)
                    => Http::response($this->lynomiaScript['installation_lifecycle']
                        ?? ['provider' => 'storesyria', 'external_store_id' => 'localhost:1',
                            'status' => 'disabled', 'applied' => true], 200),
                // ------------------------------- commerce installation (server to server)
                //
                // The call that removes the token-pasting step. Scriptable so a test can make it
                // answer `applied: false` (already installed), hand back a different remote
                // status (an operator disabled it there), or -- through `fail` above -- refuse
                // with one of the error codes Lynomia defines for it.
                (bool) preg_match('#^/platform/api/v1/accounts/\d+/commerce_installation$#', $path)
                    => Http::response($this->lynomiaScript['commerce_installation'] ?? [
                        'provider' => 'storesyria',
                        'external_store_id' => (string) (json_decode($request->body(), true)['external_store_id'] ?? ''),
                        'commerce_store_id' => 3,
                        'name' => 'Shop',
                        'status' => 'active',
                        'applied' => true,
                    ], 200),
                // -------------------------------------- Phase 6A: cross-boundary health
                // The far side's view of one installation. Scripted so a test can make it
                // critical, unreachable or quiet without reaching for a second fake.
                (bool) preg_match('#^/platform/api/v1/accounts/\d+/integration_health$#', $path) && $method === 'GET'
                    => Http::response($this->lynomiaScript['integration_health'] ?? [
                        'account' => ['id' => 7001, 'name' => 'Merchant'],
                        'status' => 'healthy',
                        'installations' => [[
                            'id' => 3, 'external_store_id' => 'localhost:1', 'provider' => 'storesyria',
                            'name' => 'Shop', 'status' => 'active',
                            'health' => ['api_state' => 'healthy', 'webhook_state' => 'processed',
                                'status' => 'healthy', 'consecutive_failures' => 0],
                        ]],
                        'signals' => ['open' => 0, 'by_source' => [], 'recent' => []],
                        'consent' => ['decisions_held' => 4, 'blocking' => 1, 'last_event_at' => null],
                        'messaging' => ['campaigns_by_status' => [], 'blocked' => 0, 'whatsapp_inboxes' => 1],
                        'generated_at' => '2026-10-16T00:00:00Z',
                    ], 200),
                // --------------------------------------------------- Phase 5D: campaigns
                (bool) preg_match('#^/api/v1/accounts/\d+/campaigns$#', $path) && $method === 'GET'
                    => Http::response(['payload' => $this->lynomiaScript['campaigns'] ?? []], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/campaigns$#', $path) && $method === 'POST'
                    => Http::response(array_merge(
                        ['id' => 51, 'display_id' => 3, 'campaign_status' => 'active'],
                        json_decode($request->body(), true)['campaign'] ?? []
                    ), 200),
                (bool) preg_match('#/campaigns/\d+/cancel$#', $path)
                    => Http::response(['id' => 51, 'display_id' => 3, 'campaign_status' => 'cancelled',
                        'blocked_reason' => json_decode($request->body(), true)['reason'] ?? null], 200),
                (bool) preg_match('#/campaigns/\d+/analytics/metrics$#', $path)
                    => Http::response($this->lynomiaScript['campaign_metrics']
                        ?? ['audience' => 180, 'status_counts' => ['sent' => 40, 'skipped' => 140]], 200),
                (bool) preg_match('#/campaigns/\d+/analytics/contacts$#', $path)
                    => Http::response(['payload' => $this->lynomiaScript['campaign_recipients'] ?? []], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/campaigns/\d+$#', $path) && $method === 'DELETE'
                    => Http::response('', 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/campaigns/\d+$#', $path)
                    => Http::response(array_merge(
                        ['id' => 51, 'display_id' => 3, 'campaign_status' => 'active'],
                        json_decode($request->body(), true)['campaign'] ?? []
                    ), 200),

                // --------------------------------------------------- Phase 5C: templates
                //
                // The manager's own endpoints. `allowed_actions` and `validation_problems`
                // are scripted, because what the page may OFFER is the server's answer and a
                // stub that always returned the same set would make the "renders only what
                // the server allows" test vacuous.
                (bool) preg_match('#^/api/v1/accounts/\d+/whatsapp/message_templates$#', $path) && $method === 'GET'
                    // SHAPED LIKE THE REAL PRODUCER: upstream puts the WABA contexts under
                    // `meta.whatsapp_business_accounts`, as a LIST of {id, inboxes,
                    // last_synced_at}. This fake used to invent a top-level `waba_contexts`
                    // map, the controller read that invented key, and both were green while
                    // the real page's WhatsApp-number picker was permanently empty.
                    => Http::response([
                        'payload' => $this->lynomiaScript['templates'] ?? [],
                        'meta' => ['whatsapp_business_accounts' => $this->lynomiaScript['waba_contexts'] ?? []],
                    ], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/whatsapp/message_templates$#', $path) && $method === 'POST'
                    => Http::response(array_merge(
                        ['id' => 31, 'state' => 'draft', 'allowed_actions' => ['edit', 'submit', 'destroy'],
                            'validation_problems' => []],
                        json_decode($request->body(), true) ?? []
                    ), 201),
                (bool) preg_match('#/whatsapp/message_templates/\d+/submit$#', $path)
                    => Http::response(['id' => 31, 'state' => 'submitting', 'allowed_actions' => [],
                        'validation_problems' => []], 200),
                (bool) preg_match('#/whatsapp/message_templates/\d+/duplicate$#', $path)
                    => Http::response(['id' => 32, 'state' => 'draft', 'name' => 'copy',
                        'allowed_actions' => ['edit', 'submit', 'destroy'], 'validation_problems' => []], 201),
                (bool) preg_match('#/whatsapp/message_templates/\d+$#', $path) && $method === 'DELETE'
                    => Http::response('', 200),
                (bool) preg_match('#/whatsapp/message_templates/\d+$#', $path)
                    => Http::response(array_merge(
                        ['id' => 31, 'state' => 'draft', 'allowed_actions' => ['edit', 'submit', 'destroy'],
                            'validation_problems' => []],
                        json_decode($request->body(), true) ?? []
                    ), 200),

                // --------------------------------------------------- Phase 5B: audiences
                //
                // The saved contact filters a merchant's audiences are. Scripted rather than
                // fixed, because the list is what the page renders and a test that could not
                // vary it would only ever prove the empty case.
                (bool) preg_match('#^/api/v1/accounts/\d+/custom_filters$#', $path) && $method === 'GET'
                    => Http::response(['payload' => $this->lynomiaScript['audiences'] ?? []], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/custom_filters$#', $path) && $method === 'POST'
                    => Http::response(array_merge(
                        ['id' => 12, 'shared' => true, 'filter_type' => 'contact'],
                        json_decode($request->body(), true)['custom_filter'] ?? []
                    ), 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/custom_filters/\d+$#', $path) && $method === 'DELETE'
                    => Http::response('', 204),
                (bool) preg_match('#^/api/v1/accounts/\d+/custom_filters/\d+$#', $path)
                    => Http::response(array_merge(
                        ['id' => 12, 'shared' => true, 'filter_type' => 'contact'],
                        json_decode($request->body(), true)['custom_filter'] ?? []
                    ), 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/commerce/audience_fields$#', $path)
                    => Http::response($this->lynomiaScript['audience_fields']
                        ?? ['stores' => [], 'currencies' => [], 'unread_contacts' => 0], 200),
                // TWO ANSWERS FROM ONE STUB, told apart by whether the request names a store:
                // the scoped count and the account-wide one are the two numbers the page
                // shows side by side, and a stub that answered the same for both would make
                // the store-scope test vacuous.
                (bool) preg_match('#^/api/v1/accounts/\d+/campaigns/audience_preview$#', $path)
                    => Http::response([
                        'count' => (json_decode($request->body(), true)['commerce_store_id'] ?? null) !== null
                            ? ($this->lynomiaScript['audience_preview']['scoped'] ?? 0)
                            : ($this->lynomiaScript['audience_preview']['all'] ?? 0),
                    ], 200),

                (bool) preg_match('#^/api/v1/accounts/\d+/agents$#', $path)
                    => Http::response([['id' => 1, 'name' => 'Agent', 'email' => 'agent@example.test']], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/contacts/\d+$#', $path)
                    => Http::response(['payload' => ['id' => 55, 'name' => 'Customer', 'email' => null]], 200),
                // The conversation's commerce section. The panel's shape mirrors
                // Commerce::ConversationPanel: a state, the link when there is one, and the
                // linked customer's orders as Commerce::Order serialises them.
                (bool) preg_match('#^/api/v1/accounts/\d+/conversations/\d+/commerce/stores$#', $path)
                    => Http::response(['payload' => $this->lynomiaScript['commerce_stores'] ?? [
                        ['id' => 31, 'name' => 'Damascus Store', 'provider' => 'storesyria', 'linked' => true,
                            'actions' => false, 'carts' => true],
                    ]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/conversations/\d+/commerce/stores/\d+$#', $path)
                    => Http::response($this->lynomiaScript['commerce_panel'] ?? self::sampleCommercePanel(), 200),
                // Seats a Store Syria package includes. `effective` is what Lynomia actually
                // enforces -- the grant composed with any plan and any operator override -- not
                // an echo of what was granted.
                // Partner consent. The OUTCOME is the point of the response, not the status:
                // a test scripts `partner_consent_outcome` to make Lynomia report that it
                // applied the decision, ignored a duplicate, or refused a grant that arrived
                // after a withdrawal -- each of which the dispatcher must settle differently.
                (bool) preg_match('#^/platform/api/v1/accounts/\d+/partner_consents$#', $path) && $method === 'POST'
                    => Http::response([
                        'outcome' => $this->lynomiaScript['partner_consent_outcome'] ?? 'applied',
                        'decision_ref' => 'echoed',
                        'revision' => $this->lynomiaScript['partner_consent_revision'] ?? 9,
                    ], 201),
                // TWO COLLECTIONS, because the real endpoint returns two: the decisions this
                // partner reported, and the NATIVE ones Lynomia recorded itself. A fake that
                // answered with only the first would make every reconciliation test vacuous
                // about the opt-out the whole feed exists for.
                (bool) preg_match('#^/platform/api/v1/accounts/\d+/partner_consents$#', $path) && $method === 'GET'
                    // `native_complete` as the real endpoint sends it: the native list is
                    // CAPPED, and the reconciler may only delete on absence when it is told
                    // the list is the whole truth. Scriptable so a test can stand up the
                    // capped case.
                    // `native_complete` as the real endpoint sends it: the native list is
                    // CAPPED, and the reconciler may only delete on absence when it is told
                    // the list is the whole truth. The sentinel 'omit' leaves the key out
                    // entirely, which is the older-Lynomia case and must be read as capped.
                    => Http::response([
                        'consents' => $this->lynomiaScript['partner_consents'] ?? [],
                        'native' => $this->lynomiaScript['native_consents'] ?? [],
                        ...(($this->lynomiaScript['native_complete'] ?? true) === 'omit'
                            ? []
                            : ['native_complete' => $this->lynomiaScript['native_complete'] ?? true]),
                    ], 200),

                (bool) preg_match('#^/platform/api/v1/accounts/\d+/entitlement_grants$#', $path)
                    => Http::response([
                        'account_id' => $accountId,
                        'grants' => $this->lynomiaScript['grants'] ?? [],
                        'effective' => $this->lynomiaScript['effective'] ?? [
                            'agents' => ['limit' => null, 'source' => 'default', 'used' => 0],
                        ],
                        'withdrawn' => true,
                    ], 200),
                // Store Syria's OUTBOUND commerce event deliveries (P3D). Scriptable, because
                // what the outbox does with a refusal is the part worth testing.
                $key === 'POST /webhooks/storesyria'
                    => Http::response([], $this->lynomiaScript['commerce_event_status'] ?? 200),
                // --------------------------------------------------- Phase 4: the Automation Studio
                //
                // The shapes Api::V1::Accounts::FlowsController answers with: a list under
                // `payload`, and one flow as `flow_json` merged with the builder keys. A test
                // that needs a refusal scripts it through `fail`, which matches verb+path and
                // takes precedence above -- so 422 and 409 bodies are written where the example
                // that needs them can be read, rather than as another option here.
                (bool) preg_match('#^/api/v1/accounts/\d+/flows$#', $path) && $method === 'GET'
                    => Http::response(['payload' => $this->lynomiaScript['flows'] ?? [self::sampleFlow()]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows$#', $path) && $method === 'POST'
                    => Http::response(array_merge(self::sampleFlow(), [
                        'id' => 77, 'state' => 'draft', 'published' => null,
                        'name' => json_decode($request->body(), true)['name'] ?? 'New',
                    ]), 201),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/\d+/duplicate$#', $path)
                    => Http::response(array_merge(self::sampleFlow(), [
                        'id' => 78, 'state' => 'draft', 'published' => null, 'inboxes' => [],
                        'name' => json_decode($request->body(), true)['name'] ?? 'Copy',
                    ]), 201),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/\d+/publish$#', $path)
                    => Http::response(array_merge(self::sampleFlow(), ['state' => 'published']), 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/\d+/disable$#', $path)
                    => Http::response(array_merge(self::sampleFlow(), [
                        'state' => 'disabled', 'published' => null, 'live_sessions' => 0,
                    ]), 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/\d+$#', $path) && $method === 'DELETE'
                    => Http::response('', 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/\d+$#', $path)
                    => Http::response($this->lynomiaScript['flow'] ?? self::sampleFlow(), 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/starters$#', $path)
                    => Http::response(['payload' => $this->lynomiaScript['starters'] ?? [
                        ['id' => 'welcome_menu', 'requires' => [], 'nodes' => 3, 'available' => true, 'missing' => [],
                            'graph' => ['nodes' => [
                                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                            ], 'edges' => []]],
                        ['id' => 'order_status', 'requires' => ['lynomia_commerce'], 'nodes' => 6,
                            'available' => false, 'missing' => ['lynomia_commerce'],
                            'graph' => ['nodes' => [], 'edges' => []]],
                    ]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/\d+/insights$#', $path)
                    => Http::response($this->lynomiaScript['insights'] ?? [
                        'since' => '2026-01-05T00:00:00Z', 'total' => 7, 'live' => 1,
                        'by_status' => ['completed' => 2, 'handed_off' => 1, 'failed' => 3, 'waiting' => 1],
                        'by_failure' => ['step_limit' => 2, 'send_limit' => 1],
                        'versions' => ['1' => 7],
                    ], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/\d+/sessions$#', $path)
                    => Http::response(['payload' => $this->lynomiaScript['sessions'] ?? [
                        ['id' => 1, 'status' => 'failed', 'current_node_id' => 'menu', 'steps_count' => 12,
                            'failure_code' => 'step_limit', 'end_reason' => null, 'version' => 1,
                            'created_at' => '2026-02-01T10:00:00Z', 'updated_at' => null, 'finished_at' => null,
                            'wake_at' => null, 'conversation_id' => 41],
                    ]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/\d+/draft$#', $path) && $method === 'PUT'
                    => Http::response(array_merge(self::sampleFlow(), [
                        'state' => 'draft',
                        'draft' => ['id' => 6, 'version' => 4, 'status' => 'draft', 'published_at' => null,
                            'updated_at' => '2026-02-02T10:00:00Z',
                            'lock_version' => (int) (json_decode($request->body(), true)['lock_version'] ?? 0) + 1],
                    ]), 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/flows/\d+/simulate$#', $path)
                    => Http::response($this->lynomiaScript['simulation'] ?? self::sampleSimulation(), 200),

                (bool) preg_match('#^/api/v1/accounts/\d+/flows/channels$#', $path)
                    => Http::response(['payload' => $this->lynomiaScript['channels'] ?? [
                        ['id' => 9, 'name' => 'WhatsApp', 'channel_type' => 'Channel::Whatsapp', 'runs_flows' => true,
                            'flow' => ['id' => 42, 'name' => 'Welcome menu', 'state' => 'published'],
                            'occupied_by_other' => false],
                        ['id' => 12, 'name' => 'Live chat', 'channel_type' => 'Channel::WebWidget', 'runs_flows' => false,
                            'flow' => null, 'occupied_by_other' => false],
                        ['id' => 13, 'name' => 'Second WhatsApp', 'channel_type' => 'Channel::Whatsapp',
                            'runs_flows' => true, 'flow' => null, 'occupied_by_other' => true],
                    ]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/inboxes/\d+/set_agent_bot$#', $path)
                    => Http::response('', 200),

                // ---------------- the references a configuration form binds its pickers to
                (bool) preg_match('#^/api/v1/accounts/\d+/labels$#', $path)
                    => Http::response(['payload' => [
                        ['id' => 3, 'title' => 'vip', 'description' => 'd', 'color' => '#fff', 'show_on_sidebar' => true],
                    ]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/teams$#', $path)
                    => Http::response([['id' => 4, 'name' => 'Support', 'description' => 'd']], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/inboxes$#', $path)
                    => Http::response(['payload' => [
                        // `phone_number` and a provider config are what an inbox payload really
                        // carries upstream; the BFF's projection must not pass them on.
                        ['id' => 9, 'name' => 'WhatsApp', 'channel_type' => 'Channel::Whatsapp',
                            'phone_number' => '+963900000000', 'provider_config' => ['api_key' => 'INBOX-SECRET-MUST-NOT-LEAK']],
                    ]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/custom_attribute_definitions$#', $path)
                    => Http::response([
                        ['id' => 1, 'attribute_key' => 'loyalty_tier', 'attribute_display_name' => 'Loyalty tier',
                            'attribute_display_type' => 'list', 'attribute_values' => ['gold', 'silver']],
                    ], 200),
                // ---------------- Phase 4E: commerce automation rules and consent
                (bool) preg_match('#^/api/v1/accounts/\d+/automation_rules$#', $path) && $method === 'GET'
                    => Http::response(['payload' => $this->lynomiaScript['rules'] ?? [self::sampleRule()]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/automation_rules$#', $path) && $method === 'POST'
                    => Http::response(array_merge(self::sampleRule(), ['id' => 88] +
                        (json_decode($request->body(), true) ?: [])), 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/automation_rules/\d+$#', $path) && $method === 'DELETE'
                    => Http::response('', 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/automation_rules/\d+$#', $path)
                    => Http::response(array_merge(self::sampleRule(), json_decode($request->body(), true) ?: []), 200),

                (bool) preg_match('#^/api/v1/accounts/\d+/commerce/consents$#', $path)
                    => Http::response(['payload' => $this->lynomiaScript['consents'] ?? [
                        ['contact_id' => 55, 'contact_name' => 'Rasha', 'purpose' => 'promotional',
                            'state' => 'granted', 'source' => 'checkout', 'decided_at' => '2026-02-01T10:00:00Z'],
                    ]], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/commerce/consents/\d+$#', $path)
                    => Http::response($this->lynomiaScript['consent'] ?? self::sampleConsent(), 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/contacts/search$#', $path)
                    => Http::response(['payload' => [
                        ['id' => 55, 'name' => 'Rasha', 'email' => 'rasha@example.test',
                            'phone_number' => '+963900000055', 'identifier' => 'secret-identifier'],
                    ]], 200),

                (bool) preg_match('#^/api/v1/accounts/\d+/automation_rules/vocabulary$#', $path)
                    => Http::response($this->lynomiaScript['vocabulary'] ?? self::sampleVocabulary(), 200),

                // What the account may do and how much of each allowance it has used, as
                // Api::V1::Accounts::BillingController#entitlements answers it.
                (bool) preg_match('#^/api/v1/accounts/\d+/billing/entitlements$#', $path)
                    => Http::response([
                        'active' => true,
                        'status' => 'active',
                        'is_admin' => true,
                        'features' => ['lynomia_flow_builder' => true],
                        'limits' => $this->lynomiaScript['limits'] ?? [
                            'agents' => ['used' => 1, 'limit' => 5],
                            'inboxes' => ['used' => 1, 'limit' => 3],
                            'stores' => ['used' => 1, 'limit' => 1],
                            'flows' => ['used' => 2, 'limit' => 10],
                        ],
                    ], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/webhooks$#', $path)
                    // `webhooks` is scriptable so a test can stand a store up that was
                    // connected before an event joined the subscription list.
                    => Http::response($method === 'GET'
                        ? ['payload' => $this->lynomiaScript['webhooks'] ?? []]
                        : ['payload' => ['id' => 1]], 200),
                // `head :ok` for a DELETE (webhooks_controller.rb:18-21) -- zero bytes, not
                // a payload. The update path does answer with one.
                (bool) preg_match('#^/api/v1/accounts/\d+/webhooks/\d+$#', $path) && $method === 'DELETE'
                    => Http::response('', 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/webhooks/\d+$#', $path)
                    => Http::response(['payload' => ['id' => 1]], 200),
                // An unscripted call is a test bug, not a 404 to shrug at: say which one.
                default => Http::response(['error' => "unscripted call: {$key}"], 599),
            };
        });
    }

    /** The shape Commerce::ConversationPanel answers with for a linked contact. */
    protected static function sampleCommercePanel(): array
    {
        return [
            'store' => ['id' => 31, 'name' => 'Damascus Store', 'provider' => 'storesyria'],
            'state' => 'linked',
            'stale' => false,
            'fetched_at' => '2026-01-02T09:00:00Z',
            // The shape Commerce::ConversationPanel#link_json really answers with: how the
            // contact was matched, not the store customer's name.
            'link' => ['match_source' => 'verified_phone', 'linked_at' => 1767344400,
                'confirmed_by' => null, 'customer_type' => 'registered'],
            'orders' => [[
                'provider' => 'storesyria', 'external_order_id' => '55', 'order_number' => 'A-1',
                'status' => 'processing', 'provider_status' => 'Picked Up', 'payment_status' => 'paid',
                'currency' => 'SYP', 'total' => '1500.50', 'created_at' => '2026-01-01T12:00:00Z',
                'item_count' => 2, 'shipments' => [['status' => 'pending']], 'tracking' => null,
                'admin_order_url' => 'https://storesyria.test/order/55',
            ]],
        ];
    }

    /**
     * One flow, as Api::V1::Accounts::FlowsController#flow_json merged with #show_json
     * answers it -- including the two fields the Studio's projection must DROP: a flow bot's
     * `secret` and `access_token` are never a merchant's to see.
     */
    protected static function sampleFlow(): array
    {
        return [
            'id' => 42,
            'name' => 'Welcome menu',
            'description' => 'First reply on WhatsApp',
            'state' => 'published',
            'live_sessions' => 3,
            'secret' => 'BOT-SECRET-MUST-NOT-LEAK',
            'access_token' => 'BOT-TOKEN-MUST-NOT-LEAK',
            'inboxes' => [['id' => 9, 'name' => 'WhatsApp', 'channel_type' => 'Channel::Whatsapp']],
            'published' => ['id' => 5, 'version' => 3, 'status' => 'published',
                'published_at' => '2026-02-01T10:00:00Z', 'updated_at' => '2026-02-01T10:00:00Z', 'lock_version' => 0],
            'draft' => null,
            'channel_eligibility' => [[
                'id' => 9, 'name' => 'WhatsApp', 'channel_type' => 'Channel::Whatsapp',
                'runs_flows' => true, 'unsupported_nodes' => [],
            ]],
            'errors' => [],

            // The builder half of show_json. Trimmed to two node types and their fields: the
            // point under test is that the catalogue is CARRIED, not that this fixture restates
            // Lynomia's whole contract -- which has its own specs on that side.
            'graph' => [
                'nodes' => [
                    ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 'hello', 'type' => 'send_message', 'position' => ['x' => 0, 'y' => 160],
                        'data' => ['text' => 'Hi {{contact.name}}']],
                ],
                'edges' => [['id' => 'e1', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'hello']],
            ],
            'node_types' => [
                'start' => ['outputs' => ['next'], 'data' => ['keywords', 'conditions']],
                'send_message' => ['outputs' => ['next'], 'data' => ['text']],
            ],
            'node_fields' => [
                'start' => [['key' => 'keywords', 'kind' => 'words', 'max_items' => 20, 'max_length' => 64]],
                'send_message' => [['key' => 'text', 'kind' => 'text', 'required' => true,
                    'max' => 4096, 'variables' => 'all']],
            ],
            'store_scopes' => ['contact', 'conversation', 'context'],
            'capabilities' => ['text' => ['body' => 4096], 'buttons' => ['max' => 3, 'title' => 20, 'body' => 1024]],
            'variables' => ['contact.name', 'flow.reply'],
            'templates' => [['name' => 'spring_offer', 'language' => 'en_US', 'category' => 'MARKETING',
                'slots' => [['section' => 'body', 'key' => '1', 'kind' => 'text']]]],
        ];
    }

    /** A Test Mode run, as Flows::Simulator answers it. */
    protected static function sampleSimulation(): array
    {
        return [
            'transcript' => [
                ['from' => 'customer', 'text' => 'hi', 'items' => []],
                ['from' => 'bot', 'text' => 'How can we help?', 'items' => [
                    ['value' => 'lfb:menu:track', 'title' => 'Track order'],
                ]],
            ],
            'session' => ['status' => 'active', 'current_node_id' => 'menu', 'end_reason' => null, 'failure_code' => null],
            // Node ids and events only -- the real simulator reports no message text here either.
            'trace' => [['event' => 'flow.node.executed', 'node_id' => 'menu', 'node_type' => 'buttons', 'output' => 'track']],
        ];
    }

    /** One automation rule, as the rules API serves it. */
    protected static function sampleRule(): array
    {
        return [
            'id' => 7, 'name' => 'Tell them when it ships', 'description' => null,
            'event_name' => 'commerce_order_shipped', 'active' => true,
            'conditions' => [['attribute_key' => 'commerce_order_status', 'filter_operator' => 'equal_to',
                'values' => ['shipped'], 'query_operator' => null]],
            'actions' => [['action_name' => 'add_label', 'action_params' => ['vip']]],
            'created_at' => '2026-02-01T10:00:00Z',
        ];
    }

    /** One shopper's consent, as Commerce::ConsentsController answers it. */
    protected static function sampleConsent(): array
    {
        return [
            'contact_id' => 55, 'contact_name' => 'Rasha',
            'purposes' => [
                'transactional' => ['state' => null, 'source' => null, 'decided_at' => null, 'note' => null,
                    'refusal' => null],
                'promotional' => ['state' => 'granted', 'source' => 'merchant', 'decided_at' => '2026-02-01T10:00:00Z',
                    'note' => 'Agreed on the phone', 'refusal' => null],
            ],
        ];
    }

    /** The condition vocabulary, as Automation::Vocabulary serves it. */
    protected static function sampleVocabulary(): array
    {
        return [
            'events' => [
                ['name' => 'conversation_created', 'available' => true],
                ['name' => 'commerce_order_shipped', 'available' => true],
                ['name' => 'commerce_cart_abandoned', 'available' => false, 'reason' => 'recovery_unavailable'],
            ],
            'actions' => [
                ['name' => 'send_message', 'commerce_safe' => false,
                    'fields' => [['key' => 'message', 'kind' => 'text']]],
                ['name' => 'add_label', 'commerce_safe' => true,
                    'fields' => [['key' => 'labels', 'kind' => 'labels', 'required' => true]]],
                // Described by nothing upstream, so the Studio must not offer it.
                ['name' => 'send_attachment', 'commerce_safe' => true],
            ],
            'conditions' => [
                ['key' => 'status', 'scope' => 'conversation', 'data_type' => 'text',
                    'operators' => ['equal_to', 'not_equal_to'], 'values' => ['open', 'resolved'], 'available' => true],
                ['key' => 'assignee_id', 'scope' => 'conversation', 'data_type' => 'text',
                    'operators' => ['equal_to'], 'options_from' => 'agents', 'available' => true],
                ['key' => 'commerce_order_status', 'scope' => 'commerce', 'data_type' => 'text',
                    'operators' => ['equal_to'], 'values' => ['delivered'], 'available' => false],
            ],
            'condition_patterns' => [['prefix' => 'commerce_spend_', 'suffix' => 'currency', 'scope' => 'commerce',
                'data_type' => 'number', 'operators' => ['is_greater_than'], 'available' => false]],
        ];
    }

    /** The shape a conversation arrives in, trimmed to the keys the BFF projects. */
    protected static function sampleConversation(): array
    {
        return [
            'id' => 1,
            'status' => 'open',
            'unread_count' => 2,
            // A UNIX INTEGER, because that is what Lynomia sends:
            // app/views/api/v1/conversations/partials/_conversation.json.jbuilder:70 is
            // `json.last_activity_at conversation.last_activity_at.to_i`. This fixture carried an
            // ISO8601 string, so every test that touched it agreed with code that would have been
            // wrong against the real payload. Checked against the jbuilder, not assumed.
            'last_activity_at' => 1760000000,
            'inbox_id' => 9,
            'meta' => [
                'channel' => 'Channel::Whatsapp',
                'sender' => ['id' => 55, 'name' => 'Customer', 'thumbnail' => null],
                'assignee' => [],
            ],
            'last_non_activity_message' => ['content' => 'Where is my order?'],
        ];
    }

    protected static function sampleMessage(): array
    {
        return [
            'id' => 101,
            'message_type' => 0,
            'content' => 'Where is my order?',
            'private' => false,
            'created_at' => 1760000000,
            'status' => 'sent',
            'sender' => ['name' => 'Customer', 'type' => 'contact'],
            'attachments' => [],
        ];
    }

    /** One workspace already registered to a store ON THIS INSTALLATION, as GET /accounts reports it. */
    /**
     * The remote id for an address: the scripted one for the first address a test uses, and a
     * fresh one after that. Stable per address, which is the property that matters -- a resume
     * must resolve the SAME user, and two people must not collide onto one.
     */
    protected function remoteUserIdFor(string $email, int $default): int
    {
        return $this->lynomiaUserIds[$email] ??= $default + count($this->lynomiaUserIds);
    }

    /** The last SSO mint that was sent, so a test can read the scope off the query string. */
    protected function lastSsoRequest()
    {
        foreach (Http::recorded()->reverse() as [$request]) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/login')) {
                return $request;
            }
        }

        $this->fail('no SSO link was minted');
    }

    protected function inventoryFor(int $storeId, int $accountId = 7001, string $name = 'WS', ?string $installation = null): array
    {
        return [[
            'id' => $accountId,
            'name' => $name,
            'custom_attributes' => [
                'store_syria_store_id' => $storeId,
                'store_syria_installation' => $installation ?? config('lynomia.installation_ref'),
            ],
        ]];
    }

    /**
     * How many times a given verb+path suffix was sent.
     *
     * SUFFIX, NOT SUBSTRING. '/platform/api/v1/accounts' is a prefix of
     * '/platform/api/v1/accounts/7001/account_users', so a substring match counts the
     * membership call as an account create and quietly passes a test asserting no second
     * workspace was made.
     */
    protected function sentCount(string $method, string $pathSuffix): int
    {
        $count = 0;

        foreach (Http::recorded() as [$request]) {
            if (strtoupper($request->method()) === strtoupper($method)
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $pathSuffix)) {
                $count++;
            }
        }

        return $count;
    }


    /** The email the most recent POST /platform/api/v1/users carried. */
    protected function lastUserCreateAddress(): string
    {
        foreach (Http::recorded()->reverse() as [$request]) {
            if (strtoupper($request->method()) === 'POST'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/platform/api/v1/users')) {
                return (string) $request->data()['email'];
            }
        }

        $this->fail('no user create was sent');
    }
}
