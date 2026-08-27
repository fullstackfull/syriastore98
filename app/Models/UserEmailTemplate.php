<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserEmailTemplate extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'template_id', 'user_id', 'is_active'
    ];
}
