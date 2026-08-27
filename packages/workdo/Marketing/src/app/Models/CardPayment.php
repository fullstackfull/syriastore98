<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CardPayment extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    private static $cardPaymentData = null;
    private static $flagPayment = false;
    protected $fillable = [
        'store_id',
        
        'payment_amount',
        'content',
        'payment_status',
        'payment_type',  
        'is_enabled',  
        'created_by'
    ];
    
    public static function cardPaymentData($id)
    {
        if(self::$cardPaymentData == null) {
            if(self::$flagPayment === false){
                
                $cardPaymentDetail=CardPayment::where('store_id', $id)->first();
                self::$cardPaymentData = $cardPaymentDetail;
                self::$flagPayment =  true;
            }       
        }
        return self::$cardPaymentData;

    }
}