<?php

namespace App\Support\Money;

/**
 * HOW MUCH A CUSTOMER'S REWARD-POINT BALANCE TAKES OFF AN ORDER.  MKT-005 / MKT-006.
 *
 * ONE EXPRESSION, DEFINED ONCE, because ELEVEN first-party payment paths each carried their own
 * copy of it and every copy was wrong in the same two ways:
 *
 *     $saveRewardPrice = ($customerDetail->total_club_point * $rewardSetting['reward_point_price'] ?? 0)
 *                      / ($rewardSetting['reward_points'] ?? 0);
 *
 * WHAT THAT PRODUCED, MEASURED:
 *
 *   1. `/ ($rewardSetting['reward_points'] ?? 0)` DEFAULTS THE DIVISOR TO ZERO, so a store with
 *      the module enabled and no `reward_points` saved -- or `reward_points` saved as 0, which
 *      Laravel's `required` rule permits -- throws `DivisionByZeroError` DURING PAYMENT
 *      CONFIRMATION. Measured: absent, 0, '0' and an entirely absent settings row all throw.
 *      The `?? 0` reads as a safe default and is the exact opposite of one.
 *
 *   2. `($a * $b ?? 0)` IS MIS-BOUND. `*` binds tighter than `??`, so it parses as
 *      `($a * $b) ?? 0`, which can never be null and so never defaults. An absent
 *      `reward_point_price` therefore reached the multiplication as an undefined key -- and
 *      inside this app that is NOT merely a warning. `HandleExceptions::bootstrap()` sets
 *      `error_reporting(-1)` and `handleError()` throws, so it was
 *      `ErrorException: Undefined array key "reward_point_price"` -- an HTTP 500 mid-checkout.
 *      Measured in the booted app, after a review corrected a measurement taken OUTSIDE it.
 *      A wholly non-numeric price was worse still: `100.0 * 'free'` is a TypeError.
 *
 * THE ARITHMETIC IS UNCHANGED WHEREVER THE OLD EXPRESSION PRODUCED A NON-NEGATIVE NUMBER.
 * `RewardPointDiscountTest` reproduces it and asserts this class agrees on every configured
 * input -- that is the licence for this change. No ordinary store's discount moves by one cent.
 *
 * THE ONE DELIBERATE DIVERGENCE, named because the sentence above is otherwise an overclaim
 * (found by review, not by me): a NEGATIVE configured rate used to produce a negative "discount",
 * and a negative discount is SUBTRACTED, so it ADDED to the bill -- `final_price + tax - (-5.0)`
 * charged 5.00 MORE than the basket. This class clamps that to no discount. It is reachable, not
 * theoretical: the module's settings validator is `'required'` with no `numeric` and no `min:0`
 * (`RewardClubPointController` rules, :26-33), so a merchant can save `reward_point_price = -0.5`
 * through the ordinary form. `it_never_returns_a_negative_discount` pins the new behaviour.
 *
 * A MISSING RATE IS NOT A RATE OF ONE. The module's own `RewardClubPointController::applyPoints()`
 * guards the same division with `$rewardPoints = ... != 0 ? ... : 1` -- substituting a divisor of
 * ONE, which converts a misconfiguration into the largest discount the balance can buy. Measured
 * on that fallback: a 100-point balance at 0.5 per point took 50.00 off a 20.00 basket. This class
 * deliberately diverges: with no rate configured there is no defined conversion, so there is no
 * discount. That is the only reading that cannot invent money out of an empty settings row.
 *
 * WHAT THIS CLASS DOES NOT DECIDE, because each is an owner decision and is reported, not taken,
 * in docs/development/phase-5/PHASE-05-MKT-006-REWARD-REVERSAL.md:
 *
 *   - WHETHER THE RESULTING TOTAL IS CLAMPED AT ZERO. The eleven callers compute
 *     `final_price = $total_sub_price - $saveRewardPrice` with no `max(0, ...)`, so a large
 *     balance against a small basket writes a NEGATIVE order total -- measured, 20.00 basket
 *     less a 50.00 discount stored as -30.00. Clamping changes what a customer pays in a case
 *     that currently succeeds, so it is not taken here.
 *   - WHETHER REDEMPTION IS ALLOWED AT ALL. The `checkout_points` / `checkout_price` thresholds
 *     are enforced in Blade views only; the server-side guard is
 *     `isset($requests_data['club_point_is_active']) && == 'on'`, which is caller-supplied.
 *   - WHETHER POINTS ARE RETURNED WHEN AN ORDER IS CANCELLED OR REFUNDED. They are not. MKT-006
 *     is unimplemented and the only write path lives in a vendored module.
 */
final class RewardPointDiscount
{
    /**
     * @param  float  $balance   the customer's own `customers.total_club_point`, read server-side
     * @param  array  $settings  the store's settings as `getAdminAllSetting()` returns them
     * @return float  the amount to subtract, never negative, 0.0 when no rate is configured
     */
    public static function for(float $balance, array $settings): float
    {
        // READ BOTH THROUGH `?? null` FIRST. This, not a later guard, is what removes defect 2:
        // `??` on the array read suppresses the undefined-key warning, and `(float) null` is a
        // clean 0.0, so an absent or junk price needs no separate check.
        $points = $settings['reward_points'] ?? null;
        $price = $settings['reward_point_price'] ?? null;

        // LOAD-BEARING, AND THE ONLY REASON THIS CLASS EXISTS: a zero or missing divisor is the
        // fatal. Dropping this line reddens six tests.
        if (! is_numeric($points) || (float) $points <= 0.0) {
            return 0.0;
        }

        // LOAD-BEARING, AND FOUND ONLY BY MUTATION. `is_numeric()` is not interchangeable with
        // casting: `(float) '5abc'` is 5.0 while `is_numeric('5abc')` is false, so a cast-only
        // guard DIVIDES BY 5 and grants a discount computed from a junk setting. Measured on the
        // price side with `reward_point_price = '2xyz'`: a 100-point balance produced a 20.00
        // discount. Only `is_numeric` is needed here -- a negative price is caught by the clamp
        // below, so repeating a `<= 0.0` test would be dead.
        if (! is_numeric($price)) {
            return 0.0;
        }

        // Same operands, same order, same IEEE result as the expression this replaces.
        $discount = ($balance * (float) $price) / (float) $points;

        // LOAD-BEARING: the only thing keeping a negative balance or a negative configured price
        // from becoming a discount that ADDS to the order. Dropping this line reddens one test.
        //
        // THERE WERE THREE MORE GUARDS HERE AND MUTATION TESTING PROVED ALL THREE DEAD. Explicit
        // `$balance <= 0.0` and `$price <= 0.0` early returns each passed the whole suite when
        // deleted, because this clamp already caught both -- and the clamp ALSO passed when
        // deleted, because they caught it first. Three mutually-covering checks, any one
        // removable invisibly. In a money class that is a trap, not depth: the next reader
        // deletes whichever one looks redundant and the suite agrees. Two checks remain and each
        // is pinned on its own.
        return $discount > 0.0 ? $discount : 0.0;
    }

    /**
     * How much of a reward discount an order of `$eligible` may actually absorb.
     *
     * D5, OWNER-APPROVED 2026-11-17: *"Prevent negative order totals. Cap applied rewards within
     * the verified eligible amount. Preserve accurate discount, payment and ledger accounting."*
     *
     * WHY THE DISCOUNT IS CAPPED AND NOT THE RESULT. Ten first-party call sites computed
     * `final_price = <base> - $saveRewardPrice` unbounded; a 20.00 basket less a 50.00 reward was
     * MEASURED storing `orders.final_price = -30.00`. Capping the discount rather than clamping
     * the result keeps the *computation* honest at the point of use: the figure subtracted is the
     * figure that could be subtracted, so the arithmetic reads as what it is.
     *
     * THIS DOES NOT MAKE THE LEDGER AGREE, AND AN EARLIER VERSION OF THIS PARAGRAPH CLAIMED IT
     * DID. That was wrong and independent review caught it. The only ledger writer in this family
     * is the VENDORED `AddRewardClubPointLis`, and it never sees the capped figure: at its :41 it
     * RECOMPUTES the raw expression
     * `($customer['total_club_point'] * $setting['reward_point_price'] ?? 0) / ($setting['reward_points'] ?? 0)`
     * — the uncapped one, including the two defects this class exists to fix — and stores that at
     * `'reward_price' => $rewardPrice` (:56), alongside `'used_points' => $customerTotalPoints`,
     * the WHOLE prior balance.
     *
     * So on the over-discount path the order now reads 0.00 while the ledger row still reads
     * 50.00. Before this change they were both wrong and CONSISTENT (-30.00 against 50.00);
     * after it they are inconsistent by the capped amount. That is a real consequence of this
     * change and it is recorded rather than glossed: reconciling them means either editing a
     * vendored listener (forbidden) or D4's first-party obligation record (owner-authorised, not
     * yet built). **D5 bounds `orders.final_price`. It does not fix reward accounting.**
     *
     *     $capped = RewardPointDiscount::cappedTo($raw, $eligible);
     *     $order->final_price = $eligible - $capped;      // >= 0 for every input, by construction
     *
     * BOTH OPERANDS ARE FLOORED AT ZERO, and neither floor is decoration:
     *
     *   - a negative `$eligible` would otherwise let a positive discount make the total *worse*.
     *     This is defensive and is NOT claimed unreachable — the entire reason this method exists
     *     is that a negative total was reachable in this very family.
     *   - a negative `$discount` would otherwise be SUBTRACTED, i.e. added to the customer's bill.
     *     `for()` already refuses to return one, but this method is public and takes a float from
     *     ten call sites, and an earlier review in this same family caught exactly that
     *     overclaim — a configured negative rate that used to ADD to the order.
     *
     * WHAT THIS DOES NOT DECIDE. How many POINTS the customer is debited. Capping the money does
     * not by itself cap the spend, and the obligation model for an over-spend is D4's, not this
     * method's. Deciding it here would silently forgive the remainder, which the owner's
     * instruction forbids in those words.
     *
     * Pinned by tests/Unit/Loyalty/RewardDiscountCapTest.
     */
    public static function cappedTo(float $discount, float $eligible): float
    {
        $discount = $discount > 0.0 ? $discount : 0.0;
        $eligible = $eligible > 0.0 ? $eligible : 0.0;

        return $discount < $eligible ? $discount : $eligible;
    }

    /**
     * The order total after a reward discount: `$eligible` reduced by as much of `$discount` as
     * it can absorb, and never below zero.
     *
     * This exists so the invariant lives in ONE place. Twenty call sites across nine files write
     * this expression, and twenty hand-written copies of `$base - cappedTo($d, $base)` is twenty
     * chances to spell `$base` differently on its second appearance — which is precisely the
     * copy-paste failure this family has already produced once, when one expression was pasted
     * into eleven handlers and was fatal in four input families at every one of them.
     *
     * Note the asymmetry with `cappedTo()`: there the eligible amount is the SECOND argument
     * because it is the bound; here it is the FIRST because it is the subject. That is
     * deliberate — `applyTo($basket, $discount)` reads in the order the sentence does.
     */
    public static function applyTo(float $eligible, float $discount): float
    {
        $floored = $eligible > 0.0 ? $eligible : 0.0;

        return $floored - self::cappedTo($discount, $eligible);
    }
}
