<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\OrderRefundSetting;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use App\DataTables\OrderRefundDataTable;

class OrderRefundController extends Controller
{
    /**
     * R4-04. Shared authorization + tenant scoping for the refund mutators.
     *
     * index() below already had the correct shape -- an isAbleTo() check plus
     * `where('store_id', getCurrentStore())` -- while updateRefundStatus,
     * CancelRefundStatus, RefundStock, updateFinalPrice and RefundAmonut each
     * did a bare OrderRefund::findOrFail($request->refund_id) with no permission
     * check and no store predicate. Any authenticated merchant could accept,
     * cancel, re-stock or mark-refunded another store's refund request by
     * guessing an id, and RefundAmonut and CancelRefundStatus also WRITE to the
     * underlying order (final_price, product_json, delivery_price).
     *
     * `Manage Order Refund Request` is the permission index() uses, and unlike
     * the finance names it IS created by Utility::addNewData() (two occurrences,
     * verified), so gating on it is safe on a database built from source.
     *
     * The super-admin limb is the same shape as OrderPolicy::allow() and the
     * `finance` gate: it keeps the platform operator working without depending
     * on a seeded row, which is the defect class that locked the finance surface
     * out for everybody.
     */
    protected function canManageRefunds(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if ($user->type === \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            return true;
        }

        return $user->isAbleTo('Manage Order Refund Request');
    }

    /**
     * Resolve a refund request that genuinely belongs to the caller's tenant.
     *
     * Returns null rather than throwing so each caller can answer in its own
     * shape -- these endpoints are called by JS and must stay JSON.
     */
    protected function findRefundInCurrentStore($refundId): ?OrderRefund
    {
        $storeId = getCurrentStore();

        if (empty($storeId) || empty($refundId)) {
            return null;
        }

        return OrderRefund::where('id', $refundId)
            ->where('store_id', $storeId)
            ->first();
    }

    /**
     * Resolve an order inside the caller's tenant.
     */
    protected function findOrderInCurrentStore($orderId): ?Order
    {
        $storeId = getCurrentStore();

        if (empty($storeId) || empty($orderId)) {
            return null;
        }

        return Order::where('id', $orderId)
            ->where('store_id', $storeId)
            ->first();
    }

    /**
     * A single JSON refusal shape for these endpoints.
     *
     * 403 for "not allowed", 404 for "not in your tenant" -- deliberately NOT
     * 403 for the second case, so the response does not confirm that an id
     * exists in some other store.
     */
    protected function refundDenied(string $message, int $status)
    {
        return response()->json(['message' => __($message)], $status);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function index(OrderRefundDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Order Refund Request'))
        {
            $refund_requests = OrderRefund::where('store_id', getCurrentStore())->get();
            return  $dataTable->render('order.refund_request', compact('refund_requests'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {
            $id = Crypt::decrypt($id);
            $order = Order::order_detail($id);
            $refund_requests = OrderRefund::where('order_id', $order['id'])->first();

            $product_refund_id_json = $refund_requests["product_refund_id"];
            $product_refund_id_array = json_decode($product_refund_id_json, true);

            $productRefundData = []; // Initialize an array to store product_refund data

            foreach ($product_refund_id_array as $item) {
                if (isset($item['product_refund_id'])) {
                    $productRefundId = $item['product_refund_id'];
                    $quantity = $item['quantity'];
                    $returnPrice = $item['return_price'];

                    // Retrieve product data based on product_refund_id
                    $product = Product::find($productRefundId);

                    $productName = $product->name; // Product name by default

                    // Check if the product has a variant
                    if ($product->variant_product == '1') {
                        $product = ProductVariant::where('id',$product->id)->first();
                        $variantName = $product->variant; // Variant name if it's a variant product
                        $productName = "$productName - $variantName";
                    }

                    // Store the data in an associative array with product_refund_id as the key
                    $productRefundData[$productRefundId] = [
                        'product_name' => $productName,
                        'quantity' => $quantity,
                        'return_price' => $returnPrice,
                    ];
                }
            }

            $plan = Plan::find(Auth::user()->plan_id);
            $store = Store::find(getCurrentStore());
            $RefundStatus = OrderRefundSetting::where('store_id',$store->id)
                    ->pluck('is_active', 'name')->toArray();
            return view('order.refund_details', compact('order', 'refund_requests', 'RefundStatus', 'plan', 'productRefundData'));
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Something Went wrong.'));
        }
    }

    public function updateStatus(Request $request)
    {
        session()->put(['setting_tab' => 'refund_setting']);
        $post = $request->all();
        unset($post['_token']);
        $usr = Auth::user();
        if ($usr) {
            $id = $usr->id;
            foreach ($post as $key => $value) {
                $order_refunds = OrderRefundSetting::where('store_id', getCurrentStore())->where('user_id',$usr->id)->where('name', $key)->first();
                if ($order_refunds) {
                    $order_refunds->is_active = $value;
                    $order_refunds->user_id = $usr->id;
                    $order_refunds->store_id = getCurrentStore();
                    $order_refunds->save();
                    $status=true; 
                }else{
                    $status = OrderRefundSetting::updateOrCreate(
                        [
                            'name' => $key,
                            'user_id' => $id,
                            'is_active' => $value,
                            'store_id' => getCurrentStore(),
                        ],
                    );
                }
            }
            if (isset($status)) {
                return redirect()->back()->with('success', __('Refund Setting successfully updated!'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

    }

    public function updateRefundStatus(Request $request)
    {
        if (!$this->canManageRefunds()) {
            return $this->refundDenied('Permission denied.', 403);
        }

        $order = $this->findRefundInCurrentStore($request->refund_id);

        if (!$order) {
            return $this->refundDenied('Refund request not found.', 404);
        }

        $order->update(['refund_status' => 'Accept']);

        return response()->json([
            'status' => $order->refund_status,
            'message' => 'Request accept successfully!'
        ]);
    }

    public function CancelRefundStatus(Request $request)
    {
        if (!$this->canManageRefunds()) {
            return $this->refundDenied('Permission denied.', 403);
        }

        $order_refund = $this->findRefundInCurrentStore($request->refund_id);

        if (!$order_refund) {
            return $this->refundDenied('Refund request not found.', 404);
        }

        // Scoped independently: this method WRITES final_price, product_price and
        // product_json back onto the order, so the order's own tenancy is checked
        // rather than inferred from the refund row.
        $order = $this->findOrderInCurrentStore($order_refund->order_id);

        if (!$order) {
            return $this->refundDenied('Order not found.', 404);
        }

        $productJson = json_decode($order->product_json, true);
        $productRefunds = json_decode($order_refund->product_refund_id, true);

        $total_product_price = 0.0;
        foreach ($productJson as $key => &$product)
        {
            $quantitie = $productRefunds[$key]['quantity'] ?? $productRefunds[$key]['qty'];
            $old_qty = $product['qty'];

            $product['qty'] = $product['qty'] + $quantitie;

            if ($old_qty != 0) {
                $product['final_price'] = ($product['final_price'] * $product['qty']) / $old_qty;
            } else {
                $product['final_price'] = $request->subtotal;
            }

            $total_product_price = $total_product_price + $product['final_price'];
        }
        $grand_total_price = ($total_product_price + $order->delivery_price + $order->tax_price) - $order->coupon_price;
        $order->final_price = $grand_total_price;
        $order->product_price = $total_product_price;
        $order->product_json = json_encode($productJson);
        $order->save();

        $order_refund->update(['refund_status' => 'Cancel']);

        return response()->json([
            'status' => $order_refund->refund_status,
            'message' => 'Request cancel successfully!'
        ]);
    }

    public function RefundStock(Request $request)
    {
        if (!$this->canManageRefunds()) {
            return $this->refundDenied('Permission denied.', 403);
        }

        $order_refund = $this->findRefundInCurrentStore($request->refundId);

        if (!$order_refund) {
            return $this->refundDenied('Refund request not found.', 404);
        }

        $storeId = getCurrentStore();

        $product_refund_id_array = json_decode($order_refund->product_refund_id, true);

        if (!is_array($product_refund_id_array)) {
            return $this->refundDenied('Refund request has no refundable products.', 422);
        }

        foreach ($product_refund_id_array as $product_refund) {
            $product_id = $product_refund['product_refund_id'] ?? null;
            $quantity = $product_refund['quantity'] ?? null;

            if ($product_id === null || $quantity === null) {
                continue;
            }

            // product_refund_id comes out of a JSON blob on the refund row, so it
            // is attacker-influenced input, not a validated foreign key. Without
            // the store predicate this loop wrote product_stock onto any product
            // in any store. The null guard matters too: Product::find() returned
            // null for a deleted product and the next line dereferenced it.
            $product = Product::where('id', $product_id)
                ->where('store_id', $storeId)
                ->first();

            if (!$product) {
                continue;
            }

            if ($product->variant_product == 0) {
                $final_stock = $product->product_stock + $quantity;
                $product->product_stock = $final_stock;
                $product->save();
            } else {
                $variantProduct = ProductVariant::find($product->id);
                $newStock = $variantProduct->stock + $quantity;
                $variantProduct->stock = $newStock;
                $variantProduct->save();
            }
        }

        return response()->json([
            'message' => 'Manage Stock updated successfully!'
        ]);
    }

    public function updateFinalPrice(Request $request, $id)
    {
        if (!$this->canManageRefunds()) {
            return $this->refundDenied('Permission denied.', 403);
        }

        $order = $this->findOrderInCurrentStore($id);
        $order_refund = $this->findRefundInCurrentStore($request->refundId);

        if (!$order) {
            return $this->refundDenied('Order not found.', 404);
        }

        if (!$order_refund) {
            return $this->refundDenied('Refund request not found.', 404);
        }

        $refundAmount = $request->finalPrice;
        return response()->json([
            'refund_amount' => currency_format_with_sym( ($refundAmount ?? 0), getCurrentStore()) ?? SetNumberFormat($refundAmount),
            'message' => 'Add shipping price successfully!'
        ], 200);
    }

    public function RefundAmonut(Request $request, $id)
    {
        if (!$this->canManageRefunds()) {
            return $this->refundDenied('Permission denied.', 403);
        }

        $order = $this->findOrderInCurrentStore($id);
        $order_refund = $this->findRefundInCurrentStore($request->refundId);

        if (!$order) {
            return $this->refundDenied('Order not found.', 404);
        }

        if (!$order_refund) {
            return $this->refundDenied('Refund request not found.', 404);
        }

        if($request->isTrendingChecked == 'true')
        {
            $order['delivery_price'] = 0.0;
            $order->save();
        }
        $amount = (float) preg_replace('/[^0-9.]/', '', $request->refund_amount);

        $order_refund->update([
            'refund_status' => 'Refunded',
            'refund_amount' => $amount
        ]);

        return response()->json([
            'status' => 'Refunded',
            'message' => 'Refund Amount successfully!'
        ]);
    }
}
