<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'product_id',
        'image_path',
        'image_url',
    ];

    protected $appends = ["demo_field","image_path_full_url"];
    protected $hidden = ["image_url"];
    
    public function getDemoFieldAttribute()
    {
        return 'demo field';
    }

    public function getImagePathFullUrlAttribute() {
        return get_file($this->image_path);
    }

}
