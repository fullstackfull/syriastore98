<?php

/**
 * P1-INV-14 — THE ENGINE HALF OF THE `resolveId()` FINDING, MEASURED ON REAL MySQL.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The Feature suite runs on SQLite `:memory:`
 * (phpunit.xml), and the behaviour this finding rests on is MySQL's implicit string→integer
 * coercion in a `WHERE id = '5abc'` comparison. SQLite's INTEGER affinity does NOT convert, so
 * the row does not match and the attack silently no-ops — **no test in that suite could have
 * found this, and none can prove it fixed**. It is the same engine-hiding that let P01-SEC-13
 * survive. A test needing MySQL would have to skip where there is none, and
 * `.github/known-test-outcomes.json` declares the exact skip set, so a conditionally-skipped test
 * would turn the known-outcome gate red.
 *
 * THE FINDING. `App\Http\Middleware\ModuleOrderMutationGuard::resolveId()` originally required
 * `is_numeric()` in BOTH of its loops. A body value that was present but not numeric — `id=5abc`
 * — was therefore skipped by the body loop, and the route-parameter loop then authorised the
 * ROUTE SEGMENT, i.e. the attacker's own order:
 *
 *   POST /lottery-order-status-change/<own order>   body: id=5abc&delivered=cancel
 *     guard   : input('id') = '5abc' is not numeric -> route param -> authorises the OWN order
 *     handler : $request->id = '5abc' -> Order::find('5abc')
 *     MySQL   : coerces and returns ANOTHER STORE'S order
 *
 * Found by the adversarial review of this guard, after its first green suite.
 *
 * WHAT THIS SCRIPT ESTABLISHES, by execution:
 *   1. that MySQL coerces `'5abc'` in an `id` comparison and RETURNS ROW 5 (the premise);
 *   2. that SQLite does NOT, so the suite is structurally blind to it (the reason for this file);
 *   3. that `filter_var(..., FILTER_VALIDATE_INT)` — what the guard now uses — rejects every
 *      string MySQL would coerce to a DIFFERENT row than the guard authorised;
 *   4. that the fixed `resolveId()` returns null for the whole family of such values, so the
 *      guard denies instead of authorising the wrong row.
 *
 * HOW TO RUN. Points at any throwaway MySQL you control; creates and drops its own database and
 * refuses a database name without 'verify' in it:
 *
 *   MYSQL_SOCK=/var/run/mysqld/mysqld.sock MYSQL_USER_NAME=root \
 *       php tests/Support/Security/bin/verify-module-order-mutation-id-coercion-on-mysql.php
 *
 * Exit 0 = every assertion held. NEVER point it at the development or production server.
 */

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$database = getenv('MYSQL_DATABASE') ?: 'p1inv14_verify';

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

// file_exists, NOT is_file: a unix socket is not a regular file, so is_file() is false for it
// and the DSN would silently fall back to TCP -- where `root` typically authenticates by
// auth_socket and is refused. Measured here the hard way.
$dsnBase = file_exists($socket)
    ? "mysql:unix_socket={$socket}"
    : "mysql:host={$host};port={$port}";

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

/*
 * `strict => false` is config/database.php's PRODUCTION setting, so the session is configured the
 * way production is. A strict session would not change the SELECT below in any case: coercion in a
 * comparison raises warning 1292, which is not an error even under STRICT_TRANS_TABLES.
 */
$pdo->exec("SET SESSION sql_mode = ''");

$pdo->exec('CREATE TABLE orders (id BIGINT UNSIGNED PRIMARY KEY, store_id BIGINT UNSIGNED)');
$pdo->exec('INSERT INTO orders (id, store_id) VALUES (5, 2), (42, 1)');
echo "         orders: id 5 -> store 2 (the victim), id 42 -> store 1 (the caller's own)\n";

echo "\nSTEP 1 -- THE PREMISE: MySQL coerces a non-numeric string in an `id` comparison.\n";

$select = $pdo->prepare('SELECT id, store_id FROM orders WHERE id = ?');

/** value => the id MySQL resolves it to, or null for no row. */
$engine = [];

foreach (['5abc', '5', ' 5', '5.9', '5e0', '0x5', 'abc', '', '5 OR 1=1'] as $value) {
    $select->execute([$value]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    $engine[$value] = $row === false ? null : (int) $row['id'];

    printf("         WHERE id = %-12s -> %s\n",
        var_export($value, true),
        $row === false ? 'no row' : 'id ' . $row['id'] . ' (store ' . $row['store_id'] . ')');
}

$out("'5abc' resolves to ROW 5 -- another store's order", $engine['5abc'] === 5,
    'If this is null the premise is wrong and the finding should be re-read. Actual: '
    . var_export($engine['5abc'], true));
$out("'5 OR 1=1' also resolves to ROW 5", $engine['5 OR 1=1'] === 5,
    'Not an injection -- a bound parameter -- but the same leading-integer coercion.');
$out("'abc' resolves to NO row (it coerces to 0)", $engine['abc'] === null);

echo "\nSTEP 2 -- WHY NO TEST IN THE SUITE COULD SEE IT: SQLite does not coerce.\n";

$lite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lite->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, store_id INTEGER)');
$lite->exec('INSERT INTO orders (id, store_id) VALUES (5, 2), (42, 1)');
$liteSelect = $lite->prepare('SELECT id FROM orders WHERE id = ?');
$liteSelect->execute(['5abc']);
$liteRow = $liteSelect->fetch(PDO::FETCH_ASSOC);

$out("SQLite returns NO row for '5abc'", $liteRow === false,
    'This is the engine-hiding: the Feature suite runs on sqlite :memory:, so the attack '
    . 'no-ops there and a green suite proves nothing about it. Same class as P01-SEC-13.');

echo "\nSTEP 3 -- THE FIX: every value MySQL coerces to a DIFFERENT row is rejected.\n";
echo "         `filter_var(FILTER_VALIDATE_INT)` is what resolveId() now uses.\n";

foreach ($engine as $value => $resolvesTo) {
    if ($value === '') {
        continue;   // treated as absent by resolveId; MySQL coerces it to 0, which matches no row
    }

    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    $guardWould = ($filtered === false || $filtered <= 0) ? null : $filtered;

    // The requirement: EITHER the guard refuses outright, OR it authorises exactly the row the
    // engine will hand the handler. Anything else is a guard authorising one row while the
    // handler mutates another.
    $sound = $guardWould === null || $guardWould === $resolvesTo;

    $out(sprintf('   %-12s guard=%-6s engine=%-6s',
            var_export($value, true),
            $guardWould === null ? 'DENY' : (string) $guardWould,
            $resolvesTo === null ? 'none' : (string) $resolvesTo),
        $sound,
        $sound ? '' : 'The guard would authorise row ' . var_export($guardWould, true)
            . " while MySQL hands the handler row " . var_export($resolvesTo, true) . '.');
}

echo "\nSTEP 4 -- the OLD predicate is shown to be unsound on the same table.\n";
$oldWould = [];

foreach ($engine as $value => $resolvesTo) {
    // The original: is_numeric in the body loop, and a fall-through to the route parameter when
    // it failed. Route segment 42 = the caller's own order.
    $oldWould[$value] = ($value !== '' && is_numeric($value)) ? (int) $value : 42;
}

$out("the OLD predicate authorises 42 for '5abc' while the engine hands over 5",
    $oldWould['5abc'] === 42 && $engine['5abc'] === 5,
    'This is the hole, reproduced against the engine rather than argued: guard says 42 (own), '
    . 'MySQL says 5 (another store). Old=' . var_export($oldWould['5abc'], true)
    . ' engine=' . var_export($engine['5abc'], true));

$out("the OLD predicate also diverges on '5.9'", $oldWould['5.9'] === 5 && $engine['5.9'] === null,
    'Less severe -- it authorises a row the handler then fails to find -- but the same class of '
    . 'divergence between guard and engine.');

echo "\nSTEP 5 -- the SHIPPED resolveId() agrees, called directly.\n";
$method = new ReflectionMethod(App\Http\Middleware\ModuleOrderMutationGuard::class, 'resolveId');
$method->setAccessible(true);
$guard = new App\Http\Middleware\ModuleOrderMutationGuard();

$route = new class {
    public function parameter($key) { return $key === 'id' ? '42' : null; }
};

foreach (['5abc' => null, '0x5' => null, 'abc' => null, '5.9' => null, '5' => 5, '-1' => null, '0' => null] as $value => $want) {
    $request = Illuminate\Http\Request::create('/x', 'POST', ['id' => $value]);
    $got = $method->invoke($guard, $request, $route, ['id']);

    $out(sprintf('   resolveId(id=%-8s) = %-6s', var_export($value, true),
            $got === null ? 'null' : (string) $got),
        $got === $want,
        $got === $want ? '' : 'expected ' . var_export($want, true));
}

$absent = $method->invoke($guard, Illuminate\Http\Request::create('/x', 'POST', []), $route, ['id']);
$out('   an ABSENT key still falls back to the route parameter (42)', $absent === 42,
    'The fallback must survive: four of the six routes declare no {id} at all, and the two that '
    . 'do are reached from forms that post nothing. Actual: ' . var_export($absent, true));

echo "\nSTEP 6 -- drop the throwaway database.\n";
$pdo->exec("DROP DATABASE `{$database}`");
$out("database `{$database}` dropped", true);

echo "\n" . ($failed ? "RESULT: FAILED\n" : "RESULT: ALL CHECKS PASSED\n");

exit($failed ? 1 : 0);
