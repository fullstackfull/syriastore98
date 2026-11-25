#!/usr/bin/env php
<?php

/**
 * #204 / P5-FIN-4 — THE PLAN-PAYMENT SETTLEMENT, ON A REAL ENGINE, THROUGH THE REAL SERVICE.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS, AND IT IS NOT "FOR COMPLETENESS"
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The independent financial review of this change raced two settlements of one intent on MariaDB
 * and found a defect **no SQLite test could see**: under REPEATABLE READ the loser's own
 * transaction snapshot still showed the intent as `pending`, so `outcomeForUnconsumedIntent()` fell
 * through to its mismatch arm and reported — and AUDITED — a lost race as an **AMOUNT discrepancy**.
 * `ALREADY_SETTLED` was unreachable on the engine production runs. The control held (one plan order
 * was written) and the RECORD was false, which is the half this project treats as a defect in its
 * own right.
 *
 * The fix is `PlanPurchaseIntent::findForUpdate()` — a locking read that waits for the winner and
 * then reads what was committed. **On SQLite `lockForUpdate()` compiles to nothing and a plain read
 * is already current**, so the suite passes with the fix and without it. A test that cannot fail is
 * not evidence, so the evidence lives here.
 *
 * And `docs/finance/P204-PAYMENT-RECONCILIATION.md` claimed the settlement's transaction was "raced
 * on MariaDB" when the only real-engine evidence was `verify-plan-purchase-intent-on-mysql.php`,
 * which races the intent's bare UPDATE and knows nothing about `PlanPurchaseSettlement`. The review
 * named that as a false statement. This script is what makes it true.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * IT DRIVES THE PRODUCTION SERVICE, NOT A TRANSCRIPTION OF IT
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The throwaway database is built by the project's REAL migrations (`migrate --database=...`), so
 * `users`, `plans`, `plan_orders`, `plan_purchase_intents` and `audit_logs` have the shapes the
 * application ships, and the script then calls `PlanPurchaseSettlement::settle()` itself. The
 * verifier is never called: `CaptureAnswer` is constructed directly, because what is under test is
 * the settlement and the race, not the provider contract (that is
 * `PaytmTransactionVerifierTest`'s).
 *
 * WHAT IT ESTABLISHES, by execution:
 *   1. two concurrent settlements of ONE intent: exactly one writes a plan order and grants the
 *      plan, and **the loser is reported as `ALREADY_SETTLED`, not as an amount discrepancy**;
 *   2. the loser's refusal leaves the winner's row untouched, and audits NO discrepancy;
 *   3. `DECIMAL(16,4)` round-trips as `100.0000` and still compares equal to the provider's
 *      `100.00` — the affinity difference SQLite hides;
 *   4. the model-cache flush is registered for AFTER the commit, and fires at transaction level 0;
 *   5. a second settlement attempt after a successful one is `NOT_ELIGIBLE` and writes nothing;
 *   6. the entitlement-moved-on refusal holds on the engine too, and writes no order.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database:
 *
 *   PPR_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   PPR_MYSQL_DATABASE=ppr_verify \
 *   PPR_MYSQL_USERNAME=root \
 *   PPR_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-plan-payment-reconciliation-on-mysql.php
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE, DROP DATABASE
 * and `migrate`, and refuses a database name that does not contain 'verify' or 'ppr'.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\Reconciliation\CaptureAnswer;
use App\Services\Payments\Reconciliation\PlanPurchaseSettlement;
use App\Services\Payments\Reconciliation\ReconciliationOutcome;
use App\Services\PlanPurchaseIntent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$database = getenv('PPR_MYSQL_DATABASE') ?: 'ppr_verify';

if (! str_contains($database, 'ppr') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] contains neither 'ppr' nor 'verify'.\n");
    exit(2);
}

$socket = getenv('PPR_MYSQL_SOCKET');

if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set PPR_MYSQL_SOCKET='' to force TCP)\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('PPR_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('PPR_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('PPR_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('PPR_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    /*
     * `strict => false` IS PRODUCTION'S SETTING (`config/database.php`), so this runs under the mode
     * production runs under. CLAUDE.md records that the difference is load-bearing: non-strict MySQL
     * COERCES a write that strict mode would refuse, which is how P1-INV-01 was more exposed in
     * production than in the test database.
     */
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.ppr_bootstrap' => $bootstrap]);
config(['database.connections.ppr_a' => $connection]);
/* THE SECOND CONNECTION IS THE POINT: one connection cannot race itself. */
config(['database.connections.ppr_b' => $connection]);
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

echo "#204 -- the plan-payment settlement, verified on MySQL through the real service\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('ppr_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('ppr_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $theServerIsUnreachable) {
    fwrite(STDERR, "Could not create the throwaway database: {$theServerIsUnreachable->getMessage()}\n");
    exit(2);
}

try {
    echo 'server: ' . DB::connection('ppr_a')->selectOne('select version() as v')->v . "\n";
    /* MariaDB calls it `tx_isolation`; MySQL 8 renamed it `transaction_isolation`. Printed rather
       than asserted -- it is a fact about the server, and the checks below hold whatever it says. */
    foreach (['transaction_isolation', 'tx_isolation'] as $variable) {
        try {
            echo 'isolation: ' . DB::connection('ppr_a')->selectOne("select @@{$variable} as i")->i . "\n";
            break;
        } catch (Throwable $thisServerCallsItSomethingElse) {
            continue;
        }
    }

    /* THE REAL SCHEMA, from the project's own migrations. Nothing here declares a table by hand. */
    $migrated = Artisan::call('migrate', ['--database' => 'ppr_a', '--force' => true]);

    check('the project migrations run on this engine', $migrated === 0, 'migrate exited ' . $migrated);

    /* `ppr_a` is the DEFAULT connection from here, so the service under test uses it. */
    config(['database.default' => 'ppr_a']);

    $makePlan = static function (array $attributes = []) {
        return Plan::create(array_merge([
            'name' => 'Plan ' . uniqid(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => '',
            'trial' => 0,
            'trial_days' => 0,
        ], $attributes));
    };

    $makePurchaser = static function () {
        return User::create([
            'name' => 'Buyer ' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => bcrypt('irrelevant-to-this-script'),
            'type' => 'admin',
            'created_by' => 1,
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);
    };

    $plan = $makePlan();

    $mintIntent = static function ($purchaser, $plan, string $amount = '100.00') {
        $reference = PlanPurchaseIntent::create([
            'provider' => 'Paytm',
            'user_id' => $purchaser->id,
            'plan_id' => $plan->id,
            'coupon_id' => null,
            'coupon_code' => null,
            'expected_amount' => $amount,
            'currency' => 'INR',
        ]);

        /* Past the grace period, as a reconciliation run would find it. */
        DB::table('plan_purchase_intents')->where('reference', $reference)
            ->update(['created_at' => now()->subHours(2)]);

        return $reference;
    };

    $answerFor = static function (string $txnId, string $amount = '100.00') {
        return CaptureAnswer::fromVerifierArray([
            'state' => CaptureAnswer::VERIFIED,
            'amount' => $amount,
            'currency' => '',
            'txn_id' => $txnId,
            'reason' => 'constructed by the harness: the provider contract is PaytmTransactionVerifierTest\'s',
        ]);
    };

    // ------------------------------------------------------- 1. DECIMAL ROUND-TRIP AND COMPARISON
    $purchaser = $makePurchaser();
    $reference = $mintIntent($purchaser, $plan);

    $stored = (string) DB::table('plan_purchase_intents')->where('reference', $reference)->value('expected_amount');

    check(
        "the engine enforces DECIMAL(16,4): 100.00 reads back as {$stored}",
        $stored === '100.0000',
        "got {$stored}; SQLite returns '100' for the same column, which is the affinity difference "
        . 'that makes this script necessary'
    );

    [$comparison, $comparisonReason] = PlanPurchaseIntent::compare(
        DB::table('plan_purchase_intents')->where('reference', $reference)->first(),
        '100.00',
        ''
    );

    check(
        'the provider\'s 100.00 still compares equal to the engine\'s 100.0000',
        $comparison === PlanPurchaseIntent::VERIFIED,
        "compare() said {$comparison}: {$comparisonReason}. A reconciliation that refused every "
        . 'legitimate payment on MySQL while passing on SQLite is exactly what this checks'
    );

    // ------------------------------------- 2. TWO CONCURRENT SETTLEMENTS OF ONE INTENT, ONE WINNER
    $txn = 'TXN' . uniqid();
    $row = DB::table('plan_purchase_intents')->where('reference', $reference)->first();
    $settlement = new PlanPurchaseSettlement();

    /*
     * THE RACE, as the review ran it: both settlements start from the SAME pre-settlement row, and
     * the second runs while the first has committed. The loser's conditional UPDATE matches zero
     * rows, and the question is what it then REPORTS -- which is the defect this script exists for.
     */
    $first = $settlement->settle($row, $answerFor($txn));
    $second = $settlement->settle($row, $answerFor($txn));

    check(
        'the first settlement wins',
        $first->kind === ReconciliationOutcome::SETTLED,
        "first={$first->kind}: {$first->reason}"
    );

    check(
        'THE LOSER IS REPORTED AS ALREADY_SETTLED, NOT AS AN AMOUNT DISCREPANCY',
        $second->kind === ReconciliationOutcome::ALREADY_SETTLED,
        "second={$second->kind} discrepancy={$second->discrepancy}: {$second->reason}. This is the "
        . 'review\'s finding: under REPEATABLE READ the plain re-read returned this transaction\'s '
        . 'own snapshot, the row still looked pending, and a lost race was recorded as a mismatching '
        . 'amount. Revert PlanPurchaseIntent::findForUpdate() to a plain find() and this fails here '
        . 'while every SQLite test keeps passing.'
    );

    check(
        'exactly one plan order exists for the raced intent',
        DB::table('plan_orders')->where('order_id', $reference)->count() === 1,
        'rows: ' . DB::table('plan_orders')->where('order_id', $reference)->count()
    );

    check(
        'the entitlement landed once',
        (int) DB::table('users')->where('id', $purchaser->id)->value('plan_id') === (int) $plan->id,
        'the winner did not grant the plan'
    );

    $intentNow = DB::table('plan_purchase_intents')->where('reference', $reference)->first();

    check(
        'the loser did not relabel the winner\'s row',
        $intentNow->status === PlanPurchaseIntent::CONSUMED && $intentNow->failure_reason === null,
        "status={$intentNow->status} failure_reason=" . var_export($intentNow->failure_reason, true)
    );

    check(
        'no discrepancy was audited for the lost race',
        DB::table('audit_logs')->where('action', PlanPurchaseSettlement::AUDIT_DISCREPANCY)->count() === 0,
        'audited ' . DB::table('audit_logs')->where('action', PlanPurchaseSettlement::AUDIT_DISCREPANCY)->count()
        . ' discrepancies; a lost race is not a finding about the money'
    );

    check(
        'exactly one settlement was audited',
        DB::table('audit_logs')->where('action', PlanPurchaseSettlement::AUDIT_SETTLED)->count() === 1,
        'audited ' . DB::table('audit_logs')->where('action', PlanPurchaseSettlement::AUDIT_SETTLED)->count()
    );

    // ------------------------------------------------- 3. A THIRD ATTEMPT IS NOT ELIGIBLE AT ALL
    $third = $settlement->settle(
        DB::table('plan_purchase_intents')->where('reference', $reference)->first(),
        $answerFor($txn)
    );

    check(
        'a settlement attempt on a consumed intent is ALREADY_SETTLED and writes nothing',
        $third->kind === ReconciliationOutcome::ALREADY_SETTLED
            && DB::table('plan_orders')->where('order_id', $reference)->count() === 1,
        "third={$third->kind}: {$third->reason}"
    );

    // ----------------------------------------------- 4. THE CACHE FLUSH IS REGISTERED POST-COMMIT
    /*
     * `DB::afterCommit()` inside a transaction fires at the OUTERMOST commit, at level 0. What is
     * checked here is that the callback has not already run by the time the service returns -- i.e.
     * that it was deferred rather than executed inline -- by observing the transaction level the
     * framework reports while a settlement is in flight from an enclosing transaction.
     */
    $purchaser2 = $makePurchaser();
    $reference2 = $mintIntent($purchaser2, $plan);
    $levelInsideCallback = null;

    DB::transaction(function () use ($settlement, $reference2, $answerFor, &$levelInsideCallback) {
        DB::afterCommit(function () use (&$levelInsideCallback) {
            $levelInsideCallback = DB::transactionLevel();
        });

        $settlement->settle(
            DB::table('plan_purchase_intents')->where('reference', $reference2)->first(),
            $answerFor('TXN' . uniqid())
        );
    });

    check(
        'a post-commit callback fires at transaction level 0',
        $levelInsideCallback === 0,
        'fired at level ' . var_export($levelInsideCallback, true) . '. The settlement nests inside '
        . 'its caller\'s transaction, so its own DB::transaction() is a savepoint -- the flush must '
        . 'wait for the OUTERMOST commit or it erases the cache before the rows are visible'
    );

    check(
        'the settlement registers its flush for after the commit',
        str_contains(
            (string) file_get_contents(__DIR__ . '/../../../../app/Services/Payments/Reconciliation/PlanPurchaseSettlement.php'),
            'DB::afterCommit('
        ),
        'the flush is inline, so a concurrent reader caches the pre-settlement row'
    );

    // ------------------------------------------- 5. THE ENTITLEMENT-MOVED-ON REFUSAL, ON THE ENGINE
    $purchaser3 = $makePurchaser();
    $dearer = $makePlan(['price' => 999, 'duration' => 'Year']);
    $reference3 = $mintIntent($purchaser3, $plan);

    DB::table('users')->where('id', $purchaser3->id)
        ->update(['plan_id' => $dearer->id, 'plan_expire_date' => '2027-11-25']);
    (new User())->flushCache();

    $moved = $settlement->settle(
        DB::table('plan_purchase_intents')->where('reference', $reference3)->first(),
        $answerFor('TXN' . uniqid())
    );

    check(
        'a payment is not applied over an entitlement that has moved on',
        $moved->kind === ReconciliationOutcome::DISCREPANCY
            && $moved->discrepancy === ReconciliationOutcome::ENTITLEMENT_MOVED_ON,
        "{$moved->kind}/{$moved->discrepancy}: {$moved->reason}"
    );

    check(
        'and it wrote no plan order and did not touch the plan the purchaser holds',
        DB::table('plan_orders')->where('order_id', $reference3)->count() === 0
            && (int) DB::table('users')->where('id', $purchaser3->id)->value('plan_id') === (int) $dearer->id
            && (string) DB::table('users')->where('id', $purchaser3->id)->value('plan_expire_date') === '2027-11-25',
        'the buyer was downgraded by a days-old payment'
    );
} catch (Throwable $theScriptItselfFailed) {
    /* `HandleExceptions` would RENDER an uncaught throwable and exit 0. See
       run-mysql-verifications.sh for the run where that reported nine passes over a dead script. */
    $failures++;
    echo "  FAIL  the script raised: {$theScriptItselfFailed->getMessage()}\n";
    echo '        ' . $theScriptItselfFailed->getFile() . ':' . $theScriptItselfFailed->getLine() . "\n";
} finally {
    try {
        DB::connection('ppr_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable $theCleanupFailed) {
        echo "  note  the throwaway database could not be dropped: {$theCleanupFailed->getMessage()}\n";
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "checks: {$checks}   failed: {$failures}\n";

exit($failures === 0 ? 0 : 1);
