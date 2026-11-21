<?php

/**
 * P4-F032 -- IS `firstOrCreate` ON `store_wallets` REALLY RACE-SAFE, ON A REAL ENGINE?
 * =================================================================================================
 *
 * THE CLAIM BEING TESTED, which the financial-policy decision package makes and which this script
 * exists to confirm or refute rather than to repeat:
 *
 *     "Forward-only provisioning needs no migration. `store_wallets` already carries
 *      UNIQUE KEY uq_store_wallet_store_id (store_id), so `firstOrCreate` is race-safe at the
 *      database level."
 *
 * THE CONSTRAINT IS REAL -- `database/migrations/2026_11_02_000100_create_store_wallets_table.php`
 * declares `$table->unique('store_id', 'uq_store_wallet_store_id')`, and that file's structure is
 * derived from a restored copy of the production dump. Check 1 below proves it is actually enforced
 * rather than merely declared.
 *
 * BUT "THE CONSTRAINT EXISTS" IS NOT THE SAME CLAIM AS "firstOrCreate IS SAFE", and the gap between
 * them is the whole point of this script. Laravel 11.57's `firstOrCreate()`
 * (vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:615-622) falls through to
 * `createOrFirst()` (:631-638), which is:
 *
 *     try   { withSavepointIfNeeded(fn () => $this->create(...)); }
 *     catch (UniqueConstraintViolationException) { return $this->useWritePdo()->where(...)->first() ?? throw $e; }
 *
 * So recovery from a lost race is a SECOND SELECT, and whether that SELECT can see the row the
 * winner just committed is a property of the ENGINE'S ISOLATION LEVEL, not of Laravel. Under
 * REPEATABLE READ a consistent read is served from the snapshot taken at the transaction's first
 * read -- and `OrderFinancialService::createForOrder()` wraps its whole body in `DB::transaction()`
 * and reads BEFORE it would reach any provisioning call. If the snapshot predates the winner's
 * commit, `first()` returns null and `createOrFirst` RE-THROWS. That exception lands in
 * `OrderObserver::created()`'s catch, the order commits without a financial row, and the merchant's
 * credit is lost -- which is the exact defect the provisioning change is supposed to close. A fix
 * that still loses the credit under concurrency is not a fix.
 *
 * SQLITE CANNOT ANSWER THIS. The suite runs on `:memory:` SQLite, which serialises writers, so a
 * test there would pass whatever MySQL does. That is precisely the class of defect CLAUDE.md §3
 * records SQLite hiding, so the question is asked here, on a real engine, in real concurrent
 * processes -- not with two connections taking turns in one process, which cannot reproduce a true
 * interleaving of INSERTs.
 *
 * WHAT THIS SCRIPT DOES NOT DO. It touches no production data and reads no production database: it
 * creates and drops its own throwaway schema. It asserts nothing about how many wallet rows are
 * missing in production -- that is a read-only reconciliation question with its own queries, and
 * answering it needs a replica this session does not have.
 *
 * PRECONDITIONS, stated rather than assumed:
 *   - a MySQL or MariaDB server this process can reach;
 *   - permission to CREATE and DROP a throwaway database whose name contains "fresh" or "verify";
 *   - `pcntl` for the concurrent arm. Without it checks 3 and 4 are reported SKIPPED, not passed.
 *
 * RUN:
 *     FRESH_MYSQL_SOCKET=/tmp/p5mysql/run/mysqld.sock \
 *     php tests/Support/Security/bin/verify-store-wallet-provisioning-on-mysql.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'store_wallet_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/tmp/p5mysql/run/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('FRESH_MYSQL_PORT') ?: '3307',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('FRESH_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('FRESH_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh' => $connection]);

$checks = 0;
$failures = 0;
$skipped = 0;

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

function skip(string $what, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP  {$what} -- {$why}\n";
}

echo "P4-F032 -- store_wallets provisioning under concurrency, verified on a real engine\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

$conn = DB::connection('fresh');

/*
 * THE ENGINE AND ISOLATION LEVEL ARE REPORTED, NOT ASSERTED.
 *
 * The conclusions below depend on what the engine actually DID, which the checks measure directly.
 * The isolation level is printed because it is the explanation for whichever behaviour appears --
 * and because it differs between MariaDB and MySQL and between this container and production, so a
 * reader needs to know which one produced the numbers.
 *
 * `@@transaction_isolation` is MySQL 5.7+; MariaDB 10.11 does not have it and raises errno 1193.
 * `@@tx_isolation` is the MariaDB spelling and MySQL 8.0 removed it. Neither name works on both,
 * so it is asked for by trial -- and a failure to read it must not fail the run.
 */
$server = $conn->select('SELECT VERSION() AS v, @@default_storage_engine AS e')[0];
$isolation = 'unknown';
foreach (['transaction_isolation', 'tx_isolation'] as $name) {
    try {
        $isolation = $conn->select('SELECT @@' . $name . ' AS i')[0]->i;
        break;
    } catch (Throwable $e) {
        // try the other spelling
    }
}
echo "  engine: {$server->v} / {$server->e} / isolation {$isolation}\n";
echo str_repeat('-', 78) . "\n";

/*
 * THE REAL DDL, copied from the migration rather than simplified.
 *
 * A simplified table would be testing a table this project does not have. The UNIQUE key is the
 * whole subject, so its exact name and shape matter; `stores` exists only to satisfy the foreign
 * key, which is itself part of what makes a stray INSERT fail.
 */
$conn->statement("
    CREATE TABLE `stores` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(191) NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$conn->statement("
    CREATE TABLE `store_wallets` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `store_id` BIGINT UNSIGNED NOT NULL,
        `currency_code` VARCHAR(10) NULL,
        `available_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `pending_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `withdrawal_pending_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `total_income` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `total_withdrawn` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `created_at` TIMESTAMP NULL,
        `updated_at` TIMESTAMP NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_store_wallet_store_id` (`store_id`),
        KEY `idx_store_wallet_store_id` (`store_id`),
        CONSTRAINT `fk_store_wallet_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$conn->table('stores')->insert(['id' => 1, 'name' => 'Race Store']);
$conn->table('stores')->insert(['id' => 2, 'name' => 'Txn Store']);

/*
|--------------------------------------------------------------------------
| CHECK 1 -- the constraint is ENFORCED, not merely declared
|--------------------------------------------------------------------------
*/

$conn->table('store_wallets')->insert(['store_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

$secondInsertRejected = false;
$violationClass = '';
try {
    $conn->table('store_wallets')->insert(['store_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
} catch (Throwable $e) {
    $secondInsertRejected = true;
    $violationClass = get_class($e);
}

check(
    'a second store_wallets row for the same store is rejected by uq_store_wallet_store_id',
    $secondInsertRejected,
    'the UNIQUE key did not fire, so "one wallet per store" is not enforced on this engine'
);

/*
 * AND LARAVEL CLASSIFIES IT AS A UNIQUE VIOLATION, which is what `createOrFirst`'s catch keys on.
 * A driver that surfaced it as a generic QueryException would make the recovery path dead code --
 * `firstOrCreate` would simply throw, and every "race-safe" claim about it would be false.
 */
check(
    'Laravel classifies it as UniqueConstraintViolationException (the class createOrFirst catches)',
    $violationClass === \Illuminate\Database\UniqueConstraintViolationException::class,
    'got ' . ($violationClass ?: 'no exception') . '; createOrFirst()\'s catch would not match'
);

$conn->table('store_wallets')->where('store_id', 1)->delete();

/*
|--------------------------------------------------------------------------
| CHECK 2 -- firstOrCreate recovers from a lost race OUTSIDE a transaction
|--------------------------------------------------------------------------
|
| Simulated deterministically: the row is created by a "winner" between this process's SELECT and
| its INSERT. That is exactly the interleaving `createOrFirst` is written to survive, and doing it
| deterministically means the check cannot pass by winning the race instead of surviving it.
*/

$model = new class extends \Illuminate\Database\Eloquent\Model {
    protected $table = 'store_wallets';
    protected $guarded = [];
    public $timestamps = true;
    protected $connection = 'fresh';
};

$recovered = null;
$threw = null;
try {
    /*
     * The winner commits first, on its own connection, so the duplicate is genuinely already
     * present and committed when our INSERT runs.
     */
    $conn->table('store_wallets')->insert([
        'store_id' => 1, 'available_balance' => 77.00, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $recovered = $model->newQuery()->firstOrCreate(['store_id' => 1], ['available_balance' => 0]);
} catch (Throwable $e) {
    $threw = $e;
}

check(
    'firstOrCreate outside a transaction returns the winner\'s row instead of throwing',
    $threw === null && $recovered !== null,
    $threw ? get_class($threw) . ': ' . $threw->getMessage() : 'returned null'
);

check(
    'and it returns the WINNER\'s row, not a fresh one -- the balance is preserved',
    $recovered !== null && (float) $recovered->available_balance === 77.00,
    'available_balance was ' . ($recovered->available_balance ?? 'n/a') . ', expected 77.00; '
    . 'a recovery that returned a zeroed row would silently discard the winner\'s balance'
);

$conn->table('store_wallets')->where('store_id', 1)->delete();

/*
|--------------------------------------------------------------------------
| CHECK 3 -- THE ONE THAT MATTERS: firstOrCreate INSIDE an open transaction
|           that has already taken a read snapshot
|--------------------------------------------------------------------------
|
| This is the shape `OrderFinancialService::createForOrder()` really has: everything happens inside
| DB::transaction(), and a read occurs before any provisioning would. If the engine serves the
| recovery SELECT from a pre-commit snapshot, `first()` is null and createOrFirst re-throws --
| landing in OrderObserver::created()'s catch and losing the credit.
*/

$txnThrew = null;
$txnRecovered = null;

try {
    $conn->transaction(function () use ($conn, $model, &$txnRecovered) {
        // 1. establish this transaction's read snapshot, the way createForOrder() does
        $conn->table('stores')->where('id', 2)->first();

        // 2. a concurrent winner commits the wallet row on a SEPARATE connection
        $winner = DB::connection('fresh_bootstrap')->getPdo();
        $winner->exec("USE `" . $GLOBALS['database'] . "`");
        $winner->exec("INSERT INTO store_wallets (store_id, available_balance, created_at, updated_at)
                       VALUES (2, 55.00, NOW(), NOW())");

        // 3. now try to provision -- the INSERT must lose, and the recovery SELECT must still see it
        $txnRecovered = $model->newQuery()->firstOrCreate(['store_id' => 2], ['available_balance' => 0]);
    });
} catch (Throwable $e) {
    $txnThrew = $e;
}

/*
 * THE ASSERTION IS INVERTED ON PURPOSE, AND THIS IS THE FINDING.
 *
 * MEASURED on MariaDB 10.11.14 / InnoDB / REPEATABLE-READ: `firstOrCreate` DOES throw here. Its
 * recovery is a plain consistent read, served from the snapshot this transaction took at its first
 * read -- which predates the winner's commit -- so `first()` returns null and `createOrFirst`
 * re-throws the violation.
 *
 * So the decision package's claim that "`firstOrCreate` is race-safe at the database level" is true
 * OUTSIDE a transaction (check 2, and the eight real processes in check 4) and FALSE INSIDE one.
 * `OrderFinancialService::createForOrder()` is the inside-a-transaction case, so a defensive
 * `firstOrCreate` dropped into it would still throw under concurrency, land in
 * `OrderObserver::created()`'s catch, and lose the merchant's credit -- the exact defect the change
 * is meant to close.
 *
 * THE SUITE COULD NOT HAVE FOUND THIS. It runs on `:memory:` SQLite, which serialises writers, so
 * the naive fix would have been green there. Check 4 below is green too, for the same reason in
 * reverse: those eight processes are NOT inside an outer transaction, so they recover normally.
 * A test that only drove check 4's shape would have certified the wrong implementation.
 *
 * THIS CHECK IS A RATCHET IN BOTH DIRECTIONS. If a future engine or Laravel version makes
 * `firstOrCreate` survive this shape, this check fails and the service can be simplified. It must
 * never be "fixed" by changing the service to use `firstOrCreate` inside the transaction.
 */
check(
    'firstOrCreate inside an open transaction with a prior read snapshot THROWS (why the service does not use it)',
    $txnThrew instanceof \Illuminate\Database\UniqueConstraintViolationException,
    $txnThrew === null
        ? 'it recovered, which contradicts the measurement this implementation is built on -- '
          . 'the engine or Laravel has changed and OrderFinancialService can be simplified'
        : 'threw ' . get_class($txnThrew) . ' rather than a unique-constraint violation'
);

check(
    'and nothing was recovered, so the credit would have been lost',
    $txnRecovered === null,
    'a row came back despite the throw: ' . json_encode($txnRecovered)
);

/*
|--------------------------------------------------------------------------
| CHECK 3b -- THE RECOVERY THAT DOES WORK INSIDE A TRANSACTION: a LOCKING read
|--------------------------------------------------------------------------
|
| Check 3 establishes that `firstOrCreate`'s own recovery is a plain consistent read, which under
| REPEATABLE READ is served from a snapshot that predates the winner's commit. A LOCKING read
| (`SELECT ... FOR UPDATE`) is a "current read" in InnoDB: it reads the latest committed version
| regardless of the transaction's snapshot, precisely because it has to lock a row that really
| exists.
|
| So the correct defensive shape inside `createForOrder()` is NOT `firstOrCreate`, it is:
|
|     try { insert } catch (UniqueConstraintViolationException) { re-read with lockForUpdate() }
|
| This check measures that claim rather than asserting it, because the whole value of this script is
| that the obvious-looking fix was already measured to fail one line above.
*/

$conn->table('stores')->insert(['id' => 4, 'name' => 'Lock Store']);

$lockThrew = null;
$lockRecovered = null;

try {
    $conn->transaction(function () use ($conn, $model, &$lockRecovered) {
        // same prior read snapshot as check 3
        $conn->table('stores')->where('id', 4)->first();

        $winner = DB::connection('fresh_bootstrap')->getPdo();
        $winner->exec("USE `" . $GLOBALS['database'] . "`");
        $winner->exec("INSERT INTO store_wallets (store_id, available_balance, created_at, updated_at)
                       VALUES (4, 99.00, NOW(), NOW())");

        try {
            $lockRecovered = $model->newQuery()->create(['store_id' => 4, 'available_balance' => 0]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $lockRecovered = $model->newQuery()
                ->where('store_id', 4)
                ->lockForUpdate()
                ->first();
        }
    });
} catch (Throwable $e) {
    $lockThrew = $e;
}

check(
    'a lockForUpdate() re-read DOES see the winner\'s row inside the same transaction',
    $lockThrew === null && $lockRecovered !== null,
    ($lockThrew ? get_class($lockThrew) . ': ' . $lockThrew->getMessage() : 'returned null')
    . ' -- if this also fails, provisioning cannot be made safe inside the order transaction at '
    . 'all and must happen before it opens'
);

check(
    'and the locking read returns the winner\'s balance, so no credit is invented or discarded',
    $lockRecovered !== null && (float) $lockRecovered->available_balance === 99.00,
    'available_balance was ' . ($lockRecovered->available_balance ?? 'n/a') . ', expected 99.00'
);

/*
|--------------------------------------------------------------------------
| CHECK 3c -- THE REAL SERVICE CLASS, in the real shape, on the real engine
|--------------------------------------------------------------------------
|
| Checks 3 and 3b establish what the ENGINE does for two query shapes. This one drives the actual
| shipped `App\Services\StoreWalletProvisioning` so the conclusion is about the code that runs in
| production rather than about a shape reproduced by hand in this file -- which is the difference
| between evidence and a plausible argument.
|
| The default connection is repointed at the throwaway database, because `StoreWallet` resolves its
| connection through the model and the service has no connection argument.
*/

config(['database.default' => 'fresh']);

$conn->table('stores')->insert(['id' => 5, 'name' => 'Service Store']);

$svcThrew = null;
$svcWallet = null;

try {
    $conn->transaction(function () use ($conn, &$svcWallet) {
        // the prior read that fixes this transaction's snapshot, as createForOrder() has
        $conn->table('stores')->where('id', 5)->first();

        // a concurrent winner commits the wallet on another connection
        $winner = DB::connection('fresh_bootstrap')->getPdo();
        $winner->exec("USE `" . $GLOBALS['database'] . "`");
        $winner->exec("INSERT INTO store_wallets (store_id, available_balance, created_at, updated_at)
                       VALUES (5, 123.45, NOW(), NOW())");

        $svcWallet = app(\App\Services\StoreWalletProvisioning::class)->ensure(5, 'USD');
    });
} catch (Throwable $e) {
    $svcThrew = $e;
}

check(
    'StoreWalletProvisioning::ensure() survives a lost race inside an open transaction',
    $svcThrew === null && $svcWallet !== null,
    ($svcThrew ? get_class($svcThrew) . ': ' . $svcThrew->getMessage() : 'returned null')
    . ' -- the shipped service would lose the credit in the shape OrderFinancialService has'
);

check(
    'and it returns the winner\'s row rather than inventing a zeroed wallet',
    $svcWallet !== null && (float) $svcWallet->available_balance === 123.45,
    'available_balance was ' . ($svcWallet->available_balance ?? 'n/a') . ', expected 123.45; '
    . 'a zero here would mean a live merchant balance was silently replaced'
);

/* An uncontended call must still simply work. */
$conn->table('stores')->insert(['id' => 6, 'name' => 'Plain Store']);
$plain = app(\App\Services\StoreWalletProvisioning::class)->ensure(6, 'USD');
$plainAgain = app(\App\Services\StoreWalletProvisioning::class)->ensure(6, 'USD');

check(
    'an uncontended ensure() creates exactly one wallet and is idempotent',
    $plain !== null && $plainAgain !== null && (int) $plain->id === (int) $plainAgain->id
        && (int) $conn->table('store_wallets')->where('store_id', 6)->count() === 1,
    'rows=' . $conn->table('store_wallets')->where('store_id', 6)->count()
);

/*
|--------------------------------------------------------------------------
| CHECK 4 -- genuinely concurrent processes, not one process taking turns
|--------------------------------------------------------------------------
*/

if (!function_exists('pcntl_fork')) {
    skip('N concurrent processes provisioning the same store produce exactly one wallet row',
        'pcntl is not available in this PHP build');
} else {
    $conn->table('stores')->insert(['id' => 3, 'name' => 'Fork Store']);

    $workers = 8;
    $pids = [];

    for ($i = 0; $i < $workers; $i++) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            // CHILD. A forked child inherits the parent's PDO handle, which cannot be shared --
            // so the connection is discarded and reopened before any query runs.
            try {
                DB::purge('fresh');
                $child = new class extends \Illuminate\Database\Eloquent\Model {
                    protected $table = 'store_wallets';
                    protected $guarded = [];
                    protected $connection = 'fresh';
                };
                usleep(random_int(0, 20000));
                $child->newQuery()->firstOrCreate(['store_id' => 3], ['available_balance' => 0]);
                exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, "    child " . getmypid() . ": " . get_class($e) . ': ' . $e->getMessage() . "\n");
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    $failedChildren = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            $failedChildren++;
        }
    }

    DB::purge('fresh');
    $rows = (int) DB::connection('fresh')->table('store_wallets')->where('store_id', 3)->count();

    check(
        "{$workers} concurrent processes produced exactly one wallet row",
        $rows === 1,
        "found {$rows} rows for store 3"
    );

    check(
        "and no process died trying -- all {$workers} recovered from the race",
        $failedChildren === 0,
        "{$failedChildren} of {$workers} children exited non-zero; each one is a lost wallet credit "
        . 'in the shape this provisioning change is meant to prevent'
    );
}

echo str_repeat('-', 78) . "\n";
echo "  {$checks} checks, {$failures} failed, {$skipped} skipped\n";

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "warning: could not drop the throwaway database: {$e->getMessage()}\n");
}

exit($failures === 0 ? 0 : 1);
