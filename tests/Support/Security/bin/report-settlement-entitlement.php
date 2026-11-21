<?php

/**
 * P4-F128 -- THE SETTLEMENT-TO-ENTITLEMENT RECONCILIATION, REPORTED. READ ONLY.
 * =================================================================================================
 *
 * Owner decision on P4-F128: keep settlement and entitlement DISTINCT, and add reconciliation. This
 * script is the second half. It does NOT make one side follow the other -- that would be the exact
 * conflation the first half forbids. It asks only: where the two are supposed to agree, do they.
 *
 * WHAT IT ADDS THAT NOTHING ELSE HAD. Measured before it was written: no query anywhere in app/
 * joins `orders` to `store_order_financials`, and `app/Console/Commands/` holds no reconciliation
 * command. The one surface named "reconciliation" in the product --
 * StoreFinanceController::unreconciledCaptures() -- reconciles gateway captures against ORDERS and
 * reads `order_gateway_references`, a table whose migration is dev/test-only and which has ZERO
 * writers in app/. In production it returns an empty list with record_available = false. So before
 * this script, the settlement-to-entitlement axis had never been checked at all.
 *
 * IT RESOLVES NOTHING, AND THAT IS THE DESIGN. There is no INSERT, UPDATE or DELETE in this file or
 * in the class it calls, and SettlementEntitlementReconciliationTest asserts that every statement it
 * issues is a SELECT. A divergence here is a question about what really happened to money; writing a
 * number in to make two columns agree would manufacture the answer.
 *
 * READ THE BUCKETS, NOT THE TOTAL. D2 separates three things a single count would merge: a currency
 * conversion (NOT COMPARABLE -- not a discrepancy), a transaction on the wrong wallet (a CONTROL
 * FAILURE), and an actual amount disagreement. Reporting them as one number would be the kind of
 * figure this project refuses to produce.
 *
 * EXIT CODES, so it can gate a release without anyone reading it carefully:
 *   0  settlement and entitlement reconcile
 *   1  at least one divergence class present -- the detail is printed
 *   2  the database could not be read
 *
 * HOW TO RUN. Against a RESTORED COPY, never against production:
 *
 *     php tests/Support/Security/bin/report-settlement-entitlement.php <database> \
 *         [--host=127.0.0.1] [--port=3306] [--user=root] [--pass=secret] [--socket=/path]
 *
 * Proven in both directions by tests/Feature/Commerce/SettlementEntitlementReconciliationTest.php:
 * a consistent store reports nothing, and each leg is separately shown to redden when neutered.
 * Narrative, the four legs and the three divergence generators measured in the code:
 * docs/finance/P4-F128-SETTLEMENT-ENTITLEMENT-RECONCILIATION.md
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Tests\Support\Security\SettlementEntitlementReconciliation;

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
    fwrite(STDERR, 'usage: php ' . basename(__FILE__) . " <database> [--host= --port= --user= --pass= --socket=]\n");
    exit(2);
}

config(['database.connections.settlement_report' => [
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

echo "P4-F128 SETTLEMENT-TO-ENTITLEMENT RECONCILIATION -- read-only, against a RESTORED COPY\n";
echo str_repeat('=', 78) . "\n";

try {
    $connection = DB::connection('settlement_report');
    printf("database  : %s\n", $database);
    printf("server    : %s\n", $connection->selectOne('SELECT VERSION() AS v')->v);
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read the database: {$e->getMessage()}\n");
    exit(2);
}

try {
    $items = (new SettlementEntitlementReconciliation($connection))->items();
} catch (Throwable $e) {
    fwrite(STDERR, "The reconciliation could not be computed: {$e->getMessage()}\n");
    exit(2);
}

echo str_repeat('-', 78) . "\n";

if ($items === []) {
    echo "SETTLEMENT AND ENTITLEMENT RECONCILE on all four legs.\n\n";
    echo "That is not the same as the finance surfaces being safe. Four of them still move or\n";
    echo "release money with no reason the caller can supply -- see the F128 document.\n";
    echo "\nNothing was read other than SELECTs, and nothing was modified.\n";
    exit(0);
}

foreach ($items as $item) {
    printf("\n%s (leg %d) -- %s\n", $item['code'], $item['leg'], $item['title']);
    echo str_repeat('-', 78) . "\n";
    echo json_encode($item['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d DIVERGENCE CLASS(ES) PRESENT.\n\n", count($items));
echo "NONE OF THESE IS CLOSED BY CODE, and D1 in particular must not be 'fixed' by copying one\n";
echo "column into the other. Settlement is what the CUSTOMER paid; entitlement is what the\n";
echo "MERCHANT is owed. Which of the two is wrong is a question about what really happened, and\n";
echo "the answer is a fact the business holds.\n\n";
echo "READ D2 BY BUCKET, never by its total: not_comparable is a currency question, and\n";
echo "control_failure is a wrong-wallet row, which is a different and more serious thing than an\n";
echo "amount that disagrees.\n";
echo "\nNothing was modified by this script.\n";

exit(1);
