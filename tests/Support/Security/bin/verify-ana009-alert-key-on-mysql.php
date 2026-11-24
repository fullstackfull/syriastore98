#!/usr/bin/env php
<?php
/**
 * ANA-009 -- the alert's deduplication key, on a REAL MySQL/MariaDB server.
 *
 * WHY THIS CANNOT BE A TEST. The Feature suite runs on SQLite `:memory:` and
 * `tests/Support/TestDatabaseGuard.php` aborts any PHPUnit run whose connection is not sqlite. The
 * 13 tests in `MetricThresholdAlertTest` already drive the command end to end, so this script is
 * not here to repeat them. It is here for the one question SQLite CANNOT answer, and that question
 * was a live defect in this feature's first version, found by review rather than by the suite:
 *
 *   `store_notifications.reference_id` IS AN `unsignedBigInteger`, AND THE FIRST VERSION OF THIS
 *   FEATURE WROTE A STRING INTO IT -- `payments_received:2026-06-15`, the dedup identity in one
 *   readable piece.
 *
 *   - SQLite's INTEGER affinity stores a non-numeric TEXT value VERBATIM. Every test passed.
 *   - MySQL with `strict => false` (config/database.php's `mysql` connection) COERCES it to 0 with
 *     a warning. So the first alert a store ever raised would carry reference_id 0, and the dedup
 *     read -- comparing a bigint column against a non-numeric string, which is also 0 -- would
 *     match it for every later metric and every later day. ONE NOTICE PER STORE, EVER.
 *   - MariaDB with `strict => true` (the `mariadb` connection) raises 1366 Incorrect integer value.
 *     `metricThresholdBreached()` catches it and returns null, so NO alert is ever written.
 *
 * This is the P01-SEC-13 shape exactly (`customers.password_otp` INTEGER receiving a 60-char bcrypt
 * hash, which survived 9,000+ tests for the same reason), and CLAUDE.md names it as why
 * schema-sensitive work needs a real server.
 *
 * WHAT IS PROVED HERE, against the real engine:
 *   1. the declared column types are what the migration says (premise -- without it the rest is
 *      about some other table);
 *   2. the OLD shape really does fail, in both of the two ways, so the defect is not hypothetical;
 *   3. the NEW shape round-trips: `reference_type` holds `metric_threshold:<metric>` and
 *      `reference_id` holds the window's end day as `Ymd`;
 *   4. the dedup predicate SELECTS the row it wrote, which the string form did not do reliably;
 *   5. two metrics in one window, and one metric in two windows, are FOUR distinct keys -- the
 *      property the suite's mutation run showed was untested until two cases were added for it;
 *   6. `reference_type` fits its 50 characters for every metric in `MetricThreshold::METRICS`.
 *
 * SAFETY: creates and drops its own database, and refuses a name without 'verify' in it. Never
 * point this at a development or production server. Exit 0 means every assertion held.
 *
 * USAGE: ANA009_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock tests/Support/Security/bin/verify-ana009-alert-key-on-mysql.php
 *        (or through tests/Support/Security/bin/run-mysql-verifications.sh, which registers it)
 */

$sock = getenv('ANA009_MYSQL_SOCKET') ?: '/var/run/mysqld/mysqld.sock';
$user = getenv('ANA009_MYSQL_USERNAME') ?: 'root';
$pass = getenv('ANA009_MYSQL_PASSWORD') ?: '';
$db = 'verify_ana009_alert_key';

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
$pdo->exec('CREATE DATABASE `'.$db.'`');
$pdo->exec('USE `'.$db.'`');

/*
 * The table as `2026_08_03_123739_create_store_notifications_table.php` declares it -- only the
 * columns this script is about, spelled with the SAME types, including the composite index the
 * dedup read is meant to use. Nothing here invents a shape: `reference_type` is `string(50)` and
 * `reference_id` is `unsignedBigInteger()->nullable()` in that migration, which is the only
 * migration for this table.
 */
$pdo->exec(<<<'SQL'
CREATE TABLE store_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    type VARCHAR(50) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    push_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX store_notifications_reference_type_reference_id_index (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

echo "server: ".$pdo->query('SELECT VERSION()')->fetchColumn()."\n\n";

echo "1. the premise -- the columns are the types the migration declares\n";

$columns = [];
foreach ($pdo->query("SHOW COLUMNS FROM store_notifications")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $columns[$row['Field']] = strtolower((string) $row['Type']);
}

check('reference_type is varchar(50)', 'varchar(50)', $columns['reference_type'] ?? null);
check('reference_id is an unsigned bigint', 'bigint(20) unsigned',
    str_replace('bigint unsigned', 'bigint(20) unsigned', $columns['reference_id'] ?? ''));

/* A row to hang the rest on; only the columns under test carry meaning. */
$insert = static function (PDO $pdo, string $referenceType, $referenceId): void {
    $stmt = $pdo->prepare(
        'INSERT INTO store_notifications (store_id, user_id, type, reference_type, reference_id, title, message)'
        .' VALUES (7, 3, :t, :rt, :ri, :title, :msg)'
    );
    $stmt->execute([
        ':t' => 'metric_threshold_breached',
        ':rt' => $referenceType,
        ':ri' => $referenceId,
        ':title' => 'Payments received is below the level you set',
        ':msg' => 'Payments received over the last 7 days is 100.00 USD, and the level you set is 500.00 USD.',
    ]);
};

echo "\n2. THE OLD SHAPE -- what the first version of this feature wrote\n";

/*
 * STRICT FIRST, because it is the loud failure and it must be shown to be loud. `config/database.php`
 * gives the `mariadb` connection `strict => true`, which Laravel renders as this sql_mode.
 */
$pdo->exec("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

$strictRejected = false;
$strictCode = null;
try {
    $insert($pdo, 'metric_threshold', 'payments_received:2026-06-15');
} catch (PDOException $e) {
    $strictRejected = true;
    $strictCode = (int) ($e->errorInfo[1] ?? 0);
}

check('strict mode REFUSES the string in reference_id', true, $strictRejected);
check('and the error is 1366 Incorrect integer value', 1366, $strictCode);

/*
 * NON-STRICT SECOND, which is the dangerous one because it SUCCEEDS. `config/database.php`'s
 * `mysql` connection is `strict => false`.
 */
$pdo->exec("SET SESSION sql_mode=''");

$insert($pdo, 'metric_threshold', 'payments_received:2026-06-15');
$coerced = $pdo->query('SELECT reference_id FROM store_notifications ORDER BY id DESC LIMIT 1')->fetchColumn();

check('non-strict mode SILENTLY stores 0 instead', '0', (string) $coerced);

/* The consequence: a DIFFERENT period's string also compares equal to that row. */
$stmt = $pdo->prepare('SELECT COUNT(*) FROM store_notifications WHERE reference_type = :rt AND reference_id = :ri');
$stmt->execute([':rt' => 'metric_threshold', ':ri' => 'order_value:2027-01-01']);

check('so a different metric AND a different day match the same row -- one notice per store, ever',
    '1', (string) $stmt->fetchColumn());

$pdo->exec('TRUNCATE TABLE store_notifications');

echo "\n3. THE SHIPPED SHAPE -- the metric in the string column, the day in the integer one\n";

/* Strict mode stays on for the rest: the shipped shape must satisfy the STRICTER engine. */
$pdo->exec("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

$accepted = true;
try {
    $insert($pdo, 'metric_threshold:payments_received', 20260615);
} catch (PDOException $e) {
    $accepted = false;
    fwrite(STDERR, 'the shipped shape was refused: '.$e->getMessage()."\n");
}

check('strict mode ACCEPTS the shipped shape', true, $accepted);

$row = $pdo->query('SELECT reference_type, reference_id FROM store_notifications ORDER BY id DESC LIMIT 1')
    ->fetch(PDO::FETCH_ASSOC);

check('reference_type round-trips whole', 'metric_threshold:payments_received', $row['reference_type']);
check('reference_id round-trips as the window end', '20260615', (string) $row['reference_id']);

echo "\n4. the dedup predicate selects the row it wrote\n";

$stmt = $pdo->prepare('SELECT COUNT(*) FROM store_notifications'
    .' WHERE store_id = 7 AND type = :t AND reference_type = :rt AND reference_id = :ri');
$stmt->execute([
    ':t' => 'metric_threshold_breached',
    ':rt' => 'metric_threshold:payments_received',
    ':ri' => 20260615,
]);

check('the second tick of the same day finds the first tick\'s notice', '1', (string) $stmt->fetchColumn());

echo "\n5. four distinct keys -- two metrics across two days\n";

$insert($pdo, 'metric_threshold:order_value', 20260615);
$insert($pdo, 'metric_threshold:payments_received', 20260616);
$insert($pdo, 'metric_threshold:order_value', 20260616);

check('four rows, four keys', '4', (string) $pdo->query(
    'SELECT COUNT(DISTINCT reference_type, reference_id) FROM store_notifications')->fetchColumn());

foreach ([
    ['metric_threshold:payments_received', 20260615],
    ['metric_threshold:order_value', 20260615],
    ['metric_threshold:payments_received', 20260616],
    ['metric_threshold:order_value', 20260616],
] as [$type, $day]) {
    $stmt->execute([':t' => 'metric_threshold_breached', ':rt' => $type, ':ri' => $day]);
    check('exactly one notice for '.$type.' on '.$day, '1', (string) $stmt->fetchColumn());
}

echo "\n6. every metric's reference_type fits the column\n";

/*
 * Read out of the source rather than restated, so a metric added to `MetricThreshold::METRICS`
 * without widening the column is caught here as well as by the suite's own ratchet.
 */
$source = (string) file_get_contents(__DIR__.'/../../../../app/Support/Analytics/MetricThreshold.php');

preg_match("/REFERENCE_PREFIX = '([^']+)'/", $source, $prefixMatch);
preg_match_all("/^\s{8}'([a-z_]+)' => \[$/m", $source, $keyMatch);

check('the prefix was read from the source', true, ($prefixMatch[1] ?? '') !== '');
check('at least two metric keys were read', true, count($keyMatch[1] ?? []) >= 2);

foreach ($keyMatch[1] as $key) {
    $reference = $prefixMatch[1].$key;

    /*
     * WRITTEN AND READ BACK rather than measured with strlen(), because truncation is the engine's
     * behaviour and not PHP's: a 51-character value is cut to 50 in non-strict mode and refused in
     * strict mode, and only the server can say which.
     */
    $insert($pdo, $reference, 20260617);
    $stored = $pdo->query('SELECT reference_type FROM store_notifications ORDER BY id DESC LIMIT 1')->fetchColumn();

    check('reference_type for `'.$key.'` is stored whole ('.strlen($reference).' chars)',
        $reference, (string) $stored);
}

$pdo->exec('DROP DATABASE `'.$db.'`');

printf("\n%d checks, %d failed\n", $checks, $failed);

exit($failed === 0 ? 0 : 1);
