<?php

/**
 * E13-F — TWO CONCURRENT REPLACEMENT ATTEMPTS ON ONE ORDER, ON A REAL ENGINE.
 * ==========================================================================
 *
 * The handoff lists this as NOT RUN, and it cannot be run in the Feature suite: SQLite has no row
 * locks worth the name, and one PHP process cannot hold two statements in flight. So it lives here
 * with the other evidence that cannot live in the suite.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS ACTUALLY AT RISK
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `OrderReplacement::cancelAndReplace()` guards one-replacement-per-original twice: an early
 * `exists()` check for a cheap answer, and the SAME check re-run INSIDE the transaction, because
 * the early one is a read with nothing to stop a second caller passing it in the same instant.
 * `orders.replaced_order_id` is **indexed, not UNIQUE** — the migration explains why a real FK and
 * a UNIQUE index were both declined — so the database will not refuse a duplicate. The
 * in-transaction check is the only control, and this script is the only place it can be tested.
 *
 * Two concurrent replacements would mean: one cancelled original, TWO replacement orders, and the
 * customer billed twice for goods that moved once.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * HOW A SINGLE PROCESS MEASURES A RACE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The same construction `verify-plan-purchase-intent-on-mysql.php` arrived at after its first
 * version was theatre: two autocommit statements in sequence are not a race, however many
 * connections they use. Connection A opens a transaction and writes; the row is then under A's
 * exclusive lock for exactly the window a second caller lands in, and B is made to meet it.
 *
 * Three facts, in the order a real second attempt encounters them:
 *
 *   a. while A's replacement is uncommitted, B's `SELECT ... FOR UPDATE NOWAIT` on the ORIGINAL
 *      cannot read it -- the lock exists rather than being assumed;
 *   b. B's own insert of a second replacement BLOCKS on that lock rather than overtaking it;
 *   c. once A commits, B's guarded insert finds the replacement already there and writes nothing.
 *
 * (b) is what distinguishes a lock from a sequence, and it is measured by the time it takes.
 *
 * USAGE
 *   E13F_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock E13F_MYSQL_DATABASE=e13f_verify \
 *   E13F_MYSQL_USERNAME=root E13F_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-order-replacement-concurrency-on-mysql.php
 *
 * It creates and drops its own database and refuses a name without 'verify' in it. NEVER point it
 * at a shared server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('E13F_MYSQL_DATABASE') ?: 'e13f_verify';

if (! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] does not contain 'verify'.\n");
    exit(2);
}

$socket = getenv('E13F_MYSQL_SOCKET');

if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate}\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('E13F_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('E13F_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('E13F_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('E13F_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.e13f_bootstrap' => $bootstrap]);
config(['database.connections.e13f_a' => $connection]);
/* The second connection. One cannot race itself. */
config(['database.connections.e13f_b' => $connection]);
config(['cache.default' => 'array']);

$checks = 0;
$failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;

    if ($ok) {
        echo "  ok    {$what}\n";
    } else {
        $failures++;
        echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

echo "E13-F -- two concurrent replacement attempts, verified on MySQL/MariaDB\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('e13f_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('e13f_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $theServerIsUnreachable) {
    fwrite(STDERR, "Could not create the throwaway database: {$theServerIsUnreachable->getMessage()}\n");
    exit(2);
}

try {
    $a = DB::connection('e13f_a');
    $b = DB::connection('e13f_b');

    echo "server: " . $a->selectOne('select version() as v')->v . "\n\n";

    /*
     * Only the columns this question needs, shaped after the real `orders` table plus the additive
     * column. `replaced_order_id` is declared **UNIQUE**, matching migration
     * `2026_11_01_000100_add_unique_index_to_orders_replaced_order_id`.
     *
     * **[2026-11-01] THIS CHANGED, AND THE OLD SHAPE IS WHY.** This script used to declare the
     * column merely INDEXED and assert that as a premise, concluding that "the in-transaction guard
     * is doing the work, not the database". That conclusion was correct and it was the problem: a
     * SELECT inside a transaction is not a concurrency guard, because two transactions can both
     * pass it before either inserts -- a read of a NON-unique index takes no gap lock under
     * REPEATABLE READ. The owner's directive asked for "an actual database-enforced invariant where
     * necessary", so the invariant now exists and this script proves the database refuses the
     * second writer rather than proving that it does not have to.
     *
     * ENGINE=InnoDB is forced because the row-lock claim does not transfer to any other engine.
     */
    $a->statement('CREATE TABLE orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        replaced_order_id BIGINT UNSIGNED NULL,
        product_order_id VARCHAR(255) NULL,
        delivered_status TINYINT NOT NULL DEFAULT 0,
        store_id BIGINT UNSIGNED NULL,
        final_price DECIMAL(16,4) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
        UNIQUE KEY orders_replaced_order_id_unique (replaced_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $a->table('orders')->insert([
        'product_order_id' => 'ORD-ORIGINAL',
        'delivered_status' => 0,
        'store_id' => 1,
        'final_price' => '200.0000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $originalId = (int) $a->table('orders')->where('product_order_id', 'ORD-ORIGINAL')->value('id');

    check('the original exists and is live', $originalId > 0);

    // ---------------------------------------------------------------- 1. THE PREMISE, STATED
    //
    // The invariant has to BE there for the checks below to mean anything, and it has to be the
    // same shape the migration creates. Asserted rather than trusted.
    $indexes = $a->select("SHOW INDEX FROM orders WHERE Column_name = 'replaced_order_id'");
    $unique = array_filter($indexes, fn ($i) => (int) $i->Non_unique === 0);

    check(
        'premise: replaced_order_id is UNIQUE, as migration 2026_11_01_000100 declares it',
        count($unique) > 0,
        'the column is not unique here, so this script is testing a schema the migration does not '
        . 'create and the database-refusal check below would prove nothing'
    );

    // MULTIPLE NULLS MUST STILL BE PERMITTED, or the index would break every ordinary order.
    $a->table('orders')->insert([
        ['product_order_id' => 'ORD-NULL-1', 'store_id' => 1, 'final_price' => '1.0000',
         'created_at' => now(), 'updated_at' => now()],
        ['product_order_id' => 'ORD-NULL-2', 'store_id' => 1, 'final_price' => '1.0000',
         'created_at' => now(), 'updated_at' => now()],
    ]);

    check(
        'two orders with replaced_order_id NULL coexist, so ordinary orders are unaffected',
        (int) $a->table('orders')->whereNull('replaced_order_id')->count() >= 3,
        'the unique index is rejecting NULLs, which would make every non-replacement order '
        . 'conflict with every other -- the index must not be added in that case'
    );

    // ----------------------------------------------- 2. A HOLDS ITS REPLACEMENT UNCOMMITTED
    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $a->beginTransaction();

    /* The service's own order: lock the original by updating it (the cancel), then insert the
       replacement. Both are inside A's open transaction. */
    $a->table('orders')->where('id', $originalId)->update(['delivered_status' => 2, 'updated_at' => now()]);
    $a->table('orders')->insert([
        'replaced_order_id' => $originalId,
        'product_order_id' => 'R-ORD-ORIGINAL-A',
        'delivered_status' => 0,
        'store_id' => 1,
        'final_price' => '200.0000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    check('connection A cancels the original and writes its replacement inside one transaction', true);

    // (a) the original is really locked.
    $lockHeld = false;
    $driverCode = 0;
    $cannotEvaluate = false;

    try {
        $b->select('select id from orders where id = ? for update nowait', [$originalId]);
    } catch (Throwable $theRowWasLocked) {
        /* The driver NUMBER, not the message -- `QueryException::formatMessage()` appends the full
           SQL to every message, so a substring test for 'nowait' is true for a parse error and a
           missing table alike. 3572 is MySQL's ER_LOCK_NOWAIT; MariaDB has no such code and
           raises 1205 instead (measured on 10.11.14). 1064 means the statement never ran. */
        $previous = $theRowWasLocked->getPrevious();
        $pdo = $previous instanceof PDOException ? $previous : ($theRowWasLocked instanceof PDOException ? $theRowWasLocked : null);
        $driverCode = (int) ($pdo && isset($pdo->errorInfo[1]) ? $pdo->errorInfo[1] : 0);
        $lockHeld = $driverCode === 3572 || $driverCode === 1205;
        $cannotEvaluate = $driverCode === 1064;
    }

    if ($cannotEvaluate) {
        echo "  SKIP  FOR UPDATE NOWAIT is unsupported here (1064); the blocking check below still\n";
        echo "        carries the claim.\n";
    } else {
        check(
            'while A holds it, B cannot read the original for update -- the lock is real (driver ' . $driverCode . ')',
            $lockHeld,
            $driverCode === 0
                ? 'FOR UPDATE NOWAIT SUCCEEDED, so A holds no lock and nothing below is a race'
                : 'it failed with driver code ' . $driverCode . ', which is neither 3572 nor 1205'
        );
    }

    // (b) B's own attempt BLOCKS rather than overtaking.
    $blockedFor = microtime(true);
    $bWasBlocked = false;

    try {
        /* The service cancels the original first, so this is where a second caller meets the
           lock -- before it ever gets to the replacement insert. */
        $b->table('orders')->where('id', $originalId)->update(['delivered_status' => 2, 'updated_at' => now()]);
    } catch (Throwable $theSecondAttemptWaited) {
        $previous = $theSecondAttemptWaited->getPrevious();
        $pdo = $previous instanceof PDOException ? $previous : ($theSecondAttemptWaited instanceof PDOException ? $theSecondAttemptWaited : null);
        $bWasBlocked = (int) ($pdo && isset($pdo->errorInfo[1]) ? $pdo->errorInfo[1] : 0) === 1205;
    }

    $blockedFor = microtime(true) - $blockedFor;

    check(
        'B\'s own cancel BLOCKS on the row lock rather than overtaking it (' . number_format($blockedFor, 2) . 's)',
        $bWasBlocked,
        'B returned without waiting, so the two attempts never contended and the checks below are '
        . 'a sequence rather than a race'
    );

    $a->commit();

    // ------------------------------------------- 3. AFTER A COMMITS, B's GUARD FINDS THE ROW
    //
    // This is the in-transaction `exists()` check the service performs, replayed against the real
    // engine: a second attempt arriving after the first has committed must find the replacement and
    // write nothing.
    $alreadyReplaced = $b->table('orders')->where('replaced_order_id', $originalId)->exists();

    check('after A commits, B\'s guard sees the replacement already exists', $alreadyReplaced);

    if (! $alreadyReplaced) {
        /* Only reached if the guard would have passed -- i.e. the defect. Written so the failure
           message below reports the real count rather than a hypothetical. */
        $b->table('orders')->insert([
            'replaced_order_id' => $originalId,
            'product_order_id' => 'R-ORD-ORIGINAL-B',
            'delivered_status' => 0,
            'store_id' => 1,
            'final_price' => '200.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --------------------------------- 3b. THE DATABASE ITSELF REFUSES A SECOND REPLACEMENT
    //
    // The checks above show the SERVICE's guard answering correctly. This shows that the guard is
    // not the only thing standing between one original and two replacements: a writer that skips
    // every PHP check -- a second app server, a console command, a future caller that forgets --
    // is refused by the schema. Error 1062 is MySQL/MariaDB's duplicate-entry code.
    $duplicateRefused = false;
    $duplicateCode = null;

    try {
        $b->table('orders')->insert([
            'replaced_order_id' => $originalId,
            'product_order_id' => 'R-ORD-ORIGINAL-BYPASS',
            'delivered_status' => 0,
            'store_id' => 1,
            'final_price' => '200.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    } catch (Throwable $theDatabaseRefusedIt) {
        $duplicateCode = (int) ($theDatabaseRefusedIt->errorInfo[1] ?? 0);
        $duplicateRefused = $duplicateCode === 1062;
    }

    check(
        'a writer that bypasses every PHP guard is refused by the UNIQUE index (1062)',
        $duplicateRefused,
        $duplicateCode === null
            ? 'the insert SUCCEEDED, so "one replacement per original" rests entirely on PHP'
            : 'it failed with code ' . $duplicateCode . ' rather than 1062, so the refusal is not '
              . 'the unique index'
    );

    $replacements = (int) $a->table('orders')->where('replaced_order_id', $originalId)->count();

    check(
        'ONE original, ONE replacement (' . $replacements . ')',
        $replacements === 1,
        'two replacements exist for one original, so the customer is billed twice for goods that '
        . 'moved once'
    );

    check(
        'and the original is cancelled exactly once',
        (int) $a->table('orders')->where('id', $originalId)->value('delivered_status') === 2
    );

    echo "\n" . str_repeat('=', 78) . "\n";
    echo "checks  : {$checks}\nfailures: {$failures}\n";

    if ($failures === 0) {
        echo "\nALL ASSERTIONS HELD on " . $a->selectOne('select version() as v')->v . ".\n";
        echo "Two concurrent replacement attempts on one order produce exactly one replacement; the\n";
        echo "second genuinely BLOCKS on the first's row lock; and `replaced_order_id` is UNIQUE, so a\n";
        echo "writer that bypasses every PHP guard is refused by the database with 1062. The lock\n";
        echo "serialises and the index guarantees -- D-15 condition 8 does not rest on a SELECT.\n";
    }
} catch (Throwable $theVerificationFailed) {
    fwrite(STDERR, "\nUNEXPECTED: {$theVerificationFailed->getMessage()}\n");
    fwrite(STDERR, "  at {$theVerificationFailed->getFile()}:{$theVerificationFailed->getLine()}\n");
    $failures++;
} finally {
    try {
        DB::connection('e13f_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ndropped the throwaway database `{$database}`.\n";
    } catch (Throwable $theCleanupFailed) {
        fwrite(STDERR, "could not drop `{$database}`: {$theCleanupFailed->getMessage()}\n");
    }
}

exit($failures === 0 ? 0 : 1);
