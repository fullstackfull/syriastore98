<?php

/**
 * P5-2 -- PROVE THE METRIC LAYER ON A REAL MariaDB/MySQL SERVER.
 *
 * WHY A SCRIPT AND NOT A TEST. The suite runs on SQLite `:memory:`, and this layer is exactly the
 * kind of code SQLite flatters:
 *
 *   - **ONLY_FULL_GROUP_BY.** Every metric aggregates with a `GROUP BY` over a currency column
 *     written through `selectRaw`. SQLite permits any shape; MySQL under this mode rejects a
 *     non-aggregated selected column that is not grouped. A layer that works on SQLite and throws
 *     `SQLSTATE[42000]` on the production server is not a layer that works.
 *   - **DECIMAL(15,2) vs float affinity.** §6 asserts two independent computations of net receipts
 *     agree. On SQLite `decimal(15,2)` is a suggestion and both sides are floats, so they agree
 *     for the wrong reason. On MySQL they are real decimals and the agreement means something.
 *     This is the same blind spot that let P01-SEC-13 survive.
 *   - **`COALESCE(a_DATE, a_DATETIME)` compared against a DATETIME bound.** §4 dates cancellations
 *     that way because one door writes no `cancel_date`. SQLite compares the strings; MySQL casts
 *     the DATE to midnight. A bound that drops the last day of the range would under-count
 *     cancellations invisibly.
 *   - **`LOWER(TRIM(payment_type))`** against a `varchar` whose stored values are inconsistently
 *     cased, under the server's own collation rather than SQLite's.
 *
 * A conditionally-skipped test could not cover these either: it would break the declared skip set
 * in `.github/known-test-outcomes.json`. So this states its own preconditions and reports what it
 * could and could not do.
 *
 * WHAT IT NEVER DOES. It never touches a database it did not create. The database is named with a
 * `metric_layer_verify` prefix plus the process id, created at the start and dropped at the end,
 * and the script refuses any name that does not say it is throwaway.
 *
 * PRECONDITIONS, checked rather than assumed:
 *   - a reachable MariaDB/MySQL server and an account that may CREATE and DROP DATABASE;
 *   - this repository's `vendor/` installed.
 *
 * Usage:
 *   php tests/Support/Security/bin/verify-metric-layer-on-mysql.php
 *   FRESH_MYSQL_SOCKET=/run/mysqld/mysqld.sock php tests/Support/Security/bin/verify-metric-layer-on-mysql.php
 *
 * Exit status: 0 = every check passed · 1 = a check failed · 2 = a precondition was not met.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Metrics\CancelledOrders;
use App\Support\Metrics\CollectedCustomerPayments;
use App\Support\Metrics\CompletedRefunds;
use App\Support\Metrics\CouponRedemption;
use App\Support\Metrics\GrossMerchandiseValue;
use App\Support\Metrics\MerchantWalletEntitlement;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\NetFinancialReceipts;
use App\Support\Metrics\ShippingAndTax;
use App\Support\Metrics\StoreRevenueMargin;
use App\Support\Metrics\UnpaidOrders;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: ('metric_layer_verify_' . getmypid());

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

/*
 * ONLY_FULL_GROUP_BY IS FORCED ON, and that is the point of the `modes` key. This container's
 * server does not enable it by default, so running without it would prove nothing about a
 * production server that does. Testing under the STRICTER mode is the only direction that is safe
 * to be wrong in.
 */
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

config(['database.connections.metric_bootstrap' => $bootstrapConnection]);
config(['database.connections.metric_fresh' => $connection]);

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

// ------------------------------------------------------------------ preconditions

try {
    DB::connection('metric_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('metric_bootstrap')->statement(
        "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$exit = 0;

try {
    config(['database.default' => 'metric_fresh']);
    DB::purge('metric_fresh');

    echo "Server: " . DB::connection('metric_fresh')->selectOne('SELECT VERSION() v')->v . "\n";
    echo "sql_mode: " . DB::connection('metric_fresh')->selectOne('SELECT @@session.sql_mode m')->m . "\n";
    echo "Database: {$database}\n\n";

    echo "Running migrations...\n";
    Artisan::call('migrate', ['--database' => 'metric_fresh', '--force' => true]);
    echo "  done\n\n";

    foreach (['orders', 'store_order_financials', 'store_wallets', 'store_wallet_transactions',
              'order_coupon_details', 'stores'] as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            fwrite(STDERR, "PRECONDITION NOT MET: migrations did not create `{$table}`.\n");
            exit(2);
        }
    }

    // -------------------------------------------------------------- fixture

    $now = now();

    foreach ([1 => 'mysql-a', 2 => 'mysql-b'] as $id => $slug) {
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
        DB::table('store_wallets')->insert([
            'id' => $id, 'store_id' => $id, 'currency_code' => 'USD',
            'available_balance' => 0, 'pending_balance' => 0, 'withdrawal_pending_balance' => 0,
            'total_income' => 0, 'total_withdrawn' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $orderSeq = 0;
    $order = function (array $a = []) use (&$orderSeq, $now): int {
        $orderSeq++;
        return DB::table('orders')->insertGetId(array_merge([
            'product_order_id' => 'MYSQL-' . $orderSeq, 'product_json' => json_encode([]),
            'product_price' => 0, 'delivery_price' => 0, 'tax_price' => 0, 'coupon_price' => 0,
            'final_price' => 100.0, 'currency_code' => 'USD',
            'payment_type' => 'cod', 'payment_status' => 'Unpaid',
            'store_id' => 1, 'order_date' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ], $a));
    };

    $financial = function (int $orderId, array $a = []) use ($now): void {
        $storeId = (int) ($a['store_id'] ?? 1);
        DB::table('store_order_financials')->insert(array_merge([
            'store_id' => $storeId, 'wallet_id' => $storeId, 'order_id' => $orderId,
            'currency_code' => 'USD',
            'product_amount' => 100.0, 'discount_amount' => 0, 'tax_amount' => 0,
            'shipping_amount' => 0, 'customer_total' => 100.0,
            'product_cost' => 40.0, 'gross_profit' => 60.0,
            'payment_status' => 'unpaid', 'paid_amount' => 0, 'remaining_amount' => 100.0,
            'paid_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ], $a));
    };

    $movement = function (array $a) use ($now): void {
        $storeId = (int) ($a['store_id'] ?? 1);
        DB::table('store_wallet_transactions')->insert(array_merge([
            'store_id' => $storeId, 'wallet_id' => $storeId, 'currency_code' => 'USD',
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 100.0,
            'balance_before' => 0, 'balance_after' => 100.0,
            'reference_type' => 'order', 'reference_id' => null,
            'description' => 'verify', 'status' => 'completed',
            'created_at' => $now, 'updated_at' => $now,
        ], $a));
    };

    /*
     * THE AMOUNTS ARE CHOSEN SO FLOAT ARITHMETIC WOULD NOT REPRODUCE THEM. 1234567.89 exceeds the
     * ~7 significant digits a FLOAT holds, and the three legs below sum to a value whose last
     * cent only survives if the column really is DECIMAL(15,2) all the way through.
     */
    $paid = $order(['final_price' => 1234567.89]);
    $financial($paid, ['payment_status' => 'paid', 'paid_amount' => 1234567.89,
        'paid_at' => $now, 'remaining_amount' => 0, 'customer_total' => 1234567.89,
        'product_amount' => 1234567.89, 'product_cost' => 0, 'gross_profit' => 1234567.89]);
    $movement(['amount' => 1234567.89, 'reference_id' => $paid,
        'balance_before' => 0, 'balance_after' => 1234567.89]);

    $cancelled = $order(['final_price' => 0.07, 'cancel_date' => null]);
    $financial($cancelled, ['payment_status' => 'cancelled', 'paid_amount' => 0.07,
        'paid_at' => $now, 'remaining_amount' => 0, 'customer_total' => 0.07]);
    $movement(['amount' => 0.07, 'reference_id' => $cancelled,
        'balance_before' => 1234567.89, 'balance_after' => 1234567.96]);
    $movement(['type' => 'order_cancelled', 'direction' => 'debit', 'amount' => 0.07,
        'reference_id' => $cancelled, 'balance_before' => 1234567.96, 'balance_after' => 1234567.89]);

    $refunded = $order(['final_price' => 0.03]);
    $financial($refunded, ['payment_status' => 'refunded', 'paid_amount' => 0.03,
        'paid_at' => $now, 'remaining_amount' => 0, 'customer_total' => 0.03]);
    $movement(['amount' => 0.03, 'reference_id' => $refunded,
        'balance_before' => 1234567.89, 'balance_after' => 1234567.92]);
    $movement(['type' => 'order_refunded', 'direction' => 'debit', 'amount' => 0.03,
        'reference_id' => $refunded, 'balance_before' => 1234567.92, 'balance_after' => 1234567.89]);

    // Mixed-case payment types, to exercise LOWER(TRIM(...)) on the server's own collation.
    $financial($order(['payment_type' => '  COD  ']), ['payment_status' => 'pending', 'remaining_amount' => 30.0]);
    $financial($order(['payment_type' => 'Bank_Transfer']), ['payment_status' => 'pending', 'remaining_amount' => 50.0]);
    $financial($order(['payment_type' => 'stripe']), ['payment_status' => 'unpaid', 'remaining_amount' => 70.0]);

    /*
     * Two rows that match NO §3 bucket, added for the disclosure review found missing. The second
     * is the one that needs a real engine: its `payment_type` is NULL, and `NULL NOT IN (...)`
     * evaluates to NULL rather than true, so without the explicit null limb this row falls out of
     * the very count that exists to catch it.
     */
    $financial($order(['payment_type' => 'stripe']), ['payment_status' => 'pending', 'remaining_amount' => 120.0]);
    $financial($order(['payment_type' => null]), ['payment_status' => 'pending', 'remaining_amount' => 45.0]);

    // Shipping/tax, and a coupon.
    $shipped = $order(['delivery_price' => 25.0]);
    $financial($shipped, ['shipping_amount' => 25.0, 'tax_amount' => 8.0]);
    DB::table('orders')->where('id', $shipped)->update(['delivery_price' => 0]);
    DB::table('order_coupon_details')->insert([
        'order_id' => $shipped, 'coupon_id' => 1, 'coupon_code' => 'TEN',
        'coupon_discount_amount' => 10.0, 'coupon_final_amount' => 90.0,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // Store B's books -- larger in every table.
    $foreign = $order(['store_id' => 2, 'final_price' => 9999.0, 'cancel_date' => $now->toDateString()]);
    $financial($foreign, ['store_id' => 2, 'payment_status' => 'paid', 'paid_amount' => 9999.0,
        'paid_at' => $now, 'customer_total' => 9999.0, 'shipping_amount' => 99.0, 'tax_amount' => 99.0,
        'product_amount' => 9999.0, 'product_cost' => 0, 'gross_profit' => 9999.0]);
    $movement(['store_id' => 2, 'amount' => 9999.0, 'reference_id' => $foreign,
        'balance_before' => 0, 'balance_after' => 9999.0]);
    DB::table('store_wallets')->where('id', 2)->update(['available_balance' => 1234.0]);

    DB::table('store_wallets')->where('id', 1)->update(['available_balance' => 1234567.89]);

    $scope = MetricScope::store(1);
    $all = MetricWindow::allTime();

    echo "A. Every metric runs under ONLY_FULL_GROUP_BY\n";

    $results = [];
    foreach ([
        'gmv' => fn () => (new GrossMerchandiseValue())->for($scope, $all),
        'collected' => fn () => (new CollectedCustomerPayments())->for($scope, $all),
        'cancelled' => fn () => (new CancelledOrders())->for($scope, $all),
        'refunds' => fn () => (new CompletedRefunds())->for($scope, $all),
        'net_receipts' => fn () => (new NetFinancialReceipts())->for($scope, $all),
        'wallet' => fn () => (new MerchantWalletEntitlement())->for($scope, $all),
        'margin' => fn () => (new StoreRevenueMargin())->for($scope, $all),
        'coupons' => fn () => (new CouponRedemption())->for($scope, $all),
    ] as $name => $run) {
        try { $results[$name] = $run(); check("{$name} executes", true); }
        catch (\Throwable $e) { check("{$name} executes", false, get_class($e) . ': ' . $e->getMessage()); }
    }
    foreach (['unpaid' => new UnpaidOrders(), 'shipping_and_tax' => new ShippingAndTax()] as $name => $metric) {
        try { $results[$name] = $metric->all($scope, $all); check("{$name} executes (multi-figure)", true); }
        catch (\Throwable $e) { check("{$name} executes (multi-figure)", false, get_class($e) . ': ' . $e->getMessage()); }
    }
    try { $drift = (new MerchantWalletEntitlement())->drift($scope); check('wallet drift executes', true); }
    catch (\Throwable $e) { $drift = null; check('wallet drift executes', false, get_class($e) . ': ' . $e->getMessage()); }

    echo "\nB. DECIMAL(15,2) is carried end to end, where SQLite would use floats\n";

    if (isset($results['collected'])) {
        check('collected payments keep the last cent of 1234567.89 + 0.07 + 0.03',
            near($results['collected']->total(), 1234567.99),
            'got ' . var_export($results['collected']->total(), true));
    }
    if (isset($results['net_receipts'])) {
        check('net receipts = 1234567.89 (the cancelled and refunded cents reversed exactly)',
            near($results['net_receipts']->total(), 1234567.89),
            'got ' . var_export($results['net_receipts']->total(), true));
    }

    echo "\nC. The two computations of net receipts agree on real decimals\n";

    try {
        $rec = (new NetFinancialReceipts())->reconcile($scope, $all);
        check('§6 agrees', $rec['agrees'], json_encode($rec));
        check('§6 reports no divergence', $rec['divergence'] === [], json_encode($rec['divergence']));
    } catch (\Throwable $e) {
        check('§6 reconciles', false, get_class($e) . ': ' . $e->getMessage());
    }

    echo "\nD. COALESCE(DATE, DATETIME) dates the door that writes no cancel_date\n";

    try {
        $bounded = (new CancelledOrders())->for(
            $scope,
            MetricWindow::between($now->copy()->subDay(), $now->copy())
        );
        check('a cancellation with a NULL cancel_date falls inside a window ending today',
            near($bounded->total(), 0.07), 'got ' . var_export($bounded->total(), true));
        check('and the fallback is declared', $bounded->notes()['dated_by_fallback'] === 1,
            var_export($bounded->notes()['dated_by_fallback'], true));
    } catch (\Throwable $e) {
        check('§4 bounded window', false, get_class($e) . ': ' . $e->getMessage());
    }

    echo "\nE. LOWER(TRIM(payment_type)) matches what the application matches\n";

    if (isset($results['unpaid'])) {
        check('"  COD  " is awaiting COD collection',
            near($results['unpaid'][UnpaidOrders::AWAITING_COD_COLLECTION]->total(), 30.0),
            'got ' . var_export($results['unpaid'][UnpaidOrders::AWAITING_COD_COLLECTION]->total(), true));
        check('"Bank_Transfer" is awaiting manual settlement',
            near($results['unpaid'][UnpaidOrders::AWAITING_MANUAL_SETTLEMENT]->total(), 50.0),
            'got ' . var_export($results['unpaid'][UnpaidOrders::AWAITING_MANUAL_SETTLEMENT]->total(), true));
        /*
         * 170, not 70, and the 100 is not a leak: the shipping fixture's financial row takes the
         * helper's default `payment_status => 'unpaid'` with `remaining_amount => 100`. Two
         * genuinely unpaid rows is the right answer; the first draft of this check asserted 70 and
         * was wrong about its own fixture.
         */
        check('"stripe" at unpaid is genuinely unpaid, with the shipping row that is also unpaid',
            near($results['unpaid'][UnpaidOrders::GENUINELY_UNPAID]->total(), 170.0),
            'got ' . var_export($results['unpaid'][UnpaidOrders::GENUINELY_UNPAID]->total(), true));

        $outside = $results['unpaid'][UnpaidOrders::AWAITING_COD_COLLECTION]
            ->notes()['pending_with_unclassified_payment_type'];

        check('two pending rows match no bucket and are DISCLOSED, the NULL payment type included',
            $outside === 2,
            'got ' . var_export($outside, true) . ' -- a NULL payment_type falls out of the count '
            . 'if `NULL NOT IN (...)` is relied on, because it is NULL and not true');
    }

    echo "\nF. Tenant isolation on the production driver\n";

    /*
     * EXACT TOTALS, NOT A THRESHOLD. The first draft of this section asked whether any figure was
     * ">= 9999" and reported five false leaks, because store A's own books run to 1.2 million --
     * a check that cannot tell a tenant leak from a large merchant is not a tenancy check. Store
     * B contributes 9,999.00 to each of these tables, so an exact expected total is both the
     * isolation assertion and the arithmetic one.
     */
    $expected = [
        'gmv' => 1235167.99,       // 1234567.89 + 0.07 + 0.03 + six orders at the 100.00 default
        'collected' => 1234567.99, // paid + the cancelled and refunded rows that WERE captured
        'cancelled' => 0.07,       // customer_total of the one cancelled row
        'refunds' => 0.03,         // the one order_refunded movement
        'net_receipts' => 1234567.89,
        'wallet' => 1234567.89,
        'margin' => 1234567.89,    // settled-only: the single `paid` row's gross_profit
        'coupons' => 10.00,
    ];

    foreach ($expected as $name => $value) {
        if (! isset($results[$name])) { continue; }
        check(
            sprintf('%s totals %.2f for store A, with store B\'s 9,999.00 absent', $name, $value),
            near($results[$name]->total(), $value),
            'got ' . var_export($results[$name]->total(), true)
        );
    }

    // The premise: store B really does hold the money these figures must not contain.
    $foreignCollected = (new CollectedCustomerPayments())->for(MetricScope::store(2), $all);
    check('premise: store B collected 9,999.00, so the figures above had something to exclude',
        near($foreignCollected->total(), 9999.0), 'got ' . var_export($foreignCollected->total(), true));
    check('the drift check does not reach store B\'s wallet (which is drifted by 8,765)',
        $drift === [], json_encode($drift));

    $foreignDrift = (new MerchantWalletEntitlement())->drift(MetricScope::store(2));
    check('premise: store B really is drifted, so the check above had something to catch',
        count($foreignDrift) === 1 && near($foreignDrift[0]['difference'], -8765.0),
        json_encode($foreignDrift));

    echo "\nG. Shipping survives the order column being zeroed\n";

    if (isset($results['shipping_and_tax'])) {
        check('shipping reads 25.00 from the financial row',
            near($results['shipping_and_tax'][ShippingAndTax::SHIPPING]->total(), 25.0));
        check('tax reads 8.00', near($results['shipping_and_tax'][ShippingAndTax::TAX]->total(), 8.0));
    }

    echo "\nH. §8 margin flags an unknown cost rather than reading it as pure profit\n";

    if (isset($results['margin'])) {
        check('margin is flagged cost-unknown',
            $results['margin']->hasCaveat(App\Support\Metrics\MetricCaveat::COST_UNKNOWN));
        check('and says how many rows had no cost',
            $results['margin']->notes()['rows_without_cost'] === 1,
            var_export($results['margin']->notes()['rows_without_cost'], true));
    }

    echo "\nI. §9 is never authoritative\n";

    if (isset($results['coupons'])) {
        check('coupon redemption is not authoritative', ! $results['coupons']->isAuthoritative());
    }

    echo "\n{$checks} checks, {$failures} failed\n";
    $exit = $failures > 0 ? 1 : 0;
} catch (\Throwable $e) {
    fwrite(STDERR, "\nUNEXPECTED: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $exit = 1;
} finally {
    try {
        DB::purge('metric_fresh');
        DB::connection('metric_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "Dropped {$database}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "WARNING: could not drop {$database} -- {$e->getMessage()}\n");
    }
}

exit($exit);
