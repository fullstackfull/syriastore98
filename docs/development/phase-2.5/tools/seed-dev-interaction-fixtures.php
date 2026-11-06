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
use App\Models\Coupon;
use App\Models\Faq;
use App\Models\FlashSale;
use App\Models\ProductQuestion;
use App\Models\Testimonial;

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

// P2.5-PKG-B. THE SIX CATALOGUE SURFACES THAT HAD NOTHING TO RENDER.
//
// `probe-package-b-reachability.cjs` reported six rows as NO FIXTURE -- coupon detail, coupon edit,
// flash-sale edit, product-question reply, testimonial edit and the FAQ repeater -- because
// `coupons`, `flash_sales`, `product_questions`, `testimonials` and `faqs` all held ZERO rows
// (measured, not assumed). An edit modal with no row to edit cannot be driven at all, so six
// Package B surfaces had never been rendered in any locale or theme.
//
// Each table carries its own taggable text column, so every row below is removable by an exact
// predicate and `--remove` puts the fixture back to what the render matrix was measured against.
// That matters more here than elsewhere: a probe fixture that cannot be removed silently becomes
// the baseline for the next person's screenshots.
$PROBE_TAG = 'P25PROBE ';          // prefix for name/question/title/topic columns
$PROBE_COUPON_CODE = 'P25PROBECOUP';

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

    // P2.5-PKG-B catalogue rows. Eloquent delete(), not DB::table(), because all five models are
    // Cachable and a raw delete leaves Eloquent reads -- route-model binding included -- serving
    // the deleted row. CLAUDE.md records three measured false readings from exactly that.
    $cat = [];
    foreach ([
        ['coupons', Coupon::class, 'coupon_code', $PROBE_COUPON_CODE],
        ['flash_sales', FlashSale::class, 'name', $PROBE_TAG . '%'],
        ['product_questions', ProductQuestion::class, 'question', $PROBE_TAG . '%'],
        ['testimonials', Testimonial::class, 'title', $PROBE_TAG . '%'],
        ['faqs', Faq::class, 'topic', $PROBE_TAG . '%'],
    ] as [$label, $class, $col, $needle]) {
        $q = $class::withoutGlobalScopes();
        $rows = str_contains($needle, '%') ? $q->where($col, 'like', $needle)->get()
                                           : $q->where($col, $needle)->get();
        foreach ($rows as $row) { $row->delete(); }
        $cat[$label] = $rows->count();
    }

    printf("removed %d withdrawal(s), %d order(s), %d wallet transaction(s), %d financial row(s)\n",
        $w->count(), $o->count(), $t->count(), $f);
    printf("removed catalogue rows: %s\n", json_encode($cat));
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

// =================================================================================================
// P2.5-PKG-B — ONE ROW EACH FOR THE SIX SURFACES THAT HAD NOTHING TO RENDER.
//
// Idempotent on the tag, so re-running adds nothing. Every column written below was read off the
// LIVE schema with Schema::getColumnListing() before it was written, because a fixture that invents
// a column dies inside the fixture and the failure looks exactly like the defect the probe was
// written to find -- that happened in this work package, where an invented `color` column on
// product_labels produced three failures indistinguishable from a real cross-tenant hole, and only
// the positive control failing alongside them caught it.
//
// forceCreate() throughout: plain create() drops `store_id` on several of these models, which is
// the same trap makeRole() documents in the security tests.
// =================================================================================================

$catalogueStoreId = $store->id;
$catalogueCounts = [];

// A coupon that is NOT expired: `coupon_expiry_date` in the past renders the expired state, which
// is a different surface from the one the edit modal is being verified against.
if (! Coupon::withoutGlobalScopes()->where('coupon_code', $PROBE_COUPON_CODE)->exists()) {
    Coupon::forceCreate([
        'coupon_name' => $PROBE_TAG . 'Coupon',
        'coupon_code' => $PROBE_COUPON_CODE,
        'coupon_type' => 'percentage',
        'discount_amount' => 10,
        'coupon_limit' => 100,
        'coupon_limit_user' => 1,
        'coupon_expiry_date' => now()->addYear()->toDateString(),
        'free_shipping_coupon' => 0,
        'status' => 1,
        'store_id' => $catalogueStoreId,
    ]);
}
$catalogueCounts['coupons'] = Coupon::withoutGlobalScopes()
    ->where('coupon_code', $PROBE_COUPON_CODE)->count();

// `sale_product` is the product-id list the screen reads; product 1 belongs to store 1 (verified).
if (! FlashSale::withoutGlobalScopes()->where('name', 'like', $PROBE_TAG . '%')->exists()) {
    FlashSale::forceCreate([
        'name' => $PROBE_TAG . 'Flash Sale',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'start_time' => '00:00',
        'end_time' => '23:59',
        'sale_product' => '1',
        'discount_type' => 'percentage',
        'discount_amount' => 15,
        'is_active' => 1,
        'created_by' => $merchant->id,
        'store_id' => $catalogueStoreId,
    ]);
}
$catalogueCounts['flash_sales'] = FlashSale::withoutGlobalScopes()
    ->where('name', 'like', $PROBE_TAG . '%')->count();

// `answers` stays NULL on purpose: an UNANSWERED question is what renders the Reply control, and
// the reply modal is the surface being unblocked. An answered row would hide it.
if (! ProductQuestion::withoutGlobalScopes()->where('question', 'like', $PROBE_TAG . '%')->exists()) {
    ProductQuestion::forceCreate([
        'question' => $PROBE_TAG . 'Does this ship to Damascus?',
        'answers' => null,
        'customer_id' => 1,
        'product_id' => 1,
        'created_by' => $merchant->id,
        'store_id' => $catalogueStoreId,
    ]);
}
$catalogueCounts['product_questions'] = ProductQuestion::withoutGlobalScopes()
    ->where('question', 'like', $PROBE_TAG . '%')->count();

if (! Testimonial::withoutGlobalScopes()->where('title', 'like', $PROBE_TAG . '%')->exists()) {
    Testimonial::forceCreate([
        'title' => $PROBE_TAG . 'Testimonial',
        'description' => 'Seeded so the testimonial edit modal has a row to edit.',
        'rating_no' => 5,
        'username' => 'P25 Probe Customer',
        'product_id' => 1,
        'category_id' => 1,
        'user_id' => $merchant->id,
        'status' => 1,
        'store_id' => $catalogueStoreId,
    ]);
}
$catalogueCounts['testimonials'] = Testimonial::withoutGlobalScopes()
    ->where('title', 'like', $PROBE_TAG . '%')->count();

// `faqs.description` IS A JSON ARRAY, NOT PROSE -- and getting that wrong manufactured a
// convincing HTTP 500.
//
// MEASURED, in this order. The first version of this block wrote
// 'Seeded so the FAQ repeater modal has a row to edit.' and `/faqs/{id}/edit` answered **HTTP 500**
// at `resources/views/faq/edit.blade.php:12`, `foreach() argument must be of type array|object,
// null given`. That looked exactly like a real defect: the view guards with
// `@if(!empty($faq->description))`, which tests for EMPTY and not for VALID JSON, so
// `json_decode()` returned null and `foreach(null)` threw.
//
// It is NOT a product defect, because the app cannot reach that state:
//   - `FaqController::store()` writes only `topic` and `store_id` -- it never touches
//     `description` -- so every FAQ created through the UI has description NULL, which
//     `!empty()` does catch. Driven: NULL renders HTTP 200 via the @else branch.
//   - `FaqController::update()` writes `$request->kt_docs_repeater_basic`, and `repeater.js`
//     rewrites the field names on init to `kt_docs_repeater_basic[0][question]` /
//     `[0][answer]` (verified by reading the names off the opened modal), so a real submit
//     stores `[{"question":"...","answer":"..."}]`. Driven end to end through the modal: the
//     stored value is valid JSON and `edit()` then renders it at HTTP 200.
//
// So the only way to get prose into that column is to put it there by hand. The fixture now
// writes the shape `update()` writes, which also means the @foreach branch -- the actual repeater,
// and the reason this surface is called "FAQ edit (modal, repeater)" -- is exercised rather than
// the empty @else fallback. TWO items, so the repeater renders a list and not a single row.
if (! Faq::withoutGlobalScopes()->where('topic', 'like', $PROBE_TAG . '%')->exists()) {
    Faq::forceCreate([
        'topic' => $PROBE_TAG . 'How do refunds work?',
        'description' => json_encode([
            ['question' => 'How long do refunds take?', 'answer' => 'Three to five working days.'],
            ['question' => 'Who pays return shipping?', 'answer' => 'We do, for a faulty item.'],
        ]),
        'store_id' => $catalogueStoreId,
    ]);
}
$catalogueCounts['faqs'] = Faq::withoutGlobalScopes()
    ->where('topic', 'like', $PROBE_TAG . '%')->count();

// PROVE IT rather than report it -- and prove the row is the one the SURFACE needs, not merely that
// a row exists. An answered product question renders no Reply control, and an expired coupon
// renders a different state, so both are asserted rather than assumed.
$unanswered = ProductQuestion::withoutGlobalScopes()
    ->where('question', 'like', $PROBE_TAG . '%')->whereNull('answers')->count();
$liveCoupon = Coupon::withoutGlobalScopes()->where('coupon_code', $PROBE_COUPON_CODE)
    ->where('coupon_expiry_date', '>=', now()->toDateString())->count();

printf("          catalogue rows for store %d: %s\n", $catalogueStoreId, json_encode($catalogueCounts));
// The FAQ description must DECODE, or faq/edit.blade.php:12 throws on foreach(null). Asserted
// rather than assumed, because writing prose there produced a 500 that read as a product defect.
$faqRow = Faq::withoutGlobalScopes()->where('topic', 'like', $PROBE_TAG . '%')->first();
$faqItems = $faqRow ? json_decode($faqRow->description, true) : null;

if ($faqRow && ! is_array($faqItems)) {
    fwrite(STDERR, "FIXTURE IS WRONG: faqs.description does not decode to an array, so "
        . "/faqs/{$faqRow->id}/edit will answer HTTP 500 at faq/edit.blade.php:12.\n");
    exit(1);
}

printf("          %d question(s) UNANSWERED (so the Reply control renders), %d coupon(s) UNEXPIRED\n",
    $unanswered, $liveCoupon);
printf("          FAQ description decodes to %d repeater item(s) (so the @foreach branch renders, "
    . "not the empty @else)\n", is_array($faqItems) ? count($faqItems) : 0);
