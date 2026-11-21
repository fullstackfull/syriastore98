#!/usr/bin/env php
<?php
/**
 * GAP-222 -- the wallet currency presentation, on a REAL MySQL/MariaDB server.
 *
 * WHY THIS CANNOT BE A TEST. The Feature suite runs on SQLite `:memory:` and
 * `tests/Support/TestDatabaseGuard.php` aborts any PHPUnit run whose connection is not sqlite. The
 * 50 tests in `FinanceLedgerFiguresTest` already drive the rendered page, so this script is not
 * there to repeat them -- it is there for the ONE question SQLite cannot answer:
 *
 *   `store_wallets.currency_code` IS A VARCHAR, AND THE TWO ENGINES GROUP IT DIFFERENTLY.
 *
 *   - MySQL's default collation (utf8mb4_general_ci / utf8mb4_0900_ai_ci) is case-INSENSITIVE and
 *     PAD SPACE. So `GROUP BY currency_code` puts 'SYP', 'syp' and 'SYP  ' in ONE bucket.
 *   - SQLite's default is BINARY and does not pad. The same three values are THREE buckets.
 *
 * That difference decides whether a figure is shown or REFUSED. `WalletEntitlementFigure` refuses a
 * total and lists the amounts when it sees more than one currency bucket. On SQLite a store whose
 * two wallet rows say 'SYP' and 'syp' looks mixed-currency and the merchant is shown a refusal; on
 * MySQL they are one currency and a total is correct. `MoneyText::buckets()` normalises with
 * `strtoupper(trim())` AFTER the engine has grouped, which is what makes the two engines agree --
 * and that normalisation is the thing worth proving against the engine that actually ships.
 *
 * The second MySQL-only fact: `SUM()` over `DECIMAL(15,2)` returns a STRING here and a float on
 * SQLite, so every amount is cast at the boundary. A missed cast is invisible under the suite.
 *
 * SAFETY: creates and drops its own database, and refuses a name without 'verify' in it. Never
 * point this at a development or production server. Exit 0 means every assertion held.
 *
 * USAGE: GAP222_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock tests/Support/Security/bin/verify-gap222-wallet-currency-on-mysql.php
 *        (or through tests/Support/Security/bin/run-mysql-verifications.sh, which registers it)
 */

$sock = getenv('GAP222_MYSQL_SOCKET') ?: '/var/run/mysqld/mysqld.sock';
$user = getenv('GAP222_MYSQL_USERNAME') ?: 'root';
$pass = getenv('GAP222_MYSQL_PASSWORD') ?: '';
$db = 'verify_gap222_wallet_currency';

if (! str_contains($db, 'verify')) {
    fwrite(STDERR, "refusing a database name without 'verify' in it\n");
    exit(2);
}

$dsnBase = 'mysql:unix_socket='.$sock;

try {
    $pdo = new PDO($dsnBase, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, 'no server on '.$sock.': '.$e->getMessage()."\n");
    exit(2);
}

$checks = 0;
$failed = 0;

function check(string $what, $expected, $actual): void
{
    global $checks, $failed;
    $checks++;
    $ok = $expected === $actual;
    if (! $ok) {
        $failed++;
    }
    printf("  %s  %s\n", $ok ? 'ok  ' : 'FAIL', $what);
    if (! $ok) {
        printf("        expected: %s\n        actual:   %s\n", var_export($expected, true), var_export($actual, true));
    }
}

$pdo->exec('DROP DATABASE IF EXISTS `'.$db.'`');
$pdo->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `'.$db.'`');

/* The shape measured in database/migrations/2026_11_02_000100_create_store_wallets_table.php. */
$pdo->exec(
    'CREATE TABLE store_wallets ('
    .' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
    .' store_id BIGINT UNSIGNED NOT NULL,'
    .' currency_code VARCHAR(10) NULL,'
    .' available_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,'
    .' pending_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,'
    .' withdrawal_pending_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,'
    .' total_income DECIMAL(15,2) NOT NULL DEFAULT 0.00,'
    .' total_withdrawn DECIMAL(15,2) NOT NULL DEFAULT 0.00'
    .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
);

echo "\nPREMISE -- the engine really is case-insensitive and PAD SPACE\n";

$pdo->exec("INSERT INTO store_wallets (store_id, currency_code, available_balance) VALUES (7, 'SYP', 10.00)");
$pdo->exec("INSERT INTO store_wallets (store_id, currency_code, available_balance) VALUES (7, 'syp', 20.00)");
$pdo->exec("INSERT INTO store_wallets (store_id, currency_code, available_balance) VALUES (7, 'SYP  ', 30.00)");

$buckets = $pdo->query(
    'SELECT currency_code, SUM(available_balance) AS amount, COUNT(*) AS n'
    .' FROM store_wallets WHERE store_id = 7 GROUP BY currency_code'
)->fetchAll(PDO::FETCH_ASSOC);

check('three spellings of SYP are ONE group on this engine (SQLite would say three)', 1, count($buckets));
check('and the group sums all three rows', '60.00', (string) $buckets[0]['amount']);
check('SUM() over DECIMAL(15,2) comes back as a STRING, so a cast is required', 'string', gettype($buckets[0]['amount']));

echo "\nTHE PRESENTER AGREES WITH THE ENGINE\n";

/* MoneyText::buckets() verbatim -- the normalisation under test. A copy, deliberately: this script
   must keep working if the class is moved, and its job is to prove the RULE, not to import it. */
$normalise = static function (array $byCurrency, bool $dropEmpty = true): array {
    $merged = [];
    foreach ($byCurrency as $currency => $amount) {
        $code = strtoupper(trim((string) $currency));
        $merged[$code] = round(($merged[$code] ?? 0.0) + (float) $amount, 2);
    }
    ksort($merged);

    return $dropEmpty
        ? array_filter($merged, static fn (float $a): bool => abs($a) >= 0.005)
        : $merged;
};

$raw = [];
foreach ($buckets as $row) {
    $raw[(string) $row['currency_code']] = $row['amount'];
}

$normalised = $normalise($raw, false);
check('the presenter keeps it one bucket', ['SYP' => 60.0], $normalised);

/* And the SQLite-shaped input -- three buckets, the shape the suite sees -- must normalise to the
   SAME one bucket, which is the whole point: the figure cannot depend on the engine. */
$asSqliteWouldGroupIt = ['SYP' => '10.00', 'syp' => '20.00', 'SYP  ' => '30.00'];
check('the SAME presenter collapses the SQLite grouping to the same answer',
    $normalise($asSqliteWouldGroupIt, false), $normalised);

echo "\nTWO GENUINELY DIFFERENT CURRENCIES STILL REFUSE A TOTAL\n";

$pdo->exec("INSERT INTO store_wallets (store_id, currency_code, available_balance) VALUES (8, 'SYP', 100.00)");
$pdo->exec("INSERT INTO store_wallets (store_id, currency_code, available_balance) VALUES (8, 'USD', 200.00)");

$mixed = [];
foreach ($pdo->query('SELECT currency_code, SUM(available_balance) AS amount FROM store_wallets WHERE store_id = 8 GROUP BY currency_code')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $mixed[(string) $row['currency_code']] = $row['amount'];
}
check('SYP and USD are two groups on this engine too', 2, count($mixed));
check('and the presenter keeps them apart, so the tile refuses a total',
    ['SYP' => 100.0, 'USD' => 200.0], $normalise($mixed, false));

echo "\nTHE ZERO TRAP, ON THE ENGINE THAT SHIPS\n";

$pdo->exec("INSERT INTO store_wallets (store_id, currency_code, available_balance) VALUES (9, 'SYP', 0.00)");
$empty = [];
foreach ($pdo->query('SELECT currency_code, SUM(available_balance) AS amount FROM store_wallets WHERE store_id = 9 GROUP BY currency_code')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $empty[(string) $row['currency_code']] = $row['amount'];
}
check('an empty wallet still has a currency row in the database', ['SYP' => '0.00'], $empty);
check('dropEmpty=false KEEPS it, so the tile can name SYP', ['SYP' => 0.0], $normalise($empty, false));
check('dropEmpty=true DROPS it -- which is why the wallet caller must pass false', [], $normalise($empty, true));

echo "\nA NULL CURRENCY IS A REAL ROW SHAPE (the column is nullable with no default)\n";

$pdo->exec('INSERT INTO store_wallets (store_id, available_balance) VALUES (10, 55.00)');
$nullRow = $pdo->query('SELECT currency_code, available_balance FROM store_wallets WHERE store_id = 10')->fetch(PDO::FETCH_ASSOC);
check('the row exists with a NULL currency', null, $nullRow['currency_code']);
check('and its balance is intact', '55.00', (string) $nullRow['available_balance']);
check('normalising a NULL key alone would spell it as the empty code, which is why the metric buckets it as UNKNOWN before the presenter sees it',
    ['' => 55.0], $normalise([null => '55.00'], false));

echo "\nTHE TENANT PREDICATE, AGAINST MySQL's LOOSER TYPE COERCION\n";

/* P1-INV-14: `WHERE id = '5abc'` returns row 5 on MySQL and no row on SQLite. The sibling read
   passes an `(int)`-cast store id, so prove the cast is what stands between the two behaviours. */
$coerced = $pdo->query("SELECT COUNT(*) AS n FROM store_wallets WHERE store_id = '7abc'")->fetch(PDO::FETCH_ASSOC);
check('MySQL coerces the string and WOULD match store 7 -- three rows', '3', (string) $coerced['n']);

$cast = $pdo->prepare('SELECT COUNT(*) AS n FROM store_wallets WHERE store_id = ?');
$cast->execute([(int) '7abc']);
check('and an (int) cast gives the same 7, so the handler is explicit rather than lucky',
    '3', (string) $cast->fetch(PDO::FETCH_ASSOC)['n']);

$cast->execute([8]);
check('store 8 reads only its own two rows', '2', (string) $cast->fetch(PDO::FETCH_ASSOC)['n']);

echo "\nSUM() ACROSS THE FOUR SIBLING COLUMNS KEEPS DECIMAL PRECISION\n";

$pdo->exec(
    'INSERT INTO store_wallets (store_id, currency_code, available_balance, pending_balance,'
    .' withdrawal_pending_balance, total_income, total_withdrawn)'
    ." VALUES (11, 'SYP', 0.01, 0.02, 0.03, 99999999999.99, 0.07)"
);
$sib = $pdo->query(
    'SELECT SUM(withdrawal_pending_balance) AS a, SUM(pending_balance) AS b,'
    .' SUM(total_income) AS c, SUM(total_withdrawn) AS d'
    .' FROM store_wallets WHERE store_id = 11'
)->fetch(PDO::FETCH_ASSOC);

check('a hundredth survives the sum', '0.03', (string) $sib['a']);
check('and so does the DECIMAL(15,2) ceiling', '99999999999.99', (string) $sib['c']);
check('number_format of that ceiling reads as money, not as scientific notation',
    '99,999,999,999.99', number_format((float) $sib['c'], 2));

$pdo->exec('DROP DATABASE `'.$db.'`');

printf("\n%d checks, %d failed\n", $checks, $failed);
exit($failed === 0 ? 0 : 1);
