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
     * WHO DID THIS. GAP-140 / P4-F086's sibling, P4-F087.
     *
     * WHAT WAS MISSING. Measured at HEAD: the only actor identifier anywhere in this controller
     * was `user_id` on the SETTINGS row written by `updateStatus()`. None of the mutators recorded
     * who accepted a return, who rejected one (which rewrites the order's `final_price`,
     * `product_price` and `product_json`), who put the goods back on the shelf, or who marked the
     * money paid out. A merchant with several staff could not answer "who approved this refund",
     * and neither could the platform operator after the fact.
     *
     * `AuditLog::record()` IS THE MECHANISM THIS APPLICATION ALREADY USES for exactly this -- 18
     * call sites before this change, across the finance, plan, store, user and theme writers -- so
     * no column, no table and no migration is added. It reads the `web` guard EXPLICITLY (a
     * `Customer` or a `DeliveryBoy` on the default guard writes `null` rather than a foreign id),
     * and `AuditAttribution::storeFor()` files the row under the SUBJECT's own `store_id`, which
     * is what keeps an operator's action out of the fabricated store 1 that `getCurrentStore()`
     * hands them.
     *
     * CALLED AFTER THE WRITE HAS COMMITTED, NEVER BEFORE, AND NEVER ON A REFUSAL. A row claiming
     * an action that did not happen is worse than no row -- the same reasoning
     * `markRefundedLinesRestored()` records for the stock marker -- and it is asserted from both
     * sides by `RefundPolicyEnforcementTest::test_a_refused_mutation_records_no_actor_row`.
     *
     * OUTSIDE the mutation's own transaction, also deliberately: an audit insert that failed would
     * otherwise roll back the refund it describes, which would turn a recording gap into a
     * financial one. The trade is the opposite residual -- a committed refund whose audit row
     * failed to write -- and that is the safe direction for this pair.
     *
     * AND THEREFORE GUARDED, WHICH IS THE ONE PLACE THIS DIFFERS FROM THE 18 EXISTING CALL SITES.
     * Each of those records a write it is PART of: a throw there refuses the write, which is
     * correct. This one runs after the commit, so an unguarded throw would answer HTTP 500 with the
     * refund, the stock movement or the payout ALREADY STANDING -- the half-written state
     * CLAUDE.md records as the shape of a real defect (a 500 after a commit is not a refusal).
     * That is not hypothetical for this table: `audit_logs` was applied to production out of band
     * and its migration is a documented no-op there, so the schema it depends on is not something
     * this code can assert.
     *
     * The failure is LOUD in the log and silent to the caller, at `error` rather than `warning` or
     * `critical`, which keeps it out of `docs/security/P01-OBSERVABILITY-INVENTORY.md`'s declared
     * set -- it is a recording fault, not a control firing. The rows themselves are pinned by
     * `RefundPolicyEnforcementTest`, so a regression that stops them being written is caught there
     * rather than by this catch going quiet.
     *
     * THE PREVIOUS STATUS IS PASSED IN, AND THAT IS A CORRECTION TO THIS METHOD'S FIRST FORM
     * RATHER THAN A STYLE CHOICE. It read `$refund->getOriginal('refund_status')`, which is the
     * NEW value by the time this runs: `Model::save()` calls `finishSave()` and that calls
     * `syncOriginal()`, so after `$refund->update(['refund_status' => 'Accept'])` the original and
     * the attribute are both `Accept`. Every row would therefore have claimed the refund was
     * already in the state the action had just moved it to -- `Processing -> Accept` recorded as
     * `Accept -> Accept`, which is a FALSE old value in an audit table whose whole purpose is to
     * say what changed. A null means "this action does not move the status" (the restock door),
     * which is a different claim from "it was already there"; both are pinned by
     * `RefundPolicyEnforcementTest::test_an_audit_row_records_the_status_the_refund_came_from`.
     *
     * @param  array<string, mixed>  $details  what changed, as the audit row's new values
     * @param  string|null  $previousStatus  the status read BEFORE the write, or null when this
     *                                       action does not move the status
     */
    protected function recordRefundAction(
        string $action,
        OrderRefund $refund,
        array $details = [],
        ?string $previousStatus = null
    ): void {
        try {
            \App\Models\AuditLog::record(
                $action,
                $refund,
                ['refund_status' => $previousStatus],
                array_merge([
                    'refund_id' => (int) $refund->id,
                    'order_id' => (int) $refund->order_id,
                ], $previousStatus === null
                    /*
                     * THE KEY CHANGES WITH THE CLAIM, which is a correction an independent review
                     * forced. Every row used to carry `refund_status => <current>` in its NEW
                     * values, so the restock row read `old {refund_status: null} -> new
                     * {refund_status: "Accept"}` -- which a reader takes for a transition INTO
                     * Accept. The null old value is honest (`RefundStock()` writes no status, and
                     * `test_an_audit_row_records_the_status_the_refund_came_from` drives it); it
                     * was the NEW value that lied, i.e. exactly the false reading this method
                     * exists to remove, reintroduced one key along.
                     */
                    ? ['refund_status_unchanged' => $refund->refund_status]
                    : ['refund_status' => $refund->refund_status]
                , $details)
            );
        } catch (\Throwable $e) {
            \Log::error('A refund action could not be attributed; the action itself stands', [
                'action' => $action,
                'refund_id' => $refund->id ?? null,
                'order_id' => $refund->order_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
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
     * RET-002 — has a merchant inspected these goods and recorded that they cannot be sold again?
     *
     * The plan's 04-F clause is *"فحص حالة المنتج قبل إعادته للبيع"* — inspect the product's
     * condition before returning it to sale. Every control already on this door is about HOW MANY
     * units (`unitsLostByProduct()`'s clamp) or WHETHER TWICE (`stockAlreadyRestored()`, the cache
     * lock, the note marker). None of them asked what state the goods came back in, so a damaged
     * return became sellable inventory on the same evidence as a pristine one.
     *
     * TRUE ONLY WHEN A VERDICT EXISTS AND SAYS NOT RESELLABLE, so a store that has recorded none
     * — every store on the platform today — sees no change at all. The gate can only ever credit
     * LESS stock, never more.
     *
     * THE CALLER SKIPS WITHOUT TOUCHING `$creditedProducts`, which is what
     * `markRefundedLinesRestored()` stamps `Order::LINE_STOCK_RESTORED` from. Stamping a line whose
     * stock was withheld would be the false "the goods are back" claim this door's own
     * `unitsLostByProduct()` then reads as evidence — it would suppress the legitimate restock once
     * the item is re-inspected as resellable. The units the clamp already took off `$lostLeft` are
     * not lost either: `$lostLeft` is rebuilt from the order's lines on every call, and the line
     * stays unstamped, so the bound is whole again next time.
     */
    protected function returnedGoodsAreNotSellable(OrderRefund $refund, $storeId, $productId, $variantId): bool
    {
        if (! app(\App\Services\ReturnInspection::class)
            ->blocksRestock($storeId, $refund->order_id, $productId, $variantId)) {
            return false;
        }

        \Log::warning('Refund restock withheld a line from sale: its recorded condition is not resellable', [
            'refund_id' => $refund->id,
            'order_id' => $refund->order_id,
            'store_id' => $storeId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'decision' => 'RET-002',
        ]);

        return true;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function index(OrderRefundDataTable $dataTable)
    {
        /*
         * P4-F089 -- THE PLATFORM OPERATOR WAS LOCKED OUT OF THE LIST OF REFUNDS THEY COULD
         * OTHERWISE ACCEPT, REJECT, RESTOCK AND PAY OUT.
         *
         * This read `auth()->user()->isAbleTo('Manage Order Refund Request')` with NO operator
         * limb, while the five mutators below all share `canManageRefunds()`, which tests the
         * operator FIRST. There is no super-admin bypass in this application -- operator status is
         * a string comparison on `users.type` and Laratrust's `permissions_as_gates` is false --
         * so `isAbleTo()` ALONE REFUSES THE OPERATOR. Measured before the fix: the operator was
         * redirected with "Permission denied." while `updateRefundStatus` answered them 200.
         *
         * Reusing the shared predicate rather than copying its two limbs is the point: a second
         * copy is a second thing to drift, and this family has already produced a gate that
         * refused the store's own owner.
         */
        if (! $this->canManageRefunds()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $refund_requests = OrderRefund::where('store_id', getCurrentStore())->get();

        return $dataTable->render('order.refund_request', compact('refund_requests'));
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

            /*
             * RET-002 — THE CONDITION COLUMN'S DATA, and the one thing about it that matters.
             *
             * THE VARIANT IS RESOLVED THE SAME WAY THE GATE RESOLVES IT. `App\Services\
             * ReturnInspection` keys a verdict on (store, order, product, variant), and
             * `RefundStock()` asks it with the variant it took from
             * `resolveVariantIdsFromOrder()` — the ORDER's own `product_json`, because the refund
             * payload records no variant id. A screen that posted `variant_id = 0` for a variant
             * line would file the verdict under a key the gate never reads: the merchant would see
             * "damaged" recorded and the goods would still go back on sale. So the same resolver is
             * called here, and the control posts what it returns.
             *
             * (The display name above has the OPPOSITE bug and it is NOT fixed here, because it is
             * pre-existing, cosmetic, and outside this slice:
             * `ProductVariant::where('id', $product->id)` looks a variant up by a PRODUCT id —
             * different key spaces, the same wrong-row read `RefundStock()` was corrected for. It
             * mislabels a variant line or throws on a missing row; it decides nothing. Reported.)
             *
             * Both reads are store-scoped and both fail SOFT: a cross-tenant or missing order
             * yields no variants and no verdicts, so the column renders as "not recorded" rather
             * than taking the screen down.
             *
             * ONE EDGE WHERE A VERDICT RECORDED FROM THIS SCREEN IS INERT, said rather than left to
             * be found: `resolveVariantIdsFromOrder()` answers `null` for a product whose lines
             * carry DIFFERENT variant ids, because the choice is undecidable and must not be
             * guessed. The control then posts `variant_id = 0` and the verdict is filed under a key
             * the gate will not read for that line. It costs nothing, because `RefundStock()`
             * SKIPS exactly those lines anyway — *"Refund restock could not resolve which variant
             * was returned; line skipped"* — so no stock is credited for them with or without a
             * verdict. The day that skip is replaced by a real per-line choice, this control needs
             * the same choice.
             */
            $orderModel = $this->findOrderInCurrentStore($order['id']);
            $returnVariantIds = $orderModel === null ? [] : $this->resolveVariantIdsFromOrder($orderModel);
            $returnInspections = app(\App\Services\ReturnInspection::class)
                ->forOrder(getCurrentStore(), $order['id']);

            foreach ($productRefundData as $pid => $row) {
                $variantId = (int) ($returnVariantIds[(string) $pid] ?? 0);
                $productRefundData[$pid]['variant_id'] = $variantId;
                $productRefundData[$pid]['inspection'] =
                    $returnInspections[(int) $pid . ':' . $variantId] ?? null;
            }

            $returnVerdicts = \App\Services\ReturnInspection::VERDICTS;

            $plan = Plan::find(Auth::user()->plan_id);
            $store = Store::find(getCurrentStore());
            $RefundStatus = OrderRefundSetting::where('store_id',$store->id)
                    ->pluck('is_active', 'name')->toArray();
            return view('order.refund_details', compact(
                'order',
                'refund_requests',
                'RefundStatus',
                'plan',
                'productRefundData',
                'returnVerdicts'
            ));
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

        // Read BEFORE the write: `update()` syncs the model's original, so a read after it
        // answers with the new value. See recordRefundAction().
        $cameFrom = (string) $order->refund_status;

        $order->update(['refund_status' => self::STATE_ACCEPT]);

        // P4-F087: who agreed to the return.
        $this->recordRefundAction('refund.accepted', $order, [], $cameFrom);

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

        // WHICH LINE EACH RECORDED ENTRY GOES BACK ONTO (P1-ORD-14). The record lists the TICKED lines only, so it is NOT parallel to
        // the order's lines: reading `$productRefunds[$key]` with `$key` the line's position gave the first entry to the first line
        // whatever product it named (order A qty 3 + B qty 2, one unit of B returned, request rejected -> A grew, B did not come back).
        // Entries are matched to lines by PRODUCT ID; where a product sits on several lines (variants) the line that actually lost
        // units takes it (`refunded_qty`, kept by the storefront request); and what is given back is bounded by what the line lost.
        $plan = $this->restorationPlan($productJson, $productRefunds);

        if ($plan === null) {
            \Log::warning('Refund cancellation refused: the record does not match the order', ['refund_id' => $order_refund->id, 'order_id' => $order->id]);

            return $this->refundDenied('This refund request does not match the order\'s lines, so nothing was restored.', 422);
        }

        // ATOMIC with the status write. Previously the order's totals were saved
        // and the refund status was updated as two separate statements: a
        // failure between them left the order's quantities and final_price
        // already restored while the refund still read 'Processing' or
        // 'Accept' -- so the next POST restored them a second time. Making the
        // pair atomic is what lets the state guard above actually hold.
        // Read BEFORE the write, for the same reason as at the accept door.
        $cameFrom = (string) $order_refund->refund_status;

        try {
            \DB::transaction(function () use ($order, $order_refund, $productJson, $plan, $request) {
                $total_product_price = 0.0;

                foreach ($productJson as $key => &$product) {
                    $quantitie = $plan[$key] ?? 0;
                    $old_qty = $product['qty'];

                    $product['qty'] = $product['qty'] + $quantitie;

                    // the value this line lost when it was refunded, given back in proportion to the units that come back (new data only)
                    $lostValue = null;

                    if ($quantitie > 0 && isset($product['refunded_qty'])) {
                        $lostQty = (int) $product['refunded_qty'];
                        $lostValue = isset($product['refunded_value']) && $lostQty > 0 ? (float) $product['refunded_value'] * $quantitie / $lostQty : null;
                        $product['refunded_qty'] = max(0, $lostQty - $quantitie);

                        if ($lostValue !== null) {
                            $product['refunded_value'] = max(0.0, (float) $product['refunded_value'] - $lostValue);
                        }
                    }

                    if ($old_qty != 0) {
                        $product['final_price'] = ($product['final_price'] * $product['qty']) / $old_qty;
                    } elseif ($lostValue !== null) {
                        // A line refunded down to nothing has no price left to scale. The value it lost was recorded when it lost it, so the
                        // restored price no longer depends on a figure posted with this request (the cancel button sends the SHOPPER's
                        // display subtotal).
                        $product['final_price'] = $lostValue;
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

        // P4-F087: who rejected the return -- and this door rewrites the order's totals, so the
        // figures it left behind are recorded with the actor rather than only the status.
        $this->recordRefundAction('refund.rejected', $order_refund, [
            'order_final_price' => $order->final_price,
            'order_product_price' => $order->product_price,
        ], $cameFrom);

        return response()->json([
            'status' => $order_refund->refund_status,
            'message' => 'Request cancel successfully!'
        ]);
    }

    /**
     * Which order line each recorded refund entry is given back to, and how many units: line index => units; null when the record cannot be
     * applied to this order without guessing or inflating it.
     *
     * An entry is {product_refund_id, return_price, quantity}. It goes to the first line (in order) of that product that has not been
     * given an entry yet and that LOST at least that many units -- `refunded_qty`, written by the storefront request. A line that predates
     * the counter (no such key) takes it unbounded, as before (recorded residual: nothing says how many units it lost). An entry for a
     * product that is on no line, an entry larger than every candidate line lost, or a quantity that is not a positive whole number makes the
     * whole plan unusable: a half-applied cancellation would leave the order and the request disagreeing.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, mixed>  $entries
     * @return array<int, int>|null
     */
    private function restorationPlan(array $lines, array $entries): ?array
    {
        $plan = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                return null;
            }

            $productId = $entry['product_refund_id'] ?? null;
            $units = $entry['quantity'] ?? ($entry['qty'] ?? null);

            if ($productId === null || ! is_scalar($productId) || ! is_numeric($units) || (float) $units != (int) $units || (int) $units <= 0) {
                return null;
            }

            $units = (int) $units;
            $placed = false;

            // IS THERE A COUNTER FOR THIS PRODUCT AT ALL? (P1-ORD-14, second pass.) The legacy limb below is
            // deliberately unbounded, for records that predate the counter -- but `refunded_qty` is written
            // PER LINE by the refund, so a product on two lines where only one has ever been refunded has one
            // counted line and one legacy line. That is the normal state of any order whose variants were
            // partly returned, and it let an entry larger than the counted line's counter skip the bound and
            // land on the legacy sibling instead of being refused: the product HAD a counter and the bound was
            // still escaped (measured; the order grew from 3 units to 52).
            //
            // So the legacy limb is allowed only when NO line of this product carries a counter. Where one
            // does, that counter is authoritative and an entry exceeding every counted line is refused.
            $hasCounter = false;

            foreach ($lines as $line) {
                if (is_array($line) && (string) ($line['product_id'] ?? '') === (string) $productId && array_key_exists('refunded_qty', $line)) {
                    $hasCounter = true;

                    break;
                }
            }

            // a line that KEEPS the counter and lost at least this many units is the right one; only then a line that predates it
            foreach ($hasCounter ? [true] : [true, false] as $counted) {
                foreach ($lines as $key => $line) {
                    if (isset($plan[$key]) || ! is_array($line) || (string) ($line['product_id'] ?? '') !== (string) $productId
                        || array_key_exists('refunded_qty', $line) !== $counted) {
                        continue;
                    }

                    if ($counted && $units > (int) $line['refunded_qty']) {
                        continue;
                    }

                    $plan[$key] = $units;
                    $placed = true;

                    break 2;
                }
            }

            if (! $placed) {
                return null;
            }
        }

        return $plan;
    }

    /**
     * Product id => the units its order lines have lost to refunds (`refunded_qty`), or null ONLY when no line
     * of that product carries the counter. A product on no line is absent from the map.
     *
     * P1-ORD-14, second pass. This used to return null as soon as ANY line of a product predated the counter,
     * and `RefundStock` skips its clamp on null -- so one legacy sibling made the counter unreadable and an
     * oversized record restocked unbounded. `refunded_qty` is written PER LINE by the refund, so a product on
     * two lines where only one has ever been refunded hits that every time: the normal state of an order whose
     * variants were partly returned. Measured: a record claiming 500 units restocked 500 against a counter of 1.
     *
     * A counter that EXISTS is now authoritative -- the sum over the counted lines bounds the product, and the
     * legacy lines contribute nothing rather than erasing the bound.
     *
     * TWO SENTENCES STOOD HERE AND GAP-140 MADE BOTH FALSE; they are corrected rather than left to
     * mislead. (1) "Only a product whose lines are ALL legacy is unknown" -- no longer true: a
     * product with a MARKED line is bounded at 0 even when every line is legacy, which is the whole
     * of the GAP-140 read half below. (2) "Same rule as `restorationPlan()`, so the cancel path and
     * the restock path agree" -- no longer true either: `restorationPlan()` reads neither marker.
     * That is deliberate and not an oversight to fix here, because the cancel path restores `qty`
     * (the units still on the line) while this path credits the units a refund took OFF it, so the
     * two ask different questions; making them agree is a separate decision. The residual criterion
     * 5 permits is unchanged ("they may be wrong; they must not be able to inflate").
     *
     * GAP-140 / P4-F083 -- A LINE WHOSE GOODS ARE ALREADY BACK ON THE SHELF LENDS THIS PRODUCT NOTHING.
     *
     * This is the whole of the refund door's entry into the cross-door stock protocol, and it is deliberately
     * made HERE rather than as a second guard in the restock loop: the allowance is already the one place that
     * decides how many units a record may honestly give back, and the two markers are evidence about exactly
     * that. Before this, `OrderRefundController` referenced neither of them -- measured at HEAD: zero
     * occurrences of `LINE_STOCK_RESTORED`, `lineStockAlreadyRestored` or `LINE_STOCK_CARRIED` in this file --
     * although the constant's own docblock (app/Models/Order.php) asserts "a refund or return reads it as
     * evidence". A merchant marking a delivered line returned (+3) and then restocking the shopper's refund of
     * that same line credited the three units TWICE: 16 where 13 was due, measured through the real doors by
     * `RefundStockCrossDoorTest`.
     *
     *   LINE_STOCK_RESTORED  these goods have already been credited, by this line's return, by a whole-order
     *                        return approval, by any of the six cancel doors, or by an earlier refund restock.
     *   LINE_STOCK_CARRIED   these goods never left and never will: the line was reissued by
     *                        `OrderReplacement::cancelAndReplace()`, whose cancel-and-reissue pair must net -N.
     *                        Crediting it invents stock. Read here for the same reason the cancel loop reads it.
     *
     * A MARKED LINE IS EVIDENCE, SO IT ALSO MAKES THE PRODUCT **BOUNDED** rather than leaving it unknown. That
     * closes the null-permissive hole for exactly the shape this gap is about: a marked line carrying no
     * `refunded_qty` would otherwise leave the product unknown, the clamp would be skipped, and the recorded
     * quantity would restock unbounded onto goods that are already in.
     *
     * THE RESIDUAL, STATED RATHER THAN HIDDEN, because it is the one case the stored line cannot answer. The
     * other doors credit `qty` -- the units still ON the line -- while this door credits the units the refund
     * took OFF it, so the two are normally disjoint. They overlap only when a return door credited the line
     * BEFORE the refund request reclassified some of those units into `refunded_qty`, and the stored line
     * cannot distinguish that from a return door crediting the remainder AFTER a partial refund: both end as
     * `{qty: 1, refunded_qty: 2, stock_restored: 1}`. This reads a marked line as fully accounted for, which
     * is the conservative direction -- it can leave refunded units uncredited (visible, and recoverable by
     * hand) rather than inventing stock that was never on the shelf (an oversell).
     *
     * "THE STORED LINE CANNOT ANSWER IT" IS TRUE; "NOTHING RECORDS THE ORDERING" WAS NOT, AND AN
     * EARLIER DRAFT SAID SO. The ORDER ROW differs between the two sequences: `product_return()`
     * sums the flagged line's `final_price` AT THE TIME IT RUNS and writes `orders.return_price`,
     * while a refund request reduces the line's `final_price` and writes no order totals -- so on a
     * single-line order the two orderings leave different `return_price` values and are
     * distinguishable. The residual stands anyway, for a better reason: that column is **not a
     * reliable trace**. `order_return_request` overwrites it unconditionally on BOTH approve and
     * reject, `RefundAmonut` recomputes `final_price` from the lines on settlement, and it
     * aggregates across lines with any coupon folded in -- so it is ambiguous for any multi-line
     * order and deciding a stock credit from it is exactly the inference this project forbids.
     * `StockMovement::movedOutFor()` would answer it exactly, but its migration is
     * development-and-test-only, so production reads UNKNOWN. Resolving it properly means recording
     * the ordering where it is known, in the storefront request writer.
     *
     * @return array<string, int|null>
     */
    private function unitsLostByProduct(Order $order): array
    {
        $lines = json_decode((string) $order->product_json, true);
        $counted = [];
        $seen = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line) || ! isset($line['product_id'])) {
                continue;
            }

            $key = (string) $line['product_id'];
            $seen[$key] = true;

            if (Order::lineStockAlreadyRestored($line) || Order::lineStockCarriedFromOriginal($line)) {
                // Known, and nothing: whatever this line lost is already accounted for elsewhere.
                $counted[$key] = $counted[$key] ?? 0;

                continue;
            }

            if (array_key_exists('refunded_qty', $line)) {
                $counted[$key] = ($counted[$key] ?? 0) + max(0, (int) $line['refunded_qty']);
            }
        }

        $lost = [];

        foreach (array_keys($seen) as $key) {
            // a counted line anywhere on the product makes the sum of the counters the bound; no counted line at
            // all leaves it unknown
            $lost[$key] = array_key_exists($key, $counted) ? $counted[$key] : null;
        }

        return $lost;
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
         * THE STORE'S OWN REFUND POLICY, ENFORCED HERE AND NOT ONLY IN BLADE. GAP-140 / P4-F086.
         *
         * `resources/views/order/refund_details.blade.php:224` renders the "Manage Stock" button
         * only when this store's refund setting is on, and that was the WHOLE of the control:
         * measured at HEAD, no mutator anywhere consulted the setting, so this endpoint credited
         * the goods back for a merchant who had switched restocking off. The button was hidden and
         * the door was open.
         *
         * PLACED AFTER THE PERMISSION, *BOTH* TENANT LOOKUPS AND THE STATE GUARD. A policy refusal
         * in front of an ownership lookup would answer differently for an id that exists in another
         * store than for one that exists nowhere, which is the existence oracle the uniform 404
         * exists to close.
         *
         * "BOTH" IS A CORRECTION THIS DOCBLOCK EARNED BY BEING WRONG. Its first form said "the
         * tenant lookup", singular, and the gate sat between the two: `findRefundInCurrentStore()`
         * above and the `Order::where('store_id', $storeId)` below. So a refund row inside the
         * caller's tenant whose ORDER belongs to another store answered 403 where every other
         * ownership failure answers 404 -- and, worse, the policy then refused before the ORDER and
         * PRODUCT store predicates ran at all, which made the two cases
         * `RefundStockCrossDoorTest::test_negative_ownership_another_stores_refund_cannot_credit_this_stores_stock`
         * exists for pass for the wrong reason: "no stock moved" holds when a policy refusal came
         * first. That test found it; the reasoning above was right and its placement did not match it.
         *
         * 403 rather than 422: this is "your store does not permit this action", and the screen's
         * `refundAjaxRefusal()` trusts the body of a 403 (as of the four statuses this controller
         * authors), so the merchant reads the reason rather than "Something went wrong".
         *
         * WHAT THIS CHANGES FOR A MERCHANT WHO HAD IT OFF -- stated rather than left to be found:
         * the restock action is now refused instead of silently succeeding. It is a STOCK movement
         * and never a money one; nothing about the refund's amount, the order's totals or the
         * refund's status is touched by this refusal, and the per-refund idempotency marker is NOT
         * written, so switching the setting on later leaves a legitimate credit still available.
         * The three other stock doors -- `Order::product_return()`, the whole-order return
         * approval and the six cancel doors -- are untouched, so a merchant with this toggle off
         * can still put returned goods back through them.
         *
         * AND IT CHANGES THE SAME THING FOR THE PLATFORM OPERATOR, which the paragraph above did
         * not say until a review asked. The gate is on the STORE, not the principal: it reads
         * `getCurrentStore()`, so an operator working in a store whose merchant has the toggle off
         * is refused too, and a support action they previously had is gone. That is held to be
         * correct -- it is the store's policy about the store's stock, and an operator quietly
         * crediting inventory a merchant has switched off would be the stranger behaviour -- but it
         * is a capability REMOVED from the operator, so it is written down rather than discovered.
         * The route back is the Refund tab, not a code change. Every test here that restocks drives
         * `makePlatformAdmin()` with the row present, so the operator's own path is exercised.
         */
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

        if (! \App\Services\RefundPolicy::allows(\App\Services\RefundPolicy::MANAGE_STOCK, $storeId)) {
            return $this->refundDenied(
                'This store does not restore stock from refund requests. Switch Manage Stock on in '
                . 'the refund settings first.',
                403
            );
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

            // P1-ORD-14: the units each product's order lines lost -- the most that can honestly come back (null = unknown, a legacy line)
            $lostLeft = $this->unitsLostByProduct($order);

            $restored = 0;
            // GAP-140: the products this call really did credit units for, so the marker below is
            // stamped only where a credit happened. See markRefundedLinesRestored().
            $creditedProducts = [];

            \DB::transaction(function () use (
                $product_refund_id_array,
                $order_refund,
                $order,
                $storeId,
                $variantIds,
                &$restored,
                &$lostLeft,
                &$creditedProducts
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

                    // A record is a claim; the order's lines say how many units were actually taken off them. Two entries for one product
                    // draw on the same allowance, so listing a unit twice does not restock it twice.
                    if (array_key_exists((string) $product_id, $lostLeft) && $lostLeft[(string) $product_id] !== null) {
                        $allowed = min($quantity, $lostLeft[(string) $product_id]);

                        if ($allowed < $quantity) {
                            \Log::warning('Refund restock clamped to the units the order lines lost', [
                                'refund_id' => $order_refund->id,
                                'product_id' => $product_id,
                                'recorded' => $quantity,
                                'allowed' => $allowed,
                            ]);
                        }

                        $lostLeft[(string) $product_id] -= $allowed;
                        $quantity = $allowed;

                        if ($quantity <= 0) {
                            continue;
                        }
                    }

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
                        /* E13-C residual: recorded, so the refund's give-back nets against
                           the placement instead of being invisible to `movedOutFor()`. */
                        if ($this->returnedGoodsAreNotSellable($order_refund, $storeId, $product->id, 0)) {
                            continue;
                        }

                        $this->stockService()->restoreProductStock($product->id, $quantity, [
                            'store_id' => $storeId,
                            'order_id' => $order->id ?? null,
                            'reason' => \App\Services\StockMovement::REASON_REFUND,
                        ]);
                        $restored++;
                        $creditedProducts[(string) $product->id] = true;

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

                    if ($this->returnedGoodsAreNotSellable($order_refund, $storeId, $product->id, $variantProduct->id)) {
                        continue;
                    }

                    $this->stockService()->restoreVariantStock($variantProduct->id, $quantity, [
                        'store_id' => $storeId,
                        'order_id' => $order->id ?? null,
                        'reason' => \App\Services\StockMovement::REASON_REFUND,
                    ]);
                    $restored++;
                    $creditedProducts[(string) $product->id] = true;
                }

                /*
                 * THE PROTOCOL MARKER, THE WRITE HALF. GAP-140 / P4-F083.
                 *
                 * Inside the same transaction as the credits it describes, exactly like
                 * `markCancelledLinesRestored()`: a marker that survived a rolled-back restore
                 * would block a legitimate return credit for ever.
                 */
                $this->markRefundedLinesRestored($order, $creditedProducts);

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

        // P4-F087: who put the goods back, and how many lines were credited -- the figure beside
        // the marker, for the same reason `MerchantCancelRestockTest` pins it there.
        // No `$previousStatus`: this door credits stock and leaves `refund_status` where it was,
        // so there is no transition to record. A null is that claim; repeating the current status
        // as the "old" one would be the false reading recordRefundAction() exists to avoid.
        $this->recordRefundAction('refund.stock_restored', $order_refund, [
            'lines_credited' => $restored,
            'product_ids_credited' => array_keys($creditedProducts),
        ]);

        return response()->json([
            'message' => 'Manage Stock updated successfully!'
        ]);
    }

    /**
     * Stamp `Order::LINE_STOCK_RESTORED` on the lines this restock has left with nothing outstanding.
     *
     * GAP-140 / P4-F083 -- THE WRITE HALF OF JOINING THE PROTOCOL, AND WHY IT IS NEEDED AT ALL.
     *
     * Reading the marker (see `unitsLostByProduct()`) closes the direction where another door ran
     * first. The other direction looks closed without a write, because every return and cancel door
     * credits `qty` -- the units still ON the line -- and a refund request has already taken the
     * refunded units OFF it. But `CancelRefundStatus()` puts them BACK onto `qty`, so a withdrawn
     * refund makes units this door has already credited creditable a second time by a later return.
     * The stamp is what stops that, and nothing else does: the per-refund note marker is keyed on
     * the refund id and is invisible to the other doors.
     *
     * THREE CONDITIONS, ALL REQUIRED, because an unconditional stamp is worse than no stamp -- it
     * is a false claim that goods came back, and every return, cancel and refund surface reads it
     * as evidence (`markCancelledLinesRestored()`'s docblock; `MerchantCancelRestockTest` pins the
     * figure beside the marker for exactly that reason):
     *
     *   1. the line's product is one this call really credited units for;
     *   2. the line has NOTHING LEFT OUT with the customer (`qty` 0), so the claim is true. A
     *      partially refunded line still holds returnable units and must stay unstamped --
     *      `RefundStockCrossDoorTest::test_the_refund_door_does_not_stamp_a_line_that_still_holds_units`;
     *   3. the line actually lost units to a refund (`refunded_qty` > 0), so a legacy `qty: 0` line
     *      that merely shares a product with the refund is not swept up.
     *
     * Inert on every path except the one it exists for: all three other doors already skip a line
     * whose `qty` is 0, so stamping such a line changes nothing until `CancelRefundStatus()` has
     * put the units back.
     *
     * THE LINES ARE RE-READ RAW, INSIDE THE TRANSACTION, AND NOT TAKEN FROM `$order`. That instance
     * was loaded before the stock writes, so re-encoding it would persist a snapshot and silently
     * discard anything another door committed to `product_json` in between -- a clobber this door
     * could not cause before, because it wrote nothing to `orders` at all. Raw because `Order` is
     * `Cachable` and an Eloquent read can serve the pre-write row; the WRITE then goes back through
     * the model for the opposite reason, so the cache is flushed.
     *
     * TWO CLAIMS ABOUT THIS READ WERE OVERSTATED AND ARE CORRECTED HERE, both found by the
     * independent review of this change rather than by the tests, which cannot see either.
     *
     * FIRST, WHAT THE RE-READ ACTUALLY CLOSES. Under MySQL's default REPEATABLE READ, InnoDB fixes
     * the consistent-read snapshot at the transaction's FIRST read -- which here is the
     * `Product::where(...)` above, not this statement -- and `config/database.php` sets no
     * `isolation_level`. So this `SELECT` returns that snapshot, NOT the latest committed row. It
     * therefore closes the window between the `$order` load and the transaction's first read, which
     * is the common case and a real improvement over re-encoding the instance; it does NOT see
     * "anything another door committed in between", which an earlier draft of this paragraph
     * claimed. `RefundStockCrossDoorTest` cannot detect the difference: it stages its interleave on
     * the same connection inside the same transaction, so the read trivially sees it.
     *
     * SECOND, THE RESIDUAL WAS STATED BACKWARDS. An earlier draft said a writer committing between
     * this read and the save "still wins". Under MySQL the `UPDATE orders` below blocks on that
     * session's row lock and lands LAST, so WE win and THEIR `product_json` change is discarded --
     * the outcome the re-read exists to prevent. The save writes only `product_json` (nothing else
     * on the instance is dirty), so no money column can be clobbered this way; a concurrent
     * `product_json` write can.
     *
     * A `lockForUpdate` on this read would close both -- it takes the same `orders` row lock the
     * `UPDATE` takes microseconds later, at the same point in the `products` -> `orders` ordering,
     * so the deadlock graph does not change, and a locking read sees the latest committed row
     * rather than the snapshot. It is NOT added here: it is a lock-behaviour change on a financial
     * path and this suite runs on SQLite, where it is a no-op, so nothing in this repository can
     * demonstrate it works. Registered with its reasoning instead -- see GAP-140's row.
     *
     * @param  array<string, true>  $creditedProducts  product ids this call credited, as keys
     */
    private function markRefundedLinesRestored(Order $order, array $creditedProducts): void
    {
        if ($creditedProducts === []) {
            return;
        }

        $stored = \DB::table('orders')
            ->where('id', $order->id)
            ->where('store_id', $order->store_id)
            ->value('product_json');

        $lines = json_decode((string) $stored, true);

        if (! is_array($lines)) {
            return;
        }

        $keys = [];

        foreach ($lines as $key => $line) {
            // `is_scalar` as well as `isset`: a line whose `product_id` decoded to an array makes
            // `(string)` an "Array to string conversion" warning, and this block runs INSIDE the
            // restock transaction -- where an ErrorException would roll back a credit that had
            // already succeeded and answer 500. Such shapes exist (`StoreCurrencyConversionService`
            // carries a branch for a non-canonical `product_json`), so the stamp skips the line
            // rather than risking the credit.
            if (! is_array($line) || ! isset($line['product_id']) || ! is_scalar($line['product_id'])) {
                continue;
            }

            if (! isset($creditedProducts[(string) $line['product_id']])) {
                continue;
            }

            if (Order::creditableReturnQty($line) > 0) {
                continue;
            }

            if (max(0, (int) ($line['refunded_qty'] ?? 0)) <= 0) {
                continue;
            }

            $keys[] = $key;
        }

        $stamped = Order::linesWithStockRestoredStamp($lines, $keys);

        if ($stamped === null) {
            return;
        }

        /* Through the model, so the `Cachable` read path is flushed; only `product_json` is dirty,
           so no money column is rewritten, and `OrderObserver::updated` returns early because
           `payment_status` did not change. */
        $order->product_json = json_encode($stamped);
        $order->save();
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
            /*
             * GAP-316 -- NAME THE CAUSE, NOT THE MERCHANT'S NUMBER.
             *
             * The ceiling here is the ORDER'S OWN TOTAL, and when that is absent the ceiling is 0
             * (`RefundIntegrityService::toAmount()` ends `return $amount > 0 ? $amount : 0.0`). Every
             * positive request is then capped to 0 and lands in this branch -- so the single message
             * that used to live here told the merchant their amount was too large when the amount was
             * never the problem. They try a smaller number and are refused identically. MEASURED over
             * 500, 50 and 1: byte-identical refusals. There is no number that works, and the sentence
             * named the only thing they could change while withholding the only thing they could not.
             *
             * Found while measuring GAP-294, which is the reachability: orders really do carry a NULL
             * `final_price`.
             *
             * THE CAUSE WAS ALREADY IN HAND. `cappedRefundAmount()` returns `limit` beside `amount`;
             * this method read `amount` and discarded the rest. No new query and no recomputation --
             * the limit is simply consulted before the sentence is chosen.
             *
             * A ZERO TOTAL IS NOT AN ABSENT TOTAL. An order that genuinely came to zero has nothing
             * to refund; an order with no recorded total has a data problem upstream that the
             * merchant cannot fix from this screen. Telling the first that its total is missing would
             * be a second wrong answer, so they are separated -- `RefundRefusalNamesTheCauseTest`
             * asserts which sentence each one gets.
             *
             * "NOT A POSITIVE AMOUNT" RATHER THAN "ZERO", so the recorded branch stays true without a
             * third one. `limit <= 0` also covers a NEGATIVE total, which a coupon exceeding the
             * subtotal could produce, and telling that merchant their total "is zero" would be the
             * same kind of confidently wrong sentence this change exists to remove.
             *
             * NOTHING ELSE CHANGES: same refusal, same 422, no money moves, and the cap itself is
             * untouched (`RefundIntegrityTest` owns that). Only the sentence differs.
             *
             * TRANSLATION, STATED RATHER THAN ASSUMED: both sentences go through `refundDenied()`,
             * which wraps in `__()`, and neither has an `ar.json` entry -- exactly like the message
             * they sit beside, which has none either (nor does 'Refund request not found.'). So this
             * is no worse than the branch it joins, and it is NOT done: Arabic copy for a
             * merchant-facing financial refusal is a content decision and is not invented here.
             */
            if ($capped['limit'] <= 0) {
                $totalIsRecorded = $order->final_price !== null && trim((string) $order->final_price) !== '';

                return $this->refundDenied(
                    $totalIsRecorded
                        ? 'This order\'s total is not a positive amount, so there is nothing to refund against it.'
                        : 'This order has no recorded total, so a refund cannot be checked against it. The order total has to be recorded before a refund amount can be entered.',
                    422
                );
            }

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

                /*
                 * OWNER DECISION D3 -- THE FINANCIAL ADJUSTMENT HAPPENS HERE, AND ONLY HERE.
                 * P1-ORD-11.
                 *
                 * "Track approval, execution and financial adjustment separately. Adjust only on
                 * reliable execution." The storefront request used to write `final_price` and
                 * `product_price` the moment a shopper submitted -- refund still `Processing`, no
                 * merchant having acted, recorded revenue already reduced. It no longer does
                 * (AccountProfileController::order_refund_request), so the adjustment lands here.
                 *
                 * ONLY ON `Refunded`, WHICH IS WHAT MAKES THIS "RELIABLE EXECUTION". `$targetStatus`
                 * comes from `RefundIntegrityService::resolveRefundStatus()`, which returns
                 * `Accept` -- approved, NOT settled -- for an online gateway payment, because no
                 * gateway refund driver exists anywhere in this platform. So an unsettled refund
                 * leaves the order's totals whole, and only an offline type (the merchant hands the
                 * cash back themselves, out of band) reaches this branch. That is the same
                 * principle D2 states for gateways: no verified settlement, no paid ledger entry.
                 *
                 * COMPUTED FROM THE ORDER'S OWN LINES, not from anything the request posted. The
                 * lines already carry what was returned -- the storefront request reduced `qty` and
                 * wrote the `refunded_qty` counter, which it must keep doing because
                 * `RefundStock`'s clamp is null-permissive without it (P1-ORD-14). So the
                 * adjustment is a recomputation of a figure the order already implies, which is why
                 * it needs no new stored state and is safe to apply once.
                 *
                 * FLOORED AT ZERO. A FIXED coupon is not prorated when lines are refunded, so a
                 * coupon larger than the remaining line total drove `final_price` NEGATIVE
                 * (measured on this task: coupon 450, lines 500, every unit refunded ->
                 * final_price -410). The floor stops a negative total being recorded; PRORATING the
                 * coupon is a revenue-reporting decision and is recorded as remaining, not guessed
                 * at here.
                 *
                 * IDEMPOTENT BY THE GUARD ABOVE. `Refunded` is terminal in LEGAL_TRANSITIONS, and
                 * the terminal-state check at the top of this method refuses a second payout, so
                 * this cannot run twice for one refund.
                 */
                if ($targetStatus === self::STATE_REFUNDED) {
                    $lines = json_decode((string) $order->product_json, true);

                    if (is_array($lines)) {
                        $lineTotal = 0.0;

                        foreach ($lines as $line) {
                            if (is_array($line) && isset($line['final_price']) && is_numeric($line['final_price'])) {
                                $lineTotal += (float) $line['final_price'];
                            }
                        }

                        $grandTotal = ($lineTotal + (float) $order->delivery_price + (float) $order->tax_price)
                            - (float) $order->coupon_price;

                        $order->product_price = $lineTotal;
                        $order->final_price = max(0.0, $grandTotal);
                        $order->save();
                    } else {
                        // The column is free text and can be unparseable. Refusing to guess is the
                        // only safe move for a money column: the payout is still recorded below, and
                        // the order's totals stay as they are rather than becoming a number derived
                        // from nothing.
                        \Log::warning('Refund settled but the order totals were left unchanged: product_json is unparseable', [
                            'refund_id' => $order_refund->id,
                            'order_id' => $order->id,
                        ]);
                    }
                }

                $order_refund->update([
                    'refund_status' => $targetStatus,
                    'refund_amount' => $amount
                ]);

                /*
                 * P4-F127 -- A COMPLETED REFUND MOVES THE MERCHANT'S ENTITLEMENT. A REQUEST DOES NOT.
                 * =====================================================================================
                 *
                 * MEASURED BEFORE THIS LINE: this controller is 1218 lines and contained ZERO
                 * functional references to `payment_status`, `StoreWallet` or
                 * `OrderFinancialService`. A fully refunded order kept `orders.payment_status =
                 * 'Paid'`, kept its `store_order_financials` row at `paid`, and kept the merchant's
                 * wallet credit. `order_refunds.refund_status` was the only record that money had
                 * come back, and nothing financial read it.
                 *
                 * `store_order_financials.payment_status` has carried 'refunded' in its production
                 * enum the whole time and nothing in the codebase could reach it. Now one thing can.
                 *
                 * THE GATE IS `$targetStatus === STATE_REFUNDED`, AND THAT IS THE WHOLE POLICY.
                 * `resolveRefundStatus()` is the only producer of that value and returns it ONLY for
                 * an OFFLINE payment type. Every online gateway resolves to 'Accept' -- approved, not
                 * settled -- and the Accept -> Refunded edge is unreachable by construction, because
                 * `hasGatewayRefundDriver()` is a hardcoded `return false` for every type and the two
                 * gateway refund HTTP clients in this tree have zero callers. No refund request has
                 * ever left this server.
                 *
                 * So the owner's "do not call a gateway refund complete without provider confirmation
                 * or independently evidenced manual settlement" holds here for a blunt reason, and
                 * this change does not pretend otherwise: it hangs the reversal on the one transition
                 * that already asserts completion, and inherits that transition's own evidence.
                 *
                 * INSIDE THIS TRANSACTION ON PURPOSE. If the refund row's write rolls back, the
                 * entitlement must roll back with it -- a reversal recorded against a refund that did
                 * not persist is worse than neither.
                 *
                 * IDEMPOTENT, so a duplicate refund notification cannot debit twice: the reversal
                 * re-reads the financial row under `lockForUpdate()` and returns without moving
                 * anything once that row is already `cancelled` or `refunded`. Cancel-then-refund and
                 * refund-then-cancel therefore both net to ONE reversal, and both orders are tested.
                 *
                 * `reverse()` and NOT `reverseOrLog()`: this call is already inside a transaction the
                 * caller rolls back on any throw, and that is the correct direction here. A
                 * cancellation must survive a ledger fault because the goods and the customer depend
                 * on it; a REFUND payout record that cannot move the entitlement should not be
                 * recorded as a completed refund at all.
                 *
                 * PARTIAL REFUNDS ARE NOT TOUCHED. `$amount` can be smaller than the order total, and
                 * the reversal still takes back the whole credited amount -- which is correct ONLY
                 * because `docs/finance/P4-F127-PARTIAL-REFUND-CAPABILITY.md` determines the ledger
                 * cannot represent a partial refund safely and that mutation stays BLOCKED. If that
                 * ever changes, this line is one of the places that has to change with it.
                 */
                if ($targetStatus === self::STATE_REFUNDED) {
                    app(\App\Services\OrderEntitlementReversal::class)->reverse(
                        $order,
                        \App\Services\OrderEntitlementReversal::REASON_REFUNDED,
                        'refund settled'
                    );
                }
            });
        } catch (\Throwable $e) {
            \Log::error('Refund payout record rolled back', [
                'refund_id' => $order_refund->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $this->refundDenied('The refund could not be recorded. Nothing was changed.', 500);
        }

        /*
         * P4-F087: who paid it out, with the figure.
         *
         * `settled` is the action name whatever `$targetStatus` came back as, and the row carries
         * the status, so an operator reading the log can tell the OFFLINE payout (`Refunded`,
         * money handed back out of band) from the online approval (`Accept`, no gateway driver
         * exists so nothing left this server). Recording the figure matters more here than
         * anywhere else in this controller: `$amount` is what the merchant typed, capped
         * server-side against the order total, and `capped` says whether the cap bit.
         */
        $this->recordRefundAction('refund.settled', $order_refund, [
            'refund_amount' => $amount,
            'requested_amount' => $request->refund_amount,
            'capped' => (bool) $capped['capped'],
            'shipping_zeroed' => $request->isTrendingChecked == 'true',
        ], $currentStatus);

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
