<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PixelFields extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];
    
}
