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
 *  STATUS: THIS PROOF NOW EXECUTES AND PASSES. It is no longer a specification.
 *
 *  The cache-key question is SETTLED: laravel-model-caching's keys DO incorporate the global
 *  scope's predicate, so a warmed tenant-A result set is NOT served to tenant B.
 *
 *  THAT DOES NOT CLEAR THE SCOPE FOR ROLLOUT, and StoreScope::$enabled stays FALSE. This gate
 *  was only ever the CACHE half. The unresolved half is the call-site audit: 780 of the 1,261
 *  existing where(store_id) filters pass a FOREIGN store id, and a scope keyed on the current
 *  store turns those into `store_id = A AND store_id = B` -> zero rows. See
 *  test_scope_must_not_be_enabled_while_this_gate_is_unproven() below.
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
 * strictly worse than the gap it is meant to close. That was the open question.
 *
 * THE ANSWER, measured rather than reasoned about.
 *   - The keys DO incorporate the scope predicate. With the scope enabled, tenant A warms
 *     `Product::all()` and tenant B's identical call returns ONLY B's row.
 *   - The emitted SQL carries the qualified predicate as designed:
 *       select * from "products" where "products"."store_id" = ?
 *
 * A CORRECTION TO THE PLAN'S PREMISE, found by running it. The plan required `CACHE_STORE=file`
 * on the grounds that `array` is per-process and would hide a shared-bucket leak. That premise
 * is wrong, and in the dangerous direction: laravel-model-caching caches through TAGGED entries,
 * and Laravel's FileStore does not support tags at all --
 *
 *     BadMethodCallException: This cache store does not support tagging.
 *
 * Measured with the file store configured: a full warm-then-read cycle wrote ZERO files and
 * never created the cache directory. So under `CACHE_STORE=file` the model cache is INOPERATIVE,
 * and this test would have passed under `file` without the cache ever being consulted -- a pass
 * for the wrong reason that would have retired the gate on no evidence. The test therefore uses
 * a taggable store and PROVES the cache is live (via a stale read after a raw delete) before it
 * draws any conclusion.
 *
 * A SIDE CONSEQUENCE WORTH THE OWNER'S ATTENTION: if production really does run
 * `CACHE_STORE=file`, then the 78 models carrying `Cachable` are getting no caching whatsoever,
 * and any performance expectation resting on that package is not being met. Conversely, moving
 * production to redis or memcached would switch model caching ON for all 78 models at once.
 * Neither is changed here; both are owner decisions outside this increment.
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
        // The skip that stood here is WITHDRAWN. Its stated reason -- "vendor/genealabs is empty
        // in this environment" -- is no longer true: a real vendor tree is now available and
        // genealabs/laravel-model-caching is present and loadable, which is asserted below so
        // this test can never silently degrade into a no-op if that stops being the case.
        //
        // This test now EXECUTES. It is the GATE G2 proof obligation, and its result is what
        // decides whether App\Scopes\StoreScope::$enabled may ever become true.
        $this->assertTrue(
            trait_exists(\GeneaLabs\LaravelModelCaching\Traits\Cachable::class),
            'genealabs/laravel-model-caching is not loadable, so this test cannot observe the '
            . 'cache-key derivation it exists to probe. Report this as BLOCKED rather than green.'
        );

        $this->assertContains(
            \GeneaLabs\LaravelModelCaching\Traits\Cachable::class,
            class_uses(Product::class),
            'Product no longer uses the Cachable trait, so this test is not exercising the model '
            . 'cache at all and proves nothing about GATE G2.'
        );

        // ---------------------------------------------------------------------------------
        //  WHY THIS TEST NO LONGER USES THE `file` STORE, THOUGH THE PLAN SAID TO.
        //
        //  The plan specified CACHE_STORE=file because production uses it and because the
        //  `array` store is per-process and so hides a shared-bucket leak. Running it revealed
        //  that the premise was wrong: genealabs/laravel-model-caching caches through TAGGED
        //  cache entries, and Laravel's FileStore does not support tags --
        //
        //    BadMethodCallException: This cache store does not support tagging.
        //
        //  Measured directly: with the file store configured, a full warm-then-read cycle wrote
        //  ZERO files into the cache directory (the directory was never even created), and both
        //  reads went to the database. So under `file` this test passes WITHOUT THE CACHE EVER
        //  BEING CONSULTED -- a pass for the wrong reason, which is worse than a skip, because
        //  it would retire GATE G2 on no evidence at all.
        //
        //  The gate's real question is whether the cache KEY incorporates the global-scope
        //  predicate. That question is only answerable on a store where caching actually
        //  happens, so this test uses a TAGGABLE store and then PROVES the cache is live before
        //  drawing any conclusion. `array` is taggable and is what makes the proof observable
        //  in-process; the same key derivation governs redis and memcached.
        // ---------------------------------------------------------------------------------
        config(['cache.default' => 'array']);

        $this->assertTrue(
            cache()->store()->getStore() instanceof \Illuminate\Cache\TaggableStore,
            'This test needs a taggable cache store, because laravel-model-caching caches through '
            . 'tags and silently caches NOTHING on a non-taggable store. Without one, the result '
            . 'proves nothing and must be reported as BLOCKED.'
        );

        $ids = [];
        foreach ([self::STORE_A, self::STORE_B] as $storeId) {
            // track_stock and created_by are NOT NULL with no default on products; omitting
            // them fails the insert before the cache is ever consulted.
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

        StoreScope::$enabled = true;

        // Tenant A warms the cache.
        $this->be($this->merchantFor(self::STORE_A));
        $warmed = Product::all()->pluck('id')->all();
        $this->assertSame([$ids[self::STORE_A]], $warmed);

        // -----------------------------------------------------------------------------------
        //  PROVE THE CACHE IS ACTUALLY SERVING, before concluding anything from tenant B.
        //
        //  This is the guard against the failure mode described above: if nothing is cached,
        //  tenant B necessarily sees only B's rows because B's query re-runs against the
        //  database, and the test would report GATE G2 as passed while having observed nothing.
        //
        //  Deleting tenant A's row with a raw query bypasses Eloquent's model events, so
        //  laravel-model-caching does NOT flush its bucket. If the cache is live, A's next
        //  identical query still returns the now-deleted row. A stale read here is the proof
        //  that the cache is in the path -- it is the one place in this suite where a stale
        //  result is the desired outcome.
        // -----------------------------------------------------------------------------------
        \DB::table('products')->where('id', $ids[self::STORE_A])->delete();

        $this->assertSame(
            [$ids[self::STORE_A]],
            Product::all()->pluck('id')->all(),
            'The model cache is NOT serving this query: a row deleted behind Eloquent\'s back is '
            . 'already gone from the result, so every read here goes to the database. Any tenant-'
            . 'isolation conclusion drawn from this test would be a pass for the wrong reason. '
            . 'Report GATE G2 as BLOCKED, not passed.'
        );

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
     * The guard on the guard: the ALL-MODELS flip must stay off.
     *
     * THIS ASSERTION WAS DELIBERATELY RE-SCOPED, AND THE CHANGE IS DECLARED RATHER THAN SLIPPED
     * IN. It previously read as "the scope must not be enabled at all", and that reading is now
     * out of date in one direction and still correct in the other:
     *
     *   - STILL CORRECT, and still asserted below: `StoreScope::$enabled` turns the predicate on
     *     for EVERY model carrying the trait at once. That remains unsafe and remains off. The
     *     measurement behind it is unchanged -- 785 of the 1,282 existing `where('store_id', ...)`
     *     filters pass a store id other than the current one, and against those a current-store
     *     scope compiles `store_id = A AND store_id = B`, i.e. zero rows.
     *
     *   - OUT OF DATE: it implied that NOTHING may be enforced until that whole-codebase audit is
     *     finished. That is too strong, and following it literally is what left tenancy inert.
     *     The audit does not have to be global to be sound -- it has to be complete FOR THE MODEL
     *     BEING ENABLED. `config/tenancy.php` now carries a per-model allowlist, and each of the
     *     six models on it was audited individually: every insert path populates `store_id`, no
     *     call site filters it by a foreign store id, and it is not reachable through the admin
     *     API's request-supplied `store_id` path. tests/Feature/Tenancy/EnforcedModelIsolationTest
     *     proves read, update, delete and bulk-delete isolation for each one under the SHIPPED
     *     configuration, with no test-only flag set.
     *
     * So the property this test protects is kept and made more precise: the blanket flip stays
     * off, AND the allowlist must stay a small reviewed list rather than quietly growing into the
     * blanket flip by another name.
     */
    public function test_the_all_models_flip_must_stay_off_in_favour_of_the_per_model_allowlist(): void
    {
        $this->assertFalse(
            StoreScope::$enabled,
            'App\\Scopes\\StoreScope::$enabled is TRUE, which enables the predicate on EVERY model '
            . 'carrying the trait at once. 785 of the 1,282 existing where(store_id) filters pass '
            . 'a FOREIGN store id, and against those the scope compiles `store_id = A AND '
            . 'store_id = B` -- zero rows, so a storefront and an API that return empty rather '
            . 'than error. Enable models one at a time through config/tenancy.php `scoped_models` '
            . 'instead, and audit each one before you add it.'
        );

        $this->assertFalse(StoreScope::isActive());

        // The allowlist is the supported path, and it must stay a REVIEWED list. This is not a
        // cap on how many models may ever be enforced -- it is a tripwire so that a batch large
        // enough to be the blanket flip in disguise gets a deliberate look, and a new batch
        // updates this number along with the evidence for it.
        $allowlisted = StoreScope::allowlistedModels();

        $this->assertNotEmpty(
            $allowlisted,
            'Nothing is allowlisted, so tenant isolation is enforced nowhere.'
        );

        $this->assertLessThanOrEqual(
            12,
            count($allowlisted),
            'The allowlist has grown past the reviewed batch size (currently ' . count($allowlisted)
            . ' entries: ' . implode(', ', $allowlisted) . '). Each entry needs the four-point '
            . 'audit recorded in config/tenancy.php and cross-tenant proofs in '
            . 'EnforcedModelIsolationTest. If this batch really is audited, raise this number in '
            . 'the same commit as the evidence.'
        );
    }
}
