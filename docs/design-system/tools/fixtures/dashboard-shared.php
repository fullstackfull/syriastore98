<?php
/**
 * Shared fixture for the P2-02 merchant dashboard previews.
 *
 * Runs in render-preview.php's global scope and owns every variable the
 * dashboard overview reads. The numbers are invented store data chosen to
 * exercise the states that matter -- NOT production data, and deliberately
 * nothing that could be a real customer, order or store.
 *
 * $state selects the case:
 *   full    a busy store: orders waiting, stock low, returns outstanding
 *   empty   a brand-new store: nothing to attend to, every count zero
 *   noperm  a view-only employee: the header's create button must disappear
 */

/** @var string $state */
/** @var bool $isRtl */

$busy = $state === 'full' || $state === 'noperm';

// ---- order status counts ----------------------------------------------------
// delivered_status: 0 pending, 1 delivered, 2 cancel, 3 return, 4 complete,
// 6 shipped -- the values HomeController::getOrderCounts() reads.
$pending_order        = $busy ? 12 : 0;
$confirmed_order      = $busy ? 48 : 0;
$picked_up_order      = $busy ? 6 : 0;
$shipped_order        = $busy ? 21 : 0;
$delivered_order      = $busy ? 1164 : 0;
$return_order         = $busy ? 3 : 0;
$cancel_order         = $busy ? 24 : 0;

// The two that are NOT fulfilment states, and the catch-all. A store that
// uses them is the case the old six-count breakdown got wrong, so the busy
// fixture uses them deliberately.
$partially_paid_order = $busy ? 4 : 0;
$pre_order_order      = $busy ? 2 : 0;
$other_status_order   = 0;

// 12 + 48 + 6 + 21 + 1164 + 3 + 24 + 4 + 2 = 1284. The breakdown must
// reconcile with this, and a browser check asserts it does.
$totle_order     = $busy ? 1284 : 0;
$totle_customers = $busy ? 412 : 0;
$totalproduct    = $busy ? 318 : 0;
$total_revenues  = $busy ? 1284500 : 0;

// The count behind the stock row, which HomeController produces with the same
// predicate the product list labels a row with.
$out_of_stock_threshold = 5;
$out_of_stock_products  = $busy ? 3 : 0;

// ---- helper stand-ins -------------------------------------------------------
if (!function_exists('getCurrentStore')) {
    function getCurrentStore() { return 2; }
}
if (!function_exists('currency_format_with_sym')) {
    /**
     * Mirrors what the real helper produces, which was read rather than
     * guessed: app/Helper/helper.php:1208 formats with number_format-style
     * separators -- so LATIN digits, always -- and places a configurable
     * symbol before or after according to site_currency_symbol_position.
     *
     * A first version of this fixture converted the Arabic figure to
     * Arabic-Indic digits. That was wrong, and it would have published a
     * screenshot of a product that does not exist. A Syrian store is
     * modelled here with a post-positioned Arabic symbol, which is also the
     * case that exercises the real bidi hazard: Latin digits followed by an
     * Arabic symbol inside an RTL line.
     */
    function currency_format_with_sym($price, $store_id = null, $productId = null)
    {
        global $isRtl;
        $n = number_format((float) $price, 2);
        return $isRtl ? $n . ' ل.س' : 'SYP ' . $n;
    }
}

// ---- permissions ------------------------------------------------------------
// `noperm` models the principal the tenant/authz review named: a staff member
// of this tenant whose role grants something unrelated and NOTHING about
// orders. They reach /dashboard -- the route carries no permission middleware,
// only ['auth','xss','setlocate','verified'] -- so every figure on the page is
// shown to them unless it gates itself.
//
// What must therefore be absent for them: the "Add Product" control, the
// "Orders" control, and the ORDER VALUE figure. What must still be present:
// the counts that were already visible before Phase 02.
$GRANTS = $state === 'noperm'
    ? ['Manage Blog' => true]
    : [
        'Manage Order'    => true,
        'Create Product'  => true,
        'Manage Products' => true,
        'Manage Customer' => true,
      ];

/* P2-02-8: the weekly orders series, shaped exactly like
 * HomeController::getOrderChart(['duration' => 'week']) returns it -- a
 * 'label' array of seven d-M strings and a 'data' array of counts.
 *
 * The values are NOT seven similar numbers. A flat series would render seven
 * near-identical bars and prove nothing about the scale: the spread here
 * includes the largest value, a mid value and a ZERO, because a zero day is
 * the case that divides by max, draws the baseline variant, and has to stay
 * distinguishable from "no data at all".
 *
 * 'empty' means the controller produced no series, which is a different state
 * from a week of zeroes and renders the empty component instead of bars. */
if ($state === 'empty') {
    $chartData = ['label' => [], 'data' => []];
} else {
    $chartData = [
        'label' => ['19-Oct', '20-Oct', '21-Oct', '22-Oct', '23-Oct', '24-Oct', '25-Oct'],
        'data'  => [14, 22, 9, 31, 0, 18, 26],
    ];
}

/* P2-02-9: the latest-orders and best-selling-products lists.
 *
 * Shaped like what handleRegularUser() passes -- Eloquent collections of Order
 * and Product rows -- using the harness's Row stand-in, which exposes
 * arbitrary properties.
 *
 * The STATUSES ARE CHOSEN, not arbitrary. They cover one of each tone the
 * badge can take (warning / success / info / danger) plus 7 = Partially Paid,
 * which is a PAYMENT state sharing the same column as fulfilment states
 * (ledger F0). If the preview only ever showed Pending and Delivered, the
 * tone map and the "not a fulfilment state" handling would never be seen.
 *
 * Product names mix Arabic and Latin so the RTL preview exercises a Latin name
 * inside an Arabic table, which is the case that needs bidi isolation. */
if ($state === 'empty') {
    $new_orders = [];
    $topSellingProducts = [];
} else {
    $new_orders = [
        new Row(0, ''), new Row(0, ''), new Row(0, ''), new Row(0, ''),
    ];
    foreach ([
        ['SY-10427', 0, 184000.0],   // Pending          -> warning
        ['SY-10426', 1, 92500.0],    // Order Delivered  -> success
        ['SY-10425', 6, 310000.0],   // Order Shipped    -> info
        ['SY-10424', 7, 47000.0],    // Partially Paid   -> neutral (a payment state)
    ] as $i => $row) {
        $new_orders[$i]->product_order_id = $row[0];
        $new_orders[$i]->delivered_status = $row[1];
        $new_orders[$i]->final_price      = $row[2];
    }

    $topSellingProducts = [];
    foreach ([
        ['قميص قطن دمشقي', 65000.0],
        ['Linen Table Runner', 128000.0],
        ['عباءة صوف حلبية', 240000.0],
        ['Olive Soap, 6-pack', 32000.0],
        ['وشاح حرير', 88000.0],
    ] as $i => $row) {
        $p = new Row(200 + $i, $row[0]);
        $p->price = $row[1];
        $topSellingProducts[] = $p;
    }
}
