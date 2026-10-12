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

        foreach ($request->products as $item) {
            $product = \App\Models\Product::find($item['product_id']);
            if (!$product) {
                throw new \Exception("المنتج غير موجود: ID {$item['product_id']}");
            }

            $qty = (int) $item['quantity'];
            $lineTotal = $item['price'] * $qty;
            $totalProductPrice += $lineTotal;

            if (!empty($item['variant_id']) && $item['variant_id'] > 0) {
                $variant = \App\Models\ProductVariant::find($item['variant_id']);
                if (!$variant) {
                    throw new \Exception("الخيار/المتغير غير موجود: ID {$item['variant_id']}");
                }

                if ($variant->stock < $qty) {
                    throw new \Exception("المخزون غير كافٍ للمتغير: {$variant->name}. المتاح: {$variant->stock}، المطلوب: {$qty}");
                }

                $variant->stock -= $qty;
                $variant->save();

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
                if ($product->product_stock < $qty) {
                    throw new \Exception("المخزون غير كافٍ للمنتج: {$product->name}. المتاح: {$product->product_stock}، المطلوب: {$qty}");
                }

                $product->product_stock -= $qty;
                $product->save();

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
            'final_price' => $request->final_price,
            'payment_type' => $request->payment_type,
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

        \App\Models\OrderNote::create([
            'customer_id' => $request->customer_id,
            'order_id' => $order->id,
            'status' => 'Order created',
            'changeble_status' => 'created',
            'note' => 'Order created manually by admin',
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
        $coupon = Coupon::whereRaw('BINARY `coupon_code` = ?', [$request->coupon_code])->where('store_id', $store->id)->first();
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
            if ($status == 2) {
                $product_json = json_decode($order->product_json, true);
                foreach ($product_json as $key => $product) {
                    $product_id = $product['product_id'];
                    $variant_id = $product['variant_id'];
                    if ($variant_id == 0 || empty($variant_id)) {
                        $product = Product::find($product_id);
                        if (!empty($product)) {
                            $product->product_stock += $product['qty'];
                            $product->save();
                        }
                    } else {
                        $productVariant = ProductVariant::where('product_id', $product_id)->where('id', $variant_id)->first();
                        if (!empty($productVariant)) {
                            $productVariant->stock += $product['qty'];
                            $productVariant->save();
                        }
                    }
                }
            }
            $order->return_price = $order->final_price;
            $order->return_status = $status;
            $order->save();
        }

        $return['message'] = __('Return request approve successfully');
        if ($status == 3) {
            $return['message'] = __('Return request reject successfully');
        }
        return response()->json($return);
    }

    public function order_status_change(Request $request)
    {

        $data['order_id'] = $request->id;
        $data['order_status'] = $request->delivered;
        $responce = Order::order_status_change($data);
        $order = Order::order_detail($data['order_id']);
        $d_order = Order::find($data['order_id']);
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
            'order_id' => $request->id,
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
                $order = Order::order_detail($request->id);
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

        $data['order_id'] = $request->order_id;
        $data['order_status'] = $request->order_status;

        $responce = Order::order_status_change($data);
        $order_detail = Order::order_detail($request->order_id);

        foreach ($order_detail['product'] as $item) {
            if ($item['variant_id'] == 0) {
                $product = Product::where('id', $item['product_id'])->first();
                $prdduct_stock = $product->product_stock + $item['qty'];
                $product->product_stock = $prdduct_stock;
                $product->save();
            } else {
                $product = ProductVariant::where('id', $item['variant_id'])->first();
                $prdduct_stock = $product->stock + $item['qty'];
                $product->stock = $prdduct_stock;
                $product->save();
            }
        }
        if ($responce['status'] == 'success') {
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

    public function fileExport()
    {
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