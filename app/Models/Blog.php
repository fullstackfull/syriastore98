<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Qirolab\Theme\Theme;

class Blog extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'title', 'slug', 'short_description', 'content', 'category_id', 'cover_image_url', 'cover_image_path',  'store_id'
    ];

    public function category() {
        return $this->hasOne(BlogCategory::class, 'id', 'category_id');
    }

    public function store() {
        return $this->hasOne(Store::class, 'id', 'store_id');
    }

    public function menuItems()
    {
        return $this->morphMany(MenuItem::class, 'menu_itemable');
    }
}
