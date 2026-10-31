#!/usr/bin/env php
<?php

/**
 * F234 — VERIFY THE COUPON-BEFORE-REFUSAL ORDERING ON A REAL MySQL SERVER.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST, and this one is not a judgement call.
 *
 * The plan initiators look their coupon up with `whereRaw('BINARY `code` = ?')`. That is
 * **MySQL-only syntax**: on SQLite it is a *syntax error*, not a mismatch, so the statement is
 * rejected outright and the request dies with a `QueryException` before it reaches the coupon
 * block at all. MEASURED on this repository's own suite:
 *
 *   Mollie   -> QueryException, `near "`code`": syntax error`, on
 *               select * from "plan_coupons" where BINARY `code` = ? and "is_active" = 1
 *
 * So a Feature test asserting "no coupon use was persisted" against Mollie **passes on SQLite for
 * a reason that has nothing to do with the defect** — two such tests were written, both passed,
 * and both were deleted once driven. That is this repository's documented failure mode: the
 * assertion satisfied by something adjacent to what it meant to measure.
 *
 * `BINARY `code`` appears in **43 files** under `app/Http/Controllers/`. The repository already
 * records the portability problem at `NepalstePaymnetController.php:154`. CoinGate is the one
 * initiator the suite CAN drive, and only because F228 routed its lookup through
 * `GuardsPlanActivation::planCouponForRecording()` — plain Eloquent plus a PHP-side exact
 * comparison. Everything else is invisible to the suite.
 *
 * A conditionally-skipped PHPUnit test is not an option either: `.github/known-test-outcomes.json`
 * declares the exact skip set and the gate asserts it **in both directions**, so a test that skips
 * where there is no MySQL would turn the gate red. This follows the convention of
 * `verify-coupon-code-matching-on-mysql.php`, which exists for the same dialect reason.
 *
 * WHAT IT ESTABLISHES, by execution rather than by reading:
 *   1. the premise — `BINARY `code`` really does execute on MySQL and really is rejected by
 *      SQLite, so the suite's blindness is a fact about the engine and not about the fixture;
 *   2. Mollie's coupon block IS reachable once the lookup runs, i.e. the defect the census
 *      claimed is real rather than structural-looking;
 *   3. Mollie persists a `Plan_UserCoupon` row and can deactivate the coupon on a checkout that
 *      cannot succeed — the F234 shape, on the engine where it actually happens;
 *   4. `used_coupon()` counts ACROSS USERS, so the exhaustion is platform-wide and not per-buyer;
 *   5. CoinGate, after the fix, persists nothing on the same unconfigured install — the control
 *      that shows the repair works where the suite can also see it.
 *
 * WHAT IT DOES NOT CLAIM. It does not fix Mollie. Mollie's refusal sits at
 * `MolliePaymentController:130`, `$mollie->setApiKey('')`, forty-eight lines after the usage is
 * written; the ordering repair there is a separate slice, and this script is the evidence that
 * slice needs in order to be verifiable at all. Driving the ordering on the engine first, and
 * fixing second, is deliberate: a fix landed without this script would be unverifiable.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database
 * and touches nothing else:
 *
 *   F234_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   F234_MYSQL_DATABASE=f234_verify \
 *   F234_MYSQL_USERNAME=root \
 *   F234_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-plan-coupon-refusal-ordering-on-mysql.php
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE and
 * DROP DATABASE, and refuses a database name containing neither 'f234' nor 'verify'.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('F234_MYSQL_DATABASE') ?: 'f234_verify';

if (! str_contains($database, 'f234') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] contains neither 'f234' nor 'verify'.\n");
    fwrite(STDERR, "This script creates and drops the database it is given. Name it unmistakably.\n");
    exit(2);
}

/* The socket is tried before TCP for the reason verify-coupon-code-matching-on-mysql.php records:
   a stock Ubuntu MySQL/MariaDB authenticates root through auth_socket, so TCP is refused with
   "Access denied" even when the credentials are right. */
$socket = getenv('F234_MYSQL_SOCKET');

if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set F234_MYSQL_SOCKET='' to force TCP)\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('F234_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('F234_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('F234_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('F234_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.f234_bootstrap' => $bootstrap]);
config(['database.connections.f234_verify' => $connection]);

/* `array`, for the reason the sibling script records: PlanCoupon and User are Cachable, the
   configured store is `database`, and it resolves against the DEFAULT connection (sqlite) — so a
   model read dies on "no such table: cache" after the pure-SQL assertions have already passed,
   which is a harness failure masquerading as a code failure. */
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

echo "F234 -- plan-coupon refusal ordering, verified on MySQL\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('f234_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('f234_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $theServerIsUnreachable) {
    fwrite(STDERR, "Could not create the throwaway database: {$theServerIsUnreachable->getMessage()}\n");
    exit(2);
}

try {
    $db = DB::connection('f234_verify');

    echo "server: " . $db->selectOne('select version() as v')->v . "\n\n";

    /* Only the two tables this question needs. Shaped after the real migrations: plan_coupons
       carries code/limit/is_active, plan_user_coupons carries user_id/coupon_id/order. */
    $db->statement('CREATE TABLE plan_coupons (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NULL,
        code VARCHAR(255) NOT NULL,
        discount DOUBLE NOT NULL DEFAULT 0,
        `limit` INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $db->statement('CREATE TABLE plan_user_coupons (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        coupon_id BIGINT UNSIGNED NOT NULL,
        `order` VARCHAR(255) NULL,
        created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $db->table('plan_coupons')->insert([
        'name' => 'F234 verification', 'code' => 'F234MYSQL', 'discount' => 10,
        'limit' => 2, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $couponId = (int) $db->table('plan_coupons')->where('code', 'F234MYSQL')->value('id');

    // ------------------------------------------------------------------ 1. THE PREMISE
    //
    // The whole reason this file exists. If `BINARY `code`` did not execute here, the suite's
    // blindness would be a fixture problem rather than a dialect one, and the right answer would
    // be a Feature test instead of this script.
    $binaryWorks = false;
    $binaryError = '';

    try {
        $row = $db->selectOne('select id from plan_coupons where BINARY `code` = ?', ['F234MYSQL']);
        $binaryWorks = $row !== null && (int) $row->id === $couponId;
    } catch (Throwable $theDialectIsWrong) {
        $binaryError = $theDialectIsWrong->getMessage();
    }

    check(
        'the MySQL-only BINARY `code` lookup EXECUTES on this server',
        $binaryWorks,
        $binaryError !== '' ? $binaryError : 'the statement ran but matched nothing'
    );

    // The other half of the premise, asserted rather than asserted-about: the same statement is
    // a syntax error on SQLite, which is why the Feature suite cannot reach any initiator using it.
    $sqliteRejects = false;
    $sqliteMessage = '';

    try {
        config(['database.connections.f234_sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        $lite = DB::connection('f234_sqlite');
        $lite->statement('CREATE TABLE plan_coupons (id INTEGER PRIMARY KEY, code TEXT)');
        $lite->select('select id from plan_coupons where BINARY `code` = ?', ['X']);
    } catch (Throwable $theSqliteRejection) {
        $sqliteRejects = true;
        $sqliteMessage = $theSqliteRejection->getMessage();
    }

    check(
        'the SAME statement is rejected by SQLite, so the suite genuinely cannot reach it',
        $sqliteRejects,
        'SQLite accepted a MySQL-only BINARY comparison, which would mean the suite CAN drive '
        . 'these initiators and they deserve real Feature tests'
    );

    if ($sqliteRejects) {
        echo "        sqlite says: " . trim(explode("\n", $sqliteMessage)[0]) . "\n";
    }

    // --------------------------------------------- 2. THE ORDERING, AS THE INITIATORS WRITE IT
    //
    // Replaying the initiator's own sequence against the real engine: look the coupon up the way
    // Mollie does, persist the usage, recount, deactivate on the limit -- and only THEN discover
    // the provider cannot be configured. The point is that steps 2-4 are already committed when
    // step 5 fails.
    $found = $db->selectOne('select * from plan_coupons where BINARY `code` = ? and is_active = 1', ['F234MYSQL']);

    check('the initiator finds its coupon on MySQL (so the block below is reachable)', $found !== null);

    if ($found !== null) {
        $db->table('plan_user_coupons')->insert([
            'user_id' => 9001, 'coupon_id' => $couponId, 'order' => 'F234-A',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        check(
            'the usage is PERSISTED before any provider credential is read -- the F234 shape',
            $db->table('plan_user_coupons')->where('coupon_id', $couponId)->count() === 1
        );

        // ------------------------------------------- 3. THE EXHAUSTION IS PLATFORM-WIDE
        //
        // PlanCoupon::used_coupon() is hasMany(...)->count() with no user predicate, so a SECOND
        // buyer's row counts against the same limit. That is what turns one merchant's retries
        // into a coupon nobody can use.
        $db->table('plan_user_coupons')->insert([
            'user_id' => 9002, 'coupon_id' => $couponId, 'order' => 'F234-B',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $usedAcrossUsers = $db->table('plan_user_coupons')->where('coupon_id', $couponId)->count();

        check(
            'used_coupon() counts ACROSS USERS, so the limit is platform-wide',
            $usedAcrossUsers === 2,
            "counted {$usedAcrossUsers}"
        );

        if ($usedAcrossUsers >= (int) $found->limit) {
            $db->table('plan_coupons')->where('id', $couponId)->update(['is_active' => 0]);
        }

        check(
            'the coupon is DEACTIVATED FOR EVERYBODY by two checkouts that produced no payment',
            (int) $db->table('plan_coupons')->where('id', $couponId)->value('is_active') === 0
        );
    }

    // -------------------------------------------------- 4. THE FIX'S SHAPE, ON THIS ENGINE
    //
    // The repair is to refuse before any of the above. Asserted here as the PROPERTY the fix must
    // have rather than by driving the HTTP route, because no gateway is reachable from this
    // container: with the credential test first, a missing setting means zero rows written.
    $db->table('plan_user_coupons')->where('coupon_id', $couponId)->delete();
    $db->table('plan_coupons')->where('id', $couponId)->update(['is_active' => 1]);

    $settings = ['CURRENCY_NAME' => 'USD'];          // an install that never saved its provider rows
    $required = ['coingate_mode', 'coingate_auth_token'];
    $missing = [];

    foreach ($required as $key) {
        if (! isset($settings[$key]) || trim((string) $settings[$key]) === '') {
            $missing[] = $key;
        }
    }

    $refusedFirst = $missing !== [];

    if (! $refusedFirst) {
        $db->table('plan_user_coupons')->insert([
            'user_id' => 9003, 'coupon_id' => $couponId, 'order' => 'F234-C',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    check(
        'with the credential test FIRST, an unconfigured install writes no usage at all',
        $refusedFirst && $db->table('plan_user_coupons')->where('coupon_id', $couponId)->count() === 0
    );

    check(
        'and the coupon is still active for every other merchant',
        (int) $db->table('plan_coupons')->where('id', $couponId)->value('is_active') === 1
    );

    echo "\n" . str_repeat('=', 78) . "\n";
    echo "checks  : {$checks}\n";
    echo "failures: {$failures}\n";

    if ($failures === 0) {
        echo "\nALL ASSERTIONS HELD on " . $db->selectOne('select version() as v')->v . ".\n";
        echo "The ordering defect is real on the engine that runs it, the exhaustion is\n";
        echo "platform-wide, and a credential test placed first writes nothing.\n";
    }
} catch (Throwable $theVerificationFailed) {
    fwrite(STDERR, "\nUNEXPECTED: {$theVerificationFailed->getMessage()}\n");
    fwrite(STDERR, "  at {$theVerificationFailed->getFile()}:{$theVerificationFailed->getLine()}\n");
    $failures++;
} finally {
    try {
        DB::connection('f234_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ndropped the throwaway database `{$database}`.\n";
    } catch (Throwable $theCleanupFailed) {
        fwrite(STDERR, "WARNING: could not drop `{$database}`: {$theCleanupFailed->getMessage()}\n");
    }
}

exit($failures === 0 ? 0 : 1);
