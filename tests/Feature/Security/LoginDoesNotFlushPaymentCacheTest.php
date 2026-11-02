<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\StripsSourceComments;

/**
 * F197 — A LOGIN MUST NOT EMPTY EVERY TENANT'S PAYMENT CACHE.
 * =============================================================================================
 *
 * `AuthenticatedSessionController::store()` calls `Utility::addNewData()` unconditionally after
 * `$request->authenticate()`, and the first statement of that method was
 * `Artisan::call('cache:clear')` — a flush of the whole default store. In production
 * (`CACHE_STORE=database`) that store holds:
 *
 *   `gateway-return-claim:*`   the replay control that stops ONE capture becoming TWO paid orders.
 *                              It is `Cache::add()` with a 48-hour TTL, so a forgotten key is an
 *                              UN-TAKEN claim.
 *   `paymera_pending_*`        a buyer mid-checkout. Losing it gives them a captured payment and
 *                              no order — F186's outcome, caused by a stranger's login.
 *   `paymera_claim_*`, `paymera_lock_*`   F96's duplicate-order window.
 *   `settings_store_*`         `rememberForever`, so every store re-reads its settings.
 *
 * SO THE REPLAY CONTROL'S REAL LIFETIME WAS "UNTIL THE NEXT LOGIN BY ANYBODY", not 48 hours — and
 * that invariant is quoted in the F185 and F186 docblocks as the reason those changes are safe.
 *
 * WHAT THIS FILE CAN HONESTLY MEASURE, AND WHAT IT CANNOT — established by probing rather than
 * assumed, because two separate test-environment artefacts made the first cut lie in both
 * directions.
 *
 * MEASURABLE HERE. `addNewData()`'s `cache:clear` empties the DEFAULT store, whatever its driver,
 * so the login cases seed and read through the default store and mean exactly what they say.
 *
 * NOT MEASURABLE HERE: anything that survives a call to `Artisan::call('optimize')`, which the
 * `/config-cache` route makes. `optimize` runs `config:cache`, which calls `config:clear` and
 * rebuilds the cached configuration — and that re-resolves the container's managers. A probe over
 * `optimize`, `route:clear`, `view:clear` and `config:clear` showed only `optimize` losing a seeded
 * entry, and `optimize:clear --except=cache` keeping it. The reason is not a flush:
 *
 *   - on the ARRAY store (`phpunit.xml`'s `CACHE_STORE=array`) a re-resolved `CacheManager` hands
 *     back a fresh `ArrayStore` whose in-memory array is empty;
 *   - and seeding through `Cache::store('database')` instead does not help, because this suite's
 *     database is SQLite `:memory:` — re-resolving the database manager discards the whole
 *     in-memory database, `cache` table and all.
 *
 * Neither effect exists in production, where the store is a real table on a persistent connection.
 * So the `/config-cache` route's payment-key behaviour is measured in
 * `tests/Support/Security/bin/verify-login-and-settings-cache-paths-on-mysql.php` and NOT asserted
 * here. Asserting it here would have been asserting an artefact — and "fixing" production code to
 * satisfy it, which is what the first cut nearly did, would have been worse.
 *
 * What this file still pins about that route is the source ratchet at the bottom: no explicit
 * `cache:clear`, and `optimize:clear` excluded from its cache task.
 */
class LoginDoesNotFlushPaymentCacheTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** Keys that stand between one capture and a second paid order. */
    private const PAYMENT_KEYS = [
        'gateway-return-claim:checkout:0000000000000000000000000000000000000000',
        'gateway-return-claim:payment:khalti:1:1111111111111111111111111111111111111111',
        'paymera_pending_paymera_f197probe',
        'paymera_claim_paymera_f197probe',
        'paymera_lock_paymera_f197probe',
    ];

    /* Named `seedPaymentKeys` and not `seed`: Laravel\'s own `TestCase::seed()` is PUBLIC, so a
       private `seed()` here is a fatal access-level error rather than a test failure. */
    private function seedPaymentKeys(): void
    {
        foreach (self::PAYMENT_KEYS as $key) {
            Cache::forever($key, 'storefront-return');
        }

        $this->assertSame(
            count(self::PAYMENT_KEYS),
            $this->surviving(),
            'the premise: every payment key is readable before the act under test'
        );
    }

    private function surviving(): int
    {
        $n = 0;

        foreach (self::PAYMENT_KEYS as $key) {
            if (Cache::get($key) === 'storefront-return') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * THE DEFECT ITSELF, driven through the real login route.
     *
     * Not through `addNewData()` directly: the point of F197 is that an ordinary login reaches it,
     * so the case has to come in the way a merchant does.
     */
    public function test_a_successful_login_leaves_every_payment_key_in_place(): void
    {
        [$merchant] = $this->makeTenant();
        $merchant->forceFill(['password' => bcrypt('f197-password')])->save();

        /* `addNewData()` is reached on login and seeds the permission list on its FIRST run. Run it
           once here so the login below is the ordinary case — nothing left to seed — which is what
           every login after the first on a given deployment looks like. */
        Utility::addNewData();

        $this->seedPaymentKeys();

        $response = $this->post(route('login'), [
            'email' => $merchant->email,
            'password' => 'f197-password',
        ]);

        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 302], true),
            'the premise: the login route answered. Status ' . $response->getStatusCode()
        );
        $this->assertAuthenticated('web');

        $this->assertSame(
            count(self::PAYMENT_KEYS),
            $this->surviving(),
            'a successful login emptied the payment cache. In production that is every tenant\'s'
            . ' gateway replay claims, pending payment records and idempotency locks — so the'
            . ' replay control lives until the next login by anybody, not 48 hours.'
        );
    }

    /**
     * THE HALF THAT HAD TO KEEP WORKING. Without this the fix is a permission bug: a deployment
     * that adds a permission name would leave it invisible behind Laratrust's hour-long cache.
     *
     * `addNewData()`'s invalidation is unchanged in substance — the same two commands in the same
     * order — it simply runs only when the method actually created or granted something.
     */
    public function test_a_seeding_run_that_creates_something_still_invalidates(): void
    {
        Utility::addNewData();

        /* Remove one row the seeder is responsible for, so the next run has work to do. */
        $victim = Permission::where('name', 'Manage Dashboard')->first();
        $this->assertNotNull($victim, 'the premise: the seeder created the row this case deletes');
        $victim->delete();

        $this->seedPaymentKeys();

        Utility::addNewData();

        $this->assertSame(
            0,
            $this->surviving(),
            'a seeding run that really created a permission row did NOT invalidate, so a new'
            . ' permission can stay invisible behind Laratrust\'s cache for its full hour. The'
            . ' fix is meant to make the flush RARE, not absent.'
        );
        $this->assertNotNull(
            Permission::where('name', 'Manage Dashboard')->first(),
            'the premise: the second run really did re-create the row'
        );
    }

    /** And a run with nothing to do invalidates nothing — which is every login after the first. */
    public function test_a_seeding_run_with_nothing_to_do_invalidates_nothing(): void
    {
        Utility::addNewData();

        $this->seedPaymentKeys();

        Utility::addNewData();

        $this->assertSame(
            count(self::PAYMENT_KEYS),
            $this->surviving(),
            'a no-op seeding run still flushed the cache, which is the defect'
        );
    }

    /*
     * THE `/config-cache` PAYMENT-KEY CASE LIVED HERE AND HAS MOVED, deliberately rather than
     * quietly. That route calls `Artisan::call('optimize')`, and for the two reasons in the class
     * docblock no store this suite can reach survives it — so a case here could only ever have
     * measured an in-memory artefact. It is in
     * `tests/Support/Security/bin/verify-login-and-settings-cache-paths-on-mysql.php`, on a real
     * table and a persistent connection, which is where the question can be answered.
     *
     * Also recorded there: `Artisan::call('optimize')` does NOT clear the application cache. Its
     * task list is `config:cache`, `event:cache`, `route:cache`, `view:cache` and nothing else, so
     * the destruction this slice fixed came from the explicit `cache:clear` and from
     * `optimize:clear`, whose own first task is `cache:clear` — which removing the explicit call
     * alone did not stop.
     */

    /**
     * AND IT STILL DOES THE ONE THING `cache:clear` BOUGHT IT — SOURCE-ASSERTED, FOR THE SAME
     * REASON THE PAYMENT-KEY CASE MOVED.
     *
     * `Utility::GetValByName()` caches each store's settings under `settings_store_<id>` with
     * `rememberForever()`, and nothing else in the codebase forgets that key. If the route stopped
     * dropping it, a merchant who changed a setting and cleared their cache would keep reading the
     * old value forever — so the compiled-artifact clears alone are not a sufficient replacement.
     *
     * A BEHAVIOURAL VERSION OF THIS CASE EXISTED AND SURVIVED ITS BITE. Removing the handler's
     * `Cache::forget()` left it green, because `Artisan::call('optimize')` re-resolves the cache
     * manager and discards the in-memory array store — so the key vanished whether the handler
     * forgot it or not. That is the same artefact documented in the class docblock, and it makes
     * this question unanswerable in this suite exactly as it makes the payment-key one
     * unanswerable. The behavioural proof is in
     * `tests/Support/Security/bin/verify-login-and-settings-cache-paths-on-mysql.php`; what is
     * left here is the structural fact, which a source assertion CAN establish.
     */
    public function test_the_config_cache_route_still_forgets_the_stores_settings(): void
    {
        $routes = $this->sourceWithoutComments('routes/web.php');
        $configCache = strpos($routes, "Route::get('/config-cache'");
        $this->assertNotFalse($configCache, 'the /config-cache route is gone; re-derive this case');

        $this->assertMatchesRegularExpression(
            "/Cache::forget\('settings_store_'\s*\.\s*getCurrentStore\(\)\)/",
            substr($routes, $configCache, 2000),
            'the route no longer drops `settings_store_<id>`, which is the only thing its'
            . ' `cache:clear` bought that the compiled-artifact clears do not. That entry is'
            . ' `rememberForever` and nothing else in the codebase forgets it, so a merchant who'
            . ' changes a setting and clears their cache would keep reading the old value forever.'
        );
    }

    /**
     * THE RATCHET. Neither path may go back to an unconditional whole-store flush.
     *
     * Source-asserted with comments stripped, because both files now DESCRIBE the old call in
     * prose — and a census that cannot tell a description from a call is the mistake this
     * repository has made six times (see `Tests\Support\StripsSourceComments`).
     */
    public function test_neither_path_empties_the_whole_store_unconditionally(): void
    {
        $utility = $this->sourceWithoutComments('app/Models/Utility.php');

        $start = strpos($utility, 'public static function addNewData()');
        $this->assertNotFalse($start, 'addNewData() is gone or renamed; re-derive this case');

        $body = substr($utility, $start, 40000);
        $guard = strpos($body, 'if ($ssSeedChanges > 0)');
        $clear = strpos($body, "Artisan::call('cache:clear')");

        $this->assertNotFalse($guard,
            'addNewData() no longer guards its invalidation on having seeded something, so every'
            . ' login flushes the payment cache again — F197');
        $this->assertNotFalse($clear, 'the invalidation is gone entirely; re-derive this case');
        $this->assertLessThan($clear, $guard,
            'the flush happens BEFORE the guard, so the guard does not gate it');

        $routes = $this->sourceWithoutComments('routes/web.php');
        $configCache = strpos($routes, "Route::get('/config-cache'");
        $this->assertNotFalse($configCache, 'the /config-cache route is gone; re-derive this case');

        $routeBody = substr($routes, $configCache, 2000);

        $this->assertStringNotContainsString(
            "Artisan::call('cache:clear')",
            $routeBody,
            'the /config-cache route empties the whole store again. Any logged-in merchant can'
            . ' reach it and it has no permission check.'
        );

        /*
         * AND `optimize:clear` MUST STILL EXCLUDE ITS CACHE TASK. Removing the explicit
         * `cache:clear` was NOT enough: `OptimizeClearCommand::getOptimizeClearTasks()` lists
         * `'cache' => 'cache:clear'` FIRST, so a bare `optimize:clear` empties the store just the
         * same. The first version of this ratchet looked only for the explicit call and would have
         * passed a revert to a bare `optimize:clear` — found by biting it.
         */
        if (str_contains($routeBody, "optimize:clear")) {
            $this->assertMatchesRegularExpression(
                "/optimize:clear'\s*,\s*\[\s*'--except'\s*=>\s*'cache'/",
                $routeBody,
                'the route calls `optimize:clear` without excluding its cache task, so it empties'
                . ' the whole store through `optimize:clear` even with no explicit `cache:clear`.'
                . ' That is the same defect by a different spelling.'
            );
        }
    }
}
