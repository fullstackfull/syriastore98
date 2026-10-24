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
     * @param  callable|null  $constrain  receives the builder, for the caller's own conditions
     *                                    (expiry, status) which stay where they were
     */
    public static function findByExactCode(?string $code, ?callable $constrain = null): ?self
    {
        if ($code === null || $code === '') {
            return null;
        }

        $query = static::query()->where('coupon_code', $code);

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
