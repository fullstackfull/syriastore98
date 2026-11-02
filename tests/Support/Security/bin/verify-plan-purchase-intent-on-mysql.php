#!/usr/bin/env php
<?php

/**
 * F229 / D-31 — VERIFY THE PURCHASE INTENT AGAINST A REAL MySQL SERVER.
 *
 * WHY A SCRIPT AND NOT A PHPUNIT TEST. Three of the properties below cannot be observed on SQLite
 * at all, and the owner's instruction asks for *"real MariaDB concurrency evidence"* rather than a
 * SQLite claim standing in for one:
 *
 *   1. **GENUINE CONCURRENCY.** Requirement 5's "concurrent activation races" needs two callbacks
 *      racing for one intent. SQLite `:memory:` is one connection in one process — there is no
 *      second writer to lose. This script opens TWO connections and has them contend.
 *   2. **DECIMAL SCALE.** `DECIMAL(16, 4)` is enforced by MySQL and **ignored by SQLite**, which
 *      stores by type affinity: a `449.10` intent reads back as `449.1` on SQLite and `449.1000`
 *      on MySQL. A Feature test that asserted the rendering would be asserting the engine. (It
 *      did, briefly, and failed for exactly that reason — the same affinity difference that let
 *      P01-SEC-13 survive a green suite.)
 *   3. **THE UNIQUE CONSTRAINT.** `reference` is UNIQUE, and "one intent per reference" being a
 *      database fact rather than a convention is only demonstrable where the constraint is real.
 *
 * A conditionally-skipped PHPUnit test is not an option: `.github/known-test-outcomes.json`
 * declares the exact skip set and the gate asserts it **in both directions**, so a test that
 * skipped for want of a MySQL would turn the gate red. Same convention as
 * `verify-plan-coupon-refusal-ordering-on-mysql.php` and `verify-sec13-migration-on-mysql.php`.
 *
 * WHAT IT ESTABLISHES, by execution:
 *   1. the engine really enforces `DECIMAL(16, 4)`, so the scale is a property of the data;
 *   2. `reference` is really UNIQUE — a duplicate insert is refused by the database;
 *   3. **two concurrent consumptions of one intent: exactly one wins.** The losing connection's
 *      conditional UPDATE matches zero rows, which is what the control is made of;
 *   4. a replay after a successful consumption also matches zero rows;
 *   5. a stranger's attempt leaves the owner's intent `pending`, so cross-user probing cannot
 *      deny a buyer their own purchase;
 *   6. the amount comparison is exact on the engine too — `499.00`, `499` and `499.0000` are the
 *      same money, and `499.00000000000001` is not, which is the case float equality cannot see.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database:
 *
 *   F229_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   F229_MYSQL_DATABASE=f229_verify \
 *   F229_MYSQL_USERNAME=root \
 *   F229_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-plan-purchase-intent-on-mysql.php
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE and
 * DROP DATABASE, and refuses a database name containing neither 'f229' nor 'verify'.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('F229_MYSQL_DATABASE') ?: 'f229_verify';

if (! str_contains($database, 'f229') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] contains neither 'f229' nor 'verify'.\n");
    exit(2);
}

$socket = getenv('F229_MYSQL_SOCKET');

if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set F229_MYSQL_SOCKET='' to force TCP)\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('F229_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('F229_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('F229_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('F229_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.f229_bootstrap' => $bootstrap]);
config(['database.connections.f229_a' => $connection]);
/* A SECOND, SEPARATE CONNECTION. This is the point of the script: one connection cannot race
   itself, so requirement 5's concurrency case needs two. */
config(['database.connections.f229_b' => $connection]);
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

/**
 * THE PRODUCTION COMPARATOR ITSELF, not a copy of it.
 *
 * This function used to be a transcription of `PlanPurchaseIntent::scaledInteger()`, and
 * independent review pointed out what that costs: the two had already drifted, so the script was
 * testing a comparator that no longer existed while reporting on one that did. A copy of the
 * thing under test is not evidence about the thing under test.
 *
 * The method is private because nothing outside the service has any business comparing money, and
 * widening it for a script's convenience would be the tail wagging the dog. Reflection reaches it
 * without changing the production API, and -- the point -- there is now exactly one implementation
 * in the repository, so it cannot drift from itself.
 */
function scaledInteger(string $amount, int $scale): ?string
{
    static $theRealOne = null;

    if ($theRealOne === null) {
        $theRealOne = new ReflectionMethod(\App\Services\PlanPurchaseIntent::class, 'scaledInteger');
        $theRealOne->setAccessible(true);
    }

    return $theRealOne->invoke(null, $amount, $scale);
}

echo "F229 -- the server-owned plan purchase intent, verified on MySQL\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('f229_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('f229_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $theServerIsUnreachable) {
    fwrite(STDERR, "Could not create the throwaway database: {$theServerIsUnreachable->getMessage()}\n");
    exit(2);
}

try {
    $a = DB::connection('f229_a');
    $b = DB::connection('f229_b');

    echo 'server: ' . $a->selectOne('select version() as v')->v . "\n\n";

    /* The real shape, from the migration. DECIMAL and UNIQUE are the two things SQLite will not
       give us, so they are declared here with the two columns this file is about -- `DECIMAL(16,4)` and the UNIQUE `reference` -- exactly as the migration declares them (the migration also indexes `provider`, `user_id`, `plan_id`, `status` and `provider_txn_id` individually; none of those changes an answer below). */
    $a->statement('CREATE TABLE plan_purchase_intents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reference VARCHAR(64) NOT NULL UNIQUE,
        provider VARCHAR(40) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        plan_id BIGINT UNSIGNED NOT NULL,
        coupon_id BIGINT UNSIGNED NULL,
        coupon_code VARCHAR(191) NULL,
        expected_amount DECIMAL(16,4) NOT NULL,
        currency VARCHAR(8) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT "pending",
        consumed_at TIMESTAMP NULL,
        plan_order_id BIGINT UNSIGNED NULL,
        provider_txn_id VARCHAR(191) NULL,
        failure_reason VARCHAR(255) NULL,
        created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
        INDEX (provider, reference)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $insert = static function ($conn, string $reference, string $amount, int $userId = 9001) {
        $conn->table('plan_purchase_intents')->insert([
            'reference' => $reference, 'provider' => 'Paytm',
            'user_id' => $userId, 'plan_id' => 7,
            'expected_amount' => $amount, 'currency' => 'USD',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    // ------------------------------------------------------------------- 1. DECIMAL SCALE
    $insert($a, 'f229-scale', '449.10');
    $stored = (string) $a->table('plan_purchase_intents')->where('reference', 'f229-scale')->value('expected_amount');

    check(
        'the engine enforces DECIMAL(16,4): 449.10 reads back as ' . $stored,
        $stored === '449.1000',
        "got {$stored}; SQLite returns '449.1' for the same column, which is the affinity "
        . 'difference that makes this script necessary'
    );

    // ------------------------------------------------------------------- 2. UNIQUE reference
    $duplicateRefused = false;

    try {
        $insert($a, 'f229-scale', '1.00');
    } catch (Throwable $theDuplicateWasRefused) {
        $duplicateRefused = true;
    }

    check('reference is UNIQUE, so one intent per reference is a database fact', $duplicateRefused);

    // --------------------------------------------- 3. TWO CONCURRENT CONSUMPTIONS, ONE WINNER
    //
    // THE ASSERTION THE OWNER ASKED FOR, and the first version of it was theatre. Independent
    // review caught it: both UPDATEs ran in autocommit, so A's committed before B's began and
    // `$wonByA + $wonByB === 1` was arithmetic over a sequence. Two connections are necessary for
    // a race but they are not sufficient -- nothing was ever contended.
    //
    // A real race needs A's claim to be IN FLIGHT while B's arrives, and a single PHP process
    // cannot run two statements at once. What it can do is hold A's transaction open, which puts
    // the row under A's exclusive lock for exactly the window a second callback would land in,
    // and then show what B meets there. Three distinct facts, in the order a real second callback
    // would encounter them:
    //
    //   a. while A's claim is uncommitted the row is genuinely locked -- `FOR UPDATE NOWAIT`
    //      fails instead of reading it, which is the lock existing rather than being assumed;
    //   b. B's own conditional UPDATE does not race past it either: it BLOCKS, and with
    //      `innodb_lock_wait_timeout = 1` it gives up rather than proceeding on stale state. A
    //      second callback is therefore serialised by the engine, not by luck;
    //   c. once A commits, B's UPDATE matches ZERO rows, because `status` is no longer 'pending'.
    //
    // (b) is the one that distinguishes a real lock from a sequence: in the old version B's
    // statement returned immediately, and here it cannot.
    $insert($a, 'f229-race', '499.00');

    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $a->beginTransaction();

    $wonByA = $a->table('plan_purchase_intents')
        ->where('reference', 'f229-race')->where('status', 'pending')
        ->update(['status' => 'consumed', 'consumed_at' => now(), 'provider_txn_id' => 'TXN-A', 'updated_at' => now()]);

    check('connection A claims the pending intent inside an open transaction', $wonByA === 1);

    // (a) the row is really locked, and this is the proof that the window is real.
    $lockHeld = false;

    $whyNot = '';
    $driverCode = 0;
    $cannotEvaluate = false;

    try {
        $b->select('select reference from plan_purchase_intents where reference = ? for update nowait', ['f229-race']);
    } catch (Throwable $theRowWasLocked) {
        /*
         * MATCH THE DRIVER ERROR NUMBER, NOT THE MESSAGE -- and the first two versions of this
         * check both got it wrong, each worse than the last.
         *
         * v1 was a bare `catch (Throwable) { $lockHeld = true; }`, which reported "the lock is
         * real" on ANY failure. v2 tested the MESSAGE for the substring `nowait`, which is worse
         * than it looks: `QueryException::formatMessage()` appends the **full SQL** to every
         * message, and this statement's SQL ends in `for update nowait`. So the substring is
         * present for a 1064 parse error, a missing table and a lost connection alike -- for
         * exactly the cases the check was added to reject. It could not fail.
         *
         * `errorInfo[1]` is the driver number. `getCode()`/`errorInfo[0]` give only the SQLSTATE,
         * and `HY000` is the generic bucket that 1205, 1213 and most server errors share, so the
         * SQLSTATE cannot separate them either.
         */
        $whyNot = $theRowWasLocked->getMessage();
        $previous = $theRowWasLocked->getPrevious();
        $pdo = $previous instanceof PDOException ? $previous : ($theRowWasLocked instanceof PDOException ? $theRowWasLocked : null);
        $driverCode = (int) ($pdo && isset($pdo->errorInfo[1]) ? $pdo->errorInfo[1] : 0);

        /*
         * 3572 ER_LOCK_NOWAIT is MySQL >= 8.0.1 only. **MariaDB has no such code** -- MEASURED on
         * the 10.11.14 server this file is run against: a NOWAIT conflict raises 1205
         * ER_LOCK_WAIT_TIMEOUT, SQLSTATE HY000, "Lock wait timeout exceeded". Both mean the row is
         * genuinely locked, which is all this check asserts.
         *
         * AND THAT IS WORTH SAYING OUT LOUD, because it costs this check its independence: on
         * MariaDB, check (a) and check (b) below come back with the same error number. (a) still
         * carries information -- it fails IMMEDIATELY rather than after the one-second timeout, so
         * it shows the conflict is detected without waiting -- but it is no longer a second,
         * independent witness to the lock. The timing in (b) is the load-bearing measurement.
         */
        $lockHeld = $driverCode === 3572 || $driverCode === 1205;

        /* 1064 ER_PARSE_ERROR: NOWAIT is unsupported here, so the statement NEVER RAN. That is
           not a pass and not a failure -- it is an absence of evidence, and scoring it as either
           is how v1 and v2 came to report a lock on a server that cannot test for one. */
        $cannotEvaluate = $driverCode === 1064;
    }

    if ($cannotEvaluate) {
        echo "  SKIP  FOR UPDATE NOWAIT is not supported by this server (1064), so the immediate-conflict\n";
        echo "        check cannot be evaluated. The blocking check below still carries the claim.\n";
    } else {
        check(
            'while A holds it, B cannot even read the row for update -- the lock is real (driver ' . $driverCode . ')',
            $lockHeld,
            $whyNot === ''
                ? 'FOR UPDATE NOWAIT SUCCEEDED, so A is not holding a lock and nothing below is a race'
                : 'it failed with driver code ' . $driverCode . ', which is neither 3572 nor 1205, so this proves nothing: ' . $whyNot
        );
    }

    // (b) B's conditional UPDATE blocks on that lock rather than overtaking it.
    $blockedFor = microtime(true);
    $bWasBlocked = false;

    try {
        $b->table('plan_purchase_intents')
            ->where('reference', 'f229-race')->where('status', 'pending')
            ->update(['status' => 'consumed', 'consumed_at' => now(), 'provider_txn_id' => 'TXN-B', 'updated_at' => now()]);
    } catch (Throwable $theSecondCallbackWaited) {
        $bWasBlocked = str_contains(strtolower($theSecondCallbackWaited->getMessage()), 'lock wait timeout');
    }

    $blockedFor = microtime(true) - $blockedFor;

    check(
        'B\'s identical UPDATE blocks on the row lock rather than overtaking it ('
        . number_format($blockedFor, 2) . 's)',
        $bWasBlocked,
        'B returned without waiting, so the two statements never contended and this check is '
        . 'the theatre the previous version of this script performed'
    );

    $a->commit();

    // (c) and now the loser finds nothing to claim.
    $wonByB = $b->table('plan_purchase_intents')
        ->where('reference', 'f229-race')->where('status', 'pending')
        ->update(['status' => 'consumed', 'consumed_at' => now(), 'provider_txn_id' => 'TXN-B', 'updated_at' => now()]);

    check(
        'after A commits, B\'s claim matches zero rows: one payment, one activation ('
        . $wonByA . ' + ' . $wonByB . ')',
        $wonByA === 1 && $wonByB === 0,
        'both callbacks claimed the same intent, so one payment can activate two plans'
    );

    check(
        'and the winner is the one recorded',
        $a->table('plan_purchase_intents')->where('reference', 'f229-race')->value('provider_txn_id') === 'TXN-A'
    );

    // ------------------------------------------------------------------- 4. REPLAY
    $replay = $b->table('plan_purchase_intents')
        ->where('reference', 'f229-race')->where('status', 'pending')
        ->update(['status' => 'consumed', 'updated_at' => now()]);

    check('a replay after a successful consumption matches zero rows', $replay === 0);

    // --------------------------------------------------------- 5. CROSS-USER, NON-DESTRUCTIVE
    //
    // A stranger must not be able to consume somebody else's intent, AND must not be able to
    // spoil it either: a purchase a buyer cannot complete is its own denial of service.
    //
    // THIS CHECK USED TO PASS WHILE THE CODE IT REPORTED ON HAD THE DEFECT IT NAMED, which
    // independent review caught and which is the most useful thing to have found in this file.
    // It demonstrated the property by adding `where('user_id', 9002)` to a hand-written UPDATE --
    // a predicate `verifyAndConsume()` does not use (it checks the purchaser in PHP and updates by
    // `id` + `status`). Meanwhile the controller's refusal path called `recordRefusal()`, which was
    // unscoped, so a stranger's arrival wrote `status = refused` onto the OWNER's pending row and
    // spoiled it terminally -- exactly what the check claimed could not happen.
    //
    // So it now drives the REAL `PlanPurchaseIntent` against this engine instead of a
    // transcription of it. `DB::table()` inside the service uses the DEFAULT connection, so the
    // default is pointed at the throwaway database for the duration.
    $insert($a, 'f229-owner', '499.00', 9001);

    $previousDefault = config('database.default');
    config(['database.default' => 'f229_a']);

    try {
        check(
            'premise: the real service can see the throwaway table',
            \App\Services\PlanPurchaseIntent::available(),
            'the default connection was not switched, so everything below is testing nothing'
        );

        [$strangerState] = \App\Services\PlanPurchaseIntent::verifyAndConsume(
            'Paytm', 'f229-owner', 9002, '499.00', 'USD', 'TXN-STRANGER'
        );

        check(
            'a stranger cannot consume another account\'s intent',
            $strangerState === \App\Services\PlanPurchaseIntent::REFUSED_MISMATCH,
            'got ' . var_export($strangerState, true)
        );

        /* AND THE STRANGER'S REFUSAL CANNOT CLOSE IT. The real method, with the stranger named. */
        \App\Services\PlanPurchaseIntent::recordRefusal('Paytm', 'f229-owner', 'a stranger said so', 'TXN-STRANGER', 9002);

        check(
            'and the owner\'s intent is still pending, so their purchase is still completable',
            $a->table('plan_purchase_intents')->where('reference', 'f229-owner')->value('status') === 'pending'
        );

        /* The owner's OWN refusal must still record, or the scope is too tight and the feature is
           gone rather than fixed. */
        \App\Services\PlanPurchaseIntent::recordRefusal('Paytm', 'f229-owner', 'the owner\'s own refusal', 'TXN-OWNER', 9001);

        check(
            'while the owner\'s own refusal still records',
            $a->table('plan_purchase_intents')->where('reference', 'f229-owner')->value('status') === 'refused'
        );
    } finally {
        config(['database.default' => $previousDefault]);
    }

    /* Reset for check 6, which reads this row's amount. */
    $a->table('plan_purchase_intents')->where('reference', 'f229-owner')->update(['status' => 'pending']);

    // ----------------------------------------------- 6. THE AMOUNT COMPARISON, ON THIS ENGINE
    $expected = (string) $a->table('plan_purchase_intents')->where('reference', 'f229-owner')->value('expected_amount');

    foreach ([
        '499.00' => true,
        '499' => true,
        '499.0000' => true,
        '499.01' => false,
        '499.00000000000001' => false,   // float equality CANNOT see this difference
        '499,00' => false,
        'abc' => false,
    ] as $reported => $shouldMatch) {
        $left = scaledInteger($expected, 4);
        $right = scaledInteger((string) $reported, 4);
        $matches = $left !== null && $right !== null && $left === $right;

        check(
            'reported ' . str_pad(var_export((string) $reported, true), 24) . ' against ' . $expected
            . ' => ' . ($matches ? 'matches' : 'refused'),
            $matches === $shouldMatch
        );
    }

    echo "\n" . str_repeat('=', 78) . "\n";
    echo "checks  : {$checks}\nfailures: {$failures}\n";

    if ($failures === 0) {
        echo "\nALL ASSERTIONS HELD on " . $a->selectOne('select version() as v')->v . ".\n";
        echo "Two concurrent callbacks produce exactly one activation, a replay matches nothing,\n";
        echo "a stranger can neither consume nor spoil an intent, and the amount comparison is\n";
        echo "exact where float equality is not.\n";
    }
} catch (Throwable $theVerificationFailed) {
    fwrite(STDERR, "\nUNEXPECTED: {$theVerificationFailed->getMessage()}\n");
    fwrite(STDERR, "  at {$theVerificationFailed->getFile()}:{$theVerificationFailed->getLine()}\n");
    $failures++;
} finally {
    try {
        DB::connection('f229_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ndropped the throwaway database `{$database}`.\n";
    } catch (Throwable $theCleanupFailed) {
        fwrite(STDERR, "WARNING: could not drop `{$database}`: {$theCleanupFailed->getMessage()}\n");
    }
}

exit($failures === 0 ? 0 : 1);
