<?php

namespace App\Support\Orders;

use App\Models\User;
use App\Services\MerchantPermissions;
use Illuminate\Support\Facades\Auth;

/**
 * MAY THIS PRINCIPAL CHANGE THIS ORDER'S STATE? ONE DEFINITION. GAP-277, originally `P4-F037`.
 * =================================================================================================
 *
 * WHY THIS CLASS EXISTS RATHER THAN A THIRD COPY OF THE PREDICATE.
 *
 * The question was already answered -- correctly -- in `Api\DashboardController` as the private
 * pair `callerMayChangeOrderState()` / `callerIsStoreOwner()`, and the two admin order doors
 * (`api/admin/status-change`, `api/{slug}/order-cancel`) ask it. The two STOREFRONT doors,
 * `Api\ApiController::order_status_change` and `::product_return`, never did -- GAP-277 -- and they
 * are in a different class, so the only ways to close them were to copy the predicate or to share
 * it. A copy is how two surfaces drift into different ideas of who may act, which is the hazard
 * `callerMayChangeOrderState()`'s own docblock warns about, and this family has already produced one
 * live divergence between two doors. So the logic moved HERE, and the controller methods became
 * one-line delegations: their docblocks, their names and their call sites are untouched, and all
 * four doors now ask one question.
 *
 * WHAT IT ANSWERS, AND WHAT IT DOES NOT. This is the PERMISSION axis only. "Whose order is it" is a
 * different question, settled before any call to this -- `DashboardController::resolveChangeableOrder()`
 * on the admin side and `ApiController::resolveMutableOrder()` on the storefront side -- and neither
 * replaces the other. `Order` is not in `config/tenancy.php`'s `scoped_models`, so those explicit
 * ownership predicates are the ONLY tenancy control on the row; this class must never be read as
 * one. It also must be asked AFTER ownership: a caller with no claim on an order must get the
 * ownership refusal, or the door tells them the order exists and that only a permission was
 * missing.
 *
 * THE THREE LIMBS, IN THIS ORDER, EACH FOR A MEASURED REASON.
 *
 *   1. THE ASSIGNED DRIVER IS NOT GATED. A `DeliveryBoy` is not a Laratrust subject --
 *      `MerchantPermissions::names()` answers `[]` for anything that is not a `User`, and
 *      `isAbleTo()` on one is a fatal -- and carrying the parcel IS the authorization, recorded in
 *      `orders.deliveryboy_id`. A gate that required a permission of every caller would refuse
 *      every driver on the platform.
 *
 *   2. THE STORE'S OWNER IS ADMITTED WITHOUT HOLDING ANYTHING, and that is not an escalation: the
 *      owner is the principal who CREATES the roles and grants the permissions in their own tenant,
 *      so there is no permission they cannot give themselves through the product. Refusing them is
 *      a live breakage with no security gain. Two earlier packages broke exactly this, and the test
 *      written to catch it says so in as many words -- *"The predicate is too tight -- that is a
 *      defect, not a fix."*
 *
 *      `$store->created_by === $user->id` AND NOT `creatorId()`: `User::creatorId()` returns the
 *      OWNER's id for a staff member too, so a `creatorId()` comparison would admit every employee
 *      and dissolve the permission gate entirely -- which is the GAP-277 defect restated. The
 *      owner's own id is the only spelling that separates the tenant root from the tenant.
 *
 *   3. THE PERMISSION, through `MerchantPermissions` and never `isAbleTo()`, for two reasons.
 *      `allPermissions()` unions every role the principal holds with NO store predicate, so a staff
 *      member granted a name in store A would carry it into store B of the same merchant; and
 *      `isAbleTo()` alone REFUSES THE PLATFORM OPERATOR -- this codebase has no super-admin bypass,
 *      operator status is a string comparison on `users.type`. `MerchantPermissions::allowsAny()`
 *      answers the wildcard for the operator, which is why the operator limb is not spelled out
 *      here: it is inside that call, and it happens before any role is consulted.
 *
 *      `$order->store_id` AND NOT `current_store`: the order names its own store, and that is the
 *      store whose grants decide.
 *
 * WHAT GAP-277 MEASURED THROUGH THE TWO STOREFRONT DOORS BEFORE THIS WAS ASKED THERE, because the
 * cost is money and goods rather than a status field. A `type='staff'` row holding NO ROLE AT ALL,
 * with a never-expiring token from `POST /api/admin/adminlogin`:
 *
 *     order-status-change cancel   HTTP 200 {"status":1}  delivered_status 0 -> 2, cancel_date
 *                                  stamped, store_order_financials.payment_status paid ->
 *                                  cancelled (the merchant's entitlement reversed), and
 *                                  products.product_stock 5 -> 7 (the goods back on the shelf)
 *     product-return               HTTP 200 "Product return successfully."  product_stock 5 -> 7
 *                                  AND orders.final_price 100 -> 50, with the line stamped
 *                                  `return` and `stock_restored`
 *
 * Pinned by `tests/Feature/Security/Gap277StorefrontOrderStatePermissionTest`, whose control cases
 * drive the owner holding nothing, a staff member holding `Manage Order`, the assigned driver, the
 * platform operator and the owning shopper -- because a gate that refuses everybody passes every
 * hostile case and breaks the product.
 */
final class OrderStateChangeAuthorization
{
    /** The permission any one of whose holders may move an order. The admin doors' own name. */
    public const PERMISSION = 'Manage Order';

    /**
     * Whether the CURRENT principal may change this order's state.
     *
     * Reads the session rather than taking a principal, so that it cannot be asked about somebody
     * other than the caller: `CustomAuth` logs a token's owner into exactly one guard, matched to
     * its tokenable class, and these are the same expressions the two resolvers use to decide which
     * ownership branch admitted the order.
     *
     * @param  \App\Models\Order|object|null  $order
     */
    public static function allows($order): bool
    {
        if (empty($order)) {
            return false;
        }

        $boy = auth('deliveryboy')->user();

        if ($boy && (int) ($order->deliveryboy_id ?? 0) === (int) $boy->id) {
            return true;
        }

        if (self::callerOwnsStore($order->store_id ?? null)) {
            return true;
        }

        return MerchantPermissions::allowsAny(Auth::user(), [self::PERMISSION], $order->store_id ?? null);
    }

    /**
     * Is the caller the store's OWNER -- the tenant root, not merely somebody inside the tenant?
     *
     * Kept separate because `Api\DashboardController` asks it on its own for two store-level
     * handlers that have nothing to do with orders.
     *
     * @param  int|string|null  $storeId
     */
    public static function callerOwnsStore($storeId): bool
    {
        $user = Auth::user();

        if (! $user instanceof User || empty($storeId)) {
            return false;
        }

        $store = getStoreById($storeId);

        return $store !== null && (int) $store->created_by === (int) $user->id;
    }
}
