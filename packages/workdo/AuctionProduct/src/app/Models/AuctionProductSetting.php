<?php

namespace Workdo\AuctionProduct\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AuctionProductSetting extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'auction_product',
        'is_active',
        'store_id',
    ];
}
