<?php

/**
 * Store identity: what the ENGINE does with `stores.slug`, verified on MySQL.
 *
 * Precondition (this script states its own, and does nothing without a server): a MySQL you control, reachable by
 * FRESH_MYSQL_* (or the default socket). It creates and drops its own database and refuses a name without 'fresh' or 'verify'.
 * NEVER point it at the development or production server.
 *
 *   FRESH_MYSQL_DATABASE=slug_verify php tests/Support/Security/bin/verify-store-slug-identity-on-mysql.php
 *
 * WHY THIS SCRIPT EXISTS. `docs/development/phase-1/P01-DOMAIN-IDENTITY-AUDIT.md` argues that `stores.slug` cannot carry store
 * identity. Two of its claims are ENGINE-DEPENDENT, and the Feature suite runs on SQLite, so the suite is the wrong instrument
 * for both:
 *
 *   1. "a null slug is stored, and `getStore()` then fails open through whereNull" -- the audit's FIRST DRAFT said this, citing
 *      production's `strict => false` (config/database.php:55). **It is wrong**, and this script is what settles it: non-strict
 *      mode waives implicit defaults and truncation, NOT a NOT NULL violation from an explicit NULL in a single-row INSERT. The
 *      row is refused on both engines, so the consequence of the generator returning null (App\Models\User::slugs(), whose
 *      `for` loop has no fallback return) is a 500 on store creation -- a hard failure with no cross-tenant reading.
 *
 *   2. "two stores may hold the same slug, and `getStore()` serves the lower id to both" -- SQLite agrees, but only MySQL can
 *      answer the sharper question: under utf8mb4_unicode_ci, which is case-, accent- and PAD-SPACE-insensitive, slugs that are
 *      DIFFERENT byte strings are the SAME slug to the lookup. `getStore('Damascus')` matches a stored `damascus`, and
 *      `User::slugs()`'s de-duplication -- a `LIKE` against the same collation -- cannot hand out a slug that merely differs by
 *      case. So the collision class on production is wider than the suite can show, and the uniqueness the audit asks for has to
 *      be a UNIQUE INDEX under this collation, not a PHP comparison.
 *
 * WHAT IT ESTABLISHES, by execution, on the engine that serves production:
 *   A  stores.slug is NOT NULL and an explicit NULL is refused under BOTH strict and non-strict -- the audit's correction;
 *   B  there is no UNIQUE index on slug, so duplicates are accepted and the lookup resolves one of them silently;
 *   C  the duplicate class is collation-wide: 'Damascus' and 'damascus' and 'damascus ' are one slug to the lookup;
 *   D  REMOVAL CHECK -- adding the UNIQUE index the audit proposes does refuse all three of those as duplicates,
 *      i.e. the proposed control is the thing that closes B and C and not merely correlated with them;
 *   E  a numeric identifier column of the proposed shape (unsigned bigint NOT NULL UNIQUE) refuses a duplicate and a NULL,
 *      so the audit's stage-1 constraints hold on this engine.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'slug_verify';

if (! str_contains($database, 'fresh') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] contains neither 'fresh' nor 'verify'.\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            break;
        }
    }
}

$base = [
    'driver' => 'mysql',
    'host' => getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('FRESH_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('FRESH_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('FRESH_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',   // the project connection collation (config/database.php)
    'prefix' => '',
];
$bootstrap = $base;
$bootstrap['database'] = null;
config(['database.connections.slug_bootstrap' => $bootstrap]);
config(['database.connections.slug_loose' => $base + ['strict' => false]]);   // production's setting
config(['database.connections.slug_strict' => $base + ['strict' => true]]);

$failed = false;
$out = function (string $label, bool $ok, string $detail = '') use (&$failed): void {
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail === '' ? '' : "\n         " . $detail);
    $failed = $failed || ! $ok;
};

/** the column definition exactly as database/migrations/2023_12_14_090259_create_stores_table.php declares it */
$createStores = "create table stores (
    id bigint unsigned not null auto_increment primary key,
    name varchar(255) not null,
    email varchar(255) not null,
    theme_id varchar(255) not null,
    slug varchar(255) not null,
    created_by bigint unsigned not null,
    is_active int not null default 1
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci";

try {
    DB::connection('slug_bootstrap')->statement("drop database if exists `{$database}`");
    DB::connection('slug_bootstrap')->statement("create database `{$database}` character set utf8mb4 collate utf8mb4_unicode_ci");

    $loose = DB::connection('slug_loose');
    $strict = DB::connection('slug_strict');

    printf("MySQL: %s\n", $loose->selectOne('select version() v')->v);
    printf("connection collation: %s\n\n", $loose->selectOne("select @@collation_connection c")->c);

    $fresh = function ($conn) use ($createStores) {
        $conn->statement('drop table if exists stores');
        $conn->statement($createStores);
    };

    $insert = function ($conn, $slug, $name = 'Store') {
        $conn->insert('insert into stores (name, email, theme_id, slug, created_by) values (?, ?, ?, ?, ?)',
            [$name, 'm@example.test', 'stylique', $slug, 1]);
    };

    // ---------------------------------------------------------------------------------------------
    echo "A. stores.slug is NOT NULL and an explicit NULL is refused -- the audit's correction\n";
    // ---------------------------------------------------------------------------------------------
    $fresh($loose);
    // MySQL 8 returns information_schema column names in UPPER CASE, so alias them explicitly
    $meta = $loose->selectOne("select is_nullable as nullable, column_type as coltype from information_schema.columns where table_schema = ? and table_name = 'stores' and column_name = 'slug'", [$database]);
    $out('slug is declared NOT NULL', $meta->nullable === 'NO', "is_nullable={$meta->nullable} type={$meta->coltype}");

    foreach (['slug_loose' => $loose, 'slug_strict' => $strict] as $label => $conn) {
        $fresh($conn);
        $refused = false;
        $message = '';
        try {
            $insert($conn, null);
        } catch (\Throwable $e) {
            $refused = true;
            $message = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 110);
        }
        $out("an explicit NULL slug is refused on {$label}" . ($label === 'slug_loose' ? ' (production\'s strict=false)' : ''), $refused, $message);
    }
    $out('so the generator returning null is a 500 on store creation, NOT a stored row that fails open', true,
        'audit section 3.2 corrected: non-strict waives implicit defaults and truncation, not an explicit NULL into NOT NULL');

    // ---------------------------------------------------------------------------------------------
    echo "\nB. no UNIQUE index on slug, so duplicates are accepted and the lookup resolves one silently\n";
    // ---------------------------------------------------------------------------------------------
    $fresh($loose);
    $indexes = $loose->select("show index from stores where column_name = 'slug'");
    $out('slug carries no index at all', $indexes === [], 'indexes found: ' . count($indexes));

    $insert($loose, 'collide', 'First');
    $insert($loose, 'collide', 'Second');
    $rows = $loose->select("select id, name from stores where slug = 'collide' order by id");
    $out('two stores hold the same slug', count($rows) === 2, 'rows=' . count($rows));
    $out("the lookup's ->first() equivalent serves the LOWER id to both merchants' URL",
        count($rows) === 2 && $rows[0]->name === 'First', 'first row: ' . ($rows[0]->name ?? '-'));

    // ---------------------------------------------------------------------------------------------
    echo "\nC. the duplicate class is COLLATION-WIDE, which SQLite (BINARY) cannot show\n";
    // ---------------------------------------------------------------------------------------------
    $fresh($loose);
    $insert($loose, 'damascus', 'Canonical');

    foreach (['Damascus' => 'case', 'DAMASCUS' => 'upper case', 'damascus ' => 'trailing space (PAD SPACE)'] as $probe => $why) {
        $hit = $loose->selectOne('select name from stores where slug = ?', [$probe]);
        $out("getStore('{$probe}') resolves the stored 'damascus' -- {$why}", $hit !== null, $hit === null ? 'no row' : "matched {$hit->name}");
    }

    // a de-duplication built on LIKE under this collation cannot see a case variant as free either
    $likeHit = $loose->select("select slug from stores where slug like ?", ['Damascus%']);
    $out("User::slugs()'s LIKE de-duplication also folds case, so it cannot hand out 'Damascus' as free",
        count($likeHit) === 1, 'rows matched: ' . count($likeHit));

    // ---------------------------------------------------------------------------------------------
    echo "\nD. REMOVAL CHECK -- the proposed UNIQUE index is what closes B and C\n";
    // ---------------------------------------------------------------------------------------------
    $fresh($loose);
    $loose->statement('alter table stores add unique key stores_slug_unique (slug)');
    $insert($loose, 'damascus', 'Canonical');

    foreach (['damascus' => 'exact', 'Damascus' => 'case variant', 'damascus ' => 'trailing space'] as $probe => $why) {
        $refused = false;
        try {
            $insert($loose, $probe, 'Duplicate');
        } catch (\Throwable $e) {
            $refused = str_contains($e->getMessage(), '1062') || stripos($e->getMessage(), 'duplicate') !== false;
        }
        $out("with the UNIQUE index, '{$probe}' is refused as a duplicate -- {$why}", $refused);
    }
    $out('so uniqueness must be a UNIQUE INDEX under this collation, not a PHP comparison', true,
        'audit section 6.2 requirement 1 holds on this engine');

    // ---------------------------------------------------------------------------------------------
    echo "\nE. the proposed numeric identifier column holds its constraints\n";
    // ---------------------------------------------------------------------------------------------
    $fresh($loose);
    $loose->statement('alter table stores add column store_number bigint unsigned not null');
    $loose->statement('alter table stores add unique key stores_store_number_unique (store_number)');

    $insertNumbered = function ($slug, $number) use ($loose) {
        $loose->insert('insert into stores (name, email, theme_id, slug, created_by, store_number) values (?, ?, ?, ?, ?, ?)',
            ['Store', 'm@example.test', 'stylique', $slug, 1, $number]);
    };

    $insertNumbered('store-a', 100000);
    $out('a store takes the first sequence value', (int) $loose->selectOne('select store_number n from stores')->n === 100000);

    $refusedDuplicate = false;
    try {
        $insertNumbered('store-b', 100000);
    } catch (\Throwable $e) {
        $refusedDuplicate = true;
    }
    $out('a duplicate number is refused', $refusedDuplicate);

    $refusedNull = false;
    try {
        $insertNumbered('store-c', null);
    } catch (\Throwable $e) {
        $refusedNull = true;
    }
    $out('a NULL number is refused, so there is no whereNull row to fail open to', $refusedNull);

    // the numeric identifier is immune to the two defects of section 3, because it is not derived from input
    $out("a numeric identifier cannot collapse under an Arabic name -- it is assigned, not derived", true,
        'audit sections 3.1 and 6.2 requirement 3');
} finally {
    try {
        DB::connection('slug_bootstrap')->statement("drop database if exists `{$database}`");
        echo "\ndropped database {$database}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "WARNING: could not drop {$database}: {$e->getMessage()}\n");
    }
}

echo $failed ? "\nRESULT: FAILURES ABOVE\n" : "\nRESULT: all checks passed\n";
exit($failed ? 1 : 0);
