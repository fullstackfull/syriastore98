#!/usr/bin/env php
<?php
/**
 * GAP-257 -- does the P4-F124 guard's TERMINAL STATE actually hold on a real MySQL/MariaDB?
 *
 * WHY THIS CANNOT BE A TEST, and it is not the usual reason. The Feature suite runs on SQLite
 * `:memory:` and `tests/Support/TestDatabaseGuard.php` aborts any PHPUnit run whose connection is
 * not sqlite. `CancellationLedgerSemanticsTest` already has a premise case --
 * `test_premise_the_ledger_accepts_cancelled_and_refunded` -- and on SQLite that case is close to
 * VACUOUS: SQLite has no ENUM type at all, so a `enum(...)` column is created with TEXT affinity
 * and accepts literally any string. The case therefore passes whatever the real column would do.
 *
 * WHAT THE GUARD ACTUALLY DEPENDS ON. `OrderFinancialService::syncFromOrder()` refuses to credit a
 * reversed order with a STRICT string comparison:
 *
 *     in_array((string) $financial->payment_status, [REASON_CANCELLED, REASON_REFUNDED], true)
 *
 * and `OrderEntitlementReversal::reverse()` uses the same list for its idempotency check. Both are
 * `=== 'cancelled'` / `=== 'refunded'` in effect. So the guard holds only if the column really
 * returns those two byte strings. Three MySQL-only ways it might not, measured here:
 *
 *   1. THE MEMBERS MIGHT NOT BE IN THE ENUM. `store_order_financials` is a GATE F table, and GATE
 *      F promotion is "a no-op against production by design" -- it aligns FRESH environments and
 *      reconciles nothing live. If a live column predates the two terminal members, every write of
 *      them fails or blanks, and the guard is inert exactly where the money is.
 *   2. AN OUT-OF-ENUM VALUE BEHAVES DIFFERENTLY BY `sql_mode`. Under STRICT_TRANS_TABLES the INSERT
 *      ERRORS, which is the safe answer. Without it MySQL stores the EMPTY STRING and warns -- and
 *      `in_array('', ['cancelled','refunded'], true)` is FALSE, so a row that was MEANT to be
 *      terminal reads as non-terminal and a late `Paid` credits the merchant. That is GAP-257's
 *      case (D) arriving by a second route, and SQLite can express neither behaviour.
 *   3. CASE. If a row ever held `'Cancelled'`, the strict comparison would miss it. MySQL's ENUM
 *      normalises an inserted value to the DECLARED member's spelling, so it cannot -- while
 *      SQLite's TEXT affinity would store the mixed case verbatim and bypass the guard. This is
 *      the one direction where the suite is PESSIMISTIC rather than blind, and it is worth
 *      recording so nobody "fixes" the comparison to be case-insensitive on the strength of a
 *      SQLite reading.
 *
 * WHAT A PASS HERE MEANS, stated so it cannot be over-read: the COLUMN AS THE MIGRATION DECLARES IT
 * supports the guard on this engine, and this server's `sql_mode` makes an out-of-enum write fail
 * loudly. It says NOTHING about production's own column -- the migrations are not the schema, and
 * only `SHOW CREATE TABLE store_order_financials` on the live server closes that. Check 1 below is
 * written as the query an operator can run there, and its expected value is printed either way.
 *
 * THIS SCRIPT MOVES NO MONEY AND CHANGES NO BEHAVIOUR. It builds the column, writes to it, and
 * drops its own database.
 *
 * SAFETY: creates and drops its own database, and refuses a name without 'verify' in it. Never
 * point this at a development or production server. Exit 0 means every assertion held.
 *
 * USAGE: GAP257_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *          tests/Support/Security/bin/verify-settled-order-guard-enum-on-mysql.php
 *        (or through tests/Support/Security/bin/run-mysql-verifications.sh, which registers it)
 */

$sock = getenv('GAP257_MYSQL_SOCKET') ?: '/var/run/mysqld/mysqld.sock';
$user = getenv('GAP257_MYSQL_USERNAME') ?: 'root';
$pass = getenv('GAP257_MYSQL_PASSWORD') ?: '';
$db = 'verify_gap257_settled_guard';

if (! str_contains($db, 'verify')) {
    fwrite(STDERR, "refusing a database name without 'verify' in it\n");
    exit(2);
}

try {
    $pdo = new PDO('mysql:unix_socket='.$sock, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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

/*
 * THE TWO STRINGS THE GUARD COMPARES AGAINST, copied from the constants rather than retyped, so a
 * rename in the service makes this script disagree instead of silently measuring stale values.
 */
$reversal = file_get_contents(__DIR__.'/../../../../app/Services/OrderEntitlementReversal.php');
preg_match("/REASON_CANCELLED = '([^']+)'/", (string) $reversal, $mc);
preg_match("/REASON_REFUNDED = '([^']+)'/", (string) $reversal, $mr);
$CANCELLED = $mc[1] ?? null;
$REFUNDED = $mr[1] ?? null;

/*
 * AND THE ENUM MEMBERS THE MIGRATION DECLARES, read from the migration for the same reason.
 */
$migration = file_get_contents(__DIR__.'/../../../../database/migrations/2026_11_02_000400_create_store_order_financials_table.php');
preg_match("/enum\('payment_status', \[([^\]]+)\]\)/", (string) $migration, $me);
$members = array_map(
    static fn ($m) => trim($m, " '"),
    explode(',', $me[1] ?? '')
);

echo "\nTHE PREMISE: the strings under test come from the code, not from this file\n";

check('OrderEntitlementReversal::REASON_CANCELLED still reads', 'cancelled', $CANCELLED);
check('OrderEntitlementReversal::REASON_REFUNDED still reads', 'refunded', $REFUNDED);
check('the migration still declares six members', 6, count($members));
check('and both terminal states are among them', true,
    in_array($CANCELLED, $members, true) && in_array($REFUNDED, $members, true));

$pdo->exec('DROP DATABASE IF EXISTS `'.$db.'`');
$pdo->exec('CREATE DATABASE `'.$db.'` DEFAULT CHARACTER SET utf8mb4');
$pdo->exec('USE `'.$db.'`');

/*
 * The column exactly as the migration declares it, with no foreign keys: this script is about the
 * one column, and the parents are irrelevant to the question.
 */
$pdo->exec(
    'CREATE TABLE store_order_financials ('
    .' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
    .' order_id BIGINT UNSIGNED NOT NULL,'
    .' customer_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,'
    ." payment_status ENUM('".implode("','", $members)."') NOT NULL DEFAULT 'unpaid'"
    .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

echo "\n1. THE COLUMN AS DECLARED HOLDS BOTH TERMINAL STATES\n";
echo "   (the operator's own query for production is:\n";
echo "      SHOW CREATE TABLE store_order_financials;\n";
echo "    and the payment_status member list must contain 'cancelled' and 'refunded')\n";

$ins = $pdo->prepare('INSERT INTO store_order_financials (order_id, payment_status) VALUES (?, ?)');
$read = $pdo->prepare('SELECT payment_status FROM store_order_financials WHERE order_id = ?');

foreach ([$CANCELLED => 101, $REFUNDED => 102] as $state => $orderId) {
    $ins->execute([$orderId, $state]);
    $read->execute([$orderId]);
    $stored = (string) $read->fetch(PDO::FETCH_ASSOC)['payment_status'];

    check("'".$state."' round-trips byte-for-byte, so the guard's === sees it", $state, $stored);
    check("and in_array(..., true) answers true for it, which IS the guard", true,
        in_array($stored, [$CANCELLED, $REFUNDED], true));
}

echo "\n2. AN OUT-OF-ENUM WRITE: does it FAIL, or does it blank the row and disarm the guard?\n";

$mode = (string) $pdo->query('SELECT @@sql_mode')->fetchColumn();
$strict = str_contains($mode, 'STRICT_TRANS_TABLES') || str_contains($mode, 'STRICT_ALL_TABLES');

printf("   server: %s\n   sql_mode: %s\n   strict: %s\n",
    (string) $pdo->query('SELECT VERSION()')->fetchColumn(), $mode, $strict ? 'yes' : 'NO');

$threw = false;
try {
    $ins->execute([103, 'reversed_by_hand']);
} catch (Throwable $e) {
    $threw = true;
}

if ($strict) {
    check('under a STRICT sql_mode an out-of-enum write ERRORS, which is the safe answer -- a '
        .'reversal that cannot be recorded is not silently recorded as non-terminal', true, $threw);
} else {
    /*
     * THE DANGEROUS CONFIGURATION, measured rather than described. Without strict mode MySQL
     * stores '' for an out-of-enum value, and '' is not in the guard's list -- so the row reads
     * NON-TERMINAL and a late `Paid` credits the merchant. This arm exists so that running this
     * script on a non-strict server produces the finding instead of a pass.
     */
    $read->execute([103]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    $stored = $row === false ? null : (string) $row['payment_status'];

    printf("   stored instead: %s\n", var_export($stored, true));
    check('FINDING: on a NON-STRICT server an out-of-enum value is stored as the empty string, '
        .'which the P4-F124 guard reads as NON-TERMINAL -- so this server would credit a late '
        .'Paid on a reversed order. Set STRICT_TRANS_TABLES.', false, $stored === '');
}

echo "\n3. CASE: the ENUM normalises, so the guard's STRICT comparison cannot be slipped\n";
echo "   (SQLite's TEXT affinity would store the mixed case verbatim and MISS it, so the suite is\n";
echo "    pessimistic here -- do not relax the comparison on the strength of a SQLite reading)\n";

$ins->execute([104, 'CANCELLED']);
$read->execute([104]);
$stored = (string) $read->fetch(PDO::FETCH_ASSOC)['payment_status'];

check("'CANCELLED' is stored as the declared member '".$CANCELLED."'", $CANCELLED, $stored);
check('so the guard still catches it with a strict comparison', true,
    in_array($stored, [$CANCELLED, $REFUNDED], true));

/*
 * AND A PADDED VALUE IS NOT NORMALISED -- IT IS REFUSED, which I assumed wrongly and the server
 * corrected. Under a strict sql_mode MariaDB raises 1265 "Data truncated for column
 * 'payment_status'" for `' refunded '`: the leading space makes it no member of the enum, and
 * unlike the case difference above there is no normalisation that rescues it. That is the SAFE
 * answer and it is recorded because the unsafe one is plausible: a stored `' refunded '` would be
 * read as NON-TERMINAL by the guard's strict comparison, and SQLite's TEXT affinity WOULD store it.
 */
$paddedRefused = false;
try {
    $ins->execute([105, ' refunded ']);
} catch (Throwable $e) {
    $paddedRefused = true;
}

if ($strict) {
    check("a padded ' refunded ' is REFUSED rather than stored as a third value the guard would "
        .'miss (MariaDB 1265)', true, $paddedRefused);
} else {
    $read->execute([105]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    $stored = $row === false ? null : (string) $row['payment_status'];

    printf("   stored instead: %s\n", var_export($stored, true));
    check("FINDING: on a NON-STRICT server a padded ' refunded ' is stored as the empty string, "
        .'which the guard reads as NON-TERMINAL. Set STRICT_TRANS_TABLES.', false, $stored === '');
}

echo "\n4. AND THE NON-TERMINAL STATES REALLY ARE NON-TERMINAL, so the guard is not vacuous\n";

foreach (['unpaid' => 106, 'paid' => 107, 'pending' => 108, 'partially_paid' => 109] as $state => $orderId) {
    $ins->execute([$orderId, $state]);
    $read->execute([$orderId]);
    $stored = (string) $read->fetch(PDO::FETCH_ASSOC)['payment_status'];

    check("'".$state."' is NOT read as terminal, which is what makes GAP-257's cases (C) and (D) "
        .'reachable', false, in_array($stored, [$CANCELLED, $REFUNDED], true));
}

echo "\n5. THE CREDITED FIGURE'S COLUMN KEEPS ITS SCALE, so the measured 100.00 is not a SQLite artefact\n";

$pdo->exec("INSERT INTO store_order_financials (order_id, payment_status, customer_total)"
    ." VALUES (110, 'paid', 2500000.49)");
$pdo->exec("INSERT INTO store_order_financials (order_id, payment_status, customer_total)"
    ." VALUES (111, 'paid', 0.01)");

$tot = $pdo->query('SELECT customer_total FROM store_order_financials WHERE order_id = 110')->fetchColumn();
check('customer_total DECIMAL(15,2) round-trips 2500000.49 exactly', '2500000.49', (string) $tot);

$cent = $pdo->query('SELECT customer_total FROM store_order_financials WHERE order_id = 111')->fetchColumn();
check('and a single cent survives', '0.01', (string) $cent);

$sum = $pdo->query('SELECT SUM(customer_total) AS s FROM store_order_financials')->fetchColumn();
check('SUM() over the column is exact to the cent, and returns a STRING on this engine',
    '2500000.50', (string) $sum);

$pdo->exec('DROP DATABASE `'.$db.'`');

printf("\n%d checks, %d failed\n", $checks, $failed);
exit($failed === 0 ? 0 : 1);
