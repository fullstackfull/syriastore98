#!/usr/bin/env php
<?php

/**
 * PHASE 03 M3-C — CONCURRENT DRAFT SAVES, AND NON-STRICT COERCION, ON A REAL ENGINE.
 * =================================================================================================
 *
 * TWO THINGS THE PHPUnit SUITE STRUCTURALLY CANNOT TEST
 * -------------------------------------------------------------------------------------------------
 *   1. **TWO CONNECTIONS RACING.** The Feature suite is one process on SQLite `:memory:`, where a
 *      second connection sees a different database entirely. `ThemeConfigurationService::saveDraft()`
 *      allocates `version_no` as `1 + max(version_no)` under a `lockForUpdate()`, and the only way
 *      to know the lock does its job is to run two of them at once against InnoDB.
 *   2. **PRODUCTION'S `sql_mode`.** `config/database.php:55` sets `'strict' => false`, which Laravel
 *      implements as `SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'` -- MySQL COERCES a bad value
 *      instead of refusing it. SQLite refuses some of the same writes, which is backwards from
 *      production and is how P1-INV-01 stayed hidden. Measured for M3-B:
 *
 *          single-row INSERT ... VALUES (NULL) into NOT NULL   refused in BOTH modes (1048)
 *          MULTI-ROW insert containing a NULL                  non-strict ACCEPTS, NULL -> 0
 *          200 chars into varchar(191)                         non-strict ACCEPTS, truncated
 *
 *      The truncation one matters here specifically: `theme_id` is merchant-writable free text
 *      (`makeActiveTheme()` writes `$request->theme_id` behind nothing but `isset()`), `stores`
 *      holds it as varchar(255) and the version tables as varchar(191).
 *
 * `tests/Support/TestDatabaseGuard.php` ABORTS any PHPUnit run whose connection is not sqlite,
 * because `RefreshDatabase` would `migrate:fresh` and drop every table in the target database. That
 * guard is right and must not be weakened, so this evidence lives in a script that creates and drops
 * its OWN database -- the same shape as the ~60 sibling `verify-*-on-mysql.php` files.
 *
 * USAGE
 *   php tests/Support/Security/bin/verify-theme-version-concurrency-on-mysql.php \
 *       [--socket=/var/run/mysqld/mysqld.sock | --host= --port=] [--user=] [--pass=]
 */

$opt = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '', 'socket' => null];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "usage: php " . basename(__FILE__) . " [--socket=|--host= --port=] [--user=] [--pass=]\n");
    exit(2);
}

/*
 * AUTO-DETECT THE UNIX SOCKET WHEN NONE WAS NAMED, exactly as the sibling
 * `verify-theme-cache-flush-after-commit-on-mysql.php` does.
 *
 * Without this the default is TCP to 127.0.0.1, and on a server using `unix_socket` auth that
 * answers "Access denied for user 'root'@'localhost'" -- which this script then reports as
 * "No MySQL/MariaDB server reachable". MEASURED after a container restart: the server WAS running
 * and the harness said there was none. A harness that reports the absence of a server when a
 * server is present is the false reading this whole package has been chasing, one layer out.
 */
if ($opt['socket'] === null) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $opt['socket'] = $candidate;
            echo "  using the unix socket at {$candidate}\n";
            break;
        }
    }
}

$root = dirname(__DIR__, 4);
$name = 'p3m3c_concurrency_' . getmypid();

function dsn(array $opt, ?string $db): string
{
    $tail = $db === null ? '' : ";dbname={$db}";

    return $opt['socket'] !== null
        ? "mysql:unix_socket={$opt['socket']}{$tail};charset=utf8mb4"
        : "mysql:host={$opt['host']};port={$opt['port']}{$tail};charset=utf8mb4";
}

/** A connection in PRODUCTION's sql_mode, not the server's default. */
function connect(array $opt, string $db): PDO
{
    $pdo = new PDO(dsn($opt, $db), $opt['user'], $opt['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");

    return $pdo;
}

try {
    $server = new PDO(dsn($opt, null), $opt['user'], $opt['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "No MySQL/MariaDB server reachable: " . $e->getMessage() . "\n");
    exit(2);
}

$pass = 0;
$fail = 0;
$inconclusive = 0;

function ok(string $what, string $detail = ''): void
{
    global $pass;
    printf("  ok    %-56s %s\n", $what, $detail);
    $pass++;
}

function bad(string $what, string $detail = ''): void
{
    global $fail;
    printf("  FAIL  %-56s %s\n", $what, $detail);
    $fail++;
}

function premise(bool $cond, string $what): bool
{
    global $inconclusive;

    if (! $cond) {
        printf("  ??    %-56s PREMISE FAILED -- nothing was proved\n", $what);
        $inconclusive++;
    }

    return $cond;
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

    echo "  migrating {$name}\n";

    /* `env` is required: a shell treats a QUOTED `VAR=value` as a command name. The sibling
     * harness records the same trap at verify-gate-f-migrations-on-mysql.php:124. */
    exec('cd ' . escapeshellarg($root) . ' && env ' . implode(' ', array_map('escapeshellarg', $env))
        . ' php artisan migrate --force --no-interaction 2>&1', $out, $code);

    if ($code !== 0) {
        fwrite(STDERR, implode("\n", array_slice($out, -12)) . "\n");
        throw new RuntimeException('migrate failed');
    }

    $db = connect($opt, $name);
    printf("  sql_mode: %s\n\n", $db->query('SELECT @@SESSION.sql_mode')->fetchColumn());

    $db->exec("INSERT INTO users (name,email,password,type,created_at,updated_at)
               VALUES ('C Probe','cprobe@example.test','x','admin',NOW(),NOW())");
    $userId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO stores (name,email,slug,theme_id,created_by,created_at,updated_at)
               VALUES ('C Store','cs@example.test','c-store','stylique',{$userId},NOW(),NOW())");
    $storeId = (int) $db->lastInsertId();

    premise($storeId > 0, 'a store exists to version');

    /* ------------------------------------------------------------- 1. the version_no race */

    echo "  --- concurrent draft saves ---\n";

    /*
     * Two connections, each doing exactly what saveDraft() does: SELECT MAX(version_no) ... FOR
     * UPDATE inside a transaction, then INSERT max+1. Connection B's SELECT must BLOCK until A
     * commits, so B reads A's new maximum and inserts the next number instead of colliding.
     */
    $a = connect($opt, $name);
    $b = connect($opt, $name);
    $b->exec('SET SESSION innodb_lock_wait_timeout = 5');

    $insert = static function (PDO $c, int $store, int $no, string $h): void {
        $c->exec("INSERT INTO theme_versions
            (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
            VALUES ({$store},'stylique',{$no},'draft','{}','" . str_repeat($h, 64) . "',NOW(),NOW())");
    };

    $a->beginTransaction();
    $maxA = (int) $a->query("SELECT COALESCE(MAX(version_no),0) FROM theme_versions
                             WHERE store_id={$storeId} AND theme_id='stylique' FOR UPDATE")->fetchColumn();
    $insert($a, $storeId, $maxA + 1, 'a');

    /* B now tries the same read. With the lock held it must WAIT; without it, it reads the stale
     * maximum and both insert the same version_no. */
    $b->beginTransaction();
    $blocked = false;

    try {
        $b->query("SELECT COALESCE(MAX(version_no),0) FROM theme_versions
                   WHERE store_id={$storeId} AND theme_id='stylique' FOR UPDATE")->fetchColumn();
    } catch (PDOException $e) {
        $blocked = str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    }

    if ($blocked) {
        ok('a concurrent allocator BLOCKS on the row lock', '(lock wait timeout, as designed)');
    } else {
        bad('a concurrent allocator BLOCKS on the row lock',
            'it read through the lock, so two saves can claim the same version_no');
    }

    $b->rollBack();
    $a->commit();

    /* And the engine refuses a collision even without the lock -- the backstop. */
    try {
        $insert($a, $storeId, $maxA + 1, 'b');
        bad('a duplicate version_no is refused by the unique key', 'it was ACCEPTED');
    } catch (PDOException $e) {
        str_contains($e->getMessage(), 'tv_store_theme_version_unique')
            ? ok('a duplicate version_no is refused by the unique key', '(1062)')
            : bad('a duplicate version_no is refused by the unique key',
                'refused for another reason: ' . substr($e->getMessage(), 0, 70));
    }

    /* ------------------------------------------------ 2. non-strict coercion on these tables */

    echo "\n  --- production's non-strict sql_mode ---\n";

    /* theme_id truncation: stores holds 255, the version tables hold 191. */
    $long = str_repeat('z', 200);
    $db->exec("UPDATE stores SET theme_id = '{$long}' WHERE id = {$storeId}");
    $storedWide = (string) $db->query("SELECT theme_id FROM stores WHERE id={$storeId}")->fetchColumn();

    try {
        $db->exec("INSERT INTO theme_versions
            (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
            VALUES ({$storeId},'{$long}',900,'draft','{}','" . str_repeat('c', 64) . "',NOW(),NOW())");

        $storedNarrow = (string) $db->query("SELECT theme_id FROM theme_versions
                                             WHERE store_id={$storeId} AND version_no=900")->fetchColumn();

        if (strlen($storedWide) === 200 && strlen($storedNarrow) === 191) {
            ok('an over-length theme_id TRUNCATES silently here',
                "stores kept 200 chars, theme_versions kept 191 -- they now disagree");
        } else {
            bad('an over-length theme_id TRUNCATES silently here',
                "stores={$storedWide} chars, theme_versions=" . strlen($storedNarrow));
        }
    } catch (PDOException $e) {
        bad('an over-length theme_id TRUNCATES silently here',
            'it was REFUSED (' . substr($e->getMessage(), 0, 60) . ') -- is this really non-strict?');
    }

    $db->exec("UPDATE stores SET theme_id = 'stylique' WHERE id = {$storeId}");

    /*
     * THE POINT OF THE ABOVE. The truncation is not itself a vulnerability -- it is why
     * ThemeConfigurationService validates `theme_id` against ThemeFieldCatalog::themes() rather
     * than trusting the column. A 200-character theme is not a shipped theme, so the service
     * refuses it before any of this can happen. This asserts that.
     */
    $declared = [];
    foreach (glob($root . '/themes/*/theme_json', GLOB_ONLYDIR) as $d) {
        $declared[] = basename(dirname($d));
    }

    premise($declared !== [], 'the installation ships at least one theme');

    in_array($long, $declared, true)
        ? bad('a 200-character theme is not a shipped theme', 'it somehow is')
        : ok('a 200-character theme is not a shipped theme',
            'so the service refuses it before the column can truncate');

    /* `status` is a short string rather than an enum, because a non-strict enum coerces an unknown
     * value to '' instead of refusing it. Demonstrated rather than asserted from the manual. */
    $db->exec("INSERT INTO theme_versions
        (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
        VALUES ({$storeId},'stylique',901,'bogus','{}','" . str_repeat('d', 64) . "',NOW(),NOW())");

    $stored = (string) $db->query("SELECT status FROM theme_versions
                                   WHERE store_id={$storeId} AND version_no=901")->fetchColumn();

    $stored === 'bogus'
        ? ok('status accepts any short string, as declared',
            "an ENUM would have coerced it to '' here, which is why it is not one")
        : bad('status accepts any short string, as declared', "it became '{$stored}'");

    /*
     * AND IT TRUNCATES, which this harness found by accident: a 17-character probe value came back
     * as 16. `status` is varchar(16) -- enough for 'draft' (5), 'published' (9) and 'archived' (8),
     * but a longer value is SILENTLY SHORTENED rather than refused, because production is
     * non-strict. The column therefore cannot be the guard on the status set, which is why
     * `App\Models\ThemeVersion` validates it on save against ThemeVersion::STATUSES.
     */
    $db->exec("INSERT INTO theme_versions
        (store_id,theme_id,version_no,status,payload,payload_hash,created_at,updated_at)
        VALUES ({$storeId},'stylique',902,'a-seventeen-char!','{}','" . str_repeat('e', 64) . "',NOW(),NOW())");

    $truncated = (string) $db->query("SELECT status FROM theme_versions
                                      WHERE store_id={$storeId} AND version_no=902")->fetchColumn();

    strlen($truncated) === 16
        ? ok('an over-length status TRUNCATES rather than being refused',
            "17 chars became 16 ('{$truncated}') -- the column is not the guard")
        : bad('an over-length status TRUNCATES rather than being refused',
            'it came back ' . strlen($truncated) . ' chars');

    /* ------------------------------------------- 3. the projection is the least-constrained hop */

    echo "\n  --- the projection theme_customizes, for contrast ---\n";

    $db->exec("INSERT INTO theme_customizes (name,value,store_id,theme_id,created_at,updated_at)
               VALUES ('blog_title','owned by nobody',NULL,NULL,NOW(),NOW())");

    $orphans = (int) $db->query('SELECT COUNT(*) FROM theme_customizes
                                 WHERE store_id IS NULL OR theme_id IS NULL')->fetchColumn();

    $orphans === 1
        ? ok('theme_customizes ACCEPTS a row owned by nobody',
            'store_id and theme_id are NULLABLE -- the handler is the only guard on this hop')
        : bad('theme_customizes ACCEPTS a row owned by nobody', "found {$orphans}");
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

exit($fail === 0 && $inconclusive === 0 ? 0 : 1);
