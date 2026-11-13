#!/usr/bin/env php
<?php

/**
 * PHASE 03 M3-B — IS THIS DATABASE SAFE FOR A UNIQUE KEY ON `theme_customizes`? READ-ONLY.
 * =================================================================================================
 *
 * WHY A REPORTER AND NOT A MIGRATION
 * -------------------------------------------------------------------------------------------------
 * `(store_id, theme_id, name)` is what the application already treats as a key:
 * `ThemeCustomizeController:132` uses `updateOrCreate` on exactly those three columns, and
 * `Api/DashboardController:9032` uses `updateOrInsert` on them. But there is NO unique index, and
 * `2026_11_13_000400_add_store_theme_index_to_theme_customizes_table.php` deliberately adds a
 * NON-unique one.
 *
 * The reason is that a unique index meets the first duplicate pair and FAILS THE MIGRATION for that
 * deployment, mid-run, on the table every storefront page read depends on. Production cannot be
 * measured from the development environment and the owner's standing restrictions forbid reaching
 * it, so a migration whose success depends on an unmeasured property of live data is not a safe
 * migration — however likely that property is.
 *
 * This script answers the question the safe way round: point it at a database and it reports,
 * READ-ONLY, whether a unique key would succeed there. It issues no DDL and no writes of any kind.
 *
 * WHY THE ANSWER MATTERS, MEASURED
 * -------------------------------------------------------------------------------------------------
 * A duplicate is not cosmetic. The storefront reader and the merchant's writer disagree about which
 * row of a duplicate group wins:
 *
 *   READ  `app/Helper/helper.php:3576`  ...->pluck('value', 'name')   -- later row overwrites earlier
 *   WRITE `ThemeCustomizeController:132` ->updateOrCreate(...)        -- Eloquent takes the FIRST match
 *
 * So with a duplicate present a merchant's save reports success, updates one row, and the storefront
 * keeps rendering the other. The save looks like it worked. Nothing in the product creates a
 * duplicate today, but nothing prevents one either, and a partially-applied import or a restored
 * backup would.
 *
 * USAGE
 *   php tests/Support/Security/bin/report-theme-customize-duplicates.php \
 *       --database=NAME [--socket=/var/run/mysqld/mysqld.sock | --host= --port=] [--user=] [--pass=]
 *
 *   # or against a SQLite file, including the development fixture:
 *   php tests/Support/Security/bin/report-theme-customize-duplicates.php --sqlite=database/p2dev.sqlite
 *
 * EXIT CODES
 *   0  no duplicates: a unique key would succeed here
 *   1  duplicates found: a unique key would FAIL here, and they are listed
 *   2  could not inspect (no server, no such table, bad arguments)
 */

$opt = [
    'host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '',
    'socket' => null, 'database' => null, 'sqlite' => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "usage: php " . basename(__FILE__)
        . " --database=NAME [--socket=|--host= --port=] [--user=] [--pass=]\n"
        . "   or: php " . basename(__FILE__) . " --sqlite=path/to.sqlite\n");
    exit(2);
}

if ($opt['sqlite'] === null && $opt['database'] === null) {
    fwrite(STDERR, "Name a database: --database=NAME (MySQL) or --sqlite=PATH.\n");
    exit(2);
}

try {
    if ($opt['sqlite'] !== null) {
        $path = $opt['sqlite'][0] === '/' ? $opt['sqlite'] : dirname(__DIR__, 4) . '/' . $opt['sqlite'];

        if (! is_file($path)) {
            fwrite(STDERR, "No such SQLite file: {$path}\n");
            exit(2);
        }

        $db     = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $target = 'sqlite:' . $path;
    } else {
        $dsn = $opt['socket'] !== null
            ? "mysql:unix_socket={$opt['socket']};dbname={$opt['database']};charset=utf8mb4"
            : "mysql:host={$opt['host']};port={$opt['port']};dbname={$opt['database']};charset=utf8mb4";

        $db     = new PDO($dsn, $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $target = 'mysql:' . $opt['database'];
    }
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect: " . $e->getMessage() . "\n");
    exit(2);
}

echo "  target: {$target}\n";

/* ------------------------------------------------------------------ premise: the table is there */
try {
    $total = (int) $db->query('SELECT COUNT(*) FROM theme_customizes')->fetchColumn();
} catch (PDOException $e) {
    fwrite(STDERR, "  theme_customizes is not readable here: " . $e->getMessage() . "\n");
    exit(2);
}

printf("  rows: %d\n", $total);

if ($total === 0) {
    /*
     * An empty table has no duplicates, but reporting "clean" would be misleading: it says nothing
     * about the database anyone actually cares about. Stated rather than silently exiting 0.
     */
    echo "\n  The table is EMPTY. A unique key would succeed, but this tells you nothing about a\n"
       . "  populated database. Point this at the one whose migration you are deciding.\n";
    exit(0);
}

/* ---------------------------------------------------------------------------- the real question */
$rows = $db->query(
    'SELECT store_id, theme_id, name, COUNT(*) AS c, MIN(id) AS first_id, MAX(id) AS last_id
       FROM theme_customizes
      GROUP BY store_id, theme_id, name
     HAVING COUNT(*) > 1
      ORDER BY c DESC, store_id, theme_id, name'
)->fetchAll(PDO::FETCH_ASSOC);

$pairs = (int) $db->query(
    'SELECT COUNT(*) FROM (SELECT 1 FROM theme_customizes GROUP BY store_id, theme_id) t'
)->fetchColumn();

$names = (int) $db->query('SELECT COUNT(DISTINCT name) FROM theme_customizes')->fetchColumn();

printf("  distinct (store, theme) pairs: %d\n", $pairs);
printf("  distinct setting names:        %d\n", $names);

/*
 * NULL is its own question. `store_id` and `theme_id` are both NULLABLE on this table, and MySQL
 * treats NULLs as DISTINCT in a unique index — so two rows with a NULL store_id and the same name
 * would NOT collide, and a unique key would accept them while the application still cannot tell
 * them apart. Counted separately because the GROUP BY above cannot express it.
 */
$nullish = (int) $db->query(
    'SELECT COUNT(*) FROM theme_customizes WHERE store_id IS NULL OR theme_id IS NULL'
)->fetchColumn();

if ($nullish > 0) {
    printf("\n  NOTE: %d row(s) have a NULL store_id or theme_id. MySQL treats NULLs as DISTINCT in a\n"
         . "  unique index, so those rows would NOT collide even when they are indistinguishable to\n"
         . "  the application. A unique key does not fix them; they need an owner decision.\n", $nullish);
}

if ($rows === []) {
    echo "\n  NO DUPLICATES on (store_id, theme_id, name).\n";
    echo "  A UNIQUE key would succeed on THIS database.\n";
    echo "\n  That is not an instruction to add one: the migration is deliberately non-unique, and\n";
    echo "  promoting a unique constraint is a separate owner decision that needs this result from\n";
    echo "  the database it would actually run against.\n";
    exit($nullish > 0 ? 1 : 0);
}

printf("\n  %d DUPLICATE GROUP(S) — a UNIQUE key would FAIL on this database:\n\n", count($rows));
printf("    %-10s %-14s %-40s %5s  %s\n", 'store_id', 'theme_id', 'name', 'count', 'id range');

foreach (array_slice($rows, 0, 50) as $r) {
    printf("    %-10s %-14s %-40s %5d  %s..%s\n",
        $r['store_id'] ?? 'NULL',
        (string) ($r['theme_id'] ?? 'NULL') === '' ? "''" : $r['theme_id'],
        substr((string) $r['name'], 0, 40),
        $r['c'], $r['first_id'], $r['last_id']);
}

if (count($rows) > 50) {
    printf("    ... and %d more group(s) not shown\n", count($rows) - 50);
}

echo "\n  WHAT THIS MEANS FOR THE MERCHANT, not just for the migration:\n";
echo "    The storefront reader (pluck) keeps the LAST row of each group; the merchant's writer\n";
echo "    (updateOrCreate) updates the FIRST. For every group above, a save reports success and the\n";
echo "    storefront keeps showing the other value.\n";

exit(1);
