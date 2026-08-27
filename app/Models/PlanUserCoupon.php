<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanUserCoupon extends Model
{
    use HasFactory, Cachable;
    protected $fillable = [
        'user_id',
        'coupon_id',
        'order',
    ];

    public function userDetail()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }

    public function coupon_detail()
    {
        return $this->hasOne('App\Models\PlanCoupon', 'id', 'coupon_id');
    }

    public function order_detail()
    {
        return $this->hasOne('App\Models\PlanOrder', 'order_id', 'order');
    }

}
