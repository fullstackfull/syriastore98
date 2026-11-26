<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'coupon_name', 'coupon_code', 'coupon_type', 'coupon_limit', 'coupon_expiry_date', 'discount_amount', 'status','free_shipping_coupon'
    ];

    /**
     * Find a coupon by an EXACTLY matching code, portably.
     *
     * P01-SEC-14 C4. The three storefront call sites used
     * `whereRaw('BINARY `coupon_code` = ?', [$code])`. `BINARY` is MySQL-only syntax, so SQLite
     * rejects the whole statement as a syntax error -- which meant apply_coupon, and every
     * coupon-bearing checkout, returned 500 before any of its logic ran on the test engine. A money
     * path was therefore untestable, which is how it stayed unexamined.
     *
     * WHY `BINARY` WAS THERE, AND WHY REMOVING IT IS NOT A NO-OP. It forces a byte-exact, and so
     * case-SENSITIVE, comparison on a column whose MySQL collation is almost certainly
     * case-insensitive. Dropping it outright would make 'SAVE10' and 'save10' the same coupon --
     * a business behaviour change, and one that widens what a guessed code matches.
     *
     * SO THE SEMANTICS ARE PRESERVED IN TWO STEPS:
     *   1. `where('coupon_code', $code)` narrows in SQL. This uses any index on the column, which
     *      a function or a collation cast on the left-hand side would not. On MySQL it may return
     *      case-variant rows; on SQLite `=` is already case-sensitive, so it returns at most the
     *      exact one.
     *   2. A strict `===` comparison in PHP then picks the byte-exact match, discarding the
     *      case-variants MySQL may have added.
     *
     * The result is identical to the old behaviour on MySQL, correct on SQLite, and index-friendly
     * on both. Per-store coupon counts are small, so step 2 reads a handful of rows at most.
     *
     * =============================================================================================
     * P4-F061 / GAP-132 -- AN INACTIVE COUPON IS NO LONGER FOUND BY CODE.
     * =============================================================================================
     *
     * THE DEFECT. `coupons.status` is `integer default 1` with the migration's own comment
     * `0 => Inactive, 1 => Active`. It is WRITTEN by four merchant-facing editors
     * (`CouponController::store` / `::update`, `DashboardController::AddCoupon` / `::UpdateCoupon`)
     * and was READ by no query predicate and no conditional anywhere in `app/` or
     * `packages/workdo/`. `Coupon` carries no global scope and is in no `scoped_models` entry, so
     * nothing underneath could supply one. A coupon the merchant switched off therefore still
     * validated and still discounted, bounded only by its expiry, `coupon_limit`,
     * `coupon_limit_user` and spend gates -- which all worked. The toggle did nothing.
     *
     * MEASURED, THREE DOORS, before this predicate existed: `POST api/{slug}/apply-coupon`
     * answered `status 1` with a 50.00 discount on a 200.00 basket; `POST api/{slug}/cart-list`
     * reported `total_coupon_price` 50.00; and `POST /{slug}/applycoupon` -- which
     * `routes/customer.php:203` publishes with NO auth middleware -- answered "Coupon code applied
     * successfully", final_price 150.00, to a visitor with no session at all.
     *
     * HERE, BECAUSE THIS IS THE CHOKEPOINT. All eight storefront lookups that resolve a coupon
     * from a shopper-supplied CODE come through this method (`Cart`, `CartController` x2,
     * `OrderController`, `ApiController` x4) -- measured: exactly ONE narrowing of a query by
     * `coupon_code` exists under `app/`, this one, and none under `packages/workdo/`. Pinned in
     * both directions by
     * `CouponDiscountAuthorizationTest::test_no_storefront_coupon_code_lookup_bypasses_the_status_gate`.
     *
     * BEFORE `$constrain`, NOT AFTER, so a caller's own conditions cannot displace it. STATED AS A
     * PRECAUTION RATHER THAN A MEASURED CONTROL: moving it after `$constrain` is a mutant that
     * SURVIVES the whole suite, because every one of the eight constrain closures is a plain
     * `->where()` and SQL ANDs them whatever the order. It would only matter for a closure that
     * opened a top-level `orWhere`, and none does. Recorded so nobody reads the ordering as
     * something a test is holding.
     *
     * NOT A GLOBAL SCOPE, AND THAT IS THE POINT. The merchant must still SEE and re-enable what
     * they deactivated: `CouponDataTable::query()`, `CouponController::edit` and
     * `DashboardController::CouponList` read through plain `Coupon::where()` / `Coupon::find()`,
     * which this does not touch. A scope would have made a deactivated coupon invisible to its own
     * owner -- the finding names that as the way to get this fix wrong, and
     * `test_the_merchant_can_still_list_and_read_a_deactivated_coupon` pins it.
     *
     * `$activeOnly` EXISTS FOR EXACTLY ONE CALLER, and it is not a convenience.
     * `CouponRedemptionRecorder` resolves the row AFTER the server has already granted a discount,
     * so that the redemption is recorded against the coupon that was validated. If a merchant
     * deactivates a coupon between acceptance and the order write, refusing the lookup there would
     * drop the redemption -- every coupon cap is a count on `user_coupons`, so a missing row is a
     * limit that cannot fire, which is P4-F134's harm re-opened. The recorder therefore opts out
     * explicitly and says so at its call site. No other caller may.
     *
     * STATUS IS COMPARED AS AN INTEGER 1, matching the column and its default. A NULL status (no
     * writer produces one, but the column is reachable by import) is therefore NOT active, which
     * is the safe direction for a predicate that decides whether to give money away.
     *
     * WHAT THIS DOES TO A BASKET ALREADY IN FLIGHT -- MEASURED, NOT CHOSEN. Nothing persists a
     * coupon acceptance between storefront requests: both cart arithmetics re-resolve the code on
     * every read, so this gate cannot be scoped to "new applications" even in principle. An
     * UNSUBMITTED basket already showing the discount loses it at the next cart read or at checkout.
     * A PLACED order keeps its recorded discount, because nothing re-resolves it. The GATEWAY RETURN
     * leg was read rather than assumed: `PaymentController::getProductStatus()` adopts the stored
     * checkout state and resolves the coupon by `coupon_id`, not by code, so a coupon deactivated
     * between the redirect and the return cannot make the recorded total diverge from the charged
     * one. The one thing left for the owner is a MESSAGE question, not a money one: such a shopper is
     * told the code is invalid without being told it was withdrawn. GAP-261's sibling row, GAP-132,
     * records it.
     *
     * @param  callable|null  $constrain  receives the builder, for the caller's own conditions
     *                                    (expiry) which stay where they were
     * @param  bool  $activeOnly  false ONLY for the redemption recorder; see above
     */
    public static function findByExactCode(?string $code, ?callable $constrain = null, bool $activeOnly = true): ?self
    {
        if ($code === null || $code === '') {
            return null;
        }

        $query = static::query()->where('coupon_code', $code);

        if ($activeOnly) {
            $query->where('status', 1);
        }

        if ($constrain !== null) {
            $constrain($query);
        }

        foreach ($query->get() as $coupon) {
            if ((string) $coupon->coupon_code === $code) {
                return $coupon;
            }
        }

        return null;
    }

    /**
     * CONSTRAIN A COUPON QUERY TO ONE THAT HAS NOT EXHAUSTED ITS TOTAL USAGE LIMIT.
     *
     * THE DEFECT THIS REPLACES, measured. Both call sites read
     * `->where('coupon_limit', '>', $uses)`, and `coupons.coupon_limit` is
     * `integer()->default('0')` (create_coupons_table.php:27). So for a coupon whose limit was
     * never set -- THE MIGRATION'S OWN DEFAULT, and what a merchant gets by leaving the field
     * blank -- the predicate is `0 > 0`, false, and the coupon reads as EXPIRED. Driven against
     * `api/{slug}/apply-coupon`: {"status":0,"data":{"message":"This coupon has expired."}} on a
     * brand-new coupon with a year left on its expiry date. Every coupon created without an
     * explicit limit was unusable, and the message did not say why.
     *
     * AN UNSET LIMIT MEANS NO LIMIT, which is this codebase's own idiom for the sibling field:
     * `coupon_limit_user` is gated on `!empty($coupon->coupon_limit_user)`, so 0 and null there
     * already mean "no per-user cap". Reading 0 as "zero uses permitted" on this column and
     * "unlimited" on that one is the inconsistency, not the fix.
     *
     * A POSITIVE LIMIT IS STILL ENFORCED STRICTLY -- `> $uses`, unchanged -- so this loosens
     * nothing a merchant actually configured.
     */
    public static function applyUsageLimit($query, $uses)
    {
        return $query->where(function ($q) use ($uses) {
            $q->whereNull('coupon_limit')
                ->orWhere('coupon_limit', 0)
                ->orWhere('coupon_limit', '>', $uses);
        });
    }

    /**
     * HOW MANY TIMES THIS CUSTOMER HAS USED THIS COUPON. The per-user cap is enforced on this.
     *
     * THE DEFECT THIS REPLACES, and a previous package explicitly decided it was harmless:
     * `PerUsesCouponCount()` resolves each prior use through `order_billing_details.email`, and
     * that column is written straight from the request (`$OrderBillingDetail->email =
     * $other_info->email`). So a shopper who posts a different `billing_info.email` on each order
     * is never seen as a repeat user, and `coupon_limit_user` never fires. The note at
     * ApiController's UserCoupon writer says "Not coupon-limit evasion ... resolves the user
     * through OrderBillingDetail.email, not through this column" -- which was TRUE AND HARMLESS
     * only while every flat and percentage coupon was refused upstream. P1-ORD-01 fixed that, so
     * the evasion now moves money, and `coupon_limit = 0` (the schema default, now correctly read
     * as "no total limit") leaves the per-user cap as the ONLY backstop.
     *
     * COUNTS BOTH AND TAKES THE HIGHER. `user_coupons.user_id` is server-derived from the
     * authenticated principal, so it is trustworthy for rows written since that was fixed; the
     * e-mail limb is kept because older rows were attributed only that way. max() means neither
     * route can lower the count the other found.
     */
    public static function usesByCustomer(self $coupon, $customer): int
    {
        if (empty($customer)) {
            return 0;
        }

        $byId = UserCoupon::where('coupon_id', $coupon->id)
            ->where('user_id', $customer->id)
            ->count();

        $byEmail = 0;
        $email = strtolower(trim((string) ($customer->email ?? '')));

        if ($email !== '') {
            foreach ($coupon->PerUsesCouponCount() as $used) {
                if (strtolower(trim((string) $used)) === $email) {
                    $byEmail++;
                }
            }
        }

        return max($byId, $byEmail);
    }

    public function coupon_uses()
    {
        return UserCoupon::where('coupon_id', $this->id)->count();
    }

    public function UsesCouponCount()
    {
        return $this->hasMany(UserCoupon::class, 'coupon_id', 'id')->count();
    }

    public function PerUsesCouponCount()
    {
        $coupon_user = UserCoupon::where('coupon_id' ,$this->id )->get();
        $user_email =[];
        foreach($coupon_user as $coupon){
            $user_email[] = OrderBillingDetail::where('order_id' ,$coupon->order_id)->pluck('email')->first();

        }
        return $user_email;
    }
}
