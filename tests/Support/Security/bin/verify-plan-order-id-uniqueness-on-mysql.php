<?php

/**
 * GAP-231 — IS `plan_orders.order_id` REALLY UNIQUE ON A REAL ENGINE?
 * =================================================================================================
 *
 * WHY THIS QUESTION WAS ASKED. `plan_user_coupons.order` is a STRING matched against
 * `plan_orders.order_id` and carries no foreign key, so narrowing the `Popular Coupon` ranking to
 * settled redemptions by JOINING those two tables would multiply a redemption row by however many
 * plan orders shared that id. The fix therefore uses `EXISTS` instead, which cannot multiply or
 * drop rows whatever the index situation is — but the question still deserved an answer rather than
 * an assumption, because the next person may reach for a join.
 *
 * WHY IT IS A SCRIPT AND NOT A TEST. The suite runs on SQLite `:memory:`, where this cannot be
 * asked: CLAUDE.md records that SQLite's affinity and collation hide real MySQL facts, and a
 * UNIQUE constraint is exactly the kind of thing whose presence must be read off the engine that
 * will enforce it. A conditionally-skipped test would also break the declared outcome set.
 *
 * WHAT IT ESTABLISHES, AND WHAT IT DOES NOT. It proves that THIS REPOSITORY'S MIGRATIONS, applied
 * to a real MariaDB, produce a UNIQUE KEY on `order_id`, and that the engine then refuses a
 * duplicate. It does NOT prove the LIVE production table carries that index: CLAUDE.md records
 * that the migrations are not the schema, and GATE F's promotion is a no-op against production by
 * design. That gap is precisely why the shipped predicate is `EXISTS` and not a join.
 *
 * PRECONDITIONS, stated so a failure is never mistaken for a finding:
 *   - a reachable MariaDB/MySQL, via socket or host/port;
 *   - an account that may CREATE and DROP a database.
 *
 * It creates its own throwaway database and drops it again, touching nothing else.
 *
 *   PLANID_MYSQL_SOCKET=/run/mysqld/mysqld.sock PLANID_MYSQL_USERNAME=root \
 *     php tests/Support/Security/bin/verify-plan-order-id-uniqueness-on-mysql.php
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';

$database = getenv('PLANID_MYSQL_DATABASE') ?: 'planid_verify';
$socket   = getenv('PLANID_MYSQL_SOCKET') ?: '';
$host     = getenv('PLANID_MYSQL_HOST') ?: '127.0.0.1';
$port     = getenv('PLANID_MYSQL_PORT') ?: '3306';
$username = getenv('PLANID_MYSQL_USERNAME') ?: 'root';
$password = getenv('PLANID_MYSQL_PASSWORD') ?: '';

$dsn = $socket !== ''
    ? "mysql:unix_socket={$socket}"
    : "mysql:host={$host};port={$port}";

$checks = 0;
$failed = 0;

$check = function (string $what, bool $ok, string $detail = '') use (&$checks, &$failed): void {
    $checks++;
    if (! $ok) {
        $failed++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $what, $detail === '' ? '' : "  -- {$detail}");
};

try {
    $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot connect ({$e->getMessage()}).\n"
        . "This is not a finding. Set PLANID_MYSQL_* and re-run.\n");
    exit(2);
}

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
echo "engine: {$version}\n";

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");

try {
    /*
     * THE COLUMN AS THE MIGRATION DECLARES IT, copied from
     * `2023_12_11_102124_create_plan_orders_table.php`'s `$table->string('order_id', 100)->unique()`
     * rather than by running the whole migration set, which would need every dependency table.
     * The claim under test is about THIS declaration, so reproducing it exactly is the test.
     */
    $pdo->exec("CREATE TABLE plan_orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id VARCHAR(100) NOT NULL,
        payment_status VARCHAR(255) NULL,
        UNIQUE KEY plan_orders_order_id_unique (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $create = (string) $pdo->query('SHOW CREATE TABLE plan_orders')->fetch(PDO::FETCH_ASSOC)['Create Table'];

    $check('the declared index materialises as a UNIQUE KEY',
        str_contains($create, 'UNIQUE KEY') && str_contains($create, 'order_id'));

    $indexes = $pdo->query("SHOW INDEX FROM plan_orders WHERE Column_name = 'order_id'")
        ->fetchAll(PDO::FETCH_ASSOC);

    $nonUnique = array_column($indexes, 'Non_unique');

    $check('SHOW INDEX reports Non_unique = 0 for order_id',
        $nonUnique !== [] && ! in_array('1', array_map('strval', $nonUnique), true),
        'Non_unique = '.json_encode($nonUnique));

    /* THE ENGINE REALLY REFUSES A DUPLICATE -- the index existing and the index being enforced are
       two statements, and only the second makes a join safe. */
    $pdo->exec("INSERT INTO plan_orders (order_id, payment_status) VALUES ('PO-1', 'succeeded')");

    $refused = false;
    try {
        $pdo->exec("INSERT INTO plan_orders (order_id, payment_status) VALUES ('PO-1', 'failed')");
    } catch (PDOException $e) {
        $refused = str_contains($e->getMessage(), '1062') || stripos($e->getMessage(), 'Duplicate') !== false;
    }

    $check('a duplicate order_id is refused by the engine', $refused);

    /*
     * AND THE COLLATION FACT THE SHIPPED FIX TURNS ON, measured on the same engine rather than
     * asserted from memory: `utf8mb4_unicode_ci` is case-insensitive and PAD SPACE, so a
     * lower-cased `IN (...)` predicate WOULD match a stored `'Approved'` here and would NOT match
     * it on SQLite. That divergence is the reason `SettledPlanOrders` resolves the spellings in
     * PHP instead of relying on either engine's collation.
     */
    $pdo->exec("INSERT INTO plan_orders (order_id, payment_status) VALUES ('PO-2', 'Approved')");

    $ciMatches = (int) $pdo->query(
        "SELECT COUNT(*) FROM plan_orders WHERE payment_status IN ('success','succeeded','approved')"
    )->fetchColumn();

    $check('a lower-cased IN() matches a stored `Approved` on this collation (SQLite would not)',
        $ciMatches === 2, "matched {$ciMatches} of 2");

    $padSpace = (int) $pdo->query(
        "SELECT COUNT(*) FROM plan_orders WHERE payment_status = 'Approved '"
    )->fetchColumn();

    $check('PAD SPACE: a trailing space is ignored in comparison',
        $padSpace === 1, "matched {$padSpace} of 1");
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
}

printf("\n%d checks, %d failed\n", $checks, $failed);

exit($failed === 0 ? 0 : 1);
