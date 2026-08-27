<?php

namespace Workdo\Campaigns\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CostSetting extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $table = 'cost_setting';
    protected $fillable = [
        'min',
        'max',
        'price',
        'created_by',
    ];


    protected static function newFactory(): CostSettingFactory
    {
        //return CostSettingFactory::new();
    }
}
