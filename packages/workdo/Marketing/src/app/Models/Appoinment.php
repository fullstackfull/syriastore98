<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Appoinment extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    private static $cardappointmentData = null;
    private static $cardflagApp = false;
    protected $fillable = [
        'store_id',
        
        'content',
        'is_enabled',
        'created_by'
    ];
    
    public static function cardAppointmentData($id)
    {
        if (self::$cardappointmentData == null) {
            if (self::$cardflagApp === false) {
                $appointmentDetail = appoinment::where('store_id', $id)->first();
                self::$cardappointmentData = $appointmentDetail;
                self::$cardflagApp = true;
            }
        }
        return self::$cardappointmentData;

    }

    public static function Marketing($var)
    {
        $company_setting=getAdminAllSetting();

        $a = $company_setting[$var] ?? "";
        
        return $a;
    }

    public static function EnableOrNot($var1 = null , $var2 = null)
    {
        $a = false;

        if (!is_null($var1) && !is_null($var2)) {
            $a = $var1['is_enabled'] == '1' ? true : false;
        }

        return $a;
    }
}