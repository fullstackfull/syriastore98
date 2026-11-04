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
 *   store_wallet_transactions = 0, store_order_financials = 0
 *                          -- so the Credit, Debit and Gross-profit columns of /finance have NO
 *                             ROWS, and the six money figures whose colour was repaired in the
 *                             finance-money-figure-contrast slice cannot be measured as the app
 *                             really renders them. measure-finance-money-contrast-live.cjs worked
 *                             around that by INJECTING the markup into the real tbody, which keeps
 *                             the real cascade but is not the real page. These rows remove the
 *                             workaround: one credit and one debit transaction, and one profitable
 *                             and one loss-making financial row, so every tone renders natively.
 *
 * REVERSIBLE ON PURPOSE. Every row this writes is tagged: withdrawals carry store_note
 * 'P25PROBE', orders carry a 'P25PROBE-' product_order_id prefix, wallet transactions a
 * 'P25PROBE ' description prefix, and financial rows hang off the probe ORDERS -- that table has no
 * taggable text column, and `order_id` carries a FOREIGN KEY to `orders(id)` ON DELETE CASCADE, so
 * a reserved id band does not insert at all (measured: SQLSTATE 23000 on order_id 9250001) and the
 * probe orders are the only legitimate anchor. Pass --remove to delete exactly those and nothing
 * else, so the fixture goes back to what the render matrix was measured against.
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

use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
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
$PROBE_DESC = 'P25PROBE ';

if ($remove) {
    $w = StoreWithdrawal::where('store_note', $PROBE_NOTE)->get();
    foreach ($w as $row) { $row->delete(); }

    $o = Order::withoutGlobalScopes()->where('product_order_id', 'like', $PROBE_REF . '%')->get();

    // FINANCIAL ROWS FIRST, and explicitly, although the FOREIGN KEY is ON DELETE CASCADE: SQLite
    // enforces that only when `PRAGMA foreign_keys` is on, and Laravel's sqlite driver turns it on
    // per connection, so relying on the cascade here would make removal depend on a connection
    // setting rather than on this script. These are OrderObserver's rows, one per probe order --
    // this script only edited two of their figures -- so removing them with the orders is right.
    $f = DB::table('store_order_financials')->whereIn('order_id', $o->pluck('id'))->delete();

    foreach ($o as $row) { $row->delete(); }

    $t = StoreWalletTransaction::where('description', 'like', $PROBE_DESC . '%')->get();
    foreach ($t as $row) { $row->delete(); }

    printf("removed %d withdrawal(s), %d order(s), %d wallet transaction(s), %d financial row(s)\n",
        $w->count(), $o->count(), $t->count(), $f);
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

// THE SIX MONEY FIGURES OF /finance, SO THEIR COLOUR CAN BE MEASURED AS THE APP RENDERS IT.
// Shapes copied from the controller's own writers (addBalance :847 manual_deposit/credit,
// deductBalance :967 manual_withdrawal/debit) so these are not a made-up row kind. Written
// through Eloquent; StoreWalletTransaction is one of the 16 non-Cachable models, but the wallet
// it hangs off is Cachable and is saved through Eloquent above.
foreach ([
    ['type' => 'manual_deposit',    'direction' => 'credit', 'amount' => 125.50],
    ['type' => 'manual_withdrawal', 'direction' => 'debit',  'amount' => 42.75],
] as $spec) {
    $desc = $PROBE_DESC . $spec['direction'];

    if (StoreWalletTransaction::where('description', $desc)->exists()) {
        continue;
    }

    StoreWalletTransaction::create([
        'store_id' => $store->id,
        'wallet_id' => $wallet->id,
        'currency_code' => $wallet->currency_code ?: 'USD',
        'type' => $spec['type'],
        'direction' => $spec['direction'],
        'amount' => $spec['amount'],
        'balance_before' => $wallet->available_balance,
        'balance_after' => $wallet->available_balance,
        'reference_type' => 'manual',
        'reference_id' => null,
        'description' => $desc,
        'status' => 'completed',
        'created_by' => $merchant->id,
    ]);
}

// ONE PROFITABLE AND ONE LOSS-MAKING FINANCIAL ROW, because the gross-profit cell picks its tone
// on the sign: a column of zeros renders only the credit branch (`0 >= 0`) and leaves the debit
// tone unmeasured -- exactly the gap that made the previous measurement inject its own markup.
//
// THIS UPDATES, IT DOES NOT INSERT, and the reason is worth keeping. An insert against a reserved
// order_id band fails: `order_id` carries a FOREIGN KEY to `orders(id)` (measured, SQLSTATE 23000).
// And an insert against a probe order's id is not needed either, because `OrderObserver` ->
// `OrderFinancialService` ALREADY WROTE one row per probe order when the loop above created them
// -- 55 rows, every money column 0, because these probe orders carry no product cost data. So the
// rows exist, they are already anchored to tagged orders, and they already vanish with them; what
// they lack is a non-zero figure of each sign. Two UPDATEs supply that and nothing else.
$probeOrderIds = Order::withoutGlobalScopes()
    ->where('product_order_id', 'like', $PROBE_REF . '%')
    ->orderBy('id')
    ->limit(2)
    ->pluck('id')
    ->all();

if (count($probeOrderIds) < 2) {
    fwrite(STDERR, "expected at least 2 probe orders to anchor the financial figures\n");
    exit(1);
}

foreach ([
    ['order_id' => $probeOrderIds[0], 'profit' => 31.25],
    ['order_id' => $probeOrderIds[1], 'profit' => -14.40],
] as $spec) {
    DB::table('store_order_financials')
        ->where('order_id', $spec['order_id'])
        ->where('store_id', $store->id)
        ->update([
            'product_amount' => 100.00,
            'discount_amount' => 5.00,
            'tax_amount' => 3.00,
            'shipping_amount' => 2.00,
            'customer_total' => 100.00,
            'product_cost' => 100.00 - $spec['profit'],
            'gross_profit' => $spec['profit'],
            'payment_status' => 'paid',
            'paid_amount' => 100.00,
            'remaining_amount' => 0.00,
            'updated_at' => now(),
        ]);
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

printf("          %d wallet transaction(s) (%d credit, %d debit), %d financial row(s) (%d profit, %d loss)\n",
    StoreWalletTransaction::where('description', 'like', $PROBE_DESC . '%')->count(),
    StoreWalletTransaction::where('description', 'like', $PROBE_DESC . '%')->where('direction', 'credit')->count(),
    StoreWalletTransaction::where('description', 'like', $PROBE_DESC . '%')->where('direction', 'debit')->count(),
    DB::table('store_order_financials')->whereIn('order_id', $probeOrderIds)->count(),
    DB::table('store_order_financials')->whereIn('order_id', $probeOrderIds)->where('gross_profit', '>=', 0)->count(),
    DB::table('store_order_financials')->whereIn('order_id', $probeOrderIds)->where('gross_profit', '<', 0)->count());
