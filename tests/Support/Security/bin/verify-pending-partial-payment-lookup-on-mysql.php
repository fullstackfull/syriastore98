<?php

/**
 * P1-ORD-02 criterion 2 — THE ENGINE HALF OF THE PENDING-BALANCE LOOKUP, MEASURED ON REAL MySQL.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The Feature suite runs on SQLite `:memory:`
 * (phpunit.xml) and two of the facts below are MySQL's and not SQLite's. A test needing MySQL would
 * have to skip where there is none, and `.github/known-test-outcomes.json` declares the exact skip
 * set, so a conditionally-skipped test would turn the known-outcome gate red. Evidence that cannot
 * live in the suite belongs here.
 *
 * AND THIS FILE EXISTS BECAUSE A REMOVAL CHECK SURVIVED. The mutation run over
 * tests/Feature/Commerce/PendingPaymentAmountSourceTest.php recorded eleven mutants biting and ONE
 * surviving: relaxing `filter_var($partialPaymentId, FILTER_VALIDATE_INT)` to `is_numeric`. It
 * survives on SQLite for the reason STEP 2 shows — the engine refuses the coerced value anyway — so
 * the suite genuinely cannot tell the two predicates apart. That is a statement about the engine,
 * not a licence to drop the guard, and this script is what closes it.
 *
 * THE TWO ENGINE FACTS.
 *
 *   1. `order_partial_payments.id` is BIGINT. MySQL coerces `'5abc'` in `WHERE id = ?` and returns
 *      ROW 5; SQLite returns nothing. So on production a caller naming `partial_payment_id=5abc`
 *      would have been priced from — and would have settled — a row they did not name.
 *
 *   2. `order_partial_payments.customer_id` is declared `string()` (VARCHAR) by the module's own
 *      migration while `OrderPartialPayments::OrderPartialPayments()` inserts an INTEGER into it.
 *      That makes a SQL-side ownership predicate engine-dependent in BOTH directions:
 *        - MySQL  : `WHERE customer_id = 5` coerces the column, so the stored string '5abc' MATCHES
 *                   — an ownership predicate that admits a row it should refuse.
 *        - SQLite : the column has TEXT affinity, so `WHERE customer_id = 5` matches NOTHING, and
 *                   the same predicate would refuse every legitimate shopper.
 *      Which is why `ResolvesPendingPartialPayment` reads the value out and compares it in PHP with
 *      the same `filter_var()` it applies to the input. STEP 3 shows the three answers side by side.
 *
 * HOW TO RUN. Points at any throwaway MySQL you control; creates and drops its own database and
 * refuses a database name without 'verify' in it:
 *
 *   MYSQL_SOCK=/var/run/mysqld/mysqld.sock MYSQL_USER_NAME=root \
 *       php tests/Support/Security/bin/verify-pending-partial-payment-lookup-on-mysql.php
 *
 * Exit 0 = every assertion held. NEVER point it at the development or production server.
 */

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$database = getenv('MYSQL_DATABASE') ?: 'p1ord02_pending_verify';

if (! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name must contain 'verify'. Got: {$database}\n");
    exit(2);
}

$socket = getenv('MYSQL_SOCK') ?: '/var/run/mysqld/mysqld.sock';
$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('MYSQL_PORT_NUM') ?: 3306);
$user = getenv('MYSQL_USER_NAME') ?: 'root';
$pass = getenv('MYSQL_PASS') ?: '';

$failed = false;

$out = function (string $label, bool $ok, string $detail = '') use (&$failed): void {
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail === '' ? '' : "\n         " . $detail);

    if (! $ok) {
        $failed = true;
    }
};

// file_exists, NOT is_file: a unix socket is not a regular file, so is_file() is false for it and
// the DSN would silently fall back to TCP, where `root` typically authenticates by auth_socket.
$dsnBase = file_exists($socket) ? "mysql:unix_socket={$socket}" : "mysql:host={$host};port={$port}";

try {
    $pdo = new PDO($dsnBase, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "No MySQL reachable ({$dsnBase}): " . $e->getMessage() . "\n");
    fwrite(STDERR, "This script states its own precondition and does nothing without a server.\n");
    exit(2);
}

echo "STEP 0 -- a throwaway database, created and dropped by this script.\n";
$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}`");
$pdo->exec("USE `{$database}`");
$out("database `{$database}` created", true);
printf("         server version: %s\n", $pdo->query('SELECT VERSION()')->fetchColumn());

// config/database.php's PRODUCTION setting, so the session is configured the way production is.
$pdo->exec("SET SESSION sql_mode = ''");

/*
 * THE MODULE'S OWN SHAPE, column for column, from
 * packages/workdo/PartialPayments/src/database/migrations/2024_09_18_121302_create_order_partial_payments_table.php
 * -- `customer_id` and `payment_amount` really are VARCHARs there, and that is the point of STEP 3.
 */
$pdo->exec(
    'CREATE TABLE order_partial_payments ('
    . ' id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
    . ' order_id VARCHAR(255) NULL,'
    . ' customer_id VARCHAR(255) NULL,'
    . ' payment VARCHAR(255) NULL,'
    . ' payment_amount VARCHAR(255) NULL,'
    . ' payment_status VARCHAR(255) NULL,'
    . ' store_id BIGINT UNSIGNED NULL)'
);
$pdo->exec(
    "INSERT INTO order_partial_payments (id, order_id, customer_id, payment, payment_amount, payment_status, store_id) VALUES"
    . " (5, 'ORD-A', '7', 'Second Payment', '40.00', 'Pending payment', 2),"
    . " (42, 'ORD-B', '9', 'Second Payment', '15.00', 'Pending payment', 1)"
);
echo "         row 5  -> customer 7, store 2, owes 40.00   (another tenant's balance)\n";
echo "         row 42 -> customer 9, store 1, owes 15.00   (the caller's own store)\n";

echo "\nSTEP 1 -- THE PREMISE: MySQL coerces a non-numeric string in the `id` comparison.\n";

$select = $pdo->prepare('SELECT id, customer_id, store_id FROM order_partial_payments WHERE id = ?');
$engine = [];

foreach (['5abc', '5', ' 5', '5.9', '5e0', '0x5', 'abc', '5 OR 1=1'] as $value) {
    $select->execute([$value]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    $engine[$value] = $row === false ? null : (int) $row['id'];

    printf("         WHERE id = %-12s -> %s\n", var_export($value, true),
        $row === false ? 'no row' : 'id ' . $row['id'] . ' (customer ' . $row['customer_id'] . ', store ' . $row['store_id'] . ')');
}

$out("'5abc' resolves to ROW 5 -- a balance the caller did not name", $engine['5abc'] === 5,
    'If this is null the premise is wrong and the finding should be re-read. Actual: ' . var_export($engine['5abc'], true));
$out("'5 OR 1=1' also resolves to ROW 5", $engine['5 OR 1=1'] === 5,
    'Not an injection -- a bound parameter -- but the same leading-integer coercion.');
$out("'abc' resolves to NO row (it coerces to 0)", $engine['abc'] === null);

echo "\nSTEP 2 -- WHY THE SURVIVING MUTANT SURVIVED: SQLite does not coerce.\n";

$lite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lite->exec('CREATE TABLE order_partial_payments (id INTEGER PRIMARY KEY, customer_id TEXT, payment_amount TEXT, payment_status TEXT, store_id INTEGER)');
$lite->exec("INSERT INTO order_partial_payments (id, customer_id, payment_amount, payment_status, store_id) VALUES (5, '7', '40.00', 'Pending payment', 2)");
$liteSelect = $lite->prepare('SELECT id FROM order_partial_payments WHERE id = ?');
$liteSelect->execute(['5abc']);

$out("SQLite returns NO row for '5abc'", $liteSelect->fetch(PDO::FETCH_ASSOC) === false,
    'This is the engine-hiding. The suite cannot distinguish filter_var from is_numeric here, '
    . 'which is exactly what the surviving mutant recorded. Same class as P01-SEC-13.');

echo "\nSTEP 3 -- THE VARCHAR `customer_id`, and why the ownership check is made in PHP.\n";

/*
 * THE APPLICATION'S OWN STACK, not a hand-bound PDO statement -- and the distinction is the whole
 * measurement. A first cut of this script bound the parameter with PDO's default PARAM_STR and
 * recorded "MySQL matches only the exact row", which is true of THAT statement and false of the one
 * the application issues: `Illuminate\Database\Connection::bindValues()` binds an integer as
 * PDO::PARAM_INT, and that is what makes MySQL convert the VARCHAR COLUMN to a number.
 */
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'database.default' => 'verify_mysql',
    'database.connections.verify_mysql' => array_merge(
        config('database.connections.mysql'),
        [
            'driver' => 'mysql',
            'database' => $database,
            'username' => $user,
            'password' => $pass,
            'host' => $host,
            'port' => $port,
            'unix_socket' => file_exists($socket) ? $socket : '',
        ]
    ),
]);
Illuminate\Support\Facades\DB::purge();

$pdo->exec("INSERT INTO order_partial_payments (id, order_id, customer_id, payment, payment_amount, payment_status, store_id) VALUES"
    . " (6, 'ORD-C', '7abc', 'Second Payment', '99.00', 'Pending payment', 2),"
    . " (7, 'ORD-D', ' 7',   'Second Payment', '98.00', 'Pending payment', 2),"
    . " (8, 'ORD-E', '07',   'Second Payment', '97.00', 'Pending payment', 2)");
echo "         rows 6, 7, 8 -> customer_id '7abc', ' 7', '07' -- all values the column's type permits\n";

$mysqlMatches = Illuminate\Support\Facades\DB::table('order_partial_payments')
    ->where('customer_id', 7)->orderBy('id')->pluck('id')->map('intval')->all();

$out('MySQL: ->where(\'customer_id\', 7) matches rows ' . json_encode($mysqlMatches),
    $mysqlMatches === [5, 6, 7, 8],
    'A SQL-side ownership predicate admits every row whose string value MySQL can read a 7 out of, '
    . "including '7abc'. Actual: " . json_encode($mysqlMatches));

$lite->exec("INSERT INTO order_partial_payments (id, customer_id, payment_amount, payment_status, store_id) VALUES"
    . " (6, '7abc', '99.00', 'Pending payment', 2), (7, ' 7', '98.00', 'Pending payment', 2), (8, '07', '97.00', 'Pending payment', 2)");
$liteStmt = $lite->prepare('SELECT id FROM order_partial_payments WHERE customer_id = ?');
$liteStmt->bindValue(1, 7, PDO::PARAM_INT);
$liteStmt->execute();
$sqliteMatches = array_map('intval', array_column($liteStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

$out('SQLite: the same predicate matches only ' . json_encode($sqliteMatches),
    $sqliteMatches === [5],
    'So the suite would never show the over-admission: on SQLite the predicate behaves. This is the '
    . 'engine divergence that makes a SQL-side ownership check unsafe to rely on.');

$phpAnswer = fn (string $stored, int $customer): bool => filter_var($stored, FILTER_VALIDATE_INT) === $customer;

$out("PHP-side: '7' is customer 7, and '7abc' and '07' are not",
    $phpAnswer('7', 7) && ! $phpAnswer('7abc', 7) && ! $phpAnswer('07', 7),
    'Which is what ResolvesPendingPartialPayment::pendingPartialPaymentRow() does, and it is the '
    . 'same answer on both engines.');

/*
 * ' 7' IS ACCEPTED, AND THAT IS STATED RATHER THAN GLOSSED. FILTER_VALIDATE_INT allows surrounding
 * whitespace, so a stored ' 7' parses to 7 -- which is also what MySQL reads out of it, so the two
 * agree and the row admitted is the row the engine would have admitted. '07' is the opposite case:
 * PHP refuses it (leading zeros need FILTER_FLAG_ALLOW_OCTAL) while MySQL reads 7. Where the two
 * disagree, PHP is the STRICTER one, which refuses a legitimate-looking row rather than admitting a
 * foreign one -- the safe direction for an ownership check.
 */
$out("' 7' parses to 7 on BOTH sides, so admitting it is agreement, not laxity",
    $phpAnswer(' 7', 7) && in_array(7, $mysqlMatches, true));
$out("'07' is refused by PHP although MySQL reads a 7 in it -- stricter, in the safe direction",
    ! $phpAnswer('07', 7) && in_array(8, $mysqlMatches, true));

echo "\nSTEP 4 -- THE FIX: every value MySQL coerces to a different row is refused before the query.\n";

foreach ($engine as $value => $resolvesTo) {
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    $guardWould = ($filtered === false || $filtered <= 0) ? null : $filtered;

    // The requirement: EITHER the guard refuses outright, OR the row it looks up is exactly the row
    // the engine would have handed over. Anything else prices one balance and settles another.
    $sound = $guardWould === null || $guardWould === $resolvesTo;

    $out(sprintf('   %-12s guard=%-6s engine=%-6s', var_export($value, true),
            $guardWould === null ? 'DENY' : (string) $guardWould,
            $resolvesTo === null ? 'none' : (string) $resolvesTo),
        $sound,
        $sound ? '' : 'The guard would read row ' . var_export($guardWould, true)
            . ' while MySQL hands over row ' . var_export($resolvesTo, true) . '.');
}

echo "\nSTEP 5 -- the OLD predicate is shown to be unsound on the same table.\n";

// What the limb did before the fix: no lookup at all -- the payable was `$request->payment_amount`
// and `partial_payment_id` went straight to OrderPartialPayments::find().
$out("the OLD code looked up NOTHING: find('5abc') on MySQL is row 5, another tenant's balance",
    $engine['5abc'] === 5,
    'ManagePartialPayment() is `OrderPartialPayments::find($request_data[\'partial_payment_id\'])` '
    . 'with no store_id and no customer_id predicate, on a model in no scoped_models entry.');

$out('is_numeric would have let the same string through to the engine', ! is_numeric('5abc') && $engine['5abc'] === 5,
    "is_numeric('5abc') is false, so a guard written with it SKIPS the value rather than refusing "
    . 'the request -- which is how the row reached the query unchecked in the first place.');

echo "\nSTEP 6 -- the SHIPPED trait agrees, called directly against this MySQL table.\n";

$shipped = new class {
    use App\Http\Controllers\Concerns\ResolvesPendingPartialPayment;

    public function row($id, $store, $customer)
    {
        return $this->pendingPartialPaymentRow($id, $store, $customer);
    }

    public function payable($id, $store, $customer): ?float
    {
        return $this->pendingPartialPaymentPayable($id, $store, $customer);
    }
};

// The connection STEP 3 pointed at this throwaway database is still the default.
$out("trait: row 5 is refused for customer 9 of store 1 (it is customer 7's, in store 2)",
    $shipped->row(5, 1, 9) === null);
$out("trait: row 5 is refused for customer 7 of store 1 (right customer, wrong store)",
    $shipped->row(5, 1, 7) === null);
$out("trait: row 5 is refused for customer 9 of store 2 (right store, wrong customer)",
    $shipped->row(5, 2, 9) === null);
$out('trait: row 5 IS resolved for customer 7 of store 2, and the payable is 40.00',
    $shipped->payable(5, 2, 7) === 40.00,
    'Actual: ' . var_export($shipped->payable(5, 2, 7), true));
$out("trait: '5abc' is refused outright, so MySQL never coerces it",
    $shipped->row('5abc', 2, 7) === null,
    'This is the assertion the surviving mutant could not make on SQLite.');

$pdo->exec("UPDATE order_partial_payments SET payment_status = 'On Hold' WHERE id = 5");
$out('trait: a settled balance is refused', $shipped->row(5, 2, 7) === null);

echo "\nSTEP 7 -- the throwaway database is dropped.\n";
Illuminate\Support\Facades\DB::disconnect();
$pdo->exec("DROP DATABASE `{$database}`");
$out("database `{$database}` dropped", true);

echo "\n" . ($failed ? "RESULT: FAILED -- at least one assertion did not hold.\n" : "RESULT: every assertion held.\n");

exit($failed ? 1 : 0);
