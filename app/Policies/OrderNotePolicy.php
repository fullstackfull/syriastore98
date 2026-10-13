<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;

/**
 * Authorization for order notes -- the web half of
 * NEW-unauthenticated-order-notes-write and its two cross-tenant siblings.
 *
 * WHAT WAS WRONG, with the evidence.
 *
 * OrderNoteController carried a permission check and nothing else:
 *
 *   store():   `if (auth()->user() && auth()->user()->isAbleTo('Create Order Note'))`
 *              then `$note->order_id = $request->order_id` with the order id
 *              taken straight off the request and never looked up. No check
 *              that the order exists, and none that it belongs to the caller's
 *              store.
 *   destroy(): `destroy(OrderNote $OrderNote)` -- an IMPLICITLY BOUND model and
 *              a bare `isAbleTo('Delete Order Note')`. The bound id was the
 *              only authorization token, so any holder of that permission
 *              deleted ANY store's order note. The shared `admin` role carries
 *              'Delete Order Note' by default (Utility.php:2957 lists it in
 *              $companyNewPermission, which calls givePermission()), and public
 *              registration grants type=admin, so every self-registered
 *              merchant held it.
 *
 * That is the identical shape as the RoleController limb of R4-04, which is why
 * this policy is written to the same pattern as RolePolicy: the permission is
 * checked first, then a platform administrator passes, then tenancy decides.
 *
 * Order-note tenancy is `order_notes.store_id`, which is the predicate
 * OrderController::order_view already applies when it LISTS notes --
 * `OrderNote::where('order_id', $id)->where('store_id', getCurrentStore())`
 * (OrderController.php:291). The read was scoped and the writes were not, which
 * is omission rather than design.
 *
 * Both 'Create Order Note' and 'Delete Order Note' ARE seeded
 * (Utility.php:2792-2793 create the rows, :2956-2957 grant them to the shared
 * `admin` role), so unlike the finance permissions there is no missing-row
 * problem here and the permission check can come first without locking anybody
 * out.
 */
class OrderNotePolicy
{
    public const PLATFORM_ADMIN = 'super admin';

    protected function isPlatformAdmin(?User $actor): bool
    {
        return $actor !== null && $actor->type === self::PLATFORM_ADMIN;
    }

    /**
     * May the actor attach a note to this order?
     *
     * $order is the result of a real lookup by the caller, so a null here means
     * "no such order", which is a denial rather than a 404: answering 404 would
     * tell an attacker which order ids exist in other stores.
     */
    public function create(User $actor, ?Order $order): bool
    {
        if (!$actor->isAbleTo('Create Order Note')) {
            return false;
        }

        if ($order === null) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        $currentStore = getCurrentStore();

        // Fail CLOSED when no tenant resolves. getCurrentStore() returns the
        // user's current_store, which can legitimately be null, and
        // `null === null` must not become "allowed" for a note whose store_id
        // is also null.
        if (empty($currentStore) || empty($order->store_id)) {
            return false;
        }

        return (int) $order->store_id === (int) $currentStore;
    }

    public function delete(User $actor, OrderNote $note): bool
    {
        if (!$actor->isAbleTo('Delete Order Note')) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        $currentStore = getCurrentStore();

        if (empty($currentStore) || empty($note->store_id)) {
            return false;
        }

        return (int) $note->store_id === (int) $currentStore;
    }
}
