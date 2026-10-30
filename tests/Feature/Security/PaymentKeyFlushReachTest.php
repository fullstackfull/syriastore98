<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * F218 — WHAT THE D-29.1 CACHE-FLUSH CENSUS FOUND, driven rather than described.
 *
 * Owner decision D-29.1 asked for "a review of every `Cache::flush` call that can reach a payment
 * key". Running that census turned up two things the existing F194/F197/F188 analysis does NOT
 * contain, and this file proves both.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * FINDING 1. THE FLUSH IS NOT ONLY ON MODEL EVENTS, AND THE CHECKOUT ERASES ITS OWN CLAIM.
 *
 * The documented framing is "any `Setting`/`User`/`Order` save anywhere erases the replay claims".
 * True, and incomplete. laravel-model-caching's `Buildable` trait — which `CachedBuilder` uses —
 * also flushes in `decrement()` (:43), `delete()` (:51), `forceDelete()` (:91) and `increment()`
 * (:111). And `ProductStockService` takes checkout stock with exactly that call:
 * `$query->decrement('product_stock', $quantity)` at :107 and `$query->decrement('stock', ...)`
 * at :189, on a `Product`/`ProductVariant` builder.
 *
 * So the sequence inside ONE checkout is: `GuardsGatewayReturn` takes the replay claim with
 * `Cache::add()`, then the order path decrements stock, and that decrement flushes the whole
 * default store — including the claim just taken. **No other tenant, no other request and no
 * admin action is needed.** It is self-inflicting, which is strictly worse than the documented
 * "something else saved a model" story.
 *
 * AND THE FAILURE IS SILENT BY CONSTRUCTION. The claim is taken with `Cache::add()`, which returns
 * TRUE for a key that is not there. An erased claim is therefore an **un-taken** claim, not a
 * detectable one: the next gateway return for the same checkout takes it cleanly and the
 * duplicate-capture guard reports success. Nothing logs, nothing 409s.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * FINDING 2. AN UNGUARDED WHOLE-STORE FLUSH ON A MERCHANT-REACHABLE ROUTE.
 *
 * `PlanController::update` ran a bare `Artisan::call('optimize:clear')` — no `--except` — on
 * `Route::resource('plan')`. It appeared in no document, no ledger row and no guard test, while
 * the ledger's F194 row stated "three reachable explicit flushes remain". Fixed in the same change
 * as this test, with the `['--except' => 'cache']` idiom routes/web.php:817 already uses.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS TEST HAS TO BUILD ITS OWN CACHE STORE.
 *
 * `phpunit.xml` sets `CACHE_STORE=array`, and `ArrayStore` IS taggable — so the model cache tags
 * its flush and touches nothing else, and **the whole Feature suite is structurally blind to this
 * class of defect.** Production runs `database`, which is not taggable, and `DatabaseStore::flush()`
 * is `$this->table()->delete()`: the entire cache table, prefix ignored.
 *
 * So each case here installs a throwaway FILE store (also non-taggable) as the default and drives
 * the real code against it. A file store rather than the database one because it needs no
 * migration and leaves no shared state; a DEDICATED path rather than the app's own, so a flush in
 * this test cannot wipe a cache another test is using.
 */
class PaymentKeyFlushReachTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePath = storage_path('framework/cache/ss-f218-' . getmypid());

        config([
            'cache.stores.ss_f218_nontaggable' => [
                'driver' => 'file',
                'path' => $this->cachePath,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);
        parent::tearDown();
    }

    /** Make the non-taggable store the default, the way production's `database` store is. */
    private function useNonTaggableDefaultStore(): void
    {
        config(['cache.default' => 'ss_f218_nontaggable']);
        config(['laravel-model-caching.store' => null]); // resolves the default, as in production
        Cache::purge('ss_f218_nontaggable');
    }

    /** The real key shapes, from GuardsGatewayReturn:292 and PaymentController:692/804. */
    private function seedPaymentKeys(): array
    {
        $keys = [
            'gateway-return-claim:checkout:f218-nonce',
            'gateway-return-claim:payment:f218-reference',
            'paymera_claim_f218',
            'paymera_pending_f218',
        ];

        foreach ($keys as $key) {
            Cache::add($key, 'paymera', 900);
        }

        return $keys;
    }

    private function survivors(array $keys): array
    {
        return array_values(array_filter($keys, fn ($k) => Cache::has($k)));
    }

    // ───────────────────────────────────────────────────────── the premise, measured not assumed

    public function test_the_taggability_premise_this_whole_class_rests_on(): void
    {
        $this->assertTrue(
            is_subclass_of(ArrayStore::class, TaggableStore::class),
            'ArrayStore is taggable — which is WHY the Feature suite cannot see this defect, '
            . 'since phpunit.xml sets CACHE_STORE=array'
        );

        $this->assertFalse(
            is_subclass_of(DatabaseStore::class, TaggableStore::class),
            'DatabaseStore is NOT taggable. This is the load-bearing fact: production runs '
            . 'CACHE_STORE=database, so laravel-model-caching cannot tag its flush and it '
            . 'degrades to a flush of the whole store.'
        );

        $this->assertFalse(
            is_subclass_of(FileStore::class, TaggableStore::class),
            'FileStore is not taggable either, which is what makes it a faithful stand-in for '
            . 'production here'
        );
    }

    // ──────────────────────────────────────────────── FINDING 1: the checkout erases its own claim

    public function test_a_stock_decrement_erases_every_payment_key_before_its_query_even_runs(): void
    {
        $this->useNonTaggableDefaultStore();

        $keys = $this->seedPaymentKeys();

        $this->assertCount(4, $this->survivors($keys), 'premise: all four claims were taken');

        /*
         * EXACTLY the call ProductStockService:107 makes on the order path — and NO PRODUCT ROW IS
         * CREATED, deliberately, because `Buildable::decrement()` (:43-49) flushes BEFORE it
         * delegates to the parent:
         *
         *     $this->cache($this->makeCacheTags())->flush();
         *     return parent::decrement($column, $amount, $extra);
         *
         * So the erasure does not depend on a row matching, on stock being sufficient, or on the
         * decrement succeeding at all. A decrement that affects ZERO rows — the rejected-for-
         * insufficient-stock path ProductStockService:109 handles — still erases every payment
         * claim in the installation. That is a sharper finding than the one the census started
         * with, and it is why this case needs no fixture.
         */
        /*
         * The query itself is allowed to fail. This class does not migrate a database, so there is
         * no `products` table — and THAT IS THE POINT, driven rather than argued: the flush runs on
         * the line before the query, so the payment claims are gone even when the decrement throws.
         */
        try {
            Product::query()->where('id', 999999)->decrement('product_stock', 1);
        } catch (\Throwable $theQueryItselfMayFail) {
            // swallowed deliberately; the assertion below is about what happened BEFORE it
        }

        $this->assertSame(
            [],
            $this->survivors($keys),
            'A STOCK DECREMENT ERASED EVERY PAYMENT CLAIM. Buildable::decrement() calls '
            . 'flushCache(), and on a non-taggable store that is a flush of the whole default '
            . 'store. This is the checkout erasing the replay claim the same checkout just took — '
            . 'no other tenant or request involved.'
        );
    }

    public function test_the_same_decrement_erases_nothing_on_a_taggable_store(): void
    {
        // The control. Without it the case above could be any old cache bug, and this is also the
        // reason the suite's own CACHE_STORE=array makes the defect invisible.
        config(['cache.default' => 'array']);
        config(['laravel-model-caching.store' => null]);

        $keys = $this->seedPaymentKeys();

        try {
            Product::query()->where('id', 999999)->decrement('product_stock', 1);
        } catch (\Throwable $theQueryItselfMayFail) {
            // same as above: the flush is what is being measured, not the query
        }

        $this->assertCount(
            4,
            $this->survivors($keys),
            'On a TAGGABLE store the flush is tagged and reaches only model-cache entries. The '
            . 'defect is a property of the store, not of the decrement — which is why it survived '
            . 'a suite that runs on the array store.'
        );
    }

    public function test_product_stock_service_really_uses_the_flushing_decrement(): void
    {
        // If this stops being true the case above is testing a call nobody makes.
        $source = (string) file_get_contents(base_path('app/Services/ProductStockService.php'));

        $this->assertStringContainsString(
            "\$query->decrement('product_stock', \$quantity)",
            $source,
            'ProductStockService no longer decrements through the model builder. If it moved to a '
            . 'raw DB::table() write it no longer flushes — but it would then leave Eloquent reads '
            . 'and route-model binding serving the pre-write row, which is the Cachable trap this '
            . "codebase has measured three times. Re-read the finding before adjusting this test."
        );

        $this->assertStringContainsString("\$query->decrement('stock', \$quantity)", $source);
    }

    public function test_an_erased_claim_reads_as_un_taken_rather_than_as_a_conflict(): void
    {
        /*
         * The severity amplifier, and the reason this is a payment defect rather than a cache
         * nuisance. `Cache::add()` returns TRUE for a missing key, so the duplicate-capture guard
         * cannot tell "first time" from "the claim was erased".
         */
        $this->useNonTaggableDefaultStore();

        $key = 'gateway-return-claim:checkout:f218-silent';

        $this->assertTrue(Cache::add($key, 'paymera', 900), 'premise: a fresh claim is taken');
        $this->assertFalse(Cache::add($key, 'paymera', 900), 'premise: a held claim is refused');

        Cache::flush();

        $this->assertTrue(
            Cache::add($key, 'paymera', 900),
            'AFTER THE FLUSH THE SAME CLAIM IS TAKEN CLEANLY. The guard reports a first-time '
            . 'return and processes the capture again. There is no signal to log and no 409 to '
            . 'raise: the information that a claim ever existed is gone with it.'
        );
    }

    // ───────────────────────────────────────── FINDING 2: the unguarded merchant-reachable flush

    public function test_a_cachable_model_write_erases_the_claims_all_by_itself(): void
    {
        /*
         * THE CORRECTION THAT MATTERS, AND IT UNDOES A CLAIM OF MINE.
         *
         * The case below asserts `PlanController` no longer runs a bare `optimize:clear`, and the
         * commit that added it implied that closed the erasure. **IT DID NOT.** An independent
         * review found, and this drives, that `$plan->update($post)` ON THE LINE ABOVE ALREADY
         * FLUSHES THE WHOLE STORE: `App\Models\Plan` uses `Cachable`, so the write goes through
         * laravel-model-caching, and on a non-taggable store that flush is a `Cache::flush()` over
         * everything.
         *
         * So the narrowing removed ONE OF TWO FLUSHES. It is still right on its own terms, and the
         * erasure is F194's to close -- which is implemented and inert. This case exists so the
         * partial fix cannot be read as a whole one again.
         */
        $this->useNonTaggableDefaultStore();

        $keys = $this->seedPaymentKeys();
        $this->assertCount(4, $this->survivors($keys), 'premise: four claims were taken');

        try {
            // A Cachable model write. The table need not exist: the flush happens on the write
            // path before the query, exactly as with Buildable::decrement() above.
            \App\Models\Plan::query()->where('id', 999999)->update(['name' => 'probe']);
        } catch (\Throwable $theQueryItselfMayFail) {
            // swallowed: the assertion is about what happened BEFORE it
        }

        $this->assertSame(
            [],
            $this->survivors($keys),
            'A `Plan` UPDATE ERASED EVERY PAYMENT CLAIM, with no Artisan call involved. This is why '
            . "`--except => 'cache'` on the next line is a narrowing and not a fix, and why F194's "
            . 'isolation is the only thing that closes it.'
        );
    }

    public function test_the_plan_update_handler_no_longer_runs_a_destructive_optimize_clear(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/PlanController.php'));

        /*
         * FORBIDS THE BEHAVIOUR, NOT ONE BYTE SEQUENCE. The first version asserted the absence of
         * exactly `Artisan::call('optimize:clear');` -- so six other destructive spellings would
         * have passed it: double quotes, a leading backslash, `cache:clear` instead, extra
         * whitespace, an array-form call, or `Artisan::call('optimize:clear', [])`. Found by an
         * independent review. The regex below matches any `optimize:clear` or `cache:clear` call in
         * this file that does not carry `--except`.
         */
        $destructive = preg_match_all(
            '/Artisan::call\(\s*[\x27"](?:optimize|cache):clear[\x27"]\s*(?:,\s*\[\s*\])?\s*\)/i',
            $source,
            $m
        );

        $this->assertSame(
            0,
            $destructive,
            'PlanController carries a destructive cache clear again, in one of its spellings: '
            . implode(' | ', $m[0] ?? [])
        );

        $this->assertStringNotContainsString(
            "Artisan::call('optimize:clear');",
            $source,
            'PlanController runs a bare optimize:clear again. In production that clears the '
            . '`database` cache store wholesale, erasing every in-flight payment replay claim in '
            . "the installation, from a route a holder of `Edit Plan` can POST. Use "
            . "Artisan::call('optimize:clear', ['--except' => 'cache']) — the idiom "
            . 'routes/web.php:817 already uses.'
        );

        $this->assertStringContainsString(
            "Artisan::call('optimize:clear', ['--except' => 'cache'])",
            $source,
            'the narrowed form must be the one that is there'
        );
    }

    public function test_the_except_flag_genuinely_spares_the_cache_store(): void
    {
        /*
         * Not a claim about the flag's documentation — the flag is driven, because the fix is
         * worthless if `--except` does not do what it says.
         */
        $this->useNonTaggableDefaultStore();

        $keys = $this->seedPaymentKeys();

        Artisan::call('optimize:clear', ['--except' => 'cache']);

        $this->assertCount(
            4,
            $this->survivors($keys),
            'optimize:clear --except=cache must leave the cache store alone. OptimizeClearCommand '
            . 'rejects by task key before any task runs, and `cache` is cache:clear\'s key.'
        );
    }

    public function test_the_bare_form_is_what_the_fix_prevents(): void
    {
        // The other half of the same measurement: the narrowed call is only meaningful because the
        // bare one really does erase the keys.
        $this->useNonTaggableDefaultStore();

        $keys = $this->seedPaymentKeys();

        Artisan::call('cache:clear');

        $this->assertSame(
            [],
            $this->survivors($keys),
            'premise for the fix: the destructive task really does erase every payment claim'
        );
    }

    public function test_the_services_own_explicit_flush_reaches_the_payment_keys_too(): void
    {
        /*
         * A SITE THE F218 CENSUS MISSED, found by an independent review.
         *
         * The census enumerated `Cache::flush`, `cache()->flush`, `Artisan::call` with
         * cache:clear/optimize:clear, and laravel-model-caching's own flush on model events. It did
         * NOT name `ProductStockService::flushModelCache()`, which calls `$instance->flushCache()`
         * directly at :238 (Product), :249 (ProductVariant) and :297 -- three call sites in one
         * service, all on the checkout stock path.
         *
         * `flushCache()` is `Caching::flushCache()`, which is `$this->cache($tags)->flush()`, and
         * `cache()` applies tags ONLY when the store is taggable. So on production's non-taggable
         * `database` store each of these is a whole-store `Cache::flush()` -- the same mechanism as
         * the decrement above, reached by a different and EXPLICIT route.
         *
         * It does not change F218's conclusion; it widens its reach. The checkout erases the claim
         * it just took by two independent paths, not one.
         */
        $this->useNonTaggableDefaultStore();

        $keys = $this->seedPaymentKeys();
        $this->assertCount(4, $this->survivors($keys), 'premise: four claims were taken');

        // Exactly what flushModelCache() does, on the same model.
        (new Product())->flushCache();

        $this->assertSame(
            [],
            $this->survivors($keys),
            'an EXPLICIT flushCache() on a Cachable model erases every payment claim on a '
            . 'non-taggable store. The census must name this route as well as the event-driven one.'
        );
    }

    public function test_the_stock_service_really_calls_that_explicit_flush(): void
    {
        // So the case above is not testing a call nobody makes.
        $source = (string) file_get_contents(base_path('app/Services/ProductStockService.php'));

        $this->assertStringContainsString(
            '$instance->flushCache();',
            $source,
            'ProductStockService must still flush explicitly, or the case above is hypothetical'
        );

        $this->assertSame(
            3,
            preg_match_all('/\$this->flushModelCache\(/', $source, $m),
            'the three call sites the census now names: :238 Product, :249 ProductVariant, :297'
        );
    }
}
