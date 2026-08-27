<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use Cachable;

    protected $fillable = [
        'menu_id', 'menu_itemable', 'target', 'parent_id', 'icon_type', 'icon'
    ];

    public function menuItemable()
    {
        return $this->morphTo();
    }

    public function children()
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->with('children')->orderBy('order', 'asc');
    }

    public function parent()
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }
}
