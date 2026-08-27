<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory, Cachable;
    protected $table = 'currency';

    protected $fillable = [
        'name', 'code', 'symbol'
    ];
}
