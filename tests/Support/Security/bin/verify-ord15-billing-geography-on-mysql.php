#!/usr/bin/env php
<?php

/**
 * P1-ORD-15 — what does MySQL really do with a city NAME in `cities.state_id`?
 * =============================================================================================
 *
 * THE CLAIM THIS SCRIPT EXISTS TO SETTLE, and it was inherited rather than measured. The ledger
 * row says: "On MySQL's non-strict connection (config/database.php:55, the production setting) the
 * same insert COERCES both names to 0 and succeeds, storing a city that belongs to country 0 and
 * state 0 -- so production does not crash, it accumulates junk geography". SQLite refuses the same
 * insert with `SQLSTATE[23000] FOREIGN KEY constraint failed`, which is what the Feature suite
 * sees.
 *
 * BOTH HALVES OF THAT CLAIM CANNOT BE TRUE AT ONCE IF THE FOREIGN KEY IS THERE. InnoDB enforces
 * foreign keys regardless of `strict` -- strict mode governs TYPE COERCION, not referential
 * integrity -- so a coercion to 0 should then be refused by the constraint, because no
 * `countries` row has id 0. The row's prediction and the schema disagree, and that is exactly the
 * kind of inherited claim this project has been wrong about before (the category-cascade script
 * next to this one corrected a published queue entry and a GitHub issue).
 *
 * So this asks the engine. It reports, with `information_schema` as well as behaviour:
 *
 *   1. whether a non-numeric name in `state_id` / `country_id` is refused, coerced, or truncated,
 *      WITH the declared foreign keys and under the production `strict => false` connection;
 *   2. what happens to the same insert with the constraints absent -- which is the case that
 *      matters for the LIVE database, since GATE F records 15 tables that live code reads with no
 *      migration at all, so the constraint cannot be assumed present there;
 *   3. that `WHERE id = '1abc'` returns row 1 on MySQL while an exact integer parse rejects it,
 *      which is why `App\Services\BillingGeography::asId()` uses FILTER_VALIDATE_INT;
 *   4. that `WHERE name = 'damascus'` matches 'Damascus' under MySQL's default case-insensitive
 *      collation -- so a name-keyed lookup is looser on production than the suite shows;
 *   5. that a NULL in either column is accepted, which is what the fix stores when a posted value
 *      cannot be resolved.
 *
 * WHY A SCRIPT AND NOT A TEST. `tests/Support/TestDatabaseGuard.php` aborts any PHPUnit run whose
 * connection is not sqlite, and `.github/known-test-outcomes.json` declares the exact skip set, so
 * a conditionally-skipped MySQL test would turn the known-outcome gate red. Same convention as the
 * other scripts here.
 *
 * It creates and drops its OWN database and refuses a name without 'verify' in it.
 *
 *   ORD15_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   ORD15_MYSQL_DATABASE=ord15_verify \
 *   ORD15_MYSQL_USERNAME=root \
 *   ORD15_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-ord15-billing-geography-on-mysql.php
 *
 * or ORD15_MYSQL_HOST / ORD15_MYSQL_PORT instead of a socket.
 *
 * WHAT A PASS MEANS: the engine behaviour the fix is built on is production behaviour. It asserts
 * NOTHING about the live Store Syria database, whose schema this script never touches.
 *
 * Exit 0 = every assertion held. 2 = refused the database name. 3 = no MySQL to talk to.
 */

$database = getenv('ORD15_MYSQL_DATABASE') ?: 'ord15_verify';

if (! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$database}' does not contain 'verify'.\n"
        . "This script issues DROP DATABASE and will only do so against a name that says it is\n"
        . "a throwaway. Pick another name.\n");
    exit(2);
}

$socket   = getenv('ORD15_MYSQL_SOCKET') ?: '';
$host     = getenv('ORD15_MYSQL_HOST') ?: '127.0.0.1';
$port     = getenv('ORD15_MYSQL_PORT') ?: '3306';
$username = getenv('ORD15_MYSQL_USERNAME') ?: 'root';
$password = getenv('ORD15_MYSQL_PASSWORD');
$password = $password === false ? '' : $password;

$dsn = $socket !== ''
    ? "mysql:unix_socket={$socket}"
    : "mysql:host={$host};port={$port}";

try {
    $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot reach MySQL ({$e->getMessage()}).\n"
        . "This script needs a MySQL server it may create and drop a database on.\n");
    exit(3);
}

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}`");
$pdo->exec("USE `{$database}`");

/*
 * THE PRODUCTION CONNECTION'S OWN SETTING. `config/database.php:55` has `'strict' => false`, which
 * Laravel implements by setting a reduced sql_mode. Reproduced here rather than described, because
 * the whole question is what the engine does under the mode production actually runs.
 */
$pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
$sqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("%s  %s%s\n", $ok ? '[PASS]' : '[FAIL]', $label, $detail === '' ? '' : "\n        {$detail}");
    if (! $ok) {
        $failures++;
    }
};

printf("MySQL %s, database `%s`\n", $version, $database);
printf("session sql_mode: %s\n\n", $sqlMode === '' ? '(empty)' : $sqlMode);

$check(
    'the session runs WITHOUT STRICT_TRANS_TABLES, as config/database.php:55 does in production',
    ! str_contains($sqlMode, 'STRICT_TRANS_TABLES')
);

// ---------------------------------------------------------------------------------------------
//  The declared schema, exactly as the migrations write it.
// ---------------------------------------------------------------------------------------------

$pdo->exec('CREATE TABLE countries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NULL
) ENGINE=InnoDB');

$pdo->exec('CREATE TABLE states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NULL,
    country_id BIGINT UNSIGNED NULL,
    CONSTRAINT states_country_id_foreign FOREIGN KEY (country_id)
        REFERENCES countries (id) ON DELETE CASCADE
) ENGINE=InnoDB');

$pdo->exec('CREATE TABLE cities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NULL,
    country_id BIGINT UNSIGNED NULL,
    state_id BIGINT UNSIGNED NULL,
    CONSTRAINT cities_country_id_foreign FOREIGN KEY (country_id)
        REFERENCES countries (id) ON DELETE CASCADE,
    CONSTRAINT cities_state_id_foreign FOREIGN KEY (state_id)
        REFERENCES states (id) ON DELETE CASCADE
) ENGINE=InnoDB');

$pdo->exec("INSERT INTO countries (id, name) VALUES (101, 'Syria')");
$pdo->exec("INSERT INTO states (id, name, country_id) VALUES (1, 'Damascus', 101)");
$pdo->exec("INSERT INTO cities (id, name, country_id, state_id) VALUES (1, 'Damascus', 101, 1)");

$declared = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'cities'"
)->fetchColumn();

$check(
    'information_schema confirms both cities foreign keys are declared, so the next result is '
    . 'about an enforced constraint and not an absent one',
    (int) $declared === 2,
    "referential_constraints on `cities`: {$declared}"
);

// ---------------------------------------------------------------------------------------------
//  1. A NAME in the foreign-key columns, WITH the constraints.
// ---------------------------------------------------------------------------------------------

$nameInsert = "INSERT INTO cities (name, state_id, country_id) VALUES ('Jaramana', 'Damascus', 'Syria')";

$outcome = 'accepted';
$sqlState = '';
$message = '';

try {
    $pdo->exec($nameInsert);
} catch (PDOException $e) {
    $outcome = 'refused';
    $sqlState = (string) $e->getCode();
    $message = $e->getMessage();
}

$storedRow = $pdo->query("SELECT state_id, country_id FROM cities WHERE name = 'Jaramana'")->fetch(PDO::FETCH_ASSOC);

printf("\nposted-name insert WITH the foreign keys: %s%s\n", $outcome,
    $sqlState === '' ? '' : " (SQLSTATE {$sqlState})");

if ($message !== '') {
    printf("        %s\n", substr($message, 0, 200));
}

if ($storedRow !== false) {
    printf("        stored: state_id=%s country_id=%s\n",
        var_export($storedRow['state_id'], true), var_export($storedRow['country_id'], true));
}

$check(
    'a posted NAME never ends up in cities.state_id / cities.country_id as a usable value: MySQL '
    . 'either refuses the insert or coerces to a value no countries/states row carries. Either '
    . 'way the write is a defect, which is what the fix removes by resolving before inserting',
    $outcome === 'refused' || $storedRow === false
        || ! in_array((string) $storedRow['state_id'], ['Damascus'], true),
    'If this FAILS, MySQL stored the literal name in a BIGINT column, which should be impossible.'
);

$check(
    'and the row\'s own prediction is checked rather than inherited: with the declared foreign '
    . 'keys present, the insert is REFUSED on MySQL too -- the coercion-to-0 the ledger predicted '
    . 'cannot survive the constraint, because no countries row has id 0',
    $outcome === 'refused',
    $outcome === 'refused'
        ? ''
        : 'MySQL ACCEPTED it. Then the ledger row was right and this script has just corrected '
        . 'the opposite expectation -- record which, and note the stored values above.'
);

// ---------------------------------------------------------------------------------------------
//  2. The same insert with the constraints ABSENT, which is the live-database case.
// ---------------------------------------------------------------------------------------------

$pdo->exec('CREATE TABLE cities_unconstrained (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NULL,
    country_id BIGINT UNSIGNED NULL,
    state_id BIGINT UNSIGNED NULL
) ENGINE=InnoDB');

$unconstrained = 'accepted';

try {
    $pdo->exec("INSERT INTO cities_unconstrained (name, state_id, country_id)
                VALUES ('Jaramana', 'Damascus', 'Syria')");
} catch (PDOException $e) {
    $unconstrained = 'refused (' . $e->getCode() . ')';
}

$row = $pdo->query("SELECT state_id, country_id FROM cities_unconstrained WHERE name = 'Jaramana'")
    ->fetch(PDO::FETCH_ASSOC);

printf("\nposted-name insert WITHOUT the foreign keys: %s\n", $unconstrained);

if ($row !== false) {
    printf("        stored: state_id=%s country_id=%s\n",
        var_export($row['state_id'], true), var_export($row['country_id'], true));
}

$check(
    'without the constraints the non-strict connection COERCES both names to 0 and succeeds -- '
    . 'the junk geography the ledger row describes. This is the case that matters for the live '
    . 'database, where GATE F means the constraint cannot be assumed present',
    $row !== false && (int) $row['state_id'] === 0 && (int) $row['country_id'] === 0,
    $row === false
        ? 'The insert was refused even unconstrained, so the coercion claim does not hold here either.'
        : ''
);

// ---------------------------------------------------------------------------------------------
//  3. '1abc' is row 1 on MySQL. 4. 'damascus' matches 'Damascus'. 5. NULL is accepted.
// ---------------------------------------------------------------------------------------------

$coerced = (int) $pdo->query("SELECT COUNT(*) FROM cities WHERE id = '1abc'")->fetchColumn();

$check(
    "WHERE id = '1abc' matches row 1 on MySQL, which is why the resolver parses ids with "
    . 'FILTER_VALIDATE_INT instead of is_numeric or a cast',
    $coerced === 1,
    "rows matched: {$coerced}"
);

$insensitive = (int) $pdo->query("SELECT COUNT(*) FROM cities WHERE name = 'damascus'")->fetchColumn();

$check(
    "WHERE name = 'damascus' matches 'Damascus' under MySQL's default collation, so the "
    . 'name-keyed lookup this fix replaced was LOOSER on production than the SQLite suite shows '
    . '-- a posted name differing only in case took the lookup branch there and the insert branch here',
    $insensitive === 1,
    "rows matched: {$insensitive}"
);

$nullAccepted = true;

try {
    $pdo->exec("INSERT INTO cities (name, state_id, country_id) VALUES ('Unresolvable', NULL, NULL)");
} catch (PDOException $e) {
    $nullAccepted = false;
}

$check(
    'NULL is accepted in both columns, which is what the fix stores when a posted value cannot be '
    . 'resolved -- so the chosen behaviour is possible on the production engine, not just on SQLite',
    $nullAccepted
);

$pdo->exec("DROP DATABASE `{$database}`");

printf("\n%s\n", $failures === 0
    ? 'ALL ASSERTIONS HELD.'
    : "{$failures} ASSERTION(S) FAILED.");

exit($failures === 0 ? 0 : 1);
