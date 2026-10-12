<?php

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Menu;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE NULL-TENANT DECISION, PROVEN IN ALL FOUR CONTEXTS WHERE IT BITES.
 *
 * This is the brief's item 3, which calls the null-tenant question "the dangerous part", and the
 * owner's item 6, "ensure background jobs and API calls resolve tenant context safely".
 *
 * THE DECISION, STATED PLAINLY. The scope FAILS OPEN on a null tenant: it adds no predicate, so
 * all rows stay visible. That is configurable (`tenancy.null_tenant` = 'open' | 'closed') and
 * 'open' is the shipped default. The full argument lives in config/tenancy.php; the short form is:
 *
 *   - Fail-open is a REAL, ACKNOWLEDGED ISOLATION HOLE. In these four contexts the scope
 *     contributes nothing and the control of record remains the explicit `where('store_id', ...)`
 *     at the call site. It is reported as a hole, not presented as a fix.
 *   - Fail-closed is nonetheless the WRONG DEFAULT, because every storefront page, every customer
 *     API response and every queued job resolves its store WITHOUT a web-guard principal. A
 *     'closed' default empties the shop. A control that takes the shop offline gets switched off,
 *     and then there is no control at all.
 *
 * WHY `store_id = NULL` IS NEVER THE ANSWER (the R4-02 trap). A naive scope would compile
 * `store_id = NULL`, which matches NO row in SQL -- so "fail open" and "fail closed" are not the
 * only two outcomes available; getting it wrong produces a silent third. Every context below
 * asserts the emitted SQL contains no `is null`.
 *
 * BOTH BRANCHES ARE EXERCISED. Each context is proven under 'open' AND under 'closed', so the
 * trade-off is backed by measurement in both directions rather than by a comment claiming one.
 */
class NullTenantContextTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 101;
    private const STORE_B = 202;

    /** @var array<string, mixed> filled by the job and the command, which run outside this class */
    public static array $observed = [];

    protected function setUp(): void
    {
        parent::setUp();

        StoreScope::resetSuppression();
        self::$observed = [];

        try {
            cache()->flush();
        } catch (\Throwable $e) {
            // not fatal
        }
    }

    protected function tearDown(): void
    {
        StoreScope::resetSuppression();
        StoreScope::$enabled = false;
        self::$observed = [];

        parent::tearDown();
    }

    /**
     * Required-column stubs read from the live schema. `store_id` is never stubbed.
     *
     * @return array<string, mixed>
     */
    private function stubs(string $table): array
    {
        $stubs = [];
        $foreignKeys = [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            if (count($fk['columns']) === 1 && count($fk['foreign_columns']) === 1) {
                $foreignKeys[$fk['columns'][0]] = [$fk['foreign_table'], $fk['foreign_columns'][0]];
            }
        }

        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];

            if ($column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
                continue;
            }

            if (in_array($name, ['store_id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            if (isset($foreignKeys[$name])) {
                $existing = DB::table($foreignKeys[$name][0])->value($foreignKeys[$name][1]);

                if ($existing === null) {
                    $this->markTestSkipped(
                        "Required foreign key `{$table}.{$name}` into `{$foreignKeys[$name][0]}` "
                        . 'cannot be satisfied: that table is empty. Fixture limitation, reported '
                        . 'rather than worked around.'
                    );
                }

                $stubs[$name] = $existing;

                continue;
            }

            $type = strtolower((string) $column['type_name']);

            $stubs[$name] = match (true) {
                str_contains($type, 'int') => 1,
                str_contains($type, 'bool') => 0,
                str_contains($type, 'dec'), str_contains($type, 'float'),
                str_contains($type, 'double'), str_contains($type, 'numeric') => 0,
                str_contains($type, 'date'), str_contains($type, 'time') => now(),
                str_contains($type, 'json') => '{}',
                default => $name . '-' . uniqid(),
            };
        }

        return $stubs;
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

    /**
     * One menu per tenant, inserted raw so the control under test cannot shape the fixture.
     *
     * @return array<int, int> store id => menu id
     */
    private function seedMenus(): array
    {
        $ids = [];

        foreach ([self::STORE_A, self::STORE_B] as $storeId) {
            $ids[$storeId] = DB::table('menus')->insertGetId([
                'name' => 'nav-' . $storeId,
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    // =====================================================================================
    //  CONTEXT 1 of 4 -- A QUEUED JOB
    // =====================================================================================

    /**
     * A job running on a worker has no session and therefore no web-guard principal, so the
     * tenant is UNRESOLVED and (under 'open') the job sees every tenant's rows.
     *
     * THIS IS THE PRODUCTION CONFIGURATION. config/queue.php:16 reads
     * `env('QUEUE_CONNECTION', 'database')`, so unless the environment pins `sync`, jobs run in a
     * separate worker process with no authenticated user at all. A job that must stay within one
     * tenant has to carry the store id in its payload and filter on it explicitly -- the scope
     * will not do it.
     */
    public function test_a_queued_job_has_no_tenant_and_sees_every_store(): void
    {
        $ids = $this->seedMenus();

        // A worker process has no authenticated user. Nothing is logged in here.
        $this->assertNull(auth()->user());

        dispatch_sync(new ProbeTenantContextJob('job'));

        $this->assertSame(StoreScope::MODE_UNRESOLVED, self::$observed['job']['mode']);
        $this->assertContains($ids[self::STORE_A], self::$observed['job']['visible']);
        $this->assertContains(
            $ids[self::STORE_B],
            self::$observed['job']['visible'],
            'A job with no tenant must FAIL OPEN under the shipped policy. If this now returns '
            . 'one store, the null-tenant default changed and every background job that touches '
            . 'an enforced model needs re-checking.'
        );

        $this->assertStringNotContainsString('is null', strtolower(self::$observed['job']['sql']));
    }

    /**
     * A FINDING WORTH THE OWNER'S ATTENTION, not just a test. Under the `sync` driver a job runs
     * INSIDE the dispatching request, so it inherits that request's authenticated user and the
     * tenant IS resolved. The same job on a `database` or `redis` worker resolves no tenant.
     *
     * So a job's tenant behaviour depends on the queue driver, and the two differ silently. Any
     * job relying on `getCurrentStore()` works in local `sync` testing and reads the wrong
     * tenant's data -- or no tenant's -- in production.
     */
    public function test_a_sync_dispatched_job_inherits_the_dispatchers_tenant_unlike_a_real_worker(): void
    {
        $ids = $this->seedMenus();

        $this->be($this->merchantFor(self::STORE_A));

        dispatch_sync(new ProbeTenantContextJob('sync'));

        $this->assertSame(
            StoreScope::MODE_TENANT,
            self::$observed['sync']['mode'],
            'Under `sync` the job shares the request, so it SHOULD resolve the dispatcher tenant. '
            . 'This is documented so nobody concludes from a local sync run that jobs are scoped.'
        );

        $this->assertSame([$ids[self::STORE_A]], self::$observed['sync']['visible']);
    }

    public function test_a_queued_job_returns_nothing_under_the_closed_policy(): void
    {
        $this->seedMenus();
        config(['tenancy.null_tenant' => 'closed']);

        dispatch_sync(new ProbeTenantContextJob('job-closed'));

        $this->assertSame(StoreScope::MODE_UNRESOLVED, self::$observed['job-closed']['mode']);
        $this->assertSame(
            [],
            self::$observed['job-closed']['visible'],
            "Under 'closed' a tenantless job sees nothing. This is why 'closed' is not the default: "
            . 'it would silently stop every background job that reads an enforced model.'
        );
    }

    // =====================================================================================
    //  CONTEXT 2 of 4 -- A CONSOLE COMMAND
    // =====================================================================================

    /**
     * A scheduled command has no principal either. routes/console.php drives the Zid sync, which
     * is exactly this shape.
     */
    public function test_a_console_command_has_no_tenant_and_sees_every_store(): void
    {
        $ids = $this->seedMenus();

        $name = 'tenancy:probe-' . uniqid();

        Artisan::command($name, function () {
            NullTenantContextTest::$observed['console'] = [
                'mode' => StoreScope::tenantMode(),
                'visible' => Menu::query()->pluck('id')->all(),
                'sql' => Menu::query()->toSql(),
            ];
        });

        $this->assertSame(0, Artisan::call($name));

        $this->assertSame(StoreScope::MODE_UNRESOLVED, self::$observed['console']['mode']);
        $this->assertContains($ids[self::STORE_A], self::$observed['console']['visible']);
        $this->assertContains($ids[self::STORE_B], self::$observed['console']['visible']);
        $this->assertStringNotContainsString('is null', strtolower(self::$observed['console']['sql']));
    }

    public function test_a_console_command_returns_nothing_under_the_closed_policy(): void
    {
        $this->seedMenus();
        config(['tenancy.null_tenant' => 'closed']);

        $name = 'tenancy:probe-closed-' . uniqid();

        Artisan::command($name, function () {
            NullTenantContextTest::$observed['console-closed'] = [
                'mode' => StoreScope::tenantMode(),
                'visible' => Menu::query()->pluck('id')->all(),
            ];
        });

        Artisan::call($name);

        $this->assertSame(
            [],
            self::$observed['console-closed']['visible'],
            "Under 'closed' the scheduled Zid sync and every other command would silently process "
            . 'zero rows -- a stopped integration with no error anywhere.'
        );
    }

    // =====================================================================================
    //  CONTEXT 3 of 4 -- THE STOREFRONT
    // =====================================================================================

    /**
     * The storefront resolves its store from the URL slug, not from a session
     * (app/Helper/helper.php getStore()), and its visitors are anonymous or authenticated on the
     * `customers` guard. So the web guard is empty and the tenant is UNRESOLVED.
     *
     * This proves the single most important consequence: the storefront's own
     * `where('store_id', $store->id)` filter -- which passes a store id the scope did NOT choose,
     * and is the shape of 785 call sites -- STILL WORKS. Under a 'closed' default it would not,
     * and every shop on the platform would render empty.
     */
    public function test_the_storefront_can_still_read_its_own_store_while_anonymous(): void
    {
        $ids = $this->seedMenus();

        $this->assertNull(auth()->user());
        $this->assertSame(StoreScope::MODE_UNRESOLVED, StoreScope::tenantMode());

        // Exactly helper.php:2259's shape, but for a store nobody is logged in to.
        $this->assertSame(
            [$ids[self::STORE_B]],
            Menu::where('store_id', self::STORE_B)->pluck('id')->all(),
            'The storefront could not read its OWN menus. Under the shipped fail-open policy an '
            . 'anonymous request must add no predicate, so an explicit foreign store id still '
            . 'resolves. If this fails, every storefront page renders empty.'
        );

        $this->assertStringNotContainsString('is null', strtolower(Menu::query()->toSql()));
    }

    public function test_the_storefront_renders_empty_under_the_closed_policy(): void
    {
        $this->seedMenus();
        config(['tenancy.null_tenant' => 'closed']);

        $this->assertSame(
            [],
            Menu::where('store_id', self::STORE_B)->pluck('id')->all(),
            "This is the measured cost of 'closed': the storefront's own explicit filter returns "
            . 'nothing because the anonymous request has no tenant to satisfy the scope.'
        );
    }

    /**
     * THE ONE REAL DEGRADATION THIS BATCH INTRODUCES, measured and documented rather than
     * discovered later.
     *
     * A MERCHANT who is logged in on the web guard and browses a DIFFERENT store's storefront has
     * a resolvable tenant -- their own -- so the scope narrows the query to their store while the
     * page filters on the store being viewed. The two disagree and the nav menu comes back empty.
     *
     * `getNavMenu()` (helper.php:2316-2333) returns `[]` when the menu is missing, so the page
     * renders without navigation rather than erroring. That is the acceptable end of the trade,
     * and it is the reason `Menu` is in batch 1 while `Product` and `Category` -- where the same
     * disagreement would blank the catalogue itself -- are not.
     */
    public function test_a_merchant_browsing_another_stores_storefront_sees_that_stores_menu_hidden(): void
    {
        $ids = $this->seedMenus();

        $this->be($this->merchantFor(self::STORE_A));

        $this->assertSame(
            [],
            Menu::where('store_id', self::STORE_B)->pluck('id')->all(),
            'Documented consequence, not a defect of this change: scope(A) AND filter(B) matches '
            . 'nothing. The remedy for a genuine cross-store read is allowCrossTenant().'
        );

        // Their own store is unaffected, which is the case that actually matters.
        $this->assertSame([$ids[self::STORE_A]], Menu::where('store_id', self::STORE_A)->pluck('id')->all());

        // And the hatch reaches across when a page legitimately needs to.
        $this->assertSame(
            [$ids[self::STORE_B]],
            StoreScope::allowCrossTenant(fn () => Menu::where('store_id', self::STORE_B)->pluck('id')->all())
        );
    }

    // =====================================================================================
    //  CONTEXT 4 of 4 -- THE `customers` AND `deliveryboy` GUARDS
    // =====================================================================================

    /**
     * `getCurrentStore()` reads `auth()->user()`, which is the DEFAULT (`web`) guard only. A
     * customer authenticates on `customers` and a delivery boy on `deliveryboy`
     * (config/auth.php:43-50), and neither is visible to the web guard. So a fully authenticated
     * customer or delivery boy still has NO resolvable tenant.
     *
     * This is the subtlest of the four, because the request IS authenticated -- just not on the
     * guard the resolver reads. Anyone assuming "authenticated implies scoped" is wrong here.
     */
    public function test_a_customer_on_the_customers_guard_has_no_web_guard_tenant(): void
    {
        $ids = $this->seedMenus();

        $customer = new Customer();
        $customer->forceFill($this->stubs('customers') + [
            'email' => 'shopper-' . uniqid() . '@example.test',
            'store_id' => self::STORE_B,
        ])->save();

        // NOT `$this->be($customer, 'customers')`. Laravel's `be($user, $guard)` calls
        // `shouldUse($guard)` as well as setting the user, which makes `customers` the DEFAULT
        // guard for the rest of the test -- and then `auth()->user()` resolves THROUGH it and
        // returns the customer. That is a test-harness artefact with no counterpart in
        // production, where the default guard stays `web`, and relying on it would have made
        // this test assert the opposite of the thing it exists to prove. Setting the user on the
        // named guard directly leaves the default alone and models the real request.
        auth()->guard('customers')->setUser($customer);

        $this->assertSame('web', auth()->getDefaultDriver(), 'The default guard must stay `web`.');
        $this->assertNotNull(auth('customers')->user(), 'The customer must really be authenticated.');
        $this->assertNull(
            auth()->user(),
            'The web guard must not see a `customers` principal; that is the premise of this test.'
        );

        $this->assertSame(StoreScope::MODE_UNRESOLVED, StoreScope::tenantMode());

        // Fail-open: the customer API's own explicit store filter still resolves.
        $this->assertSame([$ids[self::STORE_B]], Menu::where('store_id', self::STORE_B)->pluck('id')->all());
        $this->assertStringNotContainsString('is null', strtolower(Menu::query()->toSql()));
    }

    public function test_a_delivery_boy_on_its_guard_has_no_web_guard_tenant(): void
    {
        $ids = $this->seedMenus();

        // delivery_boys.created_by is a NOT NULL foreign key into users, so a user must exist.
        $this->merchantFor(self::STORE_A);

        $deliveryBoy = new DeliveryBoy();
        $deliveryBoy->forceFill($this->stubs('delivery_boys') + [
            'email' => 'rider-' . uniqid() . '@example.test',
            'store_id' => self::STORE_B,
        ])->save();

        // See the note in the customers test: `be($user, $guard)` would switch the default
        // guard and defeat the premise.
        auth()->guard('deliveryboy')->setUser($deliveryBoy);

        $this->assertNotNull(auth('deliveryboy')->user());
        $this->assertNull(auth()->user());
        $this->assertSame(StoreScope::MODE_UNRESOLVED, StoreScope::tenantMode());

        $this->assertSame([$ids[self::STORE_B]], Menu::where('store_id', self::STORE_B)->pluck('id')->all());
    }

    public function test_the_customers_guard_returns_nothing_under_the_closed_policy(): void
    {
        $this->seedMenus();

        $customer = new Customer();
        $customer->forceFill($this->stubs('customers') + [
            'email' => 'shopper-' . uniqid() . '@example.test',
            'store_id' => self::STORE_B,
        ])->save();

        // NOT `$this->be($customer, 'customers')`. Laravel's `be($user, $guard)` calls
        // `shouldUse($guard)` as well as setting the user, which makes `customers` the DEFAULT
        // guard for the rest of the test -- and then `auth()->user()` resolves THROUGH it and
        // returns the customer. That is a test-harness artefact with no counterpart in
        // production, where the default guard stays `web`, and relying on it would have made
        // this test assert the opposite of the thing it exists to prove. Setting the user on the
        // named guard directly leaves the default alone and models the real request.
        auth()->guard('customers')->setUser($customer);

        config(['tenancy.null_tenant' => 'closed']);

        $this->assertSame(
            [],
            Menu::where('store_id', self::STORE_B)->pluck('id')->all(),
            "Under 'closed' every customer-facing API response that reads an enforced model would "
            . 'come back empty.'
        );
    }

    // =====================================================================================
    //  The policy surface itself
    // =====================================================================================

    public function test_the_shipped_null_tenant_policy_is_fail_open(): void
    {
        $this->assertSame(
            'open',
            StoreScope::nullTenantPolicy(),
            'The shipped null-tenant policy changed. That is an owner-level decision: `closed` '
            . 'empties the storefront, the customer API and every queued job, as the tests above '
            . 'measure. Do not change it as a side effect of something else.'
        );
    }

    /**
     * A typo must not empty the storefront. Anything that is not exactly 'closed' is treated as
     * 'open', because the failure mode of the alternative is a dark shop.
     */
    public function test_an_unrecognised_policy_value_is_treated_as_open(): void
    {
        $ids = $this->seedMenus();

        config(['tenancy.null_tenant' => 'clsoed']);

        $this->assertSame('open', StoreScope::nullTenantPolicy());
        $this->assertSame([$ids[self::STORE_B]], Menu::where('store_id', self::STORE_B)->pluck('id')->all());
    }

    /**
     * The fail-open hole is a hole whether or not it is logged -- but it does not have to be
     * SILENT. With `tenancy.log_null_tenant` on, every tenantless query against an enforced model
     * leaves a record, which is how an operator inventories which jobs and commands need an
     * explicit predicate. It is off by default because the storefront would write a line per query.
     */
    public function test_the_null_tenant_path_can_be_made_observable(): void
    {
        $this->seedMenus();

        config(['tenancy.log_null_tenant' => true]);

        Log::shouldReceive('debug')
            ->atLeast()
            ->once()
            ->withArgs(function (string $message, array $context = []) {
                return $message === 'tenancy.null_tenant'
                    && ($context['model'] ?? null) === Menu::class
                    && ($context['policy'] ?? null) === 'open'
                    && is_string($context['reason'] ?? null);
            });

        Menu::query()->get();
    }

    public function test_an_enforced_model_never_compiles_a_store_id_is_null_predicate(): void
    {
        // Across every null-tenant shape, under both policies, the R4-02 trap must stay shut.
        foreach (['open', 'closed'] as $policy) {
            config(['tenancy.null_tenant' => $policy]);

            $sql = strtolower(Menu::query()->toSql());

            $this->assertStringNotContainsString('store_id" is null', $sql);
            $this->assertStringNotContainsString('store_id is null', $sql);
        }
    }
}

/**
 * A job that records the tenant context it actually runs in.
 *
 * It lives in this file rather than app/Jobs because app/ is not this workstream's to add to, and
 * because a test-only probe does not belong in application code.
 */
class ProbeTenantContextJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    public function __construct(private string $label)
    {
    }

    public function handle(): void
    {
        NullTenantContextTest::$observed[$this->label] = [
            'mode' => StoreScope::tenantMode(),
            'visible' => Menu::query()->pluck('id')->all(),
            'sql' => Menu::query()->toSql(),
        ];
    }
}
