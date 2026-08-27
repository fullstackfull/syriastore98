<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PixelFields extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'platform', 'pixel_id' ,'store_id'
    ];
}
