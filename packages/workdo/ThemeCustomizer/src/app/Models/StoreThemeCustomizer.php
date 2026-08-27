<?php

namespace Workdo\ThemeCustomizer\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StoreThemeCustomizer extends Model
{
    use HasFactory, Cachable;

    protected $table = 'store_theme_customize';
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'store_id',
        'theme_id',
        'class_json',
        'is_publish'
    ];
    
}
