<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatwootIntegration extends Model
{
    protected $table = 'chatwoot_integrations';

    protected $fillable = [
        'store_id',
        'base_url',
        'account_id',
        'api_access_token',
        'inbox_id',
        'inbox_name',
        'is_active',
        'last_connected_at',
    ];

    protected $casts = [
        'api_access_token' => 'encrypted',
        'is_active' => 'boolean',
        'last_connected_at' => 'datetime',
    ];
}