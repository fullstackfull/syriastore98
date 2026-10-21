<?php

/**
 * P1-SEC-18 (second review) -- a store id taken from the request and then used in a FILESYSTEM PATH, verified on MySQL.
 *
 * Precondition (this script states its own, and does nothing without a server): a MySQL you control, reachable by
 * FRESH_MYSQL_* (or the default socket). It creates and drops its own database and refuses a name without 'fresh' or 'verify'.
 * NEVER point it at the development or production server.
 *
 *   FRESH_MYSQL_DATABASE=storeid_verify php tests/Support/Security/bin/verify-store-id-coercion-on-mysql.php
 *
 * THE FINDING. Api\DashboardController::updateStoreOwnerInfo() took `$storeId = $request->query('store_id')`, proved ownership with
 * resolveOwnedStore($storeId) -- `Store::where('id', $storeId)->where('created_by', ...)` -- and then built
 * `public_path('uploads/' . $storeId)` from the RAW string and moved the uploaded file there. If MySQL matches `id = '5/../../x'`
 * to store 5, the ownership check passes for a value that is also a directory traversal.
 *
 * WHAT IT ESTABLISHES, by execution:
 *   1. MySQL (non-strict, production's setting, and strict) matches `id = '<own id>/../../x'` to the caller's own store;
 *   2. SQLite (the Feature suite's engine) does NOT, which is why no test in that suite can show it;
 *   3. the fixed shape -- the path built from the id the lookup RETURNED -- contains no traversal for any such input.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'storeid_verify';

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
config(['database.connections.sid_bootstrap' => $bootstrap]);
config(['database.connections.sid_loose' => $base + ['strict' => false]]);   // production's setting
config(['database.connections.sid_strict' => $base + ['strict' => true]]);

$failed = false;
$out = function (string $label, bool $ok, string $detail = '') use (&$failed): void {
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail === '' ? '' : "\n         " . $detail);
    $failed = $failed || ! $ok;
};

try {
    DB::connection('sid_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('sid_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "No MySQL reachable / could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

echo "P1-SEC-18 -- a store id from the request, used in a path -- verified on MySQL\n" . str_repeat('-', 78) . "\n";

$inputs = ['5/../../x', '5/../../../storage', '5\\..\\..\\x', '5%2e%2e/x', ' 5/../x', "5\0/../x"];

try {
    $loose = DB::connection('sid_loose');
    $server = $loose->select('SELECT VERSION() AS v, @@sql_mode AS m')[0];
    echo "  MySQL {$server->v}; non-strict session sql_mode = [{$server->m}]\n\n";

    // Shaped like the stores table for the two columns resolveOwnedStore() uses.
    $loose->statement('CREATE TABLE stores (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, created_by INT NOT NULL)');
    $loose->insert('INSERT INTO stores (id, created_by) VALUES (5, 77), (6, 88)');

    foreach (['sid_loose' => 'non-strict (production)', 'sid_strict' => 'strict'] as $name => $label) {
        echo "  [{$label}] Store::where('id', <input>)->where('created_by', 77) -- the caller owns store 5 only\n";
        foreach ($inputs as $input) {
            try {
                $row = DB::connection($name)->table('stores')->where('id', $input)->where('created_by', 77)->first();
                $shown = var_export($input, true);
                printf("         id = %-26s -> %s\n", $shown, $row ? 'MATCHED store ' . $row->id : 'no row');
                if ($input === '5/../../x') {
                    $out("[{$label}] '5/../../x' resolves to the caller's own store 5", $row !== null && (int) $row->id === 5,
                        'If this is no row the premise is wrong on this server; re-read the finding.');
                }
            } catch (Throwable $e) {
                printf("         id = %-26s -> ERROR %s\n", var_export($input, true), substr($e->getMessage(), 0, 80));
            }
        }
    }

    echo "\n  The fixed shape: build the path from the id the lookup RETURNED.\n";
    $row = DB::connection('sid_loose')->table('stores')->where('id', '5/../../x')->where('created_by', 77)->first();
    $path = 'uploads/' . $row->id;
    $out("the path is 'uploads/5' -- no traversal survives", $path === 'uploads/5' && ! str_contains($path, '..'), "path = {$path}");
} finally {
    DB::connection('sid_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
}

echo "\nSQLite (the Feature suite's engine):\n";
$lite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lite->exec('CREATE TABLE stores (id INTEGER PRIMARY KEY, created_by INTEGER)');
$lite->exec('INSERT INTO stores (id, created_by) VALUES (5, 77)');
$st = $lite->prepare('SELECT id FROM stores WHERE id = ? AND created_by = 77');
$st->execute(['5/../../x']);
$out("SQLite does NOT match '5/../../x' -- the suite is blind to this", $st->fetch(PDO::FETCH_ASSOC) === false);

exit($failed ? 1 : 0);
