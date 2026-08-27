<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'page_name', 'page_slug', 'page_content', 'page_meta_title', 'page_meta_description', 'page_meta_keywords', 'page_status', 'store_id', 'is_default'
    ];

    protected $appends = ["name"];

    public function getNameAttribute()
    {
        return !empty($this->page_name) ? $this->page_name : null;
    }

    public function menuItems()
    {
        return $this->morphMany(MenuItem::class, 'menu_itemable');
    }
}
