<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'customer_id', 'category_id', 'product_id', 'status'
    ];

    protected $appends = ["demo_field", 'product_name', 'product_image', 'variant_name', 'original_price', 'final_price'];

    public function getDemoFieldAttribute()
    {
        return 'demo_field';
    }

    public function getProductImageAttribute()
    {
        $cover_image_path = '';
        $product = Product::find($this->product_id);
        if(!empty($product)) {
            $cover_image_path = $product->cover_image_path;
        }
        return $cover_image_path;
    }

    public function getProductNameAttribute()
    {
        $name = '';
        $product = Product::find($this->product_id);
        if(!empty($product)) {
            $name = $product->name;
        }
        return $name;
    }

    public function getVariantIdAttribute()
    {
        $id = '';
        $product = Product::find($this->product_id);
        if(!empty($product)) {
            $id = $product->default_variant_id;
        }
        return $id;
    }

    public function getVariantNameAttribute()
    {
        $name = '';
        $product = Product::find($this->product_id);
        if(!empty($product->default_variant_id)) {
            $ProductStock = ProductVariant::find($product->default_variant_id);
            if(!empty($ProductStock)) {
                $name = $ProductStock->variant;
            }
        }
        return $name;
    }

    public function getOriginalPriceAttribute()
    {
        $price = 0;
        $product = Product::find($this->product_id);
        if(!empty($product))
        {
            $price = SetNumber($product->original_price);
        }
        return $price;
    }

    public function getFinalPriceAttribute()
    {
        $price = 0;
        $product = Product::find($this->product_id);
        if(!empty($product))
        {
            $price = SetNumber($product->final_price);
        }
        return $price;
    }

    public function UserData()
    {
        return $this->hasOne(Customer::class, 'id', 'customer_id');
    }

    public function CategoryData()
    {
        return $this->hasOne(Category::class, 'id', 'category_id');
    }

    public function ProductData()
    {
        return $this->hasOne(Product::class, 'id', 'product_id');
    }

    public function GetVariant()
    {
        return $this->hasone(ProductVariant::class,'id','variant_id');
    }

    public static function WishCount()
    {
        $return = 0;
        if (auth('customers')->check()) {

            $return = Wishlist::where('customer_id', auth('customers')->user()->id)
            ->count();
        }

        return $return;
    }
}
