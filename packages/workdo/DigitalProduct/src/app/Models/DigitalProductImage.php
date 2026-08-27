<?php

namespace Workdo\DigitalProduct\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DigitalProductImage extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'product_id',
        'image_path',
        'image_url',
        
        'store_id'
    ];
}
