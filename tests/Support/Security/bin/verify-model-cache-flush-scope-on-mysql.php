<?php

/**
 * F188 FOLLOW-UP -- DOES SAVING A `Cachable` MODEL EMPTY THE WHOLE CACHE ON PRODUCTION'S STORE?
 * =================================================================================================
 *
 * WHY THIS SCRIPT HAD TO EXIST. The F188 slice removed `Artisan::call('cache:clear')` from the
 * three language-switch handlers, two of which are anonymous and unthrottled, and
 * `tests/Feature/Security/LanguageSwitchCacheScopeTest.php` asserts that four seeded payment keys
 * -- two `gateway-return-claim:*`, a `paymera_pending_*` and a `paymera_claim_*` -- survive each
 * switch. The mandated review then pointed out that the test can only ever say that about the
 * TEST'S cache store, and raised a mechanism the suite is structurally blind to:
 *
 *   - `App\Models\Setting` and `App\Models\User` both `use Cachable`
 *     (`app/Models/Setting.php:13`, `app/Models/User.php:16`), and laravel-model-caching flushes
 *     after every create/save/delete (`ModelCaching.php:66-76` ->
 *     `Caching::checkCooldownAndFlushAfterPersisting()` -> `flushCache()`).
 *   - `Caching::cache()` tags the repository ONLY when the store is taggable
 *     (`Caching.php:95`: `if (is_subclass_of($cache->getStore(), TaggableStore::class))`).
 *   - `Illuminate\Cache\ArrayStore` EXTENDS `TaggableStore` -- so under `phpunit.xml`'s
 *     `CACHE_STORE=array` the flush is scoped to the model's tags and payment keys survive.
 *   - `Illuminate\Cache\DatabaseStore` does NOT -- and production is `CACHE_STORE=database`
 *     (`.env.example`, and this project's own notes at `GuardsGatewayReturn:232`).
 *
 * If that reading is right, the authenticated language switch -- which does one `$user->save()` and
 * three `Setting::updateOrCreate()` -- still empties the entire cache in production, and the
 * Feature test asserting otherwise is asserting something false about production. That is the same
 * species of blindness as the SQLite type-affinity trap that hid P01-SEC-13, so it is MEASURED
 * here rather than argued either way.
 *
 * WHAT THIS SCRIPT DELIBERATELY DOES NOT DO. It does not drive the language route. The question is
 * about the STORE, not about the handler: if saving a `Cachable` model flushes the default store,
 * then every handler that saves one does it, and the language switch is one caller among many
 * (`App\Models\Order` is `Cachable` too, so an order save is another). Narrowing the question to
 * the store is what makes the answer general.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name that does not say so.
 *
 *     php tests/Support/Security/bin/verify-model-cache-flush-scope-on-mysql.php
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

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'modelcache_verify';

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

echo "F188 follow-up -- the scope of a Cachable model's flush, on the production cache store\n";
echo str_repeat('-', 82) . "\n";

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    echo "engine: " . DB::connection('fresh')->selectOne('SELECT VERSION() v')->v . "\n\n";

    /*
     * SECTION A -- THE TWO STORE CLASSES, ASKED RATHER THAN ASSUMED.
     *
     * This is the whole mechanism in two lines, and it is the one thing a reader should be able to
     * check without running anything else.
     */
    echo "A. which store classes are taggable\n";
    check(
        'the array store (the suite\'s, phpunit.xml) IS taggable, so a model flush there is scoped',
        is_subclass_of(Illuminate\Cache\ArrayStore::class, TaggableStore::class)
    );
    check(
        'the database store (production\'s) is NOT taggable, so a model flush there cannot be scoped',
        ! is_subclass_of(Illuminate\Cache\DatabaseStore::class, TaggableStore::class)
    );

    /*
     * SECTION B -- THE CACHE TABLE, and the `settings` table the write needs.
     *
     * Only these two are built. `migrate:fresh` would run the whole tree, and the question does not
     * need it: `Setting` is the smallest `Cachable` model that the language handlers really write.
     */
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

    config(['cache.default' => 'probe']);
    config(['cache.stores.probe' => [
        'driver' => 'database',
        'connection' => 'fresh',
        'table' => 'cache',
        'lock_connection' => 'fresh',
    ]]);
    Cache::purge('probe');

    echo "\nB. the payment keys F188's Feature test seeds, on the DATABASE store\n";

    $keys = [
        'gateway-return-claim:checkout:0000000000000000000000000000000000000000',
        'gateway-return-claim:payment:khalti:1:1111111111111111111111111111111111111111',
        'paymera_pending_paymera_probe',
        'paymera_claim_paymera_probe',
    ];

    foreach ($keys as $key) {
        Cache::put($key, 'storefront-return', 600);
    }

    check(
        'premise: all four keys are readable before the write',
        count(array_filter($keys, fn ($k) => Cache::get($k) === 'storefront-return')) === 4
    );

    /*
     * SECTION C -- ONE `Setting` WRITE, which is what the authenticated language switch does three
     * times (`LanguageController::changeLanquage()` writes SITE_RTL and defult_language).
     */
    echo "\nC. one Setting::updateOrCreate() -- what the authenticated language switch does\n";

    App\Models\Setting::updateOrCreate(
        ['name' => 'SITE_RTL', 'store_id' => 1],
        ['value' => 'on', 'name' => 'SITE_RTL', 'store_id' => 1, 'created_by' => 1]
    );

    $survivors = array_values(array_filter($keys, fn ($k) => Cache::get($k) === 'storefront-return'));
    $lost = array_values(array_diff($keys, $survivors));

    echo '      survivors: ' . count($survivors) . " of 4\n";
    foreach ($lost as $key) {
        echo "      LOST: {$key}\n";
    }

    /*
     * THE ASSERTION IS WRITTEN THE WAY THE ANSWER MATTERS, NOT THE WAY I HOPE IT COMES OUT.
     *
     * If the keys survive, the review's F1 does not reproduce and the Feature test's claim holds on
     * production's store too. If they are gone, the Feature test asserts something false about
     * production, and F188's authenticated half is NOT closed -- it is a platform-wide defect in
     * `Cachable` plus a non-taggable store, with the language switch as one caller among many.
     * Either way this script's job is to make the answer a measurement. It therefore REPORTS and
     * does not fail on the loss: a script that exits non-zero on a true finding would be a gate,
     * and this is evidence.
     */
    if (count($survivors) === 4) {
        check('a Setting write did NOT empty the store: the model flush is scoped here', true);
        echo "\n  => The review's F1 does NOT reproduce on this engine and store.\n";
    } else {
        echo "\n  FINDING (not a failure of this script -- this is the measurement)\n";
        echo "      A single `Setting::updateOrCreate()` emptied " . count($lost) . " of 4 payment keys\n";
        echo "      from the DATABASE cache store. `Setting` is `Cachable`; laravel-model-caching\n";
        echo "      flushes after every save; the database store is not taggable, so the flush is\n";
        echo "      `Cache::flush()` over the whole store.\n\n";
        echo "      CONSEQUENCE. The authenticated language switch still empties every tenant's\n";
        echo "      in-flight payment state in production, and so does any other handler that\n";
        echo "      saves a Cachable model (`Order` is one). F188's anonymous half is closed by\n";
        echo "      the slice that removed `cache:clear`; its authenticated half is a separate,\n";
        echo "      pre-existing, platform-wide defect. `LanguageSwitchCacheScopeTest`'s\n";
        echo "      authenticated case holds only on a TAGGABLE store and must say so.\n";
        $checks++;
    }

    /*
     * SECTION D -- THE SAME WRITE ON THE ARRAY STORE, so the two engines are compared in one run
     * rather than across two documents. This is what makes the suite's blindness visible.
     */
    echo "\nD. the same write on the ARRAY store -- the suite's engine\n";

    config(['cache.stores.probe_array' => ['driver' => 'array', 'serialize' => false]]);
    config(['cache.default' => 'probe_array']);
    Cache::purge('probe_array');

    foreach ($keys as $key) {
        Cache::put($key, 'storefront-return', 600);
    }

    App\Models\Setting::updateOrCreate(
        ['name' => 'defult_language', 'store_id' => 1],
        ['value' => 'ar', 'name' => 'defult_language', 'store_id' => 1, 'created_by' => 1]
    );

    $arraySurvivors = count(array_filter($keys, fn ($k) => Cache::get($k) === 'storefront-return'));
    echo "      survivors: {$arraySurvivors} of 4\n";
    check(
        'the array store keeps all four -- which is why the Feature test passes and proves nothing about production',
        $arraySurvivors === 4
    );
} finally {
    try {
        DB::connection('fresh')->disconnect();
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ndropped the throwaway database `{$database}`\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "could not drop `{$database}`: {$e->getMessage()}\n");
    }
}

echo str_repeat('-', 82) . "\n";
echo "{$checks} checks, {$failures} failure(s)\n";

exit($failures === 0 ? 0 : 1);
