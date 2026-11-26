<?php

/**
 * GAP-121 / `P04-EV-01` (4 of 4) -- THE ORDER-WRITE TRANSACTION BOUNDARY, on real MySQL/MariaDB.
 * =================================================================================================
 *
 * WHY THIS SCRIPT EXISTS, IN THE SUITE'S OWN WORDS. `OrderWriteTransactionTest`'s docblock says:
 *
 *     "WHY THESE ASSERTIONS ARE STRUCTURAL, stated rather than implied. Demonstrating a rollback
 *      behaviourally means making one of the child-row writes fail in the middle of a method that
 *      first needs a store, theme, plan, customer, cart, billing, coupon, tax-method and
 *      payment-settings fixture. The suite does not have that fixture and does not pretend to."
 *
 * So the suite checks WHERE the boundary sits, by reading the source, and says plainly that it does
 * not check that the boundary WORKS. This script checks that it works, and it has to be here rather
 * than in the suite for two structural reasons that no amount of care inside PHPUnit removes:
 *
 *   - `phpunit.xml` runs SQLite `:memory:`, and an in-memory SQLite database is private to ONE
 *     connection. There is no second session that could observe whether uncommitted rows are
 *     visible, which is half of what a transaction boundary means.
 *   - `RefreshDatabase` wraps every test in a transaction of its own. A `DB::transaction()` inside
 *     it is therefore a SAVEPOINT, not a transaction -- §E measures exactly that -- so a test
 *     cannot tell a real rollback from a savepoint rollback, and "the rows are gone" holds either
 *     way because the wrapping transaction discards them at teardown regardless.
 *
 * TWO NAMED CONNECTIONS, not forks. This is the siblings' pattern
 * (`verify-cart-reconciliation-concurrency-on-mysql.php`,
 * `verify-pos-sale-claim-concurrency-on-mysql.php`): two real server sessions in one process, which
 * is the right instrument for a boundary you want to step through deliberately and observe from
 * outside. Nothing here is a race, so forking would add nondeterminism and measure nothing extra.
 *
 * THE INSTRUMENT IS PROVED BEFORE THE BOUNDARY (§B): a COMMITTED row must be visible to the second
 * session. A script whose second session could never see anything would report every "not visible"
 * assertion as a pass while measuring a broken connection.
 *
 * AND THE ENGINE ITSELF IS ASSERTED (§A, §F). InnoDB is what makes any of this true: a MyISAM table
 * accepts `ROLLBACK` and silently keeps the row. §F demonstrates that on this very server, so §A's
 * engine check is known to be load-bearing rather than decorative.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and refuses
 * a name that does not say it is throwaway:
 *
 *     php tests/Support/Security/bin/verify-order-write-transaction-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server. Exits 2 -- not 0 and
 * not 1 -- when a precondition is unmet, because a verification that could not run has neither
 * passed nor failed.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$checks = 0;
$failures = 0;

/**
 * The CODE this script is about, read from source with comments stripped.
 *
 * WHY THIS IS HERE. Some sections below reproduce a production expression rather than calling it,
 * because calling it needs a store, theme, plan, customer, cart, billing, coupon, tax-method and
 * payment-settings fixture -- the same reason `OrderWriteTransactionTest`'s own docblock gives for
 * being structural. A reproduction that drifts from its original measures nothing, so each one is
 * tied to the source line it reproduces. Comments are stripped, because a docblock quoting the
 * expression would otherwise satisfy the tie (CLAUDE.md records that trap eight times).
 */
function sourceCodeOf(string $relativePath): string
{
    $absolute = __DIR__ . '/../../../../' . $relativePath;

    if (! is_file($absolute)) {
        fwrite(STDERR, "PRECONDITION NOT MET: {$relativePath} is absent, so this script cannot tell\n");
        fwrite(STDERR, "whether the expressions it reproduces still match production.\n");
        exit(2);
    }

    $code = '';
    foreach (token_get_all((string) file_get_contents($absolute)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

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

/* ---------------------------------------------------------------- preconditions */

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'order_write_transaction_verify';

if (! preg_match('/(fresh|verify)/i', $database)) {
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
    /* Production's setting (config/database.php). Strict mode refuses writes production accepts. */
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh' => $connection]);    // session A: the writer
config(['database.connections.fresh_b' => $connection]);  // session B: the observer
config(['database.default' => 'fresh']);

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
} catch (Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: could not create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$server = DB::connection('fresh')->selectOne('SELECT VERSION() AS v')->v;
echo "server  : {$server}\n";
echo "database: {$database}\n\n";

try {
    /* The five tables `OrderWriteTransactionTest` names as the boundary's contents: the order, its
       billing detail, its coupon detail, the UserCoupon consumption row and its tax details.
       InnoDB explicitly, because that is the whole mechanism and §F shows what MyISAM would do. */
    DB::connection('fresh')->statement('
        CREATE TABLE orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            final_price DOUBLE NULL DEFAULT 0,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE order_billing_details (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NULL,
            billing_address VARCHAR(191) NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE order_coupon_details (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NULL,
            coupon_id BIGINT UNSIGNED NULL,
            amount DOUBLE NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE user_coupons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            coupon_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            amount DOUBLE NOT NULL DEFAULT 0,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ');
    /* `amount` is NOT NULL with no default, which is what §D uses to make a child write fail the
       way a real one would -- a column the writer must supply and a row that cannot be stored
       without it. Under `strict => false` a MISSING column is coerced, so §D fails it with an
       explicit NULL instead, and §D asserts the throw rather than assuming it. */
    DB::connection('fresh')->statement('
        CREATE TABLE order_tax_details (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NULL,
            tax_name VARCHAR(191) NULL,
            amount DOUBLE NOT NULL
        ) ENGINE=InnoDB
    ');

    $tables = ['orders', 'order_billing_details', 'order_coupon_details', 'user_coupons', 'order_tax_details'];

    /** What session B -- a genuinely separate server session -- can see. */
    $bSees = function (string $table) {
        return (int) DB::connection('fresh_b')->table($table)->count();
    };

    $truncateAll = function () use ($tables) {
        foreach ($tables as $t) {
            DB::connection('fresh')->table($t)->truncate();
        }
    };

    /** The five writes the boundary wraps, as one unit. */
    $writeTheOrder = function (bool $breakTheLastChild = false) {
        $orderId = DB::connection('fresh')->table('orders')->insertGetId([
            'store_id' => 1, 'customer_id' => 7, 'final_price' => 267.00,
        ]);
        DB::connection('fresh')->table('order_billing_details')->insert([
            'order_id' => $orderId, 'billing_address' => 'x',
        ]);
        DB::connection('fresh')->table('order_coupon_details')->insert([
            'order_id' => $orderId, 'coupon_id' => 3, 'amount' => 30.00,
        ]);
        DB::connection('fresh')->table('user_coupons')->insert([
            'user_id' => 7, 'coupon_id' => 3, 'amount' => 30.00, 'order_id' => $orderId,
        ]);
        DB::connection('fresh')->table('order_tax_details')->insert([
            'order_id' => $orderId, 'tax_name' => 'band',
            // An explicit NULL into a NOT NULL column: refused even under strict => false.
            'amount' => $breakTheLastChild ? null : 22.00,
        ]);

        return $orderId;
    };

    // ─────────────────────────── [A] the premise: the engine, and two real sessions
    echo "[A] THE PREMISE -- InnoDB, and two genuinely separate server sessions\n";

    /* THE BOUNDARY THIS SCRIPT IS ABOUT STILL EXISTS. This script verifies the ENGINE's half --
       isolation, rollback, and the savepoint trap -- for a boundary whose PLACEMENT relative to the
       writes and the external calls is asserted by `OrderWriteTransactionTest`. It deliberately
       does not re-assert the placement. But if the boundary were deleted the two halves would be
       about different code and this script would go on passing, so its existence is tied here. */
    check(
        'the order writer still opens a transaction, so the two halves are about the same code',
        str_contains(
            sourceCodeOf('app/Services/PaymentService.php'),
            'DB::beginTransaction()'
        ),
        'no transaction boundary remains in PaymentService, so OrderWriteTransactionTest\'s subject '
        . 'is gone and this script is verifying the engine for a boundary that no longer exists'
    );

    $engines = [];
    foreach (DB::connection('fresh')->select('SHOW TABLE STATUS') as $t) {
        $engines[$t->Name] = $t->Engine;
    }
    $allInnodb = true;
    foreach ($tables as $t) {
        $allInnodb = $allInnodb && (strtolower($engines[$t] ?? '') === 'innodb');
    }
    check(
        'all five tables are InnoDB, without which no assertion below means anything',
        $allInnodb,
        json_encode(array_intersect_key($engines, array_flip($tables)))
    );

    $idA = DB::connection('fresh')->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $idB = DB::connection('fresh_b')->selectOne('SELECT CONNECTION_ID() AS id')->id;
    check(
        'the two connections are different server sessions, not one handle reused',
        (int) $idA !== (int) $idB,
        "A={$idA} B={$idB} -- if these were equal, B would see A's uncommitted rows and §C would "
        . 'be measuring nothing'
    );

    $isolation = DB::connection('fresh_b')->selectOne('SELECT @@SESSION.tx_isolation AS i')->i
        ?? DB::connection('fresh_b')->selectOne('SELECT @@SESSION.transaction_isolation AS i')->i;
    printf("        observer isolation level: %s\n", $isolation);

    // ─────────────────────────── [B] the instrument
    echo "\n[B] THE INSTRUMENT -- a COMMITTED order IS visible to the observer\n";

    $truncateAll();
    DB::connection('fresh')->transaction(function () use ($writeTheOrder) {
        $writeTheOrder();
    });

    $visible = [];
    foreach ($tables as $t) {
        $visible[$t] = $bSees($t);
    }
    check(
        'after a commit the observer sees all five rows',
        array_values($visible) === [1, 1, 1, 1, 1],
        json_encode($visible)
        . ' -- if the observer can never see anything, every "not visible" pass below is vacuous'
    );

    // ─────────────────────────── [C] the boundary, observed from outside
    echo "\n[C] THE BOUNDARY -- uncommitted rows are invisible, and a rollback removes all five\n";

    $truncateAll();

    DB::connection('fresh')->beginTransaction();
    $writeTheOrder();

    $midFlight = [];
    foreach ($tables as $t) {
        $midFlight[$t] = $bSees($t);
    }
    check(
        'while the transaction is OPEN the observer sees none of the five rows',
        array_values($midFlight) === [0, 0, 0, 0, 0],
        json_encode($midFlight)
        . ' -- a half-written order was visible to another session, which is what the boundary exists '
        . 'to prevent'
    );

    /* And the writer DOES see its own uncommitted rows, which is what makes the line above a
       statement about isolation rather than about the writes having failed. */
    check(
        'but the WRITER sees its own uncommitted order, so the writes really happened',
        (int) DB::connection('fresh')->table('orders')->count() === 1,
        'writer count=' . DB::connection('fresh')->table('orders')->count()
    );

    DB::connection('fresh')->rollBack();

    $afterRollback = [];
    foreach ($tables as $t) {
        $afterRollback[$t] = $bSees($t);
    }
    check(
        'after the ROLLBACK all five tables are empty, for the observer and for anyone',
        array_values($afterRollback) === [0, 0, 0, 0, 0],
        json_encode($afterRollback)
    );
    printf("        five tables, all empty after rollback: %s\n", json_encode($afterRollback));

    // ─────────────────────────── [D] the behavioural rollback the suite declares undone
    echo "\n[D] A CHILD WRITE FAILS MID-BOUNDARY -- the case the suite says it cannot build\n";

    $truncateAll();

    $threw = null;
    try {
        DB::connection('fresh')->transaction(function () use ($writeTheOrder) {
            $writeTheOrder(true);   // the last child row is refused by the engine
        });
    } catch (Throwable $e) {
        $threw = $e;
    }

    check(
        'the engine refused the child row, so this case really exercises a mid-boundary failure',
        $threw !== null,
        'nothing threw -- the NOT NULL column accepted a NULL, so §D is not testing a failure. '
        . 'Under strict => false a MISSING column is coerced; an explicit NULL must not be'
    );
    if ($threw !== null) {
        printf("        refused with: %s\n", substr(preg_replace('/\s+/', ' ', $threw->getMessage()), 0, 110));
    }

    $afterFailure = [];
    foreach ($tables as $t) {
        $afterFailure[$t] = $bSees($t);
    }
    check(
        'and NO order, billing row, coupon detail or coupon consumption survives the failure',
        array_values($afterFailure) === [0, 0, 0, 0, 0],
        json_encode($afterFailure)
        . ' -- a surviving row here is precisely the defect the boundary was added for: an order '
        . 'with no billing address, or a coupon consumed with nothing recording what for'
    );
    printf("        after a mid-boundary failure: %s\n", json_encode($afterFailure));

    // ─────────────────────────── [E] the savepoint trap the suite cannot escape
    echo "\n[E] THE SAVEPOINT TRAP -- why a RefreshDatabase-wrapped test cannot measure §C or §D\n";

    $truncateAll();

    /* RefreshDatabase's wrapping transaction, reproduced exactly: an outer transaction, then the
       application's own `DB::transaction()` inside it. */
    DB::connection('fresh')->beginTransaction();
    $outerLevel = DB::connection('fresh')->transactionLevel();

    $innerThrew = null;
    try {
        DB::connection('fresh')->transaction(function () use ($writeTheOrder) {
            $writeTheOrder(true);
        });
    } catch (Throwable $e) {
        $innerThrew = $e;
    }

    $levelAfter = DB::connection('fresh')->transactionLevel();

    check(
        'an inner transaction nested inside an outer one is a SAVEPOINT, not a transaction',
        $outerLevel === 1 && $levelAfter === 1,
        "outer level={$outerLevel} level after the inner rollback={$levelAfter}"
    );

    /* THE POINT: a row written OUTSIDE the inner block but INSIDE the outer one survives the inner
       rollback. So "the table is empty" at the end of a wrapped test is the OUTER rollback talking,
       whatever the inner boundary did -- which is why the suite's own docblock calls its assertions
       structural and why this script exists. */
    DB::connection('fresh')->table('orders')->insert([
        'store_id' => 1, 'customer_id' => 99, 'final_price' => 1.00,
    ]);
    $survivesInner = (int) DB::connection('fresh')->table('orders')->count();

    check(
        'a row in the OUTER transaction survives the inner rollback, so emptiness proves nothing',
        $survivesInner === 1,
        "writer sees {$survivesInner} order(s) after the inner block rolled back"
    );

    DB::connection('fresh')->rollBack();

    check(
        'and the outer rollback then removes it, which is what a wrapped test actually measures',
        $bSees('orders') === 0,
        'observer sees ' . $bSees('orders') . ' order(s)'
    );

    // ─────────────────────────── [F] why §A's engine check is load-bearing
    echo "\n[F] THE ENGINE IS THE MECHANISM -- MyISAM accepts a ROLLBACK and keeps the row\n";

    DB::connection('fresh')->statement('
        CREATE TABLE orders_myisam (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NULL
        ) ENGINE=MyISAM
    ');

    $myisamEngine = DB::connection('fresh')->selectOne(
        "SELECT ENGINE e FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'orders_myisam'",
        [$database]
    )->e;

    if (strtolower((string) $myisamEngine) !== 'myisam') {
        /* The server substituted an engine, so this demonstration is not available. Reported as a
           skipped SUB-CASE rather than a failure: the point of §F is to show §A matters, and a
           server that cannot make a MyISAM table cannot show it. */
        echo "        this sub-case is not available: the server made orders_myisam "
            . var_export($myisamEngine, true) . " rather than MyISAM\n";
    } else {
        DB::connection('fresh')->beginTransaction();
        DB::connection('fresh')->table('orders_myisam')->insert(['store_id' => 1]);
        DB::connection('fresh')->rollBack();

        check(
            'a MyISAM row SURVIVES a rollback, so §A\'s InnoDB assertion is doing real work',
            (int) DB::connection('fresh')->table('orders_myisam')->count() === 1,
            'count=' . DB::connection('fresh')->table('orders_myisam')->count()
            . ' -- if this is 0 the server is rolling MyISAM back and §F has nothing to show'
        );
        printf("        a MyISAM table kept its row through a ROLLBACK; the five real tables did not\n");
    }
} finally {
    try {
        DB::connection('fresh')->disconnect();
        DB::connection('fresh_b')->disconnect();
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\n(dropped throwaway database {$database})\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop the throwaway database: {$e->getMessage()}\n");
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "{$checks} checks, {$failures} failures\n";

exit($failures === 0 ? 0 : 1);
