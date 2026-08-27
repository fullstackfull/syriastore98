<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'template_name',
        'prompt',
        'field_json',
        'is_tone',
        'module',
    ];
}
