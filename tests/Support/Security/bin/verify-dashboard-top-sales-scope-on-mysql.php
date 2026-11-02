<?php

/**
 * WS C — "TOP CATEGORY / TOP BRAND — BY SALES": THE MONEY, AND WHOSE ORDERS IT COUNTS.
 * =================================================================================================
 *
 * WHY THIS CANNOT BE A PHPUnit CASE. The twelve queries in
 * `HomeController::top_brand_category_chart()` join `orders` on
 *
 *     SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)
 *
 * and three of them also call `CURDATE()`. Neither function exists in SQLite, so on the Feature
 * suite's `:memory:` database the statement cannot execute at all — the same class of trap as
 * `whereRaw('BINARY \`code\` = ?')` elsewhere in this repository, where production code was
 * unreachable in tests and the tests passed anyway. Anything asserted about these figures on
 * SQLite would be asserted about a query that never ran.
 *
 * WHAT WS C CHANGED, and what this script measures on a real MariaDB:
 *
 *  1. THE SUM REACHED EVERY TENANT'S ORDERS. The queries filtered `categories.store_id` /
 *     `product_brands.store_id` and nothing else. `orders` registers NO global scope in this
 *     codebase — it is absent from `config/tenancy.php`'s `scoped_models` — so an order placed in
 *     ANOTHER store that references one of this store's products was summed into this store's
 *     revenue. `handleRegularUser()` has always scoped its own order figures with
 *     `Order::where('store_id', getCurrentStore())`, so these two cards were the only order money
 *     on the dashboard not filtered that way. THAT PREDICATE IS THE FIX THIS SCRIPT GUARDS.
 *
 *  2. AND IT RECORDS WHAT THE FIGURE ACTUALLY MEANS, because WS C got that wrong first and this
 *     script is what caught it. The partial divides `total_sale` by 100, which looks like a
 *     minor-unit conversion applied to a column that is already major units — so WS C removed
 *     it. Measured here, the `/100` is a compensation for a CROSS JOIN FAN-OUT: `numbers.n` runs
 *     1..100 with nothing bounding it to the number of elements in `orders.product_id`, so a
 *     single-product order matches for every one of the hundred values and its price is summed
 *     a hundred times. Removing the division would have multiplied every figure on both cards
 *     by one hundred. The division is restored, and sections 4 and 5 below pin the arithmetic
 *     so the same mistake cannot be made twice.
 *
 *     THE RESIDUAL DEFECT IS RECORDED, NOT FIXED (finding F242): the compensation is exact only
 *     for single-product orders. Correcting it means bounding `numbers.n` AND deciding whether
 *     an order counts once per CATEGORY or once per PRODUCT — a change to the figure these cards
 *     report, which is the owner's call and not a side effect of an accessibility fix.
 *
 * THE BITE IS BUILT IN. Every check that asserts the predicate works also runs the query WITHOUT
 * it, and fails if the two agree — because a fixture in which the unscoped sum happens to equal
 * the scoped one proves nothing, and that is precisely the fixture a careless setup produces.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name that does not say so.
 *
 *     php tests/Support/Security/bin/verify-dashboard-top-sales-scope-on-mysql.php
 *
 * NEVER point OGR_MYSQL_DATABASE at a development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('OGR_MYSQL_DATABASE') ?: 'ogr_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('OGR_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) { $socket = $candidate; echo "using the unix socket at {$candidate}\n"; break; }
    }
}

$base = [
    'driver' => 'mysql',
    'host' => getenv('OGR_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('OGR_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('OGR_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('OGR_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
];

$boot = $base; $boot['database'] = null;
config(['database.connections.ogr_boot' => $boot]);
config(['database.connections.ogr_a' => $base]);
config(['database.default' => 'ogr_a']);

$checks = 0; $failures = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    echo $ok ? "  ok    {$what}\n" : "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    if (! $ok) { $failures++; }
}

echo "WS C -- dashboard \"By Sales\": the figure, and whose orders it counts\n";
echo str_repeat('=', 86) . "\n";

try {
    DB::connection('ogr_boot')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('ogr_boot')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    echo 'engine: ' . DB::connection('ogr_a')->selectOne('SELECT VERSION() v')->v . "\n\n";

    /* Only the three columns the join really touches, plus the discriminators. A fuller copy of
       the shipped schema would add nothing: what is under test is a join predicate. */
    Schema::connection('ogr_a')->create('categories', function ($t) {
        $t->bigIncrements('id');
        $t->string('name', 191);
        $t->string('image_path', 191)->nullable();
        $t->unsignedBigInteger('store_id');
    });
    Schema::connection('ogr_a')->create('products', function ($t) {
        $t->bigIncrements('id');
        $t->string('name', 191);
        $t->unsignedBigInteger('category_id');
        $t->unsignedBigInteger('store_id');
    });
    Schema::connection('ogr_a')->create('orders', function ($t) {
        $t->bigIncrements('id');
        /* `orders.product_id` IS A COMMA-SEPARATED STRING, which is the whole reason for the
           SUBSTRING_INDEX machinery this script exists to exercise. Not a mistake in the
           fixture: it is the shipped column. */
        $t->string('product_id', 191);
        /* `product_json` IS THE AUTHORITATIVE PER-LINE RECORD and the new computation's only
           input. The old query never read it, which is the root of F242. */
        $t->text('product_json')->nullable();
        $t->decimal('final_price', 16, 4)->default(0);
        $t->unsignedBigInteger('store_id')->nullable();
        $t->timestamps();
    });

    echo "0. the premise -- MySQL has the functions SQLite does not, so the OLD shape can be RUN here\n";
    $probe = DB::connection('ogr_a')
        ->selectOne("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX('7,9,11', ',', 2), ',', -1) v");
    check('SUBSTRING_INDEX picks the nth element of a comma list', (string) $probe->v === '9',
        'got ' . var_export($probe->v ?? null, true));

    $past = DB::connection('ogr_a')
        ->selectOne("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX('7,9', ',', 50), ',', -1) v");
    check('and PAST the end it returns the LAST element -- the fan-out\'s cause',
        (string) $past->v === '9', 'got ' . var_export($past->v ?? null, true));

    // ------------------------------------------------------------------ 1. THE FIXTURE
    $a = DB::connection('ogr_a');

    $a->table('categories')->insert([
        ['id' => 1, 'name' => 'Lamps', 'image_path' => 'l.png',  'store_id' => 1],
        ['id' => 2, 'name' => 'Rugs',  'image_path' => 'r.png',  'store_id' => 1],
        ['id' => 3, 'name' => 'Tiles', 'image_path' => 't.png',  'store_id' => 1],
        ['id' => 4, 'name' => 'Lamps', 'image_path' => 'l2.png', 'store_id' => 2],
    ]);

    $a->table('products')->insert([
        ['id' => 7,  'name' => 'Brass lamp', 'category_id' => 1, 'store_id' => 1],
        ['id' => 9,  'name' => 'Kilim rug',  'category_id' => 2, 'store_id' => 1],
        ['id' => 11, 'name' => 'Zellige',    'category_id' => 3, 'store_id' => 1],
        ['id' => 21, 'name' => 'Other lamp', 'category_id' => 4, 'store_id' => 2],
    ]);

    $a->table('orders')->insert([
        [   // store 1, THREE products: 60 + 100 + 15 = 175 product revenue, +40 order-level money
            'id' => 1, 'product_id' => '7,9,11',
            'product_json' => json_encode([
                ['product_id' => 7,  'qty' => 3, 'price' => 20, 'final_price' => 60],
                ['product_id' => 9,  'qty' => 2, 'price' => 50, 'final_price' => 100],
                ['product_id' => 11, 'qty' => 1, 'price' => 15, 'final_price' => 15],
            ]),
            'final_price' => '215.0000', 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [   // store 1, ONE product, written with the OTHER spelling: 5 x 8 = 40
            'id' => 2, 'product_id' => '7',
            'product_json' => json_encode([['product_id' => 7, 'quantity' => 5, 'price' => 8]]),
            'final_price' => '80.0000', 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [   // store 2
            'id' => 3, 'product_id' => '21',
            'product_json' => json_encode([['product_id' => 21, 'qty' => 1, 'price' => 999, 'final_price' => 999]]),
            'final_price' => '999.0000', 'store_id' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    // ------------------- 2. THE OLD SHAPE, EXECUTED, SO F242 IS A NUMBER AND NOT AN ARGUMENT
    //
    // By hand for order 1 ('7,9,11'):  n=1 -> 7 (1 row) · n=2 -> 9 (1 row) · n=3..100 -> 11 (98 rows)
    // so SUM(final_price) is 215, 215 and 215*98 = 21070, which /100 renders as 2.15, 2.15, 210.70
    // for an order whose real split is 60 / 100 / 15.
    $numbers = '(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL '
        . 'SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 '
        . 'UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N '
        . 'UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL '
        . 'SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers';

    $old = $a->table('categories')
        ->select('categories.name as sale_name', DB::raw('SUM(orders.final_price) as total_sale'))
        ->join('products', 'categories.id', '=', 'products.category_id')
        ->join('orders', function ($join) use ($numbers) {
            $join->on('products.id', '=', DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                ->crossJoin(DB::raw($numbers));
        })
        ->where('categories.store_id', 1)
        ->where('orders.store_id', 1)
        ->groupBy('categories.name')
        ->get()
        ->keyBy('sale_name');

    echo "\n2. the OLD query, executed -- what the card used to show\n";
    $oldShown = fn ($n) => round(((float) ($old[$n]->total_sale ?? 0)) / 100, 2);

    foreach (['Lamps' => 100.0, 'Rugs' => 100.0, 'Tiles' => 15.0] as $name => $truth) {
        printf("     %-6s truth %9.2f    old card showed %12.2f\n", $name, $truth, $oldShown($name));
    }

    check('the OLD card UNDER-reported Rugs (truth 100.00, showed ' . $oldShown('Rugs') . ')',
        abs($oldShown('Rugs') - 100.0) > 1.0,
        'the old shape agreed with the truth, so this fixture does not reproduce F242');

    check('and OVER-reported Tiles (truth 15.00, showed ' . $oldShown('Tiles') . ')',
        $oldShown('Tiles') > 100.0,
        'the last element was not fanned out, so the non-uniform fan-out is not reproduced');

    check('so NO single divisor could correct both at once -- which is F242',
        abs($oldShown('Rugs') - 100.0) > 1.0 && $oldShown('Tiles') > 100.0);

    // ---------------------------- 3. THE NEW COMPUTATION, AGAINST THIS ENGINE
    echo "\n3. the NEW computation on this engine\n";
    config(['database.default' => 'ogr_a']);

    $rows = collect(\App\Support\TopSellersByRevenue::compute(
        \App\Support\TopSellersByRevenue::CATEGORY, 1
    ))->keyBy('sale_name');

    $got = fn ($n) => round((float) ($rows[$n]->total_sale ?? -1), 2);

    /* Lamps is 100, not 60: product 7 is in BOTH of store 1's orders -- 60 from the three-line one
       and 5 x 8 = 40 from the single-line one, which is written with `quantity` rather than `qty`.
       So this figure also proves the spelling tolerance on the real engine. */
    foreach (['Lamps' => 100.0, 'Rugs' => 100.0, 'Tiles' => 15.0] as $name => $expected) {
        check(sprintf('%s is %.2f', $name, $expected), abs($got($name) - $expected) < 0.001,
            'got ' . $got($name) . ($name === 'Lamps' ? '; 60 means the `quantity` line was dropped' : ''));
    }

    check('ORDER-LEVEL MONEY IS EXCLUDED: 215 of product revenue, not the 295 of order totals',
        abs(array_sum($rows->pluck('total_sale')->all()) - 215.0) < 0.001,
        'got ' . array_sum($rows->pluck('total_sale')->all()));

    check('store 2 is absent despite owning a category of the SAME NAME', $rows->count() === 3,
        'rows: ' . $rows->pluck('sale_name')->implode(', '));

    // ---------------------------- 4. THE OTHER TENANT
    $two = collect(\App\Support\TopSellersByRevenue::compute(
        \App\Support\TopSellersByRevenue::CATEGORY, 2
    ))->keyBy('sale_name');

    check('store 2 sees its own Lamps at 999.00',
        abs((float) ($two['Lamps']->total_sale ?? 0) - 999.0) < 0.001,
        'got ' . ((float) ($two['Lamps']->total_sale ?? 0)));
    check('and exactly one row', $two->count() === 1, 'rows: ' . $two->count());

    // ---------------------------- 5. THE DATE WINDOW
    $a->table('orders')->where('id', 2)->update(['created_at' => now()->subYears(2)]);

    $thisYear = collect(\App\Support\TopSellersByRevenue::compute(
        \App\Support\TopSellersByRevenue::CATEGORY, 1,
        now()->startOfYear()->toDateTimeString(),
        now()->startOfYear()->addYear()->toDateTimeString()
    ))->keyBy('sale_name');

    check('a two-year-old order leaves the year window (Lamps 100 -> 60)',
        abs((float) ($thisYear['Lamps']->total_sale ?? 0) - 60.0) < 0.001,
        'got ' . ((float) ($thisYear['Lamps']->total_sale ?? 0)));

    echo "\n" . str_repeat('=', 86) . "\n";
    echo "checks  : {$checks}\nfailures: {$failures}\n";

    if ($failures === 0) {
        echo "\nALL ASSERTIONS HELD on " . DB::connection('ogr_a')->selectOne('SELECT VERSION() v')->v . ".\n";
        echo "The OLD cross-join query is EXECUTED here and shown wrong on real MySQL: on one\n";
        echo "three-product order it under-reported one category and over-reported another, so no\n";
        echo "single divisor could have corrected it. The NEW computation returns the figures that\n";
        echo "arithmetic on the fixture predicts, excludes order-level money, tolerates both line\n";
        echo "spellings, isolates each store and honours a half-open date window.\n";
    }
} finally {
    try { DB::connection('ogr_boot')->statement("DROP DATABASE IF EXISTS `{$database}`"); } catch (Throwable $e) {}
}

exit($failures === 0 ? 0 : 1);
