<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatwootNotificationTemplate extends Model
{
    protected $table = 'chatwoot_notification_templates';

    protected $fillable = [
        'store_id',
        'event_type',
        'template_name',
        'template_language',
        'template_category',
        'parameters',
        'is_enabled',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_enabled' => 'boolean',
    ];
}