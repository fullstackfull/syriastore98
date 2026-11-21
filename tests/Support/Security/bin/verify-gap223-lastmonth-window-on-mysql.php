#!/usr/bin/env php
<?php
/**
 * GAP-223(a) -- the last-month window, selecting REAL ROWS on a REAL ENGINE, on an overflow day.
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST. Two independent reasons, and both matter:
 *
 *  1. THE BUG NEEDS A PARTICULAR DAY. It appears only when today's day-of-month does not exist in
 *     the previous month -- 33 days in every five years. `Carbon::setTestNow()` cannot help, because
 *     it moves `Carbon::now()` and NOT `date()` or `strtotime()`, which is what these handlers use.
 *     `CalendarPeriod` now takes an injectable timestamp, so `CalendarPeriodTest` asserts the
 *     DERIVATION on all 1,827 days inside the ordinary Unit suite. What it cannot do is prove the
 *     derived bounds select the intended ROWS.
 *  2. THE HANDLERS CANNOT RUN UNDER THE SUITE. `ReportController`'s chart handlers use `MONTH(` and
 *     `YEAR(` in `selectRaw`, which SQLite does not implement, so a Feature test against them
 *     answers HTTP 500. That is how a 33-day label/figure disagreement survived 9,112 tests.
 *
 * So this script does the half the Unit test cannot: it seeds orders either side of the boundary and
 * runs the SAME `whereDate` shape the handlers run, with the old and new bounds, on MariaDB -- and
 * shows the old bounds returning MARCH's orders under a card labelled February.
 *
 * SAFETY: creates and drops its own database, and refuses a name without 'verify' in it. Never point
 * this at a development or production server. Exit 0 means every assertion held.
 *
 * USAGE: GAP223_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock tests/Support/Security/bin/verify-gap223-lastmonth-window-on-mysql.php
 *        (or through tests/Support/Security/bin/run-mysql-verifications.sh, which registers it)
 */

require __DIR__.'/../../../../vendor/autoload.php';

use App\Support\Reports\CalendarPeriod;

$sock = getenv('GAP223_MYSQL_SOCKET') ?: '/var/run/mysqld/mysqld.sock';
$user = getenv('GAP223_MYSQL_USERNAME') ?: 'root';
$pass = getenv('GAP223_MYSQL_PASSWORD') ?: '';
$db = 'verify_gap223_lastmonth';

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

$pdo->exec('DROP DATABASE IF EXISTS `'.$db.'`');
$pdo->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `'.$db.'`');

$pdo->exec(
    'CREATE TABLE orders ('
    .' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
    .' store_id BIGINT UNSIGNED NOT NULL,'
    .' order_date DATE NULL,'
    .' total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00'
    .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

/*
 * THE SCENARIO. "Today" is 31 March 2026 -- an overflow day, because February has 28 days in 2026.
 * A merchant opens the sales card and picks "Last month". The label says February 2026.
 */
$today = mktime(12, 0, 0, 3, 31, 2026);

$seed = [
    // [date, amount, which month it belongs to]
    ['2026-02-01', '100.00', 'February, first day'],
    ['2026-02-14', '200.00', 'February, middle'],
    ['2026-02-28', '400.00', 'February, LAST day -- the inclusive upper bound'],
    ['2026-03-01', '1000.00', 'March, first day -- must NOT be counted'],
    ['2026-03-31', '2000.00', 'March, today -- must NOT be counted'],
    ['2026-01-31', '8000.00', 'January -- outside on the lower side'],
];

$insert = $pdo->prepare('INSERT INTO orders (store_id, order_date, total_amount) VALUES (7, ?, ?)');
foreach ($seed as [$date, $amount, $_]) {
    $insert->execute([$date, $amount]);
}
// A second tenant with the same dates, to prove the bounds are not the only predicate that matters.
$insert2 = $pdo->prepare('INSERT INTO orders (store_id, order_date, total_amount) VALUES (8, ?, ?)');
foreach ($seed as [$date, $amount, $_]) {
    $insert2->execute([$date, '77777.00']);
}

/** The `whereDate(order_date, '>=', from)->whereDate(order_date, '<=', to)` shape the handlers run. */
$sum = static function (PDO $pdo, string $from, string $to, int $storeId): string {
    $q = $pdo->prepare(
        'SELECT COALESCE(SUM(total_amount), 0) AS t, COUNT(*) AS n FROM orders'
        .' WHERE store_id = ? AND DATE(order_date) >= ? AND DATE(order_date) <= ?'
    );
    $q->execute([$storeId, $from, $to]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    return $row['t'].'/'.$row['n'];
};

echo "\nTODAY IS 31 MARCH 2026, AN OVERFLOW DAY. THE CARD'S LABEL SAYS:\n";

$label = CalendarPeriod::lastMonthLabel($today);
check('the label names February 2026', 'February 2026', $label);

echo "\nTHE OLD EXPRESSION -- what the handlers computed before the fix\n";

$oldBase = strtotime('-1 month', $today);
$oldFrom = date('Y-m-01', $oldBase);
$oldTo = date('Y-m-t', $oldBase);

check('`-1 month` from 31 March lands on 3 March', '2026-03-03', date('Y-m-d', $oldBase));
check('so the window is the WHOLE CURRENT MONTH', ['2026-03-01', '2026-03-31'], [$oldFrom, $oldTo]);
check('and it selects MARCH\'s two orders under a February label', '3000.00/2', $sum($pdo, $oldFrom, $oldTo, 7));
check('February\'s three orders are entirely absent from the figure', '700.00/3',
    $sum($pdo, '2026-02-01', '2026-02-28', 7));

echo "\nTHE FIXED EXPRESSION -- CalendarPeriod::lastMonth()\n";

$new = CalendarPeriod::lastMonth($today);

check('the window is February 2026', ['2026-02-01', '2026-02-28'], [$new['from'], $new['to']]);
check('its day count is 28, so a 28-cell chart series matches a 28-day axis', 28, $new['days']);
check('and it selects FEBRUARY\'s three orders, which is what the label promised',
    '700.00/3', $sum($pdo, $new['from'], $new['to'], 7));
check('March\'s orders are excluded', true,
    $sum($pdo, $new['from'], $new['to'], 7) !== $sum($pdo, $oldFrom, $oldTo, 7));

echo "\nTHE BOUNDS ARE INCLUSIVE AT BOTH ENDS, which is what a whole calendar month means\n";

$justFirst = $sum($pdo, '2026-02-01', '2026-02-01', 7);
$justLast = $sum($pdo, '2026-02-28', '2026-02-28', 7);
check('1 February is inside the window', '100.00/1', $justFirst);
check('28 February is inside it too -- an exclusive upper bound would drop the month\'s last day',
    '400.00/1', $justLast);

echo "\nTHE WINDOW IS NOT A TENANT PREDICATE\n";

check('store 8 has its own rows in the same window, and they are a different figure',
    '233331.00/3', $sum($pdo, $new['from'], $new['to'], 8));
check('so the bounds alone would have reported both tenants together', '234031.00/6', (function () use ($pdo, $new) {
    $q = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) AS t, COUNT(*) AS n FROM orders WHERE DATE(order_date) >= ? AND DATE(order_date) <= ?');
    $q->execute([$new['from'], $new['to']]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    return $row['t'].'/'.$row['n'];
})());

echo "\nA NON-OVERFLOW DAY IS UNCHANGED BY THE FIX, which is what keeps it bounded\n";

$ordinary = mktime(12, 0, 0, 3, 15, 2026);
$oldOrdinary = strtotime('-1 month', $ordinary);
check('15 March: the old expression was already right',
    ['2026-02-01', '2026-02-28'], [date('Y-m-01', $oldOrdinary), date('Y-m-t', $oldOrdinary)]);
check('and the fix agrees with it exactly',
    [date('Y-m-01', $oldOrdinary), date('Y-m-t', $oldOrdinary)],
    [CalendarPeriod::lastMonth($ordinary)['from'], CalendarPeriod::lastMonth($ordinary)['to']]);

echo "\nEVERY OVERFLOW DAY OF 2026, AGAINST REAL ROWS\n";

$overflowDays = 0;
$wrongMonth = 0;
for ($t = mktime(12, 0, 0, 1, 1, 2026); $t <= mktime(12, 0, 0, 12, 31, 2026); $t = strtotime('+1 day', $t)) {
    $old = strtotime('-1 month', $t);
    $fixed = CalendarPeriod::lastMonth($t);

    if (date('Y-m', $old) === substr($fixed['from'], 0, 7)) {
        continue;
    }

    $overflowDays++;

    // The label and the fixed window must agree; the old one must not.
    if (CalendarPeriod::lastMonthLabel($t) !== date('F Y', strtotime($fixed['from']))) {
        $wrongMonth++;
    }
}
check('2026 supplies this many overflow days', 7, $overflowDays);
check('and on none of them does the fixed window disagree with its own label', 0, $wrongMonth);

$pdo->exec('DROP DATABASE `'.$db.'`');

printf("\n%d checks, %d failed\n", $checks, $failed);
exit($failed === 0 ? 0 : 1);
