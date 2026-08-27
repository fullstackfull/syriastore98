<?php

namespace Workdo\ProductBarCode\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductBarCode extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [];

    public static function saveData($obj)
    {
        if ($obj) {
            $RecordId = $obj->id;
            $product = Product::find($RecordId);
            if($product->product_code != null)
            {
                $product->product_code = $obj->product_code;
            }else{
                $number = mt_rand(1000000000,9999999999);
                $code = new ProductBarCode;
                if($code->ProductCode($number))
                {
                    $number = mt_rand(1000000000,9999999999);
                }
                $product->product_code = $number;
            }
            $product->save();
        }
    }

    public function ProductCode($number)
    {
        return Product::where('product_code',$number)->exists();
    }
}
