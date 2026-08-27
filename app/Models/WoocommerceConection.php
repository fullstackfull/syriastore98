<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WoocommerceConection extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'store_id',
        
        'module',
        'woocomerce_id',
        'original_id'
    ];

    public function woocomconection()
    {
        return $this->hasOne(Category::class, 'original_id', 'id')->first();
    }
}
