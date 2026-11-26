<?php

/**
 * OPS-001 / OPS-003 -- PROVE ON A REAL MySQL/MariaDB THE THINGS SQLITE CANNOT ANSWER.
 *
 * WHY A SCRIPT AND NOT A TEST. The suite runs on SQLite `:memory:` (`phpunit.xml`), and THREE of
 * `SaasOperationsProbe`'s readings have a MySQL-only branch that SQLite can only ever REFUSE:
 *
 *     databaseTableCount()        information_schema.tables       SQLite: sqlite_master
 *     databaseSizeBytes()         data_length + index_length      SQLite: page_count * page_size
 *     databaseConnectionsInUse()  SHOW STATUS 'Threads_connected' SQLite: null, by design
 *
 * `PlatformOperationsMeasurementTest` asserts the SQLite branches by changing the system and
 * watching the figure move, and asserts the REFUSAL of the third. What it cannot do is prove that
 * the MySQL branches return the machine's own figures on the driver production actually runs -- and
 * `CLAUDE.md` is explicit that SQLite's type affinity has hidden a real MySQL defect in this
 * repository before (P01-SEC-13). A conditionally-skipped test is also not available: the Feature
 * suite's accepted-skip SET is declared in `.github/known-test-outcomes.json` and asserted in both
 * directions, so a host-dependent skip cannot be declared truthfully.
 *
 * WHAT IT ANSWERS
 *
 *   A. THE MIGRATION, ON THE PRODUCTION DRIVER. `2026_11_26_000100_create_platform_incidents_tables`
 *      runs from zero; both tables, every column and every declared index are then read back from
 *      `information_schema` rather than from the Blueprint that made them. And `down()` is asserted
 *      to be the documented NO-OP: a rollback must not destroy the platform's operational history.
 *
 *   B. THE THREE MySQL-ONLY READINGS, each of them by CHANGING THE THING IT MEASURES rather than by
 *      looking plausible -- which is the whole discipline OPS-002's one load-bearing word asks for:
 *        B1  the table count rises by exactly one when a table is created, and falls when dropped;
 *        B2  the reported size GROWS when a megabyte of rows is written;
 *        B3  `Threads_connected` is a real figure and RISES when a second connection is opened.
 *
 *   C. THE INCIDENT LOG'S STATE MACHINE ON MySQL, including the one thing SQLite cannot be trusted
 *      about: the UNIQUE constraint on `platform_incidents.reference`. SQLite enforces it, but the
 *      engine that matters is the one that will, so a duplicate insert is attempted and must be
 *      refused.
 *
 * WHAT IT NEVER DOES. It never touches a database it did not create, and it never runs against
 * production. Every database it uses is named `opsverify_` plus the process id, created at the
 * start and dropped at the end -- including on failure.
 *
 * PRECONDITIONS, stated rather than assumed -- each is checked and named if it fails:
 *   - a reachable MariaDB/MySQL server, and an account that may CREATE and DROP DATABASE;
 *   - this repository's `vendor/` installed.
 *
 * Usage:
 *   php tests/Support/Security/bin/verify-ops-measurements-on-mysql.php \
 *       [--socket=/run/mysqld/mysqld.sock | --host=127.0.0.1 --port=3306] [--user=root] [--pass=]
 *
 * Exit status: 0 = every check passed · 1 = a check failed · 2 = a precondition was not met.
 */

// ------------------------------------------------------------------ arguments

$opt = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '', 'socket' => null];

foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } elseif ($a === '--help' || $a === '-h') {
        fwrite(STDERR, 'usage: php ' . basename(__FILE__) . " [--socket=|--host= --port=] [--user=] [--pass=]\n");
        exit(2);
    }
}

$root = dirname(__DIR__, 4);

if (! is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "PRECONDITION NOT MET: vendor/ is not installed. Run `composer install` first.\n");
    exit(2);
}

require $root . '/vendor/autoload.php';

$dsnFor = static function (?string $db) use ($opt): string {
    $tail = $db !== null ? ";dbname={$db}" : '';

    return $opt['socket'] !== null
        ? "mysql:unix_socket={$opt['socket']}{$tail};charset=utf8mb4"
        : "mysql:host={$opt['host']};port={$opt['port']}{$tail};charset=utf8mb4";
};

try {
    $server = new PDO($dsnFor(null), $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot reach the server -- {$e->getMessage()}\n");
    fwrite(STDERR, "Start one, or pass --socket=/run/mysqld/mysqld.sock.\n");
    exit(2);
}

$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
$database = 'opsverify_' . getmypid();
$checks = 0;
$fail = 0;

$say = static fn (string $s = '') => print($s . "\n");
$ok = static function (string $s) use (&$checks) { $checks++; print("  PASS  {$s}\n"); };
$bad = static function (string $s) use (&$checks, &$fail) { $checks++; $fail++; print("  FAIL  {$s}\n"); };
$is = static function (bool $cond, string $s) use ($ok, $bad) { $cond ? $ok($s) : $bad($s); };

$cleanup = static function () use ($server, $database) {
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable) {
        // nothing further to do; the name carries this process id and is never production's
    }
};

register_shutdown_function($cleanup);

$say('OPS-001 / OPS-003 -- operational measurements on a real server');
$say('  server   ' . $version);
$say('  database ' . $database . '  (created and dropped by this script)');
$say();

$server->exec("DROP DATABASE IF EXISTS `{$database}`");
$server->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$pdo = new PDO($dsnFor($database), $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ------------------------------------------------------------------ the app, pointed at it

$env = [
    'DB_CONNECTION=mysql',
    'DB_DATABASE=' . $database,
    'DB_USERNAME=' . $opt['user'],
    'DB_PASSWORD=' . $opt['pass'],
    'CACHE_STORE=array',
    'SESSION_DRIVER=array',
    'QUEUE_CONNECTION=database',
];

$env[] = $opt['socket'] !== null ? 'DB_SOCKET=' . $opt['socket'] : 'DB_HOST=' . $opt['host'];

if ($opt['socket'] === null) {
    $env[] = 'DB_PORT=' . $opt['port'];
}

$artisan = static function (string $command) use ($root, $env): array {
    /*
     * `env` is required rather than decoration: a shell treats a QUOTED `VAR=value` as a command
     * name, so `'DB_CONNECTION=mysql' php artisan ...` exits 127 with "not found" -- which is how
     * the GATE F script first reported a migration failure that had not happened.
     */
    $cmd = 'cd ' . escapeshellarg($root) . ' && env ' . implode(' ', array_map('escapeshellarg', $env))
        . ' php artisan ' . $command . ' 2>&1';

    exec($cmd, $out, $code);

    return [$code, implode("\n", $out)];
};

/*
 * THE WHOLE MIGRATION SET, not just this one. The incident tables have no dependency on any other
 * table -- no foreign keys, by the deliberate decision recorded in the migration -- but running
 * them alone would not prove they land in a database that has been through the rest of the set,
 * which is the only state production is ever in.
 */
$say('A. the migration, on the production driver');

[$code, $out] = $artisan('migrate --force --no-interaction');

if ($code !== 0) {
    $bad('`php artisan migrate` failed, so nothing below could be measured:' . "\n" . $out);
    $say();
    $say("  {$checks} checks, {$fail} failed");
    exit(1);
}

$ok('every migration ran from zero on ' . $version);

$columnsOf = static function (PDO $pdo, string $table) use ($database): array {
    $stmt = $pdo->prepare(
        'select column_name, data_type, is_nullable from information_schema.columns '
        . 'where table_schema = ? and table_name = ? order by ordinal_position'
    );
    $stmt->execute([$database, $table]);

    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $columns[(string) $row['column_name']] = [
            'type' => (string) $row['data_type'],
            'nullable' => $row['is_nullable'] === 'YES',
        ];
    }

    return $columns;
};

$indexesOf = static function (PDO $pdo, string $table) use ($database): array {
    $stmt = $pdo->prepare(
        'select distinct index_name from information_schema.statistics '
        . 'where table_schema = ? and table_name = ? order by index_name'
    );
    $stmt->execute([$database, $table]);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};

$expected = [
    'platform_incidents' => [
        'columns' => [
            'id', 'reference', 'title', 'summary', 'severity', 'status',
            'detected_at', 'acknowledged_at', 'mitigated_at', 'resolved_at',
            'opened_by', 'diagnostics', 'created_at', 'updated_at',
        ],
        'indexes' => ['PRIMARY', 'pinc_detected', 'pinc_live', 'platform_incidents_reference_unique'],
    ],
    'platform_incident_events' => [
        'columns' => [
            'id', 'incident_id', 'from_status', 'to_status', 'note', 'actor_id',
            'recorded_at', 'created_at', 'updated_at',
        ],
        'indexes' => ['PRIMARY', 'pince_incident'],
    ],
];

foreach ($expected as $table => $spec) {
    $columns = $columnsOf($pdo, $table);

    $is($columns !== [], "`{$table}` exists");
    $is(array_keys($columns) === $spec['columns'],
        "`{$table}` has exactly its declared columns, in order"
        . ($columns === [] || array_keys($columns) === $spec['columns']
            ? ''
            : "\n          measured: " . implode(', ', array_keys($columns))));

    $indexes = $indexesOf($pdo, $table);
    sort($indexes);

    $is($indexes === $spec['indexes'],
        "`{$table}` has exactly its declared indexes"
        . ($indexes === $spec['indexes'] ? '' : "\n          measured: " . implode(', ', $indexes)));
}

/*
 * `diagnostics` IS `longtext` AND NOT `json`, deliberately -- SQLite has no json type and the
 * column is read back whole rather than queried into. Asserted because a later "improvement" to
 * `json` would be a MySQL-only column the suite could not create.
 */
$incidentColumns = $columnsOf($pdo, 'platform_incidents');

$is(($incidentColumns['diagnostics']['type'] ?? null) === 'longtext',
    '`platform_incidents.diagnostics` is longtext (measured: ' . ($incidentColumns['diagnostics']['type'] ?? 'absent') . ')');
$is(($incidentColumns['reference']['type'] ?? null) === 'varchar',
    '`platform_incidents.reference` is a varchar');
$is(($incidentColumns['opened_by']['nullable'] ?? false) === true,
    '`platform_incidents.opened_by` is nullable, so an incident outlives the operator who recorded it');

// THE ROLLBACK IS A DOCUMENTED NO-OP. A rollback must not destroy the operational history.
$pdo->exec("insert into platform_incidents (reference, title, severity, status, detected_at, created_at, updated_at) "
    . "values ('INC-00000000-AAAA', 'rollback canary', 'minor', 'open', now(), now(), now())");

[$code, $out] = $artisan('migrate:rollback --step=1 --force --no-interaction');

$survived = (int) $pdo->query('select count(*) from platform_incidents')->fetchColumn();

$is($code === 0 && $survived === 1,
    'rolling the migration back destroys nothing: the table and its row survive (exit ' . $code . ', rows ' . $survived . ')');

$say();

// ------------------------------------------------------------------ B. the MySQL-only readings

$say('B. the three readings SQLite can only refuse, each asserted by changing what it measures');

/*
 * THE PROBE IS NOT BOOTED THROUGH THE APPLICATION HERE, and that is deliberate. Booting Laravel in
 * this process would need the whole container, a second connection and an .env this script does not
 * own; the three readings are each ONE query, and issuing them against the same PDO the assertions
 * use is both simpler and more direct evidence -- the figure is compared with the server's own
 * answer rather than with a second reading through a framework. The SQL below is the probe's,
 * character for character; `verifyProbeSqlMatchesTheSource()` at the end asserts that it still is.
 */
$tableCount = static function (PDO $pdo) use ($database): int {
    $stmt = $pdo->prepare('select count(*) as c from information_schema.tables where table_schema = ?');
    $stmt->execute([$database]);

    return (int) $stmt->fetchColumn();
};

$sizeBytes = static function (PDO $pdo) use ($database): ?int {
    $stmt = $pdo->prepare('select sum(data_length + index_length) as b from information_schema.tables where table_schema = ?');
    $stmt->execute([$database]);
    $value = $stmt->fetchColumn();

    return $value === null || $value === false ? null : (int) $value;
};

$threadsConnected = static function (PDO $pdo): ?int {
    $row = $pdo->query("show status like 'Threads_connected'")->fetch(PDO::FETCH_NUM);

    return isset($row[1]) && is_numeric($row[1]) ? (int) $row[1] : null;
};

// B1 -- the table count follows the catalogue.
$before = $tableCount($pdo);
$pdo->exec('create table ops_probe_fixture (id bigint unsigned not null auto_increment primary key)');
$after = $tableCount($pdo);
$pdo->exec('drop table ops_probe_fixture');
$restored = $tableCount($pdo);

$is($before > 10, "B1 premise: the database really holds tables ({$before})");
$is($after === $before + 1, "B1 the table count rises by exactly one when a table is created ({$before} -> {$after})");
$is($restored === $before, "B1 and falls again when it is dropped ({$after} -> {$restored})");

// B2 -- the size follows the data.
$sizeBefore = $sizeBytes($pdo);

$is($sizeBefore !== null && $sizeBefore > 0,
    'B2 premise: information_schema reports a size at all (' . var_export($sizeBefore, true) . ')');

$pdo->exec('create table ops_probe_bulk (id bigint unsigned not null auto_increment primary key, payload longtext not null)');

$insert = $pdo->prepare('insert into ops_probe_bulk (payload) values (?)');
$payload = str_repeat('x', 8192);

for ($i = 0; $i < 400; $i++) {
    $insert->execute([$payload]);
}

/*
 * InnoDB's `data_length` is updated from statistics rather than on every write, so the figure can
 * lag. `ANALYZE TABLE` asks the engine to refresh them -- which is also the honest statement about
 * this reading and is why `SaasPlatformOperations` tells the operator on the screen that the MySQL
 * figure is the engine's own estimate and is approximate for InnoDB. That sentence is being
 * verified here, not worked around.
 */
$pdo->exec('analyze table ops_probe_bulk');

$sizeAfter = $sizeBytes($pdo);

$is($sizeAfter !== null && $sizeBefore !== null && $sizeAfter > $sizeBefore,
    'B2 the reported size grows when about 3.2 MB of rows is written ('
    . (int) $sizeBefore . ' -> ' . (int) $sizeAfter . ' bytes)');

$pdo->exec('drop table ops_probe_bulk');

// B3 -- the connection count is the server's, and it moves.
$threadsBefore = $threadsConnected($pdo);

$is($threadsBefore !== null && $threadsBefore >= 1,
    'B3 premise: the server publishes Threads_connected (' . var_export($threadsBefore, true) . ')');

$second = new PDO($dsnFor($database), $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$second->query('select 1');

$threadsAfter = $threadsConnected($pdo);

$is($threadsAfter !== null && $threadsBefore !== null && $threadsAfter > $threadsBefore,
    'B3 the connection count rises when a second connection is opened ('
    . var_export($threadsBefore, true) . ' -> ' . var_export($threadsAfter, true) . ')');

$second = null;

$say();

// ------------------------------------------------------------------ C. the incident record

$say('C. the incident record on MySQL');

[$code] = $artisan('migrate --force --no-interaction');     // put the rolled-back step back

$is($code === 0, 'C premise: the migration re-runs after a rollback (it is idempotent)');

$duplicateRefused = false;

try {
    $pdo->exec("insert into platform_incidents (reference, title, severity, status, detected_at, created_at, updated_at) "
        . "values ('INC-00000000-AAAA', 'duplicate reference', 'minor', 'open', now(), now(), now())");
} catch (Throwable) {
    $duplicateRefused = true;
}

$is($duplicateRefused,
    'C the UNIQUE constraint on `reference` refuses a duplicate, so quoting an incident reference '
    . 'is never ambiguous');

/*
 * AND THE EVENT TABLE TAKES A NOTE WITH NEITHER STATUS -- the shape that distinguishes a note from
 * a move without a `type` column. Asserted on MySQL because a NOT NULL that SQLite tolerated would
 * refuse it here.
 */
$incidentId = (int) $pdo->query("select id from platform_incidents where reference = 'INC-00000000-AAAA'")->fetchColumn();

$noteAccepted = false;

try {
    $pdo->exec("insert into platform_incident_events (incident_id, from_status, to_status, note, actor_id, recorded_at, created_at, updated_at) "
        . "values ({$incidentId}, null, null, 'a note', null, now(), now(), now())");
    $noteAccepted = true;
} catch (Throwable $e) {
    $noteAccepted = false;
}

$is($noteAccepted,
    'C an event with neither status and no actor is accepted -- which is what a note is, and what '
    . 'an automatically opened incident would need');

$say();

// ------------------------------------------------------------------ the SQL is still the probe's

$say('D. the SQL above is still the SQL the probe issues');

$source = (string) file_get_contents($root . '/app/Services/SaasOperationsProbe.php');

// Comments out, so the prose in that file explaining these queries cannot satisfy this.
$source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
$source = (string) preg_replace('#^\s*//.*$#m', '', $source);

foreach ([
    'select count(*) as c from information_schema.tables where table_schema = ?',
    'select sum(data_length + index_length) as b from information_schema.tables where table_schema = ?',
    "show status like 'Threads_connected'",
] as $sql) {
    $is(str_contains($source, $sql),
        'D the probe still issues `' . $sql . '`');
}

$say();
$say("  {$checks} checks, {$fail} failed");

exit($fail === 0 ? 0 : 1);
