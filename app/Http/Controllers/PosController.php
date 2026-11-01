<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Utility;
use App\Models\Store;
use App\Models\Product;
use App\Models\Order;
use App\Models\PurchasedProducts;
use App\Models\DeliveryAddress;
use App\Models\{Tax, TaxMethod, TaxOption};
use App\Models\OrderTaxDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PosController extends Controller
{
    /** The value a claim holds while the sale is being written. */
    private const POS_SALE_WRITING = 'writing';

    /**
     * How long one till sale's claim survives.
     *
     * Long enough to cover a double-click, a retried submit and the window in which a cashier
     * would plausibly press PAY again believing the first press failed. Short enough that a
     * genuine second sale of an identical basket -- two customers buying the same one item, at
     * the same till, minutes apart -- is not refused.
     */
    private const POS_SALE_CLAIM_SECONDS = 120;

    /**
     * F269 — THE TILL SALE WAS NOT IDEMPOTENT, AND THE CART WAS NEVER CONSUMED.
     *
     * `store()` read the basket from `session('pos_' . getCurrentStore())`, decremented stock,
     * wrote an `Order` and its `PurchasedProducts` — and the line that clears the basket was
     * COMMENTED OUT. The only code that cleared it lived in `printView()`, a different request on
     * a GET route fired by a second handler on the same click, whose response nothing checks.
     *
     * So a second PAY press re-sold the same basket: a second order, and stock decremented again.
     *
     * UNCOMMENTING THE CLEAR IS NOT THE FIX, and the owner's directive says so explicitly.
     * It closes the sequential replay and does nothing about two requests in flight at once: both
     * read the basket before either writes, both pass, and the session write-back of whichever
     * finishes last restores the basket for a third. Sessions are last-writer-wins without a lock.
     *
     * THE CONTROL IS AN ATOMIC CLAIM, taken before any stock moves and released only on a path
     * that wrote nothing. `Cache::add()` is a single atomic put-if-absent — on the production
     * `database` store it is an INSERT against a unique key, so exactly one of two concurrent
     * requests can take it. This is the same mechanism F226 uses for the admin API's order
     * intake, deliberately: one idempotency primitive in this codebase, not two.
     *
     * THE FINGERPRINT IS SERVER-DERIVED. It hashes the resolved store, the authenticated cashier
     * and a canonical form of the BASKET ITSELF — never caller input alone, which this project's
     * standing rule forbids and which would let one till evict another's claim. Two tills in one
     * store ringing up identical baskets are separate claims because the cashier differs; the
     * same till ringing the same basket twice inside the window is the double submit this exists
     * to stop.
     *
     * WHAT IT DOES NOT COVER, stated rather than implied: the cache is not durable. An eviction,
     * or a replay arriving after the TTL, is not refused by this. That is the same bound F226
     * records, and the same upgrade path — a persisted claim table — if the owner ever wants it.
     * What makes the bound tolerable here is that the basket is now genuinely consumed on
     * success, so a late replay has nothing to sell.
     *
     * ---------------------------------------------------------------------------------------------
     * THE PRESS ID IS IN THE FINGERPRINT, AND THE FIRST VERSION OF THIS CHANGE LEFT IT OUT.
     *
     * Independent review caught it, and the hole it left was WORSE than the defect being fixed.
     * Keyed on basket content alone, two separate walk-ins each buying one unit of the same SKU at
     * the same till 90 seconds apart hash identically — the POS screen posts byte-identical fields
     * (`pos/index.blade.php:836-841`). So the SECOND customer's sale was answered as a replay:
     * cash taken, no order row, no stock decrement, and a printed invoice for the FIRST sale. That
     * loses a sale silently, which is the opposite failure from the one this exists to stop.
     *
     * `new_pos_id_<store>` closes it, and it already existed: `create()` writes it each time the
     * payment modal is opened (:123-125), and `store()` reads and forgets it (:429-430). Two
     * presses of the SAME modal share it — including the concurrent pair, because neither has
     * saved its forget when the other reads — while the next customer's modal gets a new one. The
     * claim is therefore per-PRESS rather than per-basket, which is what idempotency means here.
     *
     * ITS GRANULARITY IS ONE SECOND (`create()` stores `time()`), so two modals opened inside the
     * same second still collide. A one-second window instead of a two-minute one, recorded rather
     * than hidden. If the press id is missing — a basket posted without the modal ever being
     * opened — the component is the empty string and the claim degrades to content-addressed,
     * which still refuses the concurrent duplicate.
     * ---------------------------------------------------------------------------------------------
     */
    private function posSaleFingerprint(
        array $sales,
        int $storeId,
        int $userId,
        $price,
        $discount,
        $pressId = null
    ): string {
        $lines = [];

        foreach ($sales as $line) {
            $lines[] = [
                'id' => (string) ($line['id'] ?? ''),
                'quantity' => (string) ($line['quantity'] ?? ''),
                /* `orignal_price` (sic) IS THE KEY THE CART REALLY WRITES. An earlier version of
                   this method read `$line['price']`, which `ProductController::addToCart()`
                   (:5035-5054) never creates — so that component was the empty string for every
                   real basket and the per-line price was not in the hash at all. Found by
                   independent review. */
                'price' => (string) ($line['orignal_price'] ?? ''),
                'variant_id' => (string) ($line['variant_id'] ?? ''),
            ];
        }

        /* Sorted, so the same basket assembled in a different order is the same basket. */
        usort($lines, fn ($a, $b) => [$a['id'], $a['variant_id']] <=> [$b['id'], $b['variant_id']]);

        /* A NON-SCALAR `discount` OR `price` WOULD HAVE THROWN HERE. `discount[]=1` on the request
           makes `(string) $discount` an "Array to string conversion" ErrorException. It throws
           before the claim and before any write, so it is fail-closed — but a 500 is not an
           answer, and the only caller who can send it already holds `Create Pos`. */
        $scalar = fn ($v) => is_scalar($v) ? (string) $v : '';

        return 'pos_sale:' . hash('sha256', json_encode([
            'store' => $storeId,
            'cashier' => $userId,
            'press' => $scalar($pressId),
            'price' => $scalar($price),
            'discount' => $scalar($discount),
            'lines' => $lines,
        ]));
    }

    public function index()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Pos')) {

            $settings = getAdminAllSetting();

            $tax_option = TaxOption::where('store_id', getCurrentStore())
            ->pluck('value', 'name')->toArray();
            
            $customers = Customer::where('store_id', getCurrentStore())->where('status',1)->get()->pluck('first_name', 'first_name');
            $customers->prepend(__('Walk-in-customer'), '');
            return view('pos.index',compact('customers', 'settings','tax_option'));
        }
        else{
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function create(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Pos')) {

            $sess = session()->get('pos_' . getCurrentStore());
            if (isset($sess) && !empty($sess) && count($sess) > 0) {
                $user = auth()->user();
                $settings = getAdminAllSetting();
                if (!empty($request->vc_name)) {
                    $customer_detail = Customer::where('first_name', $request->vc_name)->where('store_id', $request->store_id)->first();
                    $customer = DeliveryAddress::where('id', '=', $customer_detail->id)->where('store_id', $request->store_id)->first();
                } else {
                    $customer = [];
                }
                $new_pos_id = time();
                $session_key = 'new_pos_id_' . getCurrentStore();
                session()->put($session_key, $new_pos_id);

                $store = getStoreById(getCurrentStore());
                $details = [
                    'pos_id' => time(),
                    'customer' => $customer != null ? $customer->toArray() : [],
                    'store' => $store != null ? $store->toArray() : [],
                    'user' => $user != null ? $user->toArray() : [],
                    'date' => date('Y-m-d'),
                    'pay' => 'show',
                ];
                if (!empty($details['customer']) || isset($customer_detail)) {
                    $storedetails = '<h7 class="text-dark">' . ucfirst($details['store']['name'])  . '</p></h7>';

                    if (!empty($details['customer'])) {
                        $customerdetails = '<h6 class="text-dark">' . ucfirst($customer_detail->name) . '</h6> <p class="m-0 h6 font-weight-normal">' . $customer_detail->phone . '</p>' . '<p class="m-0 h6 font-weight-normal">' .  $details['customer']['address'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['city_name'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['country_name'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['postcode'] ?? '' . '</p>';

                        $shippdetails = '<h6 class="text-dark"><b>' . ucfirst($customer_detail->name) . '</h6> </b>' . '<p class="m-0 h6 font-weight-normal">' . $customer_detail->phone . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['address'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['city_name']  . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['country_name'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['postcode'] . '</p>';
                    } else {
                        $customerdetails = '<h2 class="h6"><b>' . ucfirst($customer_detail->name) . '</b><h2>';
                        $shippdetails = '-';
                    }
                } else {
                    $customerdetails = '<h2 class="h6"><b>' . __('Walk-in Customer') . '</b><h2>';
                    $storedetails = '<h7 class="text-dark">' . ucfirst($details['store']['name'])  . '</p></h7>';
                    $shippdetails = '-';
                }

                $store['city'] = !empty($store->city) ? ", " . $store->city . "," : '';
                $store['country'] = !empty($store->country) ? ", " . $store->country . "," : '';

                $userdetails = '<h6 class="text-dark"><b>' . ucfirst($details['user']['name']) . ' </b><p class="m-0 font-weight-normal">' . $store->address . $store['city'] . '</p>' . '<p class="m-0 font-weight-normal">' .  $store->state . $store['country']  . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $store->zipcode . '</p>';
                $details['customer']['details'] = $customerdetails;
                $details['store']['details'] = $storedetails;
                $details['customer']['shippdetails'] = $shippdetails;

                $details['user']['details'] = $userdetails;
                $mainsubtotal = 0;
                $tax_option = TaxOption::where('store_id', getCurrentStore())
                    ->pluck('value', 'name')->toArray();

                $sales        = [];
                foreach ($sess as $key => $value) {
                    $product = Product::find($value['product_id']);
                    $subtotal = $value['orignal_price'] * $value['quantity'];

                    $Tax =  Tax::where('id', $product->tax_id)->where('store_id', getCurrentStore())->first();
                    $tax_price = 0;
                    $product_tax = '';
                    if ((isset($tax_option['price_type']) && $tax_option['price_type'] != 'inclusive') && (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including')) {
                        if ($Tax && count($Tax->tax_methods()) > 0) {
                            foreach ($Tax->tax_methods() as $key1 => $value1) {
                                $amount = $subtotal * $value1->tax_rate / 100;
                                //$cart_array['tax_info'][$key1]["tax_price"] = SetNumber($amount);
                                //$product_tax .= !empty($value1) ? "<span class='badge bg-primary'>" . $value1->name . ' (' . $value1->tax_rate . '%)' . "</span><br>" : '';
                                $tax_price += $amount;

                                $cart_array['tax_info'][$key1]["tax_name"] = $value1->name;
                                $cart_array['tax_info'][$key1]["tax_type"] = $value1->tax_rate;
                                $cart_array['tax_info'][$key1]["tax_amount"] = $amount;
                                $product_tax .= !empty($value1) ? "<span class='badge bg-primary'>" . $value1->name . ' (' . $value1->tax_rate . '%)' . "</span><br>" : '';
                                $cart_array['tax_info'][$key1]["id"] = $value1->id;
                                $cart_array['tax_info'][$key1]["tax_price"] = SetNumber($amount);
                            }
                        }
                    }
                    $subtotal = $subtotal + $tax_price;

                    $sales['data'][$key]['name']       = $value['name'];
                    $sales['data'][$key]['quantity']   = $value['quantity'];
                    $sales['data'][$key]['orignal_price'] = currency_format_with_sym($value['orignal_price'], $store->id) ?? SetNumberFormat($value['orignal_price']);
                    //$sales['data'][$key]['tax']        = $tax_price;

                    $sales['data'][$key]['tax']        = $product_tax;
                    $sales['data'][$key]['tax_amount'] = currency_format_with_sym($tax_price, $store->id) ?? SetNumberFormat($tax_price);
                    $sales['data'][$key]['total_orignal_price']   = currency_format_with_sym($value['total_orignal_price'], $store->id) ?? SetNumberFormat($value['total_orignal_price']);
                    $mainsubtotal                      += $value['total_orignal_price'];
                }
                // test
                if ($request->discount <= $mainsubtotal) {
                    $discount = !empty($request->discount) ? $request->discount : 0;
                } else {
                    $discount = $mainsubtotal;
                }
                $sales['discount'] = currency_format_with_sym($discount, $store->id) ?? SetNumberFormat($discount);
                $total = $mainsubtotal - $discount;
                $sales['sub_total'] = currency_format_with_sym($mainsubtotal, $store->id) ?? SetNumberFormat($mainsubtotal);
                $sales['total'] = currency_format_with_sym($total, $store->id) ?? SetNumberFormat($total);

                $html = view('pos.create', compact('sales', 'details'))->render();
                return response()->json(
                    [
                        'status' => 'success',
                        'code' => 200,
                        'success' => __('Pos create form.'),
                        'html' => $html,
                    ]
                );
            } else {
                return response()->json(
                    [
                        'status' => 'error',
                        'code' => 400,
                        'success' => __('Add some products to cart!'),
                        'html' => null,
                    ]
                );
                // return response()->json(
                //     [
                //         'error' => __('Add some products to cart!'),
                //     ],
                //     '404'
                // );
            }
        } else {
            return response()->json(
                [
                    'status' => 'error',
                    'code' => 400,
                    'success' => __('Permission denied.'),
                    'html' => null,
                ]
            );
           // return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Pos')) {
            $discount = $request->discount;
            $user_id = auth()->user()->creatorId();

            $currency_symbol = Utility::GetValueByName('defult_currancy_symbol', getCurrentStore()) ?? '$';
            $price = floatval(str_replace(',', '.', str_replace($currency_symbol, '', $request->price)));
            if (!empty($request->vc_name)) {
                $customer = Customer::where('first_name', $request->vc_name)->where('store_id', $request->store_id)->first();
                $cust_details = DeliveryAddress::where('id', '=', $customer->id)->where('store_id', $request->store_id)->first();
            } else {
                $cust_details = [];
            }
            $store = getStoreById(getCurrentStore());
            $sales = session()->get('pos_' . getCurrentStore());
            $loopPrice = 0;
            $loopQty = 0;
            $tax_amount = 0;
            if (isset($sales) && !empty($sales) && count($sales) > 0) {
                /*
                 * F269, PART ONE: THE CLAIM, TAKEN BEFORE ANY STOCK MOVES.
                 *
                 * Everything below this point spends something -- stock, an order row, a webhook
                 * delivery -- so the claim has to be in hand before the first of them, not after
                 * the last. `Cache::add()` is a single atomic put-if-absent (an INSERT against a
                 * unique key on the production `database` store), so of two requests arriving
                 * together exactly one can take it. That is the property the session cannot give
                 * us: sessions are read-modify-write with no lock, last writer wins.
                 *
                 * THE STORE IS RESOLVED FIRST AND REFUSED IF IT IS EMPTY. `$store->id` is
                 * dereferenced unconditionally further down, so a principal with no
                 * `current_store` got a 500 off a null; and a fingerprint keyed on an empty store
                 * would put every such principal on one shared claim. A stated refusal is both
                 * safer and more honest than either.
                 */
                $storeId = (int) getCurrentStore();

                if (empty($storeId) || empty($store)) {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'error',
                            'error' => __('No store is selected.'),
                            'success' => __('No store is selected.'),
                        ],
                        404
                    );
                }

                /* READ, NOT CONSUMED. The existing read-and-forget stays where it is, further
                   down (:429-430), because `printView()` races it for the same key and that
                   behaviour is not this change's to alter. */
                $pressId = session()->get('new_pos_id_' . $storeId);

                $saleClaim = $this->posSaleFingerprint(
                    $sales,
                    $storeId,
                    (int) optional(auth()->user())->id,
                    $price,
                    $discount,
                    $pressId
                );

                if (! Cache::add($saleClaim, self::POS_SALE_WRITING, now()->addSeconds(self::POS_SALE_CLAIM_SECONDS))) {
                    $held = Cache::get($saleClaim);

                    /*
                     * THE SALE ALREADY HAPPENED. The honest answer to a replay is the answer the
                     * first request got -- the same success, carrying the SAME order id -- not a
                     * second sale and not an error. The cashier sees their receipt, the ledger
                     * holds one order. The basket is consumed on this path too, because this
                     * request has its own session copy and that copy is what gets written back.
                     */
                    if (is_array($held) && ! empty($held['order_id'])) {
                        /*
                         * THE RECEIPT COPY IS WRITTEN HERE TOO, which the first version of this
                         * branch did not do — found by independent review.
                         *
                         * Sessions are last-writer-wins, the premise this whole design rests on.
                         * The loser of a real race returns fast but still SAVES its own session
                         * copy, and if that save lands after the winner's it removed `pos_` while
                         * carrying no `pos_receipt_` — wiping the winner's. `printView()` would
                         * then print "Cart is empty!" for a sale that completed. Writing the same
                         * copy on both answers makes the two idempotent in the session as well as
                         * in the ledger.
                         */
                        session()->put('pos_receipt_' . $storeId, [
                            'at' => time(),
                            'order_id' => $held['order_id'],
                            'sales' => $sales,
                        ]);
                        session()->forget('pos_' . $storeId);

                        return response()->json(
                            [
                                'status' => 'success',
                                'code' => 200,
                                'success' => __('Payment completed successfully!'),
                                'order_id' => \Crypt::encrypt($held['order_id']),
                            ]
                        );
                    }

                    /*
                     * THE FIRST REQUEST IS STILL IN FLIGHT, so there is no order id to hand back
                     * yet and nothing truthful to call a success. HTTP 409 is deliberate: the POS
                     * screen's own `error:` callback already shows `data.error` as a toast
                     * (`pos/index.blade.php:857-861`), so the refusal is VISIBLE to the cashier
                     * without a line of new front-end code. A 200 would have been swallowed
                     * silently by the `data.code == 200` branch and read as a completed sale.
                     */
                    \Log::warning('POS sale refused as a duplicate submission', [
                        'store_id' => $storeId,
                        'user_id' => optional(auth()->user())->id,
                    ]);

                    return response()->json(
                        [
                            'code' => 409,
                            'status' => 'error',
                            'error' => __('This sale is already being processed. Please wait for the receipt.'),
                            'success' => __('This sale is already being processed. Please wait for the receipt.'),
                        ],
                        409
                    );
                }

                /*
                 * F269, PART ONE-AND-A-HALF: THE CLAIM IS RELEASED WHEN NOTHING WAS RECORDED —
                 * and the first version of this change did not release it anywhere, while the
                 * docblock above claimed it did. Found by independent review.
                 *
                 * `Cache::get() === WRITING` is the test, not a flag: once the order exists the
                 * claim carries `['order_id' => ...]`, so this cannot release a claim that is
                 * standing for a real sale. A death before that point leaves nothing in `orders`
                 * and must not leave the till refusing the same basket for two minutes.
                 *
                 * WHAT RELEASING COSTS, STATED: the stock loop runs before the order insert, so a
                 * death between them leaves stock already decremented, and the retry this release
                 * permits decrements it again. That is the PRE-CHANGE behaviour for this path, and
                 * the alternative is a till that cannot ring up the basket at all — so the
                 * release stands and the critical below is the operational signal, in the same
                 * shape `ProductStockService` uses when it drives stock negative.
                 */
                try {

                foreach ($sales as $key => $value) {
                    $product_id = $value['id'];
                    $original_quantity = ($value == null) ? 0 : (int)$value['originalquantity'];

                    /*
                     * P1-INV-03. TWO DEFECTS IN THE TWO LINES THIS REPLACES, and they were
                     * independent of one another:
                     *
                     *     $product_quantity = $original_quantity - $value['quantity'];
                     *     Product::where('id', $product_id)->update(['product_stock' => $product_quantity]);
                     *
                     * (1) NO STORE PREDICATE. `Product` is NOT in config/tenancy.php's
                     *     `scoped_models`, so `StoreScope` adds nothing and `where('id', ...)`
                     *     reaches EVERY tenant's catalogue. MEASURED: a POS session holding store
                     *     B's product moved B's `product_stock` 50 -> 49 from store A's till.
                     *
                     * (2) AN ABSOLUTE WRITE, NOT A DECREMENT. `originalquantity` is captured into
                     *     the session when the line is added to the cart and never re-read, so the
                     *     write OVERWROTE current stock with a figure derived from a snapshot.
                     *     MEASURED: stock 50 at add-time, other sales take it to 30, the till sells
                     *     1 -- the correct answer is 29 and the absolute write stored 49, INVENTING
                     *     twenty units that had already been sold. No error, no warning. Two tills
                     *     selling the same product clobber each other the same way.
                     *
                     * THE PRODUCT IS RESOLVED STORE-SCOPED and a line that does not resolve is
                     * SKIPPED rather than written: a till may only move its own store's stock, and
                     * a foreign line in a session is an anomaly to report, not an instruction.
                     *
                     * THE DECREMENT GOES THROUGH App\Services\ProductStockService, which already
                     * exists and whose single conditional UPDATE is atomic in the database --
                     * `UPDATE products SET product_stock = product_stock - ? WHERE id = ?`. No
                     * second stock engine is introduced, which is the same reason the order path
                     * uses it.
                     *
                     * `allowOversell: true` is deliberate and is what a till means. The goods are
                     * physically leaving the shop as the sale is rung up; refusing the write would
                     * not put them back, it would only make the recorded stock disagree with the
                     * shelf. The service logs a critical when that drives stock below zero on a
                     * product that forbids overselling, which is the operational signal a merchant
                     * needs.
                     */
                    if ($value != null && !empty($value)) {
                        $posProduct = Product::where('id', $product_id)
                            ->where('store_id', getCurrentStore())
                            ->first();

                        if (empty($posProduct)) {
                            \Log::warning('POS sale skipped a line whose product is not this store\'s', [
                                'product_id' => $product_id,
                                'store_id' => getCurrentStore(),
                                'user_id' => optional(auth()->user())->id,
                            ]);
                        } else {
                            app(\App\Services\ProductStockService::class)->decrementProductStock(
                                $posProduct,
                                $value['quantity'],
                                true
                            );
                        }
                    }
                    $loopPrice += ($value['orignal_price'] * $value['quantity']);
                    $loopQty += $value['quantity'];
                    
                    if (!empty($value['tax'])) {
                        $tax_amount += $value['tax'];
                    }
                }
                $is_guest = 1;
                if (auth()->check()) {
                    $product_order_id  = $request->customer_id . date('YmdHis');
                    $is_guest = 0;
                }
                $new_pos_id = session()->get('new_pos_id_' . getCurrentStore());
                session()->forget('new_pos_id_' . getCurrentStore());

                $tax_option = TaxOption::where('store_id', getCurrentStore())
                    ->pluck('value', 'name')->toArray();

                $pos                  = new Order();
                $pos->order_source = Order::SOURCE_POS;
                $pos->product_order_id = $new_pos_id;
                $pos->order_date = date('Y-m-d H:i:s');
                $pos->customer_id            = isset($customer->id) ? $customer->id : '0';
                $pos->is_guest = $is_guest;
                $pos->product_id = $product_id;
                $pos->product_json = json_encode($sales);
                $pos->final_price = ($loopPrice + $tax_amount - (float)$discount) ?? $price;


                if ((isset($tax_option['price_type']) && $tax_option['price_type'] != 'inclusive') && (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including')) {
                    $pos->product_price = $loopPrice;
                } else {
                    $pos->product_price = $price;
                }
                $pos->coupon_price = (float)$discount;
                $pos->delivery_price = '';
                $pos->tax_price = $tax_amount;
                /*
                 * F146. THE LITERAL, NOT `__('POS')`.
                 *
                 * `App\Models\Order` compares `payment_type` against the literal 'POS' in three
                 * places -- the product-line reshape, the tax/coupon block and the customer
                 * block -- and all three shape the array `Order::order_detail()` returns, which
                 * IS the order-detail API response the Flutter admin app and Lynomia read. Going
                 * through the translator made the stored discriminator depend on the UI language
                 * of the session that rang up the sale, so a one-word edit to `ar.json` would
                 * have changed an API contract and the buyer name shown for every Arabic-created
                 * till order, with no code or data change anywhere.
                 *
                 * IT WAS LIVE, NOT LATENT, AND MY FIRST NOTE HERE SAID OTHERWISE. That note
                 * read "both shipped locales map POS to POS", having checked `ar.json` and
                 * `en.json` and stopped. Independent review checked the rest: **six other
                 * shipped locales translate it** -- `es` "Punto de venta", `fr` "Le point de
                 * vente", `pl` "Pozycja POS", `ru` "ПОЗ", `zh` "没有", `ja` "POS ポイント" --
                 * every one of them offered by `Utility`'s language list, and the locale is
                 * settable through `change-languages/{lang}`, a PUBLIC GET route that writes
                 * `users.language` straight from a route parameter. So a merchant working in
                 * Spanish has been storing `payment_type = 'Punto de venta'` on every till sale,
                 * which misses all three branches and renders '-' in the list's Payment Type
                 * cell. Recorded as F146 with its own ledger row; a merchant working in Arabic or
                 * English was never affected, which is why nobody saw it.
                 *
                 * THE WRITE-SITE CHANGE IS STILL EXACTLY THIS ONE LINE, and it is corrective
                 * rather than merely defensive. Under `ar`/`en` the row written is byte-identical,
                 * so nothing a current test or an Arabic merchant sees changes. EXISTING rows are
                 * untouched -- there is no UPDATE and no backfill here, so any mis-localised rows
                 * already written stay as they are. Whether to repair those is a live-data
                 * question for the owner, not a code one, and it is recorded as such.
                 * `PosPaymentTypeLocaleTest::test_the_landmine_is_real` arms the mechanism and
                 * measures the response difference it produced.
                 */
                $pos->payment_type = 'POS';
                $pos->payment_status = 'Paid';
                $pos->store_id = $store->id;
                $pos->save();

                /*
                 * F269, PART TWO: THE CLAIM NOW CARRIES THE ANSWER, written the instant "an order
                 * exists" becomes true and BEFORE the webhook.
                 *
                 * `Utility::WebhookCall()` is an outbound HTTP request to a merchant-configured
                 * URL. It can hang, and it can throw. If it does, this request dies after the
                 * order was written -- and the retry that follows must be told the order exists,
                 * not allowed to sell the basket again. Recording the order id here rather than
                 * at the end of the handler is what makes that retry idempotent instead of
                 * duplicating.
                 *
                 * WHAT THAT LEAVES, stated rather than implied: a death between here and the
                 * `PurchasedProducts` loop below leaves an order whose purchased-product rows
                 * were not written. That partial write is pre-existing -- there is no transaction
                 * around this handler and introducing one would change when the webhook fires --
                 * and the alternative is worse: a retry that sells the basket a second time.
                 * Recorded with F269 rather than silently preferred.
                 */
                Cache::put(
                    $saleClaim,
                    ['order_id' => $pos->id],
                    now()->addSeconds(self::POS_SALE_CLAIM_SECONDS)
                );

                //webhook
                $module = 'New Order';
                $webhook =  Utility::webhook($module, $store->id);
                if ($webhook) {
                    $parameter = json_encode($sales);
                    //
                    // 1 parameter is  URL , 2 parameter is data , 3 parameter is method
                    $status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);
                    if ($status != true) {
                        $msg  = 'Webhook call failed.';
                    }
                }
                foreach ($sales as $product_id) {
                    $purchased_products = new PurchasedProducts();
                    $purchased_products->product_id = $product_id['id'];
                    $purchased_products->customer_id = isset($cust_details->id) ? $cust_details->id : '';
                    $purchased_products->order_id = $pos->id;
                    $purchased_products->store_id = $store->id;

                    $purchased_products->save();
                }


                /*
                 * F269, PART THREE: THE BASKET IS CONSUMED -- AND THE RECEIPT STILL HAS ITS DATA.
                 *
                 * The line this replaces was commented out, which is the whole sequential half of
                 * the defect: the basket survived a completed sale, the JS reloaded `#carthtml`
                 * and redrew it fully populated, and the next press of Cash Payment sold it
                 * again.
                 *
                 * BUT A BARE `forget` HERE WOULD HAVE BROKEN THE RECEIPT, which the directive
                 * names as a contract to preserve. `printView()` builds the printed invoice from
                 * this same session key, and the POS screen fires BOTH requests from one click
                 * (`pos/index.blade.php:829` posts the sale, `:866` gets the receipt), so they
                 * race. Clear the key and whichever request loses the race renders
                 * "Cart is empty!" instead of an invoice.
                 *
                 * So the basket moves rather than vanishing: into a single-use, timestamped
                 * receipt copy that `printView()` falls back to when the live key is already
                 * gone. The timestamp is what keeps it from resurfacing -- a copy older than the
                 * claim window is ignored, so it can serve the sale it belongs to and nothing
                 * else.
                 */
                session()->put('pos_receipt_' . $storeId, [
                    'at' => time(),
                    'order_id' => $pos->id,
                    'sales' => $sales,
                ]);
                session()->forget('pos_' . $storeId);

                $msg = response()->json(
                    [
                        'status' => 'success',
                        'code' => 200,
                        'success' => __('Payment completed successfully!'),
                        'order_id' => \Crypt::encrypt($pos->id),
                    ]
                );

                } catch (\Throwable $e) {
                    if (Cache::get($saleClaim) === self::POS_SALE_WRITING) {
                        Cache::forget($saleClaim);

                        \Log::critical('POS sale failed after stock may have moved; the claim was released so the till can retry', [
                            'store_id' => $storeId,
                            'user_id' => optional(auth()->user())->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    throw $e;
                }

                return $msg;
            } else {
                return response()->json(
                    [
                        'code' => 404,
                        'success' => __('Items not found!'),
                    ]
                );
            }
        } else {
            return response()->json(
                [
                    'code' => 404,
                    'success' => __('Permission denied.'),
                ]
            );
            // return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function printView(Request $request)
    {
        $sess = session()->get('pos_' . getCurrentStore());

        /*
         * F269. THE RECEIPT'S FALLBACK, and it exists because the POS screen fires two requests
         * from one click: `pos/index.blade.php:829` posts the sale, `:866` gets this invoice, and
         * nothing orders them. `store()` now consumes the basket, so whichever of the two lands
         * second finds the live key gone -- and without this the invoice would read
         * "Cart is empty!" for a sale that completed perfectly.
         *
         * TIME-BOUNDED, AND SINGLE-USE ONLY BEST-EFFORT — the first version of this comment
         * overstated it, and independent review said so. The copy is forgotten at the end of this
         * handler, but `store()` is the slower of the two racing requests, so it typically saves
         * the session AFTER this forget and the copy survives for up to one claim window. What
         * that buys is bounded and benign: a second GET of `printview/pos` within the window
         * reprints THE PRINCIPAL'S OWN invoice for THEIR OWN store, from their own session. The
         * timestamp is what keeps it from reaching any later sale, and when the copy is absent or
         * stale the handler behaves exactly as it did before: cart is empty.
         */
        if (empty($sess)) {
            $receipt = session()->get('pos_receipt_' . getCurrentStore());

            if (is_array($receipt)
                && ! empty($receipt['sales'])
                && isset($receipt['at'])
                && (time() - (int) $receipt['at']) <= self::POS_SALE_CLAIM_SECONDS
            ) {
                $sess = $receipt['sales'];
            }
        }

        $new_pos_id = session()->get('new_pos_id_' . getCurrentStore());
        session()->forget('new_pos_id_' . getCurrentStore());
        $user = auth()->user();
        $store = getStoreById(getCurrentStore());
        $settings = getAdminAllSetting();

        if (!empty($request->vc_name)) {
            $customer_detail = Customer::where('first_name', $request->vc_name)->where('store_id', $request->store_id)->first();
            $customer = DeliveryAddress::where('id', '=', $customer_detail->id)->where('store_id', $request->store_id)->first();
        } else {
            $customer_detail = '';
            $customer = [];
        }

        $details = [
            'pos_id' => $new_pos_id,
            'customer' => $customer != null ? $customer->toArray() : [],
            'store' => $store != null ? $store->toArray() : [],
            'user' => $user != null ? $user->toArray() : [],
            'date' => date('Y-m-d'),
            'pay' => 'show',
        ];
        if (!empty($details['customer']) || !empty($customer_detail)) {
            $storedetails = '<h7 class="text-dark">' . ucfirst($details['store']['name'])  . '</p></h7>';

            if (!empty($details['customer'])) {
                $customerdetails = '<h6 class="text-dark">' . ucfirst($customer_detail->name) . '</h6> <p class="m-0 h6 font-weight-normal">' . $customer_detail->phone . '</p>' . '<p class="m-0 h6 font-weight-normal">' .  $details['customer']['address'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['city_name'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['country_name'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['postcode'] ?? '' . '</p>';

                $shippdetails = '<h6 class="text-dark"><b>' . ucfirst($customer_detail->name) . '</h6> </b>' . '<p class="m-0 h6 font-weight-normal">' . $customer_detail->phone . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['address'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['city_name']  . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['country_name'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['postcode'] . '</p>';
            } else {
                $customerdetails = '<h2 class="h6"><b>' . ucfirst($customer_detail->name) . '</b><h2>';
                $shippdetails = '-';
            }
        } else {
            $customerdetails = '<h2 class="h6"><b>' . __('Walk-in Customer') . '</b><h2>';
            $storedetails = '<h7 class="text-dark">' . ucfirst($details['store']['name'])  . '</p></h7>';
            $shippdetails = '-';
        }


        $store['city'] = !empty($store->city) ? ", " . $store->city . "," : '';
        $store['country'] = !empty($store->country) ? ", " . $store->country . "," : '';

        $userdetails = '<h6 class="text-dark"><p class="m-0 font-weight-normal">' . $store->address . $store['city'] . '</p>' . '<p class="m-0 font-weight-normal">' .  $store->state . $store['country']  . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $store->zipcode . '</p>';

        $details['customer']['details'] = $customerdetails;
        $details['store']['details'] = $storedetails;

        $details['customer']['shippdetails'] = $shippdetails;

        $details['user']['details'] = $userdetails;
        $mainsubtotal = 0;
        $sales        = [];

        $tax_option = TaxOption::where('store_id', getCurrentStore())
            ->pluck('value', 'name')->toArray();
        if (!empty($sess)) {
            foreach ($sess as $key => $value) {
                $subtotal = $value['orignal_price'] * $value['quantity'];

                $Tax = Tax::where('store_id', $store->id)->first();
                $tax_price = 0;
                $product_tax = '';
                if (!empty($value['tax']) && (isset($tax_option['price_type']) && $tax_option['price_type'] != 'inclusive') && (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including')) {
                    if ($Tax && count($Tax->tax_methods()) > 0) {
                        foreach ($Tax->tax_methods() as $key1 => $value1) {

                            $amount = $subtotal * $value1->tax_rate / 100;
                            $tax_price += $amount;
                            $cart_array['tax_info'][$key1]["tax_name"] = $value1->name;
                            $cart_array['tax_info'][$key1]["tax_type"] = $value1->tax_rate;
                            $cart_array['tax_info'][$key1]["tax_amount"] = $amount;
                            $product_tax .= !empty($value1) ? "<span class='badge bg-primary'>" . $value1->name . ' (' . $value1->tax_rate . '%)' . "</span><br>" : '';
                            $cart_array['tax_info'][$key1]["id"] = $value1->id;
                            $cart_array['tax_info'][$key1]["tax_price"] = SetNumber($amount);
                        }
                    }
                }
                $subtotal = $subtotal + $tax_price;

                $sales['data'][$key]['name']       = $value['name'];
                $sales['data'][$key]['quantity']   = $value['quantity'];
                $sales['data'][$key]['orignal_price'] = currency_format_with_sym($value['orignal_price'], $store->id) ?? SetNumberFormat($value['orignal_price']);
                $sales['data'][$key]['tax']        = $product_tax;
                $sales['data'][$key]['tax_amount'] = currency_format_with_sym($tax_price, $store->id) ?? SetNumberFormat($tax_price);
                $sales['data'][$key]['total_orignal_price']   = currency_format_with_sym($value['total_orignal_price'], $store->id) ?? SetNumberFormat($value['total_orignal_price']);
                $mainsubtotal                      += $value['total_orignal_price'];
            }

            if ($request->discount <= $mainsubtotal) {
                $discount = !empty($request->discount) ? $request->discount : 0;
            } else {
                $discount = $mainsubtotal;
            }
            $sales['discount'] = currency_format_with_sym($discount, $store->id) ?? SetNumberFormat($discount);
            $total = $mainsubtotal - $discount;
            $sales['sub_total'] = currency_format_with_sym($mainsubtotal, $store->id) ?? SetNumberFormat($mainsubtotal);
            $sales['total'] = currency_format_with_sym($total, $store->id) ?? SetNumberFormat($total);
        } else {
            return response()->json(['html' => null, 'success' => false, 'message' => __('Cart is empty!')], '200'); 
           // return redirect()->back()->with('success', __('Cart is empty!'));
        }
        session()->forget('pos_' . getCurrentStore());
        session()->forget('pos_receipt_' . getCurrentStore());
        $html =  view('pos.printview', compact('details', 'sales', 'customer', 'customer_detail'))->render();
        return response()->json(['html' => $html, 'success' => true, 'message' => __('Cart is empty!')], '200'); 
    }

    public function cartDiscount(Request $request)
    {
        if ($request->discount) {
            $sess = session()->get('pos_' . getCurrentStore());
            $subtotal = !empty($sess) ? array_sum(array_column($sess, 'total_orignal_price')) : 0;
            $discount = $request->discount;
            $total = $subtotal - $discount;
            $total = currency_format_with_sym( $total, getCurrentStore()) ?? SetNumberFormat($total);
            $total_discount = currency_format_with_sym( $discount, getCurrentStore()) ?? SetNumberFormat($discount);

        }else{
            $sess = session()->get('pos_'.getCurrentStore());
            $subtotal = !empty($sess)?array_sum(array_column($sess, 'total_orignal_price')):0;
            $discount = 0;
            $total = $subtotal - $discount;
            $total = currency_format_with_sym( $total, getCurrentStore()) ?? SetNumberFormat($total);
            $total_discount = currency_format_with_sym( $discount, getCurrentStore()) ?? SetNumberFormat($discount);
        }

        return response()->json(['total' => $total, 'discount' => $total_discount], '200');
    }
}
