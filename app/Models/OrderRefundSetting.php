<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;

class OrderRefundSetting extends Model
{
    use Cachable;
    protected $table = 'order_refund_settings';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name',
        'user_id',
        'is_active',
        
        'store_id',
    ];
}
