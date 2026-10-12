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
        if (!$actor->isAbleTo($permission)) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        return $this->sameStore($actor, $order);
    }

    /**
     * NEW-order-payment-status: order_payment_status was an ANONYMOUS arbitrary
     * write of payment_status onto any order in any store.
     */
    public function updatePaymentStatus(User $actor, Order $order): bool
    {
        return $this->allow($actor, $order, 'Edit Order');
    }

    /**
     * SEC-F7. The delivery-boy limb is MANDATORY and is the positive case this
     * fix would otherwise break: a delivery boy must still be able to update
     * the status of an order assigned to them. Without it the whole delivery
     * flow stops.
     */
    public function updateStatus(User $actor, Order $order): bool
    {
        if (!empty($order->deliveryboy_id) && (int) $order->deliveryboy_id === (int) $actor->id) {
            return true;
        }

        return $this->allow($actor, $order, 'Edit Order');
    }

    public function assign(User $actor, Order $order): bool
    {
        return $this->allow($actor, $order, 'Edit Order');
    }

    public function delete(User $actor, Order $order): bool
    {
        return $this->allow($actor, $order, 'Delete Order');
    }
}
