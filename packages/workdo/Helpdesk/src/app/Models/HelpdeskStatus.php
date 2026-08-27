<?php

namespace Workdo\Helpdesk\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HelpdeskStatus extends Model
{
    use HasFactory, Cachable;

    protected $table = 'helpdesk_statuses';
    protected $fillable = ['status_name', 'status_color', 'created_by'];
}
