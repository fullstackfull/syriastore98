<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToStore;

class Shipping extends Model
{
    use HasFactory, Cachable, BelongsToStore;

    protected $fillable = [
        'name', 'description', 'slug',  'store_id'
    ];
}
