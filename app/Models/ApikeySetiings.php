<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApikeySetiings extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
    	'key',
    	'created_by'
    ];
}
