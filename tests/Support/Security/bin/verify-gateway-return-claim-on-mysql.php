<?php

/**
 * P1-PAY-02 -- the gateway-return claim is decided by the ENGINE, verified on REAL MySQL.
 * =================================================================================================
 *
 * WHY THIS SCRIPT HAD TO EXIST, and it is the same reason as its sibling for stock. The whole
 * value of `App\Http\Controllers\Concerns\GuardsGatewayReturn::claimGatewayReturn()` is that
 * exactly ONE of two overlapping gateway returns can take a key. The suite cannot show that.
 *
 *   - `phpunit.xml` sets `CACHE_STORE=array`, and `Illuminate\Cache\ArrayStore` has NO `add()`
 *     method, so `Cache::add()` there falls through to `Repository::add()`, which is
 *     `get()`-then-`put()` -- a read and a write with a gap between them. Section E MEASURES that
 *     rather than asserting it.
 *   - PHPUnit is single-threaded, so even an atomic store would never be raced.
 *
 * So the thirteen PHPUnit tests in tests/Feature/Commerce/GatewayReturnTrustBoundaryTest.php prove
 * the control's LOGIC -- which keys are built, which handlers take them, what a refusal returns --
 * and this script proves the one property they structurally cannot: that under real concurrency the
 * second caller loses, and loses because MySQL says so rather than because PHP checked first.
 *
 * WHAT IS UNDER TEST. Production runs `CACHE_STORE=database` (.env.example:39). On that store
 * `Illuminate\Cache\DatabaseStore::add()` ends in
 *
 *     return $this->table()->insertOrIgnore(compact('key', 'value', 'expiration')) > 0;
 *
 * i.e. `INSERT IGNORE` against `cache`, whose `key` column is the PRIMARY KEY
 * (database/migrations/0001_01_01_000001_create_cache_table.php:16). The `get()` above it is a
 * fast path, not the control: correctness rests on the primary key rejecting the second row. That
 * is the claim this script refuses to take from reading.
 *
 * HOW TO RUN. Needs a throwaway MySQL. It creates and drops its OWN database and refuses a name
 * without 'fresh' or 'verify' in it, like its siblings:
 *
 *     apt-get install -y mysql-server && service mysql start
 *     php tests/Support/Security/bin/verify-gateway-return-claim-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'claim_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set FRESH_MYSQL_SOCKET='' to force TCP)\n";
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
    'collation' => 'utf8mb4_unicode_ci',   // the project's connection collation (config/database.php:52)
    'prefix' => '',
    'strict' => false,                     // production's setting (config/database.php:55)
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh_a' => $connection]);
config(['database.connections.fresh_b' => $connection]);

// Two cache stores of the shape config/cache.php:41 defines, each pinned to its own connection so
// the two callers are genuinely two sessions rather than one connection used twice.
foreach (['a', 'b'] as $side) {
    config(["cache.stores.claim_{$side}" => [
        'driver' => 'database',
        'connection' => "fresh_{$side}",
        'table' => 'cache',
        'lock_connection' => "fresh_{$side}",
    ]]);
}

$checks = 0;
$failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) {
        echo "  ok    {$what}\n";
    } else {
        $failures++;
        echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

echo "P1-PAY-02 -- the gateway-return claim under real concurrency, on MySQL\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    $a = DB::connection('fresh_a');
    $b = DB::connection('fresh_b');

    /* PORTABLE ACROSS MySQL AND MariaDB, and this line is why these scripts had never run here.
       `@@transaction_isolation` is MySQL 5.7+; MariaDB 10.11 does not have it and answers
       "Unknown system variable 'transaction_isolation'" (errno 1193), which aborted the whole
       script before a single assertion. `@@tx_isolation` is the MariaDB spelling and MySQL 8.0
       REMOVED it, so neither name works on both engines and the variable has to be asked for by
       trial. MEASURED on MariaDB 10.11.14: the first SELECT raises 1193 and the second answers
       READ-COMMITTED. The engine and version are printed either way, because the assertions below
       depend on InnoDB rather than on the isolation level -- that figure is reported for the
       record, so it must not be able to fail the run. */
    $server = $a->select('SELECT VERSION() AS v, @@default_storage_engine AS e')[0];
    $server->i = 'unknown';
    foreach (['transaction_isolation', 'tx_isolation'] as $isolationVariable) {
        try {
            $server->i = $a->select('SELECT @@' . $isolationVariable . ' AS i')[0]->i;
            break;
        } catch (\Throwable $e) {
            // Try the other spelling; 'unknown' stands if neither exists.
        }
    }
    echo "server: {$server->v}  engine: {$server->e}  isolation: {$server->i}\n\n";

    // ---------------------------------------------------------------------------------------
    echo "A. the premise: the claim rests on a PRIMARY KEY, not on a PHP comparison\n";
    // ---------------------------------------------------------------------------------------

    check('InnoDB is the default engine (without row locks nothing below means anything)',
        strtolower($server->e) === 'innodb', "engine is {$server->e}");

    // The framework's own cache table, created exactly as the shipped migration creates it.
    $a->statement('CREATE TABLE `cache` (
        `key` VARCHAR(255) NOT NULL PRIMARY KEY,
        `value` MEDIUMTEXT NOT NULL,
        `expiration` INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $keyColumn = $a->select("SHOW KEYS FROM `cache` WHERE Key_name = 'PRIMARY'");

    check('cache.key is the PRIMARY KEY, so a duplicate is refused by the engine',
        count($keyColumn) === 1 && $keyColumn[0]->Column_name === 'key',
        'primary key columns: ' . json_encode(array_map(fn ($k) => $k->Column_name, $keyColumn)));

    check('DatabaseStore implements its own add(), so Cache::add() is NOT the Repository fallback',
        method_exists(\Illuminate\Cache\DatabaseStore::class, 'add'));

    // ---------------------------------------------------------------------------------------
    echo "\nB. the sequential claim: the second caller is refused\n";
    // ---------------------------------------------------------------------------------------

    $storeA = Cache::store('claim_a');
    $storeB = Cache::store('claim_b');

    /*
     * THE PREFIX IS NOT COSMETIC, AND LEAVING IT OUT MADE THIS SCRIPT LIE. Section C below has to
     * plant a row BY HAND on connection A -- that is the only way to hold an uncommitted insert
     * while B races it. The first version of that section planted the RAW key while
     * `Cache::add()` writes `cache.prefix . $key` (CacheManager::createDatabaseDriver ->
     * getPrefix()), so A and B were contending for two DIFFERENT rows. B's insert succeeded, and
     * the two checks AFTER it then passed for the wrong reason -- B was finding its own row, not
     * A's. One check failing is what exposed it. Recorded here rather than quietly fixed: a
     * harness that stages the wrong thing is the failure mode this project keeps meeting.
     */
    $prefix = (string) config('cache.prefix');
    echo "cache key prefix: '{$prefix}'\n";

    $key = 'gateway-return-claim:checkout:' . sha1('nonce-sequential');

    check('the first caller takes the claim', $storeA->add($key, 'cinetpay', 600) === true);
    check('the SECOND caller is refused the same claim', $storeB->add($key, 'cinetpay', 600) === false);
    check('a DIFFERENT key is still free (the control does not refuse everything)',
        $storeB->add($key . ':other', 'cinetpay', 600) === true);

    // ---------------------------------------------------------------------------------------
    echo "\nC. the CONCURRENT claim -- the case the suite cannot stage\n";
    // ---------------------------------------------------------------------------------------
    /*
     * Two gateway returns for the same payment arrive at once. Neither has committed when the
     * other reads, which is exactly the state `Session::forget()` cannot help with: both readers
     * hold a valid copy of the checkout state.
     *
     * A inserts its claim and does NOT commit. B then calls Cache::add on the same key. B's
     * non-locking read sees nothing -- A is uncommitted -- so B passes the fast path and reaches
     * the INSERT, where it must BLOCK on A's primary-key lock rather than decide on its own stale
     * read. B's lock wait is set to 1 second so the block is observable rather than a hang.
     */
    $concurrentKey = 'gateway-return-claim:payment:cinetpay:' . sha1('CPM-RACE');

    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $a->beginTransaction();
    $a->table('cache')->insert([
        'key' => $prefix . $concurrentKey,
        'value' => serialize('cinetpay'),
        'expiration' => time() + 600,
    ]);

    $blocked = false;
    $bResultWhileUncommitted = null;
    try {
        $bResultWhileUncommitted = $storeB->add($concurrentKey, 'cinetpay', 600);
    } catch (Throwable $e) {
        $blocked = str_contains($e->getMessage(), '1205') || str_contains(strtolower($e->getMessage()), 'lock wait');
        if (! $blocked) {
            throw $e;
        }
    }

    check('B BLOCKS on the row lock instead of deciding on its stale read (error 1205)',
        $blocked,
        $blocked ? '' : 'B returned ' . var_export($bResultWhileUncommitted, true)
            . ' without waiting, so two overlapping returns could both be admitted');

    $a->commit();

    check('once A has committed, B is refused -- the predicate is re-evaluated against the '
        . 'COMMITTED row',
        $storeB->add($concurrentKey, 'cinetpay', 600) === false);

    check('and A, which won the race, really does hold the claim',
        (int) $a->table('cache')->where('key', $prefix . $concurrentKey)->count() === 1);

    // ---------------------------------------------------------------------------------------
    echo "\nD. the TTL means what the docblock says: an expired claim is retakeable\n";
    // ---------------------------------------------------------------------------------------
    /*
     * GATEWAY_RETURN_CLAIM_TTL is 48 hours precisely so a claim cannot lapse while a gateway is
     * still retrying. The behaviour on expiry is still worth pinning: a claim that could never be
     * retaken would make the cache table grow without bound and would refuse a shopper whose
     * payment reference a gateway legitimately reused two days later.
     */
    $expiredKey = 'gateway-return-claim:checkout:' . sha1('nonce-expired');
    $a->table('cache')->insert([
        'key' => $prefix . $expiredKey,
        'value' => serialize('khalti'),
        'expiration' => time() - 1,
    ]);

    check('an EXPIRED claim does not permanently block the key',
        $storeA->add($expiredKey, 'khalti', 600) === true,
        'an expired row refused a fresh claim, so the cache table would only ever grow');

    // ---------------------------------------------------------------------------------------
    echo "\nE. what the SUITE is and is NOT evidence for\n";
    // ---------------------------------------------------------------------------------------
    /*
     * Stated as a measurement rather than as prose in a docblock, because the whole point of this
     * file is that a claim about an engine has to be executed. phpunit.xml sets CACHE_STORE=array.
     */
    check('ArrayStore has NO add() of its own, so under CACHE_STORE=array Cache::add() is the '
        . 'Repository get-then-put fallback and is NOT atomic -- which is why sections B-D exist',
        ! method_exists(\Illuminate\Cache\ArrayStore::class, 'add'),
        'ArrayStore gained an add(); re-read whether the suite now proves more than it did');

    check('the production default really is the database store (.env.example)',
        str_contains((string) @file_get_contents(__DIR__ . '/../../../../.env.example'), 'CACHE_STORE=database'));
} finally {
    try {
        DB::connection('fresh_a')->disconnect();
        DB::connection('fresh_b')->disconnect();
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop the throwaway database: {$e->getMessage()}\n");
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "{$checks} checks, {$failures} failures\n";

exit($failures === 0 ? 0 : 1);
