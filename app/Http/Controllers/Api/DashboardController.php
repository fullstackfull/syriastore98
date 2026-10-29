<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProductController;
use Illuminate\Http\Request;
use App\Traits\ApiResponser;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\Store;
use App\Models\Product;
use App\Models\Order;
use App\Models\Utility;
use App\Models\Coupon;
use App\Models\Category;
use App\Models\Shipping;
use App\Models\Tax;
use App\Models\ProductVariant;
use App\Models\Plan;
use App\Models\ProductImage;
use App\Models\State;
use App\Models\City;
use App\Models\DeliveryBoy;
use App\Models\DeliveryEarning;
use App\Models\ShippingMethod;

use App\Models\Customer;

use App\Models\Country;

use App\Models\Cart;
use App\Models\Wishlist;
use App\Models\OrderTaxDetail;
use App\Models\OrderCouponDetail;
use App\Models\OrderBillingDetail;
use App\Models\User;
use App\Models\Setting;
use App\Models\UserCoupon;
use App\Models\UserStore;
use App\Models\Page;
use App\Models\AppSetting;
use App\Models\Blog;
use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\PlanOrder;
use DB;
use Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\TestMail;
use App\Models\ProductBrand;
use App\Models\ProductLabel;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\File; 

use App\Models\OrderNote;


use App\Events\OrderPaymentStatus;

use Illuminate\Http\JsonResponse;






use Illuminate\Support\Facades\Log;

use App\Models\StoreNotification;
use App\Services\MerchantPermissions;




/**
 * NEW-api-store-id-is-the-tenant -- the dominant finding of this pass, and the
 * reason it is documented once here instead of 59 times inline.
 *
 * EVERY admin API route in this controller authenticates with `custom.auth`
 * (routes/api.php: 211 declarations, all of them) and then took its TENANT from
 * a caller-supplied field: `Store::find($request->store_id)`. Authentication
 * proved only that the caller held SOME valid token. Nothing compared the
 * requested store against the caller's own. A merchant who could log into the
 * shipped Flutter app therefore reached every other merchant's data by changing
 * one query parameter.
 *
 * MEASURED, not estimated. `grep -c "Store::find(\$request->store_id)"` found
 * 62 occurrences; 3 are inside explanatory comments, leaving 59 live code sites
 * in 59 distinct methods. 55 are routed in routes/api.php; the other 4
 * (OrderList_backup, ViewOrder_backup, DeleteProduct_backup, DeleteStore_backup)
 * were unrouted dead code and were repaired anyway so they could not be wired
 * up later and reintroduce the hole.
 *
 * THAT LAST CLAIM WAS HALF TRUE, and P1-INV-10 (d) measured which half. The
 * repair reached the STORE resolution in all four, and the per-ROW predicate in
 * only two: OrderList_backup (`Order::where('store_id', $store->id)`) and
 * DeleteStore_backup (every delete scoped to `$store->id`) really were safe to
 * wire up. ViewOrder_backup passed `$request->order_id` straight into
 * `Order::order_detail()`, which is `Order::find($order_id)` with no tenant
 * predicate (app/Models/Order.php:279-281), and DeleteProduct_backup keyed
 * `ProductImage`, `Cart`, `Wishlist` and the `Product` delete itself on a bare
 * `$request->product_id` -- so both would have reintroduced exactly the hole
 * they were kept to be safe from. Dead code cannot be relied on to stay
 * repaired, so those two are DELETED rather than fixed a second time; the two
 * that are genuinely tenant-scoped are left in place, which is what this
 * paragraph now records. Neither removed method was routed in routes/api.php or
 * referenced anywhere in app/, resources/ or tests/ (grep: 0 hits each).
 *
 * The brief named 17 sites. The real number is 59. That is reported as measured
 * rather than quoted.
 *
 * All 59 now go through resolveOwnedStore() (already present and already used
 * by ChangeStore and the seven list methods -- this reuses the existing
 * decision point rather than adding a second one).
 *
 * THE SECOND HALF OF THE DEFECT, and the reason resolveOwnedStore() alone was
 * not enough. 42 of those methods never referenced $store->id at all: the store
 * lookup was decoration and the real write keyed on a bare row id. With only
 * the resolution fixed, an attacker would still have passed their OWN store_id
 * together with a VICTIM's coupon/tax/product/order id. So 22 per-row lookups
 * are additionally constrained to the tenant, and DeleteOrder -- which deleted
 * an order plus its tax, coupon and billing detail rows with no store predicate
 * whatever -- resolves the order through the owned store before any delete runs.
 *
 * WHAT IS DELIBERATELY NOT CHANGED: the response shape. A denial answers with
 * each method's existing not-found envelope, not a new status code. Three
 * reasons. (1) repo-B lib/network/network.dart maps any 401 into a forced
 * logout, so an authorization denial must never be a 401. (2) Answering
 * "not found" rather than "forbidden" does not confirm that the id exists --
 * the same principle the status.cancel repair used. (3) The shipped Flutter app
 * already handles these envelopes, so a legitimate merchant sees no contract
 * change at all.
 *
 * Pinned by tests/Feature/Security/AdminApiTenancyTest.php, which asserts BOTH
 * directions on representative routes: the cross-tenant call is refused AND the
 * owner's own call still succeeds.
 */
class DashboardController extends Controller
{
    /*
     * D-21. The same private-helper shape `OrderController` uses (`:53-58`), rather than the
     * `AppliesCartStockChanges` trait, and the difference is deliberate: that trait's
     * `decrementStockOrFail()` is built for the post-capture paths, where a shortfall must
     * oversell rather than refuse. THIS path already refuses a shortfall and already rolls back,
     * and it must keep doing both — so what it needs is the service's `$requireAvailability`
     * argument, which the trait does not expose.
     */
    private ?\App\Services\ProductStockService $stockService = null;

    private function stockService(): \App\Services\ProductStockService
    {
        return $this->stockService ??= new \App\Services\ProductStockService();
    }

    use ApiResponser;








public function AddOrderNote(Request $request)
{
    $validator = \Validator::make($request->all(), [
        'store_id' => 'required|integer',
        'order_id' => 'required|integer',
        'note' => 'required|string|max:5000',
        'note_type' => 'required|string|in:private_note,to_customer',
    ]);

    if ($validator->fails()) {
        return $this->error([
            'message' => $validator->errors()->first(),
        ]);
    }

    // NEW-order-note-cross-tenant-write.
    //
    // This was `Store::find($request->store_id)`: the store was resolved from a
    // REQUEST FIELD and never compared against the caller's tenant. The order
    // lookup below then filtered on that attacker-chosen store, so the filter
    // confirmed the attacker's own choice instead of constraining it. Any holder
    // of ANY valid token could write a note -- including a `to_customer` note,
    // which is merchant-to-shopper text -- onto any order in any store on the
    // platform by changing one body field.
    //
    // This is the SAME defect class the Phase 1B repair closed in ChangeStore
    // (:3650) and in the seven admin list methods, and the helper written for it
    // is right here in this class. It is reused rather than re-derived, so there
    // is one tenancy decision point: resolveOwnedStore() (:3772) scopes to
    // `created_by = creatorId()` for everyone except a super admin, and
    // creatorId() (User.php:95) resolves staff to their employer so delegated
    // staff keep access.
    //
    // 403, NOT 401. repo-B lib/network/network.dart:285-287 maps any 401 into
    // _handleUnauthorized(), which clears preferences and forces a re-login, so
    // a 401 on an authorization denial would log the merchant out of the shipped
    // app. storeAccessDenied() (:3609) returns 403.
    $store = $this->resolveOwnedStore($request->store_id);

    if (!$store) {
        return $this->storeAccessDenied();
    }

    $order = Order::query()
        ->where('id', $request->order_id)
        ->where('store_id', $store->id)
        ->first();

    if (!$order) {
        return $this->error([
            'message' => __('Order not found!'),
        ]);
    }

    $note = new OrderNote();
    $note->order_id = $order->id;
    // NEW-order-note-guest-order-500. Was `$order->customer_id ?? 0`, which
    // yields the LITERAL 0 for a guest order -- orders.customer_id is 0, not
    // null, for a guest (SecurityTestCase::makeOrder mirrors the production
    // shape: customer_id 0, is_guest 1). order_notes.customer_id carries a
    // foreign key to customers.id
    // (database/migrations/2024_01_26_100237_create_order_notes_table.php:26)
    // and 0 is never a valid customers.id, so the insert violated the
    // constraint and the endpoint answered 500 for every guest order.
    // Measured: "SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN
    // KEY constraint failed ... values (1, 0, ...)".
    //
    // The column is nullable, so null is the representation the schema already
    // provides for "no customer". Pinned by
    // OrderNoteAuthorizationTest::test_api_note_create_and_delete_still_work_in_the_callers_own_store.
    $note->customer_id = empty($order->customer_id) ? null : $order->customer_id;
    $note->notes = trim((string) $request->note);
    $note->note_type = $request->note_type;

    /*
     * ملاحظات الإدارة/العميل ليست تغيير حالة الطلب.
     */
    $note->status = null;
    $note->store_id = $store->id;
    /*
     * P2-04-6. THE AUTHOR. The admin app's note endpoint is the second -- and last -- place a
     * human types a note's text; the merchant is authenticated here by `custom.auth` and the
     * store predicate above, so `\Auth::id()` is their own id and not request input.
     *
     * THE RESPONSE BELOW IS DELIBERATELY NOT WIDENED. It lists its keys explicitly, so adding
     * `created_by` would change the order-note payload the shipped app parses. The data is
     * recorded correctly either way, and letting the app DISPLAY it is a decision to take on
     * purpose -- tracked as parity gap G9, not absorbed as a side effect of this slice.
     */
    $note->created_by = \Auth::id();
    $note->save();

    return $this->success([
        'message' => __('Order note created successfully.'),
        'note' => [
            'id' => $note->id,
            'order_id' => $note->order_id,
            'customer_id' => $note->customer_id,
            'note' => $note->notes,
            'note_type' => $note->note_type,
            'status' => $note->status,
            'created_at' => $note->created_at?->toISOString(),
        ],
    ]);
}


public function DeleteOrderNote(Request $request)
{
    $validator = \Validator::make($request->all(), [
        'store_id' => 'required|integer',
        'order_id' => 'required|integer',
        'note_id' => 'required|integer',
    ]);

    if ($validator->fails()) {
        return $this->error([
            'message' => $validator->errors()->first(),
        ]);
    }

    // NEW-order-note-cross-tenant-delete -- the sibling of the write above, and
    // the same defect: the store came from a request field, so the three-way
    // note/order/store filter below only ever re-asserted the attacker's own
    // parameter. Any valid token could delete any store's order notes.
    // resolveOwnedStore() + 403, for the reasons given on AddOrderNote.
    $store = $this->resolveOwnedStore($request->store_id);

    if (!$store) {
        return $this->storeAccessDenied();
    }

    $order = Order::query()
        ->where('id', $request->order_id)
        ->where('store_id', $store->id)
        ->first();

    if (!$order) {
        return $this->error([
            'message' => __('Order not found!'),
        ]);
    }

    $note = OrderNote::query()
        ->where('id', $request->note_id)
        ->where('order_id', $order->id)
        ->where('store_id', $store->id)
        ->first();

    if (!$note) {
        return $this->error([
            'message' => __('Order note not found!'),
        ]);
    }

    $note->delete();

    return $this->success([
        'message' => __('Order note deleted successfully.'),
        'note_id' => (int) $request->note_id,
    ]);
}


















 public function updateStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_order_id' => [
                'required',
                'string',
                'max:191',
            ],

            /*
             * يمكن إرسال delivered_status وحده،
             * أو payment_status وحده،
             * أو الاثنين معًا.
             */
            'delivered_status' => [
                'nullable',
                'integer',
            /*
             * P2-07-12. BUILT FROM THE COLUMN'S OWN CONSTANTS, not typed out.
             *
             * `orders.delivered_status` holds NINE values and two of them are not fulfilment
             * states: 7 is Partially Paid (a payment state) and 8 is Pre Order (an order type),
             * both sharing the column. This read `in:0,1,2,3,4,5,6`, so the only client that
             * calls this endpoint could not ask for either -- while F29 had already taught
             * `Order::getOrderData()` to RENDER them, so the app could show a partially-paid
             * order and not request one.
             *
             * The web's own filter says why a hand-typed list is the wrong shape
             * (`order/partials/filters.blade.php:12-14`): "Typing a list by hand is how a filter
             * ends up offering 0-6 and quietly hiding those orders from the one screen a merchant
             * uses to find them." Deriving the rule from the constants means a tenth status
             * becomes filterable on its own.
             *
             * STILL VALIDATED: widening the set is not removing it. A value the column cannot
             * hold is still refused -- `test_a_status_the_column_does_not_hold_is_still_refused`.
             *
             * NO BUSINESS RULE CHANGES. This decides nothing about what a status means or when it
             * may change; it stops the endpoint refusing a value the column legitimately holds.
             */
                'in:' . implode(',', [
                    \App\Http\Controllers\HomeController::ORDER_STATUS_PENDING,
                    \App\Http\Controllers\HomeController::ORDER_STATUS_DELIVERED,
                    \App\Http\Controllers\HomeController::ORDER_STATUS_CANCEL,
                    \App\Http\Controllers\HomeController::ORDER_STATUS_RETURN,
                    \App\Http\Controllers\HomeController::ORDER_STATUS_CONFIRMED,
                    \App\Http\Controllers\HomeController::ORDER_STATUS_PICKED_UP,
                    \App\Http\Controllers\HomeController::ORDER_STATUS_SHIPPED,
                    \App\Http\Controllers\HomeController::ORDER_STATUS_PARTIALLY_PAID,
                    \App\Http\Controllers\HomeController::ORDER_STATUS_PRE_ORDER,
                ]),
                'required_without:payment_status',
            ],

            'payment_status' => [
                'nullable',
                'string',
                'in:Paid,Unpaid',
                'required_without:delivered_status',
            ],

            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /*
         * المتجر الحالي.
         *
         * هذه الدالة موجودة أصلًا في مشروعك وتستخدمها
         * في بقية الكنترولرات.
         */
        $storeId = getCurrentStore();

        if (empty($storeId)) {
            return response()->json([
                'success' => false,
                'message' => 'Current store could not be resolved.',
            ], 403);
        }
$storeId = getCurrentStore();
        /*
         * نبحث بالـproduct_order_id وضمن المتجر الحالي فقط.
         * هذا يمنع أدمن متجر من تعديل طلب تابع لمتجر آخر.
         */
        $order = Order::query()
            ->where('product_order_id', trim(
                (string) $request->input('product_order_id')
            ))
            ->where('store_id', $storeId)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        /*
         * P2-07-13 — THE WRITE SIDE, which had no permission check at all.
         *
         * The store above is resolved from `getCurrentStore()`, so the TENANT boundary was sound.
         * Nothing asked whether this principal MAY change an order. A staff member with no order
         * permission could therefore mark any order in their employer's store Delivered, or mark
         * it PAID -- and that second one changes what the merchant believes they are owed.
         *
         * THE GATE IS THE WEB'S OWN ABILITY, NOT A COPIED PERMISSION NAME, and that is the whole
         * point: `OrderPolicy::updateStatus` already requires `Manage Order`, already checks
         * `isPlatformAdmin` FIRST (because `isAbleTo()` refuses the platform operator and would
         * otherwise lock them out of their own endpoint), and already re-checks `sameStore`.
         * `OrderController` calls `$this->authorize('updateStatus', $order)` at four sites. Using
         * the same ability means the app and the panel cannot drift about who may change an
         * order -- which is exactly the drift P2-04-4 found between two of the web's own screens.
         *
         * 403 AND NEVER 401: the shipped Flutter app force-clears its session on any 401 and
         * treats 403 as "authorized but not permitted" with the session intact, so a 401 here
         * would log a merchant out for pressing a button they were never allowed to press.
         * `Gate::forUser(...)->allows()` rather than `authorize()` so the status is ours to choose
         * instead of whatever the exception handler renders for this route.
         */
        $actor = \Auth::user();

        if (! $actor instanceof \App\Models\User
            || ! \Illuminate\Support\Facades\Gate::forUser($actor)->allows('updateStatus', $order)) {
            return response()->json([
                'success' => false,
                'message' => __('You do not have permission to perform this action.'),
            ], 403);
        }

        $oldDeliveredStatus = (int) $order->delivered_status;
        $oldPaymentStatus = (string) $order->payment_status;

        $hasDeliveredStatus = $request->has('delivered_status')
            && $request->input('delivered_status') !== null;

        $hasPaymentStatus = $request->has('payment_status')
            && $request->input('payment_status') !== null;

        $newDeliveredStatus = $hasDeliveredStatus
            ? (int) $request->input('delivered_status')
            : $oldDeliveredStatus;

        $newPaymentStatus = $hasPaymentStatus
            ? (string) $request->input('payment_status')
            : $oldPaymentStatus;

        /*
         * إذا لم يتغير شيء، نعيد نجاح بدون تنفيذ حفظ غير ضروري.
         */
        if (
            $oldDeliveredStatus === $newDeliveredStatus
            && $oldPaymentStatus === $newPaymentStatus
            && !$request->filled('note')
        ) {
            return response()->json([
                'success' => true,
                'message' => 'Order already has the requested statuses.',
                'data' => $this->orderResponse($order),
            ]);
        }

        try {
            DB::transaction(function () use (
                $request,
                $order,
                $hasDeliveredStatus,
                $newDeliveredStatus,
                $hasPaymentStatus,
                $newPaymentStatus
            ) {
                if ($hasDeliveredStatus) {
                    $order->delivered_status = $newDeliveredStatus;

                    /*
                     * تحديث التاريخ المرتبط بالحالة.
                     */
                    switch ($newDeliveredStatus) {
                        case 0:
                            // Pending
                            break;

                        case 1:
                            // Delivered
                            $order->delivery_date = now();
                            break;

                        case 2:
                            // Cancel
                            $order->cancel_date = now();
                            break;

                        case 3:
                            // Return
                            $order->return_date = now();

                            /*
                             * 0 = none
                             * 1 = request
                             * 2 = approve
                             * 3 = cancel
                             */
                            $order->return_status = 2;
                            break;

                        case 4:
                            // Confirmed
                            $order->confirmed_date = now();
                            break;

                        case 5:
                            // Picked Up
                            $order->picked_date = now();
                            break;

                        case 6:
                            // Shipped
                            $order->shipped_date = now();
                            break;
                    }
                }

                if ($hasPaymentStatus) {
                    $order->payment_status = $newPaymentStatus;
                }

                if ($request->filled('note')) {
                    $order->delivery_comment = trim(
                        (string) $request->input('note')
                    );
                }

                $order->save();
            });

            $order->refresh();

            /*
             * الحدث الموجود أصلًا عندك.
             */
            event(new OrderPaymentStatus($order));

            Log::info('Admin API updated order statuses', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $order->store_id,
                'admin_id' => auth()->id(),

                'old_delivered_status' => $oldDeliveredStatus,
                'new_delivered_status' => $order->delivered_status,

                'old_payment_status' => $oldPaymentStatus,
                'new_payment_status' => $order->payment_status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully.',
                'data' => [
                    'order_id' => $order->id,
                    'product_order_id' => $order->product_order_id,

                    'previous_delivered_status' => $oldDeliveredStatus,
                    'previous_delivered_status_name' =>
                        $this->deliveredStatusName($oldDeliveredStatus),

                    'delivered_status' => (int) $order->delivered_status,
                    'delivered_status_name' =>
                        $this->deliveredStatusName(
                            (int) $order->delivered_status
                        ),

                    'previous_payment_status' => $oldPaymentStatus,
                    'payment_status' => $order->payment_status,

                    'updated_at' => $order->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin API failed to update order statuses', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $order->store_id,
                'admin_id' => auth()->id(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not update the order.',
            ], 500);
        }
    }

    private function deliveredStatusName(int $status): string
    {
        return match ($status) {
            0 => 'Pending',
            1 => 'Delivered',
            2 => 'Cancel',
            3 => 'Return',
            4 => 'Confirmed',
            5 => 'Picked Up',
            6 => 'Shipped',
            default => 'Unknown',
        };
    }

    private function orderResponse(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'delivered_status' => (int) $order->delivered_status,
            'delivered_status_name' => $this->deliveredStatusName(
                (int) $order->delivered_status
            ),
            'payment_status' => $order->payment_status,
            'updated_at' => $order->updated_at?->toISOString(),
        ];
    }
















/**
 * THE ADMIN APP'S NOTIFICATION LIST, NOW GATED ON THE SAME PERMISSION THE WEB BELL USES. (F181)
 *
 * WHAT WAS WRONG. This was `StoreNotification::where('store_id', $user->current_store)` with no
 * type filter and no permission test, behind `['custom.auth','AdminApiLog']` -- and `AdminApiLog`
 * only logs. So ANY `User` Sanctum token whose `current_store` is that store -- a staff member
 * holding nothing but `Manage Products` -- read every notification's title and message, including
 * `order_created` and `order_paid`, which carry `final_price`, `payment_type` and
 * `payment_status`. The merchant WEB bell gates each type
 * (`MerchantNotificationController::TYPE_PERMISSIONS`); the API did not, so the registry was not
 * the only door and the API was the open one.
 *
 * THE FIX REUSES THAT REGISTRY RATHER THAN RESTATING IT. `TYPE_PERMISSIONS` is the single place a
 * type's gate is declared, and `readableNotificationTypes()` below applies it with the operator
 * tested FIRST -- `isAbleTo()` alone refuses the platform operator, which is this codebase's
 * standing trap, so reversing those two limbs is a lockout rather than a tightening.
 *
 * WHY THIS DOES NOT BREAK THE FLUTTER BADGE, which is the owner's explicit condition and is
 * measured rather than assumed, twice over:
 *
 *   1. THE REGISTRY IS COMPLETE. `StoreNotificationService` writes exactly three types --
 *      `order_created` (:29), `order_paid` (:103), `payment_needs_attention` (:249) -- and all
 *      three are in `TYPE_PERMISSIONS`. So `whereIn('type', ...)` hides nothing that exists, and a
 *      caller holding `Manage Order` sees an IDENTICAL list and an IDENTICAL count to before. For
 *      the ordinary merchant this change is a no-op. A fourth type added without a permission
 *      entry would silently vanish from this endpoint, so a ratchet asserts the registry covers
 *      every type the service writes.
 *   2. A COUNT THAT DROPS IS HANDLED BY THE APP ITSELF. For a staff member who may read none of
 *      the three, the count goes to 0. `notification_controller.dart`'s `_calculateUnreadCount()`
 *      computes `currentCount - lastSavedCount` and shows it only when positive -- and when
 *      `currentCount < lastCount` it RE-BASELINES: it overwrites the stored total, persists it and
 *      shows 0. So a drop is a one-time re-baseline, not a stuck or negative badge. The counter is
 *      not broken by this; it is given a correct set to count.
 *
 * `count` KEEPS ITS MEANING. It was "every notification in this store" and is now "every
 * notification in this store this caller may read" -- the same arithmetic over the permitted set,
 * which is what keeps the badge internally consistent. It is deliberately NOT narrowed to unread:
 * the app diffs totals and never reads `is_read`, so counting unread here would change the
 * arithmetic rather than the set.
 *
 * THE NULL GUARD IS NOT DEFENSIVE NOISE. `custom.auth` logs a token's owner into the guard matching
 * the TOKENABLE CLASS, so a Customer or DeliveryBoy token passes the middleware while
 * `Auth::user()` -- the `web` guard -- stays null. Today that is a 500 on `$user->current_store`.
 * It becomes a structured refusal, the same shape `currency()` below already uses.
 */
public function noti(Request $request)
{
    $user = Auth::user();

    if (! $user) {
        return $this->error(['message' => __('User not found.')]);
    }

    $storeId = $user->current_store;
    $types = $this->readableNotificationTypes($user);

    /* `whereIn('type', ...)` rather than filtering after the fetch, so the LIMIT applies to rows
       the caller may actually see -- the web bell's own reasoning at
       MerchantNotificationController::scope(). An empty $types yields no rows, which is the
       intended answer for a caller who may read none of the three. */
    $scope = fn () => StoreNotification::whereIn('type', $types)->where('store_id', $storeId);

    $notifications = $scope()->latest()->paginate(20);
    $count = (int) $scope()->count();

    return response()->json([
        'status' => true,
        'data' => $notifications,
        'count' => $count,
    ]);
}

/**
 * The notification types this caller may read, from the web bell's own registry.
 *
 * OPERATOR FIRST. `users.type` is a string comparison and there is no super-admin bypass in
 * Laratrust, so `isAbleTo()` alone refuses the platform operator. Same ordering as
 * `MerchantNotificationController::readableTypes()`, and the same registry, so a new type is gated
 * in one place rather than two.
 *
 * @return array<int, string>
 */
private function readableNotificationTypes($user): array
{
    $registry = \App\Http\Controllers\MerchantNotificationController::TYPE_PERMISSIONS;

    if (strtolower(trim((string) $user->type)) === 'super admin') {
        return array_keys($registry);
    }

    $readable = [];

    foreach ($registry as $type => $permission) {
        if ($user->isAbleTo($permission)) {
            $readable[] = $type;
        }
    }

    return $readable;
}




    public function base_url(Request $request)
    {
        $img_url = get_file('themes');
        $data =  explode('themes', $img_url);

        return $this->success(['image_url' => $data[0], 'payment_url' => url('/')]);
    }

    public function currency(Request $request)
    {
        $user = Auth::user();

        // NEW-unauthenticated-500s. CustomAuth now rejects anonymous callers
        // before this point, so these guards are defence in depth: they turn a
        // null dereference into a structured 401/404 rather than a 500 that
        // leaks a stack trace.
        if (!$user) {
            return $this->error(
                ['message' => __('Unauthenticated user!')],
                __('Unauthenticated.'),
                401
            );
        }

        $currentstore = $user->current_store;

        $store = Store::find($currentstore);

        if (!$store) {
            return $this->error(['message' => __('Store not found!')], __('fail'), 404);
        }
        
        $array['currency'] = \App\Models\Utility::GetValByName('CURRENCY', $store->id);
        $array['currency_name'] = \App\Models\Utility::GetValByName('CURRENCY_NAME', $store->id);

        return $this->success($array);
    }

    public function login(Request $request)
    {
        $rules = [
            'email' => 'required|email',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        if (!empty($request->password)) {
            $user = Auth::attempt(['email' => $request->email, 'password' => $request->password]);
            if (!$user) {
                return $this->error(['message' => __('Invalid login details')]);
            }
            $user = Auth::user();

            $user = User::find($user->id);

            // ACCOUNT STATE, which this door did not check at all.
            //
            // The WEB login refuses both of these (AuthenticatedSessionController:68-74): an
            // inactive account, and a login disabled either on the user or on the owner whose
            // tenant they belong to. The API login validated the email, called Auth::attempt and
            // issued a token -- so a merchant who disabled an employee in the web UI found that
            // the employee could still sign into the Flutter app, with a token that never
            // expires. This change makes that worse if left alone, because the employee would now
            // also be handed a permission list.
            //
            // The refusal keeps `error()`'s DEFAULT shape -- HTTP 200 with `status: 0` -- rather
            // than introducing a status code on a path every client calls. A 401 here would be
            // actively wrong: the shipped app force-clears its session on any 401, and this is a
            // login, so the only thing that would change is which message it shows.
            // MIRRORED FROM THE WEB CHECK RATHER THAN REINVENTED, including the part that is easy
            // to get wrong: the web EXEMPTS `super admin` from both switches
            // (`&& $user->type != 'super admin'`). A first draft of this guard omitted that and
            // would have locked the platform operator out of the API the moment either column read
            // 0 on their row. The owner's switch has no such exemption in the web check and gets
            // none here either -- disabling a merchant disables their staff with them.
            //
            // `== 0` not `=== 0`: `users.is_active` is a STRING column with default '1'
            // (2014_10_12_000000_create_users_table.php:39), so a strict integer comparison would
            // never match a de-activated account. `is_enable_login` is an integer
            // (2024_03_15_055922, default 1). Matching the web's loose comparison is what makes
            // the two doors agree.
            $isOperator = \App\Policies\UserPolicy::isOperator($user);
            $owner = $user->created_by ? User::find($user->created_by) : null;

            $refused = (! $isOperator && $user->is_active == 0)
                || (! $isOperator && $user->is_enable_login == 0)
                || ($owner && $owner->is_enable_login == 0);

            if ($refused) {
                Auth::logout();

                return $this->error([
                    'message' => __('Your Account is disable,please contact your Administrator.'),
                ]);
            }
        } else {
            return $this->error(['message' => __('Invalid login details')]);
        }

        $user_data = User::find($user->id);

        $user_array['id'] = $user_data->id;
        $user_array['name'] = $user_data->name;
        $user_array['email'] = $user_data->email;
        $user_array['image'] = !empty($user_data->profile_image) ? $user_data->profile_image : "themes/style/uploads/require/user.png";
        $user_array['mobile'] = $user_data->mobile;
        $user_array['current_store'] = $user_data->current_store;

        // P2-07-13 / WS K13. ADDITIVE, and that is a requirement rather than a preference: every
        // field above is read by the shipped Flutter app and by Lynomia Chat, so none is removed,
        // renamed or retyped here. A client that does not know these two keys ignores them.
        //
        // Before this, `adminlogin` told the app nothing about what the employee may do, which is
        // the structural reason the app had no permission model at all and showed every screen to
        // everyone. The list is resolved against the store the principal is actually in --
        // see App\Services\MerchantPermissions for why `allPermissions()` could not be used as-is
        // -- and the operator gets the wildcard, because isAbleTo() refuses them.
        $user_array['user_type'] = $user_data->type;
        $user_array['permissions'] = MerchantPermissions::names($user_data, $user_data->current_store);

        $user->tokens()->delete();
        $token = $user->createToken('auth_token', ['merchant'])->plainTextToken;
        $user_array['token'] = $token;
        $user_array['token_type'] = 'Bearer';
        return $this->success($user_array);
    }





public function logouto(Request $request)
{
    $authorization = $request->header('Authorization');

    if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
        return $this->error(
            ['message' => __('Unauthenticated.')],
            __('Unauthenticated.'),
            401
        );
    }

    $plainTextToken = substr($authorization, 7);
    $parts = explode('|', $plainTextToken, 2);

    $tokenId = $parts[0] ?? null;
    $tokenValue = $parts[1] ?? null;

    if (!$tokenId || !$tokenValue) {
        return $this->error(
            ['message' => __('Unauthenticated.')],
            __('Unauthenticated.'),
            401
        );
    }

    $token = \DB::table('personal_access_tokens')
        ->where('id', $tokenId)
        ->first();

    if (
        !$token ||
        !hash_equals($token->token, hash('sha256', $tokenValue))
    ) {
        return $this->error(
            ['message' => __('Unauthenticated.')],
            __('Unauthenticated.'),
            401
        );
    }

    \DB::table('personal_access_tokens')
        ->where('id', $tokenId)
        ->delete();

    return $this->success([
        'message' => __('Logged out successfully.')
    ]);
}


    public function dashboard(Request $request)
    {
        $user = Auth::user();

        // NEW-unauthenticated-500s: defence in depth behind the CustomAuth fix.
        if (!$user) {
            return $this->error(
                ['message' => __('Unauthenticated user!')],
                __('Unauthenticated.'),
                401
            );
        }

        $currentstore = $user->current_store;

        // P2-07-13 / WS K13. Resolved ONCE, against the CURRENT store, and used for BOTH halves:
        // what the app is told it may do, and what this handler actually serves. Reporting without
        // enforcing would be a hidden button, not a control -- before this change `isAbleTo`
        // appeared nowhere in this controller and `routes/api.php` declared no permission
        // middleware on any of its 127 admin routes, so a staff token reached every figure here.
        //
        // Reported on the dashboard as well as at login BECAUSE PERMISSIONS ARE PER STORE: the app
        // calls this endpoint after a store switch, and a list handed out once at login could not
        // express the change.
        // ONCE means once. An earlier version of these four lines called names() FOUR times --
        // directly, and again inside each allows()/allowsAny() -- at two queries apiece, while
        // this comment claimed otherwise. held() answers from the list already resolved.
        $permissions = MerchantPermissions::names($user, $currentstore);
        $mayReadOrders = MerchantPermissions::held($permissions, ['Manage Order', 'Show Order']);
        $mayReadProducts = MerchantPermissions::held($permissions, ['Manage Products']);
        // `Show Coupon` is a real granted name, so a "view coupons, do not edit" role keeps the
        // dashboard's coupon block -- the same view-or-manage pair the order gate above uses.
        $mayReadCoupons = MerchantPermissions::held($permissions, ['Manage Coupon', 'Show Coupon']);
        // Store traffic is business data too. The web gates its dashboard on `Manage Dashboard`
        // and carries a `Manage Store Analytics` permission; without this the endpoint that
        // withholds everything else still handed a zero-permission staff token 15 days of
        // pageviews and unique-visitor counts.
        $mayReadAnalytics = MerchantPermissions::held(
            $permissions,
            ['Manage Store Analytics', 'Manage Dashboard']
        );

        $DashboardData = [];
        $DashboardData['permissions'] = $permissions;
        $DashboardData['user_type'] = $user->type;
        //user info
        $users = [];
        $userdata = User::find($user->id);
        if (!empty($userdata)) {
            $users['name'] = $userdata->name;
            $users['image'] = $userdata->profile_image;
        }
        $DashboardData['user'] = $users;

        //Storedata dropdown
        $storearray = [];
        $Allstore = Store::where('created_by', $user->id)->get();

        if (!empty($Allstore)) {
            foreach ($Allstore as $key => $value) {

                $storearray[$key]['id'] = $value->id;
                $storearray[$key]['name'] = $value->name;
            }
        }
        $DashboardData['store'] = $storearray;

        //statics
        $statics = [];
        $store = Store::find($currentstore);

        // $store was dereferenced unconditionally; a user whose current_store no
        // longer exists produced a 500 here.
        if ($store) {
            $statics['url'] = route('landing_page', $store->slug);
            $statics['name'] = $store->name;
        } else {
            $statics['url'] = null;
            $statics['name'] = null;
        }

        $DashboardData['qr-code'] = $statics;

        // The `if ($store)` guard above was added because a user whose current_store no longer
        // exists produced a 500 -- but only the qr-code block was guarded, and every query below
        // still dereferenced $store->id unconditionally, so the 500 simply moved four lines down.
        // Measured: test_a_dashboard_for_a_missing_store_does_not_500.
        if (! $store) {
            return $this->error(
                ['message' => __('Store not found!')],
                __('fail'),
                404
            );
        }

        //product
        // P2-07-13. A figure the principal may not see is OMITTED, not zeroed. A withheld count
        // served as 0 is wrong information rather than withheld information, and the app renders
        // `?? "0"`, so a zero here would have read as "this store has no orders". The companion
        // Flutter change reads `permissions` and shows the tile as not-permitted instead.
        if ($mayReadProducts) {
            $DashboardData['products'] = Product::where('store_id', $store->id)->count();
        }

        if ($mayReadOrders) {
            $DashboardData['sales'] = Order::where('store_id', $store->id)->where('delivered_status', 1)->count();
            $DashboardData['order'] = Order::where('store_id', $store->id)->count();
        }

        //top products
        // Withheld wholesale rather than field by field: these are full Product models, and they
        // carry cost_price -- the store's margin, not just its catalogue.
        if ($mayReadProducts) {
            $DashboardData['product'] = Product::orderBy('created_at', 'Desc')->where('store_id', $store->id)->where('variant_product', 0)->limit('2')->get();
        }
        //recent order
        // `final_price` is money. Withheld from a principal who may not read orders.
        $recentOrder = [];
        $orders = $mayReadOrders
            ? Order::orderBy('id', 'DESC')->where('store_id', $store->id)->limit(4)->get()
            : collect();
        if ($mayReadOrders) {
            foreach ($orders as $key => $value) {
                $recentOrder[$key]['id'] = $value->id;
                $recentOrder[$key]['product_order_id'] = $value->product_order_id;
                $recentOrder[$key]['user_name'] = $value->user_name;
                $recentOrder[$key]['order_date'] = date('M d, Y', strtotime($value->order_date));
                $recentOrder[$key]['final_price'] = $value->final_price;
                $recentOrder[$key]['delivery_status'] = $value->delivered_status_string;
                $recentOrder[$key]['currency'] = Utility::GetValByName('CURRENCY_NAME', $store->id);
            }
            $DashboardData['recent_order'] = $recentOrder;
        }

        //coupons
        // `discount_amount` is money. Withheld from a principal who may not read coupons.
        $recentcoupon = [];
        $coupons = $mayReadCoupons
            ? Coupon::orderBy('id', 'DESC')->where('store_id', $store->id)->limit(3)->get()
            : collect();
        if ($mayReadCoupons) {
            foreach ($coupons as $key => $value) {

                $recentcoupon[$key]['id'] = $value->id;
                $recentcoupon[$key]['coupon_name'] = $value->coupon_name;
                $recentcoupon[$key]['coupon_code'] = $value->coupon_code;
                $recentcoupon[$key]['coupon_type'] = $value->coupon_type;
                $recentcoupon[$key]['discount_amount'] = $value->discount_amount;
            }
            $DashboardData['coupons'] = $recentcoupon;
        }

        //order chart
        $arrDuration = [];
        $arrParam = ['duration' => 'week'];


        if ($arrParam['duration']) {
            if ($arrParam['duration'] == 'week') {
                $previous_week = strtotime("-1 week +1 day");

                for ($i = 0; $i < 7; $i++) {
                    $arrDuration[date('Y-m-d', $previous_week)] = date('d-M', $previous_week);
                    $previous_week = strtotime(date('Y-m-d', $previous_week) . " +1 day");
                }
            }
        }
        $arrTask = [];
        $i = 0;
        $arrTask[$i]['date'] = [];
        $arrTask[$i]['order'] = [];
        // Not merely discarded below -- not RUN. Seven order COUNT queries for a principal who
        // may not read orders is work done to throw away.
        foreach ($mayReadOrders ? $arrDuration : [] as $date => $label) {
            $data = Order::select(\DB::raw('count(*) as total'))->where('store_id', $store->id)->whereDate('created_at', '=', $date)->first();


            $arrTask[$i]['date'] = $label;
            $arrTask[$i]['order'] = $data->total;
            $i++;
        }

        if ($mayReadOrders) {
            $DashboardData['orderchart'] = $arrTask;
        }

        //visitor chart
        $visistorchart = [];
        $arrDuration = [];
        $arrParam = ['duration' => 'month'];
        if ($arrParam['duration']) {

            if ($arrParam['duration'] == 'month') {
                $previous_month = strtotime("-2 week +0 day");
                for ($i = 0; $i < 15; $i++) {
                    $arrDuration[date('Y-m-d', $previous_month)] = date('d-M', $previous_month);
                    $previous_month                              = strtotime(date('Y-m-d', $previous_month) . " +1 day");
                }
            }
        }
        $arrTask          = [];
        $i = 0;
        $arrTask[$i]['date'] = [];
        $arrTask[$i]['pageview']  = [];

        // Thirty queries over shetabit_visits, not run for a principal who may not read them.
        foreach ($mayReadAnalytics ? $arrDuration : [] as $date => $label) {
            $data['visitor'] = \DB::table('shetabit_visits')->select(\DB::raw('count(*) as total'))->where('store_id', $store->id)->whereDate('created_at', '=', $date)->first();
            $uniq            = \DB::table('shetabit_visits')->select('ip')->distinct()->where('store_id', $store->id)->whereDate('created_at', '=', $date)->get();

            $data['unique']           = $uniq->count();
            $arrTask[$i]['date']      = $label;
            $arrTask[$i]['pageview']        = $data['visitor']->total;
            $arrTask[$i]['unique_pageview'] = $data['unique'];
            $i++;
        }

        if ($mayReadAnalytics) {
            $DashboardData['visitorchart'] = $arrTask;
        }

        return $this->success($DashboardData);
    }

    public function CategoryList(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
        return $this->storeAccessDenied();
    }

        $Category = Category::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($Category)) {
            return $this->success($Category);
        } else {
            return $this->error(['message' => __('Category Data not found.')]);
        }
    }

public function ProductList(Request $request)
{
    $store = $this->resolveOwnedStore($request->store_id);
    if (empty($store)) {
        return $this->storeAccessDenied();
    }

        // Serves `cost_price` explicitly below -- the store's margin, not just its catalogue.
        if ($refusal = $this->refuseWithoutPermission(['Manage Products'], $store->id)) {
            return $refusal;
        }


    $Products = Product::where('store_id', $store->id)
        ->where('product_type', null)
        ->with('ProductData:id,name')
        ->orderBy('id', 'desc')
        ->paginate(10);

    if ($Products->isNotEmpty()) {
        // ✅ 1. جلب كود العملة الحالية الفعلي من الإعدادات
        $currencyCode = \App\Models\Utility::GetValueByName('defult_currancy', $store->id) ?? 'USD';

        // ✅ 2. جلب رمز العملة المقابل من جدول currency_rates النشط
        $currencyRate = \App\Models\CurrencyRate::where('store_id', $store->id)
            ->where('currency_code', $currencyCode)
            ->where('is_active', 1)
            ->first();

        // ✅ 3. تحديد الرمز: من الجدول، أو الافتراضي، أو كود العملة نفسه كحل أخير
        $currencySymbol = $currencyRate ? $currencyRate->symbol : ($currencyCode === 'USD' ? '$' : $currencyCode);

        $formatNumber = function ($value) {
            return number_format((float) ($value ?? 0), 2, '.', '');
        };

        // P2-07-10. ADDITIVE, and the app needs it to agree with the web about one word.
    //
    // THE WEB DOES NOT TRUST `products.stock_status` FOR A TRACKED PRODUCT. It reads that column
    // only when `track_stock == 0`; otherwise it derives the badge from the live quantity --
    // `$product->product_stock <= $settings['out_of_stock_threshold'] ? 'Out of stock' : 'In
    // stock'` (resources/views/product/list.blade.php:115-129). That is not a style difference: a
    // measured fact about this schema is that `products.stock_status` is written ONLY from
    // merchant input at create and update (`ProductController:701`, `:1744`, and the API's own
    // :2503/:2647) and is NEVER recomputed when stock drains through an order. So for a tracked
    // product it goes stale with the first shipment -- a product created "in_stock" and sold down
    // to zero still carries `in_stock` in the column.
    //
    // The app read that column unconditionally, so it told a merchant a sold-out product was In
    // stock while the web told them it was Out of stock. The app can now apply the web's rule, and
    // this is the one input it was missing: the threshold is a per-store SETTING, not a constant,
    // so hardcoding 0 client-side would be right only for stores that never changed it.
    $outOfStockThreshold = (int) (
        getAdminAllSetting(null, $store->id)['out_of_stock_threshold'] ?? 0
    );

    $Products->getCollection()->transform(function ($product) use ($store, $formatNumber) {
            $product->category = $product->ProductData;
            unset($product->ProductData);

            $product->price = $formatNumber($product->getRawOriginal('price'));
            $product->sale_price = $formatNumber($product->getRawOriginal('sale_price'));
            $product->cost_price = $formatNumber($product->getRawOriginal('cost_price'));

            $rawFinalPrice = $product->getRawOriginal('final_price') ?? $product->getRawOriginal('price') ?? 0;
            
            $currentAppends = $product->getAppends();
            $product->setAppends(array_diff($currentAppends, ['final_price']));
            
            $product->final_price = $formatNumber($rawFinalPrice);

            return $product;
        });

        return response()->json([
            'status' => 1,
            'message' => 'successfull',
            'data' => [
                'current_page' => $Products->currentPage(),
                'data' => $Products->items(), // البيانات المحولة
                'first_page_url' => $Products->url(1),
                'from' => $Products->firstItem(),
                'last_page' => $Products->lastPage(),
                'last_page_url' => $Products->url($Products->lastPage()),
                'next_page_url' => $Products->nextPageUrl(),
                'path' => $Products->path(),
                'per_page' => $Products->perPage(),
                'prev_page_url' => $Products->previousPageUrl(),
                'to' => $Products->lastItem(),
                'total' => $Products->total(),
                'currency_code' => $currencyCode,      // ✅ إضافة مفيدة: إرجاع كود العملة أيضاً
                'currency_symbol' => $currencySymbol,  // ✅ الرمز الصحيح والديناميكي
                // P2-07-10, additive: the per-store threshold the WEB compares `product_stock`
                // against for a tracked product. See the note above the transform.
                'out_of_stock_threshold' => $outOfStockThreshold,
            ]
        ], 200);

    } else {
        return $this->error(['message' => __('Product Data not found.')]);
    }
}
public function VariantList(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
        return $this->storeAccessDenied();
    }

        // Variant rows carry their own prices and stock.
        if ($refusal = $this->refuseWithoutPermission(['Manage Products'], $store->id)) {
            return $refusal;
        }


        $ProductVariant = ProductVariant::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($ProductVariant)) {
            return $this->success($ProductVariant);
        } else {
            return $this->error(['message' => __('Product Variant Data not found.')]);
        }
    }





public function OrderList(Request $request)
{
    $request->validate([
        'store_id' => ['required', 'integer'],
        // P2-07-12: all nine values the column holds -- see updateStatus() for the measurement.
        'delivered_status' => [
            'nullable',
            'integer',
            'in:' . implode(',', [
                \App\Http\Controllers\HomeController::ORDER_STATUS_PENDING,
                \App\Http\Controllers\HomeController::ORDER_STATUS_DELIVERED,
                \App\Http\Controllers\HomeController::ORDER_STATUS_CANCEL,
                \App\Http\Controllers\HomeController::ORDER_STATUS_RETURN,
                \App\Http\Controllers\HomeController::ORDER_STATUS_CONFIRMED,
                \App\Http\Controllers\HomeController::ORDER_STATUS_PICKED_UP,
                \App\Http\Controllers\HomeController::ORDER_STATUS_SHIPPED,
                \App\Http\Controllers\HomeController::ORDER_STATUS_PARTIALLY_PAID,
                \App\Http\Controllers\HomeController::ORDER_STATUS_PRE_ORDER,
            ]),
        ],
        'payment_status' => ['nullable', 'string'],
        'from_date' => ['nullable', 'date'],
        'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
    ]);

    $store = $this->resolveOwnedStore($request->store_id);

    if (!$store) {
        return $this->storeAccessDenied();
    }
    // `final_price` is set on every row of the paginator below.
    if ($refusal = $this->refuseWithoutPermission(['Manage Order', 'Show Order'], $store->id)) {
        return $refusal;
    }


    $query = Order::where('store_id', $store->id);

    if ($request->has('delivered_status') && $request->delivered_status !== null && $request->delivered_status !== '') {
        $query->where('delivered_status', (int) $request->delivered_status);
    }

    if ($request->filled('payment_status')) {
        $query->where('payment_status', $request->payment_status);
    }

    if ($request->filled('from_date')) {
        $query->whereDate('order_date', '>=', $request->from_date);
    }

    if ($request->filled('to_date')) {
        $query->whereDate('order_date', '<=', $request->to_date);
    }

    $orders = $query
        ->orderByDesc('id')
        ->paginate($request->input('per_page', 10));

    // ✅ 1. جلب كود العملة الحالية الفعلي من الإعدادات
    $currencyCode = \App\Models\Utility::GetValueByName('defult_currancy', $store->id) ?? 'USD';

    // ✅ 2. جلب رمز العملة المقابل من جدول currency_rates النشط
    $currencyRate = \App\Models\CurrencyRate::where('store_id', $store->id)
        ->where('currency_code', $currencyCode)
        ->where('is_active', 1)
        ->first();

    // ✅ 3. تحديد الرمز: من الجدول، أو الافتراضي، أو كود العملة نفسه كحل أخير
    $currencySymbol = $currencyRate ? $currencyRate->symbol : ($currencyCode === 'USD' ? '$' : $currencyCode);

    $formatNumber = function ($value) {
        return number_format((float) ($value ?? 0), 2, '.', '');
    };

    $orders->getCollection()->transform(function ($order) use ($formatNumber) {
        $order->product_price = $formatNumber($order->product_price);
        $order->delivery_price = $formatNumber($order->delivery_price);
        $order->final_price = $formatNumber($order->final_price);
        $order->coupon_price = $formatNumber($order->coupon_price ?? 0);
        $order->tax_price = $formatNumber($order->tax_price ?? 0);

        return $order;
    });

    // ✅ 4. بناء استجابة JSON مخصصة لضمان ظهور currency_symbol بشكل صحيح ومضمون
    return response()->json([
        'status' => 1,
        'message' => 'successfull',
        'data' => [
            'current_page' => $orders->currentPage(),
            'data' => $orders->items(), // البيانات المحولة
            'first_page_url' => $orders->url(1),
            'from' => $orders->firstItem(),
            'last_page' => $orders->lastPage(),
            'last_page_url' => $orders->url($orders->lastPage()),
            'next_page_url' => $orders->nextPageUrl(),
            'path' => $orders->path(),
            'per_page' => $orders->perPage(),
            'prev_page_url' => $orders->previousPageUrl(),
            'to' => $orders->lastItem(),
            'total' => $orders->total(),
            'currency_code' => $currencyCode,      // ✅ إضافة مفيدة: إرجاع كود العملة أيضاً
            'currency_symbol' => $currencySymbol,  // ✅ الرمز الصحيح والديناميكي
        ]
    ], 200);
}








    public function OrderList_backup(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $orders = Order::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($orders)) {
            return $this->success($orders);
        } else {
            return $this->error(['message' => __('Order Data not found.')]);
        }
    }

    public function CouponList(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
        return $this->storeAccessDenied();
    }

        // Returns full Coupon models, `discount_amount` among them.
        if ($refusal = $this->refuseWithoutPermission(['Manage Coupon', 'Show Coupon'], $store->id)) {
            return $refusal;
        }


        $coupons = Coupon::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($coupons)) {
            return $this->success($coupons);
        } else {
            return $this->error(['message' => __('Coupon Data not found.')]);
        }
    }

    public function ShippingList(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
        return $this->storeAccessDenied();
    }

        $shipping = Shipping::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($shipping)) {
            return $this->success($shipping);
        } else {
            return $this->error(['message' => __('Shipping Data not found.')]);
        }
    }

    public function TaxList(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
        return $this->storeAccessDenied();
    }

        $tax = Tax::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($tax)) {
            return $this->success($tax);
        } else {
            return $this->error(['message' => __('Tax Data not found.')]);
        }
    }

    public function AddCategory(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'image' => 'required',
                'icon_image' => 'required',
                'trending' => 'required',
                'status' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $dir = 'uploads/' . $store->id;
        if ($request->image) {
            $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->image->getClientOriginalName());
            $path = Utility::upload_file($request, 'image', $fileName, $dir, []);
            if ($path['flag'] == 0) {
                $msg['flag'] = 'error';
                $msg['msg'] =  $path['msg'];

                return $msg;
            }
        }

        if ($request->icon_image) {
            $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->icon_image->getClientOriginalName());
            $paths = Utility::upload_file($request, 'icon_image', $fileName, $dir, []);
            if ($paths['flag'] == 0) {
                $msg['flag'] = 'error';
                $msg['msg'] =  $paths['msg'];

                return $msg;
            }
        }

        $Category = new Category();
        $Category->name         = $request->name;
        $Category->image_url    = $path['full_url'];
        $Category->image_path   = $path['url'];
        $Category->icon_path    = $paths['url'];
        $Category->trending     = $request->trending;
        $Category->status       = $request->status;
        $Category->store_id     = $request->store_id;
        $Category->save();

        return $this->success(['message' => __('Maincategory added successfully!')]);
    }

    public function UpdateCategory(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'category_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $Category = Category::where('id', $request->category_id)
            ->where('store_id', $store->id)
            ->first();
        if (!empty($Category)) {
            $dir = 'uploads/' . $store->id;
            if ($request->image) {
                $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->image->getClientOriginalName());
                $path = Utility::upload_file($request, 'image', $fileName, $dir, []);
                if ($path['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $path['msg'];

                    return $msg;
                }
                $Category->image_url    = $path['full_url'];
                $Category->image_path   = $path['url'];
            }

            if ($request->icon_image) {
                $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->icon_image->getClientOriginalName());
                $paths = Utility::upload_file($request, 'icon_image', $fileName, $dir, []);
                if ($paths['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $paths['msg'];

                    return $msg;
                }
                $Category->icon_path    = $paths['url'];
            }
            if (!empty($request->name)) {
                $Category->name         = $request->name;
            }
            if (!empty($request->trending)) {
                $Category->trending     = $request->trending;
            }
            $Category->save();

            return $this->success(['message' => __('Maincategory Updated successfully!')]);
        } else {
            return $this->error(['message' => __('Maincategory not found!')]);
        }
    }

    public function DeleteCategory(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'category_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $Category = Category::where('id', $request->category_id)
            ->where('store_id', $store->id)
            ->first();

        if (!empty($Category)) {
            /*
             * OWNER DECISION D13 -- THE THIRD CATEGORY-DELETE PATH, AND THE ONE A CLIENT CALLS.
             * P1-INV-12.
             *
             * `products.category_id` is `ON DELETE CASCADE` against `categories.id`
             * (database/migrations/2023_12_26_092822_create_products_table.php:55), so
             * `$Category->delete()` below removes every product in the category IN THE DATABASE.
             * This endpoint, `DELETE api/admin/deletecategory`, is the one the shipped admin
             * Flutter app calls -- so of the three paths that can fire that cascade
             * (CategoryController::destroy, the BulkDelete addon route, and this) it is the one a
             * merchant is most likely to reach, and it had no refusal at all.
             *
             * SHAPE DELIBERATELY UNCHANGED. `ApiResponser::error()` answers HTTP 200 with
             * `{status: 0, message}`, which is the envelope the app already renders for
             * "Maincategory data not found." two lines down. No new status code, and specifically
             * NOT a 401: repo B's lib/network/network.dart forces a logout that clears
             * preferences on any 401.
             *
             * RAW COUNT, no store predicate: the foreign key does not read `store_id`, and
             * `Product` is `Cachable`, so a stale Eloquent read could report zero against a
             * populated category. Same reasoning as the guard in CategoryController::destroy.
             */
            /*
             * AND THE CHILD GUARD THE WEB TWIN HAS AND THIS PATH DID NOT. Raised by the
             * independent authorization review of the D13 change and verified separately.
             *
             * `CategoryController::destroy` refuses a category that has child categories
             * (CategoryController.php:460-463). This handler had no such predicate, so a merchant
             * could delete a parent through the shipped app and leave its children behind.
             *
             * ORPHANED, NOT CASCADED, and the difference is why this is integrity rather than
             * loss: `categories.parent_id` carries NO foreign key
             * (2023_12_25_093024_create_categories_table.php:21 is a plain `unsignedBigInteger`
             * defaulting to 0), so the children survive holding a `parent_id` that resolves to
             * nothing. `buildCategoryTree()` (app/Helper/helper.php) walks by `parent_id`, so such
             * a child drops out of the tree while remaining a real row with real products under
             * it -- invisible in the UI and impossible to reach.
             *
             * Scoped to the category's OWN store, like the web twin: for an own-store category the
             * two values are the same, and asking the attacker's store whether the victim's
             * category has children is the bug P1-INV-08 fixed there.
             */
            $hasChildCategory = \Illuminate\Support\Facades\DB::table('categories')
                ->where('parent_id', $Category->id)
                ->where('store_id', $Category->store_id)
                ->exists();

            if ($hasChildCategory) {
                return $this->error([
                    'message' => __('This category has child categories and cannot be deleted.'),
                ]);
            }

            $productsInCategory = \Illuminate\Support\Facades\DB::table('products')
                ->where('category_id', $Category->id)
                ->count();

            if ($productsInCategory > 0) {
                \Illuminate\Support\Facades\Log::warning(
                    'api category delete refused: products would be cascade-deleted with it',
                    [
                        'category_id' => $Category->id,
                        'store_id' => $store->id,
                        'products_in_category' => $productsInCategory,
                        'user_id' => optional(auth()->user())->id,
                    ]
                );

                return $this->error([
                    'message' => __('This category still contains :count product(s) and cannot be deleted. Move those products to another category first.', ['count' => $productsInCategory]),
                ]);
            }

            \App\Services\StoredUpload::delete($Category->image_path, auth()->user()?->creatorId());
            \App\Services\StoredUpload::delete($Category->icon_path, auth()->user()?->creatorId());

            $Category->delete();
            return $this->success(['message' => __('Maincategory deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Maincategory data not found.')]);
        }
    }

    public function AddCoupon(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'coupon_name' => 'required',
                'coupon_type' => 'required',
                'discount_amount' => 'required',
                'coupon_limit' => 'required',
                'coupon_expiry_date' => 'required',
                'coupon_code' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }


        $coupon                    = new Coupon();
        $coupon->coupon_name       = $request->coupon_name;
        $coupon->coupon_type       = $request->coupon_type;
        $coupon->discount_amount   = $request->discount_amount;
        $coupon->coupon_limit      = $request->coupon_limit;
        $coupon->coupon_expiry_date = $request->coupon_expiry_date;
        $coupon->coupon_code       = trim($request->coupon_code);
        $coupon->status            = $request->status;
        $coupon->store_id          = $request->store_id;
        $coupon->save();

        return $this->success(['message' => __('Coupon added successfully!')]);
    }

public function UpdateCoupon(Request $request)
{
    $store = $this->resolveOwnedStore($request->store_id);
    if (empty($store)) {
        return $this->error(['message' => __('Store not found!')]);
    }

    $validator = \Validator::make(
        $request->all(),
        [
            'coupon_id' => 'required',
        ]
    );

    if ($validator->fails()) {
        $messages = $validator->getMessageBag();
        return $this->error([
            'message' => $messages->first()
        ]);
    }

    $coupon = Coupon::where('id', $request->coupon_id)
        ->where('store_id', $store->id)
        ->first();
    if (!$coupon) {
        return $this->error(['message' => __('Coupon not found!')]);
    }

    if ($request->has('coupon_name')) {
        $coupon->coupon_name = $request->coupon_name;
    }
    if ($request->has('coupon_type')) {
        $coupon->coupon_type = $request->coupon_type;
    }
    if ($request->has('discount_amount')) {
        $coupon->discount_amount = $request->discount_amount;
    }
    if ($request->has('coupon_limit')) {
        $coupon->coupon_limit = $request->coupon_limit;
    }
    if ($request->has('coupon_expiry_date')) {
        $coupon->coupon_expiry_date = $request->coupon_expiry_date;
    }
    if ($request->has('coupon_code')) {
        $coupon->coupon_code = trim($request->coupon_code);
    }
    
    if ($request->has('status')) {
        $coupon->status = (int) $request->status;
    }
    
    $coupon->save();

    return $this->success(['message' => __('Coupon Updated successfully!')]);
}
    public function DeleteCoupon(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'coupon_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $coupon = Coupon::where('id', $request->coupon_id)
            ->where('store_id', $store->id)
            ->first();
        if (!empty($coupon)) {
            $coupon->delete();
            return $this->success(['message' => __('Coupon deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Coupon data not found.')]);
        }
    }

    public function GenerateCode(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $length = 10;
        $result = '';
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $charactersLength = strlen($characters);
        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[rand(0, $charactersLength - 1)];
        }

        return $this->success($result);
    }

    public function AddShipping(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'description' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $shipping                   = new Shipping();
        $shipping->name             = $request->name;
        $shipping->description      = $request->description;
        $shipping->slug             = $store->slug;
        $shipping->store_id         = $request->store_id;
        $shipping->save();

        return $this->success(['message' => __('Shipping added successfully!')]);
    }

    public function UpdateShipping(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'shipping_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $shipping = Shipping::where('id', $request->shipping_id)
            ->where('store_id', $store->id)
            ->first();

        if (!empty($shipping)) {
            if (!empty($request->name)) {
                $shipping->name         = $request->name;
            }
            if (!empty($request->description)) {
                $shipping->description      = $request->description;
            }
            $shipping->save();

            return $this->success(['message' => __('Shipping Updated successfully!')]);
        } else {
            return $this->error(['message' => __('Shipping not found!')]);
        }
    }

    public function DeleteShipping(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'shipping_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $shipping = Shipping::where('id', $request->shipping_id)
            ->where('store_id', $store->id)
            ->first();

        if (!empty($shipping)) {
            $shipping->delete();
            return $this->success(['message' => __('Shipping deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Shipping data not found.')]);
        }
    }

    public function AddTax(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $tax           = new Tax();
        $tax->name     = $request->name;
        $tax->store_id = $request->store_id;
        $tax->save();

        return $this->success(['message' => __('Tax added successfully!')]);
    }

    public function UpdateTax(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'tax_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $tax = Tax::where('id', $request->tax_id)
            ->where('store_id', $store->id)
            ->first();

        // NULL GUARD, pre-existing and now load-bearing. This method wrote to
        // the resolved row with no existence check, so an id matching nothing
        // already raised "Attempt to read property on null" -- a 500 on bad
        // input. Scoping the lookup to the caller's tenant makes a FOREIGN id
        // resolve to null too, so the guard has to be here or a denial would
        // surface as a 500 instead of a clean refusal. Same message as this
        // file's other not-found branches, so a foreign id is indistinguishable
        // from an absent one.
        if (empty($tax)) {
            return $this->error(['message' => __('Tax data not found.')]);
        }

        if (!empty($request->name)) {
            $tax->name     = $request->name;
        }
        $tax->save();
        return $this->success(['message' => __('Tax Updated successfully!')]);
    }

    public function DeleteTax(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'tax_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $tax = Tax::where('id', $request->tax_id)
            ->where('store_id', $store->id)
            ->first();

        if (!empty($tax)) {
            $tax->delete();
            return $this->success(['message' => __('Tax deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Tax data not found.')]);
        }
    }

    public function AddVariant(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'type' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        /*
         * THIS ENDPOINT CANNOT CREATE A VARIANT, AND NEVER COULD. P1-INV-10 (a).
         *
         * It used to run:
         *
         *     $ProductVariant = new ProductVariant();
         *     $ProductVariant->name = $request->name;   // no such column
         *     $ProductVariant->type = $request->type;   // no such column
         *     $ProductVariant->store_id = $request->store_id;
         *     $ProductVariant->save();
         *
         * `product_variants` has NEITHER `name` NOR `type`
         * (database/migrations/2024_01_01_104858_create_product_variants_table.php; no later
         * migration alters the table, and grep finds no reader of either attribute anywhere in
         * app/ -- the real label column is `variant`, which is what
         * SalesDownloadableProductDataTable.php:40 reads). Direct property assignment bypasses
         * `$fillable`, so both landed in the INSERT and MySQL answered
         * `Unknown column 'name' in 'field list'` -- an unhandled QueryException, i.e. a 500 for
         * every caller including the store's own owner. The same class as CLAUDE.md's
         * `delivery_boys.commission_percentage` note.
         *
         * AND THE CONTRACT CANNOT BE REPAIRED FROM HERE. `product_id`, `variant`, `sku` and
         * `price` are all NOT NULL with no default, and `product_id` carries a foreign key to
         * `products`. The validator above asks for `name` and `type` only: there is no product to
         * attach the variant to, no sku and no price anywhere in the request. Writing "columns
         * that exist" would mean inventing a request contract -- which product? which sku? which
         * price? -- and securing a new cross-tenant write path for it. That is a feature, and
         * this is a remediation task, so the endpoint is refused instead.
         *
         * WHAT CHANGES FOR CLIENTS: nothing that worked stops working, because nothing worked.
         * `api/admin/addvariant` is called by no client in either repository (grep over
         * StoreSyria_app98/lib for `addvariant`, `variantlist`, `updatevariant` and
         * `deletevariant`: 0 hits -- the Flutter app creates variants through the product
         * endpoints' `variant_attribute`/`variant_id` fields). A caller that did reach it saw a
         * 500 and now sees this file's ordinary `error()` envelope, which is HTTP 200 with
         * `status: 0` -- deliberately NOT a 401, the code repo-B's lib/network/network.dart turns
         * into a forced logout.
         */
        return $this->error([
            'message' => __('This endpoint is not available: a product variant cannot be created without a product.'),
        ]);
    }

    public function UpdateVariant(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'productvariant_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $ProductVariant = ProductVariant::where('id', $request->productvariant_id)
            ->where('store_id', $store->id)
            ->first();

        // NULL GUARD, pre-existing and now load-bearing. This method wrote to
        // the resolved row with no existence check, so an id matching nothing
        // already raised "Attempt to read property on null" -- a 500 on bad
        // input. Scoping the lookup to the caller's tenant makes a FOREIGN id
        // resolve to null too, so the guard has to be here or a denial would
        // surface as a 500 instead of a clean refusal. Same message as this
        // file's other not-found branches, so a foreign id is indistinguishable
        // from an absent one.
        if (empty($ProductVariant)) {
            return $this->error(['message' => __('Product Variant data not found.')]);
        }

        if (!empty($request->name)) {
            $ProductVariant->variant       = $request->name;
        }

        /*
         * THE `type` WRITE IS GONE, FOR THE SAME REASON AS AddVariant's. P1-INV-10 (a).
         *
         * This method is not in the task row -- the row named AddVariant -- and it carried half
         * the same defect: `$ProductVariant->type = $request->type` on a table with no `type`
         * column. Because it sat behind `if (!empty($request->type))` the endpoint worked when
         * the caller sent `name` alone and threw `Unknown column 'type'` the moment `type` was
         * sent, which is the harder failure to notice. The `name` -> `variant` mapping above is
         * also what settles AddVariant's intent: the API's `name` parameter means
         * `product_variants.variant`.
         *
         * Dropping the write rather than refusing the parameter: `name` alone already succeeded,
         * so refusing would break a call that works today, while a caller that sent `type` got a
         * 500 and no update at all and now gets its `variant` update applied. `type` is accepted
         * and has nowhere to be stored; if it ever needs to be, it needs a migration, not an
         * assignment.
         */

        $ProductVariant->save();

        return $this->success(['message' => __('Product Variant Updated successfully!')]);
    }

    public function DeleteVariant(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'variant_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        /*
         * F195 (a) -- THIS HANDLER HAD NO PERMISSION CHECK AT ALL.
         *
         * Not `Edit Products`, not anything: `resolveOwnedStore()` answered the tenancy question
         * and the delete followed. Its web sibling, `ProductController::product_attribute_delete`,
         * requires `Edit Products` for a reason it states -- the row carries stock. So any
         * principal holding a token for a store could destroy its variants and their stock
         * regardless of what their role allowed.
         *
         * `MerchantPermissions::allowsAny()` rather than `isAbleTo()`: it resolves the permission
         * against the store the principal is actually in and gives the platform operator the
         * wildcard, because `isAbleTo()` alone REFUSES the operator in this codebase. An
         * operator-last check here would be a lockout rather than a tightening.
         *
         * NOT A SHIPPED-CLIENT CHANGE, measured rather than assumed: nothing in the Flutter admin
         * app at /home/user/StoreSyria_app98 calls `deletevariant` (a case-insensitive grep over
         * `lib/` finds no occurrence), so no client loses an operation it was performing.
         */
        if (! \App\Services\MerchantPermissions::allowsAny(\Auth::user(), ['Edit Products'], $store->id)) {
            return $this->error(['message' => __('Permission denied.')]);
        }

        /*
         * F195 (b) -- THE D-20 REFERENCE BLOCK IS **NOT** APPLIED HERE, AND THAT IS RECORDED RATHER
         * THAN SILENT.
         *
         * The checks that refuse a delete when a cart line, a wishlist entry or an order references
         * the variant live in `ProductController::ssRefuseVariantRemoval()`, which is PRIVATE to a
         * web controller. Reaching them from here needs one of: extracting them into a service that
         * both controllers call (correct, and the named fix), duplicating the queries here (which
         * is how two copies of a control drift apart -- this project has a row for exactly that),
         * or making the method public and instantiating a web controller from an API one. The
         * extraction is a refactor of a guard with sixteen tests driving it through `update()`, and
         * it does not belong in the same change as a missing-permission fix.
         *
         * SO THIS PATH STILL DELETES A REFERENCED VARIANT, for a caller who now also needs
         * `Edit Products`. It is narrower than it was and it is not closed. See the F195 row.
         *
         * One mitigation it has by accident: `->where('store_id', $store->id)` below narrows on
         * `ProductVariant.store_id`, which `product_attribute_delete`'s own comment records (S36)
         * as *"not the authority on who owns it"* -- that handler resolves ownership through the
         * variant's PRODUCT instead. Here the effect is fail-closed: a variant with a NULL
         * `store_id` matches nothing and cannot be deleted through this endpoint at all. Left as it
         * is rather than widened, because widening it would make MORE variants reachable from a
         * path whose reference block is still missing.
         */
        $ProductVariant = ProductVariant::where('id', $request->variant_id)
            ->where('store_id', $store->id)
            ->first();
        if (!empty($ProductVariant)) {
            $ProductVariant->delete();
            return $this->success(['message' => __('Product Variant deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Product Variant data not found.')]);
        }
    }

    public function CreateProduct(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $ProductData = [];

        $categoryarray = [];
        $maincategory = Category::where('store_id', $store->id)->get()->toArray();
        if (!empty($maincategory)) {
            foreach ($maincategory as $key => $value) {
                $categoryarray[$key]['id'] = $value['id'];
                $categoryarray[$key]['name'] = $value['name'];

                $ProductData['category'] = $categoryarray;
            }
        } else {
            return $this->error(['message' => __('Category data not found')]);
        }

        $variantarray = [];
        $variant = ProductVariant::where('store_id', $store->id)->get()->toArray();
        if (!empty($variant)) {
            foreach ($variant as $key => $value) {
                $variantarray[$key]['id'] = $value['id'];
                $variantarray[$key]['name'] = $value['variant'] ?? ($value['name'] ?? '-');

                $ProductData['variant'] = $variantarray;
            }
        }
        // else {
        //     return $this->error(['message' => 'Variant data not found']);
        // }

        $product_details_json_path = base_path('theme_json/product-detail.json');;
        if (file_exists($product_details_json_path)) {
            $product_detail_json = json_decode(file_get_contents($product_details_json_path), true);
            $product_detail_array = [];
            foreach ($product_detail_json as $key => $value) {
                foreach ($value['inner-list'] as $key1 => $val) {
                    $product_detail_array[$value['section_slug']][$val['field_slug']] = $val['field_default_text'];
                }
            }
            $ProductData['details'] = $product_detail_array;
        } else {
            return $this->error(['message' => __('Product Details not found.')]);
        }

        return $this->success($ProductData);
    }





public function ViewOrder(Request $request)
{
    $validator = \Validator::make($request->all(), [
        'store_id' => 'required|integer',
        'order_id' => 'required|integer',
    ]);

    if ($validator->fails()) {
        return $this->error([
            'message' => $validator->errors()->first(),
        ]);
    }

    $store = $this->resolveOwnedStore($request->store_id);

    if (!$store) {
        return $this->error([
            'message' => __('Store not found!'),
        ]);
    }

    $orderModel = Order::query()
        ->where('id', $request->order_id)
        ->where('store_id', $store->id)
        ->first();

    if (!$orderModel) {
        return $this->error([
            'message' => __('Order not found!'),
        ]);
    }

    $order = Order::order_detail($orderModel->id);

    if (!empty($order['message'])) {
        return $this->error($order);
    }

    $notes = OrderNote::query()
        ->where('order_id', $orderModel->id)
        ->where('store_id', $store->id)
        ->latest('id')
        ->get()
        ->map(function (OrderNote $note) {
            return [
                'id' => $note->id,
                'order_id' => $note->order_id,
                'customer_id' => $note->customer_id,
                'note' => $note->notes,
                'note_type' => $note->note_type,
                'status' => $note->status,
                'created_at' => $note->created_at?->toISOString(),
                'updated_at' => $note->updated_at?->toISOString(),
            ];
        });

    $order['notes'] = $notes;

    $currencyCode = \App\Models\Utility::GetValueByName('defult_currancy', $store->id) ?? 'USD';

    $currencyRate = \App\Models\CurrencyRate::where('store_id', $store->id)
        ->where('currency_code', $currencyCode)
        ->where('is_active', 1)
        ->first();

    $order['currency_code'] = $currencyCode;
    $order['currency_symbol'] = $currencyRate ? $currencyRate->symbol : ($currencyCode === 'USD' ? '$' : $currencyCode);

    $formatNumber = function ($value) {
        return number_format((float) ($value ?? 0), 2, '.', '');
    };

    $mainPriceFields = ['product_price', 'delivery_price', 'final_price', 'coupon_price', 'tax_price', 'sub_total', 'return_price'];
    foreach ($mainPriceFields as $field) {
        if (isset($order[$field])) {
            $order[$field] = $formatNumber($order[$field]);
        }
    }

    if (isset($order['product'])) {
        $products = is_string($order['product']) ? json_decode($order['product'], true) : $order['product'];

        if (is_array($products)) {
            foreach ($products as &$item) {
                if (isset($item['price'])) {
                    $item['price'] = $formatNumber($item['price']);
                }
                if (isset($item['final_price'])) {
                    $item['final_price'] = $formatNumber($item['final_price']);
                }
                if (isset($item['orignal_price'])) {
                    $item['orignal_price'] = $formatNumber($item['orignal_price']);
                }
                if (isset($item['total_orignal_price'])) {
                    $item['total_orignal_price'] = $formatNumber($item['total_orignal_price']);
                }
            }
            $order['product'] = $products;
        }
    }

    return $this->success($order);
}


















private function deleteProductFile(?string $path): void
{
    if (empty($path)) {
        return;
    }

    /*
     * إذا كانت المسارات مخزنة مثل:
     * public/uploads/products/image.jpg
     * فاستخدام base_path صحيح.
     *
     * أما إذا كانت مخزنة مثل:
     * uploads/products/image.jpg
     * فغالبًا تحتاج public_path بدل base_path.
     */
    // P1-SEC-28: a database string goes to the filesystem only through StoredUpload (literal path, inside uploads/, the owner's folder)
    \App\Services\StoredUpload::delete($path, auth()->user()?->creatorId());
}





public function DeleteProduct(Request $request)
{
    $validator = Validator::make($request->all(), [
        'store_id'   => ['required', 'integer'],
        'product_id' => ['required', 'integer'],
    ]);

    if ($validator->fails()) {
        return $this->error([
            'message' => $validator->errors()->first(),
        ]);
    }

    $store = $this->resolveOwnedStore($request->store_id);

    if (!$store) {
        return $this->error([
            'message' => __('Store not found!'),
        ]);
    }

    // مهم: تأكد أن المنتج تابع لنفس المتجر
    $product = Product::where('id', $request->product_id)
        ->where('store_id', $store->id)
        ->first();

    if (!$product) {
        return $this->error([
            'message' => __('Product not found.'),
        ]);
    }

    DB::beginTransaction();

    try {
        $productImages = ProductImage::where(
            'product_id',
            $product->id
        )->get();

        foreach ($productImages as $productImage) {
            $this->deleteProductFile($productImage->image_path ?? null);
        }

        // حذف الصور الموجودة داخل JSON
        $descriptions = json_decode(
            $product->other_description_api ?? '',
            true
        );

        if (json_last_error() === JSON_ERROR_NONE && is_array($descriptions)) {
            $items = $descriptions['product-other-description'] ?? [];

            if (is_array($items)) {
                foreach ($items as $item) {
                    if (
                        is_array($item) &&
                        ($item['field_type'] ?? null) === 'photo upload'
                    ) {
                        $this->deleteProductFile($item['value'] ?? null);
                    }
                }
            }
        }

        // حذف صورة الغلاف
        $this->deleteProductFile($product->cover_image_path ?? null);

        ProductImage::where('product_id', $product->id)->delete();

        Cart::where('product_id', $product->id)->delete();
        Wishlist::where('product_id', $product->id)->delete();

        $product->delete();

        DB::commit();

        return $this->success([
            'message' => __('Product deleted successfully!'),
        ]);
    } catch (\Throwable $exception) {
        DB::rollBack();

        Log::error('Delete product failed', [
            'product_id' => $request->product_id,
            'store_id'   => $request->store_id,
            'message'    => $exception->getMessage(),
            'file'       => $exception->getFile(),
            'line'       => $exception->getLine(),
            'trace'      => $exception->getTraceAsString(),
        ]);

        return $this->error([
            'message' => __('Unable to delete the product.'),
        ]);
    }
}








public function AddProduct(Request $request)
{
    $store = $this->resolveOwnedStore($request->store_id);
    if (empty($store)) {
        return $this->error(['message' => __('Store not found!')]);
    }

    $validator = \Validator::make($request->all(), [
        'name' => 'required|string',
        'category_id' => 'required|integer',
        'description' => 'required|string',
        'cover_image' => 'required|image',
        'product_image' => 'required|array',
        'price' => 'required|numeric|min:0',
        'sale_price' => 'nullable|numeric|min:0|lte:price',
        'cost_price' => 'nullable|numeric|min:0|lte:price',
        'product_stock' => 'required|integer|min:0',
        'trending' => 'required|in:0,1',
        'variant_product' => 'required|in:0,1',
        'status' => 'required|in:0,1',


        'tax_id' => 'nullable',
        'tax_status' => 'nullable|string|in:taxable,none,shipping',
        'shipping_id' => 'nullable',
        'product_weight' => 'nullable|numeric|min:0',
        'track_stock' => 'nullable|in:0,1',
        'stock_status' => 'nullable|string|in:in_stock,out_of_stock,on_backorder',
    ]);

    if ($validator->fails()) {
        $messages = $validator->getMessageBag();
        return $this->error([
            'message' => $messages->first()
        ]);
    }

    $user = \Auth::user();
    $total_products = $user->countProducts();
    $plan = Plan::find($user->plan_id);
    $dir = 'uploads/' . $store->id;

    // description array processing (نفس الكود الخاص بك)
    $array = !empty($request->array) ? $request->array : [];
    $array_api = [];
    foreach ($array as $array_key => $slug) {
        foreach ($slug['inner-list'] as $slug_key => $value) {
            $array_api[$slug['section_slug']][$slug_key]['field_type'] = $value['field_type'];
            $array_api[$slug['section_slug']][$slug_key]['name'] = $value['field_name'];
            $array_api[$slug['section_slug']][$slug_key]['value'] = !empty($value['value']) ? $value['value'] : '';

            if ($value['field_type'] == 'photo upload') {
                $theme_image = !empty($value['value']) ? $value['value'] : '';
                $upload_image_path = !empty($value['value']) ? $value['value'] : '';
                if (gettype($theme_image) == 'object') {
                    $fileName = rand(10, 100) . '_' . time() . "_" . basename($theme_image->getClientOriginalName());
                    $upload = Utility::jsonUpload_file($request, 'value', $theme_image, $fileName, $dir, []);
                    if ($upload['flag'] == true) {
                        $upload_image_path = $upload['image_path'];
                    }
                }
                $array[$array_key]['inner-list'][$slug_key]['image_path'] = $upload_image_path;
                $array[$array_key]['inner-list'][$slug_key]['value'] = $upload_image_path;
                $array_api[$slug['section_slug']][$slug_key]['field_type'] = $value['field_type'];
                $array_api[$slug['section_slug']][$slug_key]['name'] = $value['field_name'];
                $array_api[$slug['section_slug']][$slug_key]['value'] = $upload_image_path;
            }
        }
    }
    
    if ($total_products < $plan->max_products || $plan->max_products == -1) {
        if ($request->hasFile('cover_image')) {
            $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->cover_image->getClientOriginalName());
            $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
            if ($path['flag'] == 0) {
                return $this->error(['message' => $path['msg']]);
            }
        } else {
            return $this->error(['message' => __('Cover image is required')]);
        }

        $Product = new Product();
        $Product->name = $request->name;
        $Product->description = $request->description;
        $Product->category_id = $request->category_id;
        
        $Product->cover_image_path = $path['url'] ?? null;
        $Product->cover_image_url = $path['full_url'] ?? null;
        
        $Product->price = $request->price;
        $Product->sale_price = $request->sale_price ?? 0;
        $Product->cost_price = $request->cost_price ?? 0; 

        $Product->product_stock = $request->product_stock;
        $Product->variant_product = $request->variant_product;
        $Product->trending = $request->trending;
        $Product->slug = str_replace(' ', '_', strtolower($request->name));
        $Product->status = $request->status ?? 1;

        $Product->product_weight = $request->product_weight ?? 0;
        $Product->shipping_id = $request->shipping_id ?? null;
        $Product->tax_status = $request->tax_status ?? 'none';

        if (!empty($request->tax_id)) {
            $Product->tax_id = is_array($request->tax_id) ? implode(',', $request->tax_id) : $request->tax_id;
        } else {
            $defaultTax = \App\Models\Tax::where('store_id', $store->id)->first();
            $Product->tax_id = $defaultTax ? $defaultTax->id : null;
        }

        if ($request->track_stock == 1) {
            $Product->track_stock = 1;
            $Product->stock_status = $request->stock_status ?? 'in_stock';
        } else {
            $Product->track_stock = 0;
            $Product->stock_status = $request->stock_status ?? 'in_stock';
        }

        $Product->store_id = $request->store_id;
        $Product->created_by = $user->id;
        $Product->save();

        if ($request->hasFile('product_image')) {
            foreach ($request->product_image as $key => $image) {
                $fileName = rand(10, 100) . '_' . time() . "_" . basename($image->getClientOriginalName());
                $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);

                $ProductImage = new ProductImage();
                $ProductImage->product_id = $Product->id;
                $ProductImage->image_path = $pathss['url'] ?? null;
                $ProductImage->image_url  = $pathss['full_url'] ?? null;
                $ProductImage->store_id   = $request->store_id;
                $ProductImage->save();
            }
        }

        return $this->success(['message' => __('Product added successfully!')]);
    } else {
        return $this->error(['message' => __('Your Product limit is over, Please upgrade plan')]);
    }
}


public function UpdateProduct(Request $request)
{
    $store = $this->resolveOwnedStore($request->store_id);
    if (empty($store)) {
        return $this->error(['message' => __('Store not found!')]);
    }

    $validator = \Validator::make(
        $request->all(),
        [
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string',
            'category_id' => 'required|integer',
            'description' => 'required|string',
            'cover_image' => 'nullable|image', 
            'product_image' => 'nullable|array',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0|lte:price',
            'cost_price' => 'nullable|numeric|min:0|lte:price',
            'product_stock' => 'required|integer|min:0',
            'trending' => 'required|in:0,1',
            'variant_product' => 'required|in:0,1',
            'status' => 'required|in:0,1',
            
            'tax_id' => 'nullable',
            'tax_status' => 'nullable|string|in:taxable,none,shipping',
            'shipping_id' => 'nullable',
            'product_weight' => 'nullable|numeric|min:0',
            'track_stock' => 'nullable|in:0,1',
            'stock_status' => 'nullable|string|in:in_stock,out_of_stock,on_backorder',
        ]
    );

    if ($validator->fails()) {
        $messages = $validator->getMessageBag();
        return $this->error([
            'message' => $messages->first()
        ]);
    }

    $dir = 'uploads/' . $store->id;
    $product = Product::where('id', $request->product_id)
        ->where('store_id', $store->id)
        ->first();
    
    if (empty($product)) {
        return $this->error(['message' => __('Product not found!')]);
    }

    $old_data = '';
    if (!empty($product->other_description_api)) {
        $old_data = json_decode($product->other_description_api, true);
    }

    $array = $request->array ?? [];
    $array_api = [];
    foreach ($array as $array_key => $slug) {
        foreach ($slug['inner-list'] as $slug_key => $value) {

            $array_api[$slug['section_slug']][$slug_key]['field_type'] = $value['field_type'];
            $array_api[$slug['section_slug']][$slug_key]['name'] = $value['field_name'];
            $array_api[$slug['section_slug']][$slug_key]['value'] = !empty($value['value']) ? $value['value'] : '';

            if ($value['field_type'] == 'photo upload') {
                $theme_image = !empty($value['value']) ? $value['value'] : '';
                $upload_image_path = !empty($value['value']) ? $value['value'] : '';

                if (gettype($theme_image) == 'object') {
                    $image_path = (!empty($old_data[$slug['section_slug']][$slug_key]['value'])) ? $old_data[$slug['section_slug']][$slug_key]['value'] : '';
                    \App\Services\StoredUpload::delete($image_path, auth()->user()?->creatorId());

                    $fileName = rand(10, 100) . '_' . time() . "_" . basename($theme_image->getClientOriginalName());
                    $upload = Utility::jsonUpload_file($request, 'value', $theme_image, $fileName, $dir, []);

                    if ($upload['flag'] == true) {
                        $upload_image_path = $upload['image_path'];
                    }
                }

                $array[$array_key]['inner-list'][$slug_key]['image_path'] = $upload_image_path;
                $array[$array_key]['inner-list'][$slug_key]['value'] = $upload_image_path;

                $array_api[$slug['section_slug']][$slug_key]['field_type'] = $value['field_type'];
                $array_api[$slug['section_slug']][$slug_key]['name'] = $value['field_name'];
                $array_api[$slug['section_slug']][$slug_key]['value'] = $upload_image_path;
            }
        }
    }

    $product->name = $request->name;
    $product->description = $request->description;
    $product->category_id = $request->category_id;
    
    $product->price = $request->price;
    $product->sale_price = $request->sale_price ?? 0;
    $product->cost_price = $request->cost_price ?? 0;
    
    $product->product_stock = $request->product_stock;
    $product->variant_product = $request->variant_product;
    $product->trending = $request->trending;
    $product->slug = str_replace(' ', '_', strtolower($request->name));
    $product->status = $request->status ?? 1;

    $product->product_weight = $request->product_weight ?? 0;
    $product->shipping_id = $request->shipping_id ?? null;
    $product->tax_status = $request->tax_status ?? 'none';

    if (!empty($request->tax_id)) {
        $product->tax_id = is_array($request->tax_id) ? implode(',', $request->tax_id) : $request->tax_id;
    }

    if ($request->track_stock == 1) {
        $product->track_stock = 1;
        $product->stock_status = $request->stock_status ?? 'in_stock';
    } else {
        $product->track_stock = 0;
        $product->stock_status = $request->stock_status ?? 'in_stock';
    }

    $product->store_id = $request->store_id;
    $product->save();

    if ($request->hasFile('cover_image')) {
        \App\Services\StoredUpload::delete($product->cover_image_path, auth()->user()?->creatorId());

        $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->cover_image->getClientOriginalName());
        $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
        
        if ($path['flag'] == 1) {
            $product->cover_image_path = $path['url'];
            $product->cover_image_url = $path['full_url'];
            $product->save();
        }
    }

    if ($request->hasFile('product_image')) {
        ProductImage::where('product_id', $product->id)->delete();

        foreach ($request->product_image as $key => $image) {
            $fileName = rand(10, 100) . '_' . time() . "_" . basename($image->getClientOriginalName());
            $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);

            $ProductImage = new ProductImage();
            $ProductImage->product_id = $product->id;
            $ProductImage->image_path = $pathss['url'] ?? null;
            $ProductImage->image_url  = $pathss['full_url'] ?? null;
            $ProductImage->store_id   = $request->store_id;
            $ProductImage->save();
        }
    }

    return $this->success(['message' => __('Product Updated Successfully!')]);
}


public function ViewProduct(Request $request)
{
    $store = $this->resolveOwnedStore($request->store_id);
    if (empty($store)) {
        return $this->error(['message' => __('Store not found!')]);
    }

    $validator = \Validator::make(
        $request->all(),
        [
            'product_id' => 'required',
        ]
    );

    if ($validator->fails()) {
        $messages = $validator->getMessageBag();
        return $this->error([
            'message' => $messages->first()
        ]);
    }
    
    $ProductDetail = [];

    $Products = Product::where('id', $request->product_id)
        ->where('store_id', $store->id)
        ->first();
    if (!empty($Products)) {
        $productArray = $Products->toArray();

        // ✅ 1. جلب كود العملة الحالية الفعلي من الإعدادات
        $currencyCode = \App\Models\Utility::GetValueByName('defult_currancy', $store->id) ?? 'USD';

        // ✅ 2. جلب رمز العملة المقابل من جدول currency_rates النشط
        $currencyRate = \App\Models\CurrencyRate::where('store_id', $store->id)
            ->where('currency_code', $currencyCode)
            ->where('is_active', 1)
            ->first();

        // ✅ 3. تحديد الرمز: من الجدول، أو الافتراضي، أو كود العملة نفسه كحل أخير
        $currencySymbol = $currencyRate ? $currencyRate->symbol : ($currencyCode === 'USD' ? '$' : $currencyCode);

        $formatNumber = function ($value) {
            return number_format((float) ($value ?? 0), 2, '.', '');
        };

        $productArray['price'] = $formatNumber($productArray['price'] ?? 0);
        $productArray['sale_price'] = $formatNumber($productArray['sale_price'] ?? 0);
        $productArray['cost_price'] = $formatNumber($productArray['cost_price'] ?? 0);
        $productArray['final_price'] = $formatNumber($productArray['final_price'] ?? $productArray['price'] ?? 0);

        // ✅ 4. إضافة كود العملة ورمزها للاستجابة
        $productArray['currency_code'] = $currencyCode;
        $productArray['currency_symbol'] = $currencySymbol;

        $ProductDetail['Product'] = $productArray;
    } else {
        return $this->error(['message' => __('Product not found')]);
    }

    $subimage = Product::Sub_image($request->product_id);
    if (!empty($subimage)) {
        $ProductDetail['Sub_Image'] = $subimage['data'];
    }

    if (!empty($ProductDetail)) {
        return $this->success($ProductDetail);
    } else {
        return $this->error(['message' => __('Something went wrong!')]);
    }
}

    public function DeleteOrder(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'order_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        // NEW-api-delete-any-order. This was the worst shape in the file: the
        // store was resolved from the request and then NEVER USED -- the four
        // deletes below keyed on $request->order_id alone, with no store
        // predicate of any kind. Any holder of any valid admin token deleted
        // any order on the platform, plus its tax, coupon and billing detail
        // rows, by sending one integer. Irreversible.
        //
        // The order is now resolved through the owned store first, exactly as
        // AddOrderNote (:122) already does, and the child-table deletes are
        // moved BEHIND that check so the destructive side effect cannot run on
        // a failed lookup -- the ordering rule the status.cancel repair
        // established.
        //
        // A foreign order answers 'Order not found.' rather than a 403, so the
        // response does not confirm that an order id exists.
        $order = Order::where('id', $request->order_id)
            ->where('store_id', $store->id)
            ->first();

        if (!$order) {
            return $this->error(['message' => __('Order not found.')]);
        }

        OrderTaxDetail::where('order_id', $order->id)->delete();
        OrderCouponDetail::where('order_id', $order->id)->delete();
        OrderBillingDetail::where('order_id', $order->id)->delete();
        $order->delete();

        return $this->success(['message' => __('Order deleted successfully!.')]);
    }

















    public function SearchData(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'type' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        if ($request->type == 'order') {
            $order = Order::where('store_id', $request->store_id)->where('product_order_id', 'like',  '%' . $request->name . '%');
            $Data = $order->get()->toArray();
        }
        if ($request->type == 'category') {
            $category = Category::where('store_id', $request->store_id)->where('name', 'like',  '%' . $request->name . '%');
            $Data = $category->get()->toArray();
        }

        if (!empty($Data)) {
            return $this->success($Data);
        } else {
            return $this->error(['message' => __('not found.')]);
        }
    }

    public function CreateReview(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $ReviewData = [];

        $userarray = [];
        $users = User::where('current_store', $store->id)->get()->toArray();
        if (!empty($users)) {
            foreach ($users as $key => $value) {
                $userarray[$key]['id'] = $value['id'];
                $userarray[$key]['name'] = $value['name'];

                $ReviewData['users'] = $userarray;
            }
        } else {
            return $this->error(['message' => 'Users data not found']);
        }

        return $this->success($ReviewData);
    }

    public function CategoryProduct(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'category_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }


        $ProductData = [];

        $productarray = [];
        $products = Product::select('id', 'name')->where('store_id', $store->id)->where('category_id', $request->category_id)->get();

        if (!empty($products)) {
            foreach ($products as $key => $value) {
                $productarray[$key]['id'] = $value['id'];
                $productarray[$key]['name'] = $value['name'];

                $ProductData['products'] = $productarray;
            }
        } else {
            return $this->error(['message' => __('Products data not found')]);
        }

        return $this->success($ProductData);
    }

    public function AddReview(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'product_id' => 'required',
                'user_id' => 'required',
                'rating' => 'required',
                'title' => 'required',
                'description' => 'required'
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $Product = Product::where('id', $request->product_id)
            ->where('store_id', $store->id)
            ->first();
        if (empty($Product)) {
            return $this->error([
                'message' => __('Product not found.')
            ], __("Product not found."));
        }
        $is_Review = Testimonial::where('user_id', $request->user_id)->where('product_id', $request->product_id)->where('store_id', $store->id)->exists();

        if ($is_Review) {
            return $this->error([
                'message' => __('Rating already added.')
            ], __("Rating already added."));
        }
        $review                  = new Testimonial();
        $review->user_id         = $request->user_id;
        $review->category_id     = $Product->category_id;
        $review->product_id      = $request->product_id;
        $review->rating_no       = $request->rating;
        $review->title           = $request->title;
        $review->description     = $request->description;
        $review->status          = $request->status == '1' ? 1 : 0;
        $review->store_id        = $store->id;
        $review->save();

        Testimonial::AvregeRating($request->product_id);


        return $this->success([
            'message' => __('Rating Add successfully.')
        ], __("Rating add successfully."));
    }

   // public function DeleteReview(Testimonial $Testimonial)
   //{
     //   $Testimonial->delete();
      //  return $this->success(['message' => __('Review deleted successfully!.')]);
    //}
public function DeleteReview($id)
{
        $testimonial=Testimonial::find($id);
        if($testimonial){
        $testimonial->delete();


        return $this->success(['message' => __('Review deleted successfully!.')]);
    }

    return $this->error(['message' => __('Review not found.')], 404);
}


    public function UpdateReview(Request $request)
{
    $store = $this->resolveOwnedStore($request->store_id);
    if (empty($store)) {
        return $this->error(['message' => __('Store not found!')]);
    }

    $validator = \Validator::make(
        $request->all(),
        [
            'id' => 'required|integer|exists:testimonials,id',
        ]
    );

    if ($validator->fails()) {
        return $this->error([
            'message' => $validator->getMessageBag()->first()
        ]);
    }

    $review = Testimonial::where('id', $request->id)
        ->where('store_id', $store->id)
        ->first();

    if (!$review) {
        return $this->error(['message' => __('Review not found.')], 404);
    }

    if ($request->has('user_id') && $request->user_id !== null && $request->user_id !== '') {
        $review->user_id = $request->user_id;
    }
    if ($request->has('category_id') && $request->category_id !== null && $request->category_id !== '') {
        $review->category_id = $request->category_id;
    }
    if ($request->has('product_id') && $request->product_id !== null && $request->product_id !== '') {
        $review->product_id = $request->product_id;
    }
    if ($request->has('rating_no') && $request->rating_no !== null && $request->rating_no !== '') {
        $review->rating_no = $request->rating_no;
    }
    if ($request->has('title') && $request->title !== null && $request->title !== '') {
        $review->title = $request->title;
    }
    if ($request->has('description') && $request->description !== null && $request->description !== '') {
        $review->description = $request->description;
    }
    if ($request->has('status')) {
        $review->status = (int) $request->status;
    }

    $review->save();

    if ($request->has('rating_no') || $request->has('product_id')) {
        \App\Models\Testimonial::AvregeRating($review->product_id);
    }

    return $this->success(['message' => __('Product Review Updated successfully!')]);
}

    public function ReviewStatus(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'id' => 'required',

            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $review = Testimonial::where('id', $request->id)
            ->where('store_id', $store->id)
            ->first();
        if (!empty($review)) {

            if ($review->status == '1') {
                $review->status = '0';
                $review->save();
                return $this->success(['message' => __('Product Review status Updated successfully!')]);
            } else {
                $review->status = '1';
                $review->save();
                return $this->success(['message' => __('Product Review status Updated successfully!')]);
            }
        } else {
            return $this->error(['message' => __('Review not found')]);
        }
    }

    public function ProductDropdown(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $categoryDropdown = [];

        $cat_array = [];
        $product = Category::where('store_id', $store->id)->get();
        if (!empty($product)) {
            foreach ($product as $key => $value) {
                $cat_array[$key]['id'] = $value->id;
                $cat_array[$key]['name'] = $value->name;
            }
            $categoryDropdown['category'] = $cat_array;
            return $this->success($categoryDropdown);
        } else {
            return $this->error(['message' => __('Product Data not found.')]);
        }
    }

    public function SearchProduct(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'category_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $products = Product::where('store_id', $request->store_id)->where('category_id', $request->category_id)->where('product_type',null)->get()->toArray();
        if (!empty($products)) {
            return $this->success($products);
        } else {
            return $this->error(['message' => __('Product not found.')]);
        }
    }

    public function ReviewList(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'product_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $reviewlist = Testimonial::where('product_id', $request->product_id)->where('store_id', $store->id)->OrderBy('id', 'desc')->get()->toarray();

        if (!empty($reviewlist)) {
            return $this->success($reviewlist);
        } else {
            return $this->error(['message' => __('Review Data not found.')]);
        }
    }

    public function StaticsData(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        // The dashboard's analytics twin -- the full set, where `visitorchart` is a slice.
        if ($refusal = $this->refuseWithoutPermission(['Manage Store Analytics', 'Manage Dashboard'], $store->id)) {
            return $refusal;
        }


        $DashboardData = [];

        //visitor chart
        $arrDuration = [];
        $arrParam = ['duration' => 'month'];
        if ($arrParam['duration']) {

            if ($arrParam['duration'] == 'month') {
                $previous_month = strtotime("-2 week +0 day");
                for ($i = 0; $i < 15; $i++) {
                    $arrDuration[date('Y-m-d', $previous_month)] = date('d-M', $previous_month);
                    $previous_month                              = strtotime(date('Y-m-d', $previous_month) . " +1 day");
                }
            }
        }
        $arrTask          = [];
        $i = 0;
        $arrTask[$i]['date'] = [];
        $arrTask[$i]['pageview']  = [];

        foreach ($arrDuration as $date => $label) {
            $data['visitor'] = \DB::table('shetabit_visits')->select(\DB::raw('count(*) as total'))->where('store_id', $store->id)->whereDate('created_at', '=', $date)->first();
            $uniq            = \DB::table('shetabit_visits')->select('ip')->distinct()->where('store_id', $store->id)->whereDate('created_at', '=', $date)->get();

            $data['unique']           = $uniq->count();
            $arrTask[$i]['date']      = $label;
            $arrTask[$i]['pageview']        = $data['visitor']->total;
            $arrTask[$i]['unique_pageview'] = $data['unique'];
            $i++;
        }
        $DashboardData['visitorchart'] = $arrTask;

        //Top url
        $topurl = [];
        $visitor_url   = \DB::table('shetabit_visits')->selectRaw("count('*') as total, url")->where('store_id', $store->id)->groupBy('url')->orderBy('total', 'DESC')->get();
        if (!empty($visitor_url)) {
            foreach ($visitor_url as $key => $value) {

                $topurl[$key]['total_click'] =  $value->total;
                $topurl[$key]['url'] =  $value->url;
            }

            $DashboardData['topurl'] = $topurl;
        }

        //platform chart
        $platform = [];
        $color = [
            '#4942a8',
            '#41c9d6',
            '#f8a21e',
            '#e83e8c',
            '#E9FFDF',
            '#045dff',
            '#c972f5',
            '#df5dab',
            '#8ee332',
            '#8c6576',
            '#366451',
        ];


        $user_platform = \DB::table('shetabit_visits')->selectRaw("count('*') as total, platform")->where('store_id', $store->id)->groupBy('platform')->orderBy('platform', 'DESC')->get();
        if (!empty($user_platform)) {
            $count = count($color);
            $val = 0;
            foreach ($user_platform as $key => $value) {

                $platform[$key]['platform'] = !empty($value->platform) ? $value->platform : 'other';
                $platform[$key]['data'] = $value->total;
                $platform[$key]['colour'] = $color[$val];
                $val += 1;
                if ($val == ($count - 1)) {
                    $val = 0;
                }
            }

            $DashboardData['platform'] = $platform;
        }

        //browser chart
        $browser = [];
        $color = [
            '#4942a8',
            '#41c9d6',
            '#f8a21e',
            '#e83e8c',
            '#E9FFDF',
            '#045dff',
            '#c972f5',
            '#df5dab',
            '#8ee332',
            '#8c6576',
            '#366451',
        ];
        $user_browser  = \DB::table('shetabit_visits')->selectRaw("count('*') as total, browser")->where('store_id', $store->id)->groupBy('browser')->orderBy('browser', 'DESC')->get();
        if (!empty($user_browser)) {
            $count = count($color);
            $val = 0;
            foreach ($user_browser as $key => $value) {
                $browser[$key]['browser'] = !empty($value->browser) ? $value->browser : 'other';
                $browser[$key]['data'] = $value->total;
                $browser[$key]['colour'] = $color[$val];
                $val += 1;
                if ($val == ($count - 1)) {
                    $val = 0;
                }
            }
            $DashboardData['browser'] = $browser;
        }

        //device chart
        $device = [];
        $color = [
            '#4942a8',
            '#41c9d6',
            '#f8a21e',
            '#e83e8c',
            '#E9FFDF',
            '#045dff',
            '#c972f5',
            '#df5dab',
            '#8ee332',
            '#8c6576',
            '#366451',

        ];
        $user_device   = \DB::table('shetabit_visits')->selectRaw("count('*') as total, device")->where('store_id', $store->id)->groupBy('device')->orderBy('device', 'DESC')->get();
        if (!empty($user_device)) {
            $count = count($color);
            $val = 0;
            foreach ($user_device as $key => $value) {
                $device[$key]['device'] = !empty($value->device) ? $value->device : 'other';
                $device[$key]['data'] = $value->total;
                $device[$key]['colour'] = $color[$val];
                $val += 1;
                if ($val == ($count - 1)) {
                    $val = 0;
                }
            }
            $DashboardData['device'] = $device;
        }

        return $this->success($DashboardData);
    }

    public function EditProfile(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $dir        = 'uploads/' . $store->id;

        $rules = [
            'user_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        // NEW-api-edit-any-user. Was `User::find($request->user_id)`: no
        // comparison against the caller at all, so any valid admin token could
        // rewrite any user's name and email -- including the platform
        // operator's -- and then take the account over through password reset.
        // See resolveManageableUser() for the predicate and why it keeps staff
        // and multi-store merchants working.
        $admin = $this->resolveManageableUser($request->user_id);

        if (!empty($admin)) {
            if (!empty($request->name)) {
                $admin->name = $request->name;
            }
            if (!empty($request->email)) {
                $admin->email = $request->email;
            }
            if ($request->hasFile('profile_image')) {
                $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->profile_image->getClientOriginalName());
                $path = Utility::upload_file($request, 'profile_image', $fileName, $dir, []);
                if ($path['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $path['msg'];

                    return $msg;
                }
                $admin->profile_image = $path['url'];
            }
            $admin->save();
            return $this->success(['message' => __('User Data updated successfully!')]);
        } else {
            return $this->error(['message' => __('User not found.')]);
        }
    }

    public function UpdatePassword(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'user_id' => 'required',
            'old_password' => 'required',
            'password' => 'required|string|min:4|same:password',
            'password_confirmation' => 'required|string|min:4|same:password',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        // NEW-api-password-oracle + NEW-api-updatepassword-null-deref.
        //
        // Two defects on two adjacent lines. `User::find($request->user_id)`
        // accepted any user id on the platform, turning this into an
        // old-password guessing oracle against any account; and
        // `$current_password = $admin->password` ran BEFORE the `!empty($admin)`
        // check immediately below it, so a user_id that matched nothing raised
        // "Attempt to read property 'password' on null" -- a 500 where the
        // method's own error branch was already written and simply unreachable.
        //
        // The read is moved inside the guard, which is what the existing
        // `!empty($admin)` branch was always for.
        $admin = $this->resolveManageableUser($request->user_id);

        if (!empty($admin)) {
            $current_password = $admin->password;

            if (Hash::check($request->old_password, $current_password)) {
                $admin->password = bcrypt($request->password);
                $admin->save();
                return $this->success(['message' => 'User Password updated successfully.']);
            } else {
                return $this->error(['message' => __('Please Enter Correct Current Password!')]);
            }
        } else {
            return $this->error(['message' => __('User not found.')]);
        }
    }

    public function EmailSetting(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $user = Auth::user();
        if ($user) {

            $rules = [
                'mail_driver' => 'required|string|max:50',
                'mail_host' => 'required|string|max:50',
                'mail_port' => 'required|string|max:50',
                'mail_username' => 'required|string|max:50',
                'mail_password' => 'required|string|max:50',
                'mail_encryption' => 'required|string|max:50',
                'mail_from_address' => 'required|string|max:50',
                'mail_from_name' => 'required|string|max:50',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $post['MAIL_DRIVER'] = $request->mail_driver;
            $post['MAIL_HOST'] = $request->mail_host;
            $post['MAIL_PORT'] = $request->mail_port;
            $post['MAIL_USERNAME'] = $request->mail_username;
            $post['MAIL_PASSWORD'] = $request->mail_password;
            $post['MAIL_ENCRYPTION'] = $request->mail_encryption;
            $post['MAIL_FROM_NAME'] = $request->mail_from_name;
            $post['MAIL_FROM_ADDRESS'] = $request->mail_from_address;

            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];
                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Email Setting successfully updated.')]);

        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetEmailSetting(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $emaildata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $emaildata['MAIL_DRIVER'] = isset($settings['MAIL_DRIVER']) ? $settings['MAIL_DRIVER'] : '';
            $emaildata['MAIL_HOST'] = isset($settings['MAIL_HOST']) ? $settings['MAIL_HOST'] : '';
            $emaildata['MAIL_PORT'] = isset($settings['MAIL_PORT']) ? $settings['MAIL_PORT'] : '';
            $emaildata['MAIL_USERNAME'] = isset($settings['MAIL_USERNAME']) ? $settings['MAIL_USERNAME'] : '';
            $emaildata['MAIL_PASSWORD'] = isset($settings['MAIL_PASSWORD']) ? $settings['MAIL_PASSWORD'] : '';
            $emaildata['MAIL_ENCRYPTION'] = isset($settings['MAIL_ENCRYPTION']) ? $settings['MAIL_ENCRYPTION'] : '';
            $emaildata['MAIL_FROM_NAME'] = isset($settings['MAIL_FROM_NAME']) ? $settings['MAIL_FROM_NAME'] : '';
            $emaildata['MAIL_FROM_ADDRESS'] = isset($settings['MAIL_FROM_ADDRESS']) ? $settings['MAIL_FROM_ADDRESS'] : '';
            return $this->success($emaildata);
        } else {
            return $this->error(['message' => __('Email Setting not found!')]);
        }
    }

    public function LoyalitySetting(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $user = Auth::user();
        if ($user) {
            $rules = [
                'loyality_program_enabled' => 'required',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $loyality_program_enabled = !empty($request->loyality_program_enabled) ? $request->loyality_program_enabled : 'off';
            $reward_point = !empty($request->reward_point) ? $request->reward_point : 0;

            $post['loyality_program_enabled'] = $loyality_program_enabled;
            $post['reward_point'] = $reward_point;

            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];

                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Loyality Setting successfully updated.')]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetLoyalitySetting(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $loyalitydata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $loyalitydata['loyality_program_enabled'] = isset($settings['loyality_program_enabled']) ? $settings['loyality_program_enabled'] : '';
            $loyalitydata['reward_point'] = isset($settings['reward_point']) ? $settings['reward_point'] : '';

            return $this->success($loyalitydata);
        } else {
            return $this->error(['message' => __('Loyality Setting not found!')]);
        }
    }

    public function CodPayment(Request $request)
    {

        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $user = Auth::user();
        if ($user) {
            $rules = [
                'CURRENCY_NAME' => 'required',
                'CURRENCY' => 'required',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $post['CURRENCY'] = $request->CURRENCY;
            $post['CURRENCY_NAME'] = $request->CURRENCY_NAME;


            $is_cod_enabled = !empty($request->is_cod_enabled) ? $request->is_cod_enabled : 'off';

            if ($request->is_cod_enabled == 'on' && !empty($request->cod_image)) {
                $theme_image = $request->cod_image;
                $image = upload_theme_image($theme_image);
                if ($image['status'] == false) {
                    return $this->error($image['message']);
                } else {
                    $where = ['name' => 'cod_image'];
                    // P1-SEC-18 F6: this store's row. Unscoped, ->first() was the LOWEST-ID row on the platform -- some other
                    // tenant's -- and the delete removed their file. (`Setting` is not in scoped_models.) And the stored value is
                    // data a tenant can write, so the unlink goes through StoredUpload, which refuses a path that does not resolve
                    // inside uploads/.
                    $Setting = Setting::where($where)->where('store_id', $store->id)->first();

                    if (!empty($Setting)) {
                        \App\Services\StoredUpload::delete($Setting->value, $user->creatorId());
                    }
                    $post['cod_image'] = $image['image_path'];
                }
            }

            if ($request->is_cod_enabled == 'off') {
                $request->cod_info = '';
            }

            $post['is_cod_enabled'] = $is_cod_enabled;
            $post['cod_info'] = $request->cod_info;


            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];

                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Cod payment Setting successfully updated.')]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetCodPayment(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $coddata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $coddata['CURRENCY'] = !empty($settings['CURRENCY']) ? $settings['CURRENCY'] : '';
            $coddata['CURRENCY_NAME'] = !empty($settings['CURRENCY_NAME']) ? $settings['CURRENCY_NAME'] : '';
            $coddata['is_cod_enabled'] = !empty($settings['is_cod_enabled']) ? $settings['is_cod_enabled'] : '';
            $coddata['cod_info'] = !empty($settings['cod_info']) ? $settings['cod_info'] : '';
            $coddata['cod_image'] = !empty($settings['cod_image']) ? $settings['cod_image'] : '';

            return $this->success($coddata);
        } else {
            return $this->error(['message' => __('COD Setting Data not found!')]);
        }
    }

    public function BankPayment(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $user = Auth::user();
        if ($user) {
            $rules = [
                'CURRENCY_NAME' => 'required',
                'CURRENCY' => 'required',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $post['CURRENCY'] = $request->CURRENCY;
            $post['CURRENCY_NAME'] = $request->CURRENCY_NAME;


            $is_bank_transfer_enabled = !empty($request->is_bank_transfer_enabled) ? $request->is_bank_transfer_enabled : 'off';

            if ($request->is_bank_transfer_enabled == 'on' && !empty($request->bank_transfer_image)) {
                $theme_image = $request->bank_transfer_image;
                $image = upload_theme_image( $theme_image);
                if ($image['status'] == false) {
                    return $this->error($image['message']);
                } else {
                    $where = ['name' => 'bank_transfer_image'];
                    // P1-SEC-18 F6: this store's row. Unscoped, ->first() was the LOWEST-ID row on the platform -- some other
                    // tenant's -- and the delete removed their file. (`Setting` is not in scoped_models.) And the stored value is
                    // data a tenant can write, so the unlink goes through StoredUpload, which refuses a path that does not resolve
                    // inside uploads/.
                    $Setting = Setting::where($where)->where('store_id', $store->id)->first();

                    if (!empty($Setting)) {
                        \App\Services\StoredUpload::delete($Setting->value, $user->creatorId());
                    }
                    $post['bank_transfer_image'] = $image['image_path'];
                }
            }

            if ($request->is_bank_transfer_enabled == 'off') {
                $request->bank_transfer = '';
            }

            $post['is_bank_transfer_enabled'] = $is_bank_transfer_enabled;
            $post['bank_transfer'] = $request->bank_transfer;


            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];

                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Bank payment Setting successfully updated.')]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetBankPayment(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $bankdata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $bankdata['CURRENCY'] = !empty($settings['CURRENCY']) ? $settings['CURRENCY'] : '';
            $bankdata['CURRENCY_NAME'] = !empty($settings['CURRENCY_NAME']) ? $settings['CURRENCY_NAME'] : '';
            $bankdata['is_bank_transfer_enabled'] = !empty($settings['is_bank_transfer_enabled']) ? $settings['is_bank_transfer_enabled'] : '';
            $bankdata['bank_transfer'] = !empty($settings['bank_transfer']) ? $settings['bank_transfer'] : '';
            $bankdata['bank_transfer_image'] = !empty($settings['bank_transfer_image']) ? $settings['bank_transfer_image'] : '';

            return $this->success($bankdata);
        } else {
            return $this->error(['message' => __('Bank Payment Setting Data not found!')]);
        }
    }

    public function StripePayment(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $user = Auth::user();
        if ($user) {
            $rules = [
                'CURRENCY_NAME' => 'required',
                'CURRENCY' => 'required',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $post['CURRENCY'] = $request->CURRENCY;
            $post['CURRENCY_NAME'] = $request->CURRENCY_NAME;


            $is_stripe_enabled = !empty($request->is_stripe_enabled) ? $request->is_stripe_enabled : 'off';

            if ($request->is_stripe_enabled == 'on' && !empty($request->stripe_image)) {
                $theme_image = $request->stripe_image;
                $image = upload_theme_image($theme_image);
                if ($image['status'] == false) {
                    return $this->error($image['message']);
                } else {
                    $where = ['name' => 'stripe_image'];
                    // P1-SEC-18 F6: this store's row. Unscoped, ->first() was the LOWEST-ID row on the platform -- some other
                    // tenant's -- and the delete removed their file. (`Setting` is not in scoped_models.) And the stored value is
                    // data a tenant can write, so the unlink goes through StoredUpload, which refuses a path that does not resolve
                    // inside uploads/.
                    $Setting = Setting::where($where)->where('store_id', $store->id)->first();

                    if (!empty($Setting)) {
                        \App\Services\StoredUpload::delete($Setting->value, $user->creatorId());
                    }
                    $post['stripe_image'] = $image['image_path'];
                }
            }



            $post['is_stripe_enabled'] = $is_stripe_enabled;
            $post['stripe_unfo'] = !empty($request->stripe_unfo) ? $request->stripe_unfo : '';
            $post['publishable_key'] = !empty($request->publishable_key) ? $request->publishable_key : '';
            $post['stripe_secret'] = !empty($request->stripe_secret) ? $request->stripe_secret : '';

            if ($request->is_stripe_enabled == 'off') {
                $post['stripe_unfo'] = '';
                $post['publishable_key'] = '';
                $post['stripe_secret'] = '';
            }

            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];

                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Stripe payment Setting successfully updated.')]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetStripePayment(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $stripedata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $stripedata['CURRENCY'] = !empty($settings['CURRENCY']) ? $settings['CURRENCY'] : '';
            $stripedata['CURRENCY_NAME'] = !empty($settings['CURRENCY_NAME']) ? $settings['CURRENCY_NAME'] : '';
            $stripedata['is_stripe_enabled'] = !empty($settings['is_stripe_enabled']) ? $settings['is_stripe_enabled'] : '';
            $stripedata['publishable_key'] = !empty($settings['publishable_key']) ? $settings['publishable_key'] : '';
            $stripedata['stripe_secret'] = !empty($settings['stripe_secret']) ? $settings['stripe_secret'] : '';
            $stripedata['stripe_unfo'] = !empty($settings['stripe_unfo']) ? $settings['stripe_unfo'] : '';
            $stripedata['stripe_image'] = !empty($settings['stripe_image']) ? $settings['stripe_image'] : '';

            return $this->success($stripedata);
        } else {
            return $this->error(['message' => __('Stripe Payment Setting Data not found!')]);
        }
    }

    public function TestMail(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $rules = [
            'email' => 'required|email'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (empty($settings['MAIL_DRIVER'])) {
            return $this->error(['message' => __('Email settings not configured')]);
        }

        try {
            config(
                [
                    'mail.driver' => $settings['MAIL_DRIVER'] ?? '',
                    'mail.host' => $settings['MAIL_HOST'] ?? '',
                    'mail.port' => $settings['MAIL_PORT'] ?? '',
                    'mail.encryption' => $settings['MAIL_ENCRYPTION'] ?? '',
                    'mail.username' => $settings['MAIL_USERNAME'] ?? '',
                    'mail.password' => $settings['MAIL_PASSWORD'] ?? '',
                    'mail.from.address' => $settings['MAIL_FROM_ADDRESS'] ?? '',
                    'mail.from.name' => $settings['MAIL_FROM_NAME'] ?? '',
                ]
            );

            Mail::to($request->email)->send(new TestMail());
        } catch (\Exception $e) {
            $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
            return $this->error(['message' => $smtp_error]);
        }
        return $this->success(['message' => __('Email send Successfully.')]);
    }

    /**
     * P1-SEC-18 F5 -- the gate for the handlers that read, write or USE a store's gateway and SMTP credentials
     * (EmailSetting, GetEmailSetting, StripePayment, GetStripePayment, TestMail).
     *
     * resolveOwnedStore() is an OWNERSHIP check, and creatorId() resolves a staff member to their employer, so it admits
     * every staff user of the merchant whatever role the merchant gave them. The decision (operator, then tenant owner, then
     * `Manage Setting` -- the permission behind the web "Settings" menu entry) is App\Services\StoreSettingsAccess, shared
     * with the web twins of these handlers so the two doors cannot drift apart.
     *
     * A refusal is HTTP 403 and never 401: the shipped Flutter app forces a logout on any 401 (network.dart:285-288). Note
     * the arguments -- ApiResponser::error($data, $message, $code); the code is the THIRD one.
     *
     * @return \Illuminate\Http\JsonResponse|null  a refusal, or null when the caller may proceed
     */
    private function refuseUnlessMayAdministerStoreSettings()
    {
        if (\App\Services\StoreSettingsAccess::permits(\Auth::user())) {
            return null;
        }

        return $this->error(['message' => __('Permission denied.')], 'fail', 403);
    }

    /**
     * May this caller write the store OWNER's name, login email or mobile?  The owner themselves and the platform operator may;
     * anyone else (staff, whom resolveOwnedStore() admits as their employer) may only resubmit what is already stored.
     */
    private function mayChangeOwnerIdentity($caller, $store, Request $request): bool
    {
        if (strtolower(trim((string) $caller->type)) === 'super admin' || (int) $caller->id === (int) $store->created_by) {
            return true;
        }

        $owner = \App\Models\User::find($store->created_by);

        foreach (['owner_name' => 'name', 'owner_email' => 'email', 'owner_mobile' => 'mobile'] as $field => $column) {
            if ($request->has($field) && (string) $request->input($field) !== (string) ($owner->{$column} ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a caller-supplied store_id to a Store the caller actually owns.
     *
     * This is the extraction of the predicate already written correctly four
     * times in this file (DeleteStore :3607, and :4609 / :6604 with their
     * super-admin limb). Returns null when the store does not exist or is
     * outside the caller's tenant.
     *
     * creatorId() (User.php:95) resolves a staff member to their owning admin,
     * so staff keep access to their employer's stores.
     *
     * Callers MUST answer null with 403, never 401: repo-B
     * lib/network/network.dart:285-288 maps any 401 to _handleUnauthorized(),
     * which clears preferences and forces a re-login, so a 401 on a tenancy
     * denial would log the merchant out of the shipped app.
     */
    /**
     * Used by ChangeStore, ViewUsedCoupon and all seven admin list methods
     * (CategoryList, ProductList, VariantList, OrderList, CouponList,
     * ShippingList, TaxList), each of which previously did a bare
     * Store::find($request->store_id) and then filtered on $store->id -- so a
     * caller holding ANY valid token read ANY store's rows by changing one
     * query-string parameter.
     */
    private function resolveOwnedStore($storeId)
    {
        $user = \Auth::user();

        if (!$user || empty($storeId)) {
            return null;
        }

        $query = Store::where('id', $storeId);

        // `strtolower(trim())`, matching PlatformOperatorOnly::handle():61 and
        // App\Policies\PlanOrderPolicy::isPlatformAdmin(), and matching the sibling helper
        // StoreController::resolveOwnedStore(). This helper now carries the tenancy decision for
        // 102 call sites in this file, so it is the last place that should be comparing an
        // unnormalised `type` string.
        if (strtolower(trim((string) $user->type)) !== 'super admin') {
            $query->where('created_by', $user->creatorId());
        }

        return $query->first();
    }

    /**
     * Every store id inside the caller's tenant.
     *
     * WHY THIS EXISTS ALONGSIDE resolveOwnedStore(). Most rows in this
     * controller are stamped with the REQUEST's store_id at creation time
     * (AddCategory :1192, AddCoupon :1329, AddShipping :1459, AddTax :1553,
     * AddVariant :1644, AddProduct :2234, AddReview :2672), so scoping a later
     * lookup by `store_id = $store->id` is exact.
     *
     * ProductBrand and ProductLabel are the exception: AddProductBrand :4443,
     * AddProductLable :4631 and :4664 stamp `store_id = getCurrentStore()`,
     * which is the CALLER's users.current_store (app/Helper/helper.php:367) and
     * is NOT required to equal the store_id query parameter. A merchant with two
     * stores can therefore legitimately hold a brand whose store_id differs from
     * the store_id they pass. Scoping those lookups by `$store->id` would have
     * been a tighter predicate that BROKE a real merchant flow -- the failure
     * mode this phase has already hit twice -- so they are scoped to the tenant
     * instead. That still closes the cross-tenant boundary, which is the actual
     * security property; crossing between a merchant's OWN stores is not a
     * tenancy violation.
     *
     * Returns an empty array for an unauthenticated caller, which makes every
     * whereIn() using it match nothing -- fail closed.
     */
    private function ownedStoreIds()
    {
        $user = \Auth::user();

        if (!$user) {
            return [];
        }

        $query = Store::query();

        if ($user->type !== 'super admin') {
            $query->where('created_by', $user->creatorId());
        }

        return $query->pluck('id')->all();
    }

    /**
     * Resolve a caller-supplied delivery-boy id to one the caller may act on.
     *
     * NEW-api-deliveryboy-unscoped. changeProfile (:4521), logout (:4563) and
     * delete_user (:4592) each did a bare DeliveryBoy::find() on a request
     * field and then wrote, revoked tokens, or DELETED the row. Every one of
     * those routes carries only `custom.auth` (routes/api.php:341-343), so any
     * holder of any valid token -- merchant, delivery boy, or customer --
     * could edit any driver's name and email, force any driver off the road by
     * deleting their tokens, or delete any driver account on the platform.
     *
     * Two legitimate principals, so two limbs:
     *   - a DELIVERY BOY acting on themselves. custom.auth logs a DeliveryBoy
     *     token into the `deliveryboy` guard (App\Http\Middleware\CustomAuth),
     *     which is how the driver app authenticates, and self-service is the
     *     only thing these three endpoints are for from that side.
     *   - a MERCHANT or the platform operator managing their own drivers.
     *     delivery_boys.store_id (create_delivery_boys_table:23) is the tenant
     *     link, so the driver must sit in one of the caller's own stores.
     *
     * Anything else resolves to null and the caller gets the method's existing
     * 'not found' envelope, which also avoids confirming that a driver id
     * exists.
     */
    private function resolveManageableDeliveryBoy($deliveryBoyId)
    {
        if (empty($deliveryBoyId)) {
            return null;
        }

        $boy = DeliveryBoy::find($deliveryBoyId);

        if (!$boy) {
            return null;
        }

        $self = auth('deliveryboy')->user();

        if ($self && (int) $self->id === (int) $boy->id) {
            return $boy;
        }

        $actor = \Auth::user();

        if ($actor) {
            if ($actor->type === 'super admin') {
                return $boy;
            }

            if (in_array((int) $boy->store_id, array_map('intval', $this->ownedStoreIds()), true)) {
                return $boy;
            }
        }

        return null;
    }

    /**
     * Resolve a caller-supplied order id to one the caller may change.
     *
     * NEW-api-status-change-any-order and NEW-api-order-cancel-any-order: the
     * API-side twins of the status.cancel defect.
     *
     * statusChange (:4294) called Order::order_status_change() on
     * $request->id with NO predicate of any kind and then dereferenced
     * Order::find($request->id) with no null check.
     *
     * orderCancel (:4616) is the more instructive one, because it LOOKED
     * guarded:
     *     if ($user && $order->deliveryboy_id == $user->id) {
     *         ... $order->delivered_status = 2; $order->save();
     *     } else {
     *         ... $order->delivered_status = 2; $order->save();
     *     }
     * Both branches cancelled the order. The ownership test changed only
     * whether deliveryboy_id was also cleared, so it read as a control while
     * authorising nothing -- the same "decoration" shape as the store lookups
     * elsewhere in this file.
     *
     * Legitimate principals: the delivery boy the order is ASSIGNED to, and
     * the merchant (or platform operator) who owns the order's store.
     */
    private function resolveChangeableOrder($orderId)
    {
        if (empty($orderId)) {
            return null;
        }

        $order = Order::find($orderId);

        if (!$order) {
            return null;
        }

        $boy = auth('deliveryboy')->user();

        if ($boy && (int) $order->deliveryboy_id === (int) $boy->id) {
            return $order;
        }

        $actor = \Auth::user();

        if ($actor) {
            if ($actor->type === 'super admin') {
                return $order;
            }

            if (in_array((int) $order->store_id, array_map('intval', $this->ownedStoreIds()), true)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Resolve a caller-supplied user_id to a User the caller may administer.
     *
     * NEW-api-edit-any-user / NEW-api-password-oracle. EditProfile (:3030) and
     * UpdatePassword (:3078) both did a bare `User::find($request->user_id)`
     * after resolving a store they never compared the user against. The store
     * lookup was pure decoration: neither method referenced $store->id when
     * loading the user.
     *
     * EditProfile was the serious half -- it writes `name` and `email` on the
     * resolved user, so ANY holder of ANY valid admin token could change the
     * EMAIL ADDRESS of any user on the platform, the platform operator
     * included, and then drive the password-reset flow to that address. That is
     * a full account takeover out of a single unconstrained find().
     *
     * UpdatePassword still requires the old password (Hash::check :3100), so it
     * is not a direct takeover, but against an arbitrary user_id it is a
     * credential-guessing oracle on any account; and it read
     * `$admin->password` BEFORE its own `!empty($admin)` check, so a
     * nonexistent user_id raised "Attempt to read property on null" -- the
     * same null-guard class as get_file().
     *
     * The predicate: yourself always; anyone in your tenant if you are an admin
     * (created_by = creatorId(), the same predicate resolveOwnedStore and
     * StoreController::changeStore:637 use, so staff keep working); anyone if
     * you are the platform operator. Returns null otherwise, and callers answer
     * null the way they already answered a missing user -- 'User not found.' --
     * which also avoids confirming that a user id exists.
     */
    private function resolveManageableUser($userId)
    {
        $actor = \Auth::user();

        if (!$actor || empty($userId)) {
            return null;
        }

        $target = User::find($userId);

        if (!$target) {
            return null;
        }

        if ((int) $target->id === (int) $actor->id) {
            return $target;
        }

        if ($actor->type === 'super admin') {
            return $target;
        }

        $tenant = $actor->creatorId();

        if (empty($tenant) || empty($target->created_by)) {
            return null;
        }

        return (int) $target->created_by === (int) $tenant ? $target : null;
    }

    /**
     * The 403 denial used for every tenancy failure in this controller.
     */
    /**
     * P2-07-13. The permission gate for a list endpoint, placed AFTER the store has resolved.
     *
     * WHY A HELPER AND NOT THE SAME TREATMENT THE DASHBOARD GOT. `/dashboard` is the app's HOME
     * SCREEN: refusing it outright would blank the first thing a merchant sees, so it withholds
     * fields and still answers 200. These are list endpoints backing one screen each, and the
     * shipped app already maps 403 to "authorized but not permitted" with the session INTACT
     * (`lib/network/network.dart`: "Deliberately NOT a forced logout") -- so refusing is both
     * correct and already handled. 401 is never used: the app force-clears its session on any 401.
     *
     * THE STORE IS PASSED EXPLICITLY, and that is the whole point of the parameter being required.
     * These handlers key their rows on `resolveOwnedStore($request->store_id)`, which admits any
     * store in the caller's tenant by design -- so the grant must be evaluated against THAT store,
     * not against `users.current_store`. Defaulting would let an employee permitted in store A
     * read store B.
     *
     * @param  list<string>  $permissions  any one of which suffices
     */
    private function refuseWithoutPermission(array $permissions, $storeId)
    {
        if (MerchantPermissions::allowsAny(\Auth::user(), $permissions, $storeId)) {
            return null;
        }

        return $this->error(
            ['message' => __('You do not have permission to perform this action.')],
            __('Forbidden.'),
            403
        );
    }

    private function storeAccessDenied()
    {
        return $this->error(
            ['message' => __('You do not have permission to access this store.')],
            __('Forbidden.'),
            403
        );
    }

    public function ChangeStore(Request $request)
    {
        $user = \Auth::user();

        // R2-05 / NEW-unauthenticated-500s: there was no null check, so an
        // unauthenticated caller reached `$user->current_store` and raised a 500.
        // The sibling DeleteStore (:3601-3605) already guards this; copy it.
        if (!$user) {
            return $this->error(
                ['message' => __('Unauthenticated user!')],
                __('Unauthenticated.'),
                401
            );
        }

        $rules = [
            'store_id' => 'required|integer',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        // R4-03: was Store::find($request->store_id) with no ownership check, so
        // any caller could point users.current_store at any store on the
        // platform and thereby satisfy every manual tenant filter as the victim.
        $store = $this->resolveOwnedStore($request->store_id);

        if (!$store) {
            return $this->storeAccessDenied();
        }

        $user->current_store = $store->id;
        $user->update();
        return $this->success(['message' => __('Store Change Successfully!')]);
    }

    public function ViewUsedCoupon(Request $request)
    {
        // Was Store::find(): resolved any store on the platform.
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->storeAccessDenied();
        }

        $rules = [
            'coupon_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }


        // NEW-viewusedcoupon-unscoped: $store was resolved above and then never
        // used in this query, so any caller read any store's coupon redemptions
        // by incrementing coupon_id. Scope the redemptions through the owning
        // coupon (coupons.store_id).
        $coupon = Coupon::where('id', $request->coupon_id)
            ->where('store_id', $store->id)
            ->first();

        if (!$coupon) {
            return $this->error(['message' => __('Data not found!')]);
        }

        $UserCoupons = UserCoupon::where('coupon_id', $coupon->id)->get()->toArray();
        if (!empty($UserCoupons)) {
            return $this->success($UserCoupons);
        } else {
            return $this->error(['message' => __('Data not found!')]);
        }
    }






public function DeleteStore(Request $request)
{
    $request->validate([
        'store_id' => ['required', 'integer'],
    ]);

    $user = \Auth::user();

    if (!$user) {
        return $this->error([
            'message' => __('Unauthenticated user!'),
        ]);
    }

    $store = Store::where('id', $request->store_id)
        ->where('created_by', $user->id)
        ->first();

    if (!$store) {
        return $this->error([
            'message' => __('Store not found or you are not authorized to delete it!'),
        ]);
    }

    /*
     * منع المستخدم من حذف آخر متجر يملكه.
     */
    $storesCount = Store::where('created_by', $user->id)->count();

    if ($storesCount <= 1) {
        return $this->error([
            'message' => __('You cannot delete your last store. Create another store first.'),
        ]);
    }

    try {
        \DB::beginTransaction();

        Page::where('store_id', $store->id)->delete();
        Order::where('store_id', $store->id)->delete();
        AppSetting::where('store_id', $store->id)->delete();
        Blog::where('store_id', $store->id)->delete();
        Contact::where('store_id', $store->id)->delete();
        Coupon::where('store_id', $store->id)->delete();
        /*
         * P1-INV-25. THE PRODUCT LOOP RUNS BEFORE THE CATEGORY DELETE, AND THE ORDER IS THE WHOLE
         * FIX. `products.category_id` is ON DELETE CASCADE against `categories.id`
         * (2023_12_26_092822_create_products_table.php:55 -- the same FK P1-INV-12/D13 was built
         * around), so deleting the categories first takes the products with it. The loop below
         * then read an EMPTY collection and `ProductController::destroy()` -- the only thing that
         * unlinks the image files and corrects the merchant's used-storage figure -- never ran.
         * The rows went and the FILES STAYED.
         *
         * `users.storage_limit` is USED storage despite the name: incremented on upload and
         * compared against `plans.storage_limit` as the ceiling (Utility.php:786-787), decremented
         * on delete, and rendered as a percentage of the plan (HomeController:273). So the old
         * order left a merchant charged for ever for storage whose rows no longer exist, and
         * refusable for new uploads against space they are not using. That is the half of this
         * defect that costs money rather than disk.
         *
         * MEASURED before and after, by StoreWipeMediaOrderingTest, on both live wipes.
         */
        /*
         * CAUSE 2: the destroy() loop below is gated on `Delete Products`, which a store owner
         * need not hold, and it refuses SILENTLY. The media freeing therefore happens here, where
         * the caller's ownership of the store has already been established, and the loop is kept
         * for everything else destroy() does (variants, carts, module rows). Calling both is safe:
         * the reclaimer is idempotent, because a path whose file is already gone sizes as 0 bytes.
         *
         * `$store->created_by` RATHER THAN `$user->creatorId()`, AND ON THIS HANDLER THE TWO ARE
         * THE SAME VALUE. That is why a mutant swapping them here SURVIVES, and the reason is a
         * guard rather than luck: this handler's own predicate is `created_by == $user->id`
         * (:4238), so the caller IS the store's owner on every reachable call. The expression is
         * still the owner's and not the caller's, for two reasons -- it matches the web twin,
         * where `resolveOwnedStore()` drops that predicate for a `super admin` and the two
         * genuinely differ, and it does not silently become wrong if this handler's predicate is
         * ever relaxed. Recorded as an equivalent mutant with its cause, not excused.
         */
        \App\Services\ProductMediaReclaimer::reclaimStore($store->id, $store->created_by);

        $products = Product::where('store_id', $store->id)->get();

        $productController = new ProductController();

        foreach ($products as $product) {
            $productController->destroy($product);
        }

        Category::where('store_id', $store->id)->delete();
        Newsletter::where('store_id', $store->id)->delete();
        PlanOrder::where('store_id', $store->id)->delete();
        Testimonial::where('store_id', $store->id)->delete();
        Setting::where('store_id', $store->id)->delete();
        Shipping::where('store_id', $store->id)->delete();
        Tax::where('store_id', $store->id)->delete();
        ProductVariant::where('store_id', $store->id)->delete();


        /*
         * حذف المتجر.
         */
        $deletedStoreId = $store->id;

        $store->delete();

        /*
         * اختيار متجر بديل بعد الحذف.
         */
        $nextStore = Store::where('created_by', $user->id)
            ->where('id', '!=', $deletedStoreId)
            ->first();

        /*
         * هذا الشرط احتياطي، والمفروض دائمًا يوجد متجر لأننا منعنا
         * حذف آخر متجر.
         */
        if (!$nextStore) {
            \DB::rollBack();

            return $this->error([
                'message' => __('No alternative store was found.'),
            ]);
        }

        /*
         * لا نغيّر المتجر الحالي إلا إذا كان هو المتجر المحذوف.
         */
        if ((int) $user->current_store === (int) $deletedStoreId) {
            $user->current_store = $nextStore->id;
            $user->save();
        }

        \DB::commit();

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'current_store' => $user->current_store,
        ];

        return $this->success([
            'message' => __('Store deleted successfully!'),
            'user_info' => $userData,
        ]);
    } catch (\Throwable $exception) {
        \DB::rollBack();

        \Log::error('Store deletion failed.', [
            'user_id' => $user->id,
            'store_id' => $request->store_id,
            'message' => $exception->getMessage(),
        ]);

        return $this->error([
            'message' => __('An error occurred while deleting the store.'),
        ]);
    }
}






    public function DeleteStore_backup(Request $request)
    {

        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $user = \Auth::user();

        Page::where('store_id', $store->id)->delete();
        Order::where('store_id', $store->id)->delete();
        AppSetting::where('store_id', $store->id)->delete();
        Blog::where('store_id', $store->id)->delete();
        Contact::where('store_id', $store->id)->delete();
        Coupon::where('store_id', $store->id)->delete();
        Category::where('store_id', $store->id)->delete();
        Newsletter::where('store_id', $store->id)->delete();
        PlanOrder::where('store_id', $store->id)->delete();
        Testimonial::where('store_id', $store->id)->delete();
        Setting::where('store_id', $store->id)->delete();
        Shipping::where('store_id', $store->id)->delete();
        Tax::where('store_id', $store->id)->delete();
        ProductVariant::where('store_id', $store->id)->delete();

        $products = Product::where('store_id', $store->id)->get();
        $pro_img = new ProductController();
        foreach ($products as $pro) {
            $pro_img->destroy($pro);
        }

        $store->delete();

        $userstore = Store::where('created_by', $user->id)->first();

        $user->current_store = $userstore->id;
        $user->save();

        $user_data['id'] = $user->id;
        $user_data['name'] = $user->name;
        $user_data['email'] = $user->email;
        $user_data['current_store'] = $user->current_store;

        return $this->success(['message' => __('Store deleted successfully!'), 'user_info' => $user_data]);
    }

    //Delivery APP
    //login

    public function deliveryLogin(Request $request)
    {
        $rules = [
            'email' => 'required|email',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        if (!empty($request->password)) {
            $user = Auth::guard('deliveryboy')->attempt(['email' => $request->email, 'password' => $request->password]);
            if (!$user) {
                return $this->error(['message' => 'Invalid login details']);
            }
            $user = Auth::guard('deliveryboy')->user();

            $user = DeliveryBoy::find($user->id);
        } else {
            return $this->error(['message' => 'Invalid login details']);
        }

        $user_data = DeliveryBoy::find($user->id);

        $user_array['currency'] = \App\Models\Utility::GetValByName('CURRENCY', $user_data->store_id);
        $user_array['currency_name'] = \App\Models\Utility::GetValByName('CURRENCY_NAME', $user_data->store_id);


        $user_array['id'] = $user_data->id;
        $user_array['name'] = $user_data->name;
        $user_array['email'] = $user_data->email;
        $user_array['image'] = !empty($user_data->profile_image) ? $user_data->profile_image : "/storage/uploads/avatar.png";
        $user_array['contact'] = $user_data->contact;

        $user->tokens()->delete();
        $token = $user->createToken('auth_token', ['delivery'])->plainTextToken;
        $user_array['token'] = $token;
        $user_array['token_type'] = 'Bearer';
        return $this->success($user_array);
    }

    public function DeliveryBoyOrderList(Request $request)
    {
        $user  = auth('deliveryboy')->user();
        
        if ($user) {
            if ($request->type == 'pending') {
                $orders = Order::select('id', 'product_order_id as product_order_id', 'order_date as date', 'final_price as amount', 'delivered_status', 'delivery_date', 'payment_status')
                    ->where('deliveryboy_id', $user->id)
                    ->whereIn('delivered_status', [0, 4, 5, 6])
                    ->paginate(10);
            }
            if ($request->type == 'complete') {
                $orders = Order::select('id', 'product_order_id as product_order_id', 'order_date as date', 'final_price as amount', 'delivered_status', 'delivery_date', 'payment_status')
                    ->where('deliveryboy_id', $user->id)
                    ->where('delivered_status', 1)
                    ->paginate(10);
            }
            if ($request->type == 'cancel') {
                $orders = Order::select('id', 'product_order_id as product_order_id', 'order_date as date', 'final_price as amount', 'delivered_status', 'delivery_date', 'payment_status')
                    ->where('deliveryboy_id', $user->id)
                    ->where('delivered_status', 2)
                    ->paginate(10);
            }

            if (!empty($orders)) {
                $orders->load('billingData');
                return $this->success($orders);
            } else {
                return $this->error(['message' => __('Order Data not found.')]);
            }
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function orderDetail(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'order_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        // S12(b). THIS ENDPOINT HAD NO OWNERSHIP CHECK AT ALL -- not a store predicate, not a
        // permission, nothing. It validated that `order_id` was present and then called
        // Order::order_detail(), which is `Order::find($order_id)` with the store id read off the
        // order itself (app/Models/Order.php), so ANY holder of ANY valid token -- a merchant
        // from another tenant, a shopper, a delivery driver -- read any order on the platform.
        // Reproduced before this change: a merchant token for tenant B returned tenant A's full
        // order document with HTTP 200.
        //
        // THE PREDICATE IS NOT NEW CODE. resolveChangeableOrder() already exists in this
        // controller and already encodes exactly the right rule -- the assigned delivery boy, the
        // platform operator, or an actor whose tenant owns the order's store. The sibling
        // ViewOrder() (:1861) already uses the same shape. This endpoint simply never called it.
        //
        // WHY THE DENIAL IS THE EXISTING 'Order not found!' ENVELOPE rather than a 403:
        //   - the contract must not change. $this->error() answers HTTP 200 with
        //     {status: 0, message, data}, which is what this endpoint ALREADY returns when
        //     Order::order_detail() reports a problem, so the Flutter app's existing handling of
        //     that path is unchanged and no app release is needed;
        //   - repo B's lib/network/network.dart maps any 401 onto a forced logout, so a denial
        //     must never be 401;
        //   - "not found" and "not yours" are deliberately indistinguishable, so the endpoint
        //     cannot be used to enumerate which order ids exist on the platform.
        if (!$this->resolveChangeableOrder($request->order_id)) {
            return $this->error(['message' => __('Order not found!')]);
        }

        $order = Order::order_detail($request->order_id);

        if (!empty($order['message'])) {
            return $this->error($order);
        } else {
            return $this->success($order);
        }
    }

    public function statusChange(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'id' => 'required',
                'delivered' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        // NEW-api-status-change-any-order. The API-side status.cancel. This
        // called Order::order_status_change() on the request's id with no
        // predicate at all, so any valid token changed any order's delivery
        // status on the platform -- and then dereferenced
        // Order::find($request->id) with no null check, so an unmatched id was
        // a 500 rather than the 'Order not found.' this method can now give.
        //
        // The predicate runs BEFORE the state change, which is the ordering
        // rule the status.cancel repair established: no destructive side
        // effect ahead of the success check.
        $order = $this->resolveChangeableOrder($request->id);

        if (!$order) {
            return $this->error(['message' => __('Order not found.')]);
        }

        $data['order_id'] = $order->id;
        $data['order_status'] = $request->delivered;
        $responce = Order::order_status_change($data);

        $order = $order->fresh();

        if ($order->delivered_status == 0) {
            $responce['order_status'] = __('Pending');
        }
        if ($order->delivered_status == 1) {
            $responce['order_status'] = __('Delivered');
        }
        if ($order->delivered_status == 2) {
            $responce['order_status'] = __('Cancel');
        }
        if ($order->delivered_status == 4) {
            $responce['order_status'] = 'pickedup';
        }
        if ($order->delivered_status == 5) {
            $responce['order_status'] = 'shipped';
        }
        if ($order->delivered_status == 6) {
            $responce['order_status'] = 'delivered';
        }
        if ($order->delivered_status == 8) {
            $responce['order_status'] = __('Pre Order');
        }

        if ($responce) {
            return $this->success($responce);
        } else {
            return $this->error(['message' => __('Something went wrong.')]);
        }
    }

public function deliveryHome(Request $request)
{
    $user = auth('deliveryboy')->user();

    if (!$user) {
        return $this->error(['message' => __('Unauthorized')], 401);
    }

    // ✅ الحصول على معرف المتجر لتنسيق العملة بشكل صحيح
    $storeId = $user->store_id ?? getCurrentStore();
    $counterData = [];

    // ✅ إضافة حالة العمولة والرسالة
    $isCommissionEnabled = (bool) ($user->is_commission_enabled ?? false);
    $counterData['is_commission_enabled'] = $isCommissionEnabled;
    $counterData['commission_message']    = $isCommissionEnabled 
        ? 'Commission is active.' 
        : 'Commission is not enabled for your account. Please contact the administrator.';

    $total_pending = Order::where('deliveryboy_id', $user->id)
        ->whereIn('delivered_status', [0, 4, 5, 6])
        ->count();

    $total_complete = Order::where('deliveryboy_id', $user->id)
        ->where('delivered_status', 1)
        ->count();

    $total_cancel = Order::where('deliveryboy_id', $user->id)
        ->where('delivered_status', 2)
        ->count();

    $counterData['pending'] = $total_pending;
    $counterData['complete'] = $total_complete;
    $counterData['cancel'] = $total_cancel;

    // ✅ total earning (مع تنسيق العملة)
    $totalEarningRaw = \App\Models\DeliveryEarning::where('delivery_boy_id', $user->id)
        ->sum('commission_amount');
    
    $counterData['total_earning'] = currency_format_with_sym($totalEarningRaw, $storeId) ?? SetNumberFormat($totalEarningRaw);

    // ✅ today earning (مع تنسيق العملة)
    $todayEarningRaw = \App\Models\DeliveryEarning::where('delivery_boy_id', $user->id)
        ->whereDate('created_at', today())
        ->sum('commission_amount');

    $counterData['today_earning'] = currency_format_with_sym($todayEarningRaw, $storeId) ?? SetNumberFormat($todayEarningRaw);

    // ✅ order (آخر 5 طلبات مع تنسيق المبلغ)
    $assign_order = Order::orderBy('created_at', 'Desc')
        ->select('id', 'product_order_id', 'order_date', 'final_price', 'delivered_status', 'delivery_date', 'payment_type', 'payment_status')
        ->where('deliveryboy_id', $user->id)
        ->limit(5)
        ->get()
        ->map(function ($order) use ($storeId) {
            return [
                'id'               => $order->id,
                'product_order_id' => $order->product_order_id,
                'date'             => $order->order_date,
                // ✅ تنسيق مبلغ الطلبية أيضاً ليتطابق مع باقي النظام
                'amount'           => currency_format_with_sym($order->final_price, $storeId) ?? SetNumberFormat($order->final_price),
                'delivered_status' => $order->delivered_status,
                'delivery_date'    => $order->delivery_date,
                'payment_type'     => $order->payment_type,
                'payment_status'   => $order->payment_status,
            ];
        });

    $counterData['transaction'] = $assign_order;

    return $this->success($counterData);
}
public function deliveryTransaction(Request $request)
{
    $user = auth('deliveryboy')->user();

    if (!$user) {
        return $this->error(['message' => __('Unauthorized')], 401);
    }

    // ✅ الحصول على معرف المتجر لتنسيق العملة بشكل صحيح
    $storeId = $user->store_id ?? getCurrentStore();

    $isCommissionEnabled = (bool) ($user->is_commission_enabled ?? false);

    if (!$isCommissionEnabled) {
        return $this->success([
            'is_commission_enabled' => false,
            'commission_message'    => 'Commission is not enabled for your account. Please contact the administrator.',
            // ✅ تنسيق الأصفار برمز العملة
            'total_earning'         => currency_format_with_sym(0, $storeId) ?? SetNumberFormat(0),
            'today_earning'         => currency_format_with_sym(0, $storeId) ?? SetNumberFormat(0),
            'transaction'           => [],
            'pagination'            => [
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => 10,
                'total'        => 0,
            ]
        ]);
    }

    $earnings = \App\Models\DeliveryEarning::with([
        'order:id,product_order_id,order_date,final_price,delivery_price,payment_status,payment_type,delivered_status',
        'shippingMethod:id,method_name'
    ])
    ->where('delivery_boy_id', $user->id)
    ->orderBy('created_at', 'desc')
    ->paginate(10);

    $transactions = $earnings->map(function ($earning) use ($storeId) {
        return [
            'id'                    => $earning->id,
            'product_order_id'      => $earning->order->product_order_id ?? 'N/A',
            'date'                  => $earning->order->order_date ?? $earning->created_at,
            
            // ✅ تطبيق تنسيق العملة على جميع الحقول المالية (نفس أسلوب OrderDataTable)
            'final_price'           => currency_format_with_sym(($earning->order->final_price ?? 0), $storeId) ?? SetNumberFormat($earning->order->final_price ?? 0),
            'delivery_price'        => currency_format_with_sym(($earning->order->delivery_price ?? 0), $storeId) ?? SetNumberFormat($earning->order->delivery_price ?? 0),
            'earning_amount'        => currency_format_with_sym(($earning->commission_amount ?? 0), $storeId) ?? SetNumberFormat($earning->commission_amount ?? 0),
            
            // النسبة المئوية تبقى رقماً عادياً (float) لأنها ليست عملة
            'commission_percentage' => (float) $earning->commission_percentage,
            
            // ✅ بيانات إضافية
            'payment_type'          => $earning->order->payment_type ?? 'N/A',
            'shipping_method_name'  => $earning->shippingMethod->method_name ?? 'N/A',

            'delivered_status'      => $earning->order->delivered_status ?? 0,
            'payment_status'        => $earning->order->payment_status ?? 'N/A',
            
            'delivery_date'         => null, 
            'demo_field'            => 'demo_field',
            'delivered_status_string'=> $this->getStatusString($earning->order->delivered_status ?? 0),
            'delivered_image'       => '',
            'order_id_string'       => '#' . ($earning->order->product_order_id ?? ''),
            'return_date'           => '1970-01-01',
            'user_name'             => 'Deleted Customer', 
            'customer_data'         => null
        ];
    });

    // حساب الإجماليات كرقم أولاً
    $totalEarningRaw = \App\Models\DeliveryEarning::where('delivery_boy_id', $user->id)
        ->sum('commission_amount');

    $todayEarningRaw = \App\Models\DeliveryEarning::where('delivery_boy_id', $user->id)
        ->whereDate('created_at', today())
        ->sum('commission_amount');

    $counterData = [
        'is_commission_enabled' => true,
        'commission_message'    => 'Commission is active.',
        
        // ✅ تطبيق تنسيق العملة على الإجماليات
        'total_earning'         => currency_format_with_sym($totalEarningRaw, $storeId) ?? SetNumberFormat($totalEarningRaw),
        'today_earning'         => currency_format_with_sym($todayEarningRaw, $storeId) ?? SetNumberFormat($todayEarningRaw),
        
        'transaction'           => $transactions,
        'pagination'            => [
            'current_page'    => $earnings->currentPage(),
            'last_page'       => $earnings->lastPage(),
            'per_page'        => $earnings->perPage(),
            'total'           => $earnings->total(),
            'first_page_url'  => $earnings->url(1),
            'next_page_url'   => $earnings->nextPageUrl(),
            'prev_page_url'   => $earnings->previousPageUrl(),
            'path'            => $earnings->path(),
        ]
    ];

    return $this->success($counterData);
}

private function getStatusString($status)
{
    switch ($status) {
        case 0: return 'Pending';
        case 1: return 'Delivered';
        case 2: return 'Cancel';
        case 3: return 'Return';
        default: return 'Unknown';
    }
}   
public function changeProfile(Request $request)
    {
        $rules = [
            'id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        // NEW-api-deliveryboy-unscoped: was DeliveryBoy::find($request->id),
        // so any valid token rewrote any driver's name, email and contact.
        $deliveryboy = $this->resolveManageableDeliveryBoy($request->id);

        $dir        = 'uploads';

        if (!empty($deliveryboy)) {
            if (!empty($request->name)) {
                $deliveryboy->name = $request->name;
            }
            if (!empty($request->email)) {
                $deliveryboy->email = $request->email;
            }
            if (!empty($request->contact)) {
                $deliveryboy->contact = $request->contact;
            }

            if ($request->hasFile('profile_image')) {
                $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->profile_image->getClientOriginalName());
                $path = Utility::upload_file($request, 'profile_image', $fileName, $dir, []);
                if ($path['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $path['msg'];

                    return $msg;
                }
                $deliveryboy->profile_image = $path['url'];
            }
            $deliveryboy->save();

            $user_array['id'] = $deliveryboy->id;
            $user_array['name'] = $deliveryboy->name;
            $user_array['email'] = $deliveryboy->email;
            $user_array['contact'] = $deliveryboy->contact;
            $user_array['image'] = !empty($deliveryboy->profile_image) ? $deliveryboy->profile_image : "/storage/uploads/avatar.png";

            return $this->success($user_array);
        } else {
            return $this->error(['message' => __('Delivery Boy not found.')]);
        }
    }

    public function logout(Request $request)
    {
        // NEW-api-deliveryboy-unscoped: was DeliveryBoy::find($request->user_id)
        // followed by $user->tokens()->delete(). Any valid token could revoke
        // ANY driver's tokens and force them off the road -- a denial of
        // service against a delivery fleet from one request body field.
        $user = $this->resolveManageableDeliveryBoy($request->user_id);

        if (!empty($user)) {
            return $this->success([
                'message' => __('User Logout'),
                'logout' => $user->tokens()->delete()
            ]);
        } else {
            return $this->error([
                'message' => __('User not found')
            ]);
        }
    }

    public function delete_user(Request $request)
    {
        $rules = [
            'user_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        // NEW-api-deliveryboy-unscoped, the worst of the three: this was
        // DeliveryBoy::find($request->user_id) then $user->delete(). Any
        // holder of any valid token DELETED any delivery boy account on the
        // platform. Irreversible.
        $user = $this->resolveManageableDeliveryBoy($request->user_id);

        if (!empty($user)) {
            $user->delete();

            return $this->success(['message' => __('User Deleted successfully')]);
        } else {
            return $this->error(['message' => __('User not found.')]);
        }
    }

    public function orderCancel(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'order_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        // NEW-api-order-cancel-any-order. This LOOKED guarded and was not:
        //   if ($user && $order->deliveryboy_id == $user->id) { ...save... }
        //   else                                              { ...save... }
        // BOTH branches set delivered_status = 2 and saved. The ownership test
        // decided only whether deliveryboy_id was ALSO cleared, so it read as
        // an authorization control while authorising nothing. Any valid token
        // cancelled any pending order on the platform.
        //
        // The order is now resolved through the caller first -- the assigned
        // delivery boy, or the merchant who owns the order's store -- and the
        // deliveryboy_id reset keeps its original meaning: it is the
        // ASSIGNED DRIVER cancelling, so the assignment is released. A
        // merchant cancelling leaves the assignment alone, exactly as before.
        $order = $this->resolveChangeableOrder($request->order_id);
        $user = $order ? $this->resolveManageableDeliveryBoy($request->user_id) : null;

        if (!empty($order)) {
            if ($order->delivered_status == '0') {
                if ($user && $order->deliveryboy_id == $user->id) {
                    $order->deliveryboy_id = null; // Removed quotes around null
                    $order->delivered_status = 2;
                    $order->save();
                    return $this->success(['message' => __('Order cancel Successfully.')]);
                } else {
                    $order->delivered_status = 2;
                    $order->save();
                    return $this->success(['message' => __('Order cancel Successfully.')]);
                }
            } else {
                return $this->error(['message' => __('You cannot cancel this order.')]);
            }
        } else {
            return $this->error(['message' => __('Order not found.')]);
        }
    }
    public function AddProductBrand(Request $request)
    {
        $user = Auth::user();
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'logo' => 'required'
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $slug = ProductBrand::slugs($request->name);

        $url = null;
        if ($request->logo) {
            $dir        = 'uploads/' . getCurrentStore();
            $image_size = $request->file('logo')->getSize();
            $result = Utility::updateStorageLimit($user->creatorId(), $image_size);
            if ($result == 1) {
                $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->logo->getClientOriginalName());
                $path = Utility::upload_file($request, 'logo', $fileName, $dir, []);
                
                if ($path['flag'] == 1) {
                    $url = $path['url'];
                } else {
                    return $this->error([
                        'msg' => $path['msg']
                    ]);
                }
            } else {
                return $this->error([
                    'result' => $result
                ]);
            }
        }

        $productBrand                      = new ProductBrand();
        $productBrand->name                = $request->name;
        $productBrand->logo                = $url;
        $productBrand->slug                = $slug;
        $productBrand->status              = $request->status;
        $productBrand->is_popular          = $request->is_popular;
        $productBrand->store_id            = getCurrentStore();
        $productBrand->created_by          = $user->id;
        $productBrand->save();
        return $this->success([
            'message' => __('Product Brand successfully created.')
        ]);
    }
    public function UpdateProductBrand(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $validator = \Validator::make(
            $request->all(),
            [
                'productbrand_id' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productBrand = ProductBrand::where('id', $request->productbrand_id)
            ->whereIn('store_id', $this->ownedStoreIds())
            ->first();

        // NULL GUARD, pre-existing and now load-bearing. This method wrote to
        // the resolved row with no existence check, so an id matching nothing
        // already raised "Attempt to read property on null" -- a 500 on bad
        // input. Scoping the lookup to the caller's tenant makes a FOREIGN id
        // resolve to null too, so the guard has to be here or a denial would
        // surface as a 500 instead of a clean refusal. Same message as this
        // file's other not-found branches, so a foreign id is indistinguishable
        // from an absent one.
        if (empty($productBrand)) {
            return $this->error(['message' => __('Product Brand data not found.')]);
        }

        $url = null;
        if ($request->logo) {
            $file_path = $productBrand->logo;
            $dir        = 'uploads/' . getCurrentStore();
            $image_size = $request->file('logo')->getSize();
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
            if ($result == 1) {
                Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                $fileName = rand(10, 100) . '_' . time() . "_" . basename($request->logo->getClientOriginalName());
                $path = Utility::upload_file($request, 'logo', $fileName, $dir, []);
                if ($path['flag'] == 1) {
                    $url = $path['url'];
                } else {
                    return $this->error([
                        'msg' => $path['msg']
                    ]);
                }
            } else {
                return $this->error([
                    'result' => $result
                ]);
            }
        }

        $productBrand->name                = $request->name;
        if ($url) {
            $productBrand->logo                = $url;
        }
        if ($request->status) {
            $productBrand->status              = $request->status;
        }
        if ($request->is_popular) {
            $productBrand->is_popular          = $request->is_popular;
        }
        $productBrand->save();

        return $this->success([
            'message' => __('Product Brand successfully updated.')
        ]);
    }

    public function DeleteProductBrand(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'productbrand_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $productBrand = ProductBrand::where('id', $request->productbrand_id)
            ->whereIn('store_id', $this->ownedStoreIds())
            ->first();

        if (!empty($productBrand)) {
            $productBrand->delete();
            return $this->success(['message' => __('Product Brand deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Product Brand data not found.')]);
        }
    }
    public function ChangeStatus(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $rules = [
            'productbrand_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productBrand = ProductBrand::where('id', $request->productbrand_id)
            ->whereIn('store_id', $this->ownedStoreIds())
            ->first();

        if ($productBrand) {
            $productBrand->status = $request->status;
            $productBrand->save();
            $return['status'] = 'success';
            $return['message'] = __('Status change successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Something went wrong!!');
            return response()->json($return);
        }
    }
    public function ChangePopular(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $rules = [
            'productbrand_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productBrand = ProductBrand::where('id', $request->productbrand_id)
            ->whereIn('store_id', $this->ownedStoreIds())
            ->first();

        if ($productBrand) {
            $productBrand->is_popular = $request->is_popular;
            $productBrand->save();
            $return['status'] = 'success';
            $return['message'] = __('Status change successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Something went wrong!!');
            return response()->json($return);
        }
    }
    public function AddProductLable(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required'
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $user = Auth::user();
        if ($user) {

            $slug = ProductLabel::slugs($request->name);
            
            $productLabel                      = new ProductLabel();
            $productLabel->name                = $request->name;
            $productLabel->slug                = $slug;
            $productLabel->status              = $request->status;
            $productLabel->store_id            = getCurrentStore();
            $productLabel->created_by          = $user->id;
            $productLabel->save();
            return $this->success([
                'message' => __('Product Label successfully created.')
            ]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }
    public function UpdateProductLable(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'lable_id' => 'required',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productLabel = ProductLabel::where('id', $request->lable_id)
            ->whereIn('store_id', $this->ownedStoreIds())
            ->first();

        // NULL GUARD, pre-existing and now load-bearing. This method wrote to
        // the resolved row with no existence check, so an id matching nothing
        // already raised "Attempt to read property on null" -- a 500 on bad
        // input. Scoping the lookup to the caller's tenant makes a FOREIGN id
        // resolve to null too, so the guard has to be here or a denial would
        // surface as a 500 instead of a clean refusal. Same message as this
        // file's other not-found branches, so a foreign id is indistinguishable
        // from an absent one.
        if (empty($productLabel)) {
            return $this->error(['message' => __('Product Label data not found.')]);
        }
        $productLabel->name                = $request->name;
        if ($request->status) {
            $productLabel->status              = $request->status;
        }
        $productLabel->store_id            = getCurrentStore();
        $productLabel->save();

        return $this->success([
            'message' => __('Product Label successfully updated.')
        ]);
    }
    public function DeleteProductLable(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $rules = [
            'lable_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $productLabel = ProductLabel::where('id', $request->lable_id)
            ->whereIn('store_id', $this->ownedStoreIds())
            ->first();
        if (!empty($productLabel)) {
            $productLabel->delete();

            return $this->success(['message' => __('Product Label Deleted successfully')]);
        } else {
            return $this->error(['message' => __('Product Label data not found.')]);
        }
    }
    public function LableChangeStatus(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $rules = [
            'lable_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productLabel = ProductLabel::where('id', $request->lable_id)
            ->whereIn('store_id', $this->ownedStoreIds())
            ->first();

        if ($productLabel) {
            $productLabel->status = $request->status;
            $productLabel->save();
            $return['status'] = 'success';
            $return['message'] = __('Status change successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Something went wrong!!');
            return response()->json($return);
        }
    }


public function CustomerList(Request $request)
{
    try {
        $adminUser = auth()->user();

        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => [
                    'message' => 'Unauthorized. Valid authentication token is required.'
                ]
            ], 401);
        }

        $storeId = $request->store_id ?? getCurrentStore();

        if (!$storeId) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => [
                    'message' => 'Store ID is required.'
                ]
            ], 400);
        }

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => [
                    'message' => 'You do not have permission to access this store.'
                ]
            ], 403);
        }

        $query = \App\Models\Customer::where('store_id', $storeId);

        $customers = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 10);

        $customersData = $customers->map(function ($customer) use ($storeId) {
            $ordersCount = \App\Models\Order::where('customer_id', $customer->id)
                ->where('store_id', $storeId)
                ->count();

            $totalSpend = \App\Models\Order::where('customer_id', $customer->id)
                ->where('store_id', $storeId)
                ->sum('final_price');

            $aov = $ordersCount > 0 ? $totalSpend / $ordersCount : 0;

            // جلب العنوان الافتراضي للزبون
            $defaultAddress = \App\Models\DeliveryAddress::where('customer_id', $customer->id)
                ->where('default_address', 1)
                ->first();

            $address = '';
            $stateName = '';

            if ($defaultAddress) {
                // بناء العنوان الكامل
                $addressParts = [];
                if (!empty($defaultAddress->address)) {
                    $addressParts[] = $defaultAddress->address;
                }
                if (!empty($defaultAddress->city)) {
                    $addressParts[] = $defaultAddress->city;
                }
                if (!empty($defaultAddress->postcode)) {
                    $addressParts[] = $defaultAddress->postcode;
                }
                $address = implode(', ', $addressParts);

                // جلب اسم الولاية
                if (!empty($defaultAddress->state_id)) {
                    $state = \App\Models\State::find($defaultAddress->state_id);
                    if ($state) {
                        $stateName = $state->name ?? '';
                    }
                }
            }

            return [
                'id' => $customer->id,
                'customer_info' => [
                    'name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                    'mobile' => $customer->mobile ?? '',
                ],
                'email' => $customer->email ?? '',
                'last_active' => $customer->last_active
                    ? \Carbon\Carbon::parse($customer->last_active)->format('F d, Y')
                    : null,
                'date_registered' => $customer->regiester_date
                    ? \Carbon\Carbon::parse($customer->regiester_date)->format('F d, Y')
                    : null,
                'orders' => $ordersCount,
                'total_spend' => number_format($totalSpend, 2),
                'aov' => number_format($aov, 2),
                'status' => (int) ($customer->status ?? 1),
                'status_text' => ($customer->status ?? 1) == 1 ? 'Active' : 'Inactive',
                'address' => $address,
                'state_name' => $stateName,
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Customers retrieved successfully.',
            'data' => [
                'store_id' => $storeId,
                'store_name' => $store->name ?? '',
                'customers' => $customersData->toArray(),
                'pagination' => [
                    'total' => $customers->total(),
                    'per_page' => $customers->perPage(),
                    'current_page' => $customers->currentPage(),
                    'last_page' => $customers->lastPage(),
                    'has_more' => $customers->hasMorePages(),
                ]
            ]
        ], 200);

    } catch (\Exception $e) {
        \Log::error('API CustomerList Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Debug Error: ' . $e->getMessage()
            ]
        ], 500);
    }
}
public function destroy(Request $request, $id = null)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $customerId = $request->query('customer_id') ?? $id;
        $storeId = $request->query('store_id');

        $validator = \Validator::make($request->query(), [
            'customer_id' => 'required|integer|exists:customers,id',
            'store_id'    => 'required|integer|exists:stores,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => [
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => [
                    'message' => 'You do not have permission to delete customers from this store.'
                ]
            ], 403);
        }

        $targetCustomer = \App\Models\Customer::where('id', $customerId)
            ->where('store_id', $storeId)
            ->first();

        if (!$targetCustomer) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => [
                    'message' => __('The customer not found or does not belong to this store.')
                ]
            ], 404);
        }

        $targetCustomer->delete();

        return response()->json([
            'status' => 1,
            'message' => 'successful',
            'data' => [
                'message' => __('Customer deleted successfully.')
            ]
        ], 200);

    } catch (\Exception $e) {
        \Log::error('API Customer Destroy Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to delete customer. Please try again later.'
            ]
        ], 500);
    }
}
public function store(Request $request)
{
    try {
        $adminUser = auth()->user();

        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                \Illuminate\Validation\Rule::unique('customers', 'email')
                    ->where('store_id', $storeId) 
            ],
            'mobile' => 'required|string|max:20',
            'password' => 'required|string|min:8',
            'status' => 'required|in:0,1',
        ]);

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to add customers to this store.']
            ], 403);
        }

        $customer = \App\Models\Customer::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'] ?? null,
            // A FIXED, PUBLIC DEFAULT IS NOT A PASSWORD. This fell back to Hash::make('default123')
            // -- a literal committed to this repository -- whenever an administrator created a
            // customer without supplying one, so anyone who read the source could sign in as every
            // such account. Same defect as the 'hello@1234' the recovery emergency removed and the
            // '1234' in PaymentController::createCustomer.
            //
            // A long random value leaves the account recoverable through the password-reset flow
            // but not signable-into with a known value. Nothing attempts a login with the old
            // literal (checked across app/).
            'password' => !empty($validated['password'])
                ? \Hash::make($validated['password'])
                : \Hash::make(\Illuminate\Support\Str::random(64)),
            'type' => 'customer',
            'status' => $validated['status'],
            'store_id' => $storeId, 
            'regiester_date' => now()->toDateString(),
            'last_active' => now()->toDateString(),
            'created_by' => $adminUser->id,
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'status' => 1,
            'message' => 'successful',
            'data' => [
                'message' => 'Customer created successfully.',
                'customer_id' => $customer->id,
                'store_id' => $storeId
            ]
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Customer Store Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to create customer. Please try again later.'
            ]
        ], 500);
    }
}  
  

public function createOrderViaApi(Request $request)
{
    $storeId = $request->query('store_id');

    \Validator::make(['store_id' => $storeId], [
        'store_id' => 'required|integer|exists:stores,id'
    ])->validate();

$currentCurrencyCode = \App\Models\Utility::GetValueByName('defult_currancy', $storeId) ?? 'USD';
$currentCurrencySymbol = \App\Models\Utility::GetValueByName('defult_currancy_symbol', $storeId) ?? '$';


    $validated = $request->validate([
        'customer_id' => 'required|exists:customers,id,status,1',
        'products' => 'required|array|min:1',
        'products.*.product_id' => 'required|exists:products,id',
        'products.*.quantity' => 'required|integer|min:1',
        'products.*.price' => 'required|numeric|min:0',
        'products.*.variant_id' => 'nullable|integer',
        'payment_type' => 'required|string',
        'payment_status' => 'required|in:Paid,Unpaid',
        'delivery_price' => 'nullable|numeric|min:0',
        'tax_price' => 'nullable|numeric|min:0',
        'first_name' => 'required|string|max:255',
        'last_name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'telephone' => 'required|string|max:20',
        'address' => 'required|string',
        'city_id' => 'nullable|integer|exists:cities,id',
        'state_id' => 'nullable|integer|exists:states,id',
        'country_id' => 'nullable|integer|exists:countries,id',
        'postcode' => 'nullable|string|max:20',
        'additional_note' => 'nullable|string',
    ]);

    \DB::beginTransaction();

    try {
        $productJson = [];
        $totalProductPrice = 0;
        $userId = auth()->id();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            /*
             * ROLL BACK BEFORE RETURNING. These early returns sit inside the
             * `\DB::beginTransaction()` opened above and left it open: nothing
             * rolls it back, so the request ends holding a transaction (harmless
             * under FPM, where the connection closes, and a leaked lock on a
             * long-lived worker). Reported here because the store-ownership
             * predicate added in P01-COMMERCE-SEC-03 FOLLOW-UP makes the variant
             * refusal below REACHABLE where `find()` previously always resolved a
             * row -- the leak was pre-existing and unreachable on that path, and
             * closing it is part of making that refusal safe.
             */
            \DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: You do not have permission to create orders for this store.'
            ], 403);
        }

        /*
         * P2-04-10. AND THE PRINCIPAL MUST BE ALLOWED TO CREATE ORDERS, which nothing here asked.
         *
         * `resolveOwnedStore()` above is a TENANT control and it is sound. It was also the ONLY
         * control: measured by grepping this method's whole body (328 lines, two order-creating
         * calls), `isAbleTo`, `authorize`, `Gate::` and `MerchantPermissions` appeared ZERO times.
         * So a staff member with no order permission created a real order, with a real
         * `final_price` and `payment_status`, in their employer's store -- the same hole as the
         * web's `OrderController::store()`, which this slice closed in the same pass.
         *
         * ROLL BACK FIRST, for the reason the block above documents: these early returns sit
         * inside the transaction opened earlier and returning without rolling back leaves it open.
         *
         * `$store->id`, not `current_store`: this handler serves the store named by `?store_id=`,
         * which `resolveOwnedStore()` admits anywhere in the caller's tenant, so the grant must be
         * evaluated against the store whose rows are about to be written.
         */
        if (! MerchantPermissions::allowsAny(\Auth::user(), ['Manage Order'], $store->id)) {
            \DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => __('You do not have permission to perform this action.'),
            ], 403);
        }

        /*
         * AND THE CUSTOMER MUST BE THIS STORE'S. P01-COMMERCE-SEC-03 FOLLOW-UP, found by an
         * adversarial authz review of the commit that added the product predicate above.
         *
         * `'customer_id' => 'required|exists:customers,id,status,1'` proves the customer EXISTS
         * and nothing more. `exists:` is a raw query-builder rule, so it bypasses Eloquent
         * entirely -- and `Customer` is the one model on this platform whose StoreScope actually
         * filters (CLAUDE.md), which makes that bypass easy to miss. The id then went verbatim
         * into `Order::create` and both `OrderNote::create` calls, so a merchant could write an
         * order, under their own store, naming ANOTHER STORE'S CUSTOMER.
         *
         * Lower severity than the stock drain this handler also had -- the wallet row follows the
         * order's store rather than the customer, and the victim's own order list is store-scoped,
         * so it is cross-tenant data LINKAGE rather than disclosure or money. Closed anyway,
         * because it is three lines and the ownership question is the same one.
         *
         * `withoutGlobalScopes()` because an assertion about another tenant's row must not be
         * answered by whichever tenant happens to be resolved -- the false-reading trap CLAUDE.md
         * records. The store predicate is HARD here: `customers.store_id` is how the storefront
         * identifies its own shoppers, and a customer with no store is not a customer this
         * merchant may bill.
         */
        $customerBelongsToStore = \App\Models\Customer::withoutGlobalScopes()
            ->where('id', $validated['customer_id'])
            ->where('store_id', $store->id)
            ->exists();

        if (! $customerBelongsToStore) {
            \DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found for this store.'
            ], 404);
        }

        foreach ($validated['products'] as $item) {
            /*
             * THE PRODUCT MUST BELONG TO THE STORE THIS ORDER IS BEING WRITTEN FOR.
             * P01-COMMERCE-SEC-03 FOLLOW-UP, found by a completeness sweep of the order writers
             * after the storefront chain was closed.
             *
             * This was `Product::find($item['product_id'])`. `resolveOwnedStore()` above proves
             * the caller owns the STORE, and the validator proves the product id EXISTS -- but
             * `Product` carries no StoreScope and is not in config/tenancy.php's `scoped_models`
             * (CLAUDE.md), so `find()` reaches every tenant's catalogue and nothing tied the two
             * together. A merchant who owns store A could post their own `store_id` with another
             * merchant's `product_id` and the loop below decremented THAT merchant's
             * `products.product_stock`, or their variant's `stock`, while the order was written
             * under store A. The same cross-tenant stock drain as S19, on the prefix the shipped
             * admin app calls (`api/admin/...`).
             *
             * The sibling merchant-web writer OrderController::store already carries this
             * predicate; this API twin did not.
             */
            $product = \App\Models\Product::where('id', $item['product_id'])
                ->where('store_id', $store->id)
                ->first();

            if (!$product || $product->status != 1) {
                throw new \Exception("Product not found or inactive: ID {$item['product_id']}");
            }

            $qty = (int) $item['quantity'];
            $price = (float) $item['price'];
            $lineTotal = $price * $qty;
            $totalProductPrice += $lineTotal;

            if (!empty($item['variant_id']) && $item['variant_id'] > 0) {
                /*
                 * AND THE VARIANT MUST BELONG TO THAT PRODUCT. Scoping the product does not
                 * scope the variant: `ProductVariant` is scoped by nothing either, so
                 * `find()` reached any tenant's variant row and the decrement below landed on
                 * it. `product_id`, not `store_id`, because `product_variants.store_id` is
                 * nullable -- the product predicate above already settled the tenancy.
                 */
                $variant = \App\Models\ProductVariant::where('id', $item['variant_id'])
                    ->where('product_id', $product->id)
                    ->first();
                if (!$variant) {
                    \DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => "Product variant not found: ID {$item['variant_id']}"
                    ], 404);
                }

                if ($product->track_stock == 1) {
                    /*
                     * D-21. THE TEST AND THE DECREMENT ARE NOW ONE STATEMENT.
                     *
                     * This was `if ($variant->stock < $qty) { refuse } $variant->stock -= $qty;
                     * $variant->save();` — a check-then-act. Two concurrent orders could both
                     * read the same figure, both pass the test and both subtract, so the second
                     * write erased the first and the shop oversold without either request being
                     * refused. `$requireAvailability = true` puts the `stock >= qty` predicate
                     * into the UPDATE itself, so exactly one of them affects a row.
                     *
                     * THE RESPONSE IS DELIBERATELY UNCHANGED, byte for byte: same
                     * `status`/`message` keys, same wording, same HTTP 400, same rollback. This
                     * is `POST api/admin/createOrderViaApi`, the shipped Flutter admin app's own
                     * order writer, and the owner's condition is explicit that a business refusal
                     * must not change shape — above all it must never become a 401, which the
                     * shipped client maps to a forced logout. `$variant->stock` still reads the
                     * value seen before the attempt, which is what the old message reported too.
                     */
                    $ssVariantDecrement = $this->stockService()->decrementVariantStock($variant, $qty, false, true);

                    if (! $ssVariantDecrement['applied']) {
                        \DB::rollBack();

                        return response()->json([
                            'status' => 'error',
                            'message' => "Insufficient stock for variant: {$variant->name}. Available: {$variant->stock}, Requested: {$qty}"
                        ], 400);
                    }
                }

                $productJson[] = [
                    'product_id' => $item['product_id'],
                    'name' => $product->name ?? 'Product',
                    'price' => $price,
                    'quantity' => $qty,
                    'subtotal' => $lineTotal, 
                    'orignal_price' => $product->price ?? $price,
                    'variant_id' => $item['variant_id'],
                    'variant_name' => $item['variant_name'] ?? $variant->name ?? null,
                    'image' => $product->cover_image_url ?? '',
                    'return' => false,
                ];
            } else {
                if ($product->track_stock == 1) {
                    /* D-21. The no-variant leg, same change and same reasoning as above: the
                       availability test and the decrement become one atomic statement, and the
                       refusal keeps its exact shape and status for the Flutter client. */
                    $ssProductDecrement = $this->stockService()->decrementProductStock($product, $qty, false, true);

                    if (! $ssProductDecrement['applied']) {
                        \DB::rollBack();

                        return response()->json([
                            'status' => 'error',
                            'message' => "Insufficient stock for product: {$product->name}. Available: {$product->product_stock}, Requested: {$qty}"
                        ], 400);
                    }
                }

                $productJson[] = [
                    'product_id' => $item['product_id'],
                    'name' => $product->name ?? 'Product',
                    'price' => $price,
                    'quantity' => $qty,
                    'subtotal' => $lineTotal,
                    'orignal_price' => $product->price ?? $price,
                    'variant_id' => 0,
                    'variant_name' => null,
                    'image' => $product->cover_image_url ?? '',
                    'return' => false,
                ];
            }
        }

        $deliveryPrice = (float) ($validated['delivery_price'] ?? 0);
        $taxPrice = (float) ($validated['tax_price'] ?? 0);
        $finalPrice = $totalProductPrice + $deliveryPrice + $taxPrice;
        $orderNumber = 'ORD-' . strtoupper(uniqid()) . '-' . rand(1000, 9999);

        $order = \App\Models\Order::create([
            'product_order_id' => $orderNumber,
            'order_date' => now(),
            'customer_id' => $validated['customer_id'],
            'is_guest' => 0,
            'product_json' => json_encode($productJson),
            'product_id' => implode(',', array_column($validated['products'], 'product_id')),
            'product_price' => $totalProductPrice,
            'delivery_price' => $deliveryPrice,
            'tax_price' => $taxPrice,
            'coupon_price' => 0,
                'currency_code' => $currentCurrencyCode,
    'currency_symbol' => $currentCurrencySymbol,

            'final_price' => $finalPrice,
            'payment_type' => $validated['payment_type'],
            'payment_status' => $validated['payment_status'],
            'delivered_status' => 0,
            'return_status' => 0,
            'additional_note' => $validated['additional_note'] ?? '',
            'store_id' => $storeId, 
            // P2-04-11. Entered by a merchant in the admin app's order form -- the SAME source
            // as the web form, because the provenance recorded is the path that created the
            // order, not the device that reached it. A column that separated web from app would
            // answer a question nobody on this screen asks and would double the vocabulary.
            'order_source' => \App\Models\Order::SOURCE_MANUAL,
            'created_by' => $userId,
        ]);

        \App\Models\OrderBillingDetail::create([
            'order_id' => $order->id,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'telephone' => $validated['telephone'],
            'address' => $validated['address'],
            'city' => $validated['city_id'] ?? null,     
            
            'state' => $validated['state_id'] ?? null,  

            'country' => $validated['country_id'] ?? null,
            'postcode' => $validated['postcode'] ?? '',
        ]);

\App\Models\OrderNote::create([
    'customer_id' => $validated['customer_id'],
    'order_id' => $order->id,
    'status' => 'Order created',
    'note_type' => 'system',
    'notes' => 'Order created via Admin API',
    'store_id' => $storeId,
]);

if (!empty($validated['additional_note'])) {
    \App\Models\OrderNote::create([
        'customer_id' => $validated['customer_id'],
        'order_id' => $order->id,
        'status' => 'Customer note',
        'note_type' => 'customer',
        'notes' => $validated['additional_note'],
        'store_id' => $storeId,
    ]);
}
        \DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Order created successfully',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $orderNumber,
                'store_id' => $storeId,
'currency_symbol' => $currentCurrencySymbol,    
'products' => array_map(function($item) {
                    return [
                        'product_id' => $item['product_id'],
                        'name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'subtotal' => $item['subtotal'],
                    ];
                }, $productJson),
                'product_price' => $totalProductPrice,
                'delivery_price' => $deliveryPrice,
                'tax_price' => $taxPrice,
                'final_price' => $finalPrice,
                'payment_type' => $order->payment_type,
                'payment_status' => $order->payment_status,
            ]
        ], 201);

    } catch (\Exception $e) {
        \DB::rollBack();
        \Log::error('API Order Creation Error: ' . $e->getMessage());

        return response()->json([
            'status' => 'error',
            'message' => 'Failed to create order: ' . $e->getMessage()
        ], 500);
    }
}
public function getActivePaymentMethods(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');

        if (!$storeId) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Store ID is required.']
            ], 400);
        }

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $paymentSettingController = new \App\Http\Controllers\PaymentSettingController();
        $gatewaysConfig = $paymentSettingController->getPaymentGateways();

        $activeMethods = [];

        foreach ($gatewaysConfig as $gateway) {
            $id = $gateway['id'];
            $enableKey = "is_{$id}_enabled";

            $status = \App\Models\Utility::GetValueByName($enableKey, $storeId);

            if ($status === 'on' || $status === '1') {
                /*
                 * F150 -- THE LABEL, NOT THE KEY. `ucwords(str_replace('_', ' ', $id))` produced
                 * "Cod", "Sslcommerz", "Bank Transfer", "Paiementpro" -- a mechanical transform of
                 * an internal identifier, shown to a merchant in the admin app's order form, and
                 * identical in Arabic because nothing translated it.
                 *
                 * `key` IS UNTOUCHED, and that is what makes this safe for the shipped app. The
                 * Flutter client posts the key and displays the name, verified in its own source:
                 * `create_order_screen.dart:237` renders `m.name` in the dropdown and `:242` sets
                 * `orderController.paymentType.value = method?.key`. So the value that reaches
                 * `payment_type` on the created order does not change at all; only the words the
                 * merchant reads do. No API field is added, removed or renamed.
                 *
                 * A gateway with no label falls back to the old transform rather than vanishing
                 * from the picker: this list is what a merchant can CHOOSE, and silently dropping
                 * an enabled payment method would be a functional regression, where an ugly name
                 * is only a cosmetic one.
                 */
                $activeMethods[] = [
                    'key' => $id,
                    'name' => \App\Payments\PaymentTypeLabel::for($id)
                        ?? ucwords(str_replace('_', ' ', $id)),
                ];
            }
        }

        return response()->json([
            'status' => 1,
            'message' => 'Active payment methods retrieved successfully.',
            'data' => [
                'store_id' => (int) $storeId,
                'active_methods' => $activeMethods
            ]
        ], 200);

    } catch (\Exception $e) {
        \Log::error('API Get Active Payment Methods Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to retrieve payment methods. Please try again later.'
            ]
        ], 500);
    }
}


//عرض معلومات المتجر    الشاملة والحسابات
public function getStoreOwnerInfo(Request $request)
{
    try {
        $storeId = $request->query('store_id') ?? $request->store_id ?? (function_exists('getCurrentStore') ? getCurrentStore() : null);

        if (!$storeId) {
            return response()->json(['status' => 'error', 'message' => 'Store ID is required', 'data' => null], 400);
        }

        // NEW-ADMINAPI-UNOWNED-STORE-01, third site, and a read rather than a write.
        // This was `Store::find($storeId)` with no ownership predicate at all, while
        // its own sibling updateStoreOwnerInfo (:7522) has had one. Any valid admin
        // token could read ANY store's owner name, owner email, owner mobile, store
        // email, address, every row of `settings` for that store and its
        // `theme_customizes` footer links, by naming the store id.
        //
        // resolveOwnedStore() returns the store for a super admin and only an owned
        // store otherwise, so a non-existent store and an unowned one now give the
        // same 403 -- which also stops this endpoint from being a store-id oracle.
        $store = $this->resolveOwnedStore($storeId);
        if (!$store) {
            return response()->json(['status' => 'error', 'message' => 'Permission denied.', 'data' => null], 403);
        }

        $storeId = $store->id;

        $owner = \App\Models\User::find($store->created_by);
        
        $settings = \App\Models\Setting::where('store_id', $storeId)
            ->pluck('value', 'name')
            ->toArray();

        $storeUrl = $this->buildStoreUrlLikeWeb($store, $settings);

$socialMedia = $this->extractSocialMediaLinks($storeId, $store->theme_id);

        $data = [
            'store' => [
                'id' => (int) $store->id,
                'name' => $store->name ?? '',
                'slug' => $store->slug ?? '',
                'email' => $store->email ?? '',
                'default_language' => $settings['defult_language'] ?? $store->default_language ?? 'en',
                'store_url' => $storeUrl,
                'created_at' => $store->created_at?->toDateTimeString(),
            ],
            'owner' => [
                'id' => (int) ($owner?->id ?? 0),
                'name' => $owner?->name ?? '',
                'email' => $owner?->email ?? '',
                'mobile' => $settings['mobile'] ?? ($owner?->mobile ?? ''),
                'created_at' => $owner?->created_at?->toDateTimeString(),
            ],
            'address' => [
                'store_address' => $settings['store_address'] ?? '',
                'store_city' => $settings['store_city'] ?? '',
                'store_state' => $settings['store_state'] ?? '',
                'store_zipcode' => $settings['store_zipcode'] ?? '',
                'store_country' => $settings['store_country'] ?? '',
                'full_address' => trim(
                    ($settings['store_address'] ?? '') . ', ' . 
                    ($settings['store_city'] ?? '') . ', ' . 
                    ($settings['store_state'] ?? '') . ' ' . 
                    ($settings['store_zipcode'] ?? '') . ', ' . 
                    ($settings['store_country'] ?? '')
                , ', '),
            ],
            'media' => [
                'invoice_logo' => !empty($settings['invoice_logo']) ? asset($settings['invoice_logo']) : null,
                'store_image'  => !empty($settings['theme_image']) ? asset($settings['theme_image']) : null,
            ],
'social_media' => $socialMedia,



            'settings' => [
                'currency_name' => $settings['CURRENCY_NAME'] ?? '',
                'currency_format' => $settings['currency_format'] ?? '',
                'site_rtl' => $settings['SITE_RTL'] ?? 'off',
                'date_format' => $settings['site_date_format'] ?? 'Y-m-d',
                'timezone' => $settings['defult_timezone'] ?? 'UTC',
            ]
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'Store and owner information retrieved successfully',
            'data' => $data
        ], 200);

    } catch (\Exception $e) {
        \Log::error('Get Store Owner Info Error: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to retrieve store information: ' . $e->getMessage(),
            'data' => null
        ], 500);
    }
}

private function extractSocialMediaLinks($storeId, $themeId)
{
    $socialLinks = [];
    
    try {
        $footerRecord = \DB::table('theme_customizes')
            ->where('store_id', $storeId)
            ->where('theme_id', $themeId)
            ->where('name', 'footer_repeater')
            ->first();
        
        if ($footerRecord && !empty($footerRecord->value)) {
            $rawValue = $footerRecord->value;
            
            $decoded = json_decode($rawValue, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (isset($item['link']) && isset($item['icon'])) {
                        $platform = $this->detectSocialPlatform($item['icon'], $item['link']);
                        if ($platform) {
                            $socialLinks[$platform] = [
                                'link' => $item['link'],
                                'icon' => $item['icon'],
                            ];
                        }
                    }
                }
            } else {
                $socialLinks = $this->extractLinksAndGuessIcons($rawValue);
            }
        }
    } catch (\Exception $e) {
        \Log::error('Extract Social Media Error: ' . $e->getMessage());
    }

    return $socialLinks;
}

private function extractLinksAndGuessIcons($rawString)
{
    $links = [];
    
    preg_match_all('/"link"\s*:\s*"([^"]+)"/', $rawString, $linkMatches);

    if (!empty($linkMatches[1])) {
        foreach ($linkMatches[1] as $link) {
            $cleanLink = stripslashes($link);
            
            $platform = $this->detectSocialPlatform('', $cleanLink);
            
            if ($platform) {
                $links[$platform] = [
                    'link' => $cleanLink,
                    'icon' => $this->getDefaultIcon($platform) 
                ];
            }
        }
    }
    
    return $links;
}

private function getDefaultIcon($platform)
{
    $icons = [
        'facebook'   => 'fab fa-facebook-f',
        'twitter'    => 'fab fa-x-twitter',
        'instagram'  => 'fab fa-instagram',
        'linkedin'   => 'fab fa-linkedin-in',
        'youtube'    => 'fab fa-youtube',
        'tiktok'     => 'fab fa-tiktok',
        'whatsapp'   => 'fab fa-whatsapp',
        'telegram'   => 'fab fa-telegram',
        'snapchat'   => 'fab fa-snapchat',
    ];
    
    return $icons[$platform] ?? 'fas fa-link';
}

private function detectSocialPlatform($icon, $link)
{
    $icon = strtolower($icon);
    $link = strtolower($link);

    if (strpos($icon, 'facebook') !== false || strpos($link, 'facebook.com') !== false) return 'facebook';
    if (strpos($icon, 'twitter') !== false || strpos($icon, 'x-twitter') !== false || strpos($link, 'twitter.com') !== false || strpos($link, 'x.com') !== false) return 'twitter';
    if (strpos($icon, 'instagram') !== false || strpos($link, 'instagram.com') !== false) return 'instagram';
    if (strpos($icon, 'linkedin') !== false || strpos($link, 'linkedin.com') !== false) return 'linkedin';
    if (strpos($icon, 'youtube') !== false || strpos($link, 'youtube.com') !== false) return 'youtube';
    if (strpos($icon, 'tiktok') !== false || strpos($link, 'tiktok.com') !== false) return 'tiktok';
    if (strpos($icon, 'whatsapp') !== false || strpos($link, 'wa.me') !== false || strpos($link, 'whatsapp.com') !== false) return 'whatsapp';
    if (strpos($icon, 'telegram') !== false || strpos($link, 't.me') !== false) return 'telegram';
    if (strpos($icon, 'snapchat') !== false || strpos($link, 'snapchat.com') !== false) return 'snapchat';

    return null;
}
private function buildStoreUrlLikeWeb($store, $settings)
{
    // ✅ إضافة جديدة: إذا كان هناك رابط مخصص مباشر، استخدمه فوراً وتجاهل الباقي
    if (!empty($settings['custom_store_url'])) {
        return rtrim($settings['custom_store_url'], '/');
    }

    $enableDomain = $settings['enable_domain'] ?? 'off';
    $enableSubdomain = $settings['enable_subdomain'] ?? 'off';
    $customDomain = $settings['domains'] ?? '';
    $subdomain = $settings['subdomain'] ?? '';

    if ($enableDomain === 'on' && !empty($customDomain)) {
        $domain = preg_replace('/^www\./', '', $customDomain);
        return (strpos($domain, 'http') === 0 ? '' : 'https://') . rtrim($domain, '/') . '/home';
    }

    if ($enableSubdomain === 'on' && !empty($subdomain)) {
        $sub = preg_replace('/^www\./', '', $subdomain);
        return (strpos($sub, 'http') === 0 ? '' : 'https://') . rtrim($sub, '/') . '/home';
    }

    $appUrl = trim(env('APP_URL'), '/');
    $input = preg_replace('#^http(s)?://#', '', $appUrl);
    $input = preg_replace('/^www\./', '', $input);
    if (!preg_match('#^http(s)?://#', $input)) {
        $input = 'https://' . $input;
    }

    return rtrim($input, '/') . '/' . $store->slug . '/home';
}
    /// البحث حسب اسم المنتج فقط
    public function searchProductsByName(Request $request)
{
    $adminUser = auth()->user();

    if (!$adminUser) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => ['message' => 'Unauthorized. Valid token is required.']
        ], 401);
    }

    $storeId = $request->query('store_id');

    \Validator::make(['store_id' => $storeId], [
        'store_id' => 'required|integer|exists:stores,id'
    ])->validate();

    $store = $this->resolveOwnedStore($storeId);

    if (!$store) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => ['message' => 'You do not have permission to access this store.']
        ], 403);
    }

    $rules = [
        'name' => 'required|string|min:2',
        
    ];

    $validator = \Validator::make($request->all(), $rules);
    if ($validator->fails()) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => ['message' => $validator->errors()->first()]
        ], 422);
    }

    $products = Product::where('store_id', $store->id)
        ->where('name', 'LIKE', '%' . $request->name . '%')
        ->with(['ProductData', 'brand', 'label'])
       
        ->paginate(15);

    if ($products->isNotEmpty()) {
        return response()->json([
            'status' => 1,
            'message' => 'success',
            'data' => $products
           
        ], 200);
    } else {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => ['message' => __('Product not found.')]
        ], 404);
    }
}


// Top Orders
public function Top_orders(Request $request)
{
    try {
        $adminUser = auth()->user();

        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }
        // The five largest orders BY `final_price`. This is the endpoint that made gating the
        // dashboard alone pointless: it served the same money to the same token, one route over.
        if ($refusal = $this->refuseWithoutPermission(['Manage Order', 'Show Order'], $store->id)) {
            return $refusal;
        }

        // ✅ 1. جلب كود العملة الحالية الفعلي من الإعدادات (مثال: 'USD', 'SYP')
        $currencyCode = \App\Models\Utility::GetValueByName('defult_currancy', $storeId) ?? 'USD';

        // ✅ 2. جلب رمز العملة المقابل لهذا الكود من جدول currency_rates النشط
        $currencyRate = \App\Models\CurrencyRate::where('store_id', $storeId)
            ->where('currency_code', $currencyCode)
            ->where('is_active', 1)
            ->first();

        // ✅ 3. تحديد الرمز: من الجدول، أو الرمز الافتراضي للدولار، أو كود العملة نفسه كحل أخير
        $currencySymbol = $currencyRate ? $currencyRate->symbol : ($currencyCode === 'USD' ? '$' : $currencyCode);

        $formatNumber = function ($value) {
            return number_format((float) ($value ?? 0), 2, '.', '');
        };

        $topOrders = \App\Models\Order::where('store_id', $store->id)
            ->with(['CustomerData' => function ($query) {
                $query->select('id', 'first_name', 'last_name');
            }])
            ->select('id', 'product_order_id', 'customer_id', 'order_date', 'final_price', 'payment_status', 'delivered_status')
            ->orderBy('final_price', 'desc')
            ->limit(5)
            ->get();

        $ordersData = $topOrders->map(function ($order) use ($formatNumber) {
            $customer = $order->CustomerData;
            $customerName = $customer
                ? trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
                : 'Guest';

            return [
                'id' => $order->id,
                'order_number' => '#' . $order->product_order_id,
                'customer_id' => $order->customer_id,
                'customer_name' => $customerName,
                'order_date' => $order->order_date ? \Carbon\Carbon::parse($order->order_date)->format('Y-m-d') : null,
                
                'final_price' => $formatNumber($order->final_price),
                
                'payment_status' => $order->payment_status,
                'delivered_status' => $order->delivered_status,
                'delivered_status_text' => $order->delivered_status_string ?? 'Pending',
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Top 5 highest value orders retrieved successfully.',
            'data' => [
                'store_id' => (int) $storeId,
                'store_name' => $store->name ?? '',
                'top_orders' => $ordersData,
                'count' => $ordersData->count(),
                'currency_code' => $currencyCode,      // ✅ إضافة مفيدة: إرجاع كود العملة أيضاً
                'currency_symbol' => $currencySymbol,  // ✅ الرمز الصحيح والديناميكي
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Top Orders Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to retrieve top orders: ' . $e->getMessage()
            ]
        ], 500);
    }
}//اضافة  متجر  جديد
    public function createStoreApi(Request $request)
    {

    $user = auth()->user();
        if (!$user || !in_array($user->type, ['admin', 'super admin'])) {
            return $this->error(['message' => __('Permission denied.')], 403);
        }

        $validator = \Validator::make($request->all(), [
            'storename' => 'required|string|max:120',
        ]);

        if ($validator->fails()) {
            return $this->error(['message' => $validator->errors()->first()], 422);
        }

        if ($user->type == 'admin') {
            $total_store = $user->countStore();
            $plan = Plan::find($user->plan_id);

            if ($total_store < $plan->max_stores || $plan->max_stores == -1) {
                
                $slug = User::slugs($request->storename);

                $store = Store::create([
                    'name'             => $request->storename,
                    'email'            => $user->email,
                    'theme_id'         => $request->theme_id ?? 'stylique',
                    'slug'             => $slug,
                    'default_language' => $user->default_language ?? 'en',
                    'created_by'       => $user->id,
                ]);

                if (function_exists('defaultSetting')) {
                    defaultSetting($store->id, 'admin', $user);
                }
                if (function_exists('pageDefaultData')) {
                    pageDefaultData($store->id);
                }

                return $this->success([
                    'store_id'     => $store->id,
                    'store_name'   => $store->name,
                    'slug'         => $store->slug,
                    'theme_id'     => $store->theme_id,
                ], __('Store successfully created.'));

            } else {
                return $this->error(['message' => __('Your Store limit is over, Please upgrade plan')], 403);
            }
        }
        return $this->error(['message' => __('Something went wrong.')], 500);
    }
    //عرض  الخطط

public function indexAllPlan(Request $request)
{
    try {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');
        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $settings = Utility::Setting($storeId);

        $currentPlan = Plan::where('id', $user->plan_id)
            ->where('is_disable', 1)
            ->first();

        $usedProducts = \App\Models\Product::where('created_by', $user->id)->count();
        $usedStores = \App\Models\Store::where('created_by', $user->id)->count();
        $usedUsers = \App\Models\User::where('created_by', $user->id)
            ->where('type', '!=', 'super admin')
            ->count();

        $maxProducts = $currentPlan ? (int) $currentPlan->max_products : 0;
        $maxStores = $currentPlan ? (int) $currentPlan->max_stores : 0;
        $maxUsers = $currentPlan ? (int) $currentPlan->max_users : 0;

        $remainingProducts = $maxProducts == -1 ? -1 : max(0, $maxProducts - $usedProducts);
        $remainingStores = $maxStores == -1 ? -1 : max(0, $maxStores - $usedStores);
        $remainingUsers = $maxUsers == -1 ? -1 : max(0, $maxUsers - $usedUsers);

        $planExpireDate = $user->plan_expire_date;
        $isExpired = false;
        $daysRemaining = null;
        $planStatus = 'active';

        if ($planExpireDate) {
            $today = now()->startOfDay();
            $expireDate = \Carbon\Carbon::parse($planExpireDate)->startOfDay();
            $daysRemaining = $today->diffInDays($expireDate, false);

            if ($today->greaterThan($expireDate)) {
                $isExpired = true;
                $planStatus = 'expired';
                $daysRemaining = 0;
            } elseif ($daysRemaining <= 7) {
                $planStatus = 'expiring_soon';
            }
        } else {
            $planStatus = 'unlimited';
        }

        $planData = null;
        if ($currentPlan) {
            $planData = [
                'id' => $currentPlan->id,
                'name' => $currentPlan->name,
                'price' => (float) $currentPlan->price,
                'currency' => $settings['CURRENCY'] ?? '$',
                'currency_name' => $settings['CURRENCY_NAME'] ?? 'USD',
                'duration' => $currentPlan->duration,
                'duration_text' => Plan::getDurationsArr()[$currentPlan->duration] ?? 'Month',
                'price_display' => ($settings['CURRENCY'] ?? '$') . $currentPlan->price . ' / ' . (Plan::getDurationsArr()[$currentPlan->duration] ?? 'Month'),

                'limits' => [
                    'products' => [
                        'max' => $maxProducts == -1 ? 'Unlimited' : $maxProducts,
                        'used' => $usedProducts,
                        'remaining' => $remainingProducts == -1 ? 'Unlimited' : $remainingProducts,
                        'percentage' => $maxProducts == -1 ? 0 : round(($usedProducts / $maxProducts) * 100, 2),
                    ],
                    'stores' => [
                        'max' => $maxStores == -1 ? 'Unlimited' : $maxStores,
                        'used' => $usedStores,
                        'remaining' => $remainingStores == -1 ? 'Unlimited' : $remainingStores,
                        'percentage' => $maxStores == -1 ? 0 : round(($usedStores / $maxStores) * 100, 2),
                    ],
                    'users' => [
                        'max' => $maxUsers == -1 ? 'Unlimited' : $maxUsers,
                        'used' => $usedUsers,
                        'remaining' => $remainingUsers == -1 ? 'Unlimited' : $remainingUsers,
                        'percentage' => $maxUsers == -1 ? 0 : round(($usedUsers / $maxUsers) * 100, 2),
                    ],
                ],

                'features' => [
                    'custom_domain' => $currentPlan->enable_domain == 'on',
                    'sub_domain' => $currentPlan->enable_subdomain == 'on',
                    'chatgpt' => $currentPlan->enable_chatgpt == 'on',
                    'pwa' => $currentPlan->pwa_store == 'on',
                    'shipping_method' => $currentPlan->shipping_method == 'on',
                    'enable_tax' => $currentPlan->enable_tax == 'on',
                    'storage_limit' => (float) $currentPlan->storage_limit . ' MB',
                ],

                'has_trial' => $currentPlan->trial == 1,
                'trial_days' => (int) ($currentPlan->trial_days ?? 0),
                'description' => strip_tags($currentPlan->description),

                'plan_status' => [
                    'status' => $planStatus,
                    'is_expired' => $isExpired,
                    'plan_expire_date' => $planExpireDate ? $user->dateFormat($planExpireDate) : null,
                    'days_remaining' => $daysRemaining,
                    'expire_message' => $isExpired
                        ? 'Your plan has expired on ' . ($planExpireDate ? $user->dateFormat($planExpireDate) : '')
                        : ($daysRemaining !== null && $daysRemaining > 0
                            ? $daysRemaining . ' days remaining'
                            : ($planStatus === 'unlimited' ? 'Unlimited plan' : '')),
                ],

                'user_subscription' => [
                    'is_current_plan' => true,
                    'plan_expire_date' => $planExpireDate ? $user->dateFormat($planExpireDate) : null,
                    'trial_expire_date' => $user->trial_expire_date ? $user->dateFormat($user->trial_expire_date) : null,
                    'is_trial_plan' => $user->trial_plan == $currentPlan->id,
                    'has_requested_plan' => $user->requested_plan == $currentPlan->id,
                ],
            ];
        }

        return $this->success([
            'store_id' => (int) $storeId,
            'current_plan' => $planData,
            'current_user_plan' => $user ? [
                'plan_id' => $user->plan_id,
                'trial_plan' => $user->trial_plan,
                'requested_plan' => $user->requested_plan,
            ] : null,
        ], __('Current plan retrieved successfully.'));

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('Plans API Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return $this->error(['message' => __('Failed to retrieve plans: ' . $e->getMessage())], 500);
    }
}


  //عرض معدلات صرف العملات للمتجر
public function getCurrencyRatesApi(Request $request)
{
    try {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $currencyRates = \App\Models\CurrencyRate::where('store_id', $storeId)
            ->orderBy('currency_code')
            ->get();

        if ($currencyRates->isEmpty()) {
            $defaultCurrencies = [
                ['USD', 'US Dollar', '$', 1],
                ['EUR', 'Euro', '€', 1],
                ['SYP', 'Syrian Pound', 'ل.س', 1],
                ['LBP', 'Lebanese Pound', 'ل.ل', 1],
                ['IQD', 'Iraqi Dinar', 'د.ع', 1],
                ['EGP', 'Egyptian Pound', 'ج.م', 1],
                ['JOD', 'Jordanian Dinar', 'د.أ', 1],
                ['SAR', 'Saudi Riyal', 'ر.س', 1],
                ['AED', 'UAE Dirham', 'د.إ', 1],
                ['KWD', 'Kuwaiti Dinar', 'د.ك', 1],
                ['QAR', 'Qatari Riyal', 'ر.ق', 1],
                ['BHD', 'Bahraini Dinar', 'د.ب', 1],
                ['OMR', 'Omani Rial', 'ر.ع', 1],
            ];

            foreach ($defaultCurrencies as $currency) {
                \App\Models\CurrencyRate::create([
                    'store_id' => $storeId,
                    'currency_code' => $currency[0],
                    'currency_name' => $currency[1],
                    'symbol' => $currency[2],
                    'rate_per_usd' => $currency[3],
                    'is_active' => 1,
                    'created_by' => $user->id,
                ]);
            }

            $currencyRates = \App\Models\CurrencyRate::where('store_id', $storeId)
                ->orderBy('currency_code')
                ->get();
        }

        $ratesData = $currencyRates->map(function ($rate) {
            return [
                'id' => $rate->id,
                'currency_code' => $rate->currency_code,
                'currency_name' => $rate->currency_name,
                'symbol' => $rate->symbol,
                'rate_per_usd' => (float) $rate->rate_per_usd,
                'is_active' => (bool) $rate->is_active,
            ];
        });

        $defaultCurrency = \App\Models\Setting::where('store_id', $storeId)
            ->where('name', 'defult_currancy')
            ->value('value') ?? 'USD';

        return response()->json([
            'status' => 1,
            'message' => 'Currency rates retrieved successfully.',
            'data' => [
                'store_id' => (int) $storeId,
                'store_name' => $store->name ?? '',
                'default_currency' => $defaultCurrency,
                'currency_rates' => $ratesData,
                'count' => $ratesData->count(),
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Get Currency Rates Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to retrieve currency rates: ' . $e->getMessage()
            ]
        ], 500);
    }
    
}
    //تعديل  معدلات صرف العملة
public function updateCurrencyRateApi(Request $request)
{
    try {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $validated = $request->validate([
            'store_id' => 'required|integer|exists:stores,id',
            'currency_code' => 'required|string|max:10',
            'rate_per_usd' => 'required|numeric|min:0.00000001',
        ]);

        $store = $this->resolveOwnedStore($validated['store_id']);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to update this store settings.']
            ], 403);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $currencyRate = \App\Models\CurrencyRate::where('store_id', $validated['store_id'])
            ->where('currency_code', $validated['currency_code'])
            ->first();

        if (!$currencyRate) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Currency not found for this store.']
            ], 404);
        }

        $currencyRate->rate_per_usd = $validated['rate_per_usd'];
        $currencyRate->save();

        return response()->json([
            'status' => 1,
            'message' => 'Currency rate updated successfully.',
            'data' => [
                'currency_code' => $currencyRate->currency_code,
                'currency_name' => $currencyRate->currency_name,
                'symbol' => $currencyRate->symbol,
                'rate_per_usd' => (float) $currencyRate->rate_per_usd,
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Update Currency Rate Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to update currency rate: ' . $e->getMessage()
            ]
        ], 500);
    }
}
 //لتعديل  او ايقاف عملة
public function toggleCurrencyStatusApi(Request $request)
{
    try {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $validated = $request->validate([
            'store_id' => 'required|integer|exists:stores,id',
            'currency_code' => 'required|string|max:10',
            'is_active' => 'required|boolean',
        ]);

        $store = $this->resolveOwnedStore($validated['store_id']);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to update this store settings.']
            ], 403);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $currencyRate = \App\Models\CurrencyRate::where('store_id', $validated['store_id'])
            ->where('currency_code', $validated['currency_code'])
            ->first();

        if (!$currencyRate) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Currency not found for this store.']
            ], 404);
        }

        if ($validated['currency_code'] === 'USD' && !$validated['is_active']) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'USD cannot be disabled as it is the base currency.']
            ], 400);
        }

        $currencyRate->is_active = $validated['is_active'] ? 1 : 0;
        $currencyRate->save();

        return response()->json([
            'status' => 1,
            'message' => 'Currency status updated successfully.',
            'data' => [
                'currency_code' => $currencyRate->currency_code,
                'currency_name' => $currencyRate->currency_name,
                'is_active' => (bool) $currencyRate->is_active,
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Toggle Currency Status Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to update currency status: ' . $e->getMessage()
            ]
        ], 500);
    }
}
public function updateStoreLanguage(Request $request)
{
    try {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');
        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $validator = \Validator::make($request->all(), [
            'default_language' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Validation failed.', 'errors' => $validator->errors()]
            ], 422);
        }

        $languages = Utility::languages();

        $languageCodeToSave = null;


        if (array_key_exists($request->default_language, $languages)) {
            $languageCodeToSave = $request->default_language;
        }

        else if (in_array($request->default_language, $languages)) {

        $languageCodeToSave = array_search($request->default_language, $languages);
        }

        if (!$languageCodeToSave) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'This language is not supported.']
            ], 400);
        }

        $store->default_language = $languageCodeToSave;
        $store->save();

        return response()->json([
            'status' => 1,
            'message' => 'Store language updated successfully.',
            'data' => [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'saved_language_code' => $languageCodeToSave,

                'saved_language_name' => $languages[$languageCodeToSave]

                ]
        ], 200);

    } catch (\Exception $e) {
        \Log::error('Update Store Language Error: ' . $e->getMessage());
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => ['message' => 'Failed to update store language: ' . $e->getMessage()]
        ], 500);
    }
}

public function getAvailableLanguages(Request $request)
{
    try {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $languages = \App\Models\Utility::languages();
        $flags = \App\Models\Utility::flagOfCountry();

        $langData = [];
        foreach ($languages as $code => $name) {
            $langData[] = [
                'code' => $code,
                'name' => $name,
                'flag' => $flags[$code] ?? '',
            ];
        }

        $currentLanguage = $store->default_language ?? ($user->language ?? 'en');

        return response()->json([
            'status' => 1,
            'message' => 'Languages retrieved successfully',
            'data' => [
                'store_id' => (int) $storeId,
                'store_name' => $store->name ?? '',
                'languages' => $langData,
                'current_language' => $currentLanguage,
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Get Available Languages Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to retrieve languages: ' . $e->getMessage()
            ]
        ], 500);
    }
}
public function updateCurrencyApi(Request $request)
{
    try {
        $adminUser = auth()->user();

        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id') ?? $request->input('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to update this store settings.']
            ], 403);
        }

        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
        }

        $validated = $request->validate([
            'defult_currancy' => 'required|string|max:10',     
            'defult_currancy_symbol' => 'required|string|max:10', 

            'site_currency_symbol_position' => 'nullable|in:pre,post', 

            ]);


            $currencyName = strtoupper(trim($validated['defult_currancy']));
        $currencySymbol = trim($validated['defult_currancy_symbol']);
        $position = $validated['site_currency_symbol_position'] ?? 'pre';

        $settingsToUpdate = [
            'defult_currancy' => $currencyName,
            'CURRENCY_NAME' => $currencyName,
            'defult_currancy_symbol' => $currencySymbol,
            'CURRENCY' => $currencySymbol,
            'site_currency_symbol_position' => $position,
        ];


        $settingQuery = \App\Models\Setting::query();
        foreach ($settingsToUpdate as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'store_id' => $storeId
                ],
                [
                    'value' => $data,
                    'name' => $key,
                    'store_id' => $storeId,
                    'created_by' => $adminUser->id,
                ]
            );
        }


        return response()->json([
            'status' => 1,
            'message' => 'Currency updated successfully.',
            'data' => [
                'store_id' => (int) $storeId,
                'currency_name' => $currencyName,
                'currency_symbol' => $currencySymbol,
                'symbol_position' => $position,
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Update Currency Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to update currency: ' . $e->getMessage()
            ]
        ], 500);
    }
}
public function getCurrenciesApi(Request $request)
{
    try {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');
        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $allowedCurrencies = [
            'SYP', 'AED', 'BHD', 'BYR', 'EGP', 'EUR', 'IQD', 
            'JOD', 'KWD', 'LBP', 'OMR', 'QAR', 'SAR', 'USD'
        ];

        $activeCurrencies = \App\Models\CurrencyRate::where('store_id', $storeId)
            ->where('is_active', 1)
            ->whereIn('currency_code', $allowedCurrencies)
            ->get();

        $formattedCurrencies = [];
        if ($activeCurrencies->isNotEmpty()) {
            foreach ($activeCurrencies as $currency) {
                $formattedCurrencies[] = [
                    'code' => $currency->currency_code,
                    'name' => $currency->currency_name ?? $currency->currency_code,
                    'symbol' => $currency->symbol ?? '$',                 
                    'rate_per_usd' => (float) $currency->rate_per_usd,
                    'is_active' => (bool) $currency->is_active,
                ];
            }
        }

        $currentCurrencyCode = \App\Models\Utility::GetValueByName('defult_currancy', $storeId) ?? 'USD';

        $currentCurrencyRate = \App\Models\CurrencyRate::where('store_id', $storeId)
            ->where('currency_code', $currentCurrencyCode)
            ->first();

        $finalName = $currentCurrencyRate ? $currentCurrencyRate->currency_name : (\App\Models\Utility::GetValueByName('CURRENCY_NAME', $storeId) ?? 'US Dollar');
        $finalSymbol = $currentCurrencyRate ? $currentCurrencyRate->symbol : (\App\Models\Utility::GetValueByName('CURRENCY', $storeId) ?? '$');

        return response()->json([
            'status' => 1,
            'message' => 'Currencies retrieved successfully',
            'data' => [
                'store_id' => (int) $storeId,
                'current_currency' => [
                    'code' => $currentCurrencyCode,
                    'name' => $finalName,
                    'symbol' => $finalSymbol,
                ],
                'currencies' => $formattedCurrencies
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);
    } catch (\Exception $e) {
        \Log::error('API Get Currencies Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to retrieve currencies: ' . $e->getMessage()
            ]
        ], 500);
    }
}
//عرض اللغة والعملة الحالية
public function getCurrentLanguageAndCurrency(Request $request)
{
    try {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => [
                    'message' => 'Unauthorized. Valid token is required.'
                ]
            ], 401);
        }

        $storeId = $request->query('store_id');

        \Validator::make(
            ['store_id' => $storeId],
            ['store_id' => 'required|integer|exists:stores,id']
        )->validate();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => [
                    'message' => 'You do not have permission to access this store.'
                ]
            ], 403);
        }

        // ✅ 1. جلب اللغة الحالية (نفس المنطق السابق - يعمل بشكل صحيح)
$currentLanguage = $settings['defult_language'] ?? ($store->default_language ?? 'en');        $languages = \App\Models\Utility::languages();
        $currentLanguageName = $languages[$currentLanguage] ?? 'English';
        $flags = \App\Models\Utility::flagOfCountry();
        $currentLanguageFlag = $flags[$currentLanguage] ?? '';

        // ✅ 2. جلب كود العملة الحالية من settings (مصدر الحقيقة الوحيد للكود)
        $settings = \App\Models\Setting::where('store_id', $storeId)
            ->pluck('value', 'name')
            ->toArray();

        $defaultCurrencyCode = $settings['defult_currancy'] ?? 'USD';

        // ✅ 3. جلب جميع معلومات العملة من جدول currency_rates (مصدر واحد للحقيقة)
        $currencyRate = \App\Models\CurrencyRate::where('store_id', $storeId)
            ->where('currency_code', $defaultCurrencyCode)
            ->first();

        // ✅ 4. بناء بيانات العملة من جدول currency_rates
        $currencyData = [
            'code' => $defaultCurrencyCode,
            'symbol' => $currencyRate ? $currencyRate->symbol : '$',
            'name' => $currencyRate ? $currencyRate->currency_name : 'US Dollar',
            'rate_per_usd' => $currencyRate ? (float) $currencyRate->rate_per_usd : 1.00,
            'is_active' => $currencyRate ? (bool) $currencyRate->is_active : true,
        ];

        return response()->json([
            'status' => 1,
            'message' => 'Current language and currency retrieved successfully.',
            'data' => [
                'store_id' => (int) $storeId,
                'store_name' => $store->name ?? '',
                
                'current_language' => [
                    'code' => $currentLanguage,
                    'name' => $currentLanguageName,
                    'flag' => $currentLanguageFlag,
                ],

                'current_currency' => $currencyData,

                'store_settings' => [
                    'timezone' => $settings['defult_timezone'] ?? 'UTC',
                    'date_format' => $settings['site_date_format'] ?? 'Y-m-d',
                    'currency_format' => $settings['currency_format'] ?? '1',
                    'rtl' => ($settings['SITE_RTL'] ?? 'off') === 'on',
                ],
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API GetCurrentLanguageAndCurrency Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to retrieve current language and currency: ' . $e->getMessage()
            ]
        ], 500);
    }
}
public function getCustomerOrdersApi(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $id = $request->query('id');
        \Validator::make(['id' => $id], ['id' => 'required|integer|exists:customers,id'])->validate();

        $storeId = $request->query('store_id');
        \Validator::make(['store_id' => $storeId], ['store_id' => 'required|integer|exists:stores,id'])->validate();

        $store = $this->resolveOwnedStore($storeId);
        if (!$store) {
            return response()->json([
                'status' => 0, 'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $customer = \App\Models\Customer::where('id', $id)->where('store_id', $storeId)->first();
        if (!$customer) {
            return response()->json([
                'status' => 0, 'message' => 'fail',
                'data' => ['message' => 'Customer not found or does not belong to your store.']
            ], 404);
        }

        $defaultAddress = \App\Models\DeliveryAddress::where('customer_id', $customer->id)
            ->where('default_address', 1)
            ->select('id', 'full_name', 'phone', 'title', 'address', 'country_id', 'state_id', 'city_id', 'postcode', 'delivery_instructions', 'type')
            ->first();

        $addressData = null;
        if ($defaultAddress) {
            $countryName = \App\Models\Country::where('id', $defaultAddress->country_id)->value('name') ?? null;
            $stateName = \App\Models\State::where('id', $defaultAddress->state_id)->value('name') ?? null;
            $cityName = \App\Models\City::where('id', $defaultAddress->city_id)->value('name') ?? null;

            $addressData = [
                'id' => (int) $defaultAddress->id,
                'full_name' => $defaultAddress->full_name,
                'phone' => $defaultAddress->phone,
                'title' => $defaultAddress->title,
                'address' => $defaultAddress->address,
                
                'country_id' => (int) $defaultAddress->country_id,
                'state_id' => (int) $defaultAddress->state_id,
                'city_id' => (int) $defaultAddress->city_id,
                
                'country_name' => $countryName,
                'state_name' => $stateName,
                'city_name' => $cityName,
                
                'postcode' => $defaultAddress->postcode,
                'delivery_instructions' => $defaultAddress->delivery_instructions,
                'type' => (int) $defaultAddress->type,
            ];
        }

        $ordersCount = \App\Models\Order::where('customer_id', $customer->id)
            ->where('store_id', $storeId)
            ->count();

        $totalSpend = \App\Models\Order::where('customer_id', $customer->id)
            ->where('store_id', $storeId)
            ->sum('final_price');

        $aov = $ordersCount > 0 ? ($totalSpend / $ordersCount) : 0;

        $ordersQuery = \App\Models\Order::where('customer_id', $id);
        if ($ordersQuery->count() == 0) {
            $orders_detail = \App\Models\OrderBillingDetail::where('email', $customer->email)->get()->toArray();
            $order_id = [];
            foreach ($orders_detail as $orders) {
                $order_id[] = $orders['order_id'];
            }
            $ordersQuery = \App\Models\Order::whereIn('id', $order_id)->where('customer_id', 0);
        }

        $perPage = $request->query('per_page', 15);
        $page = $request->query('page', 1);

        $orders = $ordersQuery->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        $ordersData = $orders->getCollection()->map(function ($order) use ($store) {
            $detail = \App\Models\Order::order_detail($order->id);

            if (isset($detail['message'])) {
                return null;
            }

            $notes = \App\Models\OrderNote::query()
                ->where('order_id', $order->id)
                ->where('store_id', $store->id)
                ->latest('id')
                ->get()
                ->map(function ($note) {
                    return [
                        'id' => (int) $note->id,
                        'order_id' => (int) $note->order_id,
                        'customer_id' => (int) $note->customer_id,
                        'note' => $note->notes ?? $note->note ?? '',
                        'note_type' => $note->note_type,
                        'status' => $note->status,
                        'created_at' => $note->created_at?->toISOString(),
                        'updated_at' => $note->updated_at?->toISOString(),
                    ];
                });

            $detail['notes'] = $notes;
            $detail['id'] = (int) $order->id;
            $detail['is_guest'] = (int) ($order->is_guest ?? 0);
            $detail['order_id'] = $order->product_order_id ?? ('#ORD-' . str_pad($order->id, 4, '0', STR_PAD_LEFT));
            $detail['delivery_date'] = $order->delivery_date ?? null;
            $detail['order_reward_point'] = (int) ($order->reward_points ?? 0);
            $detail['return_price'] = (float) ($order->return_price ?? 0);
            $detail['return_status_message'] = $order->return_status_message ?? null;
            $detail['return_date'] = $order->return_date ?? null;
            $detail['payment_comment'] = $order->payment_comment ?? null;
            $detail['additional_note'] = $order->additional_note ?? null;

            $detail['order_status'] = (int) ($order->delivered_status ?? ($order->order_status ?? 0));
            $detail['order_status_text'] = $detail['order_status_text'] ?? 'Pending';
            $detail['order_status_message'] = $detail['order_status_message'] ?? '';
            $detail['payment_status'] = $order->payment_status ?? ($detail['payment_status'] ?? 'Unpaid');
            $detail['payment_receipt'] = $detail['payment_receipt'] ?? null;
            $detail['deliveryboy_id'] = $detail['deliveryboy_id'] ?? ($order->delivery_man_id ?? 0);
            $detail['delivery_id'] = $detail['delivery_id'] ?? ($order->delivery_id ?? 0);
            $detail['shipping_method'] = $detail['shipping_method'] ?? null;
            $detail['coupon_price'] = (float) ($detail['coupon_price'] ?? ($order->coupon_price ?? 0));

            return $detail;
        })->filter()->values();

        return response()->json([
            'status' => 1,
            'message' => 'Customer orders retrieved successfully',
            'data' => [
                'customer' => [
                    'id' => (int) $customer->id,
                    'name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                    'email' => $customer->email,
                    'mobile' => $customer->mobile ?? null,
                    'default_address' => $addressData,

                    'orders' => (int) $ordersCount,
                    'total_spend' => currency_format_with_sym((float) $totalSpend, $storeId) ?? SetNumberFormat((float) $totalSpend),
                    'aov' => currency_format_with_sym((float) $aov, $storeId) ?? SetNumberFormat((float) $aov),
                ],
                'orders' => $ordersData->toArray(),
                'pagination' => [
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'has_more' => $orders->hasMorePages(),
                    'next_page_url' => $orders->nextPageUrl(),
                    'prev_page_url' => $orders->previousPageUrl(),
                ],
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0, 'message' => 'fail',
            'data' => ['message' => 'Validation failed.', 'errors' => $e->errors()]
        ], 422);
    } catch (\Exception $e) {
        \Log::error('API Get Customer Orders Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
        return response()->json([
            'status' => 0, 'message' => 'fail',
            'data' => ['message' => 'Failed to retrieve customer orders: ' . $e->getMessage()]
        ], 500);
    }
}

public function addCustomer(Request $request)
{
    try {
        $adminUser = auth()->user();

        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => [
                'required',
                'email',
                'max:255',
                \Illuminate\Validation\Rule::unique('customers', 'email')->where('store_id', $storeId)
            ],
            'mobile'     => 'required|string|regex:/^\+?[0-9\s\-]{8,20}$/',
            'password'   => 'required|string|min:8|regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,}$/',
            'status'     => 'required|in:0,1',

            'address'             => 'nullable|string|max:500',
            'country_id'          => 'nullable|integer|exists:countries,id',
            'state_id'            => 'nullable|integer|exists:states,id',
            'city_id'             => 'nullable|integer|exists:cities,id',
            'postcode'            => 'nullable|string|max:20',
            'address_title'       => 'nullable|string|max:100',
            'is_default'          => 'nullable|in:0,1',
            'delivery_instructions'=> 'nullable|string|max:500',
            'address_type'        => 'nullable|in:0,1,2',
        ]);

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to add customers to this store.']
            ], 403);
        }

        \DB::beginTransaction();

        try {
            $customer = \App\Models\Customer::create([
                'first_name'        => $validated['first_name'],
                'last_name'         => $validated['last_name'],
                'email'             => $validated['email'],
                'mobile'            => $validated['mobile'],
                'password'          => \Hash::make($validated['password']),
                'type'              => 'customer',
                'status'            => $validated['status'],
                'store_id'          => $storeId,
                'regiester_date'    => now()->toDateString(),
                'last_active'       => now()->toDateString(),
                'created_by'        => $adminUser->id,
                'email_verified_at' => now(),
            ]);

            if ($request->filled('address') || $request->filled('country_id') || $request->filled('city_id')) {

                $isDefault = (int) ($validated['is_default'] ?? 0);

                \App\Models\DeliveryAddress::create([
                    'customer_id'         => $customer->id,
                    'full_name'           => trim($customer->first_name . ' ' . $customer->last_name),
                    'phone'               => $customer->mobile,
                    'store_id'            => $storeId, 

                    'title'               => $validated['address_title'] ?? 'Main',
                    'address'             => $validated['address'] ?? '',
                    'country_id'          => $validated['country_id'] ?? null,
                    'state_id'            => $validated['state_id'] ?? null,
                    'city_id'             => $validated['city_id'] ?? null,
                    'postcode'            => $validated['postcode'] ?? '',
                    'delivery_instructions'=> $validated['delivery_instructions'] ?? null,
                    'default_address'     => $isDefault, 
                    'type'                => $validated['address_type'] ?? 0, 
                ]);

                $addressCreated = true;
            } else {
                $addressCreated = false;
            }

            \DB::commit();

            $responseData = [
                'message' => 'Customer created successfully.',
                'customer_id' => $customer->id,
                'store_id' => $storeId,
                'address_created' => $addressCreated,
            ];

            if ($addressCreated) {
                $responseData['address'] = [
                    'full_name' => trim($customer->first_name . ' ' . $customer->last_name),
                    'phone' => $customer->mobile,
                    'is_default' => (bool) $isDefault, 
                ];
            }

            return response()->json([
                'status' => 1,
                'message' => 'successful',
                'data' => $responseData
            ], 201);

        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Customer Store Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to create customer. Please try again later.'
            ]
        ], 500);
    }
}
public function updateStoreOwnerInfo(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.', 'data' => null], 401);
        }

        $storeId = $request->query('store_id') ?? $request->input('store_id');
        if (!$storeId) {
            return response()->json(['status' => 'error', 'message' => 'Store ID is required.', 'data' => null], 400);
        }

        // The old shape was `if (!$store && $adminUser->type !== 'super admin') -> 403;` followed
        // by `if (!$store) { $store = \App\Models\Store::find($storeId); }` -- an UNSCOPED
        // re-lookup whose only purpose was to repair the null a platform operator was left
        // holding by the fallthrough guard. resolveOwnedStore() hands an operator the real Store,
        // so that re-lookup is deleted rather than left sitting one edit away from being
        // reachable by a merchant. The separate 404 'Store not found.' goes with it: an unknown
        // id and an unowned id now answer the same 403, which is the deliberately non-oracle
        // posture resolveOwnedStore() documents at :3765-3767.
        $store = $this->resolveOwnedStore($storeId);
        if (!$store) {
            return response()->json(['status' => 'error', 'message' => 'Permission denied.', 'data' => null], 403);
        }

        // P1-SEC-18 (found by the F5 review). resolveOwnedStore() admits the owner's STAFF, and everything below writes
        // `owner_name`, `owner_email` and `owner_mobile` onto the OWNER's user row -- the owner's login email is where a password
        // reset goes, so a staff member could take the account over in two requests. The owner's identity is the owner's (and the
        // platform operator's). Staff may still resubmit the values already stored (a prefilled form) and may still change every
        // store-level field; only a CHANGE to the owner is refused.
        if (! $this->mayChangeOwnerIdentity($adminUser, $store, $request)) {
            return response()->json(['status' => 'error', 'message' => 'Permission denied.', 'data' => null], 403);
        }

        // P1-SEC-27 (third review). `currency_name` decides what a shopper is charged in -- on MySQL the writer below addresses the
        // CURRENCY_NAME row -- so a CHANGE to it needs the Settings permission like every other currency door. Staff may still
        // resubmit the stored value (a prefilled form) and change every other store-level field.
        if ($request->filled('currency_name')
            && \App\Services\StoreSettingsAccess::deniesRestrictedWrite(['CURRENCY_NAME' => $request->input('currency_name')], $adminUser, $store->id)) {
            return response()->json(['status' => 'error', 'message' => 'Permission denied.', 'data' => null], 403);
        }

        $validated = $request->validate([
            'store_name' => 'nullable|string|max:255',
            'store_email' => 'nullable|email|max:255',
            'store_slug' => 'nullable|string|max:255|unique:stores,slug,' . $store->id,
            'custom_store_url' => 'nullable|url|max:255',

            'owner_name' => 'nullable|string|max:255',
            'owner_email' => 'nullable|email|max:255',
            'owner_mobile' => 'nullable|string|max:20',
            
            'store_address' => 'nullable|string|max:500',
            'store_city' => 'nullable|string|max:100',
            'store_state' => 'nullable|string|max:100',
            'store_zipcode' => 'nullable|string|max:20',
            'store_country' => 'nullable|string|max:100',
            
            'currency_name' => 'nullable|string|max:50',
            'currency_format' => 'nullable|string|max:50',
            'site_rtl' => 'nullable|in:on,off',
            'date_format' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:50',

            'invoice_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'store_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',

            'social_media' => 'nullable|array',
            'social_media.*.platform' => 'required_with:social_media|string',
            'social_media.*.link' => 'required_with:social_media|url',
            'social_media.*.icon' => 'nullable|string',
        ]);

        \DB::beginTransaction();

        try {
            $updatedFields = [];

            if (!empty($validated['store_name'])) { 
                $store->name = $validated['store_name']; 
                $updatedFields[] = 'store_name'; 
            }
            if (!empty($validated['store_email'])) { 
                $store->email = $validated['store_email']; 
                $updatedFields[] = 'store_email'; 
            }
            if (!empty($validated['store_slug'])) { 
                $store->slug = \Illuminate\Support\Str::slug($validated['store_slug']); 
                $updatedFields[] = 'store_slug'; 
            }
            $store->save();

            $owner = \App\Models\User::find($store->created_by);
            if ($owner) {
                if (!empty($validated['owner_name'])) { 
                    $owner->name = $validated['owner_name']; 
                    $updatedFields[] = 'owner_name'; 
                }
                if (!empty($validated['owner_email'])) { 
                    $owner->email = $validated['owner_email']; 
                    $updatedFields[] = 'owner_email'; 
                }
                
                if ($request->has('owner_mobile')) {
                    // B4. The same rule as the web profile editor: a number that changed is a
                    // number nobody has proved. Without this an API caller could confirm one
                    // number and then write another, and `mobile_verified_at` would say
                    // somebody proved a number nobody has ever answered on. Called BEFORE the
                    // write, because it compares the new value against the stored one.
                    app(\Workdo\LynomiaChat\app\Services\MerchantContactVerification::class)
                        ->forgetIfChanged($owner, $validated['owner_mobile'] ?? null);

                    $owner->mobile = $validated['owner_mobile'];
                    $updatedFields[] = 'owner_mobile';
                }
                $owner->save();
            }

            $settingsToUpdate = [];
            $mapFields = [
                'store_address', 'store_city', 'store_state', 'store_zipcode', 'store_country',
                'currency_name', 'currency_format', 'site_rtl', 'date_format', 'timezone'
            ];
            foreach ($mapFields as $field) {
                if (isset($validated[$field])) $settingsToUpdate[$field] = $validated[$field];
            }
            if (isset($validated['custom_store_url']) && !empty($validated['custom_store_url'])) {
                $settingsToUpdate['custom_store_url'] = rtrim($validated['custom_store_url'], '/');
            }

            // P1-SEC-18 (second review). `$storeId` is the raw request string: resolveOwnedStore() matched it with
            // `Store::where('id', $storeId)`, and on MySQL an integer column compared with '5/../../x' coerces to 5 and matches --
            // so the DESTINATION DIRECTORY below could be walked out of public/uploads. The path and the rows use the store the
            // lookup returned. (SQLite compares the string unconverted and never matches, so the suite cannot show the coercion.)
            $storeId = $store->id;
            $uploadPath = public_path('uploads/' . $storeId);

            if ($request->hasFile('invoice_logo')) {
                $file = $request->file('invoice_logo');
                $fileName = time() . '_' . basename($file->getClientOriginalName()); 
                $file->move($uploadPath, $fileName);
                
                $relativePath = 'uploads/' . $storeId . '/' . $fileName;
                \App\Models\Setting::updateOrCreate(
                    ['store_id' => $storeId, 'name' => 'invoice_logo'],
                    ['value' => $relativePath, 'created_by' => $adminUser->id]
                );
                $updatedFields[] = 'invoice_logo';
            }

            if ($request->hasFile('store_image')) {
                $file = $request->file('store_image');
                $fileName = time() . '_' . basename($file->getClientOriginalName()); 
                $file->move($uploadPath, $fileName);

                $relativePath = 'uploads/' . $storeId . '/' . $fileName;
                \App\Models\Setting::updateOrCreate(
                    ['store_id' => $storeId, 'name' => 'theme_image'],
                    ['value' => $relativePath, 'created_by' => $adminUser->id]
                );
                $updatedFields[] = 'store_image';
            }

            foreach ($settingsToUpdate as $key => $value) {
                \App\Models\Setting::updateOrCreate(
                    ['store_id' => $storeId, 'name' => $key],
                    ['value' => $value, 'created_by' => $adminUser->id]
                );
            }

            if (isset($validated['social_media']) && is_array($validated['social_media'])) {
                $socialData = [];
                foreach ($validated['social_media'] as $social) {
                    if (!empty($social['platform']) && !empty($social['link'])) {
                        $socialData[] = [
                            'link' => $social['link'],
                            'icon' => $social['icon'] ?? $this->getDefaultIcon($social['platform']),
                            'platform' => $social['platform'],
                        ];
                    }
                }

                if (!empty($socialData)) {
                    \DB::table('theme_customizes')->updateOrInsert(
                        [
                            'store_id' => $storeId,
                            'theme_id' => $store->theme_id,
                            'name' => 'footer_repeater',
                        ],
                        ['value' => json_encode($socialData)]
                    );
                    $updatedFields[] = 'social_media';
                }
            }

            \DB::commit();

            $currentSettings = \App\Models\Setting::where('store_id', $storeId)->pluck('value', 'name')->toArray();
            $newStoreUrl = $this->buildStoreUrlLikeWeb($store, $currentSettings);

            $invoiceLogoUrl = !empty($currentSettings['invoice_logo']) 
                ? asset($currentSettings['invoice_logo']) 
                : null;

            $storeImageUrl = !empty($currentSettings['theme_image']) 
                ? asset($currentSettings['theme_image']) 
                : null;

            $responseData = [
                'message' => 'Store information updated successfully.',
                'updated_fields' => $updatedFields,
                'store' => [
                    'id' => (int) $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'email' => $store->email,
                    'store_url' => $newStoreUrl,
                    'custom_store_url' => $currentSettings['custom_store_url'] ?? '',
                ],
                'owner' => [
                    'id' => (int) ($owner?->id ?? 0),
                    'name' => $owner?->name ?? '',
                    'email' => $owner?->email ?? '',
                    'mobile' => $owner?->mobile ?? '',
                ],
                'media' => [
                    'invoice_logo' => $invoiceLogoUrl,
                    'store_image' => $storeImageUrl,
                ]
            ];

            return response()->json(['status' => 'success', 'message' => 'Store information updated successfully', 'data' => $responseData], 200);

        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json(['status' => 'error', 'message' => 'Validation failed.', 'data' => ['errors' => $e->errors()]], 422);
    } catch (\Exception $e) {
        \Log::error('Update Store Info Error: ' . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'Failed to update store information.', 'data' => ['error' => $e->getMessage()]], 500);
    }
}

public function getCountries( )
{
    try {
        $countries = Country::where('name', 'Syria')
                            ->orWhere('name', 'سوريا')
                            ->select('id', 'name')
                            ->get()
                            ->map(function ($country) {
                                return [
                                    'id' => (int) $country->id,
                                    'name' => $country->name,
                                ];
                            });

        if ($countries->isEmpty()) {
            return response()->json([
                'status' => 0,
                'message' => 'Country not found in database.',
                'data' => []
            ], 404);
        }

        return response()->json([
            'status' => 1,
            'message' => 'Countries retrieved successfully',
            'data' => $countries
        ], 200);

    } catch (\Exception $e) {
        \Log::error('API Get Countries Error: ' . $e->getMessage());
        return response()->json([
            'status' => 0,
            'message' => 'Failed to retrieve countries.',
            'data' => null
        ], 500);
    }
}



public function getStatesByCountry(Request $request)
{
    $request->validate([
        'country_id' => 'required|integer|exists:countries,id'
    ]);

    try {
        $states = State::where('country_id', $request->query('country_id'))
                       ->orderBy('name', 'ASC')
                       ->select('id', 'name', 'country_id')
                       ->get()
                       ->map(function ($state) {
                           return [
                               'id' => (int) $state->id,
                               'name' => $state->name,
                               'country_id' => (int) $state->country_id,
                           ];
                       });

        return response()->json([
            'status' => 1,
            'message' => 'States retrieved successfully',
            'data' => $states
        ], 200);

    } catch (\Exception $e) {
        \Log::error('API Get States Error: ' . $e->getMessage());
        return response()->json([
            'status' => 0,
            'message' => 'Failed to retrieve states.',
            'data' => null
        ], 500);
    }
}
public function getCitiesByState(Request $request)
{
    $request->validate([
        'country_id' => 'required|integer|exists:countries,id',
    ]);

    try {
        $cities = City::where('country_id', $request->query('country_id'))
                      ->orderBy('name', 'ASC')
                      ->select('id', 'name', 'country_id', 'state_id', 'created_at', 'updated_at')
                      ->get()
                      ->map(function ($city) {
                          return [
                              'id'         => (int) $city->id,
                              'name'       => $city->name,
                              'country_id' => (int) $city->country_id,
                              'state_id'   => (int) $city->state_id,
                              'created_at' => $city->created_at ? $city->created_at->toDateTimeString() : null,
                              'updated_at' => $city->updated_at ? $city->updated_at->toDateTimeString() : null,
                          ];
                      });

        return response()->json([
            'status'  => 1,
            'message' => 'Cities retrieved successfully',
            'data'    => $cities
        ], 200);

    } catch (\Exception $e) {
        \Log::error('API Get Cities Error: ' . $e->getMessage());
        return response()->json([
            'status'  => 0,
            'message' => 'Failed to retrieve cities.',
            'data'    => null
        ], 500);
    }
}

public function updateCustomerInfo(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser || !in_array($adminUser->type, ['admin', 'super admin'])) {
            return response()->json([
                'status' => 0,
                'message' => 'Unauthorized. Valid admin token is required.',
                'data' => null
            ], 401);
        }

        $storeId = $request->query('store_id');
        if (!$storeId) {
            return response()->json([
                'status' => 0,
                'message' => 'store_id is required in query parameters.',
                'data' => null
            ], 400);
        }

        // NEW-ADMINAPI-UNOWNED-STORE-01, second site. `$storeId` is caller-supplied
        // and was never checked against the caller's own stores, while the customer
        // lookup below scopes only on `store_id = $storeId`. Any valid admin token
        // could therefore rewrite ANOTHER store's customer -- first_name, last_name,
        // email, mobile, status and the default address -- by naming that store and
        // one of its customer ids. A cross-tenant PII write.
        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'Permission denied.',
                'data' => null
            ], 403);
        }

        $storeId = $store->id;

        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => [
                'nullable',
                'email',
                'max:255',
                \Illuminate\Validation\Rule::unique('customers', 'email')
                    ->ignore($request->customer_id)
                    ->where('store_id', $storeId)
            ],
            'mobile' => 'nullable|string|max:20',
            'status' => 'nullable|in:0,1',
            
            'address' => 'nullable|string|max:500',
            'city_id' => 'nullable|integer|exists:cities,id',
            'default_address' => 'nullable|in:0,1',
            'delivery_instructions' => 'nullable|string|max:500',
            'type' => 'nullable|in:0,1,2', 
        ]);

        $customer = \App\Models\Customer::where('id', $request->customer_id)
                            ->where('store_id', $storeId)
                            ->first();

        if (!$customer) {
            return response()->json([
                'status' => 0,
                'message' => 'Customer not found or does not belong to this store.',
                'data' => null
            ], 404);
        }

        \DB::beginTransaction();

        try {
            $customerUpdateData = [];
            
            if ($request->filled('first_name')) $customerUpdateData['first_name'] = $request->first_name;
            if ($request->filled('last_name')) $customerUpdateData['last_name'] = $request->last_name;
            if ($request->filled('email')) $customerUpdateData['email'] = $request->email;
            if ($request->filled('mobile')) $customerUpdateData['mobile'] = $request->mobile;
            if ($request->filled('status')) $customerUpdateData['status'] = $request->status;

            if (!empty($customerUpdateData)) {
                $customer->update($customerUpdateData);
            }

            $addressFields = ['address', 'city_id', 'default_address', 'delivery_instructions', 'type'];
            $hasAddressUpdate = false;
            
            foreach ($addressFields as $field) {
                if ($request->has($field)) {
                    $hasAddressUpdate = true;
                    break;
                }
            }

            if ($hasAddressUpdate) {
                $defaultAddress = \App\Models\DeliveryAddress::where('customer_id', $customer->id)
                                                             ->where('default_address', 1)
                                                             ->first();

                $addressUpdateData = [];
                
                if ($request->has('address')) $addressUpdateData['address'] = $request->address;
                if ($request->has('city_id')) $addressUpdateData['city_id'] = $request->city_id;
                if ($request->has('delivery_instructions')) $addressUpdateData['delivery_instructions'] = $request->delivery_instructions;
                if ($request->has('type')) $addressUpdateData['type'] = $request->type;
                if ($request->has('default_address')) $addressUpdateData['default_address'] = $request->default_address;

                $addressUpdateData['full_name'] = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                $addressUpdateData['phone'] = $customer->mobile;

                if ($defaultAddress) {
                    $defaultAddress->update($addressUpdateData);
                } else {
                    $addressUpdateData['customer_id'] = $customer->id;
                    $addressUpdateData['store_id'] = $storeId;
                    $addressUpdateData['default_address'] = 1;
                    \App\Models\DeliveryAddress::create($addressUpdateData);
                }
            }

            \DB::commit();

            $updatedAddress = \App\Models\DeliveryAddress::where('customer_id', $customer->id)
                                                        ->where('default_address', 1)
                                                        ->select('id', 'address', 'city_id', 'default_address', 'delivery_instructions', 'type')
                                                        ->first();

            return response()->json([
                'status' => 1,
                'message' => 'Customer information updated successfully.',
                'data' => [
                    'customer' => [
                        'id' => (int) $customer->id,
                        'first_name' => $customer->first_name,
                        'last_name' => $customer->last_name,
                        'email' => $customer->email,
                        'mobile' => $customer->mobile,
                        'status' => (int) $customer->status,
                    ],
                    'default_address' => $updatedAddress
                ]
            ], 200);

        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'Validation failed.',
            'data' => ['errors' => $e->errors()]
        ], 422);
    } catch (\Exception $e) {
        \Log::error('API Update Customer Info Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
        return response()->json([
            'status' => 0,
            'message' => 'Failed to update customer information.',
            'data' => ['error' => $e->getMessage()]
        ], 500);
    }
}
public function searchCustomerByName(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        $storeId = $request->query('store_id');
        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|min:3|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => [
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $searchTerm = trim($request->name);

        $customers = \App\Models\Customer::where('store_id', $storeId)
            ->where(function ($query) use ($searchTerm) {
                $query->where('first_name', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('last_name', 'LIKE', "%{$searchTerm}%")
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchTerm}%"]);
            })
            ->limit(20)
            ->get();

        if ($customers->isEmpty()) {
            return response()->json([
                'status' => 1,
                'message' => 'No customers found.',
                'data' => [
                    'store_id' => (int) $storeId,
                    'store_name' => $store->name ?? '',
                    'search_term' => $searchTerm,
                    'customers' => [],
                    'count' => 0
                ]
            ], 200);
        }

        $results = $customers->map(function ($customer) use ($storeId) {
            $address = \App\Models\DeliveryAddress::where('customer_id', $customer->id)
                ->orderBy('default_address', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            return [
                'id' => $customer->id,
                'first_name' => $customer->first_name ?? '',
                'last_name' => $customer->last_name ?? '',
                'email' => $customer->email ?? '',
                
                                'status' => $customer->status ?? '',


                'phone' => $customer->mobile ?? $customer->phone ?? '',
                
                'address_details' => $address ? [
                    'address' => $address->address ?? '',
                    'city_name' => $address->city_name ?? '',
                    'country_name' => $address->country_name ?? '',
                    'state_name' => $address->state_name ?? '',
                    'postcode' => $address->postcode ?? '',
                    'phone' => $address->phone ?? ($customer->mobile ?? ''),
                    'is_default' => (bool) ($address->default_address ?? false),
                ] : null,
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Customers retrieved successfully.',
            'data' => [
                'store_id' => (int) $storeId,
                'store_name' => $store->name ?? '',
                'search_term' => $searchTerm,
                'customers' => $results,
                'count' => $results->count()
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);
    } catch (\Exception $e) {
        \Log::error('API Search Customer Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to search customers: ' . $e->getMessage()
            ]
        ], 500);
    }
}
    
        public function assignOrder(Request $request)
    {
        $storeId = $request->query('store_id');

        if (!$storeId) {
            return response()->json([
                'status'  => 'error',
                'message' => __('store_id is required in the URL parameters.')
            ], 400);
        }

        // NEW-ADMINAPI-UNOWNED-STORE-01. `$storeId` is the caller's own query
        // parameter and was used UNVALIDATED as the scope of every lookup below.
        // `->where('store_id', $storeId)` only makes the order, the delivery boy and
        // the store internally consistent; it does not establish that the CALLER owns
        // that store. So any valid admin token could pass store_id=<victim>,
        // order_id=<victim's order>, delivery_boy=<victim's rider> and assign another
        // merchant's order to another merchant's rider -- a cross-tenant state change
        // that also writes `delivery_earnings`.
        //
        // This is the same defect R4-03 fixed at the other 93 methods in this
        // controller; three were missed, and the static sweep that was supposed to
        // catch them forbade only the literal string `Store::find($request->store_id)`
        // and so never looked at this shape. The sweep is now a property over methods
        // (AdminApiTenancyTest::test_every_api_method_that_reads_a_request_store_id_also_proves_ownership).
        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status'  => 'error',
                'message' => __('Permission denied.')
            ], 403);
        }

        // Use the resolved id from here on, so the scope cannot differ from the thing
        // that was authorised (string-vs-int included).
        $storeId = $store->id;

        $validator = Validator::make($request->all(), [
            'order_id'       => 'required|integer|exists:orders,id',
            'delivery_boy'   => 'required|integer|exists:delivery_boys,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors()
            ], 422);
        }

        $order = Order::where('id', $request->order_id)
                      ->where('store_id', $storeId)
                      ->first();

        if (!$order) {
            return response()->json([
                'status'  => 'error',
                'message' => __('Order not found or does not belong to this store.')
            ], 404);
        }

        $deliveryBoy = DeliveryBoy::where('id', $request->delivery_boy)
                                  ->where('store_id', $storeId)
                                  ->first();

        if (!$deliveryBoy) {
            return response()->json([
                'status'  => 'error',
                'message' => __('Delivery boy not found or does not belong to this store.')
            ], 404);
        }

        DB::beginTransaction();
        try {
            if ($order->deliveryboy_id != $request->delivery_boy) {
                DeliveryEarning::where('order_id', $order->id)->delete();
            }

            $order->deliveryboy_id = $request->delivery_boy;
            $order->save();

            $shippingMethod = ShippingMethod::find($order->delivery_id);

            if (
                $shippingMethod &&
                $deliveryBoy->is_commission_enabled == 1 &&
                strtolower(trim($shippingMethod->method_name)) === 'local pickup'
            ) {
                $commissionPercentage = (float) ($deliveryBoy->commission_percentage ?? 0);
                $shippingCost = (float) ($order->delivery_price ?? 0);

                if ($commissionPercentage > 0 && $shippingCost > 0) {
                    $earningAmount = ($shippingCost * $commissionPercentage) / 100;

                    DeliveryEarning::create([
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

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => __('Order assigned successfully.'),
                'data'    => [
                    'order_id'          => $order->id,
                    'delivery_boy_id'   => $deliveryBoy->id,
                    'delivery_boy_name' => $deliveryBoy->name,
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Order Assignment Dashboard API Error: ' . $e->getMessage(), [
                'order_id' => $request->order_id,
                'trace'    => $e->getTraceAsString()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => __('Failed to assign order. Please try again later.')
            ], 500);
        }
    }
}