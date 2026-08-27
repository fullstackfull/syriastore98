<?php

namespace Workdo\CountDownTimer\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CountDownTimer extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'title',
        'start_date',
        'start_time',
        'status',
        'sale_image',
        'image',
        'theme_layout',
        'description',
        'created_by',
        'store_id',
        
    ];
}
