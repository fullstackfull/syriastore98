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
