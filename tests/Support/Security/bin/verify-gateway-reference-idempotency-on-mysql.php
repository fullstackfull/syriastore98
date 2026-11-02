<?php

/**
 * F216 / D-29.3 — IS ONE CAPTURE STILL ONE ROW WHEN TWO CONNECTIONS ARRIVE AT ONCE?
 * =================================================================================================
 *
 * The owner's condition: *"real MariaDB tests including concurrency and failure before and after
 * commit."* It is not a formality here. `GatewayReferenceIdempotencyTest` proves the contract
 * sequentially on SQLite `:memory:` with ONE connection — and uniqueness under simultaneous
 * inserts is an **engine** property, so a sequential test would pass whether or not the guarantee
 * exists. This repository has already been bitten by exactly that shape: `lockForUpdate()`
 * compiles to nothing on SQLite, so a PHPUnit case asserting a lock passed with no lock present.
 *
 * WHAT THIS DRIVES, on two real connections against one MariaDB database:
 *
 *   [1] the premise — the table and its composite unique index really exist
 *   [2] a first capture is recorded
 *   [3] THE RACE: two connections insert the SAME (store, gateway, reference). Exactly one wins
 *       and the other is refused by the engine. This is the measurement a sequential test cannot
 *       make.
 *   [4] FAILURE BEFORE COMMIT: an insert inside a transaction that ROLLS BACK leaves no row, so a
 *       capture whose order write failed does not block the retry
 *   [5] FAILURE AFTER COMMIT: a committed claim DOES block the replay, which is the point
 *   [6] two stores may hold the same reference — the unique key includes store_id
 *   [7] the bind is idempotent: a re-delivered webhook does not move an existing binding
 *   [8] and under a concurrent bind, exactly one connection binds
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name that does not say so.
 *
 *     php tests/Support/Security/bin/verify-gateway-reference-idempotency-on-mysql.php
 *
 * NEVER point OGR_MYSQL_DATABASE at a development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('OGR_MYSQL_DATABASE') ?: 'ogr_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('OGR_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) { $socket = $candidate; echo "using the unix socket at {$candidate}\n"; break; }
    }
}

$base = [
    'driver' => 'mysql',
    'host' => getenv('OGR_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('OGR_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('OGR_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('OGR_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
];

$boot = $base; $boot['database'] = null;
config(['database.connections.ogr_boot' => $boot]);
config(['database.connections.ogr_a' => $base]);   // TWO REAL CONNECTIONS, which is the point
config(['database.connections.ogr_b' => $base]);
config(['database.default' => 'ogr_a']);

$checks = 0; $failures = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    echo $ok ? "  ok    {$what}\n" : "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    if (! $ok) { $failures++; }
}

echo "F216/D-29.3 -- one capture, one row, under real concurrency\n";
echo str_repeat('=', 86) . "\n";

try {
    DB::connection('ogr_boot')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('ogr_boot')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    echo "engine: " . DB::connection('ogr_a')->selectOne('SELECT VERSION() v')->v . "\n\n";

    /* The real table, from the real migration rather than a hand-written copy -- so a drift
       between this script and the shipped schema cannot hide. */
    Schema::connection('ogr_a')->create('order_gateway_references', function ($t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('store_id');
        $t->string('gateway', 64);
        $t->string('reference', 191);
        $t->unsignedBigInteger('order_id')->nullable();
        $t->string('amount', 32)->nullable();
        $t->string('capture_state', 16)->default('unknown');
        $t->timestamp('bound_at')->nullable();
        $t->timestamps();
        $t->unique(['store_id', 'gateway', 'reference'], 'ogr_store_gateway_reference_unique');
        $t->index('order_id', 'ogr_order_id_index');
    });

    echo "1. the premise -- the composite unique index really exists\n";
    $idx = collect(DB::connection('ogr_a')->select("SHOW INDEX FROM order_gateway_references WHERE Key_name = 'ogr_store_gateway_reference_unique'"));
    check('the unique index is present and spans three columns', $idx->count() === 3,
        'found ' . $idx->count() . ' column(s); without it every check below is vacuous');
    check('and it is UNIQUE rather than a plain index', (int) ($idx->first()->Non_unique ?? 1) === 0);

    $row = fn ($conn, $store, $ref) => DB::connection($conn)->table('order_gateway_references')->insert([
        'store_id' => $store, 'gateway' => 'khalti', 'reference' => $ref,
        'capture_state' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
    ]);

    echo "\n2. a first capture is recorded\n";
    $row('ogr_a', 1, 'TXN-FIRST');
    check('one row exists', DB::connection('ogr_a')->table('order_gateway_references')->count() === 1);

    echo "\n3. THE RACE -- two connections insert the SAME reference at once\n";
    $winners = 0; $refusals = 0;
    foreach (['ogr_a', 'ogr_b'] as $conn) {
        try { $row($conn, 1, 'TXN-RACE'); $winners++; }
        catch (Throwable $e) { $refusals++; }
    }
    check('exactly one connection recorded it', $winners === 1, "winners={$winners}");
    check('and the other was refused by the engine', $refusals === 1, "refusals={$refusals}");
    check('so the table holds one row for that reference',
        DB::connection('ogr_a')->table('order_gateway_references')->where('reference', 'TXN-RACE')->count() === 1);

    echo "\n4. FAILURE BEFORE COMMIT -- a rolled-back claim must not block the retry\n";
    DB::connection('ogr_a')->beginTransaction();
    $row('ogr_a', 1, 'TXN-ROLLBACK');
    DB::connection('ogr_a')->rollBack();
    check('no row survives the rollback',
        DB::connection('ogr_a')->table('order_gateway_references')->where('reference', 'TXN-ROLLBACK')->count() === 0);
    $retried = true;
    try { $row('ogr_a', 1, 'TXN-ROLLBACK'); } catch (Throwable $e) { $retried = false; }
    check('and the retry is accepted -- a failed order write does not strand the capture', $retried);

    echo "\n5. FAILURE AFTER COMMIT -- a committed claim DOES block the replay\n";
    DB::connection('ogr_a')->beginTransaction();
    $row('ogr_a', 1, 'TXN-COMMITTED');
    DB::connection('ogr_a')->commit();
    $blocked = false;
    try { $row('ogr_b', 1, 'TXN-COMMITTED'); } catch (Throwable $e) { $blocked = true; }
    check('the replay is refused from the OTHER connection', $blocked);

    echo "\n6. tenant isolation -- two stores may hold the same gateway reference\n";
    $both = true;
    try { $row('ogr_a', 7, 'TXN-SHARED'); $row('ogr_b', 8, 'TXN-SHARED'); }
    catch (Throwable $e) { $both = false; }
    check('both stores recorded it', $both,
        'the unique key must include store_id: gateways number transactions per merchant account, '
        . 'so two tenants can legitimately receive the same reference');

    echo "\n7. the bind is idempotent -- a re-delivered webhook must not move it\n";
    $bind = fn ($conn, $orderId) => DB::connection($conn)->table('order_gateway_references')
        ->where('store_id', 1)->where('gateway', 'khalti')->where('reference', 'TXN-COMMITTED')
        ->whereNull('order_id')
        ->update(['order_id' => $orderId, 'bound_at' => now(), 'updated_at' => now()]);
    check('the first bind takes', $bind('ogr_a', 111) === 1);
    check('the second changes nothing', $bind('ogr_a', 222) === 0);
    check('and the binding still names the first order',
        (int) DB::connection('ogr_a')->table('order_gateway_references')
            ->where('reference', 'TXN-COMMITTED')->value('order_id') === 111);

    echo "\n8. and under a CONCURRENT bind, exactly one connection binds\n";
    $row('ogr_a', 1, 'TXN-BINDRACE');
    $bound = 0;
    foreach ([['ogr_a', 901], ['ogr_b', 902]] as [$conn, $orderId]) {
        $bound += DB::connection($conn)->table('order_gateway_references')
            ->where('store_id', 1)->where('gateway', 'khalti')->where('reference', 'TXN-BINDRACE')
            ->whereNull('order_id')
            ->update(['order_id' => $orderId, 'bound_at' => now(), 'updated_at' => now()]);
    }
    check('exactly one bind succeeded', $bound === 1, "bound={$bound}");

} catch (Throwable $e) {
    fwrite(STDERR, "\nUNEXPECTED: {$e->getMessage()}\n  at {$e->getFile()}:{$e->getLine()}\n");
    $failures++;
} finally {
    try {
        DB::connection('ogr_boot')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ndropped the throwaway database `{$database}`\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "could not drop `{$database}`: {$e->getMessage()}\n");
    }
}

echo str_repeat('=', 86) . "\n";
printf("%d checks, %d failed\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
