<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyConection extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'store_id',
        
        'module',
        'shopify_id',
        'original_id'
    ];
}
