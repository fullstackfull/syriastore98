<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrderPaymentStatusRequest;
    use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;
use App\Models\Store;
use App\Models\Coupon;
use App\Models\User;
use App\Models\Plan;
use App\Models\Utility;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Product;
use App\Models\OrderNote;
use App\Models\OrderTaxDetail;
use App\Models\OrderCouponDetail;
use App\Models\OrderBillingDetail;
use App\Models\ProductVariant;
use App\Models\Category;
use Stripe;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use App\Http\Controllers\Api\ApiController;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\OrderExport;
use Qirolab\Theme\Theme;
use Carbon\Carbon;
use App\Services\DeliveryBoyNotificationService;
use Illuminate\Support\Facades\Log;
use App\Models\DeliveryBoy;
use App\Events\SendOrderReviewMail;
use App\DataTables\OrderDataTable;
use App\Facades\ModuleFacade as Module;
use Illuminate\Support\Facades\Cache;

class OrderController extends Controller
{
    /**
     * Atomic stock decrement, replacing the read-modify-write in store().
     *
     * Lazily resolved, and a property rather than a constructor argument so that
     * the existing `new OrderController()` call sites and the container both
     * keep working without a signature change -- the same shape
     * PaymentController already uses for this service
     * (PaymentController.php:63-64, :89-92).
     */
    private ?\App\Services\ProductStockService $stockService = null;

    private function stockService(): \App\Services\ProductStockService
    {
        return $this->stockService ??= new \App\Services\ProductStockService();
    }

    /** Derives an order's payment_status, and owns the COD state machine. */
    private ?\App\Services\PaymentVerificationService $paymentVerifier = null;

    private function paymentVerifier(): \App\Services\PaymentVerificationService
    {
        return $this->paymentVerifier ??= new \App\Services\PaymentVerificationService();
    }

/**
 * P2-04-10. THE WORST OF THE THREE: AN ORDER WRITE WITH NO PERMISSION GATE.
 *
 * This validates its input, opens a transaction and INSERTS AN ORDER carrying `final_price` and
 * `payment_status`. Nothing asked whether the principal may. DEMONSTRATED ON THE DATABASE rather
 * than inferred -- driven with a staff member holding NO ROLE AT ALL, the orders table went from
 * 0 rows to 1. Independent review reproduced it in an isolated checkout of HEAD. A status assertion could not have shown it: the handler redirects
 * on success and on validation failure alike, so only the row count distinguishes them.
 *
 * The gate goes BEFORE the validator, so an unpermitted principal cannot use the validation
 * messages to probe which customer and product ids exist in the store.
 */
public function store(Request $request)
{
    if (! $this->mayUseOrderScreen('Manage Order')) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    $validator = \Validator::make($request->all(), [
        'customer_id' => 'required|exists:customers,id',
        'products' => 'required|array|min:1',
        'products.*.product_id' => 'required|exists:products,id',
        'products.*.quantity' => 'required|integer|min:1',
        'products.*.price' => 'required|numeric|min:0',
        'payment_type' => 'required|string',
        'payment_status' => 'required|in:Paid,Unpaid',
        'delivery_price' => 'nullable|numeric|min:0',
        'tax_price' => 'nullable|numeric|min:0',
        'final_price' => 'required|numeric|min:0',
        'first_name' => 'required|string|max:255',
        'last_name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'telephone' => 'required|string|max:20',
        'address' => 'required|string',
        'city' => 'required|string|max:255',
        'state' => 'required|string|max:255',
        'country' => 'required|string|max:255',
        'postcode' => 'nullable|string|max:20',
    ]);

    if ($validator->fails()) {
        return redirect()->back()->withErrors($validator)->withInput();
    }

    DB::beginTransaction();

    try {
        $productJson = [];
        $totalProductPrice = 0;
        $storeId = getCurrentStore();

        $currentCurrencyCode = \App\Models\Utility::GetValueByName('defult_currancy', $storeId) ?? 'USD';
        $currentCurrencySymbol = \App\Models\Utility::GetValueByName('defult_currancy_symbol', $storeId) ?? '$';

        /*
         * TENANT OWNERSHIP ON THE PRODUCT AND THE VARIANT.
         *
         * THE DEFECT. This loop was `Product::find($item['product_id'])` and
         * `ProductVariant::find($item['variant_id'])` with no store predicate at
         * all, while `product_id` and `variant_id` come straight off the posted
         * form (validated only by `exists:products,id`, which is global). So an
         * authenticated merchant of store A could put store B's product id in
         * the request and this writer would: read B's row, DECREMENT B's stock,
         * copy B's name and price into A's order, and write the order under A's
         * store_id. That is a cross-tenant inventory write and a cross-tenant
         * catalogue read from one ordinary admin form post.
         *
         * WHY NOTHING ELSE COVERS IT. The previous phase deferred this to the
         * tenant scope, and the tenant scope does not cover it. Two independent
         * reasons, both read off the files rather than assumed:
         *
         *   1. config/tenancy.php:104-150 `scoped_models` lists exactly SIX
         *      classes -- Menu, SupportTicket, SupportConversion,
         *      FlashSaleCondition, ThemeCustomizeOrder, NotifyUser. Product and
         *      ProductVariant are not among them. config/tenancy.php:46 names
         *      Product explicitly as DEFERRED.
         *   2. app/Scopes/StoreScope.php:155 `public static bool $enabled =
         *      false` -- the all-models flip -- is off, and
         *      StoreScope::isActive() (:324-340) reads
         *      `config('tenancy.store_scope_enabled')`, a key config/tenancy.php
         *      does not define, so the static false governs.
         *
         * So the predicate below is the only thing standing between this form
         * and another store's inventory. If Product ever does become scoped the
         * predicate is simply redundant, not harmful. It is proven by
         * tests/Feature/Commerce/OrderStoreOwnershipTest.php, which asserts the
         * cross-tenant line is refused AND that the foreign row's stock is
         * untouched.
         *
         * $storeId is the one from getCurrentStore() at the top of this method,
         * i.e. the same value the order row is written with -- so a product is
         * accepted exactly when it belongs to the store the order is created in.
         */
        foreach ($request->products as $item) {
            $product = \App\Models\Product::where('id', $item['product_id'])
                ->where('store_id', $storeId)
                ->first();

            if (!$product) {
                throw new \Exception("المنتج غير موجود: ID {$item['product_id']}");
            }

            $qty = (int) $item['quantity'];
            $lineTotal = $item['price'] * $qty;
            $totalProductPrice += $lineTotal;

            if (!empty($item['variant_id']) && $item['variant_id'] > 0) {
                /*
                 * The variant must belong to BOTH this store and this product.
                 *
                 * `product_id` is the second predicate and it is not decoration:
                 * without it a posted `variant_id` from a DIFFERENT product in
                 * the same store was accepted, and the decrement below then took
                 * stock off that other product's variant while the order line
                 * recorded this product's name and price. A wrong-row inventory
                 * write inside one store, reachable from the same form.
                 */
                $variant = \App\Models\ProductVariant::where('id', $item['variant_id'])
                    ->where('product_id', $product->id)
                    ->where('store_id', $storeId)
                    ->first();

                if (!$variant) {
                    throw new \Exception("الخيار/المتغير غير موجود: ID {$item['variant_id']}");
                }

                /*
                 * ATOMIC. This was
                 *
                 *     if ($variant->stock < $qty) { throw ... }
                 *     $variant->stock -= $qty;
                 *     $variant->save();
                 *
                 * -- a check-then-act read-modify-write, i.e. the lost update
                 * App\Services\ProductStockService exists to remove: two
                 * concurrent writers both read the same stock, both pass the
                 * `<` test and both write `stock - qty`, so the second
                 * overwrites the first and the shop oversells by exactly the
                 * quantity it processed concurrently.
                 *
                 * `requireAvailability: true` keeps THIS writer's stricter rule:
                 * the original refused on `stock < qty` without consulting
                 * `stock_order_status`, so the service is asked to apply the
                 * `>=` predicate unconditionally. Behaviour is preserved and
                 * only the race is removed.
                 *
                 * The decrement runs inside the DB::beginTransaction() opened
                 * above, so DB::rollBack() in the catch gives the stock back;
                 * no separate compensation is needed here, unlike the storefront
                 * writers whose stock loop sits outside their boundary.
                 */
                $variantDecrement = $this->stockService()->decrementVariantStock(
                    $variant,
                    $qty,
                    false,
                    true
                );

                if (!$variantDecrement['applied']) {
                    throw new \Exception("المخزون غير كافٍ للمتغير: {$variant->name}. المتاح: {$variant->stock}، المطلوب: {$qty}");
                }

                $productJson[] = [
                    'product_id' => $item['product_id'],
                    'name' => $product->name ?? 'Product',
                    'price' => $item['price'],
                    'quantity' => $qty,
                    'final_price' => $lineTotal,
                    'orignal_price' => $product->price ?? $item['price'],
                    'variant_id' => $item['variant_id'],
                    'variant_name' => $item['variant_name'] ?? $variant->name ?? null,
                    'image' => $product->cover_image_url ?? '',
                    'return' => false,
                ];
            } else {
                /*
                 * ATOMIC, for the same reason as the variant branch above. This
                 * was `$product->product_stock -= $qty; $product->save();`
                 * behind a check-then-act `<` test. `requireAvailability: true`
                 * preserves this writer's unconditional refusal.
                 */
                $productDecrement = $this->stockService()->decrementProductStock(
                    $product,
                    $qty,
                    false,
                    true
                );

                if (!$productDecrement['applied']) {
                    throw new \Exception("المخزون غير كافٍ للمنتج: {$product->name}. المتاح: {$product->product_stock}، المطلوب: {$qty}");
                }

                $productJson[] = [
                    'product_id' => $item['product_id'],
                    'name' => $product->name ?? 'Product',
                    'price' => $item['price'],
                    'quantity' => $qty,
                    'final_price' => $lineTotal,
                    'orignal_price' => $product->price ?? $item['price'],
                    'variant_id' => 0,
                    'variant_name' => null,
                    'image' => $product->cover_image_url ?? '',
                    'return' => false,
                ];
            }
        }

        $orderNumber = 'ORD-' . strtoupper(uniqid()) . '-' . rand(1000, 9999);

        /*
         * SERVER-DERIVED TOTAL.
         *
         * THE DEFECT. `final_price` was persisted straight from the request --
         * `'final_price' => $request->final_price` -- and was never compared
         * against the lines it is supposed to be the total of. The loop above
         * already builds $totalProductPrice from the Product rows, so the
         * authoritative figure was being computed and then thrown away in favour
         * of whatever the browser posted. An order whose total disagreed with its
         * own line items was accepted silently, and `coupon_price` is hardcoded
         * to 0 here so there was not even a discount field to explain a
         * difference.
         *
         * THE FIX. The total is recomputed from its components and the computed
         * value is what is stored, so Laravel is the source of truth. The posted
         * figure is still reconciled against the computed one, so a divergence is
         * MEASURED rather than silently discarded -- that log is how a mismatched
         * admin form gets found.
         *
         * This wires App\Services\OrderTotalReconciler, which already existed
         * and whose own docblock (OrderTotalReconciler.php:202-212) names this
         * exact method as "what the merchant-admin order writer needs and does
         * not have". It had no caller here at all. No second total engine is
         * introduced.
         *
         * WHAT IS STILL REQUEST-SUPPLIED, and reported rather than changed:
         * each line's unit `price` ($item['price']), plus `delivery_price` and
         * `tax_price`. On a MANUAL admin order those are legitimately
         * merchant-entered -- manual pricing, a negotiated shipping charge --
         * so overriding them from the catalogue would break the feature. They
         * are validated as numeric and non-negative by the validator above. The
         * TOTAL, however, is arithmetic, and arithmetic is not an opinion.
         */
        $reconciler = app(\App\Services\OrderTotalReconciler::class);

        $totalComponents = [
            'product_price' => $totalProductPrice,
            'delivery_price' => $request->delivery_price ?? 0,
            'tax_price' => $request->tax_price ?? 0,
            'coupon_price' => 0,
        ];

        $reconciliation = $reconciler->reconcile($totalComponents, $request->final_price);

        if (!$reconciliation['matches']) {
            \Log::warning('Manual order total did not match its components; the computed total was stored', [
                'site' => __METHOD__,
                'reason' => $reconciliation['reason'],
                'computed' => $reconciliation['expected'],
                'posted' => $reconciliation['claimed'],
                'difference' => $reconciliation['difference'],
                'store_id' => $storeId,
            ]);
        }

        // Fall back to the posted value ONLY if a component was not
        // representable at all, in which case there is no computed total to
        // prefer and behaviour is left exactly as it was.
        $serverFinalPrice = $reconciliation['expected'] ?? $request->final_price;

        $order = \App\Models\Order::create([
            'product_order_id' => $orderNumber,
            'order_date' => now(),
            'customer_id' => $request->customer_id,
            'is_guest' => 0,
            'product_json' => json_encode($productJson),
            'product_id' => implode(',', array_column($request->products, 'product_id')),
            'product_price' => $totalProductPrice,
            'delivery_price' => $request->delivery_price ?? 0,
            'tax_price' => $request->tax_price ?? 0,
            'coupon_price' => 0,
            // The computed total, not the posted one. See the block above.
            'final_price' => $serverFinalPrice,
            'payment_type' => $request->payment_type,
            /*
             * REPORTED, NOT CHANGED, and the reason matters. On every OTHER
             * writer a literal 'Paid' was a false claim because nothing had
             * asked a gateway whether money moved -- that is why
             * PaymentVerificationService exists. Here it is different: this is
             * a MANUAL order an authenticated merchant is entering, so
             * payment_status is the merchant ASSERTING an out-of-band fact
             * ("I have the cash"), not the platform inferring a gateway result.
             * It is already constrained to the Paid/Unpaid vocabulary by the
             * validator above, so it is left as the merchant's statement.
             */
            'payment_status' => $request->payment_status,
            'delivered_status' => 0,
            'return_status' => 0,
            'additional_note' => $request->additional_note ?? '',
            'store_id' => $storeId,
            'created_by' => auth()->id(),
            
            'currency_code' => $currentCurrencyCode,
            'currency_symbol' => $currentCurrencySymbol,
        ]);

        \App\Models\OrderBillingDetail::create([
            'order_id' => $order->id,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'telephone' => $request->telephone,
            'address' => $request->address,
            'city' => $request->city,
            'state' => $request->state,
            'country' => $request->country,
            'postcode' => $request->postcode ?? '',
            'store_id' => $storeId,
        ]);

        /*
         * THE NOTE BODY WAS BEING SILENTLY DISCARDED.
         *
         * This create() passed `changeble_status` and `note`. Neither is a
         * column: `order_notes` has `notes` and `note_type`
         * (database/migrations/2024_01_26_100237_create_order_notes_table.php),
         * and no migration anywhere adds `changeble_status` or `note` --
         * `grep -rn changeble_status database/` returns nothing. Neither key is
         * in OrderNote::$fillable (app/Models/OrderNote.php:13-21) either, so
         * mass assignment dropped both WITHOUT error and the row was written
         * with a status and no text at all. Every manually created order got a
         * blank note.
         *
         * `changeble_status` is not invented by the caller -- it is a legitimate
         * INPUT key of OrderNote::order_note_data(), which reads it and composes
         * `notes` from it (app/Models/OrderNote.php:44). That helper is the
         * correct entry point and OrderController.php:723 uses it properly. This
         * site bypassed it and spoke to create() directly, where those keys mean
         * nothing.
         *
         * Fixed by writing the columns that exist. The note text is preserved
         * verbatim, so nothing a merchant reads changes except that it now
         * actually appears. No model and no migration is touched.
         */
        \App\Models\OrderNote::create([
            'customer_id' => $request->customer_id,
            'order_id' => $order->id,
            'status' => 'Order created',
            'note_type' => 'created',
            'notes' => 'Order created manually by admin',
            'store_id' => $storeId,
        ]);

        DB::commit();

        return redirect()->route('order.index')
            ->with('success', __('Order created successfully. Order ID: ') . $orderNumber);

    } catch (\Exception $e) {
        DB::rollBack();

        \Log::error('Order Creation Error: ' . $e->getMessage());

        return redirect()->back()
            ->with('error', __('Failed to create order: ') . $e->getMessage())
            ->withInput();
    }
}
/////////

    /**
     * P2-04-10. NO PERMISSION GATE AT ALL, on a form that discloses the store's customer list.
     *
     * THE GATE WAS ABSENT, NOT MIS-NAMED, and independent review sharpened this: a staff user with
     * NO ROLE AND NO PERMISSION AT ALL also got **200** (`roles held: []`). Any authenticated
     * member of the tenant reached it; the permission a test fixture happens to hold is beside the
     * point.
     * The view it renders (`order/createOrder.blade.php`) lists every active Customer in the
     * store as `first_name last_name (email)` -- so names AND email addresses
     * (createOrder.blade.php:40) -- plus the whole catalogue with its PRICES (:99-101). Store-scoped, so not a cross-tenant leak; a disclosure to an employee the
     * merchant never gave order access to, which is what this row is about.
     *
     * `Manage Order`, not the read-only `Show Order`: this is the manual order-entry form, and
     * its POST counterpart creates an order.
     */
    public function create(Request $request)
{
    if (! $this->mayUseOrderScreen('Manage Order')) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    $preselectedCustomerId = $request->query('customer_id');
    $preselectedCustomer = null;

    if ($preselectedCustomerId) {
        $preselectedCustomer = \App\Models\Customer::where('id', $preselectedCustomerId)
            ->where('store_id', getCurrentStore())
            ->first();
    }

    $customers = \App\Models\Customer::where('store_id', getCurrentStore())
        ->where('status', 1)
        ->orderBy('first_name')
        ->get();

        $products = \App\Models\Product::where('store_id', getCurrentStore())
            ->where('status', 1)
            ->get();


    $paymentSettingController = new \App\Http\Controllers\PaymentSettingController();
    $gatewaysConfig = $paymentSettingController->getPaymentGateways();
    $activePaymentMethods = [];

    $customLabels = [
        'cod' => 'Cash on Delivery',
        'bank_transfer' => 'Bank Transfer',
        'dpopay' => 'DPO Pay',
        'paytr' => 'Pay TR',
        'Xendit' => 'Xendit',
        'Senangpay' => 'SenangPay',
        'myfatoorah' => 'MyFatoorah',
    ];

    foreach ($gatewaysConfig as $gateway) {
        $id = $gateway['id'];
        $enableKey = "is_{$id}_enabled"; // مثال: is_stripe_enabled

        $status = \App\Models\Utility::GetValueByName($enableKey);

        if ($status === 'on' || $status === '1') {
            $label = $customLabels[$id] ?? ucwords(str_replace('_', ' ', $id));
            $activePaymentMethods[$id] = __($label);
        }
    }

    if (empty($activePaymentMethods)) {
        $activePaymentMethods['cod'] = __('Cash on Delivery (Default)');
    }

    return view('order.createOrder', compact(
        'customers',
        'preselectedCustomer',
        'products',
        'activePaymentMethods'
    ));
}


    public function index(Request $request, OrderDataTable $dataTable)
    {
        if ($this->mayUseOrderScreen('Manage Order')) {
            /*
             * P2-06-5 — see the twin in `ProductController::index()` for why this is a 302 and
             * not a client-side form fill, and `App\Services\TableFilterMemory` for why the
             * store is the server session.
             *
             * INSIDE THE PERMISSION GATE: a caller who may not see orders never reads or writes
             * the memory.
             */
            if ($request->has(\App\Services\TableFilterMemory::CLEAR)) {
                \App\Services\TableFilterMemory::forget('order');

                return redirect()->route('order.index');
            }

            if (\App\Services\TableFilterMemory::shouldRestore($request)) {
                $remembered = \App\Services\TableFilterMemory::recall('order');

                if ($remembered !== []) {
                    return redirect()->route('order.index', $remembered)
                        ->with('ss_filters_restored', true);
                }
            }

            \App\Services\TableFilterMemory::remember($request, 'order');

            return $dataTable->render('order.index', $this->orderAttentionCounts());
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    /**
     * P2-04-13 / WS E9 — the order screen's "needs attention" counts.
     *
     * EVERY COUNT HERE IS LINKED TO A LIST THAT REALLY CONTAINS IT, which is the property that
     * makes the block worth reading and the one that is easy to get wrong. Each predicate below
     * is the SAME predicate the filter its link carries will apply:
     *
     *   pending  -> `delivered_status = 0`      -> ?ss_status=0
     *   returns  -> `return_status = 1`         -> ?ss_return=1
     *   unpaid   -> `payment_status = 'Unpaid'` -> ?ss_payment=Unpaid
     *
     * TWO MISMATCHES WERE FOUND BY MEASURING AND ARE THE REASON THAT PROPERTY IS SPELLED OUT.
     *
     *  1. The dashboard's "returns" count is `delivered_status = ORDER_STATUS_RETURN`
     *     (`HomeController::getOrderCounts()`'s `return` bucket) -- the FULFILMENT status. That is
     *     NOT `orders.return_status`, which holds the return-REQUEST workflow
     *     (`0 none, 1 request, 2 approve, 3 cancel`). The two columns count different things, and
     *     a link from one to a filter on the other lands the merchant on a list that does not
     *     contain the orders just counted. `returns` below is the REQUEST queue -- "raised and
     *     nobody has decided" -- which nothing could filter for before this slice.
     *
     *  2. The dashboard's stock count and the product list's `ss_stock=out` filter DISAGREE, so
     *     that link is deliberately left unfiltered rather than given false precision. The count
     *     includes untracked products the merchant marked out of stock
     *     (`track_stock = 0 AND stock_status = 'out_of_stock'`, HomeController.php:263-264); the
     *     filter requires `track_stock = 1` on all three of its states, because a product the
     *     merchant told the system not to count has no stock state at all -- a property
     *     `ProductListFilterTest` asserts as a partition. Making either match the other changes a
     *     figure a merchant reads or breaks that partition, so it is recorded rather than papered
     *     over.
     *
     * STORE-SCOPED FROM THE PRINCIPAL, never from request input: `getCurrentStore()` reads the
     * authenticated user's own `current_store`. `Order` is not in `config/tenancy.php`'s
     * `scoped_models`, so this predicate is the only thing keeping another tenant's orders out of
     * these numbers.
     *
     * @return array<string, int>
     */
    private function orderAttentionCounts(): array
    {
        $store = getCurrentStore();

        if (empty($store)) {
            return ['ssAttentionPending' => 0, 'ssAttentionReturns' => 0, 'ssAttentionUnpaid' => 0];
        }

        $scoped = fn () => Order::where('store_id', $store);

        return [
            'ssAttentionPending' => (int) $scoped()
                ->where('delivered_status', \App\Http\Controllers\HomeController::ORDER_STATUS_PENDING)
                ->count(),
            'ssAttentionReturns' => (int) $scoped()->where('return_status', 1)->count(),
            'ssAttentionUnpaid' => (int) $scoped()->where('payment_status', 'Unpaid')->count(),
        ];
    }

    public function show(Order $order, $id)
    {

        $id = Crypt::decrypt($id);
        $order = Order::order_detail($id);
        $orders = Order::find($id);
        return view('order.order_show', compact('order', 'orders'));
    }

    /**
     * P1-ORD-05 (B): the platform operator first. Laratrust has no super-admin bypass and the operator holds no merchant role, so a bare
     * `isAbleTo()` answered "Permission denied" to the one principal who legitimately reads every store's orders (OrderPolicy::allow
     * orders its checks operator-first for the same reason). Row ownership is not decided here: OrderDocumentAccessGuard does that for
     * these URIs.
     */
    private function mayUseOrderScreen(string $permission): bool
    {
        $user = auth()->user();

        /*
         * THROUGH THE ONE HELPER, not a fourth spelling of the same decision. P1-SEC-11
         * criterion 3 asks for the operator to be defined ONCE, and this line was normalising
         * with `trim()` where `UserPolicy::isOperator()` uses `rtrim(' ')` -- deliberately, to
         * agree with MySQL's PAD SPACE, which ignores a TRAILING space and not a leading one.
         *
         * MEASURED: for `users.type` = ' super admin' (leading space) `isOperator()` answers
         * false and this line answered TRUE, so the order SCREEN admitted a principal the
         * document guard refuses -- the two gates disagreeing about who the operator is, which
         * is the defect this criterion exists to end rather than a new one. Not an escalation
         * (OrderDocumentAccessGuard still decides rows) and latent besides: no writer of
         * users.type produces that value. Narrowing to the engine's own rule is the correct
         * direction, since such a row is not the operator to MySQL either.
         */
        return $user !== null
            && (\App\Policies\UserPolicy::isOperator($user) || $user->isAbleTo($permission));
    }

    public function order_view(Request $request, $id)
    {
        if ($this->mayUseOrderScreen('Show Order')) {
            try {
                $id = Crypt::decrypt($id);
                $order = Order::order_detail($id);
                $store_id = $store = getStoreById(getCurrentStore());
                $order_notes = OrderNote::where('order_id', $id)->where('store_id', getCurrentStore())->get();
                // P2-04-6: `__()`. This was a bare English string, so the Arabic order detail
                // page offered "Assign to delivery boy" in English -- visible in the screenshots.
                $deliveryboys = DeliveryBoy::where('store_id', $store_id->id)->pluck('name', 'id')
                    ->prepend(__('Assign to delivery boy'), "");
                if (!empty($order['message'])) {
                    return redirect()->back()->with('error', __('Order Not Found.'));
                }
                return view('order.view', compact('order', 'store', 'order_notes', 'deliveryboys'));
            } catch (DecryptException $e) {
                return redirect()->back()->with('error', __('Something was wrong.'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', __('Something was wrong.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * P2-04-5. THE AUTHORIZED QUICK PREVIEW -- a read-only fragment for the side panel.
     *
     * WHY A NEW URI NEEDS A NEW GUARD ENTRY, EXPLICITLY. `OrderDocumentAccessGuard` keys on
     * `$route->uri()`, not on the controller action, which is deliberate (its docblock: "a second
     * uri on a guarded handler is NOT guarded by implication"). So this route is listed in that
     * guard's TARGETS map in the same change, and `order_preview` is added to the document-method
     * list `OrderDocumentRouteResolutionTest`'s census scans -- otherwise the census would be
     * keyed on a spelling that does not include this one, which is the exact way four earlier
     * censuses in this project missed what they claimed to cover.
     *
     * THE ID IS RAW, NOT ENCRYPTED, AND THAT IS THE HONEST CHOICE. `APP_KEY` is in git history
     * and is not being rotated (owner decision D7), so `Crypt::encrypt($id)` in a URL is an
     * ENCODING, not a control -- the ciphertext is forgeable for any id. Shipping a new surface
     * with an encrypted id would imply a protection that does not exist. Authorization is the
     * guard plus the two checks below, and the guard already accepts either form.
     *
     * DEFENCE IN DEPTH, not duplication. The middleware is the control; these checks mean the
     * handler is still safe if the route is ever re-declared outside the `web` group (which is
     * how `Authenticate` silently vanished from an order document route once already -- see
     * OrderDocumentAccessGuard's docblock on AutomaticOrderPrinting:31).
     */
    public function order_preview(Request $request, $id)
    {
        if (! $this->mayUseOrderScreen('Show Order')) {
            return response()->json(['message' => __('Permission denied.')], 403);
        }

        $orderId = filter_var($id, FILTER_VALIDATE_INT);

        // `filter_var`, not `is_numeric`: is_numeric accepts '5.9' and rejects '5abc', while
        // MySQL coerces '5abc' to row 5 in a `WHERE id = ?` comparison.
        if ($orderId === false || $orderId <= 0) {
            return response()->json(['message' => __('Order Not Found.')], 404);
        }

        $user = auth()->user();
        $order = Order::find($orderId);

        if ($order === null) {
            return response()->json(['message' => __('Order Not Found.')], 404);
        }

        /*
         * THE OWNERSHIP TEST, DERIVED FROM THE PRINCIPAL AND NEVER FROM REQUEST INPUT.
         * `Order` is not in config/tenancy.php's `scoped_models` and registers no `StoreScope`,
         * so `Order::find()` above reaches every tenant's orders -- the global scope contributes
         * nothing here for any principal. `getCurrentStore($user->id)` takes the id explicitly
         * because the no-argument form reads the MUTABLE default guard.
         *
         * Operator first, which is this codebase's rule: Laratrust has no super-admin bypass.
         */
        if (! \App\Policies\UserPolicy::isOperator($user)) {
            $currentStore = getCurrentStore($user->id);

            // Fail closed on either side being absent. An order with a NULL store_id is
            // invisible to every store predicate in the application and must not become visible
            // here.
            if (empty($currentStore) || $order->store_id === null
                || (int) $order->store_id !== (int) $currentStore) {
                return response()->json(['message' => __('Order Not Found.')], 404);
            }
        }

        $detail = Order::order_detail($orderId);

        if (! empty($detail['message'])) {
            return response()->json(['message' => __('Order Not Found.')], 404);
        }

        return response()->view('order.partials.preview', [
            'order' => $detail,
            'orderModel' => $order,
        ]);
    }

    public function applycoupon(Request $request, $slug)
    {
        $store = getStore($slug);
        // P01-SEC-14 C4: portable exact-code match. See Coupon::findByExactCode.
        $coupon = Coupon::findByExactCode(
            $request->coupon_code,
            fn ($query) => $query->where('store_id', $store->id)
        );
        $param = [
            'slug' => $slug,
            'store_id' => $store->id,
            'coupon' => $coupon,
        ];

        $request->merge($param);
        $api = new ApiController();

        $apply_coupon = $api->apply_coupon($request, $slug);
        $coupon = $apply_coupon->getData();
        return response()->json($coupon);
    }

    public function paymentlist(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            $return['html_data'] = null;
            $return['message'] = __('Store not found.');
            return response()->json($return);
        }
        
        $param = [
            'customer_id' => !empty(\Auth::guard('customers')->user()) ? \Auth::guard('customers')->user()->id : 0,
            'slug' => $slug,
            'store_id' => $store->id,
        ];
        $request->merge($param);
        $api = new ApiController();

        $payment_list_data = $api->payment_list($request, $slug);
        $payment_list = $payment_list_data->getData();
        $return['html_data'] = view('front_end.sections.payment_list', compact('slug', 'payment_list'))->render();
        return response()->json($return);
    }

    /**
     * The order ids this SESSION placed through the WhatsApp/Telegram checkout, as a clean list of
     * positive integers. The session value is attacker-influenced only through their own session,
     * but it is still parsed defensively: a non-array, a nested array, `true`, `'*'` and `null`
     * entries are all dropped rather than trusted.
     *
     * @return list<int>
     */
    /**
     * P1-ORD-13: one definition of "this session placed that order", in
     * App\Services\OrderCompleteBinding. This method was that definition's only copy while the
     * mechanism served one handler; ten gateway returns use it now.
     */
    private function orderCompleteBoundIds(): array
    {
        return \App\Services\OrderCompleteBinding::bound();
    }

    /**
     * The ONE order in this store that carries this number -- null when there is none, and null when
     * there is MORE THAN ONE.
     *
     * `product_order_id` is not unique: a guest order is `'0' . date('YmdHis')`, a dozen writers do
     * the same, and the column has no index. Two orders placed in one store in one second therefore
     * share it, and `first()` returned the lowest id -- so a visitor who placed their own order a
     * second after a victim's was shown the VICTIM'S order and a minted link to it (review lens B,
     * F1). An ambiguous number is refused rather than guessed between two people's orders. The real
     * fix is to carry the primary key in the flash (P1-ORD-13).
     */
    private function soleStoreOrderByNumber($store, $number): ?Order
    {
        $matches = Order::where('product_order_id', $number)
            ->where('store_id', $store->id)
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function orderComplete(Request $request)
    {
        $store = getStore($request->route('storeSlug'));
        if (!$store) {
            abort(404);
        }
        
        // P1-ORD-08. WHO MAY SEE WHICH ORDER ON THE ANONYMOUS THANK-YOU PAGE.
        //
        // This route is anonymous by design -- it is where a guest lands after paying -- so the
        // proof of ownership has to be something a guest can hold. There are exactly three, and the
        // fourth way this handler used to find an order was an unauthenticated enumerator:
        //
        //   1. `data`            flashed by every gateway return handler (->with('data', $poid)).
        //   2. `khalti_order_id` set by the Khalti flow.
        //      Both are set SERVER-SIDE for the buyer's own session. Both now carry the store
        //      predicate: `product_order_id` is a bare timestamp, so two stores can hold the same
        //      value in the same second and the lookup returned whichever row came first.
        //   3. `data[order_id]`  the WhatsApp/Telegram redirect, whose blade forwards the success
        //      payload's PRIMARY KEY as a query string. That is request input, and a sequential
        //      integer typed into a URL disclosed any order to a visitor who never placed it --
        //      MEASURED, P1-SEC-03 round 2, including its number and a minted link to the full
        //      order. It is honoured ONLY for an id this session placed: PaymentController::
        //      whatsapp() records it under `order_complete_ids`. A session id is random and held on
        //      the server, so unlike a Crypt::encrypt() token it is not forgeable with the leaked
        //      APP_KEY.
        //   4. `responce[data][order_id]` -- REMOVED. It has no producer anywhere in resources/,
        //      themes/ or public/ (the grep hits are server-side `$responce` variables), so it was
        //      dead for legitimate use and live only for an attacker.
        //
        // A store predicate on its own would not have been a fix: the slug is public, so an
        // attacker simply addresses the victim's own store, where the order's store matches.
        //
        // PRECEDENCE IS THE ORIGINAL'S: the flashed `data` first, then a request id, then Khalti. The
        // request id outranked Khalti before, and it must still -- a buyer with a stale
        // `khalti_order_id` who then checks out through WhatsApp has to be shown the order they just
        // placed, not the one from last week.
        $order = null;
        $flashed = session('data');
        $requested = $request->input('data.order_id');
        $khalti = Session::get('khalti_order_id');

        /*
         * P1-ORD-13. THE SESSION'S OWN ORDER IDS COME FIRST, AHEAD OF THE FLASHED NUMBER.
         *
         * `product_order_id` is not an identifier: it is `'0' . date('YmdHis')` for a guest, with no
         * index and no uniqueness, so two orders placed in one store in one second share it.
         * Resolving the flashed NUMBER therefore had two possible answers and both were wrong --
         * `first()` showed one buyer the other's order (measured, P8), and P1-ORD-08's refusal sent
         * BOTH buyers back to the landing page having just paid.
         *
         * Every gateway return now records the order's PRIMARY KEY against the session that placed
         * it (OrderCompleteBinding::remember, ten sites), so the collision resolves correctly for
         * each buyer instead of being guessed or refused. The ids are matched against the flashed
         * number's own store, so a bound id from another store cannot be shown here.
         *
         * The number lookup is KEPT as the fallback, for a session whose binding is missing -- an
         * older session, a cookie-less client, or a handler not yet reached. It still refuses an
         * ambiguous number rather than guessing.
         */
        $boundHere = Order::whereIn('id', $this->orderCompleteBoundIds() ?: [0])
            ->where('store_id', $store->id)
            ->orderByDesc('id')
            ->get();

        if ($flashed && is_scalar($flashed)) {
            $order = $boundHere->firstWhere('product_order_id', (string) $flashed)
                ?? $this->soleStoreOrderByNumber($store, $flashed);
        } elseif ($boundHere->isNotEmpty() && ! $request->has('data.order_id') && ! ($khalti && is_scalar($khalti))) {
            /*
             * Nothing flashed and nothing ASKED FOR: the most recent order this session placed in
             * this store is the one it just paid for.
             *
             * `$request->has(...)` and not `! is_scalar($requested)`, which is what the first draft
             * of this branch tested and what
             * test_odd_shapes_of_the_request_id_are_not_coerced_into_a_match caught: an ARRAY
             * `data[order_id]` is not scalar either, so a malformed request id fell into this limb
             * and was answered with the session's most recent order instead of being refused. No
             * disclosure -- the session really did place it -- but a malformed identifier must be
             * refused rather than reinterpreted, which is the whole point of that test.
             */
            $order = $boundHere->first();
        } elseif (is_scalar($requested) && in_array(filter_var($requested, FILTER_VALIDATE_INT), $this->orderCompleteBoundIds(), true)) {
            $order = Order::where('id', (int) $requested)
                ->where('store_id', $store->id)
                ->first();
        } elseif ($khalti && is_scalar($khalti)) {
            /*
             * Same precedence as the flashed limb above, for the same reason: `khalti_order_id` is a
             * NUMBER two same-second orders in one store share. Khalti now binds the primary key as
             * well (KhaltiPaymnetController), so the collision resolves per buyer; the number lookup
             * stays as the fallback for a session placed before that, and still refuses an ambiguous
             * number rather than guessing.
             */
            $order = $boundHere->firstWhere('product_order_id', (string) $khalti)
                ?? $this->soleStoreOrderByNumber($store, $khalti);
        }

        if (isset($order) && !empty($order)) {
            $storeUrl = getFrontThemeUrl($store);
            $url = $storeUrl.'/' . $store->slug . '/order/' . \Illuminate\Support\Facades\Crypt::encrypt($order->id ?? '');
            return view('front_end.pages.order-complete', compact('url', 'order'));
        } else {
            return redirect()->route('landing_page', $store->slug);
        }
    }

    public function shippinglabel(Request $request, $id)
    {
        try {
            $id = crypt::decrypt($id);
            $order = Order::order_detail($id);
            $settings = Utility::Setting();

            // P1-SEC-11. The "FROM" block is THE ORDER'S STORE's address. Utility::Setting() resolves the viewer's tenant and CURRENT store and,
            // when that store has no rows of its own, falls back to every row of user id 1 across ALL stores (last row wins) -- so a label for
            // merchant A's order printed the address of whichever store the platform user configured last (EXECUTED:
            // ShippingLabelStoreSettingsTest). Read the five address names for the order's own store; a store with none prints an empty block,
            // never another store's.
            // Read the rows directly: Utility::GetValueByName() falls back to the same Utility::Setting() when the store has no row.
            $orderStore = (int) Order::where('id', $id)->value('store_id');
            $addressNames = ['store_address', 'store_city', 'store_state', 'store_zipcode', 'store_country'];
            $ownRows = $orderStore > 0
                ? \Illuminate\Support\Facades\DB::table('settings')->where('store_id', $orderStore)->whereIn('name', $addressNames)->orderBy('id')->pluck('value', 'name')->all()
                : [];
            foreach ($addressNames as $addressName) {
                $settings[$addressName] = (string) ($ownRows[$addressName] ?? '');
            }

            $product = [];
            $products = [];
            foreach ($order['product'] as $product_item) {
                if ($product_item['variant_id'] == '0')
                    $product[] = Product::where('id', $product_item)->pluck('product_weight', 'id')->first();
                else {
                    $products[] = ProductVariant::where('id', $product_item['variant_id'])->pluck('weight', 'id')->first();
                }
            }
            $newArray = [];

            foreach ($product as $key => $value) {
                $newArray[$key] = intval($value);
            }
            $product = array_merge($newArray, $products);
            $product_sum = array_sum($product);



            if (!empty($order['message'])) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }
            /*
             * P1-SEC-11 criterion 4, and criterion 1's remainder. The document is printed FOR AN ORDER, so
             * every setting it renders belongs to that order's store -- which criterion 1 established for
             * the five address names above and not for the other two things this view reads:
             *
             *   * `invoice_logo`, which the view fetched as `GetValueByName('invoice_logo')` with NO store
             *     id. That resolves `getCurrentStore()`, the VIEWER's store, so the platform operator
             *     printing a merchant's label got the OPERATOR's logo on it.
             *   * `SITE_RTL`, which the view did not read at all: its `<html>` was a hardcoded
             *     `lang="en"` with no `dir`, so the block of `html[dir="rtl"]` rules at the bottom of its
             *     stylesheet could never match and an Arabic store's label was always laid out
             *     left-to-right. That is criterion 4.
             *
             * Both are resolved HERE, from the order's own store, rather than in the view.
             */
            $documentLocale = \App\Services\DocumentDirection::localeFor($orderStore);
            $documentRtl = \App\Services\DocumentDirection::isRtl($orderStore);
            $invoice_logo = \App\Services\DocumentDirection::invoiceLogoFor($orderStore);

            return view('order.shippinglabel', compact('order', 'product_sum', 'settings', 'documentLocale', 'documentRtl', 'invoice_logo'));
        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function order_receipt($id)
    {
        try {
            $id = Crypt::decrypt($id);
            $order = Order::order_detail($id);

            if (!empty($order['message'])) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }

            // P1-SEC-11 criteria 1 and 4 -- see the shipping label above for the full argument. Same two
            // reads, same two defects, resolved from the order's own store.
            $orderStore = (int) Order::where('id', $id)->value('store_id');
            $documentLocale = \App\Services\DocumentDirection::localeFor($orderStore);
            $documentRtl = \App\Services\DocumentDirection::isRtl($orderStore);
            $invoice_logo = \App\Services\DocumentDirection::invoiceLogoFor($orderStore);

            return view('order.receipt', compact('order', 'documentLocale', 'documentRtl', 'invoice_logo'));
        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function order_return(Request $request)
    {
        /*
         * OWNERSHIP AND PERMISSION, WHICH THIS END OF THE WORKFLOW NEVER HAD. P1-INV-02 / S37-2.
         *
         * This method sat in the `auth` group and nothing else: three request fields went
         * straight into Order::product_return(), which resolves `Order::find($order_id)` on a
         * model that is NOT in config/tenancy.php's `scoped_models` and so reaches every tenant.
         * MEASURED by a prior session: a store-A merchant posting store B's order_id moved B's
         * variant stock 50 -> 59 and marked B's order returned, then 500'd AFTER the writes
         * landed.
         *
         * Its own sibling eighteen lines below, order_return_request(), already carries exactly
         * this check from GATE C. The two ends of one workflow diverged, and that divergence is
         * the whole defect.
         *
         * `updateStatus` is reused rather than inventing a permission name: OrderPolicy::allow
         * checks isPlatformAdmin BEFORE isAbleTo (so the operator is not locked out, since on a
         * from-source database no role holds 'Manage Order'), then requires the seeded
         * 'Manage Order', then sameStore. That is all three of this task's acceptance criteria in
         * one call.
         *
         * The null guard comes first because `authorize()` needs a model, and because
         * product_return() itself read `$Order->delivered_status` on the result of find() with no
         * check -- an absent id was a 500.
         */
        $order = Order::find($request->order_id);

        if (empty($order)) {
            return response()->json([
                'status' => 'error',
                'message' => __('Something was wrong.'),
            ]);
        }

        $this->authorize('updateStatus', $order);

        $data['product_id'] = $request->product_id;
        $data['variant_id'] = $request->variant_id;
        $data['order_id'] = $request->order_id;

        $responce = Order::product_return($data);
        if ($responce['status'] == 'success') {
            $return['status'] = 'success';
            $return['message'] = $responce['message'];
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = $responce['message'];
            return response()->json($return);
        }
    }

    public function order_return_request(Request $request)
    {
        $id = $request->id;
        $status = $request->status;

        $order = Order::find($id);
        if (!empty($order)) {
            // GATE C, THE HALF THAT WAS MISSING.
            //
            // Phase 1C moved order.return.request behind `auth`, which closed ANONYMOUS
            // access. It did not close cross-tenant access: this method resolved the order
            // with a bare Order::find($id) and had NO ownership check of any kind, so any
            // authenticated merchant could approve or reject a return on ANY order in ANY
            // store -- and, with $status == 2, restore stock onto another store's products.
            // The route owner and the handler owner each took the other end to be carrying
            // the control, so neither end did, and Git reported no conflict because the two
            // changes were in different files.
            //
            // 'updateStatus' is the existing ability for "may change this order's state"
            // and is reused deliberately rather than inventing a new permission name: it
            // requires the SEEDED 'Manage Order' (Utility.php:2755) and OrderPolicy::allow
            // checks isPlatformAdmin BEFORE isAbleTo, so platform operators are not locked
            // out of the support workflow.
            $this->authorize('updateStatus', $order);

            /*
             * IDEMPOTENCY. The restock below used to run on EVERY call carrying
             * $status == 2, with no reference to the return state the order was
             * already in. `return_status` is not single-assignment -- this same
             * method writes it, and nothing refuses a second POST -- so an
             * authenticated merchant replaying one approval added `qty` back to
             * every line again, without limit.
             *
             * That is the SAME unbounded inventory-inflation primitive that was
             * closed on `status.cancel` (see the comment in status_cancel()
             * about the loop that ran before the success branch was examined).
             * It was left open here because the two fixes were made for two
             * different endpoints, and nothing tied them together.
             *
             * The marker is the order's OWN `return_status`, read BEFORE
             * anything is written: 2 is "Return" (App\Models\Order.php:145), so
             * an order already at 2 has already had its goods counted back in.
             * No note row and no cache key is needed -- the durable state is
             * already on the row this method updates, and it is updated inside
             * the same transaction as the stock it accounts for.
             */
            $previousReturnStatus = (int) $order->return_status;
            $isNewApproval = ((int) $status === 2) && $previousReturnStatus !== 2;

            /*
             * TRANSACTION BOUNDARY, around the LOCAL writes only. This method
             * makes no external call -- the e-mail and webhook side effects of
             * the order flow live in other methods -- so the boundary covers
             * exactly the stock restore and the order row that records it.
             *
             * Before this, the restock loop and `$order->save()` were separate
             * autocommitted writes. A throw part-way through the loop left some
             * lines restocked and `return_status` NEVER written, so the order
             * stayed un-approved while its inventory had already moved -- and
             * because the guard above reads `return_status`, the next attempt
             * then restocked the surviving lines a second time.
             */
            DB::transaction(function () use ($order, $status, $isNewApproval) {
                if ($isNewApproval) {
                    $this->restoreReturnedStock($order);
                }

                $order->return_price = $order->final_price;
                $order->return_status = $status;
                $order->save();
            });
        }

        $return['message'] = __('Return request approve successfully');
        if ($status == 3) {
            $return['message'] = __('Return request reject successfully');
        }
        return response()->json($return);
    }

    /**
     * Put an approved return's goods back into stock, exactly once per line.
     *
     * ATOMIC. Each line used to be `$model->stock += $qty; $model->save();` --
     * a read-modify-write, i.e. the lost update
     * App\Services\ProductStockService exists to remove. Two concurrent
     * approvals both read the same stock and both wrote `stock + qty`, so the
     * second overwrote the first and returned goods vanished from inventory.
     * The service issues an `increment()`, which the database applies to the
     * row's current value.
     *
     * The CALLER owns both the transaction and the replay guard; this method
     * only does the arithmetic, so it must not be called without them.
     */
    private function restoreReturnedStock(Order $order): void
    {
        $lines = json_decode((string) $order->product_json, true);

        if (!is_array($lines)) {
            Log::warning('Return approval restored no stock: order carries no readable product_json', [
                'order_id' => $order->id,
            ]);

            return;
        }

        /*
         * The store comes from the ORDER, not from getCurrentStore().
         *
         * OrderPolicy::allow checks isPlatformAdmin BEFORE isAbleTo, so a
         * platform operator may legitimately approve a return on a store that is
         * not their current_store. Scoping this lookup to getCurrentStore()
         * would silently restore nothing for exactly that supported workflow.
         * The order has already passed authorize('updateStatus'), so its own
         * store_id is the authorised one.
         */
        $storeId = $order->store_id;

        /*
         * ONE RETURN, ONE STOCK CREDIT -- the half of P1-INV-15 (E) that lives on this side.
         * OWNER DECISION 5: "Restock only when a return is actually accepted. Prevent duplicate
         * stock credits and duplicate refunds."
         *
         * THE DOUBLE CREDIT. This loop walks EVERY line with no reference to what the other
         * endpoint has already done. `Order::product_return()` lets a merchant mark ONE LINE
         * returned and puts that line's goods back immediately; approving the return request then
         * put the same goods back AGAIN. Each endpoint's own replay guard was correct about its
         * own endpoint and blind to the other, which is why the two-step merchant workflow --
         * mark the line, then approve -- inflated inventory by the returned quantity.
         *
         * The marker is written into the line inside `product_json` by whichever endpoint credits
         * it first, and both now consult it. See Order::LINE_STOCK_RESTORED for why the marker is
         * per-LINE rather than per-order.
         *
         * $restored collects the indexes credited by THIS pass so they can be stamped in the same
         * transaction as the status write. The caller owns that transaction (:950).
         */
        $restored = [];

        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            if (Order::lineStockAlreadyRestored($line)) {
                Log::warning('Return approval skipped a line whose stock was already credited', [
                    'order_id' => $order->id,
                    'product_id' => $line['product_id'] ?? null,
                ]);

                continue;
            }

            $productId = $line['product_id'] ?? null;
            $variantId = $line['variant_id'] ?? null;
            $qty = $line['qty'] ?? null;

            /*
             * `qty` is read out of a JSON blob on the order row, so it is
             * neither typed nor validated. A negative value would have
             * SUBTRACTED stock through a restore path, and a non-numeric one was
             * coerced to 0 silently. Both are skipped and logged rather than
             * applied.
             */
            if ($productId === null || !is_numeric($qty) || (int) $qty <= 0) {
                Log::warning('Return approval skipped a line with an unusable product id or quantity', [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                ]);

                continue;
            }

            $qty = (int) $qty;

            /*
             * The store predicate is new. `product_id` comes from the order's own
             * product_json, which is a JSON blob rather than a validated foreign
             * key, so the previous bare `Product::find($product_id)` would have
             * written product_stock onto a product in ANY store.
             */
            $product = Product::where('id', $productId)
                ->where('store_id', $storeId)
                ->first();

            if (!$product) {
                Log::warning("Return approval skipped a line whose product is not in the order's store", [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'store_id' => $storeId,
                ]);

                continue;
            }

            if ($variantId == 0 || empty($variantId)) {
                $this->stockService()->restoreProductStock($product->id, $qty);
                $restored[] = $index;

                continue;
            }

            /*
             * BOTH predicates are kept. Looking a variant up by `id` alone would
             * accept a variant belonging to a different product, and looking it
             * up by `product_id` alone is the wrong-row write already fixed in
             * OrderRefundController::RefundStock.
             */
            $variant = ProductVariant::where('id', $variantId)
                ->where('product_id', $product->id)
                ->first();

            if (!$variant) {
                Log::warning('Return approval skipped a line whose variant does not belong to its product', [
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                ]);

                continue;
            }

            $this->stockService()->restoreVariantStock($variant->id, $qty);
            $restored[] = $index;
        }

        /*
         * PERSIST THE MARKERS, in the caller's transaction, so a later call to either endpoint
         * can see that these goods are already back in. Written only when something actually
         * moved, so an approval that restocked nothing does not rewrite the column.
         *
         * `$order->save()` is NOT called here: the caller writes `return_status` and
         * `return_price` on the same instance immediately afterwards, inside the same
         * transaction, and that save persists this too. Saving twice would be two statements for
         * one state change.
         */
        if ($restored !== []) {
            foreach ($restored as $index) {
                $lines[$index][Order::LINE_STOCK_RESTORED] = 1;
            }

            $order->product_json = json_encode($lines);
        }
    }

    public function order_status_change(Request $request)
    {
        /*
         * OWNERSHIP AND PERMISSION. P1-ORD-04.
         *
         * This method had NO permission check of any kind -- the route (routes/web.php:1179)
         * carries only the `auth` group, and `index()`'s isAbleTo('Manage Order') guards the
         * LISTING, not this -- and no ownership predicate: `$request->id` went straight into
         * Order::order_status_change(), which does a bare `Order::find()` (Order.php:537) on a
         * model with no global scope and no `scoped_models` entry.
         *
         * AND THE CANCEL LIMB IS A MONEY WRITE, which is why this outranks a status tweak. It
         * sets `payment_status = 'Unpaid'` and saves (Order.php:546-554), and
         * OrderObserver::updated fires OrderFinancialService::syncFromOrder on exactly that
         * column changing (OrderObserver.php:32-38) -- so cancelling walks back the order's
         * wallet record. The finance half is invisible to the test suite because
         * `store_order_financials` is a GATE F table created by no migration, so the regression
         * test pins the order columns that trigger it and says so.
         *
         * Same three lines as order_return, and the same reasoning: `updateStatus` via
         * OrderPolicy::allow checks isPlatformAdmin BEFORE isAbleTo (so the operator keeps the
         * support workflow on a database where no role holds 'Manage Order'), then requires the
         * seeded 'Manage Order', then sameStore.
         *
         * THE ID COMES FROM THE ROUTE, NOT THE BODY. `Request::__get` reads `all()` before the
         * route parameters, so a posted `id` previously WON over the URL segment -- measured: a
         * POST to /order-status-change/<own id> carrying `id=<victim id>` acted on the victim.
         * The route declares `{id}`, so the segment is always present, and the shipped caller
         * (resources/views/order/view.blade.php:655-662) sends both with the same value. Reading
         * the segment removes the ambiguity rather than leaving the authorization and the write
         * free to disagree about which order they mean.
         */
        $orderId = $request->route('id');

        $order = Order::find($orderId);

        if (empty($order)) {
            return response()->json([
                'status' => false,
                'message' => __('Something was wrong.'),
            ]);
        }

        $this->authorize('updateStatus', $order);

        $data['order_id'] = $orderId;
        $data['order_status'] = $request->delivered;
        $responce = Order::order_status_change($data);
        $order = Order::order_detail($data['order_id']);
        $d_order = Order::find($data['order_id']);

        /*
         * ========================================================================================
         *  DELIVERED IS NOT PAID. THE COD SETTLEMENT BLOCK THAT STOOD HERE IS WITHDRAWN.
         *  Owner decision, 2026-10-24, FINAL.
         * ========================================================================================
         *
         *     "Withdraw the Delivered-based COD payment/settlement trigger now. Delivered must
         *      never, by itself, mark COD as Paid or credit a merchant wallet. Require a separate
         *      verified cash collection event, recording the collector (merchant, courier or
         *      delivery company), collection amount, time and supporting evidence. Keep order
         *      delivery reporting separate from cash collection and financial settlement
         *      reporting."
         *
         * WHAT USED TO BE HERE, so the removal is legible rather than a gap. On a successful
         * 'delivered' status change this block wrote `payment_status = Paid` and fired
         * OrderPaymentStatus, whose listeners reach OrderFinancialService and credit the merchant
         * wallet. It arrived with D-06, which made COD Pending at creation and needed SOMETHING to
         * move it to Paid; marking delivered was the only signal the product had.
         *
         * WHY IT IS GONE. A merchant clicking 'delivered' on their own order, through their own
         * web guard, is a PROXY for collection and not evidence of it -- the courier may not have
         * handed the cash over, and the click may be early, mistaken, or made to clear a
         * dashboard. D1 said so ("no wallet credit for creation *or* for 'Delivered'") and the
         * 2026-10-24 decision withdrew the trigger outright.
         *
         * SO THIS METHOD NOW CHANGES ONE THING: the order's DELIVERY status. It does not touch
         * payment_status, it fires no payment event, and it credits nothing. That separation --
         * delivery reporting apart from cash collection apart from financial settlement -- is
         * itself part of the decision, not a side effect of removing code.
         *
         * WHAT SETTLES A COD ORDER NOW. `App\Services\CodCollectionService` records a verified
         * cash collection event naming the collector, amount, time and evidence, and
         * `PaymentVerificationService::codMayBeSettled()` consults it. Until GATE F provides
         * `cod_collection_events` no event can exist, so a COD order stays Pending until a human
         * asserts payment through `order_payment_status()` -- a deliberate separate act, which is
         * the point.
         *
         * HISTORICAL ROWS ARE UNTOUCHED, per the same decision: COD orders already marked Paid by
         * the withdrawn trigger keep that status. Nothing is reversed or back-filled.
         *
         * CodStateMachineTest pins every sentence above, including that a 'delivered' change
         * leaves payment_status alone and fires no OrderPaymentStatus event.
         */
        $store = getStoreById($d_order->store_id);
        $dArr = [
            'order_id' => $data['order_id'],
            'order_status' => $data['order_status']
        ];
        $owner = User::find($store->created_by);
        try {
            $order_id = Crypt::encrypt($data['order_id']);
            $resp = Utility::sendEmailTemplate('Status Change', $order['delivery_informations']['email'], $dArr, $owner, $store, $order_id);
        } catch (\Exception $e) {
            $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
        }

        if (module_is_active('ReviewReminder')) {

            event(new SendOrderReviewMail($data, $order, $dArr, $owner, $store, $order_id));
        }

        OrderNote::order_note_data([
            'customer_id' => $d_order->customer_id,
            // P1-ORD-04: the ROUTE order, not the posted one. `order_notes.order_id` is a
            // NULLABLE FK, so the body's value attached a note row to ANY order id on the
            // platform while carrying the attacker's store_id. Litter in another tenant's id
            // space rather than a disclosure -- order-note reads are store-filtered -- but it is
            // the same one-token mistake as the webhook sink below.
            'order_id' => $orderId,
            'status' => 'Order status change',
            'changeble_status' => $request->delivered,
            'store_id' => $d_order->store_id
        ]);
        try {
            $mobile_no = $order['delivery_informations']['phone'];
            $order_id = $order['order_id'];
            $msg = __("Hello, Welcome to $store->name .Your Order is $request->delivered, !Hi $order_id, Thank you for Shopping");

            $resp = Utility::SendMsgs('Status Change', $mobile_no, $msg);
        } catch (\Exception $e) {
            $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
        }
        if ($responce['status'] == 'success') {
            $module = 'Status Change';
            $store = Store::find(getCurrentStore());
            $webhook = Utility::webhook($module, $store->id);
            if ($webhook) {
                /*
                 * THE WEBHOOK PAYLOAD READS THE ROUTE ORDER. P1-ORD-04, and this sink was a LIVE
                 * CROSS-TENANT PII EXFILTRATION THAT THE FIRST VERSION OF THIS FIX DID NOT STOP.
                 *
                 * Pinning the id for the authorization and the write left this read on the POSTED
                 * value, and `Order::order_detail()` is a bare `Order::find()` on a model with no
                 * global scope. So: a merchant creates a `Status Change` webhook pointing at
                 * their own URL (WebhookController::store has no permission check and forces
                 * store_id to the caller's own), then POSTs
                 * /order-status-change/<their own order> with the victim's id in the body. The
                 * authorization passes on their own order, the status change succeeds -- and then
                 * the VICTIM's order detail is JSON-encoded and POSTed to the attacker's
                 * endpoint: customer name, street, city, country, postcode, EMAIL, PHONE, every
                 * line item and total. One request per id enumerates the platform's order book.
                 *
                 * Found by an adversarial review of this fix, which observed that the comment
                 * above claimed the pinning "removes the ambiguity" when it had removed it for
                 * the authorize and the write only. The fix did not survive its own promise.
                 */
                $order = Order::order_detail($orderId);
                $parameter = json_encode($order);

                // 1 parameter is  URL , 2 parameter is data , 3 parameter is method
                $status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);
                if ($status != true) {
                    $msgs = 'Webhook call failed.';
                }
            }

            $return['status'] = 'success';
            $return['order_status'] = ucfirst($data['order_status']) ?? '';
            $return['message'] = $responce['message'] . ((isset($msgs)) ? '<br> <span class="text-danger">' . $msgs . '</span>' : '');
            return response()->json($return);
        } else {
            $return['status'] = false;
            $return['message'] = $responce['message'];
            return response()->json($return);
        }
    }

    public function order_payment_status(UpdateOrderPaymentStatusRequest $request)
    {
        // NEW-order-payment-status / SEC-F7. Was: Order::find(), assign, save --
        // no authentication, no permission, no tenant scope, no validation. The
        // route also sat outside the `auth` group, so this was an ANONYMOUS
        // arbitrary payment_status write on any order in any store.
        //
        // findOrFail so a bad id is a 404 rather than a null dereference 500.
        $order = Order::findOrFail($request->input('order_id'));

        $this->authorize('updatePaymentStatus', $order);

        $order->payment_status = $request->input('payment_status');
        $order->save();

        $return['status'] = 'success';
        $return['message'] = __('Payment status has been changed.');
        return response()->json($return);
    }

    public function orderDetails(Request $request)
    {
        try {
            $id = decrypt($request->route('id'));
            $order = Order::order_detail($id);
            $store = getStore($request->route('storeSlug'));
            $order_data = Order::where('id', $id)->where('store_id', $store->id)->first();
            if (!$order_data) {
                abort(404);
            }
            
            $order_note = OrderNote::where('order_id', $order['id'])
                ->where('note_type', 'to_customer')
                ->get();

            if (!empty($order['message'])) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }

            return view('front_end.pages.order-detail', compact('order', 'store','order_data', 'order_note'));
        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function status_cancel(Request $request, $slug)
    {
        // CRITICAL, AND ANONYMOUS. This endpoint took `order_id` straight off the request
        // and had NO ownership check of any kind. Its route --
        // routes/customer.php:142 `POST {storeSlug?}/status-cancel` -- sits in the
        // storefront group whose only middleware is [ActiveTheme, themelanguage], so it
        // required no session at all. An anonymous caller who guessed a numeric order id
        // could cancel ANY order on the platform, in any store.
        //
        // AND IT INFLATED INVENTORY WITHOUT LIMIT. The stock-restore loop below used to run
        // BEFORE `$responce['status']` was examined, so it added `qty` back to every line
        // even when Order::order_status_change() REFUSED the cancellation. Repeating the
        // same request therefore kept adding stock: order_status_change rejects a second
        // attempt (`delivered_status != 0`, app/Models/Order.php), but the loop had already
        // run. One guessed id was an unbounded inventory-inflation primitive.
        //
        // THE CONTROL, following the pattern orderDetails() already uses on this same route
        // group (resolve the store from the slug, then filter and 404):
        //   1. the store must resolve from the slug;
        //   2. there must be an authenticated CUSTOMER -- this is a customer self-service
        //      action, not a public one;
        //   3. the order must belong to BOTH that store and that customer.
        //
        // DISCLOSED BEHAVIOUR CHANGE: `orders.customer_id` defaults to 0, so a GUEST order
        // can no longer be cancelled through this endpoint. That is deliberate. Self-service
        // cancellation of a guest order over an unauthenticated endpoint keyed on a
        // guessable integer is the vulnerability itself, not a feature; a guest cancellation
        // has to go through the merchant.
        $store = getStore($slug);

        if (! $store) {
            abort(404);
        }

        $customer = auth('customers')->user();

        if (! $customer) {
            abort(403);
        }

        $order = Order::where('id', $request->order_id)
            ->where('store_id', $store->id)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            abort(404);
        }

        $data['order_id'] = $request->order_id;
        $data['order_status'] = $request->order_status;

        $responce = Order::order_status_change($data);

        // STOCK IS RESTORED ONLY IF THE CANCELLATION ACTUALLY SUCCEEDED. Moving this inside
        // the success branch is what makes the endpoint repeat-safe: a second attempt is
        // refused by order_status_change and now adds nothing.
        if ($responce['status'] == 'success') {
            $order_detail = Order::order_detail($request->order_id);

            /*
             * ATOMIC, AND TRANSACTIONAL. Each line was
             * `$model->stock += $item['qty']; $model->save();` -- the same
             * read-modify-write lost update App\Services\ProductStockService
             * exists to remove: two concurrent cancellations both read the same
             * stock and both wrote `stock + qty`, so the second overwrote the
             * first and a cancelled order's goods never came back.
             *
             * THE BOUNDARY COVERS THIS LOOP ONLY, deliberately. It does NOT
             * extend over the Order::order_status_change() call above, because
             * that method fires `event(new OrderPaymentStatus($order))`
             * (app/Models/Order.php:554) whose listeners send notifications --
             * an external HTTP call must never sit inside a transaction, and a
             * wallet listener behind GATE F must not be rolled back by a stock
             * failure either. The replay guard stays exactly where it was: this
             * loop still runs only on `$responce['status'] == 'success'`, which
             * a second attempt never reaches.
             *
             * The store predicate is new, for the same reason as in
             * restoreReturnedStock(): `product_id` comes out of the order's
             * product_json, so the previous unscoped lookup could add stock to a
             * product in another store.
             */
            DB::transaction(function () use ($order_detail, $order) {
                foreach ($order_detail['product'] as $item) {
                    $productId = $item['product_id'] ?? null;
                    $qty = $item['qty'] ?? null;

                    if ($productId === null || !is_numeric($qty) || (int) $qty <= 0) {
                        Log::warning('Order cancellation skipped a line with an unusable product id or quantity', [
                            'order_id' => $order->id,
                            'product_id' => $productId,
                        ]);

                        continue;
                    }

                    $qty = (int) $qty;

                    $product = Product::where('id', $productId)
                        ->where('store_id', $order->store_id)
                        ->first();

                    if (! $product) {
                        Log::warning("Order cancellation skipped a line whose product is not in the order's store", [
                            'order_id' => $order->id,
                            'product_id' => $productId,
                            'store_id' => $order->store_id,
                        ]);

                        continue;
                    }

                    if ($item['variant_id'] == 0) {
                        $this->stockService()->restoreProductStock($product->id, $qty);

                        continue;
                    }

                    // Both predicates, so a variant_id belonging to another
                    // product cannot be credited against this line.
                    $variant = ProductVariant::where('id', $item['variant_id'])
                        ->where('product_id', $product->id)
                        ->first();

                    if (! $variant) {
                        Log::warning('Order cancellation skipped a line whose variant does not belong to its product', [
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'variant_id' => $item['variant_id'],
                        ]);

                        continue;
                    }

                    $this->stockService()->restoreVariantStock($variant->id, $qty);
                }
            });

            $return['status'] = 'success';
            $return['message'] = __('Order cancel successfully!');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = $responce['message'];
            return response()->json($return);
        }
    }

    public function destroy(Order $order)
    {
        // SEC-F7. The 'Delete Order' permission was checked but ownership was
        // not, so the implicitly bound id was the only authorization token and
        // any holder deleted any store's order.
        $this->authorize('delete', $order);

        // The cascade was four unrelated statements; a failure part-way left
        // orphaned detail rows behind.
        \DB::transaction(function () use ($order) {
            OrderTaxDetail::where('order_id', $order->id)->delete();
            OrderCouponDetail::where('order_id', $order->id)->delete();
            OrderBillingDetail::where('order_id', $order->id)->delete();
            Order::where('id', $order->id)->delete();
        });

        return redirect()->back()->with('success', __('Order Delete succefully.'));
    }

    public function updateStatus(Request $request, $id)
    {
        // SEC-F7. No permission check and no tenant scope, and Order::find()
        // returned null for a bad id which the next line dereferenced into a
        // 500 (NEW-unauthenticated-500s).
        //
        // NOTE: OrderPolicy::updateStatus deliberately carries NO delivery-boy limb.
        // An earlier revision of this comment claimed it did; that was false and is
        // corrected here. A limb comparing $order->deliveryboy_id to $actor->id was
        // considered and refused for two reasons, recorded in OrderPolicy:74-91:
        // the actor here is type-hinted User (the web guard) while delivery boys
        // authenticate on the separate deliveryboy guard against the separate
        // delivery_boys table, so it could never fire; and the two id sequences are
        // unrelated and both start at 1, so it would have let a merchant whose
        // users.id collided with an order's deliveryboy_id change that order's
        // status in ANY store. The delivery-boy flow has its own guard and routes.
        $order = Order::findOrFail($id);

        $this->authorize('updateStatus', $order);

        if ($order->delivered_status == 0) {
            $order->delivered_status = 1;
            $order->delivery_date = Carbon::now();
        }
        $order->save();

        return response()->json([
            'delivered_status' => $order->delivered_status,
            'delivery_date' => $order->delivery_date,
            'message' => __('Order Status change successfully.')
        ]);
    }

    /**
     * S14 / order export. THIS CHECKED NO PERMISSION AT ALL -- any authenticated web principal
     * could download the whole order book as a spreadsheet, including a staff member whose role
     * grants no order permission whatsoever, while the order LIST they are refused sits behind
     * 'Manage Order' (index():483).
     *
     * IT IS NOT, HOWEVER, A CROSS-TENANT LEAK, and the finding that reached me said it was.
     * App\Exports\OrderExport::collection() does `Order::where('store_id', $store->id)` with
     * `$store = getStoreById(getCurrentStore())`, so the rows are already the caller's own
     * store's. Reporting it as cross-tenant would have overstated it; the real defect is that
     * the permission boundary around the order book had a file-shaped hole in it.
     *
     * Gated on 'Manage Order' -- the permission the order list already uses, seeded by
     * Utility::addNewData() and granted to the merchant role. No new name is introduced.
     * Operator-first because isAbleTo() has no super-admin bypass here.
     *
     * The empty-store guard also closes a 500: with no store context `getStoreById(null)` returns
     * null and OrderExport dereferences `$store->id`.
     */
    public function fileExport()
    {
        $user = auth()->user();
        // P1-SEC-11 criterion 3: this was already normalised but against a HARDCODED LITERAL rather
        // than the constant -- correct behaviour via a fourth copy of the decision.
        $isOperator = \App\Policies\UserPolicy::isOperator($user);

        if (!$user || (!$isOperator && !$user->isAbleTo('Manage Order'))) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        /*
         * P1-SEC-11 criterion 2: `empty(getCurrentStore())` is NOT the whole question, because the
         * helper hardcodes 1 for an operator with no `current_store` -- so this guard passes and
         * the export then resolved a store that may not exist. Ask whether the store RESOLVES, not
         * merely whether the helper returned something: a deployment whose stores do not start at
         * 1 (a deleted first store is enough) gave a 500 from OrderExport dereferencing null, and
         * where store 1 DOES exist and is not the operator's, `Order.xlsx` quietly held that
         * store's orders with nothing in the response naming it.
         */
        if (empty(getCurrentStore()) || getStoreById(getCurrentStore()) === null) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $fileName = 'Order.xlsx';
        return Excel::download(new OrderExport, $fileName);
    }

    public function additionalnote(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            $return['html_data'] = null;
            $return['message'] = __('Store not found.');
            return response()->json($return);
        }
        
        $return['html_data'] = view('front_end.sections.additional_note', compact('slug'))->render();
        return response()->json($return);
    }

    public function order_assign(Request $request, DeliveryBoyNotificationService $notificationService)
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'delivery_boy' => 'required|integer|exists:delivery_boys,id',
        ]);

        // SEC-F7. The validate() above is INPUT validation, not authorization --
        // the clearest instance of that confusion in the codebase. Without the
        // authorize() below, any authenticated user reassigned any store's order
        // and, via the DeliveryEarning delete three lines down, destroyed
        // another store's financial records.
        $order = Order::findOrFail($request->order_id);

        $this->authorize('assign', $order);

        $deliveryBoy = \App\Models\DeliveryBoy::find($request->delivery_boy);

        /*
         * P1-PLT-08. THE SCOPE IS NOT THE CONTROL OF RECORD HERE, and driving this route is how that
         * turned up. `DeliveryBoy` is allowlisted now, so the `find()` above returns null for another
         * merchant's driver -- but the write three lines down took `$request->delivery_boy` VERBATIM,
         * not `$deliveryBoy->id`, so the order was still stamped with the foreign driver's id. The
         * validator does not stop it either: `exists:delivery_boys,id` runs on the query BUILDER, which
         * global scopes do not touch, so a cross-store id passes validation. Only the commission block
         * below depended on the lookup, and it simply went quiet.
         *
         * So the refusal has to be explicit. For the platform operator the scope is bypassed, so this
         * resolves any driver and the limb is inert for them.
         */
        if ($deliveryBoy === null) {
            \Log::warning('Order assignment refused: the delivery boy is not in a store this caller owns', [
                'order_id' => $order->id,
                'delivery_boy' => $request->delivery_boy,
                'user_id' => \Auth::id(),
            ]);

            /*
             * 403 AND JSON, matching the shape this route already produces: `authorize('assign', ...)`
             * four lines up throws an AuthorizationException, and the success arm returns JSON, so a
             * redirect would be the odd one out on an AJAX endpoint.
             *
             * AND THE MERCHANT IS SHOWN IT, which the first cut got wrong twice over. That cut said the
             * refusal was unreachable without tampering "because the dropdown is built from the order's
             * own store (OrderController:535)". It is not: :533-535 reads `getStoreById(getCurrentStore())`
             * -- the caller's CURRENT store, not the order's. Both are the caller's, so there is no
             * isolation defect, but a multi-store merchant viewing an order in a non-current owned store
             * is offered the CURRENT store's drivers, so the refusal is reachable by ordinary use. The
             * AJAX caller had a `success` callback and no `error` one (the same gap P1-SEC-34 found in
             * SpamPrevent's widget), so the merchant would have seen nothing at all; the callback is
             * added in resources/views/order/view.blade.php.
             */
            return response()->json([
                'status' => 'error',
                // "not one of your stores'" rather than "not in this store": the predicate the scope
                // applies is the caller's OWNED stores, which is wider than OrderPolicy::sameStore()'s
                // current-store test. Saying "this store" overstated what was checked (review N4).
                'message' => __('That delivery boy is not one of your stores\' drivers.'),
            ], 403);
        }

        if ($order->deliveryboy_id != $request->delivery_boy) {
            \App\Models\DeliveryEarning::where('order_id', $order->id)->delete();
        }

        /*
         * The RESOLVED driver, not the request value (review N3).
         *
         * MEASURED AS AN EQUIVALENT MUTANT, and recorded as that rather than claimed as a fix: putting
         * `$request->delivery_boy` back here fails no test, and it should not, because the two cannot
         * differ. The row was fetched BY that id, so they agree numerically; and the shape that would
         * separate them on MySQL -- a junk-suffixed `'7abc'`, which `WHERE id = ?` coerces to row 7 while
         * the write stores the string -- is rejected by this handler's own `'delivery_boy' =>
         * 'required|integer|exists:delivery_boys,id'` before any of this runs.
         *
         * It is changed anyway because the guard above exists precisely because this line used to read
         * request input, and leaving it reading request input makes the next person re-derive that
         * equivalence argument from scratch.
         */
        $order->deliveryboy_id = $deliveryBoy->id;
        $order->save();

        $shippingMethod = \App\Models\ShippingMethod::find($order->delivery_id);

        if (
            $shippingMethod &&
            $deliveryBoy &&
            strtolower(trim($shippingMethod->method_name)) === 'local pickup' &&
            $deliveryBoy->is_commission_enabled == 1
        ) {
            $commissionPercentage = (float) ($deliveryBoy->commission_percentage ?? 0);
            $shippingCost = (float) ($order->delivery_price ?? 0);

            if ($commissionPercentage > 0 && $shippingCost > 0) {
                $earningAmount = ($shippingCost * $commissionPercentage) / 100;

                \App\Models\DeliveryEarning::create([
                    'delivery_boy_id'       => $deliveryBoy->id,
                    'order_id'              => $order->id,
                    'shipping_method_id'    => $shippingMethod->id,
                    'shipping_cost'         => $shippingCost,
                    'commission_percentage' => $commissionPercentage,
                    'commission_amount'     => $earningAmount,
                    'status'                => 'pending',
                ]);
            }
        }

        if ($order && $deliveryBoy) {
            try {
                $notificationService->orderAssigned($order, $deliveryBoy);
            } catch (\Exception $e) {
                Log::error('Failed to send delivery boy notification in order_assign', [
                    'order_id' => $order->id,
                    'delivery_boy_id' => $deliveryBoy->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => __('Order assigned successfully.')
        ]);
    }

    /**
     * P2-04-10. THE PERMISSION WAS FROM THE WRONG DOMAIN, and that single word did three things.
     *
     * This view's only content is ORDERS -- `Order::where('store_id', getCurrentStore())` -- and
     * it was gated on `isAbleTo('Manage Products')`. Measured, by driving it:
     *
     *   * a staff member holding `Manage Products` and NO order permission got **200** and read
     *     every order in the store -- while being REFUSED the order list itself (`GET order` =
     *     302), so the same principal could not open the list and could open a grid of the same
     *     rows. Intra-tenant, not cross-tenant: `getCurrentStore()` reads the authenticated
     *     user's own `current_store`, so the predicate is a real store scope. What breaks is
     *     privilege separation INSIDE one store, and what it discloses is customer names and
     *     mobile numbers;
     *   * a staff member holding `Manage Order` got **302 "Permission denied."** -- for the
     *     "Grid View" button that `resources/views/order/index.blade.php:18` renders for them,
     *     inside a page they reached legitimately;
     *   * the platform operator got **302** as well, because a bare `isAbleTo()` refuses them.
     *     There is no super-admin bypass in this codebase; the rule is recorded three times over.
     *
     * `mayUseOrderScreen('Manage Order')` is `index()`'s own gate -- this view is an alternative
     * presentation of the same list -- and it tests the operator first, through the one helper,
     * rather than adding a fourth spelling of that decision.
     *
     * THE WRONG NAME WAS A COPY-PASTE: `ProductController.php:4373` carries the identical shape
     * on a product surface, where `Manage Products` is the RIGHT name and the missing
     * operator-first limb is still wrong. Named here so the next pass fixes the source and not
     * only this copy.
     */
    public function order_grid_view(Request $request)
    {
        if ($this->mayUseOrderScreen('Manage Order')) {
            $orders = Order::where('store_id', getCurrentStore())->orderBy('created_at', 'desc')->paginate(16);
            
            return view('order.grid', compact('orders'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    private function getFrontThemeUrl($store)
    {
        $enable_storelink = Utility::GetValueByName('enable_storelink', $store->id);
        $enable_domain = Utility::GetValueByName('enable_domain', $store->id);
        $enable_subdomain = Utility::GetValueByName('enable_subdomain', $store->id);
        $domains = Utility::GetValueByName('domains', $store->id);
        $subdomain = Utility::GetValueByName('subdomain', $store->id);

        if ($enable_domain == 'on') {
            return 'https://' . $domains;
        } elseif ($enable_subdomain == 'on') {
            return 'https://' . $subdomain;
        } elseif ($enable_storelink) {
            return env('APP_URL');
        } else {
            return env('APP_URL');
        }
    }
}