<?php

/**
 * D-N34 -- ORDERS RECORDED WITH A CURRENCY SYMBOL WHERE THE CODE BELONGS. READ ONLY.
 * =================================================================================================
 *
 * Until 2026-11-22 the two storefront-API order writers stamped `orders.currency_code` from the
 * store's currency SYMBOL setting, so a Syrian-pound store's orders through those endpoints were
 * recorded as `$`. The writers are repaired. This answers the question that fix cannot: how many
 * rows already carry something that is not a currency code.
 *
 * A thin wrapper around Tests\Support\Security\OrderCurrencyCodeAudit, which holds the logic so
 * that the code the suite proves in both directions is the code run here.
 *
 * IT CHANGES NOTHING. There is no INSERT, UPDATE or DELETE in this file or in the class it calls.
 * It prints counts, store counts and the recorded currency strings. Nothing about any person.
 *
 * EXIT CODES:
 *   0  every order carries a currency code, or none at all
 *   1  at least one order carries something else -- the breakdown is printed
 *   2  the database could not be read
 *
 * HOW TO RUN. Against a RESTORED COPY or a read replica, never the primary:
 *
 *     php tests/Support/Security/bin/report-order-currency-codes.php <database> \
 *         [--host=127.0.0.1] [--port=3306] [--user=root] [--pass=secret] [--socket=/path]
 *
 * Proven in both directions by tests/Feature/Commerce/OrderCurrencyCodeAuditTest.php.
 * The decision this informs: docs/development/phase-5/PHASE-05-OWNER-DECISION-REGISTER.md, D-N34.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Tests\Support\Security\OrderCurrencyCodeAudit;

$database = null;
$opt = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '', 'socket' => ''];

foreach (array_slice($argv, 1) as $arg) {
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

config(['database.connections.currency_audit' => [
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

echo "ORDER CURRENCY CODES -- read-only\n" . str_repeat('=', 78) . "\n";

try {
    $connection = DB::connection('currency_audit');
    printf("database  : %s\n", $database);
    printf("server    : %s\n", $connection->selectOne('SELECT VERSION() AS v')->v);
    $report = (new OrderCurrencyCodeAudit($connection))->report();
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read the database: {$e->getMessage()}\n");
    exit(2);
}

echo str_repeat('-', 78) . "\n";
printf("orders                                         : %d\n", $report['orders']);
printf("  recorded with a currency code                : %d\n", $report['recorded_as_a_code']);
printf("  with no currency recorded                    : %d\n", $report['not_recorded']);
printf("  recorded with something that is not a code   : %d\n", $report['recorded_as_something_else']);
printf("stores holding such orders                     : %d\n", $report['stores_holding_such_orders']);
printf("stores whose orders carry more than one value  : %d\n", $report['stores_whose_orders_carry_more_than_one_value']);

if ($report['values_that_are_not_codes'] !== []) {
    echo "\nthe values, most frequent first:\n";
    foreach ($report['values_that_are_not_codes'] as $value => $count) {
        printf("  %-12s %d\n", json_encode((string) $value, JSON_UNESCAPED_UNICODE), $count);
    }
}

echo "\nTHE COUNT IS A FLOOR. A currency SYMBOL that happens to be Latin letters -- 'SP', 'Dh' --\n";
echo "reads as a code and is not counted above. See the class docblock for why this does not\n";
echo "compare against currency_rates instead.\n";
echo "\nNothing was read other than SELECTs, and nothing was modified.\n";
echo "Correcting, reinterpreting or leaving these rows is the owner's decision (D-N34).\n";

exit($report['recorded_as_something_else'] > 0 ? 1 : 0);
