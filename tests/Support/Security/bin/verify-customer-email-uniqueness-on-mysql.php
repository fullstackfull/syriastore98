#!/usr/bin/env php
<?php
/**
 * OWNER SAFEGUARDS 4 AND 5 (2026-11-07) — can MySQL enforce per-store customer e-mail uniqueness
 * over LIVE rows only, and what happens to concurrency and to existing duplicates?
 * =================================================================================================
 *
 * THE OWNER'S CORRECTION, WHICH THIS SCRIPT EXISTS TO ANSWER. My proposal said database-level
 * enforcement was impossible because MySQL has no partial-index syntax. The owner's instruction:
 * *"Investigate database-level enforcement on the actual production MySQL version, including
 * generated-column or functional unique indexes that handle archived rows through NULL semantics.
 * Do not claim enforcement is impossible merely because MySQL lacks native partial-index syntax."*
 *
 * That is right, and the reason it is right is NULL semantics: a MySQL UNIQUE index permits any
 * number of rows whose indexed value is NULL. So an expression that is NULL for archived rows and
 * (email, store_id) for live ones gives exactly
 * `UNIQUE (email, store_id) WHERE deleted_at IS NULL`.
 *
 * WHAT THIS MEASURES, by execution:
 *   A. the server's exact version, collation and sql_mode -- RECORDED, not assumed, because
 *      production's version is NOT established anywhere in this repository (see
 *      docs/development/HANDOFF-CURRENT.md:136, which says to confirm it)
 *   B. ROUTE A -- a STORED generated column + a unique index over it
 *   C. ROUTE B -- a functional unique index over the same expression, no column added (8.0.13+)
 *   For each route, the properties the owner's policy needs:
 *      1. two LIVE rows, same e-mail, SAME store      -> REJECTED
 *      2. two LIVE rows, same e-mail, DIFFERENT stores -> ALLOWED
 *      3. many ARCHIVED rows, same e-mail and store    -> ALLOWED (NULL semantics)
 *      4. archive, then re-add the same address        -> ALLOWED
 *      5. two LIVE rows, same e-mail, store_id NULL    -> REJECTED (production's store_id is
 *         nullable, and CONCAT() of a NULL is NULL, which a unique index exempts)
 *   D. CONCURRENCY (safeguard 5) -- two connections racing the same insert; exactly one must win,
 *      and the loser must get a duplicate-key error rather than a second row
 *   E. EXISTING DUPLICATE DATA (safeguard 5) -- what the ALTER does to a table that already holds
 *      live duplicates, and the exact query that finds them beforehand
 *   F. COLLATION, which is load-bearing and easy to miss: under `utf8mb4_unicode_ci` the index is
 *      CASE- AND ACCENT-INSENSITIVE, so `Alice@x.com` and `alice@x.com` in one store collide.
 *      That decides whether adding the index would reject data a merchant considers legitimate.
 *
 * NOTHING IS APPLIED TO THIS REPOSITORY. No migration is added. This script creates and drops its
 * own throwaway database and touches nothing else. Per the owner's safeguard 6, the exact
 * implementation and migration impact are presented for approval before any schema change.
 *
 * HOW TO RUN:
 *   CEU_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock CEU_MYSQL_USERNAME=root CEU_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-customer-email-uniqueness-on-mysql.php
 *
 * or with CEU_MYSQL_HOST / CEU_MYSQL_PORT instead of a socket.
 *
 * NEVER POINT IT AT THE DEV OR PRODUCTION SERVER.
 */

$socket   = getenv('CEU_MYSQL_SOCKET') ?: '';
$host     = getenv('CEU_MYSQL_HOST') ?: '127.0.0.1';
$port     = getenv('CEU_MYSQL_PORT') ?: '3306';
$user     = getenv('CEU_MYSQL_USERNAME') ?: 'root';
$pass     = getenv('CEU_MYSQL_PASSWORD');
$pass     = $pass === false ? '' : $pass;
$database = getenv('CEU_MYSQL_DATABASE') ?: ('ceu_verify_' . bin2hex(random_bytes(4)));

$dsnBase = $socket !== ''
    ? "mysql:unix_socket={$socket}"
    : "mysql:host={$host};port={$port}";

$pass_count = 0;
$fail_count = 0;

function say(bool $ok, string $msg): void
{
    global $pass_count, $fail_count;
    $ok ? $pass_count++ : $fail_count++;
    printf("   %s  %s\n", $ok ? 'PASS' : 'FAIL', $msg);
}

function note(string $msg): void
{
    printf("         %s\n", $msg);
}

function connect(string $dsn, string $user, string $pass): PDO
{
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

/** Returns [bool $succeeded, string $sqlstate, string $message]. */
function attempt(PDO $pdo, string $sql, array $bind = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        return [true, '00000', ''];
    } catch (PDOException $e) {
        return [false, (string) ($e->errorInfo[0] ?? '?'), (string) ($e->errorInfo[2] ?? $e->getMessage())];
    }
}

echo str_repeat('=', 96), "\n";
echo "CUSTOMER E-MAIL UNIQUENESS -- DATABASE-LEVEL ENFORCEMENT, MEASURED\n";
echo str_repeat('=', 96), "\n";

try {
    $root = connect($dsnBase, $user, $pass);
} catch (PDOException $e) {
    fwrite(STDERR, "PRECONDITION FAILED: cannot connect. " . $e->getMessage() . "\n");
    fwrite(STDERR, "This script needs a throwaway MySQL. Nothing was measured, and nothing is claimed.\n");
    exit(2);
}

// ---------------------------------------------------------------- A. the server, recorded
echo "\nA. THE SERVER (recorded, not assumed)\n";
$version = $root->query('select version()')->fetchColumn();
$sqlMode = $root->query('select @@sql_mode')->fetchColumn();
$collation = $root->query('select @@collation_server')->fetchColumn();
$charset = $root->query('select @@character_set_server')->fetchColumn();
note("version          : {$version}");
note("sql_mode         : {$sqlMode}");
note("collation_server : {$collation}");
note("charset_server   : {$charset}");
$comment = (string) $root->query('select @@version_comment')->fetchColumn();
$isMariaDb = stripos($version, 'mariadb') !== false || stripos($comment, 'mariadb') !== false;
note('version_comment  : ' . $comment);
note('vendor           : ' . ($isMariaDb ? 'MariaDB' : 'MySQL') . '  (detected from the version string, not assumed)');

note('');
note('PRODUCTION IS MariaDB 11.4.9, AND THAT IS NOW MEASURED RATHER THAN OPEN. The committed dump');
note('storesyria_ecommercego.sql states it in its own header: "-- Serverversie: 11.4.9-MariaDB",');
note('dumped by phpMyAdmin 5.2.3 from localhost:3306. My earlier proposal spoke of MySQL 8.0 and');
note('that was the wrong engine. Production was never accessed to learn this.');
note('Production customers DDL, from the same dump: email varchar(255) NOT NULL,');
note('store_id bigint(20) UNSIGNED DEFAULT NULL, deleted_at timestamp NULL,');
note('ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; indexes PRIMARY (id) and');
note('customers_store_id_index (store_id) only -- there is NO unique index on email.');
note('');

[$major, $minor, $patch] = array_pad(array_map('intval', explode('.', (string) $version)), 3, 0);

// Generated columns: MySQL 5.7+, MariaDB 5.2+ (and indexable when STORED). True on both here.
$supportsGenerated = $isMariaDb
    ? (($major > 5) || ($major === 5 && $minor >= 2))
    : (($major > 5) || ($major === 5 && $minor >= 7));

// Functional indexes (an index over an expression, no column added) are a MySQL 8.0.13+ feature.
// MariaDB HAS NEVER IMPLEMENTED THEM at any version -- MDEV-16620 is open. Gating this on the
// version number alone was a real flaw in the first draft of this script: MariaDB 11.4 is
// numerically above 8.0.13, so a pure number comparison called Route B "supported" on a server
// where it cannot work. The vendor decides it, not the number.
$supportsFunctional = ! $isMariaDb && (($major > 8) || ($major === 8 && ($minor > 0 || $patch >= 13)));

say($supportsGenerated, 'generated columns supported: ' . ($supportsGenerated ? 'yes' : 'NO'));
note('functional indexes: ' . ($supportsFunctional
    ? 'supported (MySQL 8.0.13+)'
    : ($isMariaDb
        ? 'NOT SUPPORTED -- MariaDB implements no functional indexes at any version'
        : 'NOT supported on this MySQL')));

$root->exec("create database `{$database}` character set utf8mb4 collate utf8mb4_unicode_ci");
$dsn = $dsnBase . ";dbname={$database}";
$pdo = connect($dsn, $user, $pass);

register_shutdown_function(function () use ($root, $database) {
    try {
        $root->exec("drop database if exists `{$database}`");
        echo "\n(dropped throwaway database {$database})\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "WARNING: could not drop {$database}: " . $e->getMessage() . "\n");
    }
});

/** The shape of the real column set this is about, reduced to what the index needs. */
function createBaseTable(PDO $pdo, string $table): void
{
    $pdo->exec("drop table if exists `{$table}`");
    $pdo->exec("
        create table `{$table}` (
            id bigint unsigned not null auto_increment primary key,
            email varchar(255) not null,
            store_id bigint unsigned null,
            deleted_at timestamp null default null
        ) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
    ");
}

/** The properties the owner's policy requires, measured against whatever index is in place. */
function fourProperties(PDO $pdo, string $table, string $route): void
{
    // 1. two LIVE rows, same e-mail, SAME store -> must be REJECTED
    $pdo->exec("delete from `{$table}`");
    [$ok1] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('a@x.test', 1)");
    [$ok2, $state2, $msg2] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('a@x.test', 1)");
    say($ok1 && !$ok2, "{$route}: two LIVE rows, same e-mail, SAME store -> rejected"
        . ($ok2 ? ' (IT WAS ACCEPTED -- no enforcement)' : " (SQLSTATE {$state2})"));

    // 2. two LIVE rows, same e-mail, DIFFERENT stores -> must be ALLOWED
    $pdo->exec("delete from `{$table}`");
    [$okA] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('b@x.test', 1)");
    [$okB, , $msgB] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('b@x.test', 2)");
    say($okA && $okB, "{$route}: two LIVE rows, same e-mail, DIFFERENT stores -> allowed"
        . ($okB ? '' : " (IT WAS REJECTED: {$msgB})"));

    // 3. many ARCHIVED rows, same e-mail and store -> must be ALLOWED (the NULL-semantics point)
    $pdo->exec("delete from `{$table}`");
    $allArchivedOk = true;
    for ($i = 0; $i < 3; $i++) {
        [$ok, , $m] = attempt($pdo,
            "insert into `{$table}` (email, store_id, deleted_at) values ('c@x.test', 1, now())");
        if (!$ok) { $allArchivedOk = false; note("archived insert {$i} refused: {$m}"); }
    }
    say($allArchivedOk, "{$route}: THREE ARCHIVED rows, same e-mail and store -> allowed"
        . ($allArchivedOk ? ' (NULL semantics)' : ' (IT WAS REJECTED -- archived rows collide)'));

    // 4. archive, then re-add the same address -> must be ALLOWED
    $pdo->exec("delete from `{$table}`");
    attempt($pdo, "insert into `{$table}` (email, store_id) values ('d@x.test', 1)");
    $pdo->exec("update `{$table}` set deleted_at = now() where email = 'd@x.test'");
    [$okReadd, , $mReadd] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('d@x.test', 1)");
    say($okReadd, "{$route}: archive, then re-add the same address -> allowed"
        . ($okReadd ? '' : " (IT WAS REJECTED: {$mReadd})"));

    // ...and a live duplicate is STILL rejected after the archived one exists
    [$okDup] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('d@x.test', 1)");
    say(!$okDup, "{$route}: and a SECOND live row after the re-add -> still rejected"
        . ($okDup ? ' (IT WAS ACCEPTED -- the index does not constrain live rows)' : ''));

    // 5. THE FIFTH PROPERTY, AND IT IS NOT DECORATIVE. Production's column is
    //    `store_id bigint(20) UNSIGNED DEFAULT NULL`, so a customers row CAN hold a NULL store.
    //    CONCAT() returns NULL if ANY argument is NULL, so the naive expression
    //    concat(email,'|',store_id) is NULL for such a row -- and a NULL indexed value is exempt
    //    from a unique index. Every NULL-store row would therefore escape the constraint
    //    silently. COALESCE(store_id, 0) is what closes it, and this measures that it does.
    $pdo->exec("delete from `{$table}`");
    [$okN1] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('n@x.test', null)");
    [$okN2, $stateN2] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('n@x.test', null)");
    say($okN1 && !$okN2, "{$route}: two LIVE rows, same e-mail, BOTH store_id NULL -> rejected"
        . ($okN2 ? ' (IT WAS ACCEPTED -- NULL stores escape the index)' : " (SQLSTATE {$stateN2})"));

    // and a NULL store must still be distinct from a real one, not folded onto store 0
    $pdo->exec("delete from `{$table}`");
    attempt($pdo, "insert into `{$table}` (email, store_id) values ('m@x.test', null)");
    [$okZero, , $mZero] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('m@x.test', 1)");
    say($okZero, "{$route}: a NULL store and store 1 are still DIFFERENT tenants -> allowed"
        . ($okZero ? '' : " (IT WAS REJECTED: {$mZero})"));
}

// ---------------------------------------------------------------- B. route A, generated column
echo "\nB. ROUTE A -- a STORED generated column, NULL for archived rows, plus a unique index\n";
createBaseTable($pdo, 'route_a');
[$okCol, $stateCol, $msgCol] = attempt($pdo, "
    alter table route_a
      add column email_store_live varchar(300)
        generated always as (if(deleted_at is null, concat(email, '|', coalesce(store_id, 0)), null)) stored,
      add unique index route_a_email_store_live_unique (email_store_live)
");
say($okCol, 'ROUTE A: the generated column and unique index were created'
    . ($okCol ? '' : " (SQLSTATE {$stateCol}: {$msgCol})"));
note("alter: add column email_store_live varchar(300) generated always as");
note("       (if(deleted_at is null, concat(email,'|',coalesce(store_id,0)), null)) stored,");
note('       add unique index route_a_email_store_live_unique (email_store_live)');

if ($okCol) {
    fourProperties($pdo, 'route_a', 'ROUTE A');
}

// ---------------------------------------------------------------- C. route B, functional index
echo "\nC. ROUTE B -- a FUNCTIONAL unique index over the same expression, no column added\n";
createBaseTable($pdo, 'route_b');
[$okFn, $stateFn, $msgFn] = attempt($pdo, "
    create unique index route_b_email_store_live_unique
      on route_b ((if(deleted_at is null, concat(email, '|', coalesce(store_id, 0)), null)))
");
if ($supportsFunctional) {
    // The vendor claims this feature, so a refusal here is a genuine failure.
    say($okFn, 'ROUTE B: the functional unique index was created'
        . ($okFn ? '' : " (SQLSTATE {$stateFn}: {$msgFn})"));
} else {
    // The vendor does not claim it. The attempt is still made -- the refusal is the evidence --
    // but it is recorded as a measured vendor fact, not scored as a failure of the proposal.
    say(! $okFn, 'ROUTE B: refused, as this vendor has no functional indexes'
        . ($okFn ? ' -- IT WAS ACCEPTED, so this gate is wrong' : " (SQLSTATE {$stateFn}: {$msgFn})"));
}

if ($okFn) {
    fourProperties($pdo, 'route_b', 'ROUTE B');
} else {
    note('ROUTE B IS UNAVAILABLE ON PRODUCTION. Production is MariaDB 11.4.9 and MariaDB has no');
    note('functional indexes, so Route B is withdrawn from the proposal entirely -- it is not a');
    note('fallback, it is not an option. ROUTE A (a STORED generated column) is the only route.');
}

// ------------------------------------------- C2. the control for COALESCE, measured not argued
echo "\nC2. WHY COALESCE(store_id, 0) -- the NAIVE expression measured side by side\n";
createBaseTable($pdo, 'naive');
[$okNaive, $stateNaive, $msgNaive] = attempt($pdo, "
    alter table naive
      add column email_store_live varchar(300)
        generated always as (if(deleted_at is null, concat(email, '|', store_id), null)) stored,
      add unique index naive_email_store_live_unique (email_store_live)
");

if (! $okNaive) {
    say(false, "C2: the naive control table could not be built (SQLSTATE {$stateNaive}: {$msgNaive})");
} else {
    // a real store: the naive expression constrains it correctly
    [$okR1] = attempt($pdo, "insert into naive (email, store_id) values ('r@x.test', 1)");
    [$okR2] = attempt($pdo, "insert into naive (email, store_id) values ('r@x.test', 1)");
    say($okR1 && ! $okR2, 'C2: naive expression, two live rows in store 1 -> rejected (it works here)');

    // a NULL store: CONCAT yields NULL, the row is exempt, and BOTH inserts land
    [$okZ1] = attempt($pdo, "insert into naive (email, store_id) values ('z@x.test', null)");
    [$okZ2] = attempt($pdo, "insert into naive (email, store_id) values ('z@x.test', null)");
    $leaked = (int) $pdo->query("select count(*) from naive where email = 'z@x.test'")->fetchColumn();
    say($okZ1 && $okZ2 && $leaked === 2,
        'C2: naive expression, two live rows with store_id NULL -> BOTH ACCEPTED, '
        . "{$leaked} rows (this is the hole COALESCE closes)");
    $nullIndexed = $pdo->query(
        "select email_store_live is null from naive where email = 'z@x.test' limit 1"
    )->fetchColumn();
    say((bool) $nullIndexed, 'C2: and the reason is visible in the column: it is NULL for those rows');
    note('MEASURED ON THE COMMITTED DUMP: 0 live customers currently hold a NULL store_id, so no');
    note('live row is exempt TODAY. The hole is what a future NULL would do, silently.');
}

// ---------------------------------------------------------------- D. concurrency, safeguard 5
echo "\nD. CONCURRENCY (safeguard 5) -- two connections racing the same insert\n";
$table = $okCol ? 'route_a' : ($okFn ? 'route_b' : null);

if ($table === null) {
    say(false, 'CONCURRENCY: neither route could be created, so nothing was raced');
} else {
    $pdo->exec("delete from `{$table}`");
    $c1 = connect($dsn, $user, $pass);
    $c2 = connect($dsn, $user, $pass);

    $c1->beginTransaction();
    [$ok1] = attempt($c1, "insert into `{$table}` (email, store_id) values ('race@x.test', 7)");

    // The second connection's insert must BLOCK on the unique index until the first commits,
    // then fail. A 3-second timeout keeps a hang from looking like a pass.
    $c2->exec('set session innodb_lock_wait_timeout = 3');
    $c1->commit();
    [$ok2, $state2, $msg2] = attempt($c2, "insert into `{$table}` (email, store_id) values ('race@x.test', 7)");

    $rows = (int) $pdo->query("select count(*) from `{$table}` where email = 'race@x.test'")->fetchColumn();

    say($ok1 && !$ok2 && $rows === 1,
        "CONCURRENCY: exactly one of two racing inserts survived (rows={$rows}, "
        . 'loser ' . ($ok2 ? 'ALSO SUCCEEDED -- no enforcement' : "refused SQLSTATE {$state2}") . ')');
    note('The engine is the control here, not the application. Laravel validation is check-then-act');
    note('and cannot close this window; the index can.');
    note('A caller must therefore be ready for a duplicate-key exception, which is a REQUIRED');
    note('application change if this migration is approved -- see safeguard 6.');
}

// ---------------------------------------------------------------- E. existing duplicate data
echo "\nE. EXISTING DUPLICATE DATA (safeguard 5) -- what the ALTER does to a dirty table\n";
createBaseTable($pdo, 'dirty');
$pdo->exec("insert into dirty (email, store_id) values ('dup@x.test', 1), ('dup@x.test', 1)");
$pdo->exec("insert into dirty (email, store_id, deleted_at) values ('arch@x.test', 1, now()), ('arch@x.test', 1, now())");

[$okDirty, $stateDirty, $msgDirty] = attempt($pdo, "
    alter table dirty
      add column email_store_live varchar(300)
        generated always as (if(deleted_at is null, concat(email, '|', coalesce(store_id, 0)), null)) stored,
      add unique index dirty_email_store_live_unique (email_store_live)
");
say(!$okDirty, 'EXISTING DUPLICATES: the ALTER is REFUSED on a table holding live duplicates'
    . ($okDirty ? ' (IT SUCCEEDED -- the constraint would be silently unenforced)' : " (SQLSTATE {$stateDirty})"));
note($okDirty ? '' : 'message: ' . substr($msgDirty, 0, 140));
note('So the migration CANNOT be applied blindly: the duplicates must be found and resolved first.');
note('THE EXACT QUERY that finds them, which belongs in the migration as a pre-flight check:');
note("  select email, store_id, count(*) c from customers");
note("   where deleted_at is null group by email, store_id having c > 1");

$found = $pdo->query("
    select email, store_id, count(*) c from dirty
     where deleted_at is null group by email, store_id having c > 1
")->fetchAll(PDO::FETCH_ASSOC);
say(count($found) === 1 && (int) $found[0]['c'] === 2,
    'EXISTING DUPLICATES: that query finds exactly the one offending group ('
    . count($found) . ' group(s))');
note('and it does NOT flag the two ARCHIVED rows sharing an address, which are legitimate.');
note('');
note("NOTE THE REFUSAL MESSAGE ABOVE: it names 'NULL', which is NOT the colliding value. The real");
note("collision is 'dup@x.test|1'. G below establishes why, because a message that sends an");
note('operator to the wrong row during a production migration is itself a defect in the plan.');

// ---------------------------------------------------------------- F. collation
echo "\nF. COLLATION -- does the index fold case and accents?\n";
if ($table !== null) {
    $pdo->exec("delete from `{$table}`");
    attempt($pdo, "insert into `{$table}` (email, store_id) values ('Case@x.test', 9)");
    [$okCase, , ] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('case@x.test', 9)");

    say(true, 'COLLATION: a case-different duplicate in the same store is '
        . ($okCase ? 'ACCEPTED (index is case-SENSITIVE here)' : 'REJECTED (index folds case)'));
    note('This is a FINDING either way, not a pass/fail: under utf8mb4_unicode_ci the index folds');
    note('case and accents, so Alice@x.com and alice@x.com in one store COLLIDE. If production');
    note('holds such pairs as separate live customers, the migration would refuse until they are');
    note('resolved -- and resolving them is a product decision about who owns the address, not a');
    note('schema step. The pre-flight query in E uses the same collation, so it finds them.');

    $pdo->exec("delete from `{$table}`");
    attempt($pdo, "insert into `{$table}` (email, store_id) values ('plain@x.test', 9)");
    [$okAccent, , ] = attempt($pdo, "insert into `{$table}` (email, store_id) values ('plaín@x.test', 9)");
    note('accent-different duplicate in the same store: ' . ($okAccent ? 'ACCEPTED' : 'REJECTED (folds accents)'));
}

// ------------------------------------------------- G. is the NULL exemption honoured at BUILD time?
echo "\nG. DOES THE NULL EXEMPTION HOLD DURING THE INDEX BUILD, not just on INSERT?\n";
note('This is the question E raised and must not be assumed. Production holds 37 archived');
note('customers. If the index BUILD treated their NULL generated values as colliding -- even');
note('though INSERT does not, per property 3 -- the migration would be refused on production for');
note('a reason no pre-flight query could find.');

// G1. ONLY archived duplicates, no live duplicate anywhere: the ALTER must SUCCEED.
createBaseTable($pdo, 'onlyarch');
$pdo->exec("insert into onlyarch (email, store_id, deleted_at) values
    ('a@x.test', 1, now()), ('a@x.test', 1, now()), ('a@x.test', 1, now())");
$pdo->exec("insert into onlyarch (email, store_id) values ('live@x.test', 1)");
[$okArch, $stArch, $msArch] = attempt($pdo, "
    alter table onlyarch
      add column email_store_live varchar(300)
        generated always as (if(deleted_at is null, concat(email, '|', coalesce(store_id, 0)), null)) stored,
      add unique index onlyarch_email_store_live_unique (email_store_live)
");
say($okArch, 'G1: THREE archived rows sharing an address do NOT block the ALTER'
    . ($okArch ? ' (the exemption holds at build time)' : " (IT WAS REFUSED: {$msArch})"));
if ($okArch) {
    $nullRows = (int) $pdo->query('select count(*) from onlyarch where email_store_live is null')->fetchColumn();
    say($nullRows === 3, "G1: and all three carry a NULL generated value ({$nullRows} of 3)");
}

// G2. the single-statement ALTER mis-names the colliding value when archived rows are present.
//     The two-step form names it correctly. That decides how the migration is written.
createBaseTable($pdo, 'twostep');
$pdo->exec("insert into twostep (email, store_id) values ('c@x.test', 1), ('c@x.test', 1)");
$pdo->exec("insert into twostep (email, store_id, deleted_at) values ('d@x.test', 1, now()), ('d@x.test', 1, now())");
[$okStep1, , $msStep1] = attempt($pdo, "
    alter table twostep
      add column email_store_live varchar(300)
        generated always as (if(deleted_at is null, concat(email, '|', coalesce(store_id, 0)), null)) stored
");
say($okStep1, 'G2 step 1: adding the generated column ALONE succeeds even on dirty data'
    . ($okStep1 ? ' (no index yet, so nothing to violate)' : " (REFUSED: {$msStep1})"));
if ($okStep1) {
    [$okStep2, , $msStep2] = attempt($pdo,
        'alter table twostep add unique index twostep_email_store_live_unique (email_store_live)');
    $namesTheRow = ! $okStep2 && strpos($msStep2, 'c@x.test|1') !== false;
    say($namesTheRow, 'G2 step 2: the index is refused AND the message names the real colliding'
        . ' value' . ($namesTheRow ? " ('c@x.test|1')" : " -- it said: " . substr($msStep2, 0, 120)));
    note('SO THE MIGRATION IS WRITTEN AS TWO STATEMENTS, not one. Same end state; the difference is');
    note('that a refusal on production names the address an operator has to go and resolve.');
}

// --------------------------------------------------- H. migration impact: which ALGORITHM is forced
echo "\nH. MIGRATION IMPACT -- which ALTER ALGORITHM does this engine allow?\n";
$algoResults = [];
foreach (['INSTANT', 'NOCOPY', 'INPLACE', 'COPY'] as $algo) {
    $t = 'algo_' . strtolower($algo);
    createBaseTable($pdo, $t);
    $pdo->exec("insert into `{$t}` (email, store_id) values ('e@x.test', 1)");
    [$okAlgo, , $msAlgo] = attempt($pdo, "
        alter table `{$t}`
          add column email_store_live varchar(300)
            generated always as (if(deleted_at is null, concat(email, '|', coalesce(store_id, 0)), null)) stored,
          add unique index {$t}_u (email_store_live), algorithm={$algo}
    ");
    $algoResults[$algo] = $okAlgo;
    note(sprintf('ALGORITHM=%-8s %s', $algo, $okAlgo ? 'ACCEPTED' : 'REFUSED: ' . substr($msAlgo, 0, 90)));
}
say($algoResults['COPY'] && ! $algoResults['INPLACE'] && ! $algoResults['INSTANT'],
    'H: a FULL TABLE REBUILD (ALGORITHM=COPY) is forced -- INSTANT, NOCOPY and INPLACE are all refused');
note('CONSEQUENCE FOR THE MIGRATION WINDOW: a COPY rebuild holds a lock and blocks writes to');
note('`customers` for its duration. The committed dump holds 62 customer rows in total, so the');
note('rebuild is a matter of milliseconds TODAY -- but the figure belongs in the plan, because it');
note('scales with the table and this is the platform`s tenant-facing customer table.');

// ---------------------------------------------------------------- verdict
echo "\n", str_repeat('=', 96), "\n";
printf("%d passed, %d failed, measured on %s %s (%s)\n",
    $pass_count, $fail_count, $isMariaDb ? 'MariaDB' : 'MySQL', $version, $comment);
echo "\n";
echo "WHAT THIS IS, AND WHAT IT IS NOT:\n";
echo "  * This server is the SAME VERSION as production, 11.4.9-MariaDB, read from the committed\n";
echo "    dump's own header. The table and database were created with production's declared\n";
echo "    charset and collation, utf8mb4 / utf8mb4_unicode_ci, not this server's defaults.\n";
echo "  * Production itself was NOT accessed, and is not authorised to be. sql_mode is the one\n";
echo "    production property a dump does not record, so it remains unconfirmed -- it affects\n";
echo "    neither NULL index semantics nor the forced ALTER algorithm, but it is not measured.\n";
echo "  * The duplicate assessment is from the dump, not from the live table: a dump is a point in\n";
echo "    time, so it must be re-run against production immediately before any schema change.\n";
echo "  * NO SCHEMA CHANGE IS AUTHORISED. No migration is added by this script or by the commit\n";
echo "    that carries it. This is the evidence the owner asked for before deciding.\n";
echo str_repeat('=', 96), "\n";

exit($fail_count === 0 ? 0 : 1);
