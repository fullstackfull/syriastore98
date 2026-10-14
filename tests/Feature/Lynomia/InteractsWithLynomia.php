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
        $permissions = array_map(static fn (string $name) => Permission::firstOrCreate(
            ['name' => $name],
            ['display_name' => $name, 'guard_name' => 'web', 'module' => 'LynomiaChat']
        ), $names);

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
                str_starts_with($key, 'DELETE /platform/api/v1/account_links/') => Http::response([], 200),

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
                (bool) preg_match('#^/platform/api/v1/accounts/\d+/entitlement_grants$#', $path)
                    => Http::response([
                        'account_id' => $accountId,
                        'grants' => $this->lynomiaScript['grants'] ?? [],
                        'effective' => $this->lynomiaScript['effective'] ?? [
                            'agents' => ['limit' => null, 'source' => 'default', 'used' => 0],
                        ],
                        'withdrawn' => true,
                    ], 200),
                (bool) preg_match('#^/api/v1/accounts/\d+/webhooks$#', $path)
                    => Http::response($method === 'GET' ? ['payload' => []] : ['payload' => ['id' => 1]], 200),
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
