#!/usr/bin/env php
<?php

/**
 * P1-ORD-07 / P1-ORD-08 -- VERIFY, ON A REAL MySQL SERVER, WHAT SQLITE CANNOT SHOW.
 *
 * Two properties, both invisible to the Feature suite (SQLite `:memory:`):
 *
 *  A. THE REFUND REQUEST'S FRESHNESS LOCK. AccountProfileController::order_refund_request re-reads the
 *     order's `product_json` `FOR UPDATE` inside its transaction and refuses if it changed since the
 *     unlocked read its numbers were computed from. SQLite emits no lock, so the PHPUnit test can pin
 *     only the comparison; the claim "the second concurrent request WAITS here, then sees the first
 *     one's commit" is about InnoDB and had never been executed. This script executes it with two
 *     connections: the second blocks (error 1205 under a short lock-wait timeout), and once the first
 *     commits, a locking read returns the NEW row while a plain read inside an older snapshot still
 *     returns the OLD one -- which is exactly why the re-read must be FOR UPDATE.
 *     It also shows the comparison does not false-refuse a legitimate untouched row on MySQL.
 *
 *  B. THE LIMITER KEYS. The order-track limiter is keyed on the store slug and the order number, and
 *     the lookups that follow compare them under MySQL's case- AND accent-insensitive collation. A key
 *     built from the raw strings gives an attacker a fresh bucket per spelling of the same row
 *     (`MyShop`, `mYshop`, `myshóp`). This script shows that those spellings match the SAME stores row
 *     and the SAME orders row on MySQL, and that App\Providers\RouteServiceProvider::foldForRateLimit()
 *     maps them all to ONE key.
 *
 * HOW TO RUN. Against any throwaway MySQL you control; it creates and drops its own database:
 *
 *   FRESH_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock FRESH_MYSQL_DATABASE=fresh_verify \
 *   php tests/Support/Security/bin/verify-refund-freshness-lock-and-limiter-keys-on-mysql.php
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER: it issues CREATE/DROP DATABASE and refuses a
 * name that contains neither 'fresh' nor 'verify'. Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Providers\RouteServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'fresh_verify';

if (! str_contains($database, 'fresh') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] contains neither 'fresh' nor 'verify'.\n");
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

echo "P1-ORD-07/08 -- refund freshness lock and limiter keys, verified on MySQL\n";
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

    $server = $a->select('SELECT VERSION() AS v, @@default_storage_engine AS e, @@transaction_isolation AS i')[0];
    echo "  MySQL {$server->v}, engine {$server->e}, isolation {$server->i}, database `{$database}`\n\n";

    // Tables shaped like the real ones for the columns these checks touch (engine left to the server's
    // default, as the real migrations leave it: config/database.php 'engine' => null).
    $a->statement('CREATE TABLE orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_order_id VARCHAR(255) NULL,
        store_id BIGINT UNSIGNED NULL,
        customer_id BIGINT UNSIGNED NULL,
        product_json TEXT NULL,
        delivery_price DOUBLE NULL, tax_price DOUBLE NULL, coupon_price DOUBLE NULL,
        final_price DOUBLE NULL, product_price DOUBLE NULL
    )');
    $a->statement('CREATE TABLE stores (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(255) NULL)');

    $original = json_encode([['product_id' => 777, 'qty' => 4, 'final_price' => 400]]);
    $a->table('orders')->insert(['id' => 1, 'product_order_id' => '020261021120007', 'store_id' => 1, 'customer_id' => 9,
        'product_json' => $original, 'delivery_price' => 20, 'tax_price' => 20, 'coupon_price' => 0, 'final_price' => 440, 'product_price' => 400]);
    $a->table('stores')->insert(['id' => 1, 'slug' => 'myshop']);

    // ---------------------------------------------------------------- A. the freshness lock
    echo "A. FOR UPDATE freshness\n";

    // The handler loads the order with `select *` (Eloquent) and later reads product_json through
    // DB::table()->value(). Same PDO, same fetch mode -- but that is the thing to execute, not assume.
    $loaded = (array) $a->table('orders')->where('id', 1)->first();
    $rawRead = $a->table('orders')->where('id', 1)->where('store_id', 1)->where('customer_id', 9)->lockForUpdate()->value('product_json');
    check('an UNTOUCHED row compares equal between the unlocked select * and the locking read (no false refusal)',
        $loaded['product_json'] === $rawRead, 'loaded=' . var_export($loaded['product_json'], true) . ' raw=' . var_export($rawRead, true));

    // Connection B takes a snapshot first (REPEATABLE READ), as an in-flight request would have.
    $b->statement('SET SESSION innodb_lock_wait_timeout = 2');
    $b->beginTransaction();
    $snapshot = $b->table('orders')->where('id', 1)->value('product_json');
    check('B reads the original row (this is its stale snapshot)', $snapshot === $original);

    // A starts ITS transaction and takes the row lock, as the first of two concurrent requests.
    $a->beginTransaction();
    $a->table('orders')->where('id', 1)->lockForUpdate()->value('product_json');

    $blocked = false;
    $started = microtime(true);
    try {
        $b->table('orders')->where('id', 1)->lockForUpdate()->value('product_json');
    } catch (QueryException $e) {
        $blocked = str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Lock wait timeout');
    }
    $waited = microtime(true) - $started;
    check('B\'s locking read BLOCKS while A holds the row (lock-wait timeout 1205 after ~2s)', $blocked && $waited >= 1.5, sprintf('waited %.2fs', $waited));

    // A applies its refund and commits.
    $committed = json_encode([['product_id' => 777, 'qty' => 1, 'final_price' => 100]]);
    $a->table('orders')->where('id', 1)->update(['product_json' => $committed, 'product_price' => 100, 'final_price' => 140]);
    $a->commit();

    $plain = $b->table('orders')->where('id', 1)->value('product_json');
    $locked = $b->table('orders')->where('id', 1)->lockForUpdate()->value('product_json');
    check('after A commits, a PLAIN read inside B\'s older snapshot still returns the OLD row (why the re-read must lock)',
        $plain === $original);
    check('after A commits, B\'s LOCKING read returns the NEW row, so `$locked !== $snapshot` and B refuses',
        $locked === $committed && $locked !== $snapshot);
    $b->rollBack();

    // ---------------------------------------------------------------- B. limiter keys
    echo "\nB. limiter keys\n";

    $variants = ['myshop', 'MyShop', 'mYSHOP', 'myshóp', 'MYSHÓP'];
    $matchedStores = [];
    foreach ($variants as $variant) {
        $matchedStores[$variant] = $a->table('stores')->where('slug', $variant)->count();
    }
    check('every case/accent spelling of the slug matches the SAME stores row on MySQL',
        array_sum($matchedStores) === count($variants), json_encode($matchedStores, JSON_UNESCAPED_UNICODE));

    $a->table('orders')->insert(['id' => 2, 'product_order_id' => 'AbC-0042', 'store_id' => 1, 'customer_id' => 9]);
    $numberVariants = ['AbC-0042', 'abc-0042', 'ABC-0042', 'ábc-0042'];
    $matchedOrders = [];
    foreach ($numberVariants as $variant) {
        $matchedOrders[$variant] = $a->table('orders')->where('product_order_id', $variant)->count();
    }
    check('every case/accent spelling of the order number matches the SAME orders row on MySQL',
        array_sum($matchedOrders) === count($numberVariants), json_encode($matchedOrders, JSON_UNESCAPED_UNICODE));

    $slugKeys = array_unique(array_map([RouteServiceProvider::class, 'foldForRateLimit'], $variants));
    check('foldForRateLimit() maps all slug spellings to ONE key', count($slugKeys) === 1, json_encode($slugKeys, JSON_UNESCAPED_UNICODE));

    $numberKeys = array_unique(array_map([RouteServiceProvider::class, 'foldForRateLimit'], $numberVariants));
    check('foldForRateLimit() maps all order-number spellings to ONE key', count($numberKeys) === 1, json_encode($numberKeys, JSON_UNESCAPED_UNICODE));

    check('foldForRateLimit() keeps DIFFERENT numbers apart',
        RouteServiceProvider::foldForRateLimit('AbC-0042') !== RouteServiceProvider::foldForRateLimit('AbC-0043'));
} catch (Throwable $e) {
    $failures++;
    echo '  FAIL  uncaught: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    try {
        DB::purge('fresh_a');
        DB::purge('fresh_b');
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable $e) {
        echo '  (could not drop the throwaway database: ' . $e->getMessage() . ")\n";
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
