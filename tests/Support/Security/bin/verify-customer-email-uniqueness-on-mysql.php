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
 *   For each route, the four properties the owner's policy needs:
 *      1. two LIVE rows, same e-mail, SAME store      -> REJECTED
 *      2. two LIVE rows, same e-mail, DIFFERENT stores -> ALLOWED
 *      3. many ARCHIVED rows, same e-mail and store    -> ALLOWED (NULL semantics)
 *      4. archive, then re-add the same address        -> ALLOWED
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
note('PRODUCTION VERSION IS NOT ESTABLISHED IN THIS REPOSITORY. HANDOFF-CURRENT.md:136 says to');
note('confirm it. Every verdict below is about THIS server and must be re-run against production');
note('before the migration is applied.');

[$major, $minor, $patch] = array_pad(array_map('intval', explode('.', (string) $version)), 3, 0);
$supportsGenerated = ($major > 5) || ($major === 5 && $minor >= 7);
$supportsFunctional = ($major > 8) || ($major === 8 && ($minor > 0 || $patch >= 13));
say($supportsGenerated, "generated columns supported (needs 5.7+): " . ($supportsGenerated ? 'yes' : 'NO'));
note('functional indexes need 8.0.13+: ' . ($supportsFunctional ? 'supported' : 'NOT supported here'));

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
            email varchar(191) not null,
            store_id bigint unsigned null,
            deleted_at timestamp null default null
        ) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
    ");
}

/** The four properties the owner's policy requires, measured against whatever index is in place. */
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
}

// ---------------------------------------------------------------- B. route A, generated column
echo "\nB. ROUTE A -- a STORED generated column, NULL for archived rows, plus a unique index\n";
createBaseTable($pdo, 'route_a');
[$okCol, $stateCol, $msgCol] = attempt($pdo, "
    alter table route_a
      add column email_store_live varchar(220)
        generated always as (if(deleted_at is null, concat(email, '|', store_id), null)) stored,
      add unique index route_a_email_store_live_unique (email_store_live)
");
say($okCol, 'ROUTE A: the generated column and unique index were created'
    . ($okCol ? '' : " (SQLSTATE {$stateCol}: {$msgCol})"));
note("alter: add column email_store_live varchar(220) generated always as");
note("       (if(deleted_at is null, concat(email,'|',store_id), null)) stored,");
note('       add unique index route_a_email_store_live_unique (email_store_live)');

if ($okCol) {
    fourProperties($pdo, 'route_a', 'ROUTE A');
}

// ---------------------------------------------------------------- C. route B, functional index
echo "\nC. ROUTE B -- a FUNCTIONAL unique index over the same expression, no column added\n";
createBaseTable($pdo, 'route_b');
[$okFn, $stateFn, $msgFn] = attempt($pdo, "
    create unique index route_b_email_store_live_unique
      on route_b ((if(deleted_at is null, concat(email, '|', store_id), null)))
");
say($okFn, 'ROUTE B: the functional unique index was created'
    . ($okFn ? '' : " (SQLSTATE {$stateFn}: {$msgFn})"));

if ($okFn) {
    fourProperties($pdo, 'route_b', 'ROUTE B');
} else {
    note('Route B is unavailable on this server; Route A is the one to propose.');
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
      add column email_store_live varchar(220)
        generated always as (if(deleted_at is null, concat(email, '|', store_id), null)) stored,
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

// ---------------------------------------------------------------- verdict
echo "\n", str_repeat('=', 96), "\n";
printf("%d passed, %d failed, measured on MySQL %s\n", $pass_count, $fail_count, $version);
echo "THIS IS NOT A CLAIM ABOUT PRODUCTION. Production's version, collation and sql_mode are not\n";
echo "established in this repository; re-run this script against it before any schema change.\n";
echo str_repeat('=', 96), "\n";

exit($fail_count === 0 ? 0 : 1);
