<?php

/**
 * F194/F211 ACTIVATION — DOES ISOLATING THE MODEL CACHE ACTUALLY SAVE THE REPLAY CLAIM?
 * =================================================================================================
 *
 * THE OWNER'S INSTRUCTION, 2026-10-30: *"commit to the Model Cache isolation option approved
 * earlier. Re-test the `Setting::updateOrCreate` scenario that erases the Replay Claim **after
 * activating the isolation** in an environment resembling production."*
 *
 * WHAT WAS ALREADY MEASURED AND WHAT WAS NOT.
 * `verify-replay-claim-survives-a-model-save-on-mysql.php` proved the defect: an ordinary
 * `Setting::updateOrCreate()` erases the gateway-return replay claim, and because
 * `claimGatewayReturn()` takes it with `Cache::add()` — which returns **true** on a missing key —
 * an erased claim is an **un-taken** claim, so the replay is welcomed rather than merely unguarded.
 * `verify-model-cache-isolation-on-mysql.php` proved the isolation works *as a cache boundary*:
 * flushing the model store leaves the seeded payment keys.
 *
 * **NEITHER ASKED THE OWNER'S QUESTION.** The first measured the defect with isolation OFF. The
 * second measured key survival against a *flush of the model store*, which is not the event that
 * causes the defect — the event is a `Cachable` model SAVE, whose flush laravel-model-caching routes
 * through `Caching::cache()`. Whether that save still reaches the claim after activation is a
 * different proposition, and it is the one the activation decision rests on.
 *
 * SO THIS SCRIPT RUNS THE SAME CHAIN TWICE, IN ONE PROCESS, AGAINST ONE DATABASE:
 *
 *   PHASE A — isolation OFF (`laravel-model-caching.store` null, today's shipped default).
 *             The chain must REPRODUCE. This is the premise: if the defect does not reproduce here,
 *             Phase B proves nothing at all, and that is the single most likely way for a
 *             before/after script to be vacuous.
 *   PHASE B — isolation ON (`laravel-model-caching.store` = 'model_cache').
 *             The claim must SURVIVE the identical save, and the replay must stay REFUSED.
 *   PHASE C — D-28a's no-stale-read condition, because an isolation that saves the claim by
 *             serving stale rows would be a worse defect than the one it fixes.
 *   PHASE D — THE BOUNDARY, measured rather than assumed: an explicit flush of the APPLICATION
 *             store still erases the claim. Activation does **not** make `cache:clear` safe, so it
 *             does not subsume F215 — F215 needed its own fix and still does.
 *
 * WHY THE CONFIG CAN BE FLIPPED MID-PROCESS. `Caching::cache()`
 * (`vendor/genealabs/laravel-model-caching/src/Traits/Caching.php:84-101`) resolves
 * `config('laravel-model-caching.store')` **on every call**, not once at boot. So the two phases
 * really are the two deployments, not an approximation of them.
 *
 * PRODUCTION-RESEMBLING MEANS THE STORE SHAPE, AND IT IS NAMED: both stores are the `database`
 * driver on MariaDB, which is what `.env.example`'s `CACHE_STORE=database` gives, and they differ
 * only in TABLE — `cache` and `model_cache` — which is the isolation boundary the runbook chose for
 * the reason §2 records. Nothing here touches production.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name that does not say so.
 *
 *     php tests/Support/Security/bin/verify-replay-claim-with-model-cache-isolation-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at a development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'claimisolation_verify';

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

/** The real key shape, from `GuardsGatewayReturn::gatewayReturnClaimKeys()`. */
function claimKey(string $reference): string
{
    return 'gateway-return-claim:payment:khalti:1:' . sha1($reference);
}

echo "F194/F211 activation -- does isolating the model cache save the gateway-return replay claim?\n";
echo str_repeat('=', 96) . "\n";

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    echo "engine: " . DB::connection('fresh')->selectOne('SELECT VERSION() v')->v . "\n";

    /* ---------------------------------------------------------------- the production-shaped schema */
    foreach ([['cache', 'cache_locks'], ['model_cache', 'model_cache_locks']] as [$store, $lock]) {
        Schema::connection('fresh')->create($store, function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::connection('fresh')->create($lock, function ($table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }
    Schema::connection('fresh')->create('settings', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->longText('value')->nullable();
        $table->integer('store_id')->nullable();
        $table->integer('created_by')->nullable();
        $table->timestamps();
    });

    /* Production's two stores: SAME driver, DIFFERENT table -- the runbook's §2 boundary. */
    config(['cache.default' => 'app_store']);
    config(['cache.stores.app_store' => [
        'driver' => 'database', 'connection' => 'fresh', 'table' => 'cache', 'lock_connection' => 'fresh',
    ]]);
    config(['cache.stores.model_cache' => [
        'driver' => 'database', 'connection' => 'fresh', 'table' => 'model_cache', 'lock_connection' => 'fresh',
    ]]);
    Cache::purge('app_store');
    Cache::purge('model_cache');

    $ttl = 60 * 60 * 24 * 2;   // GATEWAY_RETURN_CLAIM_TTL

    echo "\nPHASE A -- ISOLATION OFF (today's shipped default). The defect must REPRODUCE.\n";
    echo str_repeat('-', 96) . "\n";

    config(['laravel-model-caching.store' => null]);
    check(
        'premise: the model cache really is unset, so this phase is the shipped configuration',
        config('laravel-model-caching.store') === null
    );

    $refA = 'ss-f194a-' . bin2hex(random_bytes(8));
    check('a first gateway return takes its claim', Cache::add(claimKey($refA), 'khalti', $ttl) === true);
    check(
        'the replay is refused -- the control working before any save',
        Cache::add(claimKey($refA), 'khalti', $ttl) === false,
        'the claim was never held, so nothing below measures a flush'
    );

    App\Models\Setting::updateOrCreate(
        ['name' => 'SITE_RTL', 'store_id' => 1],
        ['value' => 'on', 'name' => 'SITE_RTL', 'store_id' => 1, 'created_by' => 1]
    );
    echo "   (one ordinary Setting::updateOrCreate -- not a payment action, not a cache command)\n";

    check(
        'THE DEFECT REPRODUCES: the claim has been erased by that save',
        Cache::get(claimKey($refA)) === null,
        'the claim survived with isolation OFF, so this environment is not reproducing F211 and '
        . 'PHASE B would prove nothing'
    );
    check(
        'and the same replay is now PERMITTED -- an erased claim is an UN-TAKEN claim',
        Cache::add(claimKey($refA), 'khalti', $ttl) === true
    );

    echo "\nPHASE B -- ISOLATION ON (MODEL_CACHE_STORE=model_cache). The claim must SURVIVE.\n";
    echo str_repeat('-', 96) . "\n";

    config(['laravel-model-caching.store' => 'model_cache']);
    check(
        'premise: the model cache now names its own store',
        config('laravel-model-caching.store') === 'model_cache'
    );

    $refB = 'ss-f194b-' . bin2hex(random_bytes(8));
    check('a first gateway return takes its claim', Cache::add(claimKey($refB), 'khalti', $ttl) === true);
    check('the replay is refused before the save', Cache::add(claimKey($refB), 'khalti', $ttl) === false);

    App\Models\Setting::updateOrCreate(
        ['name' => 'defult_language', 'store_id' => 1],
        ['value' => 'ar', 'name' => 'defult_language', 'store_id' => 1, 'created_by' => 1]
    );
    echo "   (the identical kind of save -- a Cachable model write, flush and all)\n";

    check(
        'THE CLAIM SURVIVES the save that erased it in PHASE A',
        Cache::get(claimKey($refB)) === 'khalti',
        'the claim was erased even with the isolation active, which would mean the activation does '
        . 'NOT close F211 and the whole option needs re-deriving'
    );
    check(
        'and the replay is still REFUSED -- the control is intact across a model save',
        Cache::add(claimKey($refB), 'khalti', $ttl) === false
    );

    /* The other direction: PHASE A's keys are still in the app store, untouched by a model flush. */
    $survivors = 0;
    foreach (['paymera_pending_probe', 'paymera_claim_probe'] as $k) {
        Cache::put($k, 'held', 600);
    }
    App\Models\Setting::updateOrCreate(
        ['name' => 'out_of_stock_threshold', 'store_id' => 1],
        ['value' => '0', 'name' => 'out_of_stock_threshold', 'store_id' => 1, 'created_by' => 1]
    );
    foreach (['paymera_pending_probe', 'paymera_claim_probe'] as $k) {
        $survivors += Cache::get($k) === 'held' ? 1 : 0;
    }
    check('the other two payment key shapes survive a model save too', $survivors === 2);

    echo "\nPHASE C -- D-28a's NO-STALE-READ CONDITION. An isolation that serves stale rows is worse.\n";
    echo str_repeat('-', 96) . "\n";

    $before = App\Models\Setting::where('name', 'SITE_RTL')->where('store_id', 1)->first();
    check('premise: the model read returns the seeded value', ($before->value ?? null) === 'on');

    App\Models\Setting::updateOrCreate(
        ['name' => 'SITE_RTL', 'store_id' => 1],
        ['value' => 'off', 'name' => 'SITE_RTL', 'store_id' => 1, 'created_by' => 1]
    );

    $after = App\Models\Setting::where('name', 'SITE_RTL')->where('store_id', 1)->first();
    check(
        'the model cache serves the NEW value after a write -- no stale read',
        ($after->value ?? null) === 'off',
        'the isolation saved the claim by serving a stale row, which D-28a forbids and which would '
        . 'be a worse defect than F211'
    );

    echo "\nPHASE D -- THE BOUNDARY. Activation does NOT make `cache:clear` safe.\n";
    echo str_repeat('-', 96) . "\n";

    $refD = 'ss-f194d-' . bin2hex(random_bytes(8));
    Cache::add(claimKey($refD), 'khalti', $ttl);
    check('premise: a claim is held', Cache::get(claimKey($refD)) === 'khalti');

    Cache::store('app_store')->flush();

    check(
        'an explicit flush of the APPLICATION store still erases it -- F215 is NOT subsumed',
        Cache::get(claimKey($refD)) === null,
        'the application flush no longer reaches the claim, which would mean this script is not '
        . 'measuring the boundary it claims to'
    );
    check(
        'so the replay is permitted again by that route',
        Cache::add(claimKey($refD), 'khalti', $ttl) === true
    );
    echo "   (this is the boundary, not a failure: F215's own fix -- stopping an anonymous GET /\n";
    echo "    from running `package:seed` -- is what closes that route, and it is already in.)\n";

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

echo str_repeat('=', 96) . "\n";
printf("%d checks, %d failed\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
