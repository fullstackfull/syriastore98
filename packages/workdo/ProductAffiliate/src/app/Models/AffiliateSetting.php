<?php

namespace Workdo\ProductAffiliate\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AffiliateSetting extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'percentage',
        'minimum_threshold_amount',
        'is_enable',
        'store_id',
        'created_by',
    ];
}
