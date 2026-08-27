<?php

namespace Workdo\SMS\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\SMS\Database\factories\NotificationFactory;

class Notification extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];
    
    protected static function newFactory(): NotificationFactory
    {
        //return NotificationFactory::new();
    }
}
