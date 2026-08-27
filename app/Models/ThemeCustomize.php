<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;

class ThemeCustomize extends Model
{
    use Cachable;

    protected $fillable = [
        'name',
        'value',
        'theme_id',
        'store_id',
    ];
}
