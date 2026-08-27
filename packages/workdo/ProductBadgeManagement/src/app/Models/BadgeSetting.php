<?php


namespace Workdo\ProductBadgeManagement\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BadgeSetting extends Model
{
    use HasFactory, Cachable;

    // Specify the table name if it's different from the default convention
    protected $table = 'badge_settings';

    // Specify the fillable fields to mass assign
    protected $fillable = [
        'store_id',
        
        'textcolor',
        'bgcolor',
        'badgetxt',
        'newerthan',
        'position_top',
        'position_left',
        'padding_top',
        'padding_bottom',
        'padding_left',
        'padding_right',
        'radius_top_left',
        'radius_top_right',
        'radius_bottom_left',
        'radius_bottom_right',
        'opacity',
        'storage_setting',
        'width',
    ];
}
