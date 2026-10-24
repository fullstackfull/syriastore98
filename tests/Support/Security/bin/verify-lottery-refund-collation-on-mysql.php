<?php

/**
 * P1-INV-20 / #67 item 2 — THE REFUND GUARD'S TWO LIMBS, MEASURED ON REAL MySQL.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The Feature suite runs on SQLite `:memory:`, whose
 * default TEXT collation is BINARY — case- and accent-SENSITIVE. MySQL's is `utf8mb4_0900_ai_ci`
 * on 8.x and `config/database.php` defaults `DB_COLLATION` to `utf8mb4_unicode_ci`: both
 * case- and accent-INSENSITIVE. The question below only HAS an answer under a `_ci` collation, so
 * `LotteryTicketWriterTest` cannot ask it in either direction. A test needing MySQL would also
 * have to skip where there is none, and `.github/known-test-outcomes.json` asserts the exact skip
 * set.
 *
 * TWO LEVELS OF "LIMB", AND KEEPING THEM APART IS THE POINT. The first draft of this script did
 * not, and passed for the wrong reason — see the note at the end of this block.
 *
 *   `LotteryRefundOwnershipGuard` makes two checks:
 *     GUARD-A  noMatchedRowOutsideStore('purchase_tickets', 'lottery_code', [$code], $store)
 *     GUARD-B  DB::table('purchase_tickets')->where('lottery_code', trim($code))
 *                  ->where('store_id', $store)->where('customer_id', $customer->id)->exists()
 *
 *   GUARD-A is the trait's set predicate, and it is itself two internal limbs:
 *     TRAIT-1  refuse if the handler's own match reaches ANY row outside the caller's store
 *     TRAIT-2  refuse unless every requested value is accounted for by a row the caller owns,
 *              compared in PHP with `array_diff` — case-SENSITIVE
 *
 * Under `_ci`, GUARD-B's `where('lottery_code', 'abc-1')` also matches the caller's own `ABC-1`.
 * That is LOOSER than PHP would be, so GUARD-B alone is more permissive than it reads — and the
 * handler (LotteryPrizesController:870) is looser still, having no store filter at all and
 * resolving with `->first()`, i.e. primary key order.
 *
 * So the thing to establish is not that GUARD-B is tight — it is not — but that GUARD-A has
 * already refused every request where that looseness could matter. That is an argument about SQL
 * row sets, and the only way to settle it is to run it on the engine in question.
 *
 * WHAT THIS SCRIPT ESTABLISHES, by execution:
 *   1. the collation this application actually gets, and that it is case-insensitive;
 *   2. that GUARD-B ON ITS OWN is satisfied by a case-variant request — it is not the control;
 *   3. that the handler's own query reaches a FOREIGN row for that same request, and that
 *      `->first()` returns the foreign row when it was inserted earlier;
 *   4. that GUARD-A REFUSES that request, so GUARD-B's looseness is never reached;
 *   5. the same for accent variants, so the finding is not only about case;
 *   6. **that TRAIT-1 is load-bearing on its own** — step 2b constructs a request where TRAIT-2
 *      is satisfied and only TRAIT-1 can refuse;
 *   7. that a legitimate same-store refund still PASSES both guard checks — a guard that refuses
 *      everyone is not a fix, and this is the half a security script usually forgets;
 *   8. that SQLite reproduces NONE of it, which is why this file exists;
 *   9. that the SHIPPED trait method is the one being measured, called for real rather than
 *      reimplemented — a previous script in this family copied the body of the method it meant to
 *      test and therefore could not fail.
 *
 * WHY STEP 2b EXISTS, recorded because it is the more useful half of this file's history. The
 * first draft had steps 1, 2 and 4 only, and when TRAIT-1's early return was deleted as a removal
 * check the script still reported "every assertion held". TRAIT-2 was doing the refusing:
 * `array_diff(['abc-1'], ['ABC-1'])` is non-empty in PHP, so the request was refused for a reason
 * the script was not measuring, while the label claimed TRAIT-1 had refused it. A verification
 * script that cannot fail is worth less than no script, so step 2b isolates TRAIT-1 by asking for
 * a code the caller owns EXACTLY — which satisfies `array_diff` — while a foreign row shares it
 * under the collation.
 *
 * HOW TO RUN. Points at any throwaway MySQL you control; creates and drops its own database and
 * refuses a database name without 'verify' in it:
 *
 *   MYSQL_SOCK=/var/run/mysqld/mysqld.sock MYSQL_USER_NAME=root \
 *       php tests/Support/Security/bin/verify-lottery-refund-collation-on-mysql.php
 *
 * Exit 0 = every assertion held. NEVER point it at the development or production server.
 */

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

// Booted first: config() needs a bootstrapped container, and step 0 reads the configured
// collation. Calling config() before the boot is a fatal.
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = getenv('MYSQL_DATABASE') ?: 'p1inv20_verify';

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

$collation = (string) $pdo->query('SELECT @@collation_database')->fetchColumn();
$out("the database collation is case-insensitive (ends _ci): {$collation}",
    str_ends_with($collation, '_ci'),
    'On a case-SENSITIVE server the looseness below does not arise -- but config/database.php '
    . 'defaults DB_COLLATION to utf8mb4_unicode_ci, so production is the _ci shape.');

printf("         config/database.php default collation: %s\n",
    var_export(config('database.connections.mysql.collation'), true));

/*
 * The caller throughout: customer 7, registered in store 1.
 *
 * `customer_id` is the SAME on the foreign rows, which is what makes this reachable at all --
 * `customers` is one session guard, not one per store, so a shopper's id lands on ticket rows in
 * several stores (LotteryPrizesController:613 writes whatever `auth('customers')` holds).
 */
$pdo->exec("CREATE TABLE purchase_tickets (
    id BIGINT UNSIGNED PRIMARY KEY,
    lottery_code VARCHAR(191),
    customer_id BIGINT UNSIGNED NULL,
    store_id BIGINT UNSIGNED NULL
)");
$pdo->exec("INSERT INTO purchase_tickets VALUES
    (1, 'abc-1',  7, 2),
    (2, 'ABC-1',  7, 1),
    (3, 'café-1', 7, 2),
    (4, 'CAFE-1', 7, 1),
    (5, 'MINE-1', 7, 1),
    (6, 'ORPH-1', 7, NULL)");
echo "         customer 7 is registered in STORE 1 and has rows in both stores.\n";
echo "         store 1 holds 'ABC-1'(2), 'CAFE-1'(4), 'MINE-1'(5); store 2 holds 'abc-1'(1), 'café-1'(3).\n";
echo "         row 6 'ORPH-1' has a NULL store_id -- invisible to every store_id predicate.\n";

$CALLER_STORE = 1;
$CALLER_ID = 7;

echo "\nSTEP 1 -- GUARD-B ALONE is not the control. It is satisfied by a case-variant request.\n";

$guardB = $pdo->prepare(
    'SELECT COUNT(*) FROM purchase_tickets WHERE lottery_code = ? AND store_id = ? AND customer_id = ?'
);
$handler = $pdo->prepare(
    'SELECT id, lottery_code, store_id FROM purchase_tickets WHERE lottery_code = ? AND customer_id = ? ORDER BY id'
);

foreach ([['abc-1', 1], ['café-1', 3]] as [$requested, $expectedForeignRow]) {
    $guardB->execute([$requested, $CALLER_STORE, $CALLER_ID]);
    $guardBSays = (int) $guardB->fetchColumn() > 0;

    $handler->execute([$requested, $CALLER_ID]);
    $handlerRows = $handler->fetchAll(PDO::FETCH_ASSOC);
    $firstRow = $handlerRows[0] ?? null;

    printf("\n         requested %-9s as customer %d of store %d\n",
        var_export($requested, true), $CALLER_ID, $CALLER_STORE);
    printf("           GUARD-B says owned : %s\n", $guardBSays ? 'YES' : 'no');
    printf("           handler matches   : %s\n", json_encode($handlerRows));
    printf("           handler ->first() : row %s, store %s\n",
        $firstRow['id'] ?? 'none', $firstRow['store_id'] ?? 'none');

    $out("GUARD-B alone admits the case/accent variant {$requested}", $guardBSays,
        'It matches the caller\'s own differently-cased row under the _ci collation. That is the '
        . 'point: GUARD-B is not the control and must not be mistaken for one.');

    $out("the handler's ->first() returns a FOREIGN row for {$requested}",
        $firstRow !== null && (int) $firstRow['store_id'] !== $CALLER_STORE
            && (int) $firstRow['id'] === $expectedForeignRow,
        'The foreign row was inserted first, so it holds the lower primary key and the handler\'s '
        . 'unscoped ->first() destroys it -- while GUARD-B reported the request as owned.');
}

echo "\nSTEP 2 -- GUARD-A, the SHIPPED trait method, refuses those same requests.\n";

/*
 * THE SHIPPED CODE, CALLED FOR REAL. An earlier script in this family copied the body of the
 * method it meant to test, so it could not fail no matter what shipped. The trait method is
 * protected, so it is reached through an anonymous class that uses the trait -- not reimplemented.
 */
config([
    'database.default' => 'mysql_verify',
    'database.connections.mysql_verify' => array_merge(
        config('database.connections.mysql'),
        [
            'database' => $database,
            'username' => $user,
            'password' => $pass,
            'unix_socket' => file_exists($socket) ? $socket : '',
            'host' => $host,
            'port' => $port,
        ]
    ),
]);

$probe = new class {
    use App\Http\Middleware\Concerns\ComparesRowOwnership;

    public function ask(string $code, int $store): bool
    {
        return $this->noMatchedRowOutsideStore('purchase_tickets', 'lottery_code', [$code], $store);
    }
};

printf("         probing through %s::noMatchedRowOutsideStore()\n",
    App\Http\Middleware\Concerns\ComparesRowOwnership::class);

foreach (['abc-1', 'café-1'] as $requested) {
    $allowed = $probe->ask($requested, $CALLER_STORE);
    printf("           GUARD-A on %-9s -> %s\n", var_export($requested, true),
        $allowed ? 'ALLOW' : 'REFUSE');

    $out("GUARD-A refuses {$requested}, so GUARD-B's looseness is never reached", ! $allowed,
        'GUARD-A runs the handler\'s own match with no store filter and refuses if ANY row it '
        . 'reaches lies outside the caller\'s store. NOTE this step alone does not say WHICH of '
        . 'its two internal limbs refused -- step 2b is what isolates TRAIT-1.');
}

echo "\nSTEP 2b -- TRAIT-1 ON ITS OWN. The case where TRAIT-2 cannot be what refuses.\n";

/*
 * THE ISOLATING CASE. The caller owns the requested code EXACTLY, so TRAIT-2's `array_diff` is
 * satisfied -- `array_diff(['dup-1'], ['dup-1'])` is empty -- and the only thing left that can
 * refuse is TRAIT-1's foreign-row check, because store 2 holds 'DUP-1', which the `_ci` collation
 * makes the same value.
 *
 * Without this case, deleting TRAIT-1 leaves this script green. Measured: it did.
 */
$pdo->exec("INSERT INTO purchase_tickets VALUES
    (7, 'DUP-1', 7, 2),
    (8, 'dup-1', 7, 1)");
echo "         store 2 holds 'DUP-1'(7); store 1 holds the caller's own exact 'dup-1'(8).\n";

$guardB->execute(['dup-1', $CALLER_STORE, $CALLER_ID]);
printf("           GUARD-B on 'dup-1'  -> %s\n", (int) $guardB->fetchColumn() > 0 ? 'OWNED' : 'not owned');

$exactOwned = $pdo->prepare(
    'SELECT lottery_code FROM purchase_tickets WHERE lottery_code IN (?) AND store_id = ?'
);
$exactOwned->execute(['dup-1', $CALLER_STORE]);
$ownedCodes = $exactOwned->fetchAll(PDO::FETCH_COLUMN);
printf("           TRAIT-2 sees owned  -> %s, so array_diff(['dup-1'], ...) is %s\n",
    json_encode($ownedCodes),
    array_diff(['dup-1'], array_map('trim', $ownedCodes)) === [] ? 'EMPTY (satisfied)' : 'non-empty');

$out("TRAIT-2 is SATISFIED for 'dup-1' -- it cannot be what refuses here",
    array_diff(['dup-1'], array_map('trim', $ownedCodes)) === [],
    'The caller owns the exact string, so the value-accounting limb has nothing to object to. '
    . 'This is what makes the next assertion about TRAIT-1 rather than about the trait as a whole.');

$out("and GUARD-A still REFUSES 'dup-1', which can only be TRAIT-1",
    ! $probe->ask('dup-1', $CALLER_STORE),
    "Store 2's 'DUP-1' is the same value to the database, so the handler's unscoped match reaches "
    . 'it. Deleting TRAIT-1 makes THIS assertion fail -- which is the property the first draft of '
    . 'this script lacked.');

echo "\nSTEP 3 -- the NULL store_id row is outside every store, including the caller's.\n";

$out('GUARD-A refuses a code whose only row has a NULL store_id',
    ! $probe->ask('ORPH-1', $CALLER_STORE),
    'A NULL store_id matches no store_id predicate anywhere in the application, so such a row '
    . 'must not become reachable here either.');

echo "\nSTEP 4 -- AND THE LEGITIMATE REFUND STILL PASSES BOTH GUARD CHECKS.\n";

$legitimateA = $probe->ask('MINE-1', $CALLER_STORE);
$guardB->execute(['MINE-1', $CALLER_STORE, $CALLER_ID]);
$legitimateB = (int) $guardB->fetchColumn() > 0;

printf("           GUARD-A on 'MINE-1' -> %s\n", $legitimateA ? 'ALLOW' : 'REFUSE');
printf("           GUARD-B on 'MINE-1' -> %s\n", $legitimateB ? 'OWNED' : 'not owned');

$out('a same-store, same-customer refund is allowed by both guard checks',
    $legitimateA && $legitimateB,
    'This is the half that matters most: the two steps above would also pass if the guard simply '
    . 'refused everything, which would close the defect by breaking refunds.');

echo "\nSTEP 5 -- SQLite reproduces none of it, which is why this script exists.\n";

$lite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lite->exec('CREATE TABLE purchase_tickets (
    id INTEGER PRIMARY KEY, lottery_code TEXT, customer_id INTEGER, store_id INTEGER
)');
$lite->exec("INSERT INTO purchase_tickets VALUES
    (1, 'abc-1', 7, 2), (2, 'ABC-1', 7, 1)");

$liteStmt = $lite->prepare(
    'SELECT id, store_id FROM purchase_tickets WHERE lottery_code = ? AND customer_id = ?'
);
$liteStmt->execute(['abc-1', $CALLER_ID]);
$liteRows = $liteStmt->fetchAll(PDO::FETCH_ASSOC);

printf("           SQLite handler query on 'abc-1' -> %s\n", json_encode($liteRows));

$out('SQLite matches ONLY the exact-case row, so the suite cannot see this in either direction',
    count($liteRows) === 1 && (int) $liteRows[0]['store_id'] === 2,
    'Same engine-hiding class as P01-SEC-13 and the \'5abc\' coercion. A green Feature suite is '
    . 'not evidence about MySQL here.');

echo "\nSTEP 6 -- dropping the throwaway database.\n";
$pdo->exec("USE `information_schema`");
$pdo->exec("DROP DATABASE `{$database}`");
printf("         dropped %s\n", $database);

echo "\n", $failed ? "RESULT: FAILED\n" : "RESULT: every assertion held\n";
exit($failed ? 1 : 0);
