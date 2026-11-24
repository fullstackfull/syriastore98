<?php

/**
 * P4-F128 -- does leg 1 report an ORPHANED entitlement row? READ ONLY, against a throwaway database.
 * =================================================================================================
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST. Leg 1 of SettlementEntitlementReconciliation gained an
 * `o.id IS NULL` limb and a LEFT JOIN after independent review found that the original INNER JOIN
 * silently dropped a financial row whose `order_id` matches no order. Two facts, both measured,
 * make that limb unexercisable by the suite:
 *
 *   1. THE SCHEMA CANNOT REACH THE STATE. Deleting an order CASCADES and removes the financial row
 *      with it, so with foreign keys enforced an orphan cannot exist. The limb is DEFENSIVE -- it
 *      guards against a RESTORED COPY whose referential integrity is gone (a partial dump, a table
 *      restored without its constraints), which is precisely the kind of database this reporter is
 *      pointed at.
 *   2. THE SUITE CANNOT BUILD IT EITHER. SQLite's `PRAGMA foreign_keys` is a NO-OP inside a
 *      transaction, and RefreshDatabase wraps every test in one. Switching enforcement off in a
 *      test does nothing -- measured; the cascade still fired.
 *
 * A conditionally-skipped test would break the declared known-outcome set, so per CLAUDE.md this
 * lives here instead, stating its own preconditions.
 *
 * IT CREATES NO GATE F MONEY TABLE BY HAND. The throwaway database is built by running the REAL
 * migrations, so the structure is production's, and the GATE F containment guard has nothing to
 * object to.
 *
 * EXIT CODES: 0 the orphan IS reported (the limb works) | 1 it is not | 2 the harness failed.
 *
 *     php tests/Support/Security/bin/verify-orphaned-entitlement-is-reported.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Security\SettlementEntitlementReconciliation;

$file = sys_get_temp_dir() . '/f128-orphan-' . getmypid() . '.sqlite';
@unlink($file);
touch($file);

echo "P4-F128 -- is an ORPHANED entitlement row reported by leg 1?\n";
echo str_repeat('=', 78) . "\n";
printf("throwaway database: %s\n", $file);

$exit = 2;

try {
    config(['database.connections.f128_orphan' => [
        'driver' => 'sqlite', 'database' => $file, 'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    config(['database.default' => 'f128_orphan']);

    Artisan::call('migrate', ['--force' => true, '--database' => 'f128_orphan']);
    echo "migrations: ran\n";

    $db = DB::connection('f128_orphan');

    $ownerId = $db->table('users')->insertGetId([
        'name' => 'Orphan owner', 'email' => 'orphan-owner@example.test',
        'password' => bcrypt('x'), 'type' => 'admin',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $db->table('stores')->insert([
        'id' => 1, 'name' => 'Orphan store', 'slug' => 'orphan-store',
        'email' => 'orphan@example.test', 'theme_id' => 'stylique',
        'created_by' => $ownerId, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $walletId = $db->table('store_wallets')->insertGetId([
        'store_id' => 1, 'currency_code' => 'USD', 'available_balance' => 0,
        'pending_balance' => 0, 'withdrawal_pending_balance' => 0,
        'total_income' => 0, 'total_withdrawn' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $orderId = $db->table('orders')->insertGetId([
        'product_order_id' => 'ORD-ORPHAN', 'product_json' => json_encode([]),
        'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
        'coupon_price' => 0.0, 'final_price' => 100.0,
        'payment_type' => 'cod', 'payment_status' => 'Unpaid', 'store_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $financialId = $db->table('store_order_financials')->insertGetId([
        'store_id' => 1, 'wallet_id' => $walletId, 'order_id' => $orderId,
        'currency_code' => 'USD', 'currency_symbol' => '$',
        'product_amount' => 100, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0,
        'customer_total' => 100, 'product_cost' => 0, 'gross_profit' => 0,
        'payment_status' => 'unpaid', 'paid_amount' => 0, 'remaining_amount' => 100,
        'paid_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // PREMISE 1: with the constraints ON, the reporter must find nothing.
    if ((new SettlementEntitlementReconciliation($db))->items() !== []) {
        echo "PREMISE FAILED: a consistent fixture already reports a divergence.\n";
        throw new RuntimeException('premise 1');
    }
    echo "premise: consistent fixture reports nothing -- OK\n";

    // PREMISE 2: the cascade is real, which is WHY this cannot be a test.
    $db->statement('PRAGMA foreign_keys = ON');
    $db->table('orders')->where('id', $orderId)->delete();
    if ($db->table('store_order_financials')->where('id', $financialId)->exists()) {
        echo "PREMISE FAILED: no cascade, so the orphan state is reachable and this should be a test.\n";
        throw new RuntimeException('premise 2');
    }
    echo "premise: deleting the order CASCADED the financial row away -- OK (the limb is defensive)\n";

    // Now build the state a broken restore would present: constraints OFF, outside any
    // transaction -- which is the whole reason this is a script. Inside RefreshDatabase's
    // transaction this PRAGMA is a no-op and the insert below is simply refused.
    $db->statement('PRAGMA foreign_keys = OFF');
    $db->table('store_order_financials')->insert([
        'id' => $financialId, 'store_id' => 1, 'wallet_id' => $walletId,
        'order_id' => $orderId,                       // an order that no longer exists
        'currency_code' => 'USD', 'currency_symbol' => '$',
        'product_amount' => 100, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0,
        'customer_total' => 100, 'product_cost' => 0, 'gross_profit' => 0,
        'payment_status' => 'unpaid', 'paid_amount' => 0, 'remaining_amount' => 100,
        'paid_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    echo "orphan inserted (constraints off, as a lossy restore would leave it)\n";

    $codes = array_column((new SettlementEntitlementReconciliation($db))->items(), 'code');

    echo str_repeat('-', 78) . "\n";
    if (in_array('D4', $codes, true)) {
        echo "PASS -- leg 1 reports the orphan (D4).\n";
        echo "The LEFT JOIN is doing its job. An INNER JOIN returns 0 items here.\n";
        $exit = 0;
    } else {
        echo "FAIL -- the orphan was NOT reported. Codes present: " . implode(', ', $codes ?: ['none']) . "\n";
        $exit = 1;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "harness failed: {$e->getMessage()}\n");
    $exit = 2;
} finally {
    @unlink($file);
}

echo "\nNothing outside the throwaway database was touched.\n";
exit($exit);
