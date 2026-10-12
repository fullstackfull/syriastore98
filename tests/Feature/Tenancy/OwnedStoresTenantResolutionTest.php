<?php

namespace Tests\Feature\Tenancy;

use App\Models\Menu;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE OWNED-STORES TENANT PREDICATE -- the answer to the constraint that defeated batch 1.
 *
 * Batch 1 deferred Product, Order, Category, Cart, Customer, Setting and Shipping because the
 * admin API resolves its tenant FROM THE REQUEST and authorises it by OWNERSHIP
 * (Api/DashboardController::resolveOwnedStore, :3627-3641, `created_by = creatorId()`), never by
 * `current_store`. A `store_id = current_store` predicate therefore compiles
 * `store_id = 7 AND store_id = 5` for a merchant who owns 5 and 7 and asks about 7 -- a
 * legitimate, authorised request answered with zero rows and no error.
 *
 * `StoreScope::admissibleStoreIds()` replaces that predicate with
 * `{current_store} UNION {stores whose created_by = creatorId()}`. This file proves the two
 * properties that make it safe, and proves them by EXECUTING QUERIES, not by reading the class:
 *
 *   1. SUPERSET. `current_store` is always a member, so nothing that is visible under the old
 *      predicate becomes invisible under the new one. This is the property that rules out, by
 *      construction, the silent-blank-page failure this scope has already caused twice here.
 *   2. STILL DENIES. A store the principal does not own is still excluded, so the ~70 admin-API
 *      sites still doing a bare `Store::find($request->store_id)` have their IDOR closed rather
 *      than widened.
 *
 * Fixtures go in through raw `DB::table()` so the control under test cannot shape the data it is
 * judged against, and the cross-tenant assertions read back through raw `DB::table()` too, so a
 * scope that merely HIDES a row it failed to protect cannot pass.
 */
class OwnedStoresTenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** Merchant A owns both of these. */
    private const STORE_A1 = 311;
    private const STORE_A2 = 312;

    /** Merchant B owns this one, and must never be visible to merchant A. */
    private const STORE_B = 399;

    private User $merchantA;
    private User $merchantB;

    protected function setUp(): void
    {
        parent::setUp();

        StoreScope::resetSuppression();
        StoreScope::forgetOwnedStoreIds();

        try {
            cache()->flush();
        } catch (\Throwable $e) {
            // A non-flushable store is not a reason to fail the run.
        }

        // `stores.created_by` is NOT NULL with a real FK to `users.id`
        // (2023_12_14_090259_create_stores_table.php:21,25), so the owners must exist first.
        $this->merchantA = $this->makeMerchant('a', self::STORE_A1);
        $this->merchantB = $this->makeMerchant('b', self::STORE_B);

        // Merchant A owns TWO stores. This is the shape batch 1 could not serve.
        $this->makeStore(self::STORE_A1, $this->merchantA->id);
        $this->makeStore(self::STORE_A2, $this->merchantA->id);
        $this->makeStore(self::STORE_B, $this->merchantB->id);

        StoreScope::forgetOwnedStoreIds();
    }

    private function makeMerchant(string $tag, int $currentStore, string $type = 'admin'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Merchant ' . $tag,
            'email' => 'merchant-' . $tag . '-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'type' => $type,
            'current_store' => $currentStore,
        ])->save();

        return $user;
    }

    private function makeStore(int $storeId, int $createdBy): void
    {
        if (DB::table('stores')->where('id', $storeId)->exists()) {
            return;
        }

        $row = [];

        foreach (DB::select('pragma table_info(stores)') as $column) {
            $name = $column->name;

            if ($name === 'id' || $column->dflt_value !== null || (int) $column->notnull === 0) {
                continue;
            }

            $row[$name] = str_contains(strtolower((string) $column->type), 'int') ? 0 : 'fixture';
        }

        $row['id'] = $storeId;
        $row['created_by'] = $createdBy;
        $row['created_at'] = now();
        $row['updated_at'] = now();

        DB::table('stores')->insert($row);
    }

    /**
     * One `menus` row per store, inserted raw. `Menu` is used as the probe model throughout
     * because it has been enforced since batch 1, so these assertions are about the PREDICATE
     * and not about whether a newly allowlisted model works.
     */
    private function seedMenus(): void
    {
        foreach ([self::STORE_A1, self::STORE_A2, self::STORE_B] as $storeId) {
            DB::table('menus')->insert([
                'name' => 'menu-' . $storeId,
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // -------------------------------------------------------------------------------------
    //  What the resolver actually returns
    // -------------------------------------------------------------------------------------

    public function test_a_multi_store_merchant_resolves_to_every_store_they_own(): void
    {
        $this->actingAs($this->merchantA);

        $tenant = StoreScope::resolveTenant();

        $this->assertSame(StoreScope::MODE_TENANT, $tenant['mode']);
        $this->assertSame(self::STORE_A1, $tenant['store_id'], 'store_id must stay the CURRENT store: '
            . 'resolveStoreId(), forStore() and isOwnedByCurrentStore() all depend on that contract.');
        $this->assertSame(
            [self::STORE_A1, self::STORE_A2],
            $tenant['store_ids'],
            'Both of merchant A\'s stores must be admissible, ascending and de-duplicated.'
        );
    }

    public function test_the_admissible_set_never_contains_another_merchants_store(): void
    {
        $this->actingAs($this->merchantA);

        $this->assertNotContains(
            self::STORE_B,
            StoreScope::resolveTenant()['store_ids'],
            'Merchant B\'s store must never be admissible to merchant A. If this fails the '
            . 'predicate has been widened into a cross-tenant read.'
        );
    }

    public function test_current_store_is_always_a_member_which_is_the_superset_property(): void
    {
        // The guarantee that nothing visible today becomes invisible. Asserted for a merchant
        // whose current_store is their SECOND store, so it cannot pass by accident of ordering.
        $this->merchantA->forceFill(['current_store' => self::STORE_A2])->save();
        $this->actingAs($this->merchantA);
        StoreScope::forgetOwnedStoreIds();

        $this->assertContains(self::STORE_A2, StoreScope::resolveTenant()['store_ids']);
    }

    public function test_current_store_is_admitted_even_when_the_owned_set_does_not_contain_it(): void
    {
        // THE SUPERSET PROPERTY AT ITS SHARPEST. A merchant whose `current_store` points at a
        // store they did not create -- a transferred store, or one a super admin made for them --
        // must still see it, because that is what happens today. Scoping them to their owned set
        // alone would blank a working panel, which is the regression this test exists to forbid.
        $orphan = $this->makeMerchant('orphan', self::STORE_B);
        $this->actingAs($orphan);
        StoreScope::forgetOwnedStoreIds();

        $tenant = StoreScope::resolveTenant();

        $this->assertSame([self::STORE_B], $tenant['store_ids']);
    }

    public function test_a_single_store_merchant_resolves_to_exactly_one_id(): void
    {
        $this->actingAs($this->merchantB);

        $this->assertSame([self::STORE_B], StoreScope::resolveTenant()['store_ids']);
    }

    // -------------------------------------------------------------------------------------
    //  The compiled SQL -- the part that governs behaviour and cache keys
    // -------------------------------------------------------------------------------------

    public function test_a_single_store_merchant_still_compiles_the_original_single_equality(): void
    {
        // Byte-identical SQL for the common principal is what keeps the 78 `Cachable` models'
        // cache keys unchanged, and what makes this change safe to ship without a cache flush.
        $this->actingAs($this->merchantB);

        $sql = Menu::query()->toSql();

        $this->assertStringContainsString('"menus"."store_id" = ?', $sql);
        $this->assertStringNotContainsString(' in (', strtolower($sql));
    }

    public function test_a_multi_store_merchant_compiles_a_qualified_where_in(): void
    {
        $this->actingAs($this->merchantA);

        $query = Menu::query();
        $sql = strtolower($query->toSql());

        // Qualified, not bare: there are 39 join sites and a bare `store_id` would raise
        // "Column 'store_id' in where clause is ambiguous" on every one of them.
        $this->assertStringContainsString('"menus"."store_id" in (?, ?)', $sql);
        $this->assertSame([self::STORE_A1, self::STORE_A2], $query->getBindings());
    }

    // -------------------------------------------------------------------------------------
    //  Behaviour: the admin-API shape that batch 1 could not serve
    // -------------------------------------------------------------------------------------

    public function test_the_legitimate_multi_store_request_shape_returns_rows(): void
    {
        $this->seedMenus();
        $this->actingAs($this->merchantA);

        // EXACTLY the shape of Api/DashboardController's list methods: an explicit filter on a
        // store id that came from the request and was authorised by `resolveOwnedStore()`, which
        // here is merchant A's SECOND store and not their `current_store`.
        $names = Menu::where('store_id', self::STORE_A2)->pluck('name')->all();

        $this->assertSame(
            ['menu-' . self::STORE_A2],
            $names,
            'This is the regression batch 1 was deferred to avoid. Under `= current_store` this '
            . 'compiles `store_id = 312 AND store_id = 311` and returns nothing, so an authorised '
            . 'merchant sees an empty list with no error.'
        );
    }

    public function test_the_same_request_shape_against_an_unowned_store_returns_nothing(): void
    {
        $this->seedMenus();
        $this->actingAs($this->merchantA);

        // The ~70 admin-API sites that still do a bare `Store::find($request->store_id)` with no
        // ownership check. The scope is what closes them.
        $this->assertSame(
            [],
            Menu::where('store_id', self::STORE_B)->pluck('name')->all(),
            'Merchant A read merchant B\'s rows through a request-supplied store id.'
        );

        // And the row is genuinely still there -- the assertion above must be about denial, not
        // about a fixture that never existed.
        $this->assertSame(
            1,
            DB::table('menus')->where('store_id', self::STORE_B)->count(),
            'Raw read-back: merchant B\'s row must exist, or the denial above proved nothing.'
        );
    }

    public function test_a_multi_store_merchant_sees_both_of_their_stores_in_an_unfiltered_list(): void
    {
        $this->seedMenus();
        $this->actingAs($this->merchantA);

        $names = Menu::orderBy('store_id')->pluck('name')->all();

        $this->assertSame(['menu-' . self::STORE_A1, 'menu-' . self::STORE_A2], $names);
    }

    public function test_a_cross_tenant_delete_is_still_denied_for_a_multi_store_merchant(): void
    {
        $this->seedMenus();
        $this->actingAs($this->merchantA);

        // The BulkDeleteController.php:115 call shape, which is `whereIn('id', ...)->delete()`
        // with no tenant predicate at all.
        $victimId = DB::table('menus')->where('store_id', self::STORE_B)->value('id');

        Menu::whereIn('id', [$victimId])->delete();

        $this->assertSame(
            1,
            DB::table('menus')->where('id', $victimId)->count(),
            'Raw read-back: merchant B\'s menu was deleted by merchant A. Widening the predicate '
            . 'to the owned-store set must not weaken the delete path.'
        );
    }

    public function test_super_admin_still_sees_across_every_store(): void
    {
        $this->seedMenus();

        $superAdmin = $this->makeMerchant('super', self::STORE_A1, 'super admin');
        $this->actingAs($superAdmin);
        StoreScope::forgetOwnedStoreIds();

        $tenant = StoreScope::resolveTenant();

        $this->assertSame(StoreScope::MODE_SUPER_ADMIN, $tenant['mode'], 'The platform-operator '
            . 'bypass must stay a NAMED branch, not an accident of a null tenant.');
        $this->assertSame([], $tenant['store_ids']);
        $this->assertStringNotContainsString('store_id', Menu::query()->toSql());
        $this->assertCount(3, Menu::all(), 'A super admin must see all three stores.');
    }

    // -------------------------------------------------------------------------------------
    //  Both configured modes, as the brief requires
    // -------------------------------------------------------------------------------------

    public function test_the_current_store_mode_restores_the_original_narrower_predicate(): void
    {
        config(['tenancy.tenant_scope' => 'current_store']);
        StoreScope::forgetOwnedStoreIds();

        $this->seedMenus();
        $this->actingAs($this->merchantA);

        $this->assertSame('current_store', StoreScope::tenantScopeMode());
        $this->assertSame([self::STORE_A1], StoreScope::resolveTenant()['store_ids']);

        // And this is the cost of that mode, measured rather than described: the legitimate
        // multi-store admin-API request goes empty.
        $this->assertSame(
            [],
            Menu::where('store_id', self::STORE_A2)->pluck('name')->all(),
            'Under `current_store` the authorised multi-store request must return nothing. If it '
            . 'returns rows, the two modes are not actually different and one of them is a lie.'
        );
    }

    public function test_an_unrecognised_mode_falls_back_to_owned_stores(): void
    {
        // A config typo must not silently narrow the predicate and blank a page -- the same
        // reasoning as `nullTenantPolicy()`.
        config(['tenancy.tenant_scope' => 'nonsense-value']);

        $this->assertSame('owned_stores', StoreScope::tenantScopeMode());
    }

    public function test_the_kill_switch_still_overrides_the_new_predicate(): void
    {
        config(['tenancy.enforcement_enabled' => false]);

        $this->seedMenus();
        $this->actingAs($this->merchantA);

        $this->assertStringNotContainsString('store_id', Menu::query()->toSql());
        $this->assertCount(3, Menu::all());
    }

    // -------------------------------------------------------------------------------------
    //  The memo must not outlive its database
    // -------------------------------------------------------------------------------------

    public function test_a_store_created_after_the_first_scoped_query_is_picked_up_once_forgotten(): void
    {
        // Documents the one operational caveat of memoising: the set is cached for the life of
        // the application instance, so a store created mid-request is not admitted until the memo
        // is dropped. Stated as a test rather than left for someone to discover.
        $this->actingAs($this->merchantA);

        $this->assertSame([self::STORE_A1, self::STORE_A2], StoreScope::resolveTenant()['store_ids']);

        $this->makeStore(777, $this->merchantA->id);

        $this->assertSame(
            [self::STORE_A1, self::STORE_A2],
            StoreScope::resolveTenant()['store_ids'],
            'Memoised within the application instance, by design: one lookup per request however '
            . 'many scoped models are touched.'
        );

        StoreScope::forgetOwnedStoreIds();

        $this->assertSame([self::STORE_A1, self::STORE_A2, 777], StoreScope::resolveTenant()['store_ids']);
    }

    public function test_the_resolver_degrades_to_the_current_store_when_the_stores_table_is_unreachable(): void
    {
        // `ownedStoreIds()` must never throw out of query compilation. An empty owned set has to
        // mean "fall back to the old predicate", never "no predicate" and never "zero rows".
        $this->actingAs($this->merchantA);
        StoreScope::forgetOwnedStoreIds();

        DB::statement('DROP TABLE stores');

        $tenant = StoreScope::resolveTenant();

        $this->assertSame(StoreScope::MODE_TENANT, $tenant['mode']);
        $this->assertSame([self::STORE_A1], $tenant['store_ids']);
        $this->assertStringContainsString('"menus"."store_id" = ?', Menu::query()->toSql());
    }
}
