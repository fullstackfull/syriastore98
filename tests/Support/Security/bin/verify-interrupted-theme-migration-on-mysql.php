#!/usr/bin/env php
<?php

/**
 * PHASE 03 M3-E — AN INTERRUPTED MIGRATION MUST NOT BECOME A PERMANENT, SILENT SCHEMA GAP.
 * =================================================================================================
 *
 * THE DEFECT, measured before it was fixed. The three M3-B theme migrations opened with a blind
 * `if (Schema::hasTable(...)) return;`. MySQL DDL is not transactional, so a migration that fails
 * PART WAY leaves its `CREATE TABLE` committed and its constraints missing, and is not recorded as
 * applied. The next deployment then met the early return:
 *
 *     2026_11_13_000200_create_theme_published_versions_table ........ 0.54ms DONE
 *     exit 0
 *
 *     SELECT constraint_name FROM information_schema.referential_constraints ...
 *     -> tpv_store_id_foreign            (and nothing else)
 *
 * **`tpv_version_identity_foreign` was permanently absent while `migrate:status` showed green** --
 * the constraint `2026_11_13_000100` calls *"THE KEY THAT MAKES CROSS-TENANT PUBLICATION
 * IMPOSSIBLE, not merely unlikely"*, and the engine-level backstop for the moment a hand-written
 * `where('store_id', ...)` is forgotten. Half a millisecond of "DONE" removed it for good.
 *
 * `Schema::hasTable()` answers "does a table of this name exist". The migration needed "is this the
 * table I would have created". Conflating those is the same inverted-absence mistake this project
 * keeps recording, and it is why `App\Support\Database\ThemeSchemaGuard` refuses instead.
 *
 * WHAT THIS SCRIPT PROVES, in both directions:
 *   1. a fresh install still succeeds and carries every constraint;
 *   2. a COMPLETE table is skipped, not refused -- the guard must not break idempotence;
 *   3. a PARTIAL table is REFUSED, by name, with a non-zero exit;
 *   4. and the refused migration is NOT recorded, so it can be repaired and re-run.
 *
 * Run with production's `sql_mode`. Needs a throwaway MySQL/MariaDB; it creates and drops its own.
 *
 *     php tests/Support/Security/bin/verify-interrupted-theme-migration-on-mysql.php
 *
 * EXIT 0 all passed | 1 a check failed | 2 could not inspect | 3 a premise did not hold
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

if ($opt['socket'] === null) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $c) {
        if (file_exists($c)) { $opt['socket'] = $c; echo "  using the unix socket at {$c}\n"; break; }
    }
}

$root = dirname(__DIR__, 4);
$name = 'p3m3e_interrupted_' . getmypid();

const TABLE = 'theme_published_versions';
const MIGRATION = '2026_11_13_000200_create_theme_published_versions_table';
const CRITICAL_FK = 'tpv_version_identity_foreign';

$checks = 0; $failures = 0; $inconclusive = 0;

function check(string $what, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    echo $ok ? "  ok    {$what}\n" : "  FAIL  {$what}" . ($detail !== '' ? "\n          -- {$detail}" : '') . "\n";
    if (! $ok) { $failures++; }
}

function premise(string $what, bool $ok, string $detail = ''): void {
    global $inconclusive;
    if ($ok) { echo "  ok    premise: {$what}\n"; return; }
    echo "  INCONCLUSIVE  premise failed: {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    $inconclusive++;
}

function connect(array $opt, ?string $db): PDO {
    $tail = $db === null ? '' : ";dbname={$db}";
    $dsn = $opt['socket'] !== null
        ? "mysql:unix_socket={$opt['socket']}{$tail};charset=utf8mb4"
        : "mysql:host={$opt['host']};port={$opt['port']}{$tail};charset=utf8mb4";
    $pdo = new PDO($dsn, $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");   // production's, not the server's

    return $pdo;
}

function artisan(string $root, array $opt, string $db, string $cmd): array {
    $env = ['DB_CONNECTION=mysql', 'DB_DATABASE=' . $db,
            'DB_USERNAME=' . $opt['user'], 'DB_PASSWORD=' . $opt['pass']];
    $env[] = $opt['socket'] !== null ? 'DB_SOCKET=' . $opt['socket'] : 'DB_HOST=' . $opt['host'];
    if ($opt['socket'] === null) { $env[] = 'DB_PORT=' . $opt['port']; }

    /* `env` is required: a shell treats a QUOTED `VAR=value` as a command name. Five sibling
     * harnesses record the same trap. */
    exec('cd ' . escapeshellarg($root) . ' && env ' . implode(' ', array_map('escapeshellarg', $env))
        . ' php artisan ' . $cmd . ' 2>&1', $out, $code);

    return [$code, $out];
}

function foreignKeys(PDO $db): array {
    return array_column($db->query(
        "SELECT constraint_name FROM information_schema.referential_constraints
          WHERE constraint_schema = DATABASE() AND table_name = '" . TABLE . "'"
    )->fetchAll(PDO::FETCH_ASSOC), 'constraint_name');
}

function recorded(PDO $db): int {
    $st = $db->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
    $st->execute([MIGRATION]);

    return (int) $st->fetchColumn();
}

try {
    $server = connect($opt, null);
} catch (Throwable $e) {
    fwrite(STDERR, "No MySQL/MariaDB server reachable: {$e->getMessage()}\n");
    exit(2);
}

try {
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    $server->exec("CREATE DATABASE `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    /* ========================================================= 1. a fresh install still works */

    echo "\n  --- 1. fresh install ---\n";

    [$code] = artisan($root, $opt, $name, 'migrate --force --no-interaction');

    premise('a fresh migrate succeeds', $code === 0);

    $db = connect($opt, $name);
    printf("  sql_mode: %s\n", $db->query('SELECT @@SESSION.sql_mode')->fetchColumn());

    $fks = foreignKeys($db);

    check('the fresh install carries the cross-tenant composite foreign key',
        in_array(CRITICAL_FK, $fks, true), 'found: ' . implode(', ', $fks));

    /* ====================================== 2. a COMPLETE table is skipped, not refused */

    echo "\n  --- 2. idempotence: a complete table is skipped ---\n";

    $db->exec("DELETE FROM migrations WHERE migration = '" . MIGRATION . "'");

    [$code, $out] = artisan($root, $opt, $name, 'migrate --force --no-interaction');

    check('a COMPLETE table is skipped rather than refused -- the guard must not break a re-run',
        $code === 0, implode(' | ', array_slice($out, -4)));

    /* ============================================ 3. a PARTIAL table is REFUSED, by name */

    echo "\n  --- 3. the interrupted migration ---\n";

    /*
     * Build exactly what an interrupted `2026_11_13_000200` leaves: the table committed, its unique
     * key and store foreign key present, and the COMPOSITE foreign key absent -- because that is
     * the statement the migration fails on. The migrations row is removed too, because a failed
     * migration is never recorded.
     */
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    $db->exec('DROP TABLE IF EXISTS ' . TABLE);
    $db->exec('CREATE TABLE ' . TABLE . ' (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        store_id BIGINT UNSIGNED NOT NULL, theme_id VARCHAR(191) NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        published_at TIMESTAMP NULL, published_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
        UNIQUE KEY tpv_store_theme_unique (store_id, theme_id),
        KEY tpv_identity_index (store_id, theme_id, version_id),
        CONSTRAINT tpv_store_id_foreign FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
    ) ENGINE=InnoDB');
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    $db->exec("DELETE FROM migrations WHERE migration = '" . MIGRATION . "'");

    premise('the partial table is in place and the migration is unrecorded',
        ! in_array(CRITICAL_FK, foreignKeys($db), true) && recorded($db) === 0);

    [$code, $out] = artisan($root, $opt, $name, 'migrate --force --no-interaction');
    $output = implode("\n", $out);

    check('THE MIGRATION REFUSES a table that exists but is not the one it would have created',
        $code !== 0, "exit {$code}; a zero exit here is the defect: half a millisecond of DONE");

    check('and it NAMES the missing constraint rather than failing opaquely',
        str_contains($output, CRITICAL_FK),
        'the message did not mention ' . CRITICAL_FK);

    check('the refused migration is NOT recorded, so the gap can still be repaired',
        recorded($db) === 0,
        'it was recorded as applied, which makes the missing constraint permanent -- exactly the '
        . 'measured defect');

    $db2 = connect($opt, $name);

    check('and the composite foreign key is still absent, so nothing was silently half-applied',
        ! in_array(CRITICAL_FK, foreignKeys($db2), true));
} catch (Throwable $e) {
    fwrite(STDERR, "\nABORTED: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    $failures++;
} finally {
    try { $server->exec("DROP DATABASE IF EXISTS `{$name}`"); echo "\n  dropped {$name}\n"; }
    catch (Throwable $e) {}
}

printf("\n  %d checks, %d failed, %d inconclusive\n", $checks, $failures, $inconclusive);

exit($inconclusive > 0 ? 3 : ($failures > 0 ? 1 : 0));
