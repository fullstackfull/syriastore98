#!/usr/bin/env php
<?php

/**
 * P01-SEC-14 C4 — VERIFY THE PORTABLE COUPON LOOKUP AGAINST A REAL MySQL SERVER.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The property under test cannot be observed on
 * SQLite at all. The storefront coupon lookup used `whereRaw('BINARY `coupon_code` = ?')`, which
 * is MySQL-only syntax, so SQLite rejected the statement outright and every coupon-bearing
 * checkout returned 500 before reaching its own logic. Replacing it with a portable two-step
 * lookup -- an indexed SQL comparison, then a strict === filter in PHP -- makes the path runnable
 * everywhere. But on SQLite the SQL step is ALREADY case-sensitive, so the PHP filter is a no-op
 * there and a SQLite test cannot tell whether it works. On MySQL, whose default collation is
 * case-INsensitive, that filter is the only thing preserving case-sensitivity.
 *
 * A conditionally-skipped PHPUnit test is not an option: `.github/known-test-outcomes.json`
 * declares the exact skip set, so a test that skips where there is no MySQL would turn the
 * known-outcome gate red. This follows the convention set by
 * verify-sec13-migration-on-mysql.php and verify-order-document-route-cache.php.
 *
 * WHAT IT ESTABLISHES, by execution:
 *   1. the server's collation really is case-insensitive, so the premise holds;
 *   2. a plain SQL `=` on MySQL DOES match a case variant -- the thing SQLite cannot show;
 *   3. Coupon::findByExactCode() rejects that variant anyway, so case-sensitivity survives;
 *   4. it still finds the byte-exact code;
 *   5. the new lookup and the old `BINARY` form return the SAME row for every probe, so the
 *      replacement is behaviour-preserving on the engine that matters;
 *   6. the SQL step uses an index rather than scanning, so the portability fix is not a
 *      performance regression on a hot path.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database
 * and touches nothing else:
 *
 *   COUPON_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   COUPON_MYSQL_DATABASE=coupon_verify \
 *   COUPON_MYSQL_USERNAME=root \
 *   COUPON_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-coupon-code-matching-on-mysql.php
 *
 * or with COUPON_MYSQL_HOST / COUPON_MYSQL_PORT instead of a socket.
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE and
 * DROP DATABASE, and refuses to run against a database whose name contains neither 'coupon' nor
 * 'verify' -- a guard against exactly that mistake.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Coupon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('COUPON_MYSQL_DATABASE') ?: 'coupon_verify';

if (! str_contains($database, 'coupon') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] contains neither 'coupon' nor 'verify'.\n");
    fwrite(STDERR, "This script creates and drops the database it is given. Name it unmistakably.\n");
    exit(2);
}

// P01-SEC-14 F. THE SOCKET IS TRIED BEFORE TCP, because a stock Ubuntu MySQL 8 authenticates
// `root` through the `auth_socket` plugin: a TCP connection is refused with
// "Access denied for user 'root'@'localhost'" even though the credentials are right. This script
// passed earlier in the session and then could not connect at all on the same host, which is
// exactly the failure mode that makes evidence unreproducible -- so the fallback is explicit.
// Set COUPON_MYSQL_SOCKET to override, or '' plus COUPON_MYSQL_HOST to force TCP.
$socket = getenv('COUPON_MYSQL_SOCKET');

if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set COUPON_MYSQL_SOCKET='' to force TCP)\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('COUPON_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('COUPON_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('COUPON_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('COUPON_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.coupon_bootstrap' => $bootstrap]);
config(['database.connections.coupon_verify' => $connection]);

/* THE CACHE STORE, AND WITHOUT THIS LINE THIS SCRIPT CANNOT REACH ITS OWN SUBJECT.
   `App\Models\Coupon` uses `GeneaLabs\LaravelModelCaching\Traits\Cachable` (CLAUDE.md lists it
   with Setting, User and Order), so `Coupon::findByExactCode()` -- the whole point of this file --
   goes through the configured cache store first. That store is `database` in this environment and
   resolves against the DEFAULT connection, which is sqlite, so the read died on
   "no such table: cache (Connection: sqlite)" after the two pure-SQL collation assertions had
   already passed. MEASURED: 2 checks, 1 failure, and the failure was the harness rather than the
   code under test.
   `array` is the right store for a standalone verification: it needs no table, it is what
   phpunit.xml uses for the same reason, and a per-process cache cannot carry state between the
   assertions below. */
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

echo "P01-SEC-14 C4 -- portable coupon lookup, verified on MySQL\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('coupon_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('coupon_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    DB::setDefaultConnection('coupon_verify');
    Schema::clearResolvedInstances();

    $server = DB::connection('coupon_verify')->select('SELECT VERSION() AS v')[0]->v;
    echo "  MySQL {$server}, database `{$database}`\n\n";

    // A coupons table shaped like the real one for the columns this lookup touches.
    DB::connection('coupon_verify')->statement("
        CREATE TABLE coupons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            coupon_name VARCHAR(255) NULL,
            coupon_code VARCHAR(255) NULL,
            coupon_type VARCHAR(255) NOT NULL DEFAULT 'percentage',
            coupon_limit INT NOT NULL DEFAULT 0,
            coupon_expiry_date DATE NULL,
            discount_amount FLOAT NOT NULL DEFAULT 0,
            status INT NOT NULL DEFAULT 1,
            store_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            INDEX coupons_coupon_code_index (coupon_code),
            INDEX coupons_store_id_index (store_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $collation = DB::connection('coupon_verify')->select("
        SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'coupons' AND COLUMN_NAME = 'coupon_code'
    ", [$database])[0]->c;

    // 1. THE PREMISE. If the column were case-sensitive, the PHP filter would be redundant and
    //    this whole script would be proving nothing.
    check(
        "the coupon_code collation is case-INsensitive ({$collation})",
        ! str_ends_with($collation, '_bin') && ! str_ends_with($collation, '_cs'),
        "collation is {$collation}; a _bin or _cs collation would make the SQL step case-sensitive"
    );

    DB::connection('coupon_verify')->table('coupons')->insert([
        'coupon_name' => 'Ten off', 'coupon_code' => 'SAVE10', 'coupon_type' => 'percentage',
        'coupon_limit' => 0, 'coupon_expiry_date' => now()->addYear()->toDateString(),
        'discount_amount' => 10, 'store_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // 2. WHAT SQLITE CANNOT SHOW: on MySQL a plain `=` matches the case variant.
    $sqlOnly = DB::connection('coupon_verify')->table('coupons')
        ->where('coupon_code', 'save10')->count();
    check(
        'a plain SQL `=` matches a case variant on MySQL',
        $sqlOnly === 1,
        "expected 1 row for 'save10' against a 'SAVE10' row, got {$sqlOnly}"
    );

    // 3. THE CONTROL. The PHP filter must reject it anyway.
    $variant = Coupon::findByExactCode('save10');
    check(
        'findByExactCode() rejects the case variant',
        $variant === null,
        $variant === null ? '' : "returned coupon id {$variant->id} for 'save10'"
    );

    // 4. ...while still finding the byte-exact code.
    $exact = Coupon::findByExactCode('SAVE10');
    check(
        'findByExactCode() finds the byte-exact code',
        $exact !== null && $exact->coupon_code === 'SAVE10',
        $exact === null ? "returned null for 'SAVE10'" : "returned [{$exact->coupon_code}]"
    );

    // 5. EQUIVALENCE WITH THE FORM IT REPLACES, on the engine that form was written for.
    $probes = ['SAVE10', 'save10', 'Save10', 'SAVE1', 'SAVE100', '', 'SAVE10 '];
    $mismatches = [];
    foreach ($probes as $probe) {
        $legacy = Coupon::query()
            ->whereRaw('BINARY `coupon_code` = ?', [$probe])
            ->first();
        $portable = Coupon::findByExactCode($probe === '' ? '' : $probe);

        $legacyId = $legacy->id ?? null;
        $portableId = $portable->id ?? null;

        if ($legacyId !== $portableId) {
            $mismatches[] = sprintf('%s: BINARY=%s portable=%s',
                var_export($probe, true),
                var_export($legacyId, true),
                var_export($portableId, true));
        }
    }
    check(
        'the portable lookup agrees with the BINARY form on every probe',
        $mismatches === [],
        implode('; ', $mismatches)
    );

    // 6. NOT A PERFORMANCE REGRESSION. A formulation that wrapped the column in a function or a
    //    collation cast would stop using the index, which matters on a checkout path.
    $plan = DB::connection('coupon_verify')->select(
        'EXPLAIN SELECT * FROM coupons WHERE coupon_code = ?', ['SAVE10']
    )[0];
    $usesIndex = ($plan->key ?? null) !== null && strtoupper((string) ($plan->type ?? '')) !== 'ALL';
    check(
        'the SQL step uses an index rather than a full scan',
        $usesIndex,
        sprintf('EXPLAIN type=%s key=%s', $plan->type ?? 'null', $plan->key ?? 'null')
    );

    // 7. And the BINARY form it replaces did NOT use the index, which is worth recording: the
    //    portable form is not merely as good, it is better on this path.
    $legacyPlan = DB::connection('coupon_verify')->select(
        'EXPLAIN SELECT * FROM coupons WHERE BINARY `coupon_code` = ?', ['SAVE10']
    )[0];
    echo sprintf(
        "  note  BINARY form EXPLAIN: type=%s key=%s (the portable form above: type=%s key=%s)\n",
        $legacyPlan->type ?? 'null', $legacyPlan->key ?? 'null',
        $plan->type ?? 'null', $plan->key ?? 'null'
    );
} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  unexpected error: {$e->getMessage()}\n";
    echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    try {
        DB::connection('coupon_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\n  cleaned up: dropped `{$database}`\n";
    } catch (Throwable $e) {
        echo "\n  WARNING: could not drop `{$database}`: {$e->getMessage()}\n";
    }
}

echo str_repeat('-', 78) . "\n";
printf("%d checks, %d failures\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
