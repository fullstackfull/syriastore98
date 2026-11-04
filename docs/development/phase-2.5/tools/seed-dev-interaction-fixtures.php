<?php

/**
 * SEED THE ROWS THE INTERACTION PROBES NEED. Dev fixture only, idempotent, and reversible.
 * =================================================================================================
 *
 * WHY. render-matrix.cjs proves a screen RENDERS in four locale/theme combinations. It cannot prove
 * an INTERACTION, and two of this phase's repairs have nothing to interact with in the shipped dev
 * fixture:
 *
 *   store_withdrawals = 0  -- so /finance/withdrawals has no Approve or Mark-as-paid button, and the
 *                             two confirm() dialogs translated in fc25f3b0 cannot be driven at all.
 *                             That repair's whole point is the text a merchant reads in Arabic.
 *   orders            = 7  -- spread over six statuses, so no column reaches the 50-card cap added
 *                             in daf375da and the overflow link never renders.
 *
 * REVERSIBLE ON PURPOSE. Every row this writes is tagged: withdrawals carry store_note
 * 'P25PROBE', orders carry a 'P25PROBE-' product_order_id prefix. Pass --remove to delete exactly
 * those and nothing else, so the fixture goes back to what the render matrix was measured against.
 * A probe fixture that cannot be removed silently becomes the baseline for the next person's
 * screenshots.
 *
 * usage:
 *   DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite CACHE_STORE=file \
 *     php docs/development/phase-2.5/tools/seed-dev-interaction-fixtures.php [--remove]
 *
 * Same DB/cache env as serve-dev.sh, or it writes to a different sqlite file and reports success.
 * Written through Eloquent, not raw SQL: 79 of the 95 models are Cachable, and a raw write leaves
 * Eloquent reads -- route-model binding included -- serving the pre-write row.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\StoreWithdrawal;
use App\Models\User;

$remove = in_array('--remove', $argv, true);

$store = Store::withoutGlobalScopes()->first();
$merchant = User::where('email', 'merchant@example.test')->first();

if (! $store || ! $merchant) {
    fwrite(STDERR, "fixture is missing a store or merchant@example.test\n");
    exit(1);
}

$PROBE_NOTE = 'P25PROBE';
$PROBE_REF = 'P25PROBE-';
$CAP_OVERSHOOT = 55;   // OrderController::BOARD_COLUMN_CAP is 50, so 55 proves the cap AND the count

if ($remove) {
    $w = StoreWithdrawal::where('store_note', $PROBE_NOTE)->get();
    foreach ($w as $row) { $row->delete(); }

    $o = Order::withoutGlobalScopes()->where('product_order_id', 'like', $PROBE_REF . '%')->get();
    foreach ($o as $row) { $row->delete(); }

    printf("removed %d withdrawal(s) and %d order(s)\n", $w->count(), $o->count());
    exit(0);
}

$wallet = StoreWallet::where('store_id', $store->id)->first();

if (! $wallet) {
    fwrite(STDERR, "no wallet for store {$store->id}; /finance must be opened once to create it\n");
    exit(1);
}

// A PENDING withdrawal is what renders Approve / Reject / Mark-as-paid. Its amount must be <= the
// wallet's available balance or the screen's own guards may suppress the controls.
if ($wallet->available_balance < 500) {
    $wallet->available_balance = 500;
    $wallet->save();
}

$withdrawal = StoreWithdrawal::where('store_note', $PROBE_NOTE)->first();

if (! $withdrawal) {
    $withdrawal = new StoreWithdrawal();
    $withdrawal->store_id = $store->id;
    $withdrawal->wallet_id = $wallet->id;
    $withdrawal->amount = 250.00;
    $withdrawal->status = 'pending';
    $withdrawal->payment_method = 'bank';
    $withdrawal->payment_details = 'Probe account';
    $withdrawal->store_note = $PROBE_NOTE;
    $withdrawal->requested_by = $merchant->id;
    $withdrawal->requested_at = now();
    $withdrawal->save();
}

$have = Order::withoutGlobalScopes()->where('product_order_id', 'like', $PROBE_REF . '%')->count();

for ($i = $have + 1; $i <= $CAP_OVERSHOOT; $i++) {
    $o = new Order();
    $o->product_order_id = $PROBE_REF . $i;
    $o->order_date = now();
    $o->customer_id = 1;
    $o->product_id = '1';
    $o->product_json = json_encode([]);
    $o->final_price = 10.00;
    $o->delivered_status = \App\Http\Controllers\HomeController::ORDER_STATUS_PENDING;
    $o->payment_status = 'pending';
    $o->store_id = $store->id;
    $o->save();
}

// PROVE IT rather than report it.
$pending = Order::withoutGlobalScopes()
    ->where('store_id', $store->id)
    ->where('delivered_status', \App\Http\Controllers\HomeController::ORDER_STATUS_PENDING)
    ->count();

printf("store %d: %d pending withdrawal(s) tagged %s, %d Pending order(s) (cap is 50, so the board must truncate and say so)\n",
    $store->id,
    StoreWithdrawal::where('store_note', $PROBE_NOTE)->where('status', 'pending')->count(),
    $PROBE_NOTE,
    $pending);
