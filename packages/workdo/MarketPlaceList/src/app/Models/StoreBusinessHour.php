<?php

namespace Workdo\MarketPlaceList\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\MarketPlaceList\Database\factories\ContentFactory;

class StoreBusinessHour extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $table = 'store_business_hour';
    protected $fillable = [
        'store_id', 'content', 'created_by'
    ];

    protected static function newFactory(): ContentFactory
    {
        //return ContentFactory::new();
    }

    public static $days = [
        'sun' => 'Sunday',
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
    ];
}
