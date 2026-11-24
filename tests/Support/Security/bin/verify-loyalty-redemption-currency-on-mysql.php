<?php

/**
 * P5-R12 -- PROVE THE LOYALTY / REDEMPTION FIGURE ON A REAL MariaDB/MySQL SERVER, ACROSS CURRENCIES.
 *
 * WHY THIS EXISTS ALONGSIDE `verify-metric-layer-on-mysql.php`. That script already drives
 * `CouponRedemption` (import :53, use :306, expectation :403), so the metric is not uncovered. But
 * EVERY ORDER IN ITS FIXTURE IS `USD` OR NULL. It has no two-currency case anywhere, so the one
 * behaviour that matters most for a loyalty figure -- what happens when a merchant's redemptions
 * span currencies -- has never been executed on a real server. That is `ANA-RED`'s subject:
 * `redemptions()` could reach `MetricResult::total()`, which THROWS `MixedCurrencyException`
 * (MetricResult.php:190-193), and the screen beside it could print one currency's symbol over
 * another currency's amount.
 *
 * WHY A SCRIPT AND NOT A TEST, which is the same reason the sibling gives and is worth restating
 * because it is the whole point:
 *
 *   - **ONLY_FULL_GROUP_BY.** `CouponRedemption::for()` aggregates through `sumByCurrency()` over
 *     `orders.currency_code` while JOINing `order_coupon_details`. A grouped aggregate over a
 *     joined table is exactly the shape this mode rejects when a selected column is neither
 *     grouped nor aggregated. SQLite permits any shape, so the suite cannot fail here. The mode is
 *     FORCED ON below rather than taken from the server, because this container does not enable it
 *     by default and running without it would prove nothing about production.
 *   - **`coupon_discount_amount` IS A REAL FLOAT.** The metric attaches `MetricCaveat::FLOAT_PRECISION`
 *     on the strength of that. On SQLite the column's declared type is a suggestion, so the caveat
 *     is justified by a comment rather than by the schema. §L1 reads the live `information_schema`
 *     and asserts the type, so the caveat is grounded in the server rather than in prose.
 *   - **CURRENCY CODE COLLATION.** `usd` and `USD` fold together in a MySQL `GROUP BY` under
 *     `utf8mb4_unicode_ci` and do not fold in PHP. A figure that reads as "mixed currency" only
 *     because of letter case is a false refusal, and only a real server can show which happens.
 *
 * A conditionally-skipped test could not cover these: it would break the declared outcome set in
 * `.github/known-test-outcomes.json`. So this states its own preconditions and reports what it
 * could and could not do.
 *
 * WHAT IT NEVER DOES. It never touches a database it did not create. The name carries a
 * `loyalty_redemption_verify` prefix plus the process id, is created at the start and dropped at
 * the end, and any name that does not say it is throwaway is refused.
 *
 * PRECONDITIONS, checked rather than assumed:
 *   - a reachable MariaDB/MySQL server and an account that may CREATE and DROP DATABASE;
 *   - this repository's `vendor/` installed.
 *
 * Usage:
 *   php tests/Support/Security/bin/verify-loyalty-redemption-currency-on-mysql.php
 *   FRESH_MYSQL_SOCKET=/run/mysqld/mysqld.sock php tests/Support/Security/bin/verify-loyalty-redemption-currency-on-mysql.php
 *
 * Exit status: 0 = every check passed · 1 = a check failed · 2 = a precondition was not met.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Metrics\CouponRedemption;
use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\MixedCurrencyException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: ('loyalty_redemption_verify_' . getmypid());

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
    'strict' => true,
    'modes' => [
        'ONLY_FULL_GROUP_BY',
        'STRICT_TRANS_TABLES',
        'NO_ZERO_IN_DATE',
        'NO_ZERO_DATE',
        'ERROR_FOR_DIVISION_BY_ZERO',
        'NO_ENGINE_SUBSTITUTION',
    ],
];

$bootstrapConnection = $connection;
$bootstrapConnection['database'] = null;

config(['database.connections.loyalty_bootstrap' => $bootstrapConnection]);
config(['database.connections.loyalty_fresh' => $connection]);

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
    DB::connection('loyalty_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('loyalty_bootstrap')->statement(
        "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$exit = 0;

try {
    config(['database.default' => 'loyalty_fresh']);
    DB::purge('loyalty_fresh');

    echo "Server: " . DB::connection('loyalty_fresh')->selectOne('SELECT VERSION() v')->v . "\n";
    echo "sql_mode: " . DB::connection('loyalty_fresh')->selectOne('SELECT @@session.sql_mode m')->m . "\n";
    echo "Database: {$database}\n\n";

    echo "Running migrations...\n";
    Artisan::call('migrate', ['--database' => 'loyalty_fresh', '--force' => true]);
    echo "  done\n\n";

    foreach (['orders', 'order_coupon_details', 'stores', 'users'] as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            fwrite(STDERR, "PRECONDITION NOT MET: migrations did not create `{$table}`.\n");
            exit(2);
        }
    }

    // ---------------------------------------------------------------- fixture

    $now = now();

    foreach ([1 => 'loyalty-a', 2 => 'loyalty-b'] as $id => $slug) {
        $ownerId = DB::table('users')->insertGetId([
            'name' => 'Owner ' . $id, 'email' => $slug . '@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('stores')->insert([
            'id' => $id, 'name' => 'Store ' . $id, 'slug' => $slug,
            'email' => $slug . '@example.test', 'theme_id' => 'stylique',
            'created_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $orderSeq = 0;

    /** An order, and a coupon redeemed against it. Returns the order id. */
    $redeemed = function (int $storeId, string $currency = null, string $date = '2026-11-10 09:00:00',
                          float $discount = 0.0) use (&$orderSeq, $now): int {
        $orderSeq++;

        // The column set is the sibling harness's, measured: `orders` has `product_order_id` and a
        // non-null `product_json`, and no `order_id` / `order_status`. Guessing those cost one run.
        $orderId = DB::table('orders')->insertGetId([
            'product_order_id' => 'LOY-' . $orderSeq,
            'product_json' => json_encode([]),
            'store_id' => $storeId,
            'order_date' => $date,
            'final_price' => 100.0,
            'product_price' => 100.0,
            'delivery_price' => 0,
            'tax_price' => 0,
            'coupon_price' => $discount,
            'currency_code' => $currency,
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('order_coupon_details')->insert([
            'order_id' => $orderId,
            'coupon_id' => 1,
            'coupon_code' => 'LOY' . $orderSeq,
            'coupon_discount_amount' => $discount,
            'coupon_final_amount' => 100.0 - $discount,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $orderId;
    };

    $metric = new CouponRedemption();
    $all = MetricWindow::allTime();

    echo "L1. THE CAVEAT'S PREMISE, read from the live schema rather than from a comment.\n";

    $column = DB::connection('loyalty_fresh')->selectOne(
        'SELECT DATA_TYPE t FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$database, 'order_coupon_details', 'coupon_discount_amount']
    );

    check(
        'L1 `order_coupon_details.coupon_discount_amount` really is a float type on the server, '
        . 'which is what MetricCaveat::FLOAT_PRECISION claims',
        $column !== null && in_array(strtolower($column->t), ['float', 'double'], true),
        'DATA_TYPE = ' . ($column->t ?? 'column absent')
    );

    echo "\nL2-L6. TWO CURRENCIES IN ONE MERCHANT'S REDEMPTIONS.\n";

    $redeemed(1, 'USD', '2026-11-10 09:00:00', 10.0);
    $redeemed(1, 'USD', '2026-11-11 09:00:00', 5.0);
    $redeemed(1, 'SYP', '2026-11-12 09:00:00', 2000.0);

    $mixed = $metric->for(MetricScope::store(1), $all);

    check('L2 the result is NOT single-currency', ! $mixed->isSingleCurrency(),
        'currencies: ' . implode(',', $mixed->currencies()));

    check('L3 the two currencies are held SEPARATELY and never summed',
        near($mixed->totalIn('USD'), 15.0) && near($mixed->totalIn('SYP'), 2000.0),
        'USD=' . $mixed->totalIn('USD') . ' SYP=' . $mixed->totalIn('SYP'));

    /*
     * THE ANA-RED CHECK. `total()` must REFUSE rather than return a number, because any number it
     * returned would be 2015 of nothing -- and a screen would print it beside one currency's symbol.
     */
    $threw = false;
    try {
        $mixed->total();
    } catch (MixedCurrencyException $e) {
        $threw = true;
    }

    check('L4 total() THROWS MixedCurrencyException instead of adding 15 USD to 2000 SYP', $threw);

    check('L5 currency() is null for a mixed result, so no caller can label the figure',
        $mixed->currency() === null, var_export($mixed->currency(), true));

    check('L6 the grouped aggregate over the joined table runs under ONLY_FULL_GROUP_BY at all',
        $mixed->rowCount() === 3, 'rowCount=' . $mixed->rowCount());

    echo "\nL7. A SINGLE-CURRENCY MERCHANT IS STILL ANSWERABLE.\n";

    $redeemed(2, 'USD', '2026-11-10 09:00:00', 7.5);
    $single = $metric->for(MetricScope::store(2), $all);

    check('L7a a single-currency result answers total() without throwing',
        $single->isSingleCurrency() && near($single->total(), 7.5),
        'total=' . ($single->isSingleCurrency() ? (string) $single->total() : 'mixed'));

    check('L7b and names its currency', $single->currency() === 'USD',
        var_export($single->currency(), true));

    echo "\nL8. CASE IS NOT A CURRENCY. `usd` and `USD` must be ONE bucket, not a false refusal.\n";

    $redeemed(3, 'usd', '2026-11-10 09:00:00', 1.0);
    $redeemed(3, 'USD', '2026-11-11 09:00:00', 2.0);

    $cased = $metric->for(MetricScope::store(3), $all);

    check('L8 lower- and upper-case USD fold into one currency totalling 3.00',
        $cased->isSingleCurrency() && near($cased->total(), 3.0),
        'currencies: ' . implode(',', $cased->currencies()));

    echo "\nL9. TENANT SCOPE. Store 1's figure must not contain store 2's or 3's redemptions.\n";

    /*
     * RECOMPUTED HERE, and that is the whole check. The first version re-read `$mixed`, which was
     * computed above BEFORE stores 2 and 3 had any rows -- so it asserted isolation against data
     * that did not yet exist and passed whatever the scope did. A mutation that widened the tenant
     * predicate to `MetricScope::stores([1, 2, 3])` failed L7a, L7b, L8 and L10 and left L9 GREEN,
     * which is how the vacuousness was found rather than shipped.
     */
    $isolated = $metric->for(MetricScope::store(1), $all);

    check('L9 stores 2 and 3 are absent from store 1, recomputed with their rows present '
        . '(15.00 USD, not 25.50)',
        near($isolated->totalIn('USD'), 15.0) && near($isolated->totalIn('SYP'), 2000.0),
        'USD=' . $isolated->totalIn('USD') . ' SYP=' . $isolated->totalIn('SYP'));

    echo "\nL10. THE WINDOW, inclusive at both ends, on orders.order_date.\n";

    $edge = $metric->for(
        MetricScope::store(1),
        MetricWindow::between(
            \Carbon\Carbon::parse('2026-11-10 00:00:00'),
            \Carbon\Carbon::parse('2026-11-11 23:59:59')
        )
    );

    check('L10 a 10th-to-11th window holds both USD redemptions and excludes the 12th',
        $edge->isSingleCurrency() && near($edge->total(), 15.0),
        'currencies: ' . implode(',', $edge->currencies())
        . ' total: ' . ($edge->isSingleCurrency() ? (string) $edge->total() : 'mixed'));

    echo "\nL11. THE FLOAT CAVEAT IS ACTUALLY ATTACHED, now that L1 has justified it.\n";

    check('L11 the result carries MetricCaveat::FLOAT_PRECISION',
        $mixed->hasCaveat(MetricCaveat::FLOAT_PRECISION),
        'caveats: ' . implode(',', $mixed->caveats()));

    echo "\n{$checks} checks, {$failures} failed\n";
    $exit = $failures > 0 ? 1 : 0;
} catch (\Throwable $e) {
    fwrite(STDERR, "\nUNEXPECTED: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $exit = 1;
} finally {
    try {
        DB::purge('loyalty_fresh');
        DB::connection('loyalty_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "Dropped {$database}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "WARNING: could not drop {$database} -- {$e->getMessage()}\n");
    }
}

exit($exit);
