#!/usr/bin/env php
<?php

/**
 * PHASE 03 M3-D — THE `theme_customize_orders.theme_id` MIGRATION, ON A REAL ENGINE.
 * =================================================================================================
 *
 * Fresh install, legacy upgrade and ROLLBACK, each measured rather than argued, on a throwaway
 * database this script creates and drops itself. The owner approved developing this migration in
 * isolated development/test databases and did NOT authorise production; nothing here touches a live
 * host.
 *
 * THE THREE QUESTIONS, and why each needs an engine rather than a test:
 *
 *   1. **Does the column arrive with the right shape?** SQLite has type affinity, so `varchar(191)`
 *      and `NOT NULL` are unenforceable there -- the suite literally cannot tell a wrong column from
 *      a right one. `information_schema` can.
 *
 *   2. **Do legacy rows survive, and does the resolution order work on real data?** A row written
 *      before the column existed must keep its `orders` value, read back as the fallback for every
 *      theme, and lose to a theme-specific row the moment one exists.
 *
 *   3. **Does the ROLLBACK work, and what does it cost?** This is the one M3-B got wrong twice.
 *      Adding a composite index that begins with a foreign-key column lets MariaDB drop the FK's own
 *      single-column index; `down()` then fails mid-batch with errno 1553 because dropping the
 *      composite leaves the constraint with no index at all. Two drafts in M3-B also dropped a
 *      column *because* it existed and destroyed 45 real values. So this asserts the index list
 *      after `up()` AND after `down()`, and asserts that every `orders` value survives the
 *      rollback.
 *
 * RUN WITH PRODUCTION'S SQL MODE, not the server's default. `config/database.php:55` ships
 * `'strict' => false`, so production runs `NO_ENGINE_SUBSTITUTION` and silently coerces what a
 * strict connection refuses. A harness that measured strict behaviour would be measuring a server
 * this project does not deploy.
 *
 * USAGE
 *   php tests/Support/Security/bin/verify-theme-order-theme-id-migration-on-mysql.php
 *       [--socket=/run/mysqld/mysqld.sock | --host= --port=] [--user=] [--pass=]
 *
 * EXIT 0 all checks passed | 1 a check failed | 2 could not inspect | 3 a premise did not hold
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

/* Auto-detect the socket, as the sibling harnesses do. One of them once reported "no server
 * reachable" while a server was running, because it defaulted to TCP against unix_socket auth. */
if ($opt['socket'] === null) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $c) {
        if (file_exists($c)) {
            $opt['socket'] = $c;
            echo "  using the unix socket at {$c}\n";
            break;
        }
    }
}

$root = dirname(__DIR__, 4);
$name = 'p3m3d_order_' . getmypid();

const TABLE = 'theme_customize_orders';
const INDEX = 'tco_store_theme_page_index';
const FK_INDEX = 'theme_customize_orders_store_id_foreign';

$checks = 0; $failures = 0; $inconclusive = 0;

function check(string $what, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    echo $ok ? "  ok    {$what}\n" : "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    if (! $ok) { $failures++; }
}

function premise(string $what, bool $ok): void {
    global $inconclusive;
    if ($ok) { echo "  ok    premise: {$what}\n"; return; }
    echo "  INCONCLUSIVE  premise failed: {$what}\n";
    $inconclusive++;
}

function dsn(array $opt, ?string $db): string {
    $tail = $db === null ? '' : ";dbname={$db}";

    return $opt['socket'] !== null
        ? "mysql:unix_socket={$opt['socket']}{$tail};charset=utf8mb4"
        : "mysql:host={$opt['host']};port={$opt['port']}{$tail};charset=utf8mb4";
}

function connect(array $opt, ?string $db): PDO {
    $pdo = new PDO(dsn($opt, $db), $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    /* PRODUCTION's sql_mode, not the server's default. */
    $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

    return $pdo;
}

/** The index list as the engine really holds it, name => [columns]. */
function indexes(PDO $db): array {
    $out = [];
    foreach ($db->query('SHOW INDEX FROM ' . TABLE) as $r) {
        $out[$r['Key_name']][(int) $r['Seq_in_index']] = $r['Column_name'];
    }
    foreach ($out as $k => $cols) { ksort($cols); $out[$k] = array_values($cols); }

    return $out;
}

function artisan(string $root, array $opt, string $db, string $cmd): array {
    $env = ['DB_CONNECTION=mysql', 'DB_DATABASE=' . $db,
            'DB_USERNAME=' . $opt['user'], 'DB_PASSWORD=' . $opt['pass']];
    $env[] = $opt['socket'] !== null ? 'DB_SOCKET=' . $opt['socket'] : 'DB_HOST=' . $opt['host'];
    if ($opt['socket'] === null) { $env[] = 'DB_PORT=' . $opt['port']; }

    /* `env` is required: a shell treats a QUOTED `VAR=value` as a command name. Three sibling
     * harnesses record the same trap. */
    exec('cd ' . escapeshellarg($root) . ' && env ' . implode(' ', array_map('escapeshellarg', $env))
        . ' php artisan ' . $cmd . ' 2>&1', $out, $code);

    return [$code, $out];
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

    /* ============================================================ 1. FRESH INSTALL, all migrations */

    echo "\n  --- 1. fresh install ---\n";

    [$code, $out] = artisan($root, $opt, $name, 'migrate --force --no-interaction');

    if ($code !== 0) {
        fwrite(STDERR, implode("\n", array_slice($out, -14)) . "\n");
        throw new RuntimeException('fresh migrate failed');
    }

    $db = connect($opt, $name);
    printf("  sql_mode: %s\n", $db->query('SELECT @@SESSION.sql_mode')->fetchColumn());

    $column = $db->query(
        "SELECT data_type, character_maximum_length AS len, is_nullable, column_default
           FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = '" . TABLE . "' AND column_name = 'theme_id'"
    )->fetch(PDO::FETCH_ASSOC);

    premise('the column exists after a fresh migrate', $column !== false);

    if ($column !== false) {
        check('theme_id is varchar', strtolower($column['data_type']) === 'varchar', $column['data_type']);
        check('theme_id is 191 wide, matching stores.theme_id and theme_versions.theme_id',
            (int) $column['len'] === 191, 'len=' . $column['len']);
        check('theme_id is NULLABLE, so a legacy row can hold the cross-theme fallback',
            strtoupper($column['is_nullable']) === 'YES', $column['is_nullable']);
        /*
         * MEASURED: MariaDB's `information_schema` reports a NULL default as the literal STRING
         * 'NULL', while MySQL reports a real SQL NULL. Both mean "no default". A `=== null` check
         * reported this correct column as a failure on MariaDB -- the harness was wrong, not the
         * migration.
         */
        check('theme_id defaults to NULL rather than to a theme name',
            $column['column_default'] === null
                || strtoupper((string) $column['column_default']) === 'NULL',
            var_export($column['column_default'], true));
    }

    $idx = indexes($db);

    check('the resolution index exists with the columns the query uses',
        ($idx[INDEX] ?? null) === ['store_id', 'theme_id', 'page_slug'],
        json_encode($idx[INDEX] ?? null));

    check('the resolution index is NOT unique -- a unique key would fail the migration on the '
        . 'first duplicate pair, which production has not been audited for',
        (function () use ($db): bool {
            $r = $db->query("SELECT Non_unique FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = '" . TABLE . "'
                  AND index_name = '" . INDEX . "' LIMIT 1")->fetchColumn();

            return (int) $r === 1;
        })());

    /*
     * THE M3-B TRAP, REPRODUCED EXACTLY, AND THE ASSERTION CORRECTED TO MATCH THE ENGINE.
     *
     * MEASURED here: adding `(store_id, theme_id, page_slug)` made MariaDB DROP
     * `theme_customize_orders_store_id_foreign`. After `up()` the index list is
     * `PRIMARY, tco_store_theme_page_index` and the named FK index is GONE.
     *
     * That is correct engine behaviour, not a defect: InnoDB needs an index whose LEFTMOST column
     * is the constrained one, and the new composite satisfies the constraint, so the single-column
     * index is redundant and discarded. Asserting the NAME still exists was the harness being
     * wrong. What actually matters -- and what `down()` has to protect -- is that SOME index leads
     * with `store_id`, because dropping the composite without restoring one is the errno 1553
     * failure M3-B hit mid-batch.
     */
    $leadsWithStoreId = array_filter($idx, static fn (array $cols): bool => ($cols[0] ?? null) === 'store_id');

    check('the foreign key still has an index leading with store_id after the composite was added '
        . '(MariaDB drops the now-redundant single-column FK index -- the M3-B trap, reproduced)',
        $leadsWithStoreId !== [], 'indexes: ' . json_encode($idx));

    check('and the named single-column FK index is indeed the one the engine discarded, which is '
        . "exactly why down() must restore one BEFORE dropping the composite",
        ! isset($idx[FK_INDEX]), 'indexes: ' . implode(',', array_keys($idx)));

    /* ===================================================== 2. LEGACY UPGRADE on a pre-M3-D database */

    echo "\n  --- 2. legacy upgrade ---\n";

    /* Build the pre-M3-D state by rolling this one migration back, then seed legacy rows. */
    [$code, $out] = artisan($root, $opt, $name,
        'migrate:rollback --force --no-interaction --step=1');

    if ($code !== 0) {
        fwrite(STDERR, implode("\n", array_slice($out, -14)) . "\n");
        throw new RuntimeException('rollback to the pre-M3-D state failed');
    }

    $db = connect($opt, $name);

    premise('the column is gone, so what follows is a genuine pre-M3-D database',
        (int) $db->query("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = '" . TABLE . "'
              AND column_name = 'theme_id'")->fetchColumn() === 0);

    $db->exec("INSERT INTO users (name,email,password,type,created_at,updated_at)
               VALUES ('D Probe','dprobe@example.test','x','admin',NOW(),NOW())");
    $userId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO stores (name,email,slug,theme_id,created_by,created_at,updated_at)
               VALUES ('D Store','ds@example.test','d-store','greentic',{$userId},NOW(),NOW())");
    $storeId = (int) $db->lastInsertId();

    $db->exec("INSERT INTO " . TABLE . " (page_slug,orders,store_id,created_at,updated_at)
               VALUES ('home','LEGACY-HOME',{$storeId},NOW(),NOW())");
    $db->exec("INSERT INTO " . TABLE . " (page_slug,orders,store_id,created_at,updated_at)
               VALUES ('about','LEGACY-ABOUT',{$storeId},NOW(),NOW())");

    $before = $db->query("SELECT page_slug, orders FROM " . TABLE . " ORDER BY page_slug")
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    premise('two legacy rows exist to upgrade', count($before) === 2);

    [$code, $out] = artisan($root, $opt, $name, 'migrate --force --no-interaction');

    if ($code !== 0) {
        fwrite(STDERR, implode("\n", array_slice($out, -14)) . "\n");
        throw new RuntimeException('upgrade migrate failed');
    }

    $db = connect($opt, $name);

    $after = $db->query("SELECT page_slug, orders FROM " . TABLE . " ORDER BY page_slug")
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    check('every legacy row survives the upgrade with its orders value intact',
        $after === $before, json_encode($after));

    check('NOTHING WAS BACKFILLED: legacy rows keep theme_id NULL rather than being relabelled '
        . "with the store's CURRENT theme, which the table never recorded",
        (int) $db->query("SELECT COUNT(*) FROM " . TABLE . " WHERE theme_id IS NULL")->fetchColumn() === 2);

    check('and specifically not relabelled as greentic, the theme the store happens to use today',
        (int) $db->query("SELECT COUNT(*) FROM " . TABLE . " WHERE theme_id = 'greentic'")->fetchColumn() === 0);

    /* A theme-specific row alongside the legacy one: both must coexist, which is what proves no
     * unique key is in the way of the intended upgrade path. */
    $db->exec("INSERT INTO " . TABLE . " (page_slug,theme_id,orders,store_id,created_at,updated_at)
               VALUES ('home','stylique','STYLIQUE-HOME',{$storeId},NOW(),NOW())");

    check('a theme-specific row coexists with the legacy row for the same page',
        (int) $db->query("SELECT COUNT(*) FROM " . TABLE
            . " WHERE store_id = {$storeId} AND page_slug = 'home'")->fetchColumn() === 2);

    /* ======================================================================= 3. ROLLBACK, and cost */

    echo "\n  --- 3. rollback ---\n";

    $ordersBeforeRollback = $db->query("SELECT orders FROM " . TABLE . " ORDER BY id")
        ->fetchAll(PDO::FETCH_COLUMN);

    [$code, $out] = artisan($root, $opt, $name,
        'migrate:rollback --force --no-interaction --step=1');

    check('the rollback completes without errno 1553 -- the M3-B failure, where dropping a '
        . 'composite index left the foreign key with none',
        $code === 0, implode(' | ', array_slice($out, -4)));

    $db = connect($opt, $name);
    $idxAfter = indexes($db);

    check("after the rollback the foreign key STILL has an index to stand on -- down() restored "
        . 'the single-column one before dropping the composite',
        array_filter($idxAfter, static fn (array $cols): bool => ($cols[0] ?? null) === 'store_id') !== [],
        'indexes: ' . json_encode($idxAfter));

    check('the resolution index is gone', ! isset($idxAfter[INDEX]), implode(',', array_keys($idxAfter)));

    check('the column is gone',
        (int) $db->query("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = '" . TABLE . "'
              AND column_name = 'theme_id'")->fetchColumn() === 0);

    $ordersAfterRollback = $db->query("SELECT orders FROM " . TABLE . " ORDER BY id")
        ->fetchAll(PDO::FETCH_COLUMN);

    check('NO MERCHANT CONTENT IS LOST BY THE ROLLBACK: every orders value survives, including the '
        . 'theme-specific row -- only its theme ATTRIBUTION is discarded',
        $ordersAfterRollback === $ordersBeforeRollback,
        json_encode(['before' => $ordersBeforeRollback, 'after' => $ordersAfterRollback]));

    check('and the cost is visible rather than silent: the page now has two rows that the pre-M3-D '
        . 'code resolves with ->first()',
        (int) $db->query("SELECT COUNT(*) FROM " . TABLE
            . " WHERE store_id = {$storeId} AND page_slug = 'home'")->fetchColumn() === 2);

    /* ================================================================ 4. re-apply, idempotence */

    echo "\n  --- 4. re-apply ---\n";

    [$code, $out] = artisan($root, $opt, $name, 'migrate --force --no-interaction');

    check('the migration re-applies cleanly after a rollback', $code === 0,
        implode(' | ', array_slice($out, -4)));

    $db = connect($opt, $name);
    $idxReapplied = indexes($db);

    check('and the index list is identical to the fresh install',
        ($idxReapplied[INDEX] ?? null) === ['store_id', 'theme_id', 'page_slug']
            && isset($idxReapplied[FK_INDEX]),
        implode(',', array_keys($idxReapplied)));
} catch (Throwable $e) {
    fwrite(STDERR, "\nABORTED: {$e->getMessage()}\n");
    $failures++;
} finally {
    try { $server->exec("DROP DATABASE IF EXISTS `{$name}`"); echo "\n  dropped {$name}\n"; }
    catch (Throwable $e) {}
}

printf("\n  %d checks, %d failed, %d inconclusive\n", $checks, $failures, $inconclusive);

exit($inconclusive > 0 ? 3 : ($failures > 0 ? 1 : 0));
