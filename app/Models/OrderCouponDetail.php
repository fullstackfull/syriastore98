<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderCouponDetail extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'order_id',
        'product_order_id',
        'coupon_id',
        'coupon_name',
        'coupon_code',
        'coupon_discount_type',
        'coupon_discount_amount',
        'coupon_final_amount'
    ];
}
