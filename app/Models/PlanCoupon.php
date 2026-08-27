<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanCoupon extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name',
        'code',
        'discount',
        'limit',
        'description',
    ];

    public function used_coupon()
    {
        return $this->hasMany(PlanUserCoupon::class, 'coupon_id', 'id')->count();
    }

    public function userCoupons()
    {
        return $this->hasMany(UserCoupon::class, 'coupon_id', 'id');
    }

}
