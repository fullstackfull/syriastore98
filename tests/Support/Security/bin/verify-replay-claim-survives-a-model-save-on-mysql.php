<?php

/**
 * F194 CONSEQUENCE — AN ERASED REPLAY CLAIM IS AN *UN-TAKEN* CLAIM, SO THE REPLAY IS PERMITTED.
 * =================================================================================================
 *
 * WHAT WAS ALREADY MEASURED, AND WHAT WAS NOT.
 * `verify-model-cache-flush-scope-on-mysql.php` proved that one `Setting::updateOrCreate()` erases
 * every payment key from the production cache store: `DatabaseStore` is not taggable, so
 * laravel-model-caching's post-save flush is `Cache::flush()` over the WHOLE default store. That
 * script stops at "the keys are gone".
 *
 * IT NEVER ASKED WHAT GONE MEANS TO THE CONTROL THAT WROTE THEM, and that is the question with
 * money in it. `GuardsGatewayReturn::claimGatewayReturn()` takes its claim with
 * **`Cache::add($key, $gateway, TTL)`** and treats a `false` return as "already claimed, refuse".
 * `Cache::add()` on a key that does not exist returns **true**. So an erased claim is not a missing
 * record the control notices — it is an UN-TAKEN claim, indistinguishable from a first arrival. The
 * replay is not merely unguarded; it is actively welcomed.
 *
 * THE CHAIN THIS SCRIPT DRIVES, END TO END, ON A REAL MARIADB:
 *
 *   1. a gateway return takes its claim      -> add() true   (first arrival, correct)
 *   2. the same return is replayed           -> add() false  (refused, the control working)
 *   3. one ordinary `Setting::create()`      -> the whole default store is flushed
 *   4. the claim is read back                -> gone
 *   5. the SAME replay is attempted again    -> add() TRUE   <-- the defect
 *
 * Step 5 is the finding. Between step 2 and step 5 nothing about the payment changed: the same
 * reference, the same gateway, the same shopper. What changed is that an unrelated model save
 * somewhere else in the installation — any merchant editing any setting, any order being written,
 * any user logging in — reopened a window that F95/F96 closed.
 *
 * WHY THIS IS A SCRIPT AND NOT A FEATURE TEST. `phpunit.xml` sets `CACHE_STORE=array`, and
 * `ArrayStore` EXTENDS `TaggableStore`, so in the suite the flush is scoped to the model's own tags
 * and every payment key survives. The suite cannot see this defect at all — the same structural
 * blindness as the SQLite type-affinity trap that hid P01-SEC-13. A conditionally-skipped test
 * would also break the known-outcome gate's declared skip set.
 *
 * WHAT THIS DOES NOT CLAIM. It does not claim a second order is written: reaching the order writer
 * needs a verified capture, and the handlers carry further controls (`OrderCompleteBinding`, the
 * `processed` flag, the per-payment lock, and on Paymera a durable claim). It claims exactly what it
 * drives — that the CLAIM control, one of the layers F95/F96 added specifically to stop a replayed
 * gateway return, is defeated by an ordinary model save, and that the remaining layers are
 * therefore load-bearing alone rather than in depth.
 *
 * THE FIX IS NOT TAKEN HERE AND THAT IS DELIBERATE. Two candidates exist and both are owner
 * decisions, not engineering ones:
 *
 *   - activate `MODEL_CACHE_STORE=model_cache` (the F194 runbook) so the default store stops being
 *     flushed wholesale. That is a PRODUCTION ENVIRONMENT CHANGE plus a migration, both explicitly
 *     outside this phase's authorisation, and `ModelCacheIsolationConfigTest` currently *asserts*
 *     the mitigation ships inert, by decision D-28a.
 *   - move the claim to a store the flush does not reach (what `config/consent.php` does for the
 *     F205 token). The only driver available without a migration is `file`, which is PER-SERVER: in
 *     a multi-server deployment that trades a claim erased constantly for a claim not shared at
 *     all, which may be worse. Choosing between them needs the deployment topology, which is not
 *     visible from here.
 *
 * So this script's job is to make the risk impossible to misread, and to leave the choice where it
 * belongs. Nothing here touches production.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name that does not say so.
 *
 *     php tests/Support/Security/bin/verify-replay-claim-survives-a-model-save-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at a development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'replayclaim_verify';

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

echo "F194 consequence -- is a gateway-return replay claim still held after an ordinary model save?\n";
echo str_repeat('-', 94) . "\n";

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    echo "engine: " . DB::connection('fresh')->selectOne('SELECT VERSION() v')->v . "\n\n";

    Schema::connection('fresh')->create('cache', function ($table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    Schema::connection('fresh')->create('cache_locks', function ($table) {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });
    Schema::connection('fresh')->create('settings', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->longText('value')->nullable();
        $table->integer('store_id')->nullable();
        $table->integer('created_by')->nullable();
        $table->timestamps();
    });

    /* Production's store, named so the script is explicit about what it is measuring. */
    config(['cache.default' => 'production_shaped']);
    config(['cache.stores.production_shaped' => [
        'driver' => 'database',
        'connection' => 'fresh',
        'table' => 'cache',
        'lock_connection' => 'fresh',
    ]]);
    Cache::purge('production_shaped');

    /*
     * THE REAL KEY SHAPE, taken from `GuardsGatewayReturn::gatewayReturnClaimKeys()` rather than
     * invented: `gateway-return-claim:payment:<gateway>:<store>:<sha1>`. The shape does not change
     * the answer -- a whole-store flush does not read keys -- but a made-up key would leave a
     * reader unable to tell whether the script measured the control or something adjacent to it.
     */
    $reference = 'ss-f194-' . bin2hex(random_bytes(8));
    $key = 'gateway-return-claim:payment:khalti:1:' . sha1($reference);
    $ttl = 60 * 60 * 24;   // GATEWAY_RETURN_CLAIM_TTL's order of magnitude

    echo "1. the first gateway return takes its claim\n";
    check('add() returns true for a first arrival', Cache::add($key, 'khalti', $ttl) === true);

    echo "\n2. the same return is replayed -- the control doing its job\n";
    check(
        'add() returns FALSE for the replay, so claimGatewayReturn() refuses it',
        Cache::add($key, 'khalti', $ttl) === false,
        'the claim was not held even before any flush, which would mean the control never worked'
    );

    echo "\n3. one ordinary Setting::create() -- not a payment action, not a cache command\n";
    App\Models\Setting::updateOrCreate(
        ['name' => 'SITE_RTL', 'store_id' => 1],
        ['value' => 'on', 'name' => 'SITE_RTL', 'store_id' => 1, 'created_by' => 1]
    );
    echo "   (one `Cachable` model saved; laravel-model-caching flushes afterwards)\n";

    echo "\n4. is the claim still there?\n";
    $readBack = Cache::get($key);
    check(
        'the claim has been ERASED by a write that has nothing to do with payments',
        $readBack === null,
        'the claim survived -- which would mean this environment is NOT reproducing the flush; '
        . 'check that the model cache store is unset (config laravel-model-caching.store) and that '
        . 'the default store is the database driver'
    );

    echo "\n5. THE FINDING -- the same replay, attempted again\n";
    $replayNowAllowed = Cache::add($key, 'khalti', $ttl);
    check(
        'add() returns TRUE again, so the replay is now PERMITTED rather than refused',
        $replayNowAllowed === true,
        'add() still refused, so the chain does not complete in this environment'
    );

    echo "\n6. the premise a reader will doubt: the suite store behaves differently\n";
    check(
        'ArrayStore (phpunit.xml CACHE_STORE=array) IS taggable, so the Feature suite cannot see this',
        is_subclass_of(Illuminate\Cache\ArrayStore::class, Illuminate\Cache\TaggableStore::class)
    );
    check(
        'DatabaseStore (production, .env.example CACHE_STORE=database) is NOT taggable',
        ! is_subclass_of(Illuminate\Cache\DatabaseStore::class, Illuminate\Cache\TaggableStore::class)
    );

    echo "\n7. the mitigation really does ship inert, so none of the above is already handled\n";
    check(
        'config(laravel-model-caching.store) is null in the shipped configuration',
        config('laravel-model-caching.store') === null,
        'it is set to ' . var_export(config('laravel-model-caching.store'), true)
            . ' -- if that is deliberate, this script\'s conclusion needs re-deriving'
    );
    check(
        'model caching is enabled, so the flush really happens (D-28a refused disabling it)',
        config('laravel-model-caching.enabled') === true
    );

    echo "\n8. a store on another DRIVER is untouched by the flush -- the candidate fix, measured\n";
    $fileKey = 'ss-f194-file-' . bin2hex(random_bytes(6));
    Cache::store('file')->put($fileKey, 'held', 600);
    App\Models\Setting::updateOrCreate(
        ['name' => 'defult_language', 'store_id' => 1],
        ['value' => 'ar', 'name' => 'defult_language', 'store_id' => 1, 'created_by' => 1]
    );
    check(
        'a key in the `file` store survives a Cachable model save',
        Cache::store('file')->get($fileKey) === 'held',
        'even a separate driver was erased, which would rule out the second candidate fix entirely'
    );
    Cache::store('file')->forget($fileKey);

} catch (Throwable $e) {
    fwrite(STDERR, "\nUNEXPECTED: {$e->getMessage()}\n  at {$e->getFile()}:{$e->getLine()}\n");
    $failures++;
} finally {
    try {
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ndropped the throwaway database `{$database}`\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "could not drop `{$database}`: {$e->getMessage()}\n");
    }
}

echo str_repeat('-', 94) . "\n";
printf("%d checks, %d failed\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
