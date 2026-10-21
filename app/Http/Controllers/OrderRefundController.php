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
     * THE REFUND STATE MACHINE.
     *
     * WHY THIS EXISTS. None of the four mutators below looked at the refund's
     * CURRENT state before writing the next one. Each simply assigned. The
     * vocabulary is not invented here -- it is read off the platform's own code:
     * 'Processing' is the state a request is created in
     * (AccountProfileController:967), and 'Accept', 'Cancel' and 'Refunded' are
     * the three the mutators here write and that OrderRefundDataTable:51 and the
     * refund views already branch on.
     *
     * THE DEFECTS THE MISSING GUARD CAUSED, each independently reachable by
     * replaying one authenticated POST:
     *
     *  1. CancelRefundStatus() does not merely set a status -- it ADDS the
     *     refunded quantities back onto the order's product_json and then
     *     recomputes product_price and final_price from the result
     *     (see that method). With no state check, a second POST ran the restore
     *     loop again: qty, product_price and final_price all grew, without
     *     bound, on every replay. That is an order total inflating on request.
     *
     *  2. updateRefundStatus() wrote 'Accept' from ANY state, so a refund
     *     already 'Refunded' could be moved back to 'Accept' -- un-refunding a
     *     paid-out refund in the records while the money stayed gone -- and a
     *     'Cancel'led request could be silently revived.
     *
     *  3. RefundAmonut() wrote 'Refunded' from any state, including from
     *     'Cancel', so a rejected request could still be paid out.
     *
     * THE LEGAL TRANSITIONS, derived from what each mutator actually does to the
     * order rather than from a guess about intent:
     *
     *   Processing -> Accept     the merchant agrees to the return
     *   Processing -> Cancel     the merchant rejects the request
     *   Accept     -> Refunded   the money is paid back
     *   Accept     -> Cancel     the merchant withdraws before paying out
     *   Refunded   -> (terminal) money has moved; nothing may follow
     *   Cancel     -> (terminal) the order has already been restored
     *
     * Both terminal states are terminal precisely because reaching them had an
     * irreversible side effect: 'Refunded' moved money, 'Cancel' rewrote the
     * order's totals.
     */
    public const STATE_PROCESSING = 'Processing';
    public const STATE_ACCEPT = 'Accept';
    public const STATE_CANCEL = 'Cancel';
    public const STATE_REFUNDED = 'Refunded';

    public const LEGAL_TRANSITIONS = [
        self::STATE_PROCESSING => [self::STATE_ACCEPT, self::STATE_CANCEL],
        self::STATE_ACCEPT => [self::STATE_REFUNDED, self::STATE_CANCEL],
        self::STATE_REFUNDED => [],
        self::STATE_CANCEL => [],
    ];

    /**
     * The states in which the goods are agreed to be coming back, and a stock
     * restoration is therefore defensible.
     */
    public const RESTOCKABLE_STATES = [self::STATE_ACCEPT, self::STATE_REFUNDED];

    /**
     * Refuse an illegal transition, naming both ends of it.
     *
     * An UNKNOWN current state is refused rather than waved through: the column
     * is a plain `string` with no database constraint
     * (2024_02_07_071800_create_order_refunds_table.php), so a row can hold
     * anything, and "I do not know what state this is in" is not a licence to
     * move money.
     *
     * @return array|null null when the move is legal; a [message, status] pair
     *                    otherwise.
     */
    protected function illegalTransition($refund, string $to): ?array
    {
        $from = (string) ($refund->refund_status ?? '');

        if (!array_key_exists($from, self::LEGAL_TRANSITIONS)) {
            \Log::warning('Refund transition refused: unrecognised current state', [
                'refund_id' => $refund->id ?? null,
                'from' => $from,
                'to' => $to,
            ]);

            return [
                'message' => 'This refund request is in a state this action cannot be applied to.',
                'status' => 422,
            ];
        }

        if ($from === $to) {
            // Not merely redundant. For Cancel this is the replay that inflated
            // the order total, and for Refunded it is a second payout record.
            return [
                'message' => 'This refund request is already in that state.',
                'status' => 422,
            ];
        }

        if (!in_array($to, self::LEGAL_TRANSITIONS[$from], true)) {
            \Log::warning('Refund transition refused: illegal transition', [
                'refund_id' => $refund->id ?? null,
                'from' => $from,
                'to' => $to,
            ]);

            return [
                'message' => 'This refund request cannot move from ' . $from . ' to ' . $to . '.',
                'status' => 422,
            ];
        }

        return null;
    }

    /** The atomic stock writer, shared with the order paths. */
    protected function stockService(): \App\Services\ProductStockService
    {
        return app(\App\Services\ProductStockService::class);
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
        // P1-SEC-27 (third review). The refund-policy tab of the Settings screen, which settingForm() refuses to a user without
        // `Manage Setting`; the route (`refund.settings`) wrote one row per posted name for any logged-in user of the store.
        if (! \App\Services\StoreSettingsAccess::permits(Auth::user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
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

        // STATE GUARD. Previously this wrote 'Accept' over whatever was there,
        // so a 'Refunded' request could be moved back to 'Accept' and a
        // 'Cancel'led one revived. See illegalTransition().
        if ($illegal = $this->illegalTransition($order, self::STATE_ACCEPT)) {
            return $this->refundDenied($illegal['message'], $illegal['status']);
        }

        $order->update(['refund_status' => self::STATE_ACCEPT]);

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

        // STATE GUARD, and here it is a MONEY guard, not hygiene. The loop below
        // adds the refunded quantities back onto the order and then recomputes
        // product_price and final_price from the result. With no state check a
        // replayed POST ran it a second time, so qty, product_price and
        // final_price grew on every replay, without bound. The guard makes
        // 'Cancel' reachable once, from 'Processing' or 'Accept' only.
        if ($illegal = $this->illegalTransition($order_refund, self::STATE_CANCEL)) {
            return $this->refundDenied($illegal['message'], $illegal['status']);
        }

        $productJson = json_decode($order->product_json, true);
        $productRefunds = json_decode($order_refund->product_refund_id, true);

        if (!is_array($productJson) || !is_array($productRefunds)) {
            // Both are free-text columns, so either can be unparseable. The old
            // code walked the result of json_decode() unconditionally, which
            // made a foreach over null the failure mode.
            return $this->refundDenied('This refund request has no restorable product data.', 422);
        }

        // ATOMIC with the status write. Previously the order's totals were saved
        // and the refund status was updated as two separate statements: a
        // failure between them left the order's quantities and final_price
        // already restored while the refund still read 'Processing' or
        // 'Accept' -- so the next POST restored them a second time. Making the
        // pair atomic is what lets the state guard above actually hold.
        try {
            \DB::transaction(function () use ($order, $order_refund, $productJson, $productRefunds, $request) {
                $total_product_price = 0.0;

                foreach ($productJson as $key => &$product) {
                    $quantitie = $productRefunds[$key]['quantity'] ?? ($productRefunds[$key]['qty'] ?? 0);
                    $old_qty = $product['qty'];

                    $product['qty'] = $product['qty'] + $quantitie;

                    if ($old_qty != 0) {
                        $product['final_price'] = ($product['final_price'] * $product['qty']) / $old_qty;
                    } else {
                        $product['final_price'] = $request->subtotal;
                    }

                    $total_product_price = $total_product_price + $product['final_price'];
                }
                unset($product);

                $grand_total_price = ($total_product_price + $order->delivery_price + $order->tax_price) - $order->coupon_price;
                $order->final_price = $grand_total_price;
                $order->product_price = $total_product_price;
                $order->product_json = json_encode($productJson);
                $order->save();

                $order_refund->update(['refund_status' => self::STATE_CANCEL]);
            });
        } catch (\Throwable $e) {
            \Log::error('Refund cancellation rolled back', [
                'refund_id' => $order_refund->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $this->refundDenied('The refund could not be cancelled. Nothing was changed.', 500);
        }

        return response()->json([
            'status' => $order_refund->refund_status,
            'message' => 'Request cancel successfully!'
        ]);
    }

    /**
     * Marker note_type written when a refund's stock has been given back.
     *
     * WHY A NOTE IS THE MARKER, and what the real fix is.
     *
     * Replaying this endpoint added stock again, every time: there was nothing
     * recording that a restock had happened. `order_refunds` has no column for
     * it -- see
     * database/migrations/2024_02_07_071800_create_order_refunds_table.php,
     * which has id, order_id, refund_status, refund_reason,
     * custom_refund_reason, attachments, product_refund_id,
     * product_refund_price, refund_amount, store_id, timestamps -- and adding
     * one means a migration, which belongs to another agent in this phase. So
     * the marker is an `order_notes` row, which is durable, uses only columns
     * that exist and are in OrderNote::$fillable, and has the side benefit of
     * being visible to the merchant on the order.
     *
     * The state can't carry the marker instead: RESTOCKABLE_STATES is
     * ['Accept', 'Refunded'] and 'Accept' must stay reachable by RefundAmonut(),
     * so moving the status on restock would break the payout step.
     *
     * LIMITATION, stated rather than implied: a merchant who deletes that note
     * row could restock a second time. The durable fix is a dedicated column and
     * is recorded as a cross-agent dependency. This closes the replay; it does
     * not make the record tamper-proof.
     */
    public const STOCK_RESTORED_NOTE_TYPE = 'refund_stock_restored';

    public function RefundStock(Request $request)
    {
        if (!$this->canManageRefunds()) {
            return $this->refundDenied('Permission denied.', 403);
        }

        $order_refund = $this->findRefundInCurrentStore($request->refundId);

        if (!$order_refund) {
            return $this->refundDenied('Refund request not found.', 404);
        }

        // STATE GUARD. Stock may only come back once the return is agreed. With
        // no check at all this ran against a 'Processing' request -- i.e. before
        // the merchant had accepted anything -- and against a 'Cancel'led one,
        // whose quantities CancelRefundStatus() has already put back onto the
        // order, so the goods were counted twice.
        if (!in_array((string) $order_refund->refund_status, self::RESTOCKABLE_STATES, true)) {
            return $this->refundDenied(
                'Stock can only be restored for a refund that has been accepted.',
                422
            );
        }

        $storeId = getCurrentStore();

        /*
         * The order is needed for TWO reasons, and it is resolved inside the
         * caller's tenant like everything else here.
         *
         *  1. Its `product_json` is where the variant id lives -- see
         *     resolveVariantIdsFromOrder() below.
         *  2. The idempotency marker is an order note, so it needs an order.
         */
        $order = Order::where('id', $order_refund->order_id)
            ->where('store_id', $storeId)
            ->first();

        if (!$order) {
            return $this->refundDenied('The order this refund belongs to was not found.', 404);
        }

        $product_refund_id_array = json_decode($order_refund->product_refund_id, true);

        if (!is_array($product_refund_id_array)) {
            return $this->refundDenied('Refund request has no refundable products.', 422);
        }

        /*
         * IDEMPOTENCY, in two layers, because replaying this added stock again
         * EVERY time and that is an unbounded inventory inflation from one
         * authenticated POST held down.
         *
         *  - The LOCK stops two concurrent requests both passing the
         *    already-restored check. Without it the check is a classic
         *    check-then-act.
         *  - The NOTE (written inside the transaction below) stops a sequential
         *    replay, durably. See STOCK_RESTORED_NOTE_TYPE.
         */
        $lock = \Illuminate\Support\Facades\Cache::lock(
            'refund_restock_' . $order_refund->id,
            10
        );

        if (!$lock->get()) {
            return $this->refundDenied('This refund request is already being restocked.', 409);
        }

        try {
            if ($this->stockAlreadyRestored($order_refund, $order)) {
                return $this->refundDenied(
                    'Stock for this refund request has already been restored.',
                    422
                );
            }

            $variantIds = $this->resolveVariantIdsFromOrder($order);

            $restored = 0;

            \DB::transaction(function () use (
                $product_refund_id_array,
                $order_refund,
                $order,
                $storeId,
                $variantIds,
                &$restored
            ) {
                foreach ($product_refund_id_array as $product_refund) {
                    $product_id = $product_refund['product_refund_id'] ?? null;
                    $quantity = $product_refund['quantity'] ?? null;

                    if ($product_id === null || $quantity === null) {
                        continue;
                    }

                    // A refund quantity is read out of a JSON blob, so it is
                    // neither typed nor validated. A negative value here would
                    // have SUBTRACTED stock through a restore endpoint, and a
                    // non-numeric one would have been coerced to 0 silently.
                    if (!is_numeric($quantity) || (int) $quantity <= 0) {
                        \Log::warning('Refund restock skipped a line with an unusable quantity', [
                            'refund_id' => $order_refund->id,
                            'product_id' => $product_id,
                        ]);

                        continue;
                    }

                    $quantity = (int) $quantity;

                    // product_refund_id comes out of a JSON blob on the refund
                    // row, so it is attacker-influenced input, not a validated
                    // foreign key. Without the store predicate this loop wrote
                    // product_stock onto any product in any store. The null
                    // guard matters too: Product::find() returned null for a
                    // deleted product and the next line dereferenced it.
                    $product = Product::where('id', $product_id)
                        ->where('store_id', $storeId)
                        ->first();

                    if (!$product) {
                        continue;
                    }

                    if ($product->variant_product == 0) {
                        // ATOMIC. This was `$final_stock = $product->product_stock
                        // + $quantity; $product->product_stock = $final_stock;
                        // $product->save();` -- a read-modify-write, i.e. the same
                        // lost update ProductStockService was built to remove from
                        // the order paths: two concurrent restores both wrote
                        // `stock + qty` and the second overwrote the first, so
                        // returned goods vanished from inventory.
                        $this->stockService()->restoreProductStock($product->id, $quantity);
                        $restored++;

                        continue;
                    }

                    /*
                     * THE WRONG-ROW WRITE, now fixed.
                     *
                     * This was `ProductVariant::find($product->id)` -- a VARIANT
                     * looked up by PRODUCT id. Those are different key spaces, so
                     * it restored stock to whichever variant happened to share the
                     * product's id, or to none. A silent wrong-row inventory write.
                     *
                     * The previous phase reported it as uncorrectable because the
                     * refund payload records no variant id, which is true: the JSON
                     * written by AccountProfileController.php:971-981 carries only
                     * product_refund_id, return_price and quantity. But the variant
                     * id is not missing from the SYSTEM -- it is on the ORDER, in
                     * every writer's product_json line
                     * (OrderController.php:213, PaymentService.php:2321), which is
                     * the same place the refund feature already resolves its lines
                     * from: AccountProfileController.php:947 matches a refund line
                     * to an order line by product_id alone, and
                     * CancelRefundStatus() (:393-419) walks product_json the same
                     * way. So resolving the variant from the order by product_id is
                     * exactly as determinate as the refund feature already is --
                     * it adds no new assumption.
                     *
                     * Where it is NOT determinate -- two order lines for the same
                     * product with different variants -- resolveVariantIdsFromOrder()
                     * returns null rather than picking one, and the line is skipped
                     * with a log. Guessing there would be inventing data.
                     */
                    $variantId = $variantIds[(string) $product_id] ?? null;

                    if ($variantId === null) {
                        \Log::error('Refund restock could not resolve which variant was returned; line skipped', [
                            'refund_id' => $order_refund->id,
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'note' => 'The order product_json carries no single variant_id for this '
                                . 'product (absent, zero, or several differing ones). Nothing is '
                                . 'guessed.',
                        ]);

                        continue;
                    }

                    // Tenant- and parent-checked, unlike find(): the row must be
                    // this product's variant and this store's.
                    $variantProduct = ProductVariant::where('id', $variantId)
                        ->where('product_id', $product->id)
                        ->where('store_id', $storeId)
                        ->first();

                    if (!$variantProduct) {
                        \Log::error('Refund restock resolved a variant id that is not this product\'s; line skipped', [
                            'refund_id' => $order_refund->id,
                            'product_id' => $product->id,
                            'variant_id' => $variantId,
                        ]);

                        continue;
                    }

                    $this->stockService()->restoreVariantStock($variantProduct->id, $quantity);
                    $restored++;
                }

                /*
                 * THE MARKER, written LAST and inside the same transaction as the
                 * restores, so the two cannot disagree: either the stock came back
                 * and the refund is marked restored, or neither happened. Written
                 * even when every line was skipped, because a replay of a refund
                 * whose lines are all unresolvable must still not be retried into
                 * a partial double-restore later.
                 */
                \App\Models\OrderNote::create([
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'status' => 'Stock Manage',
                    // The refund id goes in note_type so the replay check is an
                    // exact equality rather than a LIKE on prose. A prefix match
                    // on the message would confuse refund #5 with #50.
                    'note_type' => self::stockRestoredNoteType($order_refund->id),
                    'notes' => 'Stock restored for refund request #' . $order_refund->id
                        . ' (' . $restored . ' line(s)).',
                    'store_id' => $storeId,
                ]);
            });
        } catch (\Throwable $e) {
            \Log::error('Refund restock rolled back; no stock was changed', [
                'refund_id' => $order_refund->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $this->refundDenied('The stock could not be restored. Nothing was changed.', 500);
        } finally {
            $lock->release();
        }

        return response()->json([
            'message' => 'Manage Stock updated successfully!'
        ]);
    }

    /**
     * Has this refund's stock already been given back?
     *
     * Reads the durable marker note. See STOCK_RESTORED_NOTE_TYPE for why the
     * marker is a note and what the real fix is.
     */
    protected function stockAlreadyRestored(OrderRefund $order_refund, Order $order): bool
    {
        return \App\Models\OrderNote::where('order_id', $order->id)
            ->where('note_type', self::stockRestoredNoteType($order_refund->id))
            ->exists();
    }

    /** The exact marker note_type for one refund request. */
    public static function stockRestoredNoteType($refundId): string
    {
        return self::STOCK_RESTORED_NOTE_TYPE . ':' . $refundId;
    }

    /**
     * product_id => the one variant_id that product was ordered with, or null.
     *
     * Returns null for a product whose variant cannot be determined without
     * guessing, which is any of:
     *
     *   - no `variant_id` on the line, or a zero one (a non-variant line);
     *   - more than one line for the product carrying DIFFERENT variant ids.
     *
     * Keys are strings so a JSON id of 7 and an int 7 resolve the same.
     *
     * @return array<string, int|null>
     */
    protected function resolveVariantIdsFromOrder(Order $order): array
    {
        $lines = json_decode((string) $order->product_json, true);

        if (!is_array($lines)) {
            return [];
        }

        $seen = [];

        foreach ($lines as $line) {
            if (!is_array($line) || !isset($line['product_id'])) {
                continue;
            }

            $productId = (string) $line['product_id'];
            $variantId = $line['variant_id'] ?? null;
            $variantId = is_numeric($variantId) ? (int) $variantId : null;

            if ($variantId !== null && $variantId <= 0) {
                $variantId = null;
            }

            if (!array_key_exists($productId, $seen)) {
                $seen[$productId] = $variantId;

                continue;
            }

            // A second line for the same product. Agreeing is fine; disagreeing
            // makes the choice undecidable and must not be guessed.
            if ($seen[$productId] !== $variantId) {
                $seen[$productId] = null;
            }
        }

        return $seen;
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

        $currentStatus = (string) $order_refund->refund_status;

        // TERMINAL STATES. A 'Refunded' request paid out already; a 'Cancel'led
        // one was REJECTED and CancelRefundStatus() has already restored its
        // quantities to the order. Previously 'Refunded' was written over either
        // of them, so a rejected refund could still be paid and a paid one could
        // be paid twice.
        if (array_key_exists($currentStatus, self::LEGAL_TRANSITIONS)
            && self::LEGAL_TRANSITIONS[$currentStatus] === []
        ) {
            return $this->refundDenied(
                'This refund request is already ' . $currentStatus . ' and cannot be changed.',
                422
            );
        }

        // WIRING App\Services\RefundIntegrityService, which already existed,
        // already had tests (tests/Feature/Commerce/RefundIntegrityTest.php) and
        // had NO production caller anywhere -- `grep -rn RefundIntegrityService
        // app/` matched only its own definition. It was built and never
        // connected, so none of what it establishes was actually in force. This
        // connects it; it does NOT reimplement it, and no second refund path is
        // introduced.
        $refundIntegrity = app(\App\Services\RefundIntegrityService::class);

        // SERVER-SIDE AMOUNT. `refund_amount` went from the request straight
        // into a financial column, compared against nothing, so a refund larger
        // than the order could be stored. The ceiling is the order's own
        // persisted total -- authoritative, server-side -- and the service also
        // refuses a signed request, which the controller's own
        // preg_replace('/[^0-9.]/', '') would have turned from '-500' into a
        // positive 500.
        $capped = $refundIntegrity->cappedRefundAmount(
            $request->refund_amount,
            $order->final_price,
            __METHOD__
        );

        $amount = $capped['amount'];

        if ($amount <= 0) {
            return $this->refundDenied(
                'A refund amount must be a positive number no greater than the order total.',
                422
            );
        }

        // THE HONEST STATUS. There is no refund driver for any gateway anywhere
        // in this platform, so for an online payment no money moves when this
        // endpoint is called; the service returns the existing 'Accept' --
        // approved, not settled -- instead of asserting a settlement that did
        // not happen. For an offline type the merchant hands the cash back
        // themselves and 'Refunded' stays true and unchanged.
        $targetStatus = $refundIntegrity->resolveRefundStatus(
            $order->payment_type,
            $amount,
            __METHOD__
        );

        // Only guard a real transition. When the honest target equals the
        // current state -- the online-gateway refund already sitting at 'Accept'
        // -- there is no transition to police and the amount is still recorded,
        // so the merchant's figure is not lost.
        if ($targetStatus !== $currentStatus) {
            if ($illegal = $this->illegalTransition($order_refund, $targetStatus)) {
                return $this->refundDenied($illegal['message'], $illegal['status']);
            }
        }

        // ATOMIC. The delivery-price write and the refund record were two
        // separate saves: a failure between them left shipping zeroed on the
        // order with no refund recorded against it.
        try {
            \DB::transaction(function () use ($order, $order_refund, $request, $amount, $targetStatus) {
                if ($request->isTrendingChecked == 'true') {
                    // Behaviour PRESERVED, and reported: this zeroes the order's
                    // delivery_price on a client-supplied flag and does NOT
                    // recompute final_price afterwards, so the order's total
                    // stops agreeing with its own components. Recomputing it
                    // would change what the merchant is shown and what a wallet
                    // is credited, which is an owner decision, not a fix.
                    $order['delivery_price'] = 0.0;
                    $order->save();
                }

                $order_refund->update([
                    'refund_status' => $targetStatus,
                    'refund_amount' => $amount
                ]);
            });
        } catch (\Throwable $e) {
            \Log::error('Refund payout record rolled back', [
                'refund_id' => $order_refund->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $this->refundDenied('The refund could not be recorded. Nothing was changed.', 500);
        }

        // The message now reports what was actually stored. Silently storing a
        // capped figure, or reporting a settlement that did not occur, is the
        // defect -- so both are surfaced to the merchant rather than logged and
        // hidden.
        $message = $targetStatus === self::STATE_REFUNDED
            ? __('Refund Amount successfully!')
            : __('Refund approved. No gateway refund was issued, so the money has not been returned yet.');

        if ($capped['capped']) {
            $message .= ' ' . __('The amount was reduced to the order total.');
        }

        return response()->json([
            'status' => $targetStatus,
            'amount' => $amount,
            'capped' => $capped['capped'],
            'message' => $message
        ]);
    }
}
