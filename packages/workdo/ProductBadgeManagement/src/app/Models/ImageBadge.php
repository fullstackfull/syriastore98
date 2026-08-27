<?php

namespace Workdo\ProductBadgeManagement\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ImageBadge extends Model
{
    use HasFactory, Cachable;

    protected $table = 'image_badges';
    protected $fillable = [
        'filename',
        'filepath',
        
        'store_id',
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
        'width',
    ];
}

?>
