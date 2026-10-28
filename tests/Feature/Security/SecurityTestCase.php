<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

/**
 * Shared fixtures for the Phase 1B P0 security regression suites.
 *
 * Only UserFactory exists in this codebase, so tenants are built explicitly
 * here rather than through factories. Every helper is deliberately small and
 * returns a real persisted row, because the assertions these suites make are
 * about database state ("the discriminator is provably unchanged"), not just
 * about HTTP status codes.
 */
abstract class SecurityTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laratrust caches a user's roles and permissions for
        // config('laratrust.cache.expiration_time') seconds
        // (UserDefaultChecker.php:159-163). These suites grant a permission and
        // then immediately assert on it in the same request lifecycle, so the
        // cache would serve the pre-grant answer and isAbleTo() would return
        // false for a user who genuinely holds the permission -- making an
        // authorization test pass for the wrong reason.
        //
        // This is a test-fixture concern, not a production defect: in
        // production, permissions are seeded long before they are checked.
        config(['laratrust.cache.enabled' => false]);

        $this->satisfyUpdaterGate();
    }

    /**
     * XSS::handle() (app/Http/Middleware/XSS.php:27-48) contains an UPDATER GATE that
     * fires for `type == 'super admin'` ONLY. It globs every migration filename on disk --
     * database/migrations plus, for each of the 69 modules, packages/workdo/<Module>/src/
     * Database/Migrations and .../src/database/migrations -- diffs that against the
     * `migrations` table, and on any difference returns
     * `redirect()->route('LaravelUpdater::welcome')` BEFORE $next($request).
     *
     * RefreshDatabase runs database/migrations only; the module migrations are never
     * recorded. So in every test, for a platform admin, the gate sees ~hundreds of pending
     * migrations and redirects to /update. The controller is never reached.
     *
     * That silently invalidates super-admin web-route tests in BOTH directions:
     *   - a test asserting the platform admin IS allowed fails, because nothing happened;
     *   - and far worse, a test asserting the platform admin is DENIED, or that some row
     *     was NOT modified, PASSES WITHOUT EXERCISING THE CONTROL AT ALL, because the
     *     request was redirected before it reached the code under test. A green assertion
     *     then proves nothing.
     *
     * This records every on-disk migration name as already run, which is the state of a
     * fully migrated production install -- the state these suites mean to test against.
     * It touches only the framework's `migrations` bookkeeping table; no application table,
     * no application code, and no production behaviour is changed. The globbing mirrors the
     * middleware's own so the two cannot drift apart silently.
     */
    protected function satisfyUpdaterGate(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('migrations')) {
            return;
        }

        $onDisk = collect(\Illuminate\Support\Facades\File::glob(base_path('database/migrations/*.php')))
            ->map(fn ($path) => \Illuminate\Support\Facades\File::name($path));

        foreach (\App\Facades\ModuleFacade::allModules() as $module) {
            foreach ([
                base_path("packages/workdo/{$module->name}/src/Database/Migrations"),
                base_path("packages/workdo/{$module->name}/src/database/migrations"),
            ] as $dir) {
                if (\Illuminate\Support\Facades\File::exists($dir)) {
                    $onDisk = $onDisk->merge(
                        collect(\Illuminate\Support\Facades\File::glob("{$dir}/*.php"))
                            ->map(fn ($path) => \Illuminate\Support\Facades\File::name($path))
                    );
                }
            }
        }

        $recorded = \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration');
        $missing = $onDisk->unique()->diff($recorded)->values();

        if ($missing->isEmpty()) {
            return;
        }

        $batch = (int) (\Illuminate\Support\Facades\DB::table('migrations')->max('batch') ?? 0) + 1;

        $missing->chunk(200)->each(function ($chunk) use ($batch) {
            \Illuminate\Support\Facades\DB::table('migrations')->insert(
                $chunk->map(fn ($name) => ['migration' => $name, 'batch' => $batch])->all()
            );
        });
    }

    /**
     * A platform operator. `type` is compared against this literal throughout
     * the application (StoreController:43, AddonController:21/:153,
     * EmailIsVerified:46, User::creatorId():97).
     */
    protected function makePlatformAdmin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'type' => 'super admin',
            'created_by' => null,
            'is_active' => 1,
            'email_verified_at' => now(),
        ], $attributes));
    }

    /**
     * A merchant tenant owner: type='admin', so creatorId() returns their own
     * id and they are the root of their own tenant.
     */
    protected function makeMerchant(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'type' => 'admin',
            'created_by' => 1,
            'is_active' => 1,
            'email_verified_at' => now(),
        ], $attributes));
    }

    /**
     * A staff member inside a merchant's tenant: creatorId() resolves to the
     * owning admin via created_by.
     */
    protected function makeStaff(User $owner, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'type' => 'staff',
            'created_by' => $owner->id,
            'current_store' => $owner->current_store,
            'is_active' => 1,
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function makeStore(User $owner, array $attributes = []): Store
    {
        $store = Store::create(array_merge([
            'name' => 'Store ' . $owner->id . '-' . uniqid(),
            'email' => 'store' . uniqid() . '@example.test',
            'theme_id' => 'stylique',
            'slug' => 'store-' . $owner->id . '-' . uniqid(),
            'created_by' => $owner->id,
            'is_active' => 1,
        ], $attributes));

        return $store;
    }

    /**
     * A merchant plus their store, with current_store already pointing at it.
     *
     * @return array{0: User, 1: Store}
     */
    protected function makeTenant(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $merchant->current_store = $store->id;
        $merchant->save();

        return [$merchant->fresh(), $store];
    }

    protected function makeOrder(Store $store, array $attributes = []): Order
    {
        // CROSS-AGENT DEPENDENCY. App\Models\Order has a `creating` hook that
        // writes orders.currency_code and orders.currency_symbol, but NO
        // migration declares either column, so on a from-zero sqlite schema
        // every Order insert fails with "table orders has no column named
        // currency_code". Undeclared columns, same class as the missing
        // audit_logs table. Declaring them is the schema agent's increment and
        // the Order model belongs to the commerce agent, so these tests are
        // written now and skip until that lands.
        foreach (['currency_code', 'currency_symbol'] as $column) {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('orders', $column)) {
                $this->markTestSkipped(
                    "BLOCKED: orders.{$column} is written by App\\Models\\Order's creating "
                    . 'hook but is declared by no migration, so no Order row can be '
                    . 'inserted (cross-agent dependency -- schema + commerce agents).'
                );
            }
        }

        return Order::create(array_merge([
            'product_order_id' => 'ORD-' . uniqid(),
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => '[]',
            'final_price' => 100,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'store_id' => $store->id,
        ], $attributes));
    }

    /**
     * A role owned by a tenant. store_id is NOT null, which matters: RolePolicy
     * deliberately refuses to edit a role whose store_id is null, because that
     * is the shape of the shared global `admin` role.
     */
    protected function makeRole(User $owner, Store $store, string $name, array $attributes = []): Role
    {
        // forceCreate, NOT create. App\Models\Role sets `public $guarded = []`
        // but extends Laratrust\Models\Role, which declares a NON-EMPTY
        // `protected $fillable = ['name','display_name','description']`. A
        // non-empty inherited $fillable takes precedence in
        // Model::isFillable(), so store_id, created_by and guard_name are NOT
        // mass-assignable and Role::create() would silently drop them.
        // Verified directly: isFillable('store_id') === false.
        return Role::forceCreate(array_merge([
            'name' => $name,
            'display_name' => $name,
            'guard_name' => 'web',
            'created_by' => $owner->creatorId(),
            'store_id' => $store->id,
        ], $attributes));
    }

    /**
     * Give a user a real Laratrust role carrying a permission, so that a test
     * can isolate the OWNERSHIP defect from the permission defect.
     */
    protected function grantPermission(User $user, string $permission): Role
    {
        $p = \App\Models\Permission::firstOrCreate(
            ['name' => $permission],
            ['display_name' => $permission]
        );

        $role = Role::where('name', 'granted-role-' . $user->id)->first();

        if (!$role) {
            // forceCreate -- see makeRole() for why create() drops store_id.
            $role = Role::forceCreate([
                'name' => 'granted-role-' . $user->id,
                'display_name' => 'Granted Role',
                'guard_name' => 'web',
                'created_by' => $user->creatorId(),
                'store_id' => $user->current_store,
            ]);
        }

        $role->givePermission($p);

        if (!$user->hasRole($role->name)) {
            $user->addRole($role);
        }

        $user->flushCache();

        return $role;
    }

    /**
     * Skip a test when a table it depends on is not declared by any migration.
     *
     * CROSS-AGENT DEPENDENCY. `audit_logs` is one of the undeclared tables: the
     * App\Models\AuditLog model exists and is already called from
     * UserController, but no migration creates the table, so on a from-zero
     * sqlite schema any code path reaching AuditLog::record() fails with
     * "no such table". Declaring it is Agent 2's increment. These tests are
     * written now and become live the moment that migration lands -- they are
     * skipped rather than deleted so the dependency stays visible.
     */
    /**
     * `currency_rates` HAS NO MIGRATION (GATE F), and `productlist` / `orderlist` / `Top_orders`
     * all read it — so without this the endpoint 500s on a from-zero SQLite schema and every
     * assertion below would be measuring a missing table rather than the gate.
     *
     * THIS IS NOT INVENTING THE PRODUCTION SCHEMA, which `GateFSchemaContainmentTest` forbids and
     * which is why the parked draft keeps its columns commented out. It is a test-local minimum,
     * copied verbatim from the two tests that already do it — `WebSettingsSecretsTest::setUp()`
     * and `ChatgptKeyDisclosureTest` — so there is one shape in the suite rather than three.
     * `docs/GATE-F-VERIFIED-SCHEMA.md` holds the measured production columns.
     */
    protected function requireCurrencyRatesStandIn(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('currency_rates')) {
            return;
        }

        \Illuminate\Support\Facades\Schema::create('currency_rates', function ($t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->nullable();
            $t->string('currency_code');
            $t->string('currency_name')->nullable();
            $t->string('symbol')->nullable();
            $t->decimal('rate_per_usd', 20, 8)->default(1);
            $t->boolean('is_active')->default(1);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    protected function requireTable(string $table): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
            $this->markTestSkipped(
                "BLOCKED: the `{$table}` table is not declared by any migration "
                . '(cross-agent dependency -- see Agent 2 schema work).'
            );
        }
    }

    /**
     * The shared global `admin` role as PermissionTableSeeder creates it: a
     * null store_id, shared by every self-registered merchant.
     */
    protected function makeGlobalAdminRole(): Role
    {
        return Role::forceCreate([
            'name' => 'admin',
            'display_name' => 'admin',
            'guard_name' => 'web',
            'created_by' => null,
            'store_id' => null,
        ]);
    }
}
