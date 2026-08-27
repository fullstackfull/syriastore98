<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreNotification extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'type',
        'title',
        'message',
        'reference_type',
        'reference_id',
        'data',
        'is_read',
        'push_sent',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'push_sent' => 'boolean',
    ];
}