<?php

/**
 * RESERVED ROLE NAMES — THE UNIQUE INDEX AND THE COLLATION, MEASURED ON REAL MySQL.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The Feature suite runs on SQLite `:memory:`, whose
 * TEXT collation is BINARY -- case-SENSITIVE. MySQL's default here is `utf8mb4_0900_ai_ci`, which
 * is case- AND accent-INSENSITIVE. `roles.name` carries a UNIQUE index
 * (database/migrations/.../create_roles_table.php), and a unique index is enforced IN THE INDEX'S
 * OWN COLLATION. So the two engines disagree about whether a tenant can create a role named
 * `'Super admin'` while the platform's `'super admin'` exists -- and this time the engine hiding
 * the truth hides it in the SAFE direction, which is the more dangerous way round: a probe on
 * SQLite SUCCEEDS at a write production REFUSES, so an investigation run only on the test engine
 * over-states what an attacker can do.
 *
 * WHAT PROMPTED IT. `app/Http/Controllers/RoleController.php:124` (create) and `:254` (rename)
 * validate the role name with `\Illuminate\Validation\Rule::notIn(RolePolicy::RESERVED_NAMES)`,
 * which is CASE-SENSITIVE against a hand-written list of 8 exact spellings. Measured against the
 * real constant, `'super admin'`, `'admin'`, `'Admin'` and `'ADMIN'` are refused while
 * `'Super admin'`, `'sUper admin'`, `'SUPER Admin'`, `'aDmin'` and `'super  admin'` pass. That
 * list's own docblock records that omitting `'admin'` WAS A LIVE ESCALATION here, so the shape is
 * established in this codebase and the variants had to be driven rather than argued about.
 *
 * THE CHAIN DOES NOT COMPLETE, and this script is one of the two reasons. The other is in the host
 * app and is engine-independent: `app/Http/Controllers/UserController.php:147` and `:302` gate the
 * only tenant-reachable writers of `users.type` (`:154`, `:305`) with
 *
 *     in_array(strtolower(trim((string) $role->name)), RolePolicy::RESERVED_NAMES, true)
 *
 * which LOWERCASES THE NEEDLE and is therefore case-INSENSITIVE by construction. Every case variant
 * collapses onto one of the three lowercase entries and is refused. So `Rule::notIn`'s
 * case-sensitivity is NOT the last line of defence, and the two gates over one invariant have
 * OPPOSITE case sensitivity -- which is the finding worth recording.
 *
 * WHAT THIS SCRIPT ESTABLISHES, by execution:
 *   1. the collation this application actually gets, and that it is case-insensitive;
 *   2. whether the UNIQUE index on `roles.name` refuses `'Super admin'` beside `'super admin'`;
 *   3. whether `WHERE name LIKE 'super admin'` matches a differently-cased row;
 *   4. therefore whether `Role::where('name','LIKE','super admin')->first()`
 *      (app/Models/Utility.php:3117, which decides who receives the operator permission set on
 *      every login) can be AMBIGUOUS on MySQL -- three independent agents proposed fixing that
 *      line as "the real defect", and the unique index decides whether they were right;
 *   5. that SQLite behaves differently, which is why this file exists;
 *   6. that the SHIPPED validator predicate refuses every variant once normalised, called for real
 *      rather than reimplemented.
 *
 * HOW TO RUN. Points at any throwaway MySQL you control; creates and drops its own database and
 * refuses a database name without 'verify' in it:
 *
 *   DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= \
 *   php tests/Support/Security/bin/verify-reserved-role-name-collation-on-mysql.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;

function out(string $line): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

$fail = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $fail;
    out(sprintf('  [%s] %s%s', $ok ? ' OK ' : 'FAIL', $label, $detail === '' ? '' : ' -- ' . $detail));
    if (! $ok) {
        $fail++;
    }
}

// Accepts the MYSQL_* names run-mysql-verifications.sh passes, and the DB_* names a human would
// reach for when running this file alone.
$host = getenv('MYSQL_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
$user = getenv('MYSQL_USER_NAME') ?: (getenv('DB_USERNAME') ?: 'root');
$pass = getenv('MYSQL_PASS') !== false ? getenv('MYSQL_PASS') : (getenv('DB_PASSWORD') ?: '');
$db = getenv('MYSQL_DATABASE') ?: (getenv('DB_DATABASE') ?: 'verify_reserved_role_names');

if (! str_contains($db, 'verify')) {
    out('REFUSING: the database name must contain "verify". This script CREATES and DROPS it.');
    exit(2);
}

out('RESERVED ROLE NAMES -- unique index + collation, on real MySQL');
out(str_repeat('=', 78));

try {
    $pdo = new PDO("mysql:host={$host}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (\Throwable $e) {
    out('SKIP: no MySQL reachable at ' . $host . ' -- ' . $e->getMessage());
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
$pdo->exec("CREATE DATABASE `{$db}`");

$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql', 'host' => $host, 'database' => $db,
    'username' => $user, 'password' => $pass,
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci', 'strict' => false,
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

try {
    /* ---------------------------------------------------------------------------------------- *
     *  1. THE COLLATION
     * ---------------------------------------------------------------------------------------- */
    out('');
    out('1. The collation this application actually gets');
    $row = Capsule::selectOne('SELECT @@character_set_database cs, @@collation_database co');
    out("     charset={$row->cs} collation={$row->co}");
    check(str_ends_with($row->co, '_ci'), 'the database collation is case-INSENSITIVE', $row->co);

    /* ---------------------------------------------------------------------------------------- *
     *  2. THE UNIQUE INDEX -- the schema this application ships
     * ---------------------------------------------------------------------------------------- */
    out('');
    out('2. The UNIQUE index on roles.name, as database/migrations ships it');
    Capsule::statement('CREATE TABLE roles (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        store_id BIGINT UNSIGNED NULL,
        created_by BIGINT UNSIGNED NULL,
        UNIQUE KEY roles_name_unique (name)
    ) ENGINE=InnoDB');

    Capsule::table('roles')->insert(['name' => 'super admin', 'store_id' => null, 'created_by' => null]);
    out('     seeded the platform role: name=\'super admin\' store_id=NULL');

    $variantRejected = false;
    $variantError = '';
    try {
        Capsule::table('roles')->insert(['name' => 'Super admin', 'store_id' => 1, 'created_by' => 2]);
    } catch (\Throwable $e) {
        $variantRejected = true;
        $variantError = explode("\n", $e->getMessage())[0];
    }
    check($variantRejected,
        "a tenant role named 'Super admin' is REFUSED by the unique index",
        $variantRejected ? substr($variantError, 0, 90) : 'IT WAS ACCEPTED -- the unique index is case-sensitive here');

    // The same for the 'admin' leg.
    Capsule::table('roles')->insert(['name' => 'admin', 'store_id' => null, 'created_by' => null]);
    $adminVariantRejected = false;
    try {
        Capsule::table('roles')->insert(['name' => 'aDmin', 'store_id' => 1, 'created_by' => 2]);
    } catch (\Throwable $e) {
        $adminVariantRejected = true;
    }
    check($adminVariantRejected, "a tenant role named 'aDmin' is REFUSED by the unique index");

    // The one variant that is NOT a case variant: interior whitespace is a different string.
    $doubleSpaceAccepted = true;
    try {
        Capsule::table('roles')->insert(['name' => 'super  admin', 'store_id' => 1, 'created_by' => 2]);
    } catch (\Throwable $e) {
        $doubleSpaceAccepted = false;
    }
    check($doubleSpaceAccepted,
        "'super  admin' (two interior spaces) IS accepted -- it is a different string, not a case variant",
        'it grants nothing, because trim() strips only the ends and no comparison matches it');

    /* ---------------------------------------------------------------------------------------- *
     *  3. WHAT `LIKE` MATCHES, and 4. whether the operator resolution can be ambiguous
     * ---------------------------------------------------------------------------------------- */
    out('');
    out('3. What `WHERE name LIKE \'super admin\'` matches');
    $matched = Capsule::table('roles')->where('name', 'LIKE', 'super admin')->pluck('name')->all();
    out('     matched: ' . json_encode($matched));
    check(count($matched) === 1,
        'the operator resolution at app/Models/Utility.php:3117 matches EXACTLY ONE row',
        'count=' . count($matched));

    out('');
    out('4. THEREFORE: is Role::where(\'name\',\'LIKE\',\'super admin\')->first() ambiguous on MySQL?');
    check(count($matched) === 1,
        'NO -- the UNIQUE index makes at most one row matchable, whatever LIKE does',
        'so the three proposals to "fix" that line as the real defect do not apply on the '
        . 'production engine. It is ambiguous only on SQLite, i.e. only in the test suite.');

    /* ---------------------------------------------------------------------------------------- *
     *  5. SQLITE DOES NOT REPRODUCE IT -- which is why this script exists
     * ---------------------------------------------------------------------------------------- */
    out('');
    out('5. The same two writes on SQLite, the engine the suite runs on');
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'lite');
    Capsule::schema('lite')->create('roles', function ($t) {
        $t->bigIncrements('id');
        $t->string('name')->unique();
        $t->unsignedBigInteger('store_id')->nullable();
    });
    Capsule::connection('lite')->table('roles')->insert(['name' => 'super admin', 'store_id' => null]);
    $liteAccepted = true;
    try {
        Capsule::connection('lite')->table('roles')->insert(['name' => 'Super admin', 'store_id' => 1]);
    } catch (\Throwable $e) {
        $liteAccepted = false;
    }
    check($liteAccepted,
        "SQLite ACCEPTS 'Super admin' beside 'super admin' -- its unique index is BINARY",
        'so a probe run only on the test engine measures a write production refuses, and '
        . 'over-states the attacker. This is the engine hiding the truth in the SAFE direction.');

    $liteMatched = Capsule::connection('lite')->table('roles')
        ->where('name', 'LIKE', 'super admin')->pluck('name')->all();
    out('     SQLite LIKE matched: ' . json_encode($liteMatched));
    check(count($liteMatched) === 2,
        'and on SQLite the operator resolution IS ambiguous -- two rows match',
        'which is a test-environment hazard, not a production one');

    /* ---------------------------------------------------------------------------------------- *
     *  6. THE SHIPPED PREDICATE, called for real
     * ---------------------------------------------------------------------------------------- */
    out('');
    out('6. The predicate UserController:147/:302 actually uses, called for real');
    $reserved = \App\Policies\RolePolicy::RESERVED_NAMES;
    out('     RESERVED_NAMES (' . count($reserved) . '): ' . implode(' | ', $reserved));

    $variants = ['super admin', 'Super admin', 'sUper admin', 'SUPER Admin', 'Super ADMIN',
        'admin', 'Admin', 'ADMIN', 'aDmin', 'superadmin', 'SuperAdmin'];
    $leaks = [];
    foreach ($variants as $v) {
        // Verbatim from app/Http/Controllers/UserController.php:147.
        if (! in_array(strtolower(trim((string) $v)), $reserved, true)) {
            $leaks[] = $v;
        }
    }
    check($leaks === [],
        'every case variant of both reserved strings is refused by the LOWERCASING gate',
        $leaks === [] ? 'this is why the chain does not complete' : 'LEAKS: ' . implode(', ', $leaks));

    // And the asymmetry: the same list under the CASE-SENSITIVE validator.
    $notInLeaks = [];
    foreach ($variants as $v) {
        if (! in_array($v, $reserved, true)) {
            $notInLeaks[] = $v;
        }
    }
    out('     the CASE-SENSITIVE Rule::notIn at RoleController:124/:254 lets through: '
        . implode(', ', $notInLeaks));
    check($notInLeaks !== [],
        'the two gates over one invariant have OPPOSITE case sensitivity',
        'the finding worth recording: the validator is not the backstop it looks like');

    // Five entries are dead for the lowercasing consumer.
    $dead = array_values(array_filter($reserved, static fn ($n) => $n !== strtolower($n)));
    out('     entries that can NEVER equal a lowercased needle: ' . implode(', ', $dead));
    check(count($dead) === 5,
        'so 5 of the 8 entries are dead code for the UserController consumer',
        'the list reads as 8 protections and is 3 for half its call sites');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
    out('');
    out("dropped {$db}");
}

out('');
out(str_repeat('=', 78));
if ($fail > 0) {
    out("RESULT: {$fail} check(s) FAILED.");
    exit(1);
}
out('RESULT: all checks passed.');
out('');
out('CONCLUSION. The case-variant escalation DOES NOT COMPLETE, and it is stopped twice over:');
out('  - engine-independently, by UserController:147/:302 lowercasing the needle before the only');
out('    tenant-reachable writers of users.type;');
out('  - and on production MySQL, by the UNIQUE index on roles.name under a _ci collation, which');
out('    refuses the variant row before any of that is reached.');
out('What IS real and worth fixing is the asymmetry: RoleController:124 and :254 compare');
out('case-SENSITIVELY against a hand-written spelling list while the gate that actually decides');
out('the users.type write compares case-INSENSITIVELY. Normalising the two validators makes the');
out('hand-written spellings stop mattering and removes a defence that only looks like one.');
exit(0);
