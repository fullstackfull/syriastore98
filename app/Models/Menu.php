<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    use Cachable, BelongsToStore;

    protected $fillable = [
        'name', 'store_id',
    ];

    public function menuItems()
    {
        return $this->hasMany(MenuItem::class);
    }

    public function custom_links()
    {
        return $this->hasMany(CustomLink::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
