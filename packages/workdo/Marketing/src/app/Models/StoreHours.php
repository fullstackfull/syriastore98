<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StoreHours extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    private static $cardBusinessHourData = null;
    private static $cardflaghour = false;
    protected $fillable = [
        'store_id',
        
        'content',
        'is_enabled',
        'created_by'
    ];
    
    public static $days = [
        'sun' => 'Sunday',
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
    ];

    public static function cardBusinessHour($id)
    {
        if (self::$cardBusinessHourData == null) {
            if (self::$cardflaghour === false) {
                $business_hours = StoreHours::where('store_id', $id)->first();
                self::$cardBusinessHourData = $business_hours;
                self::$cardflaghour = true;
            }
        }
        return self::$cardBusinessHourData;
    }
}