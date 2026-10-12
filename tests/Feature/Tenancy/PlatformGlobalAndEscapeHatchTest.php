<?php

namespace Tests\Feature\Tenancy;

use App\Models\City;
use App\Models\Concerns\BelongsToStore;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EmailTemplate;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\State;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE OTHER HALF OF THE TENANCY CONTRACT: what the scope must NOT do.
 *
 * StoreScopeTest pins the scope's own behaviour. CrossTenantModelIsolationTest proves a
 * tenant-owned model is isolated. This class proves the three ways that isolation could be
 * CATASTROPHIC rather than merely incomplete:
 *
 *   1. A PLATFORM-GLOBAL model must never be scoped. countries, states, cities, currency, plans,
 *      roles, permissions, settings and email templates have no tenant and every tenant must see
 *      all of their rows. A scope on these does not leak data -- it EMPTIES the platform, and it
 *      does so silently, looking like missing data rather than like an error.
 *
 *   2. A NULL-USER CONTEXT must not be scoped to nothing. This is the R4-02 trap, and it is the
 *      single most dangerous property in the design. getCurrentStore() resolves from the `web`
 *      guard ONLY, so it returns null for a storefront visitor, a customer or delivery boy on
 *      their own guard, a QUEUED JOB and a CONSOLE COMMAND. A naive scope compiles
 *      `store_id = NULL`, which matches NO row in SQL, so every storefront page and every job
 *      silently returns empty. A scope that breaks the storefront or a queued job is WORSE than
 *      no scope at all, so those two cases are tested explicitly here rather than reasoned about.
 *
 *   3. A SUPER ADMIN must not be pinned to the hardcoded literal store 1. getCurrentStore()
 *      returns 1 for a super admin with an empty current_store (app/Helper/helper.php:373-374);
 *      scoping a platform operator to store 1 would break platform administration.
 *
 * These are tested with the scope FORCIBLY ENABLED. That is the whole point: production runs with
 * it off, so an off-by-default scope passes every one of these trivially and proves nothing. The
 * suite must know what happens on the day someone turns it on.
 */
class PlatformGlobalAndEscapeHatchTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 101;
    private const STORE_B = 202;

    protected function setUp(): void
    {
        parent::setUp();

        StoreScope::resetSuppression();
        StoreScope::$enabled = false;
    }

    protected function tearDown(): void
    {
        StoreScope::resetSuppression();
        StoreScope::$enabled = false;

        parent::tearDown();
    }

    private function merchantFor(int $storeId, string $type = 'admin'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Merchant ' . $storeId,
            'email' => 'merchant' . $storeId . '-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'type' => $type,
            'current_store' => $storeId,
        ])->save();

        return $user;
    }

    // =======================================================================================
    //  1. PLATFORM-GLOBAL MODELS MUST NEVER BE SCOPED
    // =======================================================================================

    /**
     * The DO-NOT-SCOPE list from the BelongsToStore docblock, as executable cases.
     *
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function platformGlobalModels(): array
    {
        return [
            'countries' => [Country::class],
            'states' => [State::class],
            'cities' => [City::class],
            'currencies' => [Currency::class],
            'plans' => [Plan::class],
            'roles' => [Role::class],
            'permissions' => [Permission::class],
            'settings' => [Setting::class],
            'email templates' => [EmailTemplate::class],
        ];
    }

    /**
     * Structural half: the trait must not be attached, and no StoreScope may be registered.
     */
    #[DataProvider('platformGlobalModels')]
    public function test_platform_global_model_carries_no_store_scope(string $modelClass): void
    {
        $this->assertNotContains(
            BelongsToStore::class,
            class_uses_recursive($modelClass),
            $modelClass . ' is platform-global and must NOT carry BelongsToStore. Every tenant has '
            . 'to see all of its rows; scoping it empties the platform silently.'
        );

        $this->assertArrayNotHasKey(
            StoreScope::class,
            (new $modelClass())->getGlobalScopes(),
            $modelClass . ' has a StoreScope registered.'
        );
    }

    /**
     * Behavioural half, which is the one that actually matters. With the scope FORCIBLY ENABLED
     * and a tenant merchant authenticated, a platform-global query must still carry no tenant
     * predicate and must still return rows written under a DIFFERENT tenant's session.
     *
     * The structural assertion above could pass while something else introduced a predicate;
     * this one observes the SQL and the result set.
     */
    #[DataProvider('platformGlobalModels')]
    public function test_platform_global_rows_stay_visible_to_every_tenant(string $modelClass): void
    {
        $model = new $modelClass();
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            $this->markTestSkipped('Table `' . $table . '` does not exist.');
        }

        StoreScope::$enabled = true;
        $this->be($this->merchantFor(self::STORE_A));

        $this->assertStringNotContainsString(
            'store_id',
            $modelClass::query()->toSql(),
            'A tenant predicate appeared on platform-global ' . $modelClass . ' while the scope was '
            . 'enabled. This is the failure mode that empties the platform.'
        );

        // Count as tenant A, then as tenant B. A platform-global table must not change shape
        // with the session.
        $seenByA = $modelClass::count();

        auth()->logout();
        $this->be($this->merchantFor(self::STORE_B));

        $this->assertSame(
            $seenByA,
            $modelClass::count(),
            $modelClass . ' returned a different row count to a different tenant, so it is being '
            . 'scoped somewhere. It is platform-global.'
        );
    }

    /**
     * Settings are the sharp edge of the DO-NOT-SCOPE list and deserve their own case.
     *
     * `settings` DOES have a store_id and IS tenant-sensitive, so it looks like a scope
     * candidate. It must not be one: app/Models/Utility.php:289 and :896 run
     * `Setting::where('store_id', 1)` with a HARDCODED id while configuring file storage. A
     * current-store scope would compile `store_id = 1 AND store_id = <tenant>` -> zero rows, and
     * file storage would silently fall back to defaults for every tenant but store 1.
     *
     * So settings isolation is enforced by the explicit predicate at the call site, and this
     * test pins both halves of that decision.
     */
    public function test_settings_keep_working_for_a_hardcoded_foreign_store_id(): void
    {
        $required = [];
        foreach (Schema::getColumns('settings') as $column) {
            if (! $column['nullable'] && $column['default'] === null && ! $column['auto_increment']
                && ! in_array($column['name'], ['store_id', 'created_at', 'updated_at'], true)) {
                $required[$column['name']] = 1;
            }
        }

        \DB::table('settings')->insert([
            'name' => 'storage_setting',
            'value' => 's3',
            'store_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $required);

        StoreScope::$enabled = true;
        $this->be($this->merchantFor(self::STORE_A));

        // This is literally the Utility.php:289 / :896 shape: a hardcoded foreign store id.
        $row = Setting::where('store_id', 1)->where('name', 'storage_setting')->first();

        $this->assertNotNull(
            $row,
            'Setting::where(store_id, 1) returned nothing while authenticated as a different '
            . 'tenant. app/Models/Utility.php:289 and :896 depend on exactly this query to '
            . 'configure file storage; a scope here breaks storage for every tenant but store 1.'
        );
        $this->assertSame('s3', $row->value);
    }

    // =======================================================================================
    //  2. THE R4-02 TRAP: NULL-USER CONTEXTS
    // =======================================================================================

    /**
     * Seed one product per tenant without going through any model, so the fixture cannot be
     * shaped by the control under test.
     *
     * @return array<int, int>
     */
    private function seedProductPair(): array
    {
        $ids = [];

        foreach ([self::STORE_A, self::STORE_B] as $storeId) {
            $ids[$storeId] = \DB::table('products')->insertGetId([
                'name' => 'Widget ' . $storeId,
                'slug' => 'widget-' . $storeId . '-' . uniqid(),
                'track_stock' => 0,
                'created_by' => 1,
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /**
     * THE STOREFRONT. An anonymous visitor browsing a shop must see products.
     *
     * If the scope compiled `store_id = NULL` for a null tenant, this returns zero rows and
     * every storefront in the platform goes blank. The storefront resolves its own store by slug
     * through getStore() (app/Helper/helper.php:2301-2306) and filters explicitly; the scope must
     * stay out of its way.
     */
    public function test_anonymous_storefront_request_still_sees_products(): void
    {
        $ids = $this->seedProductPair();

        StoreScope::$enabled = true;
        $this->assertNull(auth()->user(), 'This test is meaningless with a user authenticated.');

        $sql = Product::query()->toSql();

        $this->assertStringNotContainsString(
            'store_id',
            $sql,
            'The scope added a tenant predicate for an ANONYMOUS request. With a null tenant that '
            . 'compiles to store_id = NULL, which matches no row, and every storefront page goes '
            . 'blank. This is the R4-02 trap.'
        );

        $visible = Product::pluck('id')->all();

        $this->assertContains($ids[self::STORE_A], $visible);
        $this->assertContains($ids[self::STORE_B], $visible);

        // And the storefront's own explicit filter must still isolate correctly.
        $this->assertSame(
            [$ids[self::STORE_A]],
            Product::forStore(self::STORE_A)->pluck('id')->all(),
            'The storefront relies on an explicit forStore() filter for isolation.'
        );
    }

    /**
     * A QUEUED JOB. No authenticated principal at all, which is the same null-tenant shape as the
     * storefront but reached differently: auth() resolves, there is simply no user.
     *
     * routes/console.php:9 drives the Zid sync this way. A scope that empties a job's queries
     * stops the sync with no error anywhere -- the worst possible failure mode, because nothing
     * is logged and the data just stops moving.
     */
    public function test_queued_job_context_is_not_scoped_to_nothing(): void
    {
        $ids = $this->seedProductPair();

        StoreScope::$enabled = true;

        // A job runs with no authenticated user. Assert the precondition rather than assume it.
        auth()->logout();
        $this->assertNull(auth()->user());
        $this->assertNull(
            StoreScope::resolveStoreId(),
            'resolveStoreId() must return null with no authenticated user (R4-02). If it returns a '
            . 'value here, the scope is resolving a tenant from somewhere it should not.'
        );

        // Run the query inside a REAL queued job rather than simulating one. phpunit.xml sets
        // QUEUE_CONNECTION=sync, so a dispatched closure executes through the genuine queue
        // pipeline (CallQueuedClosure -> the job dispatcher), which is the context R4-02 is
        // about.
        //
        // The result is handed back through the cache, NOT through a `use (&$seen)` reference:
        // dispatch() wraps the closure in a SerializableClosure, and serialization drops the
        // by-reference binding, so the reference form silently observes nothing. The first
        // version of this test did exactly that and the assertion below is what caught it.
        cache()->forget('tenancy-probe-job');

        dispatch(function () {
            cache()->put('tenancy-probe-job', Product::pluck('id')->all(), 60);
        });

        $seen = cache()->get('tenancy-probe-job');

        $this->assertIsArray(
            $seen,
            'The queued closure did not run, so this test observed nothing about job context.'
        );

        $this->assertContains($ids[self::STORE_A], $seen);
        $this->assertContains(
            $ids[self::STORE_B],
            $seen,
            'A job-context query returned only one tenant\'s rows. A queued job has no tenant and '
            . 'must not be silently narrowed.'
        );
    }

    /**
     * A CONSOLE COMMAND, exercised by actually running artisan rather than by simulating it.
     *
     * This is the strongest available form of this assertion: the command runs through the real
     * console kernel, where no web guard user exists, and reports what it can see.
     */
    public function test_console_command_context_is_not_scoped_to_nothing(): void
    {
        $ids = $this->seedProductPair();

        StoreScope::$enabled = true;

        \Illuminate\Support\Facades\Artisan::command('tenancy:probe-visible-products', function () {
            $this->line('VISIBLE:' . implode(',', Product::pluck('id')->all()));
        });

        $this->artisan('tenancy:probe-visible-products')
            ->expectsOutputToContain('VISIBLE:' . $ids[self::STORE_A] . ',' . $ids[self::STORE_B])
            ->assertExitCode(0);
    }

    /**
     * A CUSTOMER on the `customers` guard. getCurrentStore() reads the `web` guard only, so a
     * logged-in customer is a null tenant too -- which is easy to miss, because the request is
     * authenticated and merely on a different guard.
     */
    public function test_customer_guard_session_is_not_scoped_to_nothing(): void
    {
        $ids = $this->seedProductPair();

        StoreScope::$enabled = true;

        $customer = \App\Models\Customer::create([
            'first_name' => 'Layla',
            'email' => 'layla-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => self::STORE_A,
        ]);

        $this->be($customer, 'customers');

        $this->assertNull(
            StoreScope::resolveStoreId(),
            'resolveStoreId() resolved a tenant from the `customers` guard. getCurrentStore() reads '
            . 'the web guard only, so this must be null -- and the scope must therefore add no '
            . 'predicate rather than compile store_id = NULL.'
        );

        $visible = Product::pluck('id')->all();
        $this->assertContains($ids[self::STORE_A], $visible);
        $this->assertContains($ids[self::STORE_B], $visible);
    }

    // =======================================================================================
    //  3. SUPER ADMIN
    // =======================================================================================

    /**
     * A super admin must see every tenant's rows, and specifically must NOT be pinned to the
     * hardcoded literal store 1 that getCurrentStore() returns for one.
     */
    public function test_super_admin_sees_every_tenant_and_is_not_pinned_to_store_one(): void
    {
        $ids = $this->seedProductPair();

        // A row owned by store 1 exactly, so "pinned to store 1" is distinguishable from
        // "correctly unscoped" rather than looking the same.
        $storeOneId = \DB::table('products')->insertGetId([
            'name' => 'Store One Widget',
            'slug' => 'store-one-' . uniqid(),
            'track_stock' => 0,
            'created_by' => 1,
            'store_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        StoreScope::$enabled = true;

        $superAdmin = $this->merchantFor(self::STORE_A, 'super admin');
        $superAdmin->forceFill(['current_store' => null])->save();
        $this->be($superAdmin);

        $this->assertNull(
            StoreScope::resolveStoreId(),
            'A super admin must resolve to null (no predicate), not to the hardcoded store 1.'
        );

        $visible = Product::pluck('id')->all();

        $this->assertContains($ids[self::STORE_A], $visible);
        $this->assertContains(
            $ids[self::STORE_B],
            $visible,
            'The super admin could not see tenant B. If only the store-1 row were visible, the '
            . 'super admin has been pinned to the hardcoded literal 1.'
        );
        $this->assertContains($storeOneId, $visible);
    }

    // =======================================================================================
    //  4. THE ESCAPE HATCHES, under a genuinely enabled scope
    // =======================================================================================

    /**
     * allowCrossTenant() must lift the scope for a deliberate cross-tenant read, and restore it
     * afterwards even when the callback throws.
     */
    public function test_allow_cross_tenant_lifts_the_scope_and_restores_it_after_a_throw(): void
    {
        $ids = $this->seedProductPair();

        StoreScope::$enabled = true;
        $this->be($this->merchantFor(self::STORE_A));

        // Baseline: the scope is genuinely narrowing, otherwise the hatch proves nothing.
        $this->assertSame(
            [$ids[self::STORE_A]],
            Product::pluck('id')->all(),
            'The scope is not narrowing at all, so this test cannot show that the hatch lifts it.'
        );

        $all = StoreScope::allowCrossTenant(fn () => Product::pluck('id')->all());

        $this->assertContains($ids[self::STORE_B], $all, 'allowCrossTenant() did not lift the scope.');

        try {
            StoreScope::allowCrossTenant(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(
            StoreScope::isSuppressed(),
            'Suppression leaked after an exception inside allowCrossTenant(). A leaked suppression '
            . 'disables tenancy for the rest of the process.'
        );

        $this->assertSame(
            [$ids[self::STORE_A]],
            Product::pluck('id')->all(),
            'The scope was not restored after the hatch.'
        );
    }

    /**
     * withoutStoreScope() must lift ONLY the tenant scope, and in particular must NOT lift
     * SoftDeletes on Customer -- a hatch that resurrects deleted rows would be a quiet disaster.
     */
    public function test_without_store_scope_does_not_lift_soft_deletes(): void
    {
        StoreScope::$enabled = true;

        $customer = \App\Models\Customer::create([
            'first_name' => 'Deleted',
            'email' => 'deleted-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => self::STORE_A,
        ]);
        $customer->delete();

        $this->be($this->merchantFor(self::STORE_A));

        $this->assertNull(
            \App\Models\Customer::withoutStoreScope()->find($customer->id),
            'withoutStoreScope() lifted SoftDeletes as well as the tenant scope, so a soft-deleted '
            . 'customer became visible again.'
        );

        $this->assertNotNull(
            \App\Models\Customer::withoutStoreScope()->withTrashed()->find($customer->id),
            'The row should still be reachable with an explicit withTrashed().'
        );
    }
}
