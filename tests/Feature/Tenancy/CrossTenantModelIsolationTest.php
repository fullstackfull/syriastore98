<?php

namespace Tests\Feature\Tenancy;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipping;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\StoreWithdrawal;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * R4-04 / R4-05 — the MODEL-SIDE half of the cross-tenant denial matrix.
 *
 * SCOPE OF THIS CLASS, stated precisely so it is not mistaken for more than it is.
 * The HTTP-level denial matrix of 05-REGRESSION-TEST-PLAN.md section 2.8 -- GET/PUT/DELETE against
 * another tenant's product, order, customer, withdrawal and so on, asserting 404/403 AND that the
 * target row is unchanged -- belongs to the controller-side increments (12, 13, 14) and to the
 * authorization and commerce workstreams, which own those controllers and route files. This class
 * proves only the model-side property those fixes rest on: that given two tenants' rows in one
 * shared table, the machinery in App\Models\Concerns\BelongsToStore denies tenant A access to
 * tenant B's rows. Without this, the controller fix has nothing to stand on; with only this, the
 * endpoints are still open. BOTH halves are required.
 *
 * R4-06 is preserved throughout: one shared database, one shared schema, tenancy enforced in the
 * application layer. Nothing here splits a database or duplicates a table per tenant.
 *
 * EXECUTION STATUS: BLOCKED in the environment this was written in. PHPUnit cannot be installed
 * in these checkouts -- three locked packages are unreachable (lahirulhr/laravel-payhere needs
 * api.github.com, which the proxy refuses for out-of-scope repositories; yoomoney/* is hosted on
 * git.yoomoney.ru). The underlying predicate and ownership logic WAS executed against the real
 * classes with framework stubs (39/39 assertions passing); the parts needing a real database and
 * a real Eloquent builder are unverified here and are honestly reported as such.
 */
class CrossTenantModelIsolationTest extends TestCase
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

    private function merchantFor(int $storeId): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Merchant ' . $storeId,
            'email' => "merchant{$storeId}@example.test",
            'password' => bcrypt('not-a-real-credential'),
            'type' => 'admin',
            'current_store' => $storeId,
        ])->save();

        return $user;
    }

    /**
     * Insert one row per tenant directly, bypassing the scope entirely, so the fixture itself
     * cannot be shaped by the control under test.
     */
    private function seedPair(string $table, array $base = []): array
    {
        $ids = [];

        foreach ([self::STORE_A, self::STORE_B] as $storeId) {
            $ids[$storeId] = \DB::table($table)->insertGetId($base + [
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    // ---------------------------------------------------------------------------------------
    //  The explicit predicate -- the PRIMARY control, available whether or not GATE G2 is green
    // ---------------------------------------------------------------------------------------

    public function test_for_store_returns_only_the_named_tenants_products(): void
    {
        $ids = $this->seedPair('products', ['name' => 'Widget', 'slug' => 'widget-' . uniqid()]);

        $visible = Product::forStore(self::STORE_A)->pluck('id')->all();

        $this->assertContains($ids[self::STORE_A], $visible);
        $this->assertNotContains(
            $ids[self::STORE_B],
            $visible,
            'forStore() leaked another tenant\'s product. This is R4-04.'
        );
    }

    /**
     * FAIL CLOSED. An explicit isolation call with an unresolvable tenant must return NOTHING,
     * not everything. This is the opposite of the global scope's null behaviour, and the
     * difference is deliberate: the scope must not empty the storefront, an explicit call must
     * not leak.
     */
    public function test_for_store_fails_closed_on_an_unresolvable_tenant(): void
    {
        $this->seedPair('products', ['name' => 'Widget', 'slug' => 'widget-' . uniqid()]);

        foreach ([null, '', 'not-a-number'] as $bad) {
            $this->assertSame(
                0,
                Product::forStore($bad)->count(),
                'forStore() must deny, not disclose, when the tenant cannot be resolved.'
            );
        }
    }

    public function test_for_current_store_follows_the_authenticated_merchant(): void
    {
        $ids = $this->seedPair('products', ['name' => 'Widget', 'slug' => 'widget-' . uniqid()]);

        $this->be($this->merchantFor(self::STORE_A));
        $visible = Product::forCurrentStore()->pluck('id')->all();

        $this->assertSame([$ids[self::STORE_A]], $visible);
    }

    public function test_for_current_store_fails_closed_for_an_anonymous_caller(): void
    {
        $this->seedPair('products', ['name' => 'Widget', 'slug' => 'widget-' . uniqid()]);

        $this->assertNull(auth()->user());
        $this->assertSame(0, Product::forCurrentStore()->count());
    }

    // ---------------------------------------------------------------------------------------
    //  The R4-04 one-liner, across every domain the task names
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: class-string, 1: string, 2: array<string, mixed>}>
     */
    public static function tenantOwnedDomains(): array
    {
        return [
            // domain               model class                 table                        extra columns
            'products' => [Product::class, 'products', ['name' => 'Widget', 'slug' => 'p-']],
            'catalogue categories' => [Category::class, 'categories', ['name' => 'Cat', 'slug' => 'c-']],
            'customers' => [Customer::class, 'customers', ['email' => 'c-']],
            'carts' => [Cart::class, 'carts', []],
            'order notes' => [OrderNote::class, 'order_notes', []],
            'finance: wallets' => [StoreWallet::class, 'store_wallets', []],
            'finance: wallet ledger' => [StoreWalletTransaction::class, 'store_wallet_transactions', []],
            'finance: withdrawals' => [StoreWithdrawal::class, 'store_withdrawals', []],
            'staff: delivery boys' => [DeliveryBoy::class, 'delivery_boys', ['email' => 'd-']],
            'shipping' => [Shipping::class, 'shippings', []],
        ];
    }

    /**
     * `isOwnedByCurrentStore()` is the one-liner the controller fix uses:
     *     abort_unless($model->isOwnedByCurrentStore(), 403);
     * It must deny tenant B's row to tenant A in every domain, and it must fail closed on a row
     * whose store_id is NULL (NEW-null-store-id-generators means such rows exist).
     *
     * Use 403, NEVER 401: repo-B lib/network/network.dart:285-288 maps any 401 to a handler that
     * wipes all local storage and force-navigates to login (:333-341), so a 401 here would log
     * the merchant out of the Flutter app on every tenancy denial.
     *
     * @dataProvider tenantOwnedDomains
     */
    public function test_ownership_check_denies_the_other_tenant(string $modelClass, string $table, array $extra): void
    {
        if (! Schema::hasTable($table)) {
            // The 15 undeclared finance/wallet tables are GATE F. Skipping is the honest outcome
            // -- a silent pass here would be worse than a reported gap.
            $this->markTestSkipped(
                "Table `{$table}` has no migration: it is one of the 15 undeclared tables held "
                . 'behind GATE F (SCHEMA-01), whose column types are not derivable from $fillable.'
            );
        }

        $base = [];
        foreach ($extra as $column => $value) {
            $base[$column] = str_ends_with((string) $value, '-') ? $value . uniqid() : $value;
        }

        $ids = $this->seedPair($table, $base);

        $this->be($this->merchantFor(self::STORE_A));

        $mine = $modelClass::withoutStoreScope()->find($ids[self::STORE_A]);
        $theirs = $modelClass::withoutStoreScope()->find($ids[self::STORE_B]);

        $this->assertTrue($mine->isOwnedByCurrentStore(), 'Own row must be allowed.');
        $this->assertFalse(
            $theirs->isOwnedByCurrentStore(),
            $modelClass . ': tenant A was granted access to tenant B\'s row. This is R4-04.'
        );

        $this->assertTrue($mine->isOwnedByStore(self::STORE_A));
        $this->assertFalse($theirs->isOwnedByStore(self::STORE_A));

        // An unscoped row belongs to nobody.
        $orphan = $modelClass::withoutStoreScope()->find($ids[self::STORE_A]);
        $orphan->store_id = null;
        $this->assertFalse(
            $orphan->isOwnedByCurrentStore(),
            'A row with store_id NULL must not be treated as owned by the current tenant.'
        );
    }

    // ---------------------------------------------------------------------------------------
    //  The global scope, exercised with it deliberately switched on
    // ---------------------------------------------------------------------------------------

    /**
     * Proves the scope WORKS, so that the day GATE G2 clears there is evidence rather than hope.
     * It does not and must not change the default, which stays off -- see
     * StoreScopeTest::test_store_scope_is_disabled_by_default_under_gate_g2.
     */
    public function test_enabled_global_scope_hides_the_other_tenants_rows(): void
    {
        $ids = $this->seedPair('products', ['name' => 'Widget', 'slug' => 'widget-' . uniqid()]);

        StoreScope::$enabled = true;
        $this->be($this->merchantFor(self::STORE_A));

        $this->assertSame([$ids[self::STORE_A]], Product::query()->pluck('id')->all());
        $this->assertNull(Product::find($ids[self::STORE_B]));

        // And the escape hatch still reaches across, for jobs, commands and platform reports.
        $all = StoreScope::allowCrossTenant(fn () => Product::query()->pluck('id')->all());
        $this->assertCount(2, $all);
    }

    /**
     * R4-05, the coexistence question. 780 of the 1,261 existing `where('store_id', ...)` filters
     * pass a store id the scope did not choose. Where the value AGREES with the scope, the result
     * must be unchanged -- `store_id = 5 AND store_id = 5` is logically identical. This is what
     * licenses leaving all 1,261 filters in place as defence in depth instead of sweeping them out.
     */
    public function test_an_existing_manual_filter_with_the_same_value_does_not_double_filter(): void
    {
        $ids = $this->seedPair('products', ['name' => 'Widget', 'slug' => 'widget-' . uniqid()]);

        StoreScope::$enabled = true;
        $this->be($this->merchantFor(self::STORE_A));

        $viaManualFilter = Product::where('store_id', self::STORE_A)->pluck('id')->all();

        $this->assertSame(
            [$ids[self::STORE_A]],
            $viaManualFilter,
            'A pre-existing manual filter agreeing with the scope must be redundant, not harmful.'
        );
    }

    /**
     * The OTHER side of R4-05, documented as a KNOWN CONSEQUENCE rather than a defect of this
     * code: a manual filter passing a FOREIGN store id composes with the scope into
     * `store_id = A AND store_id = B`, which matches nothing. This test asserts that reality
     * explicitly so nobody enables the scope expecting otherwise. It is the measured reason the
     * trait is applied to 11 classified models rather than all 95, and the reason GATE G2 is not
     * the only thing standing between here and a global rollout.
     */
    public function test_a_foreign_store_id_filter_returns_nothing_once_the_scope_is_enabled(): void
    {
        $ids = $this->seedPair('products', ['name' => 'Widget', 'slug' => 'widget-' . uniqid()]);

        StoreScope::$enabled = true;
        $this->be($this->merchantFor(self::STORE_A));

        $this->assertSame(
            [],
            Product::where('store_id', self::STORE_B)->pluck('id')->all(),
            'Documented consequence, not a bug in this change: a foreign-store-id filter plus the '
            . 'scope yields zero rows. 780 of 1,261 existing filters are of this shape, which is '
            . 'why the scope is gated and applied selectively.'
        );

        // The correct way to make such a query is an explicit cross-tenant block.
        $crossTenant = StoreScope::allowCrossTenant(
            fn () => Product::where('store_id', self::STORE_B)->pluck('id')->all()
        );

        $this->assertSame([$ids[self::STORE_B]], $crossTenant);
    }

    // ---------------------------------------------------------------------------------------
    //  Settings -- tenant data, but deliberately NOT globally scoped
    // ---------------------------------------------------------------------------------------

    /**
     * Settings ARE tenant-owned data, and a cross-tenant settings read is a real risk
     * (NEW-settings-fail-open). But a GLOBAL scope on Setting would break the platform, because
     * app/Models/Utility.php:289 and :896 run `Setting::where('store_id', 1)` with a hardcoded id
     * while configuring file storage. So Setting is classified AMBIGUOUS / HIGH-RISK: no global
     * scope, and isolation enforced by the explicit predicate at the call site.
     *
     * This test pins both halves of that decision.
     */
    public function test_settings_are_isolated_by_explicit_predicate_and_never_globally_scoped(): void
    {
        foreach ([self::STORE_A, self::STORE_B] as $storeId) {
            \DB::table('settings')->insert([
                'name' => 'defult_currancy',
                'value' => $storeId === self::STORE_A ? 'SYP' : 'USD',
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        StoreScope::$enabled = true;
        $this->be($this->merchantFor(self::STORE_A));

        // Not globally scoped: both rows are still reachable without an explicit predicate.
        $this->assertSame(
            2,
            Setting::where('name', 'defult_currancy')->count(),
            'Setting must NOT be globally scoped: Utility.php:289/:896 pass a hardcoded store_id '
            . 'of 1, and a current-store scope would make storage configuration return zero rows.'
        );

        // Isolation comes from the explicit predicate instead.
        $this->assertSame(
            'SYP',
            Setting::where('name', 'defult_currancy')->where('store_id', self::STORE_A)->value('value')
        );
        $this->assertSame(
            'USD',
            Setting::where('name', 'defult_currancy')->where('store_id', self::STORE_B)->value('value')
        );
    }
}
