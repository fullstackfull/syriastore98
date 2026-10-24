<?php

/**
 * GATE F -- THE THREE RECONCILIATION ITEMS, REPORTED. READ ONLY.
 * =================================================================================================
 *
 * Owner direction, 2026-10-24: "Preserve the historical wallet discrepancy, 17 COD orders lacking
 * collection evidence, and 89 suspicious profit records as explicit reconciliation items. Do not
 * invent collection evidence, missing costs, or corrected balances. ... Do not activate unverified
 * balances or payouts."
 *
 * WHAT THIS IS. A thin wrapper around Tests\Support\Security\GateFReconciliation, which holds the
 * logic so that the SAME code the suite proves in both directions is the code run against a restored
 * copy. A report whose logic exists only in a script has never been tested; a report whose logic
 * exists only in a test has never been run.
 *
 * IT RESOLVES NOTHING, AND THAT IS THE DESIGN. There is no INSERT, UPDATE or DELETE in this file or
 * in the class it calls. It writes no corrected balance, creates no collection event, fills no cost,
 * and computes no payout. Every item it prints needs a fact the business holds; a number written in
 * to make a column agree would be a manufactured record, which the direction forbids twice.
 *
 * EXIT CODES, so it can gate a release without anyone reading it carefully:
 *   0  no reconciliation item present
 *   1  at least one item present -- the detail is printed
 *   2  the database could not be read
 *
 * HOW TO RUN. Against a RESTORED COPY, never against production:
 *
 *     php tests/Support/Security/bin/report-gatef-reconciliation.php <database> \
 *         [--host=127.0.0.1] [--port=3306] [--user=root] [--pass=secret] [--socket=/path]
 *
 * Proven in both directions by tests/Feature/Commerce/GateFReconciliationReportTest.php -- a clean
 * synthetic store reports 0 items and exit 0, a store carrying all three shapes reports 3 and exit 1.
 * Narrative for each item, and what closing it needs: docs/GATE-F-RECONCILIATION-ITEMS.md
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Tests\Support\Security\GateFReconciliation;

$args = array_slice($argv, 1);
$database = null;
$opt = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '', 'socket' => ''];

foreach ($args as $arg) {
    if (preg_match('/^--(host|port|user|pass|socket)=(.*)$/', $arg, $m)) {
        $opt[$m[1]] = $m[2];
        continue;
    }
    if ($database === null && ! str_starts_with($arg, '--')) {
        $database = $arg;
    }
}

if ($database === null) {
    fwrite(STDERR, "usage: php " . basename(__FILE__) . " <database> [--host= --port= --user= --pass= --socket=]\n");
    exit(2);
}

config(['database.connections.gatef_report' => [
    'driver' => 'mysql',
    'host' => $opt['host'],
    'port' => $opt['port'],
    'unix_socket' => $opt['socket'],
    'database' => $database,
    'username' => $opt['user'],
    'password' => $opt['pass'],
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
]]);

echo "GATE F RECONCILIATION -- read-only, against a RESTORED COPY\n";
echo str_repeat('=', 78) . "\n";

try {
    $connection = DB::connection('gatef_report');
    printf("database  : %s\n", $database);
    printf("server    : %s\n", $connection->selectOne('SELECT VERSION() AS v')->v);
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read the database: {$e->getMessage()}\n");
    exit(2);
}

try {
    $items = (new GateFReconciliation($connection))->items();
} catch (Throwable $e) {
    fwrite(STDERR, "The reconciliation could not be computed: {$e->getMessage()}\n");
    exit(2);
}

echo str_repeat('-', 78) . "\n";

if ($items === []) {
    echo "NO RECONCILIATION ITEM PRESENT.\n\n";
    echo "That is not the same as GATE F being ready: the schema prerequisites are a separate\n";
    echo "question, recorded in docs/GATE-F-VERIFIED-SCHEMA.md.\n";
    echo "\nNothing was read other than SELECTs, and nothing was modified.\n";
    exit(0);
}

foreach ($items as $item) {
    printf("\n%s -- %s\n", $item['code'], $item['title']);
    echo str_repeat('-', 78) . "\n";
    echo json_encode($item['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d RECONCILIATION ITEM(S) PRESENT.\n\n", count($items));
echo "NONE OF THESE IS CLOSED BY CODE. Each needs a fact the business holds -- what cash was\n";
echo "actually collected, what the goods actually cost, what the balance should actually be.\n";
echo "Do not write a corrected balance, fabricate a collection event, or back-fill a cost to\n";
echo "make a column agree. See docs/GATE-F-RECONCILIATION-ITEMS.md.\n";
echo "\nNothing was modified by this script.\n";

exit(1);
