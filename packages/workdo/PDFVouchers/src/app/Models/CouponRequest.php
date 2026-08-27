<?php

namespace Workdo\PDFVouchers\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CouponRequest extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */

    protected $table = 'coupon_requests';

    protected $fillable = [
        'name',
        'email',
        'detail',
        'store_id',
    ];
}
