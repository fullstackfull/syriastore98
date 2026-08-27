<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;

class CustomLink extends Model
{
    use Cachable;

    protected $fillable = [
        'menu_id', 'title', 'url',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function menuItems()
    {
        return $this->morphMany(MenuItem::class, 'menu_itemable');
    }
}
