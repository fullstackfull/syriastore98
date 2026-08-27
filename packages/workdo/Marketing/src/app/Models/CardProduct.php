<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CardProduct extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    private static $cardproductData = null;
    private static $cardflagProduct = false;
    protected $fillable = [
        'store_id',
        
        'content',
        'is_enabled',
        'created_by'
    ];
    
   
    public static function cardProductData($id)
    {
        if(self::$cardproductData == null) {
            if(self::$cardflagProduct === false){
                $cardproductDetail=CardProduct::where('store_id', $id)->first();
                self::$cardproductData = $cardproductDetail; 
                self::$cardflagProduct =  true;
            }       
        }
        return self::$cardproductData;

    }
}