<?php

/**
 * P1-INV-05 / ACCEPTANCE CRITERION E3 -- two concurrent checkouts of the last unit cannot
 * oversell, verified on REAL MySQL.
 * =================================================================================================
 *
 * WHY THIS SCRIPT HAD TO EXIST. `14-PHASE-01-ACCEPTANCE-CRITERIA.md` E3 reads: "Concurrent checkout
 * of the last unit cannot oversell -- `P1-INV-05`, on MySQL (SQLite cannot show it)". The criterion
 * names the engine in its own text, and until this script there was no MySQL evidence for it: of the
 * 15 `verify-*-on-mysql.php` scripts, none covered stock. The closest,
 * `verify-refund-freshness-lock-and-limiter-keys-on-mysql.php`, already drives two connections
 * against a `FOR UPDATE` row lock, and this script is built on that pattern.
 *
 * WHAT SQLITE CAN AND CANNOT SHOW, because the distinction is the whole reason for this file.
 * `tests/Feature/Commerce/OrderStockConcurrencyTest.php` is a good test and it passes: it gives ten
 * callers the stale read BEFORE any of them writes and asserts exactly five of ten succeed against
 * five units. That proves the decrement is not a read-modify-write -- the arithmetic happens in SQL,
 * so a stale PHP-side value cannot be written back. What it CANNOT show is the part that only a
 * concurrent engine has: whether two transactions touching the same row at the same instant
 * serialise, and whether the second one's `WHERE product_stock >= qty` is re-evaluated against the
 * first one's COMMITTED value rather than against the value it read before the first began. SQLite
 * serialises writers at the database level, so the interleaving there is modelled in PHP; here it is
 * real, and the row lock is observed rather than assumed.
 *
 * WHAT IS UNDER TEST. `App\Services\ProductStockService::decrementProductStock()` issues exactly one
 * statement -- a conditional `UPDATE ... SET product_stock = product_stock - ? WHERE id = ? AND
 * product_stock >= ?` -- and treats an affected-row count of 0 as a refusal. The guard applies when
 * `stock_order_status = 'not_allow'` (`mustNotOversell()`), and `$allowOversell` drops it for
 * post-capture writers only.
 *
 * HOW TO RUN. Needs a throwaway MySQL. It creates and drops its OWN database and refuses a name
 * without 'fresh' or 'verify' in it, like its siblings:
 *
 *     apt-get install -y mysql-server && service mysql start
 *     php tests/Support/Security/bin/verify-stock-oversell-concurrency-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'oversell_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set FRESH_MYSQL_SOCKET='' to force TCP)\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('FRESH_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('FRESH_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('FRESH_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',   // the project's connection collation (config/database.php:52)
    'prefix' => '',
    'strict' => false,                     // production's setting (config/database.php:55)
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh_a' => $connection]);
config(['database.connections.fresh_b' => $connection]);

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

echo "P1-INV-05 / criterion E3 -- concurrent checkout of the last unit, verified on MySQL\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    $a = DB::connection('fresh_a');
    $b = DB::connection('fresh_b');

    /* PORTABLE ACROSS MySQL AND MariaDB, and this line is why these scripts had never run here.
       `@@transaction_isolation` is MySQL 5.7+; MariaDB 10.11 does not have it and answers
       "Unknown system variable 'transaction_isolation'" (errno 1193), which aborted the whole
       script before a single assertion. `@@tx_isolation` is the MariaDB spelling and MySQL 8.0
       REMOVED it, so neither name works on both engines and the variable has to be asked for by
       trial. MEASURED on MariaDB 10.11.14: the first SELECT raises 1193 and the second answers
       READ-COMMITTED. The engine and version are printed either way, because the assertions below
       depend on InnoDB rather than on the isolation level -- that figure is reported for the
       record, so it must not be able to fail the run. */
    $server = $a->select('SELECT VERSION() AS v, @@default_storage_engine AS e')[0];
    $server->i = 'unknown';
    foreach (['transaction_isolation', 'tx_isolation'] as $isolationVariable) {
        try {
            $server->i = $a->select('SELECT @@' . $isolationVariable . ' AS i')[0]->i;
            break;
        } catch (\Throwable $e) {
            // Try the other spelling; 'unknown' stands if neither exists.
        }
    }
    echo "server: {$server->v}  engine: {$server->e}  isolation: {$server->i}\n\n";

    // InnoDB is the premise: MyISAM has no row locks and no transactions, so every assertion below
    // would be meaningless on it.
    check('the default storage engine is InnoDB (row locks exist at all)',
        strtolower($server->e) === 'innodb', "engine is {$server->e}");

    // The two columns the guard reads, with production's types
    // (create_products_table.php: product_stock INT NOT NULL, stock_order_status VARCHAR NULL).
    $a->statement('
        CREATE TABLE products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_stock INT NOT NULL DEFAULT 0,
            stock_order_status VARCHAR(255) NULL
        ) ENGINE=InnoDB
    ');

    // A short lock-wait timeout, so a blocked statement reports rather than hanging the script.
    $b->statement('SET SESSION innodb_lock_wait_timeout = 2');

    $reset = function (int $stock, string $status = 'not_allow') use ($a): int {
        $a->statement('DELETE FROM products');
        $a->statement('INSERT INTO products (product_stock, stock_order_status) VALUES (?, ?)', [$stock, $status]);
        return (int) $a->table('products')->max('id');
    };

    // The exact statement the service issues, as a closure so every check below drives the SAME
    // shape rather than a paraphrase of it. Returns the affected-row count.
    $guardedDecrement = function ($conn, int $id, int $qty): int {
        return $conn->table('products')
            ->where('id', $id)
            ->where('product_stock', '>=', $qty)
            ->decrement('product_stock', $qty);
    };

    // ---------------------------------------------------------------------------------------------
    echo "\nA. THE ROW LOCK IS REAL -- the second checkout BLOCKS, it does not read around the first\n";
    // ---------------------------------------------------------------------------------------------
    // This is the half SQLite cannot show. A holds an uncommitted decrement of the last unit; B's
    // identical statement must not proceed. If it did, both would succeed and the shop oversells.
    $id = $reset(1);

    $a->beginTransaction();
    $appliedA = $guardedDecrement($a, $id, 1);
    check('A takes the last unit inside its transaction', $appliedA === 1, "affected={$appliedA}");

    $blocked = false;
    $bError = '';
    try {
        $guardedDecrement($b, $id, 1);
    } catch (Throwable $e) {
        $blocked = str_contains($e->getMessage(), '1205') || stripos($e->getMessage(), 'lock wait timeout') !== false;
        $bError = substr($e->getMessage(), 0, 120);
    }
    check('B blocks on A\'s row lock (MySQL error 1205), rather than deciding on a stale read',
        $blocked, $bError !== '' ? $bError : 'B was NOT blocked -- it completed while A held the row');

    // ---------------------------------------------------------------------------------------------
    echo "\nB. AND ONCE A COMMITS, B's PREDICATE IS RE-EVALUATED AGAINST THE COMMITTED VALUE\n";
    // ---------------------------------------------------------------------------------------------
    // The property the criterion actually asks for: B must not get the unit A already took. Note
    // that B is re-driven AFTER the commit, which is what a retrying request does.
    $a->commit();
    $afterA = (int) $a->table('products')->where('id', $id)->value('product_stock');
    check('the committed stock is 0 after A', $afterA === 0, "stock={$afterA}");

    $appliedB = $guardedDecrement($b, $id, 1);
    check('B is REFUSED -- 0 rows affected, because product_stock >= 1 is false now',
        $appliedB === 0, "affected={$appliedB}");

    $final = (int) $a->table('products')->where('id', $id)->value('product_stock');
    check('stock never went below zero on a not_allow product', $final === 0, "stock={$final}");

    // ---------------------------------------------------------------------------------------------
    echo "\nC. TEN CHECKOUTS, FIVE UNITS -- the engine, not PHP, decides who gets one\n";
    // ---------------------------------------------------------------------------------------------
    // Every caller reads 5 BEFORE any of them writes, which is the interleaving a real burst has.
    // On MySQL each UPDATE re-evaluates its own predicate server-side at execution time, so the
    // stale reads cannot be written back.
    $id = $reset(5);
    $staleReads = [];
    for ($i = 0; $i < 10; $i++) {
        $staleReads[] = (int) $a->table('products')->where('id', $id)->value('product_stock');
    }
    check('all ten callers hold the same stale read of 5',
        count(array_unique($staleReads)) === 1 && $staleReads[0] === 5,
        'reads: ' . implode(',', $staleReads));

    $applied = 0;
    $refused = 0;
    for ($i = 0; $i < 10; $i++) {
        if ($guardedDecrement($a, $id, 1) === 1) { $applied++; } else { $refused++; }
    }
    check('exactly five of ten succeed', $applied === 5, "applied={$applied}");
    check('the other five are refused', $refused === 5, "refused={$refused}");
    $final = (int) $a->table('products')->where('id', $id)->value('product_stock');
    check('stock lands exactly at zero', $final === 0, "stock={$final}");

    // ---------------------------------------------------------------------------------------------
    echo "\nD. THE REMOVAL CHECK -- the read-modify-write this replaced DOES oversell here\n";
    // ---------------------------------------------------------------------------------------------
    // The defect the service was built to fix, driven on the same engine so the contrast is
    // measured rather than asserted: two callers read 1, each writes `read - 1`, and the row ends
    // at 0 with TWO units sold. This is what makes section C evidence rather than a tautology.
    $id = $reset(1);
    $readOne = (int) $a->table('products')->where('id', $id)->value('product_stock');
    $readTwo = (int) $b->table('products')->where('id', $id)->value('product_stock');
    $a->table('products')->where('id', $id)->update(['product_stock' => $readOne - 1]);
    $b->table('products')->where('id', $id)->update(['product_stock' => $readTwo - 1]);
    $oversoldTo = (int) $a->table('products')->where('id', $id)->value('product_stock');
    check('the unguarded read-modify-write sells the same unit twice (stock 0, two sales)',
        $readOne === 1 && $readTwo === 1 && $oversoldTo === 0,
        "readA={$readOne} readB={$readTwo} final={$oversoldTo}");

    // ---------------------------------------------------------------------------------------------
    echo "\nE. A BACKORDER PRODUCT STILL GOES NEGATIVE, because that is the configured behaviour\n";
    // ---------------------------------------------------------------------------------------------
    // The guard is conditional on `stock_order_status = 'not_allow'`. Without this check, a
    // too-tight implementation that refused everyone would pass A-C and silently break backorders.
    $id = $reset(1, 'allow');
    $u1 = $a->table('products')->where('id', $id)->decrement('product_stock', 1);
    $u2 = $a->table('products')->where('id', $id)->decrement('product_stock', 1);
    $backorder = (int) $a->table('products')->where('id', $id)->value('product_stock');
    check('an allow-backorder product accepts both and reaches -1',
        $u1 === 1 && $u2 === 1 && $backorder === -1, "stock={$backorder}");

    // ---------------------------------------------------------------------------------------------
    echo "\nF. THE RESTOCK DIRECTION -- a concurrent RETURN must not lose units either\n";
    // ---------------------------------------------------------------------------------------------
    // ADDED WITH P1-INV-05 criterion 1 ("every stock mutation goes through the atomic primitive").
    // Sections A-E cover the DECREMENT. `Order::product_return()` restocked with a
    // read-modify-write -- `$stockProduct->product_stock += $line['qty']; ->save()` -- and the
    // lost update runs the same way in the increment direction: two returns of one unit each
    // should leave stock 2 higher, and a read-modify-write leaves it 1 higher.
    //
    // THIS IS WHY THE EVIDENCE IS HERE AND NOT IN THE SUITE. A PHPUnit test for it is VACUOUS,
    // and I wrote one before realising: staging "the row changed BEFORE the read" is handled
    // correctly by a read-modify-write, so the test passed against unfixed code. The lost update
    // needs the read to happen BEFORE an external change and the write AFTER -- a genuine
    // interleave, which SQLite cannot stage because it serialises writers.

    // F1: the defect, driven directly. Two callers read 10, each writes read + 1, and one unit
    // of returned stock is silently destroyed.
    $id = $reset(10);
    $rmwA = (int) $a->table('products')->where('id', $id)->value('product_stock');
    $rmwB = (int) $b->table('products')->where('id', $id)->value('product_stock');
    $a->table('products')->where('id', $id)->update(['product_stock' => $rmwA + 1]);
    $b->table('products')->where('id', $id)->update(['product_stock' => $rmwB + 1]);
    $lost = (int) $a->table('products')->where('id', $id)->value('product_stock');
    check('the read-modify-write restock LOSES one of two concurrent returns (10 -> 11, not 12)',
        $rmwA === 10 && $rmwB === 10 && $lost === 11,
        "readA={$rmwA} readB={$rmwB} final={$lost}");

    // F2: the primitive, same interleave. Both callers hold the same stale read and both
    // increments still land, because the arithmetic is in the database.
    $id = $reset(10);
    $staleA = (int) $a->table('products')->where('id', $id)->value('product_stock');
    $staleB = (int) $b->table('products')->where('id', $id)->value('product_stock');
    $a->table('products')->where('id', $id)->increment('product_stock', 1);
    $b->table('products')->where('id', $id)->increment('product_stock', 1);
    $kept = (int) $a->table('products')->where('id', $id)->value('product_stock');
    check('the atomic increment keeps BOTH concurrent returns (10 -> 12)',
        $staleA === 10 && $staleB === 10 && $kept === 12,
        "readA={$staleA} readB={$staleB} final={$kept}");

} catch (Throwable $e) {
    $failures++;
    fwrite(STDERR, "\nUNEXPECTED: {$e->getMessage()}\n" . $e->getTraceAsString() . "\n");
} finally {
    try {
        foreach (['fresh_a', 'fresh_b'] as $name) {
            $conn = DB::connection($name);
            if ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
        }
    } catch (Throwable $x) {
        // nothing to do; the database is dropped next
    }
    try {
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ndropped the throwaway database `{$database}`\n";
    } catch (Throwable $x) {
        fwrite(STDERR, "could not drop `{$database}`: {$x->getMessage()}\n");
    }
}

echo str_repeat('-', 78) . "\n";
echo "{$checks} checks, {$failures} failure(s)\n";

exit($failures === 0 ? 0 : 1);
