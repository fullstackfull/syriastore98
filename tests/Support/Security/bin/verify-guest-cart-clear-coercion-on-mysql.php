#!/usr/bin/env php
<?php

/**
 * P1-SEC-21 follow-up -- VERIFY, ON A REAL MySQL SERVER, WHAT SQLITE CANNOT SHOW: how a guest's basket is
 * cleared.
 *
 * `PaymentController::whatsapp()` pins a guest's `customer_id` to the empty string ('') and queues the
 * lines to clear as `['customer_id' => '', product, variant, store]`; `clearCartLines()` then runs
 *
 *     Cart::where('customer_id', '')->where('product_id', $p)->where('variant_id', $v)->where('store_id', $s)->delete();
 *
 * Guest basket rows carry `customer_id = 0` (the column is `integer default 0`), and the cookie is the only
 * thing that identifies WHICH guest. The Feature suite runs on SQLite, where `customer_id = ''` matches
 * nothing, so no test could show what MySQL does with it. This script runs that exact statement shape on
 * MySQL in production's non-strict mode (config/database.php:55) and in strict mode, and reports whether one
 * guest's order clears OTHER guests' baskets.
 *
 * STATUS: P1-SEC-23 changed clearCartLines() to identify a guest by `cookie_session_id`. This script keeps
 * the OLD statement shape on purpose -- it is the record of what MySQL did with it, and the reason the
 * fix exists -- and does not call application code. The fixed behaviour is pinned by
 * tests/Feature/Security/GuestBasketClearTest.php.
 *
 * HOW TO RUN. Against any throwaway MySQL you control; it creates and drops its own database:
 *
 *   FRESH_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock FRESH_MYSQL_DATABASE=cartclear_verify \
 *   php tests/Support/Security/bin/verify-guest-cart-clear-coercion-on-mysql.php
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER: it issues CREATE/DROP DATABASE and refuses a name
 * that contains neither 'fresh' nor 'verify'. Exit 0 means the script ran to the end; the FINDING is printed
 * either way, and the assertions below are about what was MEASURED, not about what is desired.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'cartclear_verify';

if (! str_contains($database, 'fresh') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] contains neither 'fresh' nor 'verify'.\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            break;
        }
    }
}

$base = [
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
];

$bootstrap = $base;
$bootstrap['database'] = null;
config(['database.connections.cc_bootstrap' => $bootstrap]);
config(['database.connections.cc_loose' => $base + ['strict' => false]]);   // production's setting
config(['database.connections.cc_strict' => $base + ['strict' => true]]);

echo "P1-SEC-21 follow-up -- how a guest's basket is cleared, verified on MySQL\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('cc_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('cc_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

$finding = [];

try {
    $loose = DB::connection('cc_loose');
    $server = $loose->select('SELECT VERSION() AS v, @@sql_mode AS m')[0];
    echo "  MySQL {$server->v}; non-strict session sql_mode = [" . $server->m . "]\n\n";

    // Shaped like database/migrations/2024_01_18_103138_create_carts_table.php (customer_id integer default 0 nullable).
    $loose->statement('CREATE TABLE carts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NULL DEFAULT 0,
        product_id INT NULL, variant_id INT NULL DEFAULT 0, store_id INT NULL,
        cookie_session_id VARCHAR(255) NULL
    )');

    $seed = function () use ($loose) {
        $loose->table('carts')->truncate();
        $loose->table('carts')->insert([
            ['customer_id' => 0, 'product_id' => 7, 'variant_id' => 0, 'store_id' => 1, 'cookie_session_id' => 'guest-A'],
            ['customer_id' => 0, 'product_id' => 7, 'variant_id' => 0, 'store_id' => 1, 'cookie_session_id' => 'guest-B'],
            ['customer_id' => 0, 'product_id' => 7, 'variant_id' => 0, 'store_id' => 2, 'cookie_session_id' => 'guest-other-store'],
            ['customer_id' => 5, 'product_id' => 7, 'variant_id' => 0, 'store_id' => 1, 'cookie_session_id' => null],
        ]);
    };

    $clear = function (string $connection, $customerId) {
        // The exact shape of PaymentController::clearCartLines().
        return DB::connection($connection)->table('carts')
            ->where('customer_id', $customerId)
            ->where('product_id', 7)->where('variant_id', 0)->where('store_id', 1)
            ->delete();
    };

    $remaining = fn () => $loose->table('carts')->orderBy('id')->pluck('cookie_session_id', 'id')->map(fn ($c) => $c ?? '(customer 5)')->values()->all();

    echo "  Seeded: guest-A, guest-B (store 1), guest-other-store (store 2), customer 5 (store 1), all for product 7.\n\n";

    foreach (['cc_loose' => 'non-strict (production)', 'cc_strict' => 'strict'] as $name => $label) {
        $seed();
        echo "  [{$label}] clearCartLines() with customer_id = '' (what whatsapp() queues for a guest):\n";
        try {
            $deleted = $clear($name, '');
            echo "      rows deleted: {$deleted}; remaining: " . json_encode($remaining()) . "\n";
            $finding[$name] = ['deleted' => $deleted, 'remaining' => $remaining(), 'error' => null];
        } catch (Throwable $e) {
            echo '      ERROR: ' . substr($e->getMessage(), 0, 160) . "\n";
            echo '      remaining: ' . json_encode($remaining()) . "\n";
            $finding[$name] = ['deleted' => 0, 'remaining' => $remaining(), 'error' => $e->getMessage()];
        }
    }

    $seed();
    $nullDeleted = $clear('cc_loose', null);
    echo "\n  [non-strict] the same statement with customer_id = NULL (what get-massage did for a guest): rows deleted: {$nullDeleted}\n";

    $seed();
    $zeroDeleted = $clear('cc_loose', 0);
    echo "  [non-strict] the same statement with customer_id = 0: rows deleted: {$zeroDeleted} (every guest of the store, whoever's cookie)\n";
} finally {
    DB::connection('cc_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
}

echo "\nFINDING\n";
$loose = $finding['cc_loose'] ?? null;
if ($loose && $loose['error'] === null && $loose['deleted'] > 0) {
    echo "  On production's non-strict MySQL, `customer_id = ''` is read as 0: ONE guest's WhatsApp/Telegram order deletes {$loose['deleted']} basket row(s),\n";
    echo "  including OTHER guests' baskets for the same product in the same store (guest-B above). Their carts are emptied by a stranger's checkout.\n";
} elseif ($loose && $loose['error'] === null) {
    echo "  `customer_id = ''` matched nothing on this server: guest baskets are not cleared by whatsapp() (and not by anyone else's order either).\n";
} else {
    echo "  The non-strict statement raised an error on this server; clearCartLines() would log it and leave the basket in place.\n";
}
$strict = $finding['cc_strict'] ?? null;
if ($strict && $strict['error'] !== null) {
    echo "  In STRICT mode the same statement is an error (caught and logged by clearCartLines(), so the basket stays).\n";
}

exit(0);
