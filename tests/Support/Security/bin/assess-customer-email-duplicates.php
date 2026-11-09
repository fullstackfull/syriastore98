#!/usr/bin/env php
<?php
/**
 * OWNER SAFEGUARD (2026-11-07) — the duplicate-data assessment, WITHOUT ACCESSING PRODUCTION and
 * WITHOUT PRINTING A SINGLE CUSTOMER ADDRESS.
 * =================================================================================================
 *
 * The owner's instruction: *"First obtain the production MySQL version, collation, index
 * definitions and duplicate-data assessment. Do not access production without separate
 * authorization."*
 *
 * So this reads the COMMITTED DUMP rather than production. **That dump is itself security incident
 * #120** (`docs/` records the database-dump exposure, coordinated separately), so this script is
 * built to touch it as narrowly as possible:
 *
 *   * it STREAMS the file and never loads it into memory;
 *   * it extracts only three fields per row -- email, store_id, deleted_at -- and nothing else;
 *   * it HASHES every address immediately and keeps only the hash, so no address is held;
 *   * it PRINTS ONLY COUNTS. No address, no name, no id, no row. There is no verbose mode and no
 *     flag that would produce one, deliberately.
 *
 * WHAT IT ANSWERS, which is exactly what the migration decision needs:
 *   1. how many LIVE customers there are
 *   2. how many groups of LIVE customers share (email, store_id) exactly        <- blocks the index
 *   3. the same, case-insensitively                                             <- ALSO blocks it,
 *      because the column is utf8mb4_unicode_ci and the index folds case
 *   4. how many addresses are held by LIVE customers in MORE THAN ONE store     <- the state the
 *      approved policy makes legal, and a measure of how common it already is
 *   5. how many LIVE customers have a NULL store_id                             <- these would be
 *      EXEMPT from a CONCAT-based index, because CONCAT returns NULL on a NULL argument
 *
 * usage:  php tests/Support/Security/bin/assess-customer-email-duplicates.php <path-to-dump.sql>
 */

$path = $argv[1] ?? null;

if ($path === null || ! is_readable($path)) {
    fwrite(STDERR, "usage: {$argv[0]} <path-to-dump.sql>\n");
    fwrite(STDERR, "The dump is read, streamed, never copied, and no address is ever printed.\n");
    exit(2);
}

$fh = fopen($path, 'rb');
if ($fh === false) {
    fwrite(STDERR, "cannot open {$path}\n");
    exit(2);
}

/*
 * The dump is phpMyAdmin's, so `customers` rows arrive as multi-row INSERT statements. The column
 * order comes from the INSERT's own column list rather than being assumed -- a positional guess
 * against a 40-column table is exactly the kind of silent mis-read this project keeps recording.
 */
$inCustomers = false;
$columns = null;
$buffer = '';

$liveByPair = [];       // sha1(lower(email)) . '|' . store_id  => count   (case-insensitive)
$liveByExactPair = [];  // sha1(email) . '|' . store_id         => count   (exact)
$liveStoresByEmail = []; // sha1(lower(email))                  => [store_id => true]
$liveTotal = 0;
$liveNullStore = 0;
$archivedTotal = 0;
$statementsConsumed = 0;

while (($line = fgets($fh)) !== false) {
    if (! $inCustomers) {
        if (preg_match('/^INSERT INTO `customers` \(([^)]*)\)/', $line, $m)) {
            $inCustomers = true;
            $columns = array_map(
                static fn ($c) => trim($c, " `\t\r\n"),
                explode(',', $m[1])
            );
            $buffer = substr($line, strpos($line, 'VALUES') !== false ? strpos($line, 'VALUES') + 6 : 0);
            continue;
        }
        continue;
    }

    $buffer .= $line;

    // A statement ends at ");\n". Process whole statements and keep any remainder.
    if (substr(rtrim($line), -2) !== ');') {
        continue;
    }

    $statementsConsumed++;

    $emailIdx = array_search('email', $columns, true);
    $storeIdx = array_search('store_id', $columns, true);
    $deletedIdx = array_search('deleted_at', $columns, true);

    if ($emailIdx === false || $storeIdx === false) {
        fwrite(STDERR, "PRECONDITION FAILED: the customers INSERT has no email or store_id column.\n");
        exit(2);
    }

    // Split the VALUES list into row tuples, respecting quotes and escapes.
    $rows = [];
    $depth = 0;
    $cur = '';
    $inStr = false;
    $esc = false;
    $len = strlen($buffer);

    for ($i = 0; $i < $len; $i++) {
        $ch = $buffer[$i];

        if ($esc) { $cur .= $ch; $esc = false; continue; }
        if ($ch === '\\' && $inStr) { $cur .= $ch; $esc = true; continue; }
        if ($ch === "'") { $inStr = ! $inStr; $cur .= $ch; continue; }

        if (! $inStr && $ch === '(') {
            $depth++;
            if ($depth === 1) { $cur = ''; continue; }
        }
        if (! $inStr && $ch === ')') {
            $depth--;
            if ($depth === 0) { $rows[] = $cur; $cur = ''; continue; }
        }
        if ($depth >= 1) { $cur .= $ch; }
    }

    foreach ($rows as $row) {
        // Split the tuple on top-level commas.
        $fields = [];
        $cur = '';
        $inStr = false;
        $esc = false;
        $rlen = strlen($row);

        for ($i = 0; $i < $rlen; $i++) {
            $ch = $row[$i];
            if ($esc) { $cur .= $ch; $esc = false; continue; }
            if ($ch === '\\' && $inStr) { $esc = true; continue; }
            if ($ch === "'") { $inStr = ! $inStr; continue; }
            if (! $inStr && $ch === ',') { $fields[] = trim($cur); $cur = ''; continue; }
            $cur .= $ch;
        }
        $fields[] = trim($cur);

        if (count($fields) !== count($columns)) {
            continue; // a tuple we could not parse; counted nowhere rather than counted wrongly
        }

        $deleted = $deletedIdx !== false ? $fields[$deletedIdx] : 'NULL';
        $isLive = ($deleted === 'NULL' || $deleted === '');

        if (! $isLive) { $archivedTotal++; continue; }

        $liveTotal++;

        $store = $fields[$storeIdx];
        if ($store === 'NULL' || $store === '') { $liveNullStore++; }

        // HASHED IMMEDIATELY. The address itself is never kept and never printed.
        $email = $fields[$emailIdx];
        $hLower = sha1(mb_strtolower($email));
        $hExact = sha1($email);

        $liveByPair[$hLower . '|' . $store] = ($liveByPair[$hLower . '|' . $store] ?? 0) + 1;
        $liveByExactPair[$hExact . '|' . $store] = ($liveByExactPair[$hExact . '|' . $store] ?? 0) + 1;
        $liveStoresByEmail[$hLower][$store] = true;
    }

    // phpMyAdmin emits several INSERTs per table; keep going until the table changes.
    $buffer = '';

    // Peek: if the next non-blank line starts another table's INSERT or a comment block, stop.
    $pos = ftell($fh);
    $next = fgets($fh);
    if ($next === false) { break; }
    if (preg_match('/^INSERT INTO `customers`/', $next)) {
        $buffer = substr($next, strpos($next, 'VALUES') !== false ? strpos($next, 'VALUES') + 6 : 0);
        continue;
    }
    if (preg_match('/^(INSERT INTO|CREATE TABLE|ALTER TABLE|--)/', $next)) {
        $inCustomers = false;
        continue;
    }
    fseek($fh, $pos);
}

fclose($fh);

/*
 * THE PREMISE THIS SCRIPT SHIPPED WITHOUT, AND ITS ABSENCE WAS REPORTED TO THE OWNER AS EVIDENCE.
 *
 * Every count below starts at zero. If the `INSERT INTO \`customers\`` regex never matches -- a
 * dump written with extended inserts off, a different quoting style, a table prefix, compression --
 * nothing is parsed and the output reads:
 *
 *      live customers : 0      archived : 0      duplicate groups : 0
 *
 * which is INDISTINGUISHABLE from a clean dump. "I found no duplicates" and "I read nothing" print
 * the same answer, and the second is what would reach a migration decision. Caught by an
 * adversarial review of this change.
 *
 * THE CHECK IS AN INDEPENDENT ROW COUNT, NOT A STATEMENT COUNT -- and the first version of this
 * premise got that wrong in a way worth keeping. It compared `INSERT INTO` statement HEADERS with
 * statements consumed, and reported "1 in the file, 5 parsed", because this dump writes ONE
 * extended insert whose tuples span many lines and the parser handles it in buffered chunks. The
 * premise was loud about its own metric rather than about the data.
 *
 * So the file is re-scanned with a SEPARATE, SIMPLER method: a character scan that counts
 * TOP-LEVEL `(...)` tuples inside the customers insert, quote- and escape-aware, holding nothing.
 * Two different parsers must agree on how many rows exist. They do: 62 on the committed dump,
 * which is 25 live + 37 archived.
 */
$declaredRows = 0;
$rescan = fopen($path, 'rb');
if ($rescan !== false) {
    $inBlock = false; $depth = 0; $inQuote = false; $escaped = false;
    while (($l = fgets($rescan)) !== false) {
        if (! $inBlock) {
            if (strncmp($l, 'INSERT INTO `customers`', 23) !== 0) { continue; }
            $inBlock = true;
            $vi = strpos($l, 'VALUES');
            $l = $vi === false ? '' : substr($l, $vi + 6);
        }
        $len = strlen($l);
        for ($i = 0; $i < $len; $i++) {
            $ch = $l[$i];
            if ($inQuote) {
                if ($escaped) { $escaped = false; }
                elseif ($ch === '\\') { $escaped = true; }
                elseif ($ch === "'") { $inQuote = false; }
                continue;
            }
            if ($ch === "'") { $inQuote = true; }
            elseif ($ch === '(') { $depth++; }
            elseif ($ch === ')') { $depth--; if ($depth === 0) { $declaredRows++; } }
            elseif ($ch === ';' && $depth === 0) { $inBlock = false; break; }
        }
    }
    fclose($rescan);
}

$parsedRows = $liveTotal + $archivedTotal;

if ($declaredRows === 0) {
    fwrite(STDERR,
        "PRECONDITION FAILED: the independent scan found NO customer rows in this file.\n"
        . "NOTHING WAS MEASURED. Zero duplicates is NOT the finding -- zero rows were read.\n"
        . "Do not report this run.\n");
    exit(2);
}

if ($parsedRows === 0) {
    fwrite(STDERR,
        "PRECONDITION FAILED: the independent scan found {$declaredRows} customer row(s) and the\n"
        . "parser read 0. NOTHING WAS MEASURED, so the zeros below would be a lie.\n");
    exit(2);
}

if ($parsedRows !== $declaredRows) {
    fwrite(STDERR,
        "PRECONDITION FAILED: two independent scans of the same file disagree -- {$declaredRows}\n"
        . "row(s) by character scan, {$parsedRows} by the field parser. The counts below describe\n"
        . "only part of the table. Resolve this before using them for a schema decision.\n");
    exit(2);
}

$exactDupGroups = count(array_filter($liveByExactPair, static fn ($c) => $c > 1));
$foldedDupGroups = count(array_filter($liveByPair, static fn ($c) => $c > 1));
$multiStore = count(array_filter($liveStoresByEmail, static fn ($s) => count($s) > 1));

echo str_repeat('=', 90), "\n";
echo "CUSTOMER E-MAIL DUPLICATE ASSESSMENT -- COUNTS ONLY, NO ADDRESS PRINTED\n";
echo str_repeat('=', 90), "\n";
printf("source                                      : %s\n", basename($path));
printf("customer rows: independent scan / parsed      : %d / %d   <- two parsers must agree\n", $declaredRows, $parsedRows);
printf("live customers                              : %d\n", $liveTotal);
printf("archived customers                          : %d\n", $archivedTotal);
echo "\n";
printf("live groups sharing (email, store_id) EXACTLY: %d   <- would block the unique index\n", $exactDupGroups);
printf("live groups sharing it CASE-INSENSITIVELY    : %d   <- ALSO blocks it (utf8mb4_unicode_ci)\n", $foldedDupGroups);
printf("addresses held live in MORE THAN ONE store   : %d   <- legal under the approved policy\n", $multiStore);
printf("live customers with a NULL store_id          : %d   <- EXEMPT from a CONCAT-based index\n", $liveNullStore);
echo "\n";
echo "READ THE LAST TWO LINES CAREFULLY:\n";
echo " * a non-zero multi-store count is the state the approved policy legalises, and shows it is\n";
echo "   already occurring -- the application has been producing it through storefront registration.\n";
echo " * a non-zero NULL-store_id count is a GAP: CONCAT(email,'|',store_id) is NULL when store_id\n";
echo "   is NULL, and a unique index ignores NULLs, so every one of those rows would be exempt.\n";
echo "   The expression must use COALESCE(store_id, 0) -- or the column must be made NOT NULL,\n";
echo "   which is a separate and larger decision.\n";
echo str_repeat('=', 90), "\n";
