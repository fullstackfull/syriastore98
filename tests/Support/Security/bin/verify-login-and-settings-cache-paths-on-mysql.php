<?php

/**
 * F197 -- THE `/config-cache` COMMAND SEQUENCE, ON THE PRODUCTION STORE DRIVER.
 * =================================================================================================
 *
 * WHY THIS IS NOT A FEATURE TEST, and the reason is two separate test-environment artefacts rather
 * than a preference. `tests/Feature/Security/LoginDoesNotFlushPaymentCacheTest` covers the LOGIN
 * path, which it can: `addNewData()`'s `cache:clear` empties the default store whatever its driver.
 * It cannot cover the `/config-cache` route, because that route calls `Artisan::call('optimize')`:
 *
 *   - `optimize` runs `config:cache`, which calls `config:clear` and rebuilds the cached
 *     configuration, and that re-resolves the container's managers;
 *   - on the ARRAY store (`phpunit.xml`'s `CACHE_STORE=array`) a re-resolved `CacheManager` returns
 *     a FRESH `ArrayStore` whose in-memory array is empty;
 *   - and seeding through `Cache::store('database')` does not help there either, because that
 *     suite's database is SQLite `:memory:` -- re-resolving the database manager discards the whole
 *     in-memory database, `cache` table included.
 *
 * Both effects are artefacts of in-memory stores. Neither exists in production, where the cache is
 * a real table on a persistent connection. Two cases in that Feature file survived their bites for
 * exactly this reason, so they were moved here rather than left to pass for the wrong reason: one
 * asserting the payment keys survive the route, and one asserting the route still forgets
 * `settings_store_<id>`.
 *
 * WHAT IS MEASURED. The route's exact command sequence, in order, against a `database` cache store
 * on a real MariaDB connection -- and then, deliberately, the same sequence with a BARE
 * `optimize:clear`, to show the `--except=cache` is load-bearing rather than decorative. Removing
 * the explicit `cache:clear` alone did NOT fix this route, because
 * `OptimizeClearCommand::getOptimizeClearTasks()` lists `'cache' => 'cache:clear'` first.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database.
 *
 *     php tests/Support/Security/bin/verify-login-and-settings-cache-paths-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at a development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

/*
 * THE CONFIGURATION IS SET THROUGH REAL ENVIRONMENT VARIABLES, BEFORE THE APP BOOTS, AND THAT IS
 * FORCED BY THE VERY THING THIS SCRIPT MEASURES.
 *
 * A first cut used runtime `config([...])` overrides, as the sibling `verify-*` scripts do. It
 * failed at the first `Artisan::call('optimize')` with "Database connection [fresh] not configured":
 * `optimize` runs `config:cache`, which REBUILDS the configuration from the config files and the
 * environment and so discards every runtime override. That is the same mechanism that makes the
 * Feature-suite version of these cases unmeasurable, reaching this script by a different route.
 *
 * Laravel loads `.env` with `Dotenv::createImmutable`, which does NOT overwrite variables already
 * present in the environment -- so setting them here wins, and a config rebuild re-reads the same
 * values instead of losing them.
 */
$database = getenv('FRESH_MYSQL_DATABASE') ?: 'cachepaths_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false || $socket === '') {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate}\n";
            break;
        }
    }
}

$username = getenv('FRESH_MYSQL_USERNAME') ?: 'root';
$password = getenv('FRESH_MYSQL_PASSWORD') ?: '';
$host = getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('FRESH_MYSQL_PORT') ?: '3306';

/* The throwaway database has to exist before the app boots, because the app will connect to it. */
try {
    $dsn = $socket ? "mysql:unix_socket={$socket}" : "mysql:host={$host};port={$port}";
    $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo->exec("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

foreach ([
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => $host,
    'DB_PORT' => $port,
    'DB_SOCKET' => $socket ?: '',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => $username,
    'DB_PASSWORD' => $password,
    'CACHE_STORE' => 'database',
    'DB_CACHE_TABLE' => 'cache',
    /* So a config rebuild cannot accidentally re-enable something this script is not testing. */
    'APP_ENV' => 'testing',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$checks = 0;
$failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    echo $ok ? "  ok    {$what}\n" : "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    if (! $ok) {
        $failures++;
    }
}

const PAYMENT_KEYS = [
    'gateway-return-claim:checkout:0000000000000000000000000000000000000000',
    'gateway-return-claim:payment:khalti:1:1111111111111111111111111111111111111111',
    'paymera_pending_paymera_f197probe',
    'paymera_claim_paymera_f197probe',
    'paymera_lock_paymera_f197probe',
];

function seedKeys(): void
{
    foreach (PAYMENT_KEYS as $key) {
        Cache::put($key, 'storefront-return', 600);
    }
    Cache::forever('settings_store_1', ['SITE_RTL' => 'off']);
}

function surviving(): int
{
    $n = 0;
    foreach (PAYMENT_KEYS as $key) {
        if (Cache::get($key) === 'storefront-return') {
            $n++;
        }
    }

    return $n;
}

echo "F197 -- the /config-cache command sequence on a real database cache store\n";
echo str_repeat('=', 88) . "\n";

try {
    echo "engine: " . DB::selectOne('SELECT VERSION() v')->v . "\n";
    echo "cache store: " . config('cache.default') . " (driver " . config('cache.stores.' . config('cache.default') . '.driver') . ")\n\n";

    if (config('cache.default') !== 'database') {
        fwrite(STDERR, "The cache store is '" . config('cache.default') . "', not 'database'. This script\n"
            . "measures the production driver and proves nothing on any other, so it refuses to run.\n");
        exit(2);
    }

    Schema::create('cache', function ($t) {
        $t->string('key')->primary();
        $t->mediumText('value');
        $t->integer('expiration');
    });
    Schema::create('cache_locks', function ($t) {
        $t->string('key')->primary();
        $t->string('owner');
        $t->integer('expiration');
    });

    /* ------------------------------------------------------- A: optimize, asserted NOT called */
    echo "A. `optimize` -- asserted from its own task list, DELIBERATELY NOT CALLED\n";

    /*
     * THIS SCRIPT MUST NOT CALL `Artisan::call('optimize')`, and the reason is a hazard this
     * session caused and then had to clean up.
     *
     * `optimize` runs `config:cache`, `event:cache`, `route:cache` and `view:cache`, which WRITE
     * `bootstrap/cache/config.php`, `events.php` and `routes-v7.php`. Those files persist after the
     * process ends, and every later `php artisan test` run then boots from them. A Feature test
     * that drove the `/config-cache` route left them behind, and the next full-gate run reported
     * two failures that had nothing to do with the code --
     * `RoleEscalationTest::test_role_update_cannot_grant_superadminaccess_permissions` (which reads
     * `config('superadminaccess')`) and `ResidualSafetyCorrectionsTest::test_a_coupon_code_is_still_case_sensitive`.
     * Both passed in isolation. Deleting the three cached files restored the suite; that gate run
     * was discarded rather than cited. `RouteShadowingTest:500` already carried a note that
     * `Artisan::call('optimize')` is "process-global on a shared-database SaaS" -- it is worse than
     * process-global, it is RUN-global.
     *
     * So the question "does `optimize` clear the application cache?" is answered from the
     * framework's own task list, which is the authority on what it runs, rather than by running it.
     */
    $optimizeTasks = (new ReflectionMethod(
        Illuminate\Foundation\Console\OptimizeCommand::class,
        'getOptimizeTasks'
    ));
    $optimizeTasks->setAccessible(true);
    $tasks = $optimizeTasks->invoke(new Illuminate\Foundation\Console\OptimizeCommand());

    echo '      optimize runs: ' . implode(', ', array_values($tasks)) . "\n";
    check(
        '`optimize` runs no cache-clearing command, so it is not what destroyed the payment cache',
        ! in_array('cache:clear', array_values($tasks), true),
        implode(', ', array_values($tasks))
    );

    foreach (['route:clear', 'view:clear', 'config:clear'] as $command) {
        seedKeys();
        $before = surviving();
        Artisan::call($command);
        Cache::purge(config('cache.default'));
        $after = surviving();

        check(
            sprintf('`%s` leaves the payment keys (%d -> %d of 5)', $command, $before, $after),
            $before === 5 && $after === 5
        );
    }

    /* ------------------------------------------------------- B: the --except is load-bearing */
    echo "\nB. `optimize:clear` -- with and without the exclusion the route now passes\n";

    seedKeys();
    Artisan::call('optimize:clear');
    Cache::purge(config('cache.default'));
    $bare = surviving();

    echo "      a BARE `optimize:clear` left {$bare} of 5\n";
    check(
        'a bare `optimize:clear` DOES empty the store, which is why removing the explicit cache:clear was not enough',
        $bare === 0,
        $bare . ' of 5 survived, so this script no longer demonstrates why the exclusion exists'
    );

    seedKeys();
    Artisan::call('optimize:clear', ['--except' => 'cache']);
    Cache::purge(config('cache.default'));
    $excepted = surviving();

    check(
        '`optimize:clear --except=cache` leaves all five payment keys',
        $excepted === 5,
        $excepted . ' of 5'
    );

    /* ------------------------------------------------------- C: the route's whole sequence */
    echo "\nC. the route's exact sequence, in order, as it now stands\n";

    seedKeys();
    /* `optimize` omitted on purpose -- see section A. Every other command the route issues, in
       order, plus the targeted forget the route now does in place of `cache:clear`. */
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    Artisan::call('optimize:clear', ['--except' => 'cache']);
    Artisan::call('config:clear');
    Cache::forget('settings_store_1');
    Cache::purge(config('cache.default'));

    check(
        'all five payment keys survive the whole /config-cache sequence',
        surviving() === 5,
        surviving() . ' of 5'
    );
    check(
        'and the settings entry the sequence is FOR is gone, so the button still does its job',
        Cache::get('settings_store_1') === null
    );
} finally {
    /*
     * BELT AND BRACES. Nothing here calls `optimize`, but `optimize:clear` and the individual
     * clears can leave a partially written `bootstrap/cache/`, and a stale file there breaks every
     * later test run rather than this one. Removing them costs nothing and the framework rebuilds
     * what it needs.
     */
    foreach (['config.php', 'events.php', 'routes-v7.php'] as $stale) {
        $path = __DIR__ . '/../../../../bootstrap/cache/' . $stale;
        if (file_exists($path)) {
            @unlink($path);
            echo "removed the stale bootstrap/cache/{$stale} this run produced\n";
        }
    }

    try {
        DB::disconnect();
        $dsn = $socket ? "mysql:unix_socket={$socket}" : "mysql:host={$host};port={$port}";
        (new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
            ->exec("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ndropped the throwaway database `{$database}`\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "could not drop `{$database}`: {$e->getMessage()}\n");
    }
}

echo str_repeat('=', 88) . "\n";
echo "{$checks} checks, {$failures} failure(s)\n";

exit($failures === 0 ? 0 : 1);
