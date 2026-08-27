<?php

namespace Workdo\ProductCompare\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductCompare extends Model
{
    use HasFactory, Cachable;

    protected $filable = [
        'product_id',
        'customer_id',
        'store_id'
    ];
}
