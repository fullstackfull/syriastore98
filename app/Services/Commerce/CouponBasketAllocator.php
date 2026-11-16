<?php

namespace App\Services\Commerce;

use App\Models\Cart;

/**
 * ONE COUPON, ONE BASKET — the bound the two cart readers never had.  P4-F001 / P4-F063 / P4-F062.
 * ==============================================================================================
 *
 * `Cart::getCouponTotalAmount()` is a pure function of ONE line's price. Both cart readers called
 * it inside their per-line loop and accumulated the difference, so a `flat` coupon was subtracted
 * once per LINE:
 *
 *     $coupon_apply_price = Cart::getCouponTotalAmount($coupon, $final_price, ...);
 *     $coupon_price      += $final_price - $coupon_apply_price;        // once PER LINE
 *
 * MEASURED, before this class existed, through `api/{slug}/cart-list` on a basket of three 40.00
 * lines with a flat 50.00 coupon:
 *
 *     total_final_price    120.00      (the subtotal, correct)
 *     total_coupon_price   120.00      <- should be 50.00
 *     total_sub_price        0.00      <- should be 70.00
 *
 * and through the real `POST {slug}/process-order` with `payment_type=cod`, the order that basket
 * wrote: **`orders.final_price` = 0.00**. The per-line floor at `Cart.php:1173` makes it worse
 * rather than safer — each 40.00 line floors to zero against the full 50.00 — so five lines give
 * a 200.00 basket away as readily as three give a 120.00 one. `PaymentService::getProductStatus()`
 * takes `$total_sub_price = $response['total_sub_price']` and writes it into `orders.final_price`,
 * which for a COD order is the cash a driver collects.
 *
 * WHY THIS IS A CLASS AND NOT TWO EDITS. The fix needs a decision the per-line loop structurally
 * cannot make: how much of the basket-wide allowance a line may consume. That state has to outlive
 * one line and die with one basket. Both readers now build one allocator per cart read and ask it
 * per line, so there is exactly one definition of the bound and the two arithmetics cannot drift —
 * which is the failure mode this codebase has already paid for twice (P1-ORD-01 review item (A):
 * fixing two of three `coupon_limit` gates made the preview and the charge disagree, and the
 * shopper was told "applied successfully" with a discount of 0.00).
 *
 * WHAT IT DOES NOT CHANGE, and why those two arms are deliberately untouched:
 *
 *   - `percentage` never multiplied. D% of each line, summed, IS D% of the basket. Passing it
 *     through the allocator leaves every figure identical, which
 *     `MultiLineCouponArithmeticTest::test_a_percentage_coupon_is_not_changed_by_the_per_basket_fix`
 *     pins — it passed before this class and must keep passing.
 *   - `fixed product discount` is per-line BY DESIGN: it is a per-product discount and two matching
 *     lines earn it twice. Only `flat` carries a basket-wide budget here.
 *
 * THE GATES STAY WHERE THEY ARE. Expiry, `coupon_limit` and `coupon_limit_user` are read by
 * `Cart::getCouponTotalAmount()`, which this class still calls for every line; it adds no second
 * copy of them. What it adds is the `minimum_spend` / `maximum_spend` pair, which that function
 * never read at all (P4-F062) — the basket subtotal is not knowable from one line, so the gate was
 * only ever enforced in `ApiController::apply_coupon`, and a coupon the basket did not qualify for
 * still reduced the cart's figure, and so the order's total, while `orders.coupon_price` stayed 0.
 *
 * The reading of those two columns is `ApiController::couponSpendRefusal()`'s, verbatim: a null or
 * **zero** limit is NO limit. Both columns default to 0, so reading 0 as "a limit of zero" would
 * silently refuse every coupon in the catalogue —
 * `MultiLineCouponArithmeticTest::test_a_zero_spend_limit_is_no_limit` is the guard.
 */
final class CouponBasketAllocator
{
    /** @var \App\Models\Coupon|null */
    private $coupon;

    /** True when this allocator will never discount anything: no coupon, or the basket is out of range. */
    private bool $inert;

    /** `flat` is the only type with a basket-wide allowance. */
    private bool $isFlat;

    /** What is left of a `flat` coupon's allowance. Not used for the other types. */
    private float $remaining;

    private float $allocated = 0.0;

    private function __construct($coupon, bool $inert, float $allowance)
    {
        $this->coupon = $coupon;
        $this->inert = $inert;
        $this->isFlat = $coupon !== null && $coupon->coupon_type === 'flat';
        $this->remaining = $allowance;
    }

    /**
     * @param  \App\Models\Coupon|null  $coupon          already resolved AND store-scoped by the caller
     * @param  float  $basketSubtotal  the PRE-coupon subtotal of the whole basket
     */
    public static function forBasket($coupon, float $basketSubtotal): self
    {
        if (empty($coupon)) {
            return new self(null, true, 0.0);
        }

        if (self::spendIsOutOfRange($coupon, $basketSubtotal)) {
            return new self($coupon, true, 0.0);
        }

        // A `flat` coupon may take the whole basket to nothing and no further. The per-line floor
        // in getCouponTotalAmount() still applies as well, so a line is never driven negative.
        $allowance = max(0.0, (float) ($coupon->discount_amount ?? 0));

        return new self($coupon, false, $allowance);
    }

    /**
     * `ApiController::couponSpendRefusal()`'s reading, which this deliberately mirrors rather than
     * reinterprets: a null limit is no limit, and so is a limit of **0** — the column default.
     */
    public static function spendIsOutOfRange($coupon, float $baseline): bool
    {
        if (empty($coupon)) {
            return false;
        }

        $maximum = $coupon->maximum_spend;
        $minimum = $coupon->minimum_spend;

        if ($maximum !== null && (float) $maximum != 0.0 && $baseline > (float) $maximum) {
            return true;
        }

        if ($minimum !== null && (float) $minimum != 0.0 && $baseline < (float) $minimum) {
            return true;
        }

        return false;
    }

    /**
     * What this line's discount is, after the basket-wide bound.
     *
     * The per-line answer still comes from `Cart::getCouponTotalAmount()` — one definition of the
     * expiry, usage-limit and per-type arithmetic — and only a `flat` coupon's result is then
     * capped by what is left of the basket allowance.
     */
    public function discountForLine(float $lineTotal, $productId, $categoryId): float
    {
        if ($this->inert) {
            return 0.0;
        }

        $after = (float) Cart::getCouponTotalAmount($this->coupon, $lineTotal, $productId, $categoryId);
        $discount = max(0.0, $lineTotal - $after);

        if ($this->isFlat) {
            $discount = min($discount, max(0.0, $this->remaining));
            $this->remaining -= $discount;
        }

        $this->allocated += $discount;

        return $discount;
    }

    /** This line's price after its share of the coupon. */
    public function lineTotalAfter(float $lineTotal, $productId, $categoryId): float
    {
        return max(0.0, $lineTotal - $this->discountForLine($lineTotal, $productId, $categoryId));
    }

    /** The basket's total discount so far — what `total_coupon_price` reports. */
    public function totalAllocated(): float
    {
        return $this->allocated;
    }

    /**
     * Whether this allocator can discount at all. The readers use it to decide whether the coupon
     * is reported in `coupon_info`: a coupon refused for spend must not be echoed as applied.
     */
    public function isInert(): bool
    {
        return $this->inert;
    }
}
