<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Social extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    private static $cardsocialData = null;
    private static $cardflagSocial = false;
    protected $fillable = [
        'store_id',
        
        'content',
        'is_enabled',
        'created_by'
    ];
    public static function cardSocialData($id)
    {
        if(self::$cardsocialData == null) {
            if(self::$cardflagSocial === false){
                $socialDetail=Social::where('store_id', $id)->first();
                self::$cardsocialData = $socialDetail; 
                self::$cardflagSocial =  true;
            }       
        }
        return self::$cardsocialData;

    }
}