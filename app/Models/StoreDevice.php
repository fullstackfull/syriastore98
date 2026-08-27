<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreDevice extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'fcm_token',
        'device_id',
        'platform',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}