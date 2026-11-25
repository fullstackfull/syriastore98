<?php

/**
 * GAP-150 / `P05-02` — VALIDATE `StoreInventoryValuation` AGAINST A REAL MySQL SERVER.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST, the reason every harness in this directory gives:
 * the Feature suite runs on SQLite `:memory:` and `tests/Support/TestDatabaseGuard.php` ABORTS any
 * PHPUnit run whose connection is not sqlite. A conditionally-skipped test would also perturb the
 * declared outcome set in `.github/known-test-outcomes.json`.
 *
 * WHAT IT ESTABLISHES that the suite cannot. The figures are summed off columns whose types differ
 * between the two engines, and the metric's precision disclosure is a claim ABOUT those types:
 *
 *   products.cost_price        decimal(15,2) NOT NULL   -- EXACT, so no precision caveat
 *   products.price             double NOT NULL
 *   products.sale_price        double NOT NULL
 *   product_variants.price     double NOT NULL          -- `double('price',10,2)` emits a BARE double
 *   product_variants.stock     int NOT NULL DEFAULT 0
 *
 * Those are the MEASURED production types (`SHOW CREATE TABLE` against a server, not the migration
 * text — Laravel's `float()` emits a MySQL `double`, which refuted a caveat elsewhere in this
 * repository the same day). The tables here are built from them verbatim, so the script checks that
 * `SUM(decimal * int)` really is exact on this engine, that the double-derived figures carry
 * `MONEY_FLOAT_PRECISION` and the decimal one does not, and that all seven figures agree with the
 * SQLite suite to the cent.
 *
 * It also drives the two cases most likely to diverge by engine: the operator's TWO-STORE scope with
 * a variant row of store B hanging off store A's product (which must be excluded), and the rounding
 * case where cost 0.10 and retail 0.30 must give a profit of 0.20 rather than 0.19999999999999998.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database:
 *
 *   INVMETRIC_MYSQL_SOCKET=/run/mysqld/mysqld.sock \
 *   INVMETRIC_MYSQL_DATABASE=inv_metric_verify \
 *   INVMETRIC_MYSQL_USERNAME=root \
 *   INVMETRIC_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-store-inventory-valuation-on-mysql.php
 *
 * or with INVMETRIC_MYSQL_HOST / INVMETRIC_MYSQL_PORT instead of a socket.
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE, DROP DATABASE
 * and TRUNCATE, and it refuses a database name containing neither 'inv_metric' nor 'verify'.
 *
 * Exit code 0 means every figure matched.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\StoreInventoryValuation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$db = getenv('INVMETRIC_MYSQL_DATABASE') ?: 'inv_metric_verify';

if (! str_contains($db, 'inv_metric') && ! str_contains($db, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$db}' contains neither 'inv_metric' nor 'verify'.\n"
        . "This script issues DROP DATABASE and TRUNCATE. Name the throwaway database accordingly.\n");
    exit(2);
}

$socket = getenv('INVMETRIC_MYSQL_SOCKET') ?: '';
$host = getenv('INVMETRIC_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('INVMETRIC_MYSQL_PORT') ?: '3306';
$username = getenv('INVMETRIC_MYSQL_USERNAME') ?: 'root';
$password = getenv('INVMETRIC_MYSQL_PASSWORD');
$password = $password === false ? '' : $password;

Config::set('database.connections.verify', [
    'driver' => 'mysql', 'unix_socket' => $socket, 'host' => $host, 'port' => $port,
    'database' => null, 'username' => $username, 'password' => $password,
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'prefix' => '',
    'strict' => true, 'engine' => null,
]);

try {
    DB::connection('verify')->statement("DROP DATABASE IF EXISTS `$db`");
} catch (\Throwable $e) {
    fwrite(STDERR, "\nCould not reach MySQL: " . $e->getMessage() . "\n"
        . "Set INVMETRIC_MYSQL_SOCKET (or INVMETRIC_MYSQL_HOST/PORT) to a throwaway server.\n");
    exit(3);
}

DB::connection('verify')->statement("CREATE DATABASE `$db`");
DB::purge('verify');
Config::set('database.connections.verify.database', $db);
Config::set('database.default', 'verify');
DB::setDefaultConnection('verify');

// The measured production types: see the metric's docblock.
DB::statement("CREATE TABLE products (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  price double NOT NULL DEFAULT 0,
  sale_price double NOT NULL DEFAULT 0,
  cost_price decimal(15,2) NOT NULL DEFAULT 0.00,
  product_stock int NOT NULL DEFAULT 0,
  variant_product tinyint(1) NOT NULL DEFAULT 1,
  store_id bigint unsigned DEFAULT NULL, KEY (store_id)) ENGINE=InnoDB");
DB::statement("CREATE TABLE product_variants (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id bigint unsigned NOT NULL,
  price double NOT NULL,
  stock int NOT NULL DEFAULT 0,
  variation_price double DEFAULT NULL,
  store_id bigint unsigned DEFAULT NULL, KEY (store_id)) ENGINE=InnoDB");

$A = 11; $B = 22;
function p(array $a) { return DB::table('products')->insertGetId($a + ['sale_price' => 0]); }
function v(array $a) { DB::table('product_variants')->insert($a); }

/* store A, the suite's fixture exactly */
p(['price'=>100,'sale_price'=>80,'cost_price'=>60,'product_stock'=>10,'variant_product'=>0,'store_id'=>$A]);   // #1
p(['price'=>25,'cost_price'=>7.50,'product_stock'=>4,'variant_product'=>0,'store_id'=>$A]);                    // #2
p(['price'=>70,'cost_price'=>0,'product_stock'=>3,'variant_product'=>0,'store_id'=>$A]);                       // #3
p(['price'=>999,'cost_price'=>500,'product_stock'=>0,'variant_product'=>0,'store_id'=>$A]);                    // #4
$p5 = p(['price'=>1,'cost_price'=>5,'product_stock'=>77,'variant_product'=>1,'store_id'=>$A]);                 // #5
v(['product_id'=>$p5,'price'=>100,'variation_price'=>80,'stock'=>2,'store_id'=>$A]);
v(['product_id'=>$p5,'price'=>50,'variation_price'=>null,'stock'=>6,'store_id'=>$A]);
v(['product_id'=>$p5,'price'=>40,'variation_price'=>30,'stock'=>0,'store_id'=>$A]);
v(['product_id'=>$p5,'price'=>20,'variation_price'=>10,'stock'=>0,'store_id'=>$A]);
$p6 = p(['price'=>1,'cost_price'=>0,'product_stock'=>0,'variant_product'=>1,'store_id'=>$A]);                  // #6
v(['product_id'=>$p6,'price'=>15,'variation_price'=>null,'stock'=>5,'store_id'=>$A]);
p(['price'=>12,'cost_price'=>0,'product_stock'=>50,'variant_product'=>1,'store_id'=>$A]);                      // #7
$p8 = p(['price'=>40,'cost_price'=>2,'product_stock'=>9,'variant_product'=>0,'store_id'=>$A]);                 // #8
v(['product_id'=>$p8,'price'=>500,'variation_price'=>null,'stock'=>13,'store_id'=>$A]);
$p9 = p(['price'=>1,'cost_price'=>0,'product_stock'=>0,'variant_product'=>1,'store_id'=>$A]);                  // #9
v(['product_id'=>$p9,'price'=>33,'variation_price'=>null,'stock'=>0,'store_id'=>$A]);
/* store B, plus the cross-paired row on store A's product #5 */
p(['price'=>999,'cost_price'=>500,'product_stock'=>7,'variant_product'=>0,'store_id'=>$B]);
$pb = p(['price'=>1,'cost_price'=>0,'product_stock'=>0,'variant_product'=>1,'store_id'=>$B]);
v(['product_id'=>$pb,'price'=>123,'variation_price'=>null,'stock'=>11,'store_id'=>$B]);
v(['product_id'=>$p5,'price'=>1000,'variation_price'=>null,'stock'=>100,'store_id'=>$B]);

$fail = 0;
function check($got, $want, string $what) {
    global $fail;
    $ok = $got === $want;
    if (! $ok) { $fail++; }
    printf("  %s  %-34s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}

$f = StoreInventoryValuation::forStore($A);
echo "store A, one-store scope:\n";
check($f->units(), 39, 'units');
check($f->cost(), 688.00, 'cost (decimal column)');
check($f->retailValue(), 2005.00, 'retail value (double columns)');
check($f->potentialProfit(), 1317.00, 'potential profit');
check($f->valuedProducts(), 6, 'valued products');
check($f->uncostedProducts(), 2, 'uncosted products');
check($f->uncostedRetail(), 285.00, 'uncosted retail');
check($f->hasCaveat(MetricCaveat::COST_UNKNOWN), true, 'COST_UNKNOWN attached');
check($f->hasCaveat(MetricCaveat::MONEY_FLOAT_PRECISION), true, 'MONEY_FLOAT_PRECISION attached');
check($f->hasCaveat(MetricCaveat::FLOAT_PRECISION), false, 'singular FLOAT_PRECISION absent');

$both = StoreInventoryValuation::for(MetricScope::stores([$A, $B]), MetricWindow::allTime());
echo "operator scope, both stores:\n";
check($both->units(), 57, 'units (cross-paired row excluded)');
check($both->retailValue(), 10351.00, 'retail value');

echo "the rounding case:\n";
DB::table('products')->truncate();
DB::table('product_variants')->truncate();
p(['price'=>0.30,'cost_price'=>0.10,'product_stock'=>1,'variant_product'=>0,'store_id'=>$A]);
(new \App\Models\Product)->flushCache(); (new \App\Models\ProductVariant)->flushCache();
$r = StoreInventoryValuation::forStore($A);
check($r->cost(), 0.1, 'cost');
check($r->retailValue(), 0.3, 'retail value');
check($r->potentialProfit(), 0.2, 'profit rounded after subtraction');

echo "the empty catalogue:\n";
DB::table('products')->truncate();
(new \App\Models\Product)->flushCache(); (new \App\Models\ProductVariant)->flushCache();
$e = StoreInventoryValuation::forStore($A);
check($e->units(), 0, 'units');
check($e->cost(), 0.0, 'cost');
check($e->retailValue(), 0.0, 'retail value');
check($e->hasCaveat(MetricCaveat::COST_UNKNOWN), false, 'no cost gap to disclose');

DB::setDefaultConnection('verify');
DB::connection('verify')->statement("DROP DATABASE IF EXISTS `$db`");
echo "\ndropped $db\n";
echo $fail === 0 ? "ALL CHECKS PASSED on " . DB::connection('verify')->getDriverName() . "\n" : "$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
