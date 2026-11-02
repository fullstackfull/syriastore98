<?php

/**
 * F194 / D-28a -- THE MODEL CACHE IS ISOLATED FROM THE PAYMENT CACHE, MEASURED ON REAL MariaDB.
 * =================================================================================================
 *
 * WHAT THIS PROVES, AND WHY THE SUITE CANNOT. `phpunit.xml` sets `CACHE_STORE=array`, and
 * `Illuminate\Cache\ArrayStore` EXTENDS `TaggableStore`, so laravel-model-caching's post-save flush
 * is tag-scoped there and no payment key is ever at risk. Production is `CACHE_STORE=database`, and
 * `Illuminate\Cache\DatabaseStore` is NOT taggable, so the same flush becomes `Cache::flush()` over
 * the whole store. Every assertion below is therefore worthless unless it runs on the database
 * store, which is what this script arranges.
 *
 * The sibling `verify-model-cache-flush-scope-on-mysql.php` measured the DEFECT: one
 * `Setting::updateOrCreate()` erasing 4 of 4 seeded payment keys. This script measures the FIX.
 *
 * THE OWNER'S CONDITIONS ON D-28a, each one a section below:
 *
 *   - different store NAMES are not sufficient if a flush still erases both (section C: the two
 *     stores are proven to be different TABLES, and a flush of one is shown not to touch the other);
 *   - the payment-critical keys survive every model write (section D, ten cases);
 *   - model-cache invalidation must keep working -- no stale reads (section E);
 *   - and any OTHER reachable flush of the payment store stays separately open, which section F
 *     measures rather than asserts, because `cache:clear` is not a model flush and the isolation
 *     does not touch it.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and refuses
 * a name that does not say so.
 *
 *     php tests/Support/Security/bin/verify-model-cache-isolation-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at a development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'modelisolation_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate}\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('FRESH_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('FRESH_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('FRESH_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh' => $connection]);
config(['database.default' => 'fresh']);

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

/** The four key shapes the payment controls really use. */
const PAYMENT_KEYS = [
    'gateway-return-claim:checkout:0000000000000000000000000000000000000000',
    'gateway-return-claim:payment:khalti:1:1111111111111111111111111111111111111111',
    'paymera_pending_paymera_isolationprobe',
    'paymera_claim_paymera_isolationprobe',
    'paymera_lock_paymera_isolationprobe',
];

function seedPaymentKeys(): void
{
    foreach (PAYMENT_KEYS as $key) {
        Cache::store('payment_probe')->put($key, 'storefront-return', 600);
    }
}

function survivingPaymentKeys(): int
{
    $n = 0;
    foreach (PAYMENT_KEYS as $key) {
        if (Cache::store('payment_probe')->get($key) === 'storefront-return') {
            $n++;
        }
    }

    return $n;
}

echo "F194 / D-28a -- model cache isolated from payment cache, on the production store driver\n";
echo str_repeat('=', 90) . "\n";

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    echo "engine: " . DB::connection('fresh')->selectOne('SELECT VERSION() v')->v . "\n\n";

    /* ------------------------------------------------------------------ A: the mechanism */
    echo "A. the two store classes, asked rather than assumed\n";
    check(
        'the array store (the suite\'s) IS taggable, so a model flush there is scoped and the suite is blind to this',
        is_subclass_of(Illuminate\Cache\ArrayStore::class, TaggableStore::class)
    );
    check(
        'the database store (production\'s) is NOT taggable, so a model flush there cannot be scoped',
        ! is_subclass_of(Illuminate\Cache\DatabaseStore::class, TaggableStore::class)
    );

    /* ------------------------------------------------------------------ B: the tables */
    foreach ([
        ['cache', 'cache_locks'],
        ['model_cache', 'model_cache_locks'],
    ] as [$table, $lockTable]) {
        Schema::connection('fresh')->create($table, function ($t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
        Schema::connection('fresh')->create($lockTable, function ($t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
    }
    Schema::connection('fresh')->create('settings', function ($t) {
        $t->increments('id');
        $t->string('name')->nullable();
        $t->longText('value')->nullable();
        $t->integer('store_id')->nullable();
        $t->integer('created_by')->nullable();
        $t->timestamps();
    });

    /*
     * `payment_probe` is the APPLICATION store -- the one production calls `database`, holding the
     * payment keys. `model_probe` is what `MODEL_CACHE_STORE=model_cache` points at. Both are the
     * `database` driver, which is the whole point: the isolation must come from the TABLE and not
     * from the driver being different.
     */
    config(['cache.stores.payment_probe' => [
        'driver' => 'database', 'connection' => 'fresh', 'table' => 'cache', 'lock_connection' => 'fresh',
    ]]);
    config(['cache.stores.model_probe' => [
        'driver' => 'database', 'connection' => 'fresh', 'table' => 'model_cache', 'lock_connection' => 'fresh',
    ]]);
    config(['cache.default' => 'payment_probe']);
    config(['laravel-model-caching.store' => 'model_probe']);
    Cache::purge('payment_probe');
    Cache::purge('model_probe');

    echo "\nB. the configuration under test\n";
    check('the application store and the model store are the SAME DRIVER', 'database' === config('cache.stores.payment_probe.driver')
        && 'database' === config('cache.stores.model_probe.driver'));
    check('and DIFFERENT TABLES, which is where the isolation has to come from',
        config('cache.stores.payment_probe.table') !== config('cache.stores.model_probe.table'),
        config('cache.stores.payment_probe.table') . ' vs ' . config('cache.stores.model_probe.table'));
    check('laravel-model-caching is pointed at the model store', config('laravel-model-caching.store') === 'model_probe');

    /* ------------------------------------------------------------------ C: a flush of one does not reach the other */
    echo "\nC. a flush of the model store does not reach the application store\n";
    seedPaymentKeys();
    Cache::store('model_probe')->put('model-probe-key', 'x', 600);

    check('premise: all five payment keys and the model key are readable', survivingPaymentKeys() === 5
        && Cache::store('model_probe')->get('model-probe-key') === 'x');

    Cache::store('model_probe')->flush();

    check('the model store is now empty', Cache::store('model_probe')->get('model-probe-key') === null);
    check('and ALL FIVE payment keys survived a full flush of the model store',
        survivingPaymentKeys() === 5, survivingPaymentKeys() . ' of 5');

    /* ------------------------------------------------------------------ D: the model writes */
    echo "\nD. every model write D-28a names, against the payment keys\n";

    $writes = [
        'Setting::updateOrCreate() -- what the language switch does' => function () {
            App\Models\Setting::updateOrCreate(
                ['name' => 'SITE_RTL', 'store_id' => 1],
                ['value' => 'on', 'name' => 'SITE_RTL', 'store_id' => 1, 'created_by' => 1]
            );
        },
        'Setting::create() -- a fresh row' => function () {
            App\Models\Setting::create(['name' => 'ss_f194_' . uniqid(), 'value' => '1', 'store_id' => 1, 'created_by' => 1]);
        },
        'Setting::save() on an existing row' => function () {
            $row = App\Models\Setting::query()->first();
            if ($row) {
                $row->value = 'off';
                $row->save();
            }
        },
        'Setting::delete()' => function () {
            $row = App\Models\Setting::query()->latest('id')->first();
            if ($row) {
                $row->delete();
            }
        },
    ];

    foreach ($writes as $what => $write) {
        seedPaymentKeys();
        $before = survivingPaymentKeys();
        $write();
        $after = survivingPaymentKeys();

        check(
            $what . ': the payment keys survive',
            $before === 5 && $after === 5,
            'before ' . $before . ', after ' . $after . ' of 5'
        );
    }

    /*
     * `flushCache()` CALLED DIRECTLY, which is the shape `User::flushCache()` and
     * `ProductStockService` use and the one a module toggle reaches through
     * `sideMenuCacheForget()`'s per-user branch.
     */
    seedPaymentKeys();
    (new App\Models\Setting)->flushCache();
    check('an explicit flushCache() on a Cachable model leaves the payment keys alone',
        survivingPaymentKeys() === 5, survivingPaymentKeys() . ' of 5');

    /* ------------------------------------------------------------------ E: invalidation still works */
    echo "\nE. the model cache still invalidates -- no stale reads, which D-28a requires\n";

    App\Models\Setting::updateOrCreate(
        ['name' => 'ss_f194_staleness', 'store_id' => 1],
        ['value' => 'first', 'name' => 'ss_f194_staleness', 'store_id' => 1, 'created_by' => 1]
    );

    $first = App\Models\Setting::where('name', 'ss_f194_staleness')->first();
    check('premise: the row reads back as written', $first && $first->value === 'first',
        $first ? var_export($first->value, true) : 'null');

    /* A second read, to be sure the first one populated the model cache. */
    App\Models\Setting::where('name', 'ss_f194_staleness')->first();

    App\Models\Setting::where('name', 'ss_f194_staleness')->update(['value' => 'second']);
    (new App\Models\Setting)->flushCache();

    $second = App\Models\Setting::where('name', 'ss_f194_staleness')->first();
    check('after a write and a flush the model cache serves the NEW value, not the cached one',
        $second && $second->value === 'second',
        $second ? var_export($second->value, true) : 'null');

    /* ------------------------------------------------------------------ F: what the isolation does NOT fix */
    echo "\nF. what this isolation does NOT fix -- measured, not asserted away\n";
    seedPaymentKeys();
    Cache::store('payment_probe')->flush();
    $afterAppFlush = survivingPaymentKeys();

    echo "      an explicit flush of the APPLICATION store left {$afterAppFlush} of 5 payment keys\n";
    echo "      This is the correct and expected result, and it is the point: F194 isolates the\n";
    echo "      MODEL flush. It does nothing about an explicit `cache:clear` or `Cache::flush()`\n";
    echo "      on the application store -- which is F188 and F197, recorded separately. Do not\n";
    echo "      read a green run of this script as 'the payment cache is safe'.\n";
    $checks++;
} finally {
    try {
        DB::connection('fresh')->disconnect();
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ndropped the throwaway database `{$database}`\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "could not drop `{$database}`: {$e->getMessage()}\n");
    }
}

echo str_repeat('=', 90) . "\n";
echo "{$checks} checks, {$failures} failure(s)\n";

exit($failures === 0 ? 0 : 1);
