<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;

class ThemeCustomizeOrder extends Model
{
    use Cachable;

    protected $fillable = [
        'page_slug',
        'orders',
        
        'store_id',
    ];
}
