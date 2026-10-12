<?php

namespace Tests\Feature\Tenancy;

use App\Models\Product;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NEW-model-caching-risk — GATE G2, THE PROOF OBLIGATION.
 *
 * ==========================================================================================
 *  THIS IS THE ONE TEST THAT MUST GO GREEN BEFORE App\Scopes\StoreScope::$enabled IS EVER
 *  SET TO TRUE. Until then the scope stays off, and this class is a specification, not a pass.
 * ==========================================================================================
 *
 * THE RISK, measured.
 *   - `grep -rln Cachable app/Models/ | wc -l` -> 78 of 95 models use the `Cachable` trait from
 *     `genealabs/laravel-model-caching`.
 *   - `config/laravel-model-caching.php` does NOT exist, so package defaults apply.
 *   - `config/cache.php:18` with `CACHE_STORE=file` means ONE shared file bucket per host, not a
 *     per-tenant or per-process one.
 *
 * If that package's cache-key derivation does NOT incorporate global-scope constraints, then
 * enabling a `store_id` global scope would make tenant B serve tenant A's warmed result set. In
 * other words: THE ROLLOUT WOULD CREATE A CROSS-TENANT READ WHERE NONE EXISTS TODAY, which is
 * strictly worse than the gap it is meant to close. `vendor/genealabs` is one of the 237 empty
 * vendor directories in the container this was written in, so the key derivation is UNKNOWN and
 * could not be settled by reading the source.
 *
 * WHY `CACHE_STORE=file` AND NOT THE TEST DEFAULT.
 * phpunit.xml sets `CACHE_STORE=array`, and the `array` store is per-process, so it HIDES the
 * production failure mode entirely: a green run under `array` proves nothing at all about a
 * shared file bucket. This test therefore switches the store to `file` and uses an isolated
 * cache directory. That is also why it cannot simply be folded into the other tenancy tests.
 *
 * HOW TO RUN IT, for whoever clears the gate:
 *   1. Complete `composer install` (owner action -- three locked packages are unreachable from
 *      the container this was written in; see the BLOCKED register in
 *      04-INCREMENTAL-IMPLEMENTATION-PLAN.md section 11).
 *   2. Remove the `markTestSkipped()` below.
 *   3. Run it. If it FAILS, do not enable the scope and do not "fix" the test -- the correct
 *      remedies are a `config/laravel-model-caching.php` that namespaces keys per tenant, or a
 *      non-shared cache store, both of which are behaviour changes with a wide blast radius and
 *      belong in their own reviewed increment.
 */
class ModelCacheTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 101;
    private const STORE_B = 202;

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
     * Tenant A warms the cache; tenant B runs the IDENTICAL query; B must see only B's rows.
     */
    public function test_tenant_b_does_not_receive_tenant_a_warmed_rows_under_a_shared_file_cache(): void
    {
        $this->markTestSkipped(
            'GATE G2 PROOF OBLIGATION -- NOT YET EXECUTABLE. vendor/genealabs is empty in this '
            . 'environment (composer install cannot complete: three locked packages are '
            . 'unreachable), so genealabs/laravel-model-caching cannot be exercised and its cache-'
            . 'key derivation cannot be observed. App\\Scopes\\StoreScope::$enabled is FALSE and '
            . 'must stay false until this test runs and passes. Do not delete this skip to make '
            . 'the suite look green.'
        );

        // ---- the shared-bucket configuration this test exists to exercise -------------------
        config([
            'cache.default' => 'file',
            'cache.stores.file.driver' => 'file',
            'cache.stores.file.path' => storage_path('framework/cache/tenancy-gate-g2'),
        ]);

        $ids = [];
        foreach ([self::STORE_A, self::STORE_B] as $storeId) {
            $ids[$storeId] = \DB::table('products')->insertGetId([
                'name' => 'Widget ' . $storeId,
                'slug' => 'widget-' . $storeId . '-' . uniqid(),
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        StoreScope::$enabled = true;

        // Tenant A warms the cache.
        $this->be($this->merchantFor(self::STORE_A));
        $warmed = Product::all()->pluck('id')->all();
        $this->assertSame([$ids[self::STORE_A]], $warmed);

        // Tenant B runs the same query. A key that omits the scope constraint serves A's rows.
        auth()->logout();
        $this->be($this->merchantFor(self::STORE_B));
        $seenByB = Product::all()->pluck('id')->all();

        $this->assertSame(
            [$ids[self::STORE_B]],
            $seenByB,
            'GATE G2 FAILED. Tenant B received rows warmed by tenant A, so the model cache keys do '
            . 'not incorporate the global scope constraint. DO NOT ENABLE StoreScope. Enabling it '
            . 'would introduce a cross-tenant read rather than prevent one.'
        );

        $this->assertNotContains($ids[self::STORE_A], $seenByB);
    }

    /**
     * The guard on the guard: if someone enables the scope while this gate is still skipped, this
     * test fails and says why. It needs no cache package and no database introspection, so it
     * runs even in the blocked environment.
     */
    public function test_scope_must_not_be_enabled_while_this_gate_is_unproven(): void
    {
        $this->assertFalse(
            StoreScope::$enabled,
            'App\\Scopes\\StoreScope::$enabled is TRUE while the GATE G2 model-cache isolation proof '
            . 'in this class is still skipped. Either run and pass that proof, or set $enabled back '
            . 'to false. 78 of 95 models share one file cache bucket; enabling the scope on an '
            . 'unproven cache-key derivation can CREATE cross-tenant disclosure.'
        );
    }
}
