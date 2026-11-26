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
    use \App\Http\Controllers\Concerns\RestoresCancelledOrderStock;

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

    /*
     * GAP-142 / P4-F110. THE PERMISSION AXIS. The tenancy repair above (and the one in
     * `DeleteOrderNote`) closed WHICH STORE; neither closed WHO INSIDE IT. Any authenticated member
     * of the tenant holding no order-note permission could write a note onto any of that tenant's
     * orders -- and `note_type` admits `to_customer`, validated three lines above, so this route
     * writes MERCHANT-TO-SHOPPER text. An unpermitted staff member could put words in the
     * merchant's mouth to a named customer.
     *
     * `Create Order Note` IS THE WEB TWIN'S OWN NAME: `OrderNoteController::store()` authorises
     * through `OrderNotePolicy::create()`, which is `isAbleTo('Create Order Note')` (`:65`). Seeded
     * by `Utility.php` in the merchant block and granted to the shared global `admin` role, so no
     * merchant is locked out in production.
     *
     * `Manage Order Note` IS SEEDED AND IS DELIBERATELY NOT ACCEPTED -- the opposite of the choice
     * the coupon and tax rows made, so here is the reason. On the web it is a READ right and
     * nothing else: `resources/views/order/view.blade.php:603` wraps the notes SECTION in
     * `@permission('Manage Order Note')` and then wraps the buttons inside it in
     * `@permission('Create Order Note')` (`:670`) and `@permission('Delete Order Note')` (`:649`),
     * and the controller gate never accepts it. The coupon argument -- "the screen's own name
     * already grants this read, so admitting it to the write adds nobody who cannot already see the
     * data" -- does not transfer, because here the write is a separate, narrower grant a merchant
     * ticks on purpose. `Manage Order` is not accepted either: it is the ORDER right, and the web
     * order screen does not let it write notes.
     *
     * ABOVE THE ORDER LOOKUP, so an unpermitted caller cannot use the not-found branch to probe
     * which order ids exist inside the store.
     */
    if (! $this->callerMayActInStore(['Create Order Note'], $store->id)) {
        return $this->storePermissionDenied();
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

    /*
     * GAP-142 / P4-F110. THE PERMISSION AXIS, the sibling of the gate in `AddOrderNote` -- read
     * that one for the reasoning in full, including why `Manage Order Note` is seeded and
     * deliberately NOT accepted.
     *
     * `Delete Order Note` IS THE WEB TWIN'S OWN NAME: `OrderNoteController::destroy()` authorises
     * through `OrderNotePolicy::delete()`, which is `isAbleTo('Delete Order Note')` (`:92`).
     *
     * THE TWO NAMES DO NOT CROSS, deliberately: a staff member granted `Create Order Note` may not
     * delete, and one granted `Delete Order Note` may not write. `order_notes` has no soft delete,
     * so a deletion removes the record of what the merchant told a customer.
     *
     * ABOVE BOTH LOOKUPS, so an unpermitted caller cannot use the two not-found branches to probe
     * which order ids and which note ids exist inside the store.
     */
    if (! $this->callerMayActInStore(['Delete Order Note'], $store->id)) {
        return $this->storePermissionDenied();
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


















 /**
     * The payment statuses a MERCHANT may set by hand on this endpoint.  P4-F145.
     *
     * Deliberately NOT the set of values `orders.payment_status` can hold. `Pending` is a third
     * real value of that column, but the server DERIVES it from verification evidence
     * (`PaymentVerificationService::STATUS_PENDING`) and a merchant may not assert it; admitting it
     * here would let a merchant claim a settlement state nobody verified.
     *
     * This is the same pair the validator's `in:Paid,Unpaid` rule carried before P4-F145 moved the
     * check to where the order's stored value is known, so no transition that was permitted became
     * forbidden and none that was forbidden became permitted.
     */
    private const MERCHANT_SETTABLE_PAYMENT_STATUSES = ['Paid', 'Unpaid'];

    /**
     * `orders.delivered_status` => the status WORD the fulfilment state machine governs. GAP-135.
     *
     * This door speaks integers and the machine speaks the six words
     * `Order::order_status_change()`'s own branch chain matches, so a translation is unavoidable.
     * Both sides of every row are read from `FulfilmentStateMachine`'s own constants rather than
     * typed out, so the map cannot drift from the matrix it is used against.
     *
     * THREE VALUES ARE ABSENT AND EACH ABSENCE IS A DECISION, not an oversight:
     *
     *   0  Pending — the machine has no word for it and neither does the shared writer; there is no
     *      `pending` arm in that branch chain. A move back to Pending is therefore UNGOVERNED here
     *      exactly as it is there, and inventing a rule for it would be a business-rule change,
     *      which is in the escalate-to-owner set.
     *   7  Partially Paid and
     *   8  Pre Order — not fulfilment states at all. They share this column (a payment state and an
     *      order type in the same integer), they are handled by the module arms further down the
     *      shared writer, and `governs()` answers false for them. Refusing them here would break
     *      `ApiOrderFilterStatusCoverageTest`'s "the write accepts every value the column holds",
     *      which exists because a hand-typed status list had already hidden those two orders once.
     *
     * A missing key yields `null`, and `refusalFor()` answers `null` for a word it does not govern
     * — so the "I have nothing to say" decision stays inside the machine instead of being made
     * twice.
     *
     * @var array<int, string>
     */
    private const FULFILMENT_WORD_FOR_STATUS = [
        \App\Support\Orders\FulfilmentStateMachine::STATE_DELIVERED
            => \App\Support\Orders\FulfilmentStateMachine::DELIVERED,
        \App\Support\Orders\FulfilmentStateMachine::STATE_CANCELLED
            => \App\Support\Orders\FulfilmentStateMachine::CANCEL,
        \App\Support\Orders\FulfilmentStateMachine::STATE_RETURN
            => \App\Support\Orders\FulfilmentStateMachine::RETURNED,
        \App\Support\Orders\FulfilmentStateMachine::STATE_CONFIRMED
            => \App\Support\Orders\FulfilmentStateMachine::CONFIRMED,
        \App\Support\Orders\FulfilmentStateMachine::STATE_PICKED_UP
            => \App\Support\Orders\FulfilmentStateMachine::PICKED_UP,
        \App\Support\Orders\FulfilmentStateMachine::STATE_SHIPPED
            => \App\Support\Orders\FulfilmentStateMachine::SHIPPED,
    ];

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

            /*
             * P4-F145 -- FORMAT ONLY HERE. THE TRANSITION IS VALIDATED AFTER THE ORDER IS LOADED.
             *
             * This read `in:Paid,Unpaid`, and that refused a request the shipped app makes on every
             * DELIVERY-status change: `order_repository.dart:81-94` declares `paymentStatus` as a
             * `required String` and writes it into the body unconditionally, and the delivery-status
             * sheet fills it with the order's OWN CURRENT VALUE
             * (`payment_status_bottom_sheet.dart:686-693` -> `order_screen.dart:563-564`).
             *
             * So any order whose stored status is neither literal died here with HTTP 422 before the
             * handler looked at it. The third value is `Pending`
             * (`PaymentVerificationService::STATUS_PENDING`), which covers COD under owner decision
             * D-06, a bank transfer awaiting settlement, and EVERY gateway return whose capture could
             * not be verified. A merchant could not move any of those orders at all from the app.
             *
             * WIDENING THE LIST WOULD BE THE WRONG FIX: admitting `Pending` would let a merchant
             * ASSERT a payment state the server is supposed to derive. Owner policy is that a
             * merchant may not assert settlement without evidence. So the rule here is reduced to
             * FORMAT, and the real question -- is this an actual TRANSITION, and is that transition
             * permitted -- is asked below, where the stored value is known. An echo of the order's
             * own value is not a transition and is ignored entirely.
             *
             * `required_without:delivered_status` is unchanged, so a request carrying neither field
             * is still refused exactly as before.
             */
            'payment_status' => [
                'nullable',
                'string',
                'max:50',
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
         * P4-F145, PART 3 -- AN ECHO IS NOT A TRANSITION ON THE DELIVERY COLUMN EITHER.
         * =========================================================================================
         *
         * PART 1, BELOW THIS ONE, drops an echoed `payment_status` because "a request that asks for
         * the state the order is already in is asking for NOTHING". (It is below, and it runs
         * AFTER the validator rather than before it -- the `in:Paid,Unpaid` rule had to move out of
         * the rule array precisely because the stored value is not known at validation time. An
         * earlier draft of this paragraph said the opposite of both, and comments here are
         * load-bearing: a census reads them.) THE SAME CLIENT DOES THE SAME THING WITH THIS COLUMN
         * and the rules below did not know it: the
         * payment sheet fills `delivered_status` with the order's own stored value on a
         * payment-only change (`payment_status_bottom_sheet.dart:177` -> `getDeliveredStatus`).
         *
         * MEASURED CONSEQUENCE, by driving this door: a merchant could not record a payment on a
         * CANCELLED or a RETURNED order at all. The cancel guard below reads an echoed `2` as an
         * attempt to cancel, and `FulfilmentStateMachine::refusalForLeaving()` reads an echoed `3`
         * as an attempt to leave a settled order. A COD bag handed over on a returned order and a
         * bank transfer that clears after a cancellation are both ordinary, and payment and
         * fulfilment are different axes -- which is the whole reason this endpoint accepts either
         * field alone.
         *
         * THIS PREDICATE GATES THE WRITE ARM TOO, AND THAT IS NOT TIDINESS. `case 2` of the switch
         * restocks every line and reverses the merchant's wallet entitlement, and its own comment
         * relies on the cancel guard to mean it "only ever runs from Pending"; the other arms stamp
         * `cancel_date` / `delivery_date` / `return_date` with `now()`. Exempting the echo from the
         * REFUSALS alone would have walked it into that arm and turned a wrong refusal into a
         * double stock credit and a re-stamped settlement date.
         * `SettledOrderPaymentEchoTest::test_an_echo_does_not_re_run_the_cancellation_side_effects`
         * is that assertion.
         *
         * NOTHING IS RELAXED FOR A REAL MOVE. The comparison is on EQUALITY of the two values, so a
         * genuine backward transition out of a settled order is still refused (decision H2) and a
         * genuine cancel out of a non-pending state is still refused (P4-F105). Both have a case.
         *
         * TWO CONSEQUENCES DISCLOSED RATHER THAN DISCOVERED LATER, both traced through the code:
         *
         *   `case 3` of the write arm also sets `return_status = 1`, and an echo no longer reaches
         *   it. THAT LOSES NOTHING, BECAUSE AN ECHO NEVER REACHED IT: an echo of 3 from a row at 3
         *   passes both local guards and then meets `refusalForLeaving(3)` -- 3 is in `TERMINAL` --
         *   and, in every version that shipped before that predicate existed, `refusalFor(3,
         *   'return')` refused it too, because `PERMITTED[STATE_RETURN]` is empty. So the arm was
         *   unreachable on an echo at this door throughout.
         *
         *   AN EARLIER DRAFT GAVE A DIFFERENT REASON AND IT WAS FALSE, which is worth leaving
         *   recorded: it claimed `delivered_status = 3` with `return_status = 0` is unreachable
         *   because every writer of 3 sets `return_status` in the same breath. There is a FOURTH
         *   writer -- `Api\ApiController`'s `ordersave` assigns `delivered_status` straight from
         *   unvalidated request input (its rule array has two keys, neither of them this one) and
         *   writes no `return_status` at all, so a store's own customer can create exactly that row.
         *   A census over writers is the shape CLAUDE.md warns about, and this one had already
         *   missed one. The reason above needs no census: it is two predicates in one file.
         *
         *   For the record, `(3, 0)` is workflow-stuck rather than money-exposed either way:
         *   `order_return_request`'s restock guard is `return_status !== 2`, not `!== 0`, so `1` is
         *   what the web's Approve/Reject controls are gated on, not the idempotency marker.
         *
         *   A row holding the literal `'-'` that `Api\ApiController` writes casts to 0, so a
         *   request naming `0` now reads as an echo and the write arm no longer normalises the
         *   column to a real `0`. This only arises when payment or a note changes in the same
         *   request: without one, `(int) '-' === 0` already took the "nothing changed" early
         *   return above, so the behaviour is unchanged there. Leaving the value is also what
         *   GAP-254 established for the board -- an unrecognised status keeps its own bucket and
         *   stays reachable rather than being silently folded into Pending.
         */
        $deliveredStatusChanges = $hasDeliveredStatus
            && $oldDeliveredStatus !== (int) $newDeliveredStatus;

        /*
         * P4-F145, PART 1 -- AN ECHO IS NOT A TRANSITION.
         * =========================================================================================
         *
         * The shipped app sends `payment_status` on every call to this endpoint, and on a
         * DELIVERY-status change it sends back the order's own stored value. A request that asks
         * for the state the order is already in is asking for NOTHING: there is no settlement
         * event, no transition to authorise, and nothing to validate.
         *
         * Dropping it here rather than admitting the value to the validator is what keeps the two
         * questions apart, which is the owner's requirement: "Validate delivery status
         * independently from an actual payment-status transition."
         *
         * CASE-INSENSITIVE because the comparison is about IDENTITY of state, not spelling. A
         * client echoing `paid` for a stored `Paid` is naming the same state, so treating it as a
         * change would re-run a settlement write for no reason -- and `$order->save()` below would
         * make `wasChanged('payment_status')` true, firing `OrderObserver::updated()` and moving
         * money through `OrderFinancialService` on a request that asked for no such thing.
         */
        if ($hasPaymentStatus
            && strcasecmp(trim($newPaymentStatus), trim($oldPaymentStatus)) === 0) {

            $hasPaymentStatus = false;
            $newPaymentStatus = $oldPaymentStatus;
        }

        /*
         * P4-F145, PART 2 -- A REAL TRANSITION IS STILL CONSTRAINED, EXACTLY AS BEFORE.
         *
         * `in:Paid,Unpaid` used to live in the validator. It still applies, but only to a request
         * that genuinely asks to MOVE the payment status, and it is asked here because only here is
         * the stored value known.
         *
         * THE SET IS DELIBERATELY NOT WIDENED. `Pending` is a state the SERVER derives from
         * verification evidence (`PaymentVerificationService::resolveOrderPaymentStatus()`); a
         * merchant asserting it by hand would be claiming knowledge of a capture nobody checked.
         * Owner policy: a merchant may not assert settlement without the required evidence.
         *
         * THE RESPONSE SHAPE IS THE VALIDATOR'S OWN, byte for byte -- same HTTP 422, same
         * `success: false`, same `message`, same `errors.payment_status` key -- so a client cannot
         * tell this refusal from the one it replaced. A new shape here would be a contract change
         * on a path the shipped app calls.
         */
        if ($hasPaymentStatus
            && ! in_array($newPaymentStatus, self::MERCHANT_SETTABLE_PAYMENT_STATUSES, true)) {

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => [
                    'payment_status' => [__('The selected payment status is invalid.')],
                ],
            ], 422);
        }

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

        /*
         * THE TRANSITION GUARDS THE WEB HAS AND THIS HANDLER DID NOT.  P4-F105.
         *
         * MEASURED: every non-pending state was cancellable over this endpoint -- Delivered(1),
         * Return(3), Confirmed(4), Picked Up(5) and Shipped(6) all moved to 2 with `cancel_date`
         * stamped -- while `Order::order_status_change()` answers `error` for every one of them
         * (app/Models/Order.php:702). And Pending -> Return landed too, which the web refuses at
         * `:757`. Five states and one, measured one by one.
         *
         * The refusal is `success: false` with HTTP 200, matching this handler's own no-op branch
         * above rather than inventing a shape. NOT 401: CLAUDE.md records that the shipped Flutter
         * admin app force-clears its session on any 401, so a new 401 here logs merchants out. Not
         * 403 or 404 either -- those are this handler's permission and lookup refusals and a client
         * cannot tell them apart from this.
         */
        if ($deliveredStatusChanges && (int) $newDeliveredStatus === 2 && $oldDeliveredStatus !== 0) {
            return response()->json([
                'success' => false,
                'message' => __('Only a pending order can be cancelled.'),
                'data' => $this->orderResponse($order),
            ]);
        }

        if ($deliveredStatusChanges && (int) $newDeliveredStatus === 3 && $oldDeliveredStatus === 0) {
            return response()->json([
                'success' => false,
                'message' => __('A pending order cannot be returned.'),
                'data' => $this->orderResponse($order),
            ]);
        }

        /*
         * GAP-135, THE REMAINDER — THE FULFILMENT STATE MACHINE THIS DOOR COULD NOT REACH.
         * =========================================================================================
         *
         * `Order::refuseIllegalTransition()`'s docblock names this handler by name as "THE ONE DOOR
         * THIS CANNOT REACH", because it writes `delivered_status` directly in the switch below
         * instead of calling `Order::order_status_change()` — the writer the merchant web screen,
         * the order board, the storefront customer's own order, `OrderReplacement::cancelAndReplace()`
         * and the vendored LotteryPrizes screen all share, and the one place the machine is asked.
         * So this door grew two rules of its own (above) and had NOTHING on the four progression
         * arms.
         *
         * MEASURED HERE, BY DRIVING THE ROUTE, BEFORE THIS BLOCK EXISTED: twelve governed
         * transitions landed that the shared writer refuses — Cancelled(2) and Return(3) each to
         * Delivered, Confirmed, Picked Up and Shipped, 2 -> 3 as well, and Delivered(1) backwards to
         * Confirmed, Picked Up and Shipped. Cancelled -> Delivered is the money leg:
         * `Order::product_return()` refuses unless `delivered_status == 1` and then REDUCES
         * `final_price`, so a cancelled order whose merchant entitlement `OrderEntitlementReversal`
         * has already walked back could be marched into the return and refund workflow.
         *
         * BELOW THE TWO ESTABLISHED RULES, DELIBERATELY. The cancel and return refusals above answer
         * for the transitions they govern with strings a client may be matching on (P4-F104/F105,
         * and `MESSAGE_CANCEL_REFUSED` / `MESSAGE_RETURN_REFUSED` are different sentences). Putting
         * this first would reword two shipped refusals; putting it here adds the arms that had no
         * rule at all and changes nothing that already had one.
         *
         * THE RAW COLUMN VALUE, NOT `$oldDeliveredStatus`, which is a cast. One shipped handler
         * writes the literal `-` into this column, and under PHP 8 a cast would make such a row
         * movable where the shared writer's own loose comparisons do not. This passes exactly what
         * `Order::refuseIllegalTransition()` passes, so the two cannot disagree.
         *
         * ONLY WHEN A DELIVERY STATUS WAS ACTUALLY ASKED FOR. The shipped app sends
         * `payment_status` on every call; a payment-only request is not a fulfilment transition and
         * no fulfilment rule may touch it.
         *
         * THE SHAPE IS THIS HANDLER'S OWN: HTTP 200, `success: false`, `data` => `orderResponse()`,
         * matching the two refusals above rather than inventing a fourth. NOT 401 — the shipped
         * Flutter admin app force-clears its session on any 401 — and not 403 or 404, which are this
         * handler's permission and lookup refusals and which a client could not tell apart from
         * this.
         */
        if ($deliveredStatusChanges) {
            /*
             * TWO QUESTIONS, AND THE FIRST ONE IS WHY THE ONE-STEP VERSION OF THIS GATE WAS A
             * BYPASS. `refusalFor()` answers about a target WORD, and `governs()` lists no
             * `pending` word -- so a request naming a status this door can express but the word
             * map cannot translate (0 Pending, 7 Partially Paid, 8 Pre Order) fell through from
             * EVERY state, settled ones included.
             *
             * MEASURED ON THIS DOOR, by driving it, with the one-step gate in place: a CANCELLED
             * order answered `{"success":true}` to `delivered_status = 0`, and
             * `PERMITTED[PENDING]` then admits five of the six words -- so `2 -> 0 -> 1` reached
             * `delivered_status = 1` in two requests, which is exactly what
             * `Order::product_return()` gates on before it REDUCES `final_price`. Ten pairs, every
             * one of them, except the `-> return` legs that `PERMITTED[PENDING]` does not hold.
             *
             * `refusalForLeaving()` IS THE MACHINE'S OWN PREDICATE AND NOT A SECOND LOCAL RULE.
             * That matters more than tidiness here: a local check would be a third definition of a
             * rule `TERMINAL` and `PERMITTED` already publish ("Nothing leaves them through the
             * shared writer"; `matrix()[2]` is `[]`), and the two would drift. It is also NOT a new
             * business rule -- `Order::order_status_change()` has no pending arm, so no other
             * surface in this product can make the move, and refusing it makes this door agree with
             * every other surface rather than diverge from one.
             */
            /*
             * 7 AND 8 NEED NO EXEMPTION LIST, AND AN EARLIER DRAFT CARRIED ONE. `refusalFor()`
             * answers null for a word it does not govern, and no entry in
             * `FULFILMENT_WORD_FOR_STATUS` maps `STATE_PARTIALLY_PAID` or `STATE_PRE_ORDER` --
             * deliberately, because the machine's own docblock calls those two "a schema problem
             * that this machine must not pretend to have solved". So the pair below already permits
             * them out of a non-settled row, which is what `P2-07-12` widened this endpoint for,
             * while `refusalForLeaving()` still refuses them out of a SETTLED one:
             * `PERMITTED[STATE_PARTIALLY_PAID]` admits `delivered`, so `2 -> 7 -> 1` would
             * otherwise reopen a cancelled order exactly as `-> 0` did. The explicit list gave the
             * same answers and read as though the machine needed correcting here.
             */
            $fulfilmentRefusal = \App\Support\Orders\FulfilmentStateMachine::refusalForLeaving($order->delivered_status)
                ?? \App\Support\Orders\FulfilmentStateMachine::refusalFor(
                    $order->delivered_status,
                    self::FULFILMENT_WORD_FOR_STATUS[$newDeliveredStatus] ?? null
                );

            if ($fulfilmentRefusal !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $fulfilmentRefusal,
                    'data' => $this->orderResponse($order),
                ]);
            }
        }

        try {
            DB::transaction(function () use (
                $request,
                $order,
                $deliveredStatusChanges,
                $newDeliveredStatus,
                $hasPaymentStatus,
                $newPaymentStatus,
                $oldPaymentStatus
            ) {
                if ($deliveredStatusChanges) {
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

                            /*
                             * THE GOODS.  P4-F034. MEASURED before this line: a merchant cancelling
                             * through the shipped app left a 3-unit line's stock at 4 where 7 was
                             * due. The guard above means this only ever runs from Pending, so a
                             * replay -- including the second POST carrying a `note`, which this
                             * handler's no-op branch does NOT catch -- is refused before here; the
                             * trait's per-line markers bound it a second time.
                             */
                            $this->restoreCancelledOrderStock(
                                $order,
                                is_array($cancelLines = json_decode((string) $order->product_json, true)) ? $cancelLines : []
                            );

                            /*
                             * P4-F124 -- THE MONEY. This door writes `delivered_status = 2`
                             * DIRECTLY rather than through `Order::order_status_change()`, so it
                             * does not inherit the engine's reversal and a cancelled order kept its
                             * wallet credit for ever. The owner's instruction is to test all the
                             * cancellation doors, so every door that cancels must reverse.
                             *
                             * `reverseOrLog` and not `reverse`: a ledger fault must not fail the
                             * merchant's cancellation. It is idempotent on the financial row's own
                             * status, so a replay -- which this handler's guards already bound --
                             * moves money at most once regardless.
                             */
                            app(\App\Services\OrderEntitlementReversal::class)->reverseOrLog(
                                $order,
                                \App\Services\OrderEntitlementReversal::REASON_CANCELLED,
                                'flutter admin updateStatus'
                            );
                            break;

                        case 3:
                            // Return
                            $order->return_date = now();

                            /*
                             * 0 = none
                             * 1 = request
                             * 2 = approve
                             * 3 = cancel
                             *
                             * `1`, NOT `2`.  P4-F104 / P4-F105.
                             *
                             * This wrote `2` -- APPROVED -- and restocked nothing. That value is the
                             * exact durable idempotency marker
                             * `OrderController::order_return_request` reads before crediting
                             * (OrderController.php:1421-1422), so an app-side "Return" DISARMED the
                             * merchant's subsequent genuine web approval: `restoreReturnedStock()`
                             * was skipped, the response still read "Return request approve
                             * successfully", and the goods were lost silently and permanently.
                             * MEASURED: stock 4 before and after both the app transition and the
                             * merchant's approval, where 7 was due.
                             *
                             * And there was no UI-reachable recovery, because both web controls are
                             * gated on `return_status == 1` (order/action.blade.php:35,
                             * order/grid.blade.php:230) -- only a crafted reject-then-approve pair
                             * of POSTs could get the stock back.
                             *
                             * `1` means "a return has been requested", which is what a tap in the
                             * app actually is. The merchant then approves it in the web UI and the
                             * single existing restore door credits the goods. No restock is added
                             * here on purpose: `restoreReturnedStock()` is private to
                             * OrderController and owns the per-line marker and the store predicate,
                             * so copying it would create a FOURTH restock door beside
                             * status_cancel, order_return_request and RefundStock.
                             */
                            $order->return_status = 1;
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
                    /*
                     * AN AUDIT LINE, NOT A REFUSAL, AND THE DISTINCTION IS DELIBERATE.
                     * =============================================================================
                     *
                     * Letting an echoed `delivered_status` through is what makes recording a
                     * payment on a settled order possible at all -- the whole point of PART 3
                     * above -- and a refusal here would re-break it. But the request that is now
                     * permitted can also move money: `$order->save()` reaches `OrderObserver` ->
                     * `OrderFinancialService::syncFromOrder()`, and a `Paid` there runs
                     * `markFinancialAsPaid()`.
                     *
                     * THE CONTROL IS ALREADY THERE AND IS NAMED FOR THIS: P4-F124's guard in
                     * `OrderFinancialService` returns early when the financial row is already
                     * `cancelled` or `refunded`, its own docblock saying "a late `Paid` on a
                     * cancelled order would reach `markFinancialAsPaid()` and CREDIT the merchant a
                     * second time". It does NOT cover every case -- a RETURNED order's financial row
                     * is moved to `refunded` only by the refund door, and a cancellation whose
                     * reversal threw leaves the row non-terminal -- and this is NOT new: the web
                     * twin, `OrderController::order_payment_status`, writes `payment_status` with no
                     * `delivered_status` predicate at all, behind the SAME `Manage Order` ability.
                     * What changed is that the shipped app can now do what the panel always could.
                     *
                     * So: no refusal, and a trace instead, because an operator reconstructing a
                     * double credit should not have to infer that this request happened. Registered
                     * as a gap rather than silently accepted.
                     */
                    if (in_array((int) $order->delivered_status, [2, 3], true)
                        && strcasecmp((string) $newPaymentStatus, (string) $oldPaymentStatus) !== 0
                    ) {
                        Log::warning('Payment status changed on a settled order', [
                            'order_id' => $order->id,
                            'product_order_id' => $order->product_order_id,
                            'store_id' => $order->store_id,
                            'actor_id' => auth()->id(),
                            'delivered_status' => (int) $order->delivered_status,
                            'old_payment_status' => $oldPaymentStatus,
                            'new_payment_status' => $newPaymentStatus,
                        ]);
                    }

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
    $types = $this->readableNotificationTypes($user, $storeId);

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
 * The notification types this caller may read IN THIS STORE, from the web bell's own registry.
 *
 * F181 RESIDUAL, 2026-10-30 — THE STORE PREDICATE WAS MISSING AND THIS WAS THE WRONG QUESTION.
 * The first version of this method asked `$user->isAbleTo($permission)`, which is Laratrust's
 * `allPermissions()` — a **union across every store the person holds a role in, with no store
 * predicate at all**. One merchant with two stores, a staff member granted `Manage Order` in
 * store A, `current_store` switched to store B: `isAbleTo()` answered yes for B, and the
 * `order_paid` and `order_created` rows it then served carry `final_price`, `payment_type` and
 * `payment_status`. The switch itself is legitimate — the staff member belongs to the tenant
 * that owns both stores — so what the gate had to stop was gaining a permission by moving,
 * which is precisely the defect `MerchantPermissions::names()` was written for and says so in
 * its own docblock: *"an employee permitted in A would read B."*
 *
 * SO IT NOW ASKS THE STORE-SCOPED READER, and `$storeId` is a REQUIRED parameter rather than
 * one defaulted from `current_store` inside here — `names()` makes that argument mandatory for
 * the same reason, so that a future caller serving some OTHER store's rows cannot evaluate the
 * wrong store's grants by omission.
 *
 * THE OPERATOR LIMB IS GONE AND THAT IS NOT A LOCKOUT. `names()` short-circuits to `['*']` for
 * the platform operator and `held()` treats the wildcard as every permission, so the operator
 * still reads all three types — now through the one wildcard this codebase already has, rather
 * than a second string comparison on `users.type` that could drift from it. The operator case
 * in `AdminApiNotificationScopeTest` is what holds that true.
 *
 * `names()` IS RESOLVED ONCE and asked three times through `held()`. Calling `allows()` per
 * type would re-run two queries per type — the exact waste `held()`'s docblock records as a
 * defect found in the dashboard gate.
 *
 * @param  int|string|null  $storeId  the store whose rows are about to be served
 * @return array<int, string>
 */
private function readableNotificationTypes($user, $storeId): array
{
    $registry = \App\Http\Controllers\MerchantNotificationController::TYPE_PERMISSIONS;

    $held = MerchantPermissions::names($user, $storeId);

    $readable = [];

    foreach ($registry as $type => $permission) {
        if (MerchantPermissions::held($held, [$permission])) {
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

        /*
         * TOKEN SECURITY STAGE 3, and it is INERT as shipped — see config/tokens.php for the whole
         * account. `merchant_token_lifetime_days` is null, so `$expiresAt` is null, so this is the
         * same two-argument call it has always been and the token row's `expires_at` stays NULL.
         *
         * The third argument is the entire mechanism the proposal specifies. It is here rather than
         * in `config/sanctum.php` because that file does not exist and creating it would expire
         * CUSTOMER and DRIVER tokens too — and storefront customers have no login endpoint
         * (`Api\AuthController::login` is unrouted), so expiring theirs would lock them out with
         * nothing to log back in with.
         *
         * Only tokens minted AFTER a value is set are affected: `expiresAt` is written into the row
         * at creation, so every existing token keeps `expires_at = NULL` and keeps working.
         * `CustomAuth:66` refuses only a token whose `expires_at` is set AND past.
         */
        $lifetimeDays = config('tokens.merchant_token_lifetime_days');
        $expiresAt = ($lifetimeDays !== null && (int) $lifetimeDays > 0)
            ? now()->addDays((int) $lifetimeDays)
            : null;

        /*
         * THE MySQL TIMESTAMP CEILING. `expires_at` is a TIMESTAMP column, whose range ends at
         * 2038-01-19 03:14:07 UTC, and this repository's MariaDB runs STRICT_TRANS_TABLES -- so a
         * later value is an ERROR, not a truncation. Measured by an independent review: a lifetime
         * past that boundary turns EVERY MERCHANT LOGIN into a 500, and the shipped Flutter app
         * meets a failed login with a forced logout. A configuration mistake would have been a
         * total outage of the admin app.
         *
         * Refused here and minted WITHOUT an expiry, loudly. Failing to "no expiry" rather than
         * "nobody can log in" is deliberate: an unenforced expiry is a security feature not yet in
         * effect, while a failed login is the platform down.
         */
        if ($expiresAt !== null) {
            $ceiling = \Illuminate\Support\Carbon::parse(
                config('tokens.merchant_token_expiry_ceiling', '2038-01-01 00:00:00')
            );

            if ($expiresAt->greaterThanOrEqualTo($ceiling)) {
                \Illuminate\Support\Facades\Log::error(
                    'merchant token lifetime refused: beyond the TIMESTAMP ceiling, so the token is minted without an expiry',
                    ['days' => (int) $lifetimeDays, 'would_expire' => $expiresAt->toDateTimeString(), 'ceiling' => $ceiling->toDateTimeString()]
                );

                $expiresAt = null;
            }
        }

        $token = $user->createToken('auth_token', ['merchant'], $expiresAt)->plainTextToken;
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ THE CATEGORY
         * TREE.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer
         * token held by a staff member of the owning store holding NO permission at all, and the whole
         * category tree came back. The only control was tenancy, and tenancy admits every employee of
         * the merchant by design -- `creatorId()` resolves a staff row to their employer.
         *
         * A GAP IN ONE OF SEVEN LIST HANDLERS AND NOT A DESIGN THIS SURFACE TOOK: `ProductList` asks
         * `Manage Products`, `CouponList` `Manage Coupon`, and `ShippingList`, `TaxList`, `VariantList`
         * and `ReviewList` each carry a name. Only the category list did not.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `CategoryController::index()` -- the same screen --
         * and is seeded by `Utility.php`, both as a row and onto the global `admin` role every
         * self-registered merchant holds, so a merchant can really grant it and no production merchant
         * loses the screen.
         *
         * `callerMayActInStore()` AND NOT `refuseWithoutPermission()`, AND THE SIX SIBLINGS ARE SPLIT
         * BETWEEN THE TWO: `ProductList`, `VariantList` and `CouponList` use
         * `refuseWithoutPermission()`, which has NO OWNER LIMB -- that is why the census records
         * `couponlist` and `Top_orders` refusing the store's own BARE owner -- while `ShippingList`,
         * `TaxList` and `ReviewList` use this owner-limbed helper. The owner-limbed one is the later
         * shape and the correct one; reproducing a known defect on a new door would be shipping it on
         * purpose. Its `MerchantPermissions` limb also answers the platform operator the wildcard,
         * which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         *
         * BELOW the tenancy control, so a foreign tenant keeps `storeAccessDenied()`'s own words
         * ("access this store") rather than being told they lack a permission -- both answers are 403
         * on this door, so the message is what separates the two readings.
         */
        if (! $this->callerMayActInStore(['Manage Product Category'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ THE SHIPPING
         * CLASSES.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they were served
         * the list. The only control was tenancy, and tenancy admits every employee of the merchant by
         * design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `ShippingController::index()`, and is seeded by
         * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
         * business rule invented by engineering, and one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         *
         * ITS TWO SIBLINGS IN THIS BLOCK ALREADY HAVE A GATE and this one did not: `OrderList` asks for
         * `Manage Order`/`Show Order` and `CouponList` for `Manage Coupon`, both through
         * `refuseWithoutPermission()`. The shape here is the owner-limbed one because refusing a store's
         * own owner is the regression this family has produced.
         */
        if (! $this->callerMayActInStore(['Manage Shipping Class'], $store->id)) {
            return $this->storePermissionDenied();
        }

        $shipping = Shipping::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($shipping)) {
            return $this->success($shipping);
        } else {
            return $this->error(['message' => __('Shipping Data not found.')]);
        }
    }

    /**
     * The store's currency symbol, resolved the way every other handler here resolves it.
     *
     * P2.5-TAX-CURRENCY. Extracted rather than copied a fourth time: `ProductList` (:1317),
     * `:1484` and `:2630` each carry this block verbatim. Those three are deliberately left alone
     * -- rewriting working handlers is not part of this bounded change -- but the next site that
     * needs a symbol should call this instead of growing the duplication.
     *
     * The fallback chain is the existing one and is unchanged: the active `currency_rates` row for
     * the store's configured currency; failing that `$` only when the configured currency really
     * is USD; failing that the currency CODE, which is a poor symbol but an honest one. There is
     * no hard-coded `$` default for a store that has not been configured.
     */
    private function storeCurrencySymbol($store): string
    {
        $currencyCode = \App\Models\Utility::GetValueByName('defult_currancy', $store->id) ?? 'USD';

        $currencyRate = \App\Models\CurrencyRate::where('store_id', $store->id)
            ->where('currency_code', $currencyCode)
            ->where('is_active', 1)
            ->first();

        return $currencyRate
            ? $currencyRate->symbol
            : ($currencyCode === 'USD' ? '$' : $currencyCode);
    }

    public function TaxList(Request $request)
    {
        $store = $this->resolveOwnedStore($request->store_id);
        if (empty($store)) {
        return $this->storeAccessDenied();
    }

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ THE TAX CLASSES.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they were served
         * the list. The only control was tenancy, and tenancy admits every employee of the merchant by
         * design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `TaxController::index()`, and is seeded by
         * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
         * business rule invented by engineering, and one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Manage Tax'], $store->id)) {
            return $this->storePermissionDenied();
        }

        $tax = Tax::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($tax)) {
            // P2.5-TAX-CURRENCY. ADDITIVE: every key the paginator already sent is still sent,
            // with `currency_symbol` beside them -- the same shape and the same key name
            // `ProductList` already uses (:1346), so the app parses it the same way.
            //
            // WHY IT WAS NEEDED. This endpoint returned the paginator and nothing else, and
            // nothing in the admin app caches a store currency -- every `currencySymbol` in it is
            // assigned from the response of that screen's own endpoint. So the tax screen had no
            // way to know the store's currency and printed a hard-coded `$` on a fixed-amount
            // tax, which is wrong for every store that does not price in dollars. The `$` was
            // removed when the defect was found; this is what lets the right symbol take its
            // place.
            return $this->success(array_merge(
                $tax->toArray(),
                ['currency_symbol' => $this->storeCurrencySymbol($store)]
            ));
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY CREATE A CATEGORY.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they were admitted
         * all the way to the INSERT. The only control was tenancy, and tenancy admits every employee of
         * the merchant by design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `CategoryController::store()`, and is seeded by
         * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
         * business rule invented by engineering, and one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         *
         * THE INSERT ITSELF IS STILL BROKEN AND THAT IS A SEPARATE FINDING, left alone here:
         * `categories.slug` is NOT NULL with no default and this handler never sets one, so the write
         * fails on any engine that enforces it (production's MariaDB does). The gate is above that
         * failure, so a refused caller never reaches it.
         */
        if (! $this->callerMayActInStore(['Create Product Category'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY REWRITE A CATEGORY.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they renamed it;
         * the write covers `name`, the two images, `parent_id`, `trending` and `status`, and a category's
         * `status` decides whether its products are reachable on the storefront. The only control was
         * tenancy, and tenancy admits every employee of the merchant by design -- `creatorId()` resolves a
         * staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `CategoryController::update()`, and is seeded by
         * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
         * business rule invented by engineering, and one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Edit Product Category'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY DESTROY A CATEGORY.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer
         * token held by a staff member of the owning store holding NO permission at all, and the
         * category row was destroyed. `categories` has no soft delete and this path writes no audit
         * row. The only control was tenancy, and tenancy admits every employee of the merchant by
         * design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE TWO INTEGRITY GUARDS BELOW ARE NOT THIS AXIS. They refuse a category that still holds
         * products (the `products.category_id` cascade) or child categories, which bounds the loss;
         * neither asks WHO the caller is. And `AddCategory` / `UpdateCategory` in this file are
         * already gated on `Create Product Category` / `Edit Product Category`, so the irreversible
         * delete was the one category door with no permission axis.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `CategoryController::destroy()`, and is seeded by
         * `Utility.php` -- both as a row and onto the global `admin` role every self-registered
         * merchant holds -- so a merchant can really grant it and no production merchant loses the
         * door. A permission name invented here would be a business rule invented by engineering, and
         * one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and ABOVE
         * the row lookup, so an unpermitted caller cannot use the not-found branch to probe which
         * category ids exist. Through `callerMayActInStore()` rather than `isAbleTo()`: its owner limb
         * keeps the store's own owner admitted (a merchant holds no permission implicitly, and
         * refusing the owner is the regression this family has already produced), and its
         * `MerchantPermissions` limb answers the platform operator the wildcard, which `isAbleTo()`
         * alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Delete Product Category'], $store->id)) {
            return $this->storePermissionDenied();
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

    /*
     * GAP-142 / P4-F065. THE PERMISSION AXIS ON THE THREE COUPON WRITERS.
     *
     * `resolveOwnedStore()` above settles TENANCY and the per-row lookup carries a `store_id`
     * predicate. Neither says who INSIDE the store may run a coupon, and nothing else did -- so any
     * authenticated member of the tenant holding no coupon permission could mint a coupon, rewrite a
     * live campaign's `discount_amount`, or delete one. That is price-affecting: `discount_amount`,
     * `coupon_limit` and `coupon_expiry_date` are what a shopper's basket is reduced by.
     *
     * EACH HANDLER TAKES ITS OWN WEB TWIN'S NAME: `CouponController::store()` gates on
     * `Create Coupon` (`:89`), `update()` on `Edit Coupon` (`:417`), `destroy()` on `Delete Coupon`
     * (`:483`). All four Coupon names are seeded by `Utility.php` in the merchant block, so nothing
     * is invented here.
     *
     * `Manage Coupon` IS ALSO ACCEPTED, and that is wider than the web rather than equal to it, so
     * it is a stated choice: `CouponController::index()` and `show()` gate the coupon SCREEN on
     * `Manage Coupon`, so a staff member holding only that name already reads every coupon in the
     * store, `discount_amount` included. The admin API is the Flutter app's only coupon surface and
     * has no separate read screen, so a merchant who ticked that one box for a staff member can run
     * coupons in the app today. Refusing them would be a NEW refusal of a live capability, which
     * this row is not authorised to take. `AdminApiCouponWritePermissionTest` pins it by name, so
     * narrowing it later is a visible change rather than a silent one.
     *
     * THE OPERATOR IS ADMITTED AND THE WEB REFUSES THEM -- RECORDED, NOT RESOLVED.
     * `CouponController::refuseOperatorWrite()` refuses the platform operator every coupon write by
     * name, on the owner's decision of 2026-10-28 ("read and export only"). The API does not adopt
     * that, because the operator can write through these three routes TODAY --
     * `resolveOwnedStore()` admits them to any store and nothing else asked -- and withdrawing a
     * live capability is a second, unstated product decision that must not ride on a security fix.
     * GAP-142's recorded next action is to gate these routes "with the operator-first predicate
     * already in `MerchantPermissions::allowsAny`", and that predicate answers `WILDCARD` for them.
     * Pinned by `test_the_platform_operator_still_writes_coupons`.
     *
     * 403 THROUGH `storePermissionDenied()`, never `$this->error()` (HTTP 200 with `{status: 0}`,
     * which no client distinguishes from success) and never 401 (the shipped app force-clears its
     * session on one).
     */
        if (! $this->callerMayActInStore(['Create Coupon', 'Manage Coupon'], $store->id)) {
            return $this->storePermissionDenied();
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

    // GAP-142 / P4-F065. `Edit Coupon` is what `CouponController::update()` names. See `AddCoupon`
    // above for the full reasoning, including why `Manage Coupon` also admits and why the operator
    // is still admitted here while the web refuses them. `discount_amount` is assigned verbatim
    // from the request below, so this gate is what stands between a staff member with nothing
    // granted and a live discount.
    if (! $this->callerMayActInStore(['Edit Coupon', 'Manage Coupon'], $store->id)) {
        return $this->storePermissionDenied();
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

        // GAP-142 / P4-F065. `Delete Coupon` is what `CouponController::destroy()` names. See
        // `AddCoupon` for the full reasoning. Ahead of the validator and of the row lookup, so an
        // unpermitted caller cannot use the not-found branch to probe which coupon ids exist.
        if (! $this->callerMayActInStore(['Delete Coupon', 'Manage Coupon'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * GAP-142 / P4-F075. THE PERMISSION AXIS ON THE SIX TAX AND SHIPPING WRITERS.
         *
         * `resolveOwnedStore()` above settles TENANCY and the per-row lookups carry a `store_id`
         * predicate. Neither asks who INSIDE the store may act, and nothing else did -- so any
         * authenticated member of the tenant holding nothing could create, rename or delete the
         * store's tax and shipping classes.
         *
         * WHERE THE MONEY ACTUALLY IS, MEASURED -- and the brief's "price-affecting" framing
         * corrected rather than repeated. `taxes` holds `name` and `store_id` ONLY
         * (`2023_12_22_060417`) and `shippings` holds `name`, `description`, `slug`, `store_id`
         * (`2023_12_19_054936`). Neither carries a rate or an amount. **THE PRICE IS ONE TABLE
         * AWAY, THROUGH A CASCADE, AND THAT IS WORSE:** `tax_methods.tax_id` is
         * `->onDelete('cascade')` against `taxes.id` (`2023_12_25_040519:27`) and
         * `tax_methods.tax_rate` is the percentage a basket is taxed at -- so `DeleteTax` destroys
         * every rate hanging off the class BELOW the application, with no model event, no log line
         * and nothing to roll back. Same cascade shape as `categories` -> `products` (P1-INV-12).
         * `shippings` has no foreign key referencing it, so the shipping half is a commercial
         * SETTING rather than money, and `AdminApiTaxShippingWritePermissionTest` asserts each as
         * what it is.
         *
         * EACH HANDLER TAKES ITS OWN WEB TWIN'S NAME. `Shipping` is the shipping CLASS model and
         * its twin is `ShippingController` -- `store()` `Create Shipping Class` (`:229`), `update()`
         * `Edit Shipping Class` (`:287`), `destroy()` `Delete Shipping Class` (`:332`). `Tax` is the
         * tax CLASS model and its twin is `TaxController` -- `store()` `Create Tax` (`:50`),
         * `update()` `Edit Tax` (`:172`), `destroy()` `Delete Tax` (`:218`). All six, and both
         * `Manage` names, are seeded by `Utility.php` in the merchant block.
         *
         * `Manage Tax` / `Manage Shipping Class` ALSO ADMIT, for the reason `AddCoupon` records in
         * full: those are what the web SCREENS gate on (`TaxController::index` `:21`,
         * `ShippingController::index` `:186`), the Flutter app has no separate read screen for
         * either, and refusing them would be a NEW refusal of a capability a merchant's staff have
         * today. Pinned by `test_staff_holding_the_screens_manage_name_still_write`.
         *
         * THE OPERATOR IS ADMITTED AND THE WEB REFUSES THEM ON ALL SIX WRITES, NOT THREE -- corrected
         * by the independent review, and the distinction it drew matters:
         *
         *   SHIPPING: `ShippingController::shippingWriteRefusal()` (:139-162) refuses the operator
         *   BY NAME. That is a DECISION somebody took.
         *
         *   TAX: `TaxController` :50, :172, :218 are bare `isAbleTo('Create|Edit|Delete Tax')` with
         *   no operator limb anywhere in the file -- and `isAbleTo()` has no super-admin bypass, so
         *   the operator is refused there as a BY-PRODUCT of that shape, not by anyone's choice.
         *
         * Both are recorded and neither is resolved here, on the same reasoning as the coupon row:
         * withdrawing a live capability is a product decision, not a side effect of closing a
         * permission hole. But the coupon refusal is an explicit owner decision of 2026-10-28 and
         * this API contradicts it, so that one is in `OWNER-DECISION-PACKAGE.md` rather than merely
         * noted here.
         */
        if (! $this->callerMayActInStore(['Create Shipping Class', 'Manage Shipping Class'], $store->id)) {
            return $this->storePermissionDenied();
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

        // GAP-142 / P4-F075. `Edit Shipping Class` is what `ShippingController::update()` names.
        // See `AddShipping` above for the full reasoning.
        if (! $this->callerMayActInStore(['Edit Shipping Class', 'Manage Shipping Class'], $store->id)) {
            return $this->storePermissionDenied();
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

        // GAP-142 / P4-F075. `Delete Shipping Class` is what `ShippingController::destroy()`
        // names. Ahead of the validator and the row lookup, so an unpermitted caller cannot use
        // the not-found branch to probe which shipping-class ids exist. See `AddShipping`.
        if (! $this->callerMayActInStore(['Delete Shipping Class', 'Manage Shipping Class'], $store->id)) {
            return $this->storePermissionDenied();
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

        // GAP-142 / P4-F075. `Create Tax` is what `TaxController::store()` names. See
        // `AddShipping` above for the full reasoning, including where the money really is.
        if (! $this->callerMayActInStore(['Create Tax', 'Manage Tax'], $store->id)) {
            return $this->storePermissionDenied();
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

        // GAP-142 / P4-F075. `Edit Tax` is what `TaxController::update()` names. See
        // `AddShipping` above for the full reasoning.
        if (! $this->callerMayActInStore(['Edit Tax', 'Manage Tax'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * GAP-142 / P4-F075. `Delete Tax` is what `TaxController::destroy()` names, and THIS is the
         * money one: `tax_methods.tax_id` is ON DELETE CASCADE against `taxes.id`, so the delete
         * below destroys every `tax_rate` hanging off the class, beneath the application. Ahead of
         * the validator and the row lookup, so the not-found branch is not an oracle.
         *
         * BELOW THE `empty($store)` GUARD, AND THAT ORDER IS NOT COSMETIC. The first draft of this
         * gate landed between the resolution and the guard, so `$store->id` dereferenced null for a
         * caller who does not own the store: the clean HTTP-200 `{status: 0}` tenancy refusal became
         * a 500. Caught by `test_another_tenants_member_is_still_refused_and_destroys_no_rate`,
         * which is the only case in this suite that drives a store the caller does not own -- and
         * precisely why a cross-tenant control belongs in every one of these suites even though
         * tenancy is not what the row is about.
         */
        if (! $this->callerMayActInStore(['Delete Tax', 'Manage Tax'], $store->id)) {
            return $this->storePermissionDenied();
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
                /* `integer`, for the same reason as `deletevariant`'s `variant_id`: `required`
                   accepts an array, `whereKey([a, b])` compiles to `whereIn(...)`, and `->first()`
                   then resolves the LOWEST id rather than refusing — the row renamed would be
                   chosen by caller-supplied input. It matters more here than on the delete door,
                   because `variant` is the key both rebuilds match their deletes on, so a rename
                   landing on an unnamed row arms the owner's next save to hard-delete it. */
                'productvariant_id' => 'required|integer',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        /*
         * F195 / D-28c (b) -- THE SAME PERMISSION AND OWNERSHIP QUESTIONS AS THE DELETE DOOR.
         *
         * WHY A RENAME ENDPOINT NEEDS THEM, which is not obvious: this method had **no permission
         * check at all** -- not `Edit Products`, nothing -- and it resolved the row by
         * `ProductVariant.store_id`, the column `product_attribute_delete`'s own comment records
         * (S36) as *"not the authority on who owns it"*. And the field it writes is `variant`, which
         * is **the key both variant rebuilds match their deletes on**
         * (`whereNotIn('variant', $sku_array)` and `whereNotIn('variant', $com)`). So renaming a
         * variant makes the owning merchant's NEXT ordinary product save hard-delete that row as
         * uncovered: a write that looks cosmetic arms a destructive one in another request.
         *
         * THE DRIFTED ROW THAT MAKES IT CROSS-TENANT IS DOCUMENTED IN THIS REPOSITORY.
         * `getCurrentStore()` returns the hardcoded literal 1 for a `super admin` with an empty
         * `current_store` (`helper.php:636`), so an operator's ordinary save of a store-7 product
         * stamped every one of its variants `store_id = 1` while `products.store_id` stayed 7
         * (recorded in `ProductController::update()`'s attribute branch). Under the old predicate,
         * store 1's merchant could pass `store_id=1` plus that variant id and rename store 7's
         * variant -- and so arm the deletion of it.
         *
         * Latent rather than live, because it needs such a drifted row. Fixed here anyway: it is
         * the same column this slice just stopped trusting on the delete door, and leaving the
         * rename door gated on nothing while the delete door is gated on the product would be the
         * odd one out. Found by the mandated review of the F195 completion.
         */
        $ProductVariant = ProductVariant::withoutGlobalScopes()
            ->whereKey($request->productvariant_id)
            ->first();

        $ssProduct = \App\Services\VariantRemovalPolicy::parentProduct($ProductVariant);

        // NULL GUARD, pre-existing and still load-bearing. This method wrote to
        // the resolved row with no existence check, so an id matching nothing
        // already raised "Attempt to read property on null" -- a 500 on bad
        // input. A row whose product is missing, or whose product is not in the
        // named store, resolves to the same clean refusal, so a foreign id stays
        // indistinguishable from an absent one.
        if (
            empty($ProductVariant)
            || $ssProduct === null
            || (int) $ssProduct->store_id !== (int) $store->id
        ) {
            return $this->error(['message' => __('Product Variant data not found.')]);
        }

        $ssDenied = \App\Services\VariantRemovalPolicy::authorize(\Auth::user(), $ssProduct);

        if ($ssDenied !== null) {
            return $this->error(['message' => $ssDenied['msg']]);
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

        /* `integer`, NOT just `required`. `required` accepts an ARRAY, and
           `ProductVariant::whereKey([a, b])` compiles to `whereIn(...)`, so `->first()` resolves
           the LOWEST matching id rather than refusing: the row acted on was chosen by
           caller-supplied input across the whole table, and the two outcomes are distinguishable
           in the response, which makes it an existence oracle over `product_variants.id`
           platform-wide. Measured both ways by review. A consent signal is worth nothing if the
           identifier beside it can select a different row than the one confirmed.
           `integer` still accepts the numeric strings every real client sends. */
        $rules = [
            'variant_id' => 'required|integer'
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
         * F195 (b) / D-28c -- THE D-20 REFERENCE BLOCK NOW APPLIES HERE, THROUGH THE SHARED POLICY.
         *
         * WHAT THIS USED TO BE: the checks that refuse a delete when a cart line, a wishlist entry
         * or an order references the variant lived in `ProductController::ssRefuseVariantRemoval()`,
         * PRIVATE to a web controller, so this endpoint had none of them. The previous comment here
         * recorded that gap and named the three ways out -- extract into a service both controllers
         * call, duplicate the queries, or instantiate a web controller from an API one. The owner's
         * D-28c chooses the first and forbids the other two by name. `App\Services\
         * VariantRemovalPolicy` is that extraction, and `ProductController` now holds one-line
         * delegates to it, so there is one implementation under all three doors.
         *
         * OWNERSHIP FOLLOWS THE PARENT PRODUCT, which is the other half of the owner's condition.
         * This handler used to narrow on `ProductVariant.store_id` -- the column
         * `product_attribute_delete`'s own comment records (S36) as *"not the authority on who owns
         * it"*, because it is nullable and is written from `getCurrentStore()` by paths that predate
         * tenancy. The product's store is the authority, and it must be the store the caller NAMED
         * and owns (`resolveOwnedStore()` above), so this is no wider than it was: a variant whose
         * product sits in another of the caller's stores is still refused here, and a variant with
         * a NULL `store_id` is now reachable only if its PRODUCT is in the named store, which is a
         * better question than the one the column could answer.
         *
         * F184 / D-28c -- THE CONSENT SIGNAL IS VERIFIABLE, which is the owner's condition for
         * this door in particular: *"use a verifiable explicit-consent signal on the API path
         * rather than permitting a plain API call."*
         *
         * This passed a hardcoded `true`, on D-20's reading that a dedicated per-variant endpoint
         * is itself the deliberate act. The owner's F184 condition supersedes that. The caller
         * must now echo the variant's own `variant` value in `confirm_variant`.
         *
         * WHY AN ECHO AND NOT A BOOLEAN. Anything that can send `confirm=1` can send it blind, so
         * a boolean would record that a parameter was set, not that a client had seen what it was
         * destroying. The name is a value a caller can only supply by having read the row. An
         * unconfirmed call is answered with the refusal that NAMES the variant and its SKU, price
         * and stock, so a client's second call is an informed one -- which is the whole shape the
         * owner asked for, expressed in two round trips instead of a dialog.
         *
         * THIS IS A BREAKING CHANGE FOR ANY CLIENT THAT DELETED IN ONE CALL, and it is documented
         * as one rather than assumed away. Measured: nothing in the Flutter admin app at
         * /home/user/StoreSyria_app98 calls `deletevariant` (a case-insensitive grep over `lib/`
         * finds no occurrence), and the Contract suite asserts nothing about it. The parity
         * document records the new parameter for any other client.
         *
         * THE BLOCKS DO NOT DEPEND ON IT: `refuse()` applies every reference check before it looks
         * at `$confirmed`, so a correct token still cannot carry a delete past a hard block.
         *
         * THE REFUSAL STAYS INSIDE THE ENVELOPE. `error()` answers HTTP 200 with `{status: 0, ...}`
         * and the real text under `data.message`; a 401 is never used, because the shipped Flutter
         * app forces a logout on any 401 and a new one on a path a client calls is a user-visible
         * regression. Measured: nothing in the Flutter admin app at /home/user/StoreSyria_app98
         * calls `deletevariant` (a case-insensitive grep over `lib/` finds no occurrence), so no
         * client loses an operation it was performing -- but the envelope is kept regardless,
         * because this endpoint is public API surface whether or not our own client uses it.
         */
        $ProductVariant = ProductVariant::withoutGlobalScopes()
            ->whereKey($request->variant_id)
            ->first();

        $ssProduct = \App\Services\VariantRemovalPolicy::parentProduct($ProductVariant);

        if (
            empty($ProductVariant)
            || $ssProduct === null
            || (int) $ssProduct->store_id !== (int) $store->id
        ) {
            return $this->error(['message' => __('Product Variant data not found.')]);
        }

        $ssDenied = \App\Services\VariantRemovalPolicy::authorize(\Auth::user(), $ssProduct);

        if ($ssDenied !== null) {
            return $this->error(['message' => $ssDenied['msg']]);
        }

        $ssApiConsent = $request->input('confirm_variant');

        /* The same comparison as the trash door, from the same place. */
        $ssApiConfirmed = \App\Services\VariantRemovalPolicy::consentSatisfied($ssApiConsent, $ProductVariant);

        $ssRefusal = \App\Services\VariantRemovalPolicy::refuse(
            $ssProduct,
            collect([$ProductVariant]),
            $ssApiConfirmed,
            ['key' => 'confirm_variant', 'value' => 'variant_name']
        );

        if ($ssRefusal !== null) {
            \Illuminate\Support\Facades\Log::warning(
                'api variant delete refused: the variant is still referenced',
                [
                    'variant_id' => $ProductVariant->id,
                    'product_id' => $ProductVariant->product_id,
                    'blocked' => $ssRefusal['ss_variant_removal']['blocked'] ?? null,
                    'user_id' => optional(\Auth::user())->id,
                ]
            );

            return $this->error([
                'message' => $ssRefusal['msg'],
                'ss_variant_removal' => $ssRefusal['ss_variant_removal'] ?? null,
            ]);
        }

        $ProductVariant->delete();

        return $this->success(['message' => __('Product Variant deleted successfully!.')]);
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

    /*
     * GAP-142 / P4-F106. THE PERMISSION AXIS, WHICH THIS ROUTE HAD NONE OF.
     *
     * `resolveOwnedStore()` above settles TENANCY -- a foreign store id resolves nothing -- and
     * the order lookup below is scoped by `store_id`. Neither says anything about WHO INSIDE THE
     * STORE may read an order, and nothing else did either: any authenticated member of the store
     * could read the whole order, its billing details and every internal order note while holding
     * no order permission of any kind. That is the merchant's own employee-level access control
     * over customer PII.
     *
     * THROUGH `MerchantPermissions`, NOT `isAbleTo()`, and the ordering is the reason: `isAbleTo()`
     * alone REFUSES the platform operator -- there is no super-admin bypass here and operator
     * status is a string on `users.type` -- so a gate built on it would lock out the one principal
     * who may do everything, on a store `resolveOwnedStore()` has already admitted them to.
     * `MerchantPermissions` answers `WILDCARD` for the operator and scopes a staff member's names
     * to THIS store, which `allPermissions()` does not.
     *
     * THE OWNER LIMB IS NOT REDUNDANT. A merchant does not implicitly hold the order permissions,
     * and the known-outcome gate caught a gate in this family refusing the store's own owner once
     * already; `DeleteOrder` carries the same two limbs for the same reason.
     *
     * THE NAMES ARE THE SINGULAR FAMILY, and the reason is not the one I first wrote. `Utility`
     * seeds a singular merchant family (`Manage Order`, beside Coupon and Newsletter) and a plural
     * platform one (`Manage Orders`, beside Manage Dashboard). I claimed a merchant cannot grant
     * the plural names; independent review refuted that -- both become real `Permission` rows and
     * `RoleController::create` lists `Permission::all()`, so a merchant can tick either. The true
     * and stronger fact is that **NO GATE IN THE CODEBASE READS THE PLURAL NAMES**: they appear
     * nowhere in `app/` except the two seeding arrays, while every order surface checks the
     * singular ones (`OrderController:916`, `:577`, `MerchantDashboardAbilities::PERMISSION_ORDERS`,
     * whose docblock warns against "correcting" it to the plural).
     *
     * BOTH SINGULAR NAMES ADMIT, AND THAT IS NOT AN OVER-ADMISSION ON ANY REACHABLE PATH -- also a
     * correction. The web's single-order view gates on `Show Order` alone, which made this look
     * wider than the web. But this route's own API siblings -- the order LIST at `:1639` and
     * `Top_orders` at `:7920` -- already admit on EITHER name, and the list is the only way the
     * shipped app reaches a detail view. So `Manage Order` admits nobody who cannot already read
     * that order's `final_price`, `payment_status` and customer name in the list row. Naming both
     * makes this agree with its siblings rather than diverge from the web.
     *
     * ONE ASYMMETRY, RECORDED RATHER THAN RESOLVED: the sibling list uses
     * `refuseWithoutPermission()`, which has NO owner limb -- so a merchant who edited their own
     * role to drop both names could open an order here and not list it. This gate keeps the owner
     * limb because refusing a store's own owner is the regression this family has already produced
     * (the register records `DeleteOrder` breaking that way), and fail-open for the legitimate owner
     * is the right direction for a read they own. Normalising the two belongs with the remaining
     * eight rows of GAP-142, not here.
     *
     * 403, NEVER 401, and NEVER `$this->error()`: the shipped Flutter app force-clears its session
     * on any 401, and `ApiResponser::error()` answers HTTP 200 by default -- a refusal no client
     * distinguishes from success. `orderPermissionDenied()` is the shape `DeleteOrder` and
     * `createOrderViaApi` already use.
     */
    if (! $this->callerIsStoreOwner($store->id)
        && ! MerchantPermissions::allowsAny(\Auth::user(), ['Show Order', 'Manage Order'], $store->id)) {
        return $this->orderPermissionDenied();
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
    /*
     * F206 [2026-10-30] -- THE PLATFORM OPERATOR DOES NOT WRITE A MERCHANT'S CATALOGUE.
     *
     * This door had **no permission check of any kind**. Its only control was
     * `resolveOwnedStore()`, and that drops its `created_by` predicate for the operator -- so the
     * operator reached it through the tenancy exemption rather than through any permission, and a
     * fix scoped to `MerchantPermissions` call sites would have missed it entirely.
     *
     * `error()` is HTTP **200** with `status: 0`, which is this API's refusal convention. A new
     * 401 here would force a logout in the shipped Flutter app, and a 403 is reserved for
     * `refuseWithoutPermission()`'s permission refusals -- this is neither; it is a capability the
     * owner has withdrawn from one principal.
     */
    if (\App\Services\OperatorCatalogueWrite::refuses(\Auth::user())) {
        return $this->error(['message' => \App\Services\OperatorCatalogueWrite::message()]);
    }

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

    /*
     * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY DESTROY A PRODUCT.
     *
     * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
     * held by a staff member of the owning store holding NO permission at all, and the product was
     * destroyed -- the row, its `product_images`, every `carts` and `wishlists` line referencing it,
     * and its image FILES on disk. There is no soft delete and no audit row on this path. The only
     * controls were `OperatorCatalogueWrite::refuses()` (which withdraws this door from the PLATFORM
     * OPERATOR -- the opposite axis) and tenancy, and tenancy admits every employee of the merchant
     * by design: `creatorId()` resolves a staff row to their employer.
     *
     * AND ITS ADD AND UPDATE TWINS IN THIS FILE WERE ALREADY GATED (`Create Product`, `Edit
     * Products`), so the irreversible delete was the one product door with no permission axis.
     *
     * THE NAME IS THE WEB TWIN'S OWN, read from `ProductController::destroy()`, and is seeded by
     * `Utility.php` -- both as a row and onto the global `admin` role every self-registered merchant
     * holds -- so a merchant can really grant it and no production merchant loses the door. A
     * permission name invented here would be a business rule invented by engineering, and one that
     * no row exists for is a permanent 403.
     *
     * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and BELOW
     * `OperatorCatalogueWrite::refuses()`, so the operator keeps that owner decision's own answer
     * rather than being told they lack a permission. Through `callerMayActInStore()` rather than
     * `isAbleTo()`: its owner limb keeps the store's own owner admitted (a merchant holds no
     * permission implicitly, and refusing the owner is the regression this family has already
     * produced), and its `MerchantPermissions` limb answers the platform operator the wildcard,
     * which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
     */
    if (! $this->callerMayActInStore(['Delete Products'], $store->id)) {
        return $this->storePermissionDenied();
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
    /*
     * F206 [2026-10-30] -- THE PLATFORM OPERATOR DOES NOT WRITE A MERCHANT'S CATALOGUE.
     *
     * This door had **no permission check of any kind**. Its only control was
     * `resolveOwnedStore()`, and that drops its `created_by` predicate for the operator -- so the
     * operator reached it through the tenancy exemption rather than through any permission, and a
     * fix scoped to `MerchantPermissions` call sites would have missed it entirely.
     *
     * `error()` is HTTP **200** with `status: 0`, which is this API's refusal convention. A new
     * 401 here would force a logout in the shipped Flutter app, and a 403 is reserved for
     * `refuseWithoutPermission()`'s permission refusals -- this is neither; it is a capability the
     * owner has withdrawn from one principal.
     */
    if (\App\Services\OperatorCatalogueWrite::refuses(\Auth::user())) {
        return $this->error(['message' => \App\Services\OperatorCatalogueWrite::message()]);
    }

    $store = $this->resolveOwnedStore($request->store_id);
    if (empty($store)) {
        return $this->error(['message' => __('Store not found!')]);
    }

    /*
     * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY PUBLISH A PRICED PRODUCT.
     *
     * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
     * held by a staff member of the owning store holding NO permission at all, and they published a
     * sellable product, setting its own `price` and `product_stock`. `products.price` is what a
     * shopper is charged. The only control was tenancy, and tenancy admits every employee of the
     * merchant by design -- `creatorId()` resolves a staff row to their employer.
     *
     * THE NAME IS THE WEB TWIN'S OWN, read from `ProductController::store()`, and is seeded by
     * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
     * business rule invented by engineering, and one that no row exists for is a permanent 403.
     *
     * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
     * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
     * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
     * this family has already produced), and its `MerchantPermissions` limb answers the platform
     * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
     *
     * BELOW the F206 operator refusal as well, so the platform operator keeps
     * `OperatorCatalogueWrite`'s own answer on this door -- that was a decision and must not be
     * reworded into a permission refusal. The sibling `UpdateProduct` took `Edit Products` the same
     * way and its own note said this door "needs its own measurement, not an assumption"; this is that
     * measurement.
     */
    if (! $this->callerMayActInStore(['Create Product'], $store->id)) {
        return $this->storePermissionDenied();
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
        $Product->shipping_id = \App\Support\Commerce\ShippingClassOwnership::keepOwned($request->shipping_id ?? null, $store->id, null);
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
    /*
     * F206 [2026-10-30] -- THE PLATFORM OPERATOR DOES NOT WRITE A MERCHANT'S CATALOGUE.
     *
     * This door had **no permission check of any kind**. Its only control was
     * `resolveOwnedStore()`, and that drops its `created_by` predicate for the operator -- so the
     * operator reached it through the tenancy exemption rather than through any permission, and a
     * fix scoped to `MerchantPermissions` call sites would have missed it entirely.
     *
     * `error()` is HTTP **200** with `status: 0`, which is this API's refusal convention. A new
     * 401 here would force a logout in the shipped Flutter app, and a 403 is reserved for
     * `refuseWithoutPermission()`'s permission refusals -- this is neither; it is a capability the
     * owner has withdrawn from one principal.
     */
    if (\App\Services\OperatorCatalogueWrite::refuses(\Auth::user())) {
        return $this->error(['message' => \App\Services\OperatorCatalogueWrite::message()]);
    }

    $store = $this->resolveOwnedStore($request->store_id);
    if (empty($store)) {
        return $this->error(['message' => __('Store not found!')]);
    }

    /*
     * GAP-250 / P4-F119 — AND THEN WHETHER THIS PRINCIPAL MAY WRITE THE CATALOGUE AT ALL.
     *
     * Measured by the behavioural route census of 2026-11-25: an unpermissioned staff member of the
     * owning store rewrote `price` 100 -> 1, `sale_price` 90 -> 1, **`cost_price` 40 -> 1** and
     * `product_stock` 50 -> 0, and got `{status:1}` "Product Updated Successfully!". A money write
     * by a principal granted nothing.
     *
     * NEITHER CONTROL ABOVE IS THIS ONE, which is why it survived both. `OperatorCatalogueWrite`
     * withdraws the door from the platform OPERATOR (F206) -- the opposite axis, and its own comment
     * records that this door "had no permission check of any kind". `resolveOwnedStore()` settles
     * TENANCY and admits the owner's staff by design.
     *
     * THE SHARPEST FACT: `searchProductsByName`, one route over, was gated on `Manage Products` the
     * same day PRECISELY BECAUSE IT READS `cost_price` (GAP-142 / P4-F111). The route that WRITES
     * `cost_price` was not in that work, because it came from a list of nine named families rather
     * than a measurement of the population. This row is the cost of that difference.
     *
     * `Edit Products` is the PLURAL seeded name the web twin uses (`ProductController:1646`); the
     * singular `Edit Product` is seeded nowhere and would admit nobody a merchant can grant.
     *
     * BELOW both existing controls, so the operator keeps `OperatorCatalogueWrite`'s own refusal
     * (F206 was a decision and must not be reworded into a permission refusal) and a foreign tenant
     * keeps `Store not found!`. Both are pinned by `AdminApiProductWritePermissionTest`.
     *
     * NOTE FOR WHOEVER TAKES `AddProduct`: it carries the IDENTICAL two-control block, which is why
     * an anchor on that block alone matched twice and this insertion is rooted at the handler
     * declaration instead. AddProduct was not in this census group and is NOT gated here -- it
     * needs its own measurement, not an assumption.
     */
    if (! $this->callerMayActInStore(['Edit Products'], $store->id)) {
        return $this->storePermissionDenied();
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
    $product->shipping_id = \App\Support\Commerce\ShippingClassOwnership::keepOwned($request->shipping_id ?? null, $store->id, $product->shipping_id);
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

        /*
         * P1-SEC-46 -- `cost_price` IS THE STORE'S MARGIN AND NEEDS THE SAME GATE ITS SIBLING HAS.
         *
         * `ProductList` serves this field behind `refuseWithoutPermission(['Manage Products'])`,
         * and its own comment says why: "the store's margin, not just its catalogue". `ViewProduct`
         * served it with NO permission check anywhere in the method. Tenant isolation held -- the
         * query carries `->where('store_id', $store->id)` -- but the intra-tenant gate did not, so
         * any token-holding principal in the store read it, including staff that `ProductList`
         * refuses.
         *
         * It cost nothing until GATE F: `products.cost_price` was declared by no migration, so
         * `?? 0` served `0.00` to everybody. The column arriving is what made the asymmetry matter.
         *
         * THE FIELD IS OMITTED RATHER THAN THE REQUEST REFUSED, deliberately. Adding a 403 here
         * would change who may call `ViewProduct` at all, and the shipped Flutter app calls it --
         * CLAUDE.md requires its contract to be preserved. Dropping one key narrows the disclosure
         * without narrowing access, and the client reads `cost_price` with a null-safe default.
         */
        if ($this->refuseWithoutPermission(['Manage Products'], $store->id) === null) {
            $productArray['cost_price'] = $formatNumber($productArray['cost_price'] ?? 0);
        } else {
            unset($productArray['cost_price']);
        }

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
        /*
         * P4-F107 -- AND WHETHER THIS PRINCIPAL MAY DELETE AT ALL.
         * =========================================================================================
         *
         * The TENANT boundary above was repaired earlier and the comment records it. What this
         * handler never had is a PERMISSION check, so any staff member of the merchant's own
         * tenant -- holding a role with no permissions whatsoever -- could irreversibly destroy an
         * order and its tax, coupon and billing rows by sending one integer. Measured through the
         * real route: HTTP 200, `{"status":1,...,"Order deleted successfully!."}`, four rows gone.
         * There is no soft delete and no audit row on this path.
         *
         * `MerchantPermissions::allowsAny()` AND NOT `isAbleTo()`. Laratrust runs here with
         * `permissions_as_gates => false` and there is no super-admin bypass, so
         * `isAbleTo('Delete Order')` REFUSES the platform operator, who holds no roles.
         * `allowsAny()` tests operator status first and returns the wildcard --
         * `AdminApiOrderDeletePermissionTest::test_the_operator_is_not_refused()` is what holds
         * that, and it is the case the naive spelling fails.
         *
         * `$store->id` AND NOT `current_store`, for the reason `MerchantPermissions::names()`
         * states: this handler serves whichever store `?store_id=` names, and `resolveOwnedStore()`
         * admits any store in the caller's tenant, so a gate keyed on `current_store` would weigh
         * store A's grants against store B's rows.
         *
         * BELOW THE TENANT CHECK AND ABOVE THE ORDER LOOKUP. Below, because a principal with no
         * business in this store should not learn whether it exists from a permission message;
         * above, because the lookup is the step before the destructive one and nothing may run
         * between a refusal and it.
         *
         * 403, NEVER 401. `ApiResponser::error()` answers HTTP 200 by default and the shipped
         * Flutter app force-clears its session on any 401, so a 401 here logs merchants out of the
         * whole app. `response()->json(..., 403)` rather than `$this->error()` for that reason,
         * matching `createOrderViaApi`'s gate in this same file.
         *
         * WHAT THIS COSTS, stated rather than discovered in production: the shipped app calls this
         * route and a merchant OWNER does not implicitly hold `Delete Order`, so a merchant whose
         * role lacks it is now refused. That is the same trade the web side's S2 records, and it is
         * the right direction -- an un-permissioned irreversible delete is the worse failure.
         */
        if (! $this->callerIsStoreOwner($store->id)
            && ! MerchantPermissions::allowsAny(\Auth::user(), ['Delete Order'], $store->id)) {
            return $this->orderPermissionDenied();
        }

        $order = Order::where('id', $request->order_id)
            ->where('store_id', $store->id)
            ->first();

        if (!$order) {
            return $this->error(['message' => __('Order not found.')]);
        }

        /*
         * ONE TRANSACTION. The four deletes were four autocommitted statements, so a throw
         * part-way -- a failing observer, a lock timeout, a lost connection -- left the order's
         * tax or coupon rows destroyed while the order itself survived, pointing at money records
         * that are no longer there. Nothing on this path could put them back.
         */
        \Illuminate\Support\Facades\DB::transaction(function () use ($order) {
            OrderTaxDetail::where('order_id', $order->id)->delete();
            OrderCouponDetail::where('order_id', $order->id)->delete();
            OrderBillingDetail::where('order_id', $order->id)->delete();
            $order->delete();
        });

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
            /*
             * GAP-142 / P4-F112. THE PERMISSION AXIS, AND IT IS THE ORDER LIST'S DATA.
             *
             * The line below is `->get()->toArray()` with no column list: every column of every
             * matching order -- `final_price`, `payment_status`, `payment_type`, `customer_id`,
             * `deliveryboy_id`, the whole `product_json`. `OrderList` resolves the same store the
             * same way and then calls `refuseWithoutPermission(['Manage Order', 'Show Order'], ...)`
             * with the comment "final_price is set on every row of the paginator below". THIS route
             * had nothing, so a staff member refused by the list could read the same figures here.
             *
             * AND `name` IS NOT REQUIRED -- only `type` is -- so `like '%%'` matched EVERY order in
             * the store. An ungated caller did not even need to know a reference.
             *
             * THE SAME TWO NAMES AS THE LIST, deliberately: a narrower set would mean a staff
             * member could list orders and not search them, and a wider one would admit somebody
             * the list already refuses.
             *
             * INSIDE THIS BRANCH AND NOT AT THE TOP OF THE HANDLER, which is the whole point of its
             * placement. `type == 'category'` below answers category rows; gating the whole handler
             * on the ORDER permissions would refuse a category search to a staff member holding
             * category rights and no order rights -- a NEW refusal of a working capability rather
             * than the hole this row was asked to close. Gating the category branch on category
             * permissions is a separate decision GAP-142 did not assign here, so it is left as it
             * is and `AdminApiSearchDataPermissionTest::test_the_category_branch_is_untouched` pins
             * the asymmetry as deliberate.
             *
             * `$store->id`, not `$request->store_id`: the grant is evaluated against the store that
             * was authorised. (The query below still reads the request value, which
             * `resolveOwnedStore()` has already matched against `stores.id`; that is pre-existing
             * and out of this row's scope.)
             */
            if (! $this->callerMayActInStore(['Manage Order', 'Show Order'], $store->id)) {
                return $this->storePermissionDenied();
            }

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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY PUBLISH A REVIEW.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they published a
         * live 5-star review ATTRIBUTED TO A NAMED SHOPPER of that store -- `user_id` is taken straight
         * from request input -- and `Testimonial::AvregeRating()` folded it into the product's displayed
         * rating. The only control was tenancy, and tenancy admits every employee of the merchant by
         * design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `TestimonialController::store()`, the same
         * `Testimonial` model, and is seeded by `Utility.php` so a merchant can really grant it. A
         * permission name invented here would be a business rule invented by engineering, and one that no
         * row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         *
         * THE OTHER HALF OF THAT FINDING IS NOT CLOSED HERE: `user_id` still carries no `exists:` rule and
         * no store predicate, so a permitted caller can still attribute a review to another tenant's
         * customer id or to none at all. That is a validation question with its own disposition; a
         * permission gate is not a fix for it and must not be reported as one.
         */
        if (! $this->callerMayActInStore(['Create Testimonial'], $store->id)) {
            return $this->storePermissionDenied();
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
/*
 * GAP-242 / P4-F115 — THIS HANDLER CARRIED NO PREDICATE OF ANY KIND, so `DELETE
 * api/admin/deletereview/N` destroyed review N for ANY bearer token: a shopper's, a delivery
 * driver's, or a competing merchant's. `custom.auth` is not a role check (it logs a token's owner
 * into the guard matching the TOKENABLE CLASS, `CustomAuth:112-120`), the `admin` URL prefix is not
 * an authorization boundary, `AdminApiLog` only logs, and `Testimonial` is NOT in
 * `config/tenancy.php`'s `scoped_models`, so the global scope adds no predicate for any principal.
 * The bare `Testimonial::find($id)` was the only control and it is not one.
 *
 * THE PREDICATE RUNS FROM THE REVIEW TO THE CALLER, not from request input — and that direction is
 * forced by the shipped app, which sends no `store_id` and no body at all
 * (`StoreSyria_app98/lib/config/repository/review_repository.dart:29-33`). Copying the sibling
 * shape (`resolveOwnedStore($request->store_id)`, used by `UpdateReview` at :4098 and `AddReview`)
 * would refuse every real delete in production. So: load the review, take ITS `store_id`, and ask
 * `resolveOwnedStore()` whether this caller owns that store. Same authorization outcome, no client
 * change. `resolveOwnedStore()` derives the principal from `\Auth::user()` and carries the operator
 * limb, so the platform operator is admitted and a review with a null `store_id` is refused.
 *
 * THE PERMISSION AXIS WAS DEFERRED HERE ON A REASON THAT HAS SINCE EXPIRED, AND IS NOW CLOSED.
 * GAP-242 recorded "neither sibling has one, so adding one to the deleter alone would invent a rule
 * for one handler of three". Both siblings have one now: `AddReview` on `Create Testimonial` and
 * `UpdateReview` on `Edit Testimonial`, so the deleter had become the only review door with no
 * permission axis -- and it is the irreversible one (`testimonials` has no soft delete). The gate is
 * below, on `Delete Testimonial`, read from `TestimonialController::destroy()`.
 *
 * Tenancy is pinned by `AdminApiReviewDeletionTenancyTest` (6 cases: shopper, driver, foreign
 * merchant, own merchant WITHOUT a store_id, operator, unknown id); the permission axis by
 * `AdminApiCatalogueDeletePermissionTest`.
 */
public function DeleteReview($id)
{
    $testimonial = Testimonial::find($id);

    /*
     * BOTH REFUSALS ARE HTTP **200**, NOT 404, AND THAT IS DELIBERATE RATHER THAN A BUG -- recorded
     * because the `404` here reads as a status and is not one. `ApiResponser::error()` is
     * `error($data = [], $message = 'fail', int $code = 200, ...)`, so this `404` lands in
     * `$message` and the wire carries `{"status":0,"message":404,...}` with a 200. Left as it is:
     * it is byte-identical to the sibling `UpdateReview:4130`, a 401 is the one answer that would
     * be harmful (the shipped app force-clears its session on one), and the delete does not happen
     * either way. Flagged by the independent review of this slice.
     *
     * THE TWO BODIES ARE IDENTICAL ON PURPOSE: "no such review" and "someone else's review" must
     * not be distinguishable, or the endpoint becomes an existence oracle across tenants.
     */
    if (! $testimonial) {
        return $this->error(['message' => __('Review not found.')], 404);
    }

    $store = $this->resolveOwnedStore($testimonial->store_id);

    if (empty($store)) {
        return $this->error(['message' => __('Review not found.')], 404);
    }

    /*
     * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY DESTROY A REVIEW.
     *
     * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
     * held by a staff member of the owning store holding NO permission at all, and the public review
     * was destroyed. The only control was the tenancy predicate above, and tenancy admits every
     * employee of the merchant by design -- `creatorId()` resolves a staff row to their employer.
     *
     * THE GRANT IS EVALUATED AGAINST THE REVIEW'S OWN STORE, not against a store_id from the request
     * -- there is none to read, and that is the point of the predicate above. `$store` is the store
     * whose review is about to be destroyed, so a staff member permitted in one of the merchant's
     * stores cannot act on another's rows through this door.
     *
     * BELOW the tenancy control, so a shopper, a driver, another tenant's merchant and a review with
     * a null `store_id` all keep the `Review not found.` answer they already got -- the same body as
     * "no such review", deliberately, so this endpoint is not an existence oracle across tenants.
     * Through `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own
     * owner admitted (a merchant holds no permission implicitly, and refusing the owner is the
     * regression this family has already produced), and its `MerchantPermissions` limb answers the
     * platform operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin
     * bypass here.
     */
    if (! $this->callerMayActInStore(['Delete Testimonial'], $store->id)) {
        return $this->storePermissionDenied();
    }

    $testimonial->delete();

    return $this->success(['message' => __('Review deleted successfully!.')]);
}


    public function UpdateReview(Request $request)
{
    $store = $this->resolveOwnedStore($request->store_id);
    if (empty($store)) {
        return $this->error(['message' => __('Store not found!')]);
    }

    /*
     * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY REWRITE A REVIEW.
     *
     * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
     * held by a staff member of the owning store holding NO permission at all, and they rewrote the
     * public review text; the write also covers `rating_no`, `status`, `user_id`, `category_id` and
     * `product_id`, and recomputes the product's average rating. The only control was tenancy, and
     * tenancy admits every employee of the merchant by design -- `creatorId()` resolves a staff row to
     * their employer.
     *
     * THE NAME IS THE WEB TWIN'S OWN, read from `TestimonialController::update()`, and is seeded by
     * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
     * business rule invented by engineering, and one that no row exists for is a permanent 403.
     *
     * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
     * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
     * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
     * this family has already produced), and its `MerchantPermissions` limb answers the platform
     * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
     */
    if (! $this->callerMayActInStore(['Edit Testimonial'], $store->id)) {
        return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY PUBLISH OR UNPUBLISH A
         * REVIEW.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and the toggle landed.
         * The only control was tenancy, and tenancy admits every employee of the merchant by design --
         * `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `TestimonialController::update()`, which is where the
         * web writes `$Testimonial->status`; this controller has no separate status screen, so the
         * editor's name is the twin, and is seeded by `Utility.php` so a merchant can really grant it. A
         * permission name invented here would be a business rule invented by engineering, and one that no
         * row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Edit Testimonial'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ A CATEGORY'S
         * PRODUCTS.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they were served
         * every product column, `cost_price` -- the merchant's margin -- included: the handler answers the
         * model's own array and `App\Models\Product` declares no hidden attributes. The only control was
         * tenancy, and tenancy admits every employee of the merchant by design -- `creatorId()` resolves a
         * staff row to their employer.
         *
         * THE NAME IS THE ONE ITS OWN SIBLING ALREADY CARRIES. `searchProductsByName`, one route over, is
         * gated on `Manage Products` PRECISELY because it serves `cost_price`, and the web catalogue
         * screen (`ProductController::index()`) uses the same name. This door serves the same column and
         * was missed.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Manage Products'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ A PRODUCT'S REVIEWS.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they were served
         * the list. The only control was tenancy, and tenancy admits every employee of the merchant by
         * design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `TestimonialController::index()`, the web review LIST
         * screen, and is seeded by `Utility.php` so a merchant can really grant it. A permission name
         * invented here would be a business rule invented by engineering, and one that no row exists for
         * is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Manage Testimonial'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * GAP-251 / P4-F120 — A COLLEAGUE PANEL-ACCOUNT TAKEOVER VECTOR.
         *
         * Measured: an unpermissioned staff member rewrote another panel user's `users.email` and
         * `name`. That e-mail is what `adminlogin` authenticates on and where the web password reset
         * sends, so this points a colleague's login at an attacker-controlled address.
         *
         * BOUNDED, and the bound is measured rather than assumed: the reach is every panel user the
         * MERCHANT created, and the store OWNER is NOT reachable — their `created_by` is the
         * operator, not themselves, so `resolveManageableUser()` refuses them. That is the
         * difference between this and an account takeover of the tenant.
         *
         * `Edit User` is the seeded name, and WHICH web action it belongs to is the whole point of
         * the self exception below.
         *
         * THE SELF EXCEPTION WAS MISSING FROM THIS DOOR AND THE INDEPENDENT REVIEW CAUGHT IT —
         * THE FOURTH INSTANCE OF A REGRESSION THIS BATCH HAD ALREADY PRODUCED TWICE.
         *
         * `UpdatePassword` and all three driver doors got the exemption; this one did not, so an
         * ordinary panel staff member could no longer edit their OWN name, e-mail or avatar.
         *
         * THE WEB SPLITS THE TWO ACTIONS AND THIS ENDPOINT CONFLATES THEM, which is why the name
         * alone was the wrong control. `UserController::editprofile()` (`:506`, route
         * `web.php:1165`) takes its subject from `\Auth::guard()->user()` and has **no permission
         * check at all** — it IS the self-service editor. `Edit User` belongs to
         * `UserController::update()` (`:232`), the OTHER-user editor. This API door accepts a
         * `user_id` and therefore serves both, so it needs both rules. Exactly the same split
         * exists for passwords (`UserController::password_update:574` is self-only with
         * `Hash::check` and no permission), which is what the `UpdatePassword` exemption mirrors.
         *
         * So admitting a user to their own row is not a weakening: it is what the web already
         * allows unconditionally, and the permission is required only for someone else's row.
         */
        if (! empty($admin)
            && (int) $admin->id !== (int) \Auth::id()
            && ! $this->callerMayActInStore(['Edit User'], $store->id)) {
            return $this->storePermissionDenied();
        }

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

        /*
         * GAP-251 / P4-F120 — A LATERAL CREDENTIAL WRITE BETWEEN COLLEAGUES, AND THIS ONE REUSES
         * THE WEB'S POLICY RATHER THAN INVENTING A PERMISSION NAME.
         *
         * Measured in all three directions: an unpermissioned staff member COULD set a sibling
         * staff member's password (same tenant root), the store OWNER's row was refused, and a
         * foreign tenant was refused. No permission was consulted anywhere — the only controls were
         * `resolveManageableUser()` (tenancy) and `Hash::check($old_password)`, so the attack is
         * held back only by knowing the colleague's current password. That makes it a real lateral
         * credential write and NOT an account takeover of the tenant, which is why it is reported
         * as the former.
         *
         * THE WEB DOES NOT USE A PERMISSION NAME HERE EITHER. `UserController::updatePassword()`
         * calls `$this->authorize('resetPassword', $user)`, and `UserPolicy::resetPassword()` is a
         * complete control already: operator first, then `Reset Password`, then it refuses an
         * OPERATOR as the target however the `type` row is spelled (a MySQL collation subtlety that
         * cost a real finding), then `sameTenant()`. Porting that is strictly better than choosing a
         * name by analogy, and it means the two surfaces cannot drift.
         *
         * `Gate::forUser()->allows()` rather than `authorize()`, because `authorize()` throws an
         * `AuthorizationException` whose 403 is an HTML error page the shipped Flutter app does not
         * parse. The refusal keeps this API's own envelope.
         *
         * THE SELF EXCEPTION IS NOT A WEAKENING, AND IT WAS A REGRESSION I CAUSED AND CAUGHT.
         *
         * This endpoint serves TWO purposes: a user changing their OWN password, and a privileged
         * user resetting someone else's. The policy alone refuses the first, because
         * `UserPolicy::resetPassword()` requires the `Reset Password` permission after its operator
         * limb -- so the first version of this gate refused an ordinary staff member changing their
         * own password, which the shipped app certainly does. The behavioural census's own pin
         * caught it: its leg (i) drives exactly that and went red.
         *
         * `$admin->id === Auth::id()` admits only the caller's own row, and `Hash::check(
         * $old_password)` below still applies to it -- so self-service needs the current password
         * exactly as before. Nothing else is admitted that the policy would refuse: the lateral
         * write between COLLEAGUES, which is what this row is about, is a different id.
         */
        if (! empty($admin)
            && (int) $admin->id !== (int) \Auth::id()
            && ! \Illuminate\Support\Facades\Gate::forUser(\Auth::user())->allows('resetPassword', $admin)) {
            return $this->storePermissionDenied();
        }

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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ THE LOYALTY
         * SETTINGS.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they were served
         * `loyality_program_enabled` and `reward_point`. The only control was tenancy, and tenancy admits
         * every employee of the merchant by design -- `creatorId()` resolves a staff row to their
         * employer.
         *
         * THE WRITER NEXT DOOR ALREADY ASKS THIS QUESTION, so the gate had been applied per HANDLER rather
         * than per SUBJECT. `LoyalitySetting` (the POST) and its web twin
         * `SettingController::LoyalityProgramSettings()` both go through `StoreSettingsAccess::permits()`;
         * the settings screen that READS these two names (`SettingController::settingForm()`, which
         * renders the `loyality_pro_setting` tab) goes through the same predicate; and both names are in
         * that class's own restricted list, whose comment calls the reward "what a point is worth". The
         * two sibling settings READERS in this controller, `GetEmailSetting` and `GetStripePayment`,
         * already call the helper below -- and the census measured this door admitting the same principal
         * they refuse 403.
         *
         * `permits()` IS OPERATOR-FIRST, THEN THE TENANT OWNER, THEN `Manage Setting`, so neither the
         * platform operator nor the store's own owner is refused. Below the tenancy control, so a foreign
         * tenant keeps the answer it already gets.
         */
        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
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
                // P1-SEC-72: stored in the folder of the store this handler resolved and authorised, not in whichever store
                // the caller's account happens to point at -- the two differ for a merchant with a second store and for the
                // operator, and the row written below belongs to `$store`. A refusal answers in the shape of every other
                // refusal here (this branch could not be reached before the helper checked anything).
                $theme_image = $request->cod_image;
                $image = upload_theme_image($theme_image, 0, 0, $store->id);
                if ($image['status'] == false) {
                    return $this->error(['message' => $image['message']], $image['message']);
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ THE COD
         * PAYMENT CONFIGURATION.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer
         * token held by a staff member of the owning store holding NO permission at all, and the COD
         * configuration came back. The only control was tenancy, and tenancy admits every employee of
         * the merchant by design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE SAME PREDICATE ITS OWN WRITE TWIN ALREADY USES. `CodPayment` -- the handler that WRITES
         * these exact rows -- is gated by `refuseUnlessMayAdministerStoreSettings()`, and so is the
         * READ twin one resource over, `GetStripePayment`. So on this one resource a staff member
         * could read a payment configuration they may not change, while the Stripe equivalent was
         * closed: this is the gap, not a design. `is_cod_enabled`, `cod_info` and `cod_image` are also
         * `StoreSettingsAccess::RESTRICTED_EXACT` names, which is the same classification asking the
         * same question on the write side.
         *
         * `refuseUnlessMayAdministerStoreSettings()` answers 403 and never 401 (the shipped Flutter
         * app force-clears its session on any 401), and `StoreSettingsAccess::permits()` asks the
         * operator FIRST, then the tenant owner, then `Manage Setting` -- the ordering a Laratrust
         * codebase with no super-admin bypass requires.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the `Store not found!` envelope it
         * already gets.
         */
        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
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
                // P1-SEC-72: stored in the folder of the store this handler resolved and authorised, not in whichever store
                // the caller's account happens to point at -- the two differ for a merchant with a second store and for the
                // operator, and the row written below belongs to `$store`. A refusal answers in the shape of every other
                // refusal here (this branch could not be reached before the helper checked anything).
                $theme_image = $request->bank_transfer_image;
                $image = upload_theme_image($theme_image, 0, 0, $store->id);
                if ($image['status'] == false) {
                    return $this->error(['message' => $image['message']], $image['message']);
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ THE STORE'S
         * BANK DETAILS.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer
         * token held by a staff member of the owning store holding NO permission at all, and
         * `bank_transfer` came back -- the free-text account / IBAN block a shopper is told to pay
         * into -- together with the uploaded transfer-slip path. The only control was tenancy, and
         * tenancy admits every employee of the merchant by design.
         *
         * THE SAME PREDICATE ITS OWN WRITE TWIN ALREADY USES. `BankPayment` -- the handler that WRITES
         * these exact rows -- is gated by `refuseUnlessMayAdministerStoreSettings()`, and so is the
         * READ twin one resource over, `GetStripePayment`. `bank_transfer`,
         * `is_bank_transfer_enabled` and `bank_transfer_image` are also
         * `StoreSettingsAccess::RESTRICTED_EXACT` names.
         *
         * `refuseUnlessMayAdministerStoreSettings()` answers 403 and never 401 (the shipped Flutter
         * app force-clears its session on any 401), and `StoreSettingsAccess::permits()` asks the
         * operator FIRST, then the tenant owner, then `Manage Setting` -- the ordering a Laratrust
         * codebase with no super-admin bypass requires.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the `Store not found!` envelope it
         * already gets.
         */
        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
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
                // P1-SEC-72: stored in the folder of the store this handler resolved and authorised, not in whichever store
                // the caller's account happens to point at -- the two differ for a merchant with a second store and for the
                // operator, and the row written below belongs to `$store`. A refusal answers in the shape of every other
                // refusal here (this branch could not be reached before the helper checked anything).
                $theme_image = $request->stripe_image;
                $image = upload_theme_image($theme_image, 0, 0, $store->id);
                if ($image['status'] == false) {
                    return $this->error(['message' => $image['message']], $image['message']);
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
            // P1-SEC-66: the tenant root must be a MERCHANT. This helper carries the tenancy decision for 102 call sites, and `creatorId()` alone is
            // the operator's id for a user rooted at the platform (a demoted merchant, or staff the operator created), which selected the
            // operator's stores. A null root resolves no store; every caller already answers a null store with its 403 envelope, never a 401.
            $root = \App\Policies\UserPolicy::merchantTenantRoot($user);

            if ($root === null) {
                return null;
            }

            $query->where('created_by', $root);
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
            // P1-SEC-66: no merchant tenant root, no owned stores -- an empty set makes every whereIn() using it match nothing (fail closed).
            $root = \App\Policies\UserPolicy::merchantTenantRoot($user);

            if ($root === null) {
                return [];
            }

            $query->where('created_by', $root);
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
    /**
     * IS THE CALLER THE STORE'S OWNER — the tenant root, not merely somebody inside the tenant?
     * =============================================================================================
     *
     * THIS LIMB EXISTS BECAUSE THE FIRST VERSION OF THE P4-F037 AND P4-F107 GATES BROKE A SHIPPED
     * CONTRACT, and two independent tests said so in as many words. `DeliveryAndOrderApiAuthorization
     * Test::test_status_change_still_works_for_the_owning_merchant` was written by an earlier
     * package precisely to catch this, and its failure message is *"The predicate is too tight --
     * that is a defect, not a fix."* `AdminApiTenancyTest::test_delete_order_still_works_for_the
     * _owning_merchant` says the same for the delete route. Both merchants in those fixtures hold
     * NO role, and a permission-only gate refused them.
     *
     * IT IS NOT A PRIVILEGE ESCALATION, which is the question worth asking before adding any
     * bypass. The store's owner is the principal who CREATES the roles and grants the permissions
     * in their own tenant; there is no permission they cannot give themselves through the product.
     * Refusing them is therefore a live breakage with no security gain, and in production it is a
     * breakage for every merchant whose role happens to lack the specific permission.
     *
     * `$store->created_by === $user->id` AND NOT `creatorId()`. `User::creatorId()` returns the
     * OWNER's id for a staff member too, so a `creatorId()` comparison would admit every employee
     * and dissolve the permission gate entirely. The owner's own id is the only spelling that
     * separates the tenant root from the tenant.
     *
     * The platform operator is not handled here: `MerchantPermissions::allowsAny()` already
     * returns the wildcard for them, which is where that decision belongs.
     */
    private function callerIsStoreOwner($storeId): bool
    {
        /* GAP-277: the body moved to App\Support\Orders\OrderStateChangeAuthorization, verbatim, so
           that the two STOREFRONT order doors can ask the same question instead of carrying a second
           copy of it. Everything above still holds and is the account of why this limb exists. */
        return \App\Support\Orders\OrderStateChangeAuthorization::callerOwnsStore($storeId);
    }

    /**
     * P4-F037 — MAY THIS PRINCIPAL CHANGE THIS ORDER'S STATE?
     * =============================================================================================
     *
     * `resolveChangeableOrder()` answers a DIFFERENT question: whose order is it. It is a tenant
     * check and a delivery-driver allowance, and it never asks whether the actor may change an
     * order. So a staff member of the merchant's own tenant, holding a role with no permissions at
     * all, could move any of that tenant's orders to Delivered or CANCEL them — and cancelling is
     * money-bearing, because it routes through `Order::order_status_change()`, which writes
     * `payment_status = 'Unpaid'` and makes `OrderObserver` reverse the merchant's wallet credit,
     * and it returns the goods to the shelf. Measured through both routes: HTTP 200, order
     * cancelled, by a principal with nothing granted.
     *
     * THIS IS A SEPARATE PREDICATE RATHER THAN A CHANGE TO THE RESOLVER, deliberately. The
     * resolver has a third caller that serves a READ, and gating reads is a different decision
     * with its own cost in the shipped app — one the finding does not claim. Folding the
     * permission into the resolver would have made that change invisibly.
     *
     * THE ASSIGNED DRIVER IS NOT GATED. A `DeliveryBoy` is not a Laratrust subject —
     * `MerchantPermissions::names()` returns `[]` for anything that is not a `User`, and
     * `isAbleTo()` on one is a fatal — and carrying the parcel IS the authorization, recorded in
     * `orders.deliveryboy_id`. A gate that required a permission of every caller would refuse
     * every driver on the platform.
     *
     * THE OPERATOR IS TESTED FIRST, by `allowsAny()`, which returns the wildcard for them. This
     * codebase has no super-admin bypass, so `isAbleTo('Manage Order')` REFUSES the operator.
     *
     * `$order->store_id` AND NOT `current_store`: the order names its own store, and that is the
     * store whose grants decide.
     */
    private function callerMayChangeOrderState($order): bool
    {
        /* GAP-277: the three limbs moved to App\Support\Orders\OrderStateChangeAuthorization,
           unchanged, because the two STOREFRONT order doors (Api\ApiController::order_status_change
           and ::product_return) asked no permission at all and are in another class -- a roleless
           staff member cancelled their tenant's order there, reversing the merchant's entitlement
           and putting the goods back on the shelf. A copy would have been how the four doors drift
           apart. Everything above still holds and is the account of each limb. */
        return \App\Support\Orders\OrderStateChangeAuthorization::allows($order);
    }

    /** The refusal every state-changing order endpoint in this controller gives.
     *
     * 403, NEVER 401: `ApiResponser::error()` answers HTTP 200 by default and the shipped Flutter
     * app force-clears its session on any 401, so a 401 here logs merchants out of the whole app.
     * Same shape as `createOrderViaApi`'s gate in this file.
     */
    private function orderPermissionDenied()
    {
        return response()->json([
            'status' => 'error',
            'message' => __('You do not have permission to perform this action.'),
        ], 403);
    }

    /**
     * GAP-142 — MAY THIS PRINCIPAL ACT IN THIS STORE? The two-limb predicate, written ONCE.
     * =============================================================================================
     *
     * WHY A HELPER AND NOT ELEVEN COPIES. GAP-142 adds this gate to eleven handlers across four
     * resources, and the two limbs have to be in a particular order for two separate reasons that
     * are easy to get wrong one site at a time:
     *
     *   THE OWNER LIMB IS FIRST AND IS LOAD-BEARING. A merchant does not implicitly hold any of
     *   these permission names, and `AdminApiTenancyTest::test_assign_order_lets_the_owner_past_the
     *   _gate_and_completes` drives `assignOrder` as a merchant built by `twoTenants()`, which
     *   grants nothing at all — a permission-only gate turns that already-green test red. CLAUDE.md
     *   records this family producing exactly that regression once before. It is not an escalation:
     *   the store's owner is the principal who creates the roles and grants the permissions in
     *   their own tenant, so there is no right they cannot give themselves through the product.
     *
     *   THE OPERATOR IS NOT TESTED HERE, DELIBERATELY. `MerchantPermissions::allowsAny()` already
     *   returns `WILDCARD` for them, which is where that decision belongs — and it must happen
     *   somewhere, because `isAbleTo()` alone REFUSES the platform operator (no super-admin bypass;
     *   operator status is a string on `users.type`). A gate built on `isAbleTo()` would lock out
     *   the one principal who may do everything.
     *
     * `MerchantPermissions` RATHER THAN `isAbleTo()` FOR THE SECOND REASON TOO: `allPermissions()`
     * unions every role the principal holds with no store predicate, so a staff member granted a
     * name in store A would carry it into store B of the same merchant. These handlers key their
     * rows on `resolveOwnedStore($request->store_id)`, which admits ANY store in the caller's
     * tenant by design, so the grant must be evaluated against THAT store — which is why the
     * `$storeId` argument is required rather than defaulted to `current_store`.
     *
     * THIS IS THE PERMISSION AXIS ONLY. Tenancy is settled before any call to this, by
     * `resolveOwnedStore()` and the per-row `store_id` predicates. Neither replaces the other, and
     * `Order`, `Product` and `Store` are not in `config/tenancy.php`'s `scoped_models`, so those
     * explicit predicates are the ONLY tenancy control on the rows involved.
     *
     * @param  list<string>  $permissions  any one of which suffices
     * @param  int|string|null  $storeId  the store whose rows are about to be written
     */
    private function callerMayActInStore(array $permissions, $storeId): bool
    {
        if ($this->callerIsStoreOwner($storeId)) {
            return true;
        }

        return MerchantPermissions::allowsAny(\Auth::user(), $permissions, $storeId);
    }

    /**
     * GAP-142 — the refusal for a non-order resource whose handler has no envelope of its own.
     *
     * 403, NEVER 401, AND NEVER `$this->error()`. `ApiResponser::error()` answers HTTP **200** with
     * `{status: 0}` by default, so a refusal through it is a 200 no client distinguishes from
     * success — and the coupon, tax and shipping handlers refuse everything ELSE that way, which is
     * exactly why the permission refusal must not. A 401 is worse than useless: the shipped Flutter
     * app force-clears its session on any 401, so it would sign a merchant's staff out of the whole
     * app instead of telling them they lack a right.
     *
     * The body is `orderPermissionDenied()`'s, because the shipped app already parses that shape
     * from `DeleteOrder`, `ViewOrder` and `createOrderViaApi`. It is a separate method rather than a
     * reuse so that the name does not say "order" on a coupon route — a comment that is wrong about
     * its own subject sends the next reader to the wrong code.
     */
    private function storePermissionDenied()
    {
        return response()->json([
            'status' => 'error',
            'message' => __('You do not have permission to perform this action.'),
        ], 403);
    }

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
     * The predicate: yourself always; anyone in your tenant if your tenant root is
     * a MERCHANT (created_by = UserPolicy::merchantTenantRoot(), the root
     * resolveOwnedStore and StoreController::changeStore use since P1-SEC-66/67,
     * so staff keep working; for a root that is not a merchant it is null and
     * nobody but yourself resolves); anyone if you are the platform operator.
     * Returns null otherwise, and callers answer
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

        // P1-SEC-67b: `creatorId()` is NOT a tenant for a user whose root is not a merchant. A merchant who demoted
        // their own `type` (PUT /users/{self} with a role they created), or staff the OPERATOR created, resolves to
        // the operator's id -- and every merchant has `created_by` = the operator's id, so the comparison below
        // returned ANY merchant to that principal. merchantTenantRoot() answers null for such a root and the
        // refusal is the same null a foreign target already gets. The operator was admitted above, and the
        // principal's own row above that, so nothing a legitimate caller reaches changes.
        $tenant = \App\Policies\UserPolicy::merchantTenantRoot($actor);

        if ($tenant === null || empty($target->created_by)) {
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ A COUPON'S
         * REDEMPTION LEDGER.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they were served
         * every redemption: which customer redeemed, for how much, on which order. The only control was
         * tenancy, and tenancy admits every employee of the merchant by design -- `creatorId()` resolves a
         * staff row to their employer.
         *
         * BOTH NAMES, BECAUSE THE WEB SPELLS THIS SCREEN WITH TWO. `CouponController::show()` -- the
         * screen that renders exactly this ledger -- asks for `Create Coupon`, and the coupon list screen
         * it is reached from (`CouponController::couponReadRefusal()`, shared by the list and the export)
         * asks for `Manage Coupon`, which is also what the API sibling `CouponList` carries. Naming both
         * admits nobody the web already refuses and refuses nobody the web admits; picking one would have
         * invented a narrowing. The same reasoning `ViewOrder` records for naming `Show Order` and `Manage
         * Order` together.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Manage Coupon', 'Create Coupon'], $store->id)) {
            return $this->storePermissionDenied();
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
        // PlanOrder IS BILLING. Same decision as the `Order` delete above: these are the records
        // of what the merchant paid the platform, and they outlive the store.
        //
        // THIS DOOR WAS MISSED WHEN THAT DECISION WAS APPLIED. The web twin,
        // StoreController::destroy, has carried this exact line commented out with that exact
        // rationale -- and StoreController:994 even points here -- but the API twin kept deleting.
        // It is the more reachable of the two: `DELETE api/admin/deletestore` under `custom.auth`,
        // which the shipped Flutter admin app calls with a `User` Sanctum token. The handler's own
        // predicate (`created_by = $user->id`, :4966) is satisfied by a merchant deleting THEIR
        // OWN store, so a merchant holding more than one store erased their own payment history --
        // order id, price paid, payment status, and the pointer to the bank slip they uploaded as
        // proof of payment.
        //
        // Reinforced by the owner instruction of 2026-11-11: "Treat financial-record deletion as
        // prohibited pending an approved retention and correction policy." BankTransferController
        // ::destroy() refuses every caller for the same reason; this was the live bypass of it.
        // PlanOrder::where('store_id', $store->id)->delete();
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
        // PlanOrder IS BILLING -- commented out for the same reason as in DeleteStore() above,
        // even though nothing routes this method. A dead copy carrying the live line is how the
        // decision came to be applied on one door and not the other in the first place.
        // PlanOrder::where('store_id', $store->id)->delete();
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
        $orderRow = $this->resolveChangeableOrder($request->order_id);

        if (!$orderRow) {
            return $this->error(['message' => __('Order not found!')]);
        }

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ THE ORDER
         * DOCUMENT.
         *
         * MEASURED: an unpermissioned staff member of the owning store was served the customer's
         * name, e-mail and telephone, the billing AND delivery address, `payment_status` and
         * `final_price` -- while the SIBLING `vieworder`, which serves the same document, refused
         * that same principal 403. The resolver above is a tenancy check and a driver allowance; it
         * asks nothing about what the actor may do, and nothing else did either.
         *
         * THE NAMES ARE `vieworder`'s OWN, not a choice made here: `ViewOrder` gates this document
         * on `Show Order` / `Manage Order` with a store-owner limb above them, and its docblock
         * records why both singular names admit (the order LIST and `Top_orders` already admit on
         * either, and the list is how the shipped app reaches a detail view -- so `Manage Order`
         * admits nobody who cannot already read that order's money in a list row). The plural
         * `Manage Orders` is read by no gate in this codebase.
         *
         * THE ASSIGNED DRIVER IS ADMITTED ON THEIR OWN MERITS AND IS NOT ASKED FOR A PERMISSION.
         * This route is declared inside the `//delivery boy` block of `routes/api.php` and is the
         * driver app's order screen; a `DeliveryBoy` is not a Laratrust subject, so
         * `MerchantPermissions` answers `[]` for one and a merchant permission on this door would
         * refuse every driver on the platform -- a failure mode this project has shipped twice. The
         * limb is the same test the resolver above already makes, and
         * `AdminApiPiiAndCatalogueRouteGateTest` pins the driver leg by name.
         *
         * 403 THROUGH `orderPermissionDenied()`, the shape `ViewOrder`, `DeleteOrder` and
         * `createOrderViaApi` already use -- never 401 (the shipped Flutter app force-clears its
         * session on any 401) and never `error()`, which answers HTTP 200. The tenancy refusal above
         * keeps its own `Order not found!` envelope, so "no such order" and "not yours" stay
         * indistinguishable and this door is still not an order-id oracle.
         */
        $driver = auth('deliveryboy')->user();

        if (! ($driver && (int) $orderRow->deliveryboy_id === (int) $driver->id)
            && ! $this->callerMayActInStore(['Show Order', 'Manage Order'], $orderRow->store_id)) {
            return $this->orderPermissionDenied();
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

        /* P4-F037. Whose order it is was already asked; whether this principal may change it was
           not. See callerMayChangeOrderState(). */
        if (! $this->callerMayChangeOrderState($order)) {
            return $this->orderPermissionDenied();
        }

        $data['order_id'] = $order->id;
        $data['order_status'] = $request->delivered;
        $responce = Order::order_status_change($data);

        /*
         * THE GOODS.  P4-F034, the doors P4-4 did not reach -- found by the P4-F124 ledger
         * inventory, which noticed the exact inverse of that finding: this door routes through
         * `Order::order_status_change()`, so it writes `payment_status = 'Unpaid'` and
         * `OrderObserver` reverses the merchant's wallet credit, and then loses the stock.
         * P4-F124 restores the stock and loses the money; this one restored the money and lost
         * the goods.
         *
         * `=== 'success'` is the replay guard: the engine answers `error` for a cancel from any
         * non-pending state (app/Models/Order.php:702), so a second attempt never reaches here.
         * The trait's per-line markers bound it again.
         */
        if (($responce['status'] ?? null) === 'success'
            && strtolower(trim((string) ($data['order_status'] ?? ''))) === 'cancel') {
            $this->restoreCancelledOrderStock(
                $order,
                is_array($cancelLines = json_decode((string) $order->product_json, true)) ? $cancelLines : []
            );
        }

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

        /*
         * GAP-251 / P4-F120 — A DRIVER ACCOUNT-TAKEOVER VECTOR. Measured: an unpermissioned staff member
         * rewrote `delivery_boys.email` and `name`. That e-mail is the identifier
         * `deliveryLogin` authenticates on, so rewriting it points the driver's login at an
         * address the attacker controls. The password hash is NOT written by this handler
         * (measured), so this is a takeover VECTOR rather than a credential write — stated that
         * way so the finding is not overstated.
         *
         * TWO DOORS ONTO ONE ACCOUNT, ONE GATED: the sibling `deliveryboysUpdate/{id}` already
         * requires `Edit Deliveryboy` and `deliveryboysReset-password` requires
         * `Reset password Deliveryboy` (both closed 2026-11-25). This one was missed because it
         * lives on a different controller surface.
         *
         * `resolveManageableDeliveryBoy()` above settles TENANCY (it replaced a bare
         * `DeliveryBoy::find($request->id)` that reached every tenant). It settles nothing about
         * who INSIDE the store may do this, so every employee could.
         *
         * The store comes from the RESOLVED DRIVER, not from request input — the same direction the
         * review deleter uses (GAP-242), and necessary here because this handler never resolves a
         * `$store` of its own. A driver with a null `store_id` resolves no store and is refused.
         *
         * THE DRIVER'S OWN ROW IS EXEMPT, AND THAT IS NOT A WEAKENING — IT IS THE LIMB THIS HELPER
         * EXISTS FOR. `resolveManageableDeliveryBoy()` admits a caller acting on THEMSELVES first
         * (`:5375-5379`, a `DeliveryBoy` on the `deliveryboy` guard), which is how a driver signs
         * themselves out and maintains their own profile from the driver app. A merchant permission
         * cannot be held by a `DeliveryBoy` at all, so the limb below would refuse every driver on
         * their own account. `DeliveryAndOrderApiAuthorizationTest::test_a_driver_can_still_log_
         * themselves_out` names that self-service limb and caught this when the first version of
         * these gates omitted the exemption. The permission is required only when the caller is
         * acting on SOMEONE ELSE.
         */
        if (! empty($deliveryboy)) {
            $self = auth('deliveryboy')->user();
            $actingOnSelf = $self && (int) $self->id === (int) $deliveryboy->id;

            if (! $actingOnSelf) {
                $driverStore = \App\Models\Store::find($deliveryboy->store_id);

                if (empty($driverStore) || ! $this->callerMayActInStore(['Edit Deliveryboy'], $driverStore->id)) {
                    return $this->storePermissionDenied();
                }
            }
        }

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

        /*
         * GAP-251 / P4-F120 — AN AVAILABILITY WRITE: revokes ANY tenant driver's tokens, repeatedly.
         * Measured: an unpermissioned staff member deleted a target driver's
         * `personal_access_tokens` rows, signing them out of the shipped driver app.
         * `$request->user_id` is not even validated as present.
         *
         * `Edit Deliveryboy` IS CHOSEN BY ANALOGY AND THAT IS FLAGGED, because it is the only
         * name here not copied from a web twin: there is no standalone driver-logout action on
         * the web at all. The web revokes a driver's tokens only INSIDE a password reset
         * (`DeliveryBoyController:251`, gated on `Reset password Deliveryboy`). Acting on a
         * driver's account is what `Edit Deliveryboy` governs on the two sibling doors, so it is
         * the closest existing right; if the owner would rather this needed the reset
         * permission, it is a one-word change and the test names the choice.
         *
         * `resolveManageableDeliveryBoy()` above settles TENANCY (it replaced a bare
         * `DeliveryBoy::find($request->id)` that reached every tenant). It settles nothing about
         * who INSIDE the store may do this, so every employee could.
         *
         * The store comes from the RESOLVED DRIVER, not from request input — the same direction the
         * review deleter uses (GAP-242), and necessary here because this handler never resolves a
         * `$store` of its own. A driver with a null `store_id` resolves no store and is refused.
         *
         * THE DRIVER'S OWN ROW IS EXEMPT, AND THAT IS NOT A WEAKENING — IT IS THE LIMB THIS HELPER
         * EXISTS FOR. `resolveManageableDeliveryBoy()` admits a caller acting on THEMSELVES first
         * (`:5375-5379`, a `DeliveryBoy` on the `deliveryboy` guard), which is how a driver signs
         * themselves out and maintains their own profile from the driver app. A merchant permission
         * cannot be held by a `DeliveryBoy` at all, so the limb below would refuse every driver on
         * their own account. `DeliveryAndOrderApiAuthorizationTest::test_a_driver_can_still_log_
         * themselves_out` names that self-service limb and caught this when the first version of
         * these gates omitted the exemption. The permission is required only when the caller is
         * acting on SOMEONE ELSE.
         */
        if (! empty($user)) {
            $self = auth('deliveryboy')->user();
            $actingOnSelf = $self && (int) $self->id === (int) $user->id;

            if (! $actingOnSelf) {
                $driverStore = \App\Models\Store::find($user->store_id);

                if (empty($driverStore) || ! $this->callerMayActInStore(['Edit Deliveryboy'], $driverStore->id)) {
                    return $this->storePermissionDenied();
                }
            }
        }

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

        /*
         * GAP-251 / P4-F120 — IRREVERSIBLE: deletes a `delivery_boys` row. Measured: an unpermissioned
         * staff member deleted a driver account and got `{status:1}` "User Deleted
         * successfully". The sibling `deliveryboysDestroy/{id}` already requires
         * `Delete Deliveryboy` (closed 2026-11-25); this is the same capability on a second,
         * ungated door.
         *
         * `resolveManageableDeliveryBoy()` above settles TENANCY (it replaced a bare
         * `DeliveryBoy::find($request->id)` that reached every tenant). It settles nothing about
         * who INSIDE the store may do this, so every employee could.
         *
         * The store comes from the RESOLVED DRIVER, not from request input — the same direction the
         * review deleter uses (GAP-242), and necessary here because this handler never resolves a
         * `$store` of its own. A driver with a null `store_id` resolves no store and is refused.
         *
         * THE DRIVER'S OWN ROW IS EXEMPT, AND THAT IS NOT A WEAKENING — IT IS THE LIMB THIS HELPER
         * EXISTS FOR. `resolveManageableDeliveryBoy()` admits a caller acting on THEMSELVES first
         * (`:5375-5379`, a `DeliveryBoy` on the `deliveryboy` guard), which is how a driver signs
         * themselves out and maintains their own profile from the driver app. A merchant permission
         * cannot be held by a `DeliveryBoy` at all, so the limb below would refuse every driver on
         * their own account. `DeliveryAndOrderApiAuthorizationTest::test_a_driver_can_still_log_
         * themselves_out` names that self-service limb and caught this when the first version of
         * these gates omitted the exemption. The permission is required only when the caller is
         * acting on SOMEONE ELSE.
         */
        if (! empty($user)) {
            $self = auth('deliveryboy')->user();
            $actingOnSelf = $self && (int) $self->id === (int) $user->id;

            if (! $actingOnSelf) {
                $driverStore = \App\Models\Store::find($user->store_id);

                if (empty($driverStore) || ! $this->callerMayActInStore(['Delete Deliveryboy'], $driverStore->id)) {
                    return $this->storePermissionDenied();
                }
            }
        }

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

        /* P4-F037, and this is the money-bearing half: cancelling writes `payment_status =
           'Unpaid'`, which makes OrderObserver reverse the merchant's wallet credit, and it
           restores the stock. REFUSED BEFORE THE DELIVERY-BOY LOOKUP BELOW, so a refused caller
           triggers no further reads. */
        if ($order && ! $this->callerMayChangeOrderState($order)) {
            return $this->orderPermissionDenied();
        }

        $user = $order ? $this->resolveManageableDeliveryBoy($request->user_id) : null;

        if (!empty($order)) {
            if ($order->delivered_status == '0') {
                /*
                 * THE GOODS.  P4-F034, the third door P4-4 did not reach. This one writes
                 * `delivered_status = 2` DIRECTLY rather than through the engine, so it moves no
                 * money either -- it simply lost the stock. The `== '0'` test above is its replay
                 * guard: a second call finds the order already cancelled and is refused.
                 */
                $cancelLines = json_decode((string) $order->product_json, true);

                if ($user && $order->deliveryboy_id == $user->id) {
                    $order->deliveryboy_id = null; // Removed quotes around null
                    $order->delivered_status = 2;
                    $order->save();
                    $this->restoreCancelledOrderStock($order, is_array($cancelLines) ? $cancelLines : []);
                    /*
                     * P4-F124 -- THE MONEY, on both branches of this handler.
                     *
                     * `orderCancel` writes `delivered_status = 2` directly and, uniquely among the
                     * doors, writes no `cancel_date` either. It is registered under TWO URIs
                     * (api/{slug}/order-cancel and api/admin/order-cancel), so this call serves a
                     * storefront-prefixed route and an admin-prefixed one.
                     */
                    app(\App\Services\OrderEntitlementReversal::class)->reverseOrLog(
                        $order,
                        \App\Services\OrderEntitlementReversal::REASON_CANCELLED,
                        'orderCancel'
                    );
                    return $this->success(['message' => __('Order cancel Successfully.')]);
                } else {
                    $order->delivered_status = 2;
                    $order->save();
                    $this->restoreCancelledOrderStock($order, is_array($cancelLines) ? $cancelLines : []);
                    /*
                     * P4-F124 -- THE MONEY, on both branches of this handler.
                     *
                     * `orderCancel` writes `delivered_status = 2` directly and, uniquely among the
                     * doors, writes no `cancel_date` either. It is registered under TWO URIs
                     * (api/{slug}/order-cancel and api/admin/order-cancel), so this call serves a
                     * storefront-prefixed route and an admin-prefixed one.
                     */
                    app(\App\Services\OrderEntitlementReversal::class)->reverseOrLog(
                        $order,
                        \App\Services\OrderEntitlementReversal::REASON_CANCELLED,
                        'orderCancel'
                    );
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY CREATE A BRAND.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they created one --
         * uploading its logo against the OWNER's plan storage quota. The only control was tenancy, and
         * tenancy admits every employee of the merchant by design -- `creatorId()` resolves a staff row to
         * their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `ProductBrandController::store()`, and is seeded by
         * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
         * business rule invented by engineering, and one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         *
         * THE GRANT IS EVALUATED AGAINST THE STORE THE CALLER NAMED, which is the convention every other
         * gate in this controller follows. This writer stamps the row with the caller's own
         * `current_store`, which for a merchant with two stores need not be that store; naming the
         * requested store is the fail-closed direction and matches the sibling doors.
         */
        if (! $this->callerMayActInStore(['Create Product Brand'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY REWRITE A BRAND.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they renamed it;
         * the write covers `name`, `slug`, `logo` and `status`, and `status` is the column the storefront
         * filters on. The only control was tenancy, and tenancy admits every employee of the merchant by
         * design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `ProductBrandController::update()`, and is seeded by
         * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
         * business rule invented by engineering, and one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Edit Product Brand'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY DESTROY A BRAND.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer
         * token held by a staff member of the owning store holding NO permission at all, and the
         * brand row was destroyed. `product_brands` has no soft delete and this path writes no audit
         * row. The only control was tenancy, and tenancy admits every employee of the merchant by
         * design -- `creatorId()` resolves a staff row to their employer.
         *
         * AND THE NEIGHBOURING DOORS WERE ALREADY GATED: `AddProductBrand` on `Create Product Brand`,
         * `UpdateProductBrand` and both status toggles on `Edit Product Brand`. The irreversible
         * delete was the one brand door with no permission axis.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `ProductBrandController::destroy()`, and is seeded
         * by `Utility.php` -- both as a row and onto the global `admin` role every self-registered
         * merchant holds -- so a merchant can really grant it and no production merchant loses the
         * door. A permission name invented here would be a business rule invented by engineering, and
         * one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and ABOVE
         * the row lookup, so an unpermitted caller cannot use the not-found branch as an existence
         * oracle. Through `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the
         * store's own owner admitted (a merchant holds no permission implicitly, and refusing the
         * owner is the regression this family has already produced), and its `MerchantPermissions`
         * limb answers the platform operator the wildcard, which `isAbleTo()` alone REFUSES -- there
         * is no super-admin bypass here.
         *
         * `$store->id` AND NOT `ownedStoreIds()`, although the lookup below is tenant-wide: brands are
         * stamped with the caller's `current_store` at creation and may legitimately sit in another of
         * the merchant's own stores, so the LOOKUP must stay wide. The GRANT is still evaluated
         * against the store the caller named, which is the store `callerMayActInStore()` documents as
         * the right subject -- a staff member permitted in store A must not act through store B.
         */
        if (! $this->callerMayActInStore(['Delete Product Brand'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY PUBLISH OR UNPUBLISH A
         * BRAND.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and the toggle landed:
         * `product_brands.status` moved. The only control was tenancy, and tenancy admits every employee
         * of the merchant by design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE WEB TWIN OF THIS EXACT TOGGLE IS GATED AND THIS ONE WAS NOT, which is the sharpest fact
         * about this row: `ProductBrandController::resolveOwnedBrand()` -- the shared guard of the web
         * status and popular toggles -- requires `Edit Product Brand`, the name its own `edit()` and
         * `update()` use, and `BrandLabelStatusAuthorizationTest` pins it. Somebody closed one surface.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Edit Product Brand'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY FEATURE OR UNFEATURE A
         * BRAND.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and the toggle landed:
         * `product_brands.is_popular` moved. The only control was tenancy, and tenancy admits every
         * employee of the merchant by design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `ProductBrandController::resolveOwnedBrand()`, the
         * shared guard of the web toggles, and is seeded by `Utility.php` so a merchant can really grant
         * it. A permission name invented here would be a business rule invented by engineering, and one
         * that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Edit Product Brand'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY CREATE A PRODUCT LABEL.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they created one.
         * The only control was tenancy, and tenancy admits every employee of the merchant by design --
         * `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `ProductLabelController::store()`, and is seeded by
         * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
         * business rule invented by engineering, and one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Create Product Label'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY REWRITE A PRODUCT LABEL.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and they renamed it.
         * The only control was tenancy, and tenancy admits every employee of the merchant by design --
         * `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `ProductLabelController::update()`, and is seeded by
         * `Utility.php` so a merchant can really grant it. A permission name invented here would be a
         * business rule invented by engineering, and one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         *
         * A SECOND FINDING ON THIS HANDLER IS NOT CLOSED BY THIS GATE and is left as it was: the write
         * rewrites `store_id` from the caller's `current_store` unconditionally, so a caller whose current
         * store differs MOVES the label between two stores of the same tenant. That is a tenancy-shaped
         * question inside one tenant, not a permission one, and conflating the two would hide it.
         */
        if (! $this->callerMayActInStore(['Edit Product Label'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY DESTROY A LABEL.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer
         * token held by a staff member of the owning store holding NO permission at all, and the
         * product-label row was destroyed. `product_labels` has no soft delete and this path writes no
         * audit row. The only control was tenancy, and tenancy admits every employee of the merchant
         * by design -- `creatorId()` resolves a staff row to their employer.
         *
         * AND THE NEIGHBOURING DOORS WERE ALREADY GATED: `AddProductLable` on `Create Product Label`,
         * `UpdateProductLable` and the status toggle on `Edit Product Label`. The irreversible delete
         * was the one label door with no permission axis.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `ProductLabelController::destroy()`, and is seeded
         * by `Utility.php` -- both as a row and onto the global `admin` role every self-registered
         * merchant holds -- so a merchant can really grant it and no production merchant loses the
         * door. A permission name invented here would be a business rule invented by engineering, and
         * one that no row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and ABOVE
         * the row lookup, so an unpermitted caller cannot use the not-found branch as an existence
         * oracle. Through `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the
         * store's own owner admitted (a merchant holds no permission implicitly, and refusing the
         * owner is the regression this family has already produced), and its `MerchantPermissions`
         * limb answers the platform operator the wildcard, which `isAbleTo()` alone REFUSES -- there
         * is no super-admin bypass here.
         *
         * `$store->id` AND NOT `ownedStoreIds()`, for the reason spelled out on `DeleteProductBrand`:
         * the lookup stays tenant-wide because labels are stamped with `getCurrentStore()`, while the
         * grant is evaluated against the store the caller named.
         */
        if (! $this->callerMayActInStore(['Delete Product Label'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY PUBLISH OR UNPUBLISH A
         * PRODUCT LABEL.
         *
         * MEASURED, not inferred: the behavioural route census drove this door with a real bearer token
         * held by a staff member of the owning store holding NO permission at all, and the toggle landed:
         * `product_labels.status` moved. The only control was tenancy, and tenancy admits every employee
         * of the merchant by design -- `creatorId()` resolves a staff row to their employer.
         *
         * THE NAME IS THE WEB TWIN'S OWN, read from `ProductLabelController::changeStatus()`, the web twin
         * of this exact toggle, and is seeded by `Utility.php` so a merchant can really grant it. A
         * permission name invented here would be a business rule invented by engineering, and one that no
         * row exists for is a permanent 403.
         *
         * BELOW the tenancy control, so a foreign tenant keeps the refusal it already gets, and through
         * `callerMayActInStore()` rather than `isAbleTo()`: its owner limb keeps the store's own owner
         * admitted (a merchant holds no permission implicitly, and refusing the owner is the regression
         * this family has already produced), and its `MerchantPermissions` limb answers the platform
         * operator the wildcard, which `isAbleTo()` alone REFUSES -- there is no super-admin bypass here.
         */
        if (! $this->callerMayActInStore(['Edit Product Label'], $store->id)) {
            return $this->storePermissionDenied();
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


/**
 * GAP-156. THE TWO CUSTOMER LEDGER FIGURES AS THE ADMIN APP RECEIVES THEM.
 *
 * PHASE-05-METRIC-DEFINITIONS.md §15 defines both. They are deliberately NOT one number and
 * never collapsed into one here: collected is the history of money handed over, including
 * amounts later cancelled or refunded; retained is what is left after completed reversals.
 *
 * A NEW KEY RATHER THAN A REPOINTED ONE. `total_spend` and `orders` keep their shipped meaning
 * and their shipped values. The alternative -- quietly changing what `total_spend` returns --
 * would have altered a figure already on merchants' phones with nothing in the payload to say
 * it had changed, which is the mislabelling this whole slice exists to stop.
 *
 * ONE DIVERGENCE IS LEFT OPEN AND IS FLAGGED IN THE PAYLOAD RATHER THAN DECIDED HERE. This
 * endpoint's `orders`/`total_spend`/`aov` attribute orders by `customer_id` ALONE, while the
 * web's `Customer::ownStoreOrders()` -- which these ledger figures follow, so that the figure
 * and the `Total Spend` beside it cover one order set -- falls back to matching unattributed
 * orders on the billing e-mail for a customer who has none of their own. So for such a customer
 * this endpoint can report `orders: 0` beside a non-zero collected figure. That is a real
 * product question (which rule is right), not a correctness one, so `attribution` states which
 * rule produced the figure instead of a guess being made on the owner's behalf.
 *
 * NOT AN AUTHORIZATION BOUNDARY. The caller's right to see this customer is established before
 * this is reached -- `resolveOwnedStore()` on the named store, and a customer query scoped to
 * it. This formats figures for a customer already cleared; it clears nobody.
 *
 * @param  null|array{collected: \App\Support\Metrics\MetricResult, retained: \App\Support\Metrics\MetricResult, attribution: string}  $figures
 * @return array<string, mixed>
 */
private static function customerLedgerPayload(?array $figures, int $storeId): array
{
    if ($figures === null) {
        /* The resolver returned no entry for this customer. Not zero -- unknown. Saying
           "available: false" is the only honest answer, and the app must not print 0.00. */
        return [
            'available' => false,
            'payments_collected' => null,
            'net_retained' => null,
            // THE SAME KEY SET AS THE POPULATED BRANCH. The first version omitted `period`, so a
            // client written against one shape met the other. The branch is unreachable from
            // either call site today -- `forCustomers()` writes an entry for every id it is given
            // and `forCustomer()` never returns null -- which is exactly why a divergence in it
            // would go unnoticed until the day it stops being unreachable.
            'period' => __('all time'),
            'attribution' => 'unresolvable',
            'payments_collected_note' => __('This figure could not be read.'),
            'net_retained_note' => __('This figure could not be read.'),
            'order_value_note' => \App\Support\Customers\CustomerMoneyTiles::spendHint(),
        ];
    }

    $tiles = \App\Support\Customers\CustomerMoneyTiles::fromFigures($figures, $storeId);

    return [
        // `false` whenever EITHER figure had to be withheld -- a client showing one and hiding
        // the other would invite the two to be read as a pair when only half is known.
        'available' => $tiles['collected']['value'] !== null && $tiles['retained']['value'] !== null,
        'payments_collected' => $tiles['collected']['value'],
        'net_retained' => $tiles['retained']['value'],
        'period' => $tiles['collected']['period'],
        'attribution' => $tiles['attribution'],
        'payments_collected_note' => $tiles['collected']['hint'],
        'net_retained_note' => $tiles['retained']['hint'],
        // So the app can caveat the `total_spend` key it already shows, without having to know
        // why that figure is not money.
        'order_value_note' => $tiles['spend_hint'],
    ];
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


        /*
         * GAP-142 / P4-F113 (READ HALF) — THE PERMISSION LIMB, BELOW THE TENANCY GATE.
         *
         * `resolveOwnedStore()` above already refuses a store this caller does not own, so this is
         * not a tenancy fix: it is that EVERY MEMBER OF THE STORE read the whole customer book -- every name, mobile, e-mail,
         * order count, TOTAL SPEND, the ledger block and the default postal address, paginated in
         * one call -- whatever they were
         * granted.
         *
         * `Manage Customer` ALONE, NOT the `Show Customer` pair, AND THAT IS A CORRECTION. The
         * first version admitted either name on the argument that the web admits either "on a
         * customer READ". Measured, that is true of the web's DETAIL screen (`CustomerController::
         * show():335`, gated `:339-341`: operator OR `Show Customer`) and FALSE of its LIST, which
         * requires `Manage Customer` only (`index():312`, gated `:314`). And the difference is not
         * cosmetic: the web puts the MONEY COLUMNS on the list behind that name
         * (`CustomerDataTable:296-297`, `Payments Collected` / `Net Retained`), while the
         * `Show Customer` detail screen is handed `compact('orders')` and shows none. This payload
         * carries `total_spend`, `aov`, the whole `ledger` block and the default postal address, so
         * admitting `Show Customer` here would hand a deliberately-restricted staff member money
         * the web withholds from them. Found by the independent review of this slice.
         *
         * BELOW the tenancy gate, not above it: a caller from another tenant must keep getting the
         * STORE refusal they get today, which shipped suites assert. 403 through
         * `storePermissionDenied()` -- never 401 (the shipped app force-clears its session on one).
         */
        if (! $this->callerMayActInStore(['Manage Customer'], $store->id)) {
            return $this->storePermissionDenied();
        }

        $query = \App\Models\Customer::where('store_id', $storeId);

        $customers = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 10);

        /*
         * GAP-156. THE TWO LEDGER FIGURES FOR THE WHOLE PAGE IN ONE GROUPED QUERY, resolved
         * before the map rather than inside it. `CustomerMoneyFigures::forCustomers()` exists for
         * exactly this shape; the merchant customer LIST cannot use it, because a DataTable row
         * callback never sees its own page, and that is the only reason these two surfaces
         * resolve differently. The DEFINITION is the same object in both, so the figures cannot
         * drift.
         *
         * `$customers` is already scoped to `$storeId`, which `resolveOwnedStore()` above has
         * authorised. `CustomerMoneyFigures` scopes again, to each customer's OWN `store_id`;
         * that is not redundant belt-and-braces but the rule the class states -- the viewer's
         * store is never the tenant it reads.
         */
        $ledgerFigures = \App\Support\Customers\CustomerMoneyFigures::forCustomers($customers);

        $customersData = $customers->map(function ($customer) use ($storeId, $ledgerFigures) {
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
                /*
                 * GAP-156. `total_spend` IS UNCHANGED, DELIBERATELY, AND IT IS NOT A MONEY
                 * FIGURE. It sums `orders.final_price` with no payment predicate: cancelled,
                 * refunded and never-paid orders all count in full. The shipped Flutter app reads
                 * this key and labels it as spend, so repointing it at the ledger would change,
                 * silently, a number already on merchants' screens -- the exact mislabelling the
                 * owner's instruction forbids, committed from the other direction. The two keys
                 * below are the money question, each named for what it is.
                 */
                'total_spend' => number_format($totalSpend, 2),
                'aov' => number_format($aov, 2),
                /*
                 * `$customer->store_id`, NOT `$storeId`, AND THE DIFFERENCE IS UNOBSERVABLE HERE
                 * -- recorded as such rather than chased. The query above is
                 * `Customer::where('store_id', $storeId)`, so every row's own store IS the named
                 * one and a mutation swapping them survives. No fixture can separate the two
                 * without breaking the scoping that
                 * `test_the_list_never_carries_another_tenants_customer` pins, so the two
                 * properties are entailed rather than independently testable.
                 *
                 * It reads the customer's own store anyway, because that is the rule
                 * `CustomerMoneyFigures` states and this value labels a CURRENCY: if the list
                 * query is ever widened, the figures would still be read per customer while the
                 * label silently followed the request. A correct amount under the wrong currency
                 * is the failure this slice exists to fix.
                 */
                'ledger' => self::customerLedgerPayload(
                    $ledgerFigures[(int) $customer->id] ?? null,
                    (int) ($customer->store_id ?? $storeId),
                ),
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

        /*
         * GAP-142 / P4-F113 (write half). THE PERMISSION AXIS, AND THE ASYMMETRY IT CLOSES.
         *
         * `resolveOwnedStore()` above settles TENANCY and the customer lookup below carries a
         * `store_id` predicate. Neither asks who INSIDE the store may act, so `$targetCustomer
         * ->delete()` ran for any authenticated member of the tenant holding nothing at all --
         * against `customers`, the highest-value PII table that is not storefront-public.
         *
         * **THE CREATE SIDE OF THIS SAME RESOURCE WAS ALREADY GATED.** `addCustomer()` checks
         * `MerchantDashboardAbilities::PERMISSION_CUSTOMERS` through `MerchantPermissions`, with its
         * own pinned suite. So until this line one staff member could DELETE a customer they could
         * not CREATE. Measured before the fix, one principal driving both halves: create answered
         * 403, delete answered 200.
         *
         * THE SAME CONSTANT AS THE CREATE SIDE, not the literal, so the two halves of one resource
         * cannot drift apart. It resolves to `Manage Customer`, which is also what the web twin
         * `CustomerController::destroy()` (`:289`) names. `Show Customer` is deliberately NOT
         * accepted: it is a READ right, neither the web delete nor the API create accepts it, and
         * accepting it would let a read-only grant destroy PII.
         *
         * THE ENVELOPE IS THE CREATE SIDE'S, NOT `storePermissionDenied()`'s, and that is
         * deliberate. This handler speaks `{status: 0, message: 'fail', data: {message}}` with real
         * HTTP codes throughout, and the ownership refusal immediately above is already a 403 in
         * exactly that shape. Matching it keeps one envelope per route for the shipped app, and
         * makes "I do not own that store" indistinguishable from "I lack the right" -- no new
         * existence oracle, which is the property `addCustomer` was explicitly repaired for.
         *
         * 403 AND NEVER 401: the shipped Flutter app force-clears its session on any 401.
         *
         * PLACED BELOW THE OWNERSHIP 403 AND ABOVE THE CUSTOMER LOOKUP, so an unpermitted caller
         * cannot use the 404 branch to probe which customer ids exist inside the store.
         *
         * ONE ASYMMETRY REMAINS AND IS RECORDED RATHER THAN HIDDEN: this gate has the owner limb
         * (`callerMayActInStore()`) and the create side does not, so a store owner holding no role
         * at all can now delete a customer they could not create. The limb stays because refusing a
         * store's own owner is the regression this family has already produced once, and because
         * there is no security gain -- the owner grants the permissions in their own tenant. In
         * production it is moot: `Utility.php` grants `Manage Customer` to the shared global `admin`
         * role that every self-registered merchant holds. Both owner shapes are asserted by
         * `AdminApiCustomerDeletePermissionTest`, so neither reading is left to inference, and
         * widening `addCustomer` instead would change a handler with its own pinned behaviour.
         */
        if (! $this->callerMayActInStore(
            [\App\Support\MerchantDashboardAbilities::PERMISSION_CUSTOMERS],
            $store->id
        )) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to delete customers from this store.']
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
public function createOrderViaApi(Request $request)
{
    $storeId = $request->query('store_id');

    \Validator::make(['store_id' => $storeId], [
        'store_id' => 'required|integer|exists:stores,id'
    ])->validate();

$currentCurrencyCode = \App\Support\Money\OrderCurrencyStamp::codeFor($storeId);          // P5-R14: the one resolver, so the admin app's order
$currentCurrencySymbol = \App\Support\Money\OrderCurrencyStamp::symbolFor($storeId);      // and the storefront's record one store's currency alike


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

    /*
     * P4-F126 -- AN UNCOLLECTED COD PARCEL MAY NOT BE ASSERTED AS ALREADY PAID.
     * =============================================================================================
     *
     * This door takes `payment_type` and `payment_status` from the caller and writes them verbatim,
     * and it never consults `PaymentVerificationService`. So `cod` + `Paid` was accepted, and
     * `OrderObserver::created()` then credited the merchant's wallet AT CREATION -- for a parcel
     * that has not left the building and cash nobody has handled.
     *
     * That is the literal condition owner decision D-06 forbids, and the STOREFRONT already obeys
     * it: `resolveOrderPaymentStatus()`'s first branch returns `Pending` unconditionally for COD.
     * The gap was never the policy; it was that this door never asked.
     *
     * NO NEW VOCABULARY IS INVENTED, because the counter sale is already sayable. `POS` exists, is
     * already written as a literal `Paid` by the POS door, and is already in
     * `OFFLINE_PAYMENT_TYPES` -- the set whose own comment reads "settles off-platform by
     * arrangement, so record Paid at once". A merchant who took cash across a counter records a POS
     * sale; a merchant dispatching a parcel for collection records COD, and COD is Pending until
     * somebody collects. That is the owner's "distinguish them using an explicit supported payment
     * type", satisfied with zero schema change.
     *
     * AND THE EVIDENCE STORE ALREADY EXISTS AND CANNOT YET BE FILLED. `CodCollectionService`
     * enforces exactly the four fields the owner named -- collector type, collector id, a strictly
     * positive amount, a timestamp -- and refuses a partial collection. It stores nothing because
     * `cod_collection_events` is declared by no migration, so `codMayBeSettled()` cannot return
     * true in shipped code. Creating that table is a production migration and is not authorised
     * here, so COD settles through no door at all today. Recorded, not worked around.
     *
     * CREATION-TIME ONLY. Existing `cod` + `Paid` rows are untouched: reclassifying them would move
     * real money through the observer, which is what the withdrawn COD trigger's own note warned
     * about.
     *
     * THE REFUSAL IS 422 AND NEVER 401 -- the shipped app force-clears its session on any 401.
     */
    if (app(\App\Services\PaymentVerificationService::class)
            ->isCashOnDelivery($validated['payment_type'] ?? null)
        && strcasecmp(trim((string) ($validated['payment_status'] ?? '')), 'Paid') === 0) {

        return response()->json([
            'success' => false,
            'message' => __('A cash-on-delivery order cannot be created as already paid. Record it as Unpaid; it becomes Paid when collection is verified. For an over-the-counter cash sale, use the POS payment type.'),
            'errors' => [
                'payment_status' => [__('Cash on delivery cannot be marked Paid before collection is verified.')],
            ],
        ], 422);
    }

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

        /*
         * ═══════════════════════════════════════════════════════════════════════════════════════
         * F226. ONE SUBMISSION, ONE ORDER — the idempotency control this writer never had.
         * ═══════════════════════════════════════════════════════════════════════════════════════
         *
         * Of the fifteen customer-order write sites the parsed census found, this is the one a
         * shipped client calls and the one with **no accidental dedupe**: the two storefront
         * writers consume the cart, so a sequential replay finds it empty and refuses itself, and
         * seven gateway returns go through `claimGatewayReturn()`. This one takes a payload and
         * writes. So a double tap, or an app retrying a request whose response was lost, wrote two
         * orders and decremented the same stock twice. MEASURED before the fix: two submissions,
         * two `orders` rows, `10 - 3 - 3 = 4` on the variant.
         *
         * The retry is the case that matters, because it is CORRECT client behaviour. A client
         * cannot tell a lost response from a failed write, so it must retry — and the server has
         * to make that safe rather than punish it.
         *
         * WHY A CONTENT FINGERPRINT. The shipped app sends no idempotency key, so a control that
         * requires one protects nobody who is at risk today. `orderIntakeFingerprint()` therefore
         * hashes a canonical form of the validated payload together with the authenticated
         * principal and the server-resolved store — never caller input alone, which this project's
         * standing rule forbids and which would let one merchant evict another's claim. Both
         * halves of that are tested: a second merchant's identical payload is its own order, and a
         * caller refused 403 above leaves no claim behind, because the claim is taken *after* the
         * tenant and permission gates and not before them.
         *
         * `Cache::add()` and not a lock, for the reason the Paymera docblock sets out at
         * `PaymentController.php:672`: it is atomic and has no owner to expire, so the first caller
         * to add the key wins and every later one is answered for as long as the key lives,
         * whatever the first caller is doing meanwhile.
         *
         * THE COST, STATED RATHER THAN HIDDEN. Two genuinely distinct but byte-identical orders
         * inside the window are treated as one: same customer, same lines, same prices, same
         * address, same note, within `ORDER_INTAKE_CLAIM_SECONDS`. That is a double submit in
         * essentially every real case, and for the case where it is not, an `Idempotency-Key`
         * header is folded into the fingerprint — so a client that wants two can ask for two.
         *
         * AND THE WINDOW IS THE LIMIT OF THE CONTROL. The cache is not durable: an eviction, or a
         * replay arriving after the TTL, writes a second order. That bounds this to what F226
         * actually reports — the double submit and the immediate retry — and the upgrade path is a
         * durable intake record, the shape `plan_purchase_intents` now uses for plan purchases.
         */
        $ssIntakeFingerprint = $this->orderIntakeFingerprint($validated, (int) $store->id, (int) $userId, $request);

        if (! \Illuminate\Support\Facades\Cache::add($ssIntakeFingerprint, self::ORDER_INTAKE_WRITING, now()->addSeconds(self::ORDER_INTAKE_CLAIM_SECONDS))) {
            \DB::rollBack();

            $ssAlreadyAnswered = \Illuminate\Support\Facades\Cache::get($ssIntakeFingerprint);

            if (is_array($ssAlreadyAnswered)) {
                /*
                 * THE FIRST ORDER'S OWN RESPONSE, kept verbatim rather than rebuilt from the
                 * persisted row. D-21's condition is that the shipped app sees no change, and a
                 * rebuild is exactly where that breaks: `orders.product_price` reads back as a
                 * STRING where the original response carried a float, so a client decoding into a
                 * typed model would fail on the retry and not on the first attempt — the worst
                 * possible place for a type to drift. Replaying the stored payload makes the two
                 * identical by construction, which `AdminApiOrderIdempotencyTest` asserts.
                 */
                return response()->json($ssAlreadyAnswered, 201);
            }

            /*
             * The first attempt is still in flight. 409 is a NEW code for a NEW condition — no
             * response the app has ever received changes — and the envelope is the one every other
             * refusal in this method uses, so a generic `if (201) ... else showError(message)`
             * handles it. It is deliberately NOT 401: `lib/network/network.dart` maps any 401 to a
             * forced logout that clears stored preferences.
             */
            return response()->json([
                'status' => 'error',
                'message' => __('This order is already being created. Please wait a moment and check your orders.'),
            ], 409);
        }

        $ssIntakeCommitted = false;

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

        $ssIntakeCommitted = true;

        $ssIntakeAnswer = [
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
        ];

        /* Written only after the commit, so a replay arriving mid-write meets the `writing`
           sentinel and is told to wait rather than handed a half-built answer. */
        \Illuminate\Support\Facades\Cache::put($ssIntakeFingerprint, $ssIntakeAnswer, now()->addSeconds(self::ORDER_INTAKE_CLAIM_SECONDS));

        return response()->json($ssIntakeAnswer, 201);

    } catch (\Exception $e) {
        \DB::rollBack();
        \Log::error('API Order Creation Error: ' . $e->getMessage());

        return response()->json([
            'status' => 'error',
            'message' => 'Failed to create order: ' . $e->getMessage()
        ], 500);
    } finally {
        /*
         * A CLAIM MUST NOT OUTLIVE A WRITE THAT NEVER HAPPENED.
         *
         * This method refuses in six places AFTER the claim is taken -- a variant that is not the
         * product's, two stock shortfalls, and the catch above -- and every one of them rolls back
         * and writes nothing. A surviving claim would answer the merchant's corrected retry as a
         * replay of an order that does not exist, turning a recoverable refusal into a dead end.
         * That is the direction the Paymera docblock warns about, and `finally` is what makes it
         * hold for the early `return`s as well as for the throw, without a release line beside
         * each one waiting to be forgotten.
         *
         * The converse is equally deliberate: on success the claim is NOT released, because
         * releasing it after a successful write is what hands a replay the second order.
         */
        if (isset($ssIntakeFingerprint) && empty($ssIntakeCommitted)) {
            \Illuminate\Support\Facades\Cache::forget($ssIntakeFingerprint);
        }
    }
}

/** The sentinel a claim holds while the write is in flight. Not an array, which is the test. */
private const ORDER_INTAKE_WRITING = 'writing';

/**
 * How long one submission's answer is remembered. Long enough to cover a client timing out and
 * retrying -- the case this control exists for -- and short enough that two genuinely identical
 * baskets from one merchant are not plausibly inside it.
 */
private const ORDER_INTAKE_CLAIM_SECONDS = 120;

/**
 * THE FINGERPRINT OF ONE SUBMISSION.
 *
 * Canonical, because the same basket must hash the same whatever order the client sent its keys
 * or its lines in: the lines are rendered to strings and sorted, the money fields are normalised
 * to a fixed scale so `10` and `10.00` agree, and the remaining keys are sorted.
 *
 * The principal and the store are part of the hash and are taken from the SERVER's resolution of
 * them, never from the request: `$userId` is `auth()->id()` and `$storeId` is the id of the store
 * `resolveOwnedStore()` admitted. A fingerprint over the payload alone would let any caller who
 * could guess another merchant's basket pre-claim it.
 *
 * `Idempotency-Key` is honoured if the client sends one and is folded in rather than replacing the
 * content hash: a distinct key buys a distinct order, which is the escape hatch for two
 * legitimately identical baskets, and an absent one leaves the content hash doing the work for the
 * shipped app that sends none.
 */
private function orderIntakeFingerprint(array $validated, int $storeId, int $userId, Request $request): string
{
    $lines = [];

    foreach ($validated['products'] as $line) {
        $lines[] = implode('|', [
            (int) ($line['product_id'] ?? 0),
            (int) ($line['variant_id'] ?? 0),
            (int) ($line['quantity'] ?? 0),
            number_format((float) ($line['price'] ?? 0), 4, '.', ''),
        ]);
    }

    sort($lines);

    $fields = $validated;
    unset($fields['products']);

    foreach (['delivery_price', 'tax_price'] as $money) {
        if (array_key_exists($money, $fields)) {
            $fields[$money] = number_format((float) $fields[$money], 4, '.', '');
        }
    }

    ksort($fields);

    $suppliedKey = $request->header('Idempotency-Key');

    return 'ss_order_intake_v2:' . hash('sha256', implode("\n", [
        $storeId,
        $userId,
        is_string($suppliedKey) ? $suppliedKey : '',
        implode(';', $lines),
        json_encode($fields),
    ]));
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY READ THE STORE
         * AND OWNER PROFILE.
         *
         * MEASURED: an unpermissioned staff member of the owning store was served the store
         * OWNER's `users.email`. That is the address the password-reset flow sends to, so this is
         * the first half of an account-takeover chain rather than a directory listing; their name,
         * their mobile, the store address block and the store's currency settings come with it.
         * The control above settles tenancy only -- this handler's own note records closing the
         * CROSS-TENANT hole and says nothing about the intra-tenant one.
         *
         * THE TWIN IS THE SETTINGS SCREEN, which is where the web reads these same fields:
         * `SettingController::settingForm()` renders every settings tab -- the `brand_setting` tab
         * is the store name, e-mail and address block -- behind `StoreSettingsAccess::permits()`,
         * and `BusinessSettings()`, which WRITES them, is behind the same predicate. The API
         * sibling that writes them, `updateStoreOwnerInfo`, keeps its own owner-identity rule
         * (P1-SEC-18); this is the read.
         *
         * `permits()` IS OPERATOR-FIRST, THEN THE TENANT OWNER, THEN `Manage Setting`, so the
         * platform operator and the store's own owner are both still admitted. Placed below the
         * tenancy refusal, so an unowned store id and an absent one still answer identically and
         * this door is still not a store-id oracle.
         */
        if ($refusal = $this->refuseUnlessMayAdministerStoreSettings()) {
            return $refusal;
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

/**
 * P1-SEC-73. The stored name of the store owner's invoice logo and store image (`updateStoreOwnerInfo`, a call the shipped app makes).
 *
 * This handler never went through the platform uploader: it validated the BYTES (`image|mimes:jpeg,png,jpg,gif,svg`) and `move()`d the file
 * under `time() . '_' . basename(<client name>)`, so PNG bytes named `page.html`, `a.pht` or `shell.php.png` were stored under exactly
 * that name in `public/uploads/<store>/` -- the name is what a web server acts on. The base is now the client's name reduced to letters,
 * digits, `_` and `-`, and the extension is the one the BYTES decide (`jpg`, `png`, `gif` or `svg`, which is all the rule above admits).
 */
private function ownerImageName(\Illuminate\Http\UploadedFile $file): string
{
    $base = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo(basename($file->getClientOriginalName()), PATHINFO_FILENAME)), '_');
    $extension = $file->guessExtension();

    // the validation rule admits nothing else: a name is never built from anything the bytes did not decide
    abort_unless(in_array($extension, ['jpg', 'png', 'gif', 'svg'], true), 422, 'The image type is not accepted.');

    return time() . '_' . ($base !== '' ? $base : 'image') . '.' . $extension;
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

    /*
     * GAP-142 / P4-F111 — THE PERMISSION LIMB, AND A MEASUREMENT THAT HAD TO BE STATED HERE.
     *
     * This handler serves `products.cost_price` -- the merchant's margin, the most commercially
     * sensitive column in the catalogue -- to every member of the store, whatever they held.
     * `resolveOwnedStore()` above covers tenancy; this covers the right.
     *
     * `Manage Products` MATCHES THE WEB (`ProductController:1514`) AND IS SEEDED, so this is an
     * ordinary grantable control that a merchant can delegate.
     *
     * AN EARLIER VERSION OF THIS COMMENT CLAIMED THE OPPOSITE -- that the whole core product
     * permission family is "seeded nowhere in this repository" -- and raised a gap row for it. That
     * was WRONG, and the cause is worth more than the correction: the measuring script extracted
     * quoted strings with a SINGLE-QUOTE-ONLY pattern, while `app/Models/Utility.php` writes
     * `"Manage Products"` in DOUBLE quotes -- at `:2941` in `$arrPermissions` (rows created at
     * `:3156-3163`) and at `:3271` in `$companyNewPermission` (granted to the global `admin` role
     * every merchant holds, at `:3377-3378`). `"Create Product"` and `"Edit Products"` are there the same way. A single-quoted
     * search finds `'Manage Customer'` and misses all three, which is exactly the reading that
     * produced the false claim. `AdminApiPermissionExposureTest:566-577` already measured the truth
     * by running `Utility::addNewData()` and asserting `hasPermission('Manage Products')`, and
     * `DashboardPermissionNamesTest:162` says the seeded name is the plural in its own failure
     * message. Caught by the independent review of this slice; the gap row was withdrawn.
     *
     * The gate's correctness never depended on that question -- `callerMayActInStore()` tries the
     * STORE-OWNER limb first and then `MerchantPermissions::allowsAny()`, which answers `WILDCARD`
     * for the platform operator, so the owner and the operator pass regardless. But the ARGUMENT
     * was weaker than the truth: staff really can be granted this name, on the web and here, so
     * this is a delegable right rather than one carried only by the owner and operator limbs.
     */
    if (! $this->callerMayActInStore(['Manage Products'], $store->id)) {
        return $this->storePermissionDenied();
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

                /*
                 * P4-F032 -- THE WALLET. This is the second of the two doors that created a store
                 * without one; `Auth\RegisteredUserController::store` was the other, and
                 * `StoreController` has always provisioned here.
                 *
                 * Without it `OrderFinancialService::createForOrder()` throws into
                 * `OrderObserver::created()`'s catch for every order this store ever takes: the
                 * order commits, no `store_order_financials` row is written, and the merchant's
                 * credit is lost permanently with nothing to revisit it.
                 */
                app(\App\Services\StoreWalletProvisioning::class)->ensure((int) $store->id);

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

                /*
                 * `> 0` AND NOT `== -1`, because -1 was not the only limit without a denominator.
                 * `plans.max_products`, `max_stores` and `max_users` are each `->default(0)`, so a
                 * plan whose limit was never filled in divided by zero here -- and
                 * `DivisionByZeroError` is an `Error`, which the `catch (\Exception)` around this
                 * whole handler cannot see, so it was an HTTP 500 on the app's current-plan screen
                 * rather than a handled failure. `0` is the answer this expression already gave for
                 * an unlimited plan, where a percentage is equally meaningless; `max` and `used` sit
                 * beside it and say the real state.
                 * AdminApiCurrentPlanZeroLimitTest holds it, and records which half of the report
                 * that found it was refuted.
                 */
                'limits' => [
                    'products' => [
                        'max' => $maxProducts == -1 ? 'Unlimited' : $maxProducts,
                        'used' => $usedProducts,
                        'remaining' => $remainingProducts == -1 ? 'Unlimited' : $remainingProducts,
                        'percentage' => $maxProducts > 0 ? round(($usedProducts / $maxProducts) * 100, 2) : 0,
                    ],
                    'stores' => [
                        'max' => $maxStores == -1 ? 'Unlimited' : $maxStores,
                        'used' => $usedStores,
                        'remaining' => $remainingStores == -1 ? 'Unlimited' : $remainingStores,
                        'percentage' => $maxStores > 0 ? round(($usedStores / $maxStores) * 100, 2) : 0,
                    ],
                    'users' => [
                        'max' => $maxUsers == -1 ? 'Unlimited' : $maxUsers,
                        'used' => $usedUsers,
                        'remaining' => $remainingUsers == -1 ? 'Unlimited' : $remainingUsers,
                        'percentage' => $maxUsers > 0 ? round(($usedUsers / $maxUsers) * 100, 2) : 0,
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- THE WRITE SIDE OF A GET, SAID OUT LOUD.
         * =========================================================================================
         *
         * THIS IS A READ ENDPOINT THAT WRITES. The branch below SEEDS THIRTEEN ROWS into a money
         * table -- USD, EUR, SYP, LBP, IQD, EGP, JOD, SAR, AED, KWD, QAR, BHD, OMR, each
         * `rate_per_usd = 1` and `is_active = 1`, stamped `created_by` = the caller. `rate_per_usd`
         * is the divisor every displayed price is converted through, so before this gate a GET by a
         * principal holding nothing ESTABLISHED the store's FX table, with the Syrian pound at
         * parity with the dollar. Measured by the behavioural route census with a real bearer token
         * held by a staff member of the owning store holding no permission at all.
         *
         * THE SAME PREDICATE ITS OWN WRITE TWINS USE: `updateCurrencyRateApi` and
         * `toggleCurrencyStatusApi` in this file, and the web `CurrencyRateController::update()`, are
         * all `StoreSettingsAccess::permits()` (operator first, then the tenant owner, then
         * `Manage Setting`). Establishing the table is the same kind of decision as changing a rate
         * in it, so it asks the same question.
         *
         * `permits()` AS A BOOLEAN RATHER THAN `refuseUnlessMayAdministerStoreSettings()`, BECAUSE
         * THE READ IS DELIBERATELY NOT REFUSED. The web twin of this read,
         * `CurrencyRateController::index()` (`routes/web.php:201`), carries `auth` and NO permission
         * at all, so a 403 here would make the API narrower than a screen the merchant's staff can
         * already open -- on a path the shipped Flutter app calls uncommented. Withdrawing a live
         * capability is a separate product decision that must not ride on a security fix, and the
         * payload discloses nothing the storefront does not already show a shopper (rate, symbol,
         * store name). So an unpermissioned caller reads the table exactly as it stands and
         * establishes nothing; for every store created through the product the rows already exist
         * (`StoreController:211` and `:367` seed the same thirteen at creation), which is the state a
         * real caller meets. Both halves are pinned by
         * `AdminApiCatalogueDeletePermissionTest::test_get_currency_rates_*`.
         *
         * BELOW the tenancy refusal above, so a foreign tenant keeps the answer it already gets.
         */
        if ($currencyRates->isEmpty() && \App\Services\StoreSettingsAccess::permits($user)) {
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

        /*
         * WITHIN-TENANT PERMISSION AXIS [2026-11-25] -- WHO INSIDE THE STORE MAY SWITCH THE
         * STOREFRONT'S LANGUAGE.
         *
         * MEASURED: an unpermissioned staff member of the owning store wrote
         * `stores.default_language`, which decides what every shopper reads -- while every OTHER
         * settings door in this controller (`stripe-payment`, `testmail`, `updateCurrencyApi`,
         * `toggle-currency-status`, `update-currency-rate`) goes through
         * `refuseUnlessMayAdministerStoreSettings()`.
         *
         * `permitsStoreScreen()` AND NOT `permits()`, because that is the WIDER of the two web
         * twins and a gate must not refuse somebody a web screen admits. Two web handlers write
         * this same column: `SettingController::SystemSettings()` / `BusinessSettings()`, behind
         * `StoreSettingsAccess::permits()` (operator, tenant owner, `Manage Setting`), and
         * `AppSettingController::ThemeSettings()` -- the Store Setting screen -- behind
         * `permitsStoreScreen()`, which is that same predicate OR `Manage Store Setting`. A staff
         * member holding only `Manage Store Setting` may change the language on the web, so they
         * keep it here.
         *
         * BELOW the tenancy refusal above, so a foreign tenant's answer is unchanged. 403 through
         * `storePermissionDenied()`, the shape this controller uses for a permission refusal;
         * never 401, which force-logs-out the shipped app.
         */
        if (! \App\Services\StoreSettingsAccess::permitsStoreScreen($user)) {
            return $this->storePermissionDenied();
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


        /*
         * FIN-CUR -- THE SAME RULES THE WEB CURRENCY SCREEN HAS CARRIED ALL ALONG.
         *
         * This handler used to write five `settings` rows from the request and answer 200. It did
         * not refuse the platform operator (F206), did not require an active `currency_rates` row,
         * did not check the rate was usable (P1-SEC-80 round 4), took the SYMBOL from the caller
         * instead of from that row (also P1-SEC-80 round 4), and -- the defect this was opened for
         * -- did not CONVERT anything. A store that switched currency through the app kept every
         * product price and every wallet balance at the old currency while every label said the
         * new one, and the wallet's own `currency_code` still named the old one.
         *
         * `defult_currancy_symbol` stays REQUIRED in the validator above so the shipped app's
         * request shape still validates, and is then IGNORED: the symbol that is stored is the
         * one on the store's own rate row. The response returns that symbol, so a client that
         * echoes the response shows the authoritative value.
         */
        $decision = \App\Services\StoreCurrencyChange::prepare($adminUser, (int) $storeId, $validated['defult_currancy']);

        if (! $decision['ok']) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => $decision['message']],
            ], $decision['reason'] === \App\Services\StoreCurrencyChange::REFUSED_OPERATOR ? 403 : 422);
        }

        $currencyName = $decision['currency'];
        $currencySymbol = $decision['symbol'];
        $position = $validated['site_currency_symbol_position'] ?? 'pre';

        $settingsToUpdate = [
            'defult_currancy' => $currencyName,
            'CURRENCY_NAME' => $currencyName,
            'defult_currancy_symbol' => $currencySymbol,
            'CURRENCY' => $currencySymbol,
            'site_currency_symbol_position' => $position,
        ];

        /*
         * ONE TRANSACTION over the conversion AND the label, so a failure cannot leave converted
         * prices under the old currency's name or the new name over unconverted amounts. The
         * conversion runs first, as it does on the web screen, and only when the currency really
         * changes.
         */
        \Illuminate\Support\Facades\DB::transaction(function () use ($decision, $storeId, $settingsToUpdate, $adminUser) {
            \App\Services\StoreCurrencyChange::apply((int) $storeId, $decision['from'], $decision['currency']);

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
        });


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

        $storeId = $request->query('store_id');
        \Validator::make(['store_id' => $storeId], ['store_id' => 'required|integer|exists:stores,id'])->validate();

        /*
         * GAP-249 — THE `exists` RULE IS SCOPED TO THE STORE, AND THE TWO VALIDATORS SWAPPED ORDER.
         *
         * This was `exists:customers,id` — unscoped, and ABOVE the store validator. So it answered a
         * question about the WHOLE `customers` table before the caller had been shown to own
         * anything, and the two answers were distinguishable: MEASURED, a foreign tenant's customer
         * id came back **404** (it passed the validator, then the handler's own
         * `where('store_id', $storeId)` found nothing) while an id existing nowhere came back
         * **422** from the validator. Any admin-API token holder could walk the id space — an
         * unpermissioned staff member of a one-customer store, or a merchant of an unrelated
         * tenant. Only a boolean crossed, so this is hardening rather than a disclosure, and it was
         * pre-existing: the permission limb below neither worsened nor closed it.
         *
         * THE ORDERING OF THE GATES IS UNCHANGED, deliberately. The tempting fix — hoisting the
         * permission limb above the validators — would break the refusal a foreign-tenant caller
         * must keep getting: three shipped suites assert the STORE status, and
         * `AdminApiCustomerReadPermissionTest::test_another_tenants_staff_still_gets_the_tenancy_refusal`
         * pins it on the body saying "this store" rather than "perform this action". Only the two
         * validators swapped, so `$storeId` is known before the customer rule needs it.
         *
         * `$storeId` here is the caller's own request value and is NOT relied on as a tenant check —
         * `resolveOwnedStore()` immediately below still decides ownership. All the scoping does is
         * stop the validator speaking about rows outside the store named in the same request, which
         * makes a foreign id and an absent id both 422. Pinned by
         * `AdminApiCustomerExistenceOracleTest`, whose two defect cases drive BOTH legs in one
         * assertion because the defect IS the difference between them.
         */
        $id = $request->query('id');
        \Validator::make(
            ['id' => $id],
            ['id' => 'required|integer|exists:customers,id,store_id,' . (int) $storeId]
        )->validate();

        $store = $this->resolveOwnedStore($storeId);
        if (!$store) {
            return response()->json([
                'status' => 0, 'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }


        /*
         * GAP-142 / P4-F113 (READ HALF) — THE PERMISSION LIMB, BELOW THE TENANCY GATE.
         *
         * `resolveOwnedStore()` above already refuses a store this caller does not own, so this is
         * not a tenancy fix: it is that EVERY MEMBER OF THE STORE read one customer's orders and a FULL DELIVERY ADDRESS whatever they were
         * granted. Both names are seeded (`Utility.php:3085-3086`, and `:3336-3337` grants them to
         * the global `admin` role every merchant holds).
         *
         * THIS ONE KEEPS THE `Show Customer` PAIR while the two LIST routes were narrowed to
         * `Manage Customer` alone, and the asymmetry is the web's: this handler's analogue is the
         * customer DETAIL screen (`CustomerController::show():335`, gated `:339-341`, operator OR
         * `Show Customer`), not the list (`index():312`, gated `:314`, `Manage Customer` only).
         * Refusing `Show Customer` here would be stricter than the web, which is a product change
         * rather than a port.
         *
         * BELOW the tenancy gate, not above it: a caller from another tenant must keep getting the
         * STORE refusal they get today, which shipped suites assert. 403 through
         * `storePermissionDenied()` -- never 401 (the shipped app force-clears its session on one).
         */
        if (! $this->callerMayActInStore(['Manage Customer', 'Show Customer'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * P2.5-PKG-C (F-C-08). `->where('store_id', $storeId)` ON BOTH BRANCHES, which the count
         * and the sum TEN LINES ABOVE already had and this list did not.
         *
         * That asymmetry is what makes it an omission rather than a decision: `$ordersCount` at
         * :8436 and `$totalSpend` at :8440 are both scoped, and this query -- the one whose rows
         * are expanded in full by `Order::order_detail()` below and returned -- was not. So the
         * figures were this store's and the ORDERS were every store's.
         *
         * MEASURED before this change (CustomerOrderAggregateTenancyTest): merchant A, their own
         * customer, one $100 guest order in A and one $900 guest order in B billed to the same
         * email -- the response listed BOTH, with full order detail for each.
         *
         * `resolveOwnedStore($storeId)` above guards the store_id PARAMETER, not these rows: the
         * merchant legitimately owns the store they named and the customer really is theirs, so
         * that check passes and cannot help here. `Order` is absent from `scoped_models`, so
         * `StoreScope` adds no predicate either. `$storeId` is already authorised at this point.
         *
         * THIS IS THE API TWIN of `Customer::ownStoreOrders()`, and it is the one with a shipped
         * client: the route is `/api/admin/customer/orders`, inside `Route::prefix('admin')`,
         * which is the Flutter admin app's base URL. The response SHAPE is unchanged and no new
         * status code is introduced -- the orders array simply no longer carries another store's
         * rows, so the app sees fewer entries and never a new error.
         *
         * NOT CHANGED, and recorded instead: `$ordersCount` uses only the direct branch while
         * this list has the email fallback, so for a guest-only customer the response still says
         * "0 orders" and "$0 total spend" while listing their orders. That incoherence is real and
         * pre-existing, but reconciling it is a decision about what the figure MEANS.
         */
        $ordersQuery = \App\Models\Order::where('store_id', $storeId)->where('customer_id', $id);

        if ($ordersQuery->clone()->count() == 0) {
            $orders_detail = \App\Models\OrderBillingDetail::where('email', $customer->email)->get()->toArray();
            $order_id = [];
            foreach ($orders_detail as $orders) {
                $order_id[] = $orders['order_id'];
            }
            $ordersQuery = \App\Models\Order::where('store_id', $storeId)
                ->whereIn('id', $order_id)
                ->where('customer_id', 0);
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
                    /* GAP-156. Unchanged and still an ORDER-VALUE figure -- see CustomerList()
                       for why this key is not repointed at the financial record. */
                    'total_spend' => currency_format_with_sym((float) $totalSpend, $storeId) ?? SetNumberFormat((float) $totalSpend),
                    'aov' => currency_format_with_sym((float) $aov, $storeId) ?? SetNumberFormat((float) $aov),
                    'ledger' => self::customerLedgerPayload(
                        \App\Support\Customers\CustomerMoneyFigures::forCustomer($customer),
                        (int) ($customer->store_id ?? $storeId),
                    ),
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

        /*
         * OWNERSHIP BEFORE FIELD VALIDATION, and the ORDER is the fix -- no rule here changes.
         *
         * `$storeId` above is `$request->query('store_id')`: CALLER-SUPPLIED. The e-mail rule
         * below is correctly scoped to it, but until `resolveOwnedStore()` has run nothing has
         * checked that the caller owns that store. So a merchant posted another merchant's
         * `store_id` with an address and the 422 told them whether that address is a customer of
         * that store; the 403 arrived only afterwards, for requests that got past validation.
         *
         * MEASURED before the move, in AddCustomerEmailDisclosureTest: a known address answered
         * **422** and an unknown one **403** for the same non-owned store. One bit per request,
         * and a list of addresses enumerates another merchant's customer base.
         *
         * `resolveOwnedStore()` itself was already correct (`:4543-4563`) -- it compares
         * `created_by` against `creatorId()` and exempts the operator. It was simply called too
         * late to protect anything.
         *
         * NOTHING CHANGES FOR A CALLER WHO OWNS THE STORE: same rules, same 422, same reason, and
         * two tests pin that. Only a NON-owner's answer changes, from 422 to 403, which is the
         * point. 403 and not 401, so the shipped Flutter app's force-logout-on-401 rule is not
         * engaged by this.
         */
        $store = $this->resolveOwnedStore($storeId);

        if (!$store) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to add customers to this store.']
            ], 403);
        }

        /*
         * S2 — THE PERMISSION CHECK THIS HANDLER SHIPPED WITHOUT. Owner-authorised 2026-11-07,
         * "NARROW FIX AUTHORIZED", and tracked at docs/security/P01-SECURITY-WORK-QUEUE.md.
         *
         * Until this line the only gates were a 401 for a missing token and the ownership 403
         * above. There was no `isAbleTo` and no operator limb anywhere in the method, while its
         * WEB SIBLING -- `CustomerController::store():50-54` -- has required `Manage Customer`
         * since the escalation recorded at `CustomerController.php:21-26` ("Any authenticated user
         * could create a customer... A HIDDEN BUTTON IS NOT A FIX"). The same operation was gated
         * on one surface and open on the other.
         *
         * MEASURED, not inferred: a staff member holding NO permissions obtained an admin token
         * (`login` restricts only `is_active`/`is_enable_login`, never `type`), resolved their
         * owning merchant's store through `resolveOwnedStore()` -- which scopes on
         * `created_by = $user->creatorId()`, and `creatorId()` returns `created_by` for any type
         * that is not admin/super admin -- and got 201. And the write is a CREDENTIAL: the create
         * below sets `password` from the request and `email_verified_at => now()`, so the account
         * signs in to that merchant's storefront at once.
         *
         * `MerchantPermissions::allowsAny()` AND NOT `isAbleTo()`, which is the whole of the
         * ordering question and the reason this is not a one-liner:
         *
         *   * `isAbleTo()` ALONE REFUSES THE PLATFORM OPERATOR. There is no super-admin bypass in
         *     this codebase -- operator status is a string on `users.type` -- so an operator-last
         *     check here would be a LOCKOUT rather than a tightening. `MerchantPermissions`
         *     answers WILDCARD for the operator, which is the established operator-first handling
         *     and is reused rather than re-derived.
         *   * `isAbleTo()` IS NOT STORE-SCOPED. `allPermissions()` unions every role the principal
         *     holds with no store predicate, so a staff member granted the permission in one store
         *     would carry it into every other store of the same merchant. `MerchantPermissions`
         *     corrects exactly that, keeping the NULL-store arm so a merchant -- who holds the
         *     global `admin` role -- does not lose their own permissions when they are read per
         *     store.
         *
         * THE PRECEDENT IS `:2403`, the `Edit Products` gate -- about 6,280 lines ABOVE this
         * one, not below it. An earlier draft of this comment said "150 lines below"; the
         * independent review measured the call sites (`:804`, `:809`, `:944`, `:1083`, `:2403`,
         * `:4804`, `:6442`, and this one) and there is NO `MerchantPermissions` call after this
         * point in the file at all. A comment that is wrong about where its own precedent lives
         * sends the next reader to the wrong code, which this project treats as a defect in its
         * own right (`MerchantPermissions.php:138-141`).
         *
         * THE CALL IS THE SAME AS `:2403`; THE RESPONSE DELIBERATELY IS NOT. That gate answers
         * `$this->error([...])`, and `ApiResponser::error()` is HTTP **200** with `{status: 0}`.
         * This one answers a real 403, for two reasons that both point the same way: the owner's
         * authorising instruction is explicit -- *"Return 403 for authenticated users lacking
         * permission, not 401"* -- and the ownership refusal 59 lines above already answers 403
         * with this exact body, so matching it is what keeps the two refusals indistinguishable.
         * Matching `:2403` instead would have made the permission refusal a 200 that the shipped
         * app reads as a generic failure. NO NEW PERMISSION MODEL: the name is the existing
         * `MerchantDashboardAbilities::PERMISSION_CUSTOMERS`, which is what the web form, the
         * merchant search and the quick actions all already ask for.
         *
         * PLACED AFTER THE OWNERSHIP 403, deliberately. Both refusals answer 403 with the same
         * body, so a caller cannot tell "I do not own that store" from "I lack the right" -- no
         * new existence oracle, which is the defect this same handler was repaired for earlier in
         * this branch.
         *
         * 403 AND NEVER 401. `ApiResponser::error()` returns HTTP 200 by default and 401 must be
         * passed deliberately; the shipped Flutter app FORCES A LOGOUT on any 401, so answering
         * 401 to a permission refusal would sign a merchant's staff out of the app instead of
         * telling them they lack a right. A test asserts the code is not 401.
         */
        if (! \App\Services\MerchantPermissions::allowsAny(
            $adminUser,
            [\App\Support\MerchantDashboardAbilities::PERMISSION_CUSTOMERS],
            $store->id
        )) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to add customers to this store.']
            ], 403);
        }

        /*
         * TAKE THE ID FROM THE RESOLVED STORE, NOT FROM THE QUERY STRING. Until this line,
         * `$storeId` was the caller's own `$request->query('store_id')` all the way through --
         * into the uniqueness rule below, into `Customer::create(['store_id' => $storeId])` and
         * into the `DeliveryAddress::create()` after it.
         *
         * IT WAS NOT EXPLOITABLE, and that is stated rather than implied: `resolveOwnedStore()`
         * matched `Store::where('id', $storeId)`, so the value identifies the store that was
         * checked, and `required|integer|exists:stores,id` above rejects the shapes that could
         * make the two queries disagree -- `'5.0'`, an overflow, a non-integer -- while numeric
         * affinity collapses `'+5'` and `' 5'` identically in both. The review of this change
         * tried to make them diverge and could not.
         *
         * SO THIS IS HARDENING, AND IT HAS NO BITE TEST, because there is no input that behaves
         * differently before and after. Saying so is the point: the value of the line is that the
         * next reader does not have to redo the paragraph above, and that this handler now matches
         * `updateCustomerInfo()` (`:9179`), which has always done exactly this.
         *
         * It also corrects a claim I made in the commit for the ordering fix, which said `$storeId`
         * was already reassigned here. It was not. Raised by the tenant-authz review.
         */
        $storeId = $store->id;

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => [
                'required',
                'email',
                'max:255',
                \Illuminate\Validation\Rule::unique('customers', 'email')
                    ->where('store_id', $storeId)
                    ->whereNull('deleted_at')
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

        // The ownership resolution that used to sit HERE has moved ABOVE the field validation.
        // See the note there: it was the ordering, not the check, that leaked. $store is
        // already resolved and already proven owned by the time this point is reached.

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

        // P3-SEC. THE UNIQUENESS RULE BELOW GUARDED A STRING THAT NEVER REACHED THE COLUMN.
        //
        // It validated the RAW `store_slug` while the writer below stored
        // `Str::slug($validated['store_slug'])`. MEASURED: `Str::slug()` maps 'My Shop', 'MY SHOP',
        // 'my shop' and 'my-shop' all to 'my-shop'. So a merchant acting legitimately on their OWN
        // store posted 'My Shop', `unique:stores,slug` compared 'My Shop' against the column, found
        // nothing, passed -- and 'my-shop' was written beside another store's identical 'my-shop'.
        //
        // Every storefront resolver is `where('slug', ...)->first()` (`helper.php:3578` and `:3627`,
        // `ActiveTheme:21`, and four raw lookups in `ViewServiceProvider` that choose the THEME VIEW
        // ROOT), so LOWEST ID WINS and one merchant's public URL served the other's shop. Not a
        // tenancy defect -- `resolveOwnedStore()` and the two permission limbs above all pass
        // legitimately, nothing in the victim's row is written, and a storefront is public. The
        // whole defect is validating one value and storing another.
        //
        // Normalising first makes the rule guard the value that lands. `Str::slug()` is idempotent
        // ('my-shop' -> 'my-shop'), so the writer below is unchanged and a merchant resubmitting
        // their own slug -- what a prefilled form does -- still passes.
        //
        // DELIBERATELY NOT CHANGED: an input that normalises to '' (e.g. '!!!') is still silently
        // skipped by the `!empty()` at the writer. Refusing it would add a new 422 to a path the
        // shipped admin app calls, which is a user-visible regression of exactly the kind
        // CLAUDE.md warns about. Recorded instead.
        //
        // `stores.slug` has NO UNIQUE INDEX (`2023_12_14_090259_create_stores_table.php:19` is a
        // bare `$table->string('slug')`), so the database does not backstop this and duplicates may
        // ALREADY EXIST in production. Adding the index needs an audit first; that is recorded, not
        // done here.
        if ($request->filled('store_slug')) {
            $request->merge([
                'store_slug' => \Illuminate\Support\Str::slug((string) $request->input('store_slug')),
            ]);
        }

        $validated = $request->validate([
            'store_name' => 'nullable|string|max:255',
            'store_email' => 'nullable|email|max:255',
            /* PHASE 03 M5/M6. The THIRD writer of `stores.slug`, and the one a merchant reaches
               from the shipped Flutter app -- found by an independent review of M5 after a comment
               in `User::slugs()` had claimed the mint path was the only one. See
               `Store::RESERVED_SLUGS` for why `platform` cannot belong to a store.

               THE VALUE THIS RULE SEES IS ALREADY NORMALISED. The `$request->merge()` immediately
               above runs `Str::slug()` over the input for the P3-SEC reason documented there, so
               `Platform` has already become `platform` by the time the rule runs. The `Str::slug()`
               inside the closure is therefore idempotent, and it is kept so the rule stays correct
               on its own if that merge is ever moved.

               IT IS A VALIDATION RULE RATHER THAN A HAND-WRITTEN REFUSAL so that the response is
               identical in shape to the `unique` failure beside it -- the 422 the shipped app
               already handles for a slug it cannot have. A new response SHAPE on a path a client
               calls is a contract change; a new reason for an existing shape is not.

               THE TRADE, STATED. The note above deliberately did NOT refuse an input that
               normalises to '' because that would be a new 422 on a path the app calls. This adds
               one -- for exactly one word, which no merchant can legitimately want and which no
               store can currently hold. A refusal that says why is better than a rename that
               silently hands a merchant the platform's own namespace. */
            'store_slug' => [
                'nullable', 'string', 'max:255',
                'unique:stores,slug,' . $store->id,
                static function ($attribute, $value, $fail) {
                    if (\App\Models\Store::slugIsReserved(\Illuminate\Support\Str::slug((string) $value))) {
                        $fail(__('This store slug is reserved by the platform. Please choose another.'));
                    }
                },
            ],
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
            'currency_format' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Support\Money\CurrencyDecimals::ALLOWED)], // GAP-290: max:50 admitted '' and '99'
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
                // P1-SEC-73 (invoice_logo): the name is built by ownerImageName(), not the client's.
                $fileName = $this->ownerImageName($file);
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
                // P1-SEC-73 (store_image): the name is built by ownerImageName(), not the client's.
                $fileName = $this->ownerImageName($file);
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
                    /*
                     * PHASE 03 M3-C. This was a RAW `\DB::table('theme_customizes')->updateOrInsert()`
                     * and it DID NOT REACH THE STOREFRONT. `App\Models\ThemeCustomize` is `Cachable`,
                     * a raw write does not invalidate that cache, and the storefront reads through
                     * `getThemeSetting()` which is Eloquent. MEASURED on MariaDB with
                     * `CACHE_STORE=database` -- production's setting, not the suite's `array`:
                     *
                     *     BEFORE the save   storefront: old links   flutter: old links
                     *     AFTER  the save   storefront: OLD LINKS   flutter: new links
                     *     after flushCache  storefront: new links
                     *
                     * The merchant saw the app confirm the save, because the app's own read is also
                     * raw, while the footer kept rendering the old links indefinitely.
                     *
                     * It now goes through the one controlled write path, which validates the value
                     * against the theme's declared taxonomy (`footer_repeater` is declared `slider`
                     * -- a repeater -- in all three shipped themes) and flushes the model cache
                     * AFTER COMMIT. The request and response SHAPE are unchanged, so the shipped
                     * Flutter app is unaffected -- but the CONDITION is not, and an earlier version
                     * of this comment said it was. `social_media` is now appended only when the
                     * service actually wrote something. A refusal (no permission, or a store with no
                     * theme selected) returns null, the field is simply omitted from
                     * `updated_fields`, and the status is the same 200: a new status code would
                     * force a logout in that app. A store whose `theme_id` is empty therefore can
                     * no longer save social links through this endpoint; that is Phase 03's rule
                     * that a theme write needs a theme, recorded here rather than hidden.
                     */
                    $written = app(\App\Services\Theme\ThemeConfigurationService::class)
                        ->writeSettings(
                            $storeId,
                            (string) $store->theme_id,
                            ['footer_repeater' => json_encode($socialData)],
                            $adminUser
                        );

                    if (!empty($written)) {
                        $updatedFields[] = 'social_media';
                    }
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
        /*
         * P4-F114 / GAP-125. THIS BRANCH USED TO SIGN MERCHANT STAFF OUT OF THE WHOLE APP.
         * =========================================================================================
         *
         * It read:
         *
         *     if (!$adminUser || !in_array($adminUser->type, ['admin', 'super admin'])) { ... 401 }
         *
         * `users.type` is `'staff'` for every staff member, so the condition was TRUE for all of
         * them -- and the shipped Flutter admin app maps ANY 401 onto `_handleUnauthorized()`,
         * which clears preferences and forces a re-login (`repo-B lib/network/network.dart:285-288`).
         * So a staff member who tapped "save" on a customer was not told they lacked a right: they
         * were logged out, with no explanation and their draft lost. Measured before the fix: 401
         * for a staff member holding `Manage Customer` AND for one holding nothing.
         *
         * NOT MIDDLEWARE, established by reading rather than assumed. `custom.auth` resolves the
         * Sanctum token and logs a `User` into the `web` guard, so `auth()->user()` returned the
         * staff member perfectly well. The refusal was this string comparison, right here.
         *
         * **IT WAS A TYPE CHECK STANDING IN FOR A PERMISSION CHECK, AND WRONG IN BOTH DIRECTIONS.**
         * It admitted every `type = 'admin'` row -- which public registration hands every
         * self-registered merchant -- whatever they had been granted, and refused every staff member
         * whatever they had been granted. The one thing it never consulted was the permission. So
         * correcting only the status would have left half the defect in place; the type limb is
         * replaced by the real gate below, after the store resolves, because the grant is per store.
         *
         * THE `!$adminUser` LIMB STAYS, AND STAYS 401, DELIBERATELY. It is the only branch where the
         * caller is genuinely not a panel principal, and `custom.auth` makes it reachable: a
         * `Customer` or `DeliveryBoy` token is logged into a DIFFERENT guard by tokenable class, so
         * `auth()->user()` is null while the middleware passed. The shipped ADMIN app cannot produce
         * it -- it only ever holds a `User` token -- and changing it would be a behaviour change on
         * a path no client in either repository is known to take. Recorded rather than widened.
         */
        $adminUser = auth()->user();
        if (!$adminUser) {
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

        /*
         * P4-F114 / GAP-125. THE PERMISSION GATE THAT REPLACES THE `users.type` LIMB ABOVE.
         *
         * `Manage Customer`, AND THERE IS NO WEB TWIN TO COPY -- measured, not assumed:
         * `Route::resource('customer', ...)->except(['edit', 'update'])` (`routes/web.php:1420`)
         * leaves the web update route unregistered because no handler exists for it. So the admin
         * API is the ONLY customer-update surface on the platform, and the name has to come from
         * this resource's own siblings, all three of which already say `Manage Customer`:
         *
         *   `addCustomer()` (CREATE) gates on `MerchantDashboardAbilities::PERMISSION_CUSTOMERS`
         *   and admits staff who hold it.
         *   `destroy()` (DELETE) gates on the same constant since GAP-142's write half.
         *   `CustomerController::customerStatus()` on the web writes `customers.status` -- one of
         *   the very columns this handler writes -- gated on `Manage Customer` (`:471`).
         *
         * THE SAME CONSTANT, not the literal, so the three halves of one resource cannot drift.
         *
         * WHAT MOVES, and the two halves are NOT the same kind of change -- an earlier version of
         * this note blurred them, and the independent review was right to split them:
         *
         *   Staff WITHOUT the name: 401 -> 403, session intact. For them the ACCESS decision really
         *   is unchanged and only the status moves, which was the user-visible defect.
         *
         *   Staff WITH `Manage Customer`: 401 -> 200, AND THE UPDATE NOW RUNS. That is a genuine
         *   ACCESS WIDENING -- the only one in this slice; every other gate here admits a strict
         *   subset of who could act before. It is deliberate (the old `in_array($adminUser->type,
         *   ...)` refused them regardless of what their merchant granted, which is the defect) but
         *   it must be stated as a widening rather than as a status change, BECAUSE OF WHAT THIS
         *   HANDLER WRITES: `customers.email` (:10518, applied at :10523). Whoever holds that
         *   address owns every storefront recovery flow that trusts it, so this is a takeover
         *   primitive and not a privacy field.
         *
         *   WHY IT IS STILL RIGHT: the sibling CREATE already admits the same principal to set a
         *   customer's `password` and `email_verified_at` (`addCustomer` :9746-9756, both writes
         *   pinned by its own suite), and the web's `customerStatus()` admits the same name. It
         *   stays inside the tenant. A merchant who does not want staff rewriting shopper addresses
         *   withholds `Manage Customer`, which is the control the product already gives them.
         *
         * A member of another tenant now gets the ownership 403 above instead of a 401.
         *
         * THE OWNER LIMB IN `callerMayActInStore()` IS WHAT KEEPS A BARE MERCHANT WORKING. The old
         * type check admitted every `type = 'admin'` row unconditionally, so replacing it with a
         * permission-only gate would refuse a merchant holding no explicit grant --
         * `AdminApiTenancyTest::test_update_customer_still_works_on_your_own_customer` drives
         * exactly that principal and was green before this change. The operator keeps working
         * through `MerchantPermissions` answering `WILDCARD`, which is how the old check's explicit
         * `'super admin'` entry is preserved; `isAbleTo()` alone would refuse them.
         *
         * BELOW THE OWNERSHIP 403 AND ABOVE THE VALIDATOR AND THE CUSTOMER LOOKUP, so the two
         * refusals are indistinguishable and an unpermitted caller cannot use the 404 branch or the
         * email-uniqueness rule to probe which customers exist inside the store.
         */
        if (! $this->callerMayActInStore(
            [\App\Support\MerchantDashboardAbilities::PERMISSION_CUSTOMERS],
            $store->id
        )) {
            return response()->json([
                'status' => 0,
                'message' => 'Permission denied.',
                'data' => null
            ], 403);
        }

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
                    ->whereNull('deleted_at')
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


        /*
         * GAP-142 / P4-F113 (READ HALF) — THE PERMISSION LIMB, BELOW THE TENANCY GATE.
         *
         * `resolveOwnedStore()` above already refuses a store this caller does not own, so this is
         * not a tenancy fix: it is that EVERY MEMBER OF THE STORE read the same customer book, with the same money, behind a
         * search term -- whatever they were
         * granted.
         *
         * `Manage Customer` ALONE, NOT the `Show Customer` pair, AND THAT IS A CORRECTION. The
         * first version admitted either name on the argument that the web admits either "on a
         * customer READ". Measured, that is true of the web's DETAIL screen (`CustomerController::
         * show():335`, gated `:339-341`: operator OR `Show Customer`) and FALSE of its LIST, which
         * requires `Manage Customer` only (`index():312`, gated `:314`). And the difference is not
         * cosmetic: the web puts the MONEY COLUMNS on the list behind that name
         * (`CustomerDataTable:296-297`, `Payments Collected` / `Net Retained`), while the
         * `Show Customer` detail screen is handed `compact('orders')` and shows none. This payload
         * carries `total_spend`, `aov`, the whole `ledger` block and the default postal address, so
         * admitting `Show Customer` here would hand a deliberately-restricted staff member money
         * the web withholds from them. Found by the independent review of this slice.
         *
         * BELOW the tenancy gate, not above it: a caller from another tenant must keep getting the
         * STORE refusal they get today, which shipped suites assert. 403 through
         * `storePermissionDenied()` -- never 401 (the shipped app force-clears its session on one).
         */
        if (! $this->callerMayActInStore(['Manage Customer'], $store->id)) {
            return $this->storePermissionDenied();
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

        /*
         * GAP-142 / P4-F108. THE PERMISSION AXIS, ON THE ONE ROUTE IN THIS SET THAT MOVES MONEY.
         *
         * `resolveOwnedStore()` above settles TENANCY, and the order and the driver are each looked
         * up with a `store_id` predicate below. None of that asks WHO INSIDE THE STORE may act, and
         * nothing else did either -- so any authenticated member of the tenant, holding no order
         * permission of any kind, reached three writes:
         *
         *   1. `DeliveryEarning::where('order_id', ...)->delete()` -- the rider's accrued commission
         *      for that order, destroyed. `delivery_earnings` has no soft delete and no audit row,
         *      so there is nothing to roll back.
         *   2. `orders.deliveryboy_id` -- the parcel handed to a different rider.
         *   3. a fresh `delivery_earnings` insert crediting the NEW rider.
         *
         * So an unpermitted staff member could move a commission between riders, or delete one
         * outright by assigning a rider with commission disabled. Measured before the fix: HTTP 200,
         * `{"status":"success","message":"Order assigned successfully."}`, and the commission moved.
         *
         * `Manage Order` IS THE WEB TWIN'S OWN NAME, not an analogy. `OrderController::order_assign()`
         * authorises through `$this->authorize('assign', $order)` and `OrderPolicy::assign()` is
         * `allow($actor, $order, 'Manage Order')` -- the singular merchant family, which is what
         * every order surface in `app/` reads. (The plural `Manage Orders` is seeded too and no gate
         * anywhere reads it.) `Manage Deliveryboy` was considered and rejected: it governs the RIDER
         * RECORD, so naming it would let a staff member who may edit a rider's phone number move
         * that rider's commissions.
         *
         * AHEAD OF THE VALIDATOR AND OF EVERY LOOKUP, so an unpermitted caller cannot use this
         * handler's 404s to probe which order ids and which rider ids exist inside the store.
         *
         * `callerMayActInStore()` carries the limb ordering and the reasoning for it; `$storeId` is
         * the RESOLVED id, so the grant is evaluated against the store whose rows are about to
         * change.
         */
        if (! $this->callerMayActInStore(['Manage Order'], $storeId)) {
            return $this->orderPermissionDenied();
        }

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

                /*
                 * P1-SEC-42 -- ONE COMMISSION ROW PER ORDER. The same defect as
                 * OrderController::order_assign(), in the handler the Flutter admin app calls, and
                 * fixed the same way. Read the long note there for the measurement.
                 *
                 * In short: the cleanup above is gated on a CHANGE of driver, the insert was gated
                 * on nothing, so re-assigning the SAME driver credited them again. 3 POSTs produced
                 * 3 rows and 6.00 owed where 2.00 is correct.
                 *
                 * `DB::beginTransaction()` does NOT help here and it is worth saying why: each
                 * request is its own transaction and each one commits a valid-looking duplicate.
                 * A transaction makes a write atomic, not idempotent.
                 *
                 * `exists()` rather than an unconditional delete or `updateOrCreate`, because both
                 * of those reset a row already moved to `paid` back to `pending`. Evaluated after
                 * the delete, so a genuine driver change still replaces the row.
                 */
                $alreadyRecorded = DeliveryEarning::where('order_id', $order->id)->exists();

                if ($commissionPercentage > 0 && $shippingCost > 0 && ! $alreadyRecorded) {
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