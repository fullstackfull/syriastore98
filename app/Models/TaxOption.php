<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxOption extends Model
{
    use HasFactory, Cachable;
    
    protected $fillable = [
        'name', 'value',  'created_by', 'store_id'
    ];
}
