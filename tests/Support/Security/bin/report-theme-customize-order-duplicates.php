#!/usr/bin/env php
<?php

/**
 * PHASE 03 M3-D — WHAT IS REALLY IN `theme_customize_orders`, BEFORE ANY COLUMN IS ADDED. READ-ONLY.
 * =================================================================================================
 *
 * WHY THIS EXISTS AND WHY IT RUNS FIRST
 * -------------------------------------------------------------------------------------------------
 * The owner approved an additive `theme_id` column so section ordering can be stored per theme, and
 * attached a condition to it: *"Inspect real indexes and duplicate patterns. Do not blindly assign
 * legacy rows to arbitrary themes. Do not add an unsafe uniqueness constraint before auditing
 * existing records."*
 *
 * Those are questions about live data, and this development environment is not live data. So the
 * audit is a SCRIPT the owner can run against any database, rather than an assumption baked into a
 * migration. It issues no DDL and no writes.
 *
 * WHAT MAKES THE ANSWER LOAD-BEARING
 * -------------------------------------------------------------------------------------------------
 * The table as shipped is `id, page_slug, orders, store_id, timestamps` and nothing else
 * (`2025_05_23_043026_create_theme_customize_orders_table.php`). Two consequences already measured
 * in M3-C:
 *
 *   1. Both handlers filtered on a `theme_id` column that DOES NOT EXIST. On MySQL that is
 *      `ERROR 1054` and the route answers 500; on SQLite the identifier silently degrades to a
 *      string literal and nothing errors. Section ordering has therefore been broken in production
 *      and invisible to the suite. Both predicates were removed at M3-C.
 *   2. With `theme_id` gone from the predicate, ordering is keyed on `(store_id, page_slug)` and is
 *      consequently SHARED ACROSS THEMES. That is the gap this column closes.
 *
 * So before the column is added, three things have to be known about the target database, and only
 * measurement can answer them:
 *
 *   - **How many rows exist, and do any have a NULL `store_id`?** A NULL-owner row belongs to no
 *     tenant and cannot be backfilled to a theme safely, because there is no store whose
 *     `theme_id` to read.
 *   - **Are `(store_id, page_slug)` pairs already duplicated?** If they are, a UNIQUE key on
 *     `(store_id, theme_id, page_slug)` would fail the migration mid-run after the backfill assigns
 *     the same theme to both. That is why the migration adds NO unique key.
 *   - **What indexes are really there?** `theme_customizes` taught this lesson at M3-B:
 *     `PRAGMA index_list` reported no indexes on SQLite while InnoDB had created one for the
 *     foreign key. The schema is not the migration, and the index list is not the migration either.
 *
 * WHAT A BACKFILL WOULD AND WOULD NOT BE ENTITLED TO DO
 * -------------------------------------------------------------------------------------------------
 * Reported here, not performed. A legacy row records an order a merchant chose while exactly one
 * theme was active — but the table never recorded WHICH, and `stores.theme_id` holds only the
 * CURRENT theme, which the merchant may have changed since. So `stores.theme_id` is evidence about
 * today, not about when the row was written, and assigning it to legacy rows is the "blindly assign
 * legacy rows to arbitrary themes" the owner forbade.
 *
 * The migration therefore leaves legacy rows at `theme_id = NULL` and the application treats NULL as
 * "applies to whichever theme has no row of its own" — a fallback, not a claim. This script reports
 * how many rows such a backfill WOULD affect and how many stores would be ambiguous, so the owner
 * can decide with numbers rather than with an argument.
 *
 * USAGE
 *   php tests/Support/Security/bin/report-theme-customize-order-duplicates.php \
 *       --database=NAME [--socket=/run/mysqld/mysqld.sock | --host= --port=] [--user=] [--pass=]
 *
 *   php tests/Support/Security/bin/report-theme-customize-order-duplicates.php --sqlite=database/p2dev.sqlite
 *
 * EXIT CODES
 *   0  CLEAN: no duplicate (store_id, page_slug) pairs and no NULL-owner rows
 *   1  NOT CLEAN: duplicates, NULL owners, or both -- each listed
 *   2  could not inspect (no server, no such table, bad arguments)
 */

$opt = ['database' => null, 'sqlite' => null, 'socket' => null,
        'host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => ''];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "usage: php " . basename(__FILE__)
        . " --database=NAME [--socket=|--host= --port=] [--user=] [--pass=]  |  --sqlite=PATH\n");
    exit(2);
}

if ($opt['sqlite'] === null && $opt['database'] === null) {
    /* Nothing named: auto-detect a socket so the common case needs no flags, as the sibling
     * harnesses do -- one of them reported "no server reachable" while a server was running. */
    fwrite(STDERR, "Name a target: --database=NAME or --sqlite=PATH\n");
    exit(2);
}

try {
    if ($opt['sqlite'] !== null) {
        $engine = 'sqlite';
        $pdo = new PDO('sqlite:' . $opt['sqlite'], null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        $engine = 'mysql';

        if ($opt['socket'] === null) {
            foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $c) {
                if (file_exists($c)) { $opt['socket'] = $c; break; }
            }
        }

        $dsn = $opt['socket'] !== null
            ? "mysql:unix_socket={$opt['socket']};dbname={$opt['database']};charset=utf8mb4"
            : "mysql:host={$opt['host']};port={$opt['port']};dbname={$opt['database']};charset=utf8mb4";

        $pdo = new PDO($dsn, $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Could not connect: {$e->getMessage()}\n");
    exit(2);
}

/* ------------------------------------------------------------------ does the table exist at all */

try {
    $pdo->query('SELECT 1 FROM theme_customize_orders LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "No readable `theme_customize_orders` in this database: {$e->getMessage()}\n");
    exit(2);
}

echo "THEME_CUSTOMIZE_ORDERS AUDIT  ({$engine})\n";
echo str_repeat('=', 79) . "\n\n";

/* ------------------------------------------------------------------------------- real columns */

$columns = [];

if ($engine === 'sqlite') {
    foreach ($pdo->query('PRAGMA table_info(theme_customize_orders)') as $r) {
        $columns[$r['name']] = $r['type'];
    }
} else {
    foreach ($pdo->query('SHOW COLUMNS FROM theme_customize_orders') as $r) {
        $columns[$r['Field']] = $r['Type'] . ($r['Null'] === 'YES' ? ' NULL' : ' NOT NULL');
    }
}

echo "COLUMNS\n";
foreach ($columns as $name => $type) {
    printf("  %-16s %s\n", $name, $type);
}

$hasThemeId = array_key_exists('theme_id', $columns);
echo "\n  theme_id present : " . ($hasThemeId ? 'YES' : 'NO -- this database predates the M3-D column') . "\n\n";

/* -------------------------------------------------------------------------------- real indexes */

echo "INDEXES, as the engine really holds them\n";

if ($engine === 'sqlite') {
    $any = false;
    foreach ($pdo->query('PRAGMA index_list(theme_customize_orders)') as $r) {
        $any = true;
        $cols = [];
        foreach ($pdo->query("PRAGMA index_info({$r['name']})") as $i) {
            $cols[] = $i['name'];
        }
        printf("  %-40s unique=%s  (%s)\n", $r['name'], $r['unique'] ? 'yes' : 'no', implode(', ', $cols));
    }
    if (! $any) {
        echo "  (none reported)\n";
        echo "  NOTE: SQLite reports no index for a FOREIGN KEY, while InnoDB CREATES one. M3-B\n";
        echo "        measured exactly this disagreement on theme_customizes. Absence here is not\n";
        echo "        evidence of absence on the production engine.\n";
    }
} else {
    $byName = [];
    foreach ($pdo->query('SHOW INDEX FROM theme_customize_orders') as $r) {
        $byName[$r['Key_name']]['unique'] = $r['Non_unique'] == 0;
        $byName[$r['Key_name']]['cols'][(int) $r['Seq_in_index']] = $r['Column_name'];
    }
    foreach ($byName as $name => $info) {
        ksort($info['cols']);
        printf("  %-40s unique=%s  (%s)\n", $name, $info['unique'] ? 'yes' : 'no', implode(', ', $info['cols']));
    }
}

/* ----------------------------------------------------------------------------------- the rows */

$total = (int) $pdo->query('SELECT COUNT(*) FROM theme_customize_orders')->fetchColumn();
$nullOwner = (int) $pdo->query('SELECT COUNT(*) FROM theme_customize_orders WHERE store_id IS NULL')->fetchColumn();
$nullSlug = (int) $pdo->query('SELECT COUNT(*) FROM theme_customize_orders WHERE page_slug IS NULL')->fetchColumn();

echo "\nROWS\n";
printf("  total                    %d\n", $total);
printf("  store_id IS NULL         %d%s\n", $nullOwner,
    $nullOwner > 0 ? '   <-- belongs to no tenant; no store theme to read, so NOT backfillable' : '');
printf("  page_slug IS NULL        %d\n", $nullSlug);

if ($hasThemeId) {
    $nullTheme = (int) $pdo->query('SELECT COUNT(*) FROM theme_customize_orders WHERE theme_id IS NULL')->fetchColumn();
    printf("  theme_id IS NULL         %d   (legacy rows -- the cross-theme fallback)\n", $nullTheme);
}

/* ------------------------------------------------------- duplicates on the key being considered */

$keyCols = $hasThemeId ? 'store_id, theme_id, page_slug' : 'store_id, page_slug';

$dupes = $pdo->query(
    "SELECT {$keyCols}, COUNT(*) AS n FROM theme_customize_orders
      GROUP BY {$keyCols} HAVING COUNT(*) > 1 ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC);

echo "\nDUPLICATES on ({$keyCols}) -- the key a UNIQUE constraint would use\n";

if ($dupes === []) {
    echo "  NONE. A unique key on those columns would succeed against THIS database.\n";
} else {
    printf("  %d duplicate group(s):\n", count($dupes));
    foreach (array_slice($dupes, 0, 30) as $d) {
        $parts = [];
        foreach ($d as $k => $v) {
            if ($k === 'n') { continue; }
            $parts[] = "{$k}=" . ($v === null ? 'NULL' : $v);
        }
        printf("    %-60s x%d\n", implode(' ', $parts), $d['n']);
    }
    if (count($dupes) > 30) {
        printf("    ... and %d more\n", count($dupes) - 30);
    }
    echo "\n  A UNIQUE key would FAIL the migration mid-run on this database. The M3-D migration\n";
    echo "  deliberately adds none; see its docblock.\n";
}

/* --------------------------------------------- what a stores.theme_id backfill would look like */

echo "\nIF LEGACY ROWS WERE BACKFILLED FROM stores.theme_id (reported, NOT performed)\n";

try {
    $joined = $pdo->query(
        "SELECT COUNT(*) FROM theme_customize_orders o
           JOIN stores s ON s.id = o.store_id"
    )->fetchColumn();

    $emptyTheme = $pdo->query(
        "SELECT COUNT(*) FROM theme_customize_orders o
           JOIN stores s ON s.id = o.store_id
          WHERE s.theme_id IS NULL OR s.theme_id = ''"
    )->fetchColumn();

    printf("  rows whose store still exists            %d of %d\n", $joined, $total);
    printf("  rows whose store has NO theme chosen     %d%s\n", $emptyTheme,
        $emptyTheme > 0 ? '   <-- nothing to backfill FROM' : '');
    printf("  rows that would be left behind           %d\n", $total - (int) $joined);

    echo "\n  AND THE REASON THIS IS STILL NOT A SAFE BACKFILL, independent of the counts above:\n";
    echo "  `stores.theme_id` is the theme active TODAY. The order row was written while some theme\n";
    echo "  was active THEN, and the table never recorded which. A merchant who has switched theme\n";
    echo "  since would have their old ordering relabelled as belonging to the new theme -- which is\n";
    echo "  worse than leaving it as a cross-theme fallback, because it is a false record rather\n";
    echo "  than an absent one.\n";
} catch (Throwable $e) {
    echo "  could not join `stores`: {$e->getMessage()}\n";
}

echo "\n" . str_repeat('=', 79) . "\n";

$clean = $dupes === [] && $nullOwner === 0;

echo $clean
    ? "CLEAN: no duplicates, no NULL-owner rows.\n"
    : "NOT CLEAN: see above. The migration is still safe -- it adds a NULLABLE column and no\n"
      . "unique key -- but a FUTURE unique constraint is not, until these are resolved.\n";

exit($clean ? 0 : 1);
