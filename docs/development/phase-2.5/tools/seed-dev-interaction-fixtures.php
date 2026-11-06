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

/**
 * P2.5-SAFETY-W. FAIL CLOSED BEFORE THE APPLICATION BOOTS, so nothing can resolve a connection to
 * the wrong database first. This tool WRITES; run against the real database it would alter live
 * records and print success.
 *
 * The header above used to carry only a warning that the right environment must be passed. The
 * owner's directive is explicit that a written warning is not a control, so this is the control.
 * `Tests\Support\DevFixtureDatabaseGuard` checks EVERY environment source rather than the first
 * one present -- the gap that emptied this very fixture once, because an `export` survives in
 * `$_SERVER` alone and Laravel's Dotenv reads `$_SERVER` first.
 *
 * `Tests\` is mapped by composer's AUTOLOAD-DEV, so on a production install the class is simply
 * absent and this tool cannot start. That is the strongest form of the guard, and the explicit
 * check below turns it from a fatal error into a stated refusal.
 */
if (! class_exists(\Tests\Support\DevFixtureDatabaseGuard::class)) {
    fwrite(STDERR, "REFUSING TO RUN: Tests\\Support\\DevFixtureDatabaseGuard is not autoloadable, "
        . "which means this is a --no-dev install. Development fixture tools do not run here.\n");
    exit(1);
}

try {
    \Tests\Support\DevFixtureDatabaseGuard::assertCurrentEnvironmentIsADevFixture();
} catch (\RuntimeException $e) {
    // CAUGHT so the refusal reads as a refusal. An uncaught throw exits 255 with a stack trace,
    // which a caller reasonably reads as "this tool is broken" rather than "this tool declined",
    // and a tool that looks broken gets re-run with different arguments until it works -- which is
    // the behaviour the guard exists to stop. tests/bootstrap.php catches its own guard for the
    // same reason.
    fwrite(STDERR, "\n" . $e->getMessage() . "\n\n");
    exit(1);
}

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
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Wishlist;

$remove = in_array('--remove', $argv, true);

// P2.5-PKG-C TAGS.
//
// `carts.cookie_session_id` is a real varchar column that nothing filters on for an admin screen,
// so it carries the tag directly.
//
// `wishlists` HAS NO TEXT COLUMN AT ALL -- id, customer_id, product_id, variant_id, status,
// store_id, timestamps. Three candidates were considered and two rejected by reading the code:
//   variant_id -- REJECTED. `VariantRemovalPolicy` really does query it
//                 (`whereIn('variant_id', $ids)` at :305 and :678), so a sentinel there would
//                 enter a policy decision about deleting product variants.
//   product_id -- REJECTED. Carries a FOREIGN KEY to products(id); a sentinel does not insert.
//   status     -- CHOSEN. Measured: no `where('status', ...)` exists against Wishlist anywhere in
//                 app/ (controller, DataTable, views, Api\ApiController, Utility, Product,
//                 VariantRemovalPolicy all read customer_id/product_id/variant_id only), and the
//                 admin list renders four columns -- customer, email, product, action -- so the
//                 value is invisible in the UI. It is a dead column, which is what makes it safe
//                 to use and is why that is written down here rather than left to be rediscovered.
$PROBE_TAG_C = 'P25PROBE';
$PROBE_WISH_STATUS = 9;

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

    // P2.5-PKG-C carts and wishlists. `withoutGlobalScopes()` matters for BOTH: Cart is one of the
    // eleven models in `config/tenancy.php`'s `scoped_models` and Customer is another, so a plain
    // query here answers against whichever tenant this CLI process happens to resolve -- which for
    // a bare script is none, and `null_tenant => 'open'` then adds no predicate at all. Removal
    // must not depend on that, so the predicate is explicit.
    $probeCarts = Cart::withoutGlobalScopes()->where('cookie_session_id', $PROBE_TAG_C)->get();
    foreach ($probeCarts as $row) { $row->delete(); }

    $probeWishes = Wishlist::withoutGlobalScopes()->where('status', $PROBE_WISH_STATUS)->get();
    foreach ($probeWishes as $row) { $row->delete(); }

    printf("removed %d cart row(s), %d wishlist row(s)\n", $probeCarts->count(), $probeWishes->count());

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


// =================================================================================================
// P2.5-PKG-C — THE ABANDONED-CART AND ABANDONED-WISHLIST FAMILIES HAD NOTHING TO RENDER AT ALL.
//
// MEASURED before this block existed: `carts` = 0 rows and `wishlists` = 0 rows in the dev
// fixture. Six of Package C's surfaces read from those two tables -- the two list screens, their
// two `product` cells, and their two products modals -- so every one of them rendered an empty
// DataTable. An empty table is not the surface: it has no row, no `product` cell, no count badge,
// no row actions and nothing to open a modal from, so a harness that drove them would have
// measured the empty state four times and reported the family verified.
//
// WHAT THE SHAPE HAS TO BE, read off the real query paths rather than guessed:
//
//   AbandonCartDataTable / WishlistDataTable both `groupBy('customer_id')`, so the list shows ONE
//   ROW PER CUSTOMER however many items that customer has. Three customers therefore give three
//   rows, which is what exercises ordering, striping and the phone-width card layout.
//
//   `wishlist/product.blade.php` renders a COUNT BADGE from
//   `Wishlist::where('customer_id', ...)->where('store_id', ...)->count()`. If every customer had
//   the same number of items the badge would render one value and prove nothing, so the three
//   customers get 1, 3 and 5 items. The cart rows vary the same way.
//
//   `CartController::abandon_carts_show($cartId)` and `WishlistController::show()` both pivot from
//   the row to the CUSTOMER and list that customer's items, so the multi-item customers are what
//   make the two modals render a list rather than a single line.
//
// FAIL CLOSED ON A FIXTURE THAT IS NOT EMPTY. If either table already holds rows this probe did
// not write, it stops rather than adding to them: `--remove` identifies probe rows by tag, so
// mixing probe and non-probe rows would leave removal unable to tell them apart, and the next
// person's screenshots would silently inherit whatever was left behind.
// =================================================================================================

$pkgCStoreId = $store->id;

// Customer is in `scoped_models`, so withoutGlobalScopes() -- a bare CLI process resolves no
// tenant and `null_tenant => 'open'` would add no predicate, which is a different answer from
// "this store's customers" and only accidentally the same one.
$pkgCCustomers = Customer::withoutGlobalScopes()
    ->where('store_id', $pkgCStoreId)->orderBy('id')->take(3)->get();

$pkgCProducts = Product::withoutGlobalScopes()
    ->where('store_id', $pkgCStoreId)->orderBy('id')->take(5)->get();

if ($pkgCCustomers->count() < 3 || $pkgCProducts->count() < 5) {
    printf("          SKIPPED carts/wishlists: need 3 customers and 5 products in store %d, have %d and %d\n",
        $pkgCStoreId, $pkgCCustomers->count(), $pkgCProducts->count());
} else {
    // ITEM COUNTS DELIBERATELY DIFFERENT, so the count badge renders three distinct values and a
    // harness cannot pass by reading one.
    $pkgCPlan = [0 => 1, 1 => 3, 2 => 5];

    $foreignCarts = Cart::withoutGlobalScopes()
        ->where('cookie_session_id', '!=', $PROBE_TAG_C)->orWhereNull('cookie_session_id')->count();
    $foreignWishes = Wishlist::withoutGlobalScopes()
        ->where('status', '!=', $PROBE_WISH_STATUS)->count();

    if ($foreignCarts > 0 || $foreignWishes > 0) {
        printf("          SKIPPED carts/wishlists: %d cart and %d wishlist row(s) this probe did not write.\n",
            $foreignCarts, $foreignWishes);
        printf("          Refusing to mix: --remove identifies probe rows by tag and could not tell them apart.\n");
    } else {
        foreach ($pkgCCustomers as $i => $customer) {
            $wanted = $pkgCPlan[$i];

            for ($n = 0; $n < $wanted; $n++) {
                $product = $pkgCProducts[$n];

                if (! Cart::withoutGlobalScopes()->where('cookie_session_id', $PROBE_TAG_C)
                        ->where('customer_id', $customer->id)->where('product_id', $product->id)->exists()) {
                    Cart::forceCreate([
                        'customer_id' => $customer->id,
                        'product_id' => $product->id,
                        'variant_id' => 0,
                        'qty' => $n + 1,
                        // `carts.price` is an INTEGER column in this schema, so a decimal price
                        // would be truncated by sqlite's affinity and the modal would show a
                        // figure the product does not have. Rounded here deliberately, and noted
                        // because a money figure that does not match its product reads as the
                        // display defect this phase has already fixed once.
                        'price' => (int) round((float) $product->price),
                        'store_id' => $pkgCStoreId,
                        'cookie_session_id' => $PROBE_TAG_C,
                    ]);
                }

                if (! Wishlist::withoutGlobalScopes()->where('status', $PROBE_WISH_STATUS)
                        ->where('customer_id', $customer->id)->where('product_id', $product->id)->exists()) {
                    Wishlist::forceCreate([
                        'customer_id' => $customer->id,
                        'product_id' => $product->id,
                        'variant_id' => 0,
                        'status' => $PROBE_WISH_STATUS,
                        'store_id' => $pkgCStoreId,
                    ]);
                }
            }
        }

        // PROVE THE SHAPE, not merely that rows exist. The three distinct per-customer counts are
        // the whole point of the fixture; asserting only a total would pass for 9 items on one
        // customer, which renders ONE list row and no badge variation.
        $cartCounts = [];
        $wishCounts = [];
        foreach ($pkgCCustomers as $customer) {
            $cartCounts[] = Cart::withoutGlobalScopes()->where('cookie_session_id', $PROBE_TAG_C)
                ->where('customer_id', $customer->id)->count();
            $wishCounts[] = Wishlist::withoutGlobalScopes()->where('status', $PROBE_WISH_STATUS)
                ->where('customer_id', $customer->id)->count();
        }

        printf("          %d cart row(s) over %d customer(s), per-customer %s\n",
            array_sum($cartCounts), count($cartCounts), json_encode($cartCounts));
        printf("          %d wishlist row(s) over %d customer(s), per-customer %s\n",
            array_sum($wishCounts), count($wishCounts), json_encode($wishCounts));

        if (count(array_unique($cartCounts)) < 3 || count(array_unique($wishCounts)) < 3) {
            fwrite(STDERR, "          WARNING: the per-customer counts are not three distinct values, so the\n"
                . "          count badge renders fewer than three and cannot be verified properly.\n");
        }
    }
}
