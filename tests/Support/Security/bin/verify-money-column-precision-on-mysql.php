<?php

/**
 * `MetricCaveat::FLOAT_PRECISION` — IS ITS STATED BASIS TRUE ON A REAL SERVER?
 * =================================================================================================
 *
 * THE CLAIM UNDER TEST, verbatim from `MetricCaveat.php`: "The figure comes off
 * `orders.final_price`, which is FLOAT -- about seven significant digits on a currency that runs to
 * millions." The sentence it produces reaches a merchant on the dashboard's headline `Order value`
 * tile, on `/analytics`' coupon-discount tile, on four report screens and in five export files.
 *
 * WHY IT HAD TO BE MEASURED RATHER THAN REASONED ABOUT. CLAUDE.md records this exact withdrawal
 * having happened once already: `$table->float()` in a Laravel migration emits a MySQL **`double`**,
 * not a `float`, so `2500000.49` round-trips exactly -- and a caveat attached to
 * `plan_orders.price` on the float reasoning was attached and then withdrawn on that measurement
 * (`GAP-192`). The same `$table->float()` declares `orders.final_price`
 * (`2024_01_23_061635_create_orders_table.php:26`) and `order_coupon_details.coupon_discount_amount`
 * (`2024_02_07_090758_create_order_coupon_details_table.php:25`). So the caveat's reasoning was
 * never checked against the engine for these two columns.
 *
 * WHAT THIS SCRIPT ASKS, in the order the question actually has to be asked:
 *
 *   A. WHAT TYPE DOES THE MIGRATION REALLY EMIT? Built through Laravel's own schema builder with
 *      the migrations' exact declarations, and read back from `information_schema`.
 *
 *   B. DOES A SINGLE MONEY VALUE ROUND-TRIP? Five amounts across the range a Syrian-pound or
 *      dollar store reaches, written and read through Eloquent.
 *
 *   C. AND THE CONTRAST CASE, so a pass here cannot be mistaken for "floating point is exact": the
 *      SAME amounts into a hand-written `FLOAT` column, which is what the caveat's wording
 *      describes.
 *
 *   D. DOES AN ACCUMULATED SUM DIVERGE? This is the question the caveat should have been asking.
 *      `double` is still binary floating point, and every one of these figures is accumulated --
 *      in PHP as `+= (float) $order->final_price`, or in SQL as `SUM()`. A per-VALUE round trip
 *      says nothing about a sum over thousands of rows. Both accumulations are compared against
 *      the exact decimal total, and the comparison is at the SMALLEST UNIT the caveat names ("may
 *      differ from the ledger in the smallest units"), not at an arbitrary epsilon.
 *
 *   E. THE LEDGER COMPARISON THE CAVEAT TELLS A MERCHANT TO MAKE. `store_order_financials` is
 *      `DECIMAL(15,2)` and the caveat says the figure "may differ from the ledger in the smallest
 *      units". So the same amounts go into a `decimal(15,2)` column and the two sums are compared.
 *
 * SAFETY. It creates and drops its own database and refuses a name without `verify` in it. Never
 * point it at a development or production server.
 *
 * THE LIMIT THIS SCRIPT CANNOT REMOVE, AND IT IS THE REASON ITS ANSWER IS NOT THE WHOLE ANSWER:
 * this is what a FRESH schema from these migrations emits. CLAUDE.md's standing rule is that the
 * migrations are not the schema -- a column altered by hand, or created by an older migration on a
 * server that has since been upgraded, can be a real `float`. Establishing production's own type
 * needs `SHOW CREATE TABLE orders` on production, which no session here has. Section C exists so
 * that answer is already measured when somebody runs it: if production says `float`, the caveat's
 * original wording is correct there and C says exactly what it costs.
 *
 * USAGE
 *   php tests/Support/Security/bin/verify-money-column-precision-on-mysql.php
 *   MONEYPREC_MYSQL_SOCKET=/run/mysqld/mysqld.sock php .../verify-money-column-precision-on-mysql.php
 *
 * Exit: 0 = every check held · 1 = a check failed · 2 = a precondition was not met.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

/*
 * The session driver is set BEFORE the framework boots, for the reason the sibling scripts record:
 * `config/session.php` reads `env('SESSION_DRIVER', 'database')` while the config is loaded, so in
 * an environment with no `.env` the default sqlite connection can touch the session table before
 * this script points anything at MySQL.
 */
putenv('SESSION_DRIVER=array');
$_ENV['SESSION_DRIVER'] = 'array';
$_SERVER['SESSION_DRIVER'] = 'array';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('MONEYPREC_MYSQL_DATABASE') ?: ('money_precision_verify_' . getmypid());

if (! preg_match('/verify/i', $database)) {
    fwrite(STDERR, "Refusing a database whose name does not say it is throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('MONEYPREC_MYSQL_SOCKET');

if ($socket === false) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;

            break;
        }
    }
}

config([
    'database.connections.verify' => [
        'driver' => 'mysql',
        'host' => getenv('MONEYPREC_MYSQL_HOST') ?: '127.0.0.1',
        'port' => getenv('MONEYPREC_MYSQL_PORT') ?: '3306',
        'unix_socket' => $socket ?: '',
        'database' => null,
        'username' => getenv('MONEYPREC_MYSQL_USER') ?: 'root',
        'password' => getenv('MONEYPREC_MYSQL_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'strict' => false,
    ],
]);

$failed = 0;
$checks = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failed, $checks;

    $checks++;

    if (! $ok) {
        $failed++;
    }

    printf("  %-5s %-58s %s\n", $ok ? 'ok' : 'FAIL', $what, $detail);
}

try {
    DB::connection('verify')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('verify')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not reach MySQL: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Set MONEYPREC_MYSQL_SOCKET (or MONEYPREC_MYSQL_HOST/PORT) to a throwaway server.\n");
    exit(2);
}

config(['database.connections.verify.database' => $database]);
DB::purge('verify');

$server = DB::connection('verify')->selectOne('SELECT VERSION() AS v')->v;

echo "MetricCaveat::FLOAT_PRECISION -- is its stated basis true?\n";
echo "=========================================================\n";
echo "Server:   {$server}\n";
echo "Database: {$database}\n\n";

/* THE AMOUNTS. Chosen to span what these stores really hold: a cent, a hundred, a million-scale
   Syrian-pound total, and the two values CLAUDE.md's own earlier measurement used. */
$amounts = ['0.01', '99.99', '999999.99', '1234567.89', '2500000.49'];

echo "A. What the migrations' own declarations emit\n";

Schema::connection('verify')->create('money_as_migrated', function ($table) {
    $table->id();
    /* `2024_01_23_061635_create_orders_table.php:26` and
       `2024_02_07_090758_create_order_coupon_details_table.php:25`, verbatim. */
    $table->float('final_price')->nullable()->default(0);
    $table->float('coupon_discount_amount')->default(0);
    /* `store_order_financials` is DECIMAL(15,2) -- the thing the caveat tells a merchant to
       compare against. */
    $table->decimal('ledger_amount', 15, 2)->default(0);
});

Schema::connection('verify')->create('money_as_worded', function ($table) {
    $table->id();
});

/* A REAL `FLOAT`, written by hand, because Laravel's builder will not emit one. This is the column
   the caveat's wording describes, and section C is what it costs. */
DB::connection('verify')->statement('ALTER TABLE money_as_worded ADD COLUMN true_float FLOAT NULL');

$types = [];

foreach (DB::connection('verify')->select(
    'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?)',
    [$database, 'money_as_migrated', 'money_as_worded']
) as $row) {
    $types[$row->TABLE_NAME . '.' . $row->COLUMN_NAME] = $row;
}

foreach (['final_price', 'coupon_discount_amount'] as $column) {
    $row = $types['money_as_migrated.' . $column] ?? null;

    check(
        "{$column}: \$table->float() emits",
        $row !== null && strtolower($row->DATA_TYPE) === 'double',
        $row === null ? '(column missing)' : "DATA_TYPE={$row->DATA_TYPE} COLUMN_TYPE={$row->COLUMN_TYPE} PRECISION={$row->NUMERIC_PRECISION}"
    );
}

$worded = $types['money_as_worded.true_float'] ?? null;
check(
    'the contrast column really is a FLOAT',
    $worded !== null && strtolower($worded->DATA_TYPE) === 'float',
    $worded === null ? '(column missing)' : "DATA_TYPE={$worded->DATA_TYPE} PRECISION={$worded->NUMERIC_PRECISION}"
);

echo "\nB. Does a single money value round-trip through the migrated columns?\n";

foreach ($amounts as $amount) {
    DB::connection('verify')->table('money_as_migrated')->insert([
        'final_price' => $amount,
        'coupon_discount_amount' => $amount,
        'ledger_amount' => $amount,
    ]);
}

$rows = DB::connection('verify')->table('money_as_migrated')->orderBy('id')->get();

foreach ($amounts as $i => $amount) {
    $got = $rows[$i];

    check(
        "final_price round trip of {$amount}",
        (string) (float) $got->final_price === (string) (float) $amount,
        "got {$got->final_price}"
    );
    check(
        "coupon_discount_amount round trip of {$amount}",
        (string) (float) $got->coupon_discount_amount === (string) (float) $amount,
        "got {$got->coupon_discount_amount}"
    );
}

echo "\nC. The contrast: the SAME amounts into a real FLOAT column\n";
echo "   (a pass in B is not 'floating point is exact' -- this is what the caveat's wording describes)\n";

$lost = 0;

foreach ($amounts as $amount) {
    DB::connection('verify')->table('money_as_worded')->insert(['true_float' => $amount]);
}

foreach (DB::connection('verify')->table('money_as_worded')->orderBy('id')->get() as $i => $got) {
    $same = (string) (float) $got->true_float === (string) (float) $amounts[$i];

    if (! $same) {
        $lost++;
    }

    printf("        %-14s -> %-14s %s\n", $amounts[$i], (string) (float) $got->true_float, $same ? '' : '<- LOST');
}

check(
    'a real FLOAT does lose money at these scales',
    $lost > 0,
    "{$lost} of " . count($amounts) . ' amounts changed. If this is 0 the contrast case is broken '
    . 'and section B proves nothing.'
);

echo "\nD. Does an ACCUMULATED sum diverge? (the question the caveat should have asked)\n";

Schema::connection('verify')->create('many_orders', function ($table) {
    $table->id();
    $table->float('final_price')->nullable()->default(0);
    $table->decimal('ledger_amount', 15, 2)->default(0);
});

/* Ten thousand rows of the one amount binary floating point cannot represent: 0.07 has no exact
   binary form, so if an accumulated double is going to drift, this is where. A second block uses
   realistic mixed amounts. */
$rows = [];
$exactCents = 0;

for ($i = 0; $i < 10000; $i++) {
    $amount = ['0.07', '19.99', '1234.56', '99999.95'][$i % 4];
    $rows[] = ['final_price' => $amount, 'ledger_amount' => $amount];
    $exactCents += (int) round(((float) $amount) * 100);

    if (count($rows) === 1000) {
        DB::connection('verify')->table('many_orders')->insert($rows);
        $rows = [];
    }
}

if ($rows !== []) {
    DB::connection('verify')->table('many_orders')->insert($rows);
}

$sqlDouble = (float) DB::connection('verify')->table('many_orders')->sum('final_price');
$sqlDecimal = (string) DB::connection('verify')->table('many_orders')->sum('ledger_amount');

/* PHP's own accumulation, which is what `ReportController` and the figures classes really do:
   `$total += (float) $order->final_price` over the rows. */
$phpDouble = 0.0;

foreach (DB::connection('verify')->table('many_orders')->orderBy('id')->cursor() as $row) {
    $phpDouble += (float) $row->final_price;
}

$exact = $exactCents / 100;

printf("        exact decimal total          %s\n", number_format($exact, 2, '.', ''));
printf("        SQL SUM() over the double    %s\n", number_format($sqlDouble, 2, '.', ''));
printf("        PHP += over the double       %s\n", number_format($phpDouble, 2, '.', ''));
printf("        SQL SUM() over DECIMAL(15,2) %s\n", $sqlDecimal);

$smallestUnit = 0.005;

check(
    'SQL SUM() over the double agrees to the smallest unit',
    abs($sqlDouble - $exact) < $smallestUnit,
    sprintf('difference %.10f over 10,000 rows', $sqlDouble - $exact)
);
check(
    'PHP accumulation over the double agrees to the smallest unit',
    abs($phpDouble - $exact) < $smallestUnit,
    sprintf('difference %.10f over 10,000 rows', $phpDouble - $exact)
);

echo "\nE. The ledger comparison the caveat tells a merchant to make\n";

check(
    'the DECIMAL(15,2) ledger sum equals the exact decimal total',
    abs(((float) $sqlDecimal) - $exact) < 0.0000001,
    "ledger {$sqlDecimal} vs exact " . number_format($exact, 2, '.', '')
);
check(
    'and the double-column sum equals the ledger sum to the cent',
    round($sqlDouble, 2) === round((float) $sqlDecimal, 2),
    sprintf('double %.2f vs ledger %.2f', $sqlDouble, (float) $sqlDecimal)
);

DB::connection('verify')->statement("DROP DATABASE IF EXISTS `{$database}`");

echo "\nthrowaway database dropped\n\n";
printf("%d checks, %d failed\n", $checks, $failed);

exit($failed === 0 ? 0 : 1);
