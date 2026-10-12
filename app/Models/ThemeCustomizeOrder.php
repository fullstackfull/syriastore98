<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;

class ThemeCustomizeOrder extends Model
{
    use Cachable, BelongsToStore;

    protected $fillable = [
        'page_slug',
        'orders',
        
        'store_id',
    ];
}
