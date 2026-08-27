<?php

namespace Workdo\ProductCatelog\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductCatelog extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'id',
        'category_id',
        'customer_id',
        'email',
        'description',
        'store_id',
        
    ];

    public function CategoryData()
    {
        return $this->hasOne(Category::class, 'id', 'category_id');
    }
}
