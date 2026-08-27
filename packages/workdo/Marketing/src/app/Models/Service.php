<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Service extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    private static $cardserviceData = null;
    private static $cardflagService = false;
    protected $fillable = [
        'store_id',
        
        'content',
        'is_enabled',
        'created_by'
    ];
    
    public static function cardServiceData($id)
    {
        if(self::$cardserviceData == null) {
            if(self::$cardflagService === false){
                $serviceDetail=service::where('store_id', $id)->first();
                self::$cardserviceData = $serviceDetail; 
                self::$cardflagService =  true;
            }       
        }
        return self::$cardserviceData;

    }
}