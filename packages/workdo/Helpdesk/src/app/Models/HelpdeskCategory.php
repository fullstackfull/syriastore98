<?php

namespace Workdo\Helpdesk\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HelpdeskCategory extends Model
{
    use HasFactory, Cachable;

    protected $table = 'helpdesk_categories';
    
    protected $fillable = ['name', 'color', 'created_by'];
}
