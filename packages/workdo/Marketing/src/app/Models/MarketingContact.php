<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MarketingContact extends Model
{
    use HasFactory, Cachable;
    static $cardcontactData = null;
    private static $cardflagContact = false;
    private static $conData = null;
    protected $fillable = [
        'store_id',
        
        'name',
        'email',
        'phone',
        'message',
        'created_by'
    ];

    public static function getStoreData($id)
    {
        $store = \App\Models\Store::where('id', $id)->pluck('name')->first();
        return $store;
    }



    public static function cardContactData($id)
    {
        if (self::$cardcontactData == null) {
            if (self::$cardflagContact === false) {
                $contactDetail = MarketingContact::where('store_id', getCurrentStore())->get();

                self::$cardcontactData = $contactDetail;
                self::$cardflagContact = true;
            }
        }
        return self::$cardcontactData;

    }

}
