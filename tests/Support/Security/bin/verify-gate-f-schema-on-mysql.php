<?php

/**
 * GATE F — VERIFY THE REAL SCHEMA OF A RESTORED COPY. READ ONLY. NEVER RUN AGAINST PRODUCTION.
 *
 * WHY THIS EXISTS. `_gate_f_pending/README.md` §"What GATE F must supply before any of this moves"
 * names four prerequisites, and three of them are database observations that cannot be made from
 * this repository. This script makes all three in one command, so the verification is a single
 * read-only run against a restored backup rather than a hand-assembled session:
 *
 *   1. SHOW CREATE TABLE for all 15 SCHEMA-01 tables, plus `users.plan_id`, `products.cost_price`
 *      and `orders`.
 *   2. A NULL census: COUNT(*) WHERE store_id IS NULL across every table that HAS a store_id --
 *      enumerated from information_schema rather than from the README's figure of 56, so a schema
 *      that has moved is reported rather than assumed.
 *   3. SELECT DISTINCT type FROM users.
 *
 * The fourth prerequisite -- "the resolution of the payment_status = 'Paid' question" -- is not a
 * database observation. It was ANSWERED by the owner on 2026-10-24 (the Delivered-based COD
 * trigger was withdrawn; see commit 47719ce1 and PaymentVerificationService::codMayBeSettled),
 * and this script reports that fact without re-deciding it.
 *
 * IT ISSUES ONLY SELECT, SHOW AND information_schema READS. It creates nothing, alters nothing and
 * drops nothing. That is the whole safety model, and it is by construction rather than by flag:
 * there is no write statement anywhere in this file. Even so, point it at a RESTORED COPY with a
 * read-only account -- the README's own instruction, and a schema-only dump is still sensitive
 * (grep it for DEFINER= and IDENTIFIED BY, keep it out of git, delete it after).
 *
 * THE DUMP WINS ON EVERY DISAGREEMENT. Where this script compares the live structure against the
 * `_gate_f_pending` drafts or the README's standing decisions, a mismatch is reported as
 * "THE DRAFT IS WRONG", never as a problem with the database. The drafts are provisional
 * reconstructions from `$fillable` and from service code; the restored copy is the fact.
 *
 * Usage:
 *   php tests/Support/Security/bin/verify-gate-f-schema-on-mysql.php <database> \
 *       [--host=127.0.0.1] [--port=3306] [--user=root] [--pass=...] [--socket=/path]
 *
 * Exit status: 0 = every check that could be made passed · 1 = a contradiction was found
 *              2 = could not connect, or the database does not exist
 */

// ---------------------------------------------------------------- arguments

$argvRest = array_slice($argv, 1);
$db = null;
$opt = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '', 'socket' => null];

foreach ($argvRest as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } elseif ($a === '--help' || $a === '-h') {
        fwrite(STDERR, "usage: php " . basename(__FILE__) . " <database> [--host=] [--port=] [--user=] [--pass=] [--socket=]\n");
        exit(2);
    } elseif ($db === null && $a[0] !== '-') {
        $db = $a;
    }
}

if ($db === null) {
    fwrite(STDERR, "ERROR: name the restored database to inspect.\n");
    fwrite(STDERR, "usage: php " . basename(__FILE__) . " <database> [--host=] [--user=] [--pass=] [--socket=]\n");
    exit(2);
}

$dsn = $opt['socket']
    ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $opt['socket'], $db)
    : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $opt['host'], $opt['port'], $db);

try {
    $pdo = new PDO($dsn, $opt['user'], $opt['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: cannot connect to `{$db}`: " . $e->getMessage() . "\n");
    exit(2);
}

$fail = [];
$warn = [];
$na = [];

function h(string $t): void { echo "\n" . $t . "\n" . str_repeat('-', max(78, strlen($t))) . "\n"; }
function q(PDO $p, string $sql, array $b = []): array { $s = $p->prepare($sql); $s->execute($b); return $s->fetchAll(); }
function one(PDO $p, string $sql, array $b = []) { $r = q($p, $sql, $b); return $r ? array_values($r[0])[0] : null; }

/**
 * SHOW CREATE TABLE returns TWO columns -- `Table` and `Create Table` -- so one() would hand back
 * the table NAME. Measured: the first draft of this script printed `store_wallets` where the DDL
 * was supposed to be, which would have made every structure section in this report vacuous while
 * looking fine. Keyed on the column name rather than on position, because the position is not
 * contractual.
 */
function ddl(PDO $p, string $table): string
{
    $row = q($p, 'SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
    if (! $row) { return '(SHOW CREATE TABLE returned nothing)'; }
    foreach ($row[0] as $k => $v) { if (strcasecmp($k, 'Create Table') === 0) { return (string) $v; } }
    return (string) (array_values($row[0])[1] ?? '(no DDL column)');
}

// ---------------------------------------------------------------- the authoritative lists
// Taken from database/migrations/_gate_f_pending/README.md, NOT from memory. Order is the README's.

$SCHEMA_01 = [
    'store_wallets'                    => 'money: merchant balance of record',
    'store_wallet_transactions'        => 'money: the ledger',
    'store_withdrawals'                => 'money: payouts',
    'store_order_financials'           => 'money: per-order P&L',
    'delivery_earnings'                => 'money: courier commission',
    'currency_rates'                   => 'money: FX multiplier on every converted price',
    'audit_logs'                       => 'compliance',
    'otp_integrations'                 => 'auth; api_secret is cast encrypted',
    'chatwoot_integrations'            => 'RETIRED model, table RETAINED (D12)',
    'chatwoot_notification_templates'  => 'RETIRED model, table RETAINED (D12)',
    'delivery_boy_devices'             => 'push tokens',
    'delivery_boy_notifications'       => '-',
    'zid_stores'                       => 'holds credentials',
    'zid_orders'                       => 'order mapping',
    'zid_events'                       => '-',
];
$MONEY = ['store_wallets', 'store_wallet_transactions', 'store_withdrawals',
          'store_order_financials', 'delivery_earnings', 'currency_rates'];

/*
 * MEASURED production types, read from a restored copy of the dump at commit 3935dee8 on
 * 2026-10-24 and reproduced exactly by the drafts. These SUPERSEDE the former standing decision
 * of decimal(18,4) for all money, which no table in production actually uses.
 *
 * WHY THE CHANGE MATTERS TO THIS SCRIPT'S HONESTY: with the old constant it reported 22
 * contradictions against drafts that now match production. A checker whose expectation is a
 * decision rather than the database reports the database as wrong, which is the opposite of this
 * script's own promotion rule. Widening a live money column is a separate, deliberate change.
 */
$MONEY_TYPES = [
    'store_wallets' => [
        'available_balance' => 'decimal(15,2)', 'pending_balance' => 'decimal(15,2)',
        'withdrawal_pending_balance' => 'decimal(15,2)',
        'total_income' => 'decimal(15,2)', 'total_withdrawn' => 'decimal(15,2)',
    ],
    'store_wallet_transactions' => [
        'amount' => 'decimal(15,2)', 'balance_before' => 'decimal(15,2)',
        'balance_after' => 'decimal(15,2)',
    ],
    'store_withdrawals'      => ['amount' => 'decimal(15,2)'],
    'store_order_financials' => [
        'product_amount' => 'decimal(15,2)', 'discount_amount' => 'decimal(15,2)',
        'tax_amount' => 'decimal(15,2)',     'shipping_amount' => 'decimal(15,2)',
        'customer_total' => 'decimal(15,2)', 'product_cost' => 'decimal(15,2)',
        'gross_profit' => 'decimal(15,2)',   'paid_amount' => 'decimal(15,2)',
        'remaining_amount' => 'decimal(15,2)',
    ],
    'delivery_earnings' => [
        'shipping_cost' => 'decimal(10,2)', 'commission_percentage' => 'decimal(5,2)',
        'commission_amount' => 'decimal(10,2)',
    ],
    'currency_rates'    => ['rate_per_usd' => 'decimal(20,8)'],
];

/*
 * The constraints production relies on, and which the drafts declared NONE of until 2026-10-24.
 * This is the check that would have caught the real defect: the type comparison above passed a set
 * of drafts that created the six tables with no unique key and no foreign key at all, silently
 * dropping guarantees production already had. uq_store_order_financial_order is what makes "one
 * financial row per order" enforced rather than merely observed in today's rows.
 */
$EXPECTED_UNIQUE = [
    'store_wallets'          => ['uq_store_wallet_store_id' => 'store_id'],
    'store_order_financials' => ['uq_store_order_financial_order' => 'order_id'],
    'currency_rates'         => ['uq_currency_rates_store_code' => 'store_id,currency_code'],
];
$EXPECTED_FK_COUNT = [
    'store_wallets' => 1, 'store_wallet_transactions' => 2, 'store_withdrawals' => 2,
    'store_order_financials' => 3, 'delivery_earnings' => 3, 'currency_rates' => 1,
];

// The column drafts of P1-MIG-03.
$COLUMN_DRAFTS = [
    'products'      => ['cost_price'],
    'delivery_boys' => ['commission_percentage', 'is_commission_enabled'],
];

// cod_collection_events is draft 0009 and reconstructs nothing -- its columns are owner decision.
$COD_EVENT_TABLE = 'cod_collection_events';
$COD_EVENT_COLS  = ['order_id', 'store_id', 'collector_type', 'collector_id', 'amount', 'collected_at', 'evidence', 'recorded_by'];

echo "GATE F SCHEMA VERIFICATION — read-only, against a RESTORED COPY\n";
echo str_repeat('=', 78) . "\n";
printf("database              : %s\n", $db);
printf("server                : %s\n", one($pdo, 'SELECT VERSION()'));
printf("sql_mode              : %s\n", one($pdo, 'SELECT @@sql_mode'));
printf("server collation      : %s\n", one($pdo, 'SELECT @@collation_server'));
printf("database collation    : %s\n", one($pdo, 'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]));

$strict = str_contains((string) one($pdo, 'SELECT @@sql_mode'), 'STRICT_TRANS_TABLES');
if (! $strict) {
    $warn[] = "sql_mode has no STRICT_TRANS_TABLES. Production runs Laravel with `strict => false`, "
        . "so this matches production -- but it means a too-short column silently truncates instead "
        . "of erroring. P01-SEC-13 survived exactly this.";
}

// ---------------------------------------------------------------- A. is the dump complete?

h('A. DUMP COMPLETENESS — is this a whole database, a schema-only dump, or a partial one?');

$allTables = array_column(q($pdo,
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$db]), 'TABLE_NAME');
$views    = (int) one($pdo, "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?", [$db]);
$routines = (int) one($pdo, "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?", [$db]);
$triggers = (int) one($pdo, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?", [$db]);
$withRows = 0;
foreach ($allTables as $t) {
    if ((int) one($pdo, "SELECT EXISTS(SELECT 1 FROM `" . str_replace('`', '', $t) . "` LIMIT 1)") === 1) { $withRows++; }
}

printf("base tables           : %d\n", count($allTables));
printf("tables holding rows   : %d  (%s)\n", $withRows,
    $withRows === 0 ? 'SCHEMA-ONLY dump — no data' : ($withRows === count($allTables) ? 'all populated' : 'partially populated'));
printf("views / routines / triggers : %d / %d / %d\n", $views, $routines, $triggers);

$hasMigrations = in_array('migrations', $allTables, true);
printf("`migrations` table    : %s\n", $hasMigrations ? 'present' : 'ABSENT');
if ($hasMigrations) {
    printf("migrations recorded   : %s (latest batch %s)\n",
        one($pdo, 'SELECT COUNT(*) FROM migrations'), one($pdo, 'SELECT MAX(batch) FROM migrations'));
} else {
    $warn[] = "No `migrations` table: this dump cannot tell you which migrations the source had run. "
        . "Promotion order cannot be checked against the source's own history.";
}

if (count($allTables) === 0) {
    $fail[] = "The database is EMPTY. Nothing was imported, or the import failed.";
}

// ---------------------------------------------------------------- B. the 15 tables

h('B. THE 15 SCHEMA-01 TABLES — which exist, and the full structure of those that do');

$present = $absent = [];
foreach ($SCHEMA_01 as $t => $why) {
    in_array($t, $allTables, true) ? $present[] = $t : $absent[] = $t;
}
printf("present: %d of 15    absent: %d of 15\n\n", count($present), count($absent));

foreach ($SCHEMA_01 as $t => $why) {
    $is = in_array($t, $present, true);
    printf("  [%s] %-34s %s\n", $is ? 'x' : ' ', $t, $why);
}

if ($absent) {
    echo "\nABSENT from this dump:\n";
    foreach ($absent as $t) { echo "  - $t\n"; }
    echo "\n  An absent table is EVIDENCE, not a gap in this script: it means the source database does\n"
       . "  not have it either, so its migration is a genuine CREATE and the draft is all there is.\n"
       . "  Do NOT promote a draft for an absent table on the strength of this run alone -- the README\n"
       . "  requires the diff in step 1, and there is nothing here to diff against.\n";
}

foreach ($present as $t) {
    h("B." . (array_search($t, $present, true) + 1) . " SHOW CREATE TABLE `$t`");
    echo ddl($pdo, $t) . "\n";
    // The create statement is the authority; the per-column table below is for scanning.
    $cols = q($pdo, "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
                     FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$db, $t]);
    printf("\n  %-28s %-24s %-5s %-12s %s\n", 'column', 'type', 'null', 'default', 'key/extra');
    foreach ($cols as $c) {
        printf("  %-28s %-24s %-5s %-12s %s\n", $c['COLUMN_NAME'], $c['COLUMN_TYPE'], $c['IS_NULLABLE'],
            $c['COLUMN_DEFAULT'] === null ? 'NULL' : (string) $c['COLUMN_DEFAULT'],
            trim($c['COLUMN_KEY'] . ' ' . $c['EXTRA']));
    }
    $fks = q($pdo, "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [$db, $t]);
    echo "\n  foreign keys: " . ($fks ? '' : '(none)') . "\n";
    foreach ($fks as $f) {
        printf("    %s: %s -> %s.%s\n", $f['CONSTRAINT_NAME'], $f['COLUMN_NAME'], $f['REFERENCED_TABLE_NAME'], $f['REFERENCED_COLUMN_NAME']);
    }
}

// ---------------------------------------------------------------- C. money-column discipline

h('C. THE SIX MONEY TABLES — column types against the standing decisions');

echo "Expectations are the MEASURED production types (2026-10-24), not a standing decision: "
   . "decimal(15,2) across the wallet, withdrawal and financial tables, decimal(10,2)/(5,2) on "
   . "delivery_earnings, decimal(20,8) for currency_rates.rate_per_usd. NEVER float, whatever the "
   . "source says — a float money column is reported even if production has one. store_id must be "
   . "indexed on every table that has it. Unique keys and foreign keys are checked too.\n\n";

foreach ($MONEY as $t) {
    if (! in_array($t, $present, true)) { $na[] = "money-column check on `$t` (table absent)"; printf("  %-28s ABSENT — not checkable\n", $t); continue; }

    $bad = [];
    $cols = q($pdo, "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$db, $t]);
    foreach ($cols as $c) {
        $n = $c['COLUMN_NAME']; $ty = strtolower($c['COLUMN_TYPE']);
        $expected = $MONEY_TYPES[$t][$n] ?? null;
        $moneyish = (bool) preg_match('/amount|price|total|balance|fee|cost|commission|earning|profit|rate|charge|tax|discount/i', $n);

        // A float money column is wrong whatever the source says. This is the one rule that is
        // NOT deferred to production, because it is a correctness property rather than a choice.
        if ($moneyish && (str_contains($ty, 'float') || str_contains($ty, 'double'))) {
            $bad[] = "$n is $ty — FLOATING POINT on a money column";
            continue;
        }
        if ($expected !== null) {
            if ($ty !== $expected) { $bad[] = "$n is $ty, expected the measured $expected"; }
            continue;
        }
        // Money-shaped but not in the measured map: a column added since the dump was read. Say so
        // rather than guessing a type for it, which is how the (18,4) error happened.
        if ($moneyish && str_starts_with($ty, 'decimal')) {
            $warn[] = "`$t`.`$n` is $ty and is money-shaped but NOT in the measured map — added "
                . "since 2026-10-24? Re-read a restored copy and extend \$MONEY_TYPES; do not "
                . "assume a scale for it.";
        }
    }

    // store_id must be indexed where present.
    $hasStore = (int) one($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='store_id'", [$db, $t]) === 1;
    if ($hasStore) {
        $idx = (int) one($pdo, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='store_id'", [$db, $t]);
        if ($idx === 0) { $bad[] = "store_id is NOT indexed, and every query filters by it"; }
    }

    /*
     * UNIQUE KEYS. Each of these is an invariant the application relies on and does not itself
     * enforce. Their absence is not a style point: without uq_store_order_financial_order, two
     * financial rows for one order become insertable, and "0 duplicates" stops being a guarantee
     * and becomes an observation about the rows that happen to exist today.
     */
    foreach ($EXPECTED_UNIQUE[$t] ?? [] as $ix => $cols) {
        $actual = q($pdo, "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
                           FROM information_schema.STATISTICS
                           WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=? AND NON_UNIQUE=0
                           GROUP BY INDEX_NAME", [$db, $t, $ix]);
        if (! $actual) {
            $bad[] = "UNIQUE KEY `$ix` ($cols) is MISSING — the invariant it enforces is unenforced";
        } elseif (($actual[0]['cols'] ?? '') !== $cols) {
            $bad[] = "UNIQUE KEY `$ix` covers {$actual[0]['cols']}, expected $cols";
        }
    }

    // FOREIGN KEYS. The ON DELETE rules are what stop a deleted store leaving money rows behind.
    $wantFk = $EXPECTED_FK_COUNT[$t] ?? null;
    if ($wantFk !== null) {
        $gotFk = (int) one($pdo, "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                                  WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=?", [$db, $t]);
        if ($gotFk !== $wantFk) {
            $bad[] = "has $gotFk foreign key(s), expected $wantFk — a table created without them "
                   . "accepts rows pointing at stores, orders or wallets that do not exist";
        }
    }

    printf("  %-28s %s\n", $t, $bad ? 'DISAGREES WITH THE MEASURED STRUCTURE' : 'matches the measured production structure');
    foreach ($bad as $b) { echo "      - $b\n"; $fail[] = "`$t`: $b"; }
}

// ---------------------------------------------------------------- D. the column drafts

h('D. P1-MIG-03 COLUMN DRAFTS — products.cost_price and the delivery_boys commission fields');

foreach ($COLUMN_DRAFTS as $t => $wanted) {
    if (! in_array($t, $allTables, true)) { $fail[] = "`$t` does not exist — a dependency of the column drafts is missing."; printf("  %-18s TABLE ABSENT\n", $t); continue; }
    foreach ($wanted as $col) {
        $row = q($pdo, "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?", [$db, $t, $col]);
        if (! $row) {
            printf("  %-18s %-26s ABSENT — the ALTER is a genuine addition\n", $t, $col);
        } else {
            printf("  %-18s %-26s PRESENT: %s, null=%s, default=%s\n", $t, $col,
                $row[0]['COLUMN_TYPE'], $row[0]['IS_NULLABLE'], $row[0]['COLUMN_DEFAULT'] ?? 'NULL');
            $warn[] = "`$t`.`$col` exists in the source, which is EXPECTED as of 2026-10-24. The "
                . "draft's hasColumn guard makes up() a no-op against production, and that is the "
                . "intended outcome — it brings fresh environments into line without touching the "
                . "live column. Confirm the live type above matches the draft. Note the draft's "
                . "down() must NOT drop it: an unguarded down() on these two destroyed 45 cost_price "
                . "values and both commission columns when rolled back against a copy of production.";
        }
    }
}

// ---------------------------------------------------------------- E. cod_collection_events

h("E. `$COD_EVENT_TABLE` — draft 0009, which reconstructs nothing");

if (in_array($COD_EVENT_TABLE, $allTables, true)) {
    echo ddl($pdo, $COD_EVENT_TABLE) . "\n";
    $have = array_column(q($pdo, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?", [$db, $COD_EVENT_TABLE]), 'COLUMN_NAME');
    $missing = array_diff($COD_EVENT_COLS, $have);
    if ($missing) {
        $fail[] = "`$COD_EVENT_TABLE` exists but lacks the owner's required fields: " . implode(', ', $missing);
        echo "\n  MISSING required fields: " . implode(', ', $missing) . "\n";
    } else {
        echo "\n  All of the owner's required fields are present.\n";
    }
} else {
    echo "  ABSENT, which is EXPECTED and is not a finding.\n\n"
       . "  Unlike the other eight drafts, this table exists nowhere and reconstructs nothing: its\n"
       . "  columns are the owner's four required fields (collector, amount, time, evidence) from the\n"
       . "  decision of 2026-10-24, not inferences from \$fillable. Its absence is why\n"
       . "  PaymentVerificationService::codMayBeSettled() cannot return true today.\n";
    $na[] = "`$COD_EVENT_TABLE` structure (absent by design, gated on D15)";
}

// ---------------------------------------------------------------- F. NULL store_id census

h('F. NULL store_id CENSUS — read-only, across every table that HAS a store_id');

$storeTables = array_column(q($pdo,
    "SELECT TABLE_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'store_id' ORDER BY TABLE_NAME", [$db]), 'TABLE_NAME');

printf("tables with a store_id column: %d  (the README's figure was 56)\n\n", count($storeTables));

$nullTotal = 0; $offenders = [];
foreach ($storeTables as $t) {
    $safe = str_replace('`', '', $t);
    $n = (int) one($pdo, "SELECT COUNT(*) FROM `$safe` WHERE store_id IS NULL");
    $z = (int) one($pdo, "SELECT COUNT(*) FROM `$safe` WHERE store_id = 0");
    $tot = (int) one($pdo, "SELECT COUNT(*) FROM `$safe`");
    $nullTotal += $n;
    if ($n > 0 || $z > 0) { $offenders[] = [$t, $n, $z, $tot]; }
}

if ($offenders) {
    printf("  %-40s %10s %10s %10s\n", 'table', 'NULL', 'zero', 'rows');
    foreach ($offenders as [$t, $n, $z, $tot]) { printf("  %-40s %10d %10d %10d\n", $t, $n, $z, $tot); }
    $warn[] = "Rows with a NULL or zero store_id exist. With `null_tenant => 'open'` the StoreScope "
        . "adds NO predicate when no tenant resolves, so these rows are reachable from any tenant "
        . "context. This is P1-PLT-02 / P1-OWN-11 territory and is the owner's to resolve; it is "
        . "reported here, not fixed.";
} else {
    echo "  No NULL or zero store_id in any table that has the column.\n";
}

// ---------------------------------------------------------------- G. users.type and plan_id

h('G. `users` — DISTINCT type, and plan_id (README prerequisites 1 and 3)');

if (in_array('users', $allTables, true)) {
    echo "  DISTINCT type:\n";
    foreach (q($pdo, 'SELECT type, COUNT(*) n FROM users GROUP BY type ORDER BY n DESC') as $r) {
        printf("    %-24s %d\n", $r['type'] === null ? '(NULL)' : $r['type'], $r['n']);
    }
    echo "\n  Operator status in this codebase is a STRING COMPARISON on users.type "
       . "('super admin' / 'admin'), and there is no super-admin bypass in Laratrust. "
       . "Any spelling above that is neither is a principal no operator check will match.\n";

    $pid = q($pdo, "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=? AND TABLE_NAME='users' AND COLUMN_NAME='plan_id'", [$db]);
    printf("\n  users.plan_id: %s\n", $pid ? $pid[0]['COLUMN_TYPE'] . ', null=' . $pid[0]['IS_NULLABLE'] . ', default=' . ($pid[0]['COLUMN_DEFAULT'] ?? 'NULL') : 'ABSENT');
    if (! $pid) { $fail[] = 'users.plan_id is absent, and the entitlement reads depend on it.'; }
} else {
    $fail[] = '`users` is absent from this dump — it is the highest-fan-in table in the schema.';
    echo "  ABSENT.\n";
}

// ---------------------------------------------------------------- H. migration ordering

h('H. MIGRATION ORDERING — the two constraints that must not be lost');

$haveCost   = (int) one($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='products' AND COLUMN_NAME='cost_price'", [$db]) === 1;
$haveFin    = in_array('store_order_financials', $allTables, true);
$haveComm   = (int) one($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='delivery_boys' AND COLUMN_NAME='is_commission_enabled'", [$db]) === 1;
$haveEarn   = in_array('delivery_earnings', $allTables, true);

printf("  products.cost_price present              : %s\n", $haveCost ? 'yes' : 'no');
printf("  store_order_financials present           : %s\n", $haveFin ? 'yes' : 'no');
printf("  delivery_boys.is_commission_enabled      : %s\n", $haveComm ? 'yes' : 'no');
printf("  delivery_earnings present                : %s\n", $haveEarn ? 'yes' : 'no');

echo "\n  CONSTRAINT 1 — products.cost_price BEFORE store_order_financials.\n";
if ($haveFin && ! $haveCost) {
    $fail[] = "ORDERING VIOLATED IN THE SOURCE: store_order_financials exists while products.cost_price "
        . "does not. OrderFinancialService reads cost_price with `?? 0`, so every P&L row written "
        . "without it is ALL-MARGIN. Existing rows are already wrong.";
    echo "    VIOLATED in the source database — see the failure list.\n";
} elseif (! $haveFin && ! $haveCost) {
    echo "    Neither exists yet: promote cost_price first, then store_order_financials.\n";
} elseif ($haveCost && ! $haveFin) {
    echo "    Correct order is available: cost_price is in place, financials may follow.\n";
} else {
    echo "    Both present. Check that cost_price was populated BEFORE any financial row was written;\n"
       . "    this script cannot tell you the order history, only the current state.\n";
    $warn[] = "Both products.cost_price and store_order_financials exist. Whether cost_price was "
        . "populated before the first financial row is a question about history that no schema read "
        . "can answer -- audit store_order_financials for all-margin rows before trusting the P&L.";
}

echo "\n  CONSTRAINT 2 — the delivery_boys commission columns WITH OR BEFORE delivery_earnings.\n";
if ($haveEarn && ! $haveComm) {
    $fail[] = "ORDERING VIOLATED IN THE SOURCE: delivery_earnings exists while "
        . "delivery_boys.is_commission_enabled does not, and that flag is what GATES the earning write.";
    echo "    VIOLATED in the source database — see the failure list.\n";
} else {
    echo "    " . ($haveComm || ! $haveEarn ? 'No violation in the current state.' : 'Check manually.') . "\n";
}

// ---------------------------------------------------------------- I. COD prerequisite 4

h("I. GATE F PREREQUISITE 4 — the payment_status = 'Paid' question");

echo "  NOT a database observation, and it is ANSWERED.\n\n"
   . "  The README requires it resolved \"before or with\" the migration, because otherwise COD and\n"
   . "  bank-transfer orders credit merchant wallets against cash never collected. Owner decision of\n"
   . "  2026-10-24 withdrew the Delivered-based COD trigger outright: Delivered alone never marks COD\n"
   . "  Paid and never credits a wallet, and settlement now requires a separate verified cash\n"
   . "  collection event naming collector, amount, time and evidence.\n\n"
   . "  Implemented in 47719ce1 — PaymentVerificationService::codMayBeSettled(), App\\Services\\\n"
   . "  CodCollectionService, and codShouldSettleOnDelivery() retired to a method that throws.\n"
   . "  Pinned by tests/Feature/Commerce/CodStateMachineTest.php.\n\n"
   . "  So prerequisite 4 is MET. Prerequisites 1-3 are what this script reports above.\n";

// ---------------------------------------------------------------- verdict

h('VERDICT');

printf("checks that could not be made (absent objects) : %d\n", count($na));
foreach ($na as $n) { echo "    N/A  $n\n"; }
printf("\nwarnings (read these before promoting anything) : %d\n", count($warn));
foreach ($warn as $w) { echo "    WARN $w\n\n"; }
printf("contradictions between the source and the drafts : %d\n", count($fail));
foreach ($fail as $f) { echo "    FAIL $f\n"; }

echo "\n" . str_repeat('=', 78) . "\n";
if ($fail) {
    echo "RESULT: the source database CONTRADICTS the drafts in " . count($fail) . " place(s).\n"
       . "THE SOURCE WINS. Correct each draft against the structure printed above before promoting it.\n";
    exit(1);
}
echo "RESULT: no contradiction found between this source database and the drafts.\n"
   . "That is NOT the same as GATE F being ready: " . count($na) . " check(s) could not be made because\n"
   . "the object is absent, and an absent table means its draft has nothing to be diffed against.\n"
   . "Promotion still requires the README's four prerequisites and owner decision D15.\n";
echo "\nNothing was created, altered or dropped by this script.\n";
exit(0);
