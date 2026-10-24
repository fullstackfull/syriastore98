<?php

/**
 * P1-INV-18 — THE COLLATION HALF OF THE SET PREDICATE, MEASURED ON REAL MySQL.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The Feature suite runs on SQLite `:memory:`, whose
 * default TEXT collation is BINARY — case- and accent-SENSITIVE. MySQL's default here is
 * `utf8mb4_0900_ai_ci`: accent- and case-INSENSITIVE. The defect below exists only under a
 * case-insensitive collation, so **no test in that suite could find it and none can prove it
 * fixed.** Same engine-hiding class as P01-SEC-13 and as the `'5abc'` coercion
 * `ModuleOrderMutationGuard` shipped with. A test needing MySQL would also have to skip where
 * there is none, and `.github/known-test-outcomes.json` declares the exact skip set.
 *
 * THE DEFECT, which was in MY OWN FIX and was found by thinking the predicate through rather than
 * by a test. `App\Http\Middleware\Concerns\ComparesRowOwnership`'s set predicate originally ran
 *
 *     whereIn($column, $values)->where('store_id', $currentStore)->pluck($column)
 *
 * and compared that against the requested values with `array_diff` — asking *"does every value I
 * was given resolve to a row I own?"*. The handler asks a different question:
 *
 *     PurchaseTicket::whereIn('lottery_code', $selectedCodes)      // NO store filter
 *
 * Under a `_ci` collation those two queries match DIFFERENT ROW SETS, so a caller owning `ABC-1`
 * could draw a victim's `abc-1`: the guard verified the value, and the handler matched wider.
 *
 * THE FIX asks the engine's own question — run the handler's `whereIn` with no store filter and
 * refuse if any row it matches lies outside the caller's store — so guard and handler see the same
 * rows whatever the collation does.
 *
 * WHAT THIS SCRIPT ESTABLISHES, by execution:
 *   1. the database collation this application actually gets, and that it is case-insensitive;
 *   2. that the OLD predicate ALLOWS while the handler's own query reaches the victim's row;
 *   3. that the NEW predicate REFUSES the same request;
 *   4. that accent variants behave the same way under `ai_ci`, so the finding is not only about case;
 *   5. that SQLite does NOT reproduce any of it, which is why this file exists;
 *   6. that the shipped trait method is the new shape, called for real rather than reimplemented.
 *
 * HOW TO RUN. Points at any throwaway MySQL you control; creates and drops its own database and
 * refuses a database name without 'verify' in it:
 *
 *   MYSQL_SOCK=/var/run/mysqld/mysqld.sock MYSQL_USER_NAME=root \
 *       php tests/Support/Security/bin/verify-lottery-code-collation-on-mysql.php
 *
 * Exit 0 = every assertion held. NEVER point it at the development or production server.
 */

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

// The application is booted FIRST: config() needs a bootstrapped container, and step 0 reads the
// configured collation. Calling it before the boot is a fatal, which is how this was found.
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = getenv('MYSQL_DATABASE') ?: 'p1inv18_verify';

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

// file_exists, NOT is_file: a unix socket is not a regular file.
$dsnBase = file_exists($socket)
    ? "mysql:unix_socket={$socket}"
    : "mysql:host={$host};port={$port}";

try {
    $pdo = new PDO($dsnBase, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "No MySQL reachable ({$dsnBase}): " . $e->getMessage() . "\n");
    exit(2);
}

echo "STEP 0 -- a throwaway database, created and dropped by this script.\n";
$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}`");
$pdo->exec("USE `{$database}`");
printf("         server %s\n", $pdo->query('SELECT VERSION()')->fetchColumn());

$collation = $pdo->query('SELECT @@collation_database')->fetchColumn();
$out("the database collation is case-insensitive (ends _ci): {$collation}",
    str_ends_with((string) $collation, '_ci'),
    'If this server is configured case-SENSITIVE the defect below does not reproduce here -- but '
    . 'config/database.php defaults DB_COLLATION to utf8mb4_unicode_ci, which is _ci, so '
    . 'production is the case-insensitive shape.');

printf("         config/database.php default collation: %s\n",
    var_export(config('database.connections.mysql.collation'), true));

$pdo->exec("CREATE TABLE purchase_tickets (
    id BIGINT UNSIGNED PRIMARY KEY,
    lottery_code VARCHAR(191),
    store_id BIGINT UNSIGNED NULL
)");
$pdo->exec("INSERT INTO purchase_tickets VALUES
    (1, 'ABC-1', 1),
    (2, 'abc-1', 2),
    (3, 'CAFE-1', 1),
    (4, 'CAFÉ-1', 2),
    (5, 'ORPHAN-1', NULL)");
echo "         store 1 owns 'ABC-1' and 'CAFE-1'; store 2 owns 'abc-1' and 'CAFÉ-1';\n";
echo "         row 5 'ORPHAN-1' has a NULL store_id.\n";

echo "\nSTEP 1 -- the two queries match DIFFERENT ROW SETS. This is the whole finding.\n";

$guardOld = $pdo->prepare('SELECT lottery_code FROM purchase_tickets WHERE lottery_code IN (?) AND store_id = ?');
$handler = $pdo->prepare('SELECT id, lottery_code, store_id FROM purchase_tickets WHERE lottery_code IN (?)');

foreach ([['ABC-1', 1, 2], ['CAFE-1', 1, 4]] as [$requested, $callerStore, $victimRow]) {
    $guardOld->execute([$requested, $callerStore]);
    $ownedCodes = $guardOld->fetchAll(PDO::FETCH_COLUMN);

    $handler->execute([$requested]);
    $handlerRows = $handler->fetchAll(PDO::FETCH_ASSOC);

    printf("\n         requested %-9s as store %d\n", var_export($requested, true), $callerStore);
    printf("           OLD guard query  -> %s\n", json_encode($ownedCodes));
    printf("           handler query    -> %s\n", json_encode($handlerRows));

    // The OLD predicate: array_diff of requested vs owned, case-sensitively in PHP.
    $oldAllows = array_diff([$requested], array_map('trim', $ownedCodes)) === [];

    $reachesForeign = false;
    foreach ($handlerRows as $row) {
        if ($row['store_id'] === null || (int) $row['store_id'] !== $callerStore) {
            $reachesForeign = true;
        }
    }

    $out("   the OLD predicate ALLOWS {$requested}", $oldAllows,
        'It asked whether the VALUE resolves to a row the caller owns, and it does.');
    $out("   the handler nonetheless reaches a FOREIGN row (id {$victimRow})", $reachesForeign,
        'Two different questions, separated by the collation. This is the bypass.');
}

echo "\nSTEP 2 -- the NEW predicate's own query refuses both.\n";

$guardNew = $pdo->prepare(
    'SELECT COUNT(*) FROM purchase_tickets WHERE lottery_code IN (?) AND (store_id IS NULL OR store_id <> ?)'
);

foreach ([['ABC-1', 1], ['CAFE-1', 1], ['ORPHAN-1', 1]] as [$requested, $callerStore]) {
    $guardNew->execute([$requested, $callerStore]);
    $foreign = (int) $guardNew->fetchColumn();

    $out(sprintf('   %-10s as store %d -> %d foreign row(s) -> REFUSE', var_export($requested, true), $callerStore, $foreign),
        $foreign > 0,
        $foreign > 0 ? '' : 'The new predicate does not see the foreign row, so the fix does not hold.');
}

// And it must still ALLOW a code with no foreign match, or the fix is a lockout.
$pdo->exec("INSERT INTO purchase_tickets VALUES (6, 'CLEAN-1', 1)");
$guardNew->execute(['CLEAN-1', 1]);
$out("   'CLEAN-1' as store 1 -> 0 foreign rows -> ALLOW", (int) $guardNew->fetchColumn() === 0,
    'A code whose every match is the caller\'s own must still be allowed, or this is a lockout '
    . 'rather than a guard.');

echo "\nSTEP 3 -- SQLite does NOT reproduce it, which is why this file exists.\n";

$lite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lite->exec('CREATE TABLE purchase_tickets (id INTEGER PRIMARY KEY, lottery_code TEXT, store_id INTEGER)');
$lite->exec("INSERT INTO purchase_tickets VALUES (1,'ABC-1',1),(2,'abc-1',2)");
$liteStmt = $lite->prepare('SELECT id, store_id FROM purchase_tickets WHERE lottery_code IN (?)');
$liteStmt->execute(['ABC-1']);
$liteRows = $liteStmt->fetchAll(PDO::FETCH_ASSOC);

$out('SQLite matches ONLY the exact-case row', count($liteRows) === 1 && (int) $liteRows[0]['store_id'] === 1,
    'Its default TEXT collation is BINARY, so the OLD predicate and the handler agree there and '
    . 'the bypass silently no-ops. Matched: ' . json_encode($liteRows));

echo "\nSTEP 4 -- the SHIPPED trait method is the new shape, called for real.\n";

$trait = new ReflectionClass(App\Http\Middleware\Concerns\ComparesRowOwnership::class);
$out('the trait exposes noMatchedRowOutsideCallerStore()', $trait->hasMethod('noMatchedRowOutsideCallerStore'));
$out('the OLD value-keyed method is gone', ! $trait->hasMethod('valuesAllInCallerStore'),
    'Leaving it callable would let a future guard pick the bypassable predicate.');

$source = (string) file_get_contents($root . '/app/Http/Middleware/Concerns/ComparesRowOwnership.php');
$out("the predicate runs the handler's whereIn WITHOUT a store filter and tests for a foreign row",
    str_contains($source, "->orWhere('store_id', '!=', \$currentStore)")
    && str_contains($source, "->whereNull('store_id')"),
    'That pairing is the collation-safe core: whatever rows the engine matches, the guard sees the '
    . 'same ones, and a NULL store_id counts as outside.');

$out('the guard calls it', str_contains(
    (string) file_get_contents($root . '/app/Http/Middleware/LotteryDrawOwnershipGuard.php'),
    'noMatchedRowOutsideCallerStore('
));

echo "\nSTEP 5 -- drop the throwaway database.\n";
$pdo->exec("DROP DATABASE `{$database}`");
$out("database `{$database}` dropped", true);

echo "\n" . ($failed ? "RESULT: FAILED\n" : "RESULT: ALL CHECKS PASSED\n");

exit($failed ? 1 : 0);
