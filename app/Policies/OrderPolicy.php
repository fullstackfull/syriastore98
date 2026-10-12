<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Authorization for order mutation -- SEC-F7 and NEW-order-payment-status
 * (authorization half; the authentication half is the routes/web.php brace
 * move).
 *
 * The omission-not-design contrast is visible inside OrderController itself:
 * destroy() checks isAbleTo('Delete Order') while its three siblings check
 * nothing, and order_assign validates only
 * 'order_id' => 'required|integer|exists:orders,id' -- input validation, not
 * authorization.
 */
class OrderPolicy
{
    public const PLATFORM_ADMIN = 'super admin';

    protected function isPlatformAdmin(?User $actor): bool
    {
        return $actor !== null && $actor->type === self::PLATFORM_ADMIN;
    }

    /**
     * Is this order inside the actor's current tenant?
     *
     * NOTE for API callers: getCurrentStore() is session-derived
     * (app/Helper/helper.php:363 reads auth()->user() on the default web
     * guard). CustomAuth logs the token owner into that same `web` guard for a
     * User tokenable, so getCurrentStore() does resolve for Flutter callers --
     * but it resolves to the TOKEN OWNER's current_store, which is correct and
     * is now itself trustworthy because R4-03 constrains who may write it.
     */
    protected function sameStore(User $actor, Order $order): bool
    {
        $currentStore = getCurrentStore();

        if (empty($currentStore) || empty($order->store_id)) {
            return false;
        }

        return (int) $order->store_id === (int) $currentStore;
    }

    protected function allow(User $actor, Order $order, string $permission): bool
    {
        // The platform-admin check MUST come first. Previously isAbleTo() ran ahead of
        // it and returned false, so this method was unreachable for a platform operator:
        // on a database built from source no role holds 'Manage Order', which locked the
        // operator out of updatePaymentStatus, updateStatus, assign and delete entirely.
        // UserPolicy::resetPassword already had the correct order; this now matches it.
        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        if (!$actor->isAbleTo($permission)) {
            return false;
        }

        return $this->sameStore($actor, $order);
    }

    /**
     * NEW-order-payment-status: order_payment_status was an ANONYMOUS arbitrary
     * write of payment_status onto any order in any store.
     */
    public function updatePaymentStatus(User $actor, Order $order): bool
    {
        return $this->allow($actor, $order, 'Manage Order');
    }

    /**
     * SEC-F7.
     *
     * A delivery-boy limb was proposed here, comparing $order->deliveryboy_id to
     * $actor->id so an assigned delivery boy could still update a status. It is
     * deliberately NOT present, for two independent reasons:
     *
     *   1. It cannot fire. This method's actor is type-hinted User, i.e. the `web`
     *      guard. Delivery boys authenticate on the separate `deliveryboy` guard
     *      (config/auth.php:47) against the separate delivery_boys table
     *      (2023_12_26_112245_create_delivery_boys_table.php), so a delivery boy is
     *      never the actor on this route (routes/web.php:1011, inside the auth group).
     *   2. It is a cross-tenant bypass. deliveryboy_id is a delivery_boys.id and
     *      $actor->id is a users.id — two unrelated sequences that both start at 1,
     *      so collisions are the norm, not the exception. Any merchant whose users.id
     *      happens to equal an order's deliveryboy_id would have been able to change
     *      that order's status in ANY store, skipping both the store check and the
     *      permission check.
     *
     * The delivery-boy flow is served by its own guard and its own endpoints; if it
     * ever needs a policy, it needs one written against the DeliveryBoy model.
     */
    public function updateStatus(User $actor, Order $order): bool
    {
        return $this->allow($actor, $order, 'Manage Order');
    }

    public function assign(User $actor, Order $order): bool
    {
        return $this->allow($actor, $order, 'Manage Order');
    }

    public function delete(User $actor, Order $order): bool
    {
        return $this->allow($actor, $order, 'Delete Order');
    }
}
