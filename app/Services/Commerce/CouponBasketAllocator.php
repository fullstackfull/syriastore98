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
 * `Cart::getCouponTotalAmount()`, which this class still calls; it adds no second copy of them.
 * What it adds is the `minimum_spend` / `maximum_spend` pair, which that function never read at all
 * (P4-F062) — the basket subtotal is not knowable from one line, so the gate was only ever enforced
 * in `ApiController::apply_coupon`, and a coupon the basket did not qualify for still reduced the
 * cart's figure, and so the order's total, while `orders.coupon_price` stayed 0.
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

    /** The whole basket's pre-coupon subtotal — the denominator of the pro-rata share. */
    private float $basketSubtotal;

    /** What a `flat` coupon is worth across this basket, already capped at the basket. */
    private float $flatDiscount;

    /**
     * What is left of `$flatDiscount`. For a well-formed basket the pro-rata shares already sum to
     * exactly `$flatDiscount`, so this never binds and allocation stays order-free. It exists for
     * the malformed case -- see `discountForLine()`.
     */
    private float $flatRemaining;

    private function __construct($coupon, bool $inert, float $basketSubtotal, float $flatDiscount)
    {
        $this->coupon = $coupon;
        $this->inert = $inert;
        $this->isFlat = $coupon !== null && $coupon->coupon_type === 'flat';
        $this->basketSubtotal = $basketSubtotal;
        $this->flatDiscount = $flatDiscount;
        $this->flatRemaining = $flatDiscount;
    }

    /**
     * @param  \App\Models\Coupon|null  $coupon          already resolved AND store-scoped by the caller
     * @param  float  $basketSubtotal  the PRE-coupon subtotal of the whole basket
     */
    public static function forBasket($coupon, float $basketSubtotal): self
    {
        if (empty($coupon)) {
            return new self(null, true, 0.0, 0.0);
        }

        if (self::spendIsOutOfRange($coupon, $basketSubtotal)) {
            return new self($coupon, true, 0.0, 0.0);
        }

        $flatDiscount = 0.0;

        if ($coupon->coupon_type === 'flat') {
            /*
             * THE BASKET-WIDE DISCOUNT IS ASKED FOR ONCE, FROM THE SAME FUNCTION, ON THE WHOLE
             * SUBTOTAL. That is deliberately `apply_coupon`'s own arithmetic
             * (`ApiController.php:4581`, one subtraction from one basket figure) rather than a
             * second expression that happens to agree today. `getCouponTotalAmount()` keeps every
             * gate — expiry, `coupon_limit`, `coupon_limit_user` — and its floor at `Cart.php:1173`
             * caps the result at the basket, so a 50.00 coupon on a 40.00 basket is worth 40.00 and
             * never pays the shopper.
             *
             * The null product and category ids are correct rather than a shortcut: the `flat` arm
             * reads neither. Only `fixed product discount` does, and that type never reaches here.
             */
            $flatDiscount = max(0.0, $basketSubtotal - (float) Cart::getCouponTotalAmount(
                $coupon,
                $basketSubtotal,
                null,
                null
            ));
        }

        return new self($coupon, false, max(0.0, $basketSubtotal), $flatDiscount);
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
     * This line's share of the coupon.
     *
     * `percentage` and `fixed product discount` are answered per line by
     * `Cart::getCouponTotalAmount()`, exactly as before — one definition of the gates and of each
     * type's arithmetic.
     *
     * `flat` is allocated **PRO RATA**, and that is a correctness requirement rather than a
     * preference. The first version of this class consumed a running allowance first-come, which
     * made the allocation depend on cart row order — and the two readers do not even iterate in the
     * same order (`ApiController::cart_list` orders by id DESC at `:362`, `Cart::cart_list_cookie`
     * has no `orderBy` at `:253`). Because tax is charged per line, at each line's own rate, on the
     * price AFTER its share of the coupon, an order-dependent allocation moves `total_tax_price`
     * and therefore `orders.final_price`: two 100.00 lines, one taxed at 0% and one at 20%, with a
     * flat 100.00 coupon, shift about 20.00 of tax according to which row is newer — which a
     * shopper controls by removing and re-adding a product. Found by independent review of the
     * first version of this class, not by a test.
     *
     * Pro rata is order-free and sums exactly: `Σ (D × line_i / subtotal) = D`. No per-line
     * rounding is applied, so there is no remainder to carry on a last line; the published figures
     * are rounded once, by `SetNumber()`, as they always were.
     */
    public function discountForLine(float $lineTotal, $productId, $categoryId): float
    {
        if ($this->inert || $lineTotal <= 0.0) {
            return 0.0;
        }

        if ($this->isFlat) {
            if ($this->basketSubtotal <= 0.0) {
                return 0.0;
            }

            $share = min($lineTotal, $this->flatDiscount * $lineTotal / $this->basketSubtotal);

            /*
             * AND THE SUM IS BOUNDED, WHICH PRO RATA ALONE IS NOT.  Found by a second round of
             * independent review, as a regression THIS CLASS introduced when it replaced the
             * first-come allowance -- whose running budget was a hard cap -- with a ratio.
             *
             * A non-positive line returns 0.0 above but stays in `$basketSubtotal`, so
             * `Sum(shares) = D x (positive mass) / (total mass)`, and one negative line makes the
             * denominator the smaller of the two. MEASURED on the basket this codebase already
             * records at `ApiController.php:3555-3565` -- one 1000.00 product plus a 50.00 product
             * at `qty = -5`, subtotal 750.00 -- with a flat 700.00 coupon: the discount came out at
             * **933.33** and `total_sub_price` at **-183.33**. Negative `carts.qty` is reachable
             * today through two vendored module writers (`GuardsGatewayReturn.php:622-628`, S29).
             *
             * For a well-formed basket the shares already sum to exactly `$flatDiscount`, so this
             * budget never binds and the allocation stays order-free -- which is what keeps
             * `test_the_flat_discount_does_not_depend_on_cart_row_order` green. It binds only in the
             * malformed case, where a bounded total matters more than order-independence.
             *
             * The denominator is NOT narrowed to the positive mass instead: the allocator is not
             * told that figure, so it would mean changing both readers for a case three order
             * writers already refuse before pricing.
             */
            $share = min($share, max(0.0, $this->flatRemaining));
            $this->flatRemaining -= $share;

            return $share;
        }

        $after = (float) Cart::getCouponTotalAmount($this->coupon, $lineTotal, $productId, $categoryId);

        return max(0.0, $lineTotal - $after);
    }

    /**
     * Whether this allocator can discount at all.
     *
     * `ApiController::cart_list` consults it before filling `coupon_info`, because that block
     * re-resolves the coupon and applies expiry and the two usage limits but NOT the spend gate — so
     * a coupon the basket does not qualify for was echoed with its real id, name and headline
     * discount beside an amount of 0.00, and a client reading `coupon_info.coupon_id != 0` concluded
     * it was active. `coupon_info.coupon_final_amount` is also read as money by
     * `PaymentService.php:2460` on the path where no top-level `coupon_code` was posted.
     */
    public function isInert(): bool
    {
        return $this->inert;
    }
}
