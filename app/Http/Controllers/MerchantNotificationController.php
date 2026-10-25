<?php

namespace App\Http\Controllers;

use App\Models\StoreNotification;
use App\Policies\UserPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P2-06-3 -- the merchant's notifications on the WEB. Workstream H.
 * =============================================================================================
 *
 * THE ROWS ALREADY EXISTED AND THE WEB COULD NOT SEE THEM. `store_notifications` is written by
 * `App\Services\StoreNotificationService` on every order created and every order paid, and the
 * shipped Flutter app reads them through `GET api/admin/notification`
 * (`Api\DashboardController::noti`). Nothing in the merchant web admin read them at all -- so a
 * merchant who works at a desk had no notifications and a merchant with the app had them. This
 * closes that half of the parity, against the SAME TABLE, without a schema change and without
 * touching the API the app depends on.
 *
 * TENANCY IS EXPLICIT, AND THERE IS NOTHING ELSE. `StoreNotification` is **not** in
 * `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and the
 * model adds NO predicate for any principal. The `where('store_id', ...)` in every query below
 * is the only control. The store comes from the authenticated principal --
 * `getCurrentStore($user->id)`, with the id passed explicitly because the no-argument form reads
 * the MUTABLE default guard -- never from request input.
 *
 * READING ACROSS STORES IS THE OPERATOR'S CONTRACT; WRITING ACROSS THEM IS NOT. `scope()` omits
 * the store predicate for an operator, which is right for the three reads. `markAllRead` adds one
 * back explicitly, because a mass `update()` with no tenant predicate is one button that clears
 * every merchant on the platform.
 *
 * AND AN UNRESOLVED TENANT IS REFUSED rather than queried. A principal with no current store
 * would otherwise reach `where('store_id', null)`; that compiles to `IS NULL` and happens to
 * match nothing today, but relying on that is relying on an accident. The operator is exempt,
 * because reading across stores is their contract.
 *
 * PERMISSION IS BY NOTIFICATION TYPE, NOT BY SCREEN, and that is the substantive decision here.
 * A notification is not a neutral envelope: `order_created`'s own message carries an order
 * reference, and its `data` payload carries `final_price`, `payment_type` and `payment_status`.
 * Letting a staff member who may not open the order list read the notification about it would
 * route around `Manage Order` through the bell. So each type names the permission it needs, the
 * list is filtered to the types the caller holds, and -- operator first, because Laratrust has
 * no super-admin bypass.
 *
 * AN UNREGISTERED TYPE IS REFUSED, deliberately, and `MerchantNotificationAuthorizationTest`
 * pins the registry so adding a type to the service FAILS A TEST rather than silently
 * publishing an unguarded one to the web. Refusing is the safe direction for a row that may
 * carry a price or a customer's name; the cost is that a new type needs one line here, which is
 * exactly the review this is for.
 *
 * WHAT IS NOT CHANGED, AND WHY. `Api\DashboardController::noti` returns `count` as the TOTAL
 * number of notifications, not the unread count, and the Flutter app diffs it against a
 * locally-stored previous total (`NotificationController._calculateUnreadCount`) to derive its
 * badge -- it never reads `is_read`. Making that endpoint return an unread count would break
 * that arithmetic in the shipped app, which is a public-response change and an owner decision.
 * So the API is untouched. Marking a row read here is safe for the app precisely because the
 * app's number is a total, which marking does not change.
 */
class MerchantNotificationController extends Controller
{
    /** How many the bell shows. The full list is its own screen. */
    private const FEED_LIMIT = 8;

    /**
     * THE REGISTRY: which permission each notification type requires.
     *
     * Both current types are order notifications and both name `Manage Order` -- the permission
     * the ORDER LIST gates on (`OrderController::index`), so the bell cannot be a way around the
     * list.
     *
     * THE ORDER PREVIEW GATES ON SOMETHING ELSE: `Show Order`, via `mayUseOrderScreen`
     * (`OrderController::order_preview`). An earlier version of this note said the two were the
     * same permission, which was wrong -- found by the independent review. The consequence is
     * not a hole (the preview fails closed on its own) but a "View order" button that answers
     * 403, so the view is told separately whether this caller holds `Show Order` and omits the
     * button when they do not.
     *
     * A type absent from this map is refused; see the class note.
     *
     * @var array<string, string>
     */
    public const TYPE_PERMISSIONS = [
        'order_created' => 'Manage Order',
        'order_paid' => 'Manage Order',
    ];

    /** The full-page list. */
    public function index(Request $request)
    {
        $user = auth()->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $context = $this->context($user);

        if ($context === null) {
            // No tenant resolved: an empty screen, not a query. See the class note.
            return view('notification.index', [
                'notifications' => collect(),
                'unread' => 0,
                'readableTypes' => [],
                'noTenant' => ! UserPolicy::isOperator($user) && empty(getCurrentStore($user->id)),
                'mayOpenOrders' => false,
            ]);
        }

        [$isOperator, $storeId, $types] = $context;

        return view('notification.index', [
            'notifications' => $this->scope($storeId, $isOperator, $types)
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'unread' => $this->unreadCount($storeId, $isOperator, $types),
            'readableTypes' => $types,
            'noTenant' => false,
            // SEPARATE FROM THE TYPE GATE, because the preview gates on `Show Order` and the
            // notification on `Manage Order`. A caller can hold one and not the other.
            'mayOpenOrders' => $isOperator || $user->isAbleTo('Show Order'),
        ]);
    }

    /**
     * The bell's JSON: the unread count and the latest few.
     *
     * Rendered server-side into strings the client only has to place, because the client has no
     * translator and no currency formatter -- the same reason the panel component takes its
     * loading and error text as props.
     */
    public function feed(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user === null) {
            return response()->json(['unread' => 0, 'items' => []], 403);
        }

        $context = $this->context($user);

        if ($context === null) {
            return response()->json(['unread' => 0, 'items' => []]);
        }

        [$isOperator, $storeId, $types] = $context;

        $items = $this->scope($storeId, $isOperator, $types)
            ->orderByDesc('id')
            ->limit(self::FEED_LIMIT)
            ->get()
            ->map(fn (StoreNotification $n) => [
                'id' => $n->id,
                'title' => (string) $n->title,
                'message' => (string) $n->message,
                'read' => (bool) $n->is_read,
                'when' => $n->created_at ? $n->created_at->diffForHumans() : null,
                'href' => $this->hrefFor($n),
            ])
            ->all();

        return response()->json([
            'unread' => $this->unreadCount($storeId, $isOperator, $types),
            'items' => $items,
        ]);
    }

    /** Mark one notification read. POST, because it changes state. */
    public function markRead(Request $request, $id)
    {
        $user = auth()->user();

        if ($user === null) {
            return response()->json(['message' => __('Permission denied.')], 403);
        }

        $context = $this->context($user);

        if ($context === null) {
            return response()->json(['message' => __('Notification not found.')], 404);
        }

        [$isOperator, $storeId, $types] = $context;

        // `filter_var`, not `is_numeric`: is_numeric accepts '5.9' and rejects '5abc', while
        // MySQL coerces '5abc' to row 5 in a `WHERE id = ?` comparison.
        $notificationId = filter_var($id, FILTER_VALIDATE_INT);

        if ($notificationId === false || $notificationId <= 0) {
            return response()->json(['message' => __('Notification not found.')], 404);
        }

        /*
         * THE SAME PREDICATE AS THE READ, not a weaker one. The row is found through
         * `scope()`, so a notification of a type this caller may not read cannot be marked
         * either -- a write gated more loosely than the read it accompanies is how a guard
         * authorises one row while the handler touches another.
         */
        $notification = $this->scope($storeId, $isOperator, $types)
            ->where('id', $notificationId)
            ->first();

        if ($notification === null) {
            return response()->json(['message' => __('Notification not found.')], 404);
        }

        $notification->is_read = true;
        $notification->save();

        return $this->marked($request, $this->unreadCount($storeId, $isOperator, $types));
    }

    /** Mark every notification this caller can read, read. */
    public function markAllRead(Request $request)
    {
        $user = auth()->user();

        if ($user === null) {
            return response()->json(['message' => __('Permission denied.')], 403);
        }

        $context = $this->context($user);

        if ($context === null) {
            return $this->marked($request, 0);
        }

        [$isOperator, $storeId, $types] = $context;

        /*
         * THE WRITE IS ALWAYS CONFINED TO ONE STORE, including for the operator -- and the
         * operator is the whole reason this is not just `scope()`.
         *
         * `scope()` deliberately omits the store predicate for an operator, because reading
         * across stores is their contract and every other controller here treats them that way.
         * A WRITE is a different contract. Without this limb one POST by a `super admin` flipped
         * `is_read` on EVERY tenant's rows -- clearing every merchant's badge at once, with no
         * mark-unread anywhere to undo it. Found by the independent review; the comment that
         * used to sit here claimed the opposite of what the code did.
         *
         * `$user->current_store` DIRECTLY, not `$storeId`. `getCurrentStore()` answers the
         * literal **1** for a `super admin` whose `current_store` is empty
         * (`app/Helper/helper.php:636-637`), so confining the write through it would silently
         * mass-mark whichever tenant happens to be store 1. An operator who is not looking at a
         * store marks nothing, which is the honest answer: "all" has no referent.
         */
        $writeStore = $isOperator ? $user->current_store : $storeId;

        if (empty($writeStore)) {
            return $this->marked($request, $this->unreadCount($storeId, $isOperator, $types));
        }

        // Scoped, so "all" means all of THIS store's and of the types this caller may read.
        $this->scope($storeId, $isOperator, $types)
            ->where('store_id', $writeStore)
            ->where('is_read', false)
            ->update(['is_read' => true, 'updated_at' => now()]);

        return $this->marked($request, $this->unreadCount($storeId, $isOperator, $types));
    }

    /**
     * THE PAGE WORKS WITHOUT JAVASCRIPT, so the two marks answer in whichever shape the caller
     * asked for.
     *
     * `resources/views/notification/index.blade.php` posts a real `<form>` with a token -- so a
     * browser with no script, or a script that failed to load, still marks the row and lands
     * back on the list rather than staring at a JSON body. The bell's fetch sends
     * `Accept: application/json` and gets the count it needs to redraw the badge.
     *
     * `expectsJson()`, not `ajax()`: yajra's own leg-picking in this codebase requires BOTH
     * `ajax()` and `wantsJson()`, and a request carrying only `X-Requested-With` silently takes
     * the wrong branch. Here the question is only what the caller can read, which is the Accept
     * header.
     */
    private function marked(Request $request, int $unread)
    {
        if ($request->expectsJson()) {
            return response()->json(['unread' => $unread]);
        }

        return redirect()->back();
    }

    // =============================================================================== internals

    /**
     * `[$isOperator, $storeId, $readableTypes]`, or null when this principal must be served
     * nothing at all.
     *
     * One place, so the four handlers above cannot drift: three of them used to be the shape
     * that has produced the most defects in this codebase -- authenticate, then act on request
     * input.
     *
     * @return array{0: bool, 1: mixed, 2: array<int, string>}|null
     */
    private function context($user): ?array
    {
        $isOperator = UserPolicy::isOperator($user);
        $storeId = getCurrentStore($user->id);

        if (! $isOperator && empty($storeId)) {
            return null;
        }

        $types = $this->readableTypes($user, $isOperator);

        /*
         * No readable type means no readable row. Returning null keeps every handler from having
         * to special-case an empty `whereIn`, which in SQL matches nothing anyway -- but saying
         * so explicitly is cheaper to read than relying on that.
         *
         * BOTH NULL CAUSES LOOK THE SAME HERE AND MUST NOT LOOK THE SAME TO THE MERCHANT.
         * `index()` therefore computes `noTenant` separately: a merchant whose store was deleted
         * was being told "your role does not include reading them", which is a different and
         * wrong explanation. Found by the independent review.
         */
        if ($types === []) {
            return null;
        }

        return [$isOperator, $storeId, $types];
    }

    /**
     * The types this caller may read. Operator first -- `isAbleTo()` alone refuses the platform
     * operator, and reversing these two limbs is a lockout rather than a tightening.
     *
     * @return array<int, string>
     */
    private function readableTypes($user, bool $isOperator): array
    {
        if ($isOperator) {
            return array_keys(self::TYPE_PERMISSIONS);
        }

        $readable = [];

        foreach (self::TYPE_PERMISSIONS as $type => $permission) {
            if ($user->isAbleTo($permission)) {
                $readable[] = $type;
            }
        }

        return $readable;
    }

    /**
     * The one query shape every handler starts from: this store's rows, of a readable type.
     *
     * `whereIn('type', $types)` rather than filtering in PHP after the fetch, so the LIMIT
     * applies to rows the caller may actually see -- filtering afterwards would return eight
     * rows and show two.
     */
    private function scope($storeId, bool $isOperator, array $types)
    {
        $query = StoreNotification::query()->whereIn('type', $types);

        if (! $isOperator) {
            $query->where('store_id', $storeId);
        }

        return $query;
    }

    private function unreadCount($storeId, bool $isOperator, array $types): int
    {
        return (int) $this->scope($storeId, $isOperator, $types)->where('is_read', false)->count();
    }

    /**
     * Where a notification leads.
     *
     * The order PREVIEW, not the full order page: it is the read-only surface, its own uri is
     * registered in `OrderDocumentAccessGuard`, and that request is judged again on its own. A
     * link from here is not authorisation for what it points at.
     */
    private function hrefFor(StoreNotification $notification): ?string
    {
        if ($notification->reference_type === 'order' && ! empty($notification->reference_id)) {
            return route('order.preview', (int) $notification->reference_id);
        }

        return null;
    }
}
