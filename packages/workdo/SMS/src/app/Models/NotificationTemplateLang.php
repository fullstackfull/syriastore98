<?php

namespace Workdo\SMS\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NotificationTemplateLang extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'parent_id',
        'lang',
        'content',
        'module_name',
        'variables',
    ];
}
