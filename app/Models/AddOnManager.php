<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddOnManager extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'module', 'name', 'monthly_price', 'yearly_price','image','is_enable','package_name'
    ];
}
