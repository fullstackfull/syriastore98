<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAttributeOption extends Model
{
    use HasFactory, Cachable;

    protected $table = 'product_attribute_options';

    protected $fillable = [
         'attribute_id','terms','order', 'store_id'
    ];

    public function attribute()
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

}
