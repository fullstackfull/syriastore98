<?php

namespace Workdo\RecentlyViewedProducts\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RecentlyViewedProduct extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'product_id', 'store_id'
    ];

    public function product() {
        return $this->hasOne(\App\Models\Product::class, 'id', 'product_id');
    }
}
