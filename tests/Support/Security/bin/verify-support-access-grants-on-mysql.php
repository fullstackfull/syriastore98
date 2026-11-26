<?php

/**
 * `SUPPORT-004` / `SUPPORT-005` — VALIDATE THE GRANT TABLES AGAINST A REAL MySQL SERVER.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The suite runs on SQLite `:memory:`, and
 * `.github/known-test-outcomes.json` declares the exact skip set, so a test that skipped where
 * there is no MySQL would turn the known-outcome gate red. CLAUDE.md's rule: evidence that cannot
 * live in the suite goes here as an executable script stating its own preconditions. Convention
 * follows `verify-sec13-migration-on-mysql.php`.
 *
 * -------------------------------------------------------------------------------------------------
 *  THE ENGINE BEHAVIOUR THIS EXISTS TO MEASURE, AND IT IS NOT HYPOTHETICAL
 * -------------------------------------------------------------------------------------------------
 *
 * With `explicit_defaults_for_timestamp = OFF` -- the legacy MySQL default, and still the default on
 * MariaDB -- **the FIRST `TIMESTAMP NOT NULL` column in a table silently acquires
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`**. The second and later ones instead
 * acquire `NOT NULL` with no default, so an INSERT that omits them errors.
 *
 * `support_access_grants` declares four timestamp columns and TWO of them are NOT NULL:
 * `requested_at` (declared first) and `expires_at`. So on an engine with that setting off:
 *
 *   • `requested_at` would be rewritten to NOW() **on every UPDATE of the row** -- and the row is
 *     updated by `consent()`, `revoke()` and every `recordUse()`. "When was this access asked for"
 *     would silently become "when was it last touched", which is the one column a merchant reading
 *     the trail uses to judge whether a request is current.
 *   • Far worse if the column order ever changes: were `expires_at` to become the first NOT NULL
 *     timestamp, **`ON UPDATE CURRENT_TIMESTAMP` would reset the expiry on every save** -- so
 *     recording a USE would extend the grant, and `SUPPORT-004`'s temporary clause would be undone
 *     by the very act of using the access. SQLite cannot show either, because it has no TIMESTAMP
 *     type and no ON UPDATE semantics at all. This is precisely the class of MySQL-only defect
 *     CLAUDE.md records P01-SEC-13 surviving on.
 *
 * WHAT IT ESTABLISHES, by execution rather than argument:
 *   1. both tables are created on MySQL at all, with the declared column types;
 *   2. `expires_at` is NOT NULL on the engine, and an UPDATE to NULL is refused -- the schema half
 *      of the temporary clause, which the suite also asserts on SQLite;
 *   3. **no column on either table carries `ON UPDATE CURRENT_TIMESTAMP`** -- read from
 *      `information_schema.columns.EXTRA`, whatever this server's `explicit_defaults_for_timestamp`
 *      happens to be, and the setting is PRINTED so the reading is interpretable;
 *   4. `requested_at` and `expires_at` survive an UPDATE of other columns unchanged -- the
 *      behavioural form of 3, which holds even if a future MySQL spells EXTRA differently;
 *   5. the UNIQUE (grant_id, approver_id) index is real on the engine, so one approver cannot
 *      satisfy an N-approver rule by inserting twice -- the one `SUPPORT-005` property that is
 *      enforced by the database rather than by PHP;
 *   6. `store_id` accepts 0, which is what makes the gate's `$storeId <= 0` guard load-bearing
 *      rather than redundant (the suite asserts the guard; this asserts its premise on MySQL);
 *   7. `down()` drops exactly the two tables it created and nothing else.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database
 * and touches nothing else:
 *
 *   SUPPORTACCESS_MYSQL_SOCKET=/path/to/mysqld.sock \
 *   SUPPORTACCESS_MYSQL_DATABASE=support_access_verify \
 *   SUPPORTACCESS_MYSQL_USERNAME=root \
 *   SUPPORTACCESS_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-support-access-grants-on-mysql.php
 *
 * or with SUPPORTACCESS_MYSQL_HOST / SUPPORTACCESS_MYSQL_PORT instead of a socket.
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE and
 * DROP DATABASE, and it refuses to run against a database whose name contains neither
 * 'support_access' nor 'verify'.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$failures = [];
$checks = 0;

function ok(string $what): void
{
    global $checks;
    $checks++;
    echo "  PASS  {$what}\n";
}

function bad(string $what, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    $failures[] = $what . ($detail !== '' ? " -- {$detail}" : '');
    echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function check(bool $condition, string $what, string $detail = ''): void
{
    $condition ? ok($what) : bad($what, $detail);
}

// ---------------------------------------------------------------------------------------
// PRECONDITIONS
// ---------------------------------------------------------------------------------------

$database = getenv('SUPPORTACCESS_MYSQL_DATABASE') ?: 'support_access_verify';

if (! str_contains($database, 'support_access') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$database}' contains neither 'support_access' nor "
        . "'verify'.\nThis script issues DROP DATABASE. Name the throwaway database accordingly.\n");
    exit(2);
}

$socket = getenv('SUPPORTACCESS_MYSQL_SOCKET') ?: '';
$host = getenv('SUPPORTACCESS_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('SUPPORTACCESS_MYSQL_PORT') ?: '3306';
$username = getenv('SUPPORTACCESS_MYSQL_USERNAME') ?: 'root';
$password = getenv('SUPPORTACCESS_MYSQL_PASSWORD');
$password = $password === false ? '' : $password;

$common = [
    'driver' => 'mysql',
    'host' => $host,
    'port' => $port,
    'unix_socket' => $socket,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
];

config([
    'database.connections.sa_verify' => $common + ['database' => $database],
    'database.connections.sa_root' => $common + ['database' => null],
]);

echo "SUPPORT-004 / SUPPORT-005 GRANT TABLES, AGAINST A REAL MySQL SERVER\n";
echo str_repeat('=', 86) . "\n";

$migration = __DIR__ . '/../../../../database/migrations/'
    . '2026_11_26_000100_create_support_access_grants_tables.php';

if (! is_file($migration)) {
    fwrite(STDERR, "REFUSING: the migration under test is missing at {$migration}\n");
    exit(2);
}

try {
    DB::connection('sa_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('sa_root')->statement("CREATE DATABASE `{$database}` "
        . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    $version = DB::connection('sa_verify')->selectOne('SELECT VERSION() AS v')->v;

    // THE SETTING THAT DECIDES EVERYTHING BELOW, PRINTED RATHER THAN ASSUMED. A reader must be
    // able to tell whether check 3 passing means "the migration is safe" or "this server would not
    // have shown the problem anyway". The variable does not exist on every MariaDB build, hence the
    // try.
    $explicit = 'unavailable on this server';
    try {
        $row = DB::connection('sa_verify')
            ->selectOne("SHOW VARIABLES LIKE 'explicit_defaults_for_timestamp'");
        $explicit = $row->Value ?? 'unavailable';
    } catch (Throwable $e) {
        // left as 'unavailable on this server'
    }

    echo "  server: {$version}\n";
    echo "  explicit_defaults_for_timestamp = {$explicit}\n";
    echo "  (OFF is the setting under which the FIRST `TIMESTAMP NOT NULL` column silently gains\n";
    echo "   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP. Checks 3 and 4 are the ones\n";
    echo "   that matter if this reads OFF.)\n";
    echo str_repeat('-', 86) . "\n";

    Schema::connection('sa_verify')->dropIfExists('support_access_approvals');
    Schema::connection('sa_verify')->dropIfExists('support_access_grants');

    // Run the real migration file against this connection.
    $instance = require $migration;
    DB::setDefaultConnection('sa_verify');
    $instance->up();

    // ---- 1. the tables exist, with the declared columns -------------------------------------
    check(Schema::connection('sa_verify')->hasTable('support_access_grants'),
        'support_access_grants is created on MySQL');
    check(Schema::connection('sa_verify')->hasTable('support_access_approvals'),
        'support_access_approvals is created on MySQL');

    foreach (['store_id', 'merchant_id', 'grantee_id', 'kind', 'scope', 'reason', 'requested_by',
              'requested_at', 'consented_at', 'consented_by', 'expires_at', 'revoked_at',
              'revoked_by', 'revoked_reason', 'first_used_at', 'last_used_at', 'uses'] as $column) {
        check(Schema::connection('sa_verify')->hasColumn('support_access_grants', $column),
            "support_access_grants.{$column} exists");
    }

    $columns = DB::connection('sa_verify')->select(
        'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE, EXTRA, COLUMN_DEFAULT
           FROM information_schema.columns
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?)',
        [$database, 'support_access_grants', 'support_access_approvals']
    );

    $byName = [];
    foreach ($columns as $c) {
        $byName[$c->COLUMN_NAME] = $c;
    }

    // ---- 2. expires_at is NOT NULL on the engine ---------------------------------------------
    check(($byName['expires_at']->IS_NULLABLE ?? null) === 'NO',
        'expires_at is NOT NULL on MySQL -- SUPPORT-004\'s temporary clause is in the schema',
        'IS_NULLABLE=' . ($byName['expires_at']->IS_NULLABLE ?? 'missing'));

    // ---- 3. NO column carries ON UPDATE CURRENT_TIMESTAMP ------------------------------------
    $onUpdate = [];
    foreach ($columns as $c) {
        if (stripos((string) $c->EXTRA, 'on update') !== false) {
            $onUpdate[] = $c->COLUMN_NAME . ' (' . $c->EXTRA . ')';
        }
    }

    check($onUpdate === [],
        'no column on either table carries ON UPDATE CURRENT_TIMESTAMP',
        $onUpdate === [] ? '' : 'found: ' . implode(', ', $onUpdate)
            . ' -- a grant column that rewrites itself on every save makes the audit trail lie, and '
            . 'on expires_at it would EXTEND the grant every time the access was used');

    // ---- 4. the behavioural form of 3 ---------------------------------------------------------
    $past = '2020-01-02 03:04:05';
    DB::connection('sa_verify')->table('support_access_grants')->insert([
        'store_id' => 7, 'merchant_id' => 8, 'grantee_id' => 9,
        'kind' => 'support', 'scope' => 'full', 'reason' => 'mysql verification',
        'requested_by' => 9, 'requested_at' => $past, 'expires_at' => '2030-01-01 00:00:00',
        'uses' => 0, 'created_at' => $past, 'updated_at' => $past,
    ]);

    $id = (int) DB::connection('sa_verify')->getPdo()->lastInsertId();

    // The write `recordUse()` performs: counters only, never the two NOT NULL timestamps.
    DB::connection('sa_verify')->table('support_access_grants')->where('id', $id)
        ->update(['uses' => 1, 'last_used_at' => '2029-01-01 00:00:00']);

    $after = DB::connection('sa_verify')->table('support_access_grants')->where('id', $id)->first();

    check(str_starts_with((string) $after->requested_at, '2020-01-02 03:04:05'),
        'requested_at survives an unrelated UPDATE unchanged',
        'became ' . $after->requested_at . ' -- the column is rewritten on every save, so "when was '
        . 'this access asked for" silently means "when was it last touched"');

    check(str_starts_with((string) $after->expires_at, '2030-01-01 00:00:00'),
        'expires_at survives an unrelated UPDATE unchanged',
        'became ' . $after->expires_at . ' -- RECORDING A USE EXTENDS THE GRANT, which undoes '
        . 'SUPPORT-004\'s temporary clause by the act of using the access');

    /* ---- 2b. WHAT AN UPDATE TO NULL ACTUALLY DOES ON THIS ENGINE ----------------------------
     *
     * THE FIRST VERSION OF THIS CHECK ASSERTED THE WRONG PROPERTY AND ITS FAILURE TEXT OVERSTATED
     * THE RISK. It asserted that the engine REFUSES the UPDATE -- which is what SQLite does, and
     * what the suite case `SupportAccessGrantMechanismTest::test_the_table_refuses_a_grant_with_no_expiry`
     * measures -- and reported the MariaDB result as "a permanent support grant can be written with
     * one UPDATE".
     *
     * MEASURED INSTEAD, on MariaDB 10.11 with `STRICT_TRANS_TABLES` set:
     *
     *     CREATE TABLE t (id INT PRIMARY KEY, expires_at TIMESTAMP NOT NULL);
     *     INSERT INTO t VALUES (1, '2030-01-01 00:00:00');
     *     UPDATE t SET expires_at = NULL WHERE id = 1;     -- succeeds, no warning
     *     SELECT expires_at, expires_at IS NULL FROM t;    -- 2026-11-26 20:56:33, 0
     *
     * Assigning NULL to a `TIMESTAMP NOT NULL` column sets it to **the current timestamp**. It is
     * not an error and it is not NULL. So:
     *
     *   • the claim "a permanent grant can be written with one UPDATE" IS FALSE on this engine.
     *     NOW() is not in the future, so `SupportAccessGrant::hasExpired()` reads such a row as
     *     EXPIRED and the gate refuses it. The coercion FAILS CLOSED.
     *   • but the SUITE's assertion is a SQLite-only property, and believing it describes production
     *     would be wrong. That is CLAUDE.md's "SQLite's type affinity hides real MySQL defects",
     *     here in the harmless direction -- and it is recorded rather than quietly dropped because
     *     the next person to read that test will otherwise believe production refuses the write.
     *
     * So this now asserts the property that actually protects the mechanism: however the engine
     * treats the NULL, the row that results is NOT a future expiry.
     */
    $refusedByEngine = false;

    try {
        DB::connection('sa_verify')->table('support_access_grants')->where('id', $id)
            ->update(['expires_at' => null]);
    } catch (Throwable $e) {
        $refusedByEngine = true;
    }

    if ($refusedByEngine) {
        ok('an UPDATE setting expires_at to NULL is refused outright by this engine');
    } else {
        $coerced = DB::connection('sa_verify')->table('support_access_grants')
            ->where('id', $id)->value('expires_at');

        echo "  note: the engine ACCEPTED expires_at = NULL and stored " . var_export($coerced, true) . "\n";

        $isFuture = $coerced !== null && strtotime((string) $coerced) > time();

        check(! $isFuture,
            'an accepted expires_at = NULL does NOT yield a future expiry, so the grant is expired '
            . 'rather than permanent',
            'stored ' . var_export($coerced, true) . ', which is in the FUTURE -- setting the '
            . 'expiry to NULL extends the grant instead of ending it, and SUPPORT-004\'s temporary '
            . 'clause can be defeated with one UPDATE');

        // And the model agrees, which is the layer that actually decides.
        $model = new App\Models\SupportAccessGrant;
        $model->setRawAttributes(['expires_at' => $coerced], true);
        check($model->hasExpired(),
            'SupportAccessGrant::hasExpired() reads the coerced row as EXPIRED',
            'the model considers the coerced row live');
    }

    // ---- 5. the UNIQUE index is real ----------------------------------------------------------
    DB::connection('sa_verify')->table('support_access_approvals')->insert([
        'grant_id' => $id, 'approver_id' => 100, 'decision' => 'approved',
        'decided_at' => $past, 'created_at' => $past, 'updated_at' => $past,
    ]);

    try {
        DB::connection('sa_verify')->table('support_access_approvals')->insert([
            'grant_id' => $id, 'approver_id' => 100, 'decision' => 'approved',
            'decided_at' => $past, 'created_at' => $past, 'updated_at' => $past,
        ]);
        bad('UNIQUE (grant_id, approver_id) is enforced',
            'one approver inserted two approvals for one grant, so a single person satisfies any '
            . 'N-approver rule by posting N times');
    } catch (Throwable $e) {
        ok('UNIQUE (grant_id, approver_id) is enforced by the engine');
    }

    // A different approver on the same grant is still allowed.
    try {
        DB::connection('sa_verify')->table('support_access_approvals')->insert([
            'grant_id' => $id, 'approver_id' => 101, 'decision' => 'approved',
            'decided_at' => $past, 'created_at' => $past, 'updated_at' => $past,
        ]);
        ok('CONTROL: a SECOND approver on the same grant is accepted');
    } catch (Throwable $e) {
        bad('CONTROL: a second approver on the same grant is accepted',
            'refused: ' . $e->getMessage() . ' -- the unique index is too wide and emergency access '
            . 'can never reach two approvals');
    }

    // ---- 6. store_id accepts 0 ----------------------------------------------------------------
    DB::connection('sa_verify')->table('support_access_grants')->insert([
        'store_id' => 0, 'merchant_id' => 8, 'grantee_id' => 9,
        'kind' => 'support', 'scope' => 'full', 'reason' => 'store id zero',
        'requested_by' => 9, 'requested_at' => $past, 'expires_at' => '2030-01-01 00:00:00',
        'uses' => 0, 'created_at' => $past, 'updated_at' => $past,
    ]);
    $zero = DB::connection('sa_verify')->table('support_access_grants')
        ->where('store_id', 0)->count();
    check($zero === 1,
        'store_id accepts 0, so SupportAccessGate\'s $storeId <= 0 guard is load-bearing',
        'rows with store_id 0: ' . $zero . ' -- if the engine rejected it, the guard would be '
        . 'redundant and the suite case asserting it would pass for the wrong reason');

    // ---- 7. down() drops exactly what up() created --------------------------------------------
    $before = array_map(
        fn ($r) => (array) $r,
        DB::connection('sa_verify')->select('SHOW TABLES')
    );

    $instance->down();

    check(! Schema::connection('sa_verify')->hasTable('support_access_grants'),
        'down() drops support_access_grants');
    check(! Schema::connection('sa_verify')->hasTable('support_access_approvals'),
        'down() drops support_access_approvals');
    check(count($before) === 2,
        'up() created exactly two tables and nothing else',
        'the database held ' . count($before) . ' tables before down()');
} catch (Throwable $e) {
    bad('the script completed', $e->getMessage() . "\n" . $e->getTraceAsString());
} finally {
    try {
        DB::connection('sa_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo str_repeat('-', 86) . "\n";
        echo "  the throwaway database `{$database}` was dropped\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "WARNING: could not drop `{$database}`: {$e->getMessage()}\n");
    }
}

echo str_repeat('=', 86) . "\n";
echo ($failures === [] ? 'ALL CHECKS PASSED' : count($failures) . ' CHECK(S) FAILED')
    . " -- {$checks} checks\n";

foreach ($failures as $f) {
    echo "  - {$f}\n";
}

exit($failures === [] ? 0 : 1);
