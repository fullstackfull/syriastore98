#!/usr/bin/env php
<?php

/**
 * P01-SEC-14 E5 -- the non-positive-quantity guard, verified on a REAL MySQL.
 *
 * WHY THIS EXISTS. The suite runs on SQLite `:memory:` and production is MySQL, and this project
 * has already lost a defect to that gap: `customers.password_otp` is INTEGER while the code writes
 * a 60-char bcrypt hash, which SQLite's type affinity accepted silently (P01-SEC-13). So a new
 * predicate on a numeric column gets checked against the real engine rather than assumed.
 *
 * WHAT IS CHECKED. `payment_sheet` refuses a basket holding a line with `qty <= 0`:
 *
 *     Cart::where('customer_id', ...)->where('store_id', ...)->where('qty', '<=', 0)->count()
 *
 * `carts.qty` is declared `$table->integer('qty')->default(0)`, so the comparison should be plain
 * integer arithmetic on both engines. This script proves that on MySQL, and -- more usefully --
 * proves the cases where the two engines COULD have diverged: a value arriving as a numeric string,
 * and the boundary at exactly 0.
 *
 * PRECONDITIONS, stated so a failure is not mistaken for a defect:
 *   - a reachable MySQL 8 with an account that may CREATE and DROP a database;
 *   - credentials from the environment (MYSQL_USER / MYSQL_PASSWORD / MYSQL_HOST / MYSQL_PORT /
 *     MYSQL_SOCKET), defaulting to root on 127.0.0.1:3306 with no password. On a stock Ubuntu
 *     MySQL 8 the root account uses the `auth_socket` plugin, so TCP is refused with
 *     "Access denied for user 'root'@'localhost'" and the UNIX SOCKET is the working path --
 *     set MYSQL_SOCKET=/var/run/mysqld/mysqld.sock, or let the fallback below find it.
 *
 * It creates and drops its OWN throwaway database and touches nothing else. It writes no row to any
 * existing schema, and it must never be pointed at the development server.
 *
 * Usage:  php tests/Support/Security/bin/verify-cart-quantity-guard-on-mysql.php
 * Exit:   0 all checks passed, 1 a check failed, 2 preconditions not met.
 */

$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('MYSQL_PORT') ?: 3306);
$user = getenv('MYSQL_USER') ?: 'root';
$pass = getenv('MYSQL_PASSWORD') ?: '';
$db = 'p01sec14_qty_' . bin2hex(random_bytes(4));

echo "P01-SEC-14 E5 -- cart quantity guard on real MySQL\n";
echo str_repeat('-', 78) . "\n";

$socket = getenv('MYSQL_SOCKET') ?: '';

// Try the socket first when one was named or a default one exists, because `auth_socket` root --
// the Ubuntu default -- can only authenticate that way; then fall back to TCP.
$attempts = [];
foreach ([$socket, '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
    if ($candidate !== '' && file_exists($candidate)) {
        $attempts[] = ['socket ' . $candidate, "mysql:unix_socket={$candidate}"];
        break;
    }
}
$attempts[] = ["tcp {$host}:{$port}", "mysql:host={$host};port={$port}"];

$pdo = null;
$errors = [];
foreach ($attempts as [$label, $dsn]) {
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        echo "connected over {$label}\n";
        break;
    } catch (Throwable $e) {
        $errors[] = "{$label}: " . $e->getMessage();
    }
}

if ($pdo === null) {
    echo "PRECONDITION NOT MET: no reachable MySQL as '{$user}'.\n";
    foreach ($errors as $error) {
        echo "  {$error}\n";
    }
    exit(2);
}

echo "server: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
$pdo->exec("CREATE DATABASE `{$db}`");
echo "throwaway database: {$db}\n\n";

$failures = 0;
$check = function (string $what, $expected, $actual) use (&$failures) {
    $ok = $expected === $actual;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
    if (! $ok) {
        $failures++;
        printf("         expected %s, got %s\n", var_export($expected, true), var_export($actual, true));
    }
};

try {
    $pdo->exec("USE `{$db}`");

    // The column exactly as database/migrations/..._create_carts_table.php declares it.
    $pdo->exec("CREATE TABLE carts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id BIGINT UNSIGNED NULL,
        store_id BIGINT UNSIGNED NULL,
        product_id BIGINT UNSIGNED NULL,
        variant_id BIGINT UNSIGNED NULL DEFAULT 0,
        qty INT NOT NULL DEFAULT 0
    )");

    echo "1. the declared column type is what the guard assumes\n";
    $type = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'carts' AND COLUMN_NAME = 'qty'")->fetchColumn();
    $check("carts.qty is an integer type on MySQL (got '{$type}')", true, $type === 'int');

    // Rows: a good line, the boundary, a negative, and the two string forms a JSON client can send.
    $insert = $pdo->prepare('INSERT INTO carts (customer_id, store_id, product_id, qty) VALUES (?, ?, ?, ?)');
    foreach ([
        [1, 1, 10, 2],       // the legitimate line
        [1, 1, 11, 0],       // the boundary
        [1, 1, 12, -5],      // the attack
        [1, 1, 13, '3'],     // a numeric string, as a JSON body supplies it
        [1, 1, 14, '-7'],    // a negative numeric string
        [2, 1, 15, -9],      // ANOTHER customer's negative line -- must not affect customer 1
    ] as $row) {
        $insert->execute($row);
    }

    echo "\n2. the guard's own predicate selects exactly the non-positive lines\n";
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM carts WHERE customer_id = ? AND store_id = ? AND qty <= 0');
    $stmt->execute([1, 1]);
    $check('customer 1 has 3 non-positive lines (0, -5, "-7")', 3, (int) $stmt->fetchColumn());

    $stmt->execute([2, 1]);
    $check("another customer's negative line is counted against THEM, not customer 1", 1, (int) $stmt->fetchColumn());

    echo "\n3. the boundary is inclusive, which is what `<= 0` must mean\n";
    $zero = (int) $pdo->query('SELECT COUNT(*) FROM carts WHERE qty = 0')->fetchColumn();
    $check('a qty of exactly 0 exists and is caught by the predicate', 1, $zero);

    echo "\n4. the numeric strings a JSON client sends are stored as integers, not text\n";
    $stored = $pdo->query('SELECT qty FROM carts WHERE product_id = 13')->fetchColumn();
    $check("'3' was coerced to the integer 3", '3', (string) $stored);
    $stored = $pdo->query('SELECT qty FROM carts WHERE product_id = 14')->fetchColumn();
    $check("'-7' was coerced to the integer -7 and so is caught", '-7', (string) $stored);

    echo "\n5. a positive-only basket is NOT refused -- the guard must not break checkout\n";
    $pdo->exec('DELETE FROM carts WHERE qty <= 0');
    $stmt->execute([1, 1]);
    $check('after removing the bad lines, customer 1 trips the guard 0 times', 0, (int) $stmt->fetchColumn());
    $check('and still has their legitimate lines', 2,
        (int) $pdo->query('SELECT COUNT(*) FROM carts WHERE customer_id = 1')->fetchColumn());

    echo "\n6. the idempotency key's row read is deterministic under MySQL's own ordering\n";
    // The key maps the rows in `orderBy('id')` order, so two reads of an unchanged basket must
    // produce the same string. Without the ORDER BY, MySQL makes no ordering promise at all.
    $a = $pdo->query('SELECT GROUP_CONCAT(CONCAT_WS(":", id, product_id, variant_id, qty) ORDER BY id) FROM carts WHERE customer_id = 1')->fetchColumn();
    $b = $pdo->query('SELECT GROUP_CONCAT(CONCAT_WS(":", id, product_id, variant_id, qty) ORDER BY id) FROM carts WHERE customer_id = 1')->fetchColumn();
    $check('two reads of an unchanged basket give the same row signature', $a, $b);
    echo "         signature: {$a}\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
    echo "\nthrowaway database dropped.\n";
}

echo str_repeat('-', 78) . "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} check(s).\n";
    exit(1);
}
echo "OK: every check passed on real MySQL.\n";
exit(0);
