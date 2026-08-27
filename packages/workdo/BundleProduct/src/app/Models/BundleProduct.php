<?php

namespace Workdo\BundleProduct\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BundleProduct extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'product_id',
        
        'store_id'
    ];

    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class, 'product_id');
    }
}
