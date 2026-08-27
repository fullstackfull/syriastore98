<?php

namespace Workdo\ProductReview\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductReview extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['category_id', 'product_id', 'rating_no', 'title', 'description', 'status'];
    
    public function MainCategoryData()
    {
        return $this->hasOne(\App\Models\Category::class, 'id', 'category_id');
    }

    public function ProductData()
    {
        return $this->hasOne(\App\Models\Product::class, 'id', 'product_id');
    }
}
