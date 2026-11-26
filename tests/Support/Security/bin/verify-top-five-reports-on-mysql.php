<?php

/**
 * P5-3f -- THE "TOP 5" CHARTS WERE NEITHER TOP NOR FIVE.
 *
 * Owner recommendation 4 (2026-11-17): "Either implement a genuinely ranked Top 5 with a
 * documented metric and tests, or relabel the chart honestly. Do not display an unordered full
 * list as Top 5." This is the first branch, and it needed no new product decision -- **the view
 * already documented the metric and the controller simply did not honour it**:
 *
 *   top_5_reports.blade.php:33  "the five are the top five"
 *   :43  "The five products appearing in the most orders. Counts orders, not quantity or value."
 *   :56  "Categories by the number of distinct orders containing one of their products."
 *   :69  "Brands by the number of distinct orders containing one of their products."
 *   :82  "Orders grouped by the method they were paid with."
 *
 * FOUR DEFECTS AGAINST THAT PROMISE, all measured:
 *
 *   1. The category query ended at `->get()` with no orderBy and no limit. Forty categories gave
 *      forty bars in engine order.
 *   2. The brand query, the same.
 *   3. Payment methods were grouped on the RAW `payment_type` with PHP's case-sensitive
 *      comparison, so 'cod' and 'COD' were two bars competing for the same five slots -- which
 *      makes a top five wrong, not merely unordered -- and then none of them was ranked either.
 *   4. The products chart WAS ranked and limited (`sortDesc()->take(5)`), but counted OCCURRENCES
 *      of a product id in `orders.product_id`, a comma-joined list that two of its three writers
 *      build without de-duplication. An order with the same product on two lines contributed 2 to
 *      a figure labelled "orders".
 *
 * A FIFTH DEFECT, found by the PR #140 deletion audit's adversarial pass and measured here as
 * section E. Ties. The three ranked charts did not break ties the same way, and the fix for the
 * first three had asserted only "at most five" and "descending", never WHICH five survive:
 *
 *   * categories and brands: `orderByDesc(count)` then `orderBy(name)` -- name ascending.
 *   * payment methods: `sortByDesc(fn => [orders, label])`, which compares BOTH elements
 *     DESCENDING, so ties fell out in REVERSE alphabet. Seven methods of one order each kept
 *     g,f,e,d,c and silently dropped a and b. The first replacement compared the label BYTEWISE,
 *     which puts every capital before every lower-case letter -- the SQL charts' collation is
 *     case-insensitive -- so the label is now case-folded; section E2 measures it.
 *   * products: `countBy()->sortDesc()` kept first-appearance order, which follows the database's
 *     row order for a query with no ORDER BY -- so the fifth place appeared and disappeared
 *     between loads. Now `sortKeys()` first, so ties break by product id, ascending.
 *
 * WHY A SCRIPT. `top_product()` is in `ReportController`, which cannot run under the test suite at
 * all: the category and brand queries use `SUBSTRING_INDEX`, and the rest of the file uses
 * `MONTH()` and `YEAR()`, none of which exist in SQLite. See
 * `verify-report-window-boundary-on-mysql.php` for the measurement. The handler returns a VIEW, so
 * this reads `getData()` rather than parsing HTML.
 *
 * It never touches a database it did not create: `top_five_verify_` plus the process id, and any
 * name that does not say it is throwaway is refused.
 *
 * Usage:  php tests/Support/Security/bin/verify-top-five-reports-on-mysql.php
 * Exit:   0 = all checks passed · 1 = a check failed · 2 = a precondition was not met.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
/*
 * THE SESSION DRIVER IS SET BEFORE THE FRAMEWORK BOOTS, AND THAT ORDER IS THE WHOLE FIX.
 * `config/session.php` reads `env('SESSION_DRIVER', 'database')` while the config is loaded, so a
 * `config([...])` call AFTER `bootstrap()` is too late -- measured: it changed nothing and the
 * script still exited 1.
 */
putenv('SESSION_DRIVER=array');
$_ENV['SESSION_DRIVER'] = 'array';
$_SERVER['SESSION_DRIVER'] = 'array';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/*
 * THE SESSION STORE IS FORCED TO `array`, AND THAT IS A DEFECT REPAIR RATHER THAN A SETTING.
 *
 * MEASURED 2026-11-26, while executing the whole Phase 05 real-MySQL evidence set for the P05-24
 * report: this script exited 1 -- "a check FAILED" -- with
 *
 *     the script threw: Illuminate\Database\QueryException -- Database file at path
 *     [.../database/database.sqlite] does not exist (Connection: sqlite,
 *     SQL: delete from "sessions" where "id" = ...)
 *
 * `config/session.php` defaults to the `database` driver, so booting the framework in an
 * environment with no `.env` lets the DEFAULT connection -- still sqlite -- touch the session
 * table before this script points anything at MySQL. The sibling
 * `verify-analytics-breakdowns-on-mysql.php` documents the remedy in its USAGE line
 * (`SESSION_DRIVER=array`), and with it this script is 22 checks, 0 failed.
 *
 * SO THE PRODUCT WAS SOUND AND THE SCRIPT WAS WRONG ABOUT ITS OWN RESULT. Its stated exit contract
 * reserves 2 for "a precondition was not met"; reporting an absent sqlite file as a failed CHECK
 * tells a reader of the Phase 05 evidence that a money figure is broken when nothing is. An
 * evidence script that cries wolf makes the whole evidence set less trustworthy, which is the
 * reporting-honesty failure P05-24 exists to close.
 *
 * It is forced here rather than documented because a precondition nobody can forget is better than
 * one they must remember: the session store is irrelevant to everything this script measures (it
 * drives HTTP requests and reads money figures), and `array` is what the whole suite runs on.
 */

use App\Http\Controllers\ReportController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: ('top_five_verify_' . getmypid());

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

config(['database.connections.tf_bootstrap' => $bootstrapConnection]);
config(['database.connections.tf_fresh' => $connection]);

$checks = 0; $failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok    {$what}\n"; return; }
    $failures++;
    echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

try {
    DB::connection('tf_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('tf_bootstrap')->statement(
        "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$exit = 0;

try {
    config(['database.default' => 'tf_fresh']);
    DB::purge('tf_fresh');

    echo "Server: " . DB::connection('tf_fresh')->selectOne('SELECT VERSION() v')->v . "\n";
    echo "Database: {$database}\n\n";

    echo "Running migrations...\n";
    Artisan::call('migrate', ['--database' => 'tf_fresh', '--force' => true]);
    echo "  done\n\n";

    $owner = new User();
    $owner->name = 'Top five owner';
    $owner->email = 'topfive@example.test';
    $owner->password = bcrypt('x');
    $owner->type = 'admin';
    $owner->save();

    DB::table('stores')->insert([
        'id' => 1, 'name' => 'Top five store', 'slug' => 'top-five-store',
        'email' => 'tf@example.test', 'theme_id' => 'stylique',
        'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $owner->current_store = 1;
    $owner->save();

    /*
     * SEVEN categories and SEVEN brands, with order counts 7,6,5,4,3,2,1. Seven so that a correct
     * top five must EXCLUDE two, and descending so that engine order and ranked order differ.
     */
    $catalogue = [];
    foreach (range(1, 7) as $i) {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat ' . $i, 'slug' => 'cat-' . $i, 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $brandId = DB::table('product_brands')->insertGetId([
            'name' => 'Brand ' . $i, 'slug' => 'brand-' . $i, 'store_id' => 1,
            'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Prod ' . $i, 'slug' => 'prod-' . $i,
            'category_id' => $categoryId, 'brand_id' => $brandId,
            'store_id' => 1, 'created_by' => $owner->id, 'track_stock' => 0, 'price' => 10.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $catalogue[$i] = ['category' => $categoryId, 'brand' => $brandId, 'product' => $productId];
    }

    $orderSeq = 0;
    $placeOrder = function (string $productIdList, string $paymentType) use (&$orderSeq): void {
        $orderSeq++;
        DB::table('orders')->insert([
            'product_order_id' => 'TF-' . $orderSeq,
            'product_json' => json_encode([]),
            'product_id' => $productIdList,
            'product_price' => 10.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 10.0, 'currency_code' => 'USD',
            'payment_type' => $paymentType, 'payment_status' => 'Paid',
            'delivered_status' => 1, 'store_id' => 1,
            'order_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    // 8 - $i orders for item $i, so counts descend 7,6,5,4,3,2,1.
    foreach (range(1, 7) as $i) {
        for ($o = 0; $o < 8 - $i; $o++) {
            $placeOrder((string) $catalogue[$i]['product'], 'cod');
        }
    }

    /*
     * ONE order listing the SAME product EIGHT times -- the shape `OrderController::store()` and
     * the Flutter admin path produce, neither of which de-duplicates.
     *
     * Eight and not two, deliberately. With two, Prod 7 reaches 3 under occurrence counting and 2
     * under distinct counting, and is outside the top five EITHER WAY -- so the check below could
     * not fail and did not: reverting the fix left this script green. With eight it reaches 9
     * under occurrence counting, which is SECOND PLACE, and the exact-count assertion then
     * separates the two readings. One order, so the category and brand counts for item 7 are
     * untouched at 2 and their own assertions stay stable.
     */
    $placeOrder(implode(',', array_fill(0, 8, $catalogue[7]['product'])), 'COD');

    // Mixed spellings of ONE method, plus a genuinely different one.
    foreach (['COD', ' cod ', 'Cod'] as $spelling) { $placeOrder((string) $catalogue[1]['product'], $spelling); }
    foreach (['stripe', 'Stripe'] as $spelling) { $placeOrder((string) $catalogue[1]['product'], $spelling); }

    foreach ([\App\Models\Product::class, \App\Models\ProductBrand::class, \App\Models\Category::class] as $model) {
        if (method_exists($model, 'flushCache')) { (new $model())->flushCache(); }
    }

    Auth::login($owner);
    $request = Request::create('/top-product', 'GET');
    app()->instance('request', $request);

    $view = (new ReportController())->top_product($request);
    $data = $view->getData();

    // ------------------------------------------------------------------ A. category

    /*
     * THE EXPECTED COUNTS ARE 12,6,5,4,3 AND NOT 7,6,5,4,3, and the first draft of this script
     * asserted the latter and failed itself. The payment-method fixture below deliberately places
     * its five extra orders on item 1, so item 1 ends on 7 + 3 + 2 = 12. That was an accident the
     * first time and is kept on purpose: a wide gap at the top is stronger evidence of ordering
     * than a one-step ladder, because engine order could coincide with a ladder and cannot
     * plausibly coincide with this.
     *
     * Items 6 and 7 end on 2 orders each (item 7's second being the duplicate-listing order), so
     * a correct top five must exclude both.
     */
    echo "A. Top Selling Category -- 7 seeded; item 1 gains the payment fixture's 5 orders\n";

    $categoryRows = collect($data['top_sales'] ?? []);
    $categoryNames = $categoryRows->pluck('sale_name')->all();
    $categoryCounts = $categoryRows->pluck('total_sale')->map(fn ($v) => (int) $v)->all();

    echo "   returned: " . json_encode(array_combine($categoryNames, $categoryCounts)) . "\n";

    check('exactly FIVE categories are returned, not all seven',
        count($categoryNames) === 5, 'got ' . count($categoryNames));
    check('they are the five with the most orders, in descending order',
        $categoryCounts === [12, 6, 5, 4, 3], json_encode($categoryCounts));
    check('the two smallest are excluded',
        ! in_array('Cat 6', $categoryNames, true) && ! in_array('Cat 7', $categoryNames, true),
        implode(', ', $categoryNames));

    // ------------------------------------------------------------------ B. brand

    echo "\nB. Top Selling Brand\n";

    $brandRows = collect($data['top_brand_sales'] ?? []);
    $brandCounts = $brandRows->pluck('total_sale')->map(fn ($v) => (int) $v)->all();

    echo "   returned: " . json_encode(array_combine($brandRows->pluck('sale_name')->all(), $brandCounts)) . "\n";

    check('exactly FIVE brands are returned', count($brandCounts) === 5, 'got ' . count($brandCounts));
    check('ranked descending by distinct orders', $brandCounts === [12, 6, 5, 4, 3], json_encode($brandCounts));

    // ------------------------------------------------------------------ C. products

    echo "\nC. Top Selling Products -- one order lists Prod 7 EIGHT times\n";

    $productNames = $data['productNames'] ?? [];
    $productCounts = $data['productCounts'] ?? [];

    echo "   returned: " . json_encode(array_combine($productNames, $productCounts)) . "\n";

    check('exactly FIVE products are returned', count($productNames) === 5, 'got ' . count($productNames));
    check('ranked descending', $productCounts === array_values(collect($productCounts)->sortDesc()->all()),
        json_encode($productCounts));

    check('the five counts are the five DISTINCT-ORDER counts, not occurrence counts',
        $productCounts === [12, 6, 5, 4, 3],
        json_encode($productCounts) . ' -- occurrence counting puts Prod 7 at 9 (second place) '
        . 'because one order lists it eight times, giving [12,9,6,5,4]');

    /*
     * Prod 7 has ONE ordinary order plus ONE order listing it twice = 2 distinct orders.
     * Occurrence counting would say 3, which would have TIED it with Prod 5 and put it in
     * contention for the fifth slot; a distinct-order count leaves it out. That is why the
     * duplicate matters beyond one wrong number -- it changes who appears at all.
     */
    /*
     * NO ESCAPE CLAUSE. This check first read `! isset($byName['Prod 7']) || ...`, which passes
     * whenever Prod 7 is absent -- and Prod 7 is absent under the CORRECT behaviour, so the
     * assertion could never fail and the mutation that reverted the fix left it green. Asserting
     * absence outright is the real claim: with two distinct orders Prod 7 does not belong in a
     * top five whose fifth place holds three.
     */
    $byName = array_combine($productNames, $productCounts);
    check('a product listed eight times in one order does not buy its way into the top five',
        ! isset($byName['Prod 7']),
        'Prod 7 is present reading ' . var_export($byName['Prod 7'] ?? null, true)
        . ' -- it has 2 distinct orders and fifth place holds 3');

    // ------------------------------------------------------------------ D. payment methods

    echo "\nD. Top Payment Method -- 'cod' spelled four ways, 'stripe' two ways\n";

    $paymentMethods = $data['paymentMethods'] ?? collect();
    $paymentArray = $paymentMethods instanceof \Illuminate\Support\Collection
        ? $paymentMethods->all()
        : (array) $paymentMethods;

    echo "   returned: " . json_encode($paymentArray) . "\n";

    $keys = array_map(fn ($k) => mb_strtolower(trim((string) $k)), array_keys($paymentArray));

    check('the four spellings of cash on delivery are ONE bar, not four',
        count(array_filter($keys, fn ($k) => $k === 'cod')) === 1,
        implode(', ', array_keys($paymentArray)));
    check('the two spellings of stripe are ONE bar',
        count(array_filter($keys, fn ($k) => $k === 'stripe')) === 1,
        implode(', ', array_keys($paymentArray)));
    check('at most FIVE methods are returned', count($paymentArray) <= 5, 'got ' . count($paymentArray));

    $values = array_values($paymentArray);
    check('ranked descending by order count',
        $values === array_values(collect($values)->sortDesc()->all()), json_encode($values));

    // Every order carries a cod or stripe spelling; 28 cod + 2 stripe = 30 orders total.
    $totalCounted = array_sum($values);
    check('every order is counted exactly once across the bars',
        $totalCounted === $orderSeq,
        "bars sum to {$totalCounted}, {$orderSeq} orders were placed");

    // ------------------------------------------------------------------ E. ties

    /*
     * EVERY ITEM TIED AT ONE ORDER, listed in the order that most separates the readings: the rows
     * are inserted with product ids DESCENDING (7..1), so first-appearance order is 7,6,5,4,3,2,1
     * and an implementation that merely keeps arrival order returns exactly the WRONG five.
     * Payment methods are named so that alphabetical order is the reverse of insertion order too.
     * The correct answer for every chart is therefore the LOWEST five by id or name: 1..5 / a..e.
     */
    echo "\nE. Ties -- seven items at one order each, inserted in descending id order\n";

    DB::table('orders')->delete();
    foreach ([\App\Models\Product::class, \App\Models\ProductBrand::class, \App\Models\Category::class] as $model) {
        if (method_exists($model, 'flushCache')) { (new $model())->flushCache(); }
    }

    foreach (range(7, 1) as $i) {
        $placeOrder((string) $catalogue[$i]['product'], chr(96 + $i) . '-pay');
    }

    $tieData = (new ReportController())->top_product($request)->getData();

    $tieCategories = collect($tieData['top_sales'] ?? [])->pluck('sale_name')->all();
    $tieBrands = collect($tieData['top_brand_sales'] ?? [])->pluck('sale_name')->all();
    $tieProducts = array_values($tieData['productNames'] ?? []);
    $tieMethods = array_keys($tieData['paymentMethods'] instanceof \Illuminate\Support\Collection
        ? $tieData['paymentMethods']->all()
        : (array) ($tieData['paymentMethods'] ?? []));

    echo "   categories: " . json_encode($tieCategories) . "\n";
    echo "   brands:     " . json_encode($tieBrands) . "\n";
    echo "   products:   " . json_encode($tieProducts) . "\n";
    echo "   methods:    " . json_encode($tieMethods) . "\n";

    check('categories break a tie by name, ascending',
        $tieCategories === ['Cat 1', 'Cat 2', 'Cat 3', 'Cat 4', 'Cat 5'], json_encode($tieCategories));
    check('brands break a tie by name, ascending',
        $tieBrands === ['Brand 1', 'Brand 2', 'Brand 3', 'Brand 4', 'Brand 5'], json_encode($tieBrands));
    check('products break a tie by id, ascending -- NOT by the order the rows arrived in',
        $tieProducts === ['Prod 1', 'Prod 2', 'Prod 3', 'Prod 4', 'Prod 5'], json_encode($tieProducts));
    check('payment methods break a tie by label ascending -- NOT in reverse alphabet',
        $tieMethods === ['a-pay', 'b-pay', 'c-pay', 'd-pay', 'e-pay'],
        json_encode($tieMethods) . ' -- reverse alphabet would give g-pay, f-pay, e-pay, d-pay, c-pay');

    // ------------------------------------------------------------------ E2. ties, mixed case

    /*
     * SEVEN METHODS, ONE ORDER EACH, WITH MIXED CASE. Section E's labels are all lower-case, so a
     * bytewise comparison and a case-folded one AGREE on them and cannot be told apart. These are
     * chosen so they disagree:
     *
     *     bytewise  B-pay, D-pay, F-pay, a-pay, c-pay      (capitals first -- keeps F, drops e and g)
     *     folded    a-pay, B-pay, c-pay, D-pay, e-pay      (what the SQL charts' collation gives)
     */
    echo "\nE2. Ties -- seven methods of mixed case, one order each\n";

    DB::table('orders')->delete();
    (new \App\Models\Order())->flushCache();

    foreach (['g-pay', 'F-pay', 'e-pay', 'D-pay', 'c-pay', 'B-pay', 'a-pay'] as $i => $label) {
        $placeOrder((string) $catalogue[($i % 7) + 1]['product'], $label);
    }

    $mixedData = (new ReportController())->top_product($request)->getData();
    $mixedMethods = array_keys($mixedData['paymentMethods'] instanceof \Illuminate\Support\Collection
        ? $mixedData['paymentMethods']->all()
        : (array) ($mixedData['paymentMethods'] ?? []));

    echo "   methods:    " . json_encode($mixedMethods) . "\n";

    check('payment methods break a tie ignoring case, as the SQL charts do',
        $mixedMethods === ['a-pay', 'B-pay', 'c-pay', 'D-pay', 'e-pay'],
        json_encode($mixedMethods) . ' -- a bytewise comparison would give B-pay, D-pay, F-pay, a-pay, c-pay');

    echo "\n{$checks} checks, {$failures} failed\n";
    $exit = $failures > 0 ? 1 : 0;
} catch (\Throwable $e) {
    fwrite(STDERR, "\nUNEXPECTED: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, substr($e->getTraceAsString(), 0, 1800) . "\n");
    $exit = 1;
} finally {
    try {
        DB::purge('tf_fresh');
        DB::connection('tf_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "Dropped {$database}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "WARNING: could not drop {$database} -- {$e->getMessage()}\n");
    }
}

exit($exit);
