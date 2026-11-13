#!/usr/bin/env php
<?php

/**
 * PHASE 03 M3-B — DO THE THEME-VERSION CONSTRAINTS ACTUALLY REFUSE WHAT THEY CLAIM?
 * =================================================================================================
 *
 * WHY THIS IS A STANDALONE SCRIPT AND NOT A PHPUnit TEST. The Feature suite runs on SQLite
 * `:memory:` (`phpunit.xml`) and `tests/Support/TestDatabaseGuard.php` ABORTS any PHPUnit run whose
 * connection is not sqlite, because `RefreshDatabase` would `migrate:fresh` and drop every table in
 * the target database. That guard is correct and must not be weakened, so MySQL-specific evidence
 * lives in scripts that create and drop their OWN database — the same shape as the other ~60
 * `verify-*-on-mysql.php` files here.
 *
 * AND SQLITE CANNOT ANSWER THIS QUESTION AT ALL. Measured at M3-A: `PRAGMA index_list` on
 * `theme_customizes` reports NO indexes, while MySQL has `theme_customizes_store_id_foreign` because
 * a foreign key requires one. Worse, SQLite does not enforce foreign keys unless
 * `PRAGMA foreign_keys = ON` is set per connection, so a `RESTRICT` that silently does nothing there
 * would pass a SQLite test and fail in production.
 *
 * WHY EVERY CHECK CAPTURES REAL IDs
 * -------------------------------------------------------------------------------------------------
 * The first hand-run of these checks was WRONG in both directions and is the reason this script
 * exists. Rows were inserted and then referenced by *assumed* ids (1 and 2). Auto-increment did not
 * restart, so:
 *
 *   - "a SECOND published version for the same pair" was refused with ERROR 1452 (FOREIGN KEY)
 *     instead of 1062 (DUPLICATE KEY). It looked like a pass. It was a refusal because the version
 *     it pointed at did not exist — the unique constraint was never reached.
 *   - "deleting the version that is LIVE" was ACCEPTED, which read as a missing RESTRICT. It
 *     deleted a row that did not exist.
 *
 * A false refusal and a false acceptance from the same mistake. So every check below inserts its own
 * rows, reads back the ids the engine assigned, asserts its PREMISE (the rows it needs really are
 * there and really are linked), and only then attempts the violation. A check whose premise fails is
 * reported as INCONCLUSIVE, never as a pass.
 *
 * It also asserts the SQLSTATE and the constraint NAME, not merely that something failed. "It threw"
 * is not evidence that the intended constraint fired.
 *
 * USAGE
 *   php tests/Support/Security/bin/verify-theme-version-integrity-on-mysql.php \
 *       [--socket=/var/run/mysqld/mysqld.sock | --host=127.0.0.1 --port=3306] [--user=root] [--pass=]
 *
 * It creates a throwaway database, migrates into it, runs the checks and DROPS it, including on
 * failure. It never touches an existing database and never reads production.
 */

$opt = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '', 'socket' => null];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "usage: php " . basename(__FILE__)
        . " [--socket=|--host= --port=] [--user=] [--pass=]\n");
    exit(2);
}

$root = dirname(__DIR__, 4);
$name = 'p3m3_theme_versions_' . getmypid();

function dsn(array $opt, ?string $db): string
{
    $tail = $db === null ? '' : ";dbname={$db}";

    return $opt['socket'] !== null
        ? "mysql:unix_socket={$opt['socket']}{$tail};charset=utf8mb4"
        : "mysql:host={$opt['host']};port={$opt['port']}{$tail};charset=utf8mb4";
}

try {
    $server = new PDO(dsn($opt, null), $opt['user'], $opt['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "No MySQL/MariaDB server reachable: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Start one (`service mariadb start`) or pass --socket=/var/run/mysqld/mysqld.sock.\n");
    exit(2);
}

$pass = 0;
$fail = 0;
$inconclusive = 0;

/** A check that must be REFUSED, by a named constraint, with an expected SQLSTATE. */
function refuses(PDO $db, string $what, string $sql, string $sqlstate, string $constraint): void
{
    global $pass, $fail;

    try {
        $db->exec($sql);
        printf("  FAIL  %-52s ACCEPTED -- the constraint did not fire\n", $what);
        $fail++;
    } catch (PDOException $e) {
        $state   = $e->errorInfo[0] ?? '?';
        $message = $e->errorInfo[2] ?? $e->getMessage();

        $stateOk      = $state === $sqlstate;
        $constraintOk = $constraint === '' || stripos($message, $constraint) !== false;

        if ($stateOk && $constraintOk) {
            printf("  ok    %-52s refused (%s, %s)\n", $what, $state, $constraint ?: 'NOT NULL');
            $pass++;
        } else {
            printf("  FAIL  %-52s refused for the WRONG reason: %s %s\n",
                $what, $state, substr($message, 0, 90));
            printf("        expected SQLSTATE %s naming %s\n", $sqlstate, $constraint ?: '(not null)');
            $fail++;
        }
    }
}

/** A check that must SUCCEED — the constraint must not be so broad it blocks legitimate work. */
function allows(PDO $db, string $what, string $sql): void
{
    global $pass, $fail;

    try {
        $db->exec($sql);
        printf("  ok    %-52s accepted\n", $what);
        $pass++;
    } catch (PDOException $e) {
        printf("  FAIL  %-52s REFUSED a legitimate operation: %s\n",
            $what, substr($e->errorInfo[2] ?? $e->getMessage(), 0, 90));
        $fail++;
    }
}

function premise(bool $ok, string $what): bool
{
    global $inconclusive;

    if (! $ok) {
        printf("  ??    %-52s PREMISE FAILED -- nothing was proved\n", $what);
        $inconclusive++;
    }

    return $ok;
}

try {
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    $server->exec("CREATE DATABASE `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $env = ['DB_CONNECTION=mysql', 'DB_DATABASE=' . $name,
            'DB_USERNAME=' . $opt['user'], 'DB_PASSWORD=' . $opt['pass']];
    $env[] = $opt['socket'] !== null ? 'DB_SOCKET=' . $opt['socket'] : 'DB_HOST=' . $opt['host'];
    if ($opt['socket'] === null) {
        $env[] = 'DB_PORT=' . $opt['port'];
    }

    echo "  migrating a fresh database ({$name})\n";

    /*
     * `env` is required, not decoration, and the sibling harness
     * `verify-gate-f-migrations-on-mysql.php:124` already records why: a shell treats a QUOTED
     * `VAR=value` as a COMMAND NAME, so `'DB_CONNECTION=mysql' php artisan ...` exits 127 with
     * "not found". This script made the identical mistake on its first run and reported a migration
     * failure that had not happened -- a false failure is as bad as a false pass.
     */
    $cmd = 'cd ' . escapeshellarg($root) . ' && env ' . implode(' ', array_map('escapeshellarg', $env))
        . ' php artisan migrate --force --no-interaction 2>&1';

    exec($cmd, $out, $code);

    if ($code !== 0) {
        fwrite(STDERR, "  migrate FAILED:\n" . implode("\n", array_slice($out, -15)) . "\n");
        throw new RuntimeException('migrate failed');
    }

    $db = new PDO(dsn($opt, $name), $opt['user'], $opt['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    /*
     * MEASURE THE ENGINE PRODUCTION ACTUALLY RUNS, NOT THE ONE THIS SERVER DEFAULTS TO.
     *
     * A raw PDO connection inherits the SERVER's sql_mode -- on a stock MariaDB 10.11 that is
     * `STRICT_TRANS_TABLES,...`. Production sets `'strict' => false` (config/database.php:55), which
     * Laravel's MySqlConnector turns into `SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'` -- i.e.
     * MySQL COERCES a bad value instead of refusing it.
     *
     * Without this line the script asserts STRICT behaviour while all three migration docblocks are
     * justified by NON-STRICT coercion, so it would pass for reasons production does not share. It
     * is the P01-SEC-13 lesson one layer up: there SQLite's affinity hid a MySQL defect; here strict
     * mode hides a non-strict one. Two things it changes:
     *   - "a version owned by no store" is refused by NOT NULL (1048) under strict, but under
     *     production NULL coerces to 0 and the FOREIGN KEY refuses it (1452) instead;
     *   - an over-length `theme_id` ERRORS under strict and SILENTLY TRUNCATES in production, which
     *     a strict-mode harness is structurally unable to observe.
     */
    $db->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
    printf("  sql_mode: %s   (production's, per config/database.php:55 'strict' => false)\n",
        $db->query('SELECT @@SESSION.sql_mode')->fetchColumn());

    /* ---------------------------------------------------------------- premise: the tables exist */
    foreach (['theme_versions', 'theme_published_versions', 'theme_publication_events'] as $t) {
        $n = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = " . $db->quote($name) . "
                                 AND TABLE_NAME = " . $db->quote($t))->fetchColumn();
        if (! premise($n === 1, "table {$t} exists")) {
            throw new RuntimeException("{$t} is missing");
        }
    }

    /* ---------------------------------------------------------------------- a store and an actor */
    $db->exec("INSERT INTO users (name,email,password,type,created_at,updated_at)
               VALUES ('M3 Probe','m3probe@example.test','x','super admin',NOW(),NOW())");
    $userId = (int) $db->lastInsertId();

    $db->exec("INSERT INTO stores (name,email,slug,theme_id,created_by,created_at,updated_at)
               VALUES ('Probe A','pa@example.test','probe-a','stylique',{$userId},NOW(),NOW())");
    $storeA = (int) $db->lastInsertId();

    $db->exec("INSERT INTO stores (name,email,slug,theme_id,created_by,created_at,updated_at)
               VALUES ('Probe B','pb@example.test','probe-b','greentic',{$userId},NOW(),NOW())");
    $storeB = (int) $db->lastInsertId();

    premise($storeA > 0 && $storeB > 0 && $storeA !== $storeB, 'two distinct stores exist');

    /* -------------------------------------------- versions, with the ids the ENGINE actually gave */
    $mk = function (int $store, string $theme, int $no, string $status, string $hashChar) use ($db): int {
        $db->exec("INSERT INTO theme_versions
            (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
            VALUES ({$store}," . $db->quote($theme) . ",{$no}," . $db->quote($status) . ",
                    '{\"site_title\":\"probe\"}'," . $db->quote(str_repeat($hashChar, 64)) . ",NOW(),NOW())");

        return (int) $db->lastInsertId();
    };

    $vA1 = $mk($storeA, 'stylique', 1, 'published', 'a');
    $vA2 = $mk($storeA, 'stylique', 2, 'draft',     'b');
    $vB1 = $mk($storeB, 'greentic', 1, 'draft',     'c');

    premise($vA1 > 0 && $vA2 > 0 && $vB1 > 0, 'three versions were created');
    printf("  premise: version ids are %d, %d, %d (NOT assumed to be 1,2,3)\n", $vA1, $vA2, $vB1);

    $db->exec("INSERT INTO theme_published_versions
               (store_id,theme_id,version_id,published_at,published_by,created_at,updated_at)
               VALUES ({$storeA},'stylique',{$vA1},NOW(),{$userId},NOW(),NOW())");

    $live = (int) $db->query("SELECT version_id FROM theme_published_versions
                              WHERE store_id = {$storeA} AND theme_id = 'stylique'")->fetchColumn();

    if (! premise($live === $vA1,
        "the pointer really references version {$vA1} (got {$live})")) {
        throw new RuntimeException('the publish pointer was not established; the RESTRICT check '
            . 'below would delete a row nothing references and FALSELY PASS');
    }

    echo "\n  --- these must be REFUSED ---\n";

    refuses($db, 'a duplicate (store, theme, version_no)',
        "INSERT INTO theme_versions (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
         VALUES ({$storeA},'stylique',1,'draft','{}'," . $db->quote(str_repeat('d', 64)) . ",NOW(),NOW())",
        '23000', 'tv_store_theme_version_unique');

    refuses($db, 'a SECOND live version for the same store+theme (its own)',
        "INSERT INTO theme_published_versions (store_id,theme_id,version_id,published_at,created_at,updated_at)
         VALUES ({$storeA},'stylique',{$vA2},NOW(),NOW(),NOW())",
        '23000', 'tpv_store_theme_unique');

    refuses($db, 'deleting the version that is LIVE',
        "DELETE FROM theme_versions WHERE id = {$vA1}",
        '23000', 'tpv_version_identity_foreign');

    /*
     * THE CROSS-TENANT CHECK. `theme_published_versions` references
     * `theme_versions (store_id, theme_id, id)` rather than `id` alone, so the engine refuses a
     * pointer whose store or theme does not match the version's. Without the composite key these
     * two would be ACCEPTED -- a cross-tenant publication primitive sitting in the schema.
     */
    refuses($db, "store B publishing store A's version",
        "INSERT INTO theme_published_versions (store_id,theme_id,version_id,published_at,created_at,updated_at)
         VALUES ({$storeB},'greentic',{$vA1},NOW(),NOW(),NOW())",
        '23000', 'tpv_version_identity_foreign');

    refuses($db, "the WRONG THEME of the right store publishing it",
        "INSERT INTO theme_published_versions (store_id,theme_id,version_id,published_at,created_at,updated_at)
         VALUES ({$storeA},'greentic',{$vA1},NOW(),NOW(),NOW())",
        '23000', 'tpv_version_identity_foreign');

    /*
     * A SINGLE-ROW explicit NULL is refused by NOT NULL in BOTH sql_modes. That was measured rather
     * than assumed, because a review predicted production's non-strict mode would coerce it to 0 and
     * let the FOREIGN KEY catch it instead. It does not:
     *
     *     sql_mode=STRICT_TRANS_TABLES,...   INSERT ... VALUES (NULL)      ERROR 1048
     *     sql_mode=NO_ENGINE_SUBSTITUTION    INSERT ... VALUES (NULL)      ERROR 1048
     *
     * Non-strict mode downgrades it only for a MULTI-ROW insert -- which is checked next, because
     * that is the shape a publish actually uses.
     */
    refuses($db, 'a version owned by no store (single row)',
        "INSERT INTO theme_versions (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
         VALUES (NULL,'stylique',9,'draft','{}'," . $db->quote(str_repeat('e', 64)) . ",NOW(),NOW())",
        '23000', '');

    /*
     * THE SHAPE A PUBLISH ACTUALLY USES, AND THE ONE WHERE THE TWO MODES DISAGREE.
     *
     *     sql_mode=STRICT_TRANS_TABLES,...   INSERT ... VALUES (1),(NULL)  ERROR 1048
     *     sql_mode=NO_ENGINE_SUBSTITUTION    INSERT ... VALUES (1),(NULL)  ACCEPTED, NULL -> 0
     *
     * Production runs the second. A publish writes a snapshot's rows in ONE multi-row insert, so a
     * null store id in that batch is COERCED rather than refused -- and on `theme_versions` the
     * foreign key is then the only thing that catches it, because no store has id 0. This asserts
     * that it does.
     *
     * It matters beyond this table: `theme_customizes`, which the publish projects into, has a
     * NULLABLE `store_id`, so the same coercion there produces a row owned by nobody that no
     * constraint objects to. That hop is guarded by the handler alone -- see the inherited
     * requirements in docs/development/phase-3/PHASE-03-M3-THEME-VERSIONING.md.
     */
    refuses($db, 'a MULTI-ROW publish carrying a null store id',
        "INSERT INTO theme_versions (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
         VALUES ({$storeB},'greentic',77,'draft','{}'," . $db->quote(str_repeat('i', 64)) . ",NOW(),NOW()),
                (NULL,'greentic',78,'draft','{}'," . $db->quote(str_repeat('j', 64)) . ",NOW(),NOW())",
        '23000', 'tv_store_id_foreign');

    refuses($db, 'a version for a store that does not exist',
        "INSERT INTO theme_versions (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
         VALUES (987654321,'stylique',9,'draft','{}'," . $db->quote(str_repeat('f', 64)) . ",NOW(),NOW())",
        '23000', 'tv_store_id_foreign');

    refuses($db, 'a pointer to a version that does not exist',
        "INSERT INTO theme_published_versions (store_id,theme_id,version_id,published_at,created_at,updated_at)
         VALUES ({$storeB},'greentic',987654321,NOW(),NOW(),NOW())",
        '23000', 'tpv_version_identity_foreign');

    echo "\n  --- these must be ACCEPTED (a constraint that blocks real work is also a defect) ---\n";

    allows($db, 'the same version_no for a DIFFERENT store',
        "INSERT INTO theme_versions (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
         VALUES ({$storeB},'greentic',2,'draft','{}'," . $db->quote(str_repeat('g', 64)) . ",NOW(),NOW())");

    allows($db, 'the same version_no for a DIFFERENT theme, same store',
        "INSERT INTO theme_versions (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
         VALUES ({$storeA},'techzonix',1,'draft','{}'," . $db->quote(str_repeat('h', 64)) . ",NOW(),NOW())");

    allows($db, 'a live version for a SECOND theme of the same store',
        "INSERT INTO theme_published_versions (store_id,theme_id,version_id,published_at,created_at,updated_at)
         SELECT {$storeA},'techzonix',id,NOW(),NOW(),NOW()
           FROM theme_versions WHERE store_id = {$storeA} AND theme_id = 'techzonix'");

    allows($db, 'deleting a version that is NOT live',
        "DELETE FROM theme_versions WHERE id = {$vA2}");

    allows($db, 'an append-only publication event',
        "INSERT INTO theme_publication_events
            (store_id,theme_id,version_id,from_version_id,action,payload_hash,actor_id,created_at)
         VALUES ({$storeA},'stylique',{$vA1},NULL,'publish'," . $db->quote(str_repeat('a', 64)) . ",{$userId},NOW())");

    allows($db, 'an event naming a version that has since been pruned',
        "INSERT INTO theme_publication_events
            (store_id,theme_id,version_id,from_version_id,action,payload_hash,created_at)
         VALUES ({$storeA},'stylique',{$vA2},{$vA1},'rollback'," . $db->quote(str_repeat('b', 64)) . ",NOW())");

    echo "\n  --- the store cascade still reaches everything ---\n";

    $before = [
        'versions' => (int) $db->query("SELECT COUNT(*) FROM theme_versions WHERE store_id={$storeA}")->fetchColumn(),
        'pointers' => (int) $db->query("SELECT COUNT(*) FROM theme_published_versions WHERE store_id={$storeA}")->fetchColumn(),
        'events'   => (int) $db->query("SELECT COUNT(*) FROM theme_publication_events WHERE store_id={$storeA}")->fetchColumn(),
    ];

    premise($before['versions'] > 0 && $before['pointers'] > 0 && $before['events'] > 0,
        sprintf('store A has rows to cascade (%d versions, %d pointers, %d events)',
            $before['versions'], $before['pointers'], $before['events']));

    $db->exec("DELETE FROM stores WHERE id = {$storeA}");

    $after = [
        'versions' => (int) $db->query("SELECT COUNT(*) FROM theme_versions WHERE store_id={$storeA}")->fetchColumn(),
        'pointers' => (int) $db->query("SELECT COUNT(*) FROM theme_published_versions WHERE store_id={$storeA}")->fetchColumn(),
        'events'   => (int) $db->query("SELECT COUNT(*) FROM theme_publication_events WHERE store_id={$storeA}")->fetchColumn(),
    ];

    /*
     * EVENTS MUST *SURVIVE*; versions and pointers must go.
     *
     * An earlier version of this script asserted all three cascade away, which passed against the
     * first draft of the migration -- and that draft was wrong. `theme_publication_events` is the
     * append-only audit, so a store deletion erasing it is the defect, not the expectation. It now
     * carries no foreign keys (matching `audit_logs`, which carries none either), and holds no
     * merchant content to require removal: only identifiers, an action verb and a digest.
     *
     * This is the check that would have caught the contradiction, so it is written as the opposite
     * of what it used to say.
     */
    $expectation = ['versions' => 0, 'pointers' => 0, 'events' => $before['events']];

    foreach ($after as $what => $n) {
        $want = $expectation[$what];

        if ($n === $want && $what !== 'events') {
            printf("  ok    %-52s cascaded away (%d -> 0)\n", "deleting the store removed its {$what}", $before[$what]);
            $pass++;
        } elseif ($n === $want) {
            printf("  ok    %-52s SURVIVED the store deletion (%d rows)\n", 'the append-only audit', $n);
            $pass++;
        } elseif ($what === 'events') {
            printf("  FAIL  %-52s %d of %d rows DESTROYED -- an audit log the audited thing can delete\n",
                'the append-only audit', $before[$what] - $n, $before[$what]);
            $fail++;
        } else {
            printf("  FAIL  %-52s %d row(s) left behind\n", "deleting the store removed its {$what}", $n);
            $fail++;
        }
    }

    $otherStoreSurvives = (int) $db->query(
        "SELECT COUNT(*) FROM theme_versions WHERE store_id = {$storeB}")->fetchColumn();

    if ($otherStoreSurvives > 0) {
        printf("  ok    %-52s %d row(s) intact\n", "the OTHER store's versions are untouched", $otherStoreSurvives);
        $pass++;
    } else {
        printf("  FAIL  %-52s the cascade reached another tenant\n", "the OTHER store's versions are untouched");
        $fail++;
    }

    echo "\n  --- the index M3-B adds to the pre-existing table ---\n";

    $idx = (int) $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = " . $db->quote($name) . "
          AND TABLE_NAME = 'theme_customizes'
          AND INDEX_NAME = 'theme_customizes_store_theme_index'")->fetchColumn();

    if ($idx === 2) {
        printf("  ok    %-52s present over 2 columns\n", 'theme_customizes_store_theme_index');
        $pass++;
    } else {
        printf("  FAIL  %-52s expected 2 index columns, found %d\n",
            'theme_customizes_store_theme_index', $idx);
        $fail++;
    }

    $unique = (int) $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = " . $db->quote($name) . "
          AND TABLE_NAME = 'theme_customizes'
          AND INDEX_NAME = 'theme_customizes_store_theme_index'
          AND NON_UNIQUE = 0")->fetchColumn();

    if ($unique === 0) {
        printf("  ok    %-52s NOT unique, as designed\n", 'the new index');
        $pass++;
    } else {
        printf("  FAIL  %-52s it is UNIQUE -- a duplicate in production would fail the migration\n",
            'the new index');
        $fail++;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\n  ABORTED: " . $e->getMessage() . "\n");
    $fail++;
} finally {
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        echo "\n  dropped {$name}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  could not drop {$name}: " . $e->getMessage() . "\n");
    }
}

printf("\n  %d passed, %d failed, %d inconclusive\n", $pass, $fail, $inconclusive);

if ($inconclusive > 0) {
    echo "  An INCONCLUSIVE check proved nothing. It is not a pass.\n";
}

exit($fail === 0 && $inconclusive === 0 ? 0 : 1);
