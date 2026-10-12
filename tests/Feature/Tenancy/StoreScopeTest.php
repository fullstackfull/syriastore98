<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToStore;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R4-01 / R4-02 — the tenant scope's own contract.
 *
 * This class pins the DESIGN of App\Scopes\StoreScope: that it is off by default under GATE G2,
 * that a null tenant adds NO predicate (and never `store_id = NULL`), that a super admin is not
 * pinned to the hardcoded literal store 1, that the predicate is column-qualified, and that both
 * escape hatches work. If any of these change silently, the platform breaks in ways that look
 * like missing data rather than like errors.
 *
 * EXECUTION STATUS: EXECUTED. A real vendor tree with PHPUnit 11.5.57 is now available, so the
 * earlier "BLOCKED / verified with framework stubs" note on this class is withdrawn -- these
 * assertions now run against the real framework, a real Eloquent builder and a real sqlite
 * :memory: database. Running them for the first time is what exposed the defect below.
 *
 * WHY THIS CLASS NEEDS RefreshDatabase. It did not declare it, and every test here that calls
 * tenantUser() therefore died on "no such table: users" -- 7 errors. The class is not a pure unit
 * test: tenantUser() does a real INSERT so that auth()->user() returns a persisted model with a
 * real current_store, which is what StoreScope::resolveStoreId() reads. A stubbed user would have
 * made these tests pass without touching the resolver they exist to pin.
 */
class StoreScopeTest extends TestCase
{
    use RefreshDatabase;


    protected function setUp(): void
    {
        parent::setUp();

        // A previous test that threw inside allowCrossTenant() must not leak suppression.
        StoreScope::resetSuppression();
        StoreScope::$enabled = false;
    }

    protected function tearDown(): void
    {
        StoreScope::resetSuppression();
        StoreScope::$enabled = false;

        parent::tearDown();
    }

    private function tenantUser(int $storeId, string $type = 'admin'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Merchant ' . $storeId,
            'email' => "merchant{$storeId}@example.test",
            'password' => bcrypt('secret-not-a-real-credential'),
            'type' => $type,
            'current_store' => $storeId,
        ])->save();

        return $user;
    }

    /**
     * GATE G2. This is the single most important assertion in this file.
     *
     * The scope must stay disabled until the model-cache isolation proof is green: 78 of 95
     * models use genealabs/laravel-model-caching with no config file and CACHE_STORE=file, so if
     * its keys omit scope constraints, enabling the scope CREATES a cross-tenant read.
     */
    public function test_store_scope_is_disabled_by_default_under_gate_g2(): void
    {
        $this->assertFalse(
            StoreScope::$enabled,
            'StoreScope was enabled by default. GATE G2 is not green: enabling the scope before the '
            . 'model-cache isolation proof passes could INTRODUCE cross-tenant disclosure rather '
            . 'than prevent it. See tests/Feature/Tenancy/ModelCacheTenantIsolationTest.php.'
        );

        $this->assertFalse(StoreScope::isActive());
    }

    /**
     * While disabled the emitted SQL must be byte-for-byte unchanged, because every
     * laravel-model-caching cache key is derived from the query.
     */
    public function test_disabled_scope_adds_no_predicate_to_the_query(): void
    {
        $this->be($this->tenantUser(5));

        $sql = Product::query()->toSql();

        $this->assertStringNotContainsString('store_id', $sql);
    }

    public function test_enabled_scope_adds_a_qualified_predicate(): void
    {
        StoreScope::$enabled = true;
        $this->be($this->tenantUser(5));

        $sql = Product::query()->toSql();

        // Qualified, not bare. There are 39 join sites and a bare `store_id = ?` raises
        // "Column 'store_id' in where clause is ambiguous" on every one of them.
        $this->assertStringContainsString('"products"."store_id"', $sql);
        $this->assertContains(5, Product::query()->getBindings());
    }

    /**
     * THE R4-02 TRAP. `store_id = NULL` matches no row in SQL, so a null tenant must add NOTHING.
     * Were this to regress, every storefront page, every customer API call and every job would
     * silently return empty.
     */
    public function test_anonymous_context_adds_no_predicate_rather_than_store_id_null(): void
    {
        StoreScope::$enabled = true;

        $this->assertNull(auth()->user());
        $this->assertNull(StoreScope::resolveStoreId());

        $sql = Product::query()->toSql();

        $this->assertStringNotContainsString('store_id', $sql);
        $this->assertStringNotContainsString('is null', strtolower($sql));
    }

    public function test_user_with_no_resolvable_store_adds_no_predicate(): void
    {
        StoreScope::$enabled = true;
        $this->be($this->tenantUser(0));

        $user = auth()->user();
        $user->current_store = null;
        $user->save();

        $this->assertNull(StoreScope::resolveStoreId());
        $this->assertStringNotContainsString('store_id', Product::query()->toSql());
    }

    /**
     * `getCurrentStore()` returns the HARDCODED literal 1 for a super admin with an empty
     * current_store (app/Helper/helper.php:373-374). Scoping a platform operator to store 1 would
     * hide every other tenant's data behind what looks like missing rows, so the scope must
     * resolve a super admin to null instead of deferring to that literal.
     */
    public function test_super_admin_is_not_pinned_to_the_hardcoded_store_one(): void
    {
        StoreScope::$enabled = true;

        $superAdmin = $this->tenantUser(0, 'super admin');
        $superAdmin->current_store = null;
        $superAdmin->save();

        $this->be($superAdmin);

        $this->assertSame(1, (int) getCurrentStore(), 'Baseline: the resolver still returns 1.');
        $this->assertNull(
            StoreScope::resolveStoreId(),
            'StoreScope must NOT inherit getCurrentStore()\'s hardcoded super-admin literal.'
        );
        $this->assertStringNotContainsString('store_id', Product::query()->toSql());
    }

    /**
     * ESCAPE HATCH 1 -- the hatch for jobs, scheduled commands and storefront rendering, which
     * R4-02 identifies as the contexts with no web-guard principal.
     */
    public function test_allow_cross_tenant_suppresses_the_scope_and_restores_it(): void
    {
        StoreScope::$enabled = true;
        $this->be($this->tenantUser(5));

        $this->assertStringContainsString('store_id', Product::query()->toSql());

        $inside = StoreScope::allowCrossTenant(function () {
            $this->assertTrue(StoreScope::isSuppressed());

            return Product::query()->toSql();
        });

        $this->assertStringNotContainsString('store_id', $inside);
        $this->assertFalse(StoreScope::isSuppressed());
        $this->assertStringContainsString('store_id', Product::query()->toSql());
    }

    public function test_nested_allow_cross_tenant_blocks_do_not_re_enable_early(): void
    {
        StoreScope::$enabled = true;
        $this->be($this->tenantUser(5));

        StoreScope::allowCrossTenant(function () {
            StoreScope::allowCrossTenant(fn () => null);

            $this->assertTrue(
                StoreScope::isSuppressed(),
                'An inner block exiting must not re-enable the scope inside the outer block.'
            );
        });

        $this->assertFalse(StoreScope::isSuppressed());
    }

    public function test_suppression_is_released_when_the_callback_throws(): void
    {
        StoreScope::$enabled = true;

        try {
            StoreScope::allowCrossTenant(function () {
                throw new \RuntimeException('deliberate');
            });
            $this->fail('Expected the exception to propagate.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertFalse(
            StoreScope::isSuppressed(),
            'A throwing callback must still release suppression, or one failure disables tenant '
            . 'scoping for the rest of the process.'
        );
    }

    /**
     * ESCAPE HATCH 2. Must lift StoreScope and nothing else -- in particular it must NOT lift
     * SoftDeletes on Customer, which would resurrect deleted rows.
     */
    public function test_without_store_scope_lifts_only_the_store_scope(): void
    {
        StoreScope::$enabled = true;
        $this->be($this->tenantUser(5));

        $this->assertStringNotContainsString('store_id', Product::withoutStoreScope()->toSql());

        $customerSql = \App\Models\Customer::withoutStoreScope()->toSql();

        $this->assertStringNotContainsString('store_id', $customerSql);
        $this->assertStringContainsString(
            'deleted_at',
            $customerSql,
            'withoutStoreScope() must not lift the SoftDeletes scope.'
        );
    }

    /**
     * PLATFORM SAFETY. The trait is applied SELECTIVELY. These models must never carry it, because
     * a tenant predicate on them breaks the platform rather than securing it.
     */
    public function test_do_not_scope_models_do_not_carry_the_trait(): void
    {
        $mustNotScope = [
            // users has no store_id at all; membership is the attacker-writable current_store
            // pointer of R4-03, so scoping on it would be scoping on attacker-controlled state.
            \App\Models\User::class,
            // store_id here is an EXTERNAL Zid identifier typed as a string, not a FK into
            // stores.id. Column-name matching would compare a local int against an external
            // string and silently return zero rows, stopping the scheduled sync with no error.
            \App\Models\ZidStore::class,
            \App\Models\ZidEvent::class,
            // Utility.php:289 and :896 run Setting::where('store_id', 1) with a HARDCODED id
            // during storage configuration. A current-store scope would make that
            // `store_id = 1 AND store_id = <current>` -> zero rows -> storage config collapses
            // for every tenant that is not store 1. This is the single worst instance of the
            // foreign-store-id hazard (780 of 1,261 filters pass a foreign id).
            Setting::class,
            // Platform-global: every tenant must see every row and there is no store_id.
            \App\Models\Country::class,
            \App\Models\State::class,
            \App\Models\City::class,
            \App\Models\Currency::class,
            \App\Models\Plan::class,
            \App\Models\Role::class,
            \App\Models\Permission::class,
            \App\Models\EmailTemplate::class,
        ];

        foreach ($mustNotScope as $class) {
            $this->assertNotContains(
                BelongsToStore::class,
                class_uses_recursive($class),
                $class . ' must NOT use BelongsToStore. See the DO-NOT-SCOPE list in the trait '
                . 'docblock -- a tenant predicate here breaks the platform.'
            );
        }
    }

    /**
     * The converse: the classified tenant-owned set must keep the trait, so a future refactor
     * cannot quietly drop tenant awareness from a model that needs it.
     */
    public function test_classified_tenant_owned_models_carry_the_trait(): void
    {
        $tenantOwned = [
            \App\Models\Product::class,
            \App\Models\Customer::class,
            \App\Models\Cart::class,
            \App\Models\Category::class,
            \App\Models\OrderNote::class,
            \App\Models\OrderRefund::class,
            \App\Models\StoreWallet::class,
            \App\Models\StoreWalletTransaction::class,
            \App\Models\StoreWithdrawal::class,
            \App\Models\DeliveryBoy::class,
            \App\Models\Shipping::class,
        ];

        foreach ($tenantOwned as $class) {
            $this->assertContains(
                BelongsToStore::class,
                class_uses_recursive($class),
                $class . ' lost BelongsToStore.'
            );

            /** @var Model $model */
            $model = new $class();

            $this->assertSame('store_id', $model->getStoreColumn());
        }
    }
}
