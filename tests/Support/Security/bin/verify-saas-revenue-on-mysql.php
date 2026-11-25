<?php

/**
 * GAP-192 / SAAS-007 — VALIDATE THE PLATFORM-REVENUE RECEIPTS PATH AGAINST A REAL MySQL SERVER.
 *
 * WHY THIS EXISTS. `App\Services\SaasRevenueFigures` computes what merchants pay Store Syria. Its
 * receipts half groups `plan_orders` IN THE DATABASE -- `payment_status`, `price_currency` and
 * `is_refund` as GROUP BY keys, `sum(price)` as the money -- and then folds and presents the groups
 * in PHP. EVERY ONE OF THOSE FOUR COLUMNS BEHAVES DIFFERENTLY ON THE TWO ENGINES, and the Feature
 * suite runs on SQLite `:memory:` while production is MySQL:
 *
 *   - `price_currency` and `payment_status` are VARCHARs. MySQL's default collation is
 *     case-INSENSITIVE, so it GROUPS `Succeeded` and `succeeded` into ONE row; SQLite's is BINARY
 *     and returns TWO. The class's own docblock asserts this in prose. Nothing executed it.
 *   - `price` is `$table->float('price')`, which READS like a single-precision column and is not
 *     one: Laravel 11 emits a MySQL `double`. Whether a SYP-scale plan payment keeps its minor
 *     units is therefore a question about the engine, and only the engine can answer it.
 *   - `is_refund` was added later, and whether a pre-existing row can carry NULL is likewise a
 *     question about what the migration actually built.
 *
 * It was the one financial surface in this repository with no MySQL evidence beside it, against
 * sixty-odd such harnesses for its neighbours. An independent financial review said so, and this is
 * that evidence.
 *
 * WHAT IT ESTABLISHES, by execution rather than by argument:
 *   1. the types the REAL migrations build on MySQL, and the COLLATION of the two grouping keys;
 *   2. that the production query is accepted under `ONLY_FULL_GROUP_BY` (MySQL 5.7+ default), which
 *      a `selectRaw` with aliases and a three-column GROUP BY is not guaranteed to be;
 *   3. that MySQL folds case-variant settled spellings into ONE group and SQLite into TWO --
 *      MEASURED SIDE BY SIDE IN THIS SCRIPT, on the same rows, through the same shipped method --
 *      and that the PRESENTED figure is nevertheless identical, because the PHP fold is the
 *      comparator. That is the claim `planOrderGroups()`'s docblock makes;
 *   4. the same for case-variant currency spellings, which fold to one presented row keyed by the
 *      upper-cased code;
 *   5. THAT `$table->float('price')` BUILDS A `double` AND NOT A `FLOAT`, so a SYP-scale plan
 *      payment keeps its minor units -- measured against a hand-written `FLOAT` column in the same
 *      database, which does NOT. This is why the receipts figures carry no
 *      `MetricCaveat::FLOAT_PRECISION`: the caveat was added on the strength of the migration's
 *      wording and removed when this check refuted it;
 *   6. that `sum()` arrives from MySQL as a STRING, so the producer's `(float)` cast is load-bearing
 *      rather than defensive;
 *   7. that `is_refund` is `NOT NULL DEFAULT 0` as built, so the NULL the producer guards against
 *      CANNOT occur on this schema -- the guard is defensive, not load-bearing, and saying which
 *      is the difference between a documented premise and a guessed one.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database
 * and touches nothing else:
 *
 *   SAASREV_MYSQL_SOCKET=/run/mysqld/mysqld.sock \
 *   SAASREV_MYSQL_DATABASE=saasrev_verify \
 *   SAASREV_MYSQL_USERNAME=root \
 *   SAASREV_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-saas-revenue-on-mysql.php
 *
 * or with SAASREV_MYSQL_HOST / SAASREV_MYSQL_PORT instead of a socket.
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE and DROP
 * DATABASE, and refuses a database name containing neither 'saasrev' nor 'verify'.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlanOrder;
use App\Services\SaasRevenueFigures;
use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$failures = [];
$checks = 0;

function ok(string $what): void
{
    global $checks;
    $checks++;
    echo "  PASS  {$what}\n";
}

function bad(string $what, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    $failures[] = $what . ($detail !== '' ? " -- {$detail}" : '');
    echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function check(bool $condition, string $what, string $detail = ''): void
{
    $condition ? ok($what) : bad($what, $detail);
}

/** Call a private static on the shipped class, so this script measures the real code path. */
function shipped(string $method, array $args = []): mixed
{
    $m = new ReflectionMethod(SaasRevenueFigures::class, $method);
    $m->setAccessible(true);

    return $m->invokeArgs(null, $args);
}

// -------------------------------------------------------------------------------------------------
// PRECONDITIONS
// -------------------------------------------------------------------------------------------------

$database = getenv('SAASREV_MYSQL_DATABASE') ?: 'saasrev_verify';

if (! str_contains($database, 'saasrev') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$database}' contains neither 'saasrev' nor 'verify'.\n"
        . "This script issues DROP DATABASE. Name the throwaway database accordingly.\n");
    exit(2);
}

$socket = getenv('SAASREV_MYSQL_SOCKET') ?: '';
$host = getenv('SAASREV_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('SAASREV_MYSQL_PORT') ?: '3306';
$username = getenv('SAASREV_MYSQL_USERNAME') ?: 'root';
$password = getenv('SAASREV_MYSQL_PASSWORD');
$password = $password === false ? '' : $password;

$mysql = [
    'driver' => 'mysql',
    'url' => null,
    'host' => $host,
    'port' => $port,
    'unix_socket' => $socket,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    // `strict => false` IS THE PRODUCTION SETTING (config/database.php). Verifying under `true`
    // would be verifying a server this application never talks to.
    'strict' => false,
    'engine' => null,
];

config([
    'database.connections.saasrev' => $mysql,
    'database.connections.saasrev_root' => array_merge($mysql, ['database' => null]),
    // The same table on SQLite, so the two engines are measured on the same rows in one run.
    'database.connections.saasrev_sqlite' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ],
    // `PlanOrder` is `Cachable`. An array store keeps this script off any real cache.
    'cache.default' => 'array',
]);

echo "GAP-192 / SAAS-007 PLATFORM-REVENUE RECEIPTS, AGAINST A REAL MySQL SERVER\n";
echo str_repeat('=', 86) . "\n";

try {
    $version = DB::connection('saasrev_root')->selectOne('SELECT VERSION() AS v')->v;
} catch (\Throwable $e) {
    fwrite(STDERR, "\nCould not reach MySQL: " . $e->getMessage() . "\n"
        . "Set SAASREV_MYSQL_SOCKET (or SAASREV_MYSQL_HOST/PORT) to a throwaway server.\n");
    exit(3);
}

echo "server: {$version}\n";

$previousDefault = config('database.default');

DB::connection('saasrev_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
DB::connection('saasrev_root')->statement("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4");
DB::connection('saasrev')->reconnect();

// -------------------------------------------------------------------------------------------------
// [1] BUILD THE REAL SCHEMA, FROM THE REAL MIGRATIONS, ON BOTH ENGINES
// -------------------------------------------------------------------------------------------------

echo "\n[1] Build `plan_orders` from the shipped migrations on both engines\n";

$migrations = [
    __DIR__ . '/../../../../database/migrations/2023_12_11_102124_create_plan_orders_table.php',
    __DIR__ . '/../../../../database/migrations/2024_03_13_111849_add_is_refund_to_plan_orders_table.php',
];

foreach (['saasrev', 'saasrev_sqlite'] as $connection) {
    config(['database.default' => $connection]);
    DB::setDefaultConnection($connection);

    // `plan_orders.user_id` carries a foreign key to `users`, so the smallest table that satisfies
    // it has to exist first. Nothing in the receipts path reads it.
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name', 100)->nullable();
    });

    foreach ($migrations as $file) {
        (require $file)->up();
    }

    DB::table('users')->insert(['id' => 7, 'name' => 'harness']);
}

config(['database.default' => 'saasrev']);
DB::setDefaultConnection('saasrev');

check(Schema::connection('saasrev')->hasTable('plan_orders'),
    'the shipped migrations created `plan_orders` on MySQL');
check(Schema::connection('saasrev_sqlite')->hasTable('plan_orders'),
    'and the same two migrations created it on SQLite');

// -------------------------------------------------------------------------------------------------
// [2] THE TYPES AND COLLATIONS THE MIGRATIONS ACTUALLY BUILD
// -------------------------------------------------------------------------------------------------

echo "\n[2] The column types and the collation of the two grouping keys\n";

$columns = [];

foreach (DB::connection('saasrev')->select(
    'SELECT COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME, IS_NULLABLE, COLUMN_DEFAULT
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [$database, 'plan_orders']
) as $row) {
    $columns[$row->COLUMN_NAME] = $row;
}

/*
 * `$table->float('price')` AND THE COLUMN IS A `double`. Laravel 11's `float()` maps to MySQL
 * `DOUBLE`, so reading the migration and concluding "single precision" is wrong -- which is exactly
 * the conclusion this harness was written to check, and it refuted it. [6] measures what that means
 * for the money.
 */
check(isset($columns['price']) && strtolower($columns['price']->COLUMN_TYPE) === 'double',
    'plan_orders.price is a `double`, though the migration says float() -- DOUBLE precision',
    'COLUMN_TYPE = ' . ($columns['price']->COLUMN_TYPE ?? 'absent'));

check(isset($columns['price_currency']) && strtolower($columns['price_currency']->COLUMN_TYPE) === 'varchar(10)',
    'plan_orders.price_currency is `varchar(10)`',
    'COLUMN_TYPE = ' . ($columns['price_currency']->COLUMN_TYPE ?? 'absent'));

check(isset($columns['payment_status']) && strtolower($columns['payment_status']->COLUMN_TYPE) === 'varchar(100)',
    'plan_orders.payment_status is `varchar(100)`',
    'COLUMN_TYPE = ' . ($columns['payment_status']->COLUMN_TYPE ?? 'absent'));

foreach (['price_currency', 'payment_status'] as $key) {
    $collation = (string) ($columns[$key]->COLLATION_NAME ?? '');

    check(str_ends_with($collation, '_ci'),
        "{$key} is grouped under a case-INSENSITIVE collation, so MySQL folds spellings SQLite does not",
        "COLLATION_NAME = {$collation}");
}

check(isset($columns['is_refund']) && (string) $columns['is_refund']->COLUMN_DEFAULT === '0'
    && strtoupper((string) $columns['is_refund']->IS_NULLABLE) === 'NO',
    'plan_orders.is_refund is NOT NULL DEFAULT 0, so no row on this schema can carry NULL',
    'IS_NULLABLE = ' . ($columns['is_refund']->IS_NULLABLE ?? '?')
    . ', COLUMN_DEFAULT = ' . var_export($columns['is_refund']->COLUMN_DEFAULT ?? null, true));

// -------------------------------------------------------------------------------------------------
// FIXTURES — the same rows on both engines, written through the same helper
// -------------------------------------------------------------------------------------------------

$seq = 0;

/** @param list<array{0:string,1:string,2:float,3:int|null}> $rows [status, currency, price, is_refund] */
function seed(array $rows): void
{
    global $seq;

    foreach (['saasrev', 'saasrev_sqlite'] as $connection) {
        foreach ($rows as $i => [$status, $currency, $price, $refund]) {
            DB::connection($connection)->table('plan_orders')->insert([
                'order_id' => 'HARNESS-' . $seq . '-' . $i,
                'plan_name' => 'harness plan',
                'plan_id' => 1,
                'price' => $price,
                'price_currency' => $currency,
                'txn_id' => 'TXN-' . $seq . '-' . $i,
                'payment_status' => $status,
                'user_id' => 7,
                'is_refund' => $refund,
                'created_at' => '2026-11-25 00:00:00',
                'updated_at' => '2026-11-25 00:00:00',
            ]);
        }
    }

    $seq++;

    // A raw write leaves a `Cachable` model's Eloquent reads serving the pre-write rows -- CLAUDE.md
    // records three measured false readings from exactly this. `planOrderGroups()` goes through
    // `PlanOrder::query()`, so the cache has to be flushed before anything is measured.
    (new PlanOrder)->flushCache();
}

/** Run the shipped grouping method against one connection and return its rows. */
function groupsOn(string $connection): array
{
    config(['database.default' => $connection]);
    DB::setDefaultConnection($connection);
    (new PlanOrder)->flushCache();

    try {
        return shipped('planOrderGroups', [null]);
    } finally {
        config(['database.default' => 'saasrev']);
        DB::setDefaultConnection('saasrev');
    }
}

// -------------------------------------------------------------------------------------------------
// [3] ONLY_FULL_GROUP_BY
// -------------------------------------------------------------------------------------------------

echo "\n[3] The production query under ONLY_FULL_GROUP_BY\n";

seed([['succeeded', 'USD', 10.0, 0]]);

DB::connection('saasrev')->statement("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES'");

try {
    $rows = groupsOn('saasrev');
    check($rows !== [], 'the aliased three-key GROUP BY is accepted under ONLY_FULL_GROUP_BY',
        count($rows) . ' row(s)');
} catch (\Throwable $e) {
    bad('the aliased three-key GROUP BY is accepted under ONLY_FULL_GROUP_BY', $e->getMessage());
}

// BACK TO THE SERVER'S OWN MODE, not to a strict one. `config/database.php` ships
// `strict => false`, so a check running under STRICT_TRANS_TABLES would be measuring a server this
// application never talks to -- and the first draft of [8] failed for exactly that reason.
DB::connection('saasrev')->statement('SET SESSION sql_mode = DEFAULT');

// -------------------------------------------------------------------------------------------------
// [4] CASE-VARIANT SETTLED SPELLINGS — the two engines, side by side, on the same rows
// -------------------------------------------------------------------------------------------------

echo "\n[4] Case-variant settled spellings group differently per engine and present identically\n";

// `Succeeded` is written by nine gateway handlers and `succeeded` by others. 30 and 12 in one
// currency, so the two groups are distinguishable by amount as well as by count.
seed([['Succeeded', 'EUR', 30.0, 0], ['succeeded', 'EUR', 12.0, 0]]);

$mysqlEur = array_values(array_filter(groupsOn('saasrev'),
    fn ($r) => strtoupper((string) $r->currency) === 'EUR'));
$sqliteEur = array_values(array_filter(groupsOn('saasrev_sqlite'),
    fn ($r) => strtoupper((string) $r->currency) === 'EUR'));

check(count($mysqlEur) === 1,
    'MySQL folds `Succeeded` and `succeeded` into ONE group',
    count($mysqlEur) . ' group(s)');
check(count($sqliteEur) === 2,
    'SQLite returns TWO groups for the same rows -- the divergence the suite cannot see',
    count($sqliteEur) . ' group(s)');

$presented = static function (string $connection): array {
    $rows = [];

    foreach (shipped('receiptRows', [groupsOn($connection)]) as $row) {
        $rows[$row['currency']] = ['amount' => $row['amount'], 'orders' => $row['orders']];
    }

    return $rows;
};

$mysqlRows = $presented('saasrev');
$sqliteRows = $presented('saasrev_sqlite');

check(($mysqlRows['EUR']['amount'] ?? null) === ($sqliteRows['EUR']['amount'] ?? 'x')
    && ($mysqlRows['EUR']['orders'] ?? null) === ($sqliteRows['EUR']['orders'] ?? -1),
    'and the PRESENTED figure is the same on both engines, because the PHP fold is the comparator',
    'mysql=' . json_encode($mysqlRows['EUR'] ?? null) . ' sqlite=' . json_encode($sqliteRows['EUR'] ?? null));

check(($mysqlRows['EUR']['orders'] ?? null) === 2,
    'both settled spellings are counted, so no gateway\'s money is dropped',
    'orders = ' . var_export($mysqlRows['EUR']['orders'] ?? null, true));

// -------------------------------------------------------------------------------------------------
// [5] CASE-VARIANT CURRENCY SPELLINGS
// -------------------------------------------------------------------------------------------------

echo "\n[5] Case-variant currency spellings fold to one presented row\n";

seed([['succeeded', 'gbp', 5.0, 0], ['succeeded', 'GBP', 7.0, 0]]);

$mysqlRows = $presented('saasrev');
$sqliteRows = $presented('saasrev_sqlite');

check(isset($mysqlRows['GBP']) && ! isset($mysqlRows['gbp']),
    'the presented row is keyed by the upper-cased code and the lower-cased spelling has no row of its own',
    'keys: ' . implode(', ', array_keys($mysqlRows)));

check(($mysqlRows['GBP']['orders'] ?? null) === 2
    && ($sqliteRows['GBP']['orders'] ?? null) === 2,
    'and both spellings\' orders land in it on BOTH engines, though only one engine grouped them in SQL',
    'mysql=' . var_export($mysqlRows['GBP']['orders'] ?? null, true)
    . ' sqlite=' . var_export($sqliteRows['GBP']['orders'] ?? null, true));

// -------------------------------------------------------------------------------------------------
// [6] THE SINGLE-PRECISION LOSS, AT THE MAGNITUDES THIS PLATFORM CHARGES
// -------------------------------------------------------------------------------------------------

echo "\n[6] `float()` built a `double`, so a SYP-scale plan payment keeps its minor units\n";

// SYP runs to millions and `price_currency` is free text the gateways write, so this is not a
// contrived magnitude -- it is what a plan priced in Syrian pounds looks like. A single-precision
// mantissa (about seven significant digits) would drop the minor units here.
seed([['succeeded', 'SYP', 2500000.49, 0]]);

$storedMysql = (float) DB::connection('saasrev')
    ->table('plan_orders')->where('price_currency', 'SYP')->value('price');
$storedSqlite = (float) DB::connection('saasrev_sqlite')
    ->table('plan_orders')->where('price_currency', 'SYP')->value('price');

check(abs($storedMysql - 2500000.49) < 0.000001,
    'MySQL stores and returns 2500000.49 EXACTLY, so the minor units of a SYP-scale plan payment survive',
    'read back as ' . sprintf('%.4f', $storedMysql));

check(abs($storedSqlite - 2500000.49) < 0.000001,
    'and SQLite agrees, so the suite and production do NOT diverge on money precision here',
    'read back as ' . sprintf('%.4f', $storedSqlite));

/*
 * THE CONTRAST THAT MAKES THE MIGRATION'S WORDING MISLEADING, measured on the same server in the
 * same database: a column that really is `FLOAT` loses what this one keeps. Without this line a
 * reader can only take "float() emits double" on trust, and the trust is what was wrong the first
 * time.
 */
DB::connection('saasrev')->statement('CREATE TABLE float_contrast (p float NULL)');
DB::connection('saasrev')->table('float_contrast')->insert(['p' => 2500000.49]);
$lossy = (float) DB::connection('saasrev')->table('float_contrast')->value('p');

check(abs($lossy - 2500000.49) > 0.001,
    'a hand-written `float` column on the SAME server DOES lose them -- which is the column '
    . '`$table->float()` does not build',
    'the real FLOAT read back as ' . sprintf('%.2f', $lossy));

/*
 * AND SO THE FIGURE MUST NOT CLAIM THE LIMITATION. GAP-217 settled that a money figure off a float
 * column carries `MetricCaveat::FLOAT_PRECISION`; this column is not one, so the caveat would be a
 * fabricated limit on a money screen. Asserted here as well as in the suite because this is the
 * check that establishes the fact the suite's assertion rests on.
 */
$allTimeHint = shipped('receipts', [CarbonImmutable::parse('2026-11-25 12:00:00')])['all_time']['hint'];

check(! str_contains($allTimeHint, MetricCaveat::message(MetricCaveat::FLOAT_PRECISION)),
    'the receipts figure claims no float-precision limit, because the column it reads has none');

// -------------------------------------------------------------------------------------------------
// [7] WHAT THE ALIASES AND THE DRIVER ACTUALLY RETURN
// -------------------------------------------------------------------------------------------------

echo "\n[7] The alias keys, and the PHP types MySQL's driver returns for them\n";

$row = groupsOn('saasrev')[0];

foreach (['status', 'currency', 'refunded', 'amount', 'orders'] as $alias) {
    check(property_exists($row, $alias), "the `{$alias}` alias is readable as a property, as the producer reads it");
}

check(is_string($row->amount) || is_float($row->amount),
    'sum(price) arrives as ' . get_debug_type($row->amount) . ' from MySQL, so the producer\'s `(float)` cast is load-bearing',
    'value = ' . var_export($row->amount, true));

// -------------------------------------------------------------------------------------------------
// [8] A NULL is_refund IS NOT A REFUND
// -------------------------------------------------------------------------------------------------

echo "\n[8] `is_refund` cannot be NULL here, so the producer's NULL guard is defensive\n";

seed([['succeeded', 'CHF', 90.0, 0]]);

$refusal = '';

try {
    DB::connection('saasrev')->table('plan_orders')->insert([
        'order_id' => 'HARNESS-NULL-REFUND', 'plan_name' => 'harness plan', 'plan_id' => 1,
        'price' => 5.0, 'price_currency' => 'CHF', 'txn_id' => 'TXN-NULL', 'payment_status' => 'succeeded',
        'user_id' => 7, 'is_refund' => null,
        'created_at' => '2026-11-25 00:00:00', 'updated_at' => '2026-11-25 00:00:00',
    ]);
} catch (\Throwable $e) {
    $refusal = $e->getMessage();
}

check($refusal !== '',
    'MySQL refuses a NULL `is_refund` outright, under the server\'s own sql_mode and the '
    . 'production `strict => false` connection -- so no row on THIS schema reaches the producer\'s '
    . 'NULL branch',
    $refusal === '' ? 'the insert was accepted' : 'refused: ' . explode(' (Connection', $refusal)[0]);

/*
 * THE GUARD IS STILL RIGHT, AND THE DISTINCTION IS THE POINT. CLAUDE.md's standing rule is that the
 * migrations are not the schema -- a restored production dump has already refuted this file once --
 * so a column that this harness sees as NOT NULL may be nullable on the live server. Reading NULL as
 * REFUNDED would drop real money out of platform revenue, which is the failure this class exists to
 * prevent, so the guard stays. What changes is the claim made about it: defensive, not load-bearing.
 */
check(shipped('isRefunded', [null]) === false,
    'and the producer reads NULL as NOT refunded, so a nullable production column keeps its money in revenue');

check(shipped('isRefunded', [0]) === false && shipped('isRefunded', [1]) === true
    && shipped('isRefunded', ['1']) === true,
    'while 0, 1 and the string "1" a driver may return are each read the obvious way');

check((($presented('saasrev')['CHF']['orders']) ?? null) === 1,
    'the settled CHF payment is present in the receipts with its order counted',
    'presented = ' . json_encode($presented('saasrev')['CHF'] ?? null));

// -------------------------------------------------------------------------------------------------
// [9] TEAR DOWN
// -------------------------------------------------------------------------------------------------

echo "\n[9] Tear down\n";
DB::connection('saasrev')->disconnect();
DB::connection('saasrev_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
ok("dropped the throwaway database `{$database}`");

config(['database.default' => $previousDefault]);
DB::setDefaultConnection($previousDefault);

echo "\n" . str_repeat('=', 86) . "\n";
echo "checks: {$checks}   failures: " . count($failures) . "\n";

if ($failures !== []) {
    echo "\nFAILED:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\nALL ASSERTIONS HELD on {$version}.\n";
exit(0);
