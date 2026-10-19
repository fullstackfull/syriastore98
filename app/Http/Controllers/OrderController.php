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

public function store(Request $request)
{
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

    public function create(Request $request)
{
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
        if (auth()->user() && auth()->user()->isAbleTo('Manage Order')) {
            return $dataTable->render('order.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function show(Order $order, $id)
    {

        $id = Crypt::decrypt($id);
        $order = Order::order_detail($id);
        $orders = Order::find($id);
        return view('order.order_show', compact('order', 'orders'));
    }

    public function order_view(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Show Order')) {
            try {
                $id = Crypt::decrypt($id);
                $order = Order::order_detail($id);
                $store_id = $store = getStoreById(getCurrentStore());
                $order_notes = OrderNote::where('order_id', $id)->where('store_id', getCurrentStore())->get();
                $deliveryboys = DeliveryBoy::where('store_id', $store_id->id)->pluck('name', 'id')->prepend('Assign to delivery boy', "");
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

    public function orderComplete(Request $request)
    {
        $store = getStore($request->route('storeSlug'));
        if (!$store) {
            abort(404);
        }
        
        $order_id = (Session::get('khalti_order_id'));

        if (session('data')) {
            $order_id = session('data');
            $order = Order::where('product_order_id', $order_id)->first();
        } elseif ((isset($request['data']['order_id']) && !empty($request['data']['order_id'])) || (isset($request->responce['data']['order_id']) && ($request->responce['data']['order_id'])) || (isset($order_id) && !empty($order_id))) {
            if (!empty($request['data']['order_id'])) {
                $order_id = $request['data']['order_id'];
                $order = Order::find($order_id);
            } elseif (!empty($request->responce['data']['order_id'])) {
                $order_id = $request->responce['data']['order_id'];
                $order = Order::find($order_id);
            } else {
                $order = Order::where('product_order_id', $order_id)->first();
            }
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
            return view('order.shippinglabel', compact('order', 'product_sum', 'settings'));
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
            return view('order.receipt', compact('order'));
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

        foreach ($lines as $line) {
            if (!is_array($line)) {
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
         * COD SETTLES HERE -- the other half of the cash-on-delivery state
         * machine. See App\Services\PaymentVerificationService
         * COD_SETTLEMENT_ON_DELIVERY for the full argument.
         *
         * THE DEFECT. PaymentService::process() routes 'cod' straight to
         * getProductStatus() (:315) and the order writer recorded it 'Paid'
         * immediately, so a cash-on-delivery order counted as paid before the
         * courier had collected anything. An order placed and never delivered
         * stayed Paid forever, and a CANCELLATION then had to UNDO a payment
         * that never happened -- which is literally what
         * Order::order_status_change() does, writing payment_status = 'Unpaid'
         * on cancel (app/Models/Order.php:550) with a comment calling it the
         * missing line needed to refund the amount. There is nothing to refund.
         * An Observer reacts to that write by adjusting the wallet, so the
         * premature Paid reaches the financial records and is then walked back.
         *
         * THE MISSING HALF. Nothing ever moved a COD order from unpaid to paid
         * on delivery, because nothing had to: it was born Paid. With the
         * correct machine the transition has to exist, and this is the one place
         * a merchant declares an order delivered.
         *
         * INERT BY DEFAULT. codShouldSettleOnDelivery() returns false unless the
         * owner has opted in to `services.cod.settlement = 'delivery'`, so for
         * every existing deployment this block does nothing at all and COD
         * behaviour is byte-for-byte what it was. That is deliberate: when an
         * order counts as paid decides when a wallet is credited, which is the
         * owner's business decision and not a fix to impose.
         *
         * IDEMPOTENT. The guard requires the order not to be Paid already, so a
         * replayed 'delivered' status change settles once, not twice.
         *
         * The save is kept narrow -- only payment_status -- and the same
         * OrderPaymentStatus event the model's own transitions fire is fired
         * here too, so the wallet and notification listeners see this change the
         * way they see every other payment-status change rather than being
         * bypassed.
         */
        if (($responce['status'] ?? null) === 'success'
            && strtolower(trim((string) $data['order_status'])) === 'delivered'
            && $this->paymentVerifier()->codShouldSettleOnDelivery($d_order)
        ) {
            $d_order->payment_status = \App\Services\PaymentVerificationService::STATUS_PAID;
            $d_order->save();

            event(new \App\Events\OrderPaymentStatus($d_order));

            \Log::info('COD order settled on delivery', [
                'order_id' => $d_order->id,
                'store_id' => $d_order->store_id,
                'payment_type' => $d_order->payment_type,
            ]);
        }
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
        $isOperator = $user && strtolower(trim((string) $user->type)) === 'super admin';

        if (!$user || (!$isOperator && !$user->isAbleTo('Manage Order'))) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (empty(getCurrentStore())) {
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

        if ($order->deliveryboy_id != $request->delivery_boy) {
            \App\Models\DeliveryEarning::where('order_id', $order->id)->delete();
        }

        $order->deliveryboy_id = $request->delivery_boy;
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

    public function order_grid_view(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Products')) {
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