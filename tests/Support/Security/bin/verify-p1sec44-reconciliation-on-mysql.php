#!/usr/bin/env php
<?php
/**
 * P1-SEC-44 HISTORICAL RECONCILIATION — did any existing store_order_financials row ever take a
 * FOREIGN tenant's product cost?
 *
 * Owner direction of 2026-11-02, Decision 3: "Review the three identified
 * store_order_financials candidates with non-zero product_cost. START WITH AN ISOLATED RESTORED
 * PRODUCTION-SHAPED COPY. Compare each order's store ownership against the referenced product
 * ownership. DO NOT PRESUME THE RECORDS ARE CORRUPTED OR BELONG TO FOREIGN TENANTS. ... Do not
 * modify, backfill, delete or recalculate existing financial records."
 *
 * ==============================================================================================
 * WHAT THIS ANSWERS, AND WHY A SCRIPT RATHER THAN A CLAIM
 * ==============================================================================================
 * `OrderFinancialService::createForOrder()` used an UNSCOPED `Product::find()`, so an order line
 * naming another store's product costed this store's P&L from that competitor's `cost_price`
 * (P1-SEC-44). The fix is store-scoped and FORWARD-ONLY: `createForOrder()` early-returns when a
 * row exists, and the three update sites write only payment fields, so no existing row is ever
 * recomputed. The question this leaves is therefore historical, and it is answerable only against
 * production-shaped data.
 *
 * The figures were measured once by hand and are reproduced here so the answer survives: a number
 * in a commit message is a claim, a script is evidence. This is the same reason every other
 * `verify-*-on-mysql.php` in this directory exists.
 *
 * ==============================================================================================
 * SAFETY — read before running
 * ==============================================================================================
 *  - It creates its OWN database and DROPS it at the end. The name must contain `verify`, and the
 *    script refuses otherwise, exactly as its siblings do.
 *  - It NEVER connects to production and NEVER writes anything anywhere but its own throwaway
 *    database. It issues only SELECTs against that copy.
 *  - IT PRINTS NO PERSONAL DATA. Only row ids, store ids, product ids, money figures and counts.
 *    No name, e-mail, phone, address or credential is read or echoed. The restored copy contains
 *    real customer data, which is why the database is dropped and why the output is restricted to
 *    identifiers and sums.
 *  - The dump it restores is the one already in this repository. That file is the subject of the
 *    OPEN, owner-deferred incident #120; this script neither copies it elsewhere nor adds a new
 *    one, and reading it is what "an isolated restored production-shaped copy" means here. If the
 *    owner would rather it ran against a separately provisioned restore, point DUMP at that file.
 *
 * USAGE
 *   tests/Support/Security/bin/verify-p1sec44-reconciliation-on-mysql.php
 *
 *   DUMP=/path/to/dump.sql            # default: storesyria_ecommercego.sql in the repo root
 *   MYSQL_SOCK=/run/mysqld/mysqld.sock
 *   MYSQL_USER_NAME=root
 *   MYSQL_PASS=
 *
 * Exit 0 = every assertion held. Exit 1 = an assertion failed, or a foreign-cost row was found.
 */

$root = dirname(__DIR__, 4);
$dump = getenv('DUMP') ?: $root . '/storesyria_ecommercego.sql';
$db = 'verify_p1sec44_recon';
$sock = getenv('MYSQL_SOCK') ?: '/run/mysqld/mysqld.sock';
$user = getenv('MYSQL_USER_NAME') ?: 'root';
$pass = getenv('MYSQL_PASS') ?: '';

if (! str_contains($db, 'verify')) {
    fwrite(STDERR, "refusing a database name without 'verify' in it\n");
    exit(2);
}

if (! is_file($dump)) {
    fwrite(STDERR, "dump not found: {$dump}\nSet DUMP to a production-shaped restore.\n");
    exit(2);
}

$failures = [];
$notes = [];

function assertSame($expected, $actual, string $what): void
{
    global $failures;

    if ($expected === $actual) {
        printf("  OK    %-62s %s\n", $what, var_export($actual, true));

        return;
    }

    $failures[] = $what;
    printf("  FAIL  %-62s expected %s, measured %s\n", $what, var_export($expected, true), var_export($actual, true));
}

$mysqlBase = sprintf('mysql --socket=%s -u%s %s', escapeshellarg($sock), escapeshellarg($user), $pass !== '' ? '-p' . escapeshellarg($pass) : '');

function sqlRows(string $sql): array
{
    global $mysqlBase, $db;

    $cmd = $mysqlBase . ' -N ' . escapeshellarg($db) . ' -e ' . escapeshellarg($sql) . ' 2>/dev/null';
    exec($cmd, $out, $code);

    if ($code !== 0) {
        fwrite(STDERR, "query failed: {$sql}\n");
        exit(2);
    }

    return array_map(fn (string $line) => explode("\t", $line), $out);
}

printf("P1-SEC-44 historical reconciliation, against an isolated restore of:\n  %s\n\n", $dump);

exec($mysqlBase . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$db}`; CREATE DATABASE `{$db}` CHARACTER SET utf8mb4;"), $o, $c);
if ($c !== 0) {
    fwrite(STDERR, "could not create {$db}. Is a throwaway MySQL/MariaDB running on {$sock}?\n");
    exit(2);
}

printf("restoring...\n");
exec($mysqlBase . ' ' . escapeshellarg($db) . ' < ' . escapeshellarg($dump), $o, $c);
if ($c !== 0) {
    fwrite(STDERR, "restore failed\n");
    exec($mysqlBase . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$db}`;"));
    exit(2);
}
printf("restored.\n\n");

// ----------------------------------------------------------------------------- the cited figures
printf("THE FIGURES THE REVIEW CITED, re-measured rather than repeated:\n");
$r = sqlRows('SELECT COUNT(*), SUM(product_cost <> 0), SUM(product_cost = 0) FROM store_order_financials')[0];
assertSame(92, (int) $r[0], 'store_order_financials rows');
assertSame(3, (int) $r[1], 'rows with a NON-ZERO product_cost');
assertSame(89, (int) $r[2], 'rows with product_cost = 0');

$p = sqlRows('SELECT COUNT(*), SUM(cost_price <> 0) FROM products')[0];
assertSame(45, (int) $p[0], 'products');
assertSame(9, (int) $p[1], 'products carrying a non-zero cost_price');

// --------------------------------------------------- ownership: the financial row vs the product
printf("\nOWNERSHIP OF EVERY PRODUCT EVERY FINANCIAL ROW'S ORDER REFERENCES:\n");

$owner = [];
foreach (sqlRows('SELECT id, store_id FROM products') as $row) {
    $owner[$row[0]] = $row[1];
}

$rows = sqlRows('SELECT f.id, f.store_id, f.order_id, f.product_cost, o.product_id, o.product_json
                 FROM store_order_financials f LEFT JOIN orders o ON o.id = f.order_id');

$same = 0;
$foreign = [];
$absent = 0;
$foreignWithCost = [];

foreach ($rows as $row) {
    [$fid, $fstore, $oid, $pcost] = [$row[0], $row[1], $row[2], $row[3]];
    $blob = implode("\t", array_slice($row, 4));

    preg_match_all('/"product_id"\s*:\s*"?(\d+)"?/', $blob, $m);
    $ids = array_unique($m[1]);

    if (isset($row[4]) && ctype_digit(trim($row[4]))) {
        $ids[] = trim($row[4]);
    }

    foreach (array_unique($ids) as $pid) {
        if (! isset($owner[$pid])) {
            $absent++;

            continue;
        }

        if ($owner[$pid] === $fstore) {
            $same++;

            continue;
        }

        $foreign[] = [$fid, $fstore, $oid, $pid, $owner[$pid], $pcost];

        if ((float) $pcost !== 0.0) {
            $foreignWithCost[] = [$fid, $fstore, $oid, $pid, $owner[$pid], $pcost];
        }
    }
}

printf("  same-store references : %d\n", $same);
printf("  foreign-store refs    : %d\n", count($foreign));
printf("  product row absent    : %d\n", $absent);

foreach ($foreign as $f) {
    printf("    cross-store reference: fin_id=%s fin_store=%s order=%s product=%s product_store=%s product_cost=%s\n", ...$f);
}

/*
 * THE ONE ASSERTION THAT DECIDES THE QUESTION. A cross-store REFERENCE is not the finding; a
 * cross-store reference ON A ROW THAT CARRIES A COST is. The first means the unscoped lookup had
 * the opportunity; the second means it took it and a competitor's figure is sitting in another
 * merchant's profit and loss.
 */
assertSame([], $foreignWithCost, 'financial rows carrying a FOREIGN store\'s product cost');

// ------------------------------------------------------- why the cross-store rows carried nothing
if ($foreign !== []) {
    printf("\nWHY THE CROSS-STORE ROWS DISCLOSED NOTHING — the referenced products' own cost:\n");
    $ids = implode(',', array_map(fn ($f) => (int) $f[3], $foreign));
    foreach (sqlRows("SELECT id, store_id, cost_price FROM products WHERE id IN ({$ids})") as $row) {
        printf("    product=%s store=%s cost_price=%s\n", $row[0], $row[1], $row[2]);
        if ((float) $row[2] !== 0.0) {
            $notes[] = sprintf('product %s is cross-referenced AND carries a cost of %s', $row[0], $row[2]);
        }
    }
    printf("\n  So the leak had the opportunity and no payload: the mechanism is present in real\n");
    printf("  data, and the forward fix is therefore load-bearing rather than theoretical.\n");
}

printf("\nWHICH STORES CARRY COSTS AT ALL (the stores a future cross-store order could expose):\n");
foreach (sqlRows('SELECT store_id, COUNT(*) FROM products WHERE cost_price <> 0 GROUP BY store_id ORDER BY store_id') as $row) {
    printf("    store %-4s %s products with a non-zero cost_price\n", $row[0], $row[1]);
}

// ----------------------------------------------------------------------------------- tear down
exec($mysqlBase . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$db}`;"), $o, $c);
printf("\nthrowaway database dropped: %s\n", $c === 0 ? 'yes' : 'NO -- drop it by hand');

if ($notes !== []) {
    printf("\nNOTES:\n");
    foreach ($notes as $n) {
        printf("  - %s\n", $n);
    }
}

if ($failures !== []) {
    printf("\n%d ASSERTION(S) FAILED:\n  %s\n", count($failures), implode("\n  ", $failures));
    exit(1);
}

printf("\nEvery assertion held. No existing store_order_financials row carries a foreign store's\n");
printf("product cost, so NO historical remediation of financial records is indicated -- which is\n");
printf("the outcome the owner's instruction not to presume corruption left room for.\n");
exit(0);
