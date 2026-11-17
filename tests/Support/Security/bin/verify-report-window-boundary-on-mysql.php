<?php

/**
 * P5-3b -- THE MERCHANT SALES REPORT SILENTLY DROPS THE LAST DAY OF THE PERIOD.
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST, and the reason is the finding rather than an excuse:
 * **`ReportController` CANNOT BE EXECUTED BY THIS PROJECT'S TEST SUITE AT ALL.** It calls
 * `MONTH()` 36 times and `YEAR()` 9 times inside `selectRaw`, and neither function exists in
 * SQLite -- measured: `SELECT MONTH('2026-11-30')` answers
 * `SQLSTATE[HY000]: General error: 1 no such function: MONTH`. A Feature test driving
 * `GET order-reports-chart` therefore gets HTTP 500 on every one of the suite's 9,112 tests'
 * engine, which is exactly how a 3,660-line controller with zero payment predicates and an
 * eighteen-site date-truncation bug came to be shipped unexercised.
 *
 * THE DEFECT. Three period branches of two handlers, plus the whole customer report, bound the
 * window with
 *
 *     ->where('order_date', '<=', date('Y-m-t', $end))
 *
 * a BARE DATE STRING against a DATETIME column, which MySQL casts to midnight. So
 * `order_date <= '2026-10-31'` admits an order placed at 2026-10-31 00:00:00 and refuses one
 * placed at 00:00:01.
 *
 * `date('Y-m-t')` for *this month* and *year* resolves to a date in the future for most of the
 * period, so there the truncation only bites on the final day itself. For **last month** it always
 * resolves to a date already past, so the last day of trading is missing from that report **every
 * single month**. The same file already contains the correct idiom at :260-261 and :269-297, which
 * use `whereDate()`.
 *
 * WHAT IT DOES. Boots the application against a throwaway MySQL database, seeds a store with
 * orders placed at the exact boundary, logs in the store's owner and calls the real handler, then
 * asserts on the JSON it returns. The controller is called directly rather than over HTTP because
 * the defect is in its query, not in its middleware -- and `ReportPermission` is covered by its own
 * tests.
 *
 * It never touches a database it did not create: the name carries a `report_boundary_verify_`
 * prefix plus the process id, and any name that does not say it is throwaway is refused.
 *
 * Usage:
 *   php tests/Support/Security/bin/verify-report-window-boundary-on-mysql.php
 *
 * Exit status: 0 = every check passed · 1 = a check failed · 2 = a precondition was not met.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\ReportController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: ('report_boundary_verify_' . getmypid());

if (! preg_match('/verify/i', $database)) {
    fwrite(STDERR, "Refusing a database whose name does not say it is throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $c) {
        if (file_exists($c)) { $socket = $c; break; }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('FRESH_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('FRESH_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('FRESH_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
];

$bootstrapConnection = $connection;
$bootstrapConnection['database'] = null;

config(['database.connections.rb_bootstrap' => $bootstrapConnection]);
config(['database.connections.rb_fresh' => $connection]);

$checks = 0; $failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok    {$what}\n"; return; }
    $failures++;
    echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }

try {
    DB::connection('rb_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('rb_bootstrap')->statement(
        "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$exit = 0;

try {
    config(['database.default' => 'rb_fresh']);
    DB::purge('rb_fresh');

    echo "Server: " . DB::connection('rb_fresh')->selectOne('SELECT VERSION() v')->v . "\n";
    echo "Database: {$database}\n\n";

    echo "Running migrations...\n";
    Artisan::call('migrate', ['--database' => 'rb_fresh', '--force' => true]);
    echo "  done\n\n";

    // ---------------------------------------------------------------- the SQLite premise

    echo "A. Why this cannot be a Feature test\n";

    $sqlite = new PDO('sqlite::memory:');
    $monthMissing = false;
    try { $sqlite->query("SELECT MONTH('2026-11-30')"); }
    catch (\Throwable $e) { $monthMissing = str_contains($e->getMessage(), 'no such function'); }
    check('SQLite -- the suite\'s engine -- has no MONTH(), so the handler 500s there', $monthMissing);

    /*
     * COUNTED IN CODE ONLY, NOT IN PROSE. The first version of this counted every `MONTH(` in the
     * file, which included the explanatory comment the fix added to the controller -- so the
     * script reported 37/10 where the code holds 36/9 and the number drifted with its own
     * documentation. That is the stale-figure trap this repository keeps finding; comment and
     * docblock lines are stripped before counting.
     */
    $source = file_get_contents(__DIR__ . '/../../../../app/Http/Controllers/ReportController.php');
    $code = implode("\n", array_filter(
        explode("\n", $source),
        static fn (string $line): bool => ! preg_match('#^\s*(\*|//|/\*)#', $line)
    ));
    $monthCalls = preg_match_all('/[^A-Z_]MONTH\(/', $code);
    $yearCalls = preg_match_all('/[^A-Z_]YEAR\(/', $code);
    check(
        "ReportController calls MONTH()/YEAR() ({$monthCalls}/{$yearCalls}), so the whole controller is suite-unreachable",
        $monthCalls > 0 && $yearCalls > 0
    );

    // ---------------------------------------------------------------- fixture

    $owner = new User();
    $owner->name = 'Boundary owner';
    $owner->email = 'boundary-owner@example.test';
    $owner->password = bcrypt('x');
    $owner->type = 'admin';
    $owner->save();

    DB::table('stores')->insert([
        'id' => 1, 'name' => 'Boundary store', 'slug' => 'boundary-store',
        'email' => 'boundary@example.test', 'theme_id' => 'stylique',
        'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('stores')->insert([
        'id' => 2, 'name' => 'Other store', 'slug' => 'other-store',
        'email' => 'other@example.test', 'theme_id' => 'stylique',
        'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $owner->current_store = 1;
    $owner->save();

    $seq = 0;
    $place = function (Carbon $when, float $amount, int $storeId = 1) use (&$seq): void {
        $seq++;
        DB::table('orders')->insert([
            'product_order_id' => 'RB-' . $seq,
            'product_json' => json_encode([]),
            'product_price' => $amount, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => $amount,
            'currency_code' => 'USD',
            'payment_type' => 'cod', 'payment_status' => 'Paid',
            'delivered_status' => 1,
            'store_id' => $storeId,
            'order_date' => $when->format('Y-m-d H:i:s'),
            'created_at' => $when->format('Y-m-d H:i:s'),
            'updated_at' => $when->format('Y-m-d H:i:s'),
        ]);
    };

    $lastMonth = Carbon::now()->subMonthNoOverflow();
    $firstMoment = $lastMonth->copy()->startOfMonth()->setTime(0, 0, 0);
    $midMonth = $lastMonth->copy()->startOfMonth()->addDays(14)->setTime(10, 0);
    $finalDayMidnight = $lastMonth->copy()->endOfMonth()->setTime(0, 0, 0);
    $finalDayTrading = $lastMonth->copy()->endOfMonth()->setTime(14, 0);
    $finalDayLastSecond = $lastMonth->copy()->endOfMonth()->setTime(23, 59, 59);
    $dayAfter = $lastMonth->copy()->endOfMonth()->addDay()->setTime(12, 0);
    $dayBefore = $lastMonth->copy()->startOfMonth()->subDay()->setTime(12, 0);

    $place($firstMoment, 5.0);            // the lower bound, which is already correct
    $place($midMonth, 100.0);             // never in doubt
    $place($finalDayMidnight, 7.0);       // the ONE final-day order the bare bound admits
    $place($finalDayTrading, 70.0);       // dropped today
    $place($finalDayLastSecond, 13.0);    // dropped today
    $place($dayAfter, 900.0);             // outside: must stay outside
    $place($dayBefore, 800.0);            // outside: must stay outside
    $place($finalDayTrading, 5000.0, 2);  // another tenant: must stay out

    echo "\nB. The figure the merchant is shown for 'last month'\n";
    echo "   seeded in-period: 5.00 + 100.00 + 7.00 + 70.00 + 13.00 = 195.00\n";
    echo "   seeded outside:   900.00 (day after) + 800.00 (day before) + 5000.00 (store 2)\n\n";

    Auth::login($owner);

    $grossFor = function (string $period) use ($owner): ?float {
        Auth::login($owner);

        $request = Request::create('/order-reports-chart', 'GET', ['chart_data' => $period]);
        app()->instance('request', $request);

        $response = (new ReportController())->order_reports_chart($request);
        $payload = json_decode($response->getContent(), true);

        if (! is_array($payload) || ! isset($payload['GrossSaleTotal'])) {
            return null;
        }

        return round(array_sum(array_map('floatval', (array) $payload['GrossSaleTotal'])), 2);
    };

    $gross = $grossFor('last-month');

    if ($gross === null) {
        check('the handler returned a gross series', false, 'no GrossSaleTotal in the response');
    } else {
        echo "   handler returned: {$gross}\n\n";

        check('every in-period order is counted, the final day included (expect 195.00)',
            near($gross, 195.0),
            "got {$gross}. The shortfall is the final day: 70.00 + 13.00 = 83.00 is dropped by "
            . "`where('order_date','<=', date('Y-m-t'))`, which MySQL reads as midnight.");

        check('no order from outside the period leaked in',
            $gross < 900.0,
            "got {$gross}, which is at least the 900.00 order placed the day after the period");

        check('no other tenant\'s order leaked in',
            $gross < 5000.0,
            "got {$gross}, which reaches store 2's 5,000.00");
    }

    // ---------------------------------------------------------------- C. the category report

    echo "\nC. The sales-by-category report for a SINGLE day\n";

    /*
     * A different defect in the same family, and a worse one: `DatePeriod`'s end is EXCLUSIVE, and
     * the category report was the only one of the three per-dimension reports that did not add a
     * day to compensate -- the product report did it inline and the brand report on the line
     * before. Measured: `DatePeriod('2026-03-05', P1D, '2026-03-05')` iterates ZERO times, so
     * `$monthList` is empty, every series is `array_fill(0, 0, 0)`, and the
     * `array_search($orderDate, $monthList)` that places each line fails for every order.
     *
     * A merchant selecting ONE day therefore got an empty report rather than one day's sales --
     * and `$request->Date` with no ' to ' in it sets from_date = to_date, which is exactly what a
     * single-date picker posts. The orders were fetched and then silently discarded.
     */
    // Columns read off the migrations rather than guessed: `categories` has no `created_by`,
    // and `products` requires `name`, `track_stock` and `created_by`.
    $categoryId = DB::table('categories')->insertGetId([
        'name' => 'Boundary category', 'slug' => 'boundary-category',
        'store_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'name' => 'Boundary product', 'slug' => 'boundary-product',
        'category_id' => $categoryId, 'store_id' => 1, 'created_by' => $owner->id,
        'track_stock' => 0, 'price' => 60.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $theDay = Carbon::now()->subDays(3)->setTime(11, 0);

    DB::table('orders')->insert([
        'product_order_id' => 'RB-CAT-1',
        'product_json' => json_encode([[
            'product_id' => $productId, 'qty' => 2, 'final_price' => 60.0,
        ]]),
        'product_price' => 60.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
        'coupon_price' => 0.0, 'final_price' => 60.0,
        'currency_code' => 'USD', 'payment_type' => 'cod', 'payment_status' => 'Paid',
        'delivered_status' => 1, 'store_id' => 1,
        'order_date' => $theDay->format('Y-m-d H:i:s'),
        'created_at' => $theDay->format('Y-m-d H:i:s'),
        'updated_at' => $theDay->format('Y-m-d H:i:s'),
    ]);

    Auth::login($owner);
    $catRequest = Request::create('/category-order-reports', 'GET', [
        'Date' => $theDay->format('Y-m-d'),
    ]);
    app()->instance('request', $catRequest);

    $catResponse = (new ReportController())->order_category_reports($catRequest);
    $catPayload = json_decode($catResponse->getContent(), true);

    $series = $catPayload['NetSaleTotal'] ?? null;
    $dayCount = is_array($catPayload['monthList'] ?? null) ? count($catPayload['monthList']) : 0;

    echo "   day list length: {$dayCount} (0 means the report has no columns at all)\n";

    check('a single-day selection produces ONE day column, not zero',
        $dayCount === 1, "got {$dayCount}");

    $categoryTotal = 0.0;
    if (is_array($series)) {
        foreach ($series as $row) {
            if (($row['name'] ?? null) === 'Boundary category') {
                $categoryTotal = round(array_sum(array_map('floatval', (array) ($row['data'] ?? []))), 2);
            }
        }
    }

    check('the day\'s 60.00 of category sales is reported, not discarded',
        near($categoryTotal, 60.0),
        'got ' . var_export($categoryTotal, true)
        . ' -- the orders are fetched and then dropped by array_search() against an empty day list');

    // ---------------------------------------------------------------- D. the brand year card

    echo "\nD. The sales-by-brand YEAR card\n";

    /*
     * A third defect in the same family and the quietest of them. In the year branch the loop is
     * months-outer / brands-inner over `yearMonth()`'s TWELVE entries, and the card total was
     * computed BEFORE the current month was written into the series:
     *
     *     $NetSalesofbrand[$brand->name] = array_sum($brandNetSales[$brand->name]);   // summed
     *     $brandNetSales[$brand->name][$index] = $totalNetSales;                      // then set
     *
     * so on the final iteration the card summed indices 0..10 and **December was missing from
     * every brand card, every year**. The CHART beside it was always right -- it walks
     * `$brandNetSales` after the loop finishes -- so the card and the chart disagreed, which is
     * what made the defect visible without explaining it. That disagreement is the assertion
     * below: the card must equal the series it sits next to.
     */
    $brandId = DB::table('product_brands')->insertGetId([
        'name' => 'Boundary brand', 'slug' => 'boundary-brand',
        'store_id' => 1, 'created_by' => $owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $brandProductId = DB::table('products')->insertGetId([
        'name' => 'Boundary brand product', 'slug' => 'boundary-brand-product',
        'brand_id' => $brandId, 'store_id' => 1, 'created_by' => $owner->id,
        'track_stock' => 0, 'price' => 1.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // January and DECEMBER -- the first and the LAST entry of the twelve-month list.
    foreach ([['01', 40.0], ['12', 70.0]] as [$month, $amount]) {
        $when = Carbon::createFromFormat('Y-m-d H:i:s', date('Y') . "-{$month}-10 12:00:00");
        DB::table('orders')->insert([
            'product_order_id' => "RB-BRAND-{$month}",
            'product_json' => json_encode([[
                'product_id' => $brandProductId, 'qty' => 1, 'final_price' => $amount,
            ]]),
            'product_price' => $amount, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => $amount,
            'currency_code' => 'USD', 'payment_type' => 'cod', 'payment_status' => 'Paid',
            'delivered_status' => 1, 'store_id' => 1,
            'order_date' => $when->format('Y-m-d H:i:s'),
            'created_at' => $when->format('Y-m-d H:i:s'),
            'updated_at' => $when->format('Y-m-d H:i:s'),
        ]);
    }

    /*
     * FLUSH THE MODEL CACHES BEFORE READING, and this is not belt-and-braces -- it is the
     * difference between 0.00 and 110.00, measured. `Product` and `ProductBrand` are `Cachable`
     * (79 of the 95 models are), these fixtures are written with raw `DB::table()` inserts, and a
     * raw write does NOT invalidate the query cache. Section C above already populated it with
     * `Product::find()` and the category lookups, so the brand report's own
     * `Product::find($product['product_id'])` served a stale miss and attributed nothing: the
     * series read 0.00 while a standalone probe of the identical code and data read 110.00.
     *
     * That is exactly the false reading CLAUDE.md's model-caching note describes, and it would
     * have made this whole section report a defect that was not there.
     */
    foreach ([\App\Models\Product::class, \App\Models\ProductBrand::class, \App\Models\Category::class] as $model) {
        if (method_exists($model, 'flushCache')) {
            (new $model())->flushCache();
        }
    }

    Auth::login($owner);
    $brandRequest = Request::create('/brand-order-reports', 'GET', ['chart_data' => 'year']);
    app()->instance('request', $brandRequest);

    $brandResponse = (new ReportController())->order_brand_reports($brandRequest);
    $brandPayload = json_decode($brandResponse->getContent(), true);

    $seriesSum = 0.0;
    foreach (($brandPayload['NetSaleTotal'] ?? []) as $row) {
        if (($row['name'] ?? null) === 'Boundary brand') {
            $seriesSum = round(array_sum(array_map('floatval', (array) ($row['data'] ?? []))), 2);
        }
    }

    // The card lives in the rendered HTML, not the JSON -- `$NetSalesofbrand` is passed to the
    // view. Read the value that follows the brand's own label.
    $cardTotal = null;
    $html = $brandPayload['html'] ?? '';
    if (preg_match(
        '/ss-stat__label">\s*Boundary brand\s*<\/span>\s*<span class="ss-stat__value">\s*[^0-9]*([0-9,]+(?:\.[0-9]+)?)/s',
        $html,
        $m
    )) {
        $cardTotal = (float) str_replace(',', '', $m[1]);
    }

    echo "   twelve-month list: " . count($brandPayload['monthList'] ?? []) . " entries\n";
    echo "   chart series sum:  {$seriesSum}\n";
    echo "   card total:        " . var_export($cardTotal, true) . "\n";

    check('the chart series carries both months (40.00 + 70.00)', near($seriesSum, 110.0),
        "got {$seriesSum}");

    check('the CARD agrees with the chart beside it, so December is not missing',
        $cardTotal !== null && near($cardTotal, 110.0),
        'card reads ' . var_export($cardTotal, true) . ' against a series of ' . $seriesSum
        . ' -- the card was summed before the last month was written into the series');

    echo "\n{$checks} checks, {$failures} failed\n";
    $exit = $failures > 0 ? 1 : 0;
} catch (\Throwable $e) {
    fwrite(STDERR, "\nUNEXPECTED: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, substr($e->getTraceAsString(), 0, 2000) . "\n");
    $exit = 1;
} finally {
    try {
        DB::purge('rb_fresh');
        DB::connection('rb_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "Dropped {$database}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "WARNING: could not drop {$database} -- {$e->getMessage()}\n");
    }
}

exit($exit);
