<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name',
        'from',
        'user_id',
        'is_active',
        
        'store_id',
        'created_by',
    ];
}
