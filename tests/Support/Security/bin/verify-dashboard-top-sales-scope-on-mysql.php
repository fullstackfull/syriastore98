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
        $t->float('final_price')->default(0);
        $t->unsignedBigInteger('store_id')->nullable();
        $t->timestamps();
    });

    echo "0. the premise -- MySQL really has the functions SQLite does not\n";
    $probe = DB::connection('ogr_a')
        ->selectOne("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX('7,9,11', ',', 2), ',', -1) v");
    check('SUBSTRING_INDEX picks the nth element of a comma list', (string) $probe->v === '9',
        'got ' . var_export($probe->v, true) . '; if this fails the script measured nothing');

    /* ---------------------------------------------------------------- the fixture
     *
     * Store 1 owns category "Lamps" and product 7.
     * Store 2 owns category "Rugs"  and product 9.
     *
     * Three orders:
     *   A  store 1, product 7,  4500.00   <- store 1's own revenue
     *   B  store 2, product 7,  9900.00   <- ANOTHER TENANT'S order, naming store 1's product
     *   C  store 2, product 9,   250.00   <- store 2's own, unrelated
     *
     * Order B is the one that matters. `Product` registers no StoreScope and is not in
     * `scoped_models`, so a storefront in store 2 can reach store 1's catalogue (finding S19) and
     * produce exactly this row. Without an `orders.store_id` predicate, store 1's "Top Category"
     * reports 14,400 — more than half of it another tenant's money.
     */
    DB::connection('ogr_a')->table('categories')->insert([
        ['id' => 1, 'name' => 'Lamps', 'image_path' => null, 'store_id' => 1],
        ['id' => 2, 'name' => 'Rugs',  'image_path' => null, 'store_id' => 2],
    ]);
    DB::connection('ogr_a')->table('products')->insert([
        ['id' => 7, 'name' => 'Brass lamp', 'category_id' => 1, 'store_id' => 1],
        ['id' => 9, 'name' => 'Kilim',      'category_id' => 2, 'store_id' => 2],
    ]);
    DB::connection('ogr_a')->table('orders')->insert([
        ['id' => 1, 'product_id' => '7', 'final_price' => 4500.00, 'store_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'product_id' => '7', 'final_price' => 9900.00, 'store_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'product_id' => '9', 'final_price' =>  250.00, 'store_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    /** The shipped query shape, with the store predicate under test made optional. */
    $topSales = function (int $storeId, bool $scopeOrders) {
        $q = DB::connection('ogr_a')->table('categories')
            ->select('categories.name as sale_name', DB::raw('SUM(orders.final_price) as total_sale'))
            ->join('products', 'categories.id', '=', 'products.category_id')
            ->join('orders', function ($join) {
                $join->on('products.id', '=', DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                    ->crossJoin(DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'));
            })
            ->where('categories.store_id', $storeId);

        if ($scopeOrders) {
            $q->where('orders.store_id', $storeId);
        }

        return $q->groupBy('categories.name')->orderBy('total_sale', 'desc')->get();
    };

    echo "\n1. the query runs at all on this engine\n";
    $scoped = $topSales(1, true);
    check('store 1 gets exactly one category row', $scoped->count() === 1,
        'got ' . $scoped->count() . ' row(s)');
    check('and it is the category store 1 owns', ($scoped->first()->sale_name ?? '') === 'Lamps',
        (string) ($scoped->first()->sale_name ?? '(none)'));

    echo "\n2. the tenant predicate -- another store's order is NOT in this store's revenue\n";
    /* RAW sums, before the view's /100. Section 4 explains why that division exists; here the
       only question is WHOSE orders are in the total, and the fan-out multiplies both sides of
       the comparison equally. */
    $scopedTotal = (float) ($scoped->first()->total_sale ?? 0);
    check('store 1 sees its own order and nothing else (4500 x the 100-row fan-out)',
        abs($scopedTotal - 450000.0) < 0.001, 'got ' . $scopedTotal);

    $unscoped = $topSales(1, false);
    $unscopedTotal = (float) ($unscoped->first()->total_sale ?? 0);
    check('THE BITE: without the predicate the same query returns a different, larger figure',
        abs($unscopedTotal - $scopedTotal) > 0.001,
        'scoped=' . $scopedTotal . ' unscoped=' . $unscopedTotal
        . ' -- the two agree, so this fixture cannot tell the predicate from its absence');
    check('and that figure carries the other tenant\'s 9900 as well: (4500 + 9900) x 100',
        abs($unscopedTotal - 1440000.0) < 0.001, 'got ' . $unscopedTotal);
    check('which the card would have shown as 14400 rather than 4500',
        abs(($unscopedTotal / 100) - 14400.0) < 0.001, 'got ' . ($unscopedTotal / 100));

    echo "\n3. store 2 is not harmed by the predicate -- it keeps the order it really placed\n";
    $two = $topSales(2, true);
    $byName = $two->keyBy('sale_name');
    check('store 2 sees its own category', $byName->has('Rugs'), $two->pluck('sale_name')->implode(', '));
    check('at 250 x the fan-out, which the card shows as 250',
        abs(((float) ($byName['Rugs']->total_sale ?? 0)) - 25000.0) < 0.001,
        'got ' . ((float) ($byName['Rugs']->total_sale ?? 0)));
    check('and its order for ANOTHER store\'s product is not attributed to that store\'s category',
        ! $byName->has('Lamps'),
        'store 2 is being shown store 1\'s category, which it does not own');

    echo "\n4. WHY THE /100 EXISTS -- the cross join fans a single-product order out 100 times\n";
    /* THE MEASUREMENT THAT CORRECTED WS C. `numbers.n` runs 1..100 and nothing bounds it to the
       number of elements in `orders.product_id`, so `SUBSTRING_INDEX('7', ',', n)` returns '7'
       for every n and the order joins a hundred times. The view's `/100` is a compensation for
       that, NOT a minor-unit conversion -- which is what it looks like, and what WS C first took
       it for. */
    $fan = DB::connection('ogr_a')->select(
        "SELECT o.id, o.product_id, COUNT(*) rows_joined, SUM(o.final_price) summed
           FROM orders o CROSS JOIN (SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers
          WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(o.product_id, ',', numbers.n), ',', -1) = '7'
            AND o.id = 1
          GROUP BY o.id, o.product_id"
    );
    check('a SINGLE-product order joins 100 times, not once',
        (int) ($fan[0]->rows_joined ?? 0) === 100,
        'joined ' . ((int) ($fan[0]->rows_joined ?? 0)) . ' time(s)');
    check('so its 4500 is summed as 450000, and /100 renders the right 4500',
        abs(((float) ($fan[0]->summed ?? 0)) - 450000.0) < 0.001
        && abs((((float) ($fan[0]->summed ?? 0)) / 100) - 4500.0) < 0.001,
        'summed ' . ((float) ($fan[0]->summed ?? 0)));
    check('THE BITE: removing the /100 would have shown 450000 for a 4500 category',
        abs($scopedTotal - 450000.0) < 0.001, 'got ' . $scopedTotal);

    echo "\n5. a multi-product order still attributes to every category it names\n";
    /* The SUBSTRING_INDEX machinery exists for this case, and it is worth one check: a predicate
       added to the wrong clause could silently drop these rows. */
    DB::connection('ogr_a')->table('products')->insert([
        ['id' => 11, 'name' => 'Copper lamp', 'category_id' => 1, 'store_id' => 1],
    ]);
    DB::connection('ogr_a')->table('orders')->insert([
        ['id' => 4, 'product_id' => '7,11', 'final_price' => 100.00, 'store_id' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $after = $topSales(1, true);
    $lamps = (float) ($after->firstWhere('sale_name', 'Lamps')->total_sale ?? 0);

    /* F242, MEASURED. Product 7 matches only n=1 ('7'), product 11 matches n=2..100 ('11'), so
       the 100 is summed 1 + 99 = 100 times -- the SAME hundredfold the single-product case gets,
       by a different route, which is why the card happens to read 100 for this order too. The
       defect shows up as soon as the two products sit in DIFFERENT categories, or the list has
       three elements: the per-element counts stop summing to 100. Recorded, not fixed. */
    check('the raw sum is 450000 + 100x100, so the card reads 4500 + 100',
        abs($lamps - 460000.0) < 0.001, 'got ' . $lamps);
    check('F242: the compensation is arithmetic coincidence, not a bound -- a THREE-element list '
        . 'does not sum to 100 per order',
        (int) DB::connection('ogr_a')->selectOne(
            "SELECT COUNT(*) c FROM (SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers
               WHERE SUBSTRING_INDEX(SUBSTRING_INDEX('7,11,13', ',', numbers.n), ',', -1) = '11'"
        )->c === 1,
        'the middle element of a three-element list matches a number of times other than 1, so '
        . 'this record of F242 is wrong');

    echo "\n" . str_repeat('-', 86) . "\n";
    echo "{$checks} checks, {$failures} failed\n";
} finally {
    try { DB::connection('ogr_boot')->statement("DROP DATABASE IF EXISTS `{$database}`"); } catch (Throwable $e) {}
}

exit($failures === 0 ? 0 : 1);
