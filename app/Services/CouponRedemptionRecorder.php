<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderCouponDetail;
use App\Models\Store;
use App\Models\UserCoupon;

/**
 * THE ONE PLACE A COUPON REDEMPTION IS RECORDED. P4-F134 / P4-F135.
 * =================================================================
 *
 * WHY `user_coupons` IS NOT AN AUDIT TABLE. Every coupon cap in the application is a count on it:
 * `Coupon::usesByCustomer()`, `Coupon::PerUsesCouponCount()`, `Coupon::coupon_uses()` and
 * `Coupon::UsesCouponCount()`. A redemption missing from that table is a limit that cannot fire; a
 * redemption wrongly in it is an allowance a shopper never spent. Both happened, in opposite
 * directions, on the two API order writers:
 *
 *   P4-F134 — `ordersave` recorded NOTHING, for every order. Its child-row block was gated on
 *   `isset($coupon_data['coupon_id'])`, and the complete set of keys that handler ever puts in
 *   `$order_array['coupon_info']` is message / status / code / discount / discount_string /
 *   discount_string2 / price / discount_amount. There is no `coupon_id`, so the guard was always
 *   false. MEASURED end to end: a coupon with `coupon_limit_user = 1` discounted TWO orders for the
 *   same shopper through `POST /api/{slug}/order-save`.
 *
 *   P4-F135 — `place_order` recorded a REFUSED coupon. Its block was gated on
 *   `!empty($request->coupon_info)` -- the request -- rather than on whether the server's own
 *   `apply_coupon()` accepted anything, and every column came from that request array, including
 *   the discount amount. It also resolved `Coupon::find($coupon_data['coupon_id'])`, a
 *   caller-named id with no store predicate. MEASURED: it additionally answered HTTP 500 with NO
 *   ORDER AT ALL (`Undefined array key "coupon_id"`) for any client that posted the code it typed
 *   without the server-derivable fields beside it.
 *
 * THE ONE RULE THIS CLASS EXISTS TO HOLD. The caller supplies a CODE, because that is the only
 * thing a client can honestly know. Everything else -- which coupon row, its name, its type, its
 * configured discount, whose redemption it is -- is derived here. Nothing on a request may name a
 * coupon by id: CLAUDE.md records that a predicate built from caller-supplied input is not a check,
 * and `Coupon::find()` on a posted id reaches every tenant's coupons.
 *
 * IT RECORDS ONLY WHAT THE SERVER ACTUALLY GRANTED. `$discountAmount` is the server's own figure --
 * `$order->coupon_price`, written from `apply_coupon()`'s answer -- so a coupon the server refused
 * has nothing to record and this returns null without writing. That is F135 closed at the source
 * rather than at each caller.
 *
 * IDEMPOTENT PER ORDER. A retry, or a second call from a handler that later grows one, must not
 * double a cap. The order already has its own uniqueness upstream; this adds the narrow guarantee
 * that one order never counts twice against one coupon.
 *
 * NO TRANSACTION OF ITS OWN. Both callers are already inside one (P4-F026 wrapped them), and a
 * nested boundary here would let a `rollBack()` in a caller's catch roll back to the wrong level --
 * the exact hazard `ordersave` documents around `$ssTransactionDepth`.
 *
 * WHAT `$finalAmount` IS, AND IS NOT -- recorded because an independent review traced it and the
 * answer is not the obvious one. It is the caller's own post-discount BASKET SUBTOTAL, which is
 * neither `orders.final_price` (that adds tax and delivery) nor `product_price - discountAmount`
 * (for a flat coupon worth more than the basket, `apply_coupon`'s face value is uncapped while the
 * cart allocator floors its discount at the basket, so the two columns of one row can disagree).
 * Both figures are server-derived -- no caller input reaches either -- so this is a question of
 * reporting fidelity rather than of money, and it is written down rather than quietly relied on.
 * The pre-fix column took this value straight from the request, so it is strictly better than it was.
 */
final class CouponRedemptionRecorder
{
    /**
     * Record one redemption, from the server's own resolution.
     *
     * @param Order       $order          the order that has just been written
     * @param Store       $store          the tenant predicate; a code is resolved only within it
     * @param string|null $code           the coupon code the caller typed -- a LOOKUP KEY, never a value
     * @param float       $discountAmount what the SERVER granted; <= 0 records nothing
     * @param float       $finalAmount    the post-discount total the server computed
     * @param int|null    $customerId     server-derived; null for a guest checkout
     *
     * @return OrderCouponDetail|null the row written, or null when there was nothing to record
     */
    public function record(
        Order $order,
        Store $store,
        ?string $code,
        float $discountAmount,
        float $finalAmount,
        ?int $customerId = null,
    ): ?OrderCouponDetail {
        // The server granted nothing, so there is nothing to record. This is F135's whole fix.
        if ($discountAmount <= 0.0) {
            return null;
        }

        /*
         * THE SERVER'S OWN RESOLUTION, scoped to this store -- the same call `apply_coupon()` makes,
         * so the row recorded is the row that was validated. `findByExactCode` re-compares the code
         * in PHP because MySQL's default collation is case-insensitive while `BINARY` is MySQL-only
         * and broke SQLite; that is the model's concern, not this one's, but it is why a plain
         * `where()` is not enough.
         */
        $coupon = Coupon::findByExactCode(
            $code === null ? null : trim($code),
            fn ($query) => $query->where('store_id', $store->id)
        );

        if ($coupon === null) {
            return null;
        }

        // Idempotence: one order never counts twice against one coupon.
        $already = OrderCouponDetail::query()
            ->where('order_id', $order->id)
            ->where('coupon_id', $coupon->id)
            ->first();

        if ($already !== null) {
            return $already;
        }

        $detail = new OrderCouponDetail();
        $detail->order_id = $order->id;
        $detail->product_order_id = $order->product_order_id;
        $detail->coupon_id = $coupon->id;
        // FROM THE ROW, not from the request: these four describe the coupon the merchant created.
        $detail->coupon_name = $coupon->coupon_name;
        $detail->coupon_code = $coupon->coupon_code;
        $detail->coupon_discount_type = $coupon->coupon_type;
        $detail->coupon_discount_number = $coupon->discount_amount;
        // FROM THE SERVER'S ARITHMETIC: what it actually took off, and what it left to pay.
        $detail->coupon_discount_amount = $discountAmount;
        $detail->coupon_final_amount = $finalAmount;
        $detail->save();

        /*
         * AND THE ROW THE CAPS COUNT. Written after the detail row, as both handlers already did, but
         * now unconditionally reachable: the pre-fix `ordersave` never got here at all and the
         * pre-fix `place_order` could die between the two on `$Coupon->id` for a posted id that
         * resolved to null -- "the discount is recorded, the redemption is not", which is a coupon
         * limit silently not firing.
         */
        $redemption = new UserCoupon();
        $redemption->user_id = $customerId;
        $redemption->coupon_id = $coupon->id;
        $redemption->amount = $discountAmount;
        $redemption->order_id = $order->id;
        $redemption->date_used = now();
        $redemption->save();

        return $detail;
    }
}
