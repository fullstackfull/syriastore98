<?php

/**
 * P01-SEC-13 — VALIDATE THE PREPARED MIGRATION AGAINST A REAL MySQL SERVER.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The Feature suite runs on SQLite, whose type
 * affinity accepts a bcrypt string in an INTEGER column -- which is the whole reason this defect
 * went unnoticed. A test that needs MySQL would have to SKIP where there is none, and
 * `.github/known-test-outcomes.json` declares the exact skip set, so a conditionally-skipped test
 * would turn the known-outcome gate red. This follows the convention already set by
 * tests/Support/Security/bin/verify-order-document-route-cache.php: evidence that must run outside
 * the suite lives in an executable script that states its own preconditions.
 *
 * WHAT IT ESTABLISHES, by execution rather than by argument:
 *   1. the engine behaviour the finding rests on -- error 1366 under STRICT_TRANS_TABLES, silent
 *      coercion to 0 without it -- which no SQLite test can show;
 *   2. that the coerced 0 is caught by the web verify step's empty() guard, so the defect FAILS
 *      CLOSED and is availability rather than a bypass;
 *   3. that the prepared migration widens the column on MySQL;
 *   4. that every customer row and every other column, passwords included, survives up() and down();
 *   5. that after up() the column holds a 60-character bcrypt hash and Hash::check() matches it,
 *      so the web reset flow can actually complete;
 *   6. that down() CLEARS non-numeric values rather than coercing them to 0.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database
 * and touches nothing else:
 *
 *   SEC13_MYSQL_SOCKET=/path/to/mysqld.sock \
 *   SEC13_MYSQL_DATABASE=sec13_verify \
 *   SEC13_MYSQL_USERNAME=root \
 *   SEC13_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-sec13-migration-on-mysql.php
 *
 * or with SEC13_MYSQL_HOST / SEC13_MYSQL_PORT instead of a socket.
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE and
 * DROP DATABASE. It refuses to run against a database whose name does not contain 'sec13' or
 * 'verify', which is a guard against exactly that mistake.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

$database = getenv('SEC13_MYSQL_DATABASE') ?: 'sec13_verify';

if (! str_contains($database, 'sec13') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$database}' does not contain 'sec13' or 'verify'.\n"
        . "This script issues DROP DATABASE. Name the throwaway database accordingly.\n");
    exit(2);
}

$socket = getenv('SEC13_MYSQL_SOCKET') ?: '';
$host = getenv('SEC13_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('SEC13_MYSQL_PORT') ?: '3306';
$username = getenv('SEC13_MYSQL_USERNAME') ?: 'root';
$password = getenv('SEC13_MYSQL_PASSWORD');
$password = $password === false ? '' : $password;

config([
    'database.connections.sec13' => [
        'driver' => 'mysql',
        'url' => null,
        'host' => $host,
        'port' => $port,
        'unix_socket' => $socket,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
    ],
    // The same credentials with no database selected, so the database can be created.
    'database.connections.sec13_root' => [
        'driver' => 'mysql',
        'host' => $host,
        'port' => $port,
        'unix_socket' => $socket,
        'database' => null,
        'username' => $username,
        'password' => $password,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
    ],
]);

echo "P01-SEC-13 MIGRATION VALIDATION, AGAINST A REAL MySQL SERVER\n";
echo str_repeat('=', 86) . "\n";

try {
    $version = DB::connection('sec13_root')->selectOne('SELECT VERSION() AS v')->v;
} catch (\Throwable $e) {
    fwrite(STDERR, "\nCould not reach MySQL: " . $e->getMessage() . "\n"
        . "Set SEC13_MYSQL_SOCKET (or SEC13_MYSQL_HOST/PORT) to a throwaway server.\n");
    exit(3);
}

DB::connection('sec13_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
DB::connection('sec13_root')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$sqlMode = DB::connection('sec13')->selectOne('SELECT @@SESSION.sql_mode AS m')->m;

echo "server      : {$version}\n";
echo "database    : {$database}  (created by this script, dropped at the end)\n";
echo "sql_mode    : {$sqlMode}\n";
echo "strict?     : " . (str_contains($sqlMode, 'STRICT_TRANS_TABLES') ? 'YES -- STRICT_TRANS_TABLES is in force' : 'no') . "\n";
echo str_repeat('-', 86) . "\n";

// ---------------------------------------------------------------------------------------
// BUILD THE TABLE FROM THE REAL MIGRATION, not from hand-written DDL
// ---------------------------------------------------------------------------------------

echo "\n[1] Build `customers` from the repository's own create migration\n";

// The create migration and the draft both use the bare Schema/DB facades, which resolve the
// DEFAULT connection. Point the default at the throwaway MySQL and clear any already-resolved
// SQLite builder, or the facade keeps handing back the old one.
$previousDefault = config('database.default');
config(['database.default' => 'sec13']);
DB::setDefaultConnection('sec13');
Schema::clearResolvedInstances();
DB::clearResolvedInstances();
app()->forgetInstance('db.schema');

check(DB::connection()->getDriverName() === 'mysql',
    'the default connection now resolves to MySQL, so the migrations under test run against it',
    'driver is ' . DB::connection()->getDriverName());

$createMigration = require __DIR__ . '/../../../../database/migrations/2023_12_15_112855_create_customers_table.php';
$createMigration->up();

check(Schema::connection('sec13')->hasTable('customers'),
    '`customers` exists, built by database/migrations/2023_12_15_112855_create_customers_table.php');

$column = DB::connection('sec13')->selectOne(
    "SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'password_otp'",
    [$database]
);

check($column !== null && strtolower($column->DATA_TYPE) === 'int',
    "password_otp is declared INT by the repository's own migration",
    $column ? "observed: {$column->COLUMN_TYPE}" : 'column missing');

// ---------------------------------------------------------------------------------------
// THE DEFECT, MEASURED
// ---------------------------------------------------------------------------------------

echo "\n[2] The defect: the web flow writes Hash::make(\$otp) -- 60 chars of bcrypt -- to that column\n";

$hash = Hash::make('123456');
check(strlen($hash) === 60, 'the hashing driver produces a 60-character hash', 'got ' . strlen($hash));

DB::connection('sec13')->table('customers')->insert([
    'email' => 'defect-probe@example.test',
    'password' => Hash::make('original-password-not-real'),
    'status' => 1,
]);

$probeId = (int) DB::connection('sec13')->table('customers')->where('email', 'defect-probe@example.test')->value('id');

$strictError = null;
try {
    DB::connection('sec13')->table('customers')->where('id', $probeId)->update(['password_otp' => $hash]);
} catch (\Throwable $e) {
    $strictError = $e->getMessage();
}

check($strictError !== null && str_contains($strictError, '1366'),
    'under STRICT_TRANS_TABLES the write FAILS with error 1366 -- so sendPasswordOtp() raises',
    $strictError === null ? 'the write SUCCEEDED, which contradicts the finding' : substr($strictError, 0, 120));

// And the same write without strict mode.
DB::connection('sec13')->statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
DB::connection('sec13')->table('customers')->where('id', $probeId)->update(['password_otp' => $hash]);
$coerced = DB::connection('sec13')->table('customers')->where('id', $probeId)->value('password_otp');

check((int) $coerced === 0 && $coerced !== null,
    'without strict mode the hash is silently coerced to 0 (not null)',
    'observed: ' . var_export($coerced, true));

// THE CONSEQUENCE, and the correction to the first report: it fails CLOSED.
check(empty($coerced),
    'PHP empty() is TRUE for that 0, so CustomerLoginController:391 refuses before Hash::check() '
    . 'at :413 -- the defect is AVAILABILITY, not a bypass',
    'empty(' . var_export($coerced, true) . ') was false');

// AND A SECOND CONSEQUENCE, measured here rather than assumed: Laravel's BcryptHasher does not
// return false for a non-bcrypt stored value -- with hashing.verify enabled (the default) it
// THROWS. So if the empty() guard at :391 were ever removed or reordered, the coerced 0 would make
// Hash::check() at :413 a 500 rather than a clean "incorrect code". The guard is carrying more
// weight than it looks.
$hashCheckThrew = null;
try {
    Hash::check('123456', (string) $coerced);
} catch (\Throwable $e) {
    $hashCheckThrew = $e->getMessage();
}

check($hashCheckThrew !== null && str_contains($hashCheckThrew, 'Bcrypt'),
    'Hash::check() against the coerced 0 THROWS rather than returning false, so the empty() guard '
    . 'at :391 is also what keeps that path off a 500',
    $hashCheckThrew === null ? 'it returned without throwing' : substr($hashCheckThrew, 0, 90));

DB::connection('sec13')->statement("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
DB::connection('sec13')->table('customers')->where('id', $probeId)->delete();

// ---------------------------------------------------------------------------------------
// SEED REPRESENTATIVE ROWS
// ---------------------------------------------------------------------------------------

echo "\n[3] Seed rows of the kinds a live `customers` table holds\n";

$seed = [
    ['email' => 'plain@example.test',       'password_otp' => null,  'note' => 'never used recovery'],
    ['email' => 'numeric@example.test',     'password_otp' => 4321,  'note' => 'a legacy plaintext OTP'],
    ['email' => 'coerced@example.test',     'password_otp' => 0,     'note' => 'a failed hash write'],
    ['email' => 'maxint@example.test',      'password_otp' => 2147483647, 'note' => 'INT upper bound'],
    ['email' => 'negative@example.test',    'password_otp' => -1,    'note' => 'a negative value'],
];

foreach ($seed as $i => $row) {
    DB::connection('sec13')->table('customers')->insert([
        'first_name' => 'Shopper',
        'last_name' => (string) $i,
        'email' => $row['email'],
        'password' => Hash::make('password-for-' . $row['email']),
        'password_otp' => $row['password_otp'],
        'password_otp_datetime' => '2026-01-0' . ($i + 1) . ' 10:00:00',
        'status' => 1,
        'register_type' => 'email',
        'store_id' => ($i % 2) + 1,
        'remember_token' => 'remember-' . $i,
    ]);
    echo "        seeded {$row['email']}  password_otp=" . var_export($row['password_otp'], true) . "  ({$row['note']})\n";
}

$before = DB::connection('sec13')->table('customers')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$countBefore = count($before);

check($countBefore === count($seed), "{$countBefore} rows seeded");

// ---------------------------------------------------------------------------------------
// THE PREPARED MIGRATION -- up()
// ---------------------------------------------------------------------------------------

echo "\n[4] Run the PREPARED migration's up()\n";

$draft = __DIR__ . '/../../../../database/migrations/_sec13_pending/0001_draft_widen_customers_password_otp.php.draft';
check(is_file($draft), 'the prepared draft is where the report says it is');

$migration = require $draft;
$migration->up();

$after = DB::connection('sec13')->selectOne(
    "SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'password_otp'",
    [$database]
);

check($after !== null && strtolower($after->DATA_TYPE) === 'varchar',
    'password_otp is now VARCHAR',
    $after ? "observed: {$after->COLUMN_TYPE}" : 'column missing');

check($after !== null && (int) $after->CHARACTER_MAXIMUM_LENGTH === 255,
    'its length is 255 -- room for bcrypt (60) and argon2id (~97)',
    $after ? 'observed: ' . var_export($after->CHARACTER_MAXIMUM_LENGTH, true) : '');

check($after !== null && strtoupper($after->IS_NULLABLE) === 'YES',
    'it is still nullable, so the handlers that write null still work');

// ---------------------------------------------------------------------------------------
// PRESERVATION
// ---------------------------------------------------------------------------------------

echo "\n[5] Every row and every other column survived\n";

$afterRows = DB::connection('sec13')->table('customers')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

check(count($afterRows) === $countBefore,
    "the row count is unchanged ({$countBefore})",
    'observed ' . count($afterRows));

$damaged = [];
$otpChanged = [];

foreach ($before as $i => $row) {
    $now = $afterRows[$i] ?? null;

    if ($now === null) {
        $damaged[] = $row['email'] . ' (row gone)';
        continue;
    }

    foreach ($row as $col => $value) {
        if ($col === 'password_otp') {
            // INT -> VARCHAR renders the integer as its decimal string. Compare loosely.
            if ((string) $value !== (string) $now[$col]) {
                $otpChanged[] = "{$row['email']}: " . var_export($value, true) . ' -> ' . var_export($now[$col], true);
            }
            continue;
        }

        if ($value !== $now[$col]) {
            $damaged[] = "{$row['email']}.{$col}: " . var_export($value, true) . ' -> ' . var_export($now[$col], true);
        }
    }
}

check($damaged === [], 'no column other than password_otp changed on any row', implode('; ', $damaged));
check($otpChanged === [], 'every pre-existing password_otp value round-tripped as its decimal string', implode('; ', $otpChanged));

// Passwords specifically, because that is the owner's question.
$passwordsIntact = true;
foreach ($seed as $row) {
    $stored = DB::connection('sec13')->table('customers')->where('email', $row['email'])->value('password');
    if (! Hash::check('password-for-' . $row['email'], (string) $stored)) {
        $passwordsIntact = false;
        bad("the password for {$row['email']} no longer verifies");
    }
}
if ($passwordsIntact) {
    ok('every seeded password still verifies with Hash::check() after the migration');
}

// ---------------------------------------------------------------------------------------
// AND THE FLOW NOW WORKS
// ---------------------------------------------------------------------------------------

echo "\n[6] The web reset flow can now actually complete\n";

$sixDigit = '654321';
$sixHash = Hash::make($sixDigit);

DB::connection('sec13')->table('customers')->where('email', 'plain@example.test')
    ->update(['password_otp' => $sixHash, 'password_otp_datetime' => '2026-01-01 10:00:00']);

$readBack = DB::connection('sec13')->table('customers')->where('email', 'plain@example.test')->value('password_otp');

check(strlen((string) $readBack) === 60, 'a 60-character bcrypt hash stores without truncation',
    'stored ' . strlen((string) $readBack) . ' characters');
check(! empty($readBack), 'the empty() guard at CustomerLoginController:391 now sees a code');
check(Hash::check($sixDigit, (string) $readBack),
    'Hash::check() at CustomerLoginController:413 matches the issued code -- the step that could '
    . 'never succeed on MySQL now does');

// A six-digit code is what the web flow issues (random_int(100000, 999999)), so the column is
// proven against the length the web flow actually uses.
check(Hash::check('654321', (string) $readBack) && ! Hash::check('654322', (string) $readBack),
    'and it rejects a wrong code of the same length');

// ---------------------------------------------------------------------------------------
// ROLLBACK
// ---------------------------------------------------------------------------------------

echo "\n[7] Run down(), and measure what reversing costs\n";

$beforeDown = DB::connection('sec13')->table('customers')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

$migration->down();

$reverted = DB::connection('sec13')->selectOne(
    "SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'password_otp'",
    [$database]
);

check($reverted !== null && strtolower($reverted->DATA_TYPE) === 'int',
    'password_otp is INT again',
    $reverted ? "observed: {$reverted->COLUMN_TYPE}" : 'column missing');

$afterDown = DB::connection('sec13')->table('customers')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

check(count($afterDown) === count($beforeDown),
    'the rollback changed no row count',
    count($beforeDown) . ' -> ' . count($afterDown));

$hashRow = DB::connection('sec13')->table('customers')->where('email', 'plain@example.test')->first();

check($hashRow !== null && $hashRow->password_otp === null,
    'the in-flight bcrypt hash was CLEARED by down(), not coerced to 0 -- so the reversed column '
    . 'cannot hold a value the verify step would read as "a code exists"',
    $hashRow ? 'observed: ' . var_export($hashRow->password_otp, true) : 'row gone');

$numericRow = DB::connection('sec13')->table('customers')->where('email', 'numeric@example.test')->first();

check($numericRow !== null && (int) $numericRow->password_otp === 4321,
    'a numeric value survived the rollback unchanged',
    $numericRow ? 'observed: ' . var_export($numericRow->password_otp, true) : 'row gone');

$negativeRow = DB::connection('sec13')->table('customers')->where('email', 'negative@example.test')->first();
check($negativeRow !== null && (int) $negativeRow->password_otp === -1,
    'a negative value survived the rollback (the down() regex admits a leading minus)',
    $negativeRow ? 'observed: ' . var_export($negativeRow->password_otp, true) : 'row gone');

$maxRow = DB::connection('sec13')->table('customers')->where('email', 'maxint@example.test')->first();
check($maxRow !== null && (int) $maxRow->password_otp === 2147483647,
    'the INT upper bound survived the rollback',
    $maxRow ? 'observed: ' . var_export($maxRow->password_otp, true) : 'row gone');

$passwordsStillIntact = true;
foreach ($seed as $row) {
    $stored = DB::connection('sec13')->table('customers')->where('email', $row['email'])->value('password');
    if (! Hash::check('password-for-' . $row['email'], (string) $stored)) {
        $passwordsStillIntact = false;
        bad("the password for {$row['email']} no longer verifies after down()");
    }
}
if ($passwordsStillIntact) {
    ok('every seeded password still verifies after the rollback');
}

// ---------------------------------------------------------------------------------------
// IDEMPOTENCE AND THE GUARD
// ---------------------------------------------------------------------------------------

echo "\n[8] Idempotence, and the guard for a server that does not have the column\n";

$migration->up();
$migration->up();
$twice = DB::connection('sec13')->selectOne(
    "SELECT DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'password_otp'",
    [$database]
);
check($twice !== null && strtolower($twice->DATA_TYPE) === 'varchar',
    'up() run twice is harmless (a server already widened out-of-band is unaffected)');

Schema::connection('sec13')->table('customers', function ($table) {
    $table->dropColumn('password_otp');
});

$migration->up();
$migration->down();

check(! Schema::connection('sec13')->hasColumn('customers', 'password_otp'),
    'with the column absent, both up() and down() are guarded no-ops rather than errors');

// ---------------------------------------------------------------------------------------

echo "\n[9] Tear down\n";
DB::connection('sec13')->disconnect();
DB::connection('sec13_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
ok("dropped the throwaway database `{$database}`");

config(['database.default' => $previousDefault]);
DB::setDefaultConnection($previousDefault);

echo "\n" . str_repeat('=', 86) . "\n";
echo "checks: {$checks}   failures: " . count($failures) . "\n";

if ($failures !== []) {
    echo "\nFAILED:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\nALL ASSERTIONS HELD on {$version}.\n";
exit(0);
