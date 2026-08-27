<?php

namespace Workdo\SpamPrevent\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductComment extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'customer_id',
        'product_id',
        'comment',
        'type',
        'store_id',
        
        'created_by'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
