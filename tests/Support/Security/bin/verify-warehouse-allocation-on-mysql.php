<?php

/**
 * `P04-P4-02` / `INV-001` — THE WAREHOUSE ALLOCATION'S CONDITIONAL UPDATE AND ITS SCHEMA,
 * VERIFIED ON REAL MySQL.
 * =================================================================================================
 *
 * WHY THIS SCRIPT HAS TO EXIST, and it is three separate reasons rather than one.
 *
 *  1. **LOCKING.** `App\Services\WarehouseInventory::sourceFrom()` issues one conditional
 *     `UPDATE product_warehouse_stock SET quantity = quantity - ? WHERE ... AND quantity >= ?` and
 *     reads an affected-row count of 0 as "somebody else got there first". Whether two transactions
 *     touching the same allocation row really serialise, and whether the second one's predicate is
 *     re-evaluated against the first one's COMMITTED value, is a property only a concurrent engine
 *     has. SQLite serialises writers at the database level, so the suite models the interleaving in
 *     PHP; here the row lock is observed.
 *
 *  2. **AN UNSIGNED COLUMN UNDER `strict => false`.** `product_warehouse_stock.quantity` is
 *     `unsignedInteger`. **SQLite DOES NOT ENFORCE THAT AT ALL** — type affinity, which CLAUDE.md
 *     §3 records as how P01-SEC-13 survived — so the suite cannot show what MySQL does when a
 *     decrement would take the column below zero. Production runs `strict => false`
 *     (`config/database.php`), and the answer decides whether a hypothetical escape from the
 *     `quantity >= ?` predicate would CLAMP silently or ERROR. Measured here rather than assumed,
 *     in both the predicated and the unpredicated shape.
 *
 *  3. **THE UNIQUE INDEX IS TOTAL.** The migration's header argues that `variant_id` is NOT NULL
 *     DEFAULT 0 rather than nullable *because* the SQL standard treats NULLs as distinct, so a
 *     nullable column would let two allocations for the same simple product both insert. That is a
 *     claim about the engine, and it is asserted here against both spellings so the argument is
 *     measured rather than quoted.
 *
 * A FOURTH THING IS MEASURED AND IS NOT A PASS/FAIL CRITERION: whether `updateOrInsert()` — which
 * `allocate()` uses — races into a duplicate-key error when two merchants save the same product's
 * allocation at the same instant. It does, and the finding is reported rather than silently fixed,
 * because the remedy (a real upsert) changes a merchant-facing write path.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and refuses
 * a name without 'fresh' or 'verify' in it, like its siblings:
 *
 *     service mysql start   # or mariadb
 *     php tests/Support/Security/bin/verify-warehouse-allocation-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'warehouse_verify';

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
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,                     // production's setting (config/database.php)
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

/** A measurement that is REPORTED and cannot fail the run. */
function note(string $what, string $answer): void
{
    echo "  note  {$what}: {$answer}\n";
}

echo "P04-P4-02 / INV-001 -- the warehouse allocation on MySQL\n";
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

    /* PORTABLE ACROSS MySQL AND MariaDB. `@@transaction_isolation` is MySQL 5.7+ and MariaDB 10.11
       does not have it; `@@tx_isolation` is MariaDB's and MySQL 8.0 removed it. Asked by trial, and
       REPORTED rather than asserted -- the checks below depend on InnoDB, not on the level. This is
       the exact lesson `verify-stock-oversell-concurrency-on-mysql.php` records. */
    $server = $a->select('SELECT VERSION() AS v, @@default_storage_engine AS e')[0];
    $isolation = 'unknown';
    foreach (['transaction_isolation', 'tx_isolation'] as $variable) {
        try {
            $isolation = $a->select('SELECT @@' . $variable . ' AS i')[0]->i;
            break;
        } catch (\Throwable $e) {
            // try the other spelling
        }
    }
    echo "server: {$server->v}  engine: {$server->e}  isolation: {$isolation}\n\n";

    check('the default storage engine is InnoDB (row locks exist at all)',
        strtolower($server->e) === 'innodb', "engine is {$server->e}");

    /* THE TABLE AS THE MIGRATION DECLARES IT. Written out rather than migrated, so the script
       states its own premise and a drift between the two is visible in one diff. */
    $a->statement('
        CREATE TABLE product_warehouse_stock (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            quantity INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            UNIQUE KEY product_warehouse_stock_line_unique (warehouse_id, product_id, variant_id),
            KEY product_warehouse_stock_lookup_idx (store_id, product_id, variant_id)
        ) ENGINE=InnoDB
    ');

    $b->statement('SET SESSION innodb_lock_wait_timeout = 2');

    $reset = function (int $quantity) use ($a): void {
        $a->statement('DELETE FROM product_warehouse_stock');
        $a->statement(
            'INSERT INTO product_warehouse_stock (store_id, warehouse_id, product_id, variant_id, quantity)'
            . ' VALUES (7, 3, 11, 0, ?)',
            [$quantity]
        );
    };

    /* THE EXACT STATEMENT `sourceFrom()` ISSUES, as a closure, so every check drives the same shape
       rather than a paraphrase of it. Returns the affected-row count. */
    $source = function ($conn, int $storeId, int $take): int {
        return $conn->table('product_warehouse_stock')
            ->where('store_id', $storeId)
            ->where('warehouse_id', 3)
            ->where('product_id', 11)
            ->where('variant_id', 0)
            ->where('quantity', '>=', $take)
            ->decrement('quantity', $take);
    };

    // ---------------------------------------------------------------------------------------------
    echo "\nA. THE ROW LOCK IS REAL -- the second sourcing BLOCKS rather than reading around the first\n";
    // ---------------------------------------------------------------------------------------------
    $reset(1);

    $a->beginTransaction();
    $appliedA = $source($a, 7, 1);
    check('A takes the last allocated unit inside its transaction', $appliedA === 1, "affected={$appliedA}");

    $blocked = false;
    $bError = '';
    try {
        $source($b, 7, 1);
    } catch (Throwable $e) {
        $blocked = str_contains($e->getMessage(), '1205')
            || stripos($e->getMessage(), 'lock wait timeout') !== false;
        $bError = substr($e->getMessage(), 0, 120);
    }
    check("B blocks on A's row lock (error 1205) rather than deciding on a stale read",
        $blocked, $bError !== '' ? $bError : 'B was NOT blocked -- it completed while A held the row');

    // ---------------------------------------------------------------------------------------------
    echo "\nB. ONCE A COMMITS, B's PREDICATE IS RE-EVALUATED AGAINST THE COMMITTED VALUE\n";
    // ---------------------------------------------------------------------------------------------
    $a->commit();

    $afterA = (int) $a->table('product_warehouse_stock')->value('quantity');
    check('the committed allocation is 0 after A', $afterA === 0, "quantity={$afterA}");

    $appliedB = $source($b, 7, 1);
    check('B is REFUSED -- 0 rows affected, because quantity >= 1 is false now',
        $appliedB === 0, "affected={$appliedB}");

    $final = (int) $a->table('product_warehouse_stock')->value('quantity');
    check('THE INVARIANT HOLDS: the allocation never went below zero, so the derived default '
        . 'remainder cannot have been inflated', $final === 0, "quantity={$final}");

    // ---------------------------------------------------------------------------------------------
    echo "\nC. TEN CONCURRENT SOURCINGS, FIVE ALLOCATED UNITS -- the engine decides, not PHP\n";
    // ---------------------------------------------------------------------------------------------
    $reset(5);

    $staleReads = [];
    for ($i = 0; $i < 10; $i++) {
        $staleReads[] = (int) $a->table('product_warehouse_stock')->value('quantity');
    }

    $granted = 0;
    for ($i = 0; $i < 10; $i++) {
        $granted += $source($i % 2 === 0 ? $a : $b, 7, 1) > 0 ? 1 : 0;
    }

    check('every caller read 5 before any of them wrote (the real burst interleaving)',
        array_unique($staleReads) === [5], 'reads: ' . implode(',', array_unique($staleReads)));
    check('exactly 5 of 10 sourcings are granted', $granted === 5, "granted={$granted}");
    check('the allocation lands on exactly 0',
        (int) $a->table('product_warehouse_stock')->value('quantity') === 0);

    // ---------------------------------------------------------------------------------------------
    echo "\nD. THE UNSIGNED COLUMN UNDER `strict => false` -- what SQLite cannot show\n";
    // ---------------------------------------------------------------------------------------------
    /* The predicated shape FIRST, because that is what the service actually issues: it must refuse
       rather than rely on the column. */
    $reset(2);
    $refused = $source($a, 7, 5);
    check('the PREDICATED decrement refuses an over-take (0 rows), so the column is never tested',
        $refused === 0, "affected={$refused}");
    check('and the allocation is untouched at 2',
        (int) $a->table('product_warehouse_stock')->value('quantity') === 2);

    /* NOW THE UNPREDICATED SHAPE, which the service never issues. This measures what the column
       would do if a future change dropped the predicate -- the question SQLite answers wrongly,
       because it enforces no UNSIGNED range at all. */
    $reset(2);
    $engineAnswer = 'no error';
    try {
        $a->table('product_warehouse_stock')->where('product_id', 11)->decrement('quantity', 5);
    } catch (Throwable $e) {
        $engineAnswer = 'raised: ' . substr($e->getMessage(), 0, 90);
    }
    $afterUnderflow = (int) $a->table('product_warehouse_stock')->value('quantity');

    note('an UNPREDICATED under-zero decrement on the UNSIGNED column', $engineAnswer);
    note('  the stored value afterwards', (string) $afterUnderflow);
    check('WITHOUT the predicate the column does NOT store a negative figure '
        . '(it clamps or errors; SQLite would store -3)',
        $afterUnderflow >= 0, "quantity={$afterUnderflow}");

    // ---------------------------------------------------------------------------------------------
    echo "\nE. THE UNIQUE INDEX IS TOTAL, which is why variant_id is NOT NULL DEFAULT 0\n";
    // ---------------------------------------------------------------------------------------------
    $a->statement('DELETE FROM product_warehouse_stock');
    $a->statement('INSERT INTO product_warehouse_stock (store_id, warehouse_id, product_id, variant_id, quantity) VALUES (7, 3, 11, 0, 4)');

    $duplicateRefused = false;
    try {
        $a->statement('INSERT INTO product_warehouse_stock (store_id, warehouse_id, product_id, variant_id, quantity) VALUES (7, 3, 11, 0, 9)');
    } catch (Throwable $e) {
        $duplicateRefused = str_contains($e->getMessage(), '1062')
            || stripos($e->getMessage(), 'duplicate') !== false;
    }
    check('a SECOND allocation for the same (warehouse, product, simple-product) is REFUSED',
        $duplicateRefused, 'the index did not refuse it -- two rows for one place would exist');

    /* THE OTHER SPELLING, to measure the migration header's own argument rather than quote it. */
    $a->statement('
        CREATE TABLE nullable_variant_probe (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            UNIQUE KEY probe_unique (warehouse_id, product_id, variant_id)
        ) ENGINE=InnoDB
    ');
    $a->statement('INSERT INTO nullable_variant_probe (warehouse_id, product_id, variant_id) VALUES (3, 11, NULL)');
    $nullableAccepted = true;
    try {
        $a->statement('INSERT INTO nullable_variant_probe (warehouse_id, product_id, variant_id) VALUES (3, 11, NULL)');
    } catch (Throwable $e) {
        $nullableAccepted = false;
    }
    $probeRows = (int) $a->table('nullable_variant_probe')->count();

    check('a NULLABLE variant_id WOULD have let two rows for one simple product in '
        . '(the migration header\'s argument, measured)',
        $nullableAccepted && $probeRows === 2,
        "accepted=" . var_export($nullableAccepted, true) . " rows={$probeRows}");

    // ---------------------------------------------------------------------------------------------
    echo "\nF. THE CONCURRENT SAVE -- why allocate() upserts instead of using updateOrInsert()\n";
    // ---------------------------------------------------------------------------------------------
    /* **THIS SECTION FOUND A DEFECT IN `allocate()`'s FIRST FORM AND IS KEPT AS THE EVIDENCE FOR
       THE FIX.** It originally measured `updateOrInsert()`, which is SELECT-then-INSERT and
       therefore not atomic: both callers read "no row" (`false` / `false`) and the second INSERT
       raised `SQLSTATE[23000] 1062 Duplicate entry '3-11-0'`. The unique index contained the
       damage -- one row, never two, so no stock figure was wrong -- but the losing merchant's save
       became a 500. SQLite could not have shown it: it serialises writers at the database level.

       `allocate()` now issues a single `upsert()`, and the two halves below are what that has to
       buy: the lost-update shape is PROVED to still exist for the old spelling, and the upsert is
       proved to resolve it. Asserting only the second would leave "the race cannot happen here"
       as an assumption about the engine. */
    $a->statement('DELETE FROM product_warehouse_stock');

    /* F1 -- THE OLD SHAPE, STILL MEASURED, so the fix has something to be a fix OF. */
    $raced = 'no error';
    try {
        $existsA = $a->table('product_warehouse_stock')
            ->where('warehouse_id', 3)->where('product_id', 11)->where('variant_id', 0)->exists();
        $existsB = $b->table('product_warehouse_stock')
            ->where('warehouse_id', 3)->where('product_id', 11)->where('variant_id', 0)->exists();

        note('updateOrInsert: both callers saw a row already present?',
            var_export($existsA, true) . ' / ' . var_export($existsB, true));

        $a->table('product_warehouse_stock')->insert([
            'store_id' => 7, 'warehouse_id' => 3, 'product_id' => 11, 'variant_id' => 0, 'quantity' => 4,
        ]);
        $b->table('product_warehouse_stock')->insert([
            'store_id' => 7, 'warehouse_id' => 3, 'product_id' => 11, 'variant_id' => 0, 'quantity' => 9,
        ]);
    } catch (Throwable $e) {
        $raced = 'raised: ' . substr($e->getMessage(), 0, 100);
    }

    note('updateOrInsert: the second insert of the racing pair', $raced);
    check('F1 THE OLD SPELLING REALLY DID FAIL the losing save (error 1062), so the fix below is '
        . 'a fix and not a precaution',
        str_contains($raced, '1062') || stripos($raced, 'duplicate') !== false,
        "second insert answered: {$raced}");

    /* F2 -- THE SHIPPED SHAPE. The exact statement `allocate()` issues, from both connections. */
    $a->statement('DELETE FROM product_warehouse_stock');

    $upsert = function ($conn, int $quantity) {
        $conn->table('product_warehouse_stock')->upsert(
            [[
                'store_id' => 7, 'warehouse_id' => 3, 'product_id' => 11, 'variant_id' => 0,
                'quantity' => $quantity,
            ]],
            ['warehouse_id', 'product_id', 'variant_id'],
            ['quantity']
        );
    };

    $upsertError = 'no error';
    try {
        $upsert($a, 4);
        $upsert($b, 9);
    } catch (Throwable $e) {
        $upsertError = 'raised: ' . substr($e->getMessage(), 0, 100);
    }

    check('F2 the upsert raises NOTHING on the second writer', $upsertError === 'no error', $upsertError);
    check('F2 exactly one row exists for that place',
        (int) $a->table('product_warehouse_stock')->count() === 1,
        'rows=' . $a->table('product_warehouse_stock')->count());
    check('F2 the LAST writer wins, which is the right answer for a merchant typing a figure',
        (int) $a->table('product_warehouse_stock')->value('quantity') === 9,
        'quantity=' . $a->table('product_warehouse_stock')->value('quantity'));

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
