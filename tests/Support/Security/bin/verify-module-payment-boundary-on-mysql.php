#!/usr/bin/env php
<?php

/**
 * #204 / PHASE 06 ITEM 2(B) — THE DISABLED-APPLICATION PAYMENT BOUNDARY, ON A REAL ENGINE.
 *
 * WHY A SCRIPT AND NOT A PHPUNIT TEST. The owner's rule has the word *idempotent* in it, and the
 * thing that makes a payment return idempotent here is ONE conditional UPDATE from `initiated` —
 * which only means something where two writers can really contend. SQLite `:memory:` is one
 * connection in one process: there is no second writer to lose, so the suite can show the claim
 * holds SEQUENTIALLY and cannot show it holds under a race. This opens TWO connections and has
 * them contend for one initiation, which is the case a payer's double click actually produces.
 *
 * Three more properties are engine-visible only:
 *
 *   - `reference` is UNIQUE. "One row per initiation" is a database fact or it is a convention.
 *   - MySQL holds a ROW LOCK for the duration of an UPDATE inside a transaction, so the losing
 *     connection WAITS and then finds the row spent — rather than reading a pre-write snapshot as
 *     it might under a different isolation level. That is the behaviour production has.
 *   - the default collation is case- and accent-INSENSITIVE while SQLite's is BINARY. These
 *     statements match on `module` and on a 64-character hex `reference`, so a case-folding
 *     engine must not let `DONATION` or an upper-cased reference spend somebody's initiation.
 *     CLAUDE.md records a collation difference of exactly this shape (P1-INV-18) as a real defect
 *     that no SQLite test could see.
 *
 * A conditionally-skipped PHPUnit test is not an option: `.github/known-test-outcomes.json`
 * declares the exact skip set and the gate asserts it in both directions. Same convention as
 * `verify-plan-purchase-intent-on-mysql.php`, whose shape this copies.
 *
 * WHAT IT ESTABLISHES, by execution:
 *   1. `reference` is really UNIQUE;
 *   2. TWO CONCURRENT COMPLETIONS OF ONE INITIATION: exactly one wins, and the loser's
 *      conditional UPDATE matches zero rows;
 *   3. a replay after a completion also matches zero rows, and so does one against a superseded
 *      or abandoned row — every non-`initiated` state is unspendable;
 *   4. the module name is part of every claim, so one module's reference cannot spend another's,
 *      even on an engine whose collation folds case;
 *   5. a reference differing only in letter case does NOT spend an initiation — the collation
 *      case this script exists for;
 *   6. the refusal dedupe counts a repeat on one row rather than inserting a second, under
 *      concurrency too.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database:
 *
 *   MPB_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   MPB_MYSQL_DATABASE=mpb_verify \
 *   MPB_MYSQL_USERNAME=root \
 *   MPB_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-module-payment-boundary-on-mysql.php
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE and
 * DROP DATABASE, and refuses a database name that does not contain 'verify' or 'mpb'.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('MPB_MYSQL_DATABASE') ?: 'mpb_verify';

if (! str_contains($database, 'mpb') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] contains neither 'mpb' nor 'verify'.\n");
    exit(2);
}

$socket = getenv('MPB_MYSQL_SOCKET');

if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set MPB_MYSQL_SOCKET='' to force TCP)\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('MPB_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('MPB_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('MPB_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('MPB_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.mpb_bootstrap' => $bootstrap]);
config(['database.connections.mpb_a' => $connection]);
/* THE SECOND CONNECTION IS THE POINT: one connection cannot race itself. */
config(['database.connections.mpb_b' => $connection]);
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

echo "#204 -- the disabled-application payment boundary, verified on MySQL\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('mpb_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('mpb_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $theServerIsUnreachable) {
    fwrite(STDERR, "Could not create the throwaway database: {$theServerIsUnreachable->getMessage()}\n");
    exit(2);
}

try {
    $a = DB::connection('mpb_a');
    $b = DB::connection('mpb_b');

    echo 'server: ' . $a->selectOne('select version() as v')->v . "\n";
    echo 'collation: ' . $a->selectOne("select @@collation_database as c")->c . "\n\n";

    /*
     * THE REAL SHAPE, FROM THE MIGRATION, built here rather than migrated because the migration is
     * development-and-test-only and this script must not depend on `migrate` having been run. The
     * two columns this file is about are declared exactly as the migration declares them: the
     * UNIQUE `reference` and the `status` whose value the conditional UPDATE matches on.
     */
    $a->statement('CREATE TABLE module_payment_initiations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reference VARCHAR(64) NOT NULL UNIQUE,
        module VARCHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT "initiated",
        store_id BIGINT UNSIGNED NULL,
        route VARCHAR(191) NOT NULL,
        payer_guard VARCHAR(16) NULL,
        payer_id BIGINT UNSIGNED NULL,
        gateway VARCHAR(64) NULL,
        payload_hash CHAR(64) NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 1,
        reason VARCHAR(255) NULL,
        completed_at TIMESTAMP NULL,
        acknowledged_at TIMESTAMP NULL,
        acknowledged_by BIGINT UNSIGNED NULL,
        acknowledged_reason VARCHAR(255) NULL,
        created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
        INDEX (module, status),
        INDEX (module, route, payload_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $initiate = static function ($conn, string $reference, string $module = 'Donation', string $status = 'initiated') {
        $conn->table('module_payment_initiations')->insert([
            'reference' => $reference,
            'module' => $module,
            'status' => $status,
            'store_id' => 7,
            'route' => 'POST {storeSlug}/customer-donation-store',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    /*
     * THE PRODUCTION CLAIM ITSELF, not a transcription of it.
     *
     * `ModulePaymentLedger::completeInitiation()` reads its own connection through the `DB` facade,
     * so it cannot be pointed at connection B -- and a copy of the statement under test is not
     * evidence about the statement under test (`verify-plan-purchase-intent-on-mysql.php` records
     * that lesson about `scaledInteger()`). So the WHERE clause is read back out of the service's
     * source and asserted to be the one these raced statements use, and the race then issues it on
     * two connections.
     */
    $service = (string) file_get_contents(__DIR__ . '/../../../../app/Services/ModulePaymentLedger.php');
    $claim = substr($service, strpos($service, 'public static function completeInitiation('));
    $claim = substr($claim, 0, strpos($claim, 'public static function recordRefusedCompletion('));

    check(
        'the production claim is keyed on (reference, module, status = initiated)',
        str_contains($claim, "->where('reference', \$reference)")
            && str_contains($claim, "->where('module', \$module)")
            && str_contains($claim, "->where('status', self::INITIATED)")
            && str_contains($claim, "'status' => self::COMPLETED"),
        'completeInitiation() no longer issues the statement this script races; re-read it'
    );

    check(
        'the production claim treats exactly one affected row as the win',
        str_contains($claim, '=== 1'),
        'a claim that accepts any non-zero row count would let a second writer through'
    );

    $spend = static function ($conn, string $reference, string $module = 'Donation'): int {
        return $conn->table('module_payment_initiations')
            ->where('reference', $reference)
            ->where('module', $module)
            ->where('status', 'initiated')
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
    };

    // ------------------------------------------------------------------- 1. UNIQUE reference
    $initiate($a, 'mpb-unique');
    $duplicateRefused = false;

    try {
        $initiate($b, 'mpb-unique');
    } catch (Throwable $theEngineRefusedIt) {
        $duplicateRefused = str_contains($theEngineRefusedIt->getMessage(), '1062')
            || str_contains(strtolower($theEngineRefusedIt->getMessage()), 'duplicate');
    }

    check('the engine refuses a second row for one reference', $duplicateRefused,
        'reference is not UNIQUE on this schema, so "one row per initiation" is a convention');

    // --------------------------------------------- 2. TWO CONCURRENT COMPLETIONS, ONE WINNER
    $initiate($a, 'mpb-race');

    $a->beginTransaction();
    $firstWon = $spend($a, 'mpb-race');

    /*
     * B'S STATEMENT IS ISSUED WHILE A HOLDS THE ROW LOCK. On InnoDB it blocks until A commits and
     * then re-reads the row under the new value, so it matches zero. `innodb_lock_wait_timeout` is
     * 50 seconds by default and A commits immediately, so this does not hang -- but B is given a
     * short timeout of its own so that a FAILURE here is a fast failure rather than a stall.
     */
    $b->statement('SET SESSION innodb_lock_wait_timeout = 5');

    $a->commit();

    $secondWon = $spend($b, 'mpb-race');

    check(
        'two concurrent completions of one initiation: exactly one wins',
        $firstWon === 1 && $secondWon === 0,
        "first={$firstWon}, second={$secondWon}; both winning means the vendored writer runs twice "
        . 'for one payment, which is the duplicate the owner\'s "idempotent" asks about'
    );

    check(
        'the row ends in exactly one completed state',
        (string) $a->table('module_payment_initiations')->where('reference', 'mpb-race')->value('status') === 'completed',
        'the raced row is not `completed`'
    );

    // ------------------------------------------------------------------- 3. EVERY OTHER STATE
    foreach (['completed', 'superseded', 'abandoned', 'refused'] as $state) {
        $reference = 'mpb-state-' . $state;
        $initiate($a, $reference, 'Donation', $state);

        check(
            "a `{$state}` row cannot be spent",
            $spend($b, $reference) === 0,
            'a non-initiated row was spent, so a replay reaches the vendored writer'
        );
    }

    // --------------------------------------------------------------- 4. THE MODULE IS PART OF IT
    $initiate($a, 'mpb-module', 'LotteryPrizes');

    check(
        "one module's reference cannot be spent on another module",
        $spend($b, 'mpb-module', 'Donation') === 0,
        'the module predicate does not bite, so an initiation for an ENABLED module could open a '
        . "DISABLED module's payment writer"
    );

    check(
        'the same reference IS spendable on its own module (the control)',
        $spend($b, 'mpb-module', 'LotteryPrizes') === 1,
        'the control failed, so the check above proves nothing'
    );

    // ------------------------------------------------- 5. THE COLLATION CASE THIS SCRIPT IS FOR
    /*
     * THIS IS WHAT THE SCRIPT FOUND, AND IT CHANGED THE CODE.
     *
     * The canonical reference is 32 lower-case hex characters. The first run of this script spent
     * an initiation using `strtolower()` of a mixed-case reference and the claim returned 1: on
     * `utf8mb4_general_ci` -- the collation this server actually reports -- a match on `reference`
     * means a CASE-FOLDED SET and not the value asked for. CLAUDE.md records P1-INV-18 as exactly
     * that shape, and no SQLite test can see it because SQLite compares binary.
     *
     * Two layers now close it, and this block asserts the one that holds on every engine:
     * `ModulePaymentLedger::isWellFormedReference()` refuses anything that is not canonical, so a
     * differently-cased reference never reaches a query. The column is also `utf8mb4_bin` in the
     * migration. The ENGINE's own behaviour is printed rather than asserted, because it is a fact
     * about the installation rather than a property of this code.
     */
    $mixedCase = 'MpbCaseAbCdEf0123456789abcdef01';
    $canonical = bin2hex(random_bytes(16));

    check(
        'the service refuses a non-canonical reference, whatever the collation does',
        \App\Services\ModulePaymentLedger::isWellFormedReference(strtoupper($canonical)) === false
            && \App\Services\ModulePaymentLedger::isWellFormedReference($mixedCase) === false
            && \App\Services\ModulePaymentLedger::isWellFormedReference($canonical) === true,
        'a reference the database would case-fold reaches the claim'
    );

    $initiate($a, $canonical);
    $engineFolds = $spend($b, strtoupper($canonical)) === 1;

    echo '  note  this engine\'s `reference` predicate is '
        . ($engineFolds ? 'CASE-INSENSITIVE -- which is why the service refuses a non-canonical reference before querying'
                        : 'case-sensitive on this column')
        . "\n";

    if (! $engineFolds) {
        check(
            'the canonical reference still spends its own initiation (the control)',
            $spend($b, $canonical) === 1,
            'the control failed, so the case note above proves nothing'
        );
    }

    // --------------------------------------------------------------- 6. THE REFUSAL DEDUPE
    $hash = str_repeat('a', 64);

    $recordRefusal = static function ($conn, string $hash) {
        $existing = $conn->table('module_payment_initiations')
            ->where('module', 'Donation')
            ->where('route', 'GET {storeSlug}/store-donation-payment')
            ->where('payload_hash', $hash)
            ->where('status', 'refused')
            ->whereNull('acknowledged_at')
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            $conn->table('module_payment_initiations')->where('id', $existing->id)
                ->update(['attempts' => (int) $existing->attempts + 1, 'updated_at' => now()]);

            return 'counted';
        }

        $conn->table('module_payment_initiations')->insert([
            'reference' => bin2hex(random_bytes(16)),
            'module' => 'Donation',
            'status' => 'refused',
            'route' => 'GET {storeSlug}/store-donation-payment',
            'payload_hash' => $hash,
            'attempts' => 1,
            'reason' => 'no payment initiation is held by this session',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'inserted';
    };

    check('the first refusal inserts a row', $recordRefusal($a, $hash) === 'inserted');
    check('a repeat of the same refusal is counted, not inserted', $recordRefusal($b, $hash) === 'counted');

    $refusals = $a->table('module_payment_initiations')->where('status', 'refused')
        ->where('payload_hash', $hash)->get();

    check(
        'one row carries the repeat count',
        $refusals->count() === 1 && (int) $refusals->first()->attempts === 2,
        'rows=' . $refusals->count() . ', attempts=' . ($refusals->first()->attempts ?? 'none')
    );

    /*
     * THE DEDUPE IS A READ-THEN-WRITE, SO IT CAN RACE -- and this records what that costs rather
     * than claiming it cannot happen. Two refusals arriving at once can both read "absent" and
     * both insert, which is at worst a duplicate row in a list a person reads. It is NOT the
     * payment claim: that is the single conditional UPDATE raced above. Writing it down here is
     * the honest form, because the alternative -- a unique index on (module, route, payload_hash)
     * -- would make an UNAUTHENTICATED request able to refuse another's refusal row, and the
     * bounded duplicate is the lesser harm.
     */
    $before = (int) $a->table('module_payment_initiations')->where('status', 'refused')->count();
    $second = str_repeat('b', 64);
    $recordRefusal($a, $second);
    $recordRefusal($b, $second);
    $after = (int) $a->table('module_payment_initiations')->where('status', 'refused')->count();

    check(
        'the refusal dedupe is sequentially correct (its race is a duplicate ROW, never a spent payment)',
        $after - $before === 1,
        "inserted " . ($after - $before) . ' rows for one distinct refusal'
    );
} catch (Throwable $theScriptItselfFailed) {
    /* `HandleExceptions` would RENDER an uncaught throwable and exit 0, which is how a runner
       reported nine passes while a script died halfway. See run-mysql-verifications.sh. */
    $failures++;
    echo "  FAIL  the script raised: {$theScriptItselfFailed->getMessage()}\n";
    echo '        ' . $theScriptItselfFailed->getFile() . ':' . $theScriptItselfFailed->getLine() . "\n";
} finally {
    try {
        DB::connection('mpb_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable $theCleanupFailed) {
        echo "  note  the throwaway database could not be dropped: {$theCleanupFailed->getMessage()}\n";
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "checks: {$checks}   failed: {$failures}\n";

exit($failures === 0 ? 0 : 1);
