<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchasedProducts extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'customer_id',
        'product_id',
        'order_id',
        
        'store_id',
    ];
}
